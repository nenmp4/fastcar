<?php
/**
 * Relatório de lucro por veículo (04/11/2026, "tela valor pago no veículo e
 * lucro do veículo, valor das parcelas, entrada, quantidade de parcelas —
 * deixa campo pra adicionar valor da quitação").
 *
 * Uma linha por veículo comprado (oportunidade etapa='fechado'):
 *   custo  = valor pago ao vendedor (valor_final) + quitação do financiamento
 *            (oportunidades.valor_quitacao, digitada aqui — nunca chutada)
 *   venda  = entrada + parcela × quantidade (termos salvos na venda);
 *            sem parcelamento, usa o preço da venda
 *   lucro  = venda − custo  (previsto: conta parcela ainda não paga)
 *   recebido = receitas da venda já marcadas pagas no financeiro
 * Locação fica de fora do cálculo (aluguel não é preço de venda).
 */
require_once __DIR__ . '/_bootstrap.php';
requireSuperAdmin();

$db = getDB();
$erro = '';
$sucesso = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'salvar_quitacao') {
    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        $erro = 'Sessão expirada, recarregue a página.';
    } else {
        $opId = (int)($_POST['oportunidade_id'] ?? 0);
        $valor = valorMonetario((string)($_POST['valor_quitacao'] ?? ''));
        $st = $db->prepare("UPDATE oportunidades SET valor_quitacao = ?, updated_at = datetime('now','localtime') WHERE id = ? AND etapa = 'fechado'");
        $st->execute([$valor, $opId]);
        if ($st->rowCount()) {
            $q = $_GET;
            $q['salvo'] = $opId;
            header('Location: /admin/relatorio_lucro_veiculos.php?' . http_build_query($q) . '#op' . $opId);
            exit;
        }
        $erro = 'Veículo não encontrado.';
    }
}
if (!empty($_GET['salvo'])) $sucesso = 'Valor da quitação salvo.';

$filtro = in_array($_GET['filtro'] ?? '', ['vendidos', 'estoque', 'todos'], true) ? $_GET['filtro'] : 'vendidos';
$busca = trim((string)($_GET['q'] ?? ''));

$where = ["o.etapa = 'fechado'", "COALESCE(o.oculto_estoque_motivo, '') NOT LIKE 'Cadastro duplicado%'"];
$params = [];
if ($filtro === 'vendidos') $where[] = "v.id IS NOT NULL AND v.etapa = 'vendido'";
if ($filtro === 'estoque') $where[] = 'v.id IS NULL';
if ($busca !== '') {
    $buscaPlaca = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $busca));
    $where[] = "(o.veiculo_marca LIKE ? OR o.veiculo_modelo LIKE ? OR (o.veiculo_marca || ' ' || o.veiculo_modelo) LIKE ? OR v.comprador_nome LIKE ?"
        . ($buscaPlaca !== '' ? " OR UPPER(REPLACE(REPLACE(o.veiculo_placa,'-',''),' ','')) LIKE ?" : '') . ')';
    $like = '%' . $busca . '%';
    array_push($params, $like, $like, $like, $like);
    if ($buscaPlaca !== '') $params[] = $buscaPlaca . '%';
}

// Venda mais recente não cancelada (e não locação) de cada veículo.
$sql = "
    SELECT o.id AS op_id, o.veiculo_marca, o.veiculo_modelo, o.veiculo_ano, o.veiculo_placa,
           o.valor_final, o.valor_quitacao, o.data_compra, o.financiamento_quitado,
           v.id AS venda_id, v.etapa AS venda_etapa, v.comprador_nome, v.data_venda, v.preco_venda,
           v.valor_pago_contratacao, v.parcelamento_valor_parcela, v.parcelamento_qtd_parcelas,
           v.parcelamento_periodicidade,
           (SELECT COALESCE(SUM(l.valor), 0) FROM fin_lancamentos l
              WHERE l.venda_id = v.id AND l.tipo = 'receita' AND l.status = 'pago') AS recebido
    FROM oportunidades o
    LEFT JOIN vendas v ON v.id = (
        SELECT v2.id FROM vendas v2
        WHERE v2.oportunidade_id = o.id AND v2.etapa NOT IN ('cancelada', 'sem_perfil')
              AND COALESCE(v2.tipo_contrato, 'venda') != 'locacao'
        ORDER BY v2.id DESC LIMIT 1
    )
    WHERE " . implode(' AND ', $where) . "
    ORDER BY COALESCE(v.data_venda, o.data_compra) DESC, o.id DESC
";
$st = $db->prepare($sql);
$st->execute($params);
$linhas = $st->fetchAll();

$tot = ['pago' => 0, 'quitacao' => 0, 'venda' => 0, 'entrada' => 0, 'recebido' => 0, 'lucro' => 0, 'n_lucro' => 0, 'sem_quitacao' => 0];
foreach ($linhas as &$l) {
    $pago = (float)($l['valor_final'] ?? 0);
    $quit = $l['valor_quitacao'] !== null ? (float)$l['valor_quitacao'] : null;
    $entrada = (float)($l['valor_pago_contratacao'] ?? 0);
    $parcela = (float)($l['parcelamento_valor_parcela'] ?? 0);
    $qtd = (int)($l['parcelamento_qtd_parcelas'] ?? 0);
    $vendaTotal = null;
    if ($l['venda_id']) {
        $vendaTotal = ($parcela > 0 && $qtd > 0) ? $entrada + $parcela * $qtd : ((float)($l['preco_venda'] ?? 0) ?: ($entrada ?: null));
    }
    $l['_custo'] = $pago + ($quit ?? 0);
    $l['_venda_total'] = $vendaTotal;
    $l['_lucro'] = $vendaTotal !== null ? $vendaTotal - $l['_custo'] : null;
    $tot['pago'] += $pago;
    $tot['quitacao'] += $quit ?? 0;
    if ($quit === null) $tot['sem_quitacao']++;
    $tot['entrada'] += $entrada;
    $tot['recebido'] += (float)$l['recebido'];
    if ($vendaTotal !== null) {
        $tot['venda'] += $vendaTotal;
        $tot['lucro'] += $l['_lucro'];
        $tot['n_lucro']++;
    }
}
unset($l);

function rlMoeda(?float $v): string {
    return $v === null ? '—' : 'R$ ' . number_format($v, 2, ',', '.');
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Lucro por veículo — Fastcar CRM</title>
<link rel="stylesheet" href="/admin/assets/style.css?v=<?= @filemtime(__DIR__ . '/assets/style.css') ?: 1 ?>">
<link rel="stylesheet" href="/admin/assets/mobile.css?v=<?= @filemtime(__DIR__ . '/assets/mobile.css') ?: 1 ?>">
<script src="/admin/assets/mobile.js?v=<?= @filemtime(__DIR__ . '/assets/mobile.js') ?: 1 ?>" defer></script>
<?php include __DIR__ . '/_pwa_head.php'; ?>
<style>
.rl-lucro-pos { color: var(--verde, #15803d); font-weight: 700; }
.rl-lucro-neg { color: var(--vermelho, #dc2626); font-weight: 700; }
.rl-quit-form { display: flex; gap: 6px; align-items: center; margin: 0; }
.rl-quit-form input { max-width: 130px; margin: 0; }
.rl-quit-form button { margin: 0; width: auto; padding: 8px 10px; }
</style>
</head>
<body>
<?php include __DIR__ . '/_impersonando_banner.php'; ?>
<header class="topbar">
    <a href="/admin/veiculos.php" style="color:#fff">← Veículos</a>
    <a class="topbar-brand" href="<?= e(paginaInicialPorPerfil($_SESSION['admin_perfil'] ?? '')) ?>"><img class="topbar-logo" src="/admin/assets/img/icon-192.png" alt="Fastcar" onerror="this.style.display='none'"><span class="topbar-wordmark">Fast<b>Car</b></span></a>
    <span>Olá, <?= e($_SESSION['admin_nome']) ?></span>
    <a href="/admin/logout.php">Sair</a>
</header>
<main>
<h2>📊 Lucro por veículo</h2>
<p><small>Custo = valor pago ao vendedor + quitação do financiamento. Venda = entrada + parcela × quantidade (ou o preço da venda, se não tiver parcelamento). Lucro previsto conta também as parcelas que ainda não foram pagas. Locação fica de fora.</small></p>
<?php if ($erro): ?><div class="alerta-erro"><?= e($erro) ?></div><?php endif; ?>
<?php if ($sucesso): ?><div class="alerta-sucesso"><?= e($sucesso) ?></div><?php endif; ?>

<div class="stat-grid">
    <div class="stat-card"><div class="valor"><?= rlMoeda($tot['pago']) ?></div><div class="rotulo">Pago aos vendedores</div></div>
    <div class="stat-card"><div class="valor"><?= rlMoeda($tot['quitacao']) ?></div><div class="rotulo">Quitações</div><?php if ($tot['sem_quitacao']): ?><small><?= $tot['sem_quitacao'] ?> sem valor preenchido</small><?php endif; ?></div>
    <div class="stat-card"><div class="valor"><?= rlMoeda($tot['venda']) ?></div><div class="rotulo">Total das vendas</div></div>
    <div class="stat-card"><div class="valor"><?= rlMoeda($tot['recebido']) ?></div><div class="rotulo">Já recebido</div></div>
    <div class="stat-card <?= $tot['lucro'] < 0 ? 'alerta' : 'sucesso' ?>"><div class="valor"><?= rlMoeda($tot['lucro']) ?></div><div class="rotulo">Lucro previsto (<?= $tot['n_lucro'] ?> vendido<?= $tot['n_lucro'] === 1 ? '' : 's' ?>)</div></div>
</div>

<div class="card">
    <form method="get" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin:0">
        <div style="flex:1;min-width:200px"><label>Buscar</label><input type="text" name="q" value="<?= e($busca) ?>" placeholder="Placa, marca, modelo ou comprador"></div>
        <div><label>Mostrar</label>
            <select name="filtro">
                <option value="vendidos" <?= $filtro === 'vendidos' ? 'selected' : '' ?>>Vendidos</option>
                <option value="estoque" <?= $filtro === 'estoque' ? 'selected' : '' ?>>Ainda sem venda</option>
                <option value="todos" <?= $filtro === 'todos' ? 'selected' : '' ?>>Todos</option>
            </select>
        </div>
        <div><button type="submit" style="margin:0;width:auto">🔎 Filtrar</button></div>
    </form>
</div>

<div class="card">
<table class="tabela-oportunidades">
    <thead><tr>
        <th>Veículo</th><th>Valor pago</th><th>Quitação</th><th>Custo total</th>
        <th>Entrada</th><th>Parcelas</th><th>Total da venda</th><th>Já recebido</th><th>Lucro</th>
    </tr></thead>
    <tbody>
    <?php if (!$linhas): ?><tr><td colspan="9">Nenhum veículo encontrado.</td></tr><?php endif; ?>
    <?php foreach ($linhas as $l): ?>
        <tr id="op<?= (int)$l['op_id'] ?>">
            <td>
                <a href="/admin/oportunidade.php?id=<?= (int)$l['op_id'] ?>"><strong><?= e(trim($l['veiculo_marca'] . ' ' . $l['veiculo_modelo'] . ' ' . $l['veiculo_ano'])) ?: 'Veículo #' . (int)$l['op_id'] ?></strong></a>
                <?= $l['veiculo_placa'] ? '<br><small>' . e(strtoupper($l['veiculo_placa'])) . '</small>' : '' ?>
                <?php if ($l['venda_id']): ?><br><small><a href="/admin/venda.php?id=<?= (int)$l['venda_id'] ?>">venda #<?= (int)$l['venda_id'] ?></a> · <?= e($l['comprador_nome'] ?: '—') ?><?= $l['venda_etapa'] !== 'vendido' ? ' (em negociação)' : '' ?></small><?php else: ?><br><small>sem venda</small><?php endif; ?>
            </td>
            <td><?= rlMoeda($l['valor_final'] !== null ? (float)$l['valor_final'] : null) ?></td>
            <td data-label="Quitação">
                <form method="post" class="rl-quit-form">
                    <?= csrfField() ?>
                    <input type="hidden" name="acao" value="salvar_quitacao">
                    <input type="hidden" name="oportunidade_id" value="<?= (int)$l['op_id'] ?>">
                    <input type="text" name="valor_quitacao" inputmode="decimal" placeholder="R$ 0,00" value="<?= $l['valor_quitacao'] !== null ? e(number_format((float)$l['valor_quitacao'], 2, ',', '.')) : '' ?>">
                    <button type="submit" title="Salvar quitação">💾</button>
                </form>
                <?php if ($l['financiamento_quitado']): ?><small>✅ financiamento quitado</small><?php endif; ?>
            </td>
            <td><?= rlMoeda($l['_custo']) ?></td>
            <td><?= $l['venda_id'] ? rlMoeda((float)($l['valor_pago_contratacao'] ?? 0)) : '—' ?></td>
            <td>
                <?php if ((int)$l['parcelamento_qtd_parcelas'] > 0 && (float)$l['parcelamento_valor_parcela'] > 0): ?>
                    <?= (int)$l['parcelamento_qtd_parcelas'] ?>× <?= rlMoeda((float)$l['parcelamento_valor_parcela']) ?><?= ($l['parcelamento_periodicidade'] ?? '') === 'semanal' ? ' <small>(semanal)</small>' : '' ?>
                <?php else: ?>
                    <?= $l['venda_id'] ? '<small>à vista / sem parcelas</small>' : '—' ?>
                <?php endif; ?>
            </td>
            <td><?= rlMoeda($l['_venda_total']) ?></td>
            <td><?= $l['venda_id'] ? rlMoeda((float)$l['recebido']) : '—' ?></td>
            <td><?php if ($l['_lucro'] === null): ?>—<?php else: ?><span class="<?= $l['_lucro'] < 0 ? 'rl-lucro-neg' : 'rl-lucro-pos' ?>"><?= rlMoeda($l['_lucro']) ?></span><?php endif; ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
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
