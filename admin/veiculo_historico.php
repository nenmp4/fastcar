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
$h = $id ? veiculoHistorico($id) : ['veiculo' => null, 'cadastros' => [], 'eventos' => [], 'pagamentos' => [], 'resumo' => null];
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

<?php $rs = $h['resumo'] ?? null; $db_ = $h['debitos'] ?? null; ?>
<?php if ($rs): ?>
<div class="card">
    <h3 style="margin-top:0">📋 Resumo</h3>
    <div class="vh-pag">
        <div><small>Dono atual</small><strong><?= e($rs['dono_atual']) ?></strong></div>
        <div><small>Tempo com a Fastcar</small><strong><?php if ($rs['dias_fastcar'] === null): ?>—<?php else: $d = (int)$rs['dias_fastcar']; ?><?= $d >= 60 ? floor($d / 30.44) . ' mês(es)' : $d . ' dia(s)' ?><?php endif; ?></strong>
            <?php if ($rs['inicio_fastcar']): ?><small>desde <?= e(date('d/m/Y', strtotime($rs['inicio_fastcar']))) ?><?= $rs['fim_fastcar'] ? ' até ' . e(date('d/m/Y', strtotime($rs['fim_fastcar']))) . ' (vendido)' : ' · ainda no estoque' ?></small><?php endif; ?></div>
        <?php if ($db_): $totDeb = (float)($db_['ipva'] ?? 0) + (float)($db_['licenciamento'] ?? 0) + (float)($db_['multas'] ?? 0); ?>
        <div><small>Débitos do veículo</small><strong><?= ($db_['ipva'] === null && $db_['licenciamento'] === null && $db_['multas'] === null) ? '— não informado' : 'R$ ' . number_format($totDeb, 2, ',', '.') ?></strong>
            <small>IPVA <?= $db_['ipva'] !== null ? 'R$ ' . number_format($db_['ipva'], 2, ',', '.') : '—' ?> · Licenc. <?= $db_['licenciamento'] !== null ? 'R$ ' . number_format($db_['licenciamento'], 2, ',', '.') : '—' ?> · Multas <?= $db_['multas'] !== null ? 'R$ ' . number_format($db_['multas'], 2, ',', '.') : '—' ?></small>
            <?php if ($db_['obs'] !== ''): ?><small><?= e($db_['obs']) ?></small><?php endif; ?></div>
        <?php endif; ?>
        <?php $totCom = array_sum(array_map(fn($c) => (float)$c['valor'], $h['comissoes'] ?? [])); ?>
        <div><small>Comissões</small><strong><?= $totCom > 0 ? 'R$ ' . number_format($totCom, 2, ',', '.') : '—' ?></strong>
            <?php foreach ($h['comissoes'] ?? [] as $cm): ?><small><?= $cm['origem'] === 'comissao_venda' ? '🛒 vendedor' : '🚗 consultor' ?> <?= e($cm['colaborador_nome'] ?: '') ?>: R$ <?= number_format((float)$cm['valor'], 2, ',', '.') ?> <?= $cm['status'] === 'pago' ? '✅ paga' : '⏳ ' . e($cm['status']) ?></small><?php endforeach; ?></div>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($h['negociacoes'])): ?>
<div class="card">
    <h3 style="margin-top:0">🛒 Negociações de venda / locação</h3>
    <?php foreach ($h['negociacoes'] as $n): $rec = $n['receita']; ?>
        <div class="vh-pag">
            <div><small>#<?= (int)$n['id'] ?> · <?= e(etapaVendaLabel($n['etapa'], $n['tipo_contrato'] ?? 'venda')) ?></small><a href="/admin/venda.php?id=<?= (int)$n['id'] ?>"><?= e($n['comprador_nome'] ?: ($n['comprador_telefone'] ?: '— sem comprador')) ?></a>
                <small><?= $n['vendedor_nome'] ? 'vendedor: ' . e($n['vendedor_nome']) : '' ?></small></div>
            <div><small>Preço</small><strong><?= $n['preco_venda'] ? 'R$ ' . number_format((float)$n['preco_venda'], 2, ',', '.') : '—' ?></strong>
                <small>entrada <?= $n['valor_pago_contratacao'] ? 'R$ ' . number_format((float)$n['valor_pago_contratacao'], 2, ',', '.') : '—' ?><?= !empty($n['parcelamento_qtd_parcelas']) ? ' · ' . (int)$n['parcelamento_qtd_parcelas'] . 'x de R$ ' . number_format((float)$n['parcelamento_valor_parcela'], 2, ',', '.') . (($n['parcelamento_periodicidade'] ?? '') === 'semanal' ? ' (semanal)' : '') : '' ?></small></div>
            <div><small>Contrato</small><?= $n['contrato_assinado_em'] ? '✍️ assinado ' . e(date('d/m/Y', strtotime($n['contrato_assinado_em']))) : '— não assinado' ?>
                <?php if ($n['etapa'] === 'cancelada'): ?><small>🔙 <?= e($n['motivo_cancelamento'] ?: 'cancelada') ?></small><?php endif; ?></div>
            <div><small>Recebido / a receber</small><?php if ($rec): ?><strong>R$ <?= number_format((float)$rec['recebido'], 2, ',', '.') ?></strong>
                <small>a receber R$ <?= number_format((float)$rec['a_receber'], 2, ',', '.') ?><?= (float)$rec['atrasado'] > 0 ? ' · ⚠️ atrasado R$ ' . number_format((float)$rec['atrasado'], 2, ',', '.') : '' ?></small><?php else: ?>— nada no financeiro<?php endif; ?></div>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

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
