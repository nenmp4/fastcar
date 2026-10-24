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
require_once __DIR__ . '/../includes/vendas_inbox.php';
requireAcessoVendas();

$db = getDB();
$erro = '';
$sucesso = '';

// 24/10/2026, "ou enviar pela instância ou copiar o link" — manda o link
// do veículo pro cliente pela instância de vendas, assinado com o nome do
// vendedor (mesma enviarMensagemManualVendas() da caixa de vendas). Se o
// telefone ainda não tem negociação ativa, abre uma já com esse vendedor
// de responsável — a conversa aparece no WhatsApp Vendas dele.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'enviar_link_cliente') {
    try {
        if (!validateCSRF($_POST['csrf_token'] ?? '')) throw new RuntimeException('Sessão expirada, recarregue a página.');
        if ($_SESSION['admin_perfil'] === 'supervisor') throw new RuntimeException('Supervisor só acompanha.');
        $opId = (int)($_POST['oportunidade_id'] ?? 0);
        $tel = normalizarTelefone((string)($_POST['telefone'] ?? ''));
        $nomeCli = trim((string)($_POST['nome'] ?? ''));
        if (strlen($tel) < 12) throw new RuntimeException('Telefone inválido — use DDD + número.');
        if (!veiculoDisponivelParaVenda($opId)) throw new RuntimeException('Esse veículo não está mais disponível.');
        $stmtV = $db->prepare("SELECT veiculo_marca, veiculo_modelo, veiculo_ano FROM oportunidades WHERE id = ?");
        $stmtV->execute([$opId]);
        $vv = $stmtV->fetch();
        $tituloV = trim(($vv['veiculo_marca'] ?? '') . ' ' . ($vv['veiculo_modelo'] ?? '') . ' ' . ($vv['veiculo_ano'] ?? ''));
        $meuId = (int)$_SESSION['admin_id'];
        $texto = ($nomeCli !== '' ? "Oi, {$nomeCli}! " : 'Oi! ') . "Olha esse {$tituloV} que temos aqui na Fastcar 👇\n" . veiculoLinkPublicoUrl($opId, $meuId);
        $res = enviarMensagemManualVendas($tel, $texto, $meuId);
        if (!$res['ok']) throw new RuntimeException($res['erro']);
        $ph = implode(',', array_fill(0, count(ETAPAS_VENDA_ATIVAS), '?'));
        $stmtA = $db->prepare("SELECT id FROM vendas WHERE comprador_telefone = ? AND etapa IN ({$ph}) LIMIT 1");
        $stmtA->execute(array_merge([$tel], ETAPAS_VENDA_ATIVAS));
        if (!$stmtA->fetchColumn()) {
            $db->prepare("INSERT INTO vendas (etapa, origem, comprador_nome, comprador_telefone, responsavel_id, veiculo_interesse_texto) VALUES ('negociacao', 'whatsapp', ?, ?, ?, ?)")
               ->execute([clean($nomeCli), $tel, $meuId, $tituloV]);
            $novaId = (int)$db->lastInsertId();
            $db->prepare("INSERT INTO venda_historico (venda_id, etapa_anterior, etapa_nova, observacao, responsavel_id) VALUES (?, '', 'negociacao', ?, ?)")
               ->execute([$novaId, "Vendedor enviou o link do {$tituloV} pelo Estoque", $meuId]);
        }
        $sucesso = "Link do {$tituloV} enviado pelo WhatsApp de vendas pra {$tel}.";
    } catch (Throwable $e) {
        $erro = $e->getMessage();
    }
}
// 24/10/2026, "ocultar todos carros no estoque que vieram migrados do
// ZapSign — já foram vendidos". Só super_admin; nunca apaga nada, reversível.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['acao'] ?? '', ['ocultar_migrados', 'ocultar_um', 'mostrar_um'], true)) {
    try {
        if (!validateCSRF($_POST['csrf_token'] ?? '')) throw new RuntimeException('Sessão expirada, recarregue a página.');
        if ($_SESSION['admin_perfil'] !== 'super_admin') throw new RuntimeException('Só o super admin oculta carros do estoque.');
        $acaoOc = $_POST['acao'];
        if ($acaoOc === 'ocultar_migrados') {
            $n = ocultarVeiculosEstoque(idsVeiculosMigradosZapsignNoEstoque(), 'Migrado do ZapSign (CRM antigo) — já vendido');
            $sucesso = "{$n} carro(s) migrado(s) do ZapSign ocultado(s) do estoque.";
        } elseif ($acaoOc === 'ocultar_um') {
            ocultarVeiculosEstoque([(int)$_POST['oportunidade_id']], 'Ocultado manualmente — já vendido');
            $sucesso = 'Carro ocultado do estoque.';
        } else {
            ocultarVeiculosEstoque([(int)$_POST['oportunidade_id']], '', false);
            $sucesso = 'Carro voltou pro estoque.';
        }
    } catch (Throwable $e) {
        $erro = $e->getMessage();
    }
}
$souSuperEstoque = $_SESSION['admin_perfil'] === 'super_admin';
$verOcultos = $souSuperEstoque && ($_GET['ocultos'] ?? '') === '1';
$migradosNoEstoque = $souSuperEstoque ? idsVeiculosMigradosZapsignNoEstoque() : [];
$ocultosLista = [];
if ($verOcultos) {
    $ocultosLista = $db->query("SELECT id, veiculo_marca, veiculo_modelo, veiculo_ano, veiculo_placa, oculto_estoque_motivo, updated_at FROM oportunidades WHERE etapa = 'fechado' AND oculto_estoque = 1 ORDER BY updated_at DESC")->fetchAll();
}
$totalOcultos = $souSuperEstoque ? (int)$db->query("SELECT COUNT(*) FROM oportunidades WHERE etapa = 'fechado' AND oculto_estoque = 1")->fetchColumn() : 0;
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
        if ($r['foto_id']) $fotoCapa[(int)$r['oportunidade_id']] = '/admin/ver_midia_revenda.php?id=' . (int)$r['foto_id'];
        $qtdMidia[(int)$r['oportunidade_id']] = (int)$r['qtd'];
    }
    // Sem foto de revenda ainda? Usa a 1ª foto da vistoria como capa.
    $stmt = $db->prepare("SELECT a.oportunidade_id, MIN(f.id) AS foto_id FROM veiculo_avaliacao_fotos f JOIN veiculo_avaliacoes a ON a.id = f.avaliacao_id WHERE f.tipo = 'foto' AND a.oportunidade_id IN ({$ph}) GROUP BY a.oportunidade_id");
    $stmt->execute($ids);
    foreach ($stmt->fetchAll() as $r) $fotoCapa[(int)$r['oportunidade_id']] ??= '/admin/ver_avaliacao_foto.php?id=' . (int)$r['foto_id'];
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

<?php if ($erro): ?><div class="alerta-erro"><?= e($erro) ?></div><?php endif; ?>
<?php if ($souSuperEstoque): ?>
<div class="card" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
    <?php if ($migradosNoEstoque): ?>
        <form method="post" onsubmit="return confirmarAcao(this, 'Ocultar <?= count($migradosNoEstoque) ?> carro(s) migrado(s) do ZapSign? Eles saem do estoque, da vitrine e da IA de vendas (dá pra mostrar de novo depois).');" style="margin:0">
            <?= csrfField() ?>
            <input type="hidden" name="acao" value="ocultar_migrados">
            <button type="submit" class="perigo" style="margin:0;width:auto">🙈 Ocultar os <?= count($migradosNoEstoque) ?> migrados do ZapSign (já vendidos)</button>
        </form>
    <?php else: ?>
        <small>✅ Nenhum carro migrado do ZapSign aparecendo no estoque.</small>
    <?php endif; ?>
    <a class="btn" href="/admin/estoque.php<?= $verOcultos ? '' : '?ocultos=1' ?>"><?= $verOcultos ? '← Voltar pro estoque' : '👁️ Ver ocultos (' . $totalOcultos . ')' ?></a>
</div>
<?php endif; ?>
<?php if ($verOcultos): ?>
<div class="card">
    <h3 style="margin-top:0">🙈 Ocultos do estoque (<?= count($ocultosLista) ?>)</h3>
    <table class="tabela-oportunidades">
        <thead><tr><th>Veículo</th><th>Motivo</th><th>Ações</th></tr></thead>
        <tbody>
        <?php if (!$ocultosLista): ?><tr><td colspan="3">Nenhum carro oculto.</td></tr><?php endif; ?>
        <?php foreach ($ocultosLista as $oc): ?>
            <tr>
                <td><?= e(trim($oc['veiculo_marca'] . ' ' . $oc['veiculo_modelo'] . ' ' . $oc['veiculo_ano'])) ?: '—' ?><?= $oc['veiculo_placa'] ? '<br><small>' . e(strtoupper($oc['veiculo_placa'])) . '</small>' : '' ?></td>
                <td><small><?= e($oc['oculto_estoque_motivo'] ?: '—') ?></small></td>
                <td>
                    <form method="post" style="margin:0"><?= csrfField() ?><input type="hidden" name="acao" value="mostrar_um"><input type="hidden" name="oportunidade_id" value="<?= (int)$oc['id'] ?>">
                        <button type="submit" class="btn-texto">👁️ Mostrar de novo</button></form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>
<?php if ($sucesso): ?><div class="alerta-sucesso"><?= e($sucesso) ?></div><?php endif; ?>
<?php if (!$veiculos): ?>
    <div class="card"><p>Nenhum veículo disponível<?= ($busca !== '' || $tipoFiltro !== '') ? ' com esse filtro' : ' no estoque agora' ?>.</p></div>
<?php else: ?>
<div class="estoque-grid">
    <?php foreach ($veiculos as $v): $id = (int)$v['oportunidade_id']; $score = buscarScoreVistoriaRecente($id); ?>
    <div class="estoque-card">
        <div class="estoque-foto">
            <?php if (isset($fotoCapa[$id])): ?>
                <img src="<?= e($fotoCapa[$id]) ?>" alt="" loading="lazy">
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
        <?php
            $linkCliente = veiculoLinkPublicoUrl($id, (int)$_SESSION['admin_id']);
            $tituloCard = trim(($v['veiculo_marca'] ?? '') . ' ' . ($v['veiculo_modelo'] ?? '') . ' ' . ($v['veiculo_ano'] ?? ''));
        ?>
        <div class="estoque-acoes">
            <button type="button" class="btn-primary" style="width:100%;margin-top:0" onclick="copiarTexto(<?= e(json_encode($linkCliente)) ?>, this)">🔗 Copiar link pro cliente</button>
            <?php if ($_SESSION['admin_perfil'] !== 'supervisor'): ?>
            <details style="margin-top:8px">
                <summary class="btn" style="list-style:none;cursor:pointer;text-align:center">💬 Enviar pelo WhatsApp de vendas</summary>
                <form method="post" style="margin-top:8px">
                    <?= csrfField() ?>
                    <input type="hidden" name="acao" value="enviar_link_cliente">
                    <input type="hidden" name="oportunidade_id" value="<?= $id ?>">
                    <input type="tel" name="telefone" required placeholder="Telefone com DDD">
                    <input type="text" name="nome" placeholder="Nome do cliente (opcional)">
                    <button type="submit" style="width:100%">Enviar link</button>
                </form>
            </details>
            <?php endif; ?>
            <a class="btn" style="margin-top:8px" href="/admin/veiculo_midias.php?id=<?= $id ?>">📸 Fotos e vídeos</a>
            <?php if ($souSuperEstoque): ?>
            <form method="post" style="margin-top:8px" onsubmit="return confirmarAcao(this, 'Ocultar este carro do estoque? (já vendido / não está mais com a Fastcar)');">
                <?= csrfField() ?>
                <input type="hidden" name="acao" value="ocultar_um">
                <input type="hidden" name="oportunidade_id" value="<?= $id ?>">
                <button type="submit" class="btn-texto perigo" style="width:100%">🙈 Ocultar (já vendido)</button>
            </form>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>
</main>
<script>
function copiarTexto(texto, btn) {
    var original = btn.textContent;
    function marcarCopiado() {
        btn.textContent = '✅ Copiado!';
        setTimeout(function () { btn.textContent = original; }, 2000);
    }
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(texto).then(marcarCopiado).catch(function () { copiarTextoFallback(texto, marcarCopiado); });
    } else {
        copiarTextoFallback(texto, marcarCopiado);
    }
}
function copiarTextoFallback(texto, callback) {
    var ta = document.createElement('textarea');
    ta.value = texto;
    ta.style.position = 'fixed';
    ta.style.left = '-9999px';
    document.body.appendChild(ta);
    ta.select();
    try { document.execCommand('copy'); callback(); } catch (e) {}
    document.body.removeChild(ta);
}
</script>
<?php include __DIR__ . '/_pwa_register.php'; ?>
<?php $notifModulo = 'vendas'; include __DIR__ . '/_notify.php'; ?>
<?php include __DIR__ . '/_scroll_restore.php'; ?>
<?php include __DIR__ . '/_acao_popup.php'; ?>
<?php include __DIR__ . '/_confirm_dialog.php'; ?>
<?php include __DIR__ . '/_zapi_status_vendas.php'; ?>
</body>
</html>
