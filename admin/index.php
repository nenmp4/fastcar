<?php
/**
 * Dashboard — lista de oportunidades abertas, agrupável por etapa.
 * "Toda oportunidade aberta precisa de responsável e próxima ação, com
 * alerta de atraso" (regra #5 do CLAUDE.md) — linha atrasada fica
 * destacada aqui mesmo, sem esperar o cron avisar por WhatsApp.
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../includes/dashboard.php';

$db = getDB();
$perfil = $_SESSION['admin_perfil'];
$meuId = (int)$_SESSION['admin_id'];
// Consultor só vê as próprias oportunidades no funil — cuida da carteira
// dele (atendimento + negociação, blocos 5-6 mesclados); super_admin vê a
// empresa inteira (visão geral).
$souDono = $perfil === 'consultor';

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
if ($souDono) {
    $sql .= " AND o.responsavel_id = ?";
    $params[] = $meuId;
}
if ($etapaFiltro && in_array($etapaFiltro, ETAPAS_ATIVAS, true)) {
    $sql .= " AND o.etapa = ?";
    $params[] = $etapaFiltro;
}
$sql .= " ORDER BY (o.proxima_acao_em IS NULL), o.proxima_acao_em ASC, o.updated_at DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$oportunidades = $stmt->fetchAll();

$sqlContagem = "SELECT etapa, COUNT(*) AS total FROM oportunidades WHERE etapa IN ({$placeholders})";
$paramsContagem = ETAPAS_ATIVAS;
if ($souDono) {
    $sqlContagem .= " AND responsavel_id = ?";
    $paramsContagem[] = $meuId;
}
$sqlContagem .= " GROUP BY etapa";
$stmtContagem = $db->prepare($sqlContagem);
$stmtContagem->execute($paramsContagem);
$contagemPorEtapa = array_column($stmtContagem->fetchAll(), 'total', 'etapa');
$totalAtivas = array_sum($contagemPorEtapa);

$stats = match ($perfil) {
    'consultor'   => dashboardConsultor($meuId),
    'super_admin' => dashboardSuperAdmin(),
    default       => [],
};

$agora = date('Y-m-d H:i:s');

function moeda(float $v): string { return 'R$ ' . number_format($v, 2, ',', '.'); }
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Fastcar CRM</title>
<link rel="stylesheet" href="/admin/assets/style.css?v=<?= @filemtime(__DIR__ . '/assets/style.css') ?: 1 ?>">
<?php include __DIR__ . '/_pwa_head.php'; ?>
</head>
<body>
<header class="topbar">
    <strong><img class="topbar-logo" src="/admin/assets/img/icon-192.png" alt="Fastcar" onerror="this.style.display='none'"> Fast<b>Car</b> <span class="crm-tag">CRM</span></strong>
    <span>Olá, <?= e($_SESSION['admin_nome']) ?> (<?= e($_SESSION['admin_perfil']) ?>)</span>
    <?php if ($_SESSION['admin_perfil'] === 'consultor'): ?>
        <?php $euAtual = buscarUsuario((int)$_SESSION['admin_id']); ?>
        <form method="post" action="/admin/toggle_disponivel.php" class="inline">
            <?= csrfField() ?>
            <input type="hidden" name="voltar" value="/admin/index.php">
            <button type="submit" style="margin-top:0;padding:4px 10px;font-size:12px">
                <?= $euAtual['disponivel'] ? '🟢 Disponível' : '⚪ Offline' ?>
            </button>
        </form>
    <?php endif; ?>
    <a href="/admin/clientes.php">👥 Clientes</a>
    <?php if ($_SESSION['admin_perfil'] === 'super_admin'): ?>
        <a href="/admin/produtividade.php">📊 Produtividade</a>
        <a href="/admin/origem_leads.php">📣 Origem dos leads</a>
        <a href="/admin/qualidade_ia.php">🤖 Qualidade da IA</a>
        <a href="/admin/usuarios.php">👤 Usuários</a>
        <a href="/admin/backup.php">💾 Backup</a>
        <a href="/admin/saude.php">🩺 Saúde do sistema</a>
        <a href="/admin/configuracoes.php">⚙️ Configurações</a>
    <?php endif; ?>
    <a href="/admin/logout.php">Sair</a>
</header>

<nav class="etapas-nav">
    <a href="/admin/index.php" class="<?= $etapaFiltro === '' ? 'ativo' : '' ?>"><?= $souDono ? 'Minhas' : 'Todas' ?> (<?= (int)$totalAtivas ?>)</a>
    <?php foreach (ETAPAS_ATIVAS as $et): ?>
        <a href="/admin/index.php?etapa=<?= urlencode($et) ?>" class="<?= $etapaFiltro === $et ? 'ativo' : '' ?>">
            <?= e(etapaLabel($et)) ?> (<?= (int)($contagemPorEtapa[$et] ?? 0) ?>)
        </a>
    <?php endforeach; ?>
</nav>

<main>

<?php if ($perfil === 'consultor'): ?>
    <div class="stat-grid">
        <div class="stat-card">
            <div class="valor"><?= (int)$stats['ativas'] ?></div>
            <div class="rotulo">Minhas oportunidades ativas</div>
        </div>
        <div class="stat-card <?= $stats['atrasadas'] > 0 ? 'alerta' : '' ?>">
            <div class="valor"><?= (int)$stats['atrasadas'] ?></div>
            <div class="rotulo">Atrasadas</div>
        </div>
        <div class="stat-card neutro">
            <div class="valor"><?= (int)$stats['recebidas_semana'] ?></div>
            <div class="rotulo">Recebidas nos últimos 7 dias</div>
        </div>
        <div class="stat-card <?= $stats['disponivel'] ? 'sucesso' : 'neutro' ?>">
            <div class="valor"><?= $stats['disponivel'] ? '🟢' : '⚪' ?></div>
            <div class="rotulo"><?= $stats['disponivel'] ? 'Disponível pra fila' : ($stats['plantao'] ? 'Offline (plantão)' : 'Offline') ?></div>
        </div>
        <div class="stat-card">
            <div class="valor"><?= (int)$stats['em_negociacao'] ?></div>
            <div class="rotulo">Em negociação/presencial</div>
        </div>
        <div class="stat-card neutro">
            <div class="valor"><?= moeda($stats['valor_em_negociacao']) ?></div>
            <div class="rotulo">Valor em negociação</div>
        </div>
        <div class="stat-card sucesso">
            <div class="valor"><?= (int)$stats['fechadas_mes'] ?></div>
            <div class="rotulo">Fechadas este mês</div>
        </div>
        <div class="stat-card sucesso">
            <div class="valor"><?= moeda($stats['valor_fechado_mes']) ?></div>
            <div class="rotulo">Valor fechado este mês</div>
        </div>
        <div class="stat-card neutro">
            <div class="valor"><?= $stats['taxa_conversao'] === null ? '—' : $stats['taxa_conversao'] . '%' ?></div>
            <div class="rotulo">Taxa de conversão</div>
        </div>
    </div>
<?php elseif ($perfil === 'super_admin'): ?>
    <div class="stat-grid">
        <div class="stat-card">
            <div class="valor"><?= (int)$stats['ativas'] ?></div>
            <div class="rotulo">Oportunidades ativas</div>
        </div>
        <div class="stat-card <?= $stats['atrasadas'] > 0 ? 'alerta' : '' ?>">
            <div class="valor"><?= (int)$stats['atrasadas'] ?></div>
            <div class="rotulo">Atrasadas</div>
        </div>
        <div class="stat-card neutro">
            <div class="valor"><?= (int)$stats['novas_hoje'] ?></div>
            <div class="rotulo">Leads novos hoje</div>
        </div>
        <div class="stat-card neutro">
            <div class="valor"><?= (int)$stats['novas_semana'] ?></div>
            <div class="rotulo">Leads novos (7 dias)</div>
        </div>
        <div class="stat-card sucesso">
            <div class="valor"><?= (int)$stats['fechadas_mes'] ?></div>
            <div class="rotulo">Fechadas este mês</div>
        </div>
        <div class="stat-card sucesso">
            <div class="valor"><?= moeda($stats['valor_fechado_mes']) ?></div>
            <div class="rotulo">Valor fechado este mês</div>
        </div>
        <div class="stat-card neutro">
            <div class="valor"><?= $stats['taxa_conversao'] === null ? '—' : $stats['taxa_conversao'] . '%' ?></div>
            <div class="rotulo">Taxa de conversão geral</div>
        </div>
    </div>

    <div class="card">
        <h3>Funil (oportunidades ativas)</h3>
        <div class="funil-barra">
            <?php foreach (ETAPAS_ATIVAS as $et): ?>
                <?php $qtd = (int)($contagemPorEtapa[$et] ?? 0); $pct = $totalAtivas > 0 ? round($qtd / $totalAtivas * 100) : 0; ?>
                <div class="funil-linha">
                    <span class="etapa-nome"><?= e(etapaLabel($et)) ?></span>
                    <span class="barra-fundo"><span class="barra-preenchida" style="width:<?= $pct ?>%"></span></span>
                    <span class="qtd"><?= $qtd ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

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
<?php include __DIR__ . '/_pwa_register.php'; ?>
<?php include __DIR__ . '/_notify.php'; ?>
</body>
</html>
