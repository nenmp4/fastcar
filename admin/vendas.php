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

// 19/09/2026, "aproveita adciona dasbord também em vendas igual de compras
// etapas igual de compras" — mesmo padrão de admin/index.php: nav sempre
// limitada a ETAPAS_VENDA_ATIVAS não deixava achar/buscar negociação já
// 'vendido' nem 'cancelada'/'sem_perfil' (nenhum lugar listava). `?etapa=`
// reaproveita o mesmo valor real da etapa terminal ('vendido', igual
// compra usa 'fechado') como aba especial, e 'encerradas' cobre
// cancelada+sem_perfil juntos — cada uma monta seu próprio escopo de
// etapa pro WHERE em vez de sempre ETAPAS_VENDA_ATIVAS.
$etapasEscopo = match ($etapaFiltro) {
    'vendido'    => ['vendido'],
    'encerradas' => ['cancelada', 'sem_perfil'],
    default      => ETAPAS_VENDA_ATIVAS,
};
$placeholders = implode(',', array_fill(0, count($etapasEscopo), '?'));

// Vendas nunca teve um "fechado_por" próprio (diferente de compra) — só
// responsavel_id existe, então as 3 abas (ativas/vendido/encerradas) usam
// o mesmo campo de dono, sem distinção.
$where = "WHERE v.etapa IN ({$placeholders})";
$params = $etapasEscopo;
if ($souDono) {
    $where .= " AND v.responsavel_id = ?";
    $params[] = $meuId;
}
if (in_array($etapaFiltro, ETAPAS_VENDA_ATIVAS, true)) {
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
    ORDER BY CASE v.temperatura_lead WHEN 'quente' THEN 0 WHEN 'morno' THEN 1 WHEN 'frio' THEN 2 ELSE 3 END,
             (v.proxima_acao_em IS NULL), v.proxima_acao_em ASC, v.created_at DESC
    LIMIT " . ITENS_POR_PAGINA_PADRAO . " OFFSET " . paginacaoOffset();
$stmt = $db->prepare($sql);
$stmt->execute($params);
$vendas = $stmt->fetchAll();

// Contadores da nav das etapas ATIVAS — sempre contra ETAPAS_VENDA_ATIVAS
// (nunca $placeholders/$etapasEscopo, que podem estar reduzidos a só
// 'vendido'/'encerradas' quando uma dessas abas está selecionada — bug
// real já corrigido uma vez no mesmo padrão em admin/index.php: usar a
// mesma variável pro `IN(...)` da nav quebraria o número de binds).
$placeholdersAtivas = implode(',', array_fill(0, count(ETAPAS_VENDA_ATIVAS), '?'));
$sqlContagem = "SELECT v.etapa, COUNT(*) AS total FROM vendas v LEFT JOIN oportunidades o ON o.id = v.oportunidade_id
                WHERE v.etapa IN ({$placeholdersAtivas})";
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

// Contador do badge "✅ Vendidas" — mesmo filtro de dono/busca, independente
// do filtro atual (mesma disciplina do "✅ Fechadas" de admin/index.php).
$sqlVendidas = "SELECT COUNT(*) FROM vendas v LEFT JOIN oportunidades o ON o.id = v.oportunidade_id WHERE v.etapa = 'vendido'";
$paramsVendidas = [];
if ($souDono) { $sqlVendidas .= " AND v.responsavel_id = ?"; $paramsVendidas[] = $meuId; }
if ($busca !== '') {
    $sqlVendidas .= " AND (v.comprador_nome LIKE ? OR v.comprador_telefone LIKE ? OR o.veiculo_marca LIKE ? OR o.veiculo_modelo LIKE ? OR v.veiculo_interesse_texto LIKE ?)";
    array_push($paramsVendidas, $like, $like, $like, $like, $like);
}
$stmtVendidas = $db->prepare($sqlVendidas);
$stmtVendidas->execute($paramsVendidas);
$totalVendidas = (int)$stmtVendidas->fetchColumn();

// Contador do badge "❌ Encerradas" (cancelada + sem_perfil) — mesmo padrão.
$sqlEncerradasVenda = "SELECT COUNT(*) FROM vendas v LEFT JOIN oportunidades o ON o.id = v.oportunidade_id WHERE v.etapa IN ('cancelada', 'sem_perfil')";
$paramsEncerradasVenda = [];
if ($souDono) { $sqlEncerradasVenda .= " AND v.responsavel_id = ?"; $paramsEncerradasVenda[] = $meuId; }
if ($busca !== '') {
    $sqlEncerradasVenda .= " AND (v.comprador_nome LIKE ? OR v.comprador_telefone LIKE ? OR o.veiculo_marca LIKE ? OR o.veiculo_modelo LIKE ? OR v.veiculo_interesse_texto LIKE ?)";
    array_push($paramsEncerradasVenda, $like, $like, $like, $like, $like);
}
$stmtEncerradasVenda = $db->prepare($sqlEncerradasVenda);
$stmtEncerradasVenda->execute($paramsEncerradasVenda);
$totalEncerradasVenda = (int)$stmtEncerradasVenda->fetchColumn();

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
    <a href="/admin/meu_perfil.php">🙋 Meu perfil</a>
    <a href="/admin/logout.php">Sair</a>
</header>

<nav class="etapas-nav">
    <a href="/admin/vendas.php<?= $busca !== '' ? '?q=' . urlencode($busca) : '' ?>" class="<?= $etapaFiltro === '' ? 'ativo' : '' ?>"><?= $souDono ? 'Minhas' : 'Todas' ?> (<?= (int)$totalAtivas ?>)</a>
    <?php foreach (ETAPAS_VENDA_ATIVAS as $et): ?>
        <a href="/admin/vendas.php?etapa=<?= urlencode($et) ?><?= $busca !== '' ? '&q=' . urlencode($busca) : '' ?>" class="<?= $etapaFiltro === $et ? 'ativo' : '' ?>">
            <?= e(etapaVendaLabel($et)) ?> (<?= (int)($contagemPorEtapa[$et] ?? 0) ?>)
        </a>
    <?php endforeach; ?>
    <a href="/admin/vendas.php?etapa=vendido<?= $busca !== '' ? '&q=' . urlencode($busca) : '' ?>" class="<?= $etapaFiltro === 'vendido' ? 'ativo' : '' ?>">✅ Vendidas (<?= $totalVendidas ?>)</a>
    <a href="/admin/vendas.php?etapa=encerradas<?= $busca !== '' ? '&q=' . urlencode($busca) : '' ?>" class="<?= $etapaFiltro === 'encerradas' ? 'ativo' : '' ?>">❌ Encerradas (<?= $totalEncerradasVenda ?>)</a>
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
            <?php
            // Mesma classe de bug já corrigida em admin/index.php: sem
            // restringir a ETAPAS_VENDA_ATIVAS, uma venda já 'vendido'/
            // 'cancelada'/'sem_perfil' com proxima_acao_em velho (resto de
            // quando ainda estava ativa) apareceria com destaque de atraso
            // como se ainda precisasse de ação — nunca mais faz sentido
            // numa negociação já encerrada.
            $atrasada = in_array($v['etapa'], ETAPAS_VENDA_ATIVAS, true) && $v['proxima_acao_em'] && $v['proxima_acao_em'] < date('Y-m-d H:i:s');
            $quente = $v['temperatura_lead'] === 'quente';
            ?>
            <tr class="<?= trim(($atrasada ? 'linha-atrasada ' : '') . ($quente ? 'linha-quente' : '')) ?>">
                <td>
                    <?php if ($v['veiculo_marca'] || $v['veiculo_modelo']): ?>
                        <?= e(trim($v['veiculo_marca'] . ' ' . $v['veiculo_modelo'])) ?> <?= e((string)($v['veiculo_ano'] ?? '')) ?>
                        <br><small><?= e($v['veiculo_placa'] ?: '—') ?></small>
                    <?php else: ?>
                        <small style="color:var(--texto-fraco)">🔍 ainda não vinculado</small>
                        <?php if ($v['veiculo_interesse_texto']): ?><br><small><?= e(mb_strimwidth($v['veiculo_interesse_texto'], 0, 40, '…')) ?></small><?php endif; ?>
                    <?php endif; ?>
                </td>
                <td>
                    <?= e($v['comprador_nome'] ?: '(sem nome ainda)') ?><br><small><?= e($v['comprador_telefone'] ?: '') ?></small>
                    <?php if ($v['temperatura_lead'] === 'quente'): ?>
                        <br><span class="badge badge-quente">🔥 Quente</span>
                    <?php elseif ($v['temperatura_lead'] === 'morno'): ?>
                        <br><span class="badge">🌤️ Morno</span>
                    <?php elseif ($v['temperatura_lead'] === 'frio'): ?>
                        <br><span class="badge">❄️ Frio</span>
                    <?php endif; ?>
                </td>
                <td><?= $v['preco_venda'] !== null ? moedaVenda((float)$v['preco_venda']) : '—' ?></td>
                <td>
                    <span class="badge"><?= e(etapaVendaLabel($v['etapa'])) ?></span>
                    <?php if ($atrasada): ?><br><span class="badge badge-atraso">⚠️ atrasada</span><?php endif; ?>
                    <?php if ($v['etapa'] === 'vendido' && $v['data_venda']): ?>
                        <br><small>vendido <?= date('d/m/Y', strtotime($v['data_venda'])) ?></small>
                    <?php elseif (in_array($v['etapa'], ['cancelada', 'sem_perfil'], true)): ?>
                        <?php $motivo = $v['motivo_cancelamento'] ?: $v['motivo_perda']; ?>
                        <?php if ($motivo): ?><br><small><?= e(mb_strimwidth($motivo, 0, 50, '…')) ?></small><?php endif; ?>
                    <?php endif; ?>
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
