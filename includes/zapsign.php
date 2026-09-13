<?php
/**
 * includes/zapsign.php — Integração ZapSign (assinatura eletrônica).
 * Substituiu a Assinafy em 13/09/2026 (pedido do José/Jean) — mesmo padrão
 * PHP puro com cURL, sem dependências, que a Assinafy usava.
 *
 * Diferença de fluxo importante em relação à Assinafy: lá eram 3 chamadas
 * separadas (upload do documento → criar signatário → criar assignment).
 * A ZapSign cria documento + signatário numa ÚNICA chamada
 * (POST /docs/, PDF em base64 + array de signers já no mesmo payload) —
 * bem mais simples, ver zapsignCriarDocumentoEAssinatura().
 *
 * ⚠️ Construído a partir da documentação oficial (docs.zapsign.com.br),
 * nunca contra a API real (ambiente de dev bloqueia acesso externo pra
 * chamadas HTTP diretas, só a busca de documentação passa) — testado
 * contra servidor fake local simulando os formatos documentados. Validar
 * contra uma conta ZapSign de verdade antes do primeiro contrato real (ver
 * CLAUDE.md → "a validar em produção").
 */

require_once __DIR__ . '/db.php';

/** Base URL override via define() só em teste (fake server local) — produção real é api.zapsign.com.br. */
function zapsignBaseUrl(): string {
    return defined('ZAPSIGN_BASE_URL') ? ZAPSIGN_BASE_URL : 'https://api.zapsign.com.br/api/v1';
}

/**
 * Faz uma requisição à API ZapSign — token de conta via header
 * "Authorization: Bearer {token}" (token único, ao contrário da Assinafy
 * que precisava de api_key + account_id separados).
 * @return array ['code' => int, 'data' => array]
 */
function zapsignRequest(string $method, string $endpoint, array $data = []): array {
    $token = getConfig('zapsign_api_token') ?: '';
    $url = zapsignBaseUrl() . $endpoint;

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);

    $headers = ['Authorization: Bearer ' . $token];

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        $json = json_encode($data);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        $headers[] = 'Content-Type: application/json';
        $headers[] = 'Content-Length: ' . strlen($json);
    } elseif ($method === 'PUT') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
        $json = json_encode($data);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        $headers[] = 'Content-Type: application/json';
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

/**
 * Cria o documento JÁ com o signatário (1 chamada só, diferente da
 * Assinafy) — manda o PDF inteiro em base64. Retorna
 * ['doc_token' => string, 'signer_token' => string, 'sign_url' => string]
 * ou ['error' => string].
 */
function zapsignCriarDocumentoEAssinatura(string $pdfPath, string $nomeDoc, string $signerNome, string $telefone = ''): array {
    if (!file_exists($pdfPath)) {
        return ['error' => 'Arquivo não encontrado: ' . $pdfPath];
    }

    $signer = ['name' => $signerNome ?: 'Vendedor'];
    if ($telefone) {
        $tel = preg_replace('/\D/', '', $telefone);
        if (strlen($tel) === 11 || strlen($tel) === 10) {
            // ZapSign quer DDD e número separados do código do país.
            $signer['phone_country'] = '55';
            $signer['phone_number']  = $tel;
        }
    }

    $payload = [
        'name'       => $nomeDoc,
        'base64_pdf' => base64_encode((string)file_get_contents($pdfPath)),
        'signers'    => [$signer],
    ];

    $res = zapsignRequest('POST', '/docs/', $payload);
    if ($res['code'] < 200 || $res['code'] >= 300) {
        $erro = $res['data']['error'] ?? $res['data']['message'] ?? ('HTTP ' . $res['code']);
        return ['error' => 'Falha ao criar documento na ZapSign: ' . $erro];
    }

    $docToken = $res['data']['token'] ?? null;
    $assinante = $res['data']['signers'][0] ?? [];
    if (!$docToken || empty($assinante['token'])) {
        return ['error' => 'Resposta da ZapSign sem token de documento/signatário.'];
    }

    return [
        'doc_token'    => (string)$docToken,
        'signer_token' => (string)$assinante['token'],
        'sign_url'     => (string)($assinante['sign_url'] ?? ''),
    ];
}

/**
 * Consulta o status de um documento.
 * @return array ['status'=>'signed'|'pending'|'refused', 'signed_file_url'=>, 'sign_url'=>] ou ['error'=>]
 */
function zapsignStatusDocumento(string $docToken): array {
    $res = zapsignRequest('GET', '/docs/' . $docToken . '/');
    if ($res['code'] !== 200) {
        return ['error' => 'Falha ao consultar status: HTTP ' . $res['code']];
    }
    $doc = $res['data'];
    $signUrl = $doc['signers'][0]['sign_url'] ?? '';
    return [
        'status'          => (string)($doc['status'] ?? 'unknown'),
        // original_file/signed_file são URLs TEMPORÁRIAS (expiram em ~60min,
        // documentado pela própria ZapSign) — nunca guardar, só baixar na
        // hora (zapsignBaixarAssinado()).
        'signed_file_url' => (string)($doc['signed_file'] ?? ''),
        'sign_url'        => (string)$signUrl,
    ];
}

/** Baixa o PDF assinado (bytes binários) — '' em caso de falha. */
function zapsignBaixarAssinado(string $docToken): string {
    $statusRes = zapsignStatusDocumento($docToken);
    $pdfUrl = $statusRes['signed_file_url'] ?? '';
    if (!$pdfUrl) return '';

    $ch = curl_init($pdfUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    $content  = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ($httpCode === 200 && $content) ? $content : '';
}
