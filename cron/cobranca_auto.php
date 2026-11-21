<?php
/**
 * cron/cobranca_auto.php — cobrança automática pelo WhatsApp da ouvidoria.
 * Roda a cada 10 min; só faz algo quando ligada (Financeiro → Lançamentos →
 * 🤖 Cobrança automática) e quando chega um dos horários configurados (seg–sáb).
 * Cada horário roda 1 vez por dia, até N clientes, com pausa de 20–60s entre
 * envios. Ver includes/cobranca_ouvidoria.php.
 */

define('ROOT', dirname(__DIR__));
require_once ROOT . '/includes/db.php';
require_once ROOT . '/includes/cobranca_ouvidoria.php';

function log_cobranca_auto(string $msg): void {
    $dir = ROOT . '/storage/logs';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $line = '[' . date('Y-m-d H:i:s') . '] ' . rtrim($msg, "\n") . "\n";
    file_put_contents($dir . '/cobranca_auto_' . date('Y-m') . '.log', $line, FILE_APPEND);
    echo $line;
}

setConfig('cobranca_auto_ultimo_run', (string)time());

$lock = fopen(ROOT . '/storage/cobranca_auto.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    log_cobranca_auto('Já existe uma execução em andamento — abortando esta.');
    exit;
}

if (cobrancaAutoAtiva() && ($h = cobrancaAutoHorarioDaVez()) !== null) {
    log_cobranca_auto("Lote das {$h} — até " . cobrancaAutoLote() . ' cliente(s).');
    $r = cobrancaAutoRodarLote($h, null, 'log_cobranca_auto');
    log_cobranca_auto("Fim: {$r['enviados']} enviada(s), {$r['falhas']} falha(s)." . (isset($r['erro']) ? " ({$r['erro']})" : ''));
}

flock($lock, LOCK_UN);
fclose($lock);
