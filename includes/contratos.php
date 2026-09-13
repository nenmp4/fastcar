<?php
/**
 * includes/contratos.php — Orquestra o contrato-mestre de COMPRA: monta os
 * dados da oportunidade, gera o PDF (includes/contratos_pdf.php), manda
 * pra assinatura eletrônica (includes/zapsign.php) e, quando assinado,
 * sobe o PDF final pra pasta do cliente no Drive (includes/google_drive.php)
 * e marca como documento da pasta fechada (bloco 8, regra #7).
 *
 * ZapSign substituiu a Assinafy em 13/09/2026 — colunas `contratos.
 * assinafy_doc_id`/`assinafy_signer_id` foram renomeadas via
 * install/migrar.php pra `zapsign_doc_token`/`zapsign_signer_token`;
 * `assinafy_assignment_id` ficou sem uso (a ZapSign não tem esse conceito
 * separado, cria documento+signatário numa chamada só).
 *
 * Módulo de VENDA fica pra outra etapa (decisão do Jean) — não implementado.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/contratos_pdf.php';
require_once __DIR__ . '/zapsign.php';
require_once __DIR__ . '/google_drive.php';
require_once __DIR__ . '/documentos.php'; // garantirPastaDriveCliente()

const CONTRATOS_STATUS_ZAPSIGN = [
    'signed'  => 'assinado',
    'refused' => 'recusado',
    'pending' => 'enviado',
];

function formatarDataExtensoPtBr(string $dataYmd): string {
    $meses = [1 => 'janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho',
              'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
    $ts = strtotime($dataYmd) ?: time();
    return date('d', $ts) . ' de ' . $meses[(int)date('n', $ts)] . ' de ' . date('Y', $ts);
}

/** Monta os campos do merge do contrato de compra a partir da oportunidade+cliente. */
function montarCamposContratoCompra(int $oportunidadeId): ?array {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT o.*, c.id AS cliente_id, c.nome AS cliente_nome, c.telefone, c.cpf, c.rg, c.cnh,
               c.nacionalidade, c.estado_civil, c.profissao, c.endereco
        FROM oportunidades o JOIN clientes c ON c.id = o.cliente_id
        WHERE o.id = ?
    ");
    $stmt->execute([$oportunidadeId]);
    $op = $stmt->fetch();
    if (!$op) return null;

    $percentual = ($op['valor_fipe_referencia'] && $op['valor_ofertado'])
        ? round((float)$op['valor_ofertado'] / (float)$op['valor_fipe_referencia'] * 100, 2)
        : 0.0;

    return [
        'vendedor_nome'                 => $op['cliente_nome'] ?: '',
        'vendedor_nacionalidade'        => $op['nacionalidade'] ?: 'brasileiro(a)',
        'vendedor_estado_civil'         => $op['estado_civil'] ?: '',
        'vendedor_profissao'            => $op['profissao'] ?: '',
        'vendedor_rg'                   => $op['rg'] ?: '',
        'vendedor_cpf'                  => $op['cpf'] ?: '',
        'vendedor_cnh'                  => $op['cnh'] ?: '',
        'vendedor_endereco'             => $op['endereco'] ?: '',
        'veiculo_marca'                 => $op['veiculo_marca'] ?: '',
        'veiculo_modelo'                => $op['veiculo_modelo'] ?: '',
        'veiculo_ano'                   => $op['veiculo_ano'] ?: '',
        'veiculo_placa'                 => $op['veiculo_placa'] ?: '',
        'veiculo_renavam'               => $op['veiculo_renavam'] ?: '',
        'veiculo_chassi'                => $op['veiculo_chassi'] ?: '',
        'valor_fipe_referencia'         => $op['valor_fipe_referencia'] !== null ? (float)$op['valor_fipe_referencia'] : null,
        'percentual_fipe'               => $percentual,
        'valor_pago_vendedor'           => $op['valor_ofertado'] !== null ? (float)$op['valor_ofertado'] : null,
        'banco_financiamento'           => $op['banco_financiamento'] ?: '',
        'contrato_financiamento_numero' => $op['contrato_financiamento_numero'] ?: '',
        'saldo_financiamento_atual'     => $op['saldo_financiamento_atual'] !== null ? (float)$op['saldo_financiamento_atual'] : null,
        'responsavel_registral'         => $op['cliente_nome'] ?: '',
        'terceiro_quitacao'             => $op['terceiro_quitacao'] ?: '',
        'data_entrega_posse'            => $op['data_entrega_posse'] ? date('d/m/Y', strtotime($op['data_entrega_posse'])) : date('d/m/Y'),
        'seguro_texto'                  => $op['seguro_texto'] ?: '',
        'encargos_texto'                => $op['encargos_texto'] ?: '',
        'testemunha1_nome'              => $op['testemunha1_nome'] ?: '',
        'testemunha1_cpf'               => $op['testemunha1_cpf'] ?: '',
        'testemunha2_nome'              => $op['testemunha2_nome'] ?: '',
        'testemunha2_cpf'               => $op['testemunha2_cpf'] ?: '',
        'data_extenso'                  => formatarDataExtensoPtBr(date('Y-m-d')),
        '_telefone'                     => $op['telefone'],
        '_cliente_id'                   => (int)$op['cliente_id'],
    ];
}

/**
 * Campos sem os quais o contrato não deveria nem ser gerado — "campos
 * essenciais... não poderão ficar em branco" (cláusula 27.2 do próprio
 * modelo). Retorna a lista de rótulos faltando (vazio = tudo ok).
 */
function verificarCamposObrigatoriosContrato(array $campos): array {
    $obrigatorios = [
        'vendedor_nome' => 'Nome do vendedor', 'vendedor_cpf' => 'CPF do vendedor',
        'vendedor_rg' => 'RG do vendedor', 'veiculo_marca' => 'Marca do veículo',
        'veiculo_modelo' => 'Modelo do veículo', 'valor_pago_vendedor' => 'Valor ofertado ao vendedor (bloco 6)',
    ];
    $faltando = [];
    foreach ($obrigatorios as $campo => $label) {
        if (empty($campos[$campo]) && $campos[$campo] !== 0.0) $faltando[] = $label;
    }
    return $faltando;
}

/**
 * Gera o PDF, sobe pra assinatura eletrônica e registra em `contratos`.
 * Retorna ['ok'=>bool, 'erro'=>?string, 'contrato_id'=>?int, 'sign_url'=>?string, 'aviso'=>?string].
 */
function gerarEEnviarContratoCompra(int $oportunidadeId, ?int $usuarioId): array {
    $campos = montarCamposContratoCompra($oportunidadeId);
    if (!$campos) return ['ok' => false, 'erro' => 'Oportunidade não encontrada.'];

    $faltando = verificarCamposObrigatoriosContrato($campos);
    if ($faltando) {
        return ['ok' => false, 'erro' => 'Faltam dados obrigatórios pra gerar o contrato: ' . implode(', ', $faltando) . '.'];
    }

    // Limite contratual de 25% da FIPE (cláusula 1.2) — nunca decide sozinho
    // se segue ou não, só avisa; a decisão de negociação é sempre do consultor.
    $aviso = ($campos['percentual_fipe'] > 25)
        ? "Percentual pago ({$campos['percentual_fipe']}%) excede o limite contratual de 25% da FIPE — confira antes de enviar pra assinatura."
        : null;

    $pdfPath = gerarPdfContratoCompra($campos);
    $nomeDoc = 'Contrato de Compra - ' . ($campos['vendedor_nome'] ?: "Oportunidade #{$oportunidadeId}");

    // Guarda uma cópia própria (Drive preferido, storage/uploads/ como
    // fallback — mesmo padrão de includes/documentos.php) ANTES de mandar
    // pra assinatura, pra dar pra visualizar o contrato no sistema
    // (admin/ver_contrato.php) mesmo enquanto ainda está esperando
    // assinatura — nunca dependeu disso pra decidir se segue com o envio.
    $nomeArquivoCopia = 'contrato_compra_' . $oportunidadeId . '_' . time() . '.pdf';
    $copia = salvarArquivoGeradoComoDocumento(
        $campos['_cliente_id'], $campos['vendedor_nome'], $pdfPath, $nomeArquivoCopia,
        'application/pdf', 'contratos/' . $oportunidadeId
    );

    // ZapSign cria documento + signatário numa chamada só (diferente da
    // Assinafy, que precisava de 3 chamadas separadas) — telefone é o
    // canal de verificação/notificação quando existe.
    $docRes = zapsignCriarDocumentoEAssinatura($pdfPath, $nomeDoc, $campos['vendedor_nome'], $campos['_telefone']);
    @unlink($pdfPath);
    if (isset($docRes['error'])) {
        return ['ok' => false, 'erro' => 'Falha ao enviar pra assinatura: ' . $docRes['error']];
    }

    $db = getDB();
    $db->prepare("
        INSERT INTO contratos
            (oportunidade_id, tipo, nome, campos_json, zapsign_doc_token, zapsign_signer_token, sign_url, status, drive_file_id, arquivo_url, created_by)
        VALUES (?, 'compra', ?, ?, ?, ?, ?, 'enviado', ?, ?, ?)
    ")->execute([
        $oportunidadeId, $nomeDoc, json_encode($campos), $docRes['doc_token'], $docRes['signer_token'],
        $docRes['sign_url'], $copia['drive_file_id'], $copia['arquivo_url'], $usuarioId,
    ]);

    return [
        'ok' => true,
        'contrato_id' => (int)$db->lastInsertId(),
        'sign_url' => $docRes['sign_url'],
        'aviso' => $aviso,
    ];
}

/**
 * Consulta o status do contrato na ZapSign e sincroniza — usado pelo
 * webhook (api/zapsign_webhook.php) e pelo polling de fallback
 * (cron/zapsign_sync.php). Quando assinado, baixa o PDF final e sobe pra
 * pasta do cliente no Drive, marcando como documento da pasta fechada
 * (bloco 8) — o checklist de fechamento (regra #7) passa a contar com ele.
 */
function zapsignSincronizarContrato(int $contratoId): void {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM contratos WHERE id = ?");
    $stmt->execute([$contratoId]);
    $c = $stmt->fetch();
    if (!$c || !$c['zapsign_doc_token']) return;

    $statusRes = zapsignStatusDocumento($c['zapsign_doc_token']);
    if (isset($statusRes['error'])) return;

    $novoStatus = CONTRATOS_STATUS_ZAPSIGN[$statusRes['status']] ?? $c['status'];

    // Só sai cedo se REALMENTE não tem nada pra fazer: status igual E (se já
    // assinado) já garantiu uma cópia de verdade. Sem o segundo checar, um
    // download que falhasse uma vez (link temporário expirado, rede) nunca
    // mais tentaria de novo — status já teria virado 'assinado' e o
    // early-return bloquearia toda tentativa futura, ficando pra sempre sem
    // cópia nenhuma do contrato assinado (bug real, achado testando).
    $jaTemCopiaAssinada = $c['status'] === 'assinado' && ($c['drive_file_id'] || $c['arquivo_url']);
    if ($novoStatus === $c['status'] && $jaTemCopiaAssinada) return;

    $driveFileId = $c['drive_file_id'];
    $arquivoUrl = $c['arquivo_url'];

    // Tenta baixar/guardar a cópia assinada sempre que o status for
    // 'assinado' e ainda não tiver copia — cobre tanto a transição normal
    // quanto o retry de uma tentativa anterior que falhou.
    if ($novoStatus === 'assinado' && !$jaTemCopiaAssinada) {
        $conteudo = zapsignBaixarAssinado($c['zapsign_doc_token']);
        if ($conteudo) {
            $tmp = tempnam(sys_get_temp_dir(), 'contrato_assinado_') . '.pdf';
            file_put_contents($tmp, $conteudo);

            $stmtCli = $db->prepare("
                SELECT cl.id, cl.nome FROM oportunidades o JOIN clientes cl ON cl.id = o.cliente_id WHERE o.id = ?
            ");
            $stmtCli->execute([$c['oportunidade_id']]);
            $cli = $stmtCli->fetch();

            if ($cli) {
                $nomeArquivo = 'contrato_compra_assinado_' . $c['oportunidade_id'] . '.pdf';
                $copia = salvarArquivoGeradoComoDocumento(
                    (int)$cli['id'], $cli['nome'] ?: "Cliente #{$cli['id']}", $tmp, $nomeArquivo,
                    'application/pdf', 'contratos/' . $c['oportunidade_id']
                );
                if ($copia['drive_file_id'] || $copia['arquivo_url']) {
                    $driveFileId = $copia['drive_file_id'];
                    $arquivoUrl  = $copia['arquivo_url'];
                }
            }
            @unlink($tmp);

            // A pasta fechada (bloco 8, regra #7) só conta esse documento
            // como presente aqui — na geração (ainda sem assinar) de
            // propósito NÃO grava em oportunidade_documentos, senão o
            // checklist de fechamento passaria mesmo sem assinatura.
            if ($driveFileId || $arquivoUrl) {
                $db->prepare("
                    INSERT INTO oportunidade_documentos (oportunidade_id, tipo, drive_file_id, arquivo_url, obrigatorio, enviado_pelo_cliente, updated_at)
                    VALUES (?, 'contrato_compra', ?, ?, 1, 0, datetime('now','localtime'))
                    ON CONFLICT(oportunidade_id, tipo) DO UPDATE SET
                        drive_file_id = excluded.drive_file_id, arquivo_url = excluded.arquivo_url,
                        updated_at = datetime('now','localtime')
                ")->execute([$c['oportunidade_id'], $driveFileId, $arquivoUrl]);

                $db->prepare("UPDATE oportunidades SET contrato_assinado = 1 WHERE id = ?")->execute([$c['oportunidade_id']]);
            }
        }
    }

    $db->prepare("
        UPDATE contratos SET status = ?, drive_file_id = ?, arquivo_url = ?, updated_at = datetime('now','localtime') WHERE id = ?
    ")->execute([$novoStatus, $driveFileId, $arquivoUrl, $contratoId]);
}
