<?php
/**
 * Webhook de recebimento — WhatsApp Cloud API (Meta oficial), 25/09/2026.
 * Endpoint NOVO, separado do webhook Z-API (whatsapp.php) — formato de
 * payload completamente diferente (entry[].changes[].value), então nunca
 * faz sentido tentar rotear os dois pela mesma URL.
 *
 * Fase 1 (ver includes/whatsapp_oficial.php): só o canal PRINCIPAL, só
 * texto — reaproveita processarMensagemZapi() já testado via
 * oficialAdaptarPayloadParaZapi(), sem duplicar lógica de dedup/
 * qualificação por IA.
 *
 * 30/09/2026, "zpi vendas zpi financeiro não faz mais sentido" → "meta só
 * permite mais um numero no aplicativo aprovado": só o canal de VENDAS
 * ganhou número Meta oficial DEDICADO (financeiro fica de fora — não
 * existe 3º número). Os 2 números (principal e vendas) compartilham a
 * MESMA URL de webhook/App — a Cloud API nunca separa isso por número,
 * só o `metadata.phone_number_id` dentro do payload diferencia de qual
 * número veio. `oficialIdentificarCanal()` (includes/whatsapp_oficial.php)
 * resolve isso ANTES de processar, roteando pra
 * processarMensagemVendasZapi() (mesmo processador que a Z-API dedicada de
 * vendas já usa, chatbot-whatsapp/includes/mensagens_vendas.php) quando o
 * número bate com o de vendas — mesma disciplina de "reaproveita 100% do
 * que já foi testado" da Fase 1/2.
 *
 * 2 métodos, como toda Cloud API:
 *   GET  — handshake de verificação (Meta chama 1x quando você salva a URL
 *          do webhook no App Dashboard): confere hub.verify_token contra
 *          config.whatsapp_oficial_verify_token, responde o hub.challenge
 *          cru se bater, 403 se não.
 *   POST — mensagem/evento de verdade.
 */

define('ROOT', dirname(__DIR__, 2));
require_once ROOT . '/includes/db.php';
require_once ROOT . '/includes/security.php';
require_once ROOT . '/includes/whatsapp_config.php';
require_once ROOT . '/includes/whatsapp_oficial.php';
require_once ROOT . '/includes/oportunidades.php';
require_once ROOT . '/includes/vendas.php';
require_once ROOT . '/includes/zapi_instancias.php';
require_once ROOT . '/chatbot-whatsapp/includes/mensagens.php';
require_once ROOT . '/chatbot-whatsapp/includes/mensagens_vendas.php';

header('Content-Type: application/json');

function log_webhook_oficial(string $msg): void {
    $dir = ROOT . '/storage/logs';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $line = '[' . date('Y-m-d H:i:s') . '] ' . rtrim($msg, "\n") . "\n";
    file_put_contents($dir . '/whatsapp_oficial_webhook_' . date('Y-m-d') . '.log', $line, FILE_APPEND);
}

// --- Handshake de verificação (GET) ---
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $modo = $_GET['hub_mode'] ?? $_GET['hub.mode'] ?? '';
    $tokenRecebido = $_GET['hub_verify_token'] ?? $_GET['hub.verify_token'] ?? '';
    $challenge = $_GET['hub_challenge'] ?? $_GET['hub.challenge'] ?? '';
    [, , $verifyToken] = oficialCredenciais();

    if ($modo === 'subscribe' && $verifyToken !== '' && hash_equals($verifyToken, (string)$tokenRecebido)) {
        log_webhook_oficial('Handshake de verificação OK.');
        header('Content-Type: text/plain');
        echo $challenge;
        exit;
    }
    log_webhook_oficial('Handshake de verificação FALHOU — token não bateu.');
    http_response_code(403);
    echo 'Verify token inválido.';
    exit;
}

// --- Mensagem/evento (POST) ---
$raw = file_get_contents('php://input');
$body = json_decode($raw, true);

if (!is_array($body)) {
    log_webhook_oficial('Payload inválido (não é JSON): ' . substr($raw, 0, 500));
    echo json_encode(['ok' => true]);
    exit;
}

// 30/09/2026 — os 2 números (principal e vendas) compartilham a mesma URL
// de webhook; `metadata.phone_number_id` (presente em TODO payload,
// mensagem ou status) diz de qual número veio. Rejeitado ANTES de adaptar
// o payload — mesma disciplina do `instanceId` desconhecido no webhook
// Z-API (chatbot-whatsapp/webhook/whatsapp.php), nunca processa origem
// que não reconhecemos.
$phoneNumberId = (string)($body['entry'][0]['changes'][0]['value']['metadata']['phone_number_id'] ?? '');
$canalInfo = oficialIdentificarCanal($phoneNumberId);
if ($canalInfo['tipo'] === 'desconhecida') {
    log_webhook_oficial("phone_number_id desconhecido, rejeitando webhook: {$phoneNumberId}");
    http_response_code(401);
    echo json_encode(['ok' => false, 'ignored' => 'unknown_phone_number_id']);
    exit;
}

try {
    $payloadAdaptado = oficialAdaptarPayloadParaZapi($body);
} catch (Throwable $e) {
    log_webhook_oficial('Erro ao adaptar payload (' . get_class($e) . '): ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'erro' => 'erro_interno']);
    exit;
}

if ($payloadAdaptado === null) {
    // status de entrega/leitura, ou payload sem messages[] — nada a fazer.
    echo json_encode(['ok' => true, 'ignored' => 'not_a_message']);
    exit;
}

// 'canal' => 'oficial' — usado por iaProcessarTurno()/enviarTelefoneConsultorAoCliente()
// (via zapiEnviarTextoPeloCanal()) pra garantir que a resposta do MESMO
// turno saia pelo MESMO canal que o cliente usou, nunca pelo toggle
// global sozinho — ver includes/whatsapp_config.php::zapiEnviarTextoPeloCanal().
$instancia = ['tipo' => $canalInfo['tipo'], 'usuario_id' => null, 'client_token' => null, 'canal' => 'oficial'];

try {
    $resultado = $canalInfo['tipo'] === 'vendas'
        ? processarMensagemVendasZapi($payloadAdaptado, $instancia)
        : processarMensagemZapi($payloadAdaptado, $instancia);
} catch (Throwable $e) {
    log_webhook_oficial('Erro ao processar mensagem (' . get_class($e) . '): ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'erro' => 'erro_interno']);
    exit;
}

if (!empty($resultado['erro_oportunidade'])) {
    log_webhook_oficial("Erro ao criar/abrir oportunidade ({$resultado['telefone']}): {$resultado['erro_oportunidade']}");
}
if (!empty($resultado['erro_venda'])) {
    log_webhook_oficial("Erro ao criar/abrir lead de venda ({$resultado['telefone']}): {$resultado['erro_venda']}");
}

echo json_encode(['ok' => true]);
