<?php
/**
 * Baixa o relatório de faltas e horários (Cartão de ponto) em Excel — 20/11/2026.
 * Só super admin/supervisor. GET de leitura, sem efeito colateral.
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../includes/ponto.php';

requireVisaoGeral();
liberarSessao();

$de = (string)($_GET['de'] ?? date('Y-m-01'));
$ate = (string)($_GET['ate'] ?? date('Y-m-d'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $de)) $de = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ate)) $ate = date('Y-m-d');

try {
    $bytes = pontoRelatorioExcel(pontoRelatorioPeriodo($de, $ate));
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Não deu pra gerar o Excel: ' . e($e->getMessage());
    exit;
}
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="ponto_' . $de . '_a_' . $ate . '.xlsx"');
header('Content-Length: ' . strlen($bytes));
header('Cache-Control: private, no-store');
echo $bytes;
