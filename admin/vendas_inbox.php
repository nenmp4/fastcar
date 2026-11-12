<?php
/**
 * WhatsApp Box do módulo de vendas — caixa de entrada dentro do CRM pra
 * conversar com compradores em potencial pela instância Z-API DEDICADA de
 * vendas. Espelha admin/whatsapp_inbox.php (compra) — ver
 * includes/vendas_inbox.php pro racional completo e o que ficou de fora
 * nesta 1ª versão (foto de perfil ao vivo, envio de áudio).
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../includes/wpp_preview.php';
require_once __DIR__ . '/../includes/vendas_inbox.php';
requireAcessoVendas();

$telefoneGet = trim((string)($_GET['telefone'] ?? ''));
$telefoneAtivo = $telefoneGet !== '' ? normalizarTelefone($telefoneGet) : '';
// "+ Iniciar conversa" (09/11/2026, igual ao inbox de compra).
if (isset($_GET['nova']) && $telefoneGet !== '' && ($_SESSION['admin_perfil'] ?? '') !== 'supervisor') {
    $telNova = iniciarConversaVendas($telefoneGet, (int)$_SESSION['admin_id'], (string)$_SESSION['admin_perfil']);
    header('Location: /admin/vendas_inbox.php' . ($telNova ? '?telefone=' . urlencode($telNova) : '?erro_tel=1'));
    exit;
}
// perfilVeTudo() (super_admin/supervisor) vê a caixa inteira; vendedor só
// vê conversa de comprador onde ele é responsavel_id em alguma negociação.
$responsavelFiltro = (perfilVeTudo() || !vendasCarteiraRestrita((string)$_SESSION['admin_perfil'])) ? null : (int)$_SESSION['admin_id']; // 06/11/2026: sem fila, todo vendedor vê a caixa inteira

if (isset($_GET['ajax'])) liberarSessao(); // polling só lê sessão, nunca escreve

if (isset($_GET['ajax']) && $_GET['ajax'] === 'novas') {
    header('Content-Type: application/json');
    $depoisDe = (int)($_GET['after_id'] ?? 0);
    if (!$telefoneAtivo || !usuarioPodeVerConversaVendas($telefoneAtivo, $responsavelFiltro)) {
        echo json_encode(['mensagens' => []]);
        exit;
    }
    $novas = buscarMensagensNovasConversa($telefoneAtivo, $depoisDe, 'vendas');
    if ($novas) marcarConversaLida($telefoneAtivo, 'vendas');
    echo json_encode(['mensagens' => $novas]);
    exit;
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'anteriores') {
    header('Content-Type: application/json');
    $antesDe = (int)($_GET['before_id'] ?? 0);
    if (!$telefoneAtivo || !$antesDe || !usuarioPodeVerConversaVendas($telefoneAtivo, $responsavelFiltro)) {
        echo json_encode(['mensagens' => []]);
        exit;
    }
    echo json_encode(['mensagens' => buscarMensagensAntesId($telefoneAtivo, $antesDe, 50, 'vendas')]);
    exit;
}

// 06/11/2026 — cards do 🚘 Estoque (veículos disponíveis + catálogo de fotos).
// ── AJAX: baixar de novo mídia que não ficou salva (09/11/2026, mesmo do inbox de compra) ──
if (isset($_GET['ajax']) && $_GET['ajax'] === 'recuperar_midia') {
    header('Content-Type: application/json');
    $midId = (int)($_GET['id'] ?? 0);
    $stMid = getDB()->prepare("SELECT telefone FROM whatsapp_mensagens WHERE id = ? AND canal = 'vendas'");
    $stMid->execute([$midId]);
    $telMid = (string)$stMid->fetchColumn();
    if (!$telMid || !usuarioPodeVerConversaVendas($telMid, $responsavelFiltro)) {
        echo json_encode(['ok' => false, 'erro' => 'sem acesso']);
        exit;
    }
    @set_time_limit(120); // baixar + salvar no Drive pode passar dos 30s
    $resMid = recuperarMidiaMensagemWhatsapp($midId, !empty($_GET['forcar']));
    echo json_encode(['ok' => $resMid['ok'], 'html' => $resMid['ok'] && $resMid['mensagem'] ? renderizarMidiaVendas($resMid['mensagem']) : '', 'erro' => $resMid['erro']]);
    exit;
}

// ── AJAX: foto de perfil do WhatsApp, ao vivo (09/11/2026 — igual inbox de compra) ──
if (isset($_GET['ajax']) && $_GET['ajax'] === 'foto') {
    header('Content-Type: application/json');
    $telFoto = normalizarTelefone((string)($_GET['telefone'] ?? ''));
    if (!$telFoto || !usuarioPodeVerConversaVendas($telFoto, $responsavelFiltro)) {
        echo json_encode(['foto_url' => '']);
        exit;
    }
    require_once __DIR__ . '/../includes/whatsapp_evolution.php';
    $fotoUrl = fotoContatoCacheada('vendas', $telFoto, function () use ($telFoto) {
        $contato = evolutionConfiguredVendas() ? evolutionBuscarContato($telFoto, evolutionCredenciaisVendas()) : null;
        if (empty($contato['foto_url'])) $contato = zapiBuscarContato($telFoto); // cai pra instância principal
        return $contato['foto_url'] ?? '';
    });
    echo json_encode(['foto_url' => $fotoUrl]);
    exit;
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'estoque') {
    header('Content-Type: application/json');
    echo json_encode(['veiculos' => listarEstoqueParaInboxVendas()]);
    exit;
}

// ── AJAX: ✓✓/lido, apagadas e online do cliente (09/11/2026) ──────────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'status') {
    header('Content-Type: application/json');
    $telSt = normalizarTelefone((string)($_GET['telefone'] ?? ''));
    if (!$telSt || !usuarioPodeVerConversaVendas($telSt, $responsavelFiltro)) {
        echo json_encode(['mensagens' => [], 'presenca' => '']);
        exit;
    }
    echo json_encode(inboxStatusConversa($telSt, 'vendas'));
    exit;
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'conversas') {
    header('Content-Type: application/json');
    echo json_encode([
        'conversas' => listarConversasVendas(trim((string)($_GET['busca'] ?? '')), $responsavelFiltro, 100, ($_GET['filtro'] ?? '') === 'nao_lidas'),
        'total_nao_lidas' => contarConversasNaoLidasVendas($responsavelFiltro),
    ]);
    exit;
}

$erro = '';
$sucesso = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $viaAjax = ($_POST['ajax'] ?? '') === '1';
    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        $erro = 'Sessão expirada, recarregue a página e tente de novo.';
    } elseif ($_SESSION['admin_perfil'] === 'supervisor') {
        if ($viaAjax) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'erro' => 'Perfil de supervisão só acompanha, não envia mensagem.']);
            exit;
        }
        $erro = 'Perfil de supervisão só acompanha, não envia mensagem.';
    } else {
        $acao = (string)($_POST['acao'] ?? '');
        $telPost = preg_replace('/\D/', '', (string)($_POST['telefone'] ?? ''));
        if ($telPost && !usuarioPodeVerConversaVendas($telPost, $responsavelFiltro)) {
            if ($viaAjax) {
                header('Content-Type: application/json');
                echo json_encode(['ok' => false, 'erro' => 'Essa conversa não é de um comprador sob sua responsabilidade.']);
                exit;
            }
            $erro = 'Essa conversa não é de um comprador sob sua responsabilidade.';
            $telPost = '';
        }
        if ($acao === 'enviar_mensagem' && $telPost) {
            $resultado = enviarMensagemManualVendas($telPost, (string)($_POST['texto'] ?? ''), (int)$_SESSION['admin_id']);
            if ($viaAjax) {
                header('Content-Type: application/json');
                echo json_encode($resultado);
                exit;
            }
            if (!$resultado['ok']) $erro = $resultado['erro'];
            $telefoneAtivo = $telPost;
        } elseif ($acao === 'enviar_anexo' && $telPost) {
            // 09/11/2026 — 📎 foto ou documento, igual ao inbox de compra.
            $resultado = enviarAnexoManualVendas($telPost, (string)($_POST['anexo_base64'] ?? ''), (string)($_POST['nome_arquivo'] ?? ''),
                (string)($_POST['legenda'] ?? ''), (int)$_SESSION['admin_id']);
            header('Content-Type: application/json');
            echo json_encode($resultado);
            exit;
        } elseif ($acao === 'encerrar_atendimento' && $telPost) {
            $motivoEnc = clean((string)($_POST['motivo'] ?? ''));
            if ($motivoEnc === '') {
                $erro = 'Motivo é obrigatório pra encerrar o atendimento.';
            } else {
                $resEnc = encerrarAtendimentoVendas($telPost, $motivoEnc, (int)$_SESSION['admin_id']);
                if ($resEnc['ok']) {
                    auditoriaRegistrar('atendimento_encerrado_inbox', (int)$_SESSION['admin_id'], (string)$_SESSION['admin_nome'], 'venda',
                        $resEnc['venda_id'], "Telefone {$telPost} (vendas). Motivo: {$motivoEnc}");
                    $sucesso = 'Atendimento encerrado — a negociação foi pra "❌ Encerradas" em Vendas.';
                } else {
                    $erro = $resEnc['erro'];
                }
            }
            $telefoneAtivo = $telPost;
        } elseif ($acao === 'excluir_conversa' && $telPost) {
            if ($_SESSION['admin_perfil'] !== 'super_admin') {
                $erro = 'Só o super_admin pode excluir uma conversa.';
                $telefoneAtivo = $telPost;
            } else {
                excluirConversaVendas($telPost);
                auditoriaRegistrar('conversa_excluida', (int)$_SESSION['admin_id'], (string)$_SESSION['admin_nome'], 'conversa_whatsapp', null, "Telefone {$telPost} (vendas).");
                $sucesso = 'Conversa excluída.';
                $telefoneAtivo = '';
            }
        } elseif (in_array($acao, ['enviar_foto', 'enviar_estoque', 'enviar_link_estoque'], true) && $telPost) {
            // 06/11/2026 — 📷 foto do aparelho / 🚘 fotos ou link de um carro do estoque.
            $uid = (int)$_SESSION['admin_id'];
            $leg = (string)($_POST['legenda'] ?? '');
            $resultado = match ($acao) {
                'enviar_foto' => enviarFotoManualVendas($telPost, (string)($_POST['foto_base64'] ?? ''), $leg, $uid),
                'enviar_estoque' => enviarFotosEstoqueVendas($telPost, (int)($_POST['oportunidade_id'] ?? 0),
                    array_map('intval', explode(',', (string)($_POST['midias'] ?? ''))), $leg, $uid),
                default => enviarLinkEstoqueVendas($telPost, (int)($_POST['oportunidade_id'] ?? 0), $leg, $uid),
            };
            header('Content-Type: application/json');
            echo json_encode($resultado);
            exit;
        } elseif ($acao === 'enviar_audio' && $telPost) {
            // 09/11/2026 — áudio gravado no navegador, pela instância de vendas.
            $resultado = enviarAudioManualVendas($telPost, (string)($_POST['audio_base64'] ?? ''), (string)($_POST['mime'] ?? ''), (int)$_SESSION['admin_id']);
            header('Content-Type: application/json');
            echo json_encode($resultado);
            exit;
        } elseif ($acao === 'apagar_mensagem' && $telPost) {
            // 09/11/2026 — apaga pra todos (some também do WhatsApp do cliente).
            $resApagar = inboxApagarMensagem((int)($_POST['mensagem_id'] ?? 0), $telPost, 'vendas');
            if ($resApagar['ok']) {
                $sucesso = 'Mensagem apagada pra todos.';
                auditoriaRegistrar('mensagem_whatsapp_apagada', (int)$_SESSION['admin_id'], (string)$_SESSION['admin_nome'], 'conversa_whatsapp', null,
                    "Telefone {$telPost}, mensagem #" . (int)($_POST['mensagem_id'] ?? 0) . ' (vendas).');
            } else {
                $erro = $resApagar['erro'];
            }
            $telefoneAtivo = $telPost;
        } elseif ($acao === 'limpar_conversa' && $telPost) {
            // 09/11/2026 — limpa só do CRM; o cliente continua vendo tudo no WhatsApp.
            $nLimpas = inboxLimparConversa($telPost, 'vendas');
            auditoriaRegistrar('conversa_whatsapp_limpa', (int)$_SESSION['admin_id'], (string)$_SESSION['admin_nome'], 'conversa_whatsapp', null,
                "Telefone {$telPost}, {$nLimpas} mensagem(ns) (vendas).");
            $sucesso = "Conversa limpa no CRM ({$nLimpas} mensagem(ns)). O cliente continua vendo tudo no WhatsApp dele.";
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
        }
    }
}

if ($telefoneAtivo && !usuarioPodeVerConversaVendas($telefoneAtivo, $responsavelFiltro)) {
    $erro = 'Essa conversa não é de um comprador sob sua responsabilidade.';
    $telefoneAtivo = '';
}

if ($telefoneAtivo) {
    marcarConversaLida($telefoneAtivo, 'vendas');
}

$busca = trim((string)($_GET['busca'] ?? ''));
$soNaoLidas = ($_GET['filtro'] ?? '') === 'nao_lidas';
$conversas = listarConversasVendas($busca, $responsavelFiltro, 100, $soNaoLidas);
$totalConversasNaoLidas = contarConversasNaoLidasVendas($responsavelFiltro);
// Conversa aberta que não está na lista filtrada (ex: "Não lidas" ou recém-iniciada):
// busca ela sozinha, senão o painel ficaria vazio.
if ($telefoneAtivo && !array_filter($conversas, fn($c) => $c['telefone'] === $telefoneAtivo)) {
    foreach (listarConversasVendas($telefoneAtivo, $responsavelFiltro, 5) as $cx) if ($cx['telefone'] === $telefoneAtivo) $conversas[] = $cx;
}
$mensagens = $telefoneAtivo ? buscarMensagensConversa($telefoneAtivo, 50, 'vendas') : [];
$ultimoId = $mensagens ? (int)end($mensagens)['id'] : 0;
$primeiroId = $mensagens ? (int)$mensagens[0]['id'] : 0;
$contatoAtivo = null;
foreach ($conversas as $c) {
    if ($c['telefone'] === $telefoneAtivo) { $contatoAtivo = $c; break; }
}
if ($telefoneAtivo && !$contatoAtivo) {
    $db = getDB();
    $stmtV = $db->prepare("SELECT comprador_nome FROM vendas WHERE comprador_telefone = ? ORDER BY id DESC LIMIT 1");
    $stmtV->execute([$telefoneAtivo]);
    $vRow = $stmtV->fetch() ?: [];
    $contatoAtivo = [
        'telefone' => $telefoneAtivo,
        'comprador_nome' => $vRow['comprador_nome'] ?? null,
        'ia_pausada' => iaPausada($telefoneAtivo) ? 1 : 0,
    ];
}
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>WhatsApp Vendas — Fastcar CRM</title>
<link rel="stylesheet" href="/admin/assets/style.css?v=<?= @filemtime(__DIR__ . '/assets/style.css') ?: 1 ?>">
<link rel="stylesheet" href="/admin/assets/mobile.css?v=<?= @filemtime(__DIR__ . '/assets/mobile.css') ?: 1 ?>">
<script src="/admin/assets/mobile.js?v=<?= @filemtime(__DIR__ . '/assets/mobile.js') ?: 1 ?>" defer></script>
<?php include __DIR__ . '/_pwa_head.php'; ?>
<style>
.wpp-wrap { display: flex; height: calc(100vh - 61px); }
.wpp-sidebar { width: 320px; flex-shrink: 0; border-right: 1px solid var(--borda); background: var(--superficie); display: flex; flex-direction: column; }
.wpp-sidebar form { padding: 12px; border-bottom: 1px solid var(--borda); }
.wpp-sidebar form input { margin: 0; }
/* 09/11/2026 — mesma configuração do inbox de compra (filtros, nova conversa, encerrar). */
.wpp-nova-form { display: flex; gap: 8px; }
.wpp-nova-form input { flex: 1; min-width: 0; }
.wpp-nova-form button { margin: 0; white-space: nowrap; padding: 6px 12px; font-size: 12.5px; }
.wpp-filtros { display: flex; gap: 8px; padding: 8px 12px; border-bottom: 1px solid var(--borda); }
.wpp-chip { padding: 6px 14px; border-radius: 999px; background: var(--fundo); color: var(--texto); font-size: 13px; text-decoration: none; min-height: 32px; display: inline-flex; align-items: center; border: 1px solid var(--borda); }
.wpp-chip.ativo { background: #d9fdd3; border-color: #25d366; color: #0b5c2e; font-weight: 700; }
.wpp-item.nao-lida .nome span:first-child { font-weight: 800; }
.wpp-item.nao-lida .preview { color: var(--texto); font-weight: 600; }
.wpp-item.nao-lida .quando { color: #1fa855; font-weight: 700; }
.wpp-item.nao-lida .wpp-badge { background: #25d366; }
.wpp-encerrar-details { width: 100%; order: 99; }
.wpp-encerrar-details summary { cursor: pointer; list-style: none; font-size: 12.5px; color: var(--texto-fraco); padding: 6px 12px; min-height: 44px; display: inline-flex; align-items: center; border: 1px solid var(--borda); border-radius: 6px; background: var(--fundo); }
.wpp-encerrar-details summary::-webkit-details-marker { display: none; }
.wpp-encerrar-details[open] summary { color: #c0392b; border-color: #c0392b; }
.wpp-encerrar-details form { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 8px; }
.wpp-encerrar-details form input[type="text"] { flex: 1; min-width: 220px; margin: 0; }
.wpp-encerrar-details form button { margin: 0; white-space: nowrap; }
.wpp-lista { flex: 1; overflow-y: auto; }
.wpp-item { display: flex; gap: 10px; align-items: center; padding: 12px 16px; border-bottom: 1px solid var(--borda); text-decoration: none; color: inherit; position: relative; }
.wpp-item:hover { background: var(--fundo); text-decoration: none; }
.wpp-item.ativo { background: var(--azul-claro); }
.wpp-item .wpp-corpo { min-width: 0; flex: 1; }
.wpp-avatar-placeholder { width: 38px; height: 38px; border-radius: 50%; flex-shrink: 0; background: var(--azul); color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 15px; }
.wpp-chat-header .wpp-avatar-placeholder { width: 40px; height: 40px; margin-right: 10px; }
.wpp-avatar { width: 38px; height: 38px; border-radius: 50%; flex-shrink: 0; object-fit: cover; cursor: zoom-in; }
.wpp-chat-header .wpp-avatar { width: 40px; height: 40px; margin-right: 10px; }
.wpp-chat-header > div:first-child { display: flex; align-items: center; }
.wpp-item .nome { font-weight: 600; font-size: 13.5px; display: flex; justify-content: space-between; gap: 8px; }
.wpp-item .preview { font-size: 12.5px; color: var(--texto-fraco); margin-top: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.wpp-item .quando { font-size: 11px; color: var(--texto-fraco); white-space: nowrap; }
.wpp-badge { background: var(--azul); color: #fff; border-radius: 999px; font-size: 11px; font-weight: 700; padding: 1px 7px; min-width: 18px; text-align: center; display: inline-block; }
.wpp-chat { flex: 1; display: flex; flex-direction: column; min-width: 0; }
.wpp-chat-header { padding: 14px 20px; border-bottom: 1px solid var(--borda); display: flex; flex-wrap: wrap; gap: 8px; justify-content: space-between; align-items: center; background: var(--superficie); }
.wpp-thread { flex: 1; overflow-y: auto; padding: 20px; display: flex; flex-direction: column; background: var(--fundo); }
.wpp-thread .msg small { display: block; margin-top: 3px; opacity: .65; font-size: 10.5px; }
.wpp-form { padding: 14px 20px; border-top: 1px solid var(--borda); background: var(--superficie); display: flex; gap: 10px; }
.wpp-form textarea { flex: 1; margin: 0; resize: none; min-height: 42px; max-height: 120px; }
.wpp-form button { margin: 0; white-space: nowrap; }
.wpp-vazio { flex: 1; display: flex; align-items: center; justify-content: center; color: var(--texto-fraco); text-align: center; padding: 40px; }
.wpp-voltar-mobile { display: none; }
/* 06/11/2026 — 📷 foto / 🚘 Estoque */
.wpp-midia { display: block; max-width: 240px; max-height: 240px; border-radius: 8px; margin-bottom: 4px; object-fit: cover; height: auto; }
.wpp-form .vi-btn-claro, .vi-mf .vi-btn-claro { background: var(--superficie); color: var(--texto); border: 1px solid var(--borda); box-shadow: none; }
.wpp-form .vi-btn-estoque { background: var(--laranja-bg); color: var(--laranja); border: 1px solid var(--laranja); box-shadow: none; }
.vi-modal { width: min(680px, 96vw); }
.vi-mh { display: flex; align-items: center; gap: 8px; }
.vi-mh h3 { margin: 0; flex: 1; font-size: 16px; }
.vi-mh .btn-texto { font-size: 20px; min-width: 44px; min-height: 44px; }
.vi-chips { display: flex; gap: 6px; margin: 10px 0; flex-wrap: wrap; }
.vi-chip { margin: 0; padding: 6px 12px; font-size: 13px; border-radius: 999px; background: var(--superficie); color: var(--texto); border: 1px solid var(--borda); box-shadow: none; }
.vi-chip.on { background: var(--azul); color: #fff; border-color: var(--azul); }
.vi-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(170px, 1fr)); gap: 12px; max-height: 60vh; overflow-y: auto; }
.vi-card { margin: 0; padding: 0; text-align: left; background: var(--superficie); color: var(--texto); border: 1px solid var(--borda); border-radius: var(--raio-sm); overflow: hidden; box-shadow: none; display: flex; flex-direction: column; }
.vi-card:hover { border-color: var(--azul); }
.vi-capa { position: relative; height: 100px; background: var(--fundo); display: flex; align-items: center; justify-content: center; font-size: 32px; }
.vi-capa img { width: 100%; height: 100%; object-fit: cover; }
.vi-qtd { position: absolute; right: 6px; bottom: 6px; background: rgba(0,0,0,.6); color: #fff; border-radius: 6px; font-size: 11px; padding: 1px 6px; }
.vi-ci { padding: 8px 10px; font-size: 12.5px; display: flex; flex-direction: column; }
.vi-ci span { color: var(--texto-suave); }
.vi-fotos { display: grid; grid-template-columns: repeat(auto-fill, minmax(110px, 1fr)); gap: 8px; margin: 8px 0 12px; max-height: 45vh; overflow-y: auto; }
.vi-ft { position: relative; display: block; height: 90px; border-radius: 8px; overflow: hidden; background: var(--fundo); cursor: pointer; }
.vi-ft img { width: 100%; height: 100%; object-fit: cover; }
.vi-ft input { position: absolute; top: 6px; left: 6px; width: 22px; height: 22px; margin: 0; }
.vi-video { display: flex; height: 100%; align-items: center; justify-content: center; font-size: 28px; }
.vi-mf { display: flex; gap: 8px; justify-content: space-between; flex-wrap: wrap; margin-top: 10px; }
.vi-mf button { margin: 0; }
.vi-dica, .vi-vazio { font-size: 13px; color: var(--texto-suave); }
@media (max-width: 700px) {
    .wpp-form { flex-wrap: wrap; }
    .wpp-form textarea { flex-basis: 100%; }
    .wpp-form .vi-txt { display: none; }
    .vi-grid { grid-template-columns: 1fr 1fr; }
}
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

<?php if (isset($_GET['erro_tel'])) $erro = 'Telefone inválido — use DDD + número.'; ?>
<?php if ($erro): ?><div class="alerta-erro" style="margin:12px 20px"><?= e($erro) ?></div><?php endif; ?>
<?php if ($sucesso): ?><div class="alerta-sucesso" style="margin:12px 20px"><?= e($sucesso) ?></div><?php endif; ?>

<div class="wpp-wrap">
    <aside class="wpp-sidebar">
        <form method="get">
            <input type="search" name="busca" value="<?= e($busca) ?>" placeholder="🔎 Buscar por nome ou telefone..." autocomplete="off">
            <?php if ($soNaoLidas): ?><input type="hidden" name="filtro" value="nao_lidas"><?php endif; ?>
        </form>
        <div class="wpp-filtros">
            <a href="?<?= e(http_build_query(array_filter(['busca' => $busca]))) ?>" class="wpp-chip <?= $soNaoLidas ? '' : 'ativo' ?>">Todas</a>
            <a href="?<?= e(http_build_query(array_filter(['busca' => $busca, 'filtro' => 'nao_lidas']))) ?>" class="wpp-chip <?= $soNaoLidas ? 'ativo' : '' ?>">Não lidas&nbsp;<span id="wpp-total-nao-lidas"><?= $totalConversasNaoLidas ? '(' . $totalConversasNaoLidas . ')' : '' ?></span></a>
        </div>
        <?php if ($_SESSION['admin_perfil'] !== 'supervisor'): ?>
        <form method="get" class="wpp-nova-form">
            <input type="hidden" name="nova" value="1">
            <input type="tel" name="telefone" placeholder="Nova conversa — telefone com DDD" inputmode="numeric" required>
            <button type="submit">+ Iniciar conversa</button>
        </form>
        <?php endif; ?>
        <div class="wpp-lista" id="wpp-lista">
            <?php if (!$conversas): ?>
                <p style="padding:16px;color:var(--texto-fraco);font-size:13px">
                    <?= $soNaoLidas ? 'Nenhuma conversa não lida. 🎉' : ($busca ? 'Nenhuma conversa encontrada.' : 'Nenhuma conversa ainda — mensagens aparecem aqui assim que um comprador escrever pra instância de vendas.') ?>
                </p>
            <?php endif; ?>
            <?php foreach ($conversas as $c): ?>
                <a class="wpp-item <?= $c['telefone'] === $telefoneAtivo ? 'ativo' : '' ?><?= (int)$c['nao_lidas'] > 0 ? ' nao-lida' : '' ?>" href="?<?= e(http_build_query(array_filter(['telefone' => $c['telefone'], 'filtro' => $soNaoLidas ? 'nao_lidas' : null]))) ?>">
                    <div class="wpp-avatar-placeholder" data-av-phone="<?= e($c['telefone']) ?>"><?= e(mb_strtoupper(mb_substr($c['comprador_nome'] ?: $c['telefone'], 0, 1))) ?></div>
                    <div class="wpp-corpo">
                        <div class="nome">
                            <span><?= e($c['comprador_nome'] ?: $c['telefone']) ?></span>
                            <?php if ((int)$c['nao_lidas'] > 0): ?><span class="wpp-badge"><?= (int)$c['nao_lidas'] ?></span><?php endif; ?>
                        </div>
                        <div class="preview">
                            <?= $c['ultima_direcao'] === 'out' ? '✓ ' : '' ?>
                            <?= wppPreviewHtml((string)$c['ultima_tipo'], (string)$c['ultima_mensagem']) ?>
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
                    <div class="wpp-avatar-placeholder" data-av-phone="<?= e($telefoneAtivo) ?>"><?= e(mb_strtoupper(mb_substr($contatoAtivo['comprador_nome'] ?: $telefoneAtivo, 0, 1))) ?></div>
                    <div>
                        <a href="?" class="wpp-voltar-mobile" style="color:inherit">← Conversas</a>
                        <strong><?= e($contatoAtivo['comprador_nome'] ?: $telefoneAtivo) ?></strong>
                        <div style="font-size:12px;color:var(--texto-fraco)"><?= e($telefoneAtivo) ?> <span id="wpp-presenca"></span></div>
                    </div>
                </div>
                <div style="display:flex;flex-wrap:wrap;gap:8px">
                    <?php if ($_SESSION['admin_perfil'] !== 'supervisor'): ?>
                        <form method="post" class="inline">
                            <?= csrfField() ?>
                            <input type="hidden" name="acao" value="toggle_ia">
                            <input type="hidden" name="telefone" value="<?= e($telefoneAtivo) ?>">
                            <button type="submit" style="margin-top:0;padding:6px 12px;font-size:12.5px">
                                <?= $contatoAtivo['ia_pausada'] ? '▶️ Reativar IA' : '⏸️ Pausar IA' ?>
                            </button>
                        </form>
                        <form method="post" class="inline" onsubmit="return confirmarAcao(this, 'Limpar essa conversa do CRM? Some só da tela do sistema — o cliente continua vendo tudo no WhatsApp dele. A IA também perde o histórico dessa conversa.');">
                            <?= csrfField() ?>
                            <input type="hidden" name="acao" value="limpar_conversa">
                            <input type="hidden" name="telefone" value="<?= e($telefoneAtivo) ?>">
                            <button type="submit" class="perigo" style="margin-top:0;padding:6px 12px;font-size:12.5px">🧹 Limpar conversa</button>
                        </form>
                    <?php endif; ?>
                    <?php if ($_SESSION['admin_perfil'] === 'super_admin'): ?>
                        <form method="post" class="inline" onsubmit="return confirmarAcao(this, 'Apagar essa conversa inteira? Não tem como desfazer.');">
                            <?= csrfField() ?>
                            <input type="hidden" name="acao" value="excluir_conversa">
                            <input type="hidden" name="telefone" value="<?= e($telefoneAtivo) ?>">
                            <button type="submit" class="perigo" style="margin-top:0;padding:6px 12px;font-size:12.5px">🗑️ Excluir conversa</button>
                        </form>
                    <?php endif; ?>
                </div>
                <?php if ($_SESSION['admin_perfil'] !== 'supervisor'): ?>
                    <details class="wpp-encerrar-details">
                        <summary>🔒 Encerrar atendimento</summary>
                        <form method="post" onsubmit="return confirmarAcao(this, 'Encerrar o atendimento? A negociação vai pra ❌ Encerradas em Vendas — pode reabrir depois se precisar.');">
                            <?= csrfField() ?>
                            <input type="hidden" name="acao" value="encerrar_atendimento">
                            <input type="hidden" name="telefone" value="<?= e($telefoneAtivo) ?>">
                            <input type="text" name="motivo" required placeholder="Motivo (obrigatório): desistiu, comprou em outro lugar, sem perfil...">
                            <button type="submit" class="perigo">Confirmar encerramento</button>
                        </form>
                    </details>
                <?php endif; ?>
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
                        <?= renderizarMidiaVendas($m) ?>
                        <?= nl2br(e($m['mensagem'])) ?>
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
                    <button type="button" id="vi-btn-foto" title="Anexar arquivo (foto ou documento)" aria-label="Anexar arquivo" style="padding:0 12px"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg></button>
                    <input type="file" id="vi-input-foto" accept="image/*,.pdf,.doc,.docx,.xls,.xlsx,.txt,.csv" hidden>
                    <button type="button" id="vi-btn-estoque" class="vi-btn-estoque" title="Mandar fotos ou link de um carro do estoque">🚘<span class="vi-txt"> Estoque</span></button>
                    <button type="button" id="vi-btn-audio" title="Gravar áudio" aria-label="Gravar áudio" style="padding:0 12px"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="2" width="6" height="12" rx="3"/><path d="M19 10v1a7 7 0 0 1-14 0v-1"/><line x1="12" y1="18" x2="12" y2="22"/></svg></button>
                    <button type="button" id="vi-btn-audio-cancelar" title="Cancelar gravação" style="padding:0 12px;display:none;color:#c0392b">✕</button>
                    <button type="submit">Enviar</button>
                </form>
                <p id="vi-audio-status" style="display:none;font-size:12.5px;color:var(--texto-fraco);margin:4px 0 0"></p>
            <?php endif; ?>
        <?php endif; ?>
    </section>
</div>

<?php if ($telefoneAtivo && $contatoAtivo && $_SESSION['admin_perfil'] !== 'supervisor'): ?>
<dialog class="modal-lancamento vi-modal" id="vi-modal" aria-labelledby="vi-modal-titulo">
    <div class="vi-mh">
        <button type="button" class="btn-texto" id="vi-voltar" hidden>←</button>
        <h3 id="vi-modal-titulo">🚘 Mandar carro do estoque</h3>
        <button type="button" class="btn-texto vi-fechar" id="vi-fechar" aria-label="Fechar">✕</button>
    </div>
    <div id="vi-lista">
        <input type="search" id="vi-busca" placeholder="🔎 Buscar modelo ou placa…">
        <div class="vi-chips" id="vi-chips">
            <button type="button" class="vi-chip on" data-f="">Todos</button>
            <button type="button" class="vi-chip" data-f="carro">🚗 Carros</button>
            <button type="button" class="vi-chip" data-f="moto">🏍️ Motos</button>
        </div>
        <div class="vi-grid" id="vi-grid"><p class="vi-vazio">Carregando…</p></div>
    </div>
    <div id="vi-detalhe" hidden>
        <p class="vi-dica">Marque as fotos que vão pro cliente (no máximo 10 por vez).</p>
        <div class="vi-fotos" id="vi-fotos"></div>
        <label for="vi-legenda">Legenda (vai na 1ª foto)</label>
        <input type="text" id="vi-legenda" maxlength="200">
        <div class="vi-mf">
            <button type="button" class="vi-btn-claro" id="vi-link">🔗 Mandar link do anúncio</button>
            <button type="button" id="vi-enviar">📤 Enviar fotos</button>
        </div>
        <p class="vi-dica">O link abre a página do carro com todas as fotos e já aparece no WhatsApp com a foto de capa.</p>
    </div>
</dialog>
<?php endif; ?>

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
        if (m.enviado_por_ia == 1) rodape += ' · 🤖 IA';
        if (m.usuario_nome) rodape += ' · 👤 ' + m.usuario_nome;
        var midia = renderizarMidiaMsg(m);
        div.innerHTML = midia + escapeHtml(m.mensagem).replace(/\n/g, '<br>') + '<small>' + escapeHtml(rodape) + '</small>';
        return div;
    }

    // Foto/áudio/vídeo da conversa (09/11/2026) — mesma lógica do inbox de compra.
    function tipoMidiaMsg(m) {
        if (!m.drive_file_id && !m.arquivo_url) return null;
        if (['audio','image','video','document'].indexOf(m.tipo) >= 0) return m.tipo;
        var t = m.mensagem || '';
        if (t.indexOf('🎤') === 0) return 'audio';
        if (t.indexOf('🎥') === 0) return 'video';
        if (t.indexOf('📷') === 0) return 'image';
        if (t.indexOf('📎') === 0) return 'document';
        return null;
    }
    function renderizarMidiaMsg(m) {
        var midia = tipoMidiaMsg(m);
        if (!midia) {
            var falta = m.direcao === 'in' && m.zapi_message_id && (['audio','image','video','document'].indexOf(m.tipo) >= 0 || /^(📷|🎤|🎥)/.test(m.mensagem || ''));
            return falta ? '<div class="midia-faltando" data-mid="' + m.id + '" style="margin-top:6px;font-size:.8rem;color:var(--texto-fraco)">🔄 carregando mídia…</div>' : '';
        }
        var u = '/admin/ver_midia_vendas.php?id=' + encodeURIComponent(m.id);
        if (midia === 'audio') return '<audio controls preload="none" src="' + u + '" style="max-width:260px;display:block;margin-top:6px"></audio>';
        if (midia === 'video') return '<video class="wpp-midia" controls preload="none" src="' + u + '"></video>';
        if (midia === 'document') return '<a href="' + u + '" target="_blank" rel="noopener">📎 ' + escapeHtml((m.mensagem || '').replace(/^📎\s*/, '')) + '</a>';
        return '<a href="' + u + '" target="_blank" rel="noopener"><img class="wpp-midia" loading="lazy" width="220" height="165" src="' + u + '" alt=""></a>';
    }
    var midiaTentadas = {}, midiaFila = [], midiaRodando = false;
    function recuperarMidias() {
        document.querySelectorAll('.midia-faltando[data-mid]').forEach(function (el) {
            var id = el.dataset.mid;
            if (midiaTentadas[id]) return;
            midiaTentadas[id] = true;
            midiaFila.push(el);
        });
        if (midiaRodando) return;
        (function proxima() {
            var el = midiaFila.shift();
            if (!el) { midiaRodando = false; return; }
            midiaRodando = true;
            var forcar = el.dataset.forcar === '1';
            el.textContent = '🔄 carregando mídia…';
            fetch('?ajax=recuperar_midia&id=' + encodeURIComponent(el.dataset.mid) + (forcar ? '&forcar=1' : ''))
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (d.ok && d.html) { el.outerHTML = d.html; return; }
                    midiaFalhou(el, d.erro || 'o WhatsApp não liberou o arquivo');
                })
                .catch(function () { midiaFalhou(el, 'demorou demais pra responder'); })
                .then(proxima);
        })();
    }
    // 09/11/2026 ("dá pra carregar novamente?") — mostra o motivo real e um
    // botão pra tentar de novo na hora, em vez de só "peça pra reenviar".
    function midiaFalhou(el, motivo) {
        el.innerHTML = '';
        el.appendChild(document.createTextNode('⚠️ Mídia não carregou (' + motivo + '). '));
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'btn-texto';
        b.textContent = '🔄 Tentar de novo';
        b.style.marginLeft = '4px';
        b.addEventListener('click', function () {
            el.dataset.forcar = '1';
            delete midiaTentadas[el.dataset.mid];
            recuperarMidias();
        });
        el.appendChild(b);
        el.appendChild(document.createTextNode(' Se não voltar, peça pro cliente reenviar.'));
    }
    setTimeout(recuperarMidias, 300);
    setInterval(recuperarMidias, 4000);

    // Foto de perfil do WhatsApp (09/11/2026) — busca ao vivo, troca o círculo
    // de iniciais só depois que a imagem carregou; cache por telefone pra o
    // polling da lista não fazer a foto piscar (mesmo padrão do inbox de compra).
    var fotoCache = {}, fotoBuscando = {};
    function _aplicarFoto(el, fotoUrl) {
        if (!fotoUrl) return;
        var phone = el.getAttribute('data-av-phone');
        if (phone) fotoCache[phone] = fotoUrl;
        var img = document.createElement('img');
        img.className = 'wpp-avatar';
        img.alt = '';
        img.setAttribute('data-av-phone', phone || '');
        img.onload = function () { if (el.isConnected) el.replaceWith(img); };
        img.src = fotoUrl;
    }
    function carregarFotos() {
        var avs = Array.from(document.querySelectorAll('div.wpp-avatar-placeholder[data-av-phone]')).slice(0, 31);
        avs.forEach(function (el, i) {
            var phone = el.getAttribute('data-av-phone');
            if (!phone) return;
            if (fotoCache[phone]) { _aplicarFoto(el, fotoCache[phone]); return; }
            if (fotoBuscando[phone]) return;
            fotoBuscando[phone] = true;
            setTimeout(function () {
                fetch('?ajax=foto&telefone=' + encodeURIComponent(phone))
                    .then(function (r) { return r.json(); })
                    .then(function (d) {
                        if (!d.foto_url) return;
                        fotoCache[phone] = d.foto_url;
                        document.querySelectorAll('div.wpp-avatar-placeholder[data-av-phone="' + phone + '"]').forEach(function (p) { _aplicarFoto(p, d.foto_url); });
                    })
                    .catch(function () {});
            }, 150 + Math.floor(i / 5) * 600 + (i % 5) * 80);
        });
    }
    document.addEventListener('click', function (ev) {
        var img = ev.target.closest('img.wpp-avatar');
        if (!img) return;
        ev.preventDefault(); ev.stopPropagation();
        window.open(img.src, '_blank', 'noopener');
    }, true);
    setTimeout(carregarFotos, 100);

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
        fetch('?ajax=conversas&busca=' + encodeURIComponent(params.get('busca') || '') + '&filtro=' + encodeURIComponent(params.get('filtro') || ''))
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var lista = document.getElementById('wpp-lista');
                if (!lista || !data.conversas) return;
                var spanTotal = document.getElementById('wpp-total-nao-lidas');
                if (spanTotal) spanTotal.textContent = data.total_nao_lidas ? '(' + data.total_nao_lidas + ')' : '';
                lista.innerHTML = '';
                if (!data.conversas.length) {
                    var vazio = document.createElement('p');
                    vazio.style.cssText = 'padding:16px;color:var(--texto-fraco);font-size:13px';
                    vazio.textContent = params.get('filtro') === 'nao_lidas' ? 'Nenhuma conversa não lida. 🎉' : 'Nenhuma conversa ainda.';
                    lista.appendChild(vazio);
                    return;
                }
                data.conversas.forEach(function (c) {
                    var a = document.createElement('a');
                    a.className = 'wpp-item' + (c.telefone === telefone ? ' ativo' : '') + (c.nao_lidas > 0 ? ' nao-lida' : '');
                    a.href = '?telefone=' + encodeURIComponent(c.telefone) + (params.get('filtro') === 'nao_lidas' ? '&filtro=nao_lidas' : '');
                    var quando = new Date(c.ultima_em.replace(' ', 'T')).toLocaleString('pt-BR', {day:'2-digit',month:'2-digit',hour:'2-digit',minute:'2-digit'});
                    var inicial = escapeHtml((c.comprador_nome || c.telefone).slice(0, 1).toUpperCase());
                    var fotoC = fotoCache[c.telefone];
                    var avatar = fotoC
                        ? '<img class="wpp-avatar" src="' + escapeHtml(fotoC) + '" alt="" data-av-phone="' + escapeHtml(c.telefone) + '">'
                        : '<div class="wpp-avatar-placeholder" data-av-phone="' + escapeHtml(c.telefone) + '">' + inicial + '</div>';
                    a.innerHTML = avatar +
                        '<div class="wpp-corpo">' +
                        '<div class="nome"><span>' + escapeHtml(c.comprador_nome || c.telefone) + '</span>' +
                        (c.nao_lidas > 0 ? '<span class="wpp-badge">' + c.nao_lidas + '</span>' : '') + '</div>' +
                        '<div class="preview">' + (c.ultima_direcao === 'out' ? '✓ ' : '') + wppPreviewHtml(c.ultima_tipo, c.ultima_mensagem) + '</div>' +
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
            var btn = form.querySelector('button[type="submit"]');
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

    // ── 06/11/2026 — 📷 foto do aparelho e 🚘 carros do estoque ──────────
    function postar(campos, btn, rotuloOk) {
        var body = new URLSearchParams();
        body.set('csrf_token', csrf.value);
        body.set('telefone', telefone);
        body.set('ajax', '1');
        Object.keys(campos).forEach(function (k) { body.set(k, campos[k]); });
        var rot = btn ? btn.innerHTML : ''; // innerHTML: o 📎 é um ícone SVG
        if (btn) { btn.disabled = true; btn.textContent = 'Enviando…'; }
        return fetch('', { method: 'POST', body: body })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (btn) { btn.disabled = false; btn.innerHTML = rot; }
                if (!d.ok) { alert(d.erro || 'Falha ao enviar.'); return false; }
                if (d.falhas) alert(d.enviados + ' enviada(s); ' + d.falhas + ' não saiu(íram).');
                return true;
            })
            .catch(function () { if (btn) { btn.disabled = false; btn.innerHTML = rot; } alert('Falha ao enviar — confira sua conexão.'); return false; });
    }

    // 09/11/2026, "adicionar áudio para enviar no inbox da venda" — mesmo
    // gravador do WhatsApp Box de compra; envia pela instância de vendas.
    var ICONE_MIC = '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="2" width="6" height="12" rx="3"/><path d="M19 10v1a7 7 0 0 1-14 0v-1"/><line x1="12" y1="18" x2="12" y2="22"/></svg>';
    var ICONE_PARAR = '<svg width="20" height="20" viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="5" width="14" height="14" rx="2" fill="#fff"/></svg>';
    var btnAudio = document.getElementById('vi-btn-audio');
    var btnAudioCancelar = document.getElementById('vi-btn-audio-cancelar');
    var statusAudio = document.getElementById('vi-audio-status');
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
            btnAudio.innerHTML = ICONE_MIC;
            btnAudio.title = 'Gravar áudio';
            btnAudio.disabled = false;
            btnAudioCancelar.style.display = 'none';
            statusAudio.style.display = 'none';
        }

        function enviarAudioGravado(blob, mime) {
            // 29/10/2026, "gravo e depois demora 20 segundos" — a bolha com o
            // áudio aparece NA HORA (tocando do próprio navegador) com
            // "⏳ enviando…"; vira "✓ enviado" quando o servidor confirma.
            // O consultor já pode gravar/escrever a próxima sem esperar.
            var bolha = document.createElement('div');
            bolha.className = 'msg msg-out';
            bolha.innerHTML = '🎤 Áudio<audio controls preload="metadata" style="max-width:260px;display:block;margin-top:6px"></audio><small>⏳ enviando…</small>';
            bolha.querySelector('audio').src = URL.createObjectURL(blob);
            if (thread) { thread.appendChild(bolha); thread.scrollTop = thread.scrollHeight; }
            var rodapeBolha = bolha.querySelector('small');
            voltarAoEstadoInicial();
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
                        if (!data.ok) {
                            rodapeBolha.textContent = '❌ não enviado';
                            alert(data.erro || 'Falha ao enviar áudio.');
                            return;
                        }
                        // O polling pode ter desenhado a mesma mensagem (ainda
                        // sem o arquivo) no meio do caminho — fica só a nossa.
                        if (data.id && thread) {
                            thread.querySelectorAll('[data-id="' + data.id + '"]').forEach(function (el) { if (el !== bolha) el.remove(); });
                            bolha.dataset.id = data.id;
                        }
                        rodapeBolha.textContent = '✓ enviado';
                    })
                    .catch(function () {
                        rodapeBolha.textContent = '❌ não enviado';
                        alert('Falha ao enviar áudio — confira sua conexão.');
                    });
            };
            leitor.onerror = function () {
                rodapeBolha.textContent = '❌ não enviado';
                alert('Não consegui processar o áudio gravado.');
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
                btnAudio.innerHTML = ICONE_PARAR;
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

    var btnFoto = document.getElementById('vi-btn-foto');
    var inputFoto = document.getElementById('vi-input-foto');
    if (btnFoto && inputFoto) {
        btnFoto.addEventListener('click', function () { inputFoto.click(); });
        inputFoto.addEventListener('change', function () {
            var f = inputFoto.files && inputFoto.files[0];
            inputFoto.value = '';
            if (!f) return;
            if (f.size > 20 * 1024 * 1024) { alert('Arquivo maior que 20MB.'); return; }
            var leitor = new FileReader();
            leitor.onload = function () {
                var b64 = String(leitor.result).split(',')[1] || '';
                var leg = form.querySelector('textarea').value.trim();
                postar({ acao: 'enviar_anexo', anexo_base64: b64, nome_arquivo: f.name, legenda: leg }, btnFoto).then(function (ok) {
                    if (ok) form.querySelector('textarea').value = '';
                });
            };
            leitor.readAsDataURL(f);
        });
    }

    var modal = document.getElementById('vi-modal');
    var btnEstoque = document.getElementById('vi-btn-estoque');
    if (modal && btnEstoque) {
        var grid = document.getElementById('vi-grid'), busca = document.getElementById('vi-busca');
        var lista = document.getElementById('vi-lista'), detalhe = document.getElementById('vi-detalhe');
        var fotosBox = document.getElementById('vi-fotos'), legenda = document.getElementById('vi-legenda');
        var titulo = document.getElementById('vi-modal-titulo'), voltar = document.getElementById('vi-voltar');
        var veiculos = null, filtro = '', atual = null;

        function urlMidia(id) { return '/admin/ver_midia_revenda.php?id=' + id; }
        function nomeV(v) { return v.titulo + (v.ano ? ' ' + v.ano : ''); }
        function desenharLista() {
            var q = (busca.value || '').toLowerCase().replace(/[-\s]/g, '');
            var itens = (veiculos || []).filter(function (v) {
                if (filtro === 'moto' && !v.moto) return false;
                if (filtro === 'carro' && v.moto) return false;
                if (!q) return true;
                return (v.titulo + v.ano + v.placa).toLowerCase().replace(/[-\s]/g, '').indexOf(q) >= 0;
            });
            if (!itens.length) { grid.innerHTML = '<p class="vi-vazio">Nenhum veículo disponível' + (q ? ' pra essa busca' : '') + '.</p>'; return; }
            grid.innerHTML = '';
            itens.forEach(function (v) {
                var b = document.createElement('button');
                b.type = 'button'; b.className = 'vi-card';
                var qtd = v.midias.length;
                b.innerHTML = '<span class="vi-capa">' + (v.capa ? '<img loading="lazy" src="' + urlMidia(v.capa) + '" alt="">' : (v.moto ? '🏍️' : '🚗')) +
                    '<span class="vi-qtd">' + (qtd ? '📷 ' + qtd : 'sem foto') + '</span></span>' +
                    '<span class="vi-ci"><b>' + escapeHtml(v.titulo) + '</b><span>' + escapeHtml([v.ano, v.placa].filter(Boolean).join(' · ')) + '</span></span>';
                b.addEventListener('click', function () { abrirVeiculo(v); });
                grid.appendChild(b);
            });
        }
        function abrirVeiculo(v) {
            atual = v;
            lista.hidden = true; detalhe.hidden = false; voltar.hidden = false;
            titulo.textContent = nomeV(v);
            legenda.value = nomeV(v);
            fotosBox.innerHTML = v.midias.length ? '' : '<p class="vi-vazio">Esse veículo ainda não tem fotos no catálogo — mande o link do anúncio ou cadastre fotos em 🚘 Estoque.</p>';
            v.midias.forEach(function (m, i) {
                var l = document.createElement('label');
                l.className = 'vi-ft';
                l.innerHTML = '<input type="checkbox" value="' + m.id + '"' + (i < 3 ? ' checked' : '') + '>' +
                    (m.tipo === 'video' ? '<span class="vi-video">🎥</span>' : '<img loading="lazy" src="' + urlMidia(m.id) + '" alt="">');
                fotosBox.appendChild(l);
            });
            document.getElementById('vi-enviar').hidden = !v.midias.length;
        }
        function voltarLista() { atual = null; lista.hidden = false; detalhe.hidden = true; voltar.hidden = true; titulo.textContent = '🚘 Mandar carro do estoque'; }

        btnEstoque.addEventListener('click', function () {
            voltarLista();
            if (typeof modal.showModal === 'function') modal.showModal(); else modal.setAttribute('open', '');
            if (veiculos) { desenharLista(); return; }
            fetch('?ajax=estoque').then(function (r) { return r.json(); }).then(function (d) { veiculos = d.veiculos || []; desenharLista(); })
                .catch(function () { grid.innerHTML = '<p class="vi-vazio">Não deu pra carregar o estoque.</p>'; });
        });
        document.getElementById('vi-fechar').addEventListener('click', function () { modal.close(); });
        voltar.addEventListener('click', voltarLista);
        busca.addEventListener('input', desenharLista);
        document.querySelectorAll('#vi-chips .vi-chip').forEach(function (c) {
            c.addEventListener('click', function () {
                document.querySelectorAll('#vi-chips .vi-chip').forEach(function (o) { o.classList.toggle('on', o === c); });
                filtro = c.dataset.f; desenharLista();
            });
        });
        document.getElementById('vi-enviar').addEventListener('click', function () {
            var ids = Array.prototype.map.call(fotosBox.querySelectorAll('input:checked'), function (i) { return i.value; });
            if (!ids.length) { alert('Marque pelo menos uma foto.'); return; }
            if (ids.length > 10) { alert('No máximo 10 por vez.'); return; }
            postar({ acao: 'enviar_estoque', oportunidade_id: atual.id, midias: ids.join(','), legenda: legenda.value }, this)
                .then(function (ok) { if (ok) modal.close(); });
        });
        document.getElementById('vi-link').addEventListener('click', function () {
            postar({ acao: 'enviar_link_estoque', oportunidade_id: atual.id, legenda: legenda.value }, this)
                .then(function (ok) { if (ok) modal.close(); });
        });
    }
})();
</script>

<?php include __DIR__ . '/_pwa_register.php'; ?>
<?php $notifModulo = 'vendas'; include __DIR__ . '/_notify.php'; ?>
<?php include __DIR__ . '/_scroll_restore.php'; ?>
<?php include __DIR__ . '/_acao_popup.php'; ?>
<?php include __DIR__ . '/_confirm_dialog.php'; ?>
<?php $inboxPodeAgir = ($_SESSION['admin_perfil'] ?? '') !== 'supervisor'; include __DIR__ . '/_inbox_extras.php'; ?>
<?php include __DIR__ . '/_zapi_status_vendas.php'; ?>
</body>
</html>
