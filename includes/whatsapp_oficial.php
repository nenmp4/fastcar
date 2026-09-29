<?php
/**
 * WhatsApp Cloud API (Meta oficial) — 25/09/2026, "os dois vamos usar api
 * oficial": depois dos DOIS números Z-API (principal e fallback) serem
 * bloqueados de novo, decisão de trocar o canal PRINCIPAL de entrada de
 * lead (bloco 2/3 do funil) pra API oficial da Meta, que não sofre banimento
 * por padrão de mensagem repetida do jeito que a Z-API (protocolo não
 * oficial, WhatsApp Web/multi-device) sofre.
 *
 * **Fase 1 (esta rodada)**: só texto, só canal PRINCIPAL — resolve a
 * qualificação de lead novo, que é a urgência real. Mídia (foto/áudio/vídeo
 * recebidos), múltiplas instâncias (vendas/financeiro) e envio de
 * documento/imagem pelo WhatsApp Box ficam pra uma fase 2, quando/se pedido —
 * a API oficial exige mecanismo diferente pra mídia (upload prévio via
 * `/media`, nunca base64 direto no corpo como a Z-API aceitava), escopo
 * maior, não implementado agora pra não atrasar a fase 1 que resolve a
 * urgência.
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
 * Mídia (Fase 2, não implementada): marca o tipo reconhecido mas sem
 * nenhum campo de URL — extrairUrlMidia() (mensagens.php) não acha nada,
 * loga o diagnóstico e segue como mídia não processada, mesmo caminho já
 * existente pra Z-API quando o campo de URL não bate.
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
