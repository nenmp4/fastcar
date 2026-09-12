<?php
/**
 * Backup rápido do banco SQLite — Fastcar. Rodar várias vezes ao dia (mesmo
 * padrão do JurídicoSaaS: 2h/8h/13h/18h), pra recuperação rápida.
 *
 * Crontab (ver install/setup_crontab.sh):
 *   0 2,8,13,18 * * * php cron/backup_db.php >> storage/logs/backup_db.log 2>&1
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

echo '[' . date('Y-m-d H:i:s') . "] Iniciando backup do banco...\n";
$r = backupDbCopiar();
echo '[' . date('Y-m-d H:i:s') . '] ' . ($r['ok'] ? '✅ ' : '❌ ') . $r['mensagem'] . (isset($r['arquivo']) ? ' — ' . $r['arquivo'] : '') . "\n";
exit($r['ok'] ? 0 : 1);
