<?php
/**
 * Integração ZapCar (api.zapcarconsulta.com.br) — consulta veicular por
 * placa (restrições, débitos, gravame, leilão, sinistro etc), pra ajudar
 * o consultor a avaliar o veículo ANTES de fechar a compra
 * (admin/oportunidade.php). 22/09/2026, "vamos integrar essa api no
 * sistema em oputunidade compras" — doc oficial da ZapCar (openapi
 * v1.1.0, capturada 22/09/2026) colada direto pelo usuário via Google
 * Docs; nunca confirmada contra a API real ainda (sandbox de dev bloqueia
 * acesso externo, mesma ressalva de toda integração nova deste projeto —
 * ver seção "A validar assim que subir em produção" do CLAUDE.md).
 *
 * Escopo desta 1ª versão, por pedido explícito ("por enquanto chamada
 * consulta simples"): só o serviço "Consulta Simples" (slug `consulta`,
 * o mais barato do catálogo). Os outros serviços (completa, veicular,
 * gravame, RENAJUD, débitos, FIPE via ZapCar etc) e o webhook (a doc
 * confirma "preferir ao polling em volume", mas o portal do cliente
 * mostrava "Nenhum webhook cadastrado" no momento desta implementação)
 * ficam pra uma próxima rodada, sem código morto criado agora à toa.
 *
 * Fluxo (assíncrono, regra de ouro #1 da doc): POST cria e debita ->
 * resultado só via GET com o id retornado. Aqui o "polling" é feito pelo
 * NAVEGADOR (admin/zapcar_ajax.php, ação `status`, chamada a cada poucos
 * segundos enquanto a tela estiver aberta) — nunca um cron/webhook nesta
 * 1ª versão; se o consultor fechar a aba antes de concluir, a consulta
 * local fica 'processando' e volta a ser repollada sozinha (JS) da
 * próxima vez que ele abrir a mesma oportunidade (zapcarUltimaConsultaDaOportunidade()).
 * Nunca repolla um id que já voltou concluido/erro (são TERMINAIS, regra
 * #3 da doc).
 *
 * Idempotency-Key sempre derivada do id local + tentativa (nunca
 * aleatória, regra #4 da doc) — protege contra duplo clique/reenvio
 * cobrando 2x a mesma consulta. Nesta versão cada clique em "Consultar"
 * cria uma linha local NOVA (nunca reaproveita id antigo pra tentar de
 * novo), então `tentativa` fica sempre 1 — um retry manual já satisfaz a
 * regra "consulta NOVA com Idempotency-Key NOVA" sem precisar de loop de
 * retry automático.
 *
 * Tri-estado (regra #7 da doc): false/[]/"NADA CONSTA" = verificado e
 * limpo; null/campo ausente = NÃO VERIFICADO — nunca mostrar "nada
 * consta" pra falta de informação. A tela (admin/oportunidade.php)
 * respeita isso ao renderizar.
 *
 * Preço nunca fixado em código (a própria doc avisa: "no CRM, o ideal é
 * sempre ler os preços da API — GET /v1/servicos — em vez de fixá-los no
 * código") — zapcarServicos() busca isso ao vivo (com cache curto), quem
 * mostra preço na tela lê de lá.
 */

require_once __DIR__ . '/db.php';

if (!defined('ZAPCAR_BASE_URL')) {
    define('ZAPCAR_BASE_URL', 'https://api.zapcarconsulta.com.br');
}

function zapcarApiKey(): string {
    return trim((string)(getConfig('zapcar_api_key') ?? ''));
}

function zapcarConfigured(): bool {
    return zapcarApiKey() !== '';
}

/**
 * Chamada crua autenticada — Authorization: Bearer <chave>. Nunca lança:
 * devolve [status_http, corpo_decodificado] mesmo em falha de rede
 * (status 0, codigo NETWORK_ERROR), quem chama decide o que fazer —
 * nunca programar pelo texto do erro, sempre por `codigo`/`erro_codigo`
 * (regra #6 da doc).
 */
function zapcarRequest(string $method, string $path, ?array $body = null, ?string $idempotencyKey = null): array {
    $chave = zapcarApiKey();
    if ($chave === '') {
        return [0, ['codigo' => 'SEM_CHAVE', 'erro' => 'Chave da API ZapCar não configurada.', 'retryable' => false]];
    }
    $headers = [
        'Authorization: Bearer ' . $chave,
        'Content-Type: application/json',
    ];
    if ($idempotencyKey !== null) {
        $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
    }
    try {
        $ch = curl_init(ZAPCAR_BASE_URL . $path);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => $headers,
        ];
        if ($method === 'POST') {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = json_encode($body ?? []);
        }
        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $erroCurl = curl_error($ch);
        curl_close($ch);
    } catch (Throwable $e) {
        return [0, ['codigo' => 'NETWORK_ERROR', 'erro' => $e->getMessage(), 'retryable' => true]];
    }

    if ($raw === false) {
        return [0, ['codigo' => 'NETWORK_ERROR', 'erro' => $erroCurl ?: 'falha de conexão', 'retryable' => true]];
    }
    $decodificado = json_decode($raw, true);
    return [$status, is_array($decodificado) ? $decodificado : ['erro' => 'resposta inválida', 'codigo' => 'RESPOSTA_INVALIDA', 'retryable' => false]];
}

/** GET /v1/servicos — catálogo + preço vigente da conta, grátis. Cache curto (1h) só pra não bater na API a cada carregamento de tela. */
function zapcarServicos(): ?array {
    $cacheKey = 'zapcar_servicos_cache';
    $cache = getConfig($cacheKey);
    if ($cache && str_contains($cache, '|')) {
        [$timestamp, $json] = explode('|', $cache, 2);
        $decodificado = json_decode($json, true);
        if (is_array($decodificado) && (time() - (int)$timestamp) < 3600) {
            return $decodificado;
        }
    }
    [$status, $body] = zapcarRequest('GET', '/v1/servicos');
    if ($status !== 200 || !is_array($body)) {
        return null;
    }
    setConfig($cacheKey, time() . '|' . json_encode($body));
    return $body;
}

/** GET /v1/saldo — { saldo: 465.01 } (reais), grátis. Nunca cacheado — saldo muda a cada consulta paga, mostrar velho seria enganoso. */
function zapcarSaldo(): ?float {
    [$status, $body] = zapcarRequest('GET', '/v1/saldo');
    if ($status !== 200 || !isset($body['saldo'])) {
        return null;
    }
    return (float)$body['saldo'];
}

/** Preço vigente do serviço "Consulta Simples" (slug `consulta`), lido do catálogo ao vivo — null se não achar/API fora do ar. */
function zapcarPrecoConsultaSimples(): ?float {
    $catalogo = zapcarServicos();
    $lista = $catalogo['servicos'] ?? $catalogo ?? [];
    if (!is_array($lista)) return null;
    foreach ($lista as $s) {
        $slug = (string)($s['slug'] ?? $s['servico'] ?? '');
        if ($slug === 'consulta' || $slug === 'consulta-simples') {
            return isset($s['preco']) ? (float)$s['preco'] : null;
        }
    }
    return null;
}

/** Só letras/números maiúsculos, formato Mercosul ou antigo (ex: ABC1D23, ABC1234) — nunca manda placa mal formatada, economiza a cobrança. */
function zapcarLimparPlaca(string $placa): string {
    $limpa = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $placa) ?? '');
    return preg_match('/^[A-Z]{3}\d[A-Z0-9]\d{2}$/', $limpa) ? $limpa : '';
}

/** POST /v1/consultas (servico=consulta). Debita, exceto 4xx/429 e replay de Idempotency-Key. */
function zapcarCriarConsultaSimples(string $placa, string $idempotencyKey): array {
    return zapcarRequest('POST', '/v1/consultas', [
        'servico' => 'consulta',
        'placa' => $placa,
    ], $idempotencyKey);
}

/** GET /v1/consultas/{id} — status + resultado quando concluido. Grátis. */
function zapcarBuscarConsultaRemota(string $zapcarId): array {
    return zapcarRequest('GET', '/v1/consultas/' . rawurlencode($zapcarId));
}

/** Linha local (zapcar_consultas) por id, ou null. */
function zapcarBuscarConsultaLocal(int $idLocal): ?array {
    $stmt = getDB()->prepare("SELECT * FROM zapcar_consultas WHERE id = ?");
    $stmt->execute([$idLocal]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Consulta local mais recente de uma oportunidade (qualquer placa) —
 * usada pra já mostrar o último resultado ao abrir a tela, sem precisar
 * consultar (e cobrar) de novo, e pro JS saber se precisa retomar o
 * polling de uma consulta que ainda estava 'processando' quando o
 * consultor fechou a aba.
 */
function zapcarUltimaConsultaDaOportunidade(int $oportunidadeId): ?array {
    $stmt = getDB()->prepare("SELECT * FROM zapcar_consultas WHERE oportunidade_id = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$oportunidadeId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Cria (paga) uma Consulta Simples pra placa informada e já grava o
 * resultado inicial localmente. Dedup: se já existe uma consulta
 * 'processando' pra essa MESMA placa+oportunidade, reaproveita o id em
 * vez de criar outra (evita cobrar 2x por duplo clique/reenvio — mesmo
 * padrão já usado em criarAvaliacao(), includes/veiculo_avaliacoes.php).
 *
 * Se a criação falhar (nunca cobra — 4xx/429/erro de rede), o rascunho
 * local é removido: nunca deixa uma linha "processando" órfã que nunca
 * vai concluir porque nem chegou a nascer na ZapCar de verdade.
 */
function zapcarIniciarConsultaSimples(int $oportunidadeId, string $placaBruta, int $usuarioId): array {
    if (!zapcarConfigured()) {
        return ['ok' => false, 'erro' => 'Chave da API ZapCar não configurada. Configure em Configurações → ZapCar.'];
    }
    $placa = zapcarLimparPlaca($placaBruta);
    if ($placa === '') {
        return ['ok' => false, 'erro' => 'Placa inválida.'];
    }

    $db = getDB();

    $stmt = $db->prepare("
        SELECT id FROM zapcar_consultas
        WHERE oportunidade_id = ? AND placa = ? AND status = 'processando'
        ORDER BY id DESC LIMIT 1
    ");
    $stmt->execute([$oportunidadeId, $placa]);
    $existenteId = $stmt->fetchColumn();
    if ($existenteId) {
        return ['ok' => true, 'id_local' => (int)$existenteId, 'reaproveitada' => true];
    }

    $db->prepare("
        INSERT INTO zapcar_consultas (oportunidade_id, servico, placa, status, tentativa, usuario_id, criado_em, atualizado_em)
        VALUES (?, 'consulta', ?, 'processando', 1, ?, datetime('now','localtime'), datetime('now','localtime'))
    ")->execute([$oportunidadeId, $placa, $usuarioId]);
    $idLocal = (int)$db->lastInsertId();

    $idempotencyKey = 'fastcar-cons-' . $idLocal . '-t1';
    $db->prepare("UPDATE zapcar_consultas SET idempotency_key = ? WHERE id = ?")->execute([$idempotencyKey, $idLocal]);

    [$status, $body] = zapcarCriarConsultaSimples($placa, $idempotencyKey);

    if ($status === 201 || $status === 200) {
        $zapcarId = (string)($body['id'] ?? '');
        if ($zapcarId === '') {
            $db->prepare("
                UPDATE zapcar_consultas SET status = 'erro', erro_codigo = 'RESPOSTA_SEM_ID',
                       erro_mensagem = 'A ZapCar não retornou o id da consulta.', atualizado_em = datetime('now','localtime')
                WHERE id = ?
            ")->execute([$idLocal]);
            return ['ok' => false, 'erro' => 'A ZapCar não retornou o id da consulta — tente de novo.'];
        }
        $db->prepare("
            UPDATE zapcar_consultas SET zapcar_id = ?, valor_cobrado = ?, atualizado_em = datetime('now','localtime')
            WHERE id = ?
        ")->execute([$zapcarId, $body['valor_cobrado'] ?? null, $idLocal]);
        return ['ok' => true, 'id_local' => $idLocal, 'reaproveitada' => !empty($body['reaproveitada'])];
    }

    // Criação falhou — nunca cobra (4xx/429/erro de rede), então remove o
    // rascunho local em vez de deixar uma linha "processando" órfã.
    $db->prepare("DELETE FROM zapcar_consultas WHERE id = ?")->execute([$idLocal]);

    if ($status === 402) {
        $saldo = isset($body['saldo']) ? number_format((float)$body['saldo'], 2, ',', '.') : '?';
        return ['ok' => false, 'erro' => "Saldo insuficiente na conta ZapCar (saldo atual: R$ {$saldo}). Recarregue pelo portal ZapCar."];
    }
    return ['ok' => false, 'erro' => (string)($body['erro'] ?? 'Falha ao criar a consulta na ZapCar.')];
}

/**
 * Repolla o status na ZapCar se a consulta local ainda estiver
 * 'processando' (nunca repolla concluido/erro — são TERMINAIS); grava o
 * resultado quando concluir/errar. Falha de rede no polling em si nunca
 * marca erro local — só mantém 'processando' pra tentar de novo no
 * próximo ciclo (GET de status é grátis e sem limite de tentativas).
 */
function zapcarAtualizarStatusLocal(int $idLocal): ?array {
    $row = zapcarBuscarConsultaLocal($idLocal);
    if (!$row) return null;
    if ($row['status'] !== 'processando' || !$row['zapcar_id']) {
        return $row;
    }

    [$status, $body] = zapcarBuscarConsultaRemota($row['zapcar_id']);
    if ($status !== 200) {
        return $row;
    }

    $statusRemoto = (string)($body['status'] ?? '');
    if ($statusRemoto === 'processando' || $statusRemoto === '') {
        return $row;
    }

    $db = getDB();
    if ($statusRemoto === 'concluido') {
        $db->prepare("
            UPDATE zapcar_consultas
            SET status = 'concluido', veiculo_json = ?, dados_json = ?, nao_verificado_json = ?,
                pdf_url = ?, concluido_em = datetime('now','localtime'), atualizado_em = datetime('now','localtime')
            WHERE id = ?
        ")->execute([
            isset($body['veiculo']) ? json_encode($body['veiculo']) : null,
            isset($body['dados']) ? json_encode($body['dados']) : null,
            isset($body['nao_verificado']) ? json_encode($body['nao_verificado']) : null,
            (string)($body['pdf_url'] ?? ''),
            $idLocal,
        ]);
    } elseif ($statusRemoto === 'erro') {
        $db->prepare("
            UPDATE zapcar_consultas
            SET status = 'erro', erro_codigo = ?, erro_mensagem = ?, retryable = ?, atualizado_em = datetime('now','localtime')
            WHERE id = ?
        ")->execute([
            (string)($body['erro_codigo'] ?? ''),
            (string)($body['erro'] ?? ''),
            !empty($body['retryable']) ? 1 : 0,
            $idLocal,
        ]);
    }
    // status remoto desconhecido (schema novo, minor version) — nunca
    // grava, mantém 'processando' pro próximo ciclo (regra #10 da doc:
    // ignorar campo desconhecido, não travar em cima de formato inesperado).

    return zapcarBuscarConsultaLocal($idLocal);
}

/** Formata uma linha local pra resposta AJAX (JSON decodificado, campos previsíveis pro JS). */
function zapcarFormatarRespostaAjax(?array $row): array {
    if (!$row) {
        return ['ok' => false, 'erro' => 'Consulta não encontrada.'];
    }
    return [
        'ok' => true,
        'id_local' => (int)$row['id'],
        'status' => $row['status'],
        'placa' => $row['placa'],
        'valor_cobrado' => $row['valor_cobrado'] !== null ? (float)$row['valor_cobrado'] : null,
        'veiculo' => $row['veiculo_json'] ? json_decode($row['veiculo_json'], true) : null,
        'nao_verificado' => $row['nao_verificado_json'] ? (json_decode($row['nao_verificado_json'], true) ?: []) : [],
        'pdf_url' => $row['pdf_url'] ?: null,
        'erro_codigo' => $row['erro_codigo'] ?: null,
        'erro_mensagem' => $row['erro_mensagem'] ?: null,
    ];
}
