<?php
/**
 * Serve um documento de venda_documentos (Google Drive ou storage/uploads/
 * local, fallback) pro admin logado — mesmo padrão de admin/ver_documento.php
 * (funil de compra), espelhado pro módulo de vendas. Lógica de servir o
 * arquivo compartilhada via includes/documentos.php::servirArquivoDriveOuLocal()
 * (já genérica, nenhuma mudança precisou lá).
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../includes/acesso_documentos.php';
requireAcessoVendas();

$id = (int)($_GET['id'] ?? 0);
$db = getDB();
$stmt = $db->prepare("SELECT * FROM venda_documentos WHERE id = ?");
$stmt->execute([$id]);
$doc = $stmt->fetch();

if (!$doc) {
    http_response_code(404);
    exit('Documento não encontrado.');
}

$detalheDoc = auditoriaRotuloTipoDocumento((string)$doc['tipo']) . ' — ' . auditoriaContextoNegocio(null, (int)$doc['venda_id']);
if (!usuarioPodeAbrirDocumentoNegocio(null, (int)$doc['venda_id'])) {
    negarAcessoDocumento('venda_documento', (int)$doc['id'], $detalheDoc);
}

auditoriaRegistrarDocumentoAberto('venda_documento', (int)$doc['id'], $detalheDoc);

servirArquivoDriveOuLocal($doc['drive_file_id'] ?: null, $doc['arquivo_url'] ?: null);
