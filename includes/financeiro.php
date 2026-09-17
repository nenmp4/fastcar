<?php
/**
 * Módulo financeiro — portado do JurídicoSaaS (repo irmão), adaptado pro
 * modelo de negócio do Fastcar (ver nota grande em install/schema.sql).
 * Concentra aqui a lógica que não é só "monta o formulário" (cálculo de
 * status, anexo, geração de plano de parcelamento de venda) — CRUD simples
 * de categoria/fornecedor/colaborador fica inline nas próprias telas
 * admin/financeiro-*.php, mesmo padrão do original.
 */

require_once __DIR__ . '/google_drive.php';
require_once __DIR__ . '/documentos.php';

/**
 * Calcula o status automaticamente a partir de data_pagamento/
 * data_vencimento — evita o usuário esquecer de marcar "atrasado" manualmente.
 */
function finCalcularStatus(?string $dataPagamento, ?string $dataVencimento): string {
    if ($dataPagamento) return 'pago';
    if ($dataVencimento && $dataVencimento < date('Y-m-d')) return 'atrasado';
    return 'pendente';
}

/**
 * Recalcula status de pendente→atrasado a cada carregamento de tela (baixo
 * custo, sem precisar de cron) — mesmo padrão do original.
 */
function finRecalcularAtrasados(): void {
    $db = getDB();
    $db->exec("UPDATE fin_lancamentos SET status='atrasado' WHERE status='pendente' AND data_vencimento IS NOT NULL AND data_vencimento < date('now','localtime')");
}

/**
 * Sobe o anexo (comprovante) pra pasta "Financeiro" dedicada no Drive —
 * mesmo padrão Drive-preferido/local-fallback do resto do Fastcar
 * (includes/documentos.php::salvarArquivoGeradoComoDocumento()), mas sem
 * exigir um cliente_id (nem todo lançamento tem cliente vinculado).
 * Retorna ['drive_file_id'=>string,'arquivo_url'=>string], os dois vazios
 * se falhar dos dois jeitos — nunca lança, quem chama decide se bloqueia.
 */
function finSalvarAnexo(array $arquivo): array {
    $ext = strtolower(pathinfo((string)($arquivo['name'] ?? ''), PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'heic', 'heif', 'pdf'], true)) {
        return ['drive_file_id' => '', 'arquivo_url' => ''];
    }

    $driveFileId = '';
    try {
        $drive = new GoogleDrive();
        if ($drive->hasCredentials() && $drive->authenticate()) {
            $pastaId = getConfig('drive_financeiro_folder_id');
            if (!$pastaId) {
                $raizId = garantirPastaRaizFastcar($drive);
                $pastaId = $raizId ? $drive->createFolder('Financeiro', $raizId) : null;
                if ($pastaId) setConfig('drive_financeiro_folder_id', $pastaId);
            }
            if ($pastaId) {
                $nomeArquivo = date('Y-m-d_His') . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', (string)$arquivo['name']);
                $mime = $arquivo['type'] ?: 'application/octet-stream';
                $idDrive = $drive->uploadFile($arquivo['tmp_name'], $nomeArquivo, $mime, $pastaId);
                if ($idDrive) $driveFileId = $idDrive;
            }
        }
    } catch (Throwable $e) {
        error_log('[financeiro] falha ao subir anexo pro Drive, usando fallback local: ' . $e->getMessage());
    }

    if ($driveFileId) return ['drive_file_id' => $driveFileId, 'arquivo_url' => ''];

    $dir = UPLOADS_DIR . '/financeiro';
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        return ['drive_file_id' => '', 'arquivo_url' => ''];
    }
    $fn = 'comprovante_' . time() . '_' . uniqid() . '.' . $ext;
    if (move_uploaded_file($arquivo['tmp_name'], $dir . '/' . $fn)) {
        return ['drive_file_id' => '', 'arquivo_url' => 'financeiro/' . $fn];
    }
    return ['drive_file_id' => '', 'arquivo_url' => ''];
}

/** Quantos lançamentos (de qualquer origem) já existem pra uma venda. */
function finContarLancamentosVenda(int $vendaId): int {
    $db = getDB();
    $stmt = $db->prepare("SELECT COUNT(*) FROM fin_lancamentos WHERE venda_id = ?");
    $stmt->execute([$vendaId]);
    return (int)$stmt->fetchColumn();
}

/** Lançamentos (parcelas/entrada) de uma venda, ordenados pela ordem da parcela. */
function finListarLancamentosVenda(int $vendaId): array {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM fin_lancamentos WHERE venda_id = ? ORDER BY parcela_numero ASC, id ASC");
    $stmt->execute([$vendaId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Gera o plano de parcelamento LOCAL (entrada + N parcelas mensais) de uma
 * venda — Fastcar vende veículo da frota financiado (entrada + parcelas)
 * pro comprador, pedido explícito ("fastcar vende veiculo parcelado entrada
 * mais parcelamentos"). "Local" = só fica registrado no financeiro do CRM,
 * sem cobrar de verdade — usado quando o Asaas não está configurado ou o
 * comprador ainda não tem cliente Asaas linkado (ver
 * includes/asaas.php::asaasGerarCobrancaParceladaVenda() pro caminho que
 * cobra de verdade). Nunca gera 2x pra mesma venda — chamador deve checar
 * finContarLancamentosVenda() antes (a tela trava o botão, mas a função
 * também recusa por segurança, nunca confia só em esconder o botão).
 *
 * $valorEntrada pode ser 0 (venda 100% parcelada, sem entrada) — nesse caso
 * não cria a linha de entrada. parcela_numero da entrada é sempre 0, as
 * parcelas em si vão de 1 a $numParcelas.
 */
function finGerarPlanoParcelamentoVenda(
    int $vendaId,
    float $valorEntrada,
    int $numParcelas,
    float $valorParcela,
    string $primeiraParcelaData,
    ?int $categoriaId,
    string $nomeCompradorManual,
    int $criadoPor
): array {
    if (finContarLancamentosVenda($vendaId) > 0) {
        return ['ok' => false, 'erro' => 'Esta venda já tem lançamentos financeiros gerados — não é possível gerar de novo.'];
    }
    if ($numParcelas < 1) {
        return ['ok' => false, 'erro' => 'Informe pelo menos 1 parcela.'];
    }
    if ($valorParcela <= 0) {
        return ['ok' => false, 'erro' => 'Valor da parcela precisa ser maior que zero.'];
    }

    $db = getDB();
    $db->beginTransaction();
    try {
        $ins = $db->prepare("
            INSERT INTO fin_lancamentos
                (tipo, categoria_id, descricao, valor, data_vencimento, status, venda_id, parcela_numero, parcela_total, cliente_nome_manual, origem, created_by)
            VALUES ('receita', ?, ?, ?, ?, 'pendente', ?, ?, ?, ?, 'parcelamento_venda', ?)
        ");

        $criadas = 0;
        if ($valorEntrada > 0) {
            $ins->execute([$categoriaId, "Entrada — venda #{$vendaId}", $valorEntrada, date('Y-m-d'), $vendaId, 0, $numParcelas, $nomeCompradorManual, $criadoPor]);
            $criadas++;
        }
        for ($i = 1; $i <= $numParcelas; $i++) {
            $vencimento = date('Y-m-d', strtotime("+{$i} months", strtotime($primeiraParcelaData) ?: strtotime('first day of next month')));
            // A 1ª parcela vence na data escolhida; as seguintes seguem +1 mês
            // a partir dela — recalcula com base na parcela 1, não composto
            // sobre a anterior, pra nunca "desalinhar" o dia do mês com o
            // tempo (mesmo raciocínio de datas mensais do resto do projeto).
            $vencimento = ($i === 1) ? $primeiraParcelaData : date('Y-m-d', strtotime("+" . ($i - 1) . " months", strtotime($primeiraParcelaData)));
            $ins->execute([$categoriaId, "Parcela {$i}/{$numParcelas} — venda #{$vendaId}", $valorParcela, $vencimento, $vendaId, $i, $numParcelas, $nomeCompradorManual, $criadoPor]);
            $criadas++;
        }

        $db->commit();
        return ['ok' => true, 'criadas' => $criadas];
    } catch (Throwable $e) {
        $db->rollBack();
        return ['ok' => false, 'erro' => $e->getMessage()];
    }
}
