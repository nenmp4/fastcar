<?php
/**
 * cron/zapsign_sync.php — fallback do webhook (api/zapsign_webhook.php).
 * Reconsulta a ZapSign pra todo contrato ainda em 'enviado' — cobre o caso
 * do webhook não chegar (rede, configuração, etc).
 *
 * Cron sugerido: a cada 30-60 min (não precisa de tanta frequência quanto
 * o followup.php — assinatura eletrônica não é tão sensível a atraso de
 * minutos quanto lead esfriando).
 */

define('ROOT', dirname(__DIR__));
require_once ROOT . '/includes/db.php';
require_once ROOT . '/includes/contratos.php';

function log_zapsign_sync(string $msg): void {
    $dir = ROOT . '/storage/logs';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $line = '[' . date('H:i:s') . '] ' . rtrim($msg, "\n") . "\n";
    file_put_contents($dir . '/zapsign_sync_' . date('Y-m-d') . '.log', $line, FILE_APPEND);
    echo $line;
}

$db = getDB();
log_zapsign_sync('Iniciando em ' . date('d/m/Y H:i'));

$pendentes = $db->query("
    SELECT id FROM contratos WHERE status IN ('enviado', 'visualizado') AND zapsign_doc_token != ''
")->fetchAll();

log_zapsign_sync(count($pendentes) . ' contrato(s) pendente(s) de assinatura.');

foreach ($pendentes as $c) {
    try {
        $antes = $db->prepare("SELECT status FROM contratos WHERE id = ?");
        $antes->execute([$c['id']]);
        $statusAntes = $antes->fetchColumn();

        zapsignSincronizarContrato((int)$c['id']);

        $depois = $db->prepare("SELECT status FROM contratos WHERE id = ?");
        $depois->execute([$c['id']]);
        $statusDepois = $depois->fetchColumn();

        if ($statusAntes !== $statusDepois) {
            log_zapsign_sync("Contrato #{$c['id']}: {$statusAntes} → {$statusDepois}");
        }
    } catch (Throwable $e) {
        log_zapsign_sync("Erro no contrato #{$c['id']}: " . $e->getMessage());
    }
}

log_zapsign_sync('Concluído.');
