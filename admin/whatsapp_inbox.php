<?php
/**
 * WhatsApp Box — caixa de entrada única dentro do CRM (decisão de
 * 15/09/2026, ver includes/whatsapp_inbox.php pro racional completo):
 * qualquer admin (consultor ou super_admin) vê e responde toda conversa
 * pela MESMA instância Z-API principal, em vez de cada consultor ter a
 * própria instância. Enviar por aqui pausa a IA automaticamente (regra #4).
 */

require_once __DIR__ . '/_bootstrap.php';

// normalizarTelefone() (não só strip de dígitos) garante que um número
// digitado sem o DDI 55 (ex: pela caixa "+ Iniciar conversa") já bate
// certinho com o que fica salvo em whatsapp_mensagens/clientes — sem isso,
// a mesma conversa podia aparecer 2x (uma com 55, outra sem) até a página
// recarregar.
$telefoneGet = trim((string)($_GET['telefone'] ?? ''));
$telefoneAtivo = $telefoneGet !== '' ? normalizarTelefone($telefoneGet) : '';
// "inbox vai mostrar todos ou leads do usuário que iniciou atendimento?"
// (pergunta direta do José/Jean, 15/09/2026) — super_admin e supervisor
// (perfilVeTudo(), 15/09/2026) veem a caixa inteira; consultor só vê
// conversa de cliente onde ele é responsavel_id em alguma oportunidade,
// mesmo padrão "Minhas/Todas" de admin/index.php.
$responsavelFiltro = perfilVeTudo() ? null : (int)$_SESSION['admin_id'];

// ── AJAX: mensagens novas (polling da conversa aberta) ─────────────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'novas') {
    header('Content-Type: application/json');
    $depoisDe = (int)($_GET['after_id'] ?? 0);
    if (!$telefoneAtivo || !usuarioPodeVerConversaWhatsapp($telefoneAtivo, $responsavelFiltro)) {
        echo json_encode(['mensagens' => []]);
        exit;
    }
    $novas = buscarMensagensNovasConversa($telefoneAtivo, $depoisDe);
    if ($novas) marcarConversaLida($telefoneAtivo);
    echo json_encode(['mensagens' => $novas]);
    exit;
}

// ── AJAX: mensagens anteriores (botão "carregar mensagens anteriores") ─────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'anteriores') {
    header('Content-Type: application/json');
    $antesDe = (int)($_GET['before_id'] ?? 0);
    if (!$telefoneAtivo || !$antesDe || !usuarioPodeVerConversaWhatsapp($telefoneAtivo, $responsavelFiltro)) {
        echo json_encode(['mensagens' => []]);
        exit;
    }
    echo json_encode(['mensagens' => buscarMensagensAntesId($telefoneAtivo, $antesDe)]);
    exit;
}

// ── AJAX: lista de conversas (refresca a barra lateral sem recarregar tudo) ─
if (isset($_GET['ajax']) && $_GET['ajax'] === 'conversas') {
    header('Content-Type: application/json');
    echo json_encode(['conversas' => listarConversasWhatsapp(trim((string)($_GET['busca'] ?? '')), $responsavelFiltro)]);
    exit;
}

$erro = '';
$sucesso = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $viaAjax = ($_POST['ajax'] ?? '') === '1';
    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        $erro = 'Sessão expirada, recarregue a página e tente de novo.';
    } elseif ($_SESSION['admin_perfil'] === 'supervisor') {
        // Perfil de acompanhamento (15/09/2026) — vê a caixa inteira mas
        // nunca manda mensagem, pausa IA ou exclui conversa por ninguém.
        if ($viaAjax) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'erro' => 'Perfil de supervisão só acompanha, não envia mensagem.']);
            exit;
        }
        $erro = 'Perfil de supervisão só acompanha, não envia mensagem.';
    } else {
        $acao = (string)($_POST['acao'] ?? '');
        $telPost = preg_replace('/\D/', '', (string)($_POST['telefone'] ?? ''));
        if ($telPost && !usuarioPodeVerConversaWhatsapp($telPost, $responsavelFiltro)) {
            if ($viaAjax) {
                header('Content-Type: application/json');
                echo json_encode(['ok' => false, 'erro' => 'Essa conversa não é de um cliente sob sua responsabilidade.']);
                exit;
            }
            $erro = 'Essa conversa não é de um cliente sob sua responsabilidade.';
            $telPost = '';
        }
        if ($acao === 'enviar_mensagem' && $telPost) {
            $resultado = enviarMensagemManualWhatsapp($telPost, (string)($_POST['texto'] ?? ''), (int)$_SESSION['admin_id']);
            if ($viaAjax) {
                header('Content-Type: application/json');
                echo json_encode($resultado);
                exit;
            }
            if (!$resultado['ok']) $erro = $resultado['erro'];
            $telefoneAtivo = $telPost;
        } elseif ($acao === 'toggle_ia' && $telPost) {
            if (iaPausada($telPost)) {
                retomarIA($telPost);
                $sucesso = 'IA reativada pra essa conversa.';
            } else {
                pausarIA($telPost);
                $sucesso = 'IA pausada — só a equipe responde por aqui até reativar.';
            }
            $telefoneAtivo = $telPost;
        } elseif ($acao === 'excluir_conversa' && $telPost) {
            // Restrito ao super_admin — ação destrutiva (apaga o histórico de
            // mensagens pra sempre), sem tela de confirmação própria porque o
            // JS já pede confirm() antes de submeter.
            if ($_SESSION['admin_perfil'] !== 'super_admin') {
                $erro = 'Só o super_admin pode excluir uma conversa.';
                $telefoneAtivo = $telPost;
            } else {
                excluirConversaWhatsapp($telPost);
                $sucesso = 'Conversa excluída.';
                $telefoneAtivo = '';
            }
        }
    }
}

if ($telefoneAtivo && !usuarioPodeVerConversaWhatsapp($telefoneAtivo, $responsavelFiltro)) {
    $erro = 'Essa conversa não é de um cliente sob sua responsabilidade.';
    $telefoneAtivo = '';
}

if ($telefoneAtivo) {
    marcarConversaLida($telefoneAtivo);
}

$busca = trim((string)($_GET['busca'] ?? ''));
$conversas = listarConversasWhatsapp($busca, $responsavelFiltro);
$mensagens = $telefoneAtivo ? buscarMensagensConversa($telefoneAtivo) : [];
$ultimoId = $mensagens ? (int)end($mensagens)['id'] : 0;
$primeiroId = $mensagens ? (int)$mensagens[0]['id'] : 0;
$contatoAtivo = null;
foreach ($conversas as $c) {
    if ($c['telefone'] === $telefoneAtivo) { $contatoAtivo = $c; break; }
}
// Conversa pode estar selecionada por link direto (ex: admin/oportunidade.php)
// sem aparecer na lista carregada (busca ativa escondendo, ou lista truncada
// no limite) — busca à parte só pra não perder nome/status da IA na tela.
if ($telefoneAtivo && !$contatoAtivo) {
    $db = getDB();
    $stmtCli = $db->prepare("SELECT nome FROM clientes WHERE telefone = ?");
    $stmtCli->execute([$telefoneAtivo]);
    $nomeCliente = $stmtCli->fetchColumn();
    $contatoAtivo = ['telefone' => $telefoneAtivo, 'cliente_nome' => $nomeCliente ?: null, 'ia_pausada' => iaPausada($telefoneAtivo) ? 1 : 0];
}
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>WhatsApp — Fastcar CRM</title>
<link rel="stylesheet" href="/admin/assets/style.css?v=<?= @filemtime(__DIR__ . '/assets/style.css') ?: 1 ?>">
<?php include __DIR__ . '/_pwa_head.php'; ?>
<style>
.wpp-wrap { display: flex; height: calc(100vh - 61px); }
.wpp-sidebar { width: 320px; flex-shrink: 0; border-right: 1px solid var(--borda); background: var(--superficie); display: flex; flex-direction: column; }
.wpp-sidebar form { padding: 12px; border-bottom: 1px solid var(--borda); }
.wpp-sidebar form input { margin: 0; }
.wpp-nova-form { display: flex; gap: 8px; }
.wpp-nova-form input { flex: 1; min-width: 0; }
.wpp-nova-form button { margin: 0; white-space: nowrap; padding: 6px 12px; font-size: 12.5px; }
.wpp-lista { flex: 1; overflow-y: auto; }
.wpp-item { display: block; padding: 12px 16px; border-bottom: 1px solid var(--borda); text-decoration: none; color: inherit; position: relative; }
.wpp-item:hover { background: var(--fundo); text-decoration: none; }
.wpp-item.ativo { background: var(--azul-claro); }
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
/* Celular: só 1 painel por vez — sidebar OU conversa, igual WhatsApp de
   verdade. Sem isso os 320px fixos da sidebar sozinhos já tomavam a tela
   toda numa tela de ~390px, escondendo a conversa por completo. */
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
    <a href="/admin/index.php" style="color:#fff">← Voltar</a>
    <strong><img class="topbar-logo" src="/admin/assets/img/icon-192.png" alt="Fastcar" onerror="this.style.display='none'"> Fast<b>Car</b> <span class="crm-tag">CRM</span></strong>
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
        <form method="get" class="wpp-nova-form" id="wpp-nova-form">
            <input type="text" name="telefone" placeholder="Nova conversa — telefone com DDD" inputmode="numeric">
            <button type="submit">+ Iniciar conversa</button>
        </form>
        <div class="wpp-lista" id="wpp-lista">
            <?php if (!$conversas): ?>
                <p style="padding:16px;color:var(--texto-fraco);font-size:13px">
                    <?= $busca ? 'Nenhuma conversa encontrada.' : 'Nenhuma conversa ainda — mensagens aparecem aqui assim que o cliente escrever pelo WhatsApp.' ?>
                </p>
            <?php endif; ?>
            <?php foreach ($conversas as $c): ?>
                <a class="wpp-item <?= $c['telefone'] === $telefoneAtivo ? 'ativo' : '' ?>" href="?telefone=<?= e($c['telefone']) ?>">
                    <div class="nome">
                        <span><?= e($c['cliente_nome'] ?: $c['telefone']) ?></span>
                        <?php if ((int)$c['nao_lidas'] > 0): ?><span class="wpp-badge"><?= (int)$c['nao_lidas'] ?></span><?php endif; ?>
                    </div>
                    <div class="preview">
                        <?= $c['ultima_direcao'] === 'out' ? '✓ ' : '' ?>
                        <?= $c['ultima_tipo'] !== 'text' ? '📎 ' : '' ?>
                        <?= e(mb_strimwidth($c['ultima_mensagem'], 0, 60, '…')) ?>
                    </div>
                    <div class="quando"><?= date('d/m H:i', strtotime($c['ultima_em'])) ?><?= $c['ia_pausada'] ? ' · ⏸️ IA pausada' : '' ?></div>
                </a>
            <?php endforeach; ?>
        </div>
    </aside>

    <section class="wpp-chat">
        <?php if (!$telefoneAtivo || !$contatoAtivo): ?>
            <div class="wpp-vazio">👈 Selecione uma conversa pra ver o histórico e responder.</div>
        <?php else: ?>
            <div class="wpp-chat-header">
                <div>
                    <a href="?" class="wpp-voltar-mobile" style="color:inherit">← Conversas</a>
                    <strong><?= e($contatoAtivo['cliente_nome'] ?: $telefoneAtivo) ?></strong>
                    <div style="font-size:12px;color:var(--texto-fraco)"><?= e($telefoneAtivo) ?></div>
                </div>
                <div style="display:flex;gap:8px">
                    <?php if ($_SESSION['admin_perfil'] !== 'supervisor'): ?>
                        <form method="post" class="inline">
                            <?= csrfField() ?>
                            <input type="hidden" name="acao" value="toggle_ia">
                            <input type="hidden" name="telefone" value="<?= e($telefoneAtivo) ?>">
                            <button type="submit" style="margin-top:0;padding:6px 12px;font-size:12.5px">
                                <?= $contatoAtivo['ia_pausada'] ? '▶️ Reativar IA' : '⏸️ Pausar IA' ?>
                            </button>
                        </form>
                    <?php endif; ?>
                    <?php if ($_SESSION['admin_perfil'] === 'super_admin'): ?>
                        <form method="post" class="inline" onsubmit="return confirm('Apagar essa conversa inteira? Não tem como desfazer.');">
                            <?= csrfField() ?>
                            <input type="hidden" name="acao" value="excluir_conversa">
                            <input type="hidden" name="telefone" value="<?= e($telefoneAtivo) ?>">
                            <button type="submit" class="perigo" style="margin-top:0;padding:6px 12px;font-size:12.5px">
                                🗑️ Excluir conversa
                            </button>
                        </form>
                    <?php endif; ?>
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
                            <?= $m['enviado_por_ia'] ? ' · 🤖 IA' : '' ?>
                            <?= $m['usuario_nome'] ? ' · 👤 ' . e($m['usuario_nome']) : '' ?>
                        </small>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php if ($_SESSION['admin_perfil'] === 'supervisor'): ?>
                <div class="wpp-form" style="color:var(--texto-fraco);font-size:13px">
                    👁️ Modo de acompanhamento — perfil de supervisão só visualiza, não envia mensagem.
                </div>
            <?php else: ?>
                <form method="post" class="wpp-form" id="wpp-form">
                    <?= csrfField() ?>
                    <input type="hidden" name="acao" value="enviar_mensagem">
                    <input type="hidden" name="telefone" value="<?= e($telefoneAtivo) ?>">
                    <input type="hidden" name="ajax" value="1">
                    <textarea name="texto" placeholder="Digite uma mensagem..." required></textarea>
                    <button type="submit">Enviar</button>
                </form>
            <?php endif; ?>
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

    // Espelha includes/whatsapp_inbox.php::tipoMidiaMensagemWhatsapp() —
    // `tipo` sozinho não basta (mídia descrita com sucesso pelo Gemini vira
    // tipo='text' de propósito, só o prefixo emoji denuncia), por isso o
    // mesmo fallback por prefixo do lado PHP.
    function tipoMidiaMsg(m) {
        if (!m.drive_file_id && !m.arquivo_url) return null;
        if (m.tipo === 'audio' || m.tipo === 'image' || m.tipo === 'video') return m.tipo;
        if (m.mensagem.indexOf('🎤') === 0) return 'audio';
        if (m.mensagem.indexOf('🎥') === 0) return 'video';
        if (m.mensagem.indexOf('📷') === 0) return 'image';
        return null;
    }

    function renderizarMidiaMsg(m) {
        var midia = tipoMidiaMsg(m);
        if (!midia) return '';
        var url = '/admin/ver_midia_whatsapp.php?id=' + m.id;
        if (midia === 'audio') return '<audio controls preload="none" src="' + url + '" style="max-width:260px;display:block;margin-top:6px"></audio>';
        if (midia === 'video') return '<video controls preload="none" src="' + url + '" style="max-width:260px;border-radius:8px;display:block;margin-top:6px"></video>';
        return '<a href="' + url + '" target="_blank" rel="noopener"><img src="' + url + '" loading="lazy" style="max-width:220px;border-radius:8px;display:block;margin-top:6px"></a>';
    }

    function renderMsg(m) {
        var div = document.createElement('div');
        div.className = 'msg ' + (m.direcao === 'in' ? 'msg-in' : 'msg-out');
        div.dataset.id = m.id;
        var rodape = new Date(m.created_at.replace(' ', 'T')).toLocaleString('pt-BR', {day:'2-digit',month:'2-digit',hour:'2-digit',minute:'2-digit'});
        if (m.enviado_por_ia == 1) rodape += ' · 🤖 IA';
        if (m.usuario_nome) rodape += ' · 👤 ' + m.usuario_nome;
        div.innerHTML = escapeHtml(m.mensagem).replace(/\n/g, '<br>') + renderizarMidiaMsg(m) + '<small>' + escapeHtml(rodape) + '</small>';
        return div;
    }

    // Já existe uma bolha renderizada pra esse id? (data-id) — usado pra
    // não duplicar quando o polling periódico e a resposta otimista do
    // próprio envio se cruzam (ver comentário na hora do submit abaixo).
    function jaRenderizada(id) {
        return !!thread.querySelector('[data-id="' + id + '"]');
    }

    // "⬆️ Carregar mensagens anteriores" (15/09/2026, achado real: "inbox
    // não está mostrando conversa inteira" — a tela só carregava as
    // últimas 50 mensagens ao abrir, sem jeito nenhum de ver o que veio
    // antes numa conversa mais longa). Preserva a posição de rolagem ao
    // inserir mensagens mais antigas no topo (sem isso a tela "pula" pro
    // topo toda vez que clicar).
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
                    // msgs já vem em ordem cronológica (mais antiga primeiro) —
                    // um fragmento só preserva essa ordem numa inserção única.
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
        }, 4000);

        // Refresca a barra lateral (não-lidas/ordem/última mensagem) sem
        // interromper o que o admin está digitando — só troca a lista.
        setInterval(atualizarListaConversas, 15000);
    }

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
                    a.innerHTML =
                        '<div class="nome"><span>' + escapeHtml(c.cliente_nome || c.telefone) + '</span>' +
                        (c.nao_lidas > 0 ? '<span class="wpp-badge">' + c.nao_lidas + '</span>' : '') + '</div>' +
                        '<div class="preview">' + (c.ultima_direcao === 'out' ? '✓ ' : '') + (c.ultima_tipo !== 'text' ? '📎 ' : '') + escapeHtml(preview) + '</div>' +
                        '<div class="quando">' + quando + (c.ia_pausada == 1 ? ' · ⏸️ IA pausada' : '') + '</div>';
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
                        if (data.id) ultimoId = Math.max(ultimoId, data.id); // evita duplicar no próximo poll
                        // Achado real (15/09/2026, "mando Olá, mostra que mandou duas
                        // vezes"): o poll de 4s roda em paralelo e pode já estar em
                        // trânsito com o after_id ANTIGO quando esse envio termina —
                        // nesse caso ele chega DEPOIS e também renderiza essa mesma
                        // mensagem (agora já salva no banco), gerando 2 bolhas pra 1
                        // envio só. jaRenderizada() checa pelo data-id antes de
                        // desenhar a bolha otimista — se o poll já desenhou primeiro,
                        // não desenha de novo.
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
</body>
</html>
