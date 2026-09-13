<?php
/**
 * Serve um documento de oportunidade_documentos (Google Drive ou
 * storage/uploads/ local, fallback) pro admin logado. Nenhum dos dois é
 * servido diretamente (fora do webroot público / privado no Drive) — este
 * é o único caminho pra ver/baixar, e exige sessão de admin. Lógica de
 * fato compartilhada com admin/ver_contrato.php via
 * includes/documentos.php::servirArquivoDriveOuLocal().
 */

require_once __DIR__ . '/_bootstrap.php';

$id = (int)($_GET['id'] ?? 0);
$db = getDB();
$stmt = $db->prepare("SELECT * FROM oportunidade_documentos WHERE id = ?");
$stmt->execute([$id]);
$doc = $stmt->fetch();

if (!$doc) {
    http_response_code(404);
    exit('Documento não encontrado.');
}

servirArquivoDriveOuLocal($doc['drive_file_id'] ?: null, $doc['arquivo_url'] ?: null);
