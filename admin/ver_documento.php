<?php
/**
 * Serve um arquivo de storage/uploads/ pro admin logado. storage/ nunca é
 * servido diretamente (fora do webroot público) — este é o único caminho
 * pra ver/baixar um documento enviado, e exige sessão de admin.
 */

require_once __DIR__ . '/_bootstrap.php';

$id = (int)($_GET['id'] ?? 0);
$db = getDB();
$stmt = $db->prepare("SELECT * FROM oportunidade_documentos WHERE id = ?");
$stmt->execute([$id]);
$doc = $stmt->fetch();

if (!$doc || !$doc['arquivo_url']) {
    http_response_code(404);
    exit('Documento não encontrado.');
}

// arquivo_url é sempre "{oportunidade_id}/{nome_arquivo}" (gerado pelo
// próprio sistema em includes/documentos.php) — mesmo assim, nunca confia
// cegamente: normaliza e confere que o caminho final continua dentro de
// UPLOADS_DIR antes de abrir (defesa contra path traversal).
$caminho = realpath(UPLOADS_DIR . '/' . $doc['arquivo_url']);
if (!$caminho || !str_starts_with($caminho, realpath(UPLOADS_DIR) . DIRECTORY_SEPARATOR)) {
    http_response_code(404);
    exit('Arquivo não encontrado.');
}

$mime = mime_content_type($caminho) ?: 'application/octet-stream';
header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="' . basename($caminho) . '"');
header('Content-Length: ' . filesize($caminho));
readfile($caminho);
