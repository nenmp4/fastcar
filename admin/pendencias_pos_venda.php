<?php
/**
 * Painel de pendências pós-venda (regra #8 do CLAUDE.md) — acompanhamento
 * de pendência operacional que sobra depois de uma pasta já fechada (ex:
 * quitação de financiamento junto ao banco, transferência do veículo),
 * separado do funil comercial que já encerrou. Achado real, 16/09/2026:
 * cliente reclamando de financiamento não quitado de um carro JÁ vendido
 * pra Fastcar, sem lugar nenhum no sistema pra registrar/acompanhar isso
 * — a tabela existia no schema desde o início mas nunca teve tela.
 *
 * Mesmo padrão "Minhas/Todas" do funil de compra: super_admin/supervisor
 * veem todas, consultor só as que é responsavel_id.
 */

require_once __DIR__ . '/_bootstrap.php';

$souDono = !perfilVeTudo();
$responsavelFiltro = $souDono ? (int)$_SESSION['admin_id'] : null;
$pendencias = listarPendenciasPosVendaAbertas($responsavelFiltro);
$atrasadas = array_filter($pendencias, fn($p) => (bool)$p['atrasada']);
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Pendências pós-venda — Fastcar CRM</title>
<link rel="stylesheet" href="/admin/assets/style.css?v=<?= @filemtime(__DIR__ . '/assets/style.css') ?: 1 ?>">
<?php include __DIR__ . '/_pwa_head.php'; ?>
</head>
<body>
<header class="topbar">
    <a href="/admin/index.php" style="color:#fff">← Voltar</a>
    <strong><img class="topbar-logo" src="/admin/assets/img/icon-192.png" alt="Fastcar" onerror="this.style.display='none'"> Fast<b>Car</b></strong>
    <span>Olá, <?= e($_SESSION['admin_nome']) ?></span>
    <a href="/admin/logout.php">Sair</a>
</header>

<main>
<div class="card">
    <h2>📋 Pendências pós-venda</h2>
    <p><small>Pasta já fechada (bloco 8), mas com pendência operacional em aberto — ex: quitação de financiamento
       junto ao banco, transferência do veículo. <?= $souDono ? 'Mostrando só as suas.' : 'Visão geral — todas as pendências abertas.' ?></small></p>
</div>

<div class="stat-grid">
    <div class="stat-card">
        <div class="valor"><?= count($pendencias) ?></div>
        <div class="rotulo">Pendências abertas</div>
    </div>
    <div class="stat-card <?= $atrasadas ? 'aviso' : '' ?>">
        <div class="valor"><?= count($atrasadas) ?></div>
        <div class="rotulo">Atrasadas (prazo estimado já passou)</div>
    </div>
</div>

<div class="card">
    <table class="tabela-oportunidades">
        <thead>
            <tr><th>Cliente</th><th>Veículo</th><th>Pendência</th><th>Prazo estimado</th><th>Responsável</th><th></th></tr>
        </thead>
        <tbody>
        <?php if (!$pendencias): ?>
            <tr><td colspan="6">Nenhuma pendência em aberto <?= $souDono ? 'atribuída a você' : '' ?>.</td></tr>
        <?php endif; ?>
        <?php foreach ($pendencias as $p): ?>
            <tr<?= $p['atrasada'] ? ' style="background:#fff3f3"' : '' ?>>
                <td><a href="/admin/cliente_detalhe.php?id=<?= (int)$p['cliente_id'] ?>"><?= e($p['cliente_nome']) ?></a><br><small><?= e($p['cliente_telefone']) ?></small></td>
                <td><?= e(trim(($p['veiculo_marca'] ?? '') . ' ' . ($p['veiculo_modelo'] ?? ''))) ?: '—' ?><?php if ($p['veiculo_placa']): ?><br><small><?= e($p['veiculo_placa']) ?></small><?php endif; ?></td>
                <td><?= e($p['descricao']) ?></td>
                <td><?= $p['prazo_estimado'] ? date('d/m/Y', strtotime($p['prazo_estimado'])) : '—' ?><?= $p['atrasada'] ? ' <span class="badge badge-atraso">atrasada</span>' : '' ?></td>
                <td><?= e($p['responsavel_nome'] ?: '— sem responsável —') ?></td>
                <td><a href="/admin/oportunidade.php?id=<?= (int)$p['oportunidade_id'] ?>">Abrir →</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
</main>
<?php include __DIR__ . '/_pwa_register.php'; ?>
<?php include __DIR__ . '/_notify.php'; ?>
<?php include __DIR__ . '/_zapi_status.php'; ?>
</body>
</html>
