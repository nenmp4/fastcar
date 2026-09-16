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
 * Envia mensagem de texto via Z-API. Mesma assinatura/lógica do
 * aaspNotificarWpp() do JurídicoSaaS (includes/aasp.php), renomeada pro
 * contexto deste projeto.
 */
function zapiEnviarTexto(string $phone, string $msg): bool {
    $inst = _chatbot_getConfig('zapi_instance_id');
    $tok  = _chatbot_getConfig('zapi_token');
    $ctok = _chatbot_getConfig('zapi_client_token');
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
 * qualificação e no rodapé com endereço real do wizard).
 */
function zapiEnviarImagem(string $phone, string $imagemUrl, string $legenda): bool {
    $inst = _chatbot_getConfig('zapi_instance_id');
    $tok  = _chatbot_getConfig('zapi_token');
    $ctok = _chatbot_getConfig('zapi_client_token');
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
                $foto = $j[0]['link'] ?? $j['link'] ?? $j['value'] ?? $j['url'] ?? '';
            }
        }
        if ($codeContato === 200 && $respContato) {
            $j2 = json_decode($respContato, true);
            $brutoParaDiagnostico['contacts'] = $j2;
            if (is_array($j2)) {
                foreach (['notify', 'pushName', 'name', 'short'] as $campo) {
                    if (!empty($j2[$campo]) && is_string($j2[$campo])) {
                        $nome = trim($j2[$campo]);
                        break;
                    }
                }
                if (!$foto) $foto = $j2['imgUrl'] ?? $j2['profilePictureUrl'] ?? '';
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
