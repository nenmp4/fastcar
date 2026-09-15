<?php
/**
 * Vendas — pipeline de revenda de veículos já comprados (frota). Cada
 * linha é uma negociação com um comprador (includes/vendas.php); iniciar
 * uma negociação nova acontece a partir de admin/veiculos.php (botão
 * "Vender" num veículo disponível), essa tela é só o pipeline em si —
 * mesmo padrão de admin/index.php pro funil de compra.
 */

require_once __DIR__ . '/_bootstrap.php';

$db = getDB();
$etapaFiltro = (string)($_GET['etapa'] ?? '');

$where = '';
$params = [];
if ($etapaFiltro !== '' && in_array($etapaFiltro, ETAPAS_VENDA_VALIDAS, true)) {
    $where = 'WHERE v.etapa = ?';
    $params = [$etapaFiltro];
}

$stmtTotal = $db->prepare("SELECT COUNT(*) FROM vendas v {$where}");
$stmtTotal->execute($params);
$totalFiltrado = (int)$stmtTotal->fetchColumn();

$sql = "
    SELECT v.*, o.veiculo_marca, o.veiculo_modelo, o.veiculo_ano, o.veiculo_placa
    FROM vendas v
    JOIN oportunidades o ON o.id = v.oportunidade_id
    {$where}
    ORDER BY v.updated_at DESC
    LIMIT " . ITENS_POR_PAGINA_PADRAO . " OFFSET " . paginacaoOffset();
$stmt = $db->prepare($sql);
$stmt->execute($params);
$vendas = $stmt->fetchAll();

// Contadores por etapa pra nav — sempre do total (sem filtro de página).
$stmtContadores = $db->query("SELECT etapa, COUNT(*) AS total FROM vendas GROUP BY etapa");
$contadoresPorEtapa = array_column($stmtContadores->fetchAll(), 'total', 'etapa');
$totalGeral = array_sum($contadoresPorEtapa);
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
    <strong><img class="topbar-logo" src="/admin/assets/img/icon-192.png" alt="Fastcar" onerror="this.style.display='none'"> Fast<b>Car</b> <span class="crm-tag">CRM</span></strong>
    <span>Olá, <?= e($_SESSION['admin_nome']) ?></span>
    <a href="/admin/veiculos.php">🚗 Veículos</a>
    <a href="/admin/logout.php">Sair</a>
</header>

<nav class="etapas-nav">
    <a href="/admin/vendas.php" class="<?= $etapaFiltro === '' ? 'ativo' : '' ?>">Todas (<?= $totalGeral ?>)</a>
    <?php foreach (ETAPAS_VENDA_VALIDAS as $et): ?>
        <a href="/admin/vendas.php?etapa=<?= urlencode($et) ?>" class="<?= $etapaFiltro === $et ? 'ativo' : '' ?>">
            <?= e(etapaVendaLabel($et)) ?> (<?= (int)($contadoresPorEtapa[$et] ?? 0) ?>)
        </a>
    <?php endforeach; ?>
</nav>

<main>
<div class="card">
    <h2>💰 Vendas</h2>
    <p><small>Pipeline de revenda dos veículos da frota. Pra vender um veículo novo, vai em
       <a href="/admin/veiculos.php">🚗 Veículos</a> e clica em "Vender" no que estiver disponível.</small></p>
</div>

<div class="card">
    <table class="tabela-oportunidades">
        <thead>
            <tr>
                <th>Veículo</th><th>Comprador</th><th>Preço de venda</th>
                <th>Etapa</th><th>Responsável</th><th>Atualizado em</th><th></th>
            </tr>
        </thead>
        <tbody>
        <?php if (!$vendas): ?>
            <tr><td colspan="7">Nenhuma venda <?= $etapaFiltro ? 'nessa etapa' : 'iniciada ainda' ?>.</td></tr>
        <?php endif; ?>
        <?php foreach ($vendas as $v): ?>
            <?php $responsavel = $v['responsavel_id'] ? buscarUsuario((int)$v['responsavel_id']) : null; ?>
            <tr>
                <td><?= e(trim($v['veiculo_marca'] . ' ' . $v['veiculo_modelo'])) ?: '—' ?> <?= e($v['veiculo_ano']) ?>
                    <br><small><?= e($v['veiculo_placa'] ?: '—') ?></small></td>
                <td><?= e($v['comprador_nome'] ?: '(sem nome ainda)') ?><br><small><?= e($v['comprador_telefone'] ?: '') ?></small></td>
                <td><?= $v['preco_venda'] !== null ? 'R$ ' . number_format((float)$v['preco_venda'], 2, ',', '.') : '—' ?></td>
                <td><span class="badge"><?= e(etapaVendaLabel($v['etapa'])) ?></span></td>
                <td><?= e($responsavel['nome'] ?? '—') ?></td>
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
