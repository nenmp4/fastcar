<?php
/**
 * Meta Marketing API — gasto por anúncio, pra calcular Custo por Lead
 * (CPL) das campanhas Meta (29/09/2026, spec trazida pelo usuário via
 * Google Docs: "registrar no CRM de qual anúncio veio cada lead e quanto
 * cada campanha/anúncio gastou").
 *
 * Token PRECISA da permissão `ads_read` (além de
 * whatsapp_business_messaging/whatsapp_business_management, que o token
 * da API oficial já usa) — pode ser o MESMO token de
 * `whatsapp_oficial_access_token` se gerado já com os 2 escopos juntos,
 * ou um token próprio em `meta_ads_token` (campo separado, nunca
 * misturado por padrão — decisão de deixar explícito qual token faz o
 * quê, já que "ads_read" é um escopo a mais que pode não ter sido
 * concedido na 1ª geração do token do WhatsApp).
 *
 * Nunca confirmado contra a API real ainda — construído a partir da
 * documentação pública da Marketing API (Graph API v21.0), mesma
 * ressalva de todo provedor novo do projeto (CLAUDE.md → "a validar em
 * produção").
 */

require_once __DIR__ . '/db.php';

function metaAdsBaseUrl(): string {
    return defined('META_ADS_BASE_URL') ? META_ADS_BASE_URL : 'https://graph.facebook.com/v21.0';
}

function metaAdsCredenciais(): array {
    return [
        getConfig('meta_ads_token') ?: '',
        getConfig('meta_ad_accounts') ?: '',
    ];
}

function metaAdsConfigured(): bool {
    [$token, $contas] = metaAdsCredenciais();
    return $token !== '' && $contas !== '';
}

/** Lista de act_XXXXXXXXXX (config meta_ad_accounts, separadas por vírgula). */
function metaAdsContas(): array {
    [, $contas] = metaAdsCredenciais();
    return array_values(array_filter(array_map('trim', explode(',', $contas))));
}

/**
 * GET genérico contra a Graph API. Retorna sempre
 * ['ok'=>bool,'http'=>int,'dados'=>array,'erro'=>string,'erro_code'=>?int] —
 * mesmo formato de includes/asaas.php::asaasRequest(), pra quem chama
 * (cron/meta_insights.php) decidir retry/backoff pelo erro_code (17/80004
 * = rate limit, 190 = token inválido) sem parsear string de erro.
 */
function metaAdsRequest(string $path, array $query = []): array {
    [$token] = metaAdsCredenciais();
    if (!$token) {
        return ['ok' => false, 'http' => 0, 'dados' => [], 'erro' => 'Token da Meta Ads não configurado.', 'erro_code' => null];
    }
    $query['access_token'] = $token;
    $url = metaAdsBaseUrl() . $path . '?' . http_build_query($query);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
    ]);
    $resp = curl_exec($ch);
    $curlErr = curl_error($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($resp === false) {
        return ['ok' => false, 'http' => 0, 'dados' => [], 'erro' => $curlErr ?: 'Falha de conexão com a Meta.', 'erro_code' => null];
    }
    $dados = json_decode($resp, true);
    if (!is_array($dados)) $dados = [];

    if ($http < 200 || $http >= 300 || isset($dados['error'])) {
        $erroMsg = $dados['error']['message'] ?? "HTTP {$http}";
        $erroCode = $dados['error']['code'] ?? null;
        return ['ok' => false, 'http' => $http, 'dados' => $dados, 'erro' => $erroMsg, 'erro_code' => $erroCode !== null ? (int)$erroCode : null];
    }
    return ['ok' => true, 'http' => $http, 'dados' => $dados, 'erro' => '', 'erro_code' => null];
}

/**
 * Testa a conexão — GET /{ad_account}?fields=name,account_status na
 * PRIMEIRA conta configurada (leitura simples, sem gastar nada, mesmo
 * espírito de GoogleDrive::testarConexao()/oficialTestarConexao()).
 */
function metaAdsTestarConexao(): array {
    $contas = metaAdsContas();
    if (!$contas) return ['ok' => false, 'erro' => 'Configure pelo menos 1 conta de anúncios (meta_ad_accounts).'];
    $conta = $contas[0];
    $r = metaAdsRequest("/{$conta}", ['fields' => 'name,account_status']);
    if (!$r['ok']) return ['ok' => false, 'erro' => $r['erro']];
    if (getConfig('meta_ads_ultimo_erro')) {
        setConfig('meta_ads_ultimo_erro', ''); // teste manual confirmou que voltou a funcionar
    }
    $nome = $r['dados']['name'] ?? $conta;
    return ['ok' => true, 'msg' => "Meta respondeu: conta \"{$nome}\" ({$conta}) acessível — conexão funcionando."];
}

/**
 * Busca insights (gasto/impressões/cliques/conversas) nível=ad de 1 conta
 * de anúncios, no intervalo [$desde,$ate] (YYYY-MM-DD), seguindo
 * paging.next até acabar. Nunca lança — retorna
 * ['ok'=>bool,'linhas'=>array,'erro'=>string,'erro_code'=>?int].
 *
 * `onsite_conversion.messaging_conversation_started_7d` dentro de
 * `actions[]` é a contagem de conversas SEGUNDO A META — guardado em
 * `conversas_meta` pra comparar contra os leads contados no CRM
 * (lead_origem_anuncio), nunca usado como fonte de verdade sozinho.
 */
function metaAdsBuscarInsights(string $contaId, string $desde, string $ate): array {
    $linhas = [];
    $r = metaAdsRequest("/{$contaId}/insights", [
        'level' => 'ad',
        'time_increment' => 1,
        'time_range' => json_encode(['since' => $desde, 'until' => $ate]),
        'fields' => 'date_start,campaign_id,campaign_name,adset_id,adset_name,ad_id,ad_name,spend,impressions,clicks,actions',
        'limit' => 500,
    ]);

    $paginas = 0;
    while (true) {
        if (!$r['ok']) {
            return ['ok' => false, 'linhas' => $linhas, 'erro' => $r['erro'], 'erro_code' => $r['erro_code']];
        }
        foreach (($r['dados']['data'] ?? []) as $item) {
            $linhas[] = _metaAdsMapearLinhaInsight($item, $desde);
        }

        $next = $r['dados']['paging']['next'] ?? null;
        $paginas++;
        // rede de segurança — nunca deveria passar de umas poucas páginas
        // de 500 linhas/dia numa conta só; evita loop infinito se
        // `paging.next` vier mal formado.
        if (!$next || $paginas > 50) break;

        // paging.next já vem como URL absoluta completa (inclusive
        // access_token) — metaAdsRequest() sempre concatena
        // metaAdsBaseUrl().$path, então seguir o `next` precisa de uma
        // chamada GET direta em vez de remontar path+query.
        $r = _metaAdsSeguirPaginaAbsoluta($next);
    }

    return ['ok' => true, 'linhas' => $linhas, 'erro' => '', 'erro_code' => null];
}

/** Extrai 1 linha de insight (com conversas_meta calculado a partir de actions[]) do formato cru da API. */
function _metaAdsMapearLinhaInsight(array $item, string $dataFallback): array {
    $conversas = 0;
    foreach (($item['actions'] ?? []) as $acao) {
        if (($acao['action_type'] ?? '') === 'onsite_conversion.messaging_conversation_started_7d') {
            $conversas = (int)($acao['value'] ?? 0);
        }
    }
    return [
        'data' => (string)($item['date_start'] ?? $dataFallback),
        'campaign_id' => (string)($item['campaign_id'] ?? ''),
        'campaign_name' => (string)($item['campaign_name'] ?? ''),
        'adset_id' => (string)($item['adset_id'] ?? ''),
        'adset_name' => (string)($item['adset_name'] ?? ''),
        'ad_id' => (string)($item['ad_id'] ?? ''),
        'ad_name' => (string)($item['ad_name'] ?? ''),
        'spend' => (float)($item['spend'] ?? 0),
        'impressions' => (int)($item['impressions'] ?? 0),
        'clicks' => (int)($item['clicks'] ?? 0),
        'conversas_meta' => $conversas,
    ];
}

/** GET numa URL absoluta (usado só pra seguir paging.next, que já vem completo) — mesmo formato de retorno de metaAdsRequest(). */
function _metaAdsSeguirPaginaAbsoluta(string $url): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30]);
    $resp = curl_exec($ch);
    $curlErr = curl_error($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($resp === false) {
        return ['ok' => false, 'http' => 0, 'dados' => [], 'erro' => $curlErr ?: 'Falha de conexão com a Meta.', 'erro_code' => null];
    }
    $dados = json_decode($resp, true);
    if (!is_array($dados)) $dados = [];
    if ($http < 200 || $http >= 300 || isset($dados['error'])) {
        $erroMsg = $dados['error']['message'] ?? "HTTP {$http}";
        $erroCode = $dados['error']['code'] ?? null;
        return ['ok' => false, 'http' => $http, 'dados' => $dados, 'erro' => $erroMsg, 'erro_code' => $erroCode !== null ? (int)$erroCode : null];
    }
    return ['ok' => true, 'http' => $http, 'dados' => $dados, 'erro' => '', 'erro_code' => null];
}

/** UPSERT em anuncio_gasto_diario, PRIMARY KEY (data, ad_id) — mesmo padrão ON CONFLICT do resto do projeto. */
function metaAdsSalvarGastoDiario(string $contaId, array $linha): void {
    $db = getDB();
    $db->prepare("
        INSERT INTO anuncio_gasto_diario
            (data, ad_account_id, campaign_id, campaign_name, adset_id, adset_name, ad_id, ad_name, spend, impressions, clicks, conversas_meta, atualizado_em)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON CONFLICT(data, ad_id) DO UPDATE SET
            ad_account_id = excluded.ad_account_id,
            campaign_id = excluded.campaign_id,
            campaign_name = excluded.campaign_name,
            adset_id = excluded.adset_id,
            adset_name = excluded.adset_name,
            ad_name = excluded.ad_name,
            spend = excluded.spend,
            impressions = excluded.impressions,
            clicks = excluded.clicks,
            conversas_meta = excluded.conversas_meta,
            atualizado_em = excluded.atualizado_em
    ")->execute([
        $linha['data'], $contaId, $linha['campaign_id'], $linha['campaign_name'],
        $linha['adset_id'], $linha['adset_name'], $linha['ad_id'], $linha['ad_name'],
        $linha['spend'], $linha['impressions'], $linha['clicks'], $linha['conversas_meta'],
        date('Y-m-d H:i:s'),
    ]);
}

/**
 * Monta o relatório de CPL (admin/relatorio_cpl.php) — hierarquia
 * Campanha → Conjunto → Anúncio com gasto/conversas/leads/CPL/vendas/
 * custo por venda/conversão, mais a linha "Sem atribuição".
 *
 * Cálculo feito em PHP (não SQL aninhado) de propósito — 2 queries
 * simples (gasto por ad_id, atribuição de lead por ad_id) e a agregação
 * hierárquica/cálculo de CPL em cima disso, mais fácil de ler/testar do
 * que um JOIN de 3 níveis com CTE. `lead_origem_anuncio` é a fonte de
 * verdade de atribuição (todo clique, não só first/last-touch resumido
 * em clientes/vendas) — 1 lead que clicou 2x no MESMO ad_id conta 1 vez
 * (DISTINCT por cliente_id/venda_id), mas se clicou em ads de campanhas
 * DIFERENTES conta em cada uma (mesmo espírito multi-touch do spec
 * original, "COUNT(DISTINCT o.lead_id)" agrupado por campanha).
 *
 * $de/$ate: 'YYYY-MM-DD'. $contaFiltro/$campanhaFiltro: '' = sem filtro.
 */
function metaAdsRelatorioCpl(string $de, string $ate, string $contaFiltro = '', string $campanhaFiltro = ''): array {
    $db = getDB();

    // 1. Gasto por ad_id no período (+ filtro de conta/campanha)
    $sqlGasto = "
        SELECT ad_id, ad_account_id,
               MAX(campaign_id) AS campaign_id, MAX(campaign_name) AS campaign_name,
               MAX(adset_id) AS adset_id, MAX(adset_name) AS adset_name, MAX(ad_name) AS ad_name,
               SUM(spend) AS spend, SUM(impressions) AS impressions, SUM(clicks) AS clicks,
               SUM(conversas_meta) AS conversas_meta
        FROM anuncio_gasto_diario
        WHERE data BETWEEN ? AND ?
    ";
    $params = [$de, $ate];
    if ($contaFiltro !== '') { $sqlGasto .= " AND ad_account_id = ?"; $params[] = $contaFiltro; }
    if ($campanhaFiltro !== '') { $sqlGasto .= " AND campaign_id = ?"; $params[] = $campanhaFiltro; }
    $sqlGasto .= " GROUP BY ad_id";
    $stmt = $db->prepare($sqlGasto);
    $stmt->execute($params);
    $gastoPorAd = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $g) {
        $gastoPorAd[$g['ad_id']] = $g;
    }

    // 2. Atribuição de lead por ad_id no período — 1 linha por
    // (ad_id, cliente_id|venda_id) distinto, já deduplicado no SQL.
    $stmtLeads = $db->prepare("
        SELECT DISTINCT ad_id, cliente_id, venda_id
        FROM lead_origem_anuncio
        WHERE recebido_em BETWEEN ? AND ? AND ad_id != ''
    ");
    $stmtLeads->execute(["{$de} 00:00:00", "{$ate} 23:59:59"]);
    $leadsPorAd = $stmtLeads->fetchAll(PDO::FETCH_ASSOC);

    // 3. Quais desses leads já converteram (compra fechada / venda vendida)
    // — 2 queries em lote (IN), nunca 1 por lead.
    $clienteIds = array_values(array_unique(array_filter(array_column($leadsPorAd, 'cliente_id'))));
    $vendaIds = array_values(array_unique(array_filter(array_column($leadsPorAd, 'venda_id'))));
    $clientesFechados = [];
    if ($clienteIds) {
        $ph = implode(',', array_fill(0, count($clienteIds), '?'));
        $stmtF = $db->prepare("SELECT DISTINCT cliente_id FROM oportunidades WHERE etapa = 'fechado' AND cliente_id IN ({$ph})");
        $stmtF->execute($clienteIds);
        $clientesFechados = array_flip(array_map('intval', $stmtF->fetchAll(PDO::FETCH_COLUMN)));
    }
    $vendasFechadas = [];
    if ($vendaIds) {
        $ph = implode(',', array_fill(0, count($vendaIds), '?'));
        $stmtV = $db->prepare("SELECT id FROM vendas WHERE etapa = 'vendido' AND id IN ({$ph})");
        $stmtV->execute($vendaIds);
        $vendasFechadas = array_flip(array_map('intval', $stmtV->fetchAll(PDO::FETCH_COLUMN)));
    }

    // 4. Agrupa leads por ad_id — leads_por_ad[ad_id] = ['leads'=>Set, 'vendas'=>int]
    $agregadoPorAd = [];
    foreach ($leadsPorAd as $l) {
        $adId = $l['ad_id'];
        if (!isset($agregadoPorAd[$adId])) $agregadoPorAd[$adId] = ['leads' => 0, 'vendas' => 0];
        $agregadoPorAd[$adId]['leads']++;
        $convertido = ($l['cliente_id'] !== null && isset($clientesFechados[(int)$l['cliente_id']]))
                   || ($l['venda_id'] !== null && isset($vendasFechadas[(int)$l['venda_id']]));
        if ($convertido) $agregadoPorAd[$adId]['vendas']++;
    }

    // 5. Monta a hierarquia campanha → conjunto → anúncio, só com ad_id que
    // tem gasto (sem gasto, não tem como calcular CPL — fica de fora da
    // árvore, mas os leads desses ad_ids ainda contam no total geral via
    // metaAdsContarLeadsSemGasto(), calculado à parte).
    $campanhas = [];
    foreach ($gastoPorAd as $adId => $g) {
        $campId = $g['campaign_id'] ?: '(sem campanha)';
        $adsetId = $g['adset_id'] ?: '(sem conjunto)';
        $leads = $agregadoPorAd[$adId]['leads'] ?? 0;
        $vendas = $agregadoPorAd[$adId]['vendas'] ?? 0;

        if (!isset($campanhas[$campId])) {
            $campanhas[$campId] = [
                'campaign_id' => $g['campaign_id'], 'campaign_name' => $g['campaign_name'] ?: '(sem nome)',
                'gasto' => 0.0, 'conversas_meta' => 0, 'leads' => 0, 'vendas' => 0, 'adsets' => [],
            ];
        }
        if (!isset($campanhas[$campId]['adsets'][$adsetId])) {
            $campanhas[$campId]['adsets'][$adsetId] = [
                'adset_id' => $g['adset_id'], 'adset_name' => $g['adset_name'] ?: '(sem nome)',
                'gasto' => 0.0, 'leads' => 0, 'vendas' => 0, 'anuncios' => [],
            ];
        }
        $campanhas[$campId]['adsets'][$adsetId]['anuncios'][$adId] = [
            'ad_id' => $adId, 'ad_name' => $g['ad_name'] ?: '(sem nome)',
            'gasto' => (float)$g['spend'], 'conversas_meta' => (int)$g['conversas_meta'],
            'leads' => $leads, 'vendas' => $vendas,
            'cpl' => $leads > 0 ? (float)$g['spend'] / $leads : null,
            'custo_por_venda' => $vendas > 0 ? (float)$g['spend'] / $vendas : null,
            'conversao' => $leads > 0 ? $vendas / $leads * 100 : null,
        ];
        $campanhas[$campId]['gasto'] += (float)$g['spend'];
        $campanhas[$campId]['conversas_meta'] += (int)$g['conversas_meta'];
        $campanhas[$campId]['leads'] += $leads;
        $campanhas[$campId]['vendas'] += $vendas;
        $campanhas[$campId]['adsets'][$adsetId]['gasto'] += (float)$g['spend'];
        $campanhas[$campId]['adsets'][$adsetId]['leads'] += $leads;
        $campanhas[$campId]['adsets'][$adsetId]['vendas'] += $vendas;
    }
    foreach ($campanhas as &$c) {
        $c['cpl'] = $c['leads'] > 0 ? $c['gasto'] / $c['leads'] : null;
        $c['custo_por_venda'] = $c['vendas'] > 0 ? $c['gasto'] / $c['vendas'] : null;
        $c['conversao'] = $c['leads'] > 0 ? $c['vendas'] / $c['leads'] * 100 : null;
        foreach ($c['adsets'] as &$as) {
            $as['cpl'] = $as['leads'] > 0 ? $as['gasto'] / $as['leads'] : null;
            $as['custo_por_venda'] = $as['vendas'] > 0 ? $as['gasto'] / $as['vendas'] : null;
            $as['conversao'] = $as['leads'] > 0 ? $as['vendas'] / $as['leads'] * 100 : null;
        }
        unset($as);
    }
    unset($c);
    uasort($campanhas, fn($a, $b) => $b['gasto'] <=> $a['gasto']);

    // 6. "Sem atribuição" — leads (compra + venda) criados no período cujo
    // canal_origem NÃO é meta_ads (contato direto/outro canal), nunca
    // confundir com lead que veio de anúncio mas ainda sem gasto
    // sincronizado (esse aparece nas linhas acima assim que o cron rodar).
    $stmtSemAtrib = $db->prepare("
        SELECT COUNT(*) FROM oportunidades o
        JOIN clientes c ON c.id = o.cliente_id
        WHERE o.created_at BETWEEN ? AND ? AND (c.canal_origem = '' OR c.canal_origem != 'meta_ads')
    ");
    $stmtSemAtrib->execute(["{$de} 00:00:00", "{$ate} 23:59:59"]);
    $semAtribCompra = (int)$stmtSemAtrib->fetchColumn();

    $stmtSemAtribVenda = $db->prepare("
        SELECT COUNT(*) FROM vendas
        WHERE created_at BETWEEN ? AND ? AND (canal_origem = '' OR canal_origem != 'meta_ads')
    ");
    $stmtSemAtribVenda->execute(["{$de} 00:00:00", "{$ate} 23:59:59"]);
    $semAtribVenda = (int)$stmtSemAtribVenda->fetchColumn();

    // 7. Cards de resumo
    $gastoTotal = array_sum(array_column($campanhas, 'gasto'));
    $leadsTotal = array_sum(array_column($campanhas, 'leads'));
    $vendasTotal = array_sum(array_column($campanhas, 'vendas'));

    return [
        'cards' => [
            'gasto_total' => $gastoTotal,
            'leads_atribuidos' => $leadsTotal,
            'cpl_medio' => $leadsTotal > 0 ? $gastoTotal / $leadsTotal : null,
            'vendas' => $vendasTotal,
            'custo_por_venda' => $vendasTotal > 0 ? $gastoTotal / $vendasTotal : null,
        ],
        'campanhas' => $campanhas,
        'sem_atribuicao' => ['leads' => $semAtribCompra + $semAtribVenda],
    ];
}
