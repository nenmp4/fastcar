<?php
/**
 * Evolution API (self-hosted, WhatsApp via Baileys — protocolo não-oficial,
 * mesma categoria de risco de shadowban/banimento que a Z-API, nunca menor,
 * ver `~/.claude/CLAUDE.md` sobre o WA-AKG) — 09/10/2026, "vamos implementa
 * evolution desativa met zpi [por enquanto]".
 *
 * **Contexto**: a conta Z-API em uso teve 4 números distintos banidos pela
 * Meta em sequência, quase instantaneamente, um deles sem NENHUMA automação
 * de envio rodando antes do bloqueio (número recém-pareado, nada além da
 * conexão em si) — padrão forte demais pra ser coincidência de número
 * isolado, consistente com a própria CONTA/infraestrutura Z-API já estar
 * marcada pela Meta (ver CLAUDE.md, bullets "Novo número banido pela Meta
 * imediatamente ao conectar na Z-API..."/"Hipótese de acompanhamento do
 * usuário"). Decisão do usuário: trocar o canal PRINCIPAL pra uma instância
 * Evolution API self-hosted, numa VPS própria (HostGator, "Evolution API
 * Whats", ainda "Em configuração" no momento desta implementação — ver
 * pendência no final deste arquivo). Z-API e Meta oficial NUNCA removidas
 * do código (regra de sempre deste projeto: nunca apaga integração
 * funcional), só deixam de ser o canal ATIVO "por enquanto" — reversível a
 * qualquer momento trocando o radio em Configurações.
 *
 * **Risco estrutural, não eliminado**: Evolution API também é construída
 * em cima do Baileys (WhatsApp Web/multi-device não-oficial) — trocar de
 * PROVEDOR muda quem hospeda a conexão, nunca elimina o risco do protocolo
 * em si (mesma ressalva já registrada na memória global sobre o WA-AKG:
 * "mesma categoria de risco de shadowban/banimento que a Z-API, nunca
 * menor"). Self-hosted dá um controle que a Z-API nunca deu (infra/IP
 * próprios, sem compartilhar pool com outras contas de terceiro) — pode
 * ajudar com a hipótese de "conta/infraestrutura compartilhada marcada",
 * mas não é garantia nenhuma.
 *
 * **Construído a partir da documentação pública** do projeto Evolution API
 * (github.com/EvolutionAPI/evolution-api; o domínio oficial dos docs,
 * mintlify.com/EvolutionAPI, está bloqueado pra leitura direta neste
 * sandbox — confirmado só via WebSearch/snippets de terceiros, nunca a
 * doc oficial inteira). NUNCA confirmado contra uma instância real — mesma
 * ressalva "a validar em produção" de todo provedor novo deste projeto
 * (Asaas, ZapCar, PlacaFIPE, Meta Cloud API passaram pela mesma fase).
 *
 * Formato assumido (API v2, auth por header `apikey` — chave da instância,
 * mesmo nível de credencial único que Z-API/Meta já usam aqui):
 *   POST /message/sendText/{instance}          — {number, text}
 *   POST /message/sendMedia/{instance}         — {number, mediatype, media, caption?, fileName?}
 *   POST /message/sendWhatsAppAudio/{instance} — {number, audio} (nota de voz, sem legenda)
 *   GET  /instance/connectionState/{instance}  — {instance: {state: open|close|connecting}}
 *   Webhook (configurado na criação/edição da instância, evento
 *   messages.upsert): {event, instance, data: {key: {remoteJid, fromMe, id},
 *   pushName, message: {conversation}}, apikey, server_url, date_time}
 *
 * **Pontos nunca confirmados**, sinalizados aqui pra quando a VPS estiver
 * pronta: (1) se `number` aceita só dígitos (DDI+DDD+número, mesmo formato
 * que `normalizarTelefone()` já produz) ou exige sufixo `@s.whatsapp.net`
 * — assumido que não precisa, já que os exemplos encontrados usam número
 * puro; (2) se o campo `media` aceita data URI base64 direto (assumido que
 * sim — Baileys lida com base64 nativamente, e outro provedor Baileys-based
 * já confirmado em produção, a Z-API, aceita os dois formatos) ou exige
 * upload prévio tipo a Cloud API da Meta; (3) se o webhook de fato inclui
 * `apikey` no corpo — por isso a validação de origem no webhook usa o nome
 * da `instance`, não o `apikey` (campo com confirmação mais fraca); (4)
 * RECEBIMENTO de mídia fica DE FORA desta 1ª versão de propósito — Baileys
 * normalmente exige decriptar a mídia usando chaves (`mediaKey`) que vêm
 * dentro da própria mensagem, mecanismo que nenhuma fonte consultada aqui
 * confirmou como a Evolution API expõe (base64 direto no webhook, com
 * `webhook_base64` ligado? endpoint `/chat/getBase64FromMediaMessage`?) —
 * sem confirmação nenhuma, melhor deixar sem tentar do que inventar um
 * endpoint errado; mídia recebida cai no mesmo caminho gracioso "mídia não
 * processada" que o projeto já tem (mimeType sem bytes/URL resolvível).
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';

/** Base URL override só em teste (fake server local), mesmo padrão do resto do projeto. */
function evolutionBaseUrl(): string {
    if (defined('EVOLUTION_BASE_URL')) return rtrim(EVOLUTION_BASE_URL, '/');
    return rtrim((string)(getConfig('evolution_base_url') ?: ''), '/');
}

/** [base_url, instance_name, api_key] — '' se algum não estiver configurado ainda. */
function evolutionCredenciais(): array {
    return [
        evolutionBaseUrl(),
        getConfig('evolution_instance_name') ?: '',
        getConfig('evolution_api_key') ?: '',
    ];
}

function evolutionConfigured(): bool {
    [$base, $instance, $key] = evolutionCredenciais();
    return $base !== '' && $instance !== '' && $key !== '';
}

/**
 * Canal principal usa Evolution? — mesmo papel de oficialEhProviderPrincipal().
 * Default 'zapi' (nunca muda comportamento sozinho) até o usuário escolher
 * explicitamente em Configurações.
 */
function evolutionEhProviderPrincipal(): bool {
    return getConfig('whatsapp_provider_principal') === 'evolution' && evolutionConfigured();
}

$GLOBALS['_evolution_ultimo_erro'] = null;
function evolutionUltimoErro(): ?string {
    return $GLOBALS['_evolution_ultimo_erro'];
}
function _evolutionSetUltimoErro(?string $msg): void {
    $GLOBALS['_evolution_ultimo_erro'] = $msg;
}

function _evolutionHeaders(string $apiKey): array {
    return ['apikey: ' . $apiKey, 'Content-Type: application/json'];
}

/** Extrai uma mensagem de erro legível de uma resposta JSON da Evolution — formato de erro nunca confirmado, tenta os campos mais prováveis. */
function _evolutionErroDeResposta($json, int $httpCode): string {
    $erro = $json['message'] ?? $json['error'] ?? $json['response']['message'] ?? "HTTP {$httpCode}";
    return is_array($erro) ? json_encode($erro, JSON_UNESCAPED_UNICODE) : (string)$erro;
}

function evolutionEnviarTexto(string $phone, string $msg, ?array $override = null): bool {
    [$base, $instance, $apiKey] = $override ?? evolutionCredenciais();
    if (!$base || !$instance || !$apiKey || !$phone) {
        _evolutionSetUltimoErro('Sem URL/instância/API key configurados.');
        return false;
    }
    $phoneNorm = normalizarTelefone($phone);
    if (strlen($phoneNorm) < 12) {
        _evolutionSetUltimoErro('Telefone inválido.');
        return false;
    }

    $ch = curl_init("{$base}/message/sendText/{$instance}");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['number' => $phoneNorm, 'text' => $msg]),
        CURLOPT_HTTPHEADER => _evolutionHeaders($apiKey),
        CURLOPT_TIMEOUT => 20,
    ]);
    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($resp === false) {
        _evolutionSetUltimoErro("Falha de conexão: {$curlErr}");
        return false;
    }
    if ($httpCode >= 200 && $httpCode < 300) {
        _evolutionSetUltimoErro(null);
        return true;
    }
    $json = json_decode((string)$resp, true);
    _evolutionSetUltimoErro(_evolutionErroDeResposta($json, $httpCode) . ' — resposta: ' . substr((string)$resp, 0, 500));
    return false;
}

function _evolutionMediaTypeDe(string $tipo): string {
    return in_array($tipo, ['image', 'video', 'document'], true) ? $tipo : 'image';
}

/**
 * Imagem/vídeo/documento — POST /message/sendMedia/{instance}. Aceita URL
 * pública ou data URI base64 direto no campo `media` (assumido, nunca
 * confirmado — ver docblock do topo do arquivo).
 */
function _evolutionEnviarMidia(string $phone, string $tipo, string $urlOuDataUri, string $legenda, string $nomeArquivo, ?array $override = null): bool {
    [$base, $instance, $apiKey] = $override ?? evolutionCredenciais();
    if (!$base || !$instance || !$apiKey || !$phone || !$urlOuDataUri) {
        _evolutionSetUltimoErro('Sem URL/instância/API key configurados, ou telefone/arquivo vazio.');
        return false;
    }
    $phoneNorm = normalizarTelefone($phone);
    if (strlen($phoneNorm) < 12) {
        _evolutionSetUltimoErro('Telefone inválido.');
        return false;
    }

    $body = [
        'number' => $phoneNorm,
        'mediatype' => _evolutionMediaTypeDe($tipo),
        'media' => $urlOuDataUri,
        'fileName' => $nomeArquivo,
    ];
    if ($legenda !== '') {
        $body['caption'] = $legenda;
    }

    $ch = curl_init("{$base}/message/sendMedia/{$instance}");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_HTTPHEADER => _evolutionHeaders($apiKey),
        CURLOPT_TIMEOUT => 40,
    ]);
    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($resp === false) {
        _evolutionSetUltimoErro("Falha de conexão: {$curlErr}");
        return false;
    }
    if ($httpCode >= 200 && $httpCode < 300) {
        _evolutionSetUltimoErro(null);
        return true;
    }
    $json = json_decode((string)$resp, true);
    _evolutionSetUltimoErro(_evolutionErroDeResposta($json, $httpCode) . ' — resposta: ' . substr((string)$resp, 0, 500));
    return false;
}

function evolutionEnviarImagem(string $phone, string $imagemUrl, string $legenda, ?array $override = null): bool {
    return _evolutionEnviarMidia($phone, 'image', $imagemUrl, $legenda, 'imagem.jpg', $override);
}

function evolutionEnviarVideo(string $phone, string $videoUrlOuDataUri, string $legenda, ?array $override = null): bool {
    return _evolutionEnviarMidia($phone, 'video', $videoUrlOuDataUri, $legenda, 'video.mp4', $override);
}

function evolutionEnviarDocumento(string $phone, string $documentoUrlOuDataUri, string $fileName, ?array $override = null): bool {
    return _evolutionEnviarMidia($phone, 'document', $documentoUrlOuDataUri, '', $fileName !== '' ? $fileName : 'documento.pdf', $override);
}

/**
 * Áudio (nota de voz) — endpoint PRÓPRIO (sendWhatsAppAudio), diferente de
 * sendMedia — sem legenda, WhatsApp não aceita caption em áudio (mesma
 * limitação já documentada pro lado Z-API/Meta).
 */
function evolutionEnviarAudio(string $phone, string $audioDataUriOuUrl, ?array $override = null): bool {
    [$base, $instance, $apiKey] = $override ?? evolutionCredenciais();
    if (!$base || !$instance || !$apiKey || !$phone || !$audioDataUriOuUrl) {
        _evolutionSetUltimoErro('Sem URL/instância/API key configurados, ou telefone/arquivo vazio.');
        return false;
    }
    $phoneNorm = normalizarTelefone($phone);
    if (strlen($phoneNorm) < 12) {
        _evolutionSetUltimoErro('Telefone inválido.');
        return false;
    }

    $ch = curl_init("{$base}/message/sendWhatsAppAudio/{$instance}");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['number' => $phoneNorm, 'audio' => $audioDataUriOuUrl]),
        CURLOPT_HTTPHEADER => _evolutionHeaders($apiKey),
        CURLOPT_TIMEOUT => 30,
    ]);
    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($resp === false) {
        _evolutionSetUltimoErro("Falha de conexão: {$curlErr}");
        return false;
    }
    if ($httpCode >= 200 && $httpCode < 300) {
        _evolutionSetUltimoErro(null);
        return true;
    }
    $json = json_decode((string)$resp, true);
    _evolutionSetUltimoErro(_evolutionErroDeResposta($json, $httpCode) . ' — resposta: ' . substr((string)$resp, 0, 500));
    return false;
}

/**
 * Testa a conexão — GET /instance/connectionState/{instance}, lê o estado
 * de pareamento do dispositivo (não existe conceito de "token válido" sem
 * pareamento aqui, diferente da Cloud API — é sempre sobre o QR code ter
 * sido escaneado ou não). Lança com o erro real em falha, mesmo padrão de
 * oficialTestarConexao().
 */
function evolutionTestarConexao(?array $override = null): string {
    [$base, $instance, $apiKey] = $override ?? evolutionCredenciais();
    if (!$base || !$instance || !$apiKey) {
        throw new RuntimeException('URL/instância/API key não configurados.');
    }
    $ch = curl_init("{$base}/instance/connectionState/{$instance}");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => _evolutionHeaders($apiKey),
        CURLOPT_TIMEOUT => 15,
    ]);
    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($resp === false) {
        throw new RuntimeException("Falha de conexão: {$curlErr}");
    }
    $json = json_decode((string)$resp, true);
    $estado = $json['instance']['state'] ?? $json['state'] ?? null;
    if ($httpCode !== 200 || !$estado) {
        throw new RuntimeException('Evolution respondeu com erro: ' . _evolutionErroDeResposta($json, $httpCode));
    }
    $rotulo = match ($estado) {
        'open' => 'conectado (pareado)',
        'connecting' => 'conectando (aguardando QR code)',
        'close' => 'desconectado',
        default => (string)$estado,
    };
    return "instância \"{$instance}\" — {$rotulo}";
}

/**
 * Status cacheado (60s TTL, mesmo padrão exato de zapiStatusPrincipalCache()/
 * oficialStatusCache()) — pro badge do topbar.
 */
function evolutionStatusCache(bool $forcar = false): array {
    [$base, $instance, $apiKey] = evolutionCredenciais();
    if (!$base || !$instance || !$apiKey) {
        return ['estado' => 'nao_configurado', 'verificado_em' => null];
    }

    $cacheRaw = getConfig('evolution_status_cache');
    if (!$forcar && $cacheRaw && str_contains($cacheRaw, '|')) {
        [$ts, $json] = explode('|', $cacheRaw, 2);
        if ((time() - (int)$ts) < 60) {
            $d = json_decode($json, true);
            if (is_array($d) && isset($d['estado'])) return $d;
        }
    }

    $resultado = ['estado' => 'erro', 'verificado_em' => time()];
    $ch = curl_init("{$base}/instance/connectionState/{$instance}");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => _evolutionHeaders($apiKey),
        CURLOPT_TIMEOUT => 4,
        CURLOPT_CONNECTTIMEOUT => 3,
    ]);
    $resp = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!$err && $code === 200) {
        $d = json_decode((string)$resp, true);
        $estado = $d['instance']['state'] ?? $d['state'] ?? null;
        $resultado = ['estado' => $estado === 'open' ? 'conectado' : 'desconectado', 'verificado_em' => time()];
    } elseif ($code === 401 || $code === 403) {
        $resultado = ['estado' => 'desconectado', 'verificado_em' => time()];
    }

    // Mesmo cuidado de zapiStatusPrincipalCache()/oficialStatusCache() —
    // esta função roda sem proteção em toda página do admin via
    // canalPrincipalStatusCache(); gravar o cache nunca pode crashar a
    // página inteira por causa de contenção real do SQLite.
    try {
        setConfig('evolution_status_cache', time() . '|' . json_encode($resultado));
    } catch (Throwable $e) {
        // nunca deixa a página inteira cair por causa de um cache de badge
    }
    return $resultado;
}

/**
 * Adapta 1 payload de webhook Evolution (evento messages.upsert) pro MESMO
 * formato que processarMensagemZapi() já sabe processar — mesmo papel de
 * oficialAdaptarPayloadParaZapi() (includes/whatsapp_oficial.php).
 *
 * Retorna null quando não é mensagem de verdade — qualquer evento que não
 * seja messages.upsert (connection.update, qrcode.updated, etc) ou um
 * messages.upsert sem `data.key.remoteJid` resolvível.
 *
 * Mídia recebida: nunca preenche bytes/URL de propósito (ver docblock do
 * topo do arquivo, ponto 4) — só o mimeType, quando disponível. Cai no
 * mesmo caminho gracioso "mídia não processada" que o projeto já tem pra
 * qualquer bloco sem URL/mediaId resolvível (tipoMidia()==='desconhecido'
 * ou extrairUrlMidia() não acha nada).
 */
function evolutionAdaptarPayloadParaZapi(array $body): ?array {
    $evento = strtolower((string)($body['event'] ?? ''));
    if (!in_array($evento, ['messages.upsert', 'messages_upsert'], true)) {
        return null;
    }
    $data = $body['data'] ?? null;
    if (!is_array($data)) return null;

    $key = $data['key'] ?? [];
    $remoteJid = (string)($key['remoteJid'] ?? '');
    if ($remoteJid === '') return null;

    $isGroup = str_ends_with($remoteJid, '@g.us');
    $phone = (string)preg_replace('/@.*/', '', $remoteJid); // tira @s.whatsapp.net / @g.us

    $mensagem = $data['message'] ?? [];
    $texto = $mensagem['conversation'] ?? $mensagem['extendedTextMessage']['text'] ?? null;

    $adaptado = [
        'messageId' => (string)($key['id'] ?? ''),
        'phone' => $phone,
        'fromMe' => !empty($key['fromMe']),
        'isGroup' => $isGroup,
        'senderName' => (string)($data['pushName'] ?? ''),
        'chatName' => (string)($data['pushName'] ?? ''),
    ];

    if ($texto !== null) {
        $adaptado['text'] = ['message' => (string)$texto];
    } elseif (isset($mensagem['imageMessage'])) {
        $adaptado['image'] = [
            'caption' => (string)($mensagem['imageMessage']['caption'] ?? ''),
            'mimeType' => (string)($mensagem['imageMessage']['mimetype'] ?? ''),
        ];
    } elseif (isset($mensagem['audioMessage'])) {
        $adaptado['audio'] = ['mimeType' => (string)($mensagem['audioMessage']['mimetype'] ?? '')];
    } elseif (isset($mensagem['videoMessage'])) {
        $adaptado['video'] = [
            'caption' => (string)($mensagem['videoMessage']['caption'] ?? ''),
            'mimeType' => (string)($mensagem['videoMessage']['mimetype'] ?? ''),
        ];
    } elseif (isset($mensagem['documentMessage'])) {
        $adaptado['document'] = ['mimeType' => (string)($mensagem['documentMessage']['mimetype'] ?? '')];
    }
    // Tipo desconhecido/evento de ack/reação: fica só com os campos base,
    // tipoMidia() retorna 'desconhecido', mesmo guard silencioso de sempre.

    return $adaptado;
}
