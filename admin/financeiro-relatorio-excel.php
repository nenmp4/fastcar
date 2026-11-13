<?php
/**
 * Baixa o relatório financeiro do período em Excel (.xlsx) — 13/11/2026,
 * "gerar relatório financeiro para gerar em excel". Abas Resumo, DRE e
 * Lançamentos (ver includes/financeiro_excel.php). Só GET de leitura, sem
 * efeito colateral.
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../includes/financeiro_excel.php';

requireAcessoFinanceiro();
liberarSessao();

$de = (string)($_GET['de'] ?? date('Y-m-01'));
$ate = (string)($_GET['ate'] ?? date('Y-m-t'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $de)) $de = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ate)) $ate = date('Y-m-t');

try {
    $bytes = finGerarRelatorioExcel(getDB(), $de, $ate);
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Não deu pra gerar o Excel: ' . e($e->getMessage());
    exit;
}

$nome = 'financeiro_' . $de . '_a_' . $ate . '.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $nome . '"');
header('Content-Length: ' . strlen($bytes));
header('Cache-Control: private, no-store');
echo $bytes;
