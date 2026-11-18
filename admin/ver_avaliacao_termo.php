<?php
/**
 * Serve o PDF do termo de entrega/vistoria (rascunho ou já assinado) —
 * mesmo padrão de admin/ver_contrato.php, só que lendo direto de
 * veiculo_avaliacoes (drive_file_id/arquivo_url), sem passar por `contratos`
 * (ver includes/veiculo_avaliacoes.php pro motivo de não reaproveitar).
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../includes/acesso_documentos.php';
requireAcessoAvaliacoes();

$id = (int)($_GET['id'] ?? 0);
$db = getDB();
$stmt = $db->prepare("SELECT * FROM veiculo_avaliacoes WHERE id = ?");
$stmt->execute([$id]);
$av = $stmt->fetch();

if (!$av) {
    http_response_code(404);
    exit('Avaliação não encontrada.');
}

if (!usuarioPodeAbrirDocumentoNegocio((int)$av['oportunidade_id'] ?: null, !empty($av['venda_id']) ? (int)$av['venda_id'] : null)) {
    negarAcessoDocumento('vistoria_termo', (int)$av['id'], 'Termo de vistoria #' . (int)$av['id']);
}

auditoriaRegistrarDocumentoAberto('vistoria_termo', (int)$av['id'],
    'Termo de vistoria #' . (int)$av['id'] . ' — ' . auditoriaContextoNegocio((int)$av['oportunidade_id'] ?: null, !empty($av['venda_id']) ? (int)$av['venda_id'] : null));

servirArquivoDriveOuLocal($av['drive_file_id'] ?: null, $av['arquivo_url'] ?: null);
