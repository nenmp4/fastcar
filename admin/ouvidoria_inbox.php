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
    foreach ($msgs as &$m) {
        $m['midia_html'] = renderizarMidiaWhatsapp($m);
        $m['pode_apagar'] = inboxPodeApagarMensagem($m);
        unset($m['zapi_message_id']);
    }
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
    $lst = listarConversasOuvidoria(trim((string)($_GET['busca'] ?? '')), (string)($_GET['assunto'] ?? ''), 100,
        (string)($_GET['status'] ?? ''), (string)($_GET['ordem'] ?? ''), (string)($_GET['aba'] ?? ''));
    foreach ($lst as &$cv) $cv['cor'] = abs(crc32((string)$cv['telefone'])) % 6; // mesma cor do avatar desenhado pelo PHP
    unset($cv);
    echo json_encode(['conversas' => $lst]);
    exit;
}

// Foto de perfil (21/11/2026, "arruma os avatares") — busca ao vivo pela instância
// da OUVIDORIA (nunca a principal), cache em disco igual ao inbox de compra.
if (isset($_GET['ajax']) && $_GET['ajax'] === 'foto') {
    header('Content-Type: application/json');
    $telFoto = normalizarTelefone((string)($_GET['telefone'] ?? ''));
    $url = '';
    if ($telFoto && evolutionConfiguredOuvidoria()) {
        $url = fotoContatoCacheada('ouvidoria', $telFoto, function () use ($telFoto) {
            return evolutionBuscarContato($telFoto, evolutionCredenciaisOuvidoria())['foto_url'] ?? '';
        });
    }
    echo json_encode(['foto_url' => $url]);
    exit;
}

/** Círculo de iniciais (a foto real entra por cima via JS quando existir). Cor fixa por telefone. */
function _ouvAvatar(string $tel, string $nome): string {
    $base = $nome !== '' ? $nome : $tel;
    $cor = abs(crc32($tel)) % 6;
    return '<div class="wpp-avatar-placeholder av-c' . $cor . '" data-av-phone="' . e($tel) . '" data-nome="' . e($base) . '">'
        . e(mb_strtoupper(mb_substr($base, 0, 1))) . '</div>';
}

/** "há 3h" / "há 2d" — tempo esperando resposta. */
function _ouvEspera(?string $desde): string {
    if (!$desde) return '';
    $min = max(0, (int)floor((time() - strtotime($desde)) / 60));
    return $min < 60 ? "{$min}min" : ($min < 1440 ? floor($min / 60) . 'h' : floor($min / 1440) . 'd');
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
                getDB()->prepare("UPDATE ouvidoria_tickets SET venda_id = ?, oportunidade_id = ?, updated_at = datetime('now','localtime') WHERE telefone = ? AND status != 'resolvido'")
                    ->execute([$tipoV === 'venda' ? $idV : null, $tipoV === 'compra' ? $idV : null, $telPost]);
                auditoriaRegistrar('ouvidoria_vinculada', (int)$_SESSION['admin_id'], (string)$_SESSION['admin_nome'], $tipoV === 'venda' ? 'venda' : 'oportunidade', $idV, "Telefone {$telPost} da ouvidoria vinculado.");
                $sucesso = 'Conversa vinculada ao ' . ($tipoV === 'venda' ? 'contrato de venda' : 'negócio de compra') . " #{$idV}.";
            } else {
                $erro = 'Negócio não encontrado.';
            }
            $telefoneAtivo = $telPost;
        } elseif ($acao === 'desvincular_negocio' && $telPost) {
            ouvidoriaSalvarConversa($telPost, ['venda_id' => null, 'oportunidade_id' => null]);
            auditoriaRegistrar('ouvidoria_desvinculada', (int)$_SESSION['admin_id'], (string)$_SESSION['admin_nome'], 'ouvidoria', null, "Vínculo do telefone {$telPost} com venda/compra removido.");
            $sucesso = 'Vínculo removido.';
            $telefoneAtivo = $telPost;
        } elseif ($acao === 'ia_ouvidoria' && $telPost) {
            $pausar = ($_POST['pausar'] ?? '') === '1';
            ouvidoriaSalvarConversa($telPost, ['ia_pausada' => $pausar ? 1 : 0]);
            auditoriaRegistrar($pausar ? 'ouvidoria_ia_pausada' : 'ouvidoria_ia_reativada', (int)$_SESSION['admin_id'], (string)$_SESSION['admin_nome'], 'ouvidoria', null, "Conversa {$telPost}.");
            $sucesso = $pausar ? 'IA pausada nesta conversa — só a equipe responde.' : 'IA reativada nesta conversa.';
            $telefoneAtivo = $telPost;
        } elseif ($acao === 'assunto_ouvidoria' && $telPost) {
            $novo = (string)($_POST['assunto'] ?? '');
            if (isset(OUVIDORIA_ASSUNTOS[$novo])) {
                $antigo = (string)(ouvidoriaConversa($telPost)['assunto'] ?? '');
                ouvidoriaSalvarConversa($telPost, ['assunto' => $novo]);
                auditoriaRegistrar('ouvidoria_assunto_alterado', (int)$_SESSION['admin_id'], (string)$_SESSION['admin_nome'], 'ouvidoria', null,
                    "Conversa {$telPost}: " . (OUVIDORIA_ASSUNTOS[$antigo] ?? ($antigo ?: '—')) . ' → ' . OUVIDORIA_ASSUNTOS[$novo] . '.');
                $sucesso = 'Assunto alterado para ' . OUVIDORIA_ASSUNTOS[$novo] . '.';
            }
            $telefoneAtivo = $telPost;
        } elseif ($acao === 'importar_historico') {
            $diasImp = (int)($_POST['dias'] ?? 90);
            if (!in_array($diasImp, [30, 90, 365, 0], true)) $diasImp = 90;
            $r = ouvidoriaImportarHistorico($diasImp);
            if ($r['ok']) {
                auditoriaRegistrar('ouvidoria_historico_importado', (int)$_SESSION['admin_id'], (string)$_SESSION['admin_nome'], '', null,
                    "{$r['mensagens']} mensagem(ns) de {$r['conversas']} conversa(s) importadas da instância da ouvidoria.");
                $sucesso = "Histórico importado: {$r['mensagens']} mensagem(ns) em {$r['conversas']} conversa(s)"
                    . ($r['falhas'] ? " ({$r['falhas']} conversa(s) não vieram — tente de novo depois)" : '') . '.';
            } else {
                $erro = $r['erro'];
            }
        } elseif ($acao === 'ticket_notificar_jean') {
            $r = ouvidoriaNotificarJean((int)($_POST['ticket_id'] ?? 0), (string)($_POST['nota'] ?? ''), (int)$_SESSION['admin_id']);
            if ($r['ok']) {
                auditoriaRegistrar('ouvidoria_ticket_jean', (int)$_SESSION['admin_id'], (string)$_SESSION['admin_nome'], 'ouvidoria_ticket', (int)($_POST['ticket_id'] ?? 0), "Resumo do ticket {$r['protocolo']} enviado ao Jean (" . implode(', ', $r['canais']) . ').');
                $sucesso = "Resumo do {$r['protocolo']} enviado pro Jean (" . implode(', ', $r['canais']) . ').';
            } else {
                $erro = $r['erro'];
            }
            $telefoneAtivo = $telPost ?: $telefoneAtivo;
            if (($_POST['voltar'] ?? '') === 'tickets') {
                header('Location: /admin/ouvidoria_tickets.php?' . ($r['ok'] ? 'ok=' . rawurlencode($sucesso) : 'erro=' . rawurlencode($erro)));
                exit;
            }
        } elseif ($acao === 'ticket_placa') {
            $r = ouvidoriaTicketSalvarPlaca((int)($_POST['ticket_id'] ?? 0), (string)($_POST['placa'] ?? ''), (string)($_POST['data_ref'] ?? ''));
            if ($r['ok']) {
                $sucesso = 'Placa/data do ticket ' . $r['ticket']['protocolo'] . ' salvas.';
                auditoriaRegistrar('ouvidoria_ticket_placa', (int)$_SESSION['admin_id'], (string)$_SESSION['admin_nome'], 'ouvidoria_ticket', (int)$r['ticket']['id'],
                    "Ticket {$r['ticket']['protocolo']}: placa " . ($r['ticket']['placa'] ?: '—') . ', data ' . ($r['ticket']['data_ref'] ? date('d/m/Y', strtotime($r['ticket']['data_ref'])) : '—') . '.');
            } else {
                $erro = $r['erro'];
            }
            $telefoneAtivo = $telPost ?: $telefoneAtivo;
            if (($_POST['voltar'] ?? '') === 'tickets') {
                header('Location: /admin/ouvidoria_tickets.php?' . ($r['ok'] ? 'ok=' . rawurlencode($sucesso) : 'erro=' . rawurlencode($erro)));
                exit;
            }
        } elseif ($acao === 'ticket_status') {
            $res = ouvidoriaTicketMudarStatus((int)($_POST['ticket_id'] ?? 0), (string)($_POST['status'] ?? ''), (int)$_SESSION['admin_id'], (string)($_POST['resolucao'] ?? ''));
            if ($res['ok']) {
                $t = $res['ticket'];
                auditoriaRegistrar('ouvidoria_ticket_status', (int)$_SESSION['admin_id'], (string)$_SESSION['admin_nome'], 'ouvidoria_ticket', (int)$t['id'],
                    "Ticket {$t['protocolo']} → " . OUVIDORIA_TICKET_STATUS[$t['status']] . ($t['resolucao'] !== '' && $t['status'] === 'resolvido' ? ": {$t['resolucao']}" : ''));
                $sucesso = "Ticket {$t['protocolo']}: " . OUVIDORIA_TICKET_STATUS[$t['status']] . '.';
                $telefoneAtivo = $t['telefone'];
            } else {
                $erro = $res['erro'];
                $telefoneAtivo = $telPost ?: $telefoneAtivo;
            }
            if (($_POST['voltar'] ?? '') === 'tickets') {
                header('Location: /admin/ouvidoria_tickets.php?' . ($res['ok'] ? 'ok=' . rawurlencode($sucesso) : 'erro=' . rawurlencode($erro)));
                exit;
            }
        } elseif ($acao === 'sugerir_resposta' && $telPost) {
            // Só sugere: nunca envia nem grava nada.
            header('Content-Type: application/json');
            echo json_encode(ouvidoriaSugerirResposta($telPost, (string)($_POST['rascunho'] ?? '')));
            exit;
        } elseif ($acao === 'enviar_anexo' && $telPost) {
            header('Content-Type: application/json');
            echo json_encode(enviarAnexoManualOuvidoria($telPost, (string)($_POST['anexo_base64'] ?? ''), (string)($_POST['nome_arquivo'] ?? ''),
                (string)($_POST['legenda'] ?? ''), (int)$_SESSION['admin_id']));
            exit;
        } elseif ($acao === 'excluir_mensagem' && $telPost) {
            // 27/11/2026 — nossa (até 48h): apaga pra todos; o resto: só do sistema.
            $r = ouvidoriaExcluirMensagem((int)($_POST['mensagem_id'] ?? 0), $telPost, (int)$_SESSION['admin_id']);
            if ($r['ok']) $sucesso = $r['msg']; else $erro = $r['erro'];
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
    // Quem abriu a conversa (1 linha por pessoa/conversa a cada 30 min).
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        _auditoriaRegistrarDocumentoDedup('ouvidoria_conversa_aberta', 'ouvidoria', (int)$telefoneAtivo, "Conversa {$telefoneAtivo} aberta na caixa da ouvidoria.");
    }
}

$busca = trim((string)($_GET['busca'] ?? ''));
$assuntoFiltro = (string)($_GET['assunto'] ?? '');
if (!isset(OUVIDORIA_ASSUNTOS[$assuntoFiltro])) $assuntoFiltro = '';
$statusFiltro = (string)($_GET['status'] ?? '');
if (!isset(OUVIDORIA_FILTROS_STATUS[$statusFiltro])) $statusFiltro = '';
$ordem = ($_GET['ordem'] ?? '') === 'espera' ? 'espera' : '';
$aba = ouvidoriaAbaValida((string)($_GET['aba'] ?? ''));
// Conversa aberta pelo link direto cai na aba certa (cobrança x ouvidoria).
if ($telefoneAtivo && !isset($_GET['aba'])) {
    $stAba = getDB()->prepare("SELECT 1 FROM cobranca_eventos WHERE telefone = ? AND tipo = 'envio' LIMIT 1");
    $stAba->execute([$telefoneAtivo]);
    if ($stAba->fetchColumn()) $aba = 'cobranca';
}
$conversas = listarConversasOuvidoria($busca, $assuntoFiltro, 100, $statusFiltro, $ordem, $aba);
// Filtros ativos vão junto em todo link de conversa (nunca perde o filtro ao abrir uma).
$qsFiltros = http_build_query(array_filter(['aba' => $aba, 'busca' => $busca, 'assunto' => $assuntoFiltro, 'status' => $statusFiltro, 'ordem' => $ordem]));
// 21/11/2026, "inbox da ouvidoria não lidas deixar essa opção" — atalho
// Todas / Não lidas sempre visível (o select de status continua igual).
$totalNaoLidasOuv = contarConversasNaoLidasOuvidoria($aba);
$naoLidasAbaOuv = $aba === '' ? $totalNaoLidasOuv : contarConversasNaoLidasOuvidoria('');
$naoLidasAbaCob = $aba === 'cobranca' ? $totalNaoLidasOuv : contarConversasNaoLidasOuvidoria('cobranca');
$qsSemStatus = array_filter(['aba' => $aba, 'busca' => $busca, 'assunto' => $assuntoFiltro, 'ordem' => $ordem]);
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
<meta name="viewport" content="width=device-width, initial-scale=1, interactive-widget=resizes-content">
<title>Ouvidoria — Fastcar CRM</title>
<link rel="stylesheet" href="/admin/assets/style.css?v=<?= @filemtime(__DIR__ . '/assets/style.css') ?: 1 ?>">
<link rel="stylesheet" href="/admin/assets/mobile.css?v=<?= @filemtime(__DIR__ . '/assets/mobile.css') ?: 1 ?>">
<script src="/admin/assets/mobile.js?v=<?= @filemtime(__DIR__ . '/assets/mobile.js') ?: 1 ?>" defer></script>
<?php include __DIR__ . '/_pwa_head.php'; ?>
<style>
html:has(body.wpp-page) { height: 100%; overflow: hidden; }
body.wpp-page { height: 100vh; height: 100dvh; height: var(--ouv-app-h, 100dvh); overflow: hidden; display: flex; flex-direction: column; }
/* Balão do cliente visível (antes tinha a mesma cor do fundo da conversa). */
.wpp-thread .msg-in { background: var(--superficie); border: 1px solid var(--borda); }
body.wpp-page > * { flex-shrink: 0; }
body.wpp-page > .wpp-wrap { display: flex; flex: 1 1 0; min-height: 0; }
.wpp-sidebar, .wpp-chat, .wpp-lista, .wpp-thread { min-height: 0; }
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
.ouv-msg-excluir { background: none; border: none; box-shadow: none; padding: 0 4px; margin: 0 0 0 6px; color: var(--texto-fraco); font-size: 12px; cursor: pointer; min-height: 0; opacity: 0; }
.msg:hover .ouv-msg-excluir, .ouv-msg-excluir:focus { opacity: 1; }
.ouv-msg-excluir:hover { color: var(--vermelho); }
@media (hover: none) { .ouv-msg-excluir { opacity: .8; min-width: 44px; min-height: 44px; margin: -14px -10px -14px 0; } }
.msg.msg-apagada { font-style: italic; opacity: .6; }
.msg.msg-apagada img, .msg.msg-apagada audio, .msg.msg-apagada video, .msg.msg-apagada a, .msg.msg-apagada .ouv-msg-excluir[data-todos="1"] { display: none; }
.wpp-form { padding: 14px 20px; border-top: 1px solid var(--borda); background: var(--superficie); display: flex; gap: 10px; }
.wpp-form textarea { flex: 1; margin: 0; resize: none; min-height: 42px; max-height: 120px; }
.wpp-form button { margin: 0; white-space: nowrap; }
.wpp-form button.ouv-sugerir { background: var(--superficie); color: var(--azul-escuro); border: 1px solid var(--borda); box-shadow: none; }
.ouv-sugestao-aviso { font-size: 12px; color: var(--texto-fraco); padding: 0 20px 6px; background: var(--superficie); }
.wpp-vazio { flex: 1; display: flex; align-items: center; justify-content: center; color: var(--texto-fraco); text-align: center; padding: 40px; }
.wpp-voltar-mobile { display: none; }
.ouv-assunto { display:inline-block; font-size:11px; font-weight:600; padding:1px 8px; border-radius:999px; background:var(--azul-claro); color:var(--azul-escuro); }
/* 21/11/2026 — notebook (1366×768, zoom 125/150%): filtros em select, faixa única
   recolhível, só a conversa rola, campo de mensagem sempre visível. */
body.ouv-page .breadcrumb { display: none; }
.ouv-filtros { padding: 10px 12px; border-bottom: 1px solid var(--borda); display: flex; flex-direction: column; gap: 6px; }
.ouv-filtros input, .ouv-filtros select { margin: 0; width: 100%; min-width: 0; }
.ouv-filtros-linha { display: grid; grid-template-columns: 1fr 1fr; gap: 6px; }
.ouv-filtros-linha select[name="ordem"] { grid-column: 1 / -1; }
.ouv-filtros select { padding: 6px 8px; font-size: 13px; }
.ouv-abas { display: flex; gap: 0; border-bottom: 2px solid var(--borda); }
.ouv-aba { flex: 1; display: inline-flex; align-items: center; justify-content: center; gap: 6px; padding: 8px; font-size: 14px; font-weight: 700; color: var(--texto-suave); text-decoration: none; border-bottom: 3px solid transparent; margin-bottom: -2px; }
.ouv-aba.ativo { color: var(--azul-escuro); border-bottom-color: var(--azul); }
.ouv-aba .wpp-badge { position: static; }
.ouv-atalhos { display: flex; gap: 6px; }
.ouv-atalho { flex: 1; display: inline-flex; align-items: center; justify-content: center; gap: 6px; padding: 6px 8px; border: 1px solid var(--borda); border-radius: var(--raio-sm); font-size: 13px; font-weight: 600; color: var(--texto-suave); text-decoration: none; background: var(--superficie); }
.ouv-atalho.ativo { background: var(--azul-claro); border-color: var(--azul); color: var(--azul-escuro); }
.ouv-atalho .wpp-badge { position: static; }
.ouv-limpar { font-size: 12px; align-self: flex-end; }
.ouv-lista-vazia { padding: 16px; color: var(--texto-fraco); font-size: 13px; }
.wpp-item .nome > .ouv-nome { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.wpp-item .preview { min-width: 0; }
.wpp-item .quando { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; margin-top: 3px; }
.ouv-espera { font-size: 11px; font-weight: 600; color: var(--amarelo); background: var(--amarelo-bg); padding: 0 6px; border-radius: 999px; }
.ouv-espera.atrasada { color: var(--vermelho); background: var(--vermelho-bg); }
.wpp-avatar { width: 38px; height: 38px; border-radius: 50%; object-fit: cover; flex-shrink: 0; background: var(--azul-claro); }
.wpp-chat-header .wpp-avatar { width: 40px; height: 40px; margin-right: 10px; }
.av-c0 { background: var(--azul); } .av-c1 { background: var(--verde); } .av-c2 { background: var(--laranja); }
.av-c3 { background: var(--roxo); } .av-c4 { background: var(--rosa); } .av-c5 { background: var(--navy); }
.ouv-contato { min-width: 0; display: flex; flex-direction: column; }
.ouv-contato strong { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.ouv-contato small { font-size: 12px; color: var(--texto-fraco); }
.wpp-chat-header { gap: 10px; }
.wpp-chat-header > div:first-child { min-width: 0; flex: 1 1 auto; }
.wpp-chat-header .ouv-acoes { flex-wrap: nowrap; flex-shrink: 0; }
.wpp-chat-header .ouv-acoes select { max-width: 210px; }
.wpp-chat-header .ouv-acoes button { white-space: nowrap; }
/* Notebook estreito/zoom: menu mais curto nesta tela (só o ícone da conta). */
@media (max-width: 1240px) and (min-width: 901px) { body.ouv-page .tb-conta-nome { display: none; } }
.ouv-faixa { border-bottom: 1px solid var(--borda); background: var(--fundo); font-size: 12.5px; }
.ouv-faixa > summary { list-style: none; cursor: pointer; display: flex; gap: 6px 14px; align-items: center; flex-wrap: wrap; padding: 7px 20px; min-height: 36px; }
.ouv-faixa > summary::-webkit-details-marker { display: none; }
.ouv-faixa-ticket, .ouv-faixa-vinculo { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.ouv-faixa-abrir { margin-left: auto; color: var(--azul); }
.ouv-faixa-abrir::after { content: ' ▾'; }
.ouv-faixa[open] .ouv-faixa-abrir::after { content: ' ▴'; }
.ouv-faixa.urgente > summary { background: var(--vermelho-bg); color: var(--vermelho); }
.ouv-atraso { color: var(--vermelho); font-weight: 700; }
.ouv-faixa-corpo { max-height: 45vh; overflow-y: auto; border-top: 1px solid var(--borda); }
.ouv-faixa-corpo .ouv-vinculo, .ouv-faixa-corpo .ouv-tickets { border-bottom: 0; }
.ouv-faixa-corpo .ouv-tickets { border-top: 1px dashed var(--borda); }
.ouv-importar { border-top: 1px solid var(--borda); border-bottom: 0; }
@media (max-height: 800px) {
    body.ouv-page .topbar-v2 { min-height: 50px; padding-top: 4px; padding-bottom: 4px; }
    .ouv-filtros { padding: 6px 10px; gap: 4px; }
    .wpp-item { padding: 8px 12px; }
    .wpp-chat-header { padding: 8px 16px; }
    .wpp-thread { padding: 12px 16px; }
    .wpp-form { padding: 8px 16px; }
    .wpp-form textarea { min-height: 38px; }
    .ouv-faixa > summary { padding: 5px 16px; min-height: 32px; }
    .ouv-faixa-corpo { max-height: 35vh; }
}
.ouv-vinculo { display:flex; flex-wrap:wrap; gap:6px 12px; align-items:center; padding:8px 20px; border-bottom:1px solid var(--borda); background:var(--fundo); font-size:12.5px; }
.ouv-vinculo-info { flex:1; min-width:220px; overflow-wrap:anywhere; }
#ouv-busca-box input { margin:6px 0; }
.ouv-res-item { display:flex; justify-content:space-between; gap:8px; align-items:center; padding:6px 8px; border:1px solid var(--borda); border-radius:8px; margin-bottom:4px; background:var(--superficie); }
.ouv-res-item form { margin:0; }
.ouv-res-item button { margin:0; width:auto; padding:6px 12px; font-size:12.5px; }
.ouv-importar { padding:6px 12px; border-bottom:1px solid var(--borda); font-size:12.5px; }
.ouv-importar summary { list-style:none; cursor:pointer; color:var(--azul); }
.ouv-importar summary::-webkit-details-marker { display:none; }
.ouv-importar form { display:flex; gap:6px; margin:6px 0 4px; }
.ouv-importar select, .ouv-importar button { margin:0; width:auto; padding:6px 10px; font-size:12.5px; }
.ouv-importar small { color:var(--texto-fraco); }
.ouv-tickets { display:flex; flex-wrap:wrap; gap:6px 14px; align-items:center; padding:6px 20px; border-bottom:1px solid var(--borda); font-size:12.5px; }
.ouv-ticket { display:flex; flex-wrap:wrap; gap:4px 10px; align-items:center; }
.ouv-ticket form { margin:0; }
.ouv-ticket details { position:relative; }
.ouv-ticket summary { list-style:none; cursor:pointer; color:var(--azul); }
.ouv-ticket summary::-webkit-details-marker { display:none; }
.ouv-ticket-resolver { display:flex; gap:6px; align-items:center; margin-top:4px; }
.ouv-ticket-resolver input { margin:0; min-width:220px; }
.ouv-ticket-resolver button { margin:0; width:auto; padding:6px 12px; font-size:12.5px; }
.ouv-comquem { width:100%; font-size:12.5px; overflow-wrap:anywhere; }
.ouv-ticket-hist { color:var(--texto-fraco); }
.ouv-acoes { display:flex; gap:8px; flex-wrap:wrap; align-items:center; font-size:12.5px; }
.ouv-acoes form { margin:0; display:flex; gap:6px; align-items:center; }
.ouv-acoes select, .ouv-acoes button { margin:0; width:auto; padding:6px 10px; font-size:12.5px; }
@media (max-width: 700px) {
    .wpp-form { align-items: flex-end; }
    .wpp-form button { min-height: 44px; }
    .wpp-form button.ouv-sugerir { padding-left: 12px; padding-right: 12px; min-width: 44px; }
    .ouv-sug-txt { display: none; }
    .wpp-sidebar { width: 100%; }
    .ouv-filtros select, .ouv-filtros input { font-size: 16px; min-height: 44px; }
    .ouv-atalho { min-height: 44px; font-size: 15px; }
    .ouv-aba { min-height: 44px; font-size: 15px; }
    .ouv-faixa > summary { min-height: 44px; padding: 6px 12px; }
    .wpp-chat-header { flex-wrap: wrap; }
    .wpp-chat-header .ouv-acoes { width: 100%; }
    .wpp-chat-header .ouv-acoes select { flex: 1; max-width: none; }
    .wpp-voltar-mobile { min-width: 44px; min-height: 44px; display: inline-flex; align-items: center; justify-content: center; font-size: 20px; color: inherit; margin-right: 0; }
    body.wpp-tem-conversa .wpp-sidebar { display: none; }
    /* 21/11/2026, "muitas conversas não dá para responder" — cabeçalho compacto
       no celular: nome + assunto + IA numa faixa só, sobra espaço pra conversa
       e o campo de responder fica sempre à vista. */
    .wpp-chat-header { padding: 6px 10px; gap: 6px; }
    .wpp-chat-header .wpp-avatar, .wpp-chat-header .wpp-avatar-placeholder { width: 32px; height: 32px; margin-right: 6px; font-size: 13px; }
    .ouv-contato strong { font-size: 15px; }
    .ouv-contato small { font-size: 11.5px; }
    .wpp-chat-header .ouv-acoes { gap: 6px; }
    .wpp-chat-header .ouv-acoes select, .wpp-chat-header .ouv-acoes button { font-size: 14px; padding: 6px 8px; min-height: 44px; }
    .ouv-faixa > summary { min-height: 40px; padding: 4px 12px; font-size: 12px; }
    .wpp-thread { padding: 10px 10px; }
    .ouv-sugestao-aviso { padding: 0 10px 4px; }
    body:not(.wpp-tem-conversa) .wpp-chat { display: none; }
}
/* Celular com o teclado aberto (tela baixa): some o menu, o assunto/IA e a faixa
   do ticket — fica só nome, conversa e o campo de responder.
   27/11/2026 ("bug no encerramento no celular não para escrever"): se o campo com
   foco está DENTRO da faixa/ações (nota de resolução, placa, recado pro Jean), a
   faixa nunca some — antes o teclado abria, a faixa sumia com o campo e o foco caía. */
@media (max-width: 700px) and (max-height: 560px) {
    body.wpp-tem-conversa .topbar, body.wpp-tem-conversa .topbar-v2 { display: none; }
    body.wpp-tem-conversa:not(.ouv-foco-faixa) .wpp-chat-header .ouv-acoes,
    body.wpp-tem-conversa:not(.ouv-foco-faixa) .ouv-faixa { display: none; }
    body.wpp-tem-conversa .ouv-contato small { display: none; }
    body.ouv-foco-faixa .wpp-thread, body.ouv-foco-faixa .wpp-form { display: none; }
    body.ouv-foco-faixa .ouv-faixa-corpo { max-height: none; }
}
</style>
<script>
<?= wppPreviewJs() ?>
</script>
</head>
<body class="wpp-page ouv-page<?= ($telefoneAtivo && $contatoAtivo) ? ' wpp-tem-conversa' : '' ?>">
<?php include __DIR__ . '/_impersonando_banner.php'; ?>
<?php include __DIR__ . '/_topbar.php'; ?>

<?php if ($erro): ?><div class="alerta-erro" style="margin:8px 20px"><?= e($erro) ?></div><?php endif; ?>
<?php if ($sucesso): ?><div class="alerta-sucesso" style="margin:8px 20px"><?= e($sucesso) ?></div><?php endif; ?>

<div class="wpp-wrap">
    <aside class="wpp-sidebar">
        <form method="get" class="ouv-filtros" id="ouv-filtros">
            <?php if ($aba !== ''): ?><input type="hidden" name="aba" value="<?= e($aba) ?>"><?php endif; ?>
            <div class="ouv-abas" role="tablist">
                <a href="?" role="tab" class="ouv-aba<?= $aba === '' ? ' ativo' : '' ?>">📣 Ouvidoria<?php if ($naoLidasAbaOuv > 0): ?> <span class="wpp-badge"><?= $naoLidasAbaOuv ?></span><?php endif; ?></a>
                <a href="?aba=cobranca" role="tab" class="ouv-aba<?= $aba === 'cobranca' ? ' ativo' : '' ?>">💰 Cobrança<?php if ($naoLidasAbaCob > 0): ?> <span class="wpp-badge"><?= $naoLidasAbaCob ?></span><?php endif; ?></a>
            </div>
            <div class="ouv-atalhos">
                <a href="?<?= e(http_build_query($qsSemStatus)) ?>" class="ouv-atalho<?= $statusFiltro !== 'nao_lidas' ? ' ativo' : '' ?>">Todas</a>
                <a href="?<?= e(http_build_query($qsSemStatus + ['status' => 'nao_lidas'])) ?>" class="ouv-atalho<?= $statusFiltro === 'nao_lidas' ? ' ativo' : '' ?>">🔵 Não lidas<?php if ($totalNaoLidasOuv > 0): ?> <span class="wpp-badge"><?= $totalNaoLidasOuv ?></span><?php endif; ?></a>
            </div>
            <input type="search" name="busca" value="<?= e($busca) ?>" placeholder="Buscar por nome ou telefone...">
            <div class="ouv-filtros-linha">
                <select name="status" aria-label="Status" onchange="this.form.submit()">
                    <?php foreach (OUVIDORIA_FILTROS_STATUS as $k => $rot): ?>
                        <option value="<?= e($k) ?>" <?= $statusFiltro === $k ? 'selected' : '' ?>><?= e($rot) ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="assunto" aria-label="Assunto" onchange="this.form.submit()">
                    <option value="">Todos os assuntos</option>
                    <?php foreach (OUVIDORIA_ASSUNTOS as $k => $rot): ?>
                        <option value="<?= e($k) ?>" <?= $assuntoFiltro === $k ? 'selected' : '' ?>><?= e($rot) ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="ordem" aria-label="Ordem" onchange="this.form.submit()">
                    <option value="">Mais recentes</option>
                    <option value="espera" <?= $ordem === 'espera' ? 'selected' : '' ?>>Esperando há mais tempo</option>
                </select>
            </div>
            <?php if ($busca !== '' || $assuntoFiltro !== '' || $statusFiltro !== '' || $ordem !== ''): ?><a href="?<?= $aba !== '' ? 'aba=' . e($aba) : '' ?>" class="ouv-limpar">limpar filtros</a><?php endif; ?>
        </form>
        <div class="wpp-lista" id="wpp-lista">
            <?php if (!$conversas): ?>
                <p class="ouv-lista-vazia">
                    <?php if ($busca !== '' || $assuntoFiltro !== '' || $statusFiltro !== ''): ?>Nenhuma conversa encontrada.
                    <?php elseif ($aba === 'cobranca'): ?>Nenhuma conversa de cobrança ainda — aparecem aqui assim que o Financeiro cobrar um cliente pelo número da ouvidoria.
                    <?php else: ?>Nenhuma conversa ainda — aparecem aqui assim que um cliente escrever pro número da ouvidoria.<?php endif; ?>
                </p>
            <?php endif; ?>
            <?php foreach ($conversas as $c): $esp = _ouvEspera($c['esperando_desde'] ?? null); ?>
                <a class="wpp-item <?= $c['telefone'] === $telefoneAtivo ? 'ativo' : '' ?>" href="?<?= e(($qsFiltros !== '' ? $qsFiltros . '&' : '') . 'telefone=' . rawurlencode($c['telefone'])) ?>">
                    <?= _ouvAvatar($c['telefone'], (string)$c['nome']) ?>
                    <div class="wpp-corpo">
                        <div class="nome">
                            <span class="ouv-nome"><?= !empty($c['urgente']) ? '🚨 ' : '' ?><?= e($c['nome'] ?: $c['telefone']) ?></span>
                            <?php if ((int)$c['nao_lidas'] > 0): ?><span class="wpp-badge"><?= (int)$c['nao_lidas'] ?></span><?php endif; ?>
                        </div>
                        <div class="preview">
                            <?= $c['ultima_direcao'] === 'out' ? '✓ ' : '' ?>
                            <?= wppPreviewHtml((string)$c['ultima_tipo'], (string)$c['ultima_mensagem']) ?>
                        </div>
                        <div class="quando">
                            <span><?= date('d/m H:i', strtotime($c['ultima_em'])) ?></span>
                            <?php if ($esp !== ''): ?><span class="ouv-espera <?= (time() - strtotime($c['esperando_desde'])) > 4 * 3600 ? 'atrasada' : '' ?>" title="Cliente esperando resposta">⏱ <?= e($esp) ?></span><?php endif; ?>
                            <?php if ($c['assunto'] !== ''): ?><span class="ouv-assunto"><?= e(OUVIDORIA_ASSUNTOS[$c['assunto']] ?? $c['assunto']) ?></span><?php endif; ?>
                        </div>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
        <details class="ouv-importar">
            <summary class="btn-texto">📥 Puxar conversas antigas</summary>
            <form method="post" onsubmit="return confirmarAcao(this, 'Puxar da instância da ouvidoria as conversas antigas? Só grava no histórico — nenhuma mensagem é enviada pro cliente.');">
                <?= csrfField() ?>
                <input type="hidden" name="acao" value="importar_historico">
                <select name="dias" aria-label="Período">
                    <option value="30">últimos 30 dias</option>
                    <option value="90" selected>últimos 90 dias</option>
                    <option value="365">último ano</option>
                    <option value="0">tudo</option>
                </select>
                <button type="submit">Puxar</button>
            </form>
            <small>Só grava o histórico (mídia antiga entra como marcador). Nunca responde nem abre ticket. Pode rodar de novo — não duplica.</small>
        </details>
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
                    <a href="?<?= e($qsFiltros) ?>" class="wpp-voltar-mobile" aria-label="Voltar pras conversas">←</a>
                    <?= _ouvAvatar($telefoneAtivo, (string)($contatoAtivo['nome'] ?? '')) ?>
                    <div class="ouv-contato">
                        <strong><?= e($contatoAtivo['nome'] ?: $telefoneAtivo) ?></strong>
                        <small><?= e($telefoneAtivo) ?></small>
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

            <?php $ticketsConv = ouvidoriaTicketsDoTelefone($telefoneAtivo); $ticketsAtivosConv = array_filter($ticketsConv, fn($t) => $t['status'] !== 'resolvido'); $nResolvidos = count($ticketsConv) - count($ticketsAtivosConv); ?>
            <?php
                $tPrinc = $ticketsAtivosConv ? reset($ticketsAtivosConv) : null;
                $temUrgente = (bool)array_filter($ticketsAtivosConv, fn($t) => !empty($t['urgente']));
            ?>
            <details class="ouv-faixa<?= $temUrgente ? ' urgente' : '' ?>" id="ouv-faixa">
                <summary>
                    <span class="ouv-faixa-ticket">
                        <?php if ($tPrinc): ?>
                            <?= $temUrgente ? '🚨 ' : '🎫 ' ?><strong><?= e($tPrinc['protocolo']) ?></strong> · <?= e(OUVIDORIA_TICKET_STATUS[$tPrinc['status']] ?? $tPrinc['status']) ?>
                            <?= count($ticketsAtivosConv) > 1 ? ' <span class="ouv-assunto">+' . (count($ticketsAtivosConv) - 1) . ' ticket(s)</span>' : '' ?>
                        <?php elseif ($ticketsConv): ?>
                            ✅ <?= count($ticketsConv) ?> ticket(s) resolvido(s)
                        <?php else: ?>
                            Sem ticket
                        <?php endif; ?>
                    </span>
                    <span class="ouv-faixa-vinculo">
                        <?php if ($vinculo): ?>
                            <?= $vinculo['tipo'] === 'venda' ? '🛒 Venda' : '🚗 Compra' ?> #<?= (int)$vinculo['id'] ?>
                            <?= $vinculo['placa'] ? ' · ' . e($vinculo['placa']) : '' ?>
                            <?php if ($vinculo['tipo'] === 'venda' && !empty($vinculo['fin']) && (float)$vinculo['fin']['atrasado'] > 0): ?> · <span class="ouv-atraso">R$ <?= number_format((float)$vinculo['fin']['atrasado'], 2, ',', '.') ?> em atraso</span><?php endif; ?>
                            <?= $vinculoSugerido ? ' · <em>confirmar vínculo</em>' : '' ?>
                        <?php else: ?>
                            🔗 sem vínculo
                        <?php endif; ?>
                    </span>
                    <span class="ouv-faixa-abrir">detalhes</span>
                </summary>
                <div class="ouv-faixa-corpo">
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

            <?php if ($ticketsConv): ?>
            <div class="ouv-tickets">
                <?php foreach ($ticketsAtivosConv as $t): ?>
                    <div class="ouv-ticket">
                        <span><?= !empty($t['urgente']) ? '<span style="color:var(--vermelho);font-weight:700">🚨 URGENTE</span> ' : '' ?>🎫 <strong><?= e($t['protocolo']) ?></strong> · <?= e(OUVIDORIA_ASSUNTOS[$t['assunto']] ?? $t['assunto']) ?> · <?= e(OUVIDORIA_TICKET_STATUS[$t['status']]) ?></span>
                        <?php if ($t['status'] === 'aberto'): ?>
                        <form method="post">
                            <?= csrfField() ?>
                            <input type="hidden" name="acao" value="ticket_status">
                            <input type="hidden" name="ticket_id" value="<?= (int)$t['id'] ?>">
                            <input type="hidden" name="telefone" value="<?= e($telefoneAtivo) ?>">
                            <input type="hidden" name="status" value="em_atendimento">
                            <button type="submit" class="btn-texto">💬 em atendimento</button>
                        </form>
                        <?php endif; ?>
                        <details>
                            <summary class="btn-texto">✅ resolver</summary>
                            <form method="post" class="ouv-ticket-resolver">
                                <?= csrfField() ?>
                                <input type="hidden" name="acao" value="ticket_status">
                                <input type="hidden" name="ticket_id" value="<?= (int)$t['id'] ?>">
                                <input type="hidden" name="telefone" value="<?= e($telefoneAtivo) ?>">
                                <input type="hidden" name="status" value="resolvido">
                                <input type="text" name="resolucao" required maxlength="500" placeholder="Como foi resolvido? (ex: baixa do pagamento feita)">
                                <button type="submit">Resolver</button>
                            </form>
                        </details>
                        <details>
                            <summary class="btn-texto">🔔 notificar Jean<?= $t['notificado_em'] ? ' (enviado ' . e(date('d/m H:i', strtotime($t['notificado_em']))) . ')' : '' ?></summary>
                            <form method="post" class="ouv-ticket-resolver">
                                <?= csrfField() ?>
                                <input type="hidden" name="acao" value="ticket_notificar_jean">
                                <input type="hidden" name="ticket_id" value="<?= (int)$t['id'] ?>">
                                <input type="hidden" name="telefone" value="<?= e($telefoneAtivo) ?>">
                                <input type="text" name="nota" maxlength="500" placeholder="Recado pro Jean (opcional) — vai junto do resumo">
                                <button type="submit">Enviar</button>
                            </form>
                        </details>
                        <div class="ouv-comquem"><?= ouvidoriaComQuemHtml(ouvidoriaComQuemDoTicket($t)) ?>
                            <details style="display:inline-block">
                                <summary class="btn-texto">✏️ placa/data</summary>
                                <form method="post" class="ouv-ticket-resolver">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="acao" value="ticket_placa">
                                    <input type="hidden" name="ticket_id" value="<?= (int)$t['id'] ?>">
                                    <input type="hidden" name="telefone" value="<?= e($telefoneAtivo) ?>">
                                    <input type="text" name="placa" value="<?= e($t['placa']) ?>" placeholder="Placa" maxlength="8" style="min-width:90px;max-width:110px">
                                    <input type="date" name="data_ref" value="<?= e((string)$t['data_ref']) ?>" title="Data da infração (vazio = hoje)" style="min-width:140px;max-width:160px">
                                    <button type="submit">Salvar</button>
                                </form>
                            </details>
                        </div>
                    </div>
                <?php endforeach; ?>
                <?php if ($nResolvidos > 0): ?>
                    <a href="/admin/ouvidoria_tickets.php?busca=<?= e(rawurlencode($telefoneAtivo)) ?>" class="ouv-ticket-hist">📁 <?= $nResolvidos ?> resolvido(s)</a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
                </div>
            </details>

            <div class="wpp-thread wpp-thread-scroll" id="wpp-thread">
                <?php if (!$mensagens): ?>
                    <p style="color:var(--texto-fraco);text-align:center;margin:auto">Nenhuma mensagem ainda.</p>
                <?php endif; ?>
                <?php if ($mensagens): ?>
                    <button type="button" id="wpp-carregar-anteriores" style="align-self:center;margin-bottom:12px;padding:6px 14px;font-size:12.5px;background:var(--superficie);color:var(--texto-fraco);border:1px solid var(--borda);box-shadow:none">⬆️ Carregar mensagens anteriores</button>
                <?php endif; ?>
                <?php foreach ($mensagens as $m): $apagada = !empty($m['apagada_em']); ?>
                    <div class="msg <?= $m['direcao'] === 'in' ? 'msg-in' : 'msg-out' ?><?= $apagada ? ' msg-apagada' : '' ?>" data-id="<?= (int)$m['id'] ?>">
                        <?php if ($apagada): ?><div>🚫 Você apagou esta mensagem</div><?php endif; ?>
                        <?= nl2br(e($m['mensagem'])) ?>
                        <?= renderizarMidiaWhatsapp($m) ?>
                        <small>
                            <?= date('d/m H:i', strtotime($m['created_at'])) ?>
                            <?= $m['usuario_nome'] ? ' · 👤 ' . e($m['usuario_nome']) : (!empty($m['enviado_por_ia']) ? ' · 🤖 IA' : '') ?>
                            <button type="button" class="ouv-msg-excluir" data-id="<?= (int)$m['id'] ?>" data-todos="<?= inboxPodeApagarMensagem($m) ? '1' : '0' ?>"
                                    title="<?= inboxPodeApagarMensagem($m) ? 'Apagar pra todos' : 'Excluir do sistema' ?>" aria-label="Excluir mensagem">🗑️</button>
                        </small>
                    </div>
                <?php endforeach; ?>
            </div>
            <form method="post" id="ouv-form-excluir-msg" style="display:none">
                <?= csrfField() ?>
                <input type="hidden" name="acao" value="excluir_mensagem">
                <input type="hidden" name="telefone" value="<?= e($telefoneAtivo) ?>">
                <input type="hidden" name="mensagem_id" value="">
            </form>
            <form method="post" class="wpp-form" id="wpp-form">
                <?= csrfField() ?>
                <input type="hidden" name="acao" value="enviar_mensagem">
                <input type="hidden" name="telefone" value="<?= e($telefoneAtivo) ?>">
                <input type="hidden" name="ajax" value="1">
                <textarea name="texto" placeholder="Digite uma mensagem..." required></textarea>
                <input type="file" id="ouv-input-anexo" accept="image/*,.pdf,.doc,.docx,.xls,.xlsx,.txt,.csv" hidden>
                <button type="button" class="btn-secundario ouv-sugerir ouv-anexo" id="ouv-btn-anexo" title="Enviar foto ou arquivo (PDF, Word, Excel). O texto digitado vai junto.">📎</button>
                <button type="button" class="btn-secundario ouv-sugerir" id="ouv-sugerir" title="A IA lê a conversa, o ticket e o contrato e sugere a resposta (você revisa antes de enviar). Se já tiver texto digitado, ela melhora o seu rascunho.">🤖<span class="ouv-sug-txt"> Sugerir</span></button>
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

    var btnSug = document.getElementById('ouv-sugerir');
    if (btnSug) {
        btnSug.addEventListener('click', function () {
            var form = document.getElementById('wpp-form');
            var ta = form.querySelector('textarea[name="texto"]');
            var fd = new FormData();
            fd.append('csrf_token', form.querySelector('input[name="csrf_token"]').value);
            fd.append('acao', 'sugerir_resposta');
            fd.append('telefone', telefone);
            fd.append('rascunho', ta.value);
            var rotulo = btnSug.innerHTML;
            btnSug.disabled = true; btnSug.textContent = '⏳';
            fetch(location.pathname + location.search, { method: 'POST', body: fd })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (d.ok) {
                        ta.value = d.texto;
                        ta.style.height = 'auto'; ta.style.height = Math.min(ta.scrollHeight, 120) + 'px';
                        ta.focus();
                        var av = document.getElementById('ouv-sugestao-aviso');
                        if (!av) { av = document.createElement('div'); av.id = 'ouv-sugestao-aviso'; av.className = 'ouv-sugestao-aviso'; form.parentNode.insertBefore(av, form); }
                        av.textContent = '🤖 Sugestão da IA — confira antes de enviar.';
                    } else {
                        alert(d.erro || 'Não deu pra sugerir agora.');
                    }
                })
                .catch(function (err) { console.error(err); alert('Não deu pra sugerir agora — tente de novo.'); })
                .finally(function () { btnSug.disabled = false; btnSug.innerHTML = rotulo; });
        });
    }

    var btnAnexo = document.getElementById('ouv-btn-anexo');
    var inputAnexo = document.getElementById('ouv-input-anexo');
    if (btnAnexo && inputAnexo && form) {
        btnAnexo.addEventListener('click', function () { inputAnexo.click(); });
        inputAnexo.addEventListener('change', function () {
            var f = inputAnexo.files && inputAnexo.files[0];
            inputAnexo.value = '';
            if (!f) return;
            if (f.size > 20 * 1024 * 1024) { alert('Arquivo maior que 20MB.'); return; }
            var ta = form.querySelector('textarea[name="texto"]');
            var leitor = new FileReader();
            leitor.onload = function () {
                var fd = new FormData();
                fd.append('csrf_token', csrf.value);
                fd.append('acao', 'enviar_anexo');
                fd.append('telefone', telefone);
                fd.append('anexo_base64', String(leitor.result).split(',')[1] || '');
                fd.append('nome_arquivo', f.name);
                fd.append('legenda', ta.value.trim());
                btnAnexo.disabled = true; btnAnexo.textContent = '⏳';
                fetch(location.pathname + location.search, { method: 'POST', body: fd })
                    .then(function (r) { return r.json(); })
                    .then(function (d) {
                        if (!d.ok) { alert(d.erro || 'Não deu pra enviar o arquivo.'); return; }
                        ta.value = '';
                    })
                    .catch(function (err) { console.error(err); alert('Não deu pra enviar o arquivo — tente de novo.'); })
                    .finally(function () { btnAnexo.disabled = false; btnAnexo.textContent = '📎'; });
            };
            leitor.readAsDataURL(f);
        });
    }

    function escapeHtml(s) {
        var d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }

    function renderMsg(m) {
        var div = document.createElement('div');
        div.className = 'msg ' + (m.direcao === 'in' ? 'msg-in' : 'msg-out') + (m.apagada_em ? ' msg-apagada' : '');
        div.dataset.id = m.id;
        var rodape = new Date(m.created_at.replace(' ', 'T')).toLocaleString('pt-BR', {day:'2-digit',month:'2-digit',hour:'2-digit',minute:'2-digit'});
        if (m.usuario_nome) rodape += ' · 👤 ' + m.usuario_nome;
        else if (parseInt(m.enviado_por_ia, 10)) rodape += ' · 🤖 IA';
        var todos = m.pode_apagar ? '1' : '0';
        div.innerHTML = (m.apagada_em ? '<div>🚫 Você apagou esta mensagem</div>' : '')
            + escapeHtml(m.mensagem).replace(/\n/g, '<br>') + (m.midia_html || '') + '<small>' + escapeHtml(rodape)
            + ' <button type="button" class="ouv-msg-excluir" data-id="' + parseInt(m.id, 10) + '" data-todos="' + todos + '" title="'
            + (todos === '1' ? 'Apagar pra todos' : 'Excluir do sistema') + '" aria-label="Excluir mensagem">🗑️</button></small>';
        return div;
    }

    // 🗑️ excluir mensagem (27/11/2026): nossa até 48h some pra todos; o resto só do sistema.
    var formExcluirMsg = document.getElementById('ouv-form-excluir-msg');
    if (thread && formExcluirMsg) {
        thread.addEventListener('click', function (ev) {
            var b = ev.target.closest && ev.target.closest('.ouv-msg-excluir');
            if (!b) return;
            formExcluirMsg.querySelector('[name="mensagem_id"]').value = b.dataset.id;
            var txt = b.dataset.todos === '1'
                ? 'Apagar essa mensagem pra todos? Ela some também do WhatsApp do cliente.'
                : 'Excluir essa mensagem do sistema? No WhatsApp do cliente ela continua aparecendo.';
            if (window.confirmarAcao) window.confirmarAcao(formExcluirMsg, txt);
            else if (confirm(txt)) formExcluirMsg.submit();
        });
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

    // 21/11/2026 — no Android o teclado (e a barra de navegação do PWA) cobria o
    // campo de responder: a altura da tela passa a seguir a área visível de verdade.
    (function () {
        var vv = window.visualViewport;
        if (!vv) return;
        var ajustar = function () {
            var pertoDoFim = thread && (thread.scrollHeight - thread.scrollTop - thread.clientHeight < 80);
            document.documentElement.style.setProperty('--ouv-app-h', Math.round(vv.height) + 'px');
            window.scrollTo(0, 0);
            if (thread && pertoDoFim) thread.scrollTop = thread.scrollHeight;
        };
        vv.addEventListener('resize', ajustar);
        ajustar();
        // Campo da faixa do ticket / ações com foco: mantém a faixa visível com o teclado aberto.
        var dentroFaixa = function (el) { return el && el.closest && el.closest('#ouv-faixa, .ouv-acoes') && el.matches('input, textarea, select'); };
        document.addEventListener('focusin', function (e) {
            if (!dentroFaixa(e.target)) return;
            document.body.classList.add('ouv-foco-faixa');
            setTimeout(function () { e.target.scrollIntoView({block: 'center'}); }, 300);
        });
        document.addEventListener('focusout', function (e) {
            if (!dentroFaixa(e.target)) return;
            setTimeout(function () { if (!dentroFaixa(document.activeElement)) document.body.classList.remove('ouv-foco-faixa'); }, 150);
        });
        var ta = document.querySelector('.wpp-form textarea');
        if (ta) ta.addEventListener('focus', function () { setTimeout(function () { if (thread) thread.scrollTop = thread.scrollHeight; ta.scrollIntoView({block: 'end'}); }, 300); });
    })();

    if (thread) {
        thread.scrollTop = thread.scrollHeight;
        // Foto do comprovante carrega depois e empurra a conversa: rola de novo pro fim.
        var rolarFim = function () { thread.scrollTop = thread.scrollHeight; };
        window.addEventListener('load', rolarFim);
        thread.querySelectorAll('img').forEach(function (img) { if (!img.complete) img.addEventListener('load', rolarFim); });
    }

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

    // Faixa ticket/vínculo: lembra se a pessoa deixou aberta (por navegador).
    var faixa = document.getElementById('ouv-faixa');
    if (faixa) {
        // 20/11/2026 — no celular começa sempre fechada (só protocolo + status), senão a
        // conversa fica sem espaço; no computador lembra como a pessoa deixou.
        var celular = window.matchMedia && window.matchMedia('(max-width: 700px)').matches;
        try { if (!celular && localStorage.getItem('ouv_faixa_aberta') === '1') faixa.open = true; } catch (e) {}
        faixa.addEventListener('toggle', function () {
            try { localStorage.setItem('ouv_faixa_aberta', faixa.open ? '1' : '0'); } catch (e) {}
        });
    }

    // Fotos de perfil (21/11/2026): busca ao vivo, troca o círculo de iniciais
    // só depois que a imagem carregou (nunca ícone quebrado) e guarda em memória
    // pra o refresh da lista (5s) não piscar.
    var fotoCache = {};
    var fotoTentada = {};
    function aplicarFoto(el, url) {
        if (!url) return;
        var img = document.createElement('img');
        img.className = 'wpp-avatar';
        img.alt = '';
        img.setAttribute('data-av-phone', el.getAttribute('data-av-phone') || '');
        img.onload = function () { if (el.parentNode) el.replaceWith(img); };
        img.src = url;
    }
    function carregarFotos() {
        var avs = Array.from(document.querySelectorAll('div.wpp-avatar-placeholder[data-av-phone]')).slice(0, 31);
        var n = 0;
        avs.forEach(function (el) {
            var phone = el.getAttribute('data-av-phone');
            if (!phone) return;
            if (fotoCache[phone]) { aplicarFoto(el, fotoCache[phone]); return; }
            if (fotoTentada[phone]) return;
            fotoTentada[phone] = true;
            var delay = Math.floor(n / 5) * 600 + (n % 5) * 80;
            n++;
            setTimeout(function () {
                fetch('?ajax=foto&telefone=' + encodeURIComponent(phone))
                    .then(function (r) { return r.json(); })
                    .then(function (d) {
                        if (!d.foto_url) return;
                        fotoCache[phone] = d.foto_url;
                        document.querySelectorAll('div.wpp-avatar-placeholder[data-av-phone="' + phone + '"]').forEach(function (p) { aplicarFoto(p, d.foto_url); });
                    })
                    .catch(function () {});
            }, delay);
        });
    }
    carregarFotos();

    function esperaTxt(desde) {
        if (!desde) return '';
        var min = Math.max(0, Math.floor((Date.now() - new Date(desde.replace(' ', 'T')).getTime()) / 60000));
        return { txt: min < 60 ? min + 'min' : (min < 1440 ? Math.floor(min / 60) + 'h' : Math.floor(min / 1440) + 'd'), atrasada: min > 240 };
    }

    var OUV_ABA = <?= json_encode($aba) ?>;
    setInterval(atualizarListaConversas, 5000);

    function atualizarListaConversas() {
        var params = new URLSearchParams(window.location.search);
        var qs = new URLSearchParams();
        ['busca', 'assunto', 'status', 'ordem'].forEach(function (k) { if (params.get(k)) qs.set(k, params.get(k)); });
        if (OUV_ABA) qs.set('aba', OUV_ABA);
        fetch('?ajax=conversas&' + qs.toString())
            .then(function (r) { return r.json(); })
            .then(function (data) {
                var lista = document.getElementById('wpp-lista');
                if (!lista || !data.conversas) return;
                var scroll = lista.scrollTop;
                lista.innerHTML = '';
                if (!data.conversas.length) {
                    var vazio = document.createElement('p');
                    vazio.className = 'ouv-lista-vazia';
                    vazio.textContent = (params.get('busca') || params.get('assunto') || params.get('status')) ? 'Nenhuma conversa encontrada.' : 'Nenhuma conversa ainda.';
                    lista.appendChild(vazio);
                    return;
                }
                data.conversas.forEach(function (c) {
                    var a = document.createElement('a');
                    a.className = 'wpp-item' + (c.telefone === telefone ? ' ativo' : '');
                    var qsItem = new URLSearchParams(qs);
                    qsItem.set('telefone', c.telefone);
                    a.href = '?' + qsItem.toString();
                    var quando = new Date(c.ultima_em.replace(' ', 'T')).toLocaleString('pt-BR', {day:'2-digit',month:'2-digit',hour:'2-digit',minute:'2-digit'});
                    var base = c.nome || c.telefone;
                    var avatar = fotoCache[c.telefone]
                        ? '<img class="wpp-avatar" alt="" src="' + escapeHtml(fotoCache[c.telefone]) + '" data-av-phone="' + escapeHtml(c.telefone) + '">'
                        : '<div class="wpp-avatar-placeholder av-c' + (parseInt(c.cor, 10) || 0) + '" data-av-phone="' + escapeHtml(c.telefone) + '" data-nome="' + escapeHtml(base) + '">' + escapeHtml(base.slice(0, 1).toUpperCase()) + '</div>';
                    var esp = esperaTxt(c.esperando_desde);
                    var espHtml = esp ? '<span class="ouv-espera' + (esp.atrasada ? ' atrasada' : '') + '" title="Cliente esperando resposta">⏱ ' + esp.txt + '</span>' : '';
                    var assuntoHtml = c.assunto ? '<span class="ouv-assunto">' + escapeHtml(ASSUNTOS[c.assunto] || c.assunto) + '</span>' : '';
                    a.innerHTML = avatar +
                        '<div class="wpp-corpo">' +
                        '<div class="nome"><span class="ouv-nome">' + (parseInt(c.urgente, 10) ? '🚨 ' : '') + escapeHtml(base) + '</span>' +
                        (c.nao_lidas > 0 ? '<span class="wpp-badge">' + c.nao_lidas + '</span>' : '') + '</div>' +
                        '<div class="preview">' + (c.ultima_direcao === 'out' ? '✓ ' : '') + wppPreviewHtml(c.ultima_tipo, c.ultima_mensagem) + '</div>' +
                        '<div class="quando"><span>' + quando + '</span>' + espHtml + assuntoHtml + '</div>' +
                        '</div>';
                    lista.appendChild(a);
                });
                lista.scrollTop = scroll;
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
                        var avSug = document.getElementById('ouv-sugestao-aviso'); if (avSug) avSug.remove();
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
<?php $notifModulo = 'ouvidoria'; include __DIR__ . '/_notify.php'; ?>
<?php include __DIR__ . '/_scroll_restore.php'; ?>
<?php include __DIR__ . '/_acao_popup.php'; ?>
<?php include __DIR__ . '/_confirm_dialog.php'; ?>

</body>
</html>
