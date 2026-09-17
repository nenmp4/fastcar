<?php
/**
 * Serve o comprovante anexado de um lançamento financeiro — mesmo padrão
 * de admin/ver_midia_revenda.php, travado por requireAcessoFinanceiro()
 * (dado financeiro é sensível, restrito ao módulo).
 */

require_once __DIR__ . '/_bootstrap.php';
requireAcessoFinanceiro();

$id = (int)($_GET['id'] ?? 0);
$db = getDB();
$stmt = $db->prepare("SELECT * FROM fin_lancamentos WHERE id = ?");
$stmt->execute([$id]);
$lancamento = $stmt->fetch();

if (!$lancamento) {
    http_response_code(404);
    exit('Lançamento não encontrado.');
}

servirArquivoDriveOuLocal($lancamento['drive_file_id'] ?: null, $lancamento['arquivo_url'] ?: null);
