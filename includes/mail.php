<?php
/**
 * includes/mail.php — envio de e-mail. Mesmo padrão do JurídicoSaaS: apesar
 * do nome popular ser "SMTP", o envio de verdade é via API HTTP da Brevo
 * (`brevo_api_key`), não protocolo SMTP puro — motivo é o mesmo de lá:
 * porta 25/465/587 costuma vir bloqueada em VPS nova (proteção antispam
 * automática de provedor de nuvem) e, mesmo quando não vem, mandar direto
 * pelo IP da VPS sem reputação/SPF/DKIM/DMARC configurado cai em spam quase
 * sempre — a Brevo já resolve entrega, autenticação e reputação por fora.
 *
 * Campos de config: brevo_api_key, email_from, email_from_nome.
 */

require_once __DIR__ . '/db.php';

/** Base URL override via define() só em teste (fake server local). */
function mailBaseUrl(): string {
    return defined('MAIL_BASE_URL') ? MAIL_BASE_URL : 'https://api.brevo.com/v3';
}

/**
 * Envia um e-mail HTML via Brevo.
 * @return true|array true em sucesso, ['erro' => 'mensagem'] em falha —
 *   mesmo padrão string|array dos outros helpers (gemini/openai), nunca
 *   lança exceção: e-mail é sempre "melhor esforço", nunca pode derrubar o
 *   fluxo principal (mesmo motivo de geminiRegistrarTokens() ser blindado).
 */
function enviarEmail(string $para, string $assunto, string $corpoHtml, string $paraNome = ''): true|array {
    $apiKey = getConfig('brevo_api_key') ?: '';
    if (!$apiKey) return ['erro' => 'Chave Brevo não configurada. Vá em Configurações → E-mail.'];

    $from     = getConfig('email_from') ?: '';
    $fromNome = getConfig('email_from_nome') ?: 'Fastcar';
    if (!$from) return ['erro' => 'E-mail remetente não configurado. Vá em Configurações → E-mail.'];

    $destinatario = ['email' => $para];
    if ($paraNome) $destinatario['name'] = $paraNome;

    $payload = json_encode([
        'sender'      => ['name' => $fromNome, 'email' => $from],
        'to'          => [$destinatario],
        'subject'     => $assunto,
        'htmlContent' => $corpoHtml,
    ]);

    $ch = curl_init(mailBaseUrl() . '/smtp/email');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => [
            'accept: application/json',
            'api-key: ' . $apiKey,
            'content-type: application/json',
        ],
    ]);
    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) return ['erro' => "cURL: {$err}"];
    if ($http >= 200 && $http < 300) return true;

    $data = json_decode($resp ?: '{}', true);
    return ['erro' => $data['message'] ?? "HTTP {$http}"];
}
