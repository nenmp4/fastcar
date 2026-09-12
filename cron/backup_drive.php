<?php
/**
 * Envia o backup completo mais recente pro Google Drive (pasta Backups,
 * separada da pasta de documentos de cliente) e roda a rotação lá. Rodar
 * depois do cron/backup.php (mesmo dia, horário seguinte).
 *
 * Crontab (ver install/setup_crontab.sh):
 *   0 4 * * * php cron/backup_drive.php >> storage/logs/backup_drive.log 2>&1
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/backup.php';

$isCli = (php_sapi_name() === 'cli');
if (!$isCli) {
    $chave = $_GET['key'] ?? '';
    $esperada = getConfig('backup_cron_key') ?: '';
    if (!$esperada || !hash_equals($esperada, $chave)) { http_response_code(403); exit('Acesso negado.'); }
    header('Content-Type: text/plain; charset=utf-8');
}

echo '[' . date('Y-m-d H:i:s') . "] Enviando backup pro Drive...\n";
$r = backupEnviarDrive();
echo '[' . date('Y-m-d H:i:s') . '] ' . ($r['ok'] ? '✅ ' : '❌ ') . $r['mensagem'] . "\n";
exit($r['ok'] ? 0 : 1);
