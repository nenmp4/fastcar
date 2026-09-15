<?php
/**
 * includes/mail.php — envio de e-mail via API do Gmail (Google Workspace).
 *
 * 15/09/2026 — trocado de Brevo pra Gmail API (decisão do José/Jean: "vamos
 * trocar brevo pelo api do google workspace"). Reaproveita a MESMA
 * credencial de service account já usada pro Google Drive
 * (config/google_drive_credentials.json, includes/google_drive.php) —
 * mesmo `client_email`/`private_key`, só muda o escopo do JWT (`gmail.send`
 * em vez de `drive`) e ganha um claim `sub` (a caixa do Workspace que a
 * service account passa a impersonar — o `email_from` já configurado).
 *
 * ⚠️ PRÉ-REQUISITO MANUAL, não dá pra automatizar por código: **delegação
 * em todo o domínio** autorizada no Google Workspace Admin Console
 * (admin.google.com → Segurança → Controles de API → Delegação em todo o
 * domínio) pro Client ID numérico dessa service account (não o
 * client_email — é outro campo do mesmo JSON), com o escopo
 * `https://www.googleapis.com/auth/gmail.send` adicionado à lista (junto
 * do `https://www.googleapis.com/auth/drive` que o Drive já usa). Sem
 * isso o Google rejeita o JWT com `unauthorized_client`, mesmo com
 * credencial válida — a assinatura RS256 é genuína, mas a service account
 * não tem permissão de agir como a caixa `email_from` até o admin do
 * Workspace autorizar explicitamente.
 */

require_once __DIR__ . '/db.php';

/** Caminho da credencial — mesma do Drive, override via define() só em teste. */
function mailCredentialsPath(): string {
    return defined('MAIL_CREDENTIALS_PATH') ? MAIL_CREDENTIALS_PATH : dirname(__DIR__) . '/config/google_drive_credentials.json';
}

/** URLs override via define() só em teste (fake server local). */
function mailOauthUrl(): string { return defined('MAIL_OAUTH_URL') ? MAIL_OAUTH_URL : 'https://oauth2.googleapis.com/token'; }
function mailApiUrl(): string   { return defined('MAIL_API_URL')   ? MAIL_API_URL   : 'https://gmail.googleapis.com/gmail/v1'; }

function mailB64u(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/**
 * Autentica como a caixa $impersonar via domain-wide delegation — devolve
 * o access_token ou false. Diferente da autenticação do Drive
 * (GoogleDrive::authenticate(), que age como a própria service account,
 * sem impersonar ninguém): Gmail API exige agir COMO uma caixa real do
 * Workspace pra poder mandar e-mail "de" ela, daí o claim `sub`.
 */
function mailAutenticar(string $impersonar): string|bool {
    $path = mailCredentialsPath();
    if (!file_exists($path)) return false;
    $creds = json_decode(file_get_contents($path), true);
    if (!isset($creds['client_email'], $creds['private_key'])) return false;

    $now = time();
    $header  = mailB64u(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $payload = mailB64u(json_encode([
        'iss'   => $creds['client_email'],
        'sub'   => $impersonar,
        'scope' => 'https://www.googleapis.com/auth/gmail.send',
        'aud'   => mailOauthUrl(),
        'exp'   => $now + 3600,
        'iat'   => $now,
    ]));
    $key = openssl_pkey_get_private($creds['private_key']);
    if (!$key) return false;
    $sig = '';
    openssl_sign("{$header}.{$payload}", $sig, $key, 'SHA256');
    $jwt = "{$header}.{$payload}." . mailB64u($sig);

    $ch = curl_init(mailOauthUrl());
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $jwt,
        ]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_TIMEOUT    => 15,
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($resp ?: '{}', true);
    return $data['access_token'] ?? false;
}

/**
 * Envia um e-mail HTML via Gmail API, autenticado como a caixa configurada
 * em `config.email_from`.
 * @return bool|array true em sucesso, ['erro' => 'mensagem'] em falha —
 *   mesmo padrão string|array dos outros helpers (gemini/openai), nunca
 *   lança exceção: e-mail é sempre "melhor esforço", nunca pode derrubar o
 *   fluxo principal. `bool|array` em vez do tipo standalone `true` de
 *   propósito — só existe a partir do PHP 8.2, e a VPS de produção roda
 *   8.1 (ver guard version-php-82-plus no tests/smoke.php).
 */
function enviarEmail(string $para, string $assunto, string $corpoHtml, string $paraNome = ''): bool|array {
    $from     = getConfig('email_from') ?: '';
    $fromNome = getConfig('email_from_nome') ?: 'Fastcar';
    if (!$from) return ['erro' => 'E-mail remetente não configurado. Vá em Configurações → E-mail.'];

    if (!file_exists(mailCredentialsPath())) {
        return ['erro' => 'Credencial do Google (service account) não configurada. Vá em Configurações → E-mail.'];
    }

    $token = mailAutenticar($from);
    if (!$token) {
        return ['erro' => "Falha ao autenticar com o Gmail como {$from} — confira se a delegação em todo o domínio (Workspace Admin) está autorizada com o escopo gmail.send pra essa service account."];
    }

    $destinatario = $paraNome ? "{$paraNome} <{$para}>" : $para;
    $remetente    = "{$fromNome} <{$from}>";
    $assuntoMime  = '=?UTF-8?B?' . base64_encode($assunto) . '?=';

    $mime = "From: {$remetente}\r\n"
          . "To: {$destinatario}\r\n"
          . "Subject: {$assuntoMime}\r\n"
          . "MIME-Version: 1.0\r\n"
          . "Content-Type: text/html; charset=UTF-8\r\n\r\n"
          . $corpoHtml;

    $ch = curl_init(mailApiUrl() . '/users/me/messages/send');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['raw' => mailB64u($mime)]),
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 15,
    ]);
    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) return ['erro' => "cURL: {$err}"];
    if ($http >= 200 && $http < 300) return true;

    $data = json_decode($resp ?: '{}', true);
    return ['erro' => $data['error']['message'] ?? "HTTP {$http}"];
}
