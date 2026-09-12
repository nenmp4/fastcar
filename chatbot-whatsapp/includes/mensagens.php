<?php
/**
 * Helpers de mensagens do WhatsApp — usados pelo webhook (e por qualquer
 * outra rota que precise ler/gravar o histórico da conversa, ex: admin
 * mandando mensagem manual pro cliente).
 */

require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/security.php';

/**
 * Já processamos esse messageId antes? Checagem antecipada pra dedup de
 * webhook reenviado (Z-API pode reentregar o mesmo evento em timeout/retry)
 * — evita reprocessar lógica (criar oportunidade, etc) de graça. A gravação
 * em si (registrarMensagem) também é protegida via INSERT OR IGNORE contra
 * corrida entre duas requests concorrentes do mesmo messageId.
 */
function jaProcessado(string $messageId): bool {
    if (!$messageId) return false;
    $db = getDB();
    $stmt = $db->prepare("SELECT 1 FROM whatsapp_mensagens WHERE zapi_message_id = ? LIMIT 1");
    $stmt->execute([$messageId]);
    return (bool)$stmt->fetchColumn();
}

/**
 * Extrai o texto de um payload de webhook Z-API, conforme o tipo de
 * mensagem. Retorna null quando é mídia sem suporte de leitura ainda
 * (áudio/imagem/documento) — quem chama decide o que fazer (salvar
 * marcador, ignorar, etc).
 */
function extrairTexto(array $payload): ?string {
    if (isset($payload['text']['message'])) return (string)$payload['text']['message'];
    if (isset($payload['button']['message'])) return (string)$payload['button']['message'];
    if (isset($payload['listResponseMessage']['message'])) return (string)$payload['listResponseMessage']['message'];
    return null;
}

/** Nome legível do tipo de mídia recebida, pra registrar um marcador no histórico. */
function tipoMidia(array $payload): string {
    foreach (['image', 'audio', 'video', 'document', 'sticker', 'location', 'contact'] as $tipo) {
        if (isset($payload[$tipo])) return $tipo;
    }
    return 'desconhecido';
}

/**
 * Salva uma mensagem no histórico (regra #2 do CLAUDE.md: salva desde o
 * 1º contato, mesmo sem cliente/oportunidade qualificados ainda).
 * INSERT OR IGNORE porque zapi_message_id tem índice único (parcial) —
 * se a mesma mensagem chegar 2x (retry do Z-API), a 2ª vira no-op.
 */
function registrarMensagem(
    string $telefone,
    string $direcao,
    string $mensagem,
    ?string $zapiMessageId = null,
    bool $enviadoPorIa = false,
    string $tipo = 'text'
): void {
    $db = getDB();
    $telNorm = normalizarTelefone($telefone);

    $stmt = $db->prepare("SELECT id FROM clientes WHERE telefone = ?");
    $stmt->execute([$telNorm]);
    $clienteId = $stmt->fetchColumn();

    $db->prepare("
        INSERT OR IGNORE INTO whatsapp_mensagens
            (telefone, cliente_id, direcao, mensagem, tipo, enviado_por_ia, zapi_message_id, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, datetime('now','localtime'))
    ")->execute([
        $telNorm,
        $clienteId !== false ? (int)$clienteId : null,
        $direcao,
        $mensagem,
        $tipo,
        $enviadoPorIa ? 1 : 0,
        $zapiMessageId ?: '',
    ]);
}

/** IA está pausada pra esse telefone? (regra #4 — consultor assumiu a conversa) */
function iaPausada(string $telefone): bool {
    $db = getDB();
    $stmt = $db->prepare("SELECT ia_pausada FROM whatsapp_sessoes WHERE telefone = ?");
    $stmt->execute([normalizarTelefone($telefone)]);
    $val = $stmt->fetchColumn();
    return $val !== false && (int)$val === 1;
}

/** Consultor assume a conversa — pausa a IA, ela não pode responder por cima. */
function pausarIA(string $telefone): void {
    $db = getDB();
    $db->prepare("
        INSERT INTO whatsapp_sessoes (telefone, ia_pausada, updated_at)
        VALUES (?, 1, datetime('now','localtime'))
        ON CONFLICT(telefone) DO UPDATE SET ia_pausada = 1, updated_at = datetime('now','localtime')
    ")->execute([normalizarTelefone($telefone)]);
}

/** Devolve a conversa pra IA (ex: consultor encerrou o atendimento manual). */
function retomarIA(string $telefone): void {
    $db = getDB();
    $db->prepare("
        INSERT INTO whatsapp_sessoes (telefone, ia_pausada, updated_at)
        VALUES (?, 0, datetime('now','localtime'))
        ON CONFLICT(telefone) DO UPDATE SET ia_pausada = 0, updated_at = datetime('now','localtime')
    ")->execute([normalizarTelefone($telefone)]);
}
