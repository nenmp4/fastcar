<?php
/**
 * Serve um documento (Google Drive ou storage/uploads/ local, fallback)
 * pro admin logado. Nenhum dos dois é servido diretamente (fora do
 * webroot público / privado no Drive) — este é o único caminho pra
 * ver/baixar, e exige sessão de admin.
 */

require_once __DIR__ . '/_bootstrap.php';

$id = (int)($_GET['id'] ?? 0);
$db = getDB();
$stmt = $db->prepare("SELECT * FROM oportunidade_documentos WHERE id = ?");
$stmt->execute([$id]);
$doc = $stmt->fetch();

if (!$doc || (!$doc['arquivo_url'] && !$doc['drive_file_id'])) {
    http_response_code(404);
    exit('Documento não encontrado.');
}

if ($doc['drive_file_id']) {
    $drive = new GoogleDrive();
    if (!$drive->hasCredentials() || !$drive->authenticate()) {
        http_response_code(503);
        exit('Google Drive indisponível no momento.');
    }
    $arquivo = $drive->download($doc['drive_file_id']);
    if (!$arquivo) {
        http_response_code(502);
        exit('Não foi possível baixar o documento agora. Tente novamente.');
    }
    header('Content-Type: ' . $arquivo['mime']);
    header('Content-Disposition: inline; filename="' . rawurlencode($arquivo['name']) . '"');
    header('Content-Length: ' . strlen($arquivo['content']));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    echo $arquivo['content'];
    exit;
}

// Fallback local — arquivo_url é sempre "{oportunidade_id}/{nome_arquivo}"
// (gerado pelo próprio sistema em includes/documentos.php), mesmo assim
// nunca confia cegamente: normaliza e confere que o caminho final continua
// dentro de UPLOADS_DIR antes de abrir (defesa contra path traversal).
$caminho = realpath(UPLOADS_DIR . '/' . $doc['arquivo_url']);
if (!$caminho || !str_starts_with($caminho, realpath(UPLOADS_DIR) . DIRECTORY_SEPARATOR)) {
    http_response_code(404);
    exit('Arquivo não encontrado.');
}

$mime = mime_content_type($caminho) ?: 'application/octet-stream';
header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="' . basename($caminho) . '"');
header('Content-Length: ' . filesize($caminho));
header('Cache-Control: private, no-store');
readfile($caminho);
