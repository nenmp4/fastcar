<?php
/**
 * Baixa um arquivo de storage/backups/ — nunca servido diretamente (pasta
 * tem .htaccess Deny from all, ver includes/backup.php::backupGarantirDiretorio()).
 * Um backup carrega TODOS os dados de cliente, então é mais sensível que um
 * documento avulso — restrito ao super_admin, não a qualquer admin logado.
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../includes/backup.php';
requireSuperAdmin();

$nome = basename((string)($_GET['arquivo'] ?? ''));
$caminho = realpath(BACKUP_DIR . '/' . $nome);

if (!$caminho || !str_starts_with($caminho, realpath(BACKUP_DIR) . DIRECTORY_SEPARATOR)) {
    http_response_code(404);
    exit('Arquivo não encontrado.');
}
// Só arquivo de backup — nunca deixa baixar o .htaccess da própria pasta nem outra coisa.
if (!preg_match('/^backup_(db|completo)_\d{4}-\d{2}-\d{2}\.(db|zip)$/', basename($caminho))) {
    http_response_code(404);
    exit('Arquivo não encontrado.');
}

$mime = str_ends_with($caminho, '.zip') ? 'application/zip' : 'application/octet-stream';
header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . basename($caminho) . '"');
header('Content-Length: ' . filesize($caminho));
header('Cache-Control: private, no-store');
readfile($caminho);
