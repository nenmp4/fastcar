<?php
/**
 * api/assinafy_webhook.php — recebe eventos de assinatura da Assinafy
 * (signer_signed_document, signer_rejected_document, signer_viewed_document
 * — mesmos eventos do JurídicoSaaS). Não usa sessão admin (webhook externo).
 *
 * Só usa o payload pra achar QUAL contrato mudou — a confirmação do status
 * em si sempre vem de uma consulta de verdade na API (assinafySincronizarContrato),
 * nunca confia cegamente no que o webhook mandou.
 */

define('ROOT', dirname(__DIR__));
require_once ROOT . '/includes/db.php';
require_once ROOT . '/includes/contratos.php';

header('Content-Type: application/json');

function log_assinafy(string $msg): void {
    $dir = ROOT . '/storage/logs';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    file_put_contents($dir . '/assinafy_webhook_' . date('Y-m-d') . '.log',
        '[' . date('Y-m-d H:i:s') . '] ' . rtrim($msg, "\n") . "\n", FILE_APPEND);
}

$payload = json_decode(file_get_contents('php://input'), true);
if (!is_array($payload) || empty($payload['event'])) {
    log_assinafy('Payload inválido ou sem event.');
    http_response_code(200); // sempre 200 — Assinafy não deve reentregar em loop
    echo json_encode(['ok' => true, 'ignored' => 'invalid_payload']);
    exit;
}

$data = $payload['data'] ?? [];
$docId        = (string)($data['document_id'] ?? ($data['document']['id'] ?? ''));
$assignmentId = (string)($data['assignment_id'] ?? ($data['assignment']['id'] ?? ''));

if (!$docId && !$assignmentId) {
    echo json_encode(['ok' => true, 'ignored' => 'no_identifier']);
    exit;
}

$db = getDB();
$stmt = $db->prepare("SELECT id FROM contratos WHERE assinafy_doc_id = ? OR assinafy_assignment_id = ? LIMIT 1");
$stmt->execute([$docId, $assignmentId]);
$contratoId = $stmt->fetchColumn();

if (!$contratoId) {
    log_assinafy("Contrato não encontrado pra doc={$docId} assignment={$assignmentId}");
    echo json_encode(['ok' => true, 'ignored' => 'contrato_nao_encontrado']);
    exit;
}

try {
    assinafySincronizarContrato((int)$contratoId);
    log_assinafy("Sincronizado contrato #{$contratoId} — evento {$payload['event']}");
} catch (Throwable $e) {
    log_assinafy("Erro ao sincronizar contrato #{$contratoId}: " . $e->getMessage());
}

echo json_encode(['ok' => true]);
