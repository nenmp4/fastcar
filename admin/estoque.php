<?php
/**
 * Estoque — catálogo dos veículos disponíveis pra venda (24/10/2026,
 * "mostrar pra todos vendedores cards/catálogo dos carros disponíveis";
 * "carro finalizou na compra tudo certinho, virou pasta finalizada, vira
 * estoque"). Mesma fonte de verdade da IA de vendas:
 * listarFrotaDisponivelParaVenda() — oportunidade em etapa 'fechado' sem
 * negociação de venda ativa/concluída. Só leitura. Nunca mostra o valor
 * pago ao vendedor original (custo da Fastcar), só a FIPE de referência.
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../includes/veiculo_avaliacoes.php';
requireAcessoVendas();

$db = getDB();
$busca = trim((string)($_GET['q'] ?? ''));
$tipoFiltro = in_array($_GET['tipo'] ?? '', ['carro', 'moto', 'caminhao', 'outro'], true) ? $_GET['tipo'] : '';

$todos = listarFrotaDisponivelParaVenda(1000);
$veiculos = array_values(array_filter($todos, function ($v) use ($busca, $tipoFiltro) {
    if ($tipoFiltro !== '' && ($v['tipo_veiculo'] ?? '') !== $tipoFiltro) return false;
    if ($busca === '') return true;
    $alvo = mb_strtolower(($v['veiculo_marca'] ?? '') . ' ' . ($v['veiculo_modelo'] ?? '') . ' ' . ($v['veiculo_ano'] ?? '') . ' ' . ($v['veiculo_placa'] ?? ''));
    return str_contains($alvo, mb_strtolower($busca));
}));

// 1ª foto e contagem de mídia de cada veículo — 1 query só pra todos.
$fotoCapa = [];
$qtdMidia = [];
if ($veiculos) {
    $ids = array_column($veiculos, 'oportunidade_id');
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare("
        SELECT oportunidade_id,
               MIN(CASE WHEN tipo = 'foto' THEN id END) AS foto_id,
               COUNT(*) AS qtd
        FROM veiculo_midias_revenda WHERE oportunidade_id IN ({$ph})
        GROUP BY oportunidade_id
    ");
    $stmt->execute($ids);
    foreach ($stmt->fetchAll() as $r) {
        if ($r['foto_id']) $fotoCapa[(int)$r['oportunidade_id']] = (int)$r['foto_id'];
        $qtdMidia[(int)$r['oportunidade_id']] = (int)$r['qtd'];
    }
}
$iconeTipo = ['carro' => '🚗', 'moto' => '🏍️', 'caminhao' => '🚚', 'outro' => '🚙'];
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Estoque — Fastcar CRM</title>
<link rel="stylesheet" href="/admin/assets/style.css?v=<?= @filemtime(__DIR__ . '/assets/style.css') ?: 1 ?>">
<link rel="stylesheet" href="/admin/assets/mobile.css?v=<?= @filemtime(__DIR__ . '/assets/mobile.css') ?: 1 ?>">
<script src="/admin/assets/mobile.js?v=<?= @filemtime(__DIR__ . '/assets/mobile.js') ?: 1 ?>" defer></script>
<?php include __DIR__ . '/_pwa_head.php'; ?>
<style>
.estoque-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(240px,1fr)); gap:16px; }
.estoque-card { background:#fff; border:1px solid var(--borda); border-radius:var(--raio); box-shadow:var(--sombra); overflow:hidden; display:flex; flex-direction:column; }
.estoque-foto { aspect-ratio:4/3; background:var(--navy); display:flex; align-items:center; justify-content:center; font-size:56px; overflow:hidden; }
.estoque-foto img { width:100%; height:100%; object-fit:cover; }
.estoque-info { padding:12px 14px; display:flex; flex-direction:column; gap:4px; flex:1; }
.estoque-info h3 { margin:0; font-size:17px; }
.estoque-info small { color:var(--cinza, #666); }
.estoque-acoes { padding:0 14px 14px; }
.estoque-acoes a { display:block; text-align:center; }
</style>
</head>
<body>
<?php include __DIR__ . '/_impersonando_banner.php'; ?>
<header class="topbar">
    <a href="/admin/vendas.php" style="color:#fff">← Vendas</a>
    <a class="topbar-brand" href="<?= e(paginaInicialPorPerfil($_SESSION['admin_perfil'] ?? '')) ?>"><img class="topbar-logo" src="/admin/assets/img/icon-192.png" alt="Fastcar" onerror="this.style.display='none'"><span class="topbar-wordmark">Fast<b>Car</b></span></a>
    <span>Olá, <?= e($_SESSION['admin_nome']) ?></span>
    <a href="/admin/vendas_inbox.php">💬 WhatsApp Vendas</a>
    <a href="/admin/meu_perfil.php">🙋 Meu perfil</a>
    <a href="/admin/logout.php">Sair</a>
</header>
<main>
<h2>🚘 Estoque — disponíveis pra venda (<?= count($veiculos) ?><?= count($veiculos) !== count($todos) ? ' de ' . count($todos) : '' ?>)</h2>
<p><small>Todo veículo com a compra finalizada (pasta fechada) e sem negociação de venda em andamento.</small></p>

<div class="card">
    <form method="get" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
        <div style="flex:1;min-width:200px">
            <label>Buscar</label>
            <input type="text" name="q" value="<?= e($busca) ?>" placeholder="Marca, modelo, ano ou placa">
        </div>
        <div>
            <label>Tipo</label>
            <select name="tipo">
                <option value="">Todos</option>
                <?php foreach ($iconeTipo as $t => $ic): ?>
                    <option value="<?= $t ?>" <?= $tipoFiltro === $t ? 'selected' : '' ?>><?= $ic ?> <?= ucfirst($t === 'caminhao' ? 'caminhão' : $t) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" style="width:auto">Filtrar</button>
    </form>
</div>

<?php if (!$veiculos): ?>
    <div class="card"><p>Nenhum veículo disponível<?= ($busca !== '' || $tipoFiltro !== '') ? ' com esse filtro' : ' no estoque agora' ?>.</p></div>
<?php else: ?>
<div class="estoque-grid">
    <?php foreach ($veiculos as $v): $id = (int)$v['oportunidade_id']; $score = buscarScoreVistoriaRecente($id); ?>
    <div class="estoque-card">
        <div class="estoque-foto">
            <?php if (isset($fotoCapa[$id])): ?>
                <img src="/admin/ver_midia_revenda.php?id=<?= $fotoCapa[$id] ?>" alt="" loading="lazy">
            <?php else: ?>
                <?= $iconeTipo[$v['tipo_veiculo'] ?? ''] ?? '🚗' ?>
            <?php endif; ?>
        </div>
        <div class="estoque-info">
            <h3><?= e(trim(($v['veiculo_marca'] ?? '') . ' ' . ($v['veiculo_modelo'] ?? ''))) ?: '(sem modelo)' ?></h3>
            <small><?= e((string)($v['veiculo_ano'] ?? '')) ?><?= !empty($v['veiculo_placa']) ? ' · ' . e($v['veiculo_placa']) : '' ?></small>
            <?php if ($v['valor_fipe_referencia']): ?>
                <span>FIPE ref.: <strong>R$ <?= number_format((float)$v['valor_fipe_referencia'], 2, ',', '.') ?></strong></span>
            <?php endif; ?>
            <?php if ($score && $score['percentual'] !== null): ?>
                <span><span class="badge <?= veiculoAvaliacaoClasseBadgeScore($score['percentual']) ?>"><?= (int)$score['percentual'] ?>/100</span> <small><?= e(veiculoAvaliacaoSugestaoRevenda($score['percentual'])) ?></small></span>
            <?php else: ?>
                <small>— sem vistoria concluída</small>
            <?php endif; ?>
            <small>📸 <?= $qtdMidia[$id] ?? 0 ?> foto(s)/vídeo(s)</small>
        </div>
        <div class="estoque-acoes">
            <a class="btn" href="/admin/veiculo_midias.php?id=<?= $id ?>">Ver fotos e vídeos</a>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>
</main>
<?php include __DIR__ . '/_pwa_register.php'; ?>
<?php $notifModulo = 'vendas'; include __DIR__ . '/_notify.php'; ?>
<?php include __DIR__ . '/_scroll_restore.php'; ?>
<?php include __DIR__ . '/_acao_popup.php'; ?>
<?php include __DIR__ . '/_confirm_dialog.php'; ?>
<?php include __DIR__ . '/_zapi_status_vendas.php'; ?>
</body>
</html>
