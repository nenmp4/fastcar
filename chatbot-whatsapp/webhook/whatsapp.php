<?php
/**
 * Webhook de recebimento de mensagens — Z-API (bloco 2 do funil: "Entrada
 * pelo WhatsApp"). Mesmo padrão do JurídicoSaaS
 * (chatbot-whatsapp/webhook/whatsapp.php):
 *
 *   1. Sempre responde 200 rápido — Z-API reenvia em loop se não confirmar
 *   2. Dedup de messageId — evita processar 2x o mesmo webhook (retry)
 *   3. Ignora fromMe — mensagem que nós mesmos mandamos (app oficial, não
 *      pelo nosso código) não é "entrada", mas é registrada no histórico
 *   4. Ignora grupo — Fastcar atende conversa 1:1
 *   5. Salva a mensagem SEMPRE (regra #2 do CLAUDE.md: desde o 1º contato),
 *      mesmo com IA pausada ou mídia sem suporte de leitura ainda
 *   6. IA pausada (regra #4) → só guarda a mensagem, não roda lógica de bot
 *
 * Qualificação por IA (bloco 3, pendência #3 resolvida — Gemini, mesmo
 * padrão do JurídicoSaaS): roda dentro de processarMensagemZapi(), não
 * aqui — ver includes/ia_qualificacao.php. Sem `gemini_api_key`
 * configurado em Configurações, a IA simplesmente não responde (mensagem
 * e oportunidade continuam sendo salvas normalmente).
 *
 * A lógica de processamento em si mora em processarMensagemZapi()
 * (chatbot-whatsapp/includes/mensagens.php) — compartilhada com o
 * simulador de conversa (chatbot-whatsapp/simulate.php), pra nunca a
 * lógica real e a de teste divergirem.
 *
 * ⚠️ Multi-instância: esta MESMA URL recebe webhook tanto da instância
 * principal (funil oficial: entrada, qualificação IA, followup) quanto da
 * instância própria de cada consultor/closer (atendimento a partir do
 * bloco 5 sempre pelo número dele — decisão do Jean) — configuradas em
 * admin/configuracoes.php. O Z-API manda `instanceId` no payload; usamos
 * isso pra descobrir de qual instância veio (zapiIdentificarInstancia())
 * e validar o client-token correto ANTES de processar qualquer coisa.
 */

define('ROOT', dirname(__DIR__, 2));
require_once ROOT . '/includes/db.php';
require_once ROOT . '/includes/security.php';
require_once ROOT . '/includes/whatsapp_config.php';
require_once ROOT . '/includes/oportunidades.php';
require_once ROOT . '/includes/zapi_instancias.php';
require_once ROOT . '/chatbot-whatsapp/includes/mensagens.php';

header('Content-Type: application/json');

function log_webhook(string $msg): void {
    $dir = ROOT . '/storage/logs';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $line = '[' . date('Y-m-d H:i:s') . '] ' . rtrim($msg, "\n") . "\n";
    file_put_contents($dir . '/whatsapp_webhook_' . date('Y-m-d') . '.log', $line, FILE_APPEND);
}

/** Sempre 200 — nunca deixar o Z-API interpretar como falha e reentregar em loop. */
function responderOk(array $extra = []): void {
    echo json_encode(['ok' => true] + $extra);
    exit;
}

$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);

if (!is_array($payload)) {
    log_webhook('Payload inválido (não é JSON): ' . substr($raw, 0, 500));
    responderOk(['ignored' => 'invalid_payload']);
}

// Formato do ReceivedCallback do Z-API:
// { instanceId, messageId, phone, fromMe, isGroup, momment, senderName,
//   chatName, text: {message: "..."}, image: {...}, audio: {...}, ... }
$instancia = zapiIdentificarInstancia((string)($payload['instanceId'] ?? ''));

if ($instancia['tipo'] === 'desconhecida') {
    // instanceId que não bate nem com a principal nem com nenhum consultor
    // cadastrado — nunca processa payload de origem que não reconhecemos.
    log_webhook('instanceId desconhecido, rejeitando webhook: ' . (string)($payload['instanceId'] ?? ''));
    http_response_code(401);
    responderOk(['ignored' => 'unknown_instance']);
}

// client-token: Z-API devolve no header o mesmo token configurado na
// instância que originou o evento (principal ou de um consultor — cada
// uma tem o seu). Só valida se essa instância já tiver client_token
// configurado — em fase de setup (sem credencial real ainda), deixa
// passar pra não travar teste.
if ($instancia['client_token']) {
    $recebido = $_SERVER['HTTP_CLIENT_TOKEN'] ?? '';
    if (!hash_equals($instancia['client_token'], $recebido)) {
        log_webhook('client-token inválido no header, ignorando webhook.');
        http_response_code(401);
        responderOk(['ignored' => 'invalid_token']);
    }
}

$resultado = processarMensagemZapi($payload, $instancia);

if ($resultado['ignored'] === 'no_phone') {
    log_webhook('Webhook sem phone, ignorando. Payload: ' . substr($raw, 0, 300));
    responderOk(['ignored' => 'no_phone']);
}
if ($resultado['ignored'] === 'group') {
    log_webhook("Mensagem de grupo ignorada ({$resultado['telefone']}).");
    responderOk(['ignored' => 'group']);
}
if ($resultado['ignored']) {
    // from_me / duplicate — nada de anormal, não precisa virar linha de log.
    responderOk(['ignored' => $resultado['ignored']]);
}

if ($resultado['erro_oportunidade']) {
    log_webhook("Erro ao criar/abrir oportunidade ({$resultado['telefone']}): {$resultado['erro_oportunidade']}");
}

// Passagem pro consultor pausa a IA (regra #4) — se já está pausada, só
// guardamos a mensagem; um humano está respondendo por fora do fluxo
// automático, a IA não pode responder por cima.
if ($resultado['ia_pausada']) {
    responderOk(['ia_pausada' => true]);
}

// A qualificação por IA já rodou dentro de processarMensagemZapi() (se
// aplicável) — nada mais a fazer aqui, é só responder OK.
responderOk();
