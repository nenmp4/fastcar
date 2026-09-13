<?php
/**
 * Veículos — frota comprada pela Fastcar (bloco 8, etapa='fechado').
 * Pedido explícito: buscar veículo por placa/chassi e ver de quem foi
 * comprado, quando, por quanto e há quantos meses está com a Fastcar.
 * Restrito ao super_admin, mesma trava das outras telas de relatório.
 *
 * Escopo de propósito: só o lado de COMPRA (o que já existe). "Vendido pra
 * quem"/"cliente pode reaver o carro" dependem do módulo de vendas
 * (segunda etapa do CLAUDE.md, ainda não iniciado) — por isso a busca já
 * usa placa/chassi como chave (não só o id da oportunidade): é o jeito
 * certo de deixar essa tela pronta pra, no futuro, relacionar o mesmo
 * veículo físico com uma revenda, sem precisar remodelar nada agora.
 */

require_once __DIR__ . '/_bootstrap.php';
requireSuperAdmin();

$db = getDB();
$busca = trim((string)($_GET['busca'] ?? ''));

$where = "WHERE o.etapa = 'fechado'";
$params = [];
if ($busca !== '') {
    $where .= " AND (o.veiculo_placa LIKE ? OR o.veiculo_chassi LIKE ? OR o.veiculo_marca LIKE ? OR o.veiculo_modelo LIKE ? OR c.nome LIKE ?)";
    $like = '%' . $busca . '%';
    $params = [$like, $like, $like, $like, $like];
}

$stmtTotal = $db->prepare("SELECT COUNT(*) FROM oportunidades o JOIN clientes c ON c.id = o.cliente_id {$where}");
$stmtTotal->execute($params);
$totalVeiculos = (int)$stmtTotal->fetchColumn();

$sql = "
    SELECT o.*, c.nome AS cliente_nome, c.telefone AS cliente_telefone
    FROM oportunidades o
    JOIN clientes c ON c.id = o.cliente_id
    {$where}
    ORDER BY o.data_compra DESC, o.updated_at DESC
    LIMIT " . ITENS_POR_PAGINA_PADRAO . " OFFSET " . paginacaoOffset();

$stmt = $db->prepare($sql);
$stmt->execute($params);
$veiculos = $stmt->fetchAll();

// Soma de TODOS os veículos que batem com a busca, não só os da página
// atual — com paginação, array_sum() em cima de $veiculos somaria só os
// 25 da tela, dando um "total pago" errado assim que passasse de 1 página.
$stmtTotalPago = $db->prepare("SELECT SUM(o.valor_final) FROM oportunidades o JOIN clientes c ON c.id = o.cliente_id {$where}");
$stmtTotalPago->execute($params);
$totalPago = (float)($stmtTotalPago->fetchColumn() ?: 0);

/** Meses inteiros desde a data de compra (ou updated_at se data_compra não foi preenchida) até hoje. */
function mesesComAFastcar(?string $dataCompra, string $updatedAt): int {
    $ref = $dataCompra ?: $updatedAt;
    if (!$ref) return 0;
    $inicio = new DateTime($ref);
    $agora = new DateTime();
    $diff = $inicio->diff($agora);
    return $diff->y * 12 + $diff->m;
}
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Veículos — Fastcar CRM</title>
<link rel="stylesheet" href="/admin/assets/style.css?v=<?= @filemtime(__DIR__ . '/assets/style.css') ?: 1 ?>">
<?php include __DIR__ . '/_pwa_head.php'; ?>
</head>
<body>
<header class="topbar">
    <a href="/admin/index.php" style="color:#fff">← Voltar</a>
    <strong><img class="topbar-logo" src="/admin/assets/img/icon-192.png" alt="Fastcar" onerror="this.style.display='none'"> Fast<b>Car</b> <span class="crm-tag">CRM</span></strong>
    <span>Olá, <?= e($_SESSION['admin_nome']) ?></span>
    <a href="/admin/configuracoes.php">⚙️ Configurações</a>
    <a href="/admin/logout.php">Sair</a>
</header>

<main>
<div class="card">
    <h2>🚗 Veículos comprados</h2>
    <p><small>Frota atual da Fastcar — todo veículo com negócio fechado (bloco 8). Busca por placa, chassi, marca/
       modelo ou nome do vendedor.</small></p>

    <form method="get">
        <input type="text" name="busca" value="<?= e($busca) ?>" placeholder="Placa, chassi, marca/modelo ou vendedor...">
        <button type="submit">Buscar</button>
    </form>
</div>

<div class="stat-grid">
    <div class="stat-card">
        <div class="valor"><?= $totalVeiculos ?></div>
        <div class="rotulo">Veículos na frota</div>
    </div>
    <div class="stat-card sucesso">
        <div class="valor">R$ <?= number_format($totalPago, 2, ',', '.') ?></div>
        <div class="rotulo">Total pago aos vendedores</div>
    </div>
</div>

<div class="card">
    <table class="tabela-oportunidades">
        <thead>
            <tr>
                <th>Veículo</th><th>Placa / Chassi</th><th>Comprado de</th>
                <th>Valor pago</th><th>Data da compra</th><th>Meses com a Fastcar</th>
                <th>Contrato</th><th></th>
            </tr>
        </thead>
        <tbody>
        <?php if (!$veiculos): ?>
            <tr><td colspan="8"><?= $busca ? 'Nenhum veículo encontrado pra essa busca.' : 'Nenhum veículo comprado ainda.' ?></td></tr>
        <?php endif; ?>
        <?php foreach ($veiculos as $v): ?>
            <tr>
                <td><?= e(trim($v['veiculo_marca'] . ' ' . $v['veiculo_modelo'])) ?: '—' ?> <?= e($v['veiculo_ano']) ?></td>
                <td><?= e($v['veiculo_placa'] ?: '—') ?><?php if ($v['veiculo_chassi']): ?><br><small><?= e($v['veiculo_chassi']) ?></small><?php endif; ?></td>
                <td><a href="/admin/cliente_detalhe.php?id=<?= (int)$v['cliente_id'] ?>"><?= e($v['cliente_nome']) ?></a><br><small><?= e($v['cliente_telefone']) ?></small></td>
                <td><?= $v['valor_final'] !== null ? 'R$ ' . number_format((float)$v['valor_final'], 2, ',', '.') : '—' ?></td>
                <td><?= $v['data_compra'] ? date('d/m/Y', strtotime($v['data_compra'])) : '—' ?></td>
                <td><?= mesesComAFastcar($v['data_compra'], $v['updated_at']) ?> mês(es)</td>
                <td>
                    <?php if ($v['contrato_assinado'] ?? false): ?>
                        <span class="badge badge-ok">✅ assinado</span>
                    <?php else: ?>
                        <span class="badge badge-aviso">pendente</span>
                    <?php endif; ?>
                </td>
                <td><a href="/admin/oportunidade.php?id=<?= (int)$v['id'] ?>">Abrir →</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php renderPaginacao($totalVeiculos); ?>
</div>
</main>
<?php include __DIR__ . '/_pwa_register.php'; ?>
<?php include __DIR__ . '/_notify.php'; ?>
</body>
</html>
