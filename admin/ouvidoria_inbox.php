<?php
/**
 * WhatsApp Box da OUVIDORIA (19/11/2026) — só super admin, só mensagens do
 * canal 'ouvidoria'. A IA responde sozinha por assunto (pagamento, multa,
 * contrato, distrato) — ver chatbot-whatsapp/includes/mensagens_ouvidoria.php.
 * Responder por aqui pausa a IA daquela conversa.
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../includes/wpp_preview.php';
require_once __DIR__ . '/../includes/ouvidoria_inbox.php';
requireSuperAdmin();
whatsappCanalRegistro('ouvidoria');

function _ouvMsgsComMidia(array $msgs): array {
    foreach ($msgs as &$m) $m['midia_html'] = renderizarMidiaWhatsapp($m);
    return $msgs;
}

$telefoneGet = trim((string)($_GET['telefone'] ?? ''));
$telefoneAtivo = $telefoneGet !== '' ? normalizarTelefone($telefoneGet) : '';

if (isset($_GET['ajax'])) liberarSessao(); // polling só lê sessão, nunca escreve

if (isset($_GET['ajax']) && $_GET['ajax'] === 'novas') {
    header('Content-Type: application/json');
    $depoisDe = (int)($_GET['after_id'] ?? 0);
    if (!$telefoneAtivo) {
        echo json_encode(['mensagens' => []]);
        exit;
    }
    $novas = buscarMensagensNovasConversa($telefoneAtivo, $depoisDe, 'ouvidoria');
    if ($novas) marcarConversaLida($telefoneAtivo, 'ouvidoria');
    echo json_encode(['mensagens' => _ouvMsgsComMidia($novas)]);
    exit;
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'anteriores') {
    header('Content-Type: application/json');
    $antesDe = (int)($_GET['before_id'] ?? 0);
    if (!$telefoneAtivo || !$antesDe) {
        echo json_encode(['mensagens' => []]);
        exit;
    }
    echo json_encode(['mensagens' => _ouvMsgsComMidia(buscarMensagensAntesId($telefoneAtivo, $antesDe, 50, 'ouvidoria'))]);
    exit;
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'buscar_negocio') {
    header('Content-Type: application/json');
    echo json_encode(['itens' => ouvidoriaBuscarNegocios((string)($_GET['q'] ?? ''))]);
    exit;
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'conversas') {
    header('Content-Type: application/json');
    echo json_encode(['conversas' => listarConversasOuvidoria(trim((string)($_GET['busca'] ?? '')), (string)($_GET['assunto'] ?? ''))]);
    exit;
}

$erro = '';
$sucesso = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $viaAjax = ($_POST['ajax'] ?? '') === '1';
    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        $erro = 'Sessão expirada, recarregue a página e tente de novo.';
    } else {
        $acao = (string)($_POST['acao'] ?? '');
        $telPost = preg_replace('/\D/', '', (string)($_POST['telefone'] ?? ''));
        if ($acao === 'vincular_negocio' && $telPost) {
            $tipoV = (string)($_POST['tipo'] ?? '');
            $idV = (int)($_POST['negocio_id'] ?? 0);
            if (in_array($tipoV, ['venda', 'compra'], true) && $idV > 0 && ouvidoriaResumoNegocio($tipoV, $idV)) {
                ouvidoriaSalvarConversa($telPost, $tipoV === 'venda' ? ['venda_id' => $idV, 'oportunidade_id' => null] : ['oportunidade_id' => $idV, 'venda_id' => null]);
                auditoriaRegistrar('ouvidoria_vinculada', (int)$_SESSION['admin_id'], (string)$_SESSION['admin_nome'], $tipoV === 'venda' ? 'venda' : 'oportunidade', $idV, "Telefone {$telPost} da ouvidoria vinculado.");
                $sucesso = 'Conversa vinculada ao ' . ($tipoV === 'venda' ? 'contrato de venda' : 'negócio de compra') . " #{$idV}.";
            } else {
                $erro = 'Negócio não encontrado.';
            }
            $telefoneAtivo = $telPost;
        } elseif ($acao === 'desvincular_negocio' && $telPost) {
            ouvidoriaSalvarConversa($telPost, ['venda_id' => null, 'oportunidade_id' => null]);
            $sucesso = 'Vínculo removido.';
            $telefoneAtivo = $telPost;
        } elseif ($acao === 'ia_ouvidoria' && $telPost) {
            $pausar = ($_POST['pausar'] ?? '') === '1';
            ouvidoriaSalvarConversa($telPost, ['ia_pausada' => $pausar ? 1 : 0]);
            $sucesso = $pausar ? 'IA pausada nesta conversa — só a equipe responde.' : 'IA reativada nesta conversa.';
            $telefoneAtivo = $telPost;
        } elseif ($acao === 'assunto_ouvidoria' && $telPost) {
            $novo = (string)($_POST['assunto'] ?? '');
            if (isset(OUVIDORIA_ASSUNTOS[$novo])) {
                ouvidoriaSalvarConversa($telPost, ['assunto' => $novo]);
                $sucesso = 'Assunto alterado para ' . OUVIDORIA_ASSUNTOS[$novo] . '.';
            }
            $telefoneAtivo = $telPost;
        } elseif ($acao === 'enviar_mensagem' && $telPost) {
            $resultado = enviarMensagemManualOuvidoria($telPost, (string)($_POST['texto'] ?? ''), (int)$_SESSION['admin_id']);
            if ($viaAjax) {
                header('Content-Type: application/json');
                echo json_encode($resultado);
                exit;
            }
            if (!$resultado['ok']) $erro = $resultado['erro'];
            $telefoneAtivo = $telPost;
        }
    }
}

if ($telefoneAtivo) {
    marcarConversaLida($telefoneAtivo, 'ouvidoria');
}

$busca = trim((string)($_GET['busca'] ?? ''));
$assuntoFiltro = (string)($_GET['assunto'] ?? '');
if (!isset(OUVIDORIA_ASSUNTOS[$assuntoFiltro])) $assuntoFiltro = '';
$conversas = listarConversasOuvidoria($busca, $assuntoFiltro);
$mensagens = $telefoneAtivo ? buscarMensagensConversa($telefoneAtivo, 50, 'ouvidoria') : [];
$ultimoId = $mensagens ? (int)end($mensagens)['id'] : 0;
$primeiroId = $mensagens ? (int)$mensagens[0]['id'] : 0;
$contatoAtivo = null;
foreach ($conversas as $c) {
    if ($c['telefone'] === $telefoneAtivo) { $contatoAtivo = $c; break; }
}
if ($telefoneAtivo && !$contatoAtivo && $mensagens) {
    $conv = ouvidoriaConversa($telefoneAtivo);
    $contatoAtivo = ['telefone' => $telefoneAtivo, 'nome' => $conv['nome'], 'assunto' => $conv['assunto'], 'ia_pausada' => $conv['ia_pausada']];
}
$negocios = $contatoAtivo ? ouvidoriaNegociosDoTelefone($telefoneAtivo) : ['vendas' => [], 'oportunidades' => []];
// Negócio vinculado (escolhido pela equipe) ou, sem vínculo, sugestão pelo telefone.
$vinculo = null;
$vinculoSugerido = false;
if ($contatoAtivo) {
    $convAtiva = ouvidoriaConversa($telefoneAtivo);
    if (!empty($convAtiva['venda_id'])) $vinculo = ouvidoriaResumoNegocio('venda', (int)$convAtiva['venda_id']);
    elseif (!empty($convAtiva['oportunidade_id'])) $vinculo = ouvidoriaResumoNegocio('compra', (int)$convAtiva['oportunidade_id']);
    if (!$vinculo && $negocios['vendas']) { $vinculo = ouvidoriaResumoNegocio('venda', (int)$negocios['vendas'][0]['id']); $vinculoSugerido = (bool)$vinculo; }
    elseif (!$vinculo && $negocios['oportunidades']) { $vinculo = ouvidoriaResumoNegocio('compra', (int)$negocios['oportunidades'][0]['id']); $vinculoSugerido = (bool)$vinculo; }
}
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Ouvidoria — Fastcar CRM</title>
<link rel="stylesheet" href="/admin/assets/style.css?v=<?= @filemtime(__DIR__ . '/assets/style.css') ?: 1 ?>">
<link rel="stylesheet" href="/admin/assets/mobile.css?v=<?= @filemtime(__DIR__ . '/assets/mobile.css') ?: 1 ?>">
<script src="/admin/assets/mobile.js?v=<?= @filemtime(__DIR__ . '/assets/mobile.js') ?: 1 ?>" defer></script>
<?php include __DIR__ . '/_pwa_head.php'; ?>
<style>
.wpp-wrap { display: flex; height: calc(100vh - 61px); }
.wpp-sidebar { width: 320px; flex-shrink: 0; border-right: 1px solid var(--borda); background: var(--superficie); display: flex; flex-direction: column; }
.wpp-sidebar form { padding: 12px; border-bottom: 1px solid var(--borda); }
.wpp-sidebar form input { margin: 0; }
.wpp-lista { flex: 1; overflow-y: auto; }
.wpp-item { display: flex; gap: 10px; align-items: center; padding: 12px 16px; border-bottom: 1px solid var(--borda); text-decoration: none; color: inherit; position: relative; }
.wpp-item:hover { background: var(--fundo); text-decoration: none; }
.wpp-item.ativo { background: var(--azul-claro); }
.wpp-item .wpp-corpo { min-width: 0; flex: 1; }
.wpp-avatar-placeholder { width: 38px; height: 38px; border-radius: 50%; flex-shrink: 0; background: var(--azul); color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 15px; }
.wpp-chat-header .wpp-avatar-placeholder { width: 40px; height: 40px; margin-right: 10px; }
.wpp-chat-header > div:first-child { display: flex; align-items: center; }
.wpp-item .nome { font-weight: 600; font-size: 13.5px; display: flex; justify-content: space-between; gap: 8px; }
.wpp-item .preview { font-size: 12.5px; color: var(--texto-fraco); margin-top: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.wpp-item .quando { font-size: 11px; color: var(--texto-fraco); white-space: nowrap; }
.wpp-badge { background: var(--azul); color: #fff; border-radius: 999px; font-size: 11px; font-weight: 700; padding: 1px 7px; min-width: 18px; text-align: center; display: inline-block; }
.wpp-chat { flex: 1; display: flex; flex-direction: column; min-width: 0; }
.wpp-chat-header { padding: 14px 20px; border-bottom: 1px solid var(--borda); display: flex; justify-content: space-between; align-items: center; background: var(--superficie); }
.wpp-thread { flex: 1; overflow-y: auto; padding: 20px; display: flex; flex-direction: column; background: var(--fundo); }
.wpp-thread .msg small { display: block; margin-top: 3px; opacity: .65; font-size: 10.5px; }
.wpp-form { padding: 14px 20px; border-top: 1px solid var(--borda); background: var(--superficie); display: flex; gap: 10px; }
.wpp-form textarea { flex: 1; margin: 0; resize: none; min-height: 42px; max-height: 120px; }
.wpp-form button { margin: 0; white-space: nowrap; }
.wpp-vazio { flex: 1; display: flex; align-items: center; justify-content: center; color: var(--texto-fraco); text-align: center; padding: 40px; }
.wpp-voltar-mobile { display: none; }
.ouv-assunto { display:inline-block; font-size:11px; font-weight:600; padding:1px 8px; border-radius:999px; background:var(--azul-claro); color:var(--azul-escuro); }
.ouv-chips { display:flex; gap:6px; flex-wrap:wrap; padding:8px 12px; border-bottom:1px solid var(--borda); }
.ouv-chips a { font-size:12px; padding:4px 10px; border-radius:999px; border:1px solid var(--borda); color:inherit; text-decoration:none; }
.ouv-chips a.ativo { background:var(--azul); color:#fff; border-color:var(--azul); }
.ouv-vinculo { display:flex; flex-wrap:wrap; gap:6px 12px; align-items:center; padding:8px 20px; border-bottom:1px solid var(--borda); background:var(--fundo); font-size:12.5px; }
.ouv-vinculo-info { flex:1; min-width:220px; overflow-wrap:anywhere; }
#ouv-busca-box input { margin:6px 0; }
.ouv-res-item { display:flex; justify-content:space-between; gap:8px; align-items:center; padding:6px 8px; border:1px solid var(--borda); border-radius:8px; margin-bottom:4px; background:var(--superficie); }
.ouv-res-item form { margin:0; }
.ouv-res-item button { margin:0; width:auto; padding:6px 12px; font-size:12.5px; }
.ouv-acoes { display:flex; gap:8px; flex-wrap:wrap; align-items:center; font-size:12.5px; }
.ouv-acoes form { margin:0; display:flex; gap:6px; align-items:center; }
.ouv-acoes select, .ouv-acoes button { margin:0; width:auto; padding:6px 10px; font-size:12.5px; }
@media (max-width: 700px) {
    .wpp-sidebar { width: 100%; }
    body.wpp-tem-conversa .wpp-sidebar { display: none; }
    body:not(.wpp-tem-conversa) .wpp-chat { display: none; }
    .wpp-voltar-mobile { display: inline-block; margin-right: 10px; }
}
</style>
<script>
<?= wppPreviewJs() ?>
</script>
</head>
<body class="<?= ($telefoneAtivo && $contatoAtivo) ? 'wpp-tem-conversa' : '' ?>">
<?php include __DIR__ . '/_impersonando_banner.php'; ?>
<?php include __DIR__ . '/_topbar.php'; ?>

<?php if ($erro): ?><div class="alerta-erro" style="margin:12px 20px"><?= e($erro) ?></div><?php endif; ?>
<?php if ($sucesso): ?><div class="alerta-sucesso" style="margin:12px 20px"><?= e($sucesso) ?></div><?php endif; ?>

<div class="wpp-wrap">
    <aside class="wpp-sidebar">
        <form method="get">
            <?php if ($assuntoFiltro): ?><input type="hidden" name="assunto" value="<?= e($assuntoFiltro) ?>"><?php endif; ?>
            <input type="text" name="busca" value="<?= e($busca) ?>" placeholder="Buscar por nome ou telefone...">
        </form>
        <nav class="ouv-chips">
            <a href="?<?= $busca ? 'busca=' . urlencode($busca) : '' ?>" class="<?= $assuntoFiltro === '' ? 'ativo' : '' ?>">Todos</a>
            <?php foreach (OUVIDORIA_ASSUNTOS as $k => $rot): ?>
                <a href="?assunto=<?= e($k) ?><?= $busca ? '&busca=' . urlencode($busca) : '' ?>" class="<?= $assuntoFiltro === $k ? 'ativo' : '' ?>"><?= e($rot) ?></a>
            <?php endforeach; ?>
        </nav>
        <div class="wpp-lista" id="wpp-lista">
            <?php if (!$conversas): ?>
                <p style="padding:16px;color:var(--texto-fraco);font-size:13px">
                    <?= ($busca || $assuntoFiltro) ? 'Nenhuma conversa encontrada.' : 'Nenhuma conversa ainda — aparecem aqui assim que um cliente escrever pro número da ouvidoria.' ?>
                </p>
            <?php endif; ?>
            <?php foreach ($conversas as $c): ?>
                <a class="wpp-item <?= $c['telefone'] === $telefoneAtivo ? 'ativo' : '' ?>" href="?telefone=<?= e($c['telefone']) ?>">
                    <div class="wpp-avatar-placeholder"><?= e(mb_strtoupper(mb_substr($c['nome'] ?: $c['telefone'], 0, 1))) ?></div>
                    <div class="wpp-corpo">
                        <div class="nome">
                            <span><?= e($c['nome'] ?: $c['telefone']) ?></span>
                            <?php if ((int)$c['nao_lidas'] > 0): ?><span class="wpp-badge"><?= (int)$c['nao_lidas'] ?></span><?php endif; ?>
                        </div>
                        <div class="preview">
                            <?= $c['ultima_direcao'] === 'out' ? '✓ ' : '' ?>
                            <?= wppPreviewHtml((string)$c['ultima_tipo'], (string)$c['ultima_mensagem']) ?>
                        </div>
                        <div class="quando">
                            <?= date('d/m H:i', strtotime($c['ultima_em'])) ?>
                            <?php if ($c['assunto'] !== ''): ?> · <span class="ouv-assunto"><?= e(OUVIDORIA_ASSUNTOS[$c['assunto']] ?? $c['assunto']) ?></span><?php endif; ?>
                        </div>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    </aside>

    <section class="wpp-chat">
        <?php if (!$telefoneAtivo || !$contatoAtivo): ?>
            <div class="wpp-vazio">
                👈 Selecione uma conversa pra ver o histórico e responder.<br>
                <small>A ouvidoria só responde quem escreve — não inicia conversa.</small>
            </div>
        <?php else: ?>
            <div class="wpp-chat-header">
                <div>
                    <div class="wpp-avatar-placeholder"><?= e(mb_strtoupper(mb_substr($contatoAtivo['nome'] ?: $telefoneAtivo, 0, 1))) ?></div>
                    <div>
                        <a href="?" class="wpp-voltar-mobile" style="color:inherit">← Conversas</a>
                        <strong><?= e($contatoAtivo['nome'] ?: $telefoneAtivo) ?></strong>
                        <div style="font-size:12px;color:var(--texto-fraco)">
                            <?= e($telefoneAtivo) ?>
                        </div>
                    </div>
                </div>
                <div class="ouv-acoes">
                    <form method="post">
                        <?= csrfField() ?>
                        <input type="hidden" name="acao" value="assunto_ouvidoria">
                        <input type="hidden" name="telefone" value="<?= e($telefoneAtivo) ?>">
                        <select name="assunto" onchange="this.form.submit()" aria-label="Assunto">
                            <?php foreach (OUVIDORIA_ASSUNTOS as $k => $rot): ?>
                                <option value="<?= e($k) ?>" <?= ($contatoAtivo['assunto'] ?? '') === $k ? 'selected' : '' ?>><?= e($rot) ?></option>
                            <?php endforeach; ?>
                            <?php if (($contatoAtivo['assunto'] ?? '') === ''): ?><option value="" selected>— assunto —</option><?php endif; ?>
                        </select>
                    </form>
                    <form method="post">
                        <?= csrfField() ?>
                        <input type="hidden" name="acao" value="ia_ouvidoria">
                        <input type="hidden" name="telefone" value="<?= e($telefoneAtivo) ?>">
                        <input type="hidden" name="pausar" value="<?= !empty($contatoAtivo['ia_pausada']) ? '0' : '1' ?>">
                        <button type="submit"><?= !empty($contatoAtivo['ia_pausada']) ? '▶️ Reativar IA' : '⏸️ Pausar IA' ?></button>
                    </form>
                </div>
            </div>

            <div class="ouv-vinculo">
                <?php if ($vinculo): ?>
                    <div class="ouv-vinculo-info">
                        <strong><?= $vinculo['tipo'] === 'venda' ? '🛒 Venda' : '🚗 Compra' ?> #<?= (int)$vinculo['id'] ?></strong>
                        — <?= e($vinculo['nome'] ?: '—') ?>
                        <?= $vinculo['cpf'] ? ' · CPF ' . e($vinculo['cpf']) : '' ?>
                        · <?= e(trim(($vinculo['marca'] ?? '') . ' ' . ($vinculo['modelo'] ?? '') . ' ' . ($vinculo['ano'] ?? '')) ?: 'veículo não informado') ?><?= $vinculo['placa'] ? ' (' . e($vinculo['placa']) . ')' : '' ?>
                        · <?= e($vinculo['etapa_label']) ?>
                        <?php if ($vinculo['tipo'] === 'venda' && !empty($vinculo['fin'])): $f = $vinculo['fin']; ?>
                            · <?= (float)$f['atrasado'] > 0 ? '<span style="color:var(--vermelho);font-weight:700">R$ ' . number_format((float)$f['atrasado'], 2, ',', '.') . ' em atraso</span>' : '✅ sem atraso' ?>
                            <?= $f['proximo_venc'] ? ' · próximo vencimento ' . e(date('d/m/Y', strtotime($f['proximo_venc']))) : '' ?>
                        <?php endif; ?>
                        <a href="<?= e($vinculo['url']) ?>" target="_blank">Abrir →</a>
                        <?php if ($vinculoSugerido): ?><span class="ouv-assunto" style="background:var(--laranja-bg);color:var(--laranja)">achado pelo telefone</span><?php endif; ?>
                    </div>
                    <div class="ouv-acoes">
                        <?php if ($vinculoSugerido): ?>
                        <form method="post">
                            <?= csrfField() ?>
                            <input type="hidden" name="acao" value="vincular_negocio">
                            <input type="hidden" name="telefone" value="<?= e($telefoneAtivo) ?>">
                            <input type="hidden" name="tipo" value="<?= e($vinculo['tipo']) ?>">
                            <input type="hidden" name="negocio_id" value="<?= (int)$vinculo['id'] ?>">
                            <button type="submit">🔗 Confirmar vínculo</button>
                        </form>
                        <?php else: ?>
                        <form method="post" onsubmit="return confirmarAcao(this, 'Remover o vínculo desta conversa com o negócio?');">
                            <?= csrfField() ?>
                            <input type="hidden" name="acao" value="desvincular_negocio">
                            <input type="hidden" name="telefone" value="<?= e($telefoneAtivo) ?>">
                            <button type="submit" class="btn-texto">desvincular</button>
                        </form>
                        <?php endif; ?>
                        <button type="button" class="btn-texto" onclick="document.getElementById('ouv-busca-box').hidden = !document.getElementById('ouv-busca-box').hidden">🔎 trocar</button>
                    </div>
                <?php else: ?>
                    <div class="ouv-vinculo-info">Esse número não está ligado a nenhuma compra ou venda.</div>
                    <div class="ouv-acoes"><button type="button" onclick="document.getElementById('ouv-busca-box').hidden = false">🔗 Vincular ao cliente</button></div>
                <?php endif; ?>
                <div id="ouv-busca-box" hidden style="width:100%">
                    <input type="search" id="ouv-busca-negocio" placeholder="Buscar por nome, CPF, telefone ou placa..." autocomplete="off">
                    <div id="ouv-busca-res"></div>
                </div>
            </div>

            <div class="wpp-thread wpp-thread-scroll" id="wpp-thread">
                <?php if (!$mensagens): ?>
                    <p style="color:var(--texto-fraco);text-align:center;margin:auto">Nenhuma mensagem ainda.</p>
                <?php endif; ?>
                <?php if ($mensagens): ?>
                    <button type="button" id="wpp-carregar-anteriores" style="align-self:center;margin-bottom:12px;padding:6px 14px;font-size:12.5px;background:var(--superficie);color:var(--texto-fraco);border:1px solid var(--borda);box-shadow:none">⬆️ Carregar mensagens anteriores</button>
                <?php endif; ?>
                <?php foreach ($mensagens as $m): ?>
                    <div class="msg <?= $m['direcao'] === 'in' ? 'msg-in' : 'msg-out' ?>" data-id="<?= (int)$m['id'] ?>">
                        <?= nl2br(e($m['mensagem'])) ?>
                        <?= renderizarMidiaWhatsapp($m) ?>
                        <small>
                            <?= date('d/m H:i', strtotime($m['created_at'])) ?>
                            <?= $m['usuario_nome'] ? ' · 👤 ' . e($m['usuario_nome']) : (!empty($m['enviado_por_ia']) ? ' · 🤖 IA' : '') ?>
                        </small>
                    </div>
                <?php endforeach; ?>
            </div>

            <form method="post" class="wpp-form" id="wpp-form">
                <?= csrfField() ?>
                <input type="hidden" name="acao" value="enviar_mensagem">
                <input type="hidden" name="telefone" value="<?= e($telefoneAtivo) ?>">
                <input type="hidden" name="ajax" value="1">
                <textarea name="texto" placeholder="Digite uma mensagem..." required></textarea>
                <button type="submit">Enviar</button>
            </form>
        <?php endif; ?>
    </section>
</div>

<script>
(function () {
    var telefone = <?= json_encode($telefoneAtivo) ?>;
    var ultimoId = <?= (int)$ultimoId ?>;
    var primeiroId = <?= (int)$primeiroId ?>;
    var semMaisAntigas = false;
    var meuNome = <?= json_encode($_SESSION['admin_nome'] ?? '') ?>;
    var thread = document.getElementById('wpp-thread');
    var form = document.getElementById('wpp-form');
    var btnAnteriores = document.getElementById('wpp-carregar-anteriores');
    var csrf = document.querySelector('input[name="csrf_token"]');
    var ASSUNTOS = <?= json_encode(OUVIDORIA_ASSUNTOS) ?>;
    var buscaNeg = document.getElementById('ouv-busca-negocio');
    if (buscaNeg) {
        var tBusca = null;
        buscaNeg.addEventListener('input', function () {
            clearTimeout(tBusca);
            var q = buscaNeg.value.trim();
            var res = document.getElementById('ouv-busca-res');
            if (q.length < 3) { res.innerHTML = ''; return; }
            tBusca = setTimeout(function () {
                fetch('?ajax=buscar_negocio&q=' + encodeURIComponent(q))
                    .then(function (r) { return r.json(); })
                    .then(function (d) {
                        res.innerHTML = '';
                        if (!d.itens || !d.itens.length) { res.innerHTML = '<small>Nada encontrado.</small>'; return; }
                        d.itens.forEach(function (it) {
                            var div = document.createElement('div');
                            div.className = 'ouv-res-item';
                            var veic = [it.marca, it.modelo].filter(Boolean).join(' ') + (it.placa ? ' (' + it.placa + ')' : '');
                            div.innerHTML = '<span>' + (it.tipo === 'venda' ? '🛒 Venda' : '🚗 Compra') + ' #' + it.id + ' — ' + escapeHtml(it.nome || '—') +
                                (veic ? ' · ' + escapeHtml(veic) : '') + ' · ' + escapeHtml(it.etapa_label || '') + '</span>' +
                                '<form method="post"><input type="hidden" name="csrf_token" value="' + escapeHtml(csrf.value) + '">' +
                                '<input type="hidden" name="acao" value="vincular_negocio"><input type="hidden" name="telefone" value="' + escapeHtml(telefone) + '">' +
                                '<input type="hidden" name="tipo" value="' + it.tipo + '"><input type="hidden" name="negocio_id" value="' + it.id + '">' +
                                '<button type="submit">Vincular</button></form>';
                            res.appendChild(div);
                        });
                    }).catch(function () {});
            }, 300);
        });
    }

    function escapeHtml(s) {
        var d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }

    function renderMsg(m) {
        var div = document.createElement('div');
        div.className = 'msg ' + (m.direcao === 'in' ? 'msg-in' : 'msg-out');
        div.dataset.id = m.id;
        var rodape = new Date(m.created_at.replace(' ', 'T')).toLocaleString('pt-BR', {day:'2-digit',month:'2-digit',hour:'2-digit',minute:'2-digit'});
        if (m.usuario_nome) rodape += ' · 👤 ' + m.usuario_nome;
        else if (parseInt(m.enviado_por_ia, 10)) rodape += ' · 🤖 IA';
        div.innerHTML = escapeHtml(m.mensagem).replace(/\n/g, '<br>') + (m.midia_html || '') + '<small>' + escapeHtml(rodape) + '</small>';
        return div;
    }

    function jaRenderizada(id) {
        return !!thread.querySelector('[data-id="' + id + '"]');
    }

    if (btnAnteriores) {
        btnAnteriores.addEventListener('click', function () {
            if (!primeiroId || semMaisAntigas) return;
            btnAnteriores.disabled = true;
            btnAnteriores.textContent = 'Carregando...';
            fetch('?ajax=anteriores&telefone=' + encodeURIComponent(telefone) + '&before_id=' + primeiroId)
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    var msgs = data.mensagens || [];
                    if (!msgs.length) {
                        semMaisAntigas = true;
                        btnAnteriores.textContent = 'Início da conversa';
                        return;
                    }
                    var alturaAntes = thread.scrollHeight;
                    var scrollAntes = thread.scrollTop;
                    var frag = document.createDocumentFragment();
                    msgs.forEach(function (m) {
                        if (!jaRenderizada(m.id)) frag.appendChild(renderMsg(m));
                    });
                    thread.insertBefore(frag, btnAnteriores.nextSibling);
                    primeiroId = msgs[0].id;
                    thread.scrollTop = scrollAntes + (thread.scrollHeight - alturaAntes);
                    btnAnteriores.disabled = false;
                    btnAnteriores.textContent = '⬆️ Carregar mensagens anteriores';
                })
                .catch(function () {
                    btnAnteriores.disabled = false;
                    btnAnteriores.textContent = '⬆️ Carregar mensagens anteriores';
                });
        });
    }

    if (thread) thread.scrollTop = thread.scrollHeight;

    if (telefone) {
        setInterval(function () {
            fetch('?ajax=novas&telefone=' + encodeURIComponent(telefone) + '&after_id=' + ultimoId)
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data.mensagens || !data.mensagens.length) return;
                    data.mensagens.forEach(function (m) {
                        if (!jaRenderizada(m.id)) thread.appendChild(renderMsg(m));
                        ultimoId = m.id;
                    });
                    thread.scrollTop = thread.scrollHeight;
                })
                .catch(function () {});
        }, 2000);
    }

    setInterval(atualizarListaConversas, 5000);

    function atualizarListaConversas() {
        var params = new URLSearchParams(window.location.search);
        fetch('?ajax=conversas&busca=' + encodeURIComponent(params.get('busca') || '') + '&assunto=' + encodeURIComponent(params.get('assunto') || ''))
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var lista = document.getElementById('wpp-lista');
                if (!lista || !data.conversas) return;
                lista.innerHTML = '';
                if (!data.conversas.length) {
                    var vazio = document.createElement('p');
                    vazio.style.cssText = 'padding:16px;color:var(--texto-fraco);font-size:13px';
                    vazio.textContent = 'Nenhuma conversa ainda.';
                    lista.appendChild(vazio);
                    return;
                }
                data.conversas.forEach(function (c) {
                    var a = document.createElement('a');
                    a.className = 'wpp-item' + (c.telefone === telefone ? ' ativo' : '');
                    a.href = '?telefone=' + encodeURIComponent(c.telefone);
                    var quando = new Date(c.ultima_em.replace(' ', 'T')).toLocaleString('pt-BR', {day:'2-digit',month:'2-digit',hour:'2-digit',minute:'2-digit'});
                    var inicial = escapeHtml((c.nome || c.telefone).slice(0, 1).toUpperCase());
                    var atrasadoHtml = c.assunto ? ' · <span class="ouv-assunto">' + escapeHtml(ASSUNTOS[c.assunto] || c.assunto) + '</span>' : '';
                    a.innerHTML = '<div class="wpp-avatar-placeholder">' + inicial + '</div>' +
                        '<div class="wpp-corpo">' +
                        '<div class="nome"><span>' + escapeHtml(c.nome || c.telefone) + '</span>' +
                        (c.nao_lidas > 0 ? '<span class="wpp-badge">' + c.nao_lidas + '</span>' : '') + '</div>' +
                        '<div class="preview">' + (c.ultima_direcao === 'out' ? '✓ ' : '') + wppPreviewHtml(c.ultima_tipo, c.ultima_mensagem) + '</div>' +
                        '<div class="quando">' + quando + atrasadoHtml + '</div>' +
                        '</div>';
                    lista.appendChild(a);
                });
            })
            .catch(function () {});
    }

    if (form) {
        form.addEventListener('submit', function (ev) {
            ev.preventDefault();
            var textarea = form.querySelector('textarea[name="texto"]');
            var texto = textarea.value.trim();
            if (!texto) return;
            var btn = form.querySelector('button');
            btn.disabled = true;
            var body = new URLSearchParams();
            body.set('csrf_token', csrf.value);
            body.set('acao', 'enviar_mensagem');
            body.set('telefone', telefone);
            body.set('ajax', '1');
            body.set('texto', texto);
            fetch('', { method: 'POST', body: body })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    btn.disabled = false;
                    if (data.ok) {
                        textarea.value = '';
                        if (data.id) ultimoId = Math.max(ultimoId, data.id);
                        if (!data.id || !jaRenderizada(data.id)) {
                            var div = document.createElement('div');
                            div.className = 'msg msg-out';
                            if (data.id) div.dataset.id = data.id;
                            var agora = new Date().toLocaleString('pt-BR', {day:'2-digit',month:'2-digit',hour:'2-digit',minute:'2-digit'});
                            var rodapeAgora = agora + (meuNome ? ' · 👤 ' + meuNome : '');
                            div.innerHTML = escapeHtml(texto).replace(/\n/g, '<br>') + '<small>' + escapeHtml(rodapeAgora) + '</small>';
                            thread.appendChild(div);
                            thread.scrollTop = thread.scrollHeight;
                        }
                    } else {
                        alert(data.erro || 'Falha ao enviar.');
                    }
                })
                .catch(function () { btn.disabled = false; alert('Falha ao enviar — confira sua conexão.'); });
        });
        form.querySelector('textarea').addEventListener('keydown', function (ev) {
            if (ev.key === 'Enter' && !ev.shiftKey) {
                ev.preventDefault();
                form.requestSubmit();
            }
        });
    }
})();
</script>

<?php include __DIR__ . '/_pwa_register.php'; ?>
<?php include __DIR__ . '/_notify.php'; ?>
<?php include __DIR__ . '/_scroll_restore.php'; ?>
<?php include __DIR__ . '/_acao_popup.php'; ?>
<?php include __DIR__ . '/_confirm_dialog.php'; ?>

</body>
</html>
