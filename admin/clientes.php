<?php
/**
 * Módulo cliente — lista/busca independente de oportunidade. Útil quando
 * o mesmo telefone tem mais de um veículo negociado ao longo do tempo
 * (regra do Jean: 1 cadastro por telefone, N oportunidades).
 */

require_once __DIR__ . '/_bootstrap.php';

$busca = trim((string)($_GET['q'] ?? ''));
$db = getDB();

// Total de clientes que batem com a busca ANTES de paginar — sem isso,
// LIMIT 100 sozinho (como era antes) simplesmente escondia todo cliente
// além do 100º (por created_at DESC), sem paginação nenhuma pra ver o
// resto — bug real achado ("quantas negociações ficar na tela, já pensou
// nisso?").
$whereBusca = '';
$paramsBusca = [];
if ($busca) {
    $whereBusca = " WHERE c.nome LIKE ? OR c.telefone LIKE ? OR c.cidade LIKE ?";
    $like = '%' . $busca . '%';
    $paramsBusca = [$like, $like, $like];
}
$stmtTotal = $db->prepare("SELECT COUNT(*) FROM clientes c{$whereBusca}");
$stmtTotal->execute($paramsBusca);
$totalClientes = (int)$stmtTotal->fetchColumn();

$sql = "
    SELECT c.*, COUNT(o.id) AS total_oportunidades,
           SUM(CASE WHEN o.etapa IN (" . implode(',', array_fill(0, count(ETAPAS_ATIVAS), '?')) . ") THEN 1 ELSE 0 END) AS ativas,
           SUM(CASE WHEN o.etapa = 'fechado' THEN 1 ELSE 0 END) AS convertidas
    FROM clientes c
    LEFT JOIN oportunidades o ON o.cliente_id = c.id
    {$whereBusca}
    GROUP BY c.id ORDER BY c.created_at DESC
    LIMIT " . ITENS_POR_PAGINA_PADRAO . " OFFSET " . paginacaoOffset();
$params = array_merge(ETAPAS_ATIVAS, $paramsBusca);

$stmt = $db->prepare($sql);
$stmt->execute($params);
$clientes = $stmt->fetchAll();
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Clientes — Fastcar CRM</title>
<link rel="stylesheet" href="/admin/assets/style.css?v=<?= @filemtime(__DIR__ . '/assets/style.css') ?: 1 ?>">
<?php include __DIR__ . '/_pwa_head.php'; ?>
</head>
<body>
<header class="topbar">
    <a href="/admin/index.php" style="color:#fff">← Voltar</a>
    <strong><img class="topbar-logo" src="/admin/assets/img/icon-192.png" alt="Fastcar" onerror="this.style.display='none'"> Fast<b>Car</b> <span class="crm-tag">CRM</span></strong>
    <span>Olá, <?= e($_SESSION['admin_nome']) ?></span>
    <a href="/admin/logout.php">Sair</a>
</header>

<main>
<div class="card">
    <h2>👥 Clientes</h2>
    <form method="get">
        <input type="text" name="q" value="<?= e($busca) ?>" placeholder="Buscar por nome, telefone ou cidade...">
        <button type="submit">Buscar</button>
    </form>
</div>

<table class="tabela-oportunidades">
    <thead>
        <tr><th>Nome</th><th>Recebido em</th><th>Telefone</th><th>Cidade/UF</th><th>Origem</th><th>Oportunidades</th><th>Status</th><th></th></tr>
    </thead>
    <tbody>
    <?php if (!$clientes): ?>
        <tr><td colspan="8">Nenhum cliente encontrado.</td></tr>
    <?php endif; ?>
    <?php foreach ($clientes as $c): ?>
        <tr>
            <td><?= e($c['nome'] ?: '(sem nome)') ?></td>
            <td><?= $c['created_at'] ? date('d/m/Y H:i', strtotime($c['created_at'])) : '—' ?></td>
            <td><?= e($c['telefone']) ?></td>
            <td><?= e($c['cidade'] ?: '—') ?><?= $c['estado'] ? '/' . e($c['estado']) : '' ?></td>
            <td><?= e($c['canal_origem'] ?: 'direto') ?></td>
            <td>
                <?= (int)$c['total_oportunidades'] ?> total
                <?php if ($c['ativas'] > 0): ?><span class="badge badge-ok"><?= (int)$c['ativas'] ?> ativa(s)</span><?php endif; ?>
            </td>
            <td>
                <?php if ((int)$c['convertidas'] > 0): ?>
                    <span class="badge badge-ok" title="Já teve pelo menos um veículo com negócio fechado">🏆 Cliente convertido</span>
                <?php else: ?>
                    <span class="badge">Lead</span>
                <?php endif; ?>
            </td>
            <td><a href="/admin/cliente_detalhe.php?id=<?= (int)$c['id'] ?>">Abrir →</a></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php renderPaginacao($totalClientes); ?>
</main>
<?php include __DIR__ . '/_pwa_register.php'; ?>
<?php include __DIR__ . '/_notify.php'; ?>
</body>
</html>
