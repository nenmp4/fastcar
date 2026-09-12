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
 * ⚠️ Pendência #3 do CLAUDE.md: a IA de qualificação (Gemini/OpenAI) ainda
 * não foi decidida. Este webhook garante a parte que já é regra fechada —
 * dedup, registro da conversa, abertura da oportunidade — e deixa o ponto
 * de entrada da IA marcado como TODO, pra plugar sem reabrir o resto.
 */

define('ROOT', dirname(__DIR__, 2));
require_once ROOT . '/includes/db.php';
require_once ROOT . '/includes/security.php';
require_once ROOT . '/includes/whatsapp_config.php';
require_once ROOT . '/includes/oportunidades.php';
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

// client-token: Z-API devolve no header o mesmo token configurado na
// instância. Só valida se já tiver instância configurada — em fase de
// setup (sem credencial real ainda), deixa passar pra não travar teste.
$clientTokenEsperado = getConfig('zapi_client_token');
if ($clientTokenEsperado) {
    $recebido = $_SERVER['HTTP_CLIENT_TOKEN'] ?? '';
    if (!hash_equals($clientTokenEsperado, $recebido)) {
        log_webhook('client-token inválido no header, ignorando webhook.');
        http_response_code(401);
        responderOk(['ignored' => 'invalid_token']);
    }
}

// Formato do ReceivedCallback do Z-API:
// { messageId, phone, fromMe, isGroup, momment, senderName, chatName,
//   text: {message: "..."}, image: {...}, audio: {...}, ... }
$messageId = (string)($payload['messageId'] ?? $payload['id'] ?? '');
$phone     = (string)($payload['phone'] ?? '');
$fromMe    = !empty($payload['fromMe']);
$isGroup   = !empty($payload['isGroup']) || str_contains($phone, '-group');

if (!$phone) {
    log_webhook('Webhook sem phone, ignorando. Payload: ' . substr($raw, 0, 300));
    responderOk(['ignored' => 'no_phone']);
}

if ($fromMe) {
    // Registrado no histórico (consultor pode ter respondido manualmente
    // pelo próprio WhatsApp/app oficial, fora do nosso código), mas não é
    // "entrada" — não roda nenhuma lógica de bot/oportunidade em cima.
    registrarMensagem($phone, 'out', extrairTexto($payload) ?? '[' . tipoMidia($payload) . ']', $messageId ?: null, false);
    responderOk(['ignored' => 'from_me']);
}

if ($isGroup) {
    log_webhook("Mensagem de grupo ignorada ({$phone}).");
    responderOk(['ignored' => 'group']);
}

if ($messageId && jaProcessado($messageId)) {
    responderOk(['ignored' => 'duplicate']);
}

$texto = extrairTexto($payload);
$tipoRegistro = 'text';
if ($texto === null) {
    $tipoRegistro = tipoMidia($payload);
    $texto = '[' . $tipoRegistro . ']'; // marcador — mantém a mensagem no histórico mesmo sem interpretar o conteúdo
}

registrarMensagem($phone, 'in', $texto, $messageId ?: null, false, $tipoRegistro);

// Cria/abre a oportunidade desde o 1º contato (regra #2) — nunca esperar a
// qualificação terminar pra existir registro, senão conversa abandonada
// não fica salva em lugar nenhum.
$nomeContato = (string)($payload['senderName'] ?? $payload['chatName'] ?? '');
try {
    criarOuAbrirOportunidade($phone, $nomeContato);
} catch (Throwable $e) {
    log_webhook("Erro ao criar/abrir oportunidade ({$phone}): " . $e->getMessage());
}

// Passagem pro consultor pausa a IA (regra #4) — se já está pausada, só
// guardamos a mensagem; um humano está respondendo por fora do fluxo
// automático, a IA não pode responder por cima.
if (iaPausada($phone)) {
    responderOk(['ia_pausada' => true]);
}

// TODO(pendência #3 do CLAUDE.md): plugar aqui a IA de qualificação
// (Gemini/OpenAI — provedor e prompt ainda não decididos). Quando existir,
// entra depois deste ponto: já tem mensagem salva, oportunidade aberta e
// garantia de que a IA não está pausada — só falta gerar e enviar a
// resposta (via zapiEnviarTexto) e atualizar oportunidades.* com o que for
// extraído da conversa (nunca inventando valor não informado).

responderOk();
