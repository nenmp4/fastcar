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
function zapsignCriarDocumentoEAssinatura(string $pdfPath, string $nomeDoc, string $signerNome, string $telefone = '', string $email = ''): array {
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
    // E-mail (14/09/2026, pedido do José/Jean — "vamos enviar no email dele
    // o contrato"): a ZapSign manda o link de assinatura por e-mail quando
    // o signatário tem um cadastrado, além/em vez do WhatsApp/SMS pelo
    // telefone acima. Sem e-mail no cadastro do cliente (wizard antigo, ou
    // etapa ainda não confirmada), segue só pelo telefone — nunca bloqueia
    // o envio do contrato por falta desse dado.
    if ($email && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $signer['email'] = $email;
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

/**
 * Lista uma página de documentos da conta ZapSign — 19/09/2026, pedido
 * direto: "zapasine tem monte contrato do crm anti será possivel puxar
 * concliar" — vários contratos assinados via ZapSign de negociações do
 * CRM anterior, nunca criados por este sistema (`zapsignCriarDocumentoEAssinatura()`
 * nunca rodou pra eles), então não têm nenhuma linha correspondente em
 * `contratos` aqui — reconciliação (achar/importar) fica em
 * `includes/zapsign_importar.php`.
 *
 * ⚠️ Formato de paginação assumido (estilo Django REST —
 * `count`/`next`/`previous`/`results` — padrão comum em API brasileira),
 * **nunca confirmado contra a API real** (mesma ressalva de todo endpoint
 * ZapSign que não seja `POST /docs/`/`GET /docs/{token}/`, já validados em
 * produção). Se a resposta real vier num formato diferente (lista simples
 * sem paginação, por exemplo), cai no fallback de tratar a resposta
 * inteira como a lista de itens.
 *
 * @return array ['ok'=>bool, 'itens'=>array, 'proxima_pagina'=>bool, 'erro'=>?string]
 */
function zapsignListarDocumentos(int $pagina = 1): array {
    $res = zapsignRequest('GET', '/docs/?page=' . max(1, $pagina));
    if ($res['code'] < 200 || $res['code'] >= 300) {
        $erro = $res['data']['error'] ?? $res['data']['message'] ?? ('HTTP ' . $res['code']);
        return ['ok' => false, 'itens' => [], 'proxima_pagina' => false, 'erro' => 'Falha ao listar documentos na ZapSign: ' . $erro];
    }
    $data = $res['data'];
    if (isset($data['results']) && is_array($data['results'])) {
        return ['ok' => true, 'itens' => $data['results'], 'proxima_pagina' => !empty($data['next']), 'erro' => null];
    }
    // Fallback — resposta veio como lista simples, sem envelope de paginação.
    $itens = array_is_list($data) ? $data : [];
    return ['ok' => true, 'itens' => $itens, 'proxima_pagina' => false, 'erro' => null];
}

/**
 * Percorre TODAS as páginas e agrega — capado em `$maxPaginas` (50, ~1000
 * documentos num plano padrão de 20/página) como rede de segurança contra
 * paginação mal formada nunca terminar. Se falhar no meio (rede,
 * autenticação), devolve o que já tinha juntado até ali com `ok=false` e o
 * erro — nunca perde silenciosamente os documentos já obtidos.
 */
function zapsignListarTodosDocumentos(int $maxPaginas = 50): array {
    $todos = [];
    $pagina = 1;
    while ($pagina <= $maxPaginas) {
        $r = zapsignListarDocumentos($pagina);
        if (!$r['ok']) {
            return ['ok' => false, 'itens' => $todos, 'erro' => $r['erro']];
        }
        $todos = array_merge($todos, $r['itens']);
        if (!$r['proxima_pagina'] || empty($r['itens'])) break;
        $pagina++;
    }
    return ['ok' => true, 'itens' => $todos, 'erro' => null];
}

/**
 * Extrai nome/telefone/cpf do 1º signatário de um item da listagem —
 * mesmos campos que `zapsignCriarDocumentoEAssinatura()` já manda pra
 * ZapSign na criação (`name`/`phone_country`+`phone_number`/`email`), mais
 * `cpf` (campo que a ZapSign pode preencher durante a qualificação do
 * signatário, dependendo de como o documento foi configurado — nunca
 * confirmado contra uma resposta real). Sem nome nem telefone reconhecidos,
 * grava o item cru em log pra ajustar o parsing depois (mesmo padrão de
 * `logDiagnosticoMidiaZapi()`).
 */
function zapsignExtrairSignatario(array $doc): array {
    $s = $doc['signers'][0] ?? [];
    $nome = trim((string)($s['name'] ?? ''));
    $telefone = '';
    if (!empty($s['phone_number'])) {
        $telefone = (string)($s['phone_country'] ?? '55') . preg_replace('/\D/', '', (string)$s['phone_number']);
    }
    $cpf = preg_replace('/\D/', '', (string)($s['cpf'] ?? $s['document'] ?? ''));
    if ($nome === '' && $telefone === '') {
        zapsignLogDiagnosticoImportacao($doc);
    }
    return ['nome' => $nome, 'telefone' => $telefone, 'cpf' => $cpf];
}

function zapsignLogDiagnosticoImportacao(array $doc): void {
    try {
        $dir = dirname(__DIR__) . '/storage/logs';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $linha = '[' . date('Y-m-d H:i:s') . '] doc_sem_signatario_reconhecido='
            . json_encode($doc, JSON_UNESCAPED_UNICODE) . "\n";
        file_put_contents($dir . '/zapsign_importacao_debug.log', $linha, FILE_APPEND);
    } catch (Throwable $e) {
        // diagnóstico nunca pode quebrar o fluxo principal
    }
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
