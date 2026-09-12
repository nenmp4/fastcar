<?php
/**
 * Dashboard — lista de oportunidades abertas, agrupável por etapa.
 * "Toda oportunidade aberta precisa de responsável e próxima ação, com
 * alerta de atraso" (regra #5 do CLAUDE.md) — linha atrasada fica
 * destacada aqui mesmo, sem esperar o cron avisar por WhatsApp.
 */

require_once __DIR__ . '/_bootstrap.php';

$db = getDB();
$etapaFiltro = (string)($_GET['etapa'] ?? '');
$placeholders = implode(',', array_fill(0, count(ETAPAS_ATIVAS), '?'));

$sql = "
    SELECT o.*, c.nome AS cliente_nome, c.telefone AS cliente_telefone,
           u.nome AS responsavel_nome
    FROM oportunidades o
    JOIN clientes c ON c.id = o.cliente_id
    LEFT JOIN usuarios u ON u.id = o.responsavel_id
    WHERE o.etapa IN ({$placeholders})
";
$params = ETAPAS_ATIVAS;
if ($etapaFiltro && in_array($etapaFiltro, ETAPAS_ATIVAS, true)) {
    $sql .= " AND o.etapa = ?";
    $params[] = $etapaFiltro;
}
$sql .= " ORDER BY (o.proxima_acao_em IS NULL), o.proxima_acao_em ASC, o.updated_at DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$oportunidades = $stmt->fetchAll();

$stmtContagem = $db->prepare("
    SELECT etapa, COUNT(*) AS total FROM oportunidades
    WHERE etapa IN ({$placeholders}) GROUP BY etapa
");
$stmtContagem->execute(ETAPAS_ATIVAS);
$contagemPorEtapa = array_column($stmtContagem->fetchAll(), 'total', 'etapa');
$totalAtivas = array_sum($contagemPorEtapa);

$agora = date('Y-m-d H:i:s');
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Fastcar CRM</title>
<link rel="stylesheet" href="/admin/assets/style.css">
</head>
<body>
<header class="topbar">
    <strong>🚗 Fastcar CRM</strong>
    <span>Olá, <?= e($_SESSION['admin_nome']) ?> (<?= e($_SESSION['admin_perfil']) ?>)</span>
    <?php if ($_SESSION['admin_perfil'] === 'super_admin'): ?>
        <a href="/admin/produtividade.php">📊 Produtividade</a>
        <a href="/admin/configuracoes.php">⚙️ Configurações</a>
    <?php endif; ?>
    <a href="/admin/logout.php">Sair</a>
</header>

<nav class="etapas-nav">
    <a href="/admin/index.php" class="<?= $etapaFiltro === '' ? 'ativo' : '' ?>">Todas (<?= (int)$totalAtivas ?>)</a>
    <?php foreach (ETAPAS_ATIVAS as $et): ?>
        <a href="/admin/index.php?etapa=<?= urlencode($et) ?>" class="<?= $etapaFiltro === $et ? 'ativo' : '' ?>">
            <?= e(etapaLabel($et)) ?> (<?= (int)($contagemPorEtapa[$et] ?? 0) ?>)
        </a>
    <?php endforeach; ?>
</nav>

<main>
<table class="tabela-oportunidades">
    <thead>
        <tr>
            <th>Cliente</th><th>Veículo</th><th>Etapa</th><th>Responsável</th><th>Próxima ação</th><th></th>
        </tr>
    </thead>
    <tbody>
    <?php if (!$oportunidades): ?>
        <tr><td colspan="6">Nenhuma oportunidade nessa etapa.</td></tr>
    <?php endif; ?>
    <?php foreach ($oportunidades as $op): ?>
        <?php $atrasada = $op['proxima_acao_em'] && $op['proxima_acao_em'] < $agora; ?>
        <tr class="<?= $atrasada ? 'linha-atrasada' : '' ?>">
            <td>
                <a href="/admin/oportunidade.php?id=<?= (int)$op['id'] ?>"><?= e($op['cliente_nome'] ?: '(sem nome)') ?></a>
                <br><small><?= e($op['cliente_telefone']) ?></small>
            </td>
            <td><?= e($op['veiculo_modelo'] ?: '—') ?> <?= e($op['veiculo_ano']) ?></td>
            <td><?= e(etapaLabel($op['etapa'])) ?></td>
            <td><?= e($op['responsavel_nome'] ?? '—') ?></td>
            <td>
                <?php if ($op['proxima_acao_em']): ?>
                    <span class="badge <?= $atrasada ? 'badge-atraso' : '' ?>">
                        <?= $atrasada ? '⚠️ ' : '' ?><?= date('d/m H:i', strtotime($op['proxima_acao_em'])) ?>
                    </span>
                    <br><small><?= e($op['proxima_acao']) ?></small>
                <?php else: ?>
                    <span class="sem-proxima-acao">sem próxima ação</span>
                <?php endif; ?>
            </td>
            <td><a href="/admin/oportunidade.php?id=<?= (int)$op['id'] ?>">Abrir →</a></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</main>
</body>
</html>
