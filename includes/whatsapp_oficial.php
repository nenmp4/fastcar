<?php
/**
 * WhatsApp Cloud API (Meta oficial) — 25/09/2026, "os dois vamos usar api
 * oficial": depois dos DOIS números Z-API (principal e fallback) serem
 * bloqueados de novo, decisão de trocar o canal PRINCIPAL de entrada de
 * lead (bloco 2/3 do funil) pra API oficial da Meta, que não sofre banimento
 * por padrão de mensagem repetida do jeito que a Z-API (protocolo não
 * oficial, WhatsApp Web/multi-device) sofre.
 *
 * **Fase 1 (25/09/2026)**: só texto, só canal PRINCIPAL — resolve a
 * qualificação de lead novo, que é a urgência real.
 *
 * **Fase 2 — mídia (29/09/2026, "sim, temos deixar funcional igual zpi")**:
 * envio E recebimento de imagem/áudio/vídeo/documento, via o mecanismo
 * próprio da Cloud API — upload prévio (`POST /{phone_number_id}/media`,
 * multipart, devolve um `media_id`) seguido de envio referenciando esse id
 * (`type: image/audio/video/document`, campo `id`), nunca base64 direto no
 * corpo como a Z-API aceitava. `_oficialEnviarMidia()` aceita os DOIS
 * formatos que o resto do projeto já usa — URL pública (manda por `link`,
 * sem upload) OU data URI base64 (faz upload primeiro, manda por `id`) —
 * decidido sozinho pelo formato da string recebida. Mídia recebida do
 * cliente nunca vem com URL direta no payload do webhook (diferente da
 * Z-API) — só um `media_id` que exige 2 chamadas autenticadas pra resolver
 * (`oficialBaixarMidiaRecebida()`): `GET /{media_id}` devolve a URL
 * temporária + mime real, depois baixa essa URL com o MESMO Bearer token
 * (o CDN da Meta exige autenticação pra baixar, nunca é link público
 * comum). **Múltiplas instâncias (vendas/financeiro) continuam Z-API** —
 * a Cloud API só entrou como fallback pra ELAS (`zapiEnviarTexto()`/
 * `zapiEnviarImagem()` etc, `includes/whatsapp_config.php`), nunca como
 * canal PRINCIPAL de vendas/financeiro (isso exigiria phone_number_id
 * dedicado por módulo, fora de escopo aqui). **Busca de nome/foto de
 * perfil (`zapiBuscarContato()`) permanece Z-API-only, sem equivalente —
 * a Cloud API oficial não expõe esse dado pra número arbitrário (limitação
 * de privacidade da própria plataforma, não uma lacuna de código; nunca
 * dá pra "portar" isso pro Meta).**
 *
 * **Regra crítica, diferente da Z-API**: só é permitido mandar mensagem de
 * texto LIVRE pra um número que escreveu pra gente nas últimas 24h — fora
 * dessa janela, só "template" pré-aprovado pelo Meta. `oficialEnviarTexto()`
 * NUNCA verifica essa janela sozinha (não temos como saber com certeza sem
 * consultar o histórico — quem chama já opera dentro do fluxo de resposta
 * normal, sempre dentro da janela); a Meta rejeita com um erro específico
 * (`code":131047` "re-engagement message") se a chamada cair fora da janela
 * — `oficialEnviarTexto()` só reporta a falha, nunca decide nada sozinha
 * sobre template (fase 2, se/quando pedido).
 *
 * Nunca confirmado contra a API real ainda (sem Phone Number ID/token de
 * verdade nesta sessão) — construído a partir da documentação pública e
 * estável da Cloud API (developers.facebook.com/docs/whatsapp), mesma
 * ressalva de todo provedor novo deste projeto ("a validar em produção").
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';

/** Base URL override só em teste (fake server local), mesmo padrão do resto do projeto. */
function oficialBaseUrl(): string {
    return defined('WHATSAPP_OFICIAL_BASE_URL') ? WHATSAPP_OFICIAL_BASE_URL : 'https://graph.facebook.com/v21.0';
}

/**
 * [phone_number_id, access_token, verify_token] — '' se algum não estiver
 * configurado ainda. `verify_token` é só pro handshake GET do webhook
 * (inventado pelo próprio usuário em Configurações, nunca vem do Meta).
 */
function oficialCredenciais(): array {
    return [
        getConfig('whatsapp_oficial_phone_number_id') ?: '',
        getConfig('whatsapp_oficial_access_token') ?: '',
        getConfig('whatsapp_oficial_verify_token') ?: '',
    ];
}

function oficialConfigured(): bool {
    [$phoneId, $token] = oficialCredenciais();
    return $phoneId !== '' && $token !== '';
}

/**
 * Canal principal usa API oficial? — chave central que
 * zapiEnviarTexto()/o webhook consultam pra decidir de onde vem/vai
 * mensagem do canal de compra. Default 'zapi' (nunca muda comportamento
 * sozinho — só depois que o usuário confirmar em Configurações que a API
 * oficial está pronta e testada).
 */
function oficialEhProviderPrincipal(): bool {
    return getConfig('whatsapp_provider_principal') === 'oficial' && oficialConfigured();
}

/**
 * Envia texto via Cloud API — POST /{phone_number_id}/messages, Bearer
 * token. Nunca lança; retorna false em qualquer falha (sem credencial,
 * erro de rede, erro reportado pela Meta — inclusive fora da janela de
 * 24h, código 131047). Loga o corpo de erro em oficialUltimoErro() pra
 * diagnóstico, mesmo padrão de GoogleDrive::lastError.
 */
$GLOBALS['_oficial_ultimo_erro'] = null;
function oficialUltimoErro(): ?string {
    return $GLOBALS['_oficial_ultimo_erro'];
}
function _oficialSetUltimoErro(?string $msg): void {
    $GLOBALS['_oficial_ultimo_erro'] = $msg;
}

function oficialEnviarTexto(string $phone, string $msg): bool {
    [$phoneId, $token] = oficialCredenciais();
    if (!$phoneId || !$token || !$phone) {
        _oficialSetUltimoErro('Sem Phone Number ID/token configurado.');
        return false;
    }
    $phoneNorm = normalizarTelefone($phone);
    if (strlen($phoneNorm) < 12) {
        _oficialSetUltimoErro('Telefone inválido.');
        return false;
    }

    $body = [
        'messaging_product' => 'whatsapp',
        'to' => $phoneNorm,
        'type' => 'text',
        'text' => ['body' => $msg, 'preview_url' => false],
    ];

    $ch = curl_init(oficialBaseUrl() . "/{$phoneId}/messages");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT => 20,
    ]);
    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($resp === false) {
        _oficialSetUltimoErro("Falha de conexão: {$curlErr}");
        return false;
    }
    $json = json_decode($resp, true);
    if ($httpCode >= 200 && $httpCode < 300 && !empty($json['messages'][0]['id'])) {
        _oficialSetUltimoErro(null);
        return true;
    }
    $erroMsg = $json['error']['message'] ?? "HTTP {$httpCode}";
    $erroCode = $json['error']['code'] ?? null;
    _oficialSetUltimoErro("{$erroMsg}" . ($erroCode ? " (code {$erroCode})" : '') . " — resposta: " . substr($resp, 0, 500));
    return false;
}

/**
 * Se `$s` for um data URI base64 (`data:{mime};base64,{...}`), devolve
 * `['mime'=>string,'bytes'=>string]` — senão `null`. Helper puro, sem
 * chamada de rede.
 */
function _oficialParseDataUri(string $s): ?array {
    if (!preg_match('#^data:([a-zA-Z0-9/+.\-]+);base64,(.+)$#s', $s, $m)) {
        return null;
    }
    $bytes = base64_decode($m[2], true);
    if ($bytes === false) {
        return null;
    }
    return ['mime' => $m[1], 'bytes' => $bytes];
}

/**
 * Faz upload de mídia pra Cloud API — POST /{phone_number_id}/media,
 * multipart/form-data (`messaging_product=whatsapp`, `type`, `file`) —
 * devolve o `media_id` gerado, ou `null` em falha. Passo prévio obrigatório
 * pra mandar qualquer mídia que só existe como base64 (a Cloud API nunca
 * aceita bytes direto no corpo de `/messages`, só `link` OU `id` de um
 * upload já feito). `CURLStringFile` (PHP 8.1+) evita precisar escrever um
 * arquivo temporário em disco só pra montar o multipart.
 */
function oficialUploadMedia(string $bytes, string $mime, string $nomeArquivo = 'arquivo'): ?string {
    [$phoneId, $token] = oficialCredenciais();
    if (!$phoneId || !$token || $bytes === '') {
        _oficialSetUltimoErro('Sem Phone Number ID/token configurado, ou arquivo vazio.');
        return null;
    }

    $ch = curl_init(oficialBaseUrl() . "/{$phoneId}/media");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token],
        CURLOPT_POSTFIELDS => [
            'messaging_product' => 'whatsapp',
            'type' => $mime,
            'file' => new CURLStringFile($bytes, $nomeArquivo, $mime),
        ],
        CURLOPT_TIMEOUT => 40,
    ]);
    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($resp === false) {
        _oficialSetUltimoErro("Falha de conexão no upload de mídia: {$curlErr}");
        return null;
    }
    $json = json_decode($resp, true);
    if ($httpCode >= 200 && $httpCode < 300 && !empty($json['id'])) {
        return (string)$json['id'];
    }
    $erroMsg = $json['error']['message'] ?? "HTTP {$httpCode}";
    _oficialSetUltimoErro("Falha no upload de mídia: {$erroMsg} — resposta: " . substr((string)$resp, 0, 500));
    return null;
}

/**
 * Envia mídia (imagem/áudio/vídeo/documento) via Cloud API — POST
 * /{phone_number_id}/messages, type={tipo}, campo {tipo}={link|id, caption?,
 * filename?}. Aceita URL pública (manda por `link`, sem upload) OU data URI
 * base64 (`oficialUploadMedia()` primeiro, manda por `id`) — decide sozinho
 * pelo formato de `$urlOuDataUri`, cobrindo os 2 formatos que o resto do
 * projeto já usa (Z-API sempre aceitou os dois). Áudio nunca aceita
 * `caption` no WhatsApp (mesma limitação já documentada pro lado Z-API,
 * `zapiEnviarAudio()`); documento sempre manda `filename`, os outros nunca.
 * Nunca lança — falha de qualquer etapa (upload ou envio) só seta
 * `oficialUltimoErro()` e retorna false.
 */
function _oficialEnviarMidia(string $phone, string $tipo, string $urlOuDataUri, string $legenda, string $nomeArquivoFallback): bool {
    [$phoneId, $token] = oficialCredenciais();
    if (!$phoneId || !$token || !$phone || !$urlOuDataUri) {
        _oficialSetUltimoErro('Sem Phone Number ID/token configurado, ou telefone/arquivo vazio.');
        return false;
    }
    $phoneNorm = normalizarTelefone($phone);
    if (strlen($phoneNorm) < 12) {
        _oficialSetUltimoErro('Telefone inválido.');
        return false;
    }

    $campo = [];
    $dataUri = _oficialParseDataUri($urlOuDataUri);
    if ($dataUri !== null) {
        $mediaId = oficialUploadMedia($dataUri['bytes'], $dataUri['mime'], $nomeArquivoFallback);
        if (!$mediaId) return false; // oficialUltimoErro() já setado por oficialUploadMedia()
        $campo['id'] = $mediaId;
    } elseif (preg_match('#^https?://#i', $urlOuDataUri)) {
        $campo['link'] = $urlOuDataUri;
    } else {
        _oficialSetUltimoErro('Formato de mídia não reconhecido (nem URL http(s), nem data URI base64).');
        return false;
    }
    if ($legenda !== '' && $tipo !== 'audio') {
        $campo['caption'] = $legenda;
    }
    if ($tipo === 'document') {
        $campo['filename'] = $nomeArquivoFallback;
    }

    $body = [
        'messaging_product' => 'whatsapp',
        'to' => $phoneNorm,
        'type' => $tipo,
        $tipo => $campo,
    ];

    $ch = curl_init(oficialBaseUrl() . "/{$phoneId}/messages");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT => 30,
    ]);
    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($resp === false) {
        _oficialSetUltimoErro("Falha de conexão: {$curlErr}");
        return false;
    }
    $json = json_decode($resp, true);
    if ($httpCode >= 200 && $httpCode < 300 && !empty($json['messages'][0]['id'])) {
        _oficialSetUltimoErro(null);
        return true;
    }
    $erroMsg = $json['error']['message'] ?? "HTTP {$httpCode}";
    $erroCode = $json['error']['code'] ?? null;
    _oficialSetUltimoErro("{$erroMsg}" . ($erroCode ? " (code {$erroCode})" : '') . " — resposta: " . substr($resp, 0, 500));
    return false;
}

/** Imagem com legenda — URL pública ou base64 (upload automático). */
function oficialEnviarImagem(string $phone, string $imagemUrl, string $legenda): bool {
    return _oficialEnviarMidia($phone, 'image', $imagemUrl, $legenda, 'imagem.jpg');
}

/** Áudio — URL pública ou base64 (upload automático). Sem legenda — WhatsApp não aceita caption em áudio. */
function oficialEnviarAudio(string $phone, string $audioDataUriOuUrl): bool {
    return _oficialEnviarMidia($phone, 'audio', $audioDataUriOuUrl, '', 'audio.ogg');
}

/** Vídeo com legenda — URL pública ou base64 (upload automático). */
function oficialEnviarVideo(string $phone, string $videoUrlOuDataUri, string $legenda): bool {
    return _oficialEnviarMidia($phone, 'video', $videoUrlOuDataUri, $legenda, 'video.mp4');
}

/** Documento (PDF/Word/planilha) — URL pública ou base64 (upload automático). `$fileName` vira o nome exibido na bolha. */
function oficialEnviarDocumento(string $phone, string $documentoDataUriOuUrl, string $fileName): bool {
    return _oficialEnviarMidia($phone, 'document', $documentoDataUriOuUrl, '', $fileName !== '' ? $fileName : 'documento.pdf');
}

/**
 * Baixa mídia RECEBIDA do cliente via Cloud API — 2 chamadas autenticadas,
 * diferente da Z-API (que já entrega uma URL direta no payload do
 * webhook): (1) `GET /{media_id}` resolve pro id um `url` temporário +
 * `mime_type` real; (2) baixa essa URL com o MESMO Bearer token — o CDN da
 * Meta exige autenticação pra baixar, nunca é link público comum, senão
 * dá 401/403. Mesmo corte de tamanho
 * (`WHATSAPP_MIDIA_MAX_BYTES`, definida em
 * `chatbot-whatsapp/includes/mensagens.php`, com fallback pra 20MB se por
 * algum motivo essa constante não estiver carregada ainda) e mesma
 * disciplina de "nunca lança" do equivalente Z-API (`baixarMidiaZapi()`).
 * Retorna `['bytes'=>string,'mime'=>string]` ou `null` em qualquer falha —
 * quem chama decide o que logar (mesmo padrão de `logDiagnosticoMidiaZapi()`).
 */
function oficialBaixarMidiaRecebida(string $mediaId, string $tipo): ?array {
    [, $token] = oficialCredenciais();
    if (!$mediaId || !$token) return null;
    $maxBytes = defined('WHATSAPP_MIDIA_MAX_BYTES') ? WHATSAPP_MIDIA_MAX_BYTES : (20 * 1024 * 1024);

    try {
        $ch = curl_init(oficialBaseUrl() . "/{$mediaId}");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token],
            CURLOPT_TIMEOUT => 15,
        ]);
        $resp = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($http !== 200 || !$resp) return null;

        $meta = json_decode($resp, true);
        $url = $meta['url'] ?? null;
        if (!is_string($url) || $url === '') return null;

        $ch2 = curl_init($url);
        curl_setopt_array($ch2, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token],
            CURLOPT_TIMEOUT => $tipo === 'video' ? 40 : 20,
            CURLOPT_RANGE => '0-' . ($maxBytes - 1),
        ]);
        $conteudo = curl_exec($ch2);
        $http2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
        curl_close($ch2);
        if (!in_array($http2, [200, 206], true) || !$conteudo || strlen($conteudo) >= $maxBytes) {
            return null;
        }
        return ['bytes' => $conteudo, 'mime' => (string)($meta['mime_type'] ?? '')];
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Testa a conexão — GET /{phone_number_id} (leitura simples, sem custo/
 * efeito colateral, mesmo espírito de GoogleDrive::testarConexao()).
 * Retorna o display_phone_number confirmado pela Meta em sucesso, ou
 * lança pra a tela mostrar o erro.
 */
function oficialTestarConexao(): string {
    [$phoneId, $token] = oficialCredenciais();
    if (!$phoneId || !$token) {
        throw new RuntimeException('Phone Number ID/token não configurados.');
    }
    $ch = curl_init(oficialBaseUrl() . "/{$phoneId}?fields=display_phone_number,verified_name");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token],
        CURLOPT_TIMEOUT => 15,
    ]);
    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $json = json_decode((string)$resp, true);
    if ($httpCode !== 200 || !isset($json['display_phone_number'])) {
        $erro = $json['error']['message'] ?? "HTTP {$httpCode}";
        throw new RuntimeException("Meta respondeu com erro: {$erro}");
    }
    return ($json['verified_name'] ?? '') . ' — ' . $json['display_phone_number'];
}

/**
 * Status cacheado da API oficial (60s TTL, mesmo padrão exato de
 * zapiStatusPrincipalCache() em includes/whatsapp_config.php) — pro badge
 * do topbar mostrar o canal principal DE VERDADE, 29/09/2026 ("mudei
 * [o toggle pra oficial] mais dica zpi bolinha" — o badge antigo sempre
 * mostrava status da Z-API, mesmo depois do toggle já estar em 'oficial').
 * Retorna ['estado' => 'conectado'|'desconectado'|'erro'|'nao_configurado',
 * 'verificado_em' => ?int].
 */
function oficialStatusCache(bool $forcar = false): array {
    [$phoneId, $token] = oficialCredenciais();
    if (!$phoneId || !$token) {
        return ['estado' => 'nao_configurado', 'verificado_em' => null];
    }

    $cacheRaw = getConfig('whatsapp_oficial_status_cache');
    if (!$forcar && $cacheRaw && str_contains($cacheRaw, '|')) {
        [$ts, $json] = explode('|', $cacheRaw, 2);
        if ((time() - (int)$ts) < 60) {
            $d = json_decode($json, true);
            if (is_array($d) && isset($d['estado'])) return $d;
        }
    }

    $resultado = ['estado' => 'erro', 'verificado_em' => time()];
    $ch = curl_init(oficialBaseUrl() . "/{$phoneId}?fields=display_phone_number");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token],
        CURLOPT_TIMEOUT => 4,
        CURLOPT_CONNECTTIMEOUT => 3,
    ]);
    $resp = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!$err && $code === 200) {
        $d = json_decode((string)$resp, true);
        // Cloud API não expõe "conectado/desconectado" como a Z-API (não é
        // pareamento de dispositivo) — responder 200 com o número já é o
        // sinal de que token+phone_number_id estão válidos e acessíveis.
        $resultado = ['estado' => is_array($d) && isset($d['display_phone_number']) ? 'conectado' : 'erro', 'verificado_em' => time()];
    } elseif ($code === 401 || $code === 403) {
        $resultado = ['estado' => 'desconectado', 'verificado_em' => time()]; // token inválido/expirado
    }

    setConfig('whatsapp_oficial_status_cache', time() . '|' . json_encode($resultado));
    return $resultado;
}

/**
 * Adapta 1 payload de webhook da Cloud API (formato
 * entry[].changes[].value) pro MESMO formato que processarMensagemZapi()
 * (chatbot-whatsapp/includes/mensagens.php) já sabe processar — reaproveita
 * 100% da lógica de dedup/qualificação/IA já testada, sem duplicar nada.
 *
 * Retorna null quando o payload não é mensagem de verdade (é `statuses` —
 * confirmação de entrega/leitura, sem `messages[]` — mesma classe de
 * "evento não é mensagem" já resolvida uma vez pra Z-API em 15/09/2026;
 * aqui a Cloud API já separa isso estruturalmente em campos diferentes,
 * então nunca precisa de heurística — só checar se `messages[]` existe).
 *
 * Mídia (29/09/2026, Fase 2 implementada): nunca preenche URL de propósito
 * — a Cloud API não entrega URL direta no payload do webhook, só um
 * `mediaId`. `chatbot-whatsapp/includes/mensagens.php` detecta esse campo
 * (`!empty($bloco['mediaId'])`) e resolve/baixa via `oficialBaixarMidiaRecebida()`
 * em vez do caminho `extrairUrlMidia()`/`baixarMidiaZapi()` (Z-API), que
 * continua intocado pra payload que nunca tem `mediaId`.
 */
function oficialAdaptarPayloadParaZapi(array $body): ?array {
    $value = $body['entry'][0]['changes'][0]['value'] ?? null;
    if (!is_array($value) || empty($value['messages'][0])) {
        return null; // status de entrega/leitura, ou payload sem mensagem — ignora
    }
    $msg = $value['messages'][0];
    $nomeContato = $value['contacts'][0]['profile']['name'] ?? '';

    $adaptado = [
        'messageId' => (string)($msg['id'] ?? ''),
        'phone' => (string)($msg['from'] ?? ''),
        'fromMe' => false, // Cloud API só entrega INBOUND em "messages" — o que a gente manda nunca volta aqui
        'isGroup' => false, // WhatsApp Business Cloud API não suporta grupo
        'senderName' => $nomeContato,
        'chatName' => $nomeContato,
    ];

    // 29/09/2026, CPL das campanhas Meta — achado real relendo este
    // adaptador: `msg['referral']` (o objeto que a Cloud API entrega na 1ª
    // mensagem de uma conversa iniciada por clique em anúncio) nunca era
    // propagado pro payload adaptado, então extrairOrigemAnuncio() (que já
    // tinha um fallback pra esse formato desde 18/09/2026) NUNCA disparava
    // de verdade pra mensagem chegando pelo canal oficial — todo lead via
    // Cloud API caía em "(direto / sem anúncio)" mesmo vindo de anúncio.
    if (!empty($msg['referral']) && is_array($msg['referral'])) {
        $adaptado['referral'] = $msg['referral'];
    }

    $tipo = (string)($msg['type'] ?? '');
    if ($tipo === 'text') {
        $adaptado['text'] = ['message' => (string)($msg['text']['body'] ?? '')];
    } elseif ($tipo === 'button') {
        $adaptado['text'] = ['message' => (string)($msg['button']['text'] ?? '')];
    } elseif ($tipo === 'interactive') {
        $texto = $msg['interactive']['button_reply']['title']
            ?? $msg['interactive']['list_reply']['title']
            ?? '';
        $adaptado['text'] = ['message' => (string)$texto];
    } elseif (in_array($tipo, ['image', 'audio', 'video', 'document', 'sticker'], true)) {
        // Fase 2 — sem URL, extrairUrlMidia() não vai achar nada, segue
        // como mídia não processada (mesmo caminho já existente).
        $adaptado[$tipo] = [
            'mediaId' => $msg[$tipo]['id'] ?? '',
            'mimeType' => $msg[$tipo]['mime_type'] ?? '',
            'caption' => $msg[$tipo]['caption'] ?? '',
        ];
    } elseif ($tipo === 'location') {
        $adaptado['location'] = $msg['location'] ?? [];
    } elseif ($tipo === 'contacts') {
        $adaptado['contact'] = $msg['contacts'][0] ?? [];
    }
    // Tipo desconhecido (reaction, unsupported etc): fica só com os
    // campos base, tipoMidia() retorna 'desconhecido', mesmo guard
    // silencioso já existente pra evento não-mensagem da Z-API.

    return $adaptado;
}
