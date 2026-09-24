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
require_once __DIR__ . '/asaas.php'; // finGerarReceitaVendaAssinatura() chama asaasConfigured()/etc direto

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
 *
 * $categoriaEntradaId (novo, 19/09/2026) — categoria SÓ da linha de
 * entrada, separada da categoria das parcelas ($categoriaId); omitido
 * (null) cai no mesmo $categoriaId de sempre — o formulário manual em
 * admin/venda.php nunca passa esse parâmetro, continua se comportando
 * exatamente como antes.
 *
 * $dataEntrada (novo, 24/09/2026) — mesmo racional de $dataCompra em
 * finRegistrarDespesaCompraFechada(): opcional, null cai em date('Y-m-d')
 * (comportamento de sempre, botão manual e gatilho automático em tempo
 * real). Só passado explícito por quem grava venda RETROATIVA
 * (install/gerar_lancamentos_vendas_retroativos.php), pra nunca datar a
 * entrada de uma venda de meses atrás como se fosse hoje.
 */
function finGerarPlanoParcelamentoVenda(
    int $vendaId,
    float $valorEntrada,
    int $numParcelas,
    float $valorParcela,
    string $primeiraParcelaData,
    ?int $categoriaId,
    string $nomeCompradorManual,
    int $criadoPor,
    ?int $categoriaEntradaId = null,
    ?string $dataEntrada = null
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
            $ins->execute([$categoriaEntradaId ?? $categoriaId, "Entrada — venda #{$vendaId}", $valorEntrada, $dataEntrada ?: date('Y-m-d'), $vendaId, 0, $numParcelas, $nomeCompradorManual, $criadoPor]);
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

/**
 * Despesa AUTOMÁTICA quando uma compra fecha (bloco 8, "sai do caixa") —
 * 19/09/2026, pedido direto do usuário pra conciliar negociação com
 * financeiro ("quando compra veiculo sai do caixa... isso entra e saide
 * de fluxo de negociação"). Chamada de dentro de mudarEtapa()
 * (includes/oportunidades.php), na mesma transição que já preenche
 * valor_final/data_compra/fechado_por — nunca em outro lugar, pra sempre
 * usar o valor final real pago. Nasce direto como 'pago' (confirmado com
 * o usuário via 2 perguntas diretas: gatilho automático, sem passo extra
 * de confirmação — valor_final já É o que foi pago de verdade na hora do
 * fechamento). Best-effort: nunca lança, nunca pode travar o fechamento
 * da oportunidade por causa disso — mesmo espírito de enviarEmail()/
 * notificarAssinaturaContrato(). Idempotente por oportunidade_id+origem —
 * nunca duplica mesmo se `mudarEtapa()` rodar de novo pra 'fechado' na
 * mesma oportunidade.
 *
 * Confirmado também que fechar só é possível DEPOIS do contrato assinado
 * de verdade (regra #7, checklistFechamentoCompleto() já exige a linha
 * `contrato_compra` em oportunidade_documentos, só preenchida por
 * zapsignSincronizarContrato() quando o status vira 'assinado') — então
 * "só depois do contrato assinado" já é garantido pelo ponto de gatilho
 * escolhido, sem checagem extra aqui.
 *
 * `$dataCompra` (novo, 24/09/2026) — a data real do fechamento
 * (`oportunidades.data_compra`), usada como `data_vencimento`/
 * `data_pagamento` do lançamento. Opcional/`null` de propósito: o
 * gatilho real (`mudarEtapa()`) nunca precisa passar nada, já que
 * `data_compra` É "hoje" no exato momento em que essa função é chamada
 * ali — omitir cai no comportamento de sempre (`date('now','localtime')`).
 * Só passa explícito quem grava RETROATIVO
 * (`install/gerar_lancamentos_fechados_retroativos.php`), pra nunca
 * datar um negócio de meses atrás como se tivesse acontecido hoje —
 * achado real, "Despesas do mês"/"Saldo do mês" ficaram distorcidos
 * depois de rodar o backfill sem isso (ver bullet no CLAUDE.md).
 */
function finRegistrarDespesaCompraFechada(int $oportunidadeId, float $valorFinal, ?int $criadoPor, ?string $dataCompra = null): void {
    try {
        if ($valorFinal <= 0) return;
        $db = getDB();

        $existe = $db->prepare("SELECT 1 FROM fin_lancamentos WHERE oportunidade_id = ? AND origem = 'fechamento_compra'");
        $existe->execute([$oportunidadeId]);
        if ($existe->fetchColumn()) return;

        $stmt = $db->prepare("
            SELECT o.veiculo_marca, o.veiculo_modelo, cl.nome AS cliente_nome
            FROM oportunidades o JOIN clientes cl ON cl.id = o.cliente_id
            WHERE o.id = ?
        ");
        $stmt->execute([$oportunidadeId]);
        $op = $stmt->fetch();
        if (!$op) return;

        $categoriaId = $db->query("SELECT id FROM fin_categorias WHERE nome = 'Compra de veículo (pagamento ao vendedor)'")->fetchColumn();
        $veiculo = trim(($op['veiculo_marca'] ?? '') . ' ' . ($op['veiculo_modelo'] ?? '')) ?: 'veículo';
        $descricao = "Compra de {$veiculo} — {$op['cliente_nome']} (oportunidade #{$oportunidadeId})";
        $data = $dataCompra ?: date('Y-m-d');

        $db->prepare("
            INSERT INTO fin_lancamentos
                (tipo, categoria_id, descricao, valor, data_vencimento, data_pagamento, status, oportunidade_id, origem, created_by)
            VALUES ('despesa', ?, ?, ?, ?, ?, 'pago', ?, 'fechamento_compra', ?)
        ")->execute([$categoriaId ?: null, $descricao, $valorFinal, $data, $data, $oportunidadeId, $criadoPor]);
    } catch (Throwable $e) {
        // best-effort — nunca pode travar o fechamento da oportunidade
    }
}

/**
 * Comissão AUTOMÁTICA do consultor ao fechar uma COMPRA — 23/09/2026,
 * pedido direto: "fazer lançamentos da comissões do consultores
 * automaticos no financeiro - após assinatura do contrato de comprar -
 * se consultor fechar ate 20 da fipe 1,5 porcento se ele fechar ate 25
 * ou mais 1 porcento". Confirmado via 3 perguntas diretas antes de
 * implementar: (1) o percentual incide sobre o VALOR PAGO ao vendedor
 * (valor_final), não sobre a FIPE em si; (2) só 2 faixas de verdade —
 * quanto o valor pago representa da FIPE (valor_final /
 * valor_fipe_referencia × 100): até 20% dela = 1,5% de comissão; acima
 * de 20% = 1% (quanto MENOR a fração da FIPE que a Fastcar pagou, melhor
 * o negócio, maior a comissão — o "25" do pedido original era só um
 * exemplo de "acima de 20", não uma 3ª faixa); (3) o lançamento nasce já
 * `status='pago'`, mesmo espírito de finRegistrarDespesaCompraFechada()
 * logo acima (gatilho automático, sem passo extra de confirmação).
 *
 * Comissão de VENDEDOR (revenda) fica de fora de propósito — o próprio
 * usuário confirmou que ainda não tem percentual definido pra esse lado
 * ("do vendedo eu ainda não porcentagens ainda"); nada implementado
 * ainda pra vendas, só compra.
 *
 * Chamada de dentro de mudarEtapa() (includes/oportunidades.php), junto
 * de finRegistrarDespesaCompraFechada() na mesma transição pra 'fechado'.
 * Best-effort (nunca lança, nunca trava o fechamento) e idempotente por
 * oportunidade_id+origem, mesmo padrão das outras funções deste arquivo.
 *
 * Nunca gera nada (regra #3, nunca chuta valor) quando falta o que
 * precisa pro cálculo: sem valor_fipe_referencia preenchido na
 * oportunidade (nem toda compra passa pela busca de FIPE), ou sem
 * `$fechadoPor` tendo um colaborador ATIVO cadastrado em
 * fin_colaboradores (usuario_id) — sem colaborador não tem em quem
 * lançar a comissão, e criar um colaborador sozinho não é decisão do
 * sistema tomar. Nesses casos fica pra lançamento manual à parte, igual
 * já acontecia antes desta função existir.
 *
 * `$dataCompra` (novo, 24/09/2026) — mesmo racional de
 * `finRegistrarDespesaCompraFechada()` logo acima: opcional, `null` cai
 * em `date('now','localtime')` (gatilho real via `mudarEtapa()`), só
 * passado explícito pelo backfill retroativo pra nunca datar comissão de
 * negócio antigo como se fosse de hoje.
 */
function finRegistrarComissaoCompraFechada(int $oportunidadeId, float $valorFinal, ?int $fechadoPor, ?string $dataCompra = null): void {
    try {
        if ($valorFinal <= 0 || !$fechadoPor) return;
        $db = getDB();

        $existe = $db->prepare("SELECT 1 FROM fin_lancamentos WHERE oportunidade_id = ? AND origem = 'comissao_compra'");
        $existe->execute([$oportunidadeId]);
        if ($existe->fetchColumn()) return;

        $stmt = $db->prepare("
            SELECT o.valor_fipe_referencia, o.veiculo_marca, o.veiculo_modelo, cl.nome AS cliente_nome
            FROM oportunidades o JOIN clientes cl ON cl.id = o.cliente_id
            WHERE o.id = ?
        ");
        $stmt->execute([$oportunidadeId]);
        $op = $stmt->fetch();
        if (!$op || (float)($op['valor_fipe_referencia'] ?? 0) <= 0) return;

        $stmtColab = $db->prepare("SELECT id FROM fin_colaboradores WHERE usuario_id = ? AND status = 'ativo'");
        $stmtColab->execute([$fechadoPor]);
        $colaboradorId = $stmtColab->fetchColumn();
        if (!$colaboradorId) return;

        $percentualFipe = ($valorFinal / (float)$op['valor_fipe_referencia']) * 100;
        $taxa = $percentualFipe <= 20 ? 1.5 : 1.0;
        $valorComissao = round($valorFinal * $taxa / 100, 2);
        if ($valorComissao <= 0) return;

        $categoriaId = $db->query("SELECT id FROM fin_categorias WHERE nome = 'Comissão de consultor/vendedor'")->fetchColumn();
        $veiculo = trim(($op['veiculo_marca'] ?? '') . ' ' . ($op['veiculo_modelo'] ?? '')) ?: 'veículo';
        $descricao = sprintf(
            'Comissão de compra — %s — %s (oportunidade #%d) — %.1f%% do valor pago (fechou a %.1f%% da FIPE)',
            $veiculo, $op['cliente_nome'], $oportunidadeId, $taxa, $percentualFipe
        );

        $data = $dataCompra ?: date('Y-m-d');
        $db->prepare("
            INSERT INTO fin_lancamentos
                (tipo, categoria_id, descricao, valor, data_vencimento, data_pagamento, status, oportunidade_id, funcionario_id, origem, created_by)
            VALUES ('despesa', ?, ?, ?, ?, ?, 'pago', ?, ?, 'comissao_compra', ?)
        ")->execute([$categoriaId ?: null, $descricao, $valorComissao, $data, $data, $oportunidadeId, $colaboradorId, $fechadoPor]);
    } catch (Throwable $e) {
        // best-effort — nunca pode travar o fechamento da oportunidade
    }
}

/**
 * Receita AUTOMÁTICA (entrada + parcelas) quando o contrato de VENDA é
 * assinado — 19/09/2026, pedido direto ("você faz mesma coinsa com venda
 * assinou contrato gera receita"), espelhando o mesmo gatilho automático
 * do lado da compra acima. Calcula os parâmetros a partir do que a
 * negociação já tem (`preco_venda`/`valor_pago_contratacao`/
 * `prazo_quitacao_meses`, já preenchidos pelo vendedor no card de
 * condições antes de gerar o contrato), em vez de exigir digitar tudo de
 * novo no formulário manual — que continua existindo do jeito que está,
 * pra corrigir/gerar na mão quando o cálculo automático não se aplica
 * (ex: negociação sem prazo/preço definidos direito). Chamada de dentro
 * de mudarEtapaVenda(), na transição pra 'vendido' — mesma etapa que já
 * marca `data_venda`, disparada por `zapsignSincronizarContrato()` assim
 * que a assinatura é confirmada. Best-effort: nunca lança, nunca trava a
 * transição de etapa da venda. Nunca duplica (checa
 * `finContarLancamentosVenda()` antes de qualquer escrita) — se o
 * vendedor já tinha gerado manualmente antes da assinatura chegar, essa
 * chamada simplesmente não faz nada. 1ª parcela vence no 1º dia do mês
 * seguinte à assinatura — assunção razoável sem pedido específico de
 * data, sempre corrigível à mão editando o lançamento depois. Nunca gera
 * nada se a venda for inteiramente à vista (saldo restante ≤ 0) ou sem
 * prazo definido — fica pro botão manual nesses casos.
 *
 * Categorias separadas (19/09/2026, "como podemos chamar essas despesa
 * compra de veiculo e venda de veiculos" → confirmado "separar entrada e
 * parcela em categorias diferentes"): entrada vai pra "Venda de veículo —
 * entrada" (existia cadastrada, nunca usada até agora), parcelas locais
 * pra "Venda de veículo — parcela" (mesma que a importação do Asaas já
 * usa).
 *
 * Asaas (19/09/2026, "da para criar o parcelamento direto pelo sistema
 * usando api? [...] do assas") — tenta cobrança REAL primeiro, igual o
 * botão manual do card de parcelamento já faz
 * (`admin/venda.php::gerar_parcelamento`, `$usarAsaas`): se
 * `asaasConfigured()` e o comprador tem CPF/nome suficiente pra
 * `asaasCriarClienteSeNecessario()` achar/criar o cliente no Asaas,
 * `asaasGerarCobrancaParceladaVenda()` cria as parcelas do SALDO como
 * cobrança de verdade (origem='asaas', cliente escolhe boleto/PIX/cartão
 * na hora de pagar) — a ENTRADA nunca passa pelo Asaas (mesma decisão de
 * escopo já documentada em `asaasGerarCobrancaParceladaVenda()`: Asaas só
 * parcela o saldo), sempre gravada como lançamento local separado. Sem
 * Asaas configurado, sem CPF/nome suficiente, ou a chamada à API falhando
 * por qualquer motivo, cai inteiro pro caminho 100% local
 * (`finGerarPlanoParcelamentoVenda()`, entrada+parcelas juntas) — nunca
 * trava a venda por causa da integração externa.
 *
 * $dataVenda (novo, 24/09/2026) — mesmo racional de $dataCompra no lado de
 * compra: opcional, null cai no comportamento de sempre (data de hoje,
 * gatilho em tempo real via mudarEtapaVenda()). Passado explícito só por
 * quem grava venda RETROATIVA (install/gerar_lancamentos_vendas_retroativos.php)
 * — quando presente, NUNCA tenta Asaas (regra #3 aplicada aqui: uma venda
 * histórica de meses atrás nunca deve gerar cobrança real/link de
 * pagamento vivo pro comprador, mesmo risco já evitado do lado de compra
 * não recriar automação "isso está acontecendo agora"), vai direto pro
 * caminho 100% local com a data real da venda na entrada e a 1ª parcela
 * calculada a partir dela (não de "hoje").
 */
/**
 * Cancela os lançamentos FUTUROS (ainda 'pendente') de uma venda que caiu —
 * usado tanto no cancelamento pré-venda (negociação nunca chegou a fechar)
 * quanto na DEVOLUÇÃO pós-venda (19/09/2026, "temos aquele problema de
 * cliente devolver veiculo agente vende para outro aquelas cobrança é
 * cancelada e novo cliente vendido gera nova entrar dinheiro novas
 * parcela"): comprador devolveu o veículo depois de já ter parcelas
 * rodando, e essas cobranças futuras não podem continuar ativas.
 *
 * Nunca mexe em lançamento já 'pago' — confirmado com o usuário via
 * pergunta direta: o que já entrou fica como receita normal, só é
 * registrada a devolução; um eventual estorno do que já foi recebido é
 * decisão contábil separada, lançada manualmente se for o caso, nunca
 * decidida sozinho aqui. Se a parcela já tinha virado cobrança real no
 * Asaas (origem='asaas', asaas_payment_id preenchido), tenta cancelar de
 * verdade lá também (confirmado: "cancelar tudo automaticamente, inclusive
 * no Asaas") — best-effort por parcela: uma falha na API do Asaas nunca
 * impede o cancelamento LOCAL de seguir pras outras parcelas nem de travar
 * a transição de etapa da venda.
 *
 * Chamada de dentro de mudarEtapaVenda() na transição pra 'cancelada' —
 * cobre os dois cenários (cancelamento pré-venda normalmente não tem
 * lançamento nenhum ainda, então isso vira um no-op silencioso pra ele) com
 * o mesmo código, sem precisar diferenciar de onde veio a transição.
 */
function finCancelarLancamentosPendentesVenda(int $vendaId): array {
    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT id, origem, asaas_payment_id FROM fin_lancamentos WHERE venda_id = ? AND status = 'pendente'");
        $stmt->execute([$vendaId]);
        $pendentes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$pendentes) return ['canceladas' => 0, 'asaas_falhas' => 0];

        $asaasFalhas = 0;
        foreach ($pendentes as $l) {
            if ($l['origem'] === 'asaas' && !empty($l['asaas_payment_id'])) {
                $r = asaasCancelarCobranca((string)$l['asaas_payment_id']);
                if (!$r['ok']) $asaasFalhas++;
            }
        }

        $db->prepare("
            UPDATE fin_lancamentos SET status = 'cancelado', updated_at = datetime('now','localtime')
            WHERE venda_id = ? AND status = 'pendente'
        ")->execute([$vendaId]);

        return ['canceladas' => count($pendentes), 'asaas_falhas' => $asaasFalhas];
    } catch (Throwable $e) {
        // best-effort — nunca pode travar a transição de etapa da venda
        return ['canceladas' => 0, 'asaas_falhas' => 0];
    }
}

function finGerarReceitaVendaAssinatura(int $vendaId, ?int $criadoPor, ?string $dataVenda = null): void {
    try {
        if (finContarLancamentosVenda($vendaId) > 0) return;

        $db = getDB();
        $stmt = $db->prepare("
            SELECT v.preco_venda, v.valor_pago_contratacao, v.prazo_quitacao_meses,
                   v.comprador_nome, v.comprador_cpf, v.comprador_telefone, v.comprador_email,
                   o.veiculo_marca, o.veiculo_modelo
            FROM vendas v
            LEFT JOIN oportunidades o ON o.id = v.oportunidade_id
            WHERE v.id = ?
        ");
        $stmt->execute([$vendaId]);
        $v = $stmt->fetch();
        if (!$v || !$v['preco_venda']) return;

        $valorEntrada = (float)($v['valor_pago_contratacao'] ?? 0);
        $restante = (float)$v['preco_venda'] - $valorEntrada;
        $numParcelas = (int)($v['prazo_quitacao_meses'] ?? 0);
        if ($restante <= 0 || $numParcelas < 1) return;

        $valorParcela = round($restante / $numParcelas, 2);
        $primeiraParcela = $dataVenda
            ? date('Y-m-d', strtotime('first day of next month', strtotime($dataVenda)))
            : date('Y-m-d', strtotime('first day of next month'));
        $categoriaParcelaId = $db->query("SELECT id FROM fin_categorias WHERE nome = 'Venda de veículo — parcela'")->fetchColumn();
        $categoriaEntradaId = $db->query("SELECT id FROM fin_categorias WHERE nome = 'Venda de veículo — entrada'")->fetchColumn();
        $veiculo = trim(($v['veiculo_marca'] ?? '') . ' ' . ($v['veiculo_modelo'] ?? '')) ?: 'veículo';

        // $dataVenda presente = chamada RETROATIVA — nunca tenta Asaas (uma
        // venda de meses atrás não pode gerar cobrança real/link de
        // pagamento vivo agora, mesmo raciocínio de nunca recriar automação
        // "isso está acontecendo agora" pra dado histórico).
        $criadoViaAsaas = false;
        if (!$dataVenda && asaasConfigured()) {
            $asaasCustomerId = asaasCriarClienteSeNecessario(
                (string)($v['comprador_nome'] ?? ''), (string)($v['comprador_cpf'] ?? ''),
                (string)($v['comprador_telefone'] ?? ''), (string)($v['comprador_email'] ?? '')
            );
            if ($asaasCustomerId) {
                $r = asaasGerarCobrancaParceladaVenda(
                    $vendaId, $asaasCustomerId, $valorParcela, $numParcelas, $primeiraParcela,
                    "Venda #{$vendaId} — {$veiculo}"
                );
                $criadoViaAsaas = $r['ok'];
            }
        }

        if ($criadoViaAsaas) {
            // Asaas já criou as parcelas do saldo (origem='asaas') — só falta
            // a entrada, que nunca passa pelo Asaas, sempre local.
            if ($valorEntrada > 0) {
                $db->prepare("
                    INSERT INTO fin_lancamentos
                        (tipo, categoria_id, descricao, valor, data_vencimento, data_pagamento, status, venda_id, parcela_numero, parcela_total, cliente_nome_manual, origem, created_by)
                    VALUES ('receita', ?, ?, ?, date('now','localtime'), date('now','localtime'), 'pago', ?, 0, ?, ?, 'parcelamento_venda', ?)
                ")->execute([
                    $categoriaEntradaId ?: null, "Entrada — venda #{$vendaId}", $valorEntrada,
                    $vendaId, $numParcelas, (string)($v['comprador_nome'] ?? ''), $criadoPor,
                ]);
            }
        } else {
            // Fallback 100% local — entrada + parcelas juntas, via a função já
            // existente/testada (mesma que o botão manual usa).
            $r = finGerarPlanoParcelamentoVenda(
                $vendaId, $valorEntrada, $numParcelas, $valorParcela, $primeiraParcela,
                $categoriaParcelaId ?: null, (string)($v['comprador_nome'] ?? ''), (int)$criadoPor,
                $categoriaEntradaId ?: null, $dataVenda
            );
            // Retroativo: a entrada de uma venda já concluída há tempos já
            // foi recebida de verdade (mesmo racional de
            // finRegistrarDespesaCompraFechada() nascer 'pago') — o
            // fallback local sempre cria como 'pendente' (comportamento
            // certo pro botão manual/gatilho em tempo real, onde ainda não
            // se sabe se o dinheiro já entrou). As parcelas seguem
            // 'pendente' — status individual de cada uma no passado é
            // incerto sem registro de pagamento real (regra #3, nunca
            // chuta), fica pro financeiro marcar manualmente as que já
            // sabe que foram pagas.
            if ($dataVenda && $valorEntrada > 0 && ($r['ok'] ?? false)) {
                $db->prepare("
                    UPDATE fin_lancamentos SET status = 'pago', data_pagamento = ?
                    WHERE venda_id = ? AND parcela_numero = 0 AND origem = 'parcelamento_venda'
                ")->execute([$dataVenda, $vendaId]);
            }
        }
    } catch (Throwable $e) {
        // best-effort — nunca pode travar a transição de etapa da venda
    }
}

/**
 * Comissão AUTOMÁTICA do vendedor ao fechar uma VENDA — 24/09/2026,
 * pedido direto: "na venda pagamos 5 por cento do valor da entrada".
 * Espelha finRegistrarComissaoCompraFechada() (compra), mas SEM faixas —
 * taxa fixa de 5% sobre `valor_pago_contratacao` (a "entrada" da venda,
 * mesmo campo que `finGerarReceitaVendaAssinatura()` logo acima já usa) —
 * o pedido só mencionou a entrada, nunca as parcelas do saldo financiado,
 * então a comissão nunca incide sobre elas.
 *
 * Chamada de dentro de `mudarEtapaVenda()` (`includes/vendas.php`), na
 * mesma transição pra `'vendido'` que já chama
 * `finGerarReceitaVendaAssinatura()`. NUNCA confia no parâmetro
 * `$responsavelId` de `mudarEtapaVenda()` pra saber quem é o vendedor —
 * o gatilho real em produção (`zapsignSincronizarContrato()`, quando a
 * ZapSign confirma a assinatura) chama `mudarEtapaVenda(..., null, ...)`
 * — o vendedor de verdade é sempre lido de `vendas.responsavel_id`.
 *
 * Best-effort e idempotente por `venda_id`+`origem`, mesmo padrão de
 * sempre. Nunca gera sem `valor_pago_contratacao > 0`, nem sem o
 * vendedor responsável ter um colaborador ATIVO cadastrado em
 * `fin_colaboradores` — mesmas 2 travas do lado de compra, nunca chuta
 * (regra #3).
 *
 * $dataVenda (novo, 24/09/2026) — mesmo racional de $dataCompra em
 * finRegistrarComissaoCompraFechada(): opcional, null cai em
 * date('now','localtime') (gatilho em tempo real via mudarEtapaVenda()).
 * Passado explícito só por install/gerar_lancamentos_vendas_retroativos.php,
 * pra nunca datar comissão de venda antiga como se fosse de hoje.
 */
function finRegistrarComissaoVendaFechada(int $vendaId, ?string $dataVenda = null): void {
    try {
        $db = getDB();

        $existe = $db->prepare("SELECT 1 FROM fin_lancamentos WHERE venda_id = ? AND origem = 'comissao_venda'");
        $existe->execute([$vendaId]);
        if ($existe->fetchColumn()) return;

        $stmt = $db->prepare("
            SELECT v.responsavel_id, v.valor_pago_contratacao, v.comprador_nome,
                   o.veiculo_marca, o.veiculo_modelo
            FROM vendas v
            LEFT JOIN oportunidades o ON o.id = v.oportunidade_id
            WHERE v.id = ?
        ");
        $stmt->execute([$vendaId]);
        $v = $stmt->fetch();
        if (!$v) return;

        $valorEntrada = (float)($v['valor_pago_contratacao'] ?? 0);
        if ($valorEntrada <= 0) return;

        $vendedorId = $v['responsavel_id'] ? (int)$v['responsavel_id'] : null;
        if (!$vendedorId) return;

        $stmtColab = $db->prepare("SELECT id FROM fin_colaboradores WHERE usuario_id = ? AND status = 'ativo'");
        $stmtColab->execute([$vendedorId]);
        $colaboradorId = $stmtColab->fetchColumn();
        if (!$colaboradorId) return;

        $valorComissao = round($valorEntrada * 5 / 100, 2);
        if ($valorComissao <= 0) return;

        $categoriaId = $db->query("SELECT id FROM fin_categorias WHERE nome = 'Comissão de consultor/vendedor'")->fetchColumn();
        $veiculo = trim(($v['veiculo_marca'] ?? '') . ' ' . ($v['veiculo_modelo'] ?? '')) ?: 'veículo';
        $descricao = sprintf(
            'Comissão de venda — %s — comprador %s (venda #%d) — 5%% da entrada (R$ %s)',
            $veiculo, $v['comprador_nome'] ?: '—', $vendaId, number_format($valorEntrada, 2, ',', '.')
        );

        $data = $dataVenda ?: date('Y-m-d');
        $db->prepare("
            INSERT INTO fin_lancamentos
                (tipo, categoria_id, descricao, valor, data_vencimento, data_pagamento, status, venda_id, funcionario_id, origem, created_by)
            VALUES ('despesa', ?, ?, ?, ?, ?, 'pago', ?, ?, 'comissao_venda', ?)
        ")->execute([$categoriaId ?: null, $descricao, $valorComissao, $data, $data, $vendaId, $colaboradorId, $vendedorId]);
    } catch (Throwable $e) {
        // best-effort — nunca pode travar a transição de etapa da venda
    }
}

/**
 * Gera automaticamente a próxima ocorrência mensal de toda despesa FIXA
 * (`natureza='fixa'`) que ainda não tenha um lançamento pro mês corrente —
 * 19/09/2026, pedido direto: "todas despesas fixas pode lançar todo mês
 * automático". "Fixa" já é o sinal de que a despesa se repete todo mês
 * (aluguel, salário, assinatura — o campo Natureza já distingue isso de
 * "Variável" na tela de lançamentos), então o gatilho é só
 * `natureza='fixa'`, sem precisar também marcar o checkbox "Recorrente"
 * (esse continua existindo pra outros casos — receita recorrente,
 * intervalo quinzenal/anual — mas fica inerte pra este fluxo específico,
 * que é só mensal por definição de "fixa").
 *
 * Cada despesa fixa forma uma CADEIA de lançamentos ligados por
 * `recorrencia_origem_id` (sempre apontando pro id do lançamento ORIGINAL
 * da série, nunca pro anterior — assim qualquer membro da cadeia resolve
 * pra raiz numa consulta só, sem precisar seguir ponteiro por ponteiro).
 * Pra cada cadeia: se já existe algum lançamento com vencimento no mês
 * corrente, não faz nada (idempotente — seguro rodar o cron quantas vezes
 * quiser no mesmo mês, nunca duplica); senão, copia os dados do
 * lançamento MAIS RECENTE da cadeia (categoria, valor, fornecedor, forma
 * de pagamento etc — reflete um reajuste de valor feito à mão na última
 * ocorrência, nunca trava no valor original da 1ª vez) pro mês corrente,
 * no mesmo dia do mês (ajustado se o mês corrente não tiver esse dia, ex:
 * dia 31 vira dia 30 em abril), sempre como 'pendente', sem copiar
 * comprovante/anexo do mês anterior (é de outro pagamento).
 *
 * Escape hatch sem precisar de campo novo: marcar o ÚLTIMO lançamento de
 * uma cadeia como `status='cancelado'` (status já existente, "assinatura
 * cancelada esse mês") interrompe a geração do mês seguinte pra aquela
 * série — sem isso, uma despesa fixa continuaria gerando pra sempre até
 * alguém apagar manualmente todo mês.
 *
 * Nunca lança (best-effort) — quem chama (cron/lancamentos_fixos.php)
 * decide o que fazer com o resultado.
 */
function finGerarDespesasFixasDoMes(): array {
    $db = getDB();

    try {
        $todas = $db->query("
            SELECT * FROM fin_lancamentos WHERE tipo = 'despesa' AND natureza = 'fixa'
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return ['criadas' => 0, 'puladas' => 0, 'erros' => [$e->getMessage()]];
    }

    // Agrupa por cadeia — raiz é recorrencia_origem_id, ou o próprio id
    // quando é a 1ª ocorrência da série (recorrencia_origem_id ainda NULL).
    $cadeias = [];
    foreach ($todas as $l) {
        $raiz = (int)($l['recorrencia_origem_id'] ?: $l['id']);
        $cadeias[$raiz][] = $l;
    }

    $mesAlvo = date('Y-m');
    $ultimoDiaMesAlvo = (int)date('t', strtotime($mesAlvo . '-01'));

    $criadas = 0;
    $puladas = 0;
    $erros = [];

    foreach ($cadeias as $raizId => $membros) {
        $jaTemNoMes = false;
        $maisRecente = null;
        foreach ($membros as $m) {
            if (!$m['data_vencimento']) continue;
            if (substr($m['data_vencimento'], 0, 7) === $mesAlvo) $jaTemNoMes = true;
            if ($maisRecente === null || $m['data_vencimento'] > $maisRecente['data_vencimento']) {
                $maisRecente = $m;
            }
        }

        // Sem vencimento em nenhum membro (nunca deveria acontecer — campo
        // opcional, mas sem ele não dá pra calcular "mês seguinte"), série
        // já cancelada, mês alvo não é estritamente posterior ao último
        // lançamento, ou valor inválido: pula, sem gerar nada.
        if (
            $jaTemNoMes || !$maisRecente
            || $maisRecente['status'] === 'cancelado'
            || substr($maisRecente['data_vencimento'], 0, 7) >= $mesAlvo
            || (float)$maisRecente['valor'] <= 0
        ) {
            $puladas++;
            continue;
        }

        $diaOriginal = (int)date('d', strtotime($maisRecente['data_vencimento']));
        $diaAlvo = min($diaOriginal, $ultimoDiaMesAlvo);
        $novoVencimento = sprintf('%s-%02d', $mesAlvo, $diaAlvo);

        try {
            $db->prepare("
                INSERT INTO fin_lancamentos
                    (tipo, categoria_id, descricao, valor, natureza, data_vencimento, status,
                     cliente_id, cliente_nome_manual, oportunidade_id, venda_id, funcionario_id,
                     fornecedor_id, forma_pagamento, recorrente, recorrencia_intervalo,
                     recorrencia_origem_id, origem)
                VALUES ('despesa', ?, ?, ?, 'fixa', ?, 'pendente', ?, ?, ?, ?, ?, ?, ?, 1, 'mensal', ?, 'recorrencia_fixa')
            ")->execute([
                $maisRecente['categoria_id'], $maisRecente['descricao'], $maisRecente['valor'], $novoVencimento,
                $maisRecente['cliente_id'], $maisRecente['cliente_nome_manual'], $maisRecente['oportunidade_id'],
                $maisRecente['venda_id'], $maisRecente['funcionario_id'], $maisRecente['fornecedor_id'],
                $maisRecente['forma_pagamento'], $raizId,
            ]);
            $criadas++;
        } catch (Throwable $e) {
            $erros[] = "cadeia #{$raizId}: {$e->getMessage()}";
        }
    }

    return ['criadas' => $criadas, 'puladas' => $puladas, 'erros' => $erros];
}
