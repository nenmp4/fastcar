<?php
/**
 * 🔥 Avisos de lead quente (10/11/2026) — "preciso uma tela que saiba que
 * notificações de lead quente foi entregue: resumo desse lead, telefone,
 * nome do consultor, incluindo número do Jean" + "horário que foi entregue
 * no WhatsApp" + "botão gerar — últimos 7 dias, hoje, ontem" + "só para
 * super admin".
 *
 * Fonte: aviso_lead_quente_envios (1 linha por destinatário, gravada por
 * includes/aviso_lead_quente.php; entregue_em/lido_em chegam pelo status
 * da Evolution). Lead quente do período SEM nenhum aviso registrado também
 * aparece ("nenhum aviso registrado") — nunca some da tela.
 */

require_once __DIR__ . '/_bootstrap.php';
requireSuperAdmin();

$db = getDB();
$periodo = $_GET['periodo'] ?? 'hoje';
if (!in_array($periodo, ['hoje', 'ontem', '7dias'], true)) $periodo = 'hoje';
$tipo = $_GET['tipo'] ?? 'todos';
if (!in_array($tipo, ['todos', 'compra', 'venda'], true)) $tipo = 'todos';
$soFalhas = !empty($_GET['falhas']);

$hoje = date('Y-m-d');
[$de, $ate] = match ($periodo) {
    'ontem' => [date('Y-m-d', strtotime('-1 day')), date('Y-m-d', strtotime('-1 day'))],
    '7dias' => [date('Y-m-d', strtotime('-6 days')), $hoje],
    default => [$hoje, $hoje],
};
$rotulos = ['hoje' => 'Hoje', 'ontem' => 'Ontem', '7dias' => 'Últimos 7 dias'];

// 1) Envios registrados no período.
$envios = $db->prepare("SELECT * FROM aviso_lead_quente_envios WHERE date(enviado_em) BETWEEN ? AND ? ORDER BY enviado_em");
$envios->execute([$de, $ate]);
$porLead = []; // "compra:12" => [envios...]
foreach ($envios->fetchAll() as $e) $porLead[$e['ref_tipo'] . ':' . $e['ref_id']][] = $e;

// 2) Leads quentes criados no período (mesmo sem aviso registrado).
$stmt = $db->prepare("SELECT id FROM oportunidades WHERE temperatura_lead = 'quente' AND date(created_at) BETWEEN ? AND ?");
$stmt->execute([$de, $ate]);
foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) $porLead['compra:' . $id] ??= [];
$stmt = $db->prepare("SELECT id FROM vendas WHERE temperatura_lead = 'quente' AND date(created_at) BETWEEN ? AND ?");
$stmt->execute([$de, $ate]);
foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) $porLead['venda:' . $id] ??= [];

// 3) Dados de cada lead.
$qCompra = $db->prepare("SELECT o.id, o.created_at, o.resumo_ia, o.etapa, o.veiculo_marca, o.veiculo_modelo, o.veiculo_ano,
        c.nome AS cliente_nome, c.telefone AS cliente_telefone, u.nome AS responsavel_nome
    FROM oportunidades o LEFT JOIN clientes c ON c.id = o.cliente_id LEFT JOIN usuarios u ON u.id = o.responsavel_id WHERE o.id = ?");
$qVenda = $db->prepare("SELECT v.id, v.created_at, v.resumo_ia, v.etapa, v.veiculo_interesse_texto,
        v.comprador_nome AS cliente_nome, v.comprador_telefone AS cliente_telefone, u.nome AS responsavel_nome
    FROM vendas v LEFT JOIN usuarios u ON u.id = v.responsavel_id WHERE v.id = ?");

$leads = [];
$tot = ['leads' => 0, 'enviados' => 0, 'entregues' => 0, 'lidos' => 0, 'falhas' => 0, 'sem_aviso' => 0];
foreach ($porLead as $chave => $lista) {
    [$refTipo, $refId] = explode(':', $chave);
    if ($tipo !== 'todos' && $tipo !== $refTipo) continue;
    $q = $refTipo === 'venda' ? $qVenda : $qCompra;
    $q->execute([(int)$refId]);
    $l = $q->fetch();
    if (!$l) continue;
    $temFalha = false;
    foreach ($lista as $e) if (in_array($e['status'], ['falhou', 'sem_whatsapp'], true)) $temFalha = true;
    if ($soFalhas && !$temFalha && $lista) continue;
    $l['ref_tipo'] = $refTipo;
    $l['envios'] = $lista;
    $l['ordem'] = $lista ? $lista[0]['enviado_em'] : $l['created_at'];
    // Aviso de venda anterior a esta tela (só a data no config, sem destinatários).
    $l['aviso_antigo'] = (!$lista && $refTipo === 'venda') ? (getConfig('venda_quente_avisado_' . $refId) ?: '') : '';
    $leads[] = $l;
    $tot['leads']++;
    if (!$lista) $tot['sem_aviso']++;
    foreach ($lista as $e) {
        if ($e['status'] === 'enviado') $tot['enviados']++;
        if ($e['entregue_em']) $tot['entregues']++;
        if ($e['lido_em']) $tot['lidos']++;
        if (in_array($e['status'], ['falhou', 'sem_whatsapp'], true)) $tot['falhas']++;
    }
}
usort($leads, fn($a, $b) => strcmp((string)$b['ordem'], (string)$a['ordem']));

function alqHora(?string $dt): string {
    if (!$dt) return '';
    $t = strtotime($dt);
    return date('Y-m-d', $t) === date('Y-m-d') ? date('H:i', $t) : date('d/m H:i', $t);
}
function alqTelefone(string $t): string {
    $d = preg_replace('/\D/', '', $t);
    if (strlen($d) >= 12 && str_starts_with($d, '55')) $d = substr($d, 2);
    if (strlen($d) === 11) return '(' . substr($d, 0, 2) . ') ' . substr($d, 2, 5) . '-' . substr($d, 7);
    if (strlen($d) === 10) return '(' . substr($d, 0, 2) . ') ' . substr($d, 2, 4) . '-' . substr($d, 6);
    return $t;
}
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Avisos de lead quente — Fastcar CRM</title>
<link rel="stylesheet" href="/admin/assets/style.css?v=<?= @filemtime(__DIR__ . '/assets/style.css') ?: 1 ?>">
<link rel="stylesheet" href="/admin/assets/mobile.css?v=<?= @filemtime(__DIR__ . '/assets/mobile.css') ?: 1 ?>">
<script src="/admin/assets/mobile.js?v=<?= @filemtime(__DIR__ . '/assets/mobile.js') ?: 1 ?>" defer></script>
<?php include __DIR__ . '/_pwa_head.php'; ?>
<style>
.alq-filtros { display: flex; flex-wrap: wrap; gap: 10px; align-items: flex-end; }
.alq-filtros label { display: flex; flex-direction: column; gap: 4px; font-size: 13px; }
.alq-filtros .alq-check { flex-direction: row; white-space: nowrap; align-items: center; gap: 6px; min-height: 44px; }
.alq-lead { border: 1px solid var(--borda); border-radius: var(--raio); padding: 14px; margin-top: 12px; background: var(--superficie); }
.alq-lead-topo { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; justify-content: space-between; }
.alq-lead-topo strong { font-size: 15px; }
.alq-meta { color: var(--texto-suave); font-size: 13px; margin-top: 4px; overflow-wrap: break-word; }
.alq-resumo { white-space: pre-wrap; font-size: 13px; background: var(--fundo); border-radius: 8px; padding: 10px; margin-top: 10px; max-height: 180px; overflow: auto; }
.alq-dest { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 8px; margin-top: 10px; }
.alq-box { border: 1px solid var(--borda); border-left: 4px solid var(--borda); border-radius: 8px; padding: 8px 10px; font-size: 13px; }
.alq-box.ok { border-left-color: var(--verde); }
.alq-box.lido { border-left-color: var(--azul); }
.alq-box.pend { border-left-color: var(--amarelo); }
.alq-box.erro { border-left-color: var(--vermelho); }
.alq-box b { display: block; }
.alq-box small { color: var(--texto-suave); display: block; overflow-wrap: break-word; }
@media print {
    .topbar, .alq-filtros, .alq-nao-imprimir, .notif-sino, .zapi-status-badge { display: none !important; }
    .alq-lead { break-inside: avoid; }
    .alq-resumo { max-height: none; }
}
</style>
</head>
<body>
<?php include __DIR__ . '/_impersonando_banner.php'; ?>
<?php include __DIR__ . '/_topbar.php'; ?>

<main>
<div class="card">
    <h2>🔥 Avisos de lead quente</h2>
    <p class="alq-meta">Quem recebeu o aviso no WhatsApp e a que horas foi entregue/lido. <?= e($rotulos[$periodo]) ?>: <?= e(date('d/m/Y', strtotime($de))) ?><?= $de !== $ate ? ' a ' . e(date('d/m/Y', strtotime($ate))) : '' ?>.</p>
    <form method="get" class="alq-filtros" onchange="this.submit()">
        <label>Período
            <select name="periodo">
                <?php foreach ($rotulos as $k => $r): ?>
                    <option value="<?= $k ?>" <?= $periodo === $k ? 'selected' : '' ?>><?= e($r) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Funil
            <select name="tipo">
                <option value="todos" <?= $tipo === 'todos' ? 'selected' : '' ?>>Compra e vendas</option>
                <option value="compra" <?= $tipo === 'compra' ? 'selected' : '' ?>>🚗 Compra</option>
                <option value="venda" <?= $tipo === 'venda' ? 'selected' : '' ?>>🛒 Vendas</option>
            </select>
        </label>
        <label class="alq-check"><input type="checkbox" name="falhas" value="1" <?= $soFalhas ? 'checked' : '' ?>> Só com falha</label>
        <button type="submit" class="btn-primary" style="margin-top:0">⚡ Gerar</button>
        <button type="button" class="secundario alq-nao-imprimir" style="margin-top:0" onclick="window.print()">🖨️ Imprimir / PDF</button>
    </form>
</div>

<div class="stat-grid">
    <div class="stat-card"><div class="rotulo">Leads quentes</div><div class="valor"><?= $tot['leads'] ?></div></div>
    <div class="stat-card sucesso"><div class="rotulo">Avisos enviados</div><div class="valor"><?= $tot['enviados'] ?></div></div>
    <div class="stat-card sucesso"><div class="rotulo">Entregues no WhatsApp</div><div class="valor"><?= $tot['entregues'] ?></div></div>
    <div class="stat-card"><div class="rotulo">Lidos</div><div class="valor"><?= $tot['lidos'] ?></div></div>
    <div class="stat-card alerta"><div class="rotulo">Falhas / sem WhatsApp</div><div class="valor"><?= $tot['falhas'] ?></div></div>
    <div class="stat-card neutro"><div class="rotulo">Sem aviso registrado</div><div class="valor"><?= $tot['sem_aviso'] ?></div></div>
</div>

<div class="card">
<?php if (!$leads): ?>
    <p>Nenhum lead quente nesse período.</p>
<?php endif; ?>
<?php foreach ($leads as $l):
    $ehVenda = $l['ref_tipo'] === 'venda';
    $url = $ehVenda ? '/admin/venda.php?id=' . (int)$l['id'] : '/admin/oportunidade.php?id=' . (int)$l['id'];
    $veiculo = $ehVenda ? (string)($l['veiculo_interesse_texto'] ?? '') : trim(($l['veiculo_marca'] ?? '') . ' ' . ($l['veiculo_modelo'] ?? '') . ' ' . ($l['veiculo_ano'] ?? ''));
?>
    <div class="alq-lead">
        <div class="alq-lead-topo">
            <strong><?= $ehVenda ? '🛒' : '🚗' ?> <?= e($l['cliente_nome'] ?: '(sem nome)') ?> <span class="badge badge-atraso">🔥 Quente</span></strong>
            <a class="btn" href="<?= e($url) ?>">Abrir #<?= (int)$l['id'] ?> →</a>
        </div>
        <div class="alq-meta">
            📱 <?= e(alqTelefone((string)$l['cliente_telefone'])) ?>
            · 👤 <?= e($l['responsavel_nome'] ?: 'sem responsável') ?>
            <?= $veiculo !== '' ? ' · ' . e($veiculo) : '' ?>
            · entrou <?= e(alqHora($l['created_at'])) ?>
        </div>
        <?php if (trim((string)$l['resumo_ia']) !== ''): ?>
            <div class="alq-resumo"><?= e(trim((string)$l['resumo_ia'])) ?></div>
        <?php endif; ?>
        <div class="alq-dest">
        <?php if (!$l['envios']): ?>
            <div class="alq-box pend"><b>Nenhum aviso registrado</b>
                <small><?= $l['aviso_antigo'] ? 'Avisado em ' . e(alqHora($l['aviso_antigo'])) . ' (antes desta tela — sem registro de entrega)' : 'Lead quente sem envio gravado aqui (pode ter sido avisado por outro caminho).' ?></small>
            </div>
        <?php endif; ?>
        <?php foreach ($l['envios'] as $e):
            $classe = $e['lido_em'] ? 'lido' : ($e['entregue_em'] ? 'ok' : ($e['status'] === 'enviado' ? 'pend' : 'erro'));
            $motivo = ['quente' => 'aviso de lead quente', 'lembrete' => 'lembrete: quente parado'][$e['motivo']] ?? $e['motivo'];
        ?>
            <div class="alq-box <?= $classe ?>">
                <b><?= e($e['destinatario']) ?></b>
                <?php if ($e['telefone'] !== ''): ?><small>📱 <?= e(alqTelefone($e['telefone'])) ?></small><?php endif; ?>
                <small><?= e($motivo) ?></small>
                <?php if ($e['status'] === 'enviado'): ?>
                    <small>📤 enviado <?= e(alqHora($e['enviado_em'])) ?></small>
                    <small><?= $e['entregue_em'] ? '✅ entregue ' . e(alqHora($e['entregue_em'])) : '⏳ entrega ainda não confirmada' ?></small>
                    <?php if ($e['lido_em']): ?><small>👀 lido <?= e(alqHora($e['lido_em'])) ?></small><?php endif; ?>
                <?php else: ?>
                    <small>❌ <?= $e['status'] === 'sem_whatsapp' ? 'não enviado' : 'falhou' ?> <?= e(alqHora($e['enviado_em'])) ?></small>
                    <small><?= e($e['erro']) ?></small>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        </div>
    </div>
<?php endforeach; ?>
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
