<?php
/**
 * Fila de trabalho do módulo de checklist de vistoria/avaliação do veículo
 * (includes/veiculo_avaliacoes.php) — mesmo espírito de admin/vendas.php
 * pro perfil `vendedor`: home page do perfil `avaliador` (login.php já
 * manda direto pra cá), mas também acessível a super_admin/supervisor
 * (veem tudo) e consultor/vendedor (só usam pra atribuir/acompanhar as
 * próprias vistorias — o card fica em admin/oportunidade.php/admin/venda.php,
 * essa tela aqui é o painel geral, mais útil pro avaliador e pra gestão).
 */

require_once __DIR__ . '/_bootstrap.php';
requireAcessoAvaliacoes();

$perfil = $_SESSION['admin_perfil'];
$meuId = (int)$_SESSION['admin_id'];
$vejoTudo = perfilVeTudo(); // super_admin/supervisor

// avaliador só vê as próprias; super_admin/supervisor veem todas;
// consultor/vendedor também veem todas (precisam achar a do próprio
// negócio pra acompanhar, não têm "carteira de avaliador" própria).
$filtroAvaliador = ($perfil === 'avaliador' && !$vejoTudo) ? $meuId : null;

$aba = (string)($_GET['aba'] ?? 'pendentes');
$pendentes = listarAvaliacoesPendentes($filtroAvaliador);
$concluidas = $aba === 'concluidas' ? listarAvaliacoesConcluidas($filtroAvaliador) : [];

function avTipoPill(string $tipo): string {
    $rotulo = $tipo === 'venda' ? '🛒 VENDA' : '🚗 COMPRA';
    return '<span class="av-tipo-pill tipo-' . e($tipo) . '">' . $rotulo . '</span>';
}
function avStatusBadge(string $status): string {
    return match ($status) {
        'concluida'    => '<span class="badge badge-ok">✅ Concluída</span>',
        'em_andamento' => '<span class="badge badge-info">🔧 Em andamento</span>',
        default        => '<span class="badge badge-aviso">⏳ Pendente</span>',
    };
}
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Avaliações/Vistoria — Fastcar CRM</title>
<link rel="stylesheet" href="/admin/assets/style.css?v=<?= @filemtime(__DIR__ . '/assets/style.css') ?: 1 ?>">
<?php include __DIR__ . '/_pwa_head.php'; ?>
</head>
<body>
<header class="topbar">
    <?php if ($perfil !== 'avaliador'): ?><a href="/admin/index.php" style="color:#fff">← Voltar</a><?php endif; ?>
    <strong><img class="topbar-logo" src="/admin/assets/img/icon-192.png" alt="Fastcar" onerror="this.style.display='none'"> Fast<b>Car</b></strong>
    <span>Olá, <?= e($_SESSION['admin_nome']) ?></span>
    <a href="/admin/meu_perfil.php">🙋 Meu perfil</a>
    <a href="/admin/logout.php">Sair</a>
</header>

<nav class="etapas-nav">
    <a href="/admin/avaliacoes.php?aba=pendentes" class="<?= $aba !== 'concluidas' ? 'ativo' : '' ?>">⏳ Pendentes/em andamento (<?= count($pendentes) ?>)</a>
    <a href="/admin/avaliacoes.php?aba=concluidas" class="<?= $aba === 'concluidas' ? 'ativo' : '' ?>">✅ Concluídas</a>
</nav>

<main>
<div class="card">
    <h2><?= $aba === 'concluidas' ? '✅ Vistorias concluídas' : '⏳ Vistorias pendentes / em andamento' ?></h2>
    <?php $lista = $aba === 'concluidas' ? $concluidas : $pendentes; ?>
    <?php if (!$lista): ?>
        <p><small>Nenhuma vistoria <?= $aba === 'concluidas' ? 'concluída' : 'pendente' ?> <?= $filtroAvaliador ? 'atribuída a você' : '' ?> no momento.</small></p>
    <?php else: ?>
        <div style="overflow-x:auto">
        <table>
            <thead><tr><th>Veículo</th><th>Tipo</th><th>Status</th><th>Avaliador</th><th>Criada em</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($lista as $a): ?>
                <tr>
                    <td><?= e(trim($a['veiculo_marca'] . ' ' . $a['veiculo_modelo'])) ?: '—' ?> <?= e((string)($a['veiculo_ano'] ?? '')) ?><br><small><?= e($a['cliente_nome']) ?></small></td>
                    <td><?= avTipoPill($a['tipo']) ?></td>
                    <td><?= avStatusBadge($a['status']) ?></td>
                    <td><?= $a['avaliador_nome'] ? e($a['avaliador_nome']) : '<em>não atribuído</em>' ?></td>
                    <td><?= date('d/m/Y H:i', strtotime($a['created_at'])) ?></td>
                    <td><a class="btn-primary" href="/admin/avaliacao.php?id=<?= (int)$a['id'] ?>">Abrir →</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</div>
</main>
<?php include __DIR__ . '/_pwa_register.php'; ?>
<?php include __DIR__ . '/_notify.php'; ?>
</body>
</html>
