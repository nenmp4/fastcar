<?php
/**
 * ⚙️ Histórico do veículo (30/10/2026, "dados do carro se cruzem —
 * consultor e vendedor — evitar duplicação, clique na engrenagem mostre
 * todo histórico do veículo, simples"). Ficha única do carro físico + linha
 * do tempo de tudo que aconteceu com ele (compra, vistorias, FIPE,
 * venda/locação, contratos, devolução), cruzando por placa/chassi. Avisa
 * quando o mesmo carro tem mais de um cadastro. Só leitura. Só super admin.
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../includes/veiculo_historico.php';

if (($_SESSION['admin_perfil'] ?? '') !== 'super_admin') {
    http_response_code(403);
    exit('Sem acesso.');
}

$id = (int)($_GET['id'] ?? 0);
$h = $id ? veiculoHistorico($id) : ['veiculo' => null, 'cadastros' => [], 'eventos' => [], 'pagamentos' => []];
$v = $h['veiculo'];
$voltar = (string)($_SERVER['HTTP_REFERER'] ?? '');
if (!preg_match('#^https?://[^/]+/admin/#', $voltar)) $voltar = paginaInicialPorPerfil($_SESSION['admin_perfil'] ?? '');
$cadastrosCompra = array_values(array_filter($h['cadastros'], fn($c) => $c['etapa'] === 'fechado'));
$vhEstilo = function () { ?>
<style>
.vh-ficha { display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:10px; margin-top:10px; }
.vh-ficha div { background:var(--fundo); border-radius:var(--raio-sm); padding:8px 10px; }
.vh-ficha small { display:block; color:var(--texto-fraco); font-size:12px; }
.vh-linha { list-style:none; margin:0; padding:0; border-left:3px solid var(--borda); }
.vh-linha li { position:relative; padding:0 0 16px 18px; }
.vh-linha li::before { content:''; position:absolute; left:-8px; top:4px; width:13px; height:13px; border-radius:50%; background:var(--azul); border:2px solid #fff; }
.vh-linha .vh-data { font-size:12px; color:var(--texto-fraco); }
.vh-linha a { color:inherit; text-decoration:none; display:block; }
.vh-linha a:hover strong { color:var(--azul); }
.vh-pag { display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:10px; background:var(--fundo); border-radius:var(--raio-sm); padding:10px 12px; margin-top:8px; }
.vh-pag small, .vh-pag-vazio { display:block; color:var(--texto-fraco); font-size:12px; }
.vh-lanc { margin:6px 0 10px; padding-left:18px; }
</style>
<?php };
$vhMiolo = function () use ($v, $h, $cadastrosCompra) { ?>
<?php if (!$v && !$h['cadastros']): ?>
    <div class="card"><h2>⚙️ Histórico do veículo</h2><p>Veículo não encontrado.</p></div>
<?php else: ?>
<div class="card">
    <h2>⚙️ <?= e(trim(($v['veiculo_marca'] ?? '') . ' ' . ($v['veiculo_modelo'] ?? '')) ?: 'Veículo') ?> <?= e((string)($v['veiculo_ano'] ?? '')) ?></h2>
    <div class="vh-ficha">
        <div><small>Placa</small><?php if (trim((string)($v['veiculo_placa'] ?? '')) !== ''): ?><span class="placa-mercosul" style="margin-top:4px"><span class="pm-faixa">BRASIL</span><span class="pm-num"><?= e(strtoupper((string)$v['veiculo_placa'])) ?></span></span><?php else: ?><strong>—</strong><?php endif; ?></div>
        <div><small>Chassi</small><?= e($v['veiculo_chassi'] ?? '') ?: '—' ?></div>
        <div><small>Renavam</small><?= e($v['veiculo_renavam'] ?? '') ?: '—' ?></div>
        <div><small>FIPE ref.</small><?= !empty($v['valor_fipe_referencia']) ? 'R$ ' . number_format((float)$v['valor_fipe_referencia'], 2, ',', '.') : '—' ?></div>
    </div>
    <?php if (count($h['cadastros']) > 1): ?>
        <div class="alerta-info" style="margin-top:12px">
            🔗 Esse carro aparece em <strong><?= count($h['cadastros']) ?> cadastros</strong>
            (<?php foreach ($h['cadastros'] as $i => $c): ?><?= $i ? ', ' : '' ?><a href="/admin/oportunidade.php?id=<?= (int)$c['id'] ?>">#<?= (int)$c['id'] ?> <?= e(etapaLabel($c['etapa'])) ?></a><?php endforeach; ?>)
            — o histórico abaixo junta todos.
            <?php if (count($cadastrosCompra) > 1): ?><br>⚠️ Tem <strong>mais de uma compra fechada</strong> pra mesma placa/chassi: se não foi o carro voltando pra Fastcar, é cadastro duplicado — peça pro super admin mesclar no 🚘 Estoque.<?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<?php if (!empty($h['pagamentos'])): ?>
<div class="card">
    <h3 style="margin-top:0">💸 Valores pagos ao vendedor</h3>
    <?php foreach ($h['pagamentos'] as $pg): ?>
        <div class="vh-pag">
            <div><small>Vendedor</small><a href="/admin/oportunidade.php?id=<?= $pg['oportunidade_id'] ?>"><?= e(mb_convert_case(mb_strtolower((string)$pg['vendedor']), MB_CASE_TITLE)) ?></a></div>
            <div><small>Valor da compra</small><strong><?= $pg['valor_final'] !== null ? 'R$ ' . number_format($pg['valor_final'], 2, ',', '.') : '—' ?></strong></div>
            <div><small>Data</small><?= $pg['data_compra'] ? e(date('d/m/Y', strtotime($pg['data_compra']))) : '—' ?></div>
            <div><small>PIX do vendedor</small><?= $pg['pix'] !== '' ? e($pg['pix']) : '—' ?></div>
        </div>
        <?php if ($pg['lancamentos']): ?>
            <ul class="vh-lanc">
            <?php foreach ($pg['lancamentos'] as $l): ?>
                <li><?= $l['status'] === 'pago' ? '✅' : '⏳' ?> R$ <?= number_format((float)$l['valor'], 2, ',', '.') ?>
                    <small>— <?= $l['status'] === 'pago' ? 'pago em ' . e(date('d/m/Y', strtotime((string)($l['data_pagamento'] ?: $l['data_vencimento'])))) : e($l['status']) . ($l['data_vencimento'] ? ' · vence ' . e(date('d/m/Y', strtotime($l['data_vencimento']))) : '') ?> · <?= e($l['descricao']) ?></small></li>
            <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <p class="vh-pag-vazio" style="margin:4px 0 10px">Nenhum pagamento lançado no financeiro pra essa compra.</p>
        <?php endif; ?>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="card">
    <h3 style="margin-top:0">🕘 Linha do tempo</h3>
    <?php if (!$h['eventos']): ?><p>Nada registrado ainda.</p><?php else: ?>
    <ul class="vh-linha">
        <?php foreach ($h['eventos'] as $ev): ?>
            <li><a href="<?= e($ev['url']) ?>">
                <span class="vh-data"><?= $ev['data'] ? e(date('d/m/Y H:i', strtotime($ev['data']))) : '' ?></span><br>
                <strong><?= e($ev['icone'] . ' ' . $ev['titulo']) ?></strong>
                <?php if ($ev['detalhe'] !== ''): ?><br><small><?= e($ev['detalhe']) ?></small><?php endif; ?>
            </a></li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</div>
<?php endif; ?>
<?php };
// ?parcial=1 → só o conteúdo, pro modal (admin/_historico_modal.php).
if (!empty($_GET['parcial'])) { $vhEstilo(); $vhMiolo(); exit; }
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Histórico do veículo — Fastcar CRM</title>
<link rel="stylesheet" href="/admin/assets/style.css?v=<?= @filemtime(__DIR__ . '/assets/style.css') ?: 1 ?>">
<link rel="stylesheet" href="/admin/assets/mobile.css?v=<?= @filemtime(__DIR__ . '/assets/mobile.css') ?: 1 ?>">
<script src="/admin/assets/mobile.js?v=<?= @filemtime(__DIR__ . '/assets/mobile.js') ?: 1 ?>" defer></script>
<?php include __DIR__ . '/_pwa_head.php'; ?>
<?php $vhEstilo(); ?>
</head>
<body>
<?php include __DIR__ . '/_impersonando_banner.php'; ?>
<header class="topbar">
    <a href="<?= e($voltar) ?>" style="color:#fff">← Voltar</a>
    <a class="topbar-brand" href="<?= e(paginaInicialPorPerfil($_SESSION['admin_perfil'] ?? '')) ?>"><img class="topbar-logo" src="/admin/assets/img/icon-192.png" alt="Fastcar" onerror="this.style.display='none'"><span class="topbar-wordmark">Fast<b>Car</b></span></a>
    <span>Olá, <?= e($_SESSION['admin_nome']) ?></span>
    <a href="/admin/logout.php">Sair</a>
</header>
<main>
<?php $vhMiolo(); ?>
</main>
<?php include __DIR__ . '/_pwa_register.php'; ?>
<?php include __DIR__ . '/_notify.php'; ?>
<?php include __DIR__ . '/_scroll_restore.php'; ?>
<?php include __DIR__ . '/_acao_popup.php'; ?>
<?php include __DIR__ . '/_confirm_dialog.php'; ?>
<?php include __DIR__ . '/_zapi_status.php'; ?>
</body>
</html>
