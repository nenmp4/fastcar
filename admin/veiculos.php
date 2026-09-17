<?php
/**
 * Veículos — frota comprada pela Fastcar (bloco 8, etapa='fechado').
 * Pedido explícito: buscar veículo por placa/chassi e ver de quem foi
 * comprado, quando, por quanto e há quantos meses está com a Fastcar.
 * Restrito ao super_admin, mesma trava das outras telas de relatório.
 *
 * "Vender este veículo" (15/09/2026, módulo de vendas) abre uma negociação
 * nova (includes/vendas.php::criarVenda()) pro veículo clicado e manda
 * direto pro detalhe (admin/venda.php) — busca por placa/chassi já existia
 * de propósito desde antes, pensando exatamente nisso: dar pra relacionar
 * o mesmo veículo físico com uma revenda sem remodelar nada.
 */

require_once __DIR__ . '/_bootstrap.php';
requireSuperAdmin();

$db = getDB();
$busca = trim((string)($_GET['busca'] ?? ''));
$erro = '';

$sucesso = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'iniciar_venda') {
    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        $erro = 'Sessão expirada, recarregue a página e tente de novo.';
    } else {
        try {
            $vendaId = criarVenda((int)$_POST['oportunidade_id'], (int)$_SESSION['admin_id']);
            header('Location: /admin/venda.php?id=' . $vendaId);
            exit;
        } catch (Throwable $e) {
            $erro = $e->getMessage();
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'cadastrar_manual') {
    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        $erro = 'Sessão expirada, recarregue a página e tente de novo.';
    } else {
        try {
            $valorPagoPost = trim((string)($_POST['valor_final'] ?? ''));
            $r = criarVeiculoManualFrota(
                (string)($_POST['vendedor_nome'] ?? ''),
                (string)($_POST['vendedor_telefone'] ?? ''),
                (string)($_POST['veiculo_marca'] ?? ''),
                (string)($_POST['veiculo_modelo'] ?? ''),
                (string)($_POST['veiculo_ano'] ?? ''),
                (string)($_POST['veiculo_placa'] ?? ''),
                (string)($_POST['veiculo_chassi'] ?? ''),
                (string)($_POST['veiculo_renavam'] ?? ''),
                $valorPagoPost !== '' ? (float)str_replace(',', '.', preg_replace('/[^\d,.-]/', '', $valorPagoPost)) : null,
                (int)$_SESSION['admin_id']
            );
            header('Location: /admin/veiculo_midias.php?id=' . $r['oportunidade_id'] . '&recem_cadastrado=1');
            exit;
        } catch (Throwable $e) {
            $erro = $e->getMessage();
        }
    }
}

$where = "WHERE o.etapa = 'fechado'";
$params = [];
if ($busca !== '') {
    $where .= " AND (o.veiculo_placa LIKE ? OR o.veiculo_chassi LIKE ? OR o.veiculo_marca LIKE ? OR o.veiculo_modelo LIKE ? OR c.nome LIKE ?)";
    $like = '%' . $busca . '%';
    $params = [$like, $like, $like, $like, $like];
}

$stmtTotal = $db->prepare("SELECT COUNT(*) FROM oportunidades o JOIN clientes c ON c.id = o.cliente_id {$where}");
$stmtTotal->execute($params);
$totalVeiculos = (int)$stmtTotal->fetchColumn();

$sql = "
    SELECT o.*, c.nome AS cliente_nome, c.telefone AS cliente_telefone,
           vd.id AS venda_id, vd.etapa AS venda_etapa,
           (SELECT COUNT(*) FROM veiculo_midias_revenda WHERE oportunidade_id = o.id) AS total_midias
    FROM oportunidades o
    JOIN clientes c ON c.id = o.cliente_id
    LEFT JOIN vendas vd ON vd.id = (
        SELECT id FROM vendas WHERE oportunidade_id = o.id AND etapa != 'cancelada'
        ORDER BY CASE etapa WHEN 'vendido' THEN 0 WHEN 'contrato_enviado' THEN 1 ELSE 2 END
        LIMIT 1
    )
    {$where}
    ORDER BY o.data_compra DESC, o.updated_at DESC
    LIMIT " . ITENS_POR_PAGINA_PADRAO . " OFFSET " . paginacaoOffset();

$stmt = $db->prepare($sql);
$stmt->execute($params);
$veiculos = $stmt->fetchAll();

// Soma de TODOS os veículos que batem com a busca, não só os da página
// atual — com paginação, array_sum() em cima de $veiculos somaria só os
// 25 da tela, dando um "total pago" errado assim que passasse de 1 página.
$stmtTotalPago = $db->prepare("SELECT SUM(o.valor_final) FROM oportunidades o JOIN clientes c ON c.id = o.cliente_id {$where}");
$stmtTotalPago->execute($params);
$totalPago = (float)($stmtTotalPago->fetchColumn() ?: 0);

/** Meses inteiros desde a data de compra (ou updated_at se data_compra não foi preenchida) até hoje. */
function mesesComAFastcar(?string $dataCompra, string $updatedAt): int {
    $ref = $dataCompra ?: $updatedAt;
    if (!$ref) return 0;
    $inicio = new DateTime($ref);
    $agora = new DateTime();
    $diff = $inicio->diff($agora);
    return $diff->y * 12 + $diff->m;
}
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Veículos — Fastcar CRM</title>
<link rel="stylesheet" href="/admin/assets/style.css?v=<?= @filemtime(__DIR__ . '/assets/style.css') ?: 1 ?>">
<?php include __DIR__ . '/_pwa_head.php'; ?>
</head>
<body>
<header class="topbar">
    <a href="/admin/index.php" style="color:#fff">← Voltar</a>
    <strong><img class="topbar-logo" src="/admin/assets/img/icon-192.png" alt="Fastcar" onerror="this.style.display='none'"> Fast<b>Car</b> <span class="crm-tag">CRM</span></strong>
    <span>Olá, <?= e($_SESSION['admin_nome']) ?></span>
    <a href="/admin/vendas.php">💰 Vendas</a>
    <a href="/admin/configuracoes.php">⚙️ Configurações</a>
    <a href="/admin/logout.php">Sair</a>
</header>

<main>
<?php if ($erro): ?><div class="alerta-erro"><?= e($erro) ?></div><?php endif; ?>
<?php if ($sucesso): ?><div class="alerta-sucesso"><?= e($sucesso) ?></div><?php endif; ?>
<div class="card">
    <h2>🚗 Veículos comprados</h2>
    <p><small>Frota atual da Fastcar — todo veículo com negócio fechado (bloco 8). Busca por placa, chassi, marca/
       modelo ou nome do vendedor.</small></p>

    <form method="get">
        <input type="text" name="busca" value="<?= e($busca) ?>" placeholder="Placa, chassi, marca/modelo ou vendedor...">
        <button type="submit">Buscar</button>
    </form>
</div>

<div class="card">
    <h3>➕ Adicionar veículo manualmente</h3>
    <p><small>Pra veículo que a Fastcar já tem, mas não passou pelo funil de compra pelo WhatsApp (negócio fechado
       fora do CRM, frota legada, etc) — entra direto na frota, pronto pra ganhar fotos/vídeos e ir pro módulo de
       vendas. Continua pedindo o vendedor/origem (nome + telefone), mesma disciplina de cadastro do resto do
       sistema.</small></p>
    <div class="form-group" style="max-width:420px;margin-bottom:14px">
        <label>📄 Subir pelo CRLV (opcional) — a IA lê o documento e preenche os campos do veículo abaixo</label>
        <input type="file" id="mv-crlv-arquivo" accept="image/jpeg,image/png,image/webp,application/pdf">
        <button type="button" onclick="lerCrlvManual()" style="margin-top:.4rem" id="mv-crlv-btn">📄 Ler CRLV com IA</button>
        <span id="mv-crlv-status" style="font-size:.8rem;color:var(--muted);margin-left:.5rem"></span>
    </div>
    <form method="post">
        <?= csrfField() ?>
        <input type="hidden" name="acao" value="cadastrar_manual">
        <div class="grid-2">
            <div>
                <label>Nome do vendedor/origem *</label>
                <input type="text" name="vendedor_nome" required>
                <label>Telefone do vendedor/origem *</label>
                <input type="text" name="vendedor_telefone" required placeholder="Ex: 31999998888">
                <label>Valor pago (R$)</label>
                <input type="text" name="valor_final" placeholder="0,00">
            </div>
            <div>
                <label>Marca</label>
                <input type="text" name="veiculo_marca" id="mv-marca">
                <label>Modelo</label>
                <input type="text" name="veiculo_modelo" id="mv-modelo">
                <label>Ano</label>
                <input type="text" name="veiculo_ano" id="mv-ano" style="max-width:120px">
                <label>Placa</label>
                <input type="text" name="veiculo_placa" id="mv-placa" style="max-width:160px">
                <label>Chassi</label>
                <input type="text" name="veiculo_chassi" id="mv-chassi">
                <label>RENAVAM</label>
                <input type="text" name="veiculo_renavam" id="mv-renavam">
            </div>
        </div>
        <button type="submit">Cadastrar e adicionar fotos/vídeos →</button>
    </form>
</div>

<script>
var csrfTokenVeiculos = <?= json_encode(generateCSRF()) ?>;

function lerCrlvManual() {
    var input = document.getElementById('mv-crlv-arquivo');
    if (!input.files.length) { alert('Escolha o arquivo do CRLV primeiro.'); return; }
    var status = document.getElementById('mv-crlv-status');
    var btn = document.getElementById('mv-crlv-btn');
    status.textContent = '🔄 Lendo CRLV...';
    btn.disabled = true;

    var fd = new FormData();
    fd.append('crlv', input.files[0]);
    fd.append('csrf_token', csrfTokenVeiculos);
    fetch('/admin/veiculo_crlv_ajax.php', { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            btn.disabled = false;
            if (!d.ok) { status.textContent = '⚠️ ' + d.erro; return; }
            // fill-if-empty — nunca sobrescreve o que já foi digitado/corrigido na mão.
            var campos = { marca: d.veiculo_marca, modelo: d.veiculo_modelo, ano: d.veiculo_ano, placa: d.veiculo_placa, chassi: d.veiculo_chassi, renavam: d.veiculo_renavam };
            Object.keys(campos).forEach(function (k) {
                var el = document.getElementById('mv-' + k);
                if (el && !el.value && campos[k]) el.value = campos[k];
            });
            status.textContent = '✅ Preenchido! Confira antes de cadastrar.';
        })
        .catch(function (err) { btn.disabled = false; status.textContent = '⚠️ Erro ao ler o CRLV.'; console.error(err); });
}
</script>

<div class="stat-grid">
    <div class="stat-card">
        <div class="valor"><?= $totalVeiculos ?></div>
        <div class="rotulo">Veículos na frota</div>
    </div>
    <div class="stat-card sucesso">
        <div class="valor">R$ <?= number_format($totalPago, 2, ',', '.') ?></div>
        <div class="rotulo">Total pago aos vendedores</div>
    </div>
</div>

<div class="card">
    <table class="tabela-oportunidades">
        <thead>
            <tr>
                <th>Veículo</th><th>Placa / Chassi</th><th>Comprado de</th>
                <th>Valor pago</th><th>Data da compra</th><th>Meses com a Fastcar</th>
                <th>Contrato compra</th><th>Fotos/vídeos</th><th>Venda</th><th></th>
            </tr>
        </thead>
        <tbody>
        <?php if (!$veiculos): ?>
            <tr><td colspan="10"><?= $busca ? 'Nenhum veículo encontrado pra essa busca.' : 'Nenhum veículo comprado ainda.' ?></td></tr>
        <?php endif; ?>
        <?php foreach ($veiculos as $v): ?>
            <tr>
                <td><?= e(trim($v['veiculo_marca'] . ' ' . $v['veiculo_modelo'])) ?: '—' ?> <?= e($v['veiculo_ano']) ?></td>
                <td><?= e($v['veiculo_placa'] ?: '—') ?><?php if ($v['veiculo_chassi']): ?><br><small><?= e($v['veiculo_chassi']) ?></small><?php endif; ?></td>
                <td><a href="/admin/cliente_detalhe.php?id=<?= (int)$v['cliente_id'] ?>"><?= e($v['cliente_nome']) ?></a><br><small><?= e($v['cliente_telefone']) ?></small></td>
                <td><?= $v['valor_final'] !== null ? 'R$ ' . number_format((float)$v['valor_final'], 2, ',', '.') : '—' ?></td>
                <td><?= $v['data_compra'] ? date('d/m/Y', strtotime($v['data_compra'])) : '—' ?></td>
                <td><?= mesesComAFastcar($v['data_compra'], $v['updated_at']) ?> mês(es)</td>
                <td>
                    <?php if ($v['contrato_assinado'] ?? false): ?>
                        <span class="badge badge-ok">✅ assinado</span>
                    <?php else: ?>
                        <span class="badge badge-aviso">pendente</span>
                    <?php endif; ?>
                </td>
                <td><a href="/admin/veiculo_midias.php?id=<?= (int)$v['id'] ?>">📸 <?= (int)$v['total_midias'] ?></a></td>
                <td>
                    <?php if ($v['venda_id'] === null): ?>
                        <form method="post" class="inline">
                            <?= csrfField() ?>
                            <input type="hidden" name="acao" value="iniciar_venda">
                            <input type="hidden" name="oportunidade_id" value="<?= (int)$v['id'] ?>">
                            <button type="submit" style="margin-top:0;padding:5px 10px;font-size:12px">💰 Vender</button>
                        </form>
                    <?php elseif ($v['venda_etapa'] === 'vendido'): ?>
                        <a href="/admin/venda.php?id=<?= (int)$v['venda_id'] ?>"><span class="badge badge-ok">✅ vendido</span></a>
                    <?php else: ?>
                        <a href="/admin/venda.php?id=<?= (int)$v['venda_id'] ?>"><span class="badge"><?= e(etapaVendaLabel($v['venda_etapa'])) ?></span></a>
                    <?php endif; ?>
                </td>
                <td><a href="/admin/oportunidade.php?id=<?= (int)$v['id'] ?>">Abrir →</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php renderPaginacao($totalVeiculos); ?>
</div>
</main>
<?php include __DIR__ . '/_pwa_register.php'; ?>
<?php include __DIR__ . '/_notify.php'; ?>
</body>
</html>
