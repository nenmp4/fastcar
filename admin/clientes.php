<?php
/**
 * Módulo cliente — lista/busca independente de oportunidade. Útil quando
 * o mesmo telefone tem mais de um veículo negociado ao longo do tempo
 * (regra do Jean: 1 cadastro por telefone, N oportunidades).
 */

require_once __DIR__ . '/_bootstrap.php';

$busca = trim((string)($_GET['q'] ?? ''));
$db = getDB();

// 04/11/2026, "tela de clientes: são vendidos os carros - clientes que
// compramos o carro tem separar fica mais fácil" — 3 abas:
//   todos        → todo cadastro (leads + quem já negociou), como sempre foi
//   vendedores   → quem VENDEU o carro pra Fastcar (oportunidade fechada)
//   compradores  → quem COMPROU carro da Fastcar (venda concluída) — vem de
//                  `vendas` (comprador não vira linha em `clientes`)
$perfilCli = (string)($_SESSION['admin_perfil'] ?? '');
$aba = (string)($_GET['aba'] ?? 'todos');
if (!in_array($aba, ['todos', 'vendedores', 'compradores'], true)) $aba = 'todos';
if ($perfilCli === 'vendedor') $aba = 'compradores'; // vendedor só vê o lado dele

// 21/10/2026, "clientes todos aparece para todos... temos cada consultor
// tenha seus clientes" — até aqui essa tela não tinha NENHUM filtro de
// responsável, qualquer consultor via a base inteira. Mesmo padrão de
// "minha carteira" já usado no resto do sistema (perfilVeTudo(), 15/09) —
// financeiro mantém acesso irrestrito de propósito (confirmado com o
// usuário: "financeiro continua vendo todos", já tinha acesso explícito
// desde 18/09 e não é responsável de oportunidade nenhuma).
$responsavelFiltro = (perfilVeTudo() || ($_SESSION['admin_perfil'] ?? '') === 'financeiro')
    ? null
    : (int)$_SESSION['admin_id'];

// Total de clientes que batem com a busca ANTES de paginar — sem isso,
// LIMIT 100 sozinho (como era antes) simplesmente escondia todo cliente
// além do 100º (por created_at DESC), sem paginação nenhuma pra ver o
// resto — bug real achado ("quantas negociações ficar na tela, já pensou
// nisso?").
$condicoesBusca = [];
$paramsBusca = [];
if ($busca) {
    $condicoesBusca[] = "(c.nome LIKE ? OR c.telefone LIKE ? OR c.cidade LIKE ?)";
    $like = '%' . $busca . '%';
    array_push($paramsBusca, $like, $like, $like);
}
if ($responsavelFiltro !== null) {
    // EXISTS, nunca filtrando o JOIN de agregação abaixo — um cliente
    // continua mostrando o histórico COMPLETO de oportunidades (inclusive
    // de outro consultor), só a VISIBILIDADE do cliente em si é que exige
    // ter sido responsável em pelo menos uma, mesmo já fechada/perdida.
    $condicoesBusca[] = "EXISTS (SELECT 1 FROM oportunidades o2 WHERE o2.cliente_id = c.id AND o2.responsavel_id = ?)";
    $paramsBusca[] = $responsavelFiltro;
}
if ($aba === 'vendedores') {
    $condicoesBusca[] = "EXISTS (SELECT 1 FROM oportunidades o3 WHERE o3.cliente_id = c.id AND o3.etapa = 'fechado')";
}
$whereBusca = $condicoesBusca ? (" WHERE " . implode(' AND ', $condicoesBusca)) : '';
$stmtTotal = $db->prepare("SELECT COUNT(*) FROM clientes c{$whereBusca}");
$stmtTotal->execute($paramsBusca);
$totalClientes = (int)$stmtTotal->fetchColumn();

$sql = "
    SELECT c.*, COUNT(o.id) AS total_oportunidades,
           SUM(CASE WHEN o.etapa IN (" . implode(',', array_fill(0, count(ETAPAS_ATIVAS), '?')) . ") THEN 1 ELSE 0 END) AS ativas,
           SUM(CASE WHEN o.etapa = 'fechado' THEN 1 ELSE 0 END) AS convertidas
    FROM clientes c
    LEFT JOIN oportunidades o ON o.cliente_id = c.id
    {$whereBusca}
    GROUP BY c.id ORDER BY c.created_at DESC
    LIMIT " . ITENS_POR_PAGINA_PADRAO . " OFFSET " . paginacaoOffset();
$params = array_merge(ETAPAS_ATIVAS, $paramsBusca);

$clientes = [];
$compradores = [];
if ($aba !== 'compradores') {
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $clientes = $stmt->fetchAll();
}

// Veículos que cada vendedor passou pra Fastcar (só na aba vendedores).
$carrosVendedor = [];
if ($aba === 'vendedores' && $clientes) {
    $ids = array_map(fn($c) => (int)$c['id'], $clientes);
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $stC = $db->prepare("SELECT id, cliente_id, veiculo_marca, veiculo_modelo, veiculo_ano, veiculo_placa, valor_final, data_compra
                         FROM oportunidades WHERE etapa = 'fechado' AND cliente_id IN ({$ph}) ORDER BY data_compra DESC, id DESC");
    $stC->execute($ids);
    foreach ($stC->fetchAll() as $r) $carrosVendedor[(int)$r['cliente_id']][] = $r;
}

// Compradores: agrupados por telefone (1 linha por pessoa, N carros).
$condV = ["v.etapa = 'vendido'"];
$paramsV = [];
if ($busca) {
    $condV[] = "(v.comprador_nome LIKE ? OR v.comprador_telefone LIKE ? OR o.veiculo_placa LIKE ? OR o.veiculo_modelo LIKE ?)";
    $likeV = '%' . $busca . '%';
    array_push($paramsV, $likeV, $likeV, $likeV, $likeV);
}
if ($perfilCli === 'vendedor' || ($responsavelFiltro !== null && $perfilCli !== 'vendedor')) {
    $condV[] = "v.responsavel_id = ?";
    $paramsV[] = (int)$_SESSION['admin_id'];
}
$whereV = implode(' AND ', $condV);
$chaveV = "COALESCE(NULLIF(v.comprador_telefone, ''), 'venda-' || v.id)";
$stTotV = $db->prepare("SELECT COUNT(DISTINCT {$chaveV}) FROM vendas v LEFT JOIN oportunidades o ON o.id = v.oportunidade_id WHERE {$whereV}");
$stTotV->execute($paramsV);
$totalCompradores = (int)$stTotV->fetchColumn();
if ($aba === 'compradores') {
    $stV = $db->prepare("
        SELECT {$chaveV} AS chave, MAX(v.comprador_nome) AS nome, MAX(v.comprador_telefone) AS telefone,
               COUNT(*) AS qtd, COALESCE(SUM(v.preco_venda), 0) AS total, MAX(COALESCE(v.data_venda, v.created_at)) AS ultima
        FROM vendas v LEFT JOIN oportunidades o ON o.id = v.oportunidade_id
        WHERE {$whereV}
        GROUP BY chave ORDER BY ultima DESC
        LIMIT " . ITENS_POR_PAGINA_PADRAO . " OFFSET " . paginacaoOffset());
    $stV->execute($paramsV);
    $compradores = $stV->fetchAll();
    // Carros de cada comprador.
    $stCar = $db->prepare("
        SELECT v.id, v.preco_venda, v.data_venda, v.tipo_contrato, o.veiculo_marca, o.veiculo_modelo, o.veiculo_ano, o.veiculo_placa, u.nome AS vendedor
        FROM vendas v LEFT JOIN oportunidades o ON o.id = v.oportunidade_id LEFT JOIN usuarios u ON u.id = v.responsavel_id
        WHERE {$whereV} AND {$chaveV} = ? ORDER BY v.id DESC");
    foreach ($compradores as &$cp) {
        $stCar->execute(array_merge($paramsV, [$cp['chave']]));
        $cp['carros'] = $stCar->fetchAll();
    }
    unset($cp);
}

// Contagens das abas.
$totalVendedores = 0;
if ($perfilCli !== 'vendedor') {
    $condVd = ["EXISTS (SELECT 1 FROM oportunidades o3 WHERE o3.cliente_id = c.id AND o3.etapa = 'fechado')"];
    $paramsVd = [];
    if ($responsavelFiltro !== null) { $condVd[] = "EXISTS (SELECT 1 FROM oportunidades o2 WHERE o2.cliente_id = c.id AND o2.responsavel_id = ?)"; $paramsVd[] = $responsavelFiltro; }
    $stVd = $db->prepare("SELECT COUNT(*) FROM clientes c WHERE " . implode(' AND ', $condVd));
    $stVd->execute($paramsVd);
    $totalVendedores = (int)$stVd->fetchColumn();
}
$fmtR = fn($v) => 'R$ ' . number_format((float)$v, 2, ',', '.');
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Clientes — Fastcar CRM</title>
<link rel="stylesheet" href="/admin/assets/style.css?v=<?= @filemtime(__DIR__ . '/assets/style.css') ?: 1 ?>">
<link rel="stylesheet" href="/admin/assets/mobile.css?v=<?= @filemtime(__DIR__ . '/assets/mobile.css') ?: 1 ?>">
<script src="/admin/assets/mobile.js?v=<?= @filemtime(__DIR__ . '/assets/mobile.js') ?: 1 ?>" defer></script>
<?php include __DIR__ . '/_pwa_head.php'; ?>
</head>
<body>
<?php include __DIR__ . '/_impersonando_banner.php'; ?>
<header class="topbar">
    <a href="<?= $perfilCli === 'vendedor' ? '/admin/vendas.php' : '/admin/index.php' ?>" style="color:#fff">← Voltar</a>
    <a class="topbar-brand" href="<?= e(paginaInicialPorPerfil($_SESSION['admin_perfil'] ?? '')) ?>"><img class="topbar-logo" src="/admin/assets/img/icon-192.png" alt="Fastcar" onerror="this.style.display='none'"><span class="topbar-wordmark">Fast<b>Car</b></span></a>
    <span>Olá, <?= e($_SESSION['admin_nome']) ?></span>
    <a href="/admin/logout.php">Sair</a>
</header>

<main>
<div class="card">
    <h2>👥 Clientes</h2>
    <nav class="etapas-nav" style="margin-bottom:10px">
        <?php if ($perfilCli !== 'vendedor'): ?>
        <a class="<?= $aba === 'todos' ? 'ativo' : '' ?>" href="?aba=todos">Todos</a>
        <a class="<?= $aba === 'vendedores' ? 'ativo' : '' ?>" href="?aba=vendedores">🚗 Vendedores — compramos o carro deles (<?= $totalVendedores ?>)</a>
        <?php endif; ?>
        <a class="<?= $aba === 'compradores' ? 'ativo' : '' ?>" href="?aba=compradores">🛒 Compradores — vendemos o carro pra eles (<?= $totalCompradores ?>)</a>
    </nav>
    <form method="get">
        <input type="hidden" name="aba" value="<?= e($aba) ?>">
        <input type="text" name="q" value="<?= e($busca) ?>" placeholder="<?= $aba === 'compradores' ? 'Buscar por nome, telefone, placa ou modelo...' : 'Buscar por nome, telefone ou cidade...' ?>">
        <button type="submit">Buscar</button>
    </form>
</div>

<?php if ($aba === 'compradores'): ?>
<table class="tabela-oportunidades">
    <thead><tr><th>Comprador</th><th>Telefone</th><th>Carro(s) comprado(s)</th><th>Total</th><th>Última compra</th><th></th></tr></thead>
    <tbody>
    <?php if (!$compradores): ?><tr><td colspan="6">Nenhum comprador encontrado.</td></tr><?php endif; ?>
    <?php foreach ($compradores as $cp): ?>
        <tr>
            <td><?= e($cp['nome'] ?: '(sem nome)') ?><?= (int)$cp['qtd'] > 1 ? ' <span class="badge badge-ok">' . (int)$cp['qtd'] . ' carros</span>' : '' ?></td>
            <td><?= e($cp['telefone'] ?: '—') ?></td>
            <td><?php foreach ($cp['carros'] as $car): ?>
                <div style="margin-bottom:4px"><a href="/admin/venda.php?id=<?= (int)$car['id'] ?>"><?= e(trim(($car['veiculo_marca'] ?? '') . ' ' . ($car['veiculo_modelo'] ?? '') . ' ' . ($car['veiculo_ano'] ?? ''))) ?: 'Veículo' ?></a>
                    <?= $car['veiculo_placa'] ? '<small>· ' . e($car['veiculo_placa']) . '</small>' : '' ?>
                    <small>· <?= $car['preco_venda'] ? $fmtR($car['preco_venda']) : '—' ?><?= ($car['tipo_contrato'] ?? '') === 'locacao' ? ' · 🔑 locação' : '' ?><?= $car['vendedor'] ? ' · ' . e($car['vendedor']) : '' ?></small></div>
            <?php endforeach; ?></td>
            <td><?= $fmtR($cp['total']) ?></td>
            <td><?= $cp['ultima'] ? date('d/m/Y', strtotime($cp['ultima'])) : '—' ?></td>
            <td><a class="btn" href="/admin/venda.php?id=<?= (int)$cp['carros'][0]['id'] ?>">Abrir →</a></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php renderPaginacao($totalCompradores); ?>
<?php elseif ($aba === 'vendedores'): ?>
<table class="tabela-oportunidades">
    <thead><tr><th>Vendedor</th><th>Telefone</th><th>Carro(s) que vendeu pra Fastcar</th><th>Cidade/UF</th><th></th></tr></thead>
    <tbody>
    <?php if (!$clientes): ?><tr><td colspan="5">Nenhum vendedor encontrado.</td></tr><?php endif; ?>
    <?php foreach ($clientes as $c): ?>
        <tr>
            <td><?= e($c['nome'] ?: '(sem nome)') ?></td>
            <td><?= e($c['telefone']) ?></td>
            <td><?php foreach ($carrosVendedor[(int)$c['id']] ?? [] as $car): ?>
                <div style="margin-bottom:4px"><a href="/admin/oportunidade.php?id=<?= (int)$car['id'] ?>"><?= e(trim(($car['veiculo_marca'] ?? '') . ' ' . ($car['veiculo_modelo'] ?? '') . ' ' . ($car['veiculo_ano'] ?? ''))) ?: 'Veículo' ?></a>
                    <?= $car['veiculo_placa'] ? '<small>· ' . e($car['veiculo_placa']) . '</small>' : '' ?>
                    <small>· pago <?= $car['valor_final'] !== null ? $fmtR($car['valor_final']) : '—' ?><?= $car['data_compra'] ? ' em ' . date('d/m/Y', strtotime($car['data_compra'])) : '' ?></small></div>
            <?php endforeach; ?></td>
            <td><?= e($c['cidade'] ?: '—') ?><?= $c['estado'] ? '/' . e($c['estado']) : '' ?></td>
            <td><a class="btn" href="/admin/cliente_detalhe.php?id=<?= (int)$c['id'] ?>">Abrir →</a></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php renderPaginacao($totalClientes); ?>
<?php else: ?>
<table class="tabela-oportunidades">
    <thead>
        <tr><th>Nome</th><th>Recebido em</th><th>Telefone</th><th>Cidade/UF</th><th>Origem</th><th>Oportunidades</th><th>Status</th><th></th></tr>
    </thead>
    <tbody>
    <?php if (!$clientes): ?>
        <tr><td colspan="8">Nenhum cliente encontrado.</td></tr>
    <?php endif; ?>
    <?php foreach ($clientes as $c): ?>
        <tr>
            <td><?= e($c['nome'] ?: '(sem nome)') ?></td>
            <td><?= $c['created_at'] ? date('d/m/Y H:i', strtotime($c['created_at'])) : '—' ?></td>
            <td><?= e($c['telefone']) ?></td>
            <td><?= e($c['cidade'] ?: '—') ?><?= $c['estado'] ? '/' . e($c['estado']) : '' ?></td>
            <td><?= e($c['canal_origem'] ?: 'direto') ?></td>
            <td>
                <?= (int)$c['total_oportunidades'] ?> total
                <?php if ($c['ativas'] > 0): ?><span class="badge badge-ok"><?= (int)$c['ativas'] ?> ativa(s)</span><?php endif; ?>
            </td>
            <td>
                <?php if ((int)$c['convertidas'] > 0): ?>
                    <span class="badge badge-ok" title="Já teve pelo menos um veículo com negócio fechado">🏆 Cliente convertido</span>
                <?php else: ?>
                    <span class="badge">Lead</span>
                <?php endif; ?>
            </td>
            <td><a href="/admin/cliente_detalhe.php?id=<?= (int)$c['id'] ?>">Abrir →</a></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php renderPaginacao($totalClientes); ?>
<?php endif; ?>
</main>
<?php include __DIR__ . '/_pwa_register.php'; ?>
<?php include __DIR__ . '/_notify.php'; ?>
<?php include __DIR__ . '/_scroll_restore.php'; ?>
<?php include __DIR__ . '/_acao_popup.php'; ?>
<?php include __DIR__ . '/_confirm_dialog.php'; ?>
<?php include __DIR__ . '/_zapi_status.php'; ?>
</body>
</html>
