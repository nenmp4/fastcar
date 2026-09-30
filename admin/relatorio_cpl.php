<?php
/**
 * Relatório de Custo por Lead (CPL) das campanhas Meta — 29/09/2026, spec
 * trazida pelo usuário via Google Docs: "calcular CPL = gasto ÷ leads (por
 * campanha, conjunto e anúncio) e custo por venda = gasto ÷ leads que
 * viraram venda/contrato".
 *
 * Cruza anuncio_gasto_diario (puxado por cron/meta_insights.php da
 * Marketing API) com lead_origem_anuncio (capturado no webhook, todo
 * clique de anúncio que virou mensagem) — ver includes/meta_ads.php::metaAdsRelatorioCpl().
 *
 * Mesmo nível de acesso de admin/origem_leads.php (que este relatório
 * complementa com o lado financeiro) — super_admin + supervisor, dado de
 * tráfego pago é decisão de negócio, não operacional do dia a dia.
 */

require_once __DIR__ . '/_bootstrap.php';
requireVisaoGeral();

$hoje = date('Y-m-d');
$de = (string)($_GET['de'] ?? date('Y-m-d', strtotime('-7 days')));
$ate = (string)($_GET['ate'] ?? $hoje);
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $de)) $de = date('Y-m-d', strtotime('-7 days'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ate)) $ate = $hoje;

$contaFiltro = trim((string)($_GET['conta'] ?? ''));
$campanhaFiltro = trim((string)($_GET['campanha'] ?? ''));

$sincSucesso = '';
$sincErro = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'sincronizar') {
    // Botão manual "🔄 Sincronizar agora" — 29/09/2026, "coloca botão para
    // sincronizar" — mesma lógica do cron (a cada 3h), só disparada na hora
    // em vez de esperar. Sempre reprocessa hoje + últimos 3 dias (não o
    // período do filtro na tela), mesmo padrão do cron/meta_insights.php —
    // a Meta ajusta o número dela retroativamente.
    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        $sincErro = 'Sessão expirada, recarregue a página e tente de novo.';
    } elseif (!metaAdsConfigured()) {
        $sincErro = 'Meta Ads ainda não configurado — cadastre o token e as contas em Configurações.';
    } else {
        $desdeSinc = date('Y-m-d', strtotime('-3 days'));
        $ateSinc = date('Y-m-d');
        $totalLinhas = 0;
        $totalGasto = 0.0;
        $contasComErro = [];
        foreach (metaAdsContas() as $conta) {
            $r = metaAdsSincronizarConta($conta, $desdeSinc, $ateSinc);
            if ($r['ok']) {
                $totalLinhas += count($r['linhas']);
                $totalGasto += $r['gasto'];
            } else {
                $contasComErro[] = "{$conta} ({$r['erro']})";
            }
        }
        if ($contasComErro) {
            $sincErro = "Sincronizado com falha em " . count($contasComErro) . " conta(s): " . implode(', ', $contasComErro)
                . ($totalLinhas ? ". As demais deram certo: {$totalLinhas} linha(s), " . cplFmt($totalGasto) . " de gasto." : '.');
        } else {
            $sincSucesso = "✅ Sincronizado — {$totalLinhas} linha(s), " . cplFmt($totalGasto) . " de gasto (últimos 3 dias + hoje).";
        }
    }
}

$db = getDB();
$contasDisponiveis = $db->query("SELECT DISTINCT ad_account_id FROM anuncio_gasto_diario ORDER BY ad_account_id")->fetchAll(PDO::FETCH_COLUMN);
$campanhasDisponiveis = $db->query("SELECT DISTINCT campaign_id, campaign_name FROM anuncio_gasto_diario WHERE campaign_id != '' ORDER BY campaign_name")->fetchAll(PDO::FETCH_ASSOC);

$relatorio = metaAdsRelatorioCpl($de, $ate, $contaFiltro, $campanhaFiltro);
$temDadoDeGasto = (bool)$db->query("SELECT 1 FROM anuncio_gasto_diario LIMIT 1")->fetchColumn();

function cplFmt(?float $v): string {
    return $v === null ? '—' : 'R$ ' . number_format($v, 2, ',', '.');
}
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Custo por Lead (CPL) — Fastcar CRM</title>
<link rel="stylesheet" href="/admin/assets/style.css?v=<?= @filemtime(__DIR__ . '/assets/style.css') ?: 1 ?>">
<link rel="stylesheet" href="/admin/assets/mobile.css?v=<?= @filemtime(__DIR__ . '/assets/mobile.css') ?: 1 ?>">
<script src="/admin/assets/mobile.js?v=<?= @filemtime(__DIR__ . '/assets/mobile.js') ?: 1 ?>" defer></script>
<?php include __DIR__ . '/_pwa_head.php'; ?>
<style>
.cpl-hierarquia summary { cursor: pointer; padding: 8px 4px; }
.cpl-hierarquia .nivel-campanha > summary { font-weight: 700; background: var(--cinza-claro, #f4f4f7); border-radius: var(--raio, 8px); }
.cpl-hierarquia .nivel-conjunto { margin-left: 16px; }
.cpl-hierarquia .nivel-conjunto > summary { font-weight: 600; }
.cpl-hierarquia .nivel-anuncio { margin-left: 16px; padding: 6px 4px; border-bottom: 1px solid var(--borda, #e5e7eb); display: flex; flex-wrap: wrap; gap: 12px; justify-content: space-between; }
.cpl-metricas { display: flex; gap: 14px; flex-wrap: wrap; font-size: 13px; color: #444; }
.cpl-metricas b { color: #111; }
</style>
</head>
<body>
<?php include __DIR__ . '/_impersonando_banner.php'; ?>
<header class="topbar">
    <a href="/admin/index.php" style="color:#fff">← Voltar</a>
    <strong><img class="topbar-logo" src="/admin/assets/img/icon-192.png" alt="Fastcar" onerror="this.style.display='none'"> Fast<b>Car</b></strong>
    <span>Olá, <?= e($_SESSION['admin_nome']) ?></span>
    <a href="/admin/origem_leads.php">📣 Origem dos leads</a>
    <a href="/admin/configuracoes.php">⚙️ Configurações</a>
    <a href="/admin/logout.php">Sair</a>
</header>

<main>
<div class="card">
    <h2>📣 Custo por Lead (CPL) das campanhas Meta</h2>
    <p><small>Gasto puxado da Marketing API (<code>cron/meta_insights.php</code>, a cada 3h) cruzado com os leads que
       entraram por clique de anúncio (<code>lead_origem_anuncio</code>, capturado automaticamente no webhook).
       CPL = gasto ÷ leads · Custo por venda = gasto ÷ leads que fecharam compra ou venda no CRM.</small></p>

    <?php if ($sincErro): ?><div class="alerta-erro"><?= e($sincErro) ?></div><?php endif; ?>
    <?php if ($sincSucesso): ?><div class="alerta-sucesso"><?= e($sincSucesso) ?></div><?php endif; ?>

    <?php if (!metaAdsConfigured()): ?>
        <p><span class="badge badge-atraso">⏳ Meta Ads ainda não configurado</span>
           <small> — cadastre o token e as contas de anúncios em <a href="/admin/configuracoes.php">Configurações → Meta Marketing API</a>.</small></p>
    <?php else: ?>
        <?php if (!$temDadoDeGasto): ?>
            <p><span class="badge badge-atraso">⏳ Nenhum gasto sincronizado ainda</span></p>
        <?php endif; ?>
        <form method="POST" style="margin-top:8px">
            <?= csrfField() ?>
            <input type="hidden" name="acao" value="sincronizar">
            <button type="submit">🔄 Sincronizar agora</button>
            <small style="margin-left:8px;color:#666">Puxa hoje + últimos 3 dias da Marketing API — o cron já faz isso sozinho a cada 3h, use pra atualizar na hora.</small>
        </form>
    <?php endif; ?>

    <form method="get" style="display:flex;gap:12px;flex-wrap:wrap;align-items:end;margin-top:12px">
        <div>
            <label>De</label>
            <input type="date" name="de" value="<?= e($de) ?>">
        </div>
        <div>
            <label>Até</label>
            <input type="date" name="ate" value="<?= e($ate) ?>">
        </div>
        <div>
            <label>Conta de anúncios</label>
            <select name="conta">
                <option value="">Todas</option>
                <?php foreach ($contasDisponiveis as $c): ?>
                    <option value="<?= e($c) ?>" <?= $contaFiltro === $c ? 'selected' : '' ?>><?= e($c) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label>Campanha</label>
            <select name="campanha">
                <option value="">Todas</option>
                <?php foreach ($campanhasDisponiveis as $c): ?>
                    <option value="<?= e($c['campaign_id']) ?>" <?= $campanhaFiltro === $c['campaign_id'] ? 'selected' : '' ?>><?= e($c['campaign_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit">Filtrar</button>
        <?php if ($contaFiltro || $campanhaFiltro): ?>
            <a class="btn" href="/admin/relatorio_cpl.php?de=<?= e($de) ?>&ate=<?= e($ate) ?>">Limpar filtros</a>
        <?php endif; ?>
    </form>
</div>

<div class="card">
    <div class="stat-grid">
        <div class="stat-card"><div class="valor"><?= cplFmt($relatorio['cards']['gasto_total']) ?></div><div class="rotulo">Gasto total</div></div>
        <div class="stat-card"><div class="valor"><?= (int)$relatorio['cards']['leads_atribuidos'] ?></div><div class="rotulo">Leads atribuídos</div></div>
        <div class="stat-card"><div class="valor"><?= cplFmt($relatorio['cards']['cpl_medio']) ?></div><div class="rotulo">CPL médio</div></div>
        <div class="stat-card sucesso"><div class="valor"><?= (int)$relatorio['cards']['vendas'] ?></div><div class="rotulo">Vendas</div></div>
        <div class="stat-card sucesso"><div class="valor"><?= cplFmt($relatorio['cards']['custo_por_venda']) ?></div><div class="rotulo">Custo por venda</div></div>
    </div>
</div>

<div class="card">
    <h3>Por campanha → conjunto → anúncio</h3>
    <?php if (!$relatorio['campanhas']): ?>
        <p>Nenhum gasto sincronizado nesse período/filtro ainda.</p>
    <?php endif; ?>
    <div class="cpl-hierarquia">
        <?php foreach ($relatorio['campanhas'] as $camp): ?>
            <details class="nivel-campanha" open>
                <summary>
                    <?= e($camp['campaign_name']) ?>
                    <span class="cpl-metricas">
                        <span>Gasto: <b><?= cplFmt($camp['gasto']) ?></b></span>
                        <span>Conversas (Meta): <b><?= (int)$camp['conversas_meta'] ?></b></span>
                        <span>Leads (CRM): <b><?= (int)$camp['leads'] ?></b></span>
                        <span>CPL: <b><?= cplFmt($camp['cpl']) ?></b></span>
                        <span>Vendas: <b><?= (int)$camp['vendas'] ?></b></span>
                        <span>Custo/venda: <b><?= cplFmt($camp['custo_por_venda']) ?></b></span>
                        <span>Conversão: <b><?= $camp['conversao'] === null ? '—' : number_format($camp['conversao'], 1, ',', '.') . '%' ?></b></span>
                    </span>
                </summary>
                <?php foreach ($camp['adsets'] as $as): ?>
                    <details class="nivel-conjunto">
                        <summary>
                            <?= e($as['adset_name']) ?>
                            <span class="cpl-metricas">
                                <span>Gasto: <b><?= cplFmt($as['gasto']) ?></b></span>
                                <span>Leads: <b><?= (int)$as['leads'] ?></b></span>
                                <span>CPL: <b><?= cplFmt($as['cpl']) ?></b></span>
                                <span>Vendas: <b><?= (int)$as['vendas'] ?></b></span>
                                <span>Custo/venda: <b><?= cplFmt($as['custo_por_venda']) ?></b></span>
                            </span>
                        </summary>
                        <?php foreach ($as['anuncios'] as $ad): ?>
                            <div class="nivel-anuncio">
                                <span><?= e($ad['ad_name']) ?> <small>(<?= e($ad['ad_id']) ?>)</small></span>
                                <span class="cpl-metricas">
                                    <span>Gasto: <b><?= cplFmt($ad['gasto']) ?></b></span>
                                    <span>Conversas (Meta): <b><?= (int)$ad['conversas_meta'] ?></b></span>
                                    <span>Leads: <b><?= (int)$ad['leads'] ?></b></span>
                                    <span>CPL: <b><?= cplFmt($ad['cpl']) ?></b></span>
                                    <span>Vendas: <b><?= (int)$ad['vendas'] ?></b></span>
                                    <span>Custo/venda: <b><?= cplFmt($ad['custo_por_venda']) ?></b></span>
                                    <span>Conversão: <b><?= $ad['conversao'] === null ? '—' : number_format($ad['conversao'], 1, ',', '.') . '%' ?></b></span>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    </details>
                <?php endforeach; ?>
            </details>
        <?php endforeach; ?>
    </div>

    <p style="margin-top:16px"><strong>⚪ Sem atribuição:</strong> <?= (int)$relatorio['sem_atribuicao']['leads'] ?> lead(s)
       criado(s) no período sem clique de anúncio (contato direto ou outro canal).</p>
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
