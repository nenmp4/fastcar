<?php
/**
 * cron/asaas_sync.php — fallback do webhook (api/asaas_webhook.php).
 * Reconsulta o Asaas pra toda cobrança importada ainda pendente/atrasada —
 * cobre o caso do webhook não chegar, mesmo padrão do zapsign_sync.php.
 *
 * Cron sugerido: a cada 30-60 min, mesma frequência do zapsign_sync.php.
 */

define('ROOT', dirname(__DIR__));
require_once ROOT . '/includes/db.php';
require_once ROOT . '/includes/asaas.php';

function log_asaas_sync(string $msg): void {
    $dir = ROOT . '/storage/logs';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $line = '[' . date('H:i:s') . '] ' . rtrim($msg, "\n") . "\n";
    file_put_contents($dir . '/asaas_sync_' . date('Y-m-d') . '.log', $line, FILE_APPEND);
    echo $line;
}

log_asaas_sync('Iniciando em ' . date('d/m/Y H:i'));

if (!asaasConfigured()) {
    log_asaas_sync('Asaas não configurado, nada a fazer.');
    exit;
}

$r = asaasSincronizarPendentes(100);
if (!$r['ok']) {
    log_asaas_sync('Erro: ' . $r['erro']);
} else {
    log_asaas_sync("{$r['atualizados']} cobrança(s) resincronizada(s).");
}

log_asaas_sync('Concluído.');
