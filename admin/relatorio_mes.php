<?php
/**
 * 📊 Resumo do mês (31/10/2026, "puxar os dados financeiros, compra de
 * veículos outubro 2026, vendas — ele quer com números reais" — kit da
 * mentoria do Jean). Junta numa tela só os números reais do mês: compras,
 * vendas, financeiro, leads e tráfego pago. Mesmas regras do resto do
 * sistema: compra/venda contam pela data do contrato ASSINADO (venda sem
 * contrato assinado fica de fora), financeiro em regime de caixa (só o
 * que foi pago). Sem nome de cliente/comprador — dá pra mostrar em
 * apresentação. ?formato=json baixa os mesmos números num arquivo.
 * Só super admin.
 */

require_once __DIR__ . '/_bootstrap.php';
requireSuperAdmin();

$db = getDB();
$mes = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['mes'] ?? '')) ? (string)$_GET['mes'] : date('Y-m');
$ini = $mes . '-01';
$fim = date('Y-m-t', strtotime($ini));
$nomesMes = ['01'=>'janeiro','02'=>'fevereiro','03'=>'março','04'=>'abril','05'=>'maio','06'=>'junho','07'=>'julho','08'=>'agosto','09'=>'setembro','10'=>'outubro','11'=>'novembro','12'=>'dezembro'];
$rotuloMes = $nomesMes[substr($mes, 5, 2)] . ' de ' . substr($mes, 0, 4);

// Puxa da Meta o MÊS INTEIRO (o cron só reprocessa os últimos 3 dias) —
// pra garantir que os anúncios do mês estão completos antes de exportar.
$sincMsg = ''; $sincErro = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'sincronizar_meta_mes') {
    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        $sincErro = 'Sessão expirada, recarregue a página e tente de novo.';
    } elseif (!metaAdsConfigured()) {
        $sincErro = 'Meta Ads ainda não configurado — cadastre o token e as contas em Configurações.';
    } else {
        $ateSinc = min($fim, date('Y-m-d'));
        $linhas = 0; $gasto = 0.0; $falhas = [];
        foreach (metaAdsContas() as $conta) {
            $r = metaAdsSincronizarConta($conta, $ini, $ateSinc);
            if ($r['ok']) { $linhas += count($r['linhas']); $gasto += (float)$r['gasto']; }
            else $falhas[] = "{$conta} ({$r['erro']})";
        }
        if ($falhas) $sincErro = 'Falha ao puxar da Meta: ' . implode(', ', $falhas);
        else $sincMsg = "✅ Anúncios de {$rotuloMes} puxados da Meta — {$linhas} linha(s), R$ " . number_format($gasto, 2, ',', '.') . ' de gasto.';
    }
}

// ---- Compras (pasta fechada, data do contrato de compra assinado)
$st = $db->prepare("
    SELECT o.id, o.veiculo_marca, o.veiculo_modelo, o.veiculo_ano, o.tipo_veiculo,
           o.valor_final, o.valor_fipe_referencia, o.saldo_financiamento_atual,
           date(COALESCE(ctr.assinado_em, o.data_compra, o.updated_at)) AS data_ref
    FROM oportunidades o
    LEFT JOIN (SELECT oportunidade_id, MAX(assinado_em) AS assinado_em FROM contratos
               WHERE tipo = 'compra' AND assinado_em IS NOT NULL GROUP BY oportunidade_id) ctr ON ctr.oportunidade_id = o.id
    WHERE o.etapa = 'fechado' AND COALESCE(o.oculto_estoque_motivo, '') NOT LIKE 'Cadastro duplicado%'
      AND date(COALESCE(ctr.assinado_em, o.data_compra, o.updated_at)) BETWEEN ? AND ?
    ORDER BY data_ref, o.id
");
$st->execute([$ini, $fim]);
$compras = $st->fetchAll(PDO::FETCH_ASSOC);
$cTotalPago = array_sum(array_map(fn($r) => (float)$r['valor_final'], $compras));
$cTotalFipe = array_sum(array_map(fn($r) => (float)$r['valor_fipe_referencia'], $compras));
$cComFipe = array_filter($compras, fn($r) => (float)$r['valor_fipe_referencia'] > 0 && (float)$r['valor_final'] > 0);
$cPctFipe = $cComFipe ? array_sum(array_map(fn($r) => $r['valor_final'] / $r['valor_fipe_referencia'], $cComFipe)) / count($cComFipe) * 100 : null;
$cSaldoAssumido = array_sum(array_map(fn($r) => (float)$r['saldo_financiamento_atual'], $compras));

// ---- Vendas (só com contrato de venda assinado, data da assinatura)
$st = $db->prepare("
    SELECT v.id, v.tipo_contrato, v.preco_venda, v.valor_pago_contratacao, v.parcelamento_qtd_parcelas,
           v.parcelamento_valor_parcela, o.veiculo_marca, o.veiculo_modelo, o.veiculo_ano, o.valor_fipe_referencia,
           o.valor_final AS custo_compra, date(ctrv.assinado_em) AS data_ref
    FROM vendas v
    JOIN (SELECT venda_id, MAX(assinado_em) AS assinado_em FROM contratos
          WHERE tipo = 'venda' AND status = 'assinado' AND assinado_em IS NOT NULL GROUP BY venda_id) ctrv ON ctrv.venda_id = v.id
    LEFT JOIN oportunidades o ON o.id = v.oportunidade_id
    WHERE v.etapa = 'vendido' AND date(ctrv.assinado_em) BETWEEN ? AND ?
    ORDER BY data_ref, v.id
");
$st->execute([$ini, $fim]);
$vendas = $st->fetchAll(PDO::FETCH_ASSOC);
$vTotal = array_sum(array_map(fn($r) => (float)$r['preco_venda'], $vendas));
$vEntradas = array_sum(array_map(fn($r) => (float)$r['valor_pago_contratacao'], $vendas));
$vTicket = $vendas ? $vTotal / count($vendas) : 0;
$vEntradaMedia = $vendas ? $vEntradas / count($vendas) : 0;
$vSemContrato = (int)$db->query("SELECT COUNT(*) FROM vendas v WHERE v.etapa = 'vendido' AND date(v.data_venda) BETWEEN " . $db->quote($ini) . " AND " . $db->quote($fim) . "
    AND NOT EXISTS (SELECT 1 FROM contratos c WHERE c.venda_id = v.id AND c.tipo = 'venda' AND c.status = 'assinado')")->fetchColumn();

// ---- Financeiro (regime de caixa)
$soma = function (string $tipo, string $status) use ($db, $ini, $fim): float {
    $st = $db->prepare("SELECT COALESCE(SUM(valor),0) FROM fin_lancamentos WHERE tipo = ? AND status = ?
                        AND COALESCE(data_pagamento, data_vencimento) BETWEEN ? AND ?");
    $st->execute([$tipo, $status, $ini, $fim]);
    return (float)$st->fetchColumn();
};
$recPaga = $soma('receita', 'pago');
$despPaga = $soma('despesa', 'pago');
$recPrev = $soma('receita', 'pendente');
$despPrev = $soma('despesa', 'pendente');
$st = $db->prepare("
    SELECT l.tipo, COALESCE(c.nome, '(sem categoria)') AS categoria, SUM(l.valor) AS total, COUNT(*) AS qtd
    FROM fin_lancamentos l LEFT JOIN fin_categorias c ON c.id = l.categoria_id
    WHERE l.status = 'pago' AND COALESCE(l.data_pagamento, l.data_vencimento) BETWEEN ? AND ?
    GROUP BY l.tipo, categoria ORDER BY l.tipo DESC, total DESC
");
$st->execute([$ini, $fim]);
$porCategoria = $st->fetchAll(PDO::FETCH_ASSOC);
$st = $db->prepare("SELECT origem, COALESCE(SUM(valor),0) FROM fin_lancamentos WHERE status = 'pago'
                    AND origem IN ('comissao_compra','comissao_venda') AND COALESCE(data_pagamento, data_vencimento) BETWEEN ? AND ? GROUP BY origem");
$st->execute([$ini, $fim]);
$comissoes = $st->fetchAll(PDO::FETCH_KEY_PAIR) + ['comissao_compra' => 0, 'comissao_venda' => 0];

// ---- Leads e tráfego
$st = $db->prepare("SELECT COUNT(*) FROM oportunidades WHERE date(created_at) BETWEEN ? AND ?");
$st->execute([$ini, $fim]);
$leadsCompra = (int)$st->fetchColumn();
$st = $db->prepare("SELECT COUNT(*) FROM vendas WHERE origem != 'manual' AND date(created_at) BETWEEN ? AND ?");
$st->execute([$ini, $fim]);
$leadsVenda = (int)$st->fetchColumn();
$st = $db->prepare("SELECT temperatura_lead, COUNT(*) FROM oportunidades WHERE date(created_at) BETWEEN ? AND ? GROUP BY temperatura_lead");
$st->execute([$ini, $fim]);
$temps = $st->fetchAll(PDO::FETCH_KEY_PAIR);
$st = $db->prepare("SELECT COALESCE(SUM(spend),0), COALESCE(SUM(conversas_meta),0) FROM anuncio_gasto_diario WHERE data BETWEEN ? AND ?");
$st->execute([$ini, $fim]);
[$gastoAds, $conversasAds] = $st->fetch(PDO::FETCH_NUM);
$gastoAds = (float)$gastoAds;
if ($gastoAds <= 0) { // sem Meta Ads sincronizado: usa o lançado em Marketing no financeiro
    $st = $db->prepare("SELECT COALESCE(SUM(l.valor),0) FROM fin_lancamentos l JOIN fin_categorias c ON c.id = l.categoria_id
                        WHERE c.nome = 'Marketing' AND l.status = 'pago' AND COALESCE(l.data_pagamento, l.data_vencimento) BETWEEN ? AND ?");
    $st->execute([$ini, $fim]);
    $gastoAds = (float)$st->fetchColumn();
}
$totalLeads = $leadsCompra + $leadsVenda;
$cpl = ($gastoAds > 0 && $totalLeads > 0) ? $gastoAds / $totalLeads : null;
$convCompra = $leadsCompra ? count($compras) / $leadsCompra * 100 : null;

// ---- Anúncios reais do mês (Meta Ads): gasto/impressões/cliques/conversas
// sincronizados pela Marketing API + leads/vendas atribuídos pelo clique
// (lead_origem_anuncio) + o texto real do anúncio que o cliente clicou.
// Inclui anúncio que gerou lead mesmo sem gasto sincronizado ainda.
$anuncios = [];
$st = $db->prepare("SELECT ad_id, MAX(ad_account_id) AS conta, MAX(campaign_name) AS campanha, MAX(adset_name) AS conjunto,
                           MAX(ad_name) AS anuncio, SUM(spend) AS gasto, SUM(impressions) AS impressoes,
                           SUM(clicks) AS cliques, SUM(conversas_meta) AS conversas, MIN(data) AS primeiro_dia, MAX(data) AS ultimo_dia
                    FROM anuncio_gasto_diario WHERE data BETWEEN ? AND ? GROUP BY ad_id");
$st->execute([$ini, $fim]);
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $anuncios[$r['ad_id']] = $r + ['leads' => 0, 'vendas' => 0, 'titulo' => '', 'texto' => '', 'link' => '', 'midia' => ''];
}
$st = $db->prepare("SELECT l.ad_id, l.cliente_id, l.venda_id, l.headline, l.corpo, l.source_url, l.media_type,
                           (SELECT COUNT(*) FROM oportunidades o WHERE o.cliente_id = l.cliente_id AND o.etapa = 'fechado') AS comprou,
                           (SELECT COUNT(*) FROM vendas v WHERE v.id = l.venda_id AND v.etapa = 'vendido') AS vendeu
                    FROM lead_origem_anuncio l WHERE l.recebido_em BETWEEN ? AND ? AND l.ad_id != '' ORDER BY l.recebido_em");
$st->execute([$ini . ' 00:00:00', $fim . ' 23:59:59']);
$vistos = [];
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $ad = $r['ad_id'];
    if (!isset($anuncios[$ad])) {
        $anuncios[$ad] = ['ad_id' => $ad, 'conta' => '', 'campanha' => '', 'conjunto' => '', 'anuncio' => '', 'gasto' => 0,
            'impressoes' => 0, 'cliques' => 0, 'conversas' => 0, 'primeiro_dia' => '', 'ultimo_dia' => '',
            'leads' => 0, 'vendas' => 0, 'titulo' => '', 'texto' => '', 'link' => '', 'midia' => ''];
    }
    $chave = $ad . '|' . ($r['cliente_id'] ?? '') . '|' . ($r['venda_id'] ?? '');
    if (!isset($vistos[$chave])) { // 1 lead conta 1x por anúncio, mesmo clicando várias vezes
        $vistos[$chave] = true;
        $anuncios[$ad]['leads']++;
        if ($r['comprou'] || $r['vendeu']) $anuncios[$ad]['vendas']++;
    }
    foreach (['titulo' => 'headline', 'texto' => 'corpo', 'link' => 'source_url', 'midia' => 'media_type'] as $k => $col) {
        if ((string)$r[$col] !== '') $anuncios[$ad][$k] = (string)$r[$col];
    }
}
foreach ($anuncios as &$a) {
    $a['gasto'] = (float)$a['gasto'];
    $a['cpl'] = ($a['gasto'] > 0 && $a['leads'] > 0) ? $a['gasto'] / $a['leads'] : null;
    $a['ctr'] = (int)$a['impressoes'] > 0 ? (int)$a['cliques'] / (int)$a['impressoes'] * 100 : null;
    $a['custo_por_conversa'] = ($a['gasto'] > 0 && (int)$a['conversas'] > 0) ? $a['gasto'] / (int)$a['conversas'] : null;
}
unset($a);
// Melhor primeiro: mais leads; empate → menor CPL; depois maior gasto.
uasort($anuncios, fn($x, $y) => [$y['leads'], $x['cpl'] ?? PHP_FLOAT_MAX, $y['gasto']] <=> [$x['leads'], $y['cpl'] ?? PHP_FLOAT_MAX, $x['gasto']]);
$anuncios = array_values($anuncios);

if (($_GET['formato'] ?? '') === 'anuncios_csv') {
    auditoriaRegistrar('relatorio_mes_exportado', (int)$_SESSION['admin_id'], (string)$_SESSION['admin_nome'], 'relatorio', null, "Anúncios do mês {$mes} exportados (planilha).");
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="fastcar_anuncios_' . $mes . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM: Excel abre acentuado certo
    $num = fn($v, $d = 2) => $v === null ? '' : number_format((float)$v, $d, ',', '');
    fputcsv($out, ['Posição', 'Campanha', 'Conjunto', 'Anúncio', 'ID do anúncio', 'Gasto (R$)', 'Impressões', 'Cliques', 'CTR (%)',
        'Conversas (Meta)', 'Custo por conversa (R$)', 'Leads no CRM', 'CPL (R$)', 'Negócios fechados', 'Título do anúncio',
        'Texto do anúncio', 'Link', 'Mídia', 'Primeiro dia', 'Último dia'], ';');
    foreach ($anuncios as $i => $a) {
        fputcsv($out, [$i + 1, $a['campanha'], $a['conjunto'], $a['anuncio'], $a['ad_id'], $num($a['gasto']), (int)$a['impressoes'],
            (int)$a['cliques'], $num($a['ctr']), (int)$a['conversas'], $num($a['custo_por_conversa']), $a['leads'], $num($a['cpl']),
            $a['vendas'], $a['titulo'], $a['texto'], $a['link'], $a['midia'], $a['primeiro_dia'], $a['ultimo_dia']], ';');
    }
    exit;
}

$dados = [
    'mes' => $mes, 'gerado_em' => date('Y-m-d H:i'),
    'compras' => ['qtd' => count($compras), 'total_pago_vendedores' => $cTotalPago, 'total_fipe' => $cTotalFipe,
        'pct_medio_da_fipe' => $cPctFipe, 'saldo_financiamento_assumido' => $cSaldoAssumido,
        'veiculos' => array_map(fn($r) => ['data' => $r['data_ref'], 'veiculo' => trim($r['veiculo_marca'] . ' ' . $r['veiculo_modelo'] . ' ' . $r['veiculo_ano']),
            'tipo' => $r['tipo_veiculo'], 'pago' => (float)$r['valor_final'], 'fipe' => (float)$r['valor_fipe_referencia'],
            'saldo_financiamento' => (float)$r['saldo_financiamento_atual']], $compras)],
    'vendas' => ['qtd' => count($vendas), 'valor_total' => $vTotal, 'entradas' => $vEntradas, 'ticket_medio' => $vTicket,
        'entrada_media' => $vEntradaMedia, 'vendidas_sem_contrato_fora_da_conta' => $vSemContrato,
        'veiculos' => array_map(fn($r) => ['data' => $r['data_ref'], 'veiculo' => trim($r['veiculo_marca'] . ' ' . $r['veiculo_modelo'] . ' ' . $r['veiculo_ano']),
            'tipo_contrato' => $r['tipo_contrato'], 'preco' => (float)$r['preco_venda'], 'entrada' => (float)$r['valor_pago_contratacao'],
            'parcelas' => (int)$r['parcelamento_qtd_parcelas'], 'valor_parcela' => (float)$r['parcelamento_valor_parcela'],
            'fipe' => (float)$r['valor_fipe_referencia'], 'custo_compra' => (float)$r['custo_compra']], $vendas)],
    'financeiro' => ['receita_paga' => $recPaga, 'despesa_paga' => $despPaga, 'saldo_realizado' => $recPaga - $despPaga,
        'receita_prevista' => $recPrev, 'despesa_prevista' => $despPrev,
        'comissoes_compra' => (float)$comissoes['comissao_compra'], 'comissoes_venda' => (float)$comissoes['comissao_venda'],
        'por_categoria' => array_map(fn($r) => ['tipo' => $r['tipo'], 'categoria' => $r['categoria'], 'total' => (float)$r['total'], 'qtd' => (int)$r['qtd']], $porCategoria)],
    'leads' => ['compra' => $leadsCompra, 'venda' => $leadsVenda, 'temperatura_compra' => $temps,
        'investimento_trafego' => $gastoAds, 'conversas_meta' => (int)$conversasAds, 'custo_por_lead' => $cpl,
        'conversao_lead_compra_pct' => $convCompra],
    'anuncios' => $anuncios,
];

if (($_GET['formato'] ?? '') === 'json') {
    auditoriaRegistrar('relatorio_mes_exportado', (int)$_SESSION['admin_id'], (string)$_SESSION['admin_nome'], 'relatorio', null, "Resumo do mês {$mes} baixado (JSON).");
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="fastcar_resumo_' . $mes . '.json"');
    echo json_encode($dados, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

$m = fn($v) => 'R$ ' . number_format((float)$v, 2, ',', '.');
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Resumo do mês — Fastcar CRM</title>
<link rel="stylesheet" href="/admin/assets/style.css?v=<?= @filemtime(__DIR__ . '/assets/style.css') ?: 1 ?>">
<link rel="stylesheet" href="/admin/assets/mobile.css?v=<?= @filemtime(__DIR__ . '/assets/mobile.css') ?: 1 ?>">
<script src="/admin/assets/mobile.js?v=<?= @filemtime(__DIR__ . '/assets/mobile.js') ?: 1 ?>" defer></script>
<?php include __DIR__ . '/_pwa_head.php'; ?>
</head>
<body>
<?php include __DIR__ . '/_impersonando_banner.php'; ?>
<header class="topbar">
    <a href="/admin/financeiro.php" style="color:#fff">← Financeiro</a>
    <a class="topbar-brand" href="<?= e(paginaInicialPorPerfil($_SESSION['admin_perfil'] ?? '')) ?>"><img class="topbar-logo" src="/admin/assets/img/icon-192.png" alt="Fastcar" onerror="this.style.display='none'"><span class="topbar-wordmark">Fast<b>Car</b></span></a>
    <span>Olá, <?= e($_SESSION['admin_nome']) ?></span>
    <a href="/admin/logout.php">Sair</a>
</header>
<main>
<div class="card">
    <h2 style="margin-top:0">📊 Resumo do mês — <?= e($rotuloMes) ?></h2>
    <form method="get" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end">
        <div><label>Mês</label><input type="month" name="mes" value="<?= e($mes) ?>"></div>
        <button type="submit" style="width:auto;margin:0">Ver</button>
        <a class="btn" href="?mes=<?= e($mes) ?>&formato=json">⬇️ Baixar dados (arquivo)</a>
        <a class="btn" href="?mes=<?= e($mes) ?>&formato=anuncios_csv">📣 Exportar anúncios (planilha)</a>
    </form>
    <?php if ($sincErro): ?><div class="alerta-erro"><?= e($sincErro) ?></div><?php endif; ?>
    <?php if ($sincMsg): ?><div class="alerta-sucesso"><?= e($sincMsg) ?></div><?php endif; ?>
    <?php if (metaAdsConfigured()): ?>
    <form method="post" style="margin-top:8px">
        <?= csrfField() ?><input type="hidden" name="acao" value="sincronizar_meta_mes">
        <button type="submit" style="width:auto">🔄 Puxar anúncios do mês inteiro da Meta</button>
        <small>Use antes de exportar — o automático só atualiza os últimos 3 dias.</small>
    </form>
    <?php endif; ?>
    <p><small>Números reais do sistema. Compra e venda contam pela data do <b>contrato assinado</b> (venda sem contrato fica de fora<?= $vSemContrato ? " — {$vSemContrato} neste mês" : '' ?>). Financeiro em regime de caixa: só o que foi pago. Sem nome de cliente — pode usar em apresentação.</small></p>
</div>

<div class="stat-grid">
    <div class="stat-card sucesso"><div class="valor"><?= count($compras) ?></div><div class="rotulo">Carros comprados</div></div>
    <div class="stat-card"><div class="valor"><?= $m($cTotalPago) ?></div><div class="rotulo">Pago aos vendedores</div></div>
    <div class="stat-card neutro"><div class="valor"><?= $cPctFipe !== null ? number_format($cPctFipe, 1, ',', '.') . '%' : '—' ?></div><div class="rotulo">Média paga sobre a FIPE</div></div>
    <div class="stat-card neutro"><div class="valor"><?= $m($cSaldoAssumido) ?></div><div class="rotulo">Saldo de financiamento assumido</div></div>
    <div class="stat-card sucesso"><div class="valor"><?= count($vendas) ?></div><div class="rotulo">Carros vendidos (contrato assinado)</div></div>
    <div class="stat-card"><div class="valor"><?= $m($vTotal) ?></div><div class="rotulo">Valor vendido · ticket <?= $m($vTicket) ?></div></div>
    <div class="stat-card"><div class="valor"><?= $m($vEntradas) ?></div><div class="rotulo">Entradas · média <?= $m($vEntradaMedia) ?></div></div>
    <div class="stat-card sucesso"><div class="valor"><?= $m($recPaga) ?></div><div class="rotulo">Receita realizada</div></div>
    <div class="stat-card alerta"><div class="valor"><?= $m($despPaga) ?></div><div class="rotulo">Despesa realizada</div></div>
    <div class="stat-card <?= $recPaga - $despPaga < 0 ? 'alerta' : 'sucesso' ?>"><div class="valor"><?= $m($recPaga - $despPaga) ?></div><div class="rotulo">Saldo do mês (caixa)</div></div>
    <div class="stat-card neutro"><div class="valor"><?= $m($recPrev) ?></div><div class="rotulo">Receita prevista (a receber)</div></div>
    <div class="stat-card neutro"><div class="valor"><?= $totalLeads ?></div><div class="rotulo">Leads (compra <?= $leadsCompra ?> · venda <?= $leadsVenda ?>)</div></div>
    <div class="stat-card neutro"><div class="valor"><?= $m($gastoAds) ?></div><div class="rotulo">Tráfego pago<?= $cpl !== null ? ' · CPL ' . $m($cpl) : '' ?></div></div>
</div>

<div class="card">
    <h3 style="margin-top:0">🚗 Compras do mês</h3>
    <div class="tabela-scroll"><table class="tabela-oportunidades">
        <thead><tr><th>Veículo</th><th>Data</th><th>FIPE</th><th>Pago ao vendedor</th><th>% FIPE</th><th>Saldo do financiamento</th></tr></thead>
        <tbody>
        <?php if (!$compras): ?><tr><td colspan="6">Nenhuma compra neste mês.</td></tr><?php endif; ?>
        <?php foreach ($compras as $r): ?>
            <tr><td><?= e(trim($r['veiculo_marca'] . ' ' . $r['veiculo_modelo'] . ' ' . $r['veiculo_ano'])) ?></td>
                <td><?= e(date('d/m', strtotime($r['data_ref']))) ?></td>
                <td><?= (float)$r['valor_fipe_referencia'] > 0 ? $m($r['valor_fipe_referencia']) : '—' ?></td>
                <td><?= $m($r['valor_final']) ?></td>
                <td><?= (float)$r['valor_fipe_referencia'] > 0 ? round($r['valor_final'] / $r['valor_fipe_referencia'] * 100) . '%' : '—' ?></td>
                <td><?= (float)$r['saldo_financiamento_atual'] > 0 ? $m($r['saldo_financiamento_atual']) : '—' ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>

<div class="card">
    <h3 style="margin-top:0">🛒 Vendas do mês (contrato assinado)</h3>
    <div class="tabela-scroll"><table class="tabela-oportunidades">
        <thead><tr><th>Veículo</th><th>Data</th><th>Contrato</th><th>Preço</th><th>Entrada</th><th>Parcelas</th><th>FIPE</th></tr></thead>
        <tbody>
        <?php if (!$vendas): ?><tr><td colspan="7">Nenhuma venda com contrato assinado neste mês.</td></tr><?php endif; ?>
        <?php foreach ($vendas as $r): ?>
            <tr><td><?= e(trim($r['veiculo_marca'] . ' ' . $r['veiculo_modelo'] . ' ' . $r['veiculo_ano']) ?: '—') ?></td>
                <td><?= e(date('d/m', strtotime($r['data_ref']))) ?></td>
                <td><?= ($r['tipo_contrato'] ?? 'venda') === 'locacao' ? 'Locação' : 'Promissória' ?></td>
                <td><?= $m($r['preco_venda']) ?></td>
                <td><?= $m($r['valor_pago_contratacao']) ?></td>
                <td><?= (int)$r['parcelamento_qtd_parcelas'] ? (int)$r['parcelamento_qtd_parcelas'] . 'x ' . $m($r['parcelamento_valor_parcela']) : '—' ?></td>
                <td><?= (float)$r['valor_fipe_referencia'] > 0 ? $m($r['valor_fipe_referencia']) : '—' ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>

<div class="card">
    <h3 style="margin-top:0">📣 Anúncios do mês (Meta Ads)</h3>
    <p><small>Ordenado pelo que mais trouxe lead pro CRM. Gasto, impressões, cliques e conversas vêm da Meta; leads e negócios fechados são contados pelo clique no anúncio que chegou no WhatsApp. O texto é o do anúncio que o cliente clicou.</small></p>
    <div class="tabela-scroll"><table class="tabela-oportunidades">
        <thead><tr><th>Anúncio</th><th>Campanha</th><th>Gasto</th><th>Impressões</th><th>Cliques</th><th>Conversas</th><th>Leads</th><th>CPL</th><th>Fechados</th><th>Texto do anúncio</th></tr></thead>
        <tbody>
        <?php if (!$anuncios): ?><tr><td colspan="10">Nenhum anúncio com gasto ou lead neste mês. Confira em Configurações se o Meta Ads está conectado e clique em "🔄 Sincronizar agora" no Custo por Lead.</td></tr><?php endif; ?>
        <?php foreach ($anuncios as $i => $a): ?>
            <tr><td><?= $i === 0 ? '🏆 ' : '' ?><?= e($a['anuncio'] ?: ($a['titulo'] ?: 'Anúncio ' . $a['ad_id'])) ?></td>
                <td><?= e($a['campanha'] ?: '—') ?></td>
                <td><?= $a['gasto'] > 0 ? $m($a['gasto']) : '—' ?></td>
                <td><?= (int)$a['impressoes'] ? number_format((int)$a['impressoes'], 0, ',', '.') : '—' ?></td>
                <td><?= (int)$a['cliques'] ? number_format((int)$a['cliques'], 0, ',', '.') : '—' ?></td>
                <td><?= (int)$a['conversas'] ?: '—' ?></td>
                <td><?= (int)$a['leads'] ?></td>
                <td><?= $a['cpl'] !== null ? $m($a['cpl']) : '—' ?></td>
                <td><?= (int)$a['vendas'] ?></td>
                <td style="overflow-wrap:anywhere;max-width:320px"><?= e(mb_strimwidth(trim($a['titulo'] . ($a['titulo'] && $a['texto'] ? ' — ' : '') . $a['texto']), 0, 160, '…')) ?: '—' ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>

<div class="card">
    <h3 style="margin-top:0">💳 Financeiro por categoria (pago no mês)</h3>
    <div class="tabela-scroll"><table class="tabela-oportunidades">
        <thead><tr><th>Categoria</th><th>Tipo</th><th>Lançamentos</th><th>Total</th></tr></thead>
        <tbody>
        <?php if (!$porCategoria): ?><tr><td colspan="4">Nada pago neste mês.</td></tr><?php endif; ?>
        <?php foreach ($porCategoria as $r): ?>
            <tr><td><?= e($r['categoria']) ?></td><td><?= $r['tipo'] === 'receita' ? 'Entrada' : 'Saída' ?></td><td><?= (int)$r['qtd'] ?></td><td><?= $m($r['total']) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>
</main>
<?php include __DIR__ . '/_pwa_register.php'; ?>
<?php include __DIR__ . '/_notify.php'; ?>
<?php include __DIR__ . '/_scroll_restore.php'; ?>
<?php include __DIR__ . '/_acao_popup.php'; ?>
<?php include __DIR__ . '/_confirm_dialog.php'; ?>
<?php include __DIR__ . '/_zapi_status.php'; ?>
</body>
</html>
