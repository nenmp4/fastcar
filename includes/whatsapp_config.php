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
function zapiEnviarTexto(string $phone, string $msg, ?array $instanciaOverride = null): bool {
    [$inst, $tok, $ctok] = $instanciaOverride ?? [
        _chatbot_getConfig('zapi_instance_id'),
        _chatbot_getConfig('zapi_token'),
        _chatbot_getConfig('zapi_client_token'),
    ];
    if (!$inst || !$tok || !$phone) return false;

    $phone = normalizarTelefone($phone);
    if (strlen($phone) < 12) return false;

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
