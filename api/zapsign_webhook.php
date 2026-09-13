<?php
/**
 * api/zapsign_webhook.php — recebe eventos de assinatura da ZapSign
 * (doc_signed, doc_refused, etc — configurados manualmente no painel da
 * ZapSign ou via POST /user/company/webhook/, apontando pra esta URL). Não
 * usa sessão admin (webhook externo).
 *
 * Só usa o payload pra achar QUAL contrato mudou — a confirmação do status
 * em si sempre vem de uma consulta de verdade na API (zapsignSincronizarContrato),
 * nunca confia cegamente no que o webhook mandou. Mesmo padrão que o antigo
 * api/assinafy_webhook.php usava.
 */

define('ROOT', dirname(__DIR__));
require_once ROOT . '/includes/db.php';
require_once ROOT . '/includes/contratos.php';

header('Content-Type: application/json');

function log_zapsign(string $msg): void {
    $dir = ROOT . '/storage/logs';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    file_put_contents($dir . '/zapsign_webhook_' . date('Y-m-d') . '.log',
        '[' . date('Y-m-d H:i:s') . '] ' . rtrim($msg, "\n") . "\n", FILE_APPEND);
}

$payload = json_decode(file_get_contents('php://input'), true);
$docToken = (string)($payload['token'] ?? '');

if (!is_array($payload) || !$docToken) {
    log_zapsign('Payload inválido ou sem token.');
    http_response_code(200); // sempre 200 — ZapSign reentrega em loop se não for 200
    echo json_encode(['ok' => true, 'ignored' => 'invalid_payload']);
    exit;
}

$db = getDB();
$stmt = $db->prepare("SELECT id FROM contratos WHERE zapsign_doc_token = ? LIMIT 1");
$stmt->execute([$docToken]);
$contratoId = $stmt->fetchColumn();

if (!$contratoId) {
    log_zapsign("Contrato não encontrado pra doc_token={$docToken}");
    echo json_encode(['ok' => true, 'ignored' => 'contrato_nao_encontrado']);
    exit;
}

try {
    zapsignSincronizarContrato((int)$contratoId);
    log_zapsign("Sincronizado contrato #{$contratoId} — evento " . ($payload['event_type'] ?? '?'));
} catch (Throwable $e) {
    log_zapsign("Erro ao sincronizar contrato #{$contratoId}: " . $e->getMessage());
}

echo json_encode(['ok' => true]);
