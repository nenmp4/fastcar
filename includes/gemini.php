<?php
/**
 * includes/gemini.php — Helper centralizado pra API Google Gemini.
 * Mesmo padrão (e correções) do JurídicoSaaS (includes/gemini.php):
 *  - modelos 2.5+/3.x usam "thinking mode" por padrão — thinkingBudget=0
 *    desativa isso, senão a resposta real vem vazia (parte "thought" antes).
 *  - Fallback de modelos: se um falhar, tenta o próximo antes de desistir.
 *  - Modelos aposentados pelo Google são remapeados pro atual.
 *
 * Modelo padrão: gemini-3.5-flash-lite (mais barato disponível pra chave nova
 * — ver 15/09/2026 abaixo) — volume real de leads é baixo (média 16-30/dia,
 * pico ~50/dia, ver CLAUDE.md), então o custo por chamada nem seria um
 * problema em nenhum dos dois, mas o Jean pediu pra já sair no modelo mais
 * barato por padrão. gemini-3.6-flash (mais caro, mais capaz) entra só como
 * fallback automático se o lite falhar.
 *
 * **15/09/2026 — gemini-2.5-flash/-lite aposentados pra chave nova:**
 * primeiro teste real (Configurações → IA → testar conexão) voltou
 * "models/gemini-2.5-flash is no longer available to new users. [...] use
 * models/gemini-3.6-flash" — a família 2.5 inteira saiu de circulação pra
 * projetos novos (a chave da Fastcar é nova). Trocado o padrão pra
 * gemini-3.5-flash-lite (mais barato da geração 3.x — não existe
 * "gemini-3.6-flash-lite", só o gemini-3.6-flash "cheio" nessa geração) e os
 * 2.5 entraram na lista de `geminiModeloValido()` pra remapear sozinho
 * qualquer `config.gemini_model` salvo antigo, sem precisar mexer no banco
 * na mão.
 */

require_once __DIR__ . '/db.php';

/** Base URL da API Gemini — override via define() só em teste (fake server local). */
function geminiBaseUrl(): string {
    return defined('GEMINI_BASE_URL') ? GEMINI_BASE_URL : 'https://generativelanguage.googleapis.com/v1beta';
}

/** Remapeia modelos aposentados pelo Google pro substituto atual (o mais barato). */
function geminiModeloValido(string $model): string {
    $aposentados = [
        'gemini-1.5-flash', 'gemini-1.5-pro', 'gemini-2.0-flash', 'gemini-2.0-flash-lite',
        'gemini-2.5-flash', 'gemini-2.5-flash-lite', // aposentados pra chave nova em 15/09/2026
        'auto', '',
    ];
    return in_array($model, $aposentados, true) ? 'gemini-3.5-flash-lite' : $model;
}

/** Extrai o texto real da resposta Gemini, pulando partes de "thinking". */
function geminiExtrairTexto(array $data): string {
    foreach ($data['candidates'][0]['content']['parts'] ?? [] as $parte) {
        if (empty($parte['thought']) && isset($parte['text'])) {
            return trim($parte['text']);
        }
    }
    return '';
}

/**
 * Chama a API Gemini (prompt único, sem histórico) com fallback de modelos.
 * @return string|array Texto da resposta ou ['erro' => 'mensagem']
 */
function geminiCall(
    string $prompt,
    string $key,
    string $model     = 'gemini-3.5-flash-lite',
    int    $maxTokens = 512,
    float  $temp      = 0.4,
    int    $timeout   = 25
): string|array {
    if (!$key) return ['erro' => 'Chave Gemini não configurada. Vá em Configurações → IA.'];

    // Ordem: modelo configurado (barato por padrão) primeiro; só escala pro
    // gemini-3.6-flash (mais caro) se o lite falhar de verdade.
    $modelos = array_unique([geminiModeloValido($model), 'gemini-3.5-flash-lite', 'gemini-3.6-flash']);
    $ultimoErro = '';

    foreach ($modelos as $m) {
        $url = geminiBaseUrl() . '/models/' . $m . ':generateContent?key=' . $key;
        $genCfg = ['temperature' => $temp, 'maxOutputTokens' => $maxTokens];
        if (preg_match('/-(2\.5|[3-9])/', $m)) {
            $genCfg['thinkingConfig'] = ['thinkingBudget' => 0];
        }
        $body = json_encode([
            'contents'         => [['parts' => [['text' => $prompt]]]],
            'generationConfig' => $genCfg,
        ]);

        [$http, $data, $err] = geminiPost($url, $body, $timeout);
        if ($err) { $ultimoErro = "cURL: {$err}"; continue; }

        if ($http === 200) {
            $text = geminiExtrairTexto($data);
            if ($text !== '') {
                geminiRegistrarTokens($data['usageMetadata'] ?? []);
                return $text;
            }
        }
        $ultimoErro = "{$m}: " . ($data['error']['message']
            ?? (isset($data['candidates'][0]['finishReason']) ? "finishReason: {$data['candidates'][0]['finishReason']}" : "HTTP {$http}"));
    }
    return ['erro' => 'Gemini falhou — ' . ($ultimoErro ?: 'sem resposta') . '.'];
}

/** Versão com system_instruction + histórico de mensagens (chatbot). */
function geminiCallChat(
    string $systemPrompt,
    array  $mensagens,
    string $key,
    string $model     = 'gemini-3.5-flash-lite',
    int    $maxTokens = 500,
    float  $temp      = 0.7,
    int    $timeout   = 20
): string {
    if (!$key) return '';

    $modelos = array_unique([geminiModeloValido($model), 'gemini-3.5-flash-lite', 'gemini-3.6-flash']);

    foreach ($modelos as $m) {
        $url = geminiBaseUrl() . '/models/' . $m . ':generateContent?key=' . $key;
        $genCfg = ['temperature' => $temp, 'maxOutputTokens' => $maxTokens];
        if (preg_match('/-(2\.5|[3-9])/', $m)) {
            $genCfg['thinkingConfig'] = ['thinkingBudget' => 0];
        }
        $body = json_encode([
            'system_instruction' => ['parts' => [['text' => $systemPrompt]]],
            'contents'           => $mensagens,
            'generationConfig'   => $genCfg,
        ]);

        [$http, $data, $err] = geminiPost($url, $body, $timeout);
        if ($err) continue;

        if ($http === 200) {
            $text = geminiExtrairTexto($data);
            if ($text !== '') {
                geminiRegistrarTokens($data['usageMetadata'] ?? []);
                return $text;
            }
        }
    }
    return '';
}

/**
 * Chama Gemini com ÁUDIO ou IMAGEM inline (base64) + um prompt de texto —
 * usado pra transcrever áudio de voz e descrever foto do veículo recebidos
 * no WhatsApp (chatbot-whatsapp/includes/mensagens.php). Mesmo padrão de
 * fallback de modelo do geminiCall(); sem chave ou qualquer falha, retorna
 * '' — quem chama trata como "não deu pra processar essa mídia agora",
 * nunca derruba o webhook.
 */
function geminiCallComMidia(
    string $prompt, string $mimeType, string $dadosBase64,
    string $key, string $model = 'gemini-3.5-flash-lite',
    int $maxTokens = 300, float $temp = 0.2, int $timeout = 30
): string {
    if (!$key || !$dadosBase64) return '';

    $modelos = array_unique([geminiModeloValido($model), 'gemini-3.5-flash-lite', 'gemini-3.6-flash']);

    foreach ($modelos as $m) {
        $url = geminiBaseUrl() . '/models/' . $m . ':generateContent?key=' . $key;
        $genCfg = ['temperature' => $temp, 'maxOutputTokens' => $maxTokens];
        if (preg_match('/-(2\.5|[3-9])/', $m)) {
            $genCfg['thinkingConfig'] = ['thinkingBudget' => 0];
        }
        $body = json_encode([
            'contents' => [['parts' => [
                ['inlineData' => ['mimeType' => $mimeType, 'data' => $dadosBase64]],
                ['text' => $prompt],
            ]]],
            'generationConfig' => $genCfg,
        ]);

        [$http, $data, $err] = geminiPost($url, $body, $timeout);
        if ($err) continue;

        if ($http === 200) {
            $text = geminiExtrairTexto($data);
            if ($text !== '') {
                geminiRegistrarTokens($data['usageMetadata'] ?? []);
                return $text;
            }
        }
    }
    return '';
}

/** POST cru pra API Gemini — devolve [http_code, data_decodificado, erro_curl]. */
function geminiPost(string $url, string $body, int $timeout): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    ]);
    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    return [$http, json_decode($resp ?: '{}', true) ?: [], $err];
}

/**
 * Acumula contagem de tokens em `config` pra monitoramento de custo —
 * chave gemini_tokens_YYYY-MM-DD, valor JSON {"in":N,"out":N,"calls":N}.
 */
function geminiRegistrarTokens(array $usage): void {
    if (empty($usage)) return;
    try {
        $chave = 'gemini_tokens_' . date('Y-m-d');
        $atual = json_decode(getConfig($chave) ?? '{}', true) ?: [];
        $atual['in']    = ($atual['in']    ?? 0) + (int)($usage['promptTokenCount']     ?? 0);
        $atual['out']   = ($atual['out']   ?? 0) + (int)($usage['candidatesTokenCount'] ?? 0);
        $atual['calls'] = ($atual['calls'] ?? 0) + 1;
        setConfig($chave, json_encode($atual));
    } catch (Throwable $e) {
        // monitoramento de custo nunca pode derrubar a resposta ao cliente
    }
}

/**
 * Preço por 1M de tokens do modelo PADRÃO (gemini-3.5-flash-lite),
 * confirmado 16/09/2026 (pedido "coloca valor estimado de gasto em reais
 * lá no saúde api"): US$0,30/1M entrada, US$2,50/1M saída — tabela oficial
 * do Google AI. `geminiRegistrarTokens()` acumula in/out num total só, sem
 * dizer qual modelo serviu cada chamada — a estimativa assume que
 * praticamente tudo usa o lite (fallback pro gemini-3.6-flash, mais caro,
 * só quando o lite falha de verdade), então o custo real fica um pouco
 * ACIMA do estimado nos dias em que o fallback entra em ação.
 */
const GEMINI_PRECO_USD_MILHAO_INPUT = 0.30;
const GEMINI_PRECO_USD_MILHAO_OUTPUT = 2.50;

/**
 * Cotação USD→BRL pra converter o custo estimado da tela de Saúde —
 * busca ao vivo (AwesomeAPI, gratuita, sem chave), cache de 6h em `config`
 * (mesmo padrão "timestamp|json" já usado pra outras consultas com custo/
 * limite de requisição neste projeto, ex: PlacaFIPE) — nunca trava a tela
 * se a busca falhar, cai num valor padrão conservador.
 */
function cotacaoUsdBrl(): float {
    $chave = 'cotacao_usd_brl';
    $cache = getConfig($chave) ?: '';
    if (strpos($cache, '|') !== false) {
        [$ts, $valor] = explode('|', $cache, 2);
        if ((time() - (int)$ts) < 6 * 3600 && (float)$valor > 0) {
            return (float)$valor;
        }
    }
    try {
        $base = defined('COTACAO_BASE_URL') ? COTACAO_BASE_URL : 'https://economia.awesomeapi.com.br';
        $ch = curl_init($base . '/json/last/USD-BRL');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
        $resp = curl_exec($ch);
        curl_close($ch);
        $bid = (float)(json_decode($resp ?: '', true)['USDBRL']['bid'] ?? 0);
        if ($bid > 0) {
            setConfig($chave, time() . '|' . $bid);
            return $bid;
        }
    } catch (Throwable $e) {
        // busca de cotação é sempre melhor esforço
    }
    return 5.30; // fallback se a API falhar (ou 1ª vez, sem cache ainda)
}

/** Custo estimado em reais pra um volume de tokens de entrada/saída. */
function geminiCustoEstimadoBrl(int $tokensIn, int $tokensOut): float {
    $usd = ($tokensIn / 1_000_000 * GEMINI_PRECO_USD_MILHAO_INPUT)
         + ($tokensOut / 1_000_000 * GEMINI_PRECO_USD_MILHAO_OUTPUT);
    return $usd * cotacaoUsdBrl();
}
