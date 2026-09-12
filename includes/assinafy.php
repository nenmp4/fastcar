<?php
/**
 * includes/assinafy.php — Integração Assinafy (assinatura eletrônica).
 * Mesmo padrão do JurídicoSaaS: PHP puro com cURL, sem dependências.
 */

require_once __DIR__ . '/db.php';

/** Base URL override via define() só em teste (fake server local). */
function assinafyBaseUrl(): string {
    return defined('ASSINAFY_BASE_URL') ? ASSINAFY_BASE_URL : 'https://api.assinafy.com.br/v1';
}

/**
 * Faz uma requisição à API Assinafy.
 * @return array ['code' => int, 'data' => array]
 */
function assinafyRequest(string $method, string $endpoint, array $data = [], bool $multipart = false): array {
    $apiKey    = getConfig('assinafy_api_key') ?: '';
    $accountId = getConfig('assinafy_account_id') ?: '';
    $url = assinafyBaseUrl() . str_replace('{account_id}', $accountId, $endpoint);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);

    $headers = ['X-Api-Key: ' . $apiKey];

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($multipart) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        } else {
            $json = json_encode($data);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
            $headers[] = 'Content-Type: application/json';
            $headers[] = 'Content-Length: ' . strlen($json);
        }
    } elseif ($method === 'PATCH') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
        $json = json_encode($data);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        $headers[] = 'Content-Type: application/json';
    } elseif ($method === 'DELETE') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
    }

    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $body     = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        return ['code' => 0, 'data' => ['error' => $curlErr]];
    }
    $decoded = json_decode($body ?: '{}', true);
    return ['code' => $httpCode, 'data' => $decoded ?? []];
}

/** Upload de um PDF pra Assinafy. Retorna ['document_id' => string] ou ['error' => string]. */
function assinafyUploadPdf(string $filePath, string $nome): array {
    if (!file_exists($filePath)) {
        return ['error' => 'Arquivo não encontrado: ' . $filePath];
    }

    $postFields = [
        'file' => new CURLFile($filePath, 'application/pdf', $nome . '.pdf'),
        'name' => $nome,
    ];

    $res = assinafyRequest('POST', '/accounts/{account_id}/documents', $postFields, true);
    if ($res['code'] === 201 || $res['code'] === 200) {
        $docId = $res['data']['data']['id'] ?? ($res['data']['id'] ?? null);
        if ($docId) return ['document_id' => (string)$docId];
    }
    return ['error' => 'Falha no upload: HTTP ' . $res['code']];
}

/** Cria (ou reutiliza) um signatário. Retorna ['signer_id' => string] ou ['error' => string]. */
function assinafyCriarSignatario(string $nome, string $email, string $telefone = ''): array {
    $payload = ['full_name' => $nome, 'email' => $email];
    if ($telefone) {
        $tel = preg_replace('/\D/', '', $telefone);
        if (strlen($tel) === 11 || strlen($tel) === 10) $tel = '55' . $tel;
        if (str_starts_with($tel, '55') && strlen($tel) >= 12) {
            $payload['whatsapp_phone_number'] = '+' . $tel;
        }
    }

    $res = assinafyRequest('POST', '/accounts/{account_id}/signers', $payload);
    if ($res['code'] === 201 || $res['code'] === 200) {
        $signerId = $res['data']['data']['id'] ?? ($res['data']['id'] ?? null);
        if ($signerId) return ['signer_id' => (string)$signerId];
    }

    // Signatário já existe — tenta extrair ID do erro ou buscar por e-mail
    if ($res['code'] === 422 || $res['code'] === 400) {
        $existingId = $res['data']['data']['id'] ?? ($res['data']['id'] ?? ($res['data']['signer']['id'] ?? null));
        if ($existingId && is_numeric($existingId)) return ['signer_id' => (string)$existingId];

        $busca = assinafyRequest('GET', '/accounts/{account_id}/signers?email=' . urlencode($email));
        if ($busca['code'] === 200) {
            $items = $busca['data']['data'] ?? ($busca['data'] ?? []);
            if (is_array($items) && isset($items[0]['id'])) return ['signer_id' => (string)$items[0]['id']];
        }
    }

    return ['error' => 'Falha ao criar signatário: HTTP ' . $res['code']];
}

/**
 * Cria um assignment (envelope de assinatura).
 * Retorna ['assignment_id' => string, 'sign_url' => string] ou ['error' => string].
 */
function assinafyCriarAssignment(string $docId, string $signerId, bool $temWhatsapp = false): array {
    $payloads = [];
    if ($temWhatsapp) {
        $payloads[] = [
            'method'  => 'virtual',
            'signers' => [['id' => $signerId, 'notification_methods' => ['whatsapp'], 'verification_method' => 'whatsapp']],
        ];
    }
    $payloads[] = ['method' => 'virtual', 'signerIds' => [$signerId]];

    $res = ['code' => 0, 'data' => []];
    foreach ($payloads as $payload) {
        $res = assinafyRequest('POST', '/documents/' . $docId . '/assignments', $payload);
        if ($res['code'] === 200 || $res['code'] === 201) break;
    }

    if ($res['code'] === 201 || $res['code'] === 200) {
        $assignmentData = $res['data']['data'] ?? $res['data'];
        $assignmentId   = $assignmentData['id'] ?? null;
        $signUrl = $assignmentData['sign_url'] ?? ($assignmentData['url'] ?? ($assignmentData['signing_url'] ?? ''));
        if ($assignmentId) return ['assignment_id' => (string)$assignmentId, 'sign_url' => $signUrl];
    }

    return ['error' => 'Falha ao criar assignment: HTTP ' . $res['code']];
}

/** Consulta o status de um documento. Retorna ['status'=>,'pdf_url'=>,'sign_url'=>] ou ['error'=>]. */
function assinafyStatusDocumento(string $docId): array {
    $res = assinafyRequest('GET', '/documents/' . $docId);
    if ($res['code'] === 404) {
        $res = assinafyRequest('GET', '/accounts/{account_id}/documents/' . $docId);
    }
    if ($res['code'] === 200) {
        $doc    = $res['data']['data'] ?? $res['data'];
        $status = $doc['status'] ?? 'unknown';
        $pdfUrl = $doc['artifacts']['certificated'] ?? ($doc['artifacts']['bundle'] ?? ($doc['signed_file_url'] ?? ($doc['file_url'] ?? '')));
        $signUrl = $doc['signing_url'] ?? ($doc['assignment']['signing_urls'][0]['url'] ?? '');
        return ['status' => $status, 'pdf_url' => $pdfUrl, 'sign_url' => $signUrl];
    }
    return ['error' => 'Falha ao consultar status: HTTP ' . $res['code']];
}

/** Baixa o PDF assinado (bytes binários) — '' em caso de falha. */
function assinafyBaixarAssinado(string $docId): string {
    $statusRes = assinafyStatusDocumento($docId);
    $pdfUrl    = $statusRes['pdf_url'] ?? '';
    if (!$pdfUrl) return '';

    $apiKey = getConfig('assinafy_api_key') ?: '';
    $ch = curl_init($pdfUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    if ($apiKey) curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-Api-Key: ' . $apiKey]);
    $content  = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ($httpCode === 200 && $content) ? $content : '';
}
