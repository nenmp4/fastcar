<?php
/**
 * Helpers de mensagens do WhatsApp — usados pelo webhook (e por qualquer
 * outra rota que precise ler/gravar o histórico da conversa, ex: admin
 * mandando mensagem manual pro cliente).
 */

require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/security.php';
require_once dirname(__DIR__, 2) . '/includes/oportunidades.php';
require_once dirname(__DIR__, 2) . '/includes/zapi_instancias.php';
require_once dirname(__DIR__, 2) . '/includes/whatsapp_config.php';
require_once dirname(__DIR__, 2) . '/includes/ia_qualificacao.php';

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

/**
 * Extrai a atribuição de anúncio (bloco 1 do funil) de um clique em
 * anúncio "Clique para WhatsApp" do Meta — decisão confirmada com o Jean
 * (pendência #5 do CLAUDE.md). A WhatsApp Cloud API manda um objeto
 * `referral` na primeira mensagem originada de um anúncio desses:
 * { source_id, source_type: "ad"|"post", source_url, headline, body,
 *   media_type, ctwa_clid, ... }. A Z-API deve repassar isso no webhook,
 * mas o formato exato (nome do campo, se vem plano ou aninhado) só dá pra
 * confirmar contra uma instância real — sem instância ainda (pendência #1
 * do CLAUDE.md), então esta função aceita tanto `referral` quanto
 * `message.referral` (variações comuns de proxy de webhook) e nunca
 * lança: sem `referral` no payload, retorna tudo vazio (contato direto,
 * sem anúncio) — nunca inventa atribuição que não veio no evento.
 */
function extrairOrigemAnuncio(array $payload): array {
    $referral = $payload['referral'] ?? $payload['message']['referral'] ?? null;
    if (!is_array($referral) || empty($referral['source_id'])) {
        return ['canal_origem' => '', 'campanha_origem' => '', 'anuncio_origem' => ''];
    }

    return [
        'canal_origem' => 'meta_ads',
        // headline é o texto do anúncio exibido — mais legível que só um ID
        // pra identificar "de qual campanha" na hora de olhar o relatório.
        'campanha_origem' => (string)($referral['headline'] ?? ''),
        'anuncio_origem' => (string)($referral['source_id'] ?? $referral['ctwa_clid'] ?? ''),
    ];
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
    string $tipo = 'text',
    ?int $usuarioId = null
): void {
    $db = getDB();
    $telNorm = normalizarTelefone($telefone);

    $stmt = $db->prepare("SELECT id FROM clientes WHERE telefone = ?");
    $stmt->execute([$telNorm]);
    $clienteId = $stmt->fetchColumn();

    $db->prepare("
        INSERT OR IGNORE INTO whatsapp_mensagens
            (telefone, cliente_id, direcao, mensagem, tipo, enviado_por_ia, zapi_message_id, usuario_id, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, datetime('now','localtime'))
    ")->execute([
        $telNorm,
        $clienteId !== false ? (int)$clienteId : null,
        $direcao,
        $mensagem,
        $tipo,
        $enviadoPorIa ? 1 : 0,
        $zapiMessageId ?: '',
        $usuarioId,
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

/**
 * Núcleo do processamento de uma mensagem recebida via Z-API — dedup,
 * fromMe/grupo, salvar histórico, abrir oportunidade, checar IA pausada.
 * Usada pelo webhook real (chatbot-whatsapp/webhook/whatsapp.php) E pelo
 * simulador de conversa (chatbot-whatsapp/simulate.php), de propósito:
 * assim o que o simulador mostra é garantidamente o que o webhook real
 * faria com o mesmo payload — nenhuma lógica duplicada pra divergir.
 *
 * Não faz nada HTTP-específico (não loga em arquivo, não decide status
 * code) — quem chama decide o que fazer com o retorno.
 *
 * Aceita payload de QUALQUER instância — a principal (funil oficial:
 * entrada, qualificação IA, followup) ou a de um consultor/closer
 * (conversa paralela, capturada só pra visibilidade/produtividade). O
 * telefone do cliente é sempre a chave que junta tudo na mesma conversa;
 * $instancia (se não informado, resolvido a partir de payload.instanceId)
 * só marca QUEM trouxe cada mensagem.
 *
 * Retorno:
 *   ignored: null|'no_phone'|'from_me'|'group'|'duplicate'
 *   telefone, texto, tipo: dados da mensagem processada (null se ignorada)
 *   ia_pausada: bool — true = humano assumiu, nenhuma resposta automática
 *   oportunidade: ['cliente_id','oportunidade_id','nova'] | null
 *   erro_oportunidade: string|null — erro ao criar/abrir oportunidade, se houve
 *   instancia: ['tipo' => 'principal'|'consultor'|'desconhecida', 'usuario_id' => ?int]
 */
function processarMensagemZapi(array $payload, ?array $instancia = null): array {
    $instancia ??= zapiIdentificarInstancia((string)($payload['instanceId'] ?? ''));
    $usuarioId = $instancia['usuario_id'] ?? null;

    $vazio = ['ignored' => null, 'telefone' => '', 'texto' => null, 'tipo' => null,
              'ia_pausada' => false, 'oportunidade' => null, 'erro_oportunidade' => null,
              'instancia' => $instancia, 'ia_resultado' => null];

    $messageId = (string)($payload['messageId'] ?? $payload['id'] ?? '');
    $phone     = (string)($payload['phone'] ?? '');
    $fromMe    = !empty($payload['fromMe']);
    $isGroup   = !empty($payload['isGroup']) || str_contains($phone, '-group');

    if (!$phone) {
        return ['ignored' => 'no_phone'] + $vazio;
    }

    if ($fromMe) {
        // Registrado no histórico (consultor pode ter respondido manualmente
        // pelo próprio WhatsApp/app oficial, fora do nosso código), mas não
        // é "entrada" — não roda lógica de bot/oportunidade em cima.
        registrarMensagem($phone, 'out', extrairTexto($payload) ?? '[' . tipoMidia($payload) . ']', $messageId ?: null, false, 'text', $usuarioId);
        return ['ignored' => 'from_me', 'telefone' => $phone, 'ia_pausada' => iaPausada($phone)] + $vazio;
    }

    if ($isGroup) {
        return ['ignored' => 'group', 'telefone' => $phone] + $vazio;
    }

    if ($messageId && jaProcessado($messageId)) {
        return ['ignored' => 'duplicate', 'telefone' => $phone, 'ia_pausada' => iaPausada($phone)] + $vazio;
    }

    $texto = extrairTexto($payload);
    $tipoRegistro = 'text';
    if ($texto === null) {
        $tipoRegistro = tipoMidia($payload);
        $texto = '[' . $tipoRegistro . ']'; // marcador — mantém a mensagem no histórico mesmo sem interpretar o conteúdo
    }

    registrarMensagem($phone, 'in', $texto, $messageId ?: null, false, $tipoRegistro, $usuarioId);

    // Cria/abre a oportunidade desde o 1º contato (regra #2) — nunca esperar
    // a qualificação terminar pra existir registro. Idempotente: se já
    // existe oportunidade ativa (ex: cliente já em atendimento com um
    // consultor), só reaproveita — vale pra mensagem vinda de qualquer instância.
    $nomeContato = (string)($payload['senderName'] ?? $payload['chatName'] ?? '');
    $origemAnuncio = extrairOrigemAnuncio($payload);
    $oportunidade = null;
    $erroOportunidade = null;
    try {
        $oportunidade = criarOuAbrirOportunidade($phone, $nomeContato, $origemAnuncio);
    } catch (Throwable $e) {
        $erroOportunidade = $e->getMessage();
    }

    $ia_pausada = iaPausada($phone);
    $iaResultado = null;

    // Qualificação por IA (bloco 3, pendência #3 resolvida) — só roda pela
    // instância principal (nunca sobre uma conversa que já é de um
    // consultor), só com IA não pausada, só em texto de verdade (não em
    // marcador de mídia) e só enquanto a oportunidade ainda está nos
    // blocos 2/3 do funil. iaProcessarTurno() nunca lança — falha de rede/
    // API não pode derrubar o webhook, só significa "IA não respondeu
    // dessa vez", igual quando não tem chave configurada ainda.
    if ($oportunidade && !$ia_pausada && $instancia['tipo'] === 'principal' && $tipoRegistro === 'text') {
        $db = getDB();
        $stmtEtapa = $db->prepare("SELECT etapa FROM oportunidades WHERE id = ?");
        $stmtEtapa->execute([$oportunidade['oportunidade_id']]);
        $etapaAtual = $stmtEtapa->fetchColumn();

        if (in_array($etapaAtual, ['whatsapp', 'qualificacao_ia'], true)) {
            if ($etapaAtual === 'whatsapp') {
                mudarEtapa($oportunidade['oportunidade_id'], 'qualificacao_ia', null, 'IA iniciou qualificação');
            }
            try {
                $iaResultado = iaProcessarTurno($oportunidade['oportunidade_id'], $phone);
            } catch (Throwable $e) {
                // Nunca deixa uma falha da IA quebrar o resto do webhook —
                // mensagem do cliente já está salva, oportunidade já existe.
            }
        }
    }

    return [
        'ignored' => null,
        'telefone' => $phone,
        'texto' => $texto,
        'tipo' => $tipoRegistro,
        'ia_pausada' => $ia_pausada,
        'oportunidade' => $oportunidade,
        'erro_oportunidade' => $erroOportunidade,
        'instancia' => $instancia,
        'ia_resultado' => $iaResultado,
    ];
}
