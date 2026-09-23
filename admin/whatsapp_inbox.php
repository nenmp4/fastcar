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

// ── AJAX: foto de perfil do WhatsApp, buscada AO VIVO (16/09/2026, "as
// fotos vem bugada" — guardar a URL da CDN do WhatsApp em
// clientes.foto_perfil_url e servir ela direto depois de um tempo dava
// ícone quebrado, porque essa URL expira; mesmo padrão já validado em
// produção no JurídicoSaaS — nunca cacheia, busca sob demanda a cada
// carregamento de tela, lazy e em lotes pra não floodar a Z-API).
if (isset($_GET['ajax']) && $_GET['ajax'] === 'foto') {
    header('Content-Type: application/json');
    $telefoneFoto = normalizarTelefone((string)($_GET['telefone'] ?? ''));
    if (!$telefoneFoto || !usuarioPodeVerConversaWhatsapp($telefoneFoto, $responsavelFiltro)) {
        echo json_encode(['foto_url' => '']);
        exit;
    }
    $contatoFoto = zapiBuscarContato($telefoneFoto);
    echo json_encode(['foto_url' => $contatoFoto['foto_url'] ?? '']);
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
        } elseif ($acao === 'enviar_audio' && $telPost) {
            // 17/09/2026, "permita enviar audio no inbox para o cliente" —
            // sempre via AJAX (fetch do input de arquivo), nunca form
            // tradicional (base64 de um áudio de verdade é grande demais
            // pra ida e volta de página inteira).
            $resultado = enviarAudioManualWhatsapp(
                $telPost, (string)($_POST['audio_base64'] ?? ''), (string)($_POST['mime'] ?? ''), (int)$_SESSION['admin_id']
            );
            header('Content-Type: application/json');
            echo json_encode($resultado);
            exit;
        } elseif ($acao === 'enviar_anexo' && $telPost) {
            // 18/09/2026, "adicionei opção de enviar anexo para clientes no
            // ibox do consultor" — mesmo padrão do envio de áudio: sempre
            // via AJAX (base64 de imagem/documento é grande demais pra ida
            // e volta de página inteira).
            $resultado = enviarAnexoManualWhatsapp(
                $telPost,
                (string)($_POST['anexo_base64'] ?? ''),
                (string)($_POST['mime'] ?? ''),
                (string)($_POST['nome_arquivo'] ?? ''),
                (int)$_SESSION['admin_id']
            );
            header('Content-Type: application/json');
            echo json_encode($resultado);
            exit;
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
                auditoriaRegistrar('conversa_excluida', (int)$_SESSION['admin_id'], (string)$_SESSION['admin_nome'], 'conversa_whatsapp', null, "Telefone {$telPost}.");
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
    $stmtCli = $db->prepare("SELECT nome, foto_perfil_url FROM clientes WHERE telefone = ?");
    $stmtCli->execute([$telefoneAtivo]);
    $cliRow = $stmtCli->fetch() ?: [];
    $contatoAtivo = [
        'telefone' => $telefoneAtivo,
        'cliente_nome' => $cliRow['nome'] ?? null,
        'foto_perfil_url' => $cliRow['foto_perfil_url'] ?? null,
        'ia_pausada' => iaPausada($telefoneAtivo) ? 1 : 0,
    ];
}
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>WhatsApp — Fastcar CRM</title>
<link rel="stylesheet" href="/admin/assets/style.css?v=<?= @filemtime(__DIR__ . '/assets/style.css') ?: 1 ?>">
<link rel="stylesheet" href="/admin/assets/mobile.css?v=<?= @filemtime(__DIR__ . '/assets/mobile.css') ?: 1 ?>">
<script src="/admin/assets/mobile.js?v=<?= @filemtime(__DIR__ . '/assets/mobile.js') ?: 1 ?>" defer></script>
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
.wpp-item { display: flex; gap: 10px; align-items: center; padding: 12px 16px; border-bottom: 1px solid var(--borda); text-decoration: none; color: inherit; position: relative; }
.wpp-item:hover { background: var(--fundo); text-decoration: none; }
.wpp-item.ativo { background: var(--azul-claro); }
.wpp-item .wpp-corpo { min-width: 0; flex: 1; }
.wpp-avatar { width: 38px; height: 38px; border-radius: 50%; object-fit: cover; flex-shrink: 0; background: var(--azul-claro); }
.wpp-avatar-placeholder { width: 38px; height: 38px; border-radius: 50%; flex-shrink: 0; background: var(--azul); color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 15px; }
.wpp-chat-header .wpp-avatar, .wpp-chat-header .wpp-avatar-placeholder { width: 40px; height: 40px; margin-right: 10px; }
.wpp-chat-header > div:first-child { display: flex; align-items: center; }
.wpp-avatar-clicavel { cursor: zoom-in; }
#foto-lightbox { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.82); z-index: 9999; align-items: center; justify-content: center; cursor: zoom-out; flex-direction: column; gap: .75rem; }
#foto-lightbox.aberto { display: flex; }
#foto-lightbox img { max-width: 320px; max-height: 320px; border-radius: 50%; box-shadow: 0 8px 40px rgba(0,0,0,.6); object-fit: cover; }
#foto-lightbox .nome { color: #fff; font-weight: 700; font-size: 15px; text-shadow: 0 1px 4px rgba(0,0,0,.7); }
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
                    <div class="wpp-avatar-placeholder" data-av-phone="<?= e($c['telefone']) ?>" data-nome="<?= e($c['cliente_nome'] ?: $c['telefone']) ?>"><?= e(mb_strtoupper(mb_substr($c['cliente_nome'] ?: $c['telefone'], 0, 1))) ?></div>
                    <div class="wpp-corpo">
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
                    </div>
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
                    <div class="wpp-avatar-placeholder" data-av-phone="<?= e($telefoneAtivo) ?>" data-nome="<?= e($contatoAtivo['cliente_nome'] ?: $telefoneAtivo) ?>"><?= e(mb_strtoupper(mb_substr($contatoAtivo['cliente_nome'] ?: $telefoneAtivo, 0, 1))) ?></div>
                    <div>
                        <a href="?" class="wpp-voltar-mobile" style="color:inherit">← Conversas</a>
                        <strong><?= e($contatoAtivo['cliente_nome'] ?: $telefoneAtivo) ?></strong>
                        <div style="font-size:12px;color:var(--texto-fraco)"><?= e($telefoneAtivo) ?></div>
                    </div>
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
                    <button type="button" id="wpp-btn-anexo" title="Anexar arquivo (foto ou documento)" style="padding:0 12px">📎</button>
                    <input type="file" id="wpp-input-anexo" accept="image/*,.pdf,.doc,.docx,.xls,.xlsx,.txt,.csv" style="display:none">
                    <button type="button" id="wpp-btn-audio" title="Gravar áudio" style="padding:0 12px">🎤</button>
                    <button type="button" id="wpp-btn-audio-cancelar" title="Cancelar gravação" style="padding:0 12px;display:none;color:#c0392b">✕</button>
                    <button type="submit">Enviar</button>
                </form>
                <p id="wpp-anexo-status" style="display:none;font-size:12.5px;color:var(--texto-fraco);margin:4px 0 0"></p>
                <p id="wpp-audio-status" style="display:none;font-size:12.5px;color:var(--texto-fraco);margin:4px 0 0"></p>
            <?php endif; ?>
        <?php endif; ?>
    </section>
</div>

<!-- Precisa vir ANTES do <script> abaixo: achado real testando o
     lazy-load de fotos (16/09/2026) — o script conecta o clique/Esc do
     lightbox em document.getElementById('foto-lightbox') assim que roda;
     com a div depois do <script>, esse elemento ainda não existia no DOM
     nesse ponto, e a chamada quebrava com TypeError (null.addEventListener),
     derrubando silenciosamente o resto do IIFE que vinha depois dela
     (o handler de tecla Esc nunca chegava a ser registrado). -->
<div id="foto-lightbox">
    <img id="foto-lightbox-img" src="" alt="">
    <div id="foto-lightbox-nome" class="nome"></div>
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
    // Cache em memória (só desta página aberta, nunca persistido — achado
    // real 16/09/2026: "as fotos aparece e some carrega desaparece") —
    // atualizarListaConversas() reconstrói a sidebar inteira do zero a cada
    // 5s de polling, sempre voltando pro placeholder de iniciais; sem esse
    // cache, carregarFotos() reaplicava a busca AJAX + troca do zero em
    // TODO refresh, piscando a foto (placeholder→foto→placeholder→foto...)
    // pra sempre. Com o telefone já em cache, o refresh usa a foto direto
    // (o navegador já tem essa URL no cache HTTP também, carrega instantâneo).
    var fotoCache = {};

    function escapeHtml(s) {
        var d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }

    // Foto de perfil do WhatsApp (16/09/2026, "as fotos vem bugada" —
    // achado real: guardar a URL da CDN em clientes.foto_perfil_url e servir
    // ela direto no <img src> dava ícone quebrado depois de um tempo,
    // porque essa URL expira; padrão corrigido pra igual ao JurídicoSaaS,
    // "só olhar padrão iab que está funcionando" — busca AO VIVO via AJAX,
    // nunca reaproveita URL velha, e só troca o placeholder pelo <img> DEPOIS
    // de confirmar que carregou (onload), nunca antes — assim o usuário
    // nunca vê ícone de imagem quebrada, só continua vendo o círculo de
    // iniciais até a foto de verdade estar pronta).
    function _aplicarFoto(el, fotoUrl) {
        if (!fotoUrl) return;
        var phone = el.getAttribute('data-av-phone');
        if (phone) fotoCache[phone] = fotoUrl;
        var img = document.createElement('img');
        img.className = 'wpp-avatar wpp-avatar-clicavel';
        img.alt = '';
        // NUNCA loading="lazy" aqui — achado real testando: num <img> ainda
        // fora do DOM (só entra depois, no onload abaixo), lazy-loading
        // nativo nunca dispara o carregamento (não tem como o navegador
        // saber que "está perto da viewport" pra algo que não existe na
        // árvore ainda) — trava pra sempre nesse círculo. O lazy/batching de
        // verdade já é feito manualmente pelo setTimeout de carregarEl().
        img.setAttribute('data-nome', el.getAttribute('data-nome') || '');
        img.onerror = function () {}; // falhou — mantém o placeholder como está
        img.onload = function () {
            img.onclick = function (ev) {
                ev.preventDefault();
                ev.stopPropagation();
                abrirFotoLightbox(img.src, img.getAttribute('data-nome'));
            };
            el.replaceWith(img);
        };
        img.src = fotoUrl;
    }

    function carregarFotos() {
        // Placeholder ainda (IMG já é o elemento cacheado renderizado direto
        // por atualizarListaConversas() — reprocessar de novo só trocaria o
        // <img> por outro <img> idêntico à toa).
        var avs = Array.from(document.querySelectorAll('div.wpp-avatar-placeholder[data-av-phone]'));
        var header = document.querySelector('.wpp-chat-header [data-av-phone]');
        // Sidebar pode ter até 100 conversas — capa em 30 pra não floodar a
        // Z-API com 1 chamada por avatar visível (mesmo cuidado do JurídicoSaaS).
        var sidebar = avs.filter(function (el) { return el !== header; }).slice(0, 30);

        function carregarEl(el, delay) {
            var phone = el.getAttribute('data-av-phone');
            if (!phone) return;
            if (fotoCache[phone]) { _aplicarFoto(el, fotoCache[phone]); return; }
            setTimeout(function () {
                fetch('?ajax=foto&telefone=' + encodeURIComponent(phone))
                    .then(function (r) { return r.json(); })
                    .then(function (d) { _aplicarFoto(el, d.foto_url); })
                    .catch(function () {});
            }, delay);
        }

        if (header && header.tagName === 'DIV') carregarEl(header, 0);
        var delay = 200;
        sidebar.forEach(function (el, i) { carregarEl(el, delay + Math.floor(i / 5) * 600 + (i % 5) * 80); });
    }
    carregarFotos();

    // Espelha includes/whatsapp_inbox.php::tipoMidiaMensagemWhatsapp() —
    // `tipo` sozinho não basta (mídia descrita com sucesso pelo Gemini vira
    // tipo='text' de propósito, só o prefixo emoji denuncia), por isso o
    // mesmo fallback por prefixo do lado PHP.
    function tipoMidiaMsg(m) {
        if (!m.drive_file_id && !m.arquivo_url) return null;
        if (m.tipo === 'audio' || m.tipo === 'image' || m.tipo === 'video' || m.tipo === 'document') return m.tipo;
        if (m.mensagem.indexOf('🎤') === 0) return 'audio';
        if (m.mensagem.indexOf('🎥') === 0) return 'video';
        if (m.mensagem.indexOf('📷') === 0) return 'image';
        if (m.mensagem.indexOf('📎') === 0) return 'document';
        return null;
    }

    function renderizarMidiaMsg(m) {
        var midia = tipoMidiaMsg(m);
        if (!midia) return '';
        var url = '/admin/ver_midia_whatsapp.php?id=' + m.id;
        if (midia === 'audio') return '<audio controls preload="none" src="' + url + '" style="max-width:260px;display:block;margin-top:6px"></audio>';
        if (midia === 'video') return '<video controls preload="none" src="' + url + '" style="max-width:260px;border-radius:8px;display:block;margin-top:6px"></video>';
        if (midia === 'document') return '<a href="' + url + '" target="_blank" rel="noopener" style="display:inline-flex;align-items:center;gap:6px;margin-top:6px;padding:6px 10px;background:#f1f5f9;border-radius:8px;text-decoration:none;color:inherit;font-size:.85rem">📎 ' + escapeHtml(m.mensagem.replace(/^📎\s*/, '')) + '</a>';
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
        }, 2000);
    }

    // Refresca a barra lateral (não-lidas/ordem/última mensagem) sem
    // interromper o que o admin está digitando — só troca a lista. Bug
    // real (15/09/2026, "verifica demora de atualizar as mensgens do
    // ibox"): esse polling só rodava dentro do `if (telefone)` acima, ou
    // seja, só atualizava sozinho com uma conversa JÁ aberta — sentado na
    // caixa sem nenhuma conversa selecionada (o estado mais comum
    // esperando lead novo chegar), a lista nunca atualizava sozinha, só
    // recarregando a página na mão. Movido pra fora do `if`, roda sempre.
    // Intervalo apertado (5s) a pedido — "deixa bem fluido inbox".
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
                    var inicial = escapeHtml((c.cliente_nome || c.telefone).slice(0, 1).toUpperCase());
                    var nomeAttr = escapeHtml(c.cliente_nome || c.telefone);
                    // Foto já em cache (carregada numa passada anterior de
                    // carregarFotos() nesta mesma página) renderiza o <img>
                    // direto, sem passar pelo placeholder de novo — sem isso,
                    // o refresh de 5s reconstruía a lista inteira sempre do
                    // zero, voltando pro placeholder e piscando a foto que já
                    // tinha carregado (achado real, "as fotos aparece e some").
                    // Sem cache ainda, placeholder normal — carregarFotos()
                    // troca pela foto de verdade só depois de confirmar que
                    // carregou (ver comentário de _aplicarFoto).
                    var fotoCacheada = fotoCache[c.telefone];
                    var avatar = fotoCacheada
                        ? '<img class="wpp-avatar wpp-avatar-clicavel" src="' + escapeHtml(fotoCacheada) + '" alt="" data-av-phone="' + escapeHtml(c.telefone) + '" data-nome="' + nomeAttr + '" onclick="event.preventDefault(); event.stopPropagation(); abrirFotoLightbox(this.src, this.getAttribute(\'data-nome\'))">'
                        : '<div class="wpp-avatar-placeholder" data-av-phone="' + escapeHtml(c.telefone) + '" data-nome="' + nomeAttr + '">' + inicial + '</div>';
                    a.innerHTML = avatar +
                        '<div class="wpp-corpo">' +
                        '<div class="nome"><span>' + escapeHtml(c.cliente_nome || c.telefone) + '</span>' +
                        (c.nao_lidas > 0 ? '<span class="wpp-badge">' + c.nao_lidas + '</span>' : '') + '</div>' +
                        '<div class="preview">' + (c.ultima_direcao === 'out' ? '✓ ' : '') + (c.ultima_tipo !== 'text' ? '📎 ' : '') + escapeHtml(preview) + '</div>' +
                        '<div class="quando">' + quando + (c.ia_pausada == 1 ? ' · ⏸️ IA pausada' : '') + '</div>' +
                        '</div>';
                    lista.appendChild(a);
                });
                carregarFotos();
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

    // Enviar áudio (17/09/2026, "permita enviar audio no inbox para o
    // cliente") — botão 🎤 abre o seletor de arquivo (accept="audio/*",
    // já filtra no picker do sistema); lido como base64 no navegador e
    // mandado via fetch, mesmo padrão AJAX do envio de texto. Nunca
    // desenha uma bolha otimista pro áudio (diferente do texto) — sem
    // saber ainda a URL de reprodução da cópia salva no servidor, seria
    // preciso duplicar a lógica de player só pra essa 1ª renderização;
    // mais simples deixar o polling de 2s (já rodando) pegar a mensagem
    // nova como qualquer outra, evita reproduzir o mesmo bug de
    // duplicação já corrigido uma vez no envio de texto.
    //
    // Gravação direto pelo microfone (17/09/2026, "mandar audio está com
    // bug ainda está abrindo pasta no pc") — a 1ª versão abria o seletor
    // de arquivo do navegador (<input type=file accept="audio/*">), que o
    // consultor esperava que fosse gravar a voz na hora, tipo WhatsApp de
    // verdade, não escolher um arquivo já salvo no PC. Trocado por
    // MediaRecorder (API nativa do navegador) — clique inicia a gravação
    // pelo microfone, clique de novo no mesmo botão para e envia; nunca
    // fica pendurado esperando o usuário "soltar" um botão (padrão
    // clique/clique, mais robusto que segurar/soltar num mouse — soltar
    // fora do botão por acidente perderia a gravação).
    // Anexo (foto ou documento) manual — 18/09/2026, "adicionei opção de
    // enviar anexo para clientes no ibox do consultor". Clique abre o
    // seletor de arquivo nativo (diferente do áudio, aqui faz sentido
    // escolher um arquivo já salvo — foto tirada antes, PDF de contrato
    // etc — não é uma "gravação ao vivo"); ao escolher, lê como base64 e
    // manda via o mesmo padrão AJAX do áudio. Mesmo limite de tamanho do
    // servidor (WHATSAPP_MIDIA_MAX_BYTES, 20MB) checado aqui também, só
    // pra dar feedback imediato sem esperar a ida e volta da rede.
    var btnAnexo = document.getElementById('wpp-btn-anexo');
    var inputAnexo = document.getElementById('wpp-input-anexo');
    var statusAnexo = document.getElementById('wpp-anexo-status');
    var ANEXO_MAX_BYTES = 20 * 1024 * 1024;
    if (btnAnexo && inputAnexo && statusAnexo) {
        btnAnexo.addEventListener('click', function () {
            inputAnexo.value = '';
            inputAnexo.click();
        });
        inputAnexo.addEventListener('change', function () {
            var arquivo = inputAnexo.files && inputAnexo.files[0];
            if (!arquivo) return;
            if (arquivo.size > ANEXO_MAX_BYTES) {
                alert('Arquivo maior que o limite de 20MB.');
                return;
            }
            btnAnexo.disabled = true;
            statusAnexo.style.display = 'block';
            statusAnexo.textContent = '📎 Enviando ' + arquivo.name + '...';
            var leitor = new FileReader();
            leitor.onload = function () {
                var base64 = String(leitor.result).split(',')[1] || '';
                var body = new URLSearchParams();
                body.set('csrf_token', csrf.value);
                body.set('acao', 'enviar_anexo');
                body.set('telefone', telefone);
                body.set('ajax', '1');
                body.set('mime', arquivo.type || 'application/octet-stream');
                body.set('nome_arquivo', arquivo.name);
                body.set('anexo_base64', base64);
                fetch('', { method: 'POST', body: body })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        // Mesmo espírito do áudio — nunca desenha bolha
                        // otimista aqui, o polling de 2s já em andamento
                        // traz a mensagem nova sozinho, com o link/preview
                        // certo já pronto pra servir.
                        if (!data.ok) alert(data.erro || 'Falha ao enviar anexo.');
                    })
                    .catch(function () {
                        alert('Falha ao enviar anexo — confira sua conexão.');
                    })
                    .finally(function () {
                        btnAnexo.disabled = false;
                        statusAnexo.style.display = 'none';
                    });
            };
            leitor.onerror = function () {
                alert('Não consegui processar esse arquivo.');
                btnAnexo.disabled = false;
                statusAnexo.style.display = 'none';
            };
            leitor.readAsDataURL(arquivo);
        });
    }

    var btnAudio = document.getElementById('wpp-btn-audio');
    var btnAudioCancelar = document.getElementById('wpp-btn-audio-cancelar');
    var statusAudio = document.getElementById('wpp-audio-status');
    if (btnAudio && statusAudio) {
        var gravador = null;
        var streamAtual = null;
        var pedacos = [];
        var timerIntervalo = null;
        var inicioGravacao = 0;
        var cancelando = false;
        var AUDIO_MAX_SEGUNDOS = 600; // 10min — rede de segurança, nunca grava pra sempre se esquecerem aberto

        function formatarTempo(segundos) {
            var m = Math.floor(segundos / 60);
            var s = segundos % 60;
            return m + ':' + (s < 10 ? '0' : '') + s;
        }

        function pararStream() {
            if (streamAtual) {
                streamAtual.getTracks().forEach(function (t) { t.stop(); });
                streamAtual = null;
            }
            if (timerIntervalo) {
                clearInterval(timerIntervalo);
                timerIntervalo = null;
            }
        }

        function voltarAoEstadoInicial() {
            pararStream();
            gravador = null;
            pedacos = [];
            cancelando = false;
            btnAudio.textContent = '🎤';
            btnAudio.title = 'Gravar áudio';
            btnAudio.disabled = false;
            btnAudioCancelar.style.display = 'none';
            statusAudio.style.display = 'none';
        }

        function enviarAudioGravado(blob, mime) {
            btnAudio.disabled = true;
            statusAudio.style.display = 'block';
            statusAudio.textContent = '🎤 Enviando áudio...';
            var leitor = new FileReader();
            leitor.onload = function () {
                var base64 = String(leitor.result).split(',')[1] || '';
                var body = new URLSearchParams();
                body.set('csrf_token', csrf.value);
                body.set('acao', 'enviar_audio');
                body.set('telefone', telefone);
                body.set('ajax', '1');
                body.set('mime', mime);
                body.set('audio_base64', base64);
                fetch('', { method: 'POST', body: body })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        // Nunca mexe em ultimoId aqui de propósito — o próximo
                        // poll de 2s (setInterval já rodando) descobre a
                        // mensagem nova sozinho e desenha a bolha certa,
                        // com a URL de reprodução já pronta.
                        if (!data.ok) alert(data.erro || 'Falha ao enviar áudio.');
                    })
                    .catch(function () {
                        alert('Falha ao enviar áudio — confira sua conexão.');
                    })
                    .finally(voltarAoEstadoInicial);
            };
            leitor.onerror = function () {
                alert('Não consegui processar o áudio gravado.');
                voltarAoEstadoInicial();
            };
            leitor.readAsDataURL(blob);
        }

        function iniciarGravacao() {
            if (!navigator.mediaDevices || !window.MediaRecorder) {
                alert('Seu navegador não suporta gravação de áudio. Tente pelo Chrome, Firefox ou Edge atualizados.');
                return;
            }
            navigator.mediaDevices.getUserMedia({ audio: true }).then(function (stream) {
                streamAtual = stream;
                var candidatos = ['audio/ogg;codecs=opus', 'audio/webm;codecs=opus', 'audio/webm', 'audio/mp4'];
                var mimeEscolhido = '';
                for (var i = 0; i < candidatos.length; i++) {
                    if (MediaRecorder.isTypeSupported(candidatos[i])) { mimeEscolhido = candidatos[i]; break; }
                }
                try {
                    gravador = mimeEscolhido ? new MediaRecorder(stream, { mimeType: mimeEscolhido }) : new MediaRecorder(stream);
                } catch (e) {
                    alert('Não consegui iniciar a gravação neste navegador.');
                    pararStream();
                    return;
                }
                pedacos = [];
                cancelando = false;
                gravador.addEventListener('dataavailable', function (e) {
                    if (e.data && e.data.size > 0) pedacos.push(e.data);
                });
                gravador.addEventListener('stop', function () {
                    // stop() dispara um 'dataavailable' final com o pedaço
                    // pendente ANTES do evento 'stop' — checar cancelando
                    // aqui (não só pedacos.length) é o que garante que
                    // cancelar de verdade não manda nada, mesmo que esse
                    // pedaço final já tenha entrado no array nesse meio-tempo.
                    if (cancelando || pedacos.length === 0) { voltarAoEstadoInicial(); return; }
                    var mimeFinal = gravador.mimeType || mimeEscolhido || 'audio/webm';
                    var blob = new Blob(pedacos, { type: mimeFinal });
                    enviarAudioGravado(blob, mimeFinal);
                });
                gravador.start();
                inicioGravacao = Date.now();
                btnAudio.textContent = '⏹️';
                btnAudio.title = 'Parar e enviar';
                btnAudioCancelar.style.display = '';
                statusAudio.style.display = 'block';
                statusAudio.textContent = '🔴 Gravando... 0:00';
                timerIntervalo = setInterval(function () {
                    var decorridos = Math.floor((Date.now() - inicioGravacao) / 1000);
                    statusAudio.textContent = '🔴 Gravando... ' + formatarTempo(decorridos);
                    if (decorridos >= AUDIO_MAX_SEGUNDOS && gravador && gravador.state === 'recording') {
                        gravador.stop();
                    }
                }, 1000);
            }).catch(function () {
                alert('Não consegui acessar o microfone — verifique a permissão do navegador pra este site.');
            });
        }

        btnAudio.addEventListener('click', function () {
            if (gravador && gravador.state === 'recording') {
                gravador.stop(); // dispara o listener 'stop' acima, que envia
            } else {
                iniciarGravacao();
            }
        });

        btnAudioCancelar.addEventListener('click', function () {
            if (gravador && gravador.state === 'recording') {
                cancelando = true;
                gravador.stop();
            }
        });
    }

    // Foto de perfil clicável — mesmo padrão do WhatsApp Inbox do
    // JurídicoSaaS (repo irmão), lido direto de lá pra copiar o
    // comportamento certo em vez de reinventar (16/09/2026, "vai no
    // inbox do iab tem jeito certo lá" / "pode deixar foto clicavel
    // iggual ai"): clique em qualquer avatar com foto abre ela grande.
    window.abrirFotoLightbox = function (src, nome) {
        if (!src) return;
        document.getElementById('foto-lightbox-img').src = src;
        document.getElementById('foto-lightbox-nome').textContent = nome || '';
        document.getElementById('foto-lightbox').classList.add('aberto');
    };
    document.getElementById('foto-lightbox').addEventListener('click', function () {
        this.classList.remove('aberto');
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') document.getElementById('foto-lightbox').classList.remove('aberto');
    });
})();
</script>

<?php include __DIR__ . '/_pwa_register.php'; ?>
<?php include __DIR__ . '/_notify.php'; ?>
<?php include __DIR__ . '/_zapi_status.php'; ?>
</body>
</html>
