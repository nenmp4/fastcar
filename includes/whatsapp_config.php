<?php
/**
 * Config base do bot WhatsApp — mesmo padrão do JurídicoSaaS
 * (chatbot-whatsapp/includes/whatsapp_config.php).
 * Credenciais da instância Z-API própria da Fastcar ficam em `config`
 * (chave/valor no SQLite), preenchidas via admin quando a instância for
 * criada — nunca hardcoded aqui.
 */

define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/includes/db.php';

define('BOT_DEBUG', false);

function _chatbot_getConfig(string $chave): string {
    return getConfig($chave) ?? '';
}

/** Base URL override via define() só em teste (fake server local) — mesmo padrão dos outros includes/*.php. */
function zapiBaseUrl(): string {
    return defined('ZAPI_BASE_URL') ? ZAPI_BASE_URL : 'https://api.z-api.io';
}

/**
 * Status (conectado/desconectado) da instância Z-API PRINCIPAL (compra/
 * leads), com cache curto — 23/09/2026, "tem como colocar status da
 * instancia topo zpi conectado em destaque ai eu não preciso ir no saude
 * ver": badge no topbar (admin/_zapi_status.php) pra ver de relance sem
 * abrir admin/saude.php. `admin/saude.php` continua com seu próprio check
 * ao vivo (curl_multi junto de todos os outros, sem cache) — é a página de
 * diagnóstico completo, nunca precisou de cache; esta função é só pro
 * badge leve, que roda em praticamente toda página do admin.
 *
 * Cache de 60s em `config.zapi_status_cache` (mesmo padrão
 * "timestamp|json" de sempre, ex: cotacaoUsdBrl()/PlacaFIPE) — sem isso,
 * cada carregamento de página (e o polling do badge) bateria na Z-API de
 * novo, sem necessidade: status de conexão não muda segundo a segundo.
 * `$forcar` ignora o cache (usado só se um dia precisar de refresh manual;
 * nenhum caller força hoje).
 */
function zapiStatusPrincipalCache(bool $forcar = false): array {
    $inst = getConfig('zapi_instance_id') ?: '';
    $tok = getConfig('zapi_token') ?: '';
    if (!$inst || !$tok) {
        return ['estado' => 'nao_configurado', 'verificado_em' => null];
    }

    $cacheRaw = getConfig('zapi_status_cache');
    if (!$forcar && $cacheRaw && str_contains($cacheRaw, '|')) {
        [$ts, $json] = explode('|', $cacheRaw, 2);
        if ((time() - (int)$ts) < 60) {
            $d = json_decode($json, true);
            if (is_array($d) && isset($d['estado'])) return $d;
        }
    }

    $cli = getConfig('zapi_client_token') ?: '';
    $headers = ['Content-Type: application/json'];
    if ($cli) $headers[] = 'client-token: ' . $cli;

    $resultado = ['estado' => 'erro', 'verificado_em' => time()];
    $ch = curl_init(zapiBaseUrl() . "/instances/{$inst}/token/{$tok}/status");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 4,
        CURLOPT_CONNECTTIMEOUT => 3,
    ]);
    $resp = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!$err && $code === 200) {
        $d = json_decode((string)$resp, true);
        if (is_array($d)) {
            $resultado = ['estado' => !empty($d['connected']) ? 'conectado' : 'desconectado', 'verificado_em' => time()];
        }
    }

    setConfig('zapi_status_cache', time() . '|' . json_encode($resultado));
    return $resultado;
}

/**
 * Credenciais da instância Z-API DEDICADA de vendas (17/09/2026, módulo de
 * vendas ganhando funil de entrada pelo WhatsApp próprio — pedido
 * José/Jean: "vamos adcionar instancia só para vendas"). Config separada
 * da instância principal (zapi_instance_id/token/client_token, sempre a de
 * COMPRA) — as duas convivem, cada uma com seu próprio número/webhook.
 * Retorna [instance_id, token, client_token], todos '' se não configurada
 * ainda (quem chama decide o que fazer — zapiEnviarTexto() etc já tratam
 * "sem instância" como falha graciosa).
 */
function zapiCredenciaisVendas(): array {
    return [
        _chatbot_getConfig('zapi_instancia_vendas_id'),
        _chatbot_getConfig('zapi_instancia_vendas_token'),
        _chatbot_getConfig('zapi_instancia_vendas_client_token'),
    ];
}

/**
 * Credenciais da instância Z-API DEDICADA do financeiro (18/09/2026, pedido
 * José/Jean: "vamos fazer gestão desses clientes que não paga fazer
 * cobrança pelo sistema vai ser instancias só do finceir outro numero") —
 * mesmo padrão de `zapiCredenciaisVendas()`, número/webhook PRÓPRIO,
 * separado da instância principal (compra) e da de vendas. Retorna
 * [instance_id, token, client_token], '' se ainda não configurada.
 */
function zapiCredenciaisFinanceiro(): array {
    return [
        _chatbot_getConfig('zapi_instancia_financeiro_id'),
        _chatbot_getConfig('zapi_instancia_financeiro_token'),
        _chatbot_getConfig('zapi_instancia_financeiro_client_token'),
    ];
}

/**
 * Credenciais da instância Z-API FALLBACK, só pra ENVIO (20/09/2026,
 * "quero clocar instancia fallback" — depois do incidente de bloqueio da
 * instância principal, 19-20/09/2026). Nunca recebe webhook nem é
 * roteada por zapiIdentificarInstancia() — é usada só como tentativa
 * automática de reenvio quando zapiEnviarTexto() pela instância PRINCIPAL
 * falha (erro/limite temporário), pra nunca perder uma mensagem de saída
 * (resposta da IA, notificação, reengajamento) por causa de instabilidade
 * pontual de uma instância só. ⚠️ Nunca ajuda contra um NÚMERO banido de
 * verdade pelo WhatsApp — nesse caso a mensagem sai por um número
 * DIFERENTE do que o cliente já conhece (sem jeito técnico de "herdar" a
 * conversa de um número banido, WhatsApp não permite isso de forma
 * nenhuma); é rede de segurança pra falha passageira de envio, não pra
 * bloqueio permanente do número principal.
 */
function zapiCredenciaisFallback(): array {
    return [
        _chatbot_getConfig('zapi_fallback_instance_id'),
        _chatbot_getConfig('zapi_fallback_token'),
        _chatbot_getConfig('zapi_fallback_client_token'),
    ];
}

/**
 * Envia mensagem de texto via Z-API. Mesma assinatura/lógica do
 * aaspNotificarWpp() do JurídicoSaaS (includes/aasp.php), renomeada pro
 * contexto deste projeto.
 *
 * $instanciaOverride (17/09/2026): [instance_id, token, client_token]
 * opcional — usado pra mandar pela instância DEDICADA de vendas em vez da
 * principal (zapiCredenciaisVendas()), sem duplicar a função inteira só
 * pra trocar de onde lê a credencial. Omitido (padrão) = instância
 * principal, igual sempre foi — nenhum dos ~40 call sites existentes
 * precisou mudar.
 */
/**
 * 20/09/2026 — quando chamada pra instância PRINCIPAL (sem
 * $instanciaOverride, ou seja, nunca pra vendas/financeiro, que têm seus
 * próprios números e não faz sentido "socorrer" com o número de compra) e
 * o envio falha, tenta uma vez de novo pela instância FALLBACK
 * (zapiCredenciaisFallback()) antes de desistir — nunca perde uma
 * mensagem de saída (resposta da IA, notificação, reengajamento) só
 * porque a instância principal deu erro passageiro. Sem fallback
 * configurado, comportamento idêntico a antes (só falha mesmo).
 */
function zapiEnviarTexto(string $phone, string $msg, ?array $instanciaOverride = null): bool {
    $usandoPrincipal = $instanciaOverride === null;
    [$inst, $tok, $ctok] = $instanciaOverride ?? [
        _chatbot_getConfig('zapi_instance_id'),
        _chatbot_getConfig('zapi_token'),
        _chatbot_getConfig('zapi_client_token'),
    ];
    if (!$inst || !$tok || !$phone) return false;

    $phone = normalizarTelefone($phone);
    if (strlen($phone) < 12) return false;

    if (_zapiEnviarTextoBruto($phone, $msg, $inst, $tok, $ctok)) return true;

    if ($usandoPrincipal) {
        [$instFb, $tokFb, $ctokFb] = zapiCredenciaisFallback();
        if ($instFb && $tokFb) {
            $ok = _zapiEnviarTextoBruto($phone, $msg, $instFb, $tokFb, $ctokFb);
            if ($ok) {
                registrarUsoFallbackZapi($phone);
                alertarUsoFallbackZapi($instFb, $tokFb, $ctokFb);
            }
            return $ok;
        }
    }
    return false;
}

/**
 * Log de cada vez que o fallback foi realmente usado pra completar um
 * envio (20/09/2026, "como vou saber que instância estou operando") —
 * best-effort, nunca pode travar o envio que já deu certo.
 * admin/saude.php lê este arquivo pra mostrar um indicador visual.
 */
function registrarUsoFallbackZapi(string $telefone): void {
    try {
        $dir = __DIR__ . '/../storage/logs';
        @mkdir($dir, 0755, true);
        @file_put_contents($dir . '/whatsapp_fallback_usado.log', '[' . date('Y-m-d H:i:s') . "] Fallback usado pra {$telefone}\n", FILE_APPEND);
    } catch (Throwable $e) {
        // log nunca pode travar o envio
    }
}

/**
 * Avisa os números de notificação genérica (`notificacao_leads_whatsapp`,
 * mesma lista de `notificarNovoLeadWhatsapp()`) quando a instância
 * PRINCIPAL falha e o fallback precisou assumir — pra ficar sabendo na
 * hora, não só olhando o log/Saúde depois. Dedup de 1h
 * (`zapi_fallback_alerta_enviado`, mesmo padrão `alerta_atraso_{id}` de
 * cron/followup.php) pra nunca virar spam numa sequência de falhas
 * seguidas da principal. Manda pela própria instância FALLBACK — a única
 * confirmada funcionando nesse momento — via _zapiEnviarTextoBruto()
 * direto, nunca zapiEnviarTexto() de novo aqui (evita repetir uma
 * tentativa pela principal já fadada a falhar só pra mandar o aviso).
 */
function alertarUsoFallbackZapi(string $instFb, string $tokFb, string $ctokFb): void {
    try {
        $guardKey = 'zapi_fallback_alerta_enviado';
        $ultimo = getConfig($guardKey);
        if ($ultimo && (time() - strtotime($ultimo)) < 3600) return;

        $lista = _chatbot_getConfig('notificacao_leads_whatsapp');
        $numeros = array_filter(array_map('trim', explode(',', $lista)));
        if (!$numeros) return;

        $msg = "⚠️ *Fastcar CRM — instância principal do WhatsApp falhou*\n\nO envio caiu automaticamente pra instância FALLBACK. Verifique a conexão da instância principal (Configurações → Z-API, ou admin/saude.php).";
        $enviouAlgum = false;
        foreach ($numeros as $numero) {
            $tel = normalizarTelefone($numero);
            if (strlen($tel) >= 12 && _zapiEnviarTextoBruto($tel, $msg, $instFb, $tokFb, $ctokFb)) {
                $enviouAlgum = true;
            }
        }
        if ($enviouAlgum) {
            setConfig($guardKey, date('Y-m-d H:i:s'));
        }
    } catch (Throwable $e) {
        // alerta nunca pode travar o envio principal
    }
}

function _zapiEnviarTextoBruto(string $phone, string $msg, string $inst, string $tok, string $ctok): bool {
    $headers = ['Content-Type: application/json'];
    if ($ctok) $headers[] = 'client-token: ' . $ctok;

    $ch = curl_init(zapiBaseUrl() . "/instances/{$inst}/token/{$tok}/send-text");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => json_encode(['phone' => $phone, 'message' => $msg]),
        CURLOPT_TIMEOUT => 10,
    ]);
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code === 200;
}

/**
 * Envia imagem com legenda via Z-API (POST /send-image, campos `image`
 * [URL pública ou base64] + `caption` — docs.z-api.io/message/
 * send-message-image). Usado pra mandar o link do wizard de documentos
 * com a logo da Fastcar como capa em vez de texto puro (pedido do
 * José/Jean, 13/09/2026: passa mais confiança/profissionalismo — mesma
 * preocupação de "isso não é golpe?" já coberta no prompt da IA de
 * qualificação e no rodapé com endereço real do wizard) e, desde
 * 17/09/2026, pra IA de vendas mandar foto do catálogo de um veículo da
 * frota (`$instanciaOverride`, mesmo padrão de `zapiEnviarTexto()`).
 */
function zapiEnviarImagem(string $phone, string $imagemUrl, string $legenda, ?array $instanciaOverride = null): bool {
    [$inst, $tok, $ctok] = $instanciaOverride ?? [
        _chatbot_getConfig('zapi_instance_id'),
        _chatbot_getConfig('zapi_token'),
        _chatbot_getConfig('zapi_client_token'),
    ];
    if (!$inst || !$tok || !$phone || !$imagemUrl) return false;

    $phone = normalizarTelefone($phone);
    if (strlen($phone) < 12) return false;

    $headers = ['Content-Type: application/json'];
    if ($ctok) $headers[] = 'client-token: ' . $ctok;

    $ch = curl_init(zapiBaseUrl() . "/instances/{$inst}/token/{$tok}/send-image");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => json_encode(['phone' => $phone, 'image' => $imagemUrl, 'caption' => $legenda]),
        CURLOPT_TIMEOUT => 15,
    ]);
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code === 200;
}

/**
 * Envia vídeo com legenda via Z-API (POST /send-video, campos `video`
 * [URL pública ou base64] + `caption` — mesmo formato de `send-image`,
 * nunca confirmado contra instância real ainda, mesma ressalva de "a
 * validar em produção" — ver CLAUDE.md). Novo em 17/09/2026, pra IA de
 * vendas mandar vídeo do catálogo de um veículo da frota
 * (`includes/ia_qualificacao_vendas.php`), junto das fotos.
 */
function zapiEnviarVideo(string $phone, string $videoUrl, string $legenda, ?array $instanciaOverride = null): bool {
    [$inst, $tok, $ctok] = $instanciaOverride ?? [
        _chatbot_getConfig('zapi_instance_id'),
        _chatbot_getConfig('zapi_token'),
        _chatbot_getConfig('zapi_client_token'),
    ];
    if (!$inst || !$tok || !$phone || !$videoUrl) return false;

    $phone = normalizarTelefone($phone);
    if (strlen($phone) < 12) return false;

    $headers = ['Content-Type: application/json'];
    if ($ctok) $headers[] = 'client-token: ' . $ctok;

    $ch = curl_init(zapiBaseUrl() . "/instances/{$inst}/token/{$tok}/send-video");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => json_encode(['phone' => $phone, 'video' => $videoUrl, 'caption' => $legenda]),
        CURLOPT_TIMEOUT => 30,
    ]);
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code === 200;
}

/**
 * Envia áudio via Z-API (POST /send-audio, campo `audio` — URL pública ou
 * data URI base64 `data:{mime};base64,{...}`, confirmado via busca na
 * documentação oficial Z-API, docs.z-api.io/message/send-message-audio,
 * mesma ressalva de todo endpoint Z-API que não seja envio de texto — "a
 * validar em produção", ver CLAUDE.md). Usado pelo WhatsApp Box
 * (`enviarAudioManualWhatsapp()`, `includes/whatsapp_inbox.php`, 17/09/2026,
 * "permita enviar audio no inbox para o cliente") pra mandar áudio que o
 * consultor anexa direto pela caixa — manda como data URI, não precisa de
 * URL pública própria hospedada (o arquivo já fica salvo à parte via
 * `salvarMidiaWhatsappRecebida()` pra reproduzir depois na thread do CRM,
 * mas o envio pro Z-API em si não depende dessa cópia estar pronta).
 * Áudio não tem legenda no WhatsApp (diferente de imagem/vídeo) — por isso,
 * ao contrário de `zapiEnviarTexto()`, não dá pra "assinar" com o nome do
 * consultor dentro da própria mensagem que o cliente recebe.
 */
function zapiEnviarAudio(string $phone, string $audioDataUriOuUrl): bool {
    $inst = _chatbot_getConfig('zapi_instance_id');
    $tok  = _chatbot_getConfig('zapi_token');
    $ctok = _chatbot_getConfig('zapi_client_token');
    if (!$inst || !$tok || !$phone || !$audioDataUriOuUrl) return false;

    $phone = normalizarTelefone($phone);
    if (strlen($phone) < 12) return false;

    $headers = ['Content-Type: application/json'];
    if ($ctok) $headers[] = 'client-token: ' . $ctok;

    $ch = curl_init(zapiBaseUrl() . "/instances/{$inst}/token/{$tok}/send-audio");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => json_encode(['phone' => $phone, 'audio' => $audioDataUriOuUrl]),
        CURLOPT_TIMEOUT => 30,
    ]);
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code === 200;
}

/**
 * Envia documento (PDF, Word, planilha etc) via Z-API (POST
 * /send-document/{extensao}, campos `document` [URL pública ou data URI
 * base64] + `fileName` — confirmado via busca na documentação oficial
 * Z-API, docs.z-api.io/message/send-message-document, mas o domínio da
 * doc está bloqueado neste sandbox pra confirmar campo a campo; formato
 * batido em 2 fontes: a doc oficial (via WebSearch) e um espelho
 * PlugZapi, mesma ressalva de "a validar em produção" de todo endpoint
 * Z-API que não seja envio de texto). Diferente de imagem/vídeo/áudio, o
 * endpoint carrega a EXTENSÃO na própria URL, não só no corpo. Usado pelo
 * WhatsApp Box do consultor (18/09/2026, "adicionei opção de enviar
 * anexo para clientes no ibox do consultor") pra mandar anexo que não é
 * imagem — imagem continua indo por `zapiEnviarImagem()` (já aceita
 * base64, mesmo caminho usado pra mandar foto do catálogo de revenda).
 */
function zapiEnviarDocumento(string $phone, string $documentoDataUriOuUrl, string $fileName, string $extensao, ?array $instanciaOverride = null): bool {
    [$inst, $tok, $ctok] = $instanciaOverride ?? [
        _chatbot_getConfig('zapi_instance_id'),
        _chatbot_getConfig('zapi_token'),
        _chatbot_getConfig('zapi_client_token'),
    ];
    if (!$inst || !$tok || !$phone || !$documentoDataUriOuUrl || !$extensao) return false;

    $phone = normalizarTelefone($phone);
    if (strlen($phone) < 12) return false;

    $headers = ['Content-Type: application/json'];
    if ($ctok) $headers[] = 'client-token: ' . $ctok;

    $ch = curl_init(zapiBaseUrl() . "/instances/{$inst}/token/{$tok}/send-document/{$extensao}");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => json_encode(['phone' => $phone, 'document' => $documentoDataUriOuUrl, 'fileName' => $fileName]),
        CURLOPT_TIMEOUT => 30,
    ]);
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code === 200;
}

/**
 * Busca nome/foto de perfil do WhatsApp pra um telefone — 2 chamadas em
 * paralelo (curl_multi), confirmadas contra produção no repo irmão
 * JurídicoSaaS (`nenmp4/iabadvocaciaboutique`, `api/clientes.php` ação
 * `foto_wpp` — lido direto de lá em 16/09/2026, "vai no inbox do iab tem
 * jeito certo lá", depois de uma 1ª tentativa aqui com endpoint/campos
 * nunca confirmados):
 *   1. GET /profile-picture?phone={phone} — foto em si. Resposta variando
 *      entre array `[{"link":"..."}]` e objeto `{"link":...}`, por isso
 *      checa os dois formatos (`value`/`url` como fallback adicional).
 *   2. GET /contacts/{phone} — metadados do contato, `{"notify":"Nome",
 *      "short":"N","imgUrl":"..."}` — `notify` é o nome de exibição do
 *      WhatsApp; `imgUrl` serve de FALLBACK pra foto só se o endpoint 1
 *      não trouxe nada (mesma prioridade do código de referência).
 * 16/09/2026, pedido direto ("puxa foto do zap e nome"): antes disso o
 * CRM só tinha o `senderName` que vem solto no payload do webhook da 1ª
 * mensagem (às vezes vazio, às vezes só um apelido esquisito tipo "." ou
 * "$"), nunca a foto de perfil de verdade.
 *
 * Ainda não confirmado contra uma instância REAL da Fastcar (só copiado
 * do formato já validado em produção no projeto irmão) — se algum campo
 * vier diferente, loga o corpo cru em storage/logs/whatsapp_contato_debug.log
 * (mesmo padrão de logDiagnosticoMidiaZapi()) em vez de falhar em
 * silêncio. Nunca lança — busca de nome/foto é sempre melhor esforço,
 * nunca pode travar a criação do lead.
 */
function zapiBuscarContato(string $phone): ?array {
    $inst = _chatbot_getConfig('zapi_instance_id');
    $tok  = _chatbot_getConfig('zapi_token');
    $ctok = _chatbot_getConfig('zapi_client_token');
    if (!$inst || !$tok || !$phone) return null;

    $phoneNorm = normalizarTelefone($phone);
    if (strlen($phoneNorm) < 12) return null;

    try {
        $headers = ['Content-Type: application/json'];
        if ($ctok) $headers[] = 'client-token: ' . $ctok;
        $base = zapiBaseUrl() . "/instances/{$inst}/token/{$tok}";

        $chFoto = curl_init("{$base}/profile-picture?phone={$phoneNorm}");
        curl_setopt_array($chFoto, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 10]);
        $chContato = curl_init("{$base}/contacts/{$phoneNorm}");
        curl_setopt_array($chContato, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 10]);

        $mh = curl_multi_init();
        curl_multi_add_handle($mh, $chFoto);
        curl_multi_add_handle($mh, $chContato);
        do {
            $status = curl_multi_exec($mh, $ativo);
            if ($ativo) curl_multi_select($mh);
        } while ($ativo && $status === CURLM_OK);

        $respFoto = curl_multi_getcontent($chFoto);
        $codeFoto = curl_getinfo($chFoto, CURLINFO_HTTP_CODE);
        $respContato = curl_multi_getcontent($chContato);
        $codeContato = curl_getinfo($chContato, CURLINFO_HTTP_CODE);
        curl_multi_remove_handle($mh, $chFoto);
        curl_multi_remove_handle($mh, $chContato);
        curl_multi_close($mh);

        $foto = '';
        $nome = '';
        $brutoParaDiagnostico = [];

        if ($codeFoto === 200 && $respFoto) {
            $j = json_decode($respFoto, true);
            $brutoParaDiagnostico['profile-picture'] = $j;
            if (is_array($j)) {
                $candidatoFoto = $j[0]['link'] ?? $j['link'] ?? $j['value'] ?? $j['url'] ?? '';
                if (_zapiUrlFotoValida($candidatoFoto)) $foto = $candidatoFoto;
            }
        }
        if ($codeContato === 200 && $respContato) {
            $j2 = json_decode($respContato, true);
            $brutoParaDiagnostico['contacts'] = $j2;
            if (is_array($j2)) {
                // 16/09/2026, achado real em produção: 2 clientes apareceram
                // na caixa com "nome" = "online"/"disponível" — texto de
                // status/presença do WhatsApp, não nome de verdade. Pula
                // campo com esse tipo de valor e tenta o próximo da lista,
                // em vez de aceitar o 1º que vier não-vazio.
                foreach (['notify', 'pushName', 'name', 'short'] as $campo) {
                    if (!empty($j2[$campo]) && is_string($j2[$campo]) && nomeWhatsappPareceValido($j2[$campo])) {
                        $nome = trim($j2[$campo]);
                        break;
                    }
                }
                if (!$foto) {
                    $candidatoFoto = $j2['imgUrl'] ?? $j2['profilePictureUrl'] ?? '';
                    if (_zapiUrlFotoValida($candidatoFoto)) $foto = $candidatoFoto;
                }
            }
        }

        if (!$nome && !$foto) {
            _zapiLogDiagnosticoContato($phoneNorm, $brutoParaDiagnostico);
            return null;
        }

        return ['nome' => $nome, 'foto_url' => (string)$foto];
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Nomes de contato do WhatsApp que na verdade são texto de status/presença/
 * About padrão, não o nome real da pessoa — achado real em produção,
 * 16/09/2026 ("puxa foto do zap e nome" tinha ficado com 2 clientes reais
 * mostrando "online"/"disponível" na caixa). Comparação EXATA (não
 * substring), pra nunca recusar um nome de verdade que só CONTENHA uma
 * dessas palavras (ex: "Ana Online" continua válido).
 */
function nomeWhatsappPareceValido(string $nome): bool {
    $normalizado = mb_strtolower(trim($nome));
    if ($normalizado === '') return false;
    static $invalidos = [
        'online', 'offline', 'disponivel', 'disponível', 'indisponivel', 'indisponível',
        'ocupado', 'ocupada', 'ausente', 'away', 'busy', 'at work', 'no trabalho',
        'em uma ligacao', 'em uma ligação', 'em uma chamada', 'bateria fraca',
        'battery about to die', 'disponible',
        'hey there i am using whatsapp', 'hey there! i am using whatsapp.',
        'ola estou usando o whatsapp', 'olá estou usando o whatsapp',
        'ola, estou usando o whatsapp', 'olá, estou usando o whatsapp.',
    ];
    return !in_array($normalizado, $invalidos, true);
}

/** Só aceita foto de perfil com URL http(s) plausível — nunca deixa passar
 *  string vazia/garbage direto pro `<img src>` do WhatsApp Box. */
function _zapiUrlFotoValida($url): bool {
    if (!is_string($url) || $url === '') return false;
    return str_starts_with($url, 'http://') || str_starts_with($url, 'https://');
}

/**
 * Sorteia 1 texto entre variações pré-escritas pro MESMO recado — 21/09/2026,
 * achado real: `cron/recuperacao_leads.php` mandou o mesmo texto fixo pra
 * ~48 clientes numa tarde só, e a instância principal apareceu desconectada
 * logo depois; mensagem idêntica em volume é justamente o padrão que mais
 * costuma acionar antispam do WhatsApp — ainda mais num número que já foi
 * bloqueado antes (ver incidente de flood, CLAUDE.md). Sorteio simples
 * (nunca via IA aqui — não é dado que precisa ser preciso/confiável, é só
 * forma de escrever, gerar via IA numa rotina de cron adicionaria custo/
 * latência/risco de erro sem necessidade real) — cada call site mantém sua
 * própria lista de variações, só o sorteio é compartilhado.
 */
function variarMensagem(array $variantes): string {
    if (!$variantes) return '';
    return $variantes[array_rand($variantes)];
}

/** Mesmo padrão de logDiagnosticoMidiaZapi() — grava o corpo cru quando
 *  nenhum nome de campo esperado bate, pra achar o formato real depois. */
function _zapiLogDiagnosticoContato(string $phone, $detalhe): void {
    try {
        $dir = dirname(__DIR__) . '/storage/logs';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $linha = '[' . date('Y-m-d H:i:s') . "] telefone={$phone} motivo=campo_nao_encontrado detalhe="
            . json_encode($detalhe, JSON_UNESCAPED_UNICODE) . "\n";
        file_put_contents($dir . '/whatsapp_contato_debug.log', $linha, FILE_APPEND);
    } catch (Throwable $e) {
        // diagnóstico nunca pode quebrar o fluxo principal
    }
}
