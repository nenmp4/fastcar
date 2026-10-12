<?php
/**
 * Webhook de recebimento — Evolution API (self-hosted), 09/10/2026.
 * Endpoint NOVO, separado dos webhooks Z-API (whatsapp.php) e Meta oficial
 * (whatsapp_oficial.php) — formato de payload próprio (envelope
 * {event, instance, data}), nunca faz sentido tentar rotear pela mesma URL.
 *
 * Só 1 método, diferente da Cloud API (sem handshake de verificação GET —
 * a Evolution não exige esse passo, confirmado via docs):
 *   POST — mensagem/evento de verdade.
 *
 * Validação de origem: pelo nome da `instance` no corpo do payload (sempre
 * presente no envelope, confirmado contra a doc), não pelo campo `apikey`
 * — uma fonte sugeriu que o webhook também carrega `apikey` no corpo, mas
 * isso nunca foi confirmado contra a doc real desta instalação; validar
 * por um campo não confirmado arriscaria rejeitar 100% do tráfego real,
 * mesmo erro já cometido uma vez neste projeto com o Client-Token da Z-API
 * (ver CLAUDE.md, "checagem de client-token no header REMOVIDA").
 *
 * 12/10/2026 — "adcona evlotion instancias adccionas vendas e financeiro":
 * MESMA URL agora atende as 3 instâncias (principal/vendas/financeiro,
 * cada uma pareada separadamente no mesmo servidor self-hosted) —
 * `evolutionIdentificarCanal()` (includes/whatsapp_evolution.php) resolve
 * qual é qual pelo nome da instância no payload, mesmo papel que
 * `zapiIdentificarInstancia()` já cumpre pro webhook Z-API
 * (chatbot-whatsapp/webhook/whatsapp.php) — roteamento espelhado 1:1
 * daquele arquivo.
 */

define('ROOT', dirname(__DIR__, 2));
require_once ROOT . '/includes/db.php';
require_once ROOT . '/includes/security.php';
require_once ROOT . '/includes/whatsapp_config.php';
require_once ROOT . '/includes/whatsapp_evolution.php';
require_once ROOT . '/includes/oportunidades.php';
require_once ROOT . '/includes/vendas.php';
require_once ROOT . '/includes/zapi_instancias.php';
require_once ROOT . '/chatbot-whatsapp/includes/mensagens.php';
require_once ROOT . '/chatbot-whatsapp/includes/mensagens_vendas.php';
require_once ROOT . '/chatbot-whatsapp/includes/mensagens_financeiro.php';

header('Content-Type: application/json');

function log_webhook_evolution(string $msg): void {
    $dir = ROOT . '/storage/logs';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $line = '[' . date('Y-m-d H:i:s') . '] ' . rtrim($msg, "\n") . "\n";
    file_put_contents($dir . '/whatsapp_evolution_webhook_' . date('Y-m-d') . '.log', $line, FILE_APPEND);
}

$raw = file_get_contents('php://input');
$body = json_decode($raw, true);

if (!is_array($body)) {
    log_webhook_evolution('Payload inválido (não é JSON): ' . substr($raw, 0, 500));
    echo json_encode(['ok' => true]);
    exit;
}

// Identifica de qual instância veio (principal/vendas/financeiro/
// desconhecida) pelo nome no payload — nunca processa origem que não
// reconhecemos, mesma disciplina do instanceId/phone_number_id
// desconhecido nos outros 2 webhooks.
$instanceNoPayload = (string)($body['instance'] ?? '');
$instancia = evolutionIdentificarCanal($instanceNoPayload);
if ($instancia['tipo'] === 'desconhecida') {
    log_webhook_evolution("Instância desconhecida/não configurada, rejeitando webhook: \"{$instanceNoPayload}\"");
    http_response_code(401);
    echo json_encode(['ok' => false, 'ignored' => 'unknown_instance']);
    exit;
}

try {
    $payloadAdaptado = evolutionAdaptarPayloadParaZapi($body);
} catch (Throwable $e) {
    log_webhook_evolution('Erro ao adaptar payload (' . get_class($e) . '): ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'erro' => 'erro_interno']);
    exit;
}

if ($payloadAdaptado === null) {
    // evento que não é messages.upsert, ou sem remoteJid resolvível — nada a fazer.
    echo json_encode(['ok' => true, 'ignored' => 'not_a_message']);
    exit;
}

// Instância de vendas/financeiro tem processador PRÓPRIO — mesmo
// roteamento de chatbot-whatsapp/webhook/whatsapp.php (Z-API), só que
// identificado pelo NOME da instância Evolution em vez de instanceId.
// 'canal' => 'evolution' (já vem em $instancia) usado por
// iaProcessarTurno()/enviarTelefoneConsultorAoCliente() (via
// zapiEnviarTextoPeloCanal()) pra garantir que a resposta do MESMO turno
// saia pelo MESMO canal que o cliente usou.
try {
    $resultado = match ($instancia['tipo']) {
        'vendas' => processarMensagemVendasZapi($payloadAdaptado, $instancia),
        'financeiro' => processarMensagemFinanceiroZapi($payloadAdaptado, $instancia),
        default => processarMensagemZapi($payloadAdaptado, $instancia),
    };
} catch (Throwable $e) {
    log_webhook_evolution('Erro ao processar mensagem (' . get_class($e) . '): ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'erro' => 'erro_interno']);
    exit;
}

if (!empty($resultado['erro_oportunidade'])) {
    log_webhook_evolution("Erro ao criar/abrir oportunidade ({$resultado['telefone']}): {$resultado['erro_oportunidade']}");
}
if (!empty($resultado['erro_venda'])) {
    log_webhook_evolution("Erro ao criar/abrir lead de venda ({$resultado['telefone']}): {$resultado['erro_venda']}");
}

echo json_encode(['ok' => true]);
