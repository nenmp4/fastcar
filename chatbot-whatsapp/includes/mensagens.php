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

// Segundos de silêncio esperados antes da IA responder — evita o bot
// respondendo picotado quando o cliente manda várias mensagens curtas em
// sequência (ex: "Oi" / "quero vender meu carro" / "é um Onix 2019" como 3
// mensagens separadas em poucos segundos: sem isso, o bot respondia à
// primeira sozinha, com jeito de robô mal escutando). `0` desliga (usado
// pelo simulador de CLI — não faz sentido esperar segundos numa conversa
// digitada linha a linha ao vivo).
if (!defined('WHATSAPP_DEBOUNCE_SEGUNDOS')) define('WHATSAPP_DEBOUNCE_SEGUNDOS', 4);

// Tamanho máximo de mídia (áudio/imagem/vídeo) baixada pra mandar pro
// Gemini — a API só aceita `inlineData` base64 até por volta de 20MB;
// acima disso precisaria da Files API (não implementada). Vídeo de
// WhatsApp é o caso mais provável de estourar isso; corta o download
// cedo em vez de baixar um arquivo grande só pra descartar depois.
if (!defined('WHATSAPP_MIDIA_MAX_BYTES')) define('WHATSAPP_MIDIA_MAX_BYTES', 20 * 1024 * 1024);

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
 * URL de download de áudio/imagem no payload — nome exato do campo ainda
 * não confirmado contra uma instância Z-API real (mesma ressalva do resto
 * do payload, CLAUDE.md → "a validar em produção"), por isso tenta as
 * variações mais prováveis em vez de travar num nome só.
 */
function extrairUrlMidia(array $payload, string $tipo): ?string {
    $bloco = $payload[$tipo] ?? null;
    if (!is_array($bloco)) return null;
    foreach (["{$tipo}Url", 'url', 'mediaUrl', 'link'] as $campo) {
        if (!empty($bloco[$campo])) return (string)$bloco[$campo];
    }
    return null;
}

/** mimeType declarado no payload da mídia, com fallback razoável por tipo. */
function mimeMidia(array $payload, string $tipo, string $default): string {
    $bloco = $payload[$tipo] ?? [];
    return (string)($bloco['mimeType'] ?? $default);
}

/**
 * Baixa áudio/imagem/vídeo e pede pro Gemini transcrever (áudio) ou
 * descrever (imagem/vídeo) — retorna o texto resultante, ou '' se não deu
 * por qualquer motivo (sem URL, download falhou, arquivo grande demais,
 * sem chave Gemini configurada). Nunca lança: mídia é sempre melhor
 * esforço, mesmo espírito de enviarEmail()/geminiRegistrarTokens() — se
 * falhar, quem chama trata como mídia não processada (cai no
 * reconhecimento simples em vez de travar o webhook).
 *
 * Vídeo (15/09/2026, pedido do José/Jean — "receber mídias áudio, imagem
 * e vídeo, se cliente mandar, agente olhar"): mesmo mecanismo de
 * audio/imagem (Gemini multimodal, `inlineData` base64, mesma função
 * `geminiCallComMidia()` já usada pro wizard de documentos), só com um
 * limite de tamanho — vídeo de WhatsApp pode passar fácil dos ~20MB que a
 * API do Gemini aceita inline (acima disso precisaria da Files API, que
 * não estava implementada) e o download síncrono dentro do webhook não
 * pode ficar esperando um arquivo enorme. `WHATSAPP_MIDIA_MAX_BYTES` (20MB)
 * corta o download cedo (CURLOPT_RANGE, evita baixar o arquivo inteiro só
 * pra descartar) — vídeo grande demais cai no mesmo caminho de "não deu
 * pra processar" (reconhecimento simples), nunca trava nem estoura memória.
 */
function processarMidiaComGemini(string $url, string $mimeType, string $tipo): string {
    try {
        $geminiKey = getConfig('gemini_api_key') ?: '';
        if (!$geminiKey || !$url) return '';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $tipo === 'video' ? 40 : 20,
            CURLOPT_RANGE => '0-' . (WHATSAPP_MIDIA_MAX_BYTES - 1),
        ]);
        $conteudo = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        // 200 = servidor ignorou o Range e mandou tudo (ainda aceitável se
        // coube no limite); 206 = respeitou o corte parcial.
        if (!in_array($http, [200, 206], true) || !$conteudo) return '';
        if (strlen($conteudo) >= WHATSAPP_MIDIA_MAX_BYTES) return '';

        $prompts = [
            'audio' => 'Transcreva literalmente o que a pessoa fala neste áudio, em português do Brasil. Responda só com a transcrição, sem comentário nenhum antes ou depois.',
            'image' => 'Descreva em 1-2 frases curtas o veículo nesta foto (marca/modelo se der pra identificar, cor, estado aparente de conservação). Se a imagem não for de um veículo, diga em poucas palavras o que é. Responda em português, direto, sem introdução tipo "a imagem mostra".',
            'video' => 'Descreva em 2-3 frases curtas o que aparece neste vídeo, focando no veículo se houver um (marca/modelo se der pra identificar, cor, estado aparente de conservação, avarias visíveis) e transcrevendo rapidamente qualquer coisa relevante que a pessoa fale. Responda em português, direto, sem introdução tipo "o vídeo mostra".',
        ];

        return geminiCallComMidia($prompts[$tipo] ?? $prompts['image'], $mimeType, base64_encode($conteudo), $geminiKey, getConfig('gemini_model') ?: 'gemini-3.5-flash-lite');
    } catch (Throwable $e) {
        return '';
    }
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
): int {
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

    // id da linha inserida — 0 se foi ignorado por dedup de zapi_message_id
    // (não deveria acontecer aqui: quem chama já checou jaProcessado() antes
    // pra mensagem recebida; pra mensagem 'out' não tem dedup, sempre insere).
    return (int)$db->lastInsertId();
}

/**
 * Espera WHATSAPP_DEBOUNCE_SEGUNDOS de silêncio antes de deixar a IA
 * responder. Se chegar uma mensagem MAIS NOVA desse mesmo telefone
 * enquanto essa request está dormindo (cliente mandou outra mensagem
 * separada, um webhook concorrente já está processando), essa request
 * aborta silenciosamente — a request da mensagem mais nova vai fazer sua
 * própria espera e responder pra todas de uma vez, já que
 * iaMontarHistoricoGemini() sempre lê o histórico completo do telefone,
 * não só a última mensagem recebida. Sem isso, um cliente que manda "Oi" /
 * "quero vender meu carro" / "é um Onix 2019" como 3 mensagens separadas
 * recebia 3 respostas picotadas, uma pra cada, em vez de 1 resposta lendo
 * tudo junto.
 *
 * @param int $idMensagem id (whatsapp_mensagens.id) da mensagem que ESSA
 *   request acabou de registrar — geralmente o retorno de registrarMensagem().
 * @return bool true = pode responder agora (essa é a última mensagem do
 *   burst); false = abortar, uma request mais nova vai responder por ela.
 */
function aguardarSilencioOuAbortar(string $telefone, int $idMensagem): bool {
    if (WHATSAPP_DEBOUNCE_SEGUNDOS <= 0 || $idMensagem <= 0) return true;

    sleep(WHATSAPP_DEBOUNCE_SEGUNDOS);

    $db = getDB();
    $stmt = $db->prepare("SELECT MAX(id) FROM whatsapp_mensagens WHERE telefone = ? AND direcao = 'in'");
    $stmt->execute([normalizarTelefone($telefone)]);
    $ultimoId = (int)$stmt->fetchColumn();

    return $ultimoId <= $idMensagem;
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
 * entrada, qualificação IA, followup) ou a de um consultor
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
        $tipoBruto = tipoMidia($payload);

        // ⚠️ 15/09/2026 — achado real em produção: payload sem NENHUM campo
        // reconhecido (nem text, nem image/audio/video/document/sticker/
        // location/contact) não é mensagem de verdade — é outro tipo de
        // evento da Z-API (presença, status de entrega, conexão) caindo no
        // mesmo webhook "Ao receber" por engano (aconteceu quando os 6
        // campos de webhook da Z-API foram configurados pra mesma URL).
        // Sem essa checagem, cada evento desses virava "mídia não
        // suportada" e disparava a resposta de reconhecimento — 129
        // respostas repetidas pro mesmo telefone num intervalo de minutos,
        // sem nenhuma mensagem real do cliente por trás. Nunca registra
        // nem responde nesse caso — só ignora silenciosamente.
        if ($tipoBruto === 'desconhecido') {
            return ['ignored' => 'not_a_message', 'telefone' => $phone] + $vazio;
        }

        $textoMidia = '';
        if (in_array($tipoBruto, ['audio', 'image', 'video'], true)) {
            $url = extrairUrlMidia($payload, $tipoBruto);
            if ($url) {
                $mimeDefault = ['audio' => 'audio/ogg', 'image' => 'image/jpeg', 'video' => 'video/mp4'][$tipoBruto];
                $mime = mimeMidia($payload, $tipoBruto, $mimeDefault);
                $textoMidia = processarMidiaComGemini($url, $mime, $tipoBruto);
            }
        }
        if ($textoMidia !== '') {
            // Processado com sucesso (transcrito/descrito) — entra no histórico
            // já como texto, prefixo só pra quem olhar a conversa saber que
            // veio de mídia. tipoRegistro='text' de propósito: assim
            // iaMontarHistoricoGemini() (que só lê tipo='text') e o gatilho de
            // qualificação abaixo tratam igual a uma mensagem digitada.
            $prefixo = ['audio' => '🎤 ', 'video' => '🎥 '][$tipoBruto] ?? '📷 ';
            $texto = $prefixo . $textoMidia;
        } else {
            // Sem URL, download falhou, ou sem chave Gemini — marcador comum,
            // mantém a mensagem no histórico mesmo sem interpretar o conteúdo.
            $tipoRegistro = $tipoBruto;
            $texto = '[' . $tipoRegistro . ']';
        }
    }

    $idMensagemRecebida = registrarMensagem($phone, 'in', $texto, $messageId ?: null, false, $tipoRegistro, $usuarioId);

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
    // consultor), só com IA não pausada, e só enquanto a oportunidade ainda
    // está nos blocos 2/3 do funil. iaProcessarTurno() nunca lança — falha
    // de rede/API não pode derrubar o webhook, só significa "IA não
    // respondeu dessa vez", igual quando não tem chave configurada ainda.
    if ($oportunidade && !$ia_pausada && $instancia['tipo'] === 'principal') {
        $db = getDB();
        $stmtEtapa = $db->prepare("SELECT etapa FROM oportunidades WHERE id = ?");
        $stmtEtapa->execute([$oportunidade['oportunidade_id']]);
        $etapaAtual = $stmtEtapa->fetchColumn();

        if (in_array($etapaAtual, ['whatsapp', 'qualificacao_ia'], true)) {
            if ($tipoRegistro === 'text') {
                if ($etapaAtual === 'whatsapp') {
                    mudarEtapa($oportunidade['oportunidade_id'], 'qualificacao_ia', null, 'IA iniciou qualificação');
                }
                // Debounce (ver aguardarSilencioOuAbortar) — se chegar mensagem
                // mais nova desse telefone enquanto essa dorme, aborta: a mais
                // nova vai responder pelas duas juntas.
                if (aguardarSilencioOuAbortar($phone, $idMensagemRecebida)) {
                    try {
                        $iaResultado = iaProcessarTurno($oportunidade['oportunidade_id'], $phone);
                    } catch (Throwable $e) {
                        // Nunca deixa uma falha da IA quebrar o resto do webhook —
                        // mensagem do cliente já está salva, oportunidade já existe.
                    }
                }
            } else {
                // Mídia que não deu pra processar (vídeo, figurinha, documento,
                // localização, contato, ou áudio/imagem que falhou) — nunca
                // deixa o lead sem resposta nenhuma (achado real: bot ficava
                // mudo se a 1ª mensagem fosse um áudio), só não roda extração
                // de dados em cima de algo que não conseguimos interpretar.
                $textoAck = 'Recebi por aqui! 😊 Consegue me contar em texto ou áudio?';
                if (zapiEnviarTexto($phone, $textoAck)) {
                    registrarMensagem($phone, 'out', $textoAck, null, true);
                }
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
