<?php
/**
 * cron/frota_prazo_aviso.php — 1x por dia: avisa a equipe (sino + WhatsApp de
 * notificação) dos veículos da frota que ENTRARAM numa faixa de prazo de
 * quitação (6/12/18 meses, 3 anos = risco de banco). Ver includes/frota_prazo.php.
 * Dedup por dia + flock().
 */

define('ROOT', dirname(__DIR__));
require_once ROOT . '/includes/db.php';
require_once ROOT . '/includes/frota_prazo.php';

function log_frota_prazo(string $msg): void {
    $dir = ROOT . '/storage/logs';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $line = '[' . date('Y-m-d H:i:s') . '] ' . rtrim($msg, "\n") . "\n";
    file_put_contents($dir . '/frota_prazo_' . date('Y-m') . '.log', $line, FILE_APPEND);
    echo $line;
}

$lock = fopen(ROOT . '/storage/frota_prazo_aviso.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    log_frota_prazo('Já existe uma execução em andamento — abortando esta.');
    exit;
}

$dedup = 'frota_prazo_aviso_rodou_' . date('Y-m-d');
if (getConfig($dedup)) {
    log_frota_prazo('Já rodou hoje.');
} else {
    $r = frotaPrazoProcessarAvisos();
    setConfig($dedup, '1');
    log_frota_prazo(($r['inicial'] ? '1ª execução (marcou sem avisar, só risco de banco). ' : '')
        . "{$r['novos']} veículo(s) entraram numa faixa nova; {$r['risco_total']} em risco de banco; WhatsApp " . ($r['enviado_whatsapp'] ? 'enviado' : 'não enviado') . '.');
}

flock($lock, LOCK_UN);
fclose($lock);
