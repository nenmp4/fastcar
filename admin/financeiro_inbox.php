<?php
/**
 * WhatsApp Box do financeiro — caixa de entrada dentro do CRM pra
 * conversar com clientes em atraso/cobrança pela instância Z-API DEDICADA
 * do financeiro. Ver includes/financeiro_inbox.php pro racional completo
 * (resolução de telefone, escopo cortado desta 1ª versão, por que nunca
 * dispara mensagem automática).
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../includes/financeiro_inbox.php';
requireAcessoFinanceiro();

$telefoneGet = trim((string)($_GET['telefone'] ?? ''));
$telefoneAtivo = $telefoneGet !== '' ? normalizarTelefone($telefoneGet) : '';

if (isset($_GET['ajax']) && $_GET['ajax'] === 'novas') {
    header('Content-Type: application/json');
    $depoisDe = (int)($_GET['after_id'] ?? 0);
    if (!$telefoneAtivo || !usuarioPodeVerConversaFinanceiro($telefoneAtivo)) {
        echo json_encode(['mensagens' => []]);
        exit;
    }
    $novas = buscarMensagensNovasConversa($telefoneAtivo, $depoisDe);
    if ($novas) marcarConversaLida($telefoneAtivo);
    echo json_encode(['mensagens' => $novas]);
    exit;
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'anteriores') {
    header('Content-Type: application/json');
    $antesDe = (int)($_GET['before_id'] ?? 0);
    if (!$telefoneAtivo || !$antesDe || !usuarioPodeVerConversaFinanceiro($telefoneAtivo)) {
        echo json_encode(['mensagens' => []]);
        exit;
    }
    echo json_encode(['mensagens' => buscarMensagensAntesId($telefoneAtivo, $antesDe)]);
    exit;
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'conversas') {
    header('Content-Type: application/json');
    echo json_encode(['conversas' => listarConversasFinanceiro(trim((string)($_GET['busca'] ?? '')))]);
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
        if ($telPost && !usuarioPodeVerConversaFinanceiro($telPost)) {
            if ($viaAjax) {
                header('Content-Type: application/json');
                echo json_encode(['ok' => false, 'erro' => 'Esse telefone não está ligado a nenhum lançamento financeiro.']);
                exit;
            }
            $erro = 'Esse telefone não está ligado a nenhum lançamento financeiro.';
            $telPost = '';
        }
        if ($acao === 'enviar_mensagem' && $telPost) {
            $resultado = enviarMensagemManualFinanceiro($telPost, (string)($_POST['texto'] ?? ''), (int)$_SESSION['admin_id']);
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

if ($telefoneAtivo && !usuarioPodeVerConversaFinanceiro($telefoneAtivo)) {
    $erro = 'Esse telefone não está ligado a nenhum lançamento financeiro.';
    $telefoneAtivo = '';
}

if ($telefoneAtivo) {
    marcarConversaLida($telefoneAtivo);
}

$busca = trim((string)($_GET['busca'] ?? ''));
$conversas = listarConversasFinanceiro($busca);
$mensagens = $telefoneAtivo ? buscarMensagensConversa($telefoneAtivo) : [];
$ultimoId = $mensagens ? (int)end($mensagens)['id'] : 0;
$primeiroId = $mensagens ? (int)$mensagens[0]['id'] : 0;
$contatoAtivo = null;
foreach ($conversas as $c) {
    if ($c['telefone'] === $telefoneAtivo) { $contatoAtivo = $c; break; }
}
if ($telefoneAtivo && !$contatoAtivo && usuarioPodeVerConversaFinanceiro($telefoneAtivo)) {
    // Telefone existe em fin_lancamentos mas ainda sem nenhuma mensagem
    // trocada — "+ Iniciar conversa" caiu aqui, mesmo padrão do inbox de
    // compra pra número que ainda não escreveu.
    $info = finInboxContato($telefoneAtivo);
    $contatoAtivo = [
        'telefone' => $telefoneAtivo,
        'nome' => $info['nome'] ?? null,
        'total_atrasado' => $info['total_atrasado'] ?? 0,
    ];
}
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>WhatsApp Financeiro — Fastcar CRM</title>
<link rel="stylesheet" href="/admin/assets/style.css?v=<?= @filemtime(__DIR__ . '/assets/style.css') ?: 1 ?>">
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
.fin-atrasado { color: #991b1b; font-weight: 700; }
@media (max-width: 700px) {
    .wpp-sidebar { width: 100%; }
    body.wpp-tem-conversa .wpp-sidebar { display: none; }
    body:not(.wpp-tem-conversa) .wpp-chat { display: none; }
    .wpp-voltar-mobile { display: inline-block; margin-right: 10px; }
}
</style>
</head>
<body class="<?= ($telefoneAtivo && $contatoAtivo) ? 'wpp-tem-conversa' : '' ?>">
<header class="topbar">
    <a href="/admin/financeiro.php" style="color:#fff">← Financeiro</a>
    <strong><img class="topbar-logo" src="/admin/assets/img/icon-192.png" alt="Fastcar" onerror="this.style.display='none'"> Fast<b>Car</b></strong>
    <span>Olá, <?= e($_SESSION['admin_nome']) ?></span>
    <a href="/admin/logout.php">Sair</a>
</header>

<?php if ($erro): ?><div class="alerta-erro" style="margin:12px 20px"><?= e($erro) ?></div><?php endif; ?>
<?php if ($sucesso): ?><div class="alerta-sucesso" style="margin:12px 20px"><?= e($sucesso) ?></div><?php endif; ?>

<div class="wpp-wrap">
    <aside class="wpp-sidebar">
        <form method="get">
            <input type="text" name="busca" value="<?= e($busca) ?>" placeholder="Buscar por nome ou telefone...">
        </form>
        <div class="wpp-lista" id="wpp-lista">
            <?php if (!$conversas): ?>
                <p style="padding:16px;color:var(--texto-fraco);font-size:13px">
                    <?= $busca ? 'Nenhuma conversa encontrada.' : 'Nenhuma conversa ainda — mensagens aparecem aqui assim que um cliente com lançamento financeiro escrever pra essa instância.' ?>
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
                            <?= $c['ultima_tipo'] !== 'text' ? '📎 ' : '' ?>
                            <?= e(mb_strimwidth($c['ultima_mensagem'], 0, 60, '…')) ?>
                        </div>
                        <div class="quando">
                            <?= date('d/m H:i', strtotime($c['ultima_em'])) ?>
                            <?php if ((float)$c['total_atrasado'] > 0): ?> · <span class="fin-atrasado">R$ <?= number_format((float)$c['total_atrasado'], 2, ',', '.') ?> atrasado</span><?php endif; ?>
                        </div>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    </aside>

    <section class="wpp-chat">
        <?php if (!$telefoneAtivo || !$contatoAtivo): ?>
            <div class="wpp-vazio">
                👈 Selecione uma conversa pra ver o histórico e responder.
                <?php if (!$telefoneAtivo): ?>
                <form method="get" style="margin-top:16px;display:flex;gap:8px;justify-content:center">
                    <input type="text" name="telefone" placeholder="Telefone do cliente (com DDD)" style="margin:0;max-width:220px">
                    <button type="submit" style="margin:0;width:auto">+ Iniciar conversa</button>
                </form>
                <?php endif; ?>
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
                            <?php if ((float)($contatoAtivo['total_atrasado'] ?? 0) > 0): ?>
                                · <span class="fin-atrasado">R$ <?= number_format((float)$contatoAtivo['total_atrasado'], 2, ',', '.') ?> em atraso</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <a href="/admin/financeiro-lancamentos.php?status=atrasado&todos_periodos=1" style="font-size:12.5px">📋 Ver lançamentos</a>
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
                        <small>
                            <?= date('d/m H:i', strtotime($m['created_at'])) ?>
                            <?= $m['usuario_nome'] ? ' · 👤 ' . e($m['usuario_nome']) : '' ?>
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
        div.innerHTML = escapeHtml(m.mensagem).replace(/\n/g, '<br>') + '<small>' + escapeHtml(rodape) + '</small>';
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
        fetch('?ajax=conversas&busca=' + encodeURIComponent(params.get('busca') || ''))
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
                    var preview = c.ultima_mensagem.length > 60 ? c.ultima_mensagem.slice(0, 60) + '…' : c.ultima_mensagem;
                    var quando = new Date(c.ultima_em.replace(' ', 'T')).toLocaleString('pt-BR', {day:'2-digit',month:'2-digit',hour:'2-digit',minute:'2-digit'});
                    var inicial = escapeHtml((c.nome || c.telefone).slice(0, 1).toUpperCase());
                    var atrasadoHtml = parseFloat(c.total_atrasado) > 0
                        ? ' · <span class="fin-atrasado">R$ ' + parseFloat(c.total_atrasado).toLocaleString('pt-BR', {minimumFractionDigits: 2}) + ' atrasado</span>'
                        : '';
                    a.innerHTML = '<div class="wpp-avatar-placeholder">' + inicial + '</div>' +
                        '<div class="wpp-corpo">' +
                        '<div class="nome"><span>' + escapeHtml(c.nome || c.telefone) + '</span>' +
                        (c.nao_lidas > 0 ? '<span class="wpp-badge">' + c.nao_lidas + '</span>' : '') + '</div>' +
                        '<div class="preview">' + (c.ultima_direcao === 'out' ? '✓ ' : '') + (c.ultima_tipo !== 'text' ? '📎 ' : '') + escapeHtml(preview) + '</div>' +
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
<?php include __DIR__ . '/_zapi_status.php'; ?>
</body>
</html>
