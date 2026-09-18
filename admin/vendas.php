<?php
/**
 * Vendas — pipeline de revenda de veículos já comprados (frota). Cada
 * linha é uma negociação com um comprador (includes/vendas.php); nasce a
 * partir de admin/veiculos.php (botão "Vender" num veículo disponível) OU,
 * desde 17/09/2026, sozinha pela instância Z-API dedicada de vendas
 * (comprador entra pelo WhatsApp, qualificado por IA — ver
 * includes/ia_qualificacao_vendas.php) — as duas origens convivem no mesmo
 * pipeline. Também serve de DASHBOARD do vendedor (mesmo espírito de
 * admin/index.php pro funil de compra): cards de resultado + funil filtrado
 * por responsável.
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../includes/dashboard.php';
requireAcessoVendas();

$db = getDB();
$perfil = $_SESSION['admin_perfil'];
$meuId = (int)$_SESSION['admin_id'];
// vendedor só vê a própria carteira; super_admin/supervisor veem tudo.
$souDono = $perfil === 'vendedor';

$etapaFiltro = (string)($_GET['etapa'] ?? '');
$busca = trim((string)($_GET['q'] ?? ''));
$placeholders = implode(',', array_fill(0, count(ETAPAS_VENDA_ATIVAS), '?'));

$where = "WHERE v.etapa IN ({$placeholders})";
$params = ETAPAS_VENDA_ATIVAS;
if ($souDono) {
    $where .= " AND v.responsavel_id = ?";
    $params[] = $meuId;
}
if ($etapaFiltro && in_array($etapaFiltro, ETAPAS_VENDA_ATIVAS, true)) {
    $where .= " AND v.etapa = ?";
    $params[] = $etapaFiltro;
}
if ($busca !== '') {
    $where .= " AND (v.comprador_nome LIKE ? OR v.comprador_telefone LIKE ? OR o.veiculo_marca LIKE ? OR o.veiculo_modelo LIKE ? OR v.veiculo_interesse_texto LIKE ?)";
    $like = '%' . $busca . '%';
    array_push($params, $like, $like, $like, $like, $like);
}

// LEFT JOIN — desde 17/09/2026 uma negociação pode não ter veículo
// vinculado ainda (lead recém-entrado pelo WhatsApp, ver
// includes/vendas.php::criarOuAbrirVendaLead()), diferente do JOIN
// original (que assumia oportunidade_id sempre preenchido).
$stmtTotal = $db->prepare("SELECT COUNT(*) FROM vendas v LEFT JOIN oportunidades o ON o.id = v.oportunidade_id {$where}");
$stmtTotal->execute($params);
$totalFiltrado = (int)$stmtTotal->fetchColumn();

$sql = "
    SELECT v.*, o.veiculo_marca, o.veiculo_modelo, o.veiculo_ano, o.veiculo_placa,
           u.nome AS responsavel_nome
    FROM vendas v
    LEFT JOIN oportunidades o ON o.id = v.oportunidade_id
    LEFT JOIN usuarios u ON u.id = v.responsavel_id
    {$where}
    ORDER BY (v.proxima_acao_em IS NULL), v.proxima_acao_em ASC, v.updated_at DESC
    LIMIT " . ITENS_POR_PAGINA_PADRAO . " OFFSET " . paginacaoOffset();
$stmt = $db->prepare($sql);
$stmt->execute($params);
$vendas = $stmt->fetchAll();

$sqlContagem = "SELECT v.etapa, COUNT(*) AS total FROM vendas v LEFT JOIN oportunidades o ON o.id = v.oportunidade_id
                WHERE v.etapa IN ({$placeholders})";
$paramsContagem = ETAPAS_VENDA_ATIVAS;
if ($souDono) {
    $sqlContagem .= " AND v.responsavel_id = ?";
    $paramsContagem[] = $meuId;
}
if ($busca !== '') {
    $sqlContagem .= " AND (v.comprador_nome LIKE ? OR v.comprador_telefone LIKE ? OR o.veiculo_marca LIKE ? OR o.veiculo_modelo LIKE ? OR v.veiculo_interesse_texto LIKE ?)";
    array_push($paramsContagem, $like, $like, $like, $like, $like);
}
$sqlContagem .= " GROUP BY v.etapa";
$stmtContagem = $db->prepare($sqlContagem);
$stmtContagem->execute($paramsContagem);
$contagemPorEtapa = array_column($stmtContagem->fetchAll(), 'total', 'etapa');
$totalAtivas = array_sum($contagemPorEtapa);

$stats = match (true) {
    $souDono => dashboardVendedor($meuId),
    default  => dashboardVendasGeral(),
};

function moedaVenda(float $v): string { return 'R$ ' . number_format($v, 2, ',', '.'); }
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Vendas — Fastcar CRM</title>
<link rel="stylesheet" href="/admin/assets/style.css?v=<?= @filemtime(__DIR__ . '/assets/style.css') ?: 1 ?>">
<?php include __DIR__ . '/_pwa_head.php'; ?>
</head>
<body>
<header class="topbar">
    <a href="/admin/index.php" style="color:#fff">← Voltar</a>
    <strong><img class="topbar-logo" src="/admin/assets/img/icon-192.png" alt="Fastcar" onerror="this.style.display='none'"> Fast<b>Car</b></strong>
    <span>Olá, <?= e($_SESSION['admin_nome']) ?></span>
    <a href="/admin/vendas_inbox.php">💬 WhatsApp Vendas</a>
    <?php if (perfilVeTudo()): ?><a href="/admin/veiculos.php">🚗 Veículos</a><?php endif; ?>
    <a href="/admin/logout.php">Sair</a>
</header>

<nav class="etapas-nav">
    <a href="/admin/vendas.php<?= $busca !== '' ? '?q=' . urlencode($busca) : '' ?>" class="<?= $etapaFiltro === '' ? 'ativo' : '' ?>"><?= $souDono ? 'Minhas' : 'Todas' ?> (<?= (int)$totalAtivas ?>)</a>
    <?php foreach (ETAPAS_VENDA_ATIVAS as $et): ?>
        <a href="/admin/vendas.php?etapa=<?= urlencode($et) ?><?= $busca !== '' ? '&q=' . urlencode($busca) : '' ?>" class="<?= $etapaFiltro === $et ? 'ativo' : '' ?>">
            <?= e(etapaVendaLabel($et)) ?> (<?= (int)($contagemPorEtapa[$et] ?? 0) ?>)
        </a>
    <?php endforeach; ?>
</nav>

<main>
<div class="stat-grid">
    <div class="stat-card">
        <div class="valor"><?= (int)$stats['ativas'] ?></div>
        <div class="rotulo"><?= $souDono ? 'Minhas' : '' ?> negociações ativas</div>
    </div>
    <div class="stat-card <?= $stats['atrasadas'] > 0 ? 'alerta' : '' ?>">
        <div class="valor"><?= (int)$stats['atrasadas'] ?></div>
        <div class="rotulo">Atrasadas</div>
    </div>
    <?php if ($souDono): ?>
        <div class="stat-card neutro">
            <div class="valor"><?= (int)$stats['recebidas_semana'] ?></div>
            <div class="rotulo">Recebidas nos últimos 7 dias</div>
        </div>
        <div class="stat-card <?= $stats['disponivel'] ? 'sucesso' : 'neutro' ?>">
            <div class="valor"><?= $stats['disponivel'] ? '🟢' : '⚪' ?></div>
            <div class="rotulo"><?= $stats['disponivel'] ? 'Disponível pra fila' : ($stats['plantao'] ? 'Offline (plantão)' : 'Offline') ?></div>
        </div>
    <?php endif; ?>
    <div class="stat-card sucesso">
        <div class="valor"><?= (int)$stats['vendidas_mes'] ?></div>
        <div class="rotulo">Vendidas este mês</div>
    </div>
    <div class="stat-card sucesso">
        <div class="valor"><?= moedaVenda($stats['valor_vendido_mes']) ?></div>
        <div class="rotulo">Valor vendido este mês</div>
    </div>
    <?php if ($souDono && $stats['taxa_conversao'] !== null): ?>
        <div class="stat-card neutro">
            <div class="valor"><?= $stats['taxa_conversao'] ?>%</div>
            <div class="rotulo">Taxa de conversão</div>
        </div>
    <?php endif; ?>
</div>

<div class="card">
    <form method="get" style="display:flex;gap:8px;align-items:center">
        <?php if ($etapaFiltro): ?><input type="hidden" name="etapa" value="<?= e($etapaFiltro) ?>"><?php endif; ?>
        <input type="text" name="q" value="<?= e($busca) ?>" placeholder="Buscar por comprador, telefone ou veículo..." style="flex:1;margin:0">
        <button type="submit" style="margin:0">Buscar</button>
        <?php if ($busca): ?><a href="/admin/vendas.php<?= $etapaFiltro ? '?etapa=' . urlencode($etapaFiltro) : '' ?>">Limpar</a><?php endif; ?>
    </form>
</div>

<div class="card">
    <table class="tabela-oportunidades">
        <thead>
            <tr>
                <th>Veículo</th><th>Comprador</th><th>Preço/interesse</th>
                <th>Etapa</th><th>Responsável</th><th>Atualizado em</th><th></th>
            </tr>
        </thead>
        <tbody>
        <?php if (!$vendas): ?>
            <tr><td colspan="7">Nenhuma <?= $souDono ? 'venda sua' : 'venda' ?> <?= $busca ? 'encontrada' : ($etapaFiltro ? 'nessa etapa' : 'iniciada ainda') ?>.</td></tr>
        <?php endif; ?>
        <?php foreach ($vendas as $v): ?>
            <?php $atrasada = $v['proxima_acao_em'] && $v['proxima_acao_em'] < date('Y-m-d H:i:s'); ?>
            <tr>
                <td>
                    <?php if ($v['veiculo_marca'] || $v['veiculo_modelo']): ?>
                        <?= e(trim($v['veiculo_marca'] . ' ' . $v['veiculo_modelo'])) ?> <?= e((string)($v['veiculo_ano'] ?? '')) ?>
                        <br><small><?= e($v['veiculo_placa'] ?: '—') ?></small>
                    <?php else: ?>
                        <small style="color:var(--texto-fraco)">🔍 ainda não vinculado</small>
                        <?php if ($v['veiculo_interesse_texto']): ?><br><small><?= e(mb_strimwidth($v['veiculo_interesse_texto'], 0, 40, '…')) ?></small><?php endif; ?>
                    <?php endif; ?>
                </td>
                <td><?= e($v['comprador_nome'] ?: '(sem nome ainda)') ?><br><small><?= e($v['comprador_telefone'] ?: '') ?></small></td>
                <td><?= $v['preco_venda'] !== null ? moedaVenda((float)$v['preco_venda']) : '—' ?></td>
                <td>
                    <span class="badge"><?= e(etapaVendaLabel($v['etapa'])) ?></span>
                    <?php if ($atrasada): ?><br><span class="badge badge-atraso">⚠️ atrasada</span><?php endif; ?>
                </td>
                <td><?= e($v['responsavel_nome'] ?? '—') ?></td>
                <td><?= date('d/m/Y H:i', strtotime($v['updated_at'])) ?></td>
                <td><a href="/admin/venda.php?id=<?= (int)$v['id'] ?>">Abrir →</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php renderPaginacao($totalFiltrado); ?>
</div>
</main>
<?php include __DIR__ . '/_pwa_register.php'; ?>
<?php include __DIR__ . '/_notify.php'; ?>
</body>
</html>
