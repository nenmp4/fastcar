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

// $souAvaliador controla só o FILTRO da fila (logo abaixo) — cada avaliador
// vê só a própria carteira. Criar vistoria é sempre ação de
// consultor/vendedor (card em admin/oportunidade.php/admin/venda.php, com
// avaliador_id obrigatório na hora de criar) — o botão self-atribuído
// "➕ Nova vistoria" que existia aqui (28/09/2026) foi removido em
// 08/10/2026, "está confudindo", junto com o modal de busca/cadastro de
// veículo e o endpoint de histórico por placa que só ele usava.
$souAvaliador = $perfil === 'avaliador';

$erro = '';

// avaliador só vê as próprias; super_admin/supervisor veem todas;
// consultor/vendedor também veem todas (precisam achar a do próprio
// negócio pra acompanhar, não têm "carteira de avaliador" própria).
$filtroAvaliador = ($souAvaliador && !$vejoTudo) ? $meuId : null;

$aba = (string)($_GET['aba'] ?? 'pendentes');
// 05/10/2026, "listagem de veículos vistoriados pra ele conferir todas
// suas vistorias" — busca por placa/marca/modelo/cliente + paginação de
// verdade na aba Concluídas (antes era um LIMIT 100 fixo, sem jeito de
// ver o que ficasse além disso nem de achar uma vistoria antiga
// específica sem rolar a lista inteira).
$busca = trim((string)($_GET['q'] ?? ''));
$pendentes = listarAvaliacoesPendentes($filtroAvaliador);
$totalConcluidas = 0;
$concluidas = [];
if ($aba === 'concluidas') {
    $totalConcluidas = contarAvaliacoesConcluidas($filtroAvaliador, $busca);
    $concluidas = listarAvaliacoesConcluidas($filtroAvaliador, $busca, ITENS_POR_PAGINA_PADRAO, paginacaoOffset());
}

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
<link rel="stylesheet" href="/admin/assets/mobile.css?v=<?= @filemtime(__DIR__ . '/assets/mobile.css') ?: 1 ?>">
<script src="/admin/assets/mobile.js?v=<?= @filemtime(__DIR__ . '/assets/mobile.js') ?: 1 ?>" defer></script>
<?php include __DIR__ . '/_pwa_head.php'; ?>
</head>
<body>
<?php include __DIR__ . '/_impersonando_banner.php'; ?>
<header class="topbar">
    <?php if ($perfil !== 'avaliador'): ?><a href="/admin/index.php" style="color:#fff">← Voltar</a><?php endif; ?>
    <a class="topbar-brand" href="<?= e(paginaInicialPorPerfil($_SESSION['admin_perfil'] ?? '')) ?>"><img class="topbar-logo" src="/admin/assets/img/icon-192.png" alt="Fastcar" onerror="this.style.display='none'"><span class="topbar-wordmark">Fast<b>Car</b></span></a>
    <span>Olá, <?= e($_SESSION['admin_nome']) ?></span>
    <a href="/admin/meu_perfil.php">🙋 Meu perfil</a>
    <a href="/admin/logout.php">Sair</a>
</header>

<nav class="etapas-nav">
    <a href="/admin/avaliacoes.php?aba=pendentes" class="<?= $aba !== 'concluidas' ? 'ativo' : '' ?>">⏳ Pendentes/em andamento (<?= count($pendentes) ?>)</a>
    <a href="/admin/avaliacoes.php?aba=concluidas" class="<?= $aba === 'concluidas' ? 'ativo' : '' ?>">✅ Concluídas</a>
</nav>

<main>
<?php if ($erro): ?><div class="alerta-erro"><?= e($erro) ?></div><?php endif; ?>

<div class="card">
    <h2><?= $aba === 'concluidas' ? '✅ Vistorias concluídas' : '⏳ Vistorias pendentes / em andamento' ?></h2>
    <?php if ($aba === 'concluidas'): ?>
    <form method="get" style="margin-bottom:1rem;display:flex;gap:8px;flex-wrap:wrap">
        <input type="hidden" name="aba" value="concluidas">
        <input type="text" name="q" value="<?= e($busca) ?>" placeholder="Buscar por placa, marca, modelo ou cliente..." style="flex:1;min-width:220px">
        <button type="submit" style="width:auto">Buscar</button>
        <?php if ($busca): ?><a href="/admin/avaliacoes.php?aba=concluidas" class="btn-texto" style="align-self:center">Limpar</a><?php endif; ?>
    </form>
    <?php endif; ?>
    <?php $lista = $aba === 'concluidas' ? $concluidas : $pendentes; ?>
    <?php if (!$lista): ?>
        <p><small>Nenhuma vistoria <?= $aba === 'concluidas' ? 'concluída' : 'pendente' ?> <?= $filtroAvaliador ? 'atribuída a você' : '' ?><?= $busca ? ' pra essa busca' : '' ?> no momento.</small></p>
    <?php else: ?>
        <div style="overflow-x:auto">
        <table>
            <thead><tr><th>Veículo</th><th>Placa</th><th>Tipo</th><th>Status</th><th>Avaliador</th><th><?= $aba === 'concluidas' ? 'Concluída em' : 'Criada em' ?></th><th></th></tr></thead>
            <tbody>
            <?php foreach ($lista as $a): ?>
                <tr>
                    <td><?= $a['tipo_veiculo'] === 'moto' ? '🏍️' : '🚗' ?> <?= e(trim($a['veiculo_marca'] . ' ' . $a['veiculo_modelo'])) ?: '—' ?> <?= e((string)($a['veiculo_ano'] ?? '')) ?><br><small><?= e($a['cliente_nome']) ?></small></td>
                    <td><?= e($a['veiculo_placa'] ?: '—') ?></td>
                    <td><?= avTipoPill($a['tipo']) ?></td>
                    <td><?= avStatusBadge($a['status']) ?></td>
                    <td><?= $a['avaliador_nome'] ? e($a['avaliador_nome']) : '<em>não atribuído</em>' ?></td>
                    <td><?php
                        $dataLinha = $aba === 'concluidas' ? ($a['concluida_em'] ?: $a['created_at']) : $a['created_at'];
                        echo date('d/m/Y H:i', strtotime($dataLinha));
                    ?></td>
                    <td><a class="btn-primary" href="/admin/avaliacao.php?id=<?= (int)$a['id'] ?>">Abrir →</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php if ($aba === 'concluidas') renderPaginacao($totalConcluidas); ?>
    <?php endif; ?>
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
