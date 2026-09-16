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
 * Busca nome/foto de perfil do WhatsApp pra um telefone (GET
 * /instances/{id}/token/{token}/contacts/{phone} — endpoint documentado da
 * Z-API pra metadados de contato). 16/09/2026, pedido direto ("puxa foto
 * do zap e nome"): antes disso o CRM só tinha o `senderName` que vem
 * solto no payload do webhook da 1ª mensagem (às vezes vazio, às vezes só
 * um apelido esquisito tipo "." ou "$"), nunca a foto de perfil.
 *
 * ⚠️ Formato de resposta NUNCA confirmado contra uma instância real (mesma
 * ressalva de todo endpoint Z-API deste projeto que não seja envio de
 * mensagem — ver CLAUDE.md "a validar em produção"). Tenta os nomes de
 * campo mais prováveis pro nome (`name`, `short`, `vname`, `notify`) e pra
 * foto (`imgUrl`, `profileImage`, `photo`); se nenhum bater, loga o corpo
 * cru em storage/logs/whatsapp_contato_debug.log (mesmo padrão de
 * chatbot-whatsapp/includes/mensagens.php::logDiagnosticoMidiaZapi()) pra
 * corrigir o campo certo assim que rodar contra um contato real, em vez
 * de ficar adivinhando às cegas. Nunca lança — busca de nome/foto é
 * sempre melhor esforço, nunca pode travar a criação do lead.
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

        $ch = curl_init(zapiBaseUrl() . "/instances/{$inst}/token/{$tok}/contacts/{$phoneNorm}");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 10,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 200 || !$resp) return null;
        $dados = json_decode($resp, true);
        if (!is_array($dados)) return null;

        $nome = '';
        foreach (['name', 'short', 'vname', 'notify'] as $campo) {
            if (!empty($dados[$campo]) && is_string($dados[$campo])) {
                $nome = trim($dados[$campo]);
                break;
            }
        }
        $foto = '';
        foreach (['imgUrl', 'profileImage', 'photo', 'profilePicture'] as $campo) {
            if (!empty($dados[$campo]) && is_string($dados[$campo])) {
                $foto = $dados[$campo];
                break;
            }
        }

        if (!$nome && !$foto) {
            _zapiLogDiagnosticoContato($phoneNorm, $dados);
            return null;
        }

        return ['nome' => $nome, 'foto_url' => $foto];
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
