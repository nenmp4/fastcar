<?php
/**
 * Módulo cliente — lista/busca independente de oportunidade. Útil quando
 * o mesmo telefone tem mais de um veículo negociado ao longo do tempo
 * (regra do Jean: 1 cadastro por telefone, N oportunidades).
 */

require_once __DIR__ . '/_bootstrap.php';

$busca = trim((string)($_GET['q'] ?? ''));
$db = getDB();

$sql = "
    SELECT c.*, COUNT(o.id) AS total_oportunidades,
           SUM(CASE WHEN o.etapa IN (" . implode(',', array_fill(0, count(ETAPAS_ATIVAS), '?')) . ") THEN 1 ELSE 0 END) AS ativas,
           SUM(CASE WHEN o.etapa = 'fechado' THEN 1 ELSE 0 END) AS convertidas
    FROM clientes c
    LEFT JOIN oportunidades o ON o.cliente_id = c.id
";
$params = ETAPAS_ATIVAS;
if ($busca) {
    $sql .= " WHERE c.nome LIKE ? OR c.telefone LIKE ? OR c.cidade LIKE ?";
    $like = '%' . $busca . '%';
    array_push($params, $like, $like, $like);
}
$sql .= " GROUP BY c.id ORDER BY c.created_at DESC LIMIT 100";

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
<link rel="stylesheet" href="/admin/assets/style.css">
</head>
<body>
<header class="topbar">
    <a href="/admin/index.php" style="color:#fff">← Voltar</a>
    <strong>🚗 Fastcar CRM</strong>
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
        <tr><th>Nome</th><th>Telefone</th><th>Cidade/UF</th><th>Origem</th><th>Oportunidades</th><th>Status</th><th></th></tr>
    </thead>
    <tbody>
    <?php if (!$clientes): ?>
        <tr><td colspan="7">Nenhum cliente encontrado.</td></tr>
    <?php endif; ?>
    <?php foreach ($clientes as $c): ?>
        <tr>
            <td><?= e($c['nome'] ?: '(sem nome)') ?></td>
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
</main>
</body>
</html>
