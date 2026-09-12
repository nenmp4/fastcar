<?php
/**
 * Backup completo — Fastcar. ZIP com .db + storage/uploads/ (fallback local
 * de documento) + credencial do Drive. 1x/dia. Código não entra: já está
 * versionado no git.
 *
 * Crontab (ver install/setup_crontab.sh):
 *   0 3 * * * php cron/backup.php >> storage/logs/backup.log 2>&1
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

if (getConfig('backup_auto_ativo') === '0') {
    echo '[' . date('Y-m-d H:i:s') . "] Backup automático desativado nas configurações. Abortando.\n";
    exit(0);
}

echo '[' . date('Y-m-d H:i:s') . "] Iniciando backup completo...\n";
$r = backupCompletoZip();
echo '[' . date('Y-m-d H:i:s') . '] ' . ($r['ok'] ? '✅ ' : '❌ ') . $r['mensagem'] . (isset($r['arquivo']) ? ' — ' . $r['arquivo'] : '') . "\n";
exit($r['ok'] ? 0 : 1);
