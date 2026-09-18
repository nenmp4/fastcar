<?php
/**
 * Integração com a API do Asaas (17/09/2026, pedido José/Jean: "vamos
 * integrar api do assas pra puxar tudo de lá") — o Asaas já está em uso de
 * verdade pra cobrar cliente de venda parcelada (entrada + parcelas);
 * "puxar tudo de lá" = importar clientes + cobranças já existentes lá pro
 * financeiro do Fastcar, mantendo sincronizado depois (webhook + polling,
 * mesmo padrão já usado com a ZapSign pro contrato — ver
 * includes/zapsign.php/api/zapsign_webhook.php/cron/zapsign_sync.php).
 *
 * ⚠️ Construído a partir da documentação pública da API v3 (docs.asaas.com),
 * nunca confirmado contra uma conta/credencial real (mesma ressalva de todo
 * endpoint externo do projeto que ainda não foi testado em produção — ver
 * CLAUDE.md, seção "A validar assim que subir em produção"). Testado só
 * contra servidor fake local simulando os formatos documentados. Pontos
 * específicos a confirmar quando a API key chegar: nome exato dos campos
 * de status/parcela no payload de /payments, e se o cabeçalho de
 * autenticação do webhook (`asaas-access-token`) é mesmo esse nome.
 */

require_once __DIR__ . '/google_drive.php'; // só pelo padrão de erro/lastError, não usa Drive aqui

/** Base URL override via define() só em teste (fake server local) — mesmo padrão do resto do projeto. */
function asaasBaseUrl(): string {
    if (defined('ASAAS_BASE_URL')) return ASAAS_BASE_URL;
    return (getConfig('asaas_ambiente') === 'producao')
        ? 'https://api.asaas.com/v3'
        : 'https://api-sandbox.asaas.com/v3';
}

function asaasApiKey(): string {
    return getConfig('asaas_api_key') ?: '';
}

function asaasConfigured(): bool {
    return asaasApiKey() !== '';
}

/**
 * Faz uma chamada HTTP crua pra API do Asaas. Retorna sempre um array
 * ['ok'=>bool,'http'=>int,'dados'=>array,'erro'=>string] — nunca lança,
 * quem chama decide o que fazer com a falha (mesmo contrato de erro do
 * resto do projeto, ex: GoogleDrive::lastError).
 */
function asaasRequest(string $method, string $path, ?array $body = null): array {
    if (!asaasConfigured()) {
        return ['ok' => false, 'http' => 0, 'dados' => [], 'erro' => 'Chave da API Asaas não configurada.'];
    }

    $ch = curl_init(asaasBaseUrl() . $path);
    $headers = [
        'Content-Type: application/json',
        'access_token: ' . asaasApiKey(),
        'User-Agent: FastcarCRM/1.0',
    ];
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 20,
    ];
    if ($body !== null) $opts[CURLOPT_POSTFIELDS] = json_encode($body);
    curl_setopt_array($ch, $opts);

    $resposta = curl_exec($ch);
    $erroCurl = curl_error($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($resposta === false) {
        return ['ok' => false, 'http' => 0, 'dados' => [], 'erro' => $erroCurl ?: 'Falha de conexão com o Asaas.'];
    }

    $dados = json_decode($resposta, true);
    if (!is_array($dados)) $dados = [];

    if ($http < 200 || $http >= 300) {
        $msgErro = $dados['errors'][0]['description'] ?? ($dados['message'] ?? "HTTP {$http}");
        return ['ok' => false, 'http' => $http, 'dados' => $dados, 'erro' => $msgErro];
    }

    return ['ok' => true, 'http' => $http, 'dados' => $dados, 'erro' => ''];
}

/**
 * Testa a conexão (leitura simples, não gasta nada) — botão "Testar
 * conexão" em Configurações, mesmo padrão dos outros provedores.
 */
function asaasTestarConexao(): array {
    $r = asaasRequest('GET', '/customers?limit=1');
    if (!$r['ok']) return ['ok' => false, 'erro' => $r['erro']];
    $total = $r['dados']['totalCount'] ?? '?';
    return ['ok' => true, 'msg' => "Conexão ok — {$total} cliente(s) cadastrado(s) no Asaas."];
}

/** Mapeia o status de cobrança do Asaas pro conjunto de status do Fastcar (pendente/pago/atrasado/cancelado). */
function asaasStatusParaFin(string $statusAsaas): string {
    return match ($statusAsaas) {
        'RECEIVED', 'CONFIRMED', 'RECEIVED_IN_CASH' => 'pago',
        'OVERDUE' => 'atrasado',
        'REFUNDED', 'REFUND_REQUESTED', 'CHARGEBACK_REQUESTED', 'CHARGEBACK_DISPUTE', 'AWAITING_CHARGEBACK_REVERSAL' => 'cancelado',
        default => 'pendente', // PENDING, AWAITING_RISK_ANALYSIS, DUNNING_REQUESTED etc — ainda em aberto
    };
}

/**
 * Importa (INSERT OR UPDATE, cache) todos os clientes já cadastrados no
 * Asaas pra fin_asaas_clientes — nunca cria/edita cliente_id/venda_id
 * (vínculo com o CRM é sempre manual, ver nota no schema). Pagina em lotes
 * de 100 até acabar. Retorna contagem — nunca lança.
 */
function asaasImportarClientes(): array {
    if (!asaasConfigured()) return ['ok' => false, 'erro' => 'Chave da API Asaas não configurada.'];

    $db = getDB();
    $upsert = $db->prepare("
        INSERT INTO fin_asaas_clientes (asaas_id, nome, cpf_cnpj, email, telefone, updated_at)
        VALUES (?,?,?,?,?, datetime('now','localtime'))
        ON CONFLICT(asaas_id) DO UPDATE SET nome=excluded.nome, cpf_cnpj=excluded.cpf_cnpj, email=excluded.email, telefone=excluded.telefone, updated_at=excluded.updated_at
    ");

    $offset = 0;
    $total = 0;
    do {
        $r = asaasRequest('GET', "/customers?limit=100&offset={$offset}");
        if (!$r['ok']) return ['ok' => false, 'erro' => $r['erro'], 'importados' => $total];
        $lista = $r['dados']['data'] ?? [];
        foreach ($lista as $c) {
            $upsert->execute([
                (string)($c['id'] ?? ''),
                (string)($c['name'] ?? ''),
                (string)($c['cpfCnpj'] ?? ''),
                (string)($c['email'] ?? ''),
                (string)($c['mobilePhone'] ?? $c['phone'] ?? ''),
            ]);
            $total++;
        }
        $hasMore = !empty($r['dados']['hasMore']);
        $offset += 100;
    } while ($hasMore);

    return ['ok' => true, 'importados' => $total];
}

/**
 * Importa (dedup por asaas_payment_id) as cobranças do Asaas pra
 * fin_lancamentos — sempre tipo='receita' (dinheiro entrando, cobrança de
 * cliente), origem='asaas'. cliente_nome_manual sai preenchido com o nome
 * cacheado em fin_asaas_clientes (se já foi importado antes — senão fica
 * vazio, próxima importação de clientes preenche pra trás); cliente_id/
 * venda_id nunca são setados automaticamente aqui (vínculo é manual, ver
 * admin/financeiro-asaas.php).
 */
function asaasImportarCobrancas(): array {
    if (!asaasConfigured()) return ['ok' => false, 'erro' => 'Chave da API Asaas não configurada.'];

    $db = getDB();
    $buscarNomeCliente = $db->prepare("SELECT nome, cliente_id, venda_id FROM fin_asaas_clientes WHERE asaas_id = ?");

    // 18/09/2026, "isso que puxamos do assas são receitas de parcela dos
    // veiculos temos organizar" — cobrança nova importada já entra com a
    // categoria padrão configurada (Configurações → Asaas, config
    // `asaas_categoria_padrao_id`), fill-if-empty por natureza (só afeta o
    // INSERT de linha nova — o UPDATE abaixo nunca mexe em categoria_id,
    // então uma categoria trocada à mão depois nunca é sobrescrita).
    $categoriaPadraoId = (int)(getConfig('asaas_categoria_padrao_id') ?: 0) ?: null;

    $insert = $db->prepare("
        INSERT INTO fin_lancamentos
            (tipo, descricao, valor, data_vencimento, data_pagamento, status, forma_pagamento, cliente_nome_manual, cliente_id, venda_id, origem, asaas_payment_id, asaas_customer_id, parcela_numero, parcela_total, categoria_id)
        VALUES ('receita', ?, ?, ?, ?, ?, ?, ?, ?, ?, 'asaas', ?, ?, ?, ?, ?)
    ");
    $update = $db->prepare("
        UPDATE fin_lancamentos SET
            descricao=?, valor=?, data_vencimento=?, data_pagamento=?, status=?, forma_pagamento=?,
            parcela_numero=?, parcela_total=?, updated_at=datetime('now','localtime')
        WHERE asaas_payment_id=?
    ");
    $existe = $db->prepare("SELECT id FROM fin_lancamentos WHERE asaas_payment_id = ?");

    $offset = 0;
    $novos = 0;
    $atualizados = 0;
    do {
        $r = asaasRequest('GET', "/payments?limit=100&offset={$offset}");
        if (!$r['ok']) return ['ok' => false, 'erro' => $r['erro'], 'novos' => $novos, 'atualizados' => $atualizados];
        $lista = $r['dados']['data'] ?? [];
        foreach ($lista as $p) {
            $asaasId = (string)($p['id'] ?? '');
            if (!$asaasId) continue;
            $valor = (float)($p['value'] ?? 0);
            $vencimento = (string)($p['dueDate'] ?? '') ?: null;
            $pagamento = (string)($p['paymentDate'] ?? $p['clientPaymentDate'] ?? '') ?: null;
            $status = asaasStatusParaFin((string)($p['status'] ?? ''));
            $descricao = (string)($p['description'] ?? '') ?: "Cobrança Asaas #{$asaasId}";
            $forma = (string)($p['billingType'] ?? '');
            $parcelaNum = isset($p['installmentNumber']) ? (int)$p['installmentNumber'] : null;
            $parcelaTotal = isset($p['installmentCount']) ? (int)$p['installmentCount'] : null;

            $custId = (string)($p['customer'] ?? '');
            $buscarNomeCliente->execute([$custId]);
            $cliCache = $buscarNomeCliente->fetch(PDO::FETCH_ASSOC) ?: [];
            $nomeManual = (string)($cliCache['nome'] ?? '');
            $clienteId = $cliCache['cliente_id'] ?? null;
            $vendaId = $cliCache['venda_id'] ?? null;

            $existe->execute([$asaasId]);
            if ($existe->fetch()) {
                $update->execute([$descricao, $valor, $vencimento, $pagamento, $status, $forma, $parcelaNum, $parcelaTotal, $asaasId]);
                $atualizados++;
            } else {
                $insert->execute([$descricao, $valor, $vencimento, $pagamento, $status, $forma, $nomeManual, $clienteId, $vendaId, $asaasId, $custId ?: null, $parcelaNum, $parcelaTotal, $categoriaPadraoId]);
                $novos++;
            }
        }
        $hasMore = !empty($r['dados']['hasMore']);
        $offset += 100;
    } while ($hasMore);

    return ['ok' => true, 'novos' => $novos, 'atualizados' => $atualizados];
}

/**
 * Sincroniza (só releitura de status, nunca cria) as cobranças já
 * importadas que ainda não estão num status final — usado pelo cron de
 * polling (fallback caso o webhook não chegue, mesmo espírito do
 * cron/zapsign_sync.php). Reconsulta cada payment individualmente pelo id
 * (mais barato que reimportar tudo de novo quando só um punhado ainda está
 * em aberto).
 */
function asaasSincronizarPendentes(int $limite = 50): array {
    if (!asaasConfigured()) return ['ok' => false, 'erro' => 'Chave da API Asaas não configurada.'];

    $db = getDB();
    $pendentes = $db->query("
        SELECT id, asaas_payment_id FROM fin_lancamentos
        WHERE origem='asaas' AND status IN ('pendente','atrasado') AND asaas_payment_id IS NOT NULL
        LIMIT {$limite}
    ")->fetchAll(PDO::FETCH_ASSOC);

    $atualizados = 0;
    foreach ($pendentes as $l) {
        $r = asaasRequest('GET', '/payments/' . rawurlencode((string)$l['asaas_payment_id']));
        if (!$r['ok']) continue;
        $p = $r['dados'];
        $status = asaasStatusParaFin((string)($p['status'] ?? ''));
        $pagamento = (string)($p['paymentDate'] ?? $p['clientPaymentDate'] ?? '') ?: null;
        $db->prepare("UPDATE fin_lancamentos SET status=?, data_pagamento=?, updated_at=datetime('now','localtime') WHERE id=?")
           ->execute([$status, $pagamento, $l['id']]);
        $atualizados++;
    }
    return ['ok' => true, 'atualizados' => $atualizados];
}

/**
 * Atualiza um lançamento a partir de um payload de webhook do Asaas
 * (evento payment.* — {event, payment: {id, status, ...}}). Só mexe em
 * lançamentos que JÁ existem com esse asaas_payment_id (importados antes)
 * — um webhook de uma cobrança nunca vista antes é ignorado silenciosamente
 * aqui (próxima importação manual/cron traz ela pra dentro).
 */
function asaasProcessarWebhook(array $payload): array {
    $p = $payload['payment'] ?? null;
    if (!is_array($p) || empty($p['id'])) {
        return ['ok' => false, 'erro' => 'Payload sem payment.id'];
    }
    $db = getDB();
    $stmt = $db->prepare("SELECT id FROM fin_lancamentos WHERE asaas_payment_id = ?");
    $stmt->execute([(string)$p['id']]);
    if (!$stmt->fetch()) {
        return ['ok' => true, 'ignorado' => 'cobrança não importada ainda'];
    }
    $status = asaasStatusParaFin((string)($p['status'] ?? ''));
    $pagamento = (string)($p['paymentDate'] ?? $p['clientPaymentDate'] ?? '') ?: null;
    $db->prepare("UPDATE fin_lancamentos SET status=?, data_pagamento=?, updated_at=datetime('now','localtime') WHERE asaas_payment_id=?")
       ->execute([$status, $pagamento, (string)$p['id']]);
    return ['ok' => true, 'atualizado' => true];
}

/**
 * Cria (ou reaproveita, se já tem cache com esse CPF/CNPJ) o cliente no
 * Asaas — usado antes de gerar uma cobrança parcelada de verdade.
 */
function asaasCriarClienteSeNecessario(string $nome, string $cpfCnpj, string $telefone, string $email): ?string {
    $db = getDB();
    if ($cpfCnpj) {
        $stmt = $db->prepare("SELECT asaas_id FROM fin_asaas_clientes WHERE cpf_cnpj = ?");
        $stmt->execute([$cpfCnpj]);
        $existente = $stmt->fetchColumn();
        if ($existente) return (string)$existente;
    }

    $body = array_filter([
        'name' => $nome,
        'cpfCnpj' => $cpfCnpj ?: null,
        'mobilePhone' => $telefone ?: null,
        'email' => $email ?: null,
    ]);
    $r = asaasRequest('POST', '/customers', $body);
    if (!$r['ok'] || empty($r['dados']['id'])) return null;

    $asaasId = (string)$r['dados']['id'];
    $db->prepare("
        INSERT INTO fin_asaas_clientes (asaas_id, nome, cpf_cnpj, email, telefone)
        VALUES (?,?,?,?,?)
        ON CONFLICT(asaas_id) DO UPDATE SET nome=excluded.nome
    ")->execute([$asaasId, $nome, $cpfCnpj, $email, $telefone]);

    return $asaasId;
}

/**
 * Cria a cobrança parcelada de verdade no Asaas (entrada NÃO entra aqui —
 * Asaas parcela o SALDO; a entrada, se houver, é cobrada à parte ou
 * recebida por fora, e registrada como lançamento local 'manual'/
 * 'parcelamento_venda' — decisão de escopo pra não misturar os dois
 * conceitos). Cria 1 cobrança com installmentCount>1; o Asaas retorna a
 * 1ª parcela com um id de grupo em `installment` — busca as demais em
 * seguida. Cada parcela retornada vira 1 linha fin_lancamentos
 * (origem='asaas'), vinculada à venda.
 */
function asaasGerarCobrancaParceladaVenda(int $vendaId, string $asaasCustomerId, float $valorParcela, int $numParcelas, string $primeiraParcelaData, string $descricao): array {
    if (!asaasConfigured()) return ['ok' => false, 'erro' => 'Chave da API Asaas não configurada.'];
    if (finContarLancamentosVenda($vendaId) > 0) {
        return ['ok' => false, 'erro' => 'Esta venda já tem lançamentos financeiros gerados — não é possível gerar de novo.'];
    }

    $r = asaasRequest('POST', '/payments', [
        'customer' => $asaasCustomerId,
        'billingType' => 'UNDEFINED', // cliente escolhe (boleto/pix/cartão) na hora de pagar
        'value' => $valorParcela,
        'dueDate' => $primeiraParcelaData,
        'description' => $descricao,
        'installmentCount' => $numParcelas,
        'installmentValue' => $valorParcela,
    ]);
    if (!$r['ok']) return ['ok' => false, 'erro' => $r['erro']];

    $installmentGroupId = $r['dados']['installment'] ?? null;
    $pagamentos = [$r['dados']];
    if ($installmentGroupId) {
        $rLista = asaasRequest('GET', '/payments?installment=' . rawurlencode((string)$installmentGroupId));
        if ($rLista['ok'] && !empty($rLista['dados']['data'])) {
            $pagamentos = $rLista['dados']['data'];
        }
    }

    $db = getDB();
    $ins = $db->prepare("
        INSERT INTO fin_lancamentos
            (tipo, descricao, valor, data_vencimento, status, venda_id, parcela_numero, parcela_total, origem, asaas_payment_id, asaas_customer_id)
        VALUES ('receita', ?, ?, ?, 'pendente', ?, ?, ?, 'asaas', ?, ?)
    ");
    $criadas = 0;
    foreach ($pagamentos as $idx => $p) {
        $num = (int)($p['installmentNumber'] ?? ($idx + 1));
        $ins->execute([
            (string)($p['description'] ?? $descricao),
            (float)($p['value'] ?? $valorParcela),
            (string)($p['dueDate'] ?? ''),
            $vendaId,
            $num,
            $numParcelas,
            (string)($p['id'] ?? ''),
            $asaasCustomerId,
        ]);
        $criadas++;
    }

    return ['ok' => true, 'criadas' => $criadas];
}
