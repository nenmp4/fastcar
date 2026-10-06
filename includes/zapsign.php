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
 * Monta o objeto 'signer' pro payload da ZapSign a partir de nome/telefone/
 * email — usado pelo signatário principal e por cada extra (FASTCAR/
 * testemunhas).
 *
 * 06/10/2026 — achado real: "disparos de email para as testemunhas e
 * whatsap pela instacia zpi também não enviando[,] cliente da conferida"
 * (nenhum signatário, nem a contraparte principal, nem FASTCAR/testemunha,
 * jamais recebeu nada automaticamente da ZapSign desde que a integração
 * foi criada em 13/09/2026). Causa raiz: `send_automatic_email`/
 * `send_automatic_whatsapp` NUNCA foram mandados no payload — confirmado
 * via busca na documentação pública da ZapSign (docs.zapsign.com.br,
 * domínio bloqueado pra leitura direta neste sandbox, mesma limitação de
 * sempre — confirmado via 2 buscas independentes com exemplo de JSON real
 * batendo): são campos booleanos, **por signatário** (dentro de cada
 * objeto em `signers[]`, nunca no nível do documento), **falsos por
 * padrão** — a própria doc diz explicitamente: "se `false` (o padrão),
 * você é responsável por compartilhar o link de assinatura manualmente
 * (WhatsApp, SMS, e-mail, etc)". Batia exatamente com o sintoma: todo o
 * fluxo de "copiar link"/"reenviar por WhatsApp" (admin/oportunidade.php,
 * admin/venda.php) sempre foi um WORKAROUND manual pra um envio
 * automático que nunca existiu de verdade.
 * Corrigido mandando os 2 flags sempre que o respectivo dado existe —
 * `send_automatic_email` só quando `email` foi de fato incluído,
 * `send_automatic_whatsapp` só quando `phone_number` foi de fato incluído
 * (a própria doc exige o dado correspondente presente) — nunca manda o
 * flag sozinho sem o dado, e nunca inventa contato que o signatário não
 * tem. ⚠️ Ainda não confirmado contra uma conta ZapSign real (mesma
 * ressalva de sempre pra essa integração) — validar no próximo contrato
 * de verdade que uma testemunha/FASTCAR/cliente recebe a mensagem sem
 * ninguém precisar clicar em nada.
 */
function _zapsignMontarSigner(string $nome, string $telefone = '', string $email = ''): array {
    $signer = ['name' => $nome ?: 'Signatário'];
    if ($telefone) {
        $tel = preg_replace('/\D/', '', $telefone);
        // 06/10/2026 — 2º achado no mesmo diagnóstico, bug JÁ documentado em
        // 21/09/2026 mas nunca corrigido ("fora do escopo... sinalizado
        // como tarefa separada"): todo caller daqui (montarCamposContratoCompra()/
        // Venda(), via $op['telefone']/_telefone) passa o telefone já
        // normalizado por normalizarTelefone() — SEMPRE com o DDI 55 na
        // frente (12 ou 13 dígitos), nunca o formato bruto de 10/11 que
        // este `if` abaixo sempre esperou. Resultado real: phone_number
        // NUNCA era incluído no payload pra NENHUM signatário (confirmado
        // testando contra fake server — "só name/email chegavam, nunca
        // telefone") — explica o "whatsapp... não enviando" reportado
        // agora, concretamente, não só a falta do flag send_automatic_whatsapp
        // (ver comentário da função acima). Descasca o 55 antes de medir
        // o tamanho; aceita também o formato bruto (sem DDI) de quem
        // porventura já mandar assim.
        if (strlen($tel) >= 12 && str_starts_with($tel, '55')) {
            $tel = substr($tel, 2);
        }
        if (strlen($tel) === 11 || strlen($tel) === 10) {
            // ZapSign quer DDD e número separados do código do país.
            $signer['phone_country'] = '55';
            $signer['phone_number']  = $tel;
            $signer['send_automatic_whatsapp'] = true;
        }
    }
    // E-mail (14/09/2026, pedido do José/Jean — "vamos enviar no email dele
    // o contrato"): a ZapSign manda o link de assinatura por e-mail quando
    // o signatário tem um cadastrado, além/em vez do WhatsApp/SMS pelo
    // telefone acima. Sem e-mail, segue só pelo telefone — nunca bloqueia
    // o envio do contrato por falta desse dado.
    if ($email && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $signer['email'] = $email;
        $signer['send_automatic_email'] = true;
    }
    return $signer;
}

/**
 * Cria o documento JÁ com o(s) signatário(s) (1 chamada só, diferente da
 * Assinafy) — manda o PDF inteiro em base64. `$signersExtras` (02/10/2026,
 * "todos precisam assinar... bota as duas testemunhas pra assinar") é uma
 * lista de signatários ALÉM da contraparte principal — cada item
 * `['chave'=>'fastcar'|'testemunha1'|'testemunha2', 'nome'=>, 'telefone'=>,
 * 'email'=>]`; item sem `nome` preenchido é ignorado (nunca bloqueia a
 * geração do contrato por falta de representante/testemunha cadastrado em
 * Configurações — mesma regra #3 do projeto, nunca inventa signatário).
 * O status geral do documento na ZapSign (`doc.status`) só vira "signed"
 * quando TODOS os signatários configurados assinarem — zapsignSincronizarContrato()
 * não precisou de nenhuma mudança por causa disso, já lê esse campo agregado.
 *
 * Retorna ['doc_token'=>, 'signer_token'=>, 'sign_url'=>] (sempre da
 * contraparte principal, mesmo contrato de antes) + 'signers_extra' =>
 * ['chave' => ['token'=>, 'sign_url'=>], ...] só com quem de fato entrou
 * como signatário — ou ['error' => string].
 */
function zapsignCriarDocumentoEAssinatura(string $pdfPath, string $nomeDoc, string $signerNome, string $telefone = '', string $email = '', array $signersExtras = []): array {
    if (!file_exists($pdfPath)) {
        return ['error' => 'Arquivo não encontrado: ' . $pdfPath];
    }

    $signers = [_zapsignMontarSigner($signerNome, $telefone, $email)];
    $chavesExtras = [];
    foreach ($signersExtras as $extra) {
        $nomeExtra = trim((string)($extra['nome'] ?? ''));
        if ($nomeExtra === '') continue; // sem nome configurado — nunca inventa signatário
        $signers[] = _zapsignMontarSigner($nomeExtra, (string)($extra['telefone'] ?? ''), (string)($extra['email'] ?? ''));
        $chavesExtras[] = (string)($extra['chave'] ?? ('extra' . count($chavesExtras)));
    }

    $payload = [
        'name'       => $nomeDoc,
        'base64_pdf' => base64_encode((string)file_get_contents($pdfPath)),
        'signers'    => $signers,
    ];

    $res = zapsignRequest('POST', '/docs/', $payload);
    if ($res['code'] < 200 || $res['code'] >= 300) {
        $erro = $res['data']['error'] ?? $res['data']['message'] ?? ('HTTP ' . $res['code']);
        return ['error' => 'Falha ao criar documento na ZapSign: ' . $erro];
    }

    $docToken = $res['data']['token'] ?? null;
    $assinantesRes = $res['data']['signers'] ?? [];
    $assinante = $assinantesRes[0] ?? [];
    if (!$docToken || empty($assinante['token'])) {
        return ['error' => 'Resposta da ZapSign sem token de documento/signatário.'];
    }

    $signersExtra = [];
    foreach ($chavesExtras as $i => $chave) {
        $assinanteExtra = $assinantesRes[$i + 1] ?? null;
        if ($assinanteExtra && !empty($assinanteExtra['token'])) {
            $signersExtra[$chave] = [
                'token'    => (string)$assinanteExtra['token'],
                'sign_url' => (string)($assinanteExtra['sign_url'] ?? ''),
            ];
        }
    }

    return [
        'doc_token'     => (string)$docToken,
        'signer_token'  => (string)$assinante['token'],
        'sign_url'      => (string)($assinante['sign_url'] ?? ''),
        'signers_extra' => $signersExtra,
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
