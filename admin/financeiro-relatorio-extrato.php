<?php
/**
 * Streama o extrato financeiro completo do período direto pro navegador
 * — 19/09/2026, "permita gerar sempre extrato completo para contado[r]".
 * Mesmo cuidado de ob_start() ANTES de tudo do DRE
 * (admin/financeiro-relatorio-dre.php) — fpdf.php vaza um \n depois da
 * tag de fechamento do PHP, que quebra Output('I',...) sem buffer ativo.
 */
ob_start();

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../includes/financeiro_extrato.php';

requireAcessoFinanceiro();

$db = getDB();

$de = (string)($_GET['de'] ?? date('Y-m-01'));
$ate = (string)($_GET['ate'] ?? date('Y-m-t'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $de)) $de = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ate)) $ate = date('Y-m-t');

$pdf = finGerarExtratoPdf($db, $de, $ate);
ob_end_clean();
$pdf->Output('I', 'extrato_financeiro_' . $de . '_a_' . $ate . '.pdf');
