<?php
/**
 * includes/openai.php — chamada à API OpenAI (Chat Completions), usada
 * como FALLBACK do Gemini na qualificação por IA — mesmo padrão do
 * JurídicoSaaS (chatbot-whatsapp/includes/whatsapp_bot.php::classificarAreaComGemini()):
 * tenta Gemini primeiro, GPT só entra em cena se o Gemini falhar/não responder.
 */

/** Base URL override via define() só em teste (fake server local). */
function openaiBaseUrl(): string {
    return defined('OPENAI_BASE_URL') ? OPENAI_BASE_URL : 'https://api.openai.com/v1';
}

/** Prompt único, sem histórico — equivalente a geminiCall(). */
function openaiCall(
    string $prompt, string $key, string $model = 'gpt-4o-mini',
    int $maxTokens = 512, float $temp = 0.4, int $timeout = 25
): string|array {
    if (!$key) return ['erro' => 'Chave OpenAI não configurada.'];
    return openaiChatCompletion([['role' => 'user', 'content' => $prompt]], $key, $model, $maxTokens, $temp, $timeout);
}

/**
 * Com system prompt + histórico — equivalente a geminiCallChat(). Recebe o
 * histórico no MESMO formato que geminiCallChat() usa (role user/model +
 * parts[0].text), pra quem chama não precisar montar dois formatos.
 */
function openaiCallChat(
    string $systemPrompt, array $historicoGemini, string $key, string $model = 'gpt-4o-mini',
    int $maxTokens = 500, float $temp = 0.7, int $timeout = 20
): string {
    if (!$key) return '';
    $mensagens = [['role' => 'system', 'content' => $systemPrompt]];
    foreach ($historicoGemini as $m) {
        $mensagens[] = ['role' => $m['role'] === 'model' ? 'assistant' : 'user', 'content' => $m['parts'][0]['text'] ?? ''];
    }
    $resposta = openaiChatCompletion($mensagens, $key, $model, $maxTokens, $temp, $timeout);
    return is_array($resposta) ? '' : $resposta;
}

function openaiChatCompletion(array $mensagens, string $key, string $model, int $maxTokens, float $temp, int $timeout): string|array {
    $ch = curl_init(openaiBaseUrl() . '/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $key],
        CURLOPT_POSTFIELDS     => json_encode([
            'model' => $model, 'max_tokens' => $maxTokens, 'temperature' => $temp, 'messages' => $mensagens,
        ]),
    ]);
    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) return ['erro' => "cURL: {$err}"];
    $data = json_decode($resp ?: '{}', true);
    $texto = trim($data['choices'][0]['message']['content'] ?? '');
    if ($http === 200 && $texto !== '') return $texto;
    return ['erro' => $data['error']['message'] ?? "HTTP {$http}"];
}
