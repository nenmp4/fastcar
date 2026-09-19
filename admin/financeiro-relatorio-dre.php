<?php
/**
 * Streama o DRE gerencial do período direto pro navegador — 19/09/2026,
 * "dre para enviar para contabiidade queremos exatamente nessa pegada".
 * ob_start() ANTES de tudo — fpdf.php vaza um \n depois da tag de
 * fechamento do PHP, que quebra Output('I',...) sem buffer de saída
 * ativo (mesmo cuidado já documentado no JurídicoSaaS, repo irmão, nos
 * arquivos equivalentes de lá).
 */
ob_start();

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../includes/financeiro_dre.php';

requireAcessoFinanceiro();

$db = getDB();

$de = (string)($_GET['de'] ?? date('Y-m-01'));
$ate = (string)($_GET['ate'] ?? date('Y-m-t'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $de)) $de = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ate)) $ate = date('Y-m-t');

$pdf = finGerarDrePdf($db, $de, $ate);
ob_end_clean();
$pdf->Output('I', 'dre_gerencial_' . $de . '_a_' . $ate . '.pdf');
