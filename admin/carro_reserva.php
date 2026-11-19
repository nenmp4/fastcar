<?php
/**
 * Carro reserva de um contrato (19/11/2026) — liberar outro carro da frota
 * quando o banco tomou o do cliente (busca e apreensão), colher a assinatura
 * do termo NA TELA e registrar a devolução. Regras em includes/carro_reserva.php.
 * Entrada: Frota (botão "🔑 Carro reserva") e o card da venda.
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../includes/carro_reserva.php';
requireAcessoVendas();

$db = getDB();
$vendaId = (int)($_GET['venda'] ?? 0);
$st = $db->prepare("
    SELECT v.id, v.etapa, v.tipo_contrato, v.comprador_nome, v.comprador_cpf, v.comprador_telefone, v.oportunidade_id,
           o.veiculo_marca, o.veiculo_modelo, o.veiculo_ano, o.veiculo_placa, o.busca_apreensao_em, o.busca_apreensao_obs
    FROM vendas v LEFT JOIN oportunidades o ON o.id = v.oportunidade_id WHERE v.id = ?
");
$st->execute([$vendaId]);
$venda = $st->fetch();
if (!$venda) {
    http_response_code(404);
    exit('Negociação não encontrada.');
}

$erro = '';
$sucesso = '';
$somenteLeitura = ($_SESSION['admin_perfil'] ?? '') === 'supervisor';
$meuId = (int)$_SESSION['admin_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        $erro = 'Sessão expirada, recarregue a página e tente de novo.';
    } elseif ($somenteLeitura) {
        http_response_code(403);
        $erro = 'Perfil supervisor só acompanha — não altera.';
    } else {
        $acao = (string)($_POST['acao'] ?? '');
        $rid = (int)($_POST['reserva_id'] ?? 0);
        if ($rid && (!($rr = carroReservaBuscar($rid)) || (int)$rr['venda_id'] !== $vendaId)) {
            $erro = 'Carro reserva não encontrado neste contrato.';
        } elseif ($acao === 'liberar') {
            $res = carroReservaLiberar($vendaId, $_POST, $meuId);
            if ($res['ok']) {
                header('Location: /admin/carro_reserva.php?venda=' . $vendaId . '&ok=liberado#reserva-' . $res['id']);
                exit;
            }
            $erro = $res['erro'];
        } elseif ($acao === 'assinar') {
            $res = carroReservaAssinar($rid, (string)($_POST['assinatura_dados'] ?? ''), $meuId);
            if ($res['ok']) {
                header('Location: /admin/carro_reserva.php?venda=' . $vendaId . '&ok=' . ($res['status'] === 'concluida' ? 'trocado' : 'assinado'));
                exit;
            }
            $erro = $res['erro'];
        } elseif ($acao === 'cancelar') {
            $res = carroReservaCancelar($rid, $meuId, trim(clean((string)($_POST['motivo'] ?? ''))));
            $res['ok'] ? $sucesso = 'Liberação cancelada — o carro voltou pro estoque.' : $erro = $res['erro'];
        } elseif ($acao === 'devolver') {
            $res = carroReservaDevolver($rid, ['data' => $_POST['data'] ?? '', 'km' => $_POST['km'] ?? '',
                'combustivel' => $_POST['combustivel'] ?? '', 'obs' => $_POST['obs'] ?? ''], $meuId);
            $res['ok'] ? $sucesso = 'Devolução registrada — o carro reserva voltou pro estoque.' : $erro = $res['erro'];
        }
    }
}
$okMsg = ['liberado' => 'Carro reserva liberado. Agora mostre a tela pro cliente assinar o termo.',
          'assinado' => 'Termo assinado — carro reserva entregue ao cliente.',
          'trocado'  => 'Termo assinado — troca definitiva aplicada: o contrato agora é do carro reserva.'];
if (!$sucesso && isset($okMsg[$_GET['ok'] ?? ''])) $sucesso = $okMsg[$_GET['ok']];

// Reler a venda (a troca definitiva muda o veículo do contrato).
$st->execute([$vendaId]);
$venda = $st->fetch();
$reservas = carroReservaListarDaVenda($vendaId);
$emAndamento = array_values(array_filter($reservas, fn($r) => in_array($r['status'], ['pendente', 'ativa'], true)));
$podeLiberar = !$somenteLeitura && $venda['etapa'] === 'vendido' && !$emAndamento;
$disponiveis = $podeLiberar ? carroReservaVeiculosDisponiveis((int)$venda['oportunidade_id']) : [];
$contratoNum = (($venda['tipo_contrato'] ?? 'venda') === 'locacao' ? 'LOC-' : 'VENDA-') . $vendaId;
$veicTxt = fn($m, $mo, $a, $p) => trim("$m $mo $a") . ($p ? ' · ' . strtoupper($p) : '');
$motivoPadrao = $venda['busca_apreensao_em']
    ? 'Busca e apreensão do veículo original em ' . date('d/m/Y', strtotime($venda['busca_apreensao_em'])) . ($venda['busca_apreensao_obs'] ? ' — ' . $venda['busca_apreensao_obs'] : '')
    : 'Busca e apreensão do veículo original pelo banco';
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Carro reserva — Fastcar CRM</title>
<link rel="stylesheet" href="/admin/assets/style.css?v=<?= @filemtime(__DIR__ . '/assets/style.css') ?: 1 ?>">
<link rel="stylesheet" href="/admin/assets/mobile.css?v=<?= @filemtime(__DIR__ . '/assets/mobile.css') ?: 1 ?>">
<script src="/admin/assets/mobile.js?v=<?= @filemtime(__DIR__ . '/assets/mobile.js') ?: 1 ?>" defer></script>
<?php include __DIR__ . '/_pwa_head.php'; ?>
<style>
.cr-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:12px; }
.cr-pad-wrap { position:relative; max-width:520px; }
.cr-pad { display:block; width:100%; height:200px; background:#fff; border:2px dashed var(--borda); border-radius:var(--raio); touch-action:none; }
.cr-pad-dica { position:absolute; inset:0; display:flex; align-items:center; justify-content:center; color:var(--cinza, #8891a3); pointer-events:none; }
.cr-acoes { display:flex; gap:10px; flex-wrap:wrap; margin-top:10px; max-width:520px; }
.cr-acoes button, .cr-acoes a.btn { flex:1; min-height:48px; margin-top:0; }
.cr-hist { border-top:1px solid var(--borda); padding-top:12px; margin-top:12px; }
</style>
</head>
<body>
<?php include __DIR__ . '/_impersonando_banner.php'; ?>
<?php include __DIR__ . '/_topbar.php'; ?>
<main>
<?php if ($erro): ?><div class="alerta-erro"><?= e($erro) ?></div><?php endif; ?>
<?php if ($sucesso): ?><div class="alerta-sucesso"><?= e($sucesso) ?></div><?php endif; ?>

<div class="card">
    <p style="margin:0 0 6px"><a href="/admin/venda.php?id=<?= $vendaId ?>">← Voltar pra negociação</a> · <a href="/admin/veiculos.php">Frota</a></p>
    <h2 style="margin:0 0 6px">🔑 Carro reserva — contrato <?= e($contratoNum) ?></h2>
    <div class="cr-grid">
        <div><small>Cliente</small><br><strong><?= e((string)$venda['comprador_nome'] ?: '—') ?></strong>
            <br><small><?= e((string)$venda['comprador_cpf']) ?> <?= e((string)$venda['comprador_telefone']) ?></small></div>
        <div><small>Carro do contrato</small><br><strong><?= e($veicTxt($venda['veiculo_marca'], $venda['veiculo_modelo'], $venda['veiculo_ano'], $venda['veiculo_placa'])) ?: '—' ?></strong>
            <?php if ($venda['busca_apreensao_em']): ?><br><span class="badge badge-atraso">🚨 Busca e apreensão em <?= date('d/m/Y', strtotime($venda['busca_apreensao_em'])) ?></span><?php endif; ?></div>
    </div>
    <?php if ($venda['etapa'] !== 'vendido'): ?>
        <div class="alerta-info" style="margin-top:10px">Carro reserva só pra contrato já assinado (vendido/locado). Esta negociação está em: <?= e(etapaVendaLabel($venda['etapa'], $venda['tipo_contrato'] ?? 'venda')) ?>.</div>
    <?php endif; ?>
</div>

<?php foreach ($emAndamento as $r): ?>
<div class="card" id="reserva-<?= (int)$r['id'] ?>">
    <h3 style="margin:0 0 6px"><?= e(CARRO_RESERVA_STATUS[$r['status']]) ?> — <?= e(CARRO_RESERVA_TIPOS[$r['tipo']]) ?></h3>
    <p style="margin:0 0 8px">Carro reserva: <strong><?= e($veicTxt($r['res_marca'], $r['res_modelo'], $r['res_ano'], $r['res_placa'])) ?></strong>
        <br><small>Motivo: <?= e($r['motivo']) ?><?= $r['prazo_devolucao'] ? ' · devolução prevista até ' . date('d/m/Y', strtotime($r['prazo_devolucao'])) : '' ?></small></p>
    <a class="btn" href="/admin/carro_reserva_termo.php?id=<?= (int)$r['id'] ?>" target="_blank" rel="noopener">📄 <?= $r['status'] === 'pendente' ? 'Ver o termo (prévia)' : 'Ver termo assinado' ?></a>

    <?php if ($r['status'] === 'pendente' && !$somenteLeitura): ?>
        <div class="cr-hist">
            <h4 style="margin:0 0 4px">✍️ Assinatura do cliente</h4>
            <p style="margin:0 0 8px"><small>Mostre o termo pro cliente, depois peça pra ele assinar com o dedo aqui. Ao confirmar, o termo é gerado com a assinatura, data, hora e IP<?= $r['tipo'] === 'definitivo' ? ' e o contrato passa a ser do carro reserva' : ' e o carro sai do estoque até ele devolver' ?>.</small></p>
            <div class="cr-pad-wrap">
                <canvas class="cr-pad" id="cr-pad"></canvas>
                <div class="cr-pad-dica" id="cr-pad-dica">✍️ Toque e assine aqui</div>
            </div>
            <div class="cr-acoes">
                <button type="button" class="secundario" id="cr-limpar">🗑️ Limpar</button>
                <button type="button" id="cr-confirmar" disabled>✅ Confirmar assinatura</button>
            </div>
            <form method="post" id="cr-form-assinar">
                <?= csrfField() ?>
                <input type="hidden" name="acao" value="assinar">
                <input type="hidden" name="reserva_id" value="<?= (int)$r['id'] ?>">
                <input type="hidden" name="assinatura_dados" id="cr-dados">
            </form>
        </div>
        <form method="post" class="cr-hist" onsubmit="return confirmarAcao(this, 'Cancelar a liberação deste carro reserva? Ele volta pro estoque.');">
            <?= csrfField() ?>
            <input type="hidden" name="acao" value="cancelar">
            <input type="hidden" name="reserva_id" value="<?= (int)$r['id'] ?>">
            <input type="text" name="motivo" maxlength="200" placeholder="Motivo do cancelamento (opcional)">
            <button type="submit" class="perigo">🚫 Cancelar liberação</button>
        </form>
    <?php elseif ($r['status'] === 'ativa' && !$somenteLeitura): ?>
        <form method="post" class="cr-hist" onsubmit="return confirmarAcao(this, 'Registrar que o cliente devolveu o carro reserva?');">
            <h4 style="margin:0 0 6px">↩️ Cliente devolveu o carro reserva</h4>
            <?= csrfField() ?>
            <input type="hidden" name="acao" value="devolver">
            <input type="hidden" name="reserva_id" value="<?= (int)$r['id'] ?>">
            <div class="cr-grid">
                <label>Data da devolução<input type="date" name="data" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" required></label>
                <label>KM na devolução<input type="text" name="km" inputmode="numeric" placeholder="ex: 45200"></label>
                <label>Combustível<select name="combustivel"><?php foreach (CARRO_RESERVA_COMBUSTIVEL as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></label>
            </div>
            <input type="text" name="obs" maxlength="500" placeholder="Estado na devolução / observações (opcional)">
            <button type="submit">↩️ Registrar devolução</button>
        </form>
    <?php endif; ?>
</div>
<?php endforeach; ?>

<?php if ($podeLiberar): ?>
<div class="card">
    <h3 style="margin:0 0 6px">🔑 Liberar carro reserva</h3>
    <?php if (!$disponiveis): ?>
        <p>Nenhum carro disponível no estoque agora.</p>
    <?php else: ?>
    <form method="post" onsubmit="return confirmarAcao(this, 'Liberar este carro como reserva? Ele sai do estoque e o cliente assina o termo em seguida.');">
        <?= csrfField() ?>
        <input type="hidden" name="acao" value="liberar">
        <label>Modalidade</label>
        <div class="cr-grid" style="margin-bottom:10px">
            <label class="badge" style="display:flex;gap:8px;align-items:center;padding:12px;min-height:44px"><input type="radio" name="tipo" value="temporario" checked style="width:auto;margin:0"> 🔁 Temporário — empréstimo até resolver o carro original; depois devolve</label>
            <label class="badge" style="display:flex;gap:8px;align-items:center;padding:12px;min-height:44px"><input type="radio" name="tipo" value="definitivo" style="width:auto;margin:0"> ♻️ Troca definitiva — o reserva vira o carro do contrato</label>
        </div>
        <label>Carro reserva
            <select name="oportunidade_reserva_id" required>
                <option value="">— escolha um carro do estoque —</option>
                <?php foreach ($disponiveis as $d): ?>
                    <option value="<?= (int)$d['oportunidade_id'] ?>"><?= e($veicTxt($d['veiculo_marca'], $d['veiculo_modelo'], $d['veiculo_ano'], $d['veiculo_placa'])) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Motivo<input type="text" name="motivo" maxlength="300" value="<?= e($motivoPadrao) ?>" required></label>
        <div class="cr-grid">
            <label>Devolução prevista (só temporário)<input type="date" name="prazo_devolucao" min="<?= date('Y-m-d') ?>"></label>
            <label>KM na entrega<input type="text" name="km_entrega" inputmode="numeric" placeholder="ex: 45000"></label>
            <label>Combustível na entrega<select name="combustivel_entrega"><?php foreach (CARRO_RESERVA_COMBUSTIVEL as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></label>
        </div>
        <label>Estado do carro / observações<input type="text" name="obs_entrega" maxlength="500" placeholder="ex: risco na porta traseira, pneus novos"></label>
        <button type="submit">🔑 Liberar e ir pra assinatura</button>
    </form>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php $historico = array_values(array_filter($reservas, fn($r) => !in_array($r['status'], ['pendente', 'ativa'], true))); ?>
<?php if ($historico): ?>
<div class="card">
    <h3 style="margin:0 0 6px">📜 Histórico de carros reserva</h3>
    <table class="tabela-oportunidades">
        <thead><tr><th>Carro reserva</th><th>Modalidade</th><th>Situação</th><th>Datas</th><th>Ações</th></tr></thead>
        <tbody>
        <?php foreach ($historico as $r): ?>
            <tr>
                <td><?= e($veicTxt($r['res_marca'], $r['res_modelo'], $r['res_ano'], $r['res_placa'])) ?></td>
                <td><?= e(CARRO_RESERVA_TIPOS[$r['tipo']]) ?></td>
                <td><?= e(CARRO_RESERVA_STATUS[$r['status']]) ?></td>
                <td><small>liberado <?= date('d/m/Y', strtotime($r['created_at'])) ?><?= $r['assinado_em'] ? ' · assinado ' . date('d/m/Y H:i', strtotime($r['assinado_em'])) : '' ?><?= $r['devolvido_em'] ? ' · devolvido ' . date('d/m/Y', strtotime($r['devolvido_em'])) : '' ?></small></td>
                <td><?php if ($r['assinado_em']): ?><a class="btn" href="/admin/carro_reserva_termo.php?id=<?= (int)$r['id'] ?>" target="_blank" rel="noopener">📄 Termo</a><?php endif; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>
</main>
<script>
(function () {
    var canvas = document.getElementById('cr-pad');
    if (!canvas) return;
    var dica = document.getElementById('cr-pad-dica');
    var btnLimpar = document.getElementById('cr-limpar');
    var btnOk = document.getElementById('cr-confirmar');
    var ctx, temTraco = false, desenhando = false;
    function preparar() {
        var r = canvas.getBoundingClientRect();
        canvas.width = r.width; canvas.height = r.height;
        ctx = canvas.getContext('2d');
        ctx.strokeStyle = '#151722'; ctx.lineWidth = 2.6; ctx.lineCap = 'round'; ctx.lineJoin = 'round';
    }
    preparar();
    function pos(e) {
        var r = canvas.getBoundingClientRect();
        var t = (e.touches && e.touches.length) ? e.touches[0] : e;
        return { x: t.clientX - r.left, y: t.clientY - r.top };
    }
    function iniciar(e) {
        e.preventDefault(); desenhando = true;
        var p = pos(e); ctx.beginPath(); ctx.moveTo(p.x, p.y);
        if (!temTraco) { temTraco = true; dica.style.display = 'none'; btnOk.disabled = false; }
    }
    function mover(e) { if (!desenhando) return; e.preventDefault(); var p = pos(e); ctx.lineTo(p.x, p.y); ctx.stroke(); }
    function parar() { desenhando = false; }
    canvas.addEventListener('mousedown', iniciar);
    canvas.addEventListener('mousemove', mover);
    window.addEventListener('mouseup', parar);
    canvas.addEventListener('touchstart', iniciar, { passive: false });
    canvas.addEventListener('touchmove', mover, { passive: false });
    canvas.addEventListener('touchend', parar);
    btnLimpar.addEventListener('click', function () {
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        temTraco = false; dica.style.display = 'flex'; btnOk.disabled = true;
    });
    btnOk.addEventListener('click', function () {
        if (!temTraco) return;
        document.getElementById('cr-dados').value = canvas.toDataURL('image/png');
        btnOk.disabled = true;
        var f = document.getElementById('cr-form-assinar');
        if (f.requestSubmit) { f.requestSubmit(); } else { f.submit(); }
    });
})();
</script>
<?php include __DIR__ . '/_pwa_register.php'; ?>
<?php include __DIR__ . '/_notify.php'; ?>
<?php include __DIR__ . '/_scroll_restore.php'; ?>
<?php include __DIR__ . '/_acao_popup.php'; ?>
<?php include __DIR__ . '/_confirm_dialog.php'; ?>
<?php include __DIR__ . '/_zapi_status.php'; ?>
</body>
</html>
