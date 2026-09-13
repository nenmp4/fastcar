<?php
/**
 * includes/contratos.php — Orquestra o contrato-mestre de COMPRA: monta os
 * dados da oportunidade, gera o PDF (includes/contratos_pdf.php), manda
 * pra assinatura eletrônica (includes/assinafy.php) e, quando assinado,
 * sobe o PDF final pra pasta do cliente no Drive (includes/google_drive.php)
 * e marca como documento da pasta fechada (bloco 8, regra #7).
 *
 * Módulo de VENDA fica pra outra etapa (decisão do Jean) — não implementado.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/contratos_pdf.php';
require_once __DIR__ . '/assinafy.php';
require_once __DIR__ . '/google_drive.php';
require_once __DIR__ . '/documentos.php'; // garantirPastaDriveCliente()

const CONTRATOS_STATUS_ASSINAFY = [
    'signed'    => 'assinado', 'completed' => 'assinado',
    'declined'  => 'recusado', 'rejected'  => 'recusado',
    'pending'   => 'enviado',  'viewed'    => 'visualizado',
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
    // se segue ou não, só avisa; a decisão de negociação é sempre do closer.
    $aviso = ($campos['percentual_fipe'] > 25)
        ? "Percentual pago ({$campos['percentual_fipe']}%) excede o limite contratual de 25% da FIPE — confira antes de enviar pra assinatura."
        : null;

    $pdfPath = gerarPdfContratoCompra($campos);
    $nomeDoc = 'Contrato de Compra - ' . ($campos['vendedor_nome'] ?: "Oportunidade #{$oportunidadeId}");

    $uploadRes = assinafyUploadPdf($pdfPath, $nomeDoc);
    @unlink($pdfPath);
    if (isset($uploadRes['error'])) {
        return ['ok' => false, 'erro' => 'Falha no upload pra assinatura: ' . $uploadRes['error']];
    }
    $docId = $uploadRes['document_id'];

    // E-mail não é coletado hoje no formulário do cliente — usa um
    // sintético baseado no id (Assinafy exige o campo); WhatsApp é o canal
    // de verificação real quando o telefone existe.
    $email = "cliente{$campos['_cliente_id']}@fastcar.assinatura.invalido";
    $signerRes = assinafyCriarSignatario($campos['vendedor_nome'], $email, $campos['_telefone']);
    if (isset($signerRes['error'])) {
        return ['ok' => false, 'erro' => 'Falha ao criar signatário: ' . $signerRes['error']];
    }

    $assignRes = assinafyCriarAssignment($docId, $signerRes['signer_id'], !empty($campos['_telefone']));
    if (isset($assignRes['error'])) {
        return ['ok' => false, 'erro' => 'Falha ao criar assinatura: ' . $assignRes['error']];
    }

    $db = getDB();
    $db->prepare("
        INSERT INTO contratos
            (oportunidade_id, tipo, nome, campos_json, assinafy_doc_id, assinafy_assignment_id, assinafy_signer_id, sign_url, status, created_by)
        VALUES (?, 'compra', ?, ?, ?, ?, ?, ?, 'enviado', ?)
    ")->execute([
        $oportunidadeId, $nomeDoc, json_encode($campos), $docId,
        $assignRes['assignment_id'], $signerRes['signer_id'], $assignRes['sign_url'] ?? '', $usuarioId,
    ]);

    return [
        'ok' => true,
        'contrato_id' => (int)$db->lastInsertId(),
        'sign_url' => $assignRes['sign_url'] ?? '',
        'aviso' => $aviso,
    ];
}

/**
 * Consulta o status do contrato na Assinafy e sincroniza — usado pelo
 * webhook (api/assinafy_webhook.php) e pelo polling de fallback
 * (cron/assinafy_sync.php). Quando assinado, baixa o PDF final e sobe pra
 * pasta do cliente no Drive, marcando como documento da pasta fechada
 * (bloco 8) — o checklist de fechamento (regra #7) passa a contar com ele.
 */
function assinafySincronizarContrato(int $contratoId): void {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM contratos WHERE id = ?");
    $stmt->execute([$contratoId]);
    $c = $stmt->fetch();
    if (!$c || !$c['assinafy_doc_id']) return;

    $statusRes = assinafyStatusDocumento($c['assinafy_doc_id']);
    if (isset($statusRes['error'])) return;

    $novoStatus = CONTRATOS_STATUS_ASSINAFY[$statusRes['status']] ?? $c['status'];
    if ($novoStatus === $c['status']) return; // nada mudou, evita trabalho à toa

    $driveFileId = $c['drive_file_id'];

    if ($novoStatus === 'assinado' && !$c['drive_file_id']) {
        $conteudo = assinafyBaixarAssinado($c['assinafy_doc_id']);
        if ($conteudo) {
            $tmp = tempnam(sys_get_temp_dir(), 'contrato_assinado_') . '.pdf';
            file_put_contents($tmp, $conteudo);

            $stmtCli = $db->prepare("
                SELECT cl.id, cl.nome FROM oportunidades o JOIN clientes cl ON cl.id = o.cliente_id WHERE o.id = ?
            ");
            $stmtCli->execute([$c['oportunidade_id']]);
            $cli = $stmtCli->fetch();

            $drive = new GoogleDrive();
            if ($cli && $drive->hasCredentials() && $drive->authenticate()) {
                $pastaId = garantirPastaDriveCliente($drive, (int)$cli['id'], $cli['nome'] ?: "Cliente #{$cli['id']}");
                if ($pastaId) {
                    $nomeArquivo = 'contrato_compra_assinado_' . $c['oportunidade_id'] . '.pdf';
                    $idDrive = $drive->uploadFile($tmp, $nomeArquivo, 'application/pdf', $pastaId);
                    if ($idDrive) $driveFileId = $idDrive;
                }
            }
            @unlink($tmp);

            if ($driveFileId) {
                $db->prepare("
                    INSERT INTO oportunidade_documentos (oportunidade_id, tipo, drive_file_id, obrigatorio, enviado_pelo_cliente, updated_at)
                    VALUES (?, 'contrato_compra', ?, 1, 0, datetime('now','localtime'))
                    ON CONFLICT(oportunidade_id, tipo) DO UPDATE SET
                        drive_file_id = excluded.drive_file_id, updated_at = datetime('now','localtime')
                ")->execute([$c['oportunidade_id'], $driveFileId]);

                $db->prepare("UPDATE oportunidades SET contrato_assinado = 1 WHERE id = ?")->execute([$c['oportunidade_id']]);
            }
        }
    }

    $db->prepare("
        UPDATE contratos SET status = ?, drive_file_id = ?, updated_at = datetime('now','localtime') WHERE id = ?
    ")->execute([$novoStatus, $driveFileId, $contratoId]);
}
