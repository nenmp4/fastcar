<?php
/**
 * Evolution API (self-hosted, WhatsApp via Baileys — protocolo não-oficial,
 * mesma categoria de risco de shadowban/banimento que a Z-API, nunca menor,
 * ver `~/.claude/CLAUDE.md` sobre o WA-AKG) — 09/10/2026, "vamos implementa
 * evolution desativa met zpi [por enquanto]".
 *
 * **Contexto**: a conta Z-API em uso teve 4 números distintos banidos pela
 * Meta em sequência, quase instantaneamente, um deles sem NENHUMA automação
 * de envio rodando antes do bloqueio (número recém-pareado, nada além da
 * conexão em si) — padrão forte demais pra ser coincidência de número
 * isolado, consistente com a própria CONTA/infraestrutura Z-API já estar
 * marcada pela Meta (ver CLAUDE.md, bullets "Novo número banido pela Meta
 * imediatamente ao conectar na Z-API..."/"Hipótese de acompanhamento do
 * usuário"). Decisão do usuário: trocar o canal PRINCIPAL pra uma instância
 * Evolution API self-hosted, numa VPS própria (HostGator, addon "Evolution
 * API Whats", ainda "Em configuração" no momento desta implementação). Z-API
 * e Meta oficial NUNCA removidas do código (regra de sempre deste projeto:
 * nunca apaga integração funcional), só deixam de ser o canal ATIVO "por
 * enquanto" — reversível a qualquer momento trocando o radio em
 * Configurações.
 *
 * **Risco estrutural, não eliminado**: Evolution API também é construída
 * em cima do Baileys (WhatsApp Web/multi-device não-oficial) — trocar de
 * PROVEDOR muda quem hospeda a conexão, nunca elimina o risco do protocolo
 * em si (mesma ressalva já registrada na memória global sobre o WA-AKG:
 * "mesma categoria de risco de shadowban/banimento que a Z-API, nunca
 * menor"). Self-hosted dá um controle que a Z-API nunca deu (infra/IP
 * próprios, sem compartilhar pool com outras contas de terceiro) — pode
 * ajudar com a hipótese de "conta/infraestrutura compartilhada marcada",
 * mas não é garantia nenhuma.
 *
 * **Formato real desta instância, confirmado contra a API de verdade**
 * (09/10/2026) — a doc pública (`docs.evolutionfoundation.com.br`,
 * confirmada só via WebSearch, domínio bloqueado neste sandbox) sugeria
 * um corpo ANINHADO pro sendText, mas a instância REAL rejeitou com
 * `"instance requires property \"text\""` assim que testada de verdade —
 * prova de que a doc (ou a versão dela) não bate com este deploy
 * (v2.3.7). Corrigido pro formato que o próprio servidor exige:
 *
 *   - Auth: header `apikey` (global ou da instância).
 *   - `POST /message/sendText/{instance}` — JSON, corpo **FLAT**:
 *     `{number, text}` — **NUNCA** `{number, textMessage: {text}}`
 *     aninhado (a doc sugeria isso, mas a API real desta instância
 *     rejeita — confirmado pelo erro literal do servidor, não por
 *     documentação de terceiro).
 *   - `POST /message/sendMedia/{instance}` — **multipart/form-data**, NÃO
 *     JSON (2 buscas independentes confirmaram, nenhuma mostrou opção de
 *     base64/URL em texto) — campos `number`, `mediatype`
 *     (image|video|audio|document), `media` (o ARQUIVO de verdade, upload
 *     binário via `@arquivo` no curl), `caption?`, `fileName?`. Diferente
 *     de Z-API/Meta (que aceitam URL pública OU base64 em texto),
 *     `_evolutionEnviarMidia()` sempre RESOLVE os bytes primeiro — baixa a
 *     URL ou decodifica o data URI — antes de montar o multipart.
 *   - `GET /instance/connectionState/{instance}` — `{instance: {state:
 *     open|close|connecting}}`.
 *   - Webhook: evento `MESSAGES_UPSERT` (confirmado na doc de configuração
 *     de webhook — maiúsculo/underscore, não o `messages.upsert` minúsculo/
 *     ponto que a busca genérica inicial tinha sugerido; aceito os dois
 *     formatos no adaptador, por segurança). **Nunca confirmado contra
 *     esta doc específica**: o formato exato do corpo de
 *     `data.key.remoteJid`/`data.message.conversation`/`data.pushName` —
 *     só confirmado contra a doc genérica do projeto EvolutionAPI original
 *     (github.com/EvolutionAPI/evolution-api, via mintlify.com, mesmo
 *     código-base que a Evolution Foundation distribui) — mantido como
 *     suposição mais provável, mas nunca validado contra ESTE produto
 *     específico.
 *   - "Webhook by events": a doc menciona um modo onde cada evento vai pra
 *     uma URL própria (`/webhook/messages-upsert`) — este projeto assume
 *     o modo SIMPLES (1 URL só, `event` no corpo decide o tipo), mesmo
 *     padrão de toda outra integração daqui (Z-API/Meta também usam 1 URL
 *     única) — instruir o usuário a NUNCA ligar "Webhook By Events" na
 *     hora de configurar a instância.
 *
 * **IMPORTANTE — existem DOIS produtos distintos** sob a marca Evolution:
 * "Evolution API" (o clássico, JS/TS, Baileys) e "Evolution Go" (mais
 * novo, Go, formato de API bem diferente — `/send/media` em vez de
 * `/message/sendMedia/...`, JSON em vez de multipart). Este arquivo
 * assume **Evolution API** (o clássico) — é o que o addon "Evolution API
 * Whats" da HostGator parece instalar pelo próprio nome; se a VPS vier
 * com Evolution Go, os endpoints aqui não batem e precisam de revisão.
 *
 * **Ainda sem confirmação nenhuma** (nem documentação nem instância real):
 * (1) RECEBIMENTO de mídia — Baileys normalmente exige decriptar usando
 * chaves (`mediaKey`) que vêm dentro da própria mensagem; nenhuma fonte
 * consultada aqui confirmou como a Evolution API expõe isso (base64 direto
 * no webhook? endpoint de download à parte?) — deixado de fora de
 * propósito nesta 1ª versão, cai no mesmo caminho gracioso "mídia não
 * processada" que o projeto já tem; (2) endpoint `sendWhatsAppAudio`
 * (nota de voz/PTT) só confirmado via busca genérica do projeto original,
 * nunca contra esta doc específica — mantido como aposta mais provável.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';

/** Base URL override só em teste (fake server local), mesmo padrão do resto do projeto. */
function evolutionBaseUrl(): string {
    if (defined('EVOLUTION_BASE_URL')) return rtrim(EVOLUTION_BASE_URL, '/');
    return rtrim((string)(getConfig('evolution_base_url') ?: ''), '/');
}

/** [base_url, instance_name, api_key] — '' se algum não estiver configurado ainda. */
function evolutionCredenciais(): array {
    return [
        evolutionBaseUrl(),
        getConfig('evolution_instance_name') ?: '',
        getConfig('evolution_api_key') ?: '',
    ];
}

function evolutionConfigured(): bool {
    [$base, $instance, $key] = evolutionCredenciais();
    return $base !== '' && $instance !== '' && $key !== '';
}

/**
 * ⚠️ Ignora verificação de certificado SSL pro host da Evolution — opt-in
 * explícito (`config.evolution_ignorar_ssl==='1'`), NUNCA ligado por
 * padrão (só existe pra cobrir a janela em que a VPS própria ainda não
 * tem domínio/certificado válido, só autoassinado — ver card em
 * Configurações, 09/10/2026, "SSL certificate problem: self-signed
 * certificate"). Nunca usar isso contra host de terceiro — aqui é
 * sempre a VPS que o próprio super_admin controla.
 */
function evolutionIgnorarSsl(): bool {
    return getConfig('evolution_ignorar_ssl') === '1';
}

/**
 * Opções de SSL pra injetar nos curl_setopt_array() que falam DIRETO com a
 * Evolution (nunca em _evolutionResolverBytes(), que baixa de URL
 * arbitrária — ali a verificação de certificado sempre tem que valer).
 */
function _evolutionCurlSslOpts(): array {
    if (!evolutionIgnorarSsl()) return [];
    return [
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
    ];
}

/**
 * Canal principal usa Evolution? — mesmo papel de oficialEhProviderPrincipal().
 * Default 'zapi' (nunca muda comportamento sozinho) até o usuário escolher
 * explicitamente em Configurações.
 */
function evolutionEhProviderPrincipal(): bool {
    return getConfig('whatsapp_provider_principal') === 'evolution' && evolutionConfigured();
}

$GLOBALS['_evolution_ultimo_erro'] = null;
function evolutionUltimoErro(): ?string {
    return $GLOBALS['_evolution_ultimo_erro'];
}
function _evolutionSetUltimoErro(?string $msg): void {
    $GLOBALS['_evolution_ultimo_erro'] = $msg;
}

function _evolutionHeaders(string $apiKey, bool $json = true): array {
    $h = ['apikey: ' . $apiKey];
    if ($json) $h[] = 'Content-Type: application/json';
    return $h;
}

/**
 * Extrai uma mensagem de erro legível de uma resposta JSON da Evolution —
 * formato de erro nunca confirmado, tenta os campos mais prováveis.
 * `$redirectUrl` (de CURLINFO_REDIRECT_URL, funciona mesmo sem
 * CURLOPT_FOLLOWLOCATION) aparece junto num 3xx — diagnóstico direto de
 * "URL base" errada (http em vez de https, IP em vez do host certo etc),
 * sem precisar adivinhar.
 */
function _evolutionErroDeResposta($json, int $httpCode, ?string $redirectUrl = null): string {
    $erro = $json['message'] ?? $json['error'] ?? $json['response']['message'] ?? "HTTP {$httpCode}";
    $erro = is_array($erro) ? json_encode($erro, JSON_UNESCAPED_UNICODE) : (string)$erro;
    if ($httpCode >= 300 && $httpCode < 400 && $redirectUrl) {
        $erro .= " — redirecionado pra: {$redirectUrl} (confira protocolo/host/porta em \"URL base\")";
    }
    return $erro;
}

/**
 * POST /message/sendText/{instance} — JSON, corpo aninhado
 * {number, textMessage: {text}} (confirmado contra a doc real, ver
 * docblock do topo do arquivo).
 */
function evolutionEnviarTexto(string $phone, string $msg, ?array $override = null): bool {
    [$base, $instance, $apiKey] = $override ?? evolutionCredenciais();
    if (!$base || !$instance || !$apiKey || !$phone) {
        _evolutionSetUltimoErro('Sem URL/instância/API key configurados.');
        return false;
    }
    $phoneNorm = normalizarTelefone($phone);
    if (strlen($phoneNorm) < 12) {
        _evolutionSetUltimoErro('Telefone inválido.');
        return false;
    }

    $ch = curl_init("{$base}/message/sendText/" . rawurlencode($instance));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'number' => $phoneNorm,
            'text' => $msg,
        ]),
        CURLOPT_HTTPHEADER => _evolutionHeaders($apiKey),
        CURLOPT_TIMEOUT => 20,
    ] + _evolutionCurlSslOpts());
    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $redirectUrl = curl_getinfo($ch, CURLINFO_REDIRECT_URL) ?: null;
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($resp === false) {
        _evolutionSetUltimoErro("Falha de conexão: {$curlErr}");
        return false;
    }
    if ($httpCode >= 200 && $httpCode < 300) {
        _evolutionSetUltimoErro(null);
        return true;
    }
    $json = json_decode((string)$resp, true);
    _evolutionSetUltimoErro(_evolutionErroDeResposta($json, $httpCode, $redirectUrl) . ' — resposta: ' . substr((string)$resp, 0, 500));
    return false;
}

/**
 * Resolve `$urlOuDataUri` pros bytes de verdade + mime — baixa a URL (http/
 * https) ou decodifica o data URI base64. Necessário porque, diferente de
 * Z-API/Meta, o sendMedia da Evolution API quer o ARQUIVO em si no
 * multipart, nunca uma URL/base64 em campo de texto. `null` em qualquer
 * falha (nunca lança — quem chama decide o que fazer).
 */
function _evolutionResolverBytes(string $urlOuDataUri): ?array {
    if (preg_match('#^data:([a-zA-Z0-9/+.\-]+);base64,(.+)$#s', $urlOuDataUri, $m)) {
        $bytes = base64_decode($m[2], true);
        if ($bytes === false) return null;
        return ['mime' => $m[1], 'bytes' => $bytes];
    }
    if (preg_match('#^https?://#i', $urlOuDataUri)) {
        $ch = curl_init($urlOuDataUri);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $bytes = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $mime = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);
        if ($bytes === false || $http < 200 || $http >= 300 || $bytes === '') return null;
        return ['mime' => $mime !== '' ? $mime : 'application/octet-stream', 'bytes' => $bytes];
    }
    return null;
}

/**
 * Imagem/vídeo/documento — POST /message/sendMedia/{instance},
 * multipart/form-data (confirmado contra a doc real — ver docblock do
 * topo). `$urlOuDataUri` sempre resolvido pros bytes antes do upload
 * (`_evolutionResolverBytes()`) — nunca manda URL/base64 em texto, a
 * Evolution quer o arquivo de verdade.
 */
function _evolutionEnviarMidia(string $phone, string $tipo, string $urlOuDataUri, string $legenda, string $nomeArquivo, ?array $override = null): bool {
    [$base, $instance, $apiKey] = $override ?? evolutionCredenciais();
    if (!$base || !$instance || !$apiKey || !$phone || !$urlOuDataUri) {
        _evolutionSetUltimoErro('Sem URL/instância/API key configurados, ou telefone/arquivo vazio.');
        return false;
    }
    $phoneNorm = normalizarTelefone($phone);
    if (strlen($phoneNorm) < 12) {
        _evolutionSetUltimoErro('Telefone inválido.');
        return false;
    }

    $resolvido = _evolutionResolverBytes($urlOuDataUri);
    if ($resolvido === null) {
        _evolutionSetUltimoErro('Não consegui resolver o arquivo (URL inacessível ou data URI inválido).');
        return false;
    }

    $campos = [
        'number' => $phoneNorm,
        'mediatype' => in_array($tipo, ['image', 'video', 'document'], true) ? $tipo : 'image',
        'media' => new CURLStringFile($resolvido['bytes'], $nomeArquivo, $resolvido['mime']),
        'fileName' => $nomeArquivo,
    ];
    if ($legenda !== '') {
        $campos['caption'] = $legenda;
    }

    $ch = curl_init("{$base}/message/sendMedia/" . rawurlencode($instance));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $campos,
        CURLOPT_HTTPHEADER => _evolutionHeaders($apiKey, false), // multipart — nunca Content-Type manual, o cURL monta o boundary sozinho
        CURLOPT_TIMEOUT => 40,
    ] + _evolutionCurlSslOpts());
    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $redirectUrl = curl_getinfo($ch, CURLINFO_REDIRECT_URL) ?: null;
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($resp === false) {
        _evolutionSetUltimoErro("Falha de conexão: {$curlErr}");
        return false;
    }
    if ($httpCode >= 200 && $httpCode < 300) {
        _evolutionSetUltimoErro(null);
        return true;
    }
    $json = json_decode((string)$resp, true);
    _evolutionSetUltimoErro(_evolutionErroDeResposta($json, $httpCode, $redirectUrl) . ' — resposta: ' . substr((string)$resp, 0, 500));
    return false;
}

function evolutionEnviarImagem(string $phone, string $imagemUrl, string $legenda, ?array $override = null): bool {
    return _evolutionEnviarMidia($phone, 'image', $imagemUrl, $legenda, 'imagem.jpg', $override);
}

function evolutionEnviarVideo(string $phone, string $videoUrlOuDataUri, string $legenda, ?array $override = null): bool {
    return _evolutionEnviarMidia($phone, 'video', $videoUrlOuDataUri, $legenda, 'video.mp4', $override);
}

function evolutionEnviarDocumento(string $phone, string $documentoUrlOuDataUri, string $fileName, ?array $override = null): bool {
    return _evolutionEnviarMidia($phone, 'document', $documentoUrlOuDataUri, '', $fileName !== '' ? $fileName : 'documento.pdf', $override);
}

/**
 * Áudio (nota de voz) — endpoint PRÓPRIO (`sendWhatsAppAudio`, só
 * confirmado via busca genérica do projeto original — nunca contra esta
 * doc específica, ver docblock do topo), também multipart (mesmo
 * raciocínio de `_evolutionEnviarMidia()` — nunca confirmado se esse
 * endpoint específico aceita JSON com base64 em vez de arquivo, mas
 * manter o mesmo padrão de sendMedia é a aposta mais segura). Sem
 * legenda — WhatsApp não aceita caption em áudio.
 */
function evolutionEnviarAudio(string $phone, string $audioDataUriOuUrl, ?array $override = null): bool {
    [$base, $instance, $apiKey] = $override ?? evolutionCredenciais();
    if (!$base || !$instance || !$apiKey || !$phone || !$audioDataUriOuUrl) {
        _evolutionSetUltimoErro('Sem URL/instância/API key configurados, ou telefone/arquivo vazio.');
        return false;
    }
    $phoneNorm = normalizarTelefone($phone);
    if (strlen($phoneNorm) < 12) {
        _evolutionSetUltimoErro('Telefone inválido.');
        return false;
    }

    $resolvido = _evolutionResolverBytes($audioDataUriOuUrl);
    if ($resolvido === null) {
        _evolutionSetUltimoErro('Não consegui resolver o áudio (URL inacessível ou data URI inválido).');
        return false;
    }

    $ch = curl_init("{$base}/message/sendWhatsAppAudio/" . rawurlencode($instance));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => [
            'number' => $phoneNorm,
            'audio' => new CURLStringFile($resolvido['bytes'], 'audio.ogg', $resolvido['mime']),
        ],
        CURLOPT_HTTPHEADER => _evolutionHeaders($apiKey, false),
        CURLOPT_TIMEOUT => 30,
    ] + _evolutionCurlSslOpts());
    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $redirectUrl = curl_getinfo($ch, CURLINFO_REDIRECT_URL) ?: null;
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($resp === false) {
        _evolutionSetUltimoErro("Falha de conexão: {$curlErr}");
        return false;
    }
    if ($httpCode >= 200 && $httpCode < 300) {
        _evolutionSetUltimoErro(null);
        return true;
    }
    $json = json_decode((string)$resp, true);
    _evolutionSetUltimoErro(_evolutionErroDeResposta($json, $httpCode, $redirectUrl) . ' — resposta: ' . substr((string)$resp, 0, 500));
    return false;
}

/**
 * Testa a conexão — GET /instance/connectionState/{instance}, lê o estado
 * de pareamento do dispositivo (não existe conceito de "token válido" sem
 * pareamento aqui, diferente da Cloud API — é sempre sobre o QR code ter
 * sido escaneado ou não). Lança com o erro real em falha, mesmo padrão de
 * oficialTestarConexao().
 */
function evolutionTestarConexao(?array $override = null): string {
    [$base, $instance, $apiKey] = $override ?? evolutionCredenciais();
    if (!$base || !$instance || !$apiKey) {
        throw new RuntimeException('URL/instância/API key não configurados.');
    }
    $ch = curl_init("{$base}/instance/connectionState/" . rawurlencode($instance));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => _evolutionHeaders($apiKey),
        CURLOPT_TIMEOUT => 15,
    ] + _evolutionCurlSslOpts());
    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $redirectUrl = curl_getinfo($ch, CURLINFO_REDIRECT_URL) ?: null;
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($resp === false) {
        throw new RuntimeException("Falha de conexão: {$curlErr}");
    }
    $json = json_decode((string)$resp, true);
    $estado = $json['instance']['state'] ?? $json['state'] ?? null;
    if ($httpCode !== 200 || !$estado) {
        throw new RuntimeException('Evolution respondeu com erro: ' . _evolutionErroDeResposta($json, $httpCode, $redirectUrl) . ' — resposta: ' . substr((string)$resp, 0, 400));
    }
    $rotulo = match ($estado) {
        'open' => 'conectado (pareado)',
        'connecting' => 'conectando (aguardando QR code)',
        'close' => 'desconectado',
        default => (string)$estado,
    };
    return "instância \"{$instance}\" — {$rotulo}";
}

/**
 * GET /instance/fetchInstances — lista TODA instância cadastrada de
 * verdade nesse servidor. Diagnóstico manual, não usado em nenhum fluxo
 * automático: 09/10/2026, achado real — nem o valor mostrado com destaque
 * no painel do Manager (hash/API key da instância) nem o UUID que aparece
 * na própria URL do Manager eram aceitos como `instanceName` pelo
 * `/instance/connectionState/{instance}` ("instance does not exist" nos
 * dois) — só esse endpoint diz com certeza qual é o nome real, sem ficar
 * adivinhando valor por valor. Normalmente exige a API key GLOBAL (não a
 * de 1 instância específica) — se a chave salva for só da instância, pode
 * vir 401/403 aqui mesmo com a chave "funcionando" pra outros endpoints.
 */
function evolutionListarInstancias(?array $override = null): array {
    [$base, , $apiKey] = $override ?? evolutionCredenciais();
    if (!$base || !$apiKey) {
        throw new RuntimeException('URL base/API key não configurados.');
    }
    $ch = curl_init("{$base}/instance/fetchInstances");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => _evolutionHeaders($apiKey),
        CURLOPT_TIMEOUT => 15,
    ] + _evolutionCurlSslOpts());
    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $redirectUrl = curl_getinfo($ch, CURLINFO_REDIRECT_URL) ?: null;
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($resp === false) {
        throw new RuntimeException("Falha de conexão: {$curlErr}");
    }
    $json = json_decode((string)$resp, true);
    if ($httpCode !== 200) {
        throw new RuntimeException('Evolution respondeu com erro: ' . _evolutionErroDeResposta($json, $httpCode, $redirectUrl) . ' — resposta: ' . substr((string)$resp, 0, 800));
    }
    return is_array($json) ? $json : [];
}

/**
 * Status cacheado (60s TTL, mesmo padrão exato de zapiStatusPrincipalCache()/
 * oficialStatusCache()) — pro badge do topbar.
 */
function evolutionStatusCache(bool $forcar = false): array {
    [$base, $instance, $apiKey] = evolutionCredenciais();
    if (!$base || !$instance || !$apiKey) {
        return ['estado' => 'nao_configurado', 'verificado_em' => null];
    }

    $cacheRaw = getConfig('evolution_status_cache');
    if (!$forcar && $cacheRaw && str_contains($cacheRaw, '|')) {
        [$ts, $json] = explode('|', $cacheRaw, 2);
        if ((time() - (int)$ts) < 60) {
            $d = json_decode($json, true);
            if (is_array($d) && isset($d['estado'])) return $d;
        }
    }

    $resultado = ['estado' => 'erro', 'verificado_em' => time()];
    $ch = curl_init("{$base}/instance/connectionState/" . rawurlencode($instance));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => _evolutionHeaders($apiKey),
        CURLOPT_TIMEOUT => 4,
        CURLOPT_CONNECTTIMEOUT => 3,
    ] + _evolutionCurlSslOpts());
    $resp = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!$err && $code === 200) {
        $d = json_decode((string)$resp, true);
        $estado = $d['instance']['state'] ?? $d['state'] ?? null;
        $resultado = ['estado' => $estado === 'open' ? 'conectado' : 'desconectado', 'verificado_em' => time()];
    } elseif ($code === 401 || $code === 403) {
        $resultado = ['estado' => 'desconectado', 'verificado_em' => time()];
    }

    // Mesmo cuidado de zapiStatusPrincipalCache()/oficialStatusCache() —
    // esta função roda sem proteção em toda página do admin via
    // canalPrincipalStatusCache(); gravar o cache nunca pode crashar a
    // página inteira por causa de contenção real do SQLite.
    try {
        setConfig('evolution_status_cache', time() . '|' . json_encode($resultado));
    } catch (Throwable $e) {
        // nunca deixa a página inteira cair por causa de um cache de badge
    }
    return $resultado;
}

/**
 * Adapta 1 payload de webhook Evolution (evento MESSAGES_UPSERT) pro MESMO
 * formato que processarMensagemZapi() já sabe processar — mesmo papel de
 * oficialAdaptarPayloadParaZapi() (includes/whatsapp_oficial.php).
 *
 * Retorna null quando não é mensagem de verdade — qualquer evento que não
 * seja messages.upsert (connection.update, qrcode.updated, etc) ou um
 * messages.upsert sem `data.key.remoteJid` resolvível.
 *
 * Nome do evento aceito nos 2 formatos vistos em fontes diferentes:
 * `MESSAGES_UPSERT` (confirmado na doc de configuração de webhook do
 * produto real) e `messages.upsert` (formato da doc genérica do projeto
 * original) — nunca custa aceitar os dois.
 *
 * Mídia recebida: nunca preenche bytes/URL de propósito (recebimento de
 * mídia não tem confirmação nenhuma, ver docblock do topo do arquivo) — só
 * o mimeType, quando disponível. Cai no mesmo caminho gracioso "mídia não
 * processada" que o projeto já tem pra qualquer bloco sem URL/mediaId
 * resolvível (tipoMidia()==='desconhecido' ou extrairUrlMidia() não acha
 * nada).
 */
function evolutionAdaptarPayloadParaZapi(array $body): ?array {
    $evento = strtoupper((string)($body['event'] ?? ''));
    $evento = str_replace('.', '_', $evento);
    if ($evento !== 'MESSAGES_UPSERT') {
        return null;
    }
    $data = $body['data'] ?? null;
    if (!is_array($data)) return null;

    $key = $data['key'] ?? [];
    $remoteJid = (string)($key['remoteJid'] ?? '');
    if ($remoteJid === '') return null;

    $isGroup = str_ends_with($remoteJid, '@g.us');
    $phone = (string)preg_replace('/@.*/', '', $remoteJid); // tira @s.whatsapp.net / @g.us

    $mensagem = $data['message'] ?? [];
    $texto = $mensagem['conversation'] ?? $mensagem['extendedTextMessage']['text'] ?? null;

    $adaptado = [
        'messageId' => (string)($key['id'] ?? ''),
        'phone' => $phone,
        'fromMe' => !empty($key['fromMe']),
        'isGroup' => $isGroup,
        'senderName' => (string)($data['pushName'] ?? ''),
        'chatName' => (string)($data['pushName'] ?? ''),
    ];

    if ($texto !== null) {
        $adaptado['text'] = ['message' => (string)$texto];
    } elseif (isset($mensagem['imageMessage'])) {
        $adaptado['image'] = [
            'caption' => (string)($mensagem['imageMessage']['caption'] ?? ''),
            'mimeType' => (string)($mensagem['imageMessage']['mimetype'] ?? ''),
        ];
    } elseif (isset($mensagem['audioMessage'])) {
        $adaptado['audio'] = ['mimeType' => (string)($mensagem['audioMessage']['mimetype'] ?? '')];
    } elseif (isset($mensagem['videoMessage'])) {
        $adaptado['video'] = [
            'caption' => (string)($mensagem['videoMessage']['caption'] ?? ''),
            'mimeType' => (string)($mensagem['videoMessage']['mimetype'] ?? ''),
        ];
    } elseif (isset($mensagem['documentMessage'])) {
        $adaptado['document'] = ['mimeType' => (string)($mensagem['documentMessage']['mimetype'] ?? '')];
    }
    // Tipo desconhecido/evento de ack/reação: fica só com os campos base,
    // tipoMidia() retorna 'desconhecido', mesmo guard silencioso de sempre.

    return $adaptado;
}
