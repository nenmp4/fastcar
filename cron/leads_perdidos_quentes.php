<?php
/**
 * cron/leads_perdidos_quentes.php — 1x/dia reabre lead PERDIDO classificado
 * como QUENTE, parado há mais de 30 dias, no máximo 10 por consultor, sempre
 * pra um consultor diferente de quem já cuidou (16/11/2026). Cada lead volta
 * 1 vez só. Nenhuma mensagem sai pro cliente — o consultor faz o follow-up
 * pelo WhatsApp Box (card roxo "Leads reabertos"). Lógica em
 * reciclarLeadsPerdidosQuentes() (includes/oportunidades.php). Desligável em
 * Configurações → Fila.
 */

define('ROOT', dirname(__DIR__));
require_once ROOT . '/includes/db.php';
require_once ROOT . '/includes/oportunidades.php';

function log_perdidos_quentes(string $msg): void {
    $dir = ROOT . '/storage/logs';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $line = '[' . date('H:i:s') . '] ' . rtrim($msg, "\n") . "\n";
    file_put_contents($dir . '/leads_perdidos_quentes_' . date('Y-m') . '.log', $line, FILE_APPEND);
    echo $line;
}

$lockHandle = fopen(ROOT . '/storage/leads_perdidos_quentes.lock', 'c');
if (!$lockHandle || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    log_perdidos_quentes('Já existe uma execução em andamento — abortando.');
    exit;
}

if (!reciclagemPerdidosQuentesAtiva()) {
    log_perdidos_quentes('Reciclagem de perdidos quentes DESATIVADA em Configurações — nada a fazer.');
    exit;
}

// 1 rodada por dia, mesmo se o cron rodar de novo (retry/crontab duplicado).
$chaveDia = 'leads_perdidos_quentes_rodou_' . date('Y-m-d');
if (getConfig($chaveDia)) {
    log_perdidos_quentes('Já rodou hoje — nada a fazer.');
    exit;
}

$r = reciclarLeadsPerdidosQuentes();
setConfig($chaveDia, date('Y-m-d H:i:s'));
$partes = [];
foreach ($r['por_consultor'] as $n => $q) $partes[] = "{$n}: {$q}";
log_perdidos_quentes("{$r['reabertos']} lead(s) quente(s) reaberto(s) de {$r['candidatos']} candidato(s)" . ($partes ? ' — ' . implode(', ', $partes) : '') . '.');

flock($lockHandle, LOCK_UN);
fclose($lockHandle);
