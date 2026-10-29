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
// 29/10/2026, "em estoque carros sem vistoria aparecer botão pra fazer
// vistoria" — cria a vistoria de recebimento (compra) direto do card, já com
// o avaliador escolhido (avaliador logado = ele mesmo) e abre ela.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'fazer_vistoria') {
    try {
        if (!validateCSRF($_POST['csrf_token'] ?? '')) throw new RuntimeException('Sessão expirada, recarregue a página.');
        if ($_SESSION['admin_perfil'] === 'supervisor') throw new RuntimeException('Supervisor só acompanha.');
        $opIdV = (int)($_POST['oportunidade_id'] ?? 0);
        $avalId = $_SESSION['admin_perfil'] === 'avaliador' ? (int)$_SESSION['admin_id'] : (int)($_POST['avaliador_id'] ?? 0);
        $stA = $db->prepare("SELECT 1 FROM usuarios WHERE id = ? AND perfil = 'avaliador' AND bloqueado = 0");
        $stA->execute([$avalId]);
        if (!$stA->fetchColumn()) throw new RuntimeException('Escolha um avaliador pra fazer a vistoria.');
        $stT = $db->prepare("SELECT tipo_veiculo FROM oportunidades WHERE id = ? AND etapa = 'fechado'");
        $stT->execute([$opIdV]);
        $tv = $stT->fetchColumn();
        if ($tv === false) throw new RuntimeException('Veículo não encontrado no estoque.');
        $avId = criarAvaliacao($opIdV, 'compra', null, $avalId, (int)$_SESSION['admin_id'], $tv === 'moto' ? 'moto' : 'carro');
        header('Location: /admin/avaliacao.php?id=' . $avId);
        exit;
    } catch (Throwable $e) {
        $erro = $e->getMessage();
    }
}

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
// 24/10/2026, "botão que abre modal — tipo de venda: promissória ou
// quitação futura". Quitação futura = mesmo fluxo do botão "Vender" da
// Frota (criarVenda() → negociação com o contrato de venda com quitação
// futura). Promissória = abre o modal de promissória em Vendas já com o carro.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'iniciar_venda_quitacao') {
    try {
        if (!validateCSRF($_POST['csrf_token'] ?? '')) throw new RuntimeException('Sessão expirada, recarregue a página.');
        if ($_SESSION['admin_perfil'] === 'supervisor') throw new RuntimeException('Supervisor só acompanha.');
        $vendaId = criarVenda((int)$_POST['oportunidade_id'], (int)$_SESSION['admin_id']);
        // 30/10/2026, "no estoque adicionar quem comprou e abrir negociação"
        $compNome = trim((string)($_POST['comprador_nome'] ?? ''));
        $compTel = normalizarTelefone((string)($_POST['comprador_telefone'] ?? ''));
        if ($compNome !== '' || $compTel) {
            getDB()->prepare("UPDATE vendas SET comprador_nome = CASE WHEN ? != '' THEN ? ELSE comprador_nome END,
                comprador_telefone = CASE WHEN ? != '' THEN ? ELSE comprador_telefone END WHERE id = ?")
                ->execute([clean($compNome), clean($compNome), (string)$compTel, (string)$compTel, $vendaId]);
        }
        header('Location: /admin/venda.php?id=' . $vendaId);
        exit;
    } catch (Throwable $e) {
        $erro = $e->getMessage();
    }
}

// 24/10/2026, "crie link pra mandar o catálogo inteiro do estoque" —
// mesmo fluxo do envio de 1 carro, só que manda o link do catálogo.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'enviar_catalogo_cliente') {
    try {
        if (!validateCSRF($_POST['csrf_token'] ?? '')) throw new RuntimeException('Sessão expirada, recarregue a página.');
        if ($_SESSION['admin_perfil'] === 'supervisor') throw new RuntimeException('Supervisor só acompanha.');
        $tel = normalizarTelefone((string)($_POST['telefone'] ?? ''));
        $nomeCli = trim((string)($_POST['nome'] ?? ''));
        if (strlen($tel) < 12) throw new RuntimeException('Telefone inválido — use DDD + número.');
        $meuId = (int)$_SESSION['admin_id'];
        $texto = ($nomeCli !== '' ? "Oi, {$nomeCli}! " : 'Oi! ') . "Separei pra você o nosso catálogo com todos os veículos disponíveis na Fastcar 👇\n" . catalogoLinkPublicoUrl($meuId);
        $res = enviarMensagemManualVendas($tel, $texto, $meuId);
        if (!$res['ok']) throw new RuntimeException($res['erro']);
        $ph = implode(',', array_fill(0, count(ETAPAS_VENDA_ATIVAS), '?'));
        $stmtA = $db->prepare("SELECT id FROM vendas WHERE comprador_telefone = ? AND etapa IN ({$ph}) LIMIT 1");
        $stmtA->execute(array_merge([$tel], ETAPAS_VENDA_ATIVAS));
        if (!$stmtA->fetchColumn()) {
            $db->prepare("INSERT INTO vendas (etapa, origem, comprador_nome, comprador_telefone, responsavel_id) VALUES ('negociacao', 'whatsapp', ?, ?, ?)")
               ->execute([clean($nomeCli), $tel, $meuId]);
            $novaId = (int)$db->lastInsertId();
            $db->prepare("INSERT INTO venda_historico (venda_id, etapa_anterior, etapa_nova, observacao, responsavel_id) VALUES (?, '', 'negociacao', ?, ?)")
               ->execute([$novaId, 'Vendedor enviou o catálogo completo do Estoque', $meuId]);
        }
        $sucesso = "Catálogo enviado pelo WhatsApp de vendas pra {$tel}.";
    } catch (Throwable $e) {
        $erro = $e->getMessage();
    }
}

// 24/10/2026, "ocultar todos carros no estoque que vieram migrados do
// ZapSign — já foram vendidos". Só super_admin; nunca apaga nada, reversível.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['acao'] ?? '', ['ocultar_migrados', 'ocultar_um', 'mostrar_um', 'mesclar_duplicados'], true)) {
    try {
        if (!validateCSRF($_POST['csrf_token'] ?? '')) throw new RuntimeException('Sessão expirada, recarregue a página.');
        if ($_SESSION['admin_perfil'] !== 'super_admin') throw new RuntimeException('Só o super admin oculta carros do estoque.');
        $acaoOc = $_POST['acao'];
        if ($acaoOc === 'mesclar_duplicados') {
            $m = mesclarVeiculosDuplicadosPorPlaca(true);
            $sucesso = count($m) . ' placa(s) duplicada(s) mesclada(s) — vistorias e fotos foram pro cadastro principal.';
        } elseif ($acaoOc === 'ocultar_migrados') {
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
$avaliadoresEstoque = array_values(array_filter(listarUsuarios(true), fn($u) => $u['perfil'] === 'avaliador'));
$vistoriaPorOp = [];
$verOcultos = $souSuperEstoque && ($_GET['ocultos'] ?? '') === '1';
$migradosNoEstoque = $souSuperEstoque ? idsVeiculosMigradosZapsignNoEstoque() : [];
$duplicadosPlaca = $souSuperEstoque ? mesclarVeiculosDuplicadosPorPlaca(false) : [];
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
    // Vistoria de recebimento mais recente de cada carro (pelo cadastro ou pela mesma placa).
    $vistoriaPorOp = [];
    $stmtV = $db->prepare("SELECT o.id AS op_id, va.id, va.status FROM oportunidades o
        JOIN oportunidades o2 ON (o2.id = o.id OR (LENGTH(UPPER(REPLACE(REPLACE(COALESCE(o.veiculo_placa,''),'-',''),' ',''))) >= 6
             AND UPPER(REPLACE(REPLACE(COALESCE(o2.veiculo_placa,''),'-',''),' ','')) = UPPER(REPLACE(REPLACE(COALESCE(o.veiculo_placa,''),'-',''),' ',''))))
        JOIN veiculo_avaliacoes va ON va.oportunidade_id = o2.id AND va.tipo = 'compra'
        WHERE o.id IN ({$ph}) ORDER BY va.id");
    $stmtV->execute($ids);
    foreach ($stmtV->fetchAll() as $r) $vistoriaPorOp[(int)$r['op_id']] = ['id' => (int)$r['id'], 'status' => $r['status']];
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
<?php $linkCatalogo = catalogoLinkPublicoUrl((int)$_SESSION['admin_id']); ?>
<div class="card">
    <h3 style="margin-top:0">📚 Catálogo completo pro cliente</h3>
    <p><small>Um link só com todos os veículos disponíveis (atualiza sozinho — carro vendido some). O botão de WhatsApp de cada carro vem pra você.</small></p>
    <?php
        $stmtMeuZap = $db->prepare("SELECT whatsapp FROM usuarios WHERE id = ?");
        $stmtMeuZap->execute([(int)$_SESSION['admin_id']]);
        $meuZap = trim((string)$stmtMeuZap->fetchColumn());
    ?>
    <?php if ($meuZap === ''): ?>
        <div class="alerta-erro" style="margin:8px 0">⚠️ Seu WhatsApp não está cadastrado — no catálogo e no link dos carros o botão "Falar com você" não aparece pro cliente. Cadastre em <a href="/admin/meu_perfil.php">🙋 Meu perfil</a>.</div>
    <?php else: ?>
        <p style="margin:6px 0"><small>📱 O cliente que clicar em "Falar com..." cai no <strong>seu</strong> WhatsApp: <strong><?= e($meuZap) ?></strong> (não no número da instância). Pra trocar, use <a href="/admin/meu_perfil.php">🙋 Meu perfil</a>.</small></p>
    <?php endif; ?>
    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-start">
        <button type="button" class="btn-primary" style="width:auto;margin:0" onclick="copiarTexto(<?= e(json_encode($linkCatalogo)) ?>, this)">🔗 Copiar link do catálogo</button>
        <a class="btn" href="<?= e($linkCatalogo) ?>" target="_blank" rel="noopener">👀 Ver como o cliente vê</a>
        <?php if ($_SESSION['admin_perfil'] !== 'supervisor'): ?>
        <details style="flex:1;min-width:240px">
            <summary class="btn" style="list-style:none;cursor:pointer;text-align:center">💬 Enviar catálogo pelo WhatsApp de vendas</summary>
            <form method="post" style="margin-top:8px">
                <?= csrfField() ?>
                <input type="hidden" name="acao" value="enviar_catalogo_cliente">
                <input type="tel" name="telefone" required placeholder="Telefone com DDD">
                <input type="text" name="nome" placeholder="Nome do cliente (opcional)">
                <button type="submit" style="width:100%">Enviar catálogo</button>
            </form>
        </details>
        <?php endif; ?>
    </div>
</div>
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
    <?php if ($duplicadosPlaca): ?>
        <form method="post" onsubmit="return confirmarAcao(this, 'Mesclar <?= count($duplicadosPlaca) ?> placa(s) cadastrada(s) mais de uma vez? Vistorias e fotos vão pro cadastro principal e os repetidos saem do estoque (nada é apagado).');" style="margin:0">
            <?= csrfField() ?>
            <input type="hidden" name="acao" value="mesclar_duplicados">
            <button type="submit" style="margin:0;width:auto">🔀 Mesclar <?= count($duplicadosPlaca) ?> placa(s) duplicada(s): <?= e(implode(', ', array_column($duplicadosPlaca, 'placa'))) ?></button>
        </form>
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
        <<?= $souSuperEstoque ? 'a' : 'div' ?> class="estoque-foto"<?= $souSuperEstoque ? ' href="/admin/veiculo_historico.php?id=' . $id . '" title="Ver histórico do veículo"' : '' ?>>
            <?php if (isset($fotoCapa[$id])): ?>
                <img src="<?= e($fotoCapa[$id]) ?>" alt="" loading="lazy">
            <?php else: ?>
                <?= $iconeTipo[$v['tipo_veiculo'] ?? ''] ?? '🚗' ?>
            <?php endif; ?>
        </<?= $souSuperEstoque ? 'a' : 'div' ?>>
        <div class="estoque-info">
            <?php $tituloCarro = e(trim(($v['veiculo_marca'] ?? '') . ' ' . ($v['veiculo_modelo'] ?? ''))) ?: '(sem modelo)'; ?>
            <h3><?php if ($souSuperEstoque): ?><a class="estoque-link-hist" href="/admin/veiculo_historico.php?id=<?= $id ?>" title="Ver histórico do veículo"><?= $tituloCarro ?> <span style="font-size:14px">⚙️</span></a><?php else: ?><?= $tituloCarro ?><?php endif; ?></h3>
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
            <?php if ($_SESSION['admin_perfil'] !== 'supervisor'): ?>
            <button type="button" class="btn-sucesso-venda" onclick="abrirModalVender(<?= $id ?>, <?= e(json_encode($tituloCard)) ?>)">💰 Vender este veículo</button>
            <?php endif; ?>
            <button type="button" class="btn-primary" style="width:100%;margin-top:8px" onclick="copiarTexto(<?= e(json_encode($linkCliente)) ?>, this)">🔗 Copiar link pro cliente</button>
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
            <?php $vist = $vistoriaPorOp[$id] ?? null; ?>
            <?php if ($vist): ?>
                <a class="btn" style="margin-top:8px" href="/admin/avaliacao.php?id=<?= $vist['id'] ?>">🔍 Vistoria <?= $vist['status'] === 'concluida' ? '✅ concluída' : '⏳ em andamento' ?></a>
            <?php elseif ($_SESSION['admin_perfil'] !== 'supervisor'): ?>
                <?php if ($_SESSION['admin_perfil'] === 'avaliador'): ?>
                <form method="post" style="margin-top:8px">
                    <?= csrfField() ?><input type="hidden" name="acao" value="fazer_vistoria"><input type="hidden" name="oportunidade_id" value="<?= $id ?>">
                    <button type="submit" class="btn-vistoria">🔍 Fazer vistoria</button>
                </form>
                <?php elseif ($avaliadoresEstoque): ?>
                <details style="margin-top:8px">
                    <summary class="btn btn-vistoria" style="list-style:none;cursor:pointer;text-align:center">🔍 Fazer vistoria</summary>
                    <form method="post" style="margin-top:8px">
                        <?= csrfField() ?><input type="hidden" name="acao" value="fazer_vistoria"><input type="hidden" name="oportunidade_id" value="<?= $id ?>">
                        <select name="avaliador_id" required>
                            <option value="">— escolha o avaliador —</option>
                            <?php foreach ($avaliadoresEstoque as $av): ?><option value="<?= (int)$av['id'] ?>"><?= e($av['nome']) ?></option><?php endforeach; ?>
                        </select>
                        <button type="submit" style="width:100%">Criar vistoria</button>
                    </form>
                </details>
                <?php else: ?>
                <small style="display:block;margin-top:8px;color:var(--texto-fraco)">🔍 Sem vistoria — nenhum avaliador cadastrado.</small>
                <?php endif; ?>
            <?php endif; ?>
            <?php if ($souSuperEstoque): ?><a class="btn" style="margin-top:8px" href="/admin/veiculo_historico.php?id=<?= $id ?>">⚙️ Histórico do veículo</a><?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>
<dialog id="modal-vender" class="modal-lancamento">
    <div style="display:flex;justify-content:space-between;align-items:center">
        <h3 style="margin:0">💰 Vender veículo</h3>
        <button type="button" onclick="document.getElementById('modal-vender').close()" style="background:none;border:none;font-size:1.6rem;font-weight:700;cursor:pointer;color:var(--texto-suave)" aria-label="Fechar">&times;</button>
    </div>
    <p id="modal-vender-titulo" style="font-weight:600;margin:8px 0 14px"></p>
    <p style="margin:0 0 6px"><strong>1. Pra quem a Fastcar está vendendo este carro?</strong> <small>(o cliente novo — opcional, dá pra preencher depois)</small></p>
    <div class="grid-2" style="margin-bottom:4px">
        <div><label>Nome do cliente que está comprando</label><input type="text" id="mv-comprador-nome" placeholder="Ex: João da Silva"></div>
        <div><label>Celular (WhatsApp) desse cliente, com DDD</label><input type="tel" inputmode="tel" id="mv-comprador-tel" placeholder="Ex: 11 99999-8888"></div>
    </div>
    <p style="margin:0 0 12px"><small>É o número do <strong>cliente</strong> (não o seu nem o da Fastcar) — é pra onde vai o link dos documentos e o contrato pra assinar.</small></p>
    <p style="margin:0 0 10px"><strong>2. Escolha o tipo de contrato</strong> <small>— abre a negociação já com esse cliente:</small></p>
    <div class="tipo-venda-opcoes">
        <a id="modal-vender-promissoria" class="tipo-venda-opcao" href="#">
            <span class="tv-icone">💳</span>
            <strong>Promissória</strong>
            <small>Entrada (PIX em partes, bem de troca) + parcelas da Fastcar, sem consulta SPC/Serasa. Abre o formulário completo já com este carro.</small>
        </a>
        <a id="modal-vender-locacao" class="tipo-venda-opcao" href="#">
            <span class="tv-icone">🔑</span>
            <strong>Locação</strong>
            <small>Aluguel semanal ou mensal (motorista de aplicativo). Abre o formulário de locação já com este carro.</small>
        </a>
        <form method="post" style="margin:0" onsubmit="mvCopiarComprador(this); return confirmarAcao(this, 'Abrir uma negociação de venda com quitação futura para este veículo?');">
            <?= csrfField() ?>
            <input type="hidden" name="acao" value="iniciar_venda_quitacao">
            <input type="hidden" name="oportunidade_id" id="modal-vender-op" value="">
            <input type="hidden" name="comprador_nome" value="">
            <input type="hidden" name="comprador_telefone" value="">
            <button type="submit" class="tipo-venda-opcao" style="width:100%;margin:0;text-align:left">
                <span class="tv-icone">📄</span>
                <strong>Quitação futura</strong>
                <small>Contrato de venda com quitação futura do financiamento. Abre a negociação pra preencher comprador e condições.</small>
            </button>
        </form>
    </div>
</dialog>
<style>
a.estoque-foto { display:flex; text-decoration:none; color:inherit; cursor:pointer; }
a.estoque-foto:hover img { opacity:.9; }
.estoque-link-hist { color:inherit; text-decoration:none; }
.estoque-link-hist:hover { color:var(--azul); text-decoration:underline; }
.btn-vistoria { display:block; width:100%; text-align:center; background:var(--laranja-bg); color:var(--laranja); border:1px solid var(--laranja); font-weight:600; }
.btn-sucesso-venda { width:100%; margin:0; background:#16a34a; color:#fff; border:none; border-radius:var(--raio); min-height:44px; font-weight:700; cursor:pointer; }
.tipo-venda-opcoes { display:grid; gap:12px; }
.tipo-venda-opcao { display:flex; flex-direction:column; gap:4px; padding:14px 16px; border:2px solid var(--borda); border-radius:var(--raio); background:#fff; color:inherit; text-decoration:none; cursor:pointer; box-shadow:none; }
.tipo-venda-opcao:hover { border-color:var(--azul); background:#f5f8ff; }
.tipo-venda-opcao strong { font-size:17px; color:var(--navy); }
.tipo-venda-opcao small { color:#555; font-weight:400; }
.tv-icone { font-size:26px; }
</style>
<script>
var mvOpAtual = 0;
function abrirModalVender(opId, titulo) {
    mvOpAtual = opId;
    document.getElementById('modal-vender-titulo').textContent = titulo;
    document.getElementById('modal-vender-op').value = opId;
    document.getElementById('modal-vender').showModal();
}
function mvQsComprador() {
    var n = document.getElementById('mv-comprador-nome').value.trim();
    var t = document.getElementById('mv-comprador-tel').value.trim();
    return (n ? '&comprador_nome=' + encodeURIComponent(n) : '') + (t ? '&comprador_telefone=' + encodeURIComponent(t) : '');
}
function mvCopiarComprador(form) {
    form.querySelector('[name=comprador_nome]').value = document.getElementById('mv-comprador-nome').value.trim();
    form.querySelector('[name=comprador_telefone]').value = document.getElementById('mv-comprador-tel').value.trim();
}
document.getElementById('modal-vender-promissoria').addEventListener('click', function (e) {
    e.preventDefault(); window.location.href = '/admin/vendas.php?vender_op=' + mvOpAtual + mvQsComprador();
});
document.getElementById('modal-vender-locacao').addEventListener('click', function (e) {
    e.preventDefault(); window.location.href = '/admin/vendas.php?locar_op=' + mvOpAtual + mvQsComprador();
});
</script>
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
<?php include __DIR__ . '/_historico_modal.php'; ?>
<?php include __DIR__ . '/_zapi_status_vendas.php'; ?>
</body>
</html>
