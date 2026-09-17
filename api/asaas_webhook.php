<?php
/**
 * api/asaas_webhook.php — recebe eventos de cobrança do Asaas
 * (PAYMENT_RECEIVED, PAYMENT_CONFIRMED, PAYMENT_OVERDUE etc, configurados
 * manualmente no painel do Asaas apontando pra esta URL). Não usa sessão
 * admin (webhook externo).
 *
 * Autenticação opcional via header `asaas-access-token` (nome do header
 * confirmado na doc pública, nunca testado contra um envio real — mesma
 * ressalva de includes/asaas.php) comparado contra
 * config.asaas_webhook_token, se configurado; sem token configurado, aceita
 * qualquer payload (mesmo trade-off já aceito pro zapsign_webhook —
 * confirmação de verdade sempre vem de uma consulta na API, nunca confia
 * cegamente no payload em si, ver includes/asaas.php::asaasProcessarWebhook()).
 */

define('ROOT', dirname(__DIR__));
require_once ROOT . '/includes/db.php';
require_once ROOT . '/includes/asaas.php';

header('Content-Type: application/json');

function log_asaas(string $msg): void {
    $dir = ROOT . '/storage/logs';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    file_put_contents($dir . '/asaas_webhook_' . date('Y-m-d') . '.log',
        '[' . date('Y-m-d H:i:s') . '] ' . rtrim($msg, "\n") . "\n", FILE_APPEND);
}

$tokenEsperado = getConfig('asaas_webhook_token');
if ($tokenEsperado) {
    $tokenRecebido = $_SERVER['HTTP_ASAAS_ACCESS_TOKEN'] ?? '';
    if (!hash_equals($tokenEsperado, $tokenRecebido)) {
        log_asaas('Token de webhook inválido, ignorando.');
        http_response_code(200); // sempre 200 — evita reentrega em loop
        echo json_encode(['ok' => true, 'ignored' => 'token_invalido']);
        exit;
    }
}

$payload = json_decode(file_get_contents('php://input'), true);
if (!is_array($payload)) {
    log_asaas('Payload inválido.');
    echo json_encode(['ok' => true, 'ignored' => 'invalid_payload']);
    exit;
}

try {
    $r = asaasProcessarWebhook($payload);
    log_asaas('Evento ' . ($payload['event'] ?? '?') . ' — payment ' . ($payload['payment']['id'] ?? '?') . ' — ' . json_encode($r));
} catch (Throwable $e) {
    log_asaas('Erro ao processar webhook: ' . $e->getMessage());
}

echo json_encode(['ok' => true]);
