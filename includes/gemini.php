<?php
/**
 * includes/gemini.php — Helper centralizado pra API Google Gemini.
 * Mesmo padrão (e correções) do JurídicoSaaS (includes/gemini.php):
 *  - gemini-2.5-flash/-lite usa "thinking mode" por padrão — thinkingBudget=0
 *    desativa isso, senão a resposta real vem vazia (parte "thought" antes).
 *  - Fallback de modelos: se um falhar, tenta o próximo antes de desistir.
 *  - Modelos aposentados pelo Google são remapeados pro atual.
 *
 * Modelo padrão: gemini-2.5-flash-lite (mais barato da família 2.5) — volume
 * real de leads é baixo (média 16-30/dia, pico ~50/dia, ver CLAUDE.md), então
 * o custo por chamada nem seria um problema em nenhum dos dois, mas o Jean
 * pediu pra já sair no modelo mais barato por padrão. gemini-2.5-flash
 * (mais caro, mais capaz) entra só como fallback automático se o lite falhar.
 */

require_once __DIR__ . '/db.php';

/** Base URL da API Gemini — override via define() só em teste (fake server local). */
function geminiBaseUrl(): string {
    return defined('GEMINI_BASE_URL') ? GEMINI_BASE_URL : 'https://generativelanguage.googleapis.com/v1beta';
}

/** Remapeia modelos aposentados pelo Google pro substituto atual (o mais barato). */
function geminiModeloValido(string $model): string {
    $aposentados = ['gemini-1.5-flash', 'gemini-1.5-pro', 'gemini-2.0-flash', 'gemini-2.0-flash-lite', 'auto', ''];
    return in_array($model, $aposentados, true) ? 'gemini-2.5-flash-lite' : $model;
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
    string $model     = 'gemini-2.5-flash-lite',
    int    $maxTokens = 512,
    float  $temp      = 0.4,
    int    $timeout   = 25
): string|array {
    if (!$key) return ['erro' => 'Chave Gemini não configurada. Vá em Configurações → IA.'];

    // Ordem: modelo configurado (barato por padrão) primeiro; só escala pro
    // gemini-2.5-flash (mais caro) se o lite falhar de verdade.
    $modelos = array_unique([geminiModeloValido($model), 'gemini-2.5-flash-lite', 'gemini-2.5-flash']);
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
    string $model     = 'gemini-2.5-flash-lite',
    int    $maxTokens = 500,
    float  $temp      = 0.7,
    int    $timeout   = 20
): string {
    if (!$key) return '';

    $modelos = array_unique([geminiModeloValido($model), 'gemini-2.5-flash-lite', 'gemini-2.5-flash']);

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
