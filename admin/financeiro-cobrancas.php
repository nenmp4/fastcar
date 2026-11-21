<?php
/**
 * 📞 Cobranças (21/11/2026, "criar tela de cobranças agente saber") — quem
 * está em atraso, quando foi cobrado (manual ou automático), quem mandou
 * comprovante e o histórico. Envio pelo mesmo modal do botão 💬 Cobrar.
 * Fonte: fin_lancamentos (atrasados) + cobranca_eventos.
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../includes/cobranca_ouvidoria.php';
require_once __DIR__ . '/../includes/financeiro.php';
requireAcessoFinanceiro();

$db = getDB();
$erro = '';
$sucesso = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        $erro = 'Sessão expirada, recarregue a página e tente de novo.';
    } elseif (($_POST['acao'] ?? '') === 'enviar_cobranca') {
        $r = cobrancaEnviarPelaOuvidoria((int)($_POST['id'] ?? 0), (string)($_POST['mensagem'] ?? ''), (int)($_SESSION['admin_id'] ?? 0), !empty($_POST['forcar']));
        if ($r['ok']) $sucesso = 'Cobrança enviada pelo WhatsApp da ouvidoria' . ($r['nome'] ? ' pra ' . $r['nome'] : '') . '.';
        else $erro = $r['erro'];
    }
}

try { finRecalcularAtrasados(); } catch (Throwable $e) {}

$ver = ($_GET['ver'] ?? '') === 'historico' ? 'historico' : 'clientes';
$filtro = in_array($_GET['f'] ?? '', ['nunca', 'cobrados', 'comprovante', 'sem_telefone'], true) ? $_GET['f'] : '';
$busca = trim((string)($_GET['q'] ?? ''));

// ── Clientes em atraso (1 linha por telefone) ─────────────────────────────
$clientes = [];
$semTelefone = [];
$hoje = strtotime(date('Y-m-d'));
foreach ($db->query("SELECT * FROM fin_lancamentos WHERE tipo = 'receita' AND status = 'atrasado' ORDER BY data_vencimento, id")->fetchAll(PDO::FETCH_ASSOC) as $l) {
    $c = cobrancaContatoDoLancamento($l);
    if ($c['telefone'] === '') { $semTelefone[] = $l + ['_nome' => $c['nome']]; continue; }
    $t = $c['telefone'];
    if (!isset($clientes[$t])) {
        $clientes[$t] = ['telefone' => $t, 'nome' => $c['nome'], 'qtd' => 0, 'total' => 0.0, 'vencimento' => $l['data_vencimento'],
                         'lancamento_id' => (int)$l['id'], 'venda_id' => $l['venda_id'] ? (int)$l['venda_id'] : null];
    }
    $clientes[$t]['qtd']++;
    $clientes[$t]['total'] += (float)$l['valor'];
    if (!$clientes[$t]['nome'] && $c['nome']) $clientes[$t]['nome'] = $c['nome'];
}

// Última cobrança e último comprovante de cada telefone.
$ultimos = [];
foreach ($db->query("SELECT telefone, tipo, origem, MAX(created_at) AS em, COUNT(*) AS n FROM cobranca_eventos GROUP BY telefone, tipo")->fetchAll(PDO::FETCH_ASSOC) as $ev) {
    $ultimos[$ev['telefone']][$ev['tipo']] = $ev;
}
$origemUltima = [];
foreach ($db->query("SELECT e.telefone, e.origem FROM cobranca_eventos e JOIN (SELECT telefone, MAX(id) AS mid FROM cobranca_eventos WHERE tipo = 'envio' GROUP BY telefone) u ON u.mid = e.id")->fetchAll(PDO::FETCH_ASSOC) as $o) {
    $origemUltima[$o['telefone']] = $o['origem'];
}
foreach ($clientes as $t => &$cl) {
    $cl['envio'] = $ultimos[$t]['envio'] ?? null;
    $cl['comprovante'] = $ultimos[$t]['comprovante'] ?? null;
    $cl['origem'] = $origemUltima[$t] ?? '';
    $cl['dias'] = $cl['vencimento'] ? (int)floor(($hoje - strtotime($cl['vencimento'])) / 86400) : 0;
}
unset($cl);

$totalAtraso = array_sum(array_column($clientes, 'total'));
$nuncaCobrados = count(array_filter($clientes, fn($c) => !$c['envio']));
$comComprovante = count(array_filter($clientes, fn($c) => $c['comprovante'] && (!$c['envio'] || $c['comprovante']['em'] >= $c['envio']['em'])));

$lista = array_values(array_filter($clientes, function ($c) use ($filtro, $busca) {
    if ($filtro === 'nunca' && $c['envio']) return false;
    if ($filtro === 'cobrados' && !$c['envio']) return false;
    if ($filtro === 'comprovante' && !$c['comprovante']) return false;
    if ($busca !== '' && mb_stripos($c['nome'] . ' ' . $c['telefone'], $busca) === false) return false;
    return true;
}));

// ── Números do topo ────────────────────────────────────────────────────────
$envHoje = (int)$db->query("SELECT COUNT(*) FROM cobranca_eventos WHERE tipo = 'envio' AND date(created_at) = date('now','localtime')")->fetchColumn();
$env7 = $db->query("SELECT origem, COUNT(*) FROM cobranca_eventos WHERE tipo = 'envio' AND created_at >= datetime('now','localtime','-7 days') GROUP BY origem")->fetchAll(PDO::FETCH_KEY_PAIR);
$comp7 = (int)$db->query("SELECT COUNT(*) FROM cobranca_eventos WHERE tipo = 'comprovante' AND created_at >= datetime('now','localtime','-7 days')")->fetchColumn();
// Pagou depois de cobrado (30 dias): lançamento cobrado que hoje está pago.
$pagou = $db->query("
    SELECT COUNT(DISTINCT e.telefone) AS n, COALESCE(SUM(l.valor),0) AS v FROM cobranca_eventos e
    JOIN fin_lancamentos l ON l.id = e.lancamento_id AND l.status = 'pago'
    WHERE e.tipo = 'envio' AND e.created_at >= datetime('now','localtime','-30 days')
")->fetch(PDO::FETCH_ASSOC);

$historico = [];
if ($ver === 'historico') {
    $historico = $db->query("
        SELECT e.*, u.nome AS usuario_nome, l.status AS lanc_status FROM cobranca_eventos e
        LEFT JOIN usuarios u ON u.id = e.usuario_id
        LEFT JOIN fin_lancamentos l ON l.id = COALESCE(e.lancamento_id, e.bate_lancamento_id)
        ORDER BY e.id DESC LIMIT 200
    ")->fetchAll(PDO::FETCH_ASSOC);
}

$fmt = fn($v) => 'R$ ' . number_format((float)$v, 2, ',', '.');
$quando = function (?string $dt): string {
    if (!$dt) return '';
    $d = (int)floor((strtotime(date('Y-m-d')) - strtotime(substr($dt, 0, 10))) / 86400);
    return date('d/m H:i', strtotime($dt)) . ' (' . ($d === 0 ? 'hoje' : ($d === 1 ? 'ontem' : "há {$d} dias")) . ')';
};
$qsBase = fn(array $extra) => http_build_query(array_filter(array_merge(['f' => $filtro, 'q' => $busca], $extra), fn($v) => $v !== '' && $v !== null));
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Cobranças — Financeiro Fastcar</title>
<link rel="stylesheet" href="/admin/assets/style.css?v=<?= @filemtime(__DIR__ . '/assets/style.css') ?: 1 ?>">
<link rel="stylesheet" href="/admin/assets/mobile.css?v=<?= @filemtime(__DIR__ . '/assets/mobile.css') ?: 1 ?>">
<script src="/admin/assets/mobile.js?v=<?= @filemtime(__DIR__ . '/assets/mobile.js') ?: 1 ?>" defer></script>
<?php include __DIR__ . '/_pwa_head.php'; ?>
</head>
<body>
<?php include __DIR__ . '/_impersonando_banner.php'; ?>
<?php include __DIR__ . '/_topbar.php'; ?>
<main>
<?php if ($erro): ?><div class="alerta-erro"><?= e($erro) ?></div><?php endif; ?>
<?php if ($sucesso): ?><div class="alerta-sucesso"><?= e($sucesso) ?></div><?php endif; ?>

<h1 style="margin-bottom:.25rem">📞 Cobranças</h1>
<p style="color:var(--muted);margin-top:0">Quem está em atraso, quando foi cobrado e quem já mandou comprovante. A cobrança sai pelo WhatsApp da ouvidoria.</p>

<div class="stat-grid">
  <a class="stat-card alerta" href="?<?= $qsBase(['f' => '']) ?>"><div class="valor"><?= count($clientes) ?></div><div class="rotulo">Clientes em atraso<br><small><?= $fmt($totalAtraso) ?></small></div></a>
  <a class="stat-card neutro" href="?<?= $qsBase(['f' => 'nunca']) ?>"><div class="valor"><?= $nuncaCobrados ?></div><div class="rotulo">Nunca cobrados</div></a>
  <div class="stat-card"><div class="valor"><?= $envHoje ?></div><div class="rotulo">Cobranças hoje<br><small>7 dias: <?= (int)($env7['manual'] ?? 0) ?> manual · <?= (int)($env7['automatica'] ?? 0) ?> automática</small></div></div>
  <a class="stat-card azul-forte" href="?<?= $qsBase(['f' => 'comprovante']) ?>"><div class="valor"><?= $comComprovante ?></div><div class="rotulo">Mandaram comprovante<br><small><?= $comp7 ?> comprovante(s) em 7 dias</small></div></a>
  <div class="stat-card sucesso"><div class="valor"><?= (int)$pagou['n'] ?></div><div class="rotulo">Pagaram depois de cobrados (30 dias)<br><small><?= $fmt($pagou['v']) ?></small></div></div>
</div>

<p style="font-size:.9rem">
  🤖 Cobrança automática: <strong><?= cobrancaAutoAtiva() ? 'ligada — ' . e(implode(' e ', cobrancaAutoHorarios())) . ', ' . cobrancaAutoLote() . ' por horário' : 'desligada' ?></strong>
  · <a href="/admin/financeiro-lancamentos.php?ver=cobranca_auto">configurar</a>
  <?php if ($ul = (string)getConfig('cobranca_auto_ultimo_lote')): $ul = explode('|', $ul); ?> · último lote <?= e($ul[0]) ?>: <?= (int)($ul[1] ?? 0) ?> enviada(s)<?php endif; ?>
</p>

<nav class="etapas-nav">
  <a href="?<?= $qsBase([]) ?>" class="<?= $ver === 'clientes' ? 'ativo' : '' ?>">👥 Clientes em atraso (<?= count($clientes) ?>)</a>
  <a href="?ver=historico" class="<?= $ver === 'historico' ? 'ativo' : '' ?>">🕘 Histórico</a>
</nav>

<?php if ($ver === 'clientes'): ?>
<form method="GET" class="card" style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:end;margin-bottom:1rem">
  <label style="flex:1;min-width:200px">Buscar nome ou telefone<input type="search" name="q" value="<?= e($busca) ?>"></label>
  <label style="min-width:180px">Mostrar
    <select name="f">
      <option value="">Todos</option>
      <option value="nunca"<?= $filtro === 'nunca' ? ' selected' : '' ?>>Nunca cobrados</option>
      <option value="cobrados"<?= $filtro === 'cobrados' ? ' selected' : '' ?>>Já cobrados</option>
      <option value="comprovante"<?= $filtro === 'comprovante' ? ' selected' : '' ?>>Mandaram comprovante</option>
      <option value="sem_telefone"<?= $filtro === 'sem_telefone' ? ' selected' : '' ?>>Sem telefone (<?= count($semTelefone) ?>)</option>
    </select>
  </label>
  <button type="submit" style="width:auto">Filtrar</button>
</form>

<?php if ($filtro === 'sem_telefone'): ?>
<div class="card">
  <p style="color:var(--muted)">Esses lançamentos atrasados não têm telefone do cliente — vincule a venda, o cliente ou o cliente Asaas pra poder cobrar.</p>
  <div class="tabela-scroll"><table class="tabela-oportunidades">
    <thead><tr><th>Descrição</th><th>Cliente</th><th>Vencimento</th><th>Valor</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($semTelefone as $l): ?>
      <tr><td><?= e($l['descricao']) ?></td><td><?= e($l['_nome'] ?: '—') ?></td><td><?= $l['data_vencimento'] ? e(date('d/m/Y', strtotime($l['data_vencimento']))) : '—' ?></td><td><?= $fmt($l['valor']) ?></td>
        <td><a class="btn" style="width:auto" href="/admin/financeiro-lancamentos.php?action=edit&id=<?= (int)$l['id'] ?>">Abrir</a></td></tr>
    <?php endforeach; ?>
    <?php if (!$semTelefone): ?><tr><td colspan="5" style="text-align:center;color:var(--muted)">Nenhum.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
</div>
<?php else: ?>
<div class="card">
  <div class="tabela-scroll"><table class="tabela-oportunidades">
    <thead><tr><th>Cliente</th><th>Em atraso</th><th>Mais antigo</th><th>Última cobrança</th><th>Comprovante</th><th>Ações</th></tr></thead>
    <tbody>
    <?php foreach ($lista as $c): ?>
      <?php $compDepois = $c['comprovante'] && (!$c['envio'] || $c['comprovante']['em'] >= $c['envio']['em']); ?>
      <tr class="<?= $c['dias'] > 60 ? 'linha-atrasada' : '' ?>">
        <td><strong><?= e($c['nome'] ?: 'Sem nome') ?></strong><br><small style="color:var(--muted)"><?= e($c['telefone']) ?></small></td>
        <td><?= $fmt($c['total']) ?><br><small style="color:var(--muted)"><?= $c['qtd'] ?> lançamento(s)</small></td>
        <td><?= $c['vencimento'] ? e(date('d/m/Y', strtotime($c['vencimento']))) : '—' ?><br><small style="color:#991b1b"><?= $c['dias'] ?> dia(s)</small></td>
        <td><?php if ($c['envio']): ?><?= e($quando($c['envio']['em'])) ?><br><small style="color:var(--muted)"><?= $c['origem'] === 'automatica' ? '🤖 automática' : '👤 manual' ?> · <?= (int)$c['envio']['n'] ?>x no total</small><?php else: ?><span class="badge badge-aviso">nunca cobrado</span><?php endif; ?></td>
        <td><?php if ($c['comprovante']): ?><span class="badge <?= $compDepois ? 'badge-ok' : 'badge-info' ?>">🧾 <?= e($quando($c['comprovante']['em'])) ?></span><?php else: ?>—<?php endif; ?></td>
        <td>
          <button type="button" class="btn-texto" style="color:#166534" onclick="fcAbrirCobranca(<?= $c['lancamento_id'] ?>)">💬 Cobrar</button>
          <?php if (($_SESSION['admin_perfil'] ?? '') === 'super_admin'): ?><a class="btn-texto" href="/admin/ouvidoria_inbox.php?telefone=<?= rawurlencode($c['telefone']) ?>">💬 Conversa</a><?php endif; ?>
          <?php if ($c['venda_id'] && podeAcessarVendas()): ?><a class="btn-texto" href="/admin/venda.php?id=<?= $c['venda_id'] ?>">🛒 Venda</a><?php endif; ?>
          <a class="btn-texto" href="/admin/financeiro-lancamentos.php?action=edit&id=<?= $c['lancamento_id'] ?>">📒 Lançamento</a>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$lista): ?><tr><td colspan="6" style="text-align:center;color:var(--muted)">Ninguém nesse filtro. 🎉</td></tr><?php endif; ?>
    </tbody>
  </table></div>
</div>
<?php endif; ?>

<?php else: ?>
<div class="card">
  <p style="color:var(--muted);margin-top:0">Últimos 200 eventos: cobranças enviadas e comprovantes recebidos na ouvidoria.</p>
  <div class="tabela-scroll"><table class="tabela-oportunidades">
    <thead><tr><th>Cliente</th><th>Quando</th><th>O quê</th><th>Valor</th><th>Situação hoje</th></tr></thead>
    <tbody>
    <?php foreach ($historico as $h): ?>
      <tr>
        <td><strong><?= e($h['nome'] ?: 'Sem nome') ?></strong><br><small style="color:var(--muted)"><?= e($h['telefone']) ?></small></td>
        <td><?= e(date('d/m/Y H:i', strtotime($h['created_at']))) ?></td>
        <td><?php if ($h['tipo'] === 'envio'): ?><?= $h['origem'] === 'automatica' ? '🤖 Cobrança automática' : '👤 Cobrança por ' . e($h['usuario_nome'] ?: '—') ?><br><small style="color:var(--muted)"><?= (int)$h['itens'] ?> lançamento(s)</small><?php else: ?>🧾 Comprovante recebido<?= $h['bate_lancamento_id'] ? '<br><small style="color:#166534">bate com o lançamento #' . (int)$h['bate_lancamento_id'] . '</small>' : '' ?><?php endif; ?></td>
        <td><?= $h['valor'] !== null ? $fmt($h['valor']) : '—' ?></td>
        <td><?php $stl = $h['lanc_status']; echo $stl === 'pago' ? '<span class="badge badge-ok">✅ pago</span>' : ($stl ? '<span class="badge badge-atraso">' . e($stl) . '</span>' : '—'); ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$historico): ?><tr><td colspan="5" style="text-align:center;color:var(--muted)">Nenhuma cobrança ou comprovante registrado ainda.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/_modal_cobranca.php'; ?>
</main>
<?php include __DIR__ . '/_pwa_register.php'; ?>
<?php include __DIR__ . '/_notify.php'; ?>
<?php include __DIR__ . '/_scroll_restore.php'; ?>
<?php include __DIR__ . '/_acao_popup.php'; ?>
<?php include __DIR__ . '/_confirm_dialog.php'; ?>
<?php include __DIR__ . '/_zapi_status.php'; ?>
</body>
</html>
