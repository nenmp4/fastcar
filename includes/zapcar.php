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
 * Escopo desta versão: serviço padrão "Consulta Veicular" (slug
 * `consulta-veicular`, R$24,99 — proprietário, restrições, gravame e
 * leilão pela placa), trocado de "Consulta Simples" (slug `consulta`) em
 * 22/09/2026, mesmo dia, pedido direto: "ver documentação consulta caiu
 * 404 no link no resultado vamos mudar para puxar Consulta Veicular —
 * Proprietário, restrições, gravame e leilão pela placa" (o link do PDF
 * de uma Consulta Simples real deu 404 — ver zapcarBaixarPdf() abaixo,
 * causa raiz era confiar num `pdf_url` cru devolvido pela API em vez de
 * sempre buscar via GET /v1/consultas/{id}/pdf autenticado).
 *
 * **Tipo de consulta agora é configurável** (22/09/2026, "da para deixar
 * uma chave escolher tipo de consulta api mais em configurações") — em
 * vez de travado só em "Consulta Veicular" no código, `admin/configuracoes.php`
 * mostra um seletor com TODO serviço que aparecer no catálogo ao vivo
 * (`GET /v1/servicos`, mesmo endpoint que já lê preço), salvo em
 * `config.zapcar_servico_slug`; `zapcarServicoAtivo()` lê esse valor com
 * fallback pro padrão (`consulta-veicular`) se nunca configurado. Qualquer
 * serviço do catálogo funciona sem mudança de código (`zapcarCriarConsulta()`
 * já manda o slug dinâmico no `POST /v1/consultas`) — só a Consulta Veicular
 * foi testada de ponta a ponta até aqui, mas o mecanismo de criação/polling/
 * aplicação na oportunidade é genérico por natureza, nunca hardcoded pro
 * formato específico dela (`zapcarAplicarNaOportunidade()` só lê campos que
 * PODEM não vir em serviços mais simples — tudo com fallback gracioso já
 * existente pro tri-estado). O webhook (a doc confirma "preferir ao polling
 * em volume", mas o portal do cliente mostrava "Nenhum webhook cadastrado"
 * no momento desta implementação) fica pra uma próxima rodada, sem código
 * morto criado agora à toa.
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
if (!defined('ZAPCAR_SERVICO_PADRAO')) {
    define('ZAPCAR_SERVICO_PADRAO', 'consulta-veicular');
}

function zapcarApiKey(): string {
    return trim((string)(getConfig('zapcar_api_key') ?? ''));
}

function zapcarConfigured(): bool {
    return zapcarApiKey() !== '';
}

/** Slug do serviço escolhido em Configurações (config.zapcar_servico_slug) — fallback pro padrão se nunca configurado. */
function zapcarServicoAtivo(): string {
    $slug = trim((string)(getConfig('zapcar_servico_slug') ?? ''));
    return $slug !== '' ? $slug : ZAPCAR_SERVICO_PADRAO;
}

/** Nome legível de um serviço, lido do catálogo ao vivo (zapcarServicos()) — cai no próprio slug se não achar/catálogo indisponível. */
function zapcarNomeServico(string $slug): string {
    $catalogo = zapcarServicos();
    $lista = $catalogo['servicos'] ?? $catalogo ?? [];
    if (is_array($lista)) {
        foreach ($lista as $s) {
            if (!is_array($s)) continue;
            $slugAtual = (string)($s['slug'] ?? $s['servico'] ?? '');
            if ($slugAtual === $slug) {
                return (string)($s['nome'] ?? $s['descricao'] ?? $slug);
            }
        }
    }
    return $slug;
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

/** Preço vigente de um serviço (slug), lido do catálogo ao vivo — null se não achar/API fora do ar. */
function zapcarPrecoServico(string $slug): ?float {
    $catalogo = zapcarServicos();
    $lista = $catalogo['servicos'] ?? $catalogo ?? [];
    if (!is_array($lista)) return null;
    foreach ($lista as $s) {
        if (!is_array($s)) continue;
        $slugAtual = (string)($s['slug'] ?? $s['servico'] ?? '');
        if ($slugAtual === $slug) {
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

/** POST /v1/consultas (servico dinâmico, ver zapcarServicoAtivo()). Debita, exceto 4xx/429 e replay de Idempotency-Key. */
function zapcarCriarConsulta(string $placa, string $idempotencyKey, string $servico): array {
    return zapcarRequest('POST', '/v1/consultas', [
        'servico' => $servico,
        'placa' => $placa,
    ], $idempotencyKey);
}

/** GET /v1/consultas/{id} — status + resultado quando concluido. Grátis. */
function zapcarBuscarConsultaRemota(string $zapcarId): array {
    return zapcarRequest('GET', '/v1/consultas/' . rawurlencode($zapcarId));
}

/**
 * GET /v1/consultas/{id}/pdf — baixa o documento (PDF ou imagem) de uma
 * consulta CONCLUÍDA, autenticado. 22/09/2026, achado real: o `pdf_url`
 * que a API devolve dentro de GET /v1/consultas/{id} deu 404 quando
 * clicado direto no navegador (provável URL que exige o header
 * Authorization, que um <a href> comum nunca manda) — por isso o PDF
 * SEMPRE é buscado por aqui, no servidor (com a chave), e o navegador só
 * recebe os bytes já prontos via admin/zapcar_pdf.php, nunca o pdf_url
 * cru da API. Regra #11 da doc: confiar no Content-Type da resposta, não
 * em extensão (pode vir image/png/image/jpeg em vez de PDF de verdade).
 * Antes de concluir, a API responde 400 PDF_NOT_READY (retryable) —
 * nunca deveria acontecer aqui, já que só chamamos pra status='concluido'.
 */
function zapcarBaixarPdf(string $zapcarId): array {
    $chave = zapcarApiKey();
    if ($chave === '') {
        return ['ok' => false, 'erro' => 'Chave da API ZapCar não configurada.'];
    }
    try {
        $ch = curl_init(ZAPCAR_BASE_URL . '/v1/consultas/' . rawurlencode($zapcarId) . '/pdf');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $chave],
        ]);
        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $erroCurl = curl_error($ch);
        curl_close($ch);
    } catch (Throwable $e) {
        return ['ok' => false, 'erro' => $e->getMessage()];
    }

    if ($raw === false) {
        return ['ok' => false, 'erro' => $erroCurl ?: 'falha de conexão'];
    }
    $headerBruto = substr($raw, 0, $headerSize);
    $corpo = substr($raw, $headerSize);

    if ($status !== 200) {
        $decodificado = json_decode($corpo, true);
        return ['ok' => false, 'erro' => (string)($decodificado['erro'] ?? "HTTP {$status} ao buscar o PDF."), 'status' => $status];
    }

    $contentType = 'application/pdf';
    if (preg_match('/^Content-Type:\s*(.+?)\s*$/mi', $headerBruto, $m)) {
        $contentType = trim($m[1]);
    }
    return ['ok' => true, 'content_type' => $contentType, 'bytes' => $corpo];
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
 * Cria (paga) uma consulta pra placa informada — serviço é sempre o
 * escolhido em Configurações (zapcarServicoAtivo()) — e já grava o
 * resultado inicial localmente. Dedup: se já existe uma consulta
 * 'processando' pra essa MESMA placa+oportunidade+serviço, reaproveita o
 * id em vez de criar outra (evita cobrar 2x por duplo clique/reenvio —
 * mesmo padrão já usado em criarAvaliacao(), includes/veiculo_avaliacoes.php).
 * O dedup inclui o serviço de propósito: trocar o tipo de consulta em
 * Configurações enquanto uma consulta do tipo ANTIGO ainda está
 * 'processando' nunca reaproveita ela por engano pra um serviço diferente.
 *
 * Se a criação falhar (nunca cobra — 4xx/429/erro de rede), o rascunho
 * local é removido: nunca deixa uma linha "processando" órfã que nunca
 * vai concluir porque nem chegou a nascer na ZapCar de verdade.
 */
function zapcarIniciarConsulta(int $oportunidadeId, string $placaBruta, int $usuarioId): array {
    if (!zapcarConfigured()) {
        return ['ok' => false, 'erro' => 'Chave da API ZapCar não configurada. Configure em Configurações → ZapCar.'];
    }
    $placa = zapcarLimparPlaca($placaBruta);
    if ($placa === '') {
        return ['ok' => false, 'erro' => 'Placa inválida.'];
    }
    $servico = zapcarServicoAtivo();

    $db = getDB();

    $stmt = $db->prepare("
        SELECT id FROM zapcar_consultas
        WHERE oportunidade_id = ? AND placa = ? AND servico = ? AND status = 'processando'
        ORDER BY id DESC LIMIT 1
    ");
    $stmt->execute([$oportunidadeId, $placa, $servico]);
    $existenteId = $stmt->fetchColumn();
    if ($existenteId) {
        return ['ok' => true, 'id_local' => (int)$existenteId, 'reaproveitada' => true];
    }

    $db->prepare("
        INSERT INTO zapcar_consultas (oportunidade_id, servico, placa, status, tentativa, usuario_id, criado_em, atualizado_em)
        VALUES (?, ?, ?, 'processando', 1, ?, datetime('now','localtime'), datetime('now','localtime'))
    ")->execute([$oportunidadeId, $servico, $placa, $usuarioId]);
    $idLocal = (int)$db->lastInsertId();

    $idempotencyKey = 'fastcar-cons-' . $idLocal . '-t1';
    $db->prepare("UPDATE zapcar_consultas SET idempotency_key = ? WHERE id = ?")->execute([$idempotencyKey, $idLocal]);

    [$status, $body] = zapcarCriarConsulta($placa, $idempotencyKey, $servico);

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
        // 22/09/2026, "vamos preencher tudo... oportunidade para consultor
        // ter poder negociação" — best-effort (nunca lança): uma falha aqui
        // não pode derrubar a atualização do status que já foi salva acima.
        if (isset($body['veiculo']) && is_array($body['veiculo'])) {
            try {
                zapcarAplicarNaOportunidade((int)$row['oportunidade_id'], $body['veiculo'], zapcarNomeServico((string)$row['servico']));
            } catch (Throwable $e) {
                // segue sem aplicar — a consulta em si já está salva e
                // visível no card, só não propagou pros campos/resumo.
            }
        }
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

/** true/false/null -> texto (mesmo tri-estado da tela: nunca "nada consta" pra null). */
function zapcarTriTexto($valor, string $simTexto, string $naoTexto): string {
    if ($valor === true) return $simTexto;
    if ($valor === false) return $naoTexto;
    return 'não verificado';
}

/**
 * Monta um texto legível com TUDO que a consulta trouxe (situação, recall,
 * sinistro, leilão, restrições, débitos, proprietário, último
 * licenciamento) — pensado pra ser lido de cima a baixo pelo consultor na
 * hora de negociar, sem precisar abrir o JSON cru. $servicoNome é só pro
 * cabeçalho (qual tipo de consulta gerou isso — o tipo é configurável em
 * Configurações, ver zapcarServicoAtivo()).
 */
function zapcarResumoTexto(array $veiculo, string $servicoNome = 'Consulta Veicular'): string {
    $linhas = ['Consulta ZapCar (' . $servicoNome . ') — ' . date('d/m/Y H:i') . ':'];

    $ident = trim(($veiculo['marca'] ?? '') . ' ' . ($veiculo['modelo'] ?? ''));
    if ($ident !== '') {
        $linhas[] = '- Veículo: ' . $ident
            . (!empty($veiculo['ano_modelo']) ? ' (' . $veiculo['ano_modelo'] . ')' : '')
            . (!empty($veiculo['cor']) ? ', cor ' . $veiculo['cor'] : '')
            . (!empty($veiculo['combustivel']) ? ', ' . $veiculo['combustivel'] : '');
    }
    if (!empty($veiculo['categoria']) || !empty($veiculo['especie'])) {
        $linhas[] = '- Categoria/espécie: ' . trim(($veiculo['categoria'] ?? '') . ' / ' . ($veiculo['especie'] ?? ''), ' /');
    }
    if (!empty($veiculo['municipio']) || !empty($veiculo['uf'])) {
        $linhas[] = '- Município/UF de registro: ' . trim(($veiculo['municipio'] ?? '') . '/' . ($veiculo['uf'] ?? ''), '/');
    }
    $linhas[] = '- Situação: ' . (($veiculo['situacao'] ?? '') ?: 'não informada')
        . (($veiculo['baixado'] ?? null) === true ? ' — 🚫 BAIXADO' : '');
    $linhas[] = '- Recall: ' . zapcarTriTexto($veiculo['recall'] ?? null, 'SIM', 'não consta');
    $linhas[] = '- Sinistro: ' . zapcarTriTexto($veiculo['sinistro'] ?? null, 'SIM', 'não consta');

    if (isset($veiculo['leilao']) && is_array($veiculo['leilao'])) {
        $l = $veiculo['leilao'];
        $linhas[] = '- Leilão: ' . zapcarTriTexto(
            $l['consta'] ?? null,
            ($l['ocorrencias'] ?? 0) . ' ocorrência(s), ' . ($l['fotos'] ?? 0) . ' foto(s)',
            'não consta'
        );
    } else {
        $linhas[] = '- Leilão: não verificado';
    }

    $restricoes = is_array($veiculo['restricoes'] ?? null) ? $veiculo['restricoes'] : [];
    if ($restricoes) {
        $linhas[] = '- Restrições:';
        foreach ($restricoes as $r) {
            if (!is_array($r)) continue;
            $status = zapcarTriTexto($r['ativa'] ?? null, 'ATIVA' . (!empty($r['descricao']) ? ' — ' . $r['descricao'] : ''), 'inativa');
            $linhas[] = '  · ' . ($r['tipo'] ?? '?') . ': ' . $status;
        }
    }

    $debitos = is_array($veiculo['debitos'] ?? null) ? $veiculo['debitos'] : [];
    if ($debitos) {
        $linhas[] = '- Débitos:';
        foreach ($debitos as $d) {
            if (!is_array($d)) continue;
            $valorTxt = (($d['valor_informado'] ?? null) === false)
                ? 'valor não informado'
                : 'R$ ' . number_format(((float)($d['valor_centavos'] ?? 0)) / 100, 2, ',', '.');
            $linhas[] = '  · ' . ($d['tipo'] ?? 'OUTRO') . (!empty($d['descricao']) ? ' (' . $d['descricao'] . ')' : '') . ': ' . $valorTxt;
        }
        $linhas[] = '  Total: R$ ' . number_format(((float)($veiculo['debitos_total_centavos'] ?? 0)) / 100, 2, ',', '.');
    }

    if (!empty($veiculo['proprietario']['nome'])) {
        $linhas[] = '- Proprietário no CRLV: ' . $veiculo['proprietario']['nome']
            . (!empty($veiculo['proprietario']['documento']) ? ' — ' . $veiculo['proprietario']['documento'] : '');
    }
    if (!empty($veiculo['ultimo_licenciamento'])) {
        $linhas[] = '- Último licenciamento: ' . $veiculo['ultimo_licenciamento'];
    }

    return implode("\n", $linhas);
}

/**
 * Aplica o resultado de uma Consulta Veicular CONCLUÍDA na própria
 * oportunidade de compra — 22/09/2026, "vamos preencher tudo... para
 * consultor ter poder negociação". Duas disciplinas diferentes, de
 * propósito:
 *  - Campos de identificação (marca/modelo/ano/placa/renavam/chassi) e os
 *    3 débitos (IPVA/licenciamento/multas, somados por tipo) são
 *    FILL-IF-EMPTY — nunca sobrescrevem o que o consultor já confirmou
 *    com o vendedor (regra do resto do projeto).
 *  - `zapcar_resumo_texto`/`zapcar_consultado_em` sempre SOBRESCREVEM —
 *    é sempre o retrato mais recente da fonte oficial, não um dado
 *    "confirmado" por humano; o histórico completo de toda consulta já
 *    feita continua intacto em `zapcar_consultas`, nunca é perdido.
 */
function zapcarAplicarNaOportunidade(int $oportunidadeId, array $veiculo, string $servicoNome = 'Consulta Veicular'): void {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT veiculo_marca, veiculo_modelo, veiculo_ano, veiculo_placa, veiculo_renavam, veiculo_chassi,
               debito_ipva, debito_licenciamento, debito_multas
        FROM oportunidades WHERE id = ?
    ");
    $stmt->execute([$oportunidadeId]);
    $atual = $stmt->fetch();
    if (!$atual) return;

    $sets = [];
    $params = [];

    $mapaIdentificacao = [
        'veiculo_marca'   => $veiculo['marca'] ?? null,
        'veiculo_modelo'  => $veiculo['modelo'] ?? null,
        'veiculo_ano'     => $veiculo['ano_modelo'] ?? ($veiculo['ano_fabricacao'] ?? null),
        'veiculo_placa'   => $veiculo['placa'] ?? null,
        'veiculo_renavam' => $veiculo['renavam'] ?? null,
        'veiculo_chassi'  => $veiculo['chassi'] ?? null,
    ];
    foreach ($mapaIdentificacao as $coluna => $valor) {
        if ($valor !== null && $valor !== '' && empty($atual[$coluna])) {
            $sets[] = "{$coluna} = ?";
            $params[] = (string)$valor;
        }
    }

    $somaPorTipo = ['IPVA' => 0.0, 'LICENCIAMENTO' => 0.0, 'MULTA' => 0.0];
    foreach ((is_array($veiculo['debitos'] ?? null) ? $veiculo['debitos'] : []) as $d) {
        if (!is_array($d)) continue;
        $tipo = strtoupper((string)($d['tipo'] ?? ''));
        if (isset($somaPorTipo[$tipo]) && ($d['valor_informado'] ?? null) === true && isset($d['valor_centavos'])) {
            $somaPorTipo[$tipo] += ((float)$d['valor_centavos']) / 100;
        }
    }
    $mapaDebito = ['debito_ipva' => 'IPVA', 'debito_licenciamento' => 'LICENCIAMENTO', 'debito_multas' => 'MULTA'];
    foreach ($mapaDebito as $coluna => $tipo) {
        if ($somaPorTipo[$tipo] > 0 && $atual[$coluna] === null) {
            $sets[] = "{$coluna} = ?";
            $params[] = $somaPorTipo[$tipo];
        }
    }

    $sets[] = "zapcar_resumo_texto = ?";
    $params[] = zapcarResumoTexto($veiculo, $servicoNome);
    $sets[] = "zapcar_consultado_em = datetime('now','localtime')";

    $params[] = $oportunidadeId;
    $db->prepare("UPDATE oportunidades SET " . implode(', ', $sets) . " WHERE id = ?")->execute($params);
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
        'servico' => $row['servico'],
        'servico_nome' => zapcarNomeServico((string)$row['servico']),
        'valor_cobrado' => $row['valor_cobrado'] !== null ? (float)$row['valor_cobrado'] : null,
        'veiculo' => $row['veiculo_json'] ? json_decode($row['veiculo_json'], true) : null,
        'nao_verificado' => $row['nao_verificado_json'] ? (json_decode($row['nao_verificado_json'], true) ?: []) : [],
        'pdf_url' => $row['pdf_url'] ?: null,
        'erro_codigo' => $row['erro_codigo'] ?: null,
        'erro_mensagem' => $row['erro_mensagem'] ?: null,
    ];
}
