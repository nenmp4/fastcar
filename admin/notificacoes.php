<?php
/**
 * Endpoint JSON de polling — novos leads + eventos persistentes (ex:
 * "cliente confirmou os documentos") pro usuário logado. Sem WebSocket/SSE
 * (shared-hosting-friendly, mesma filosofia do resto do projeto): o JS de
 * admin/_notify.php chama isso a cada ~20s.
 *
 * Dois cursores independentes, mesclados numa resposta só:
 * - "desde" (datetime): detecção de LEAD NOVO, computada ao vivo contra
 *   oportunidades.created_at/updated_at — mecanismo ORIGINAL, intocado,
 *   já validado em produção. Cursor OPACO (string no mesmo formato que o
 *   banco já usa), nunca calculado/parseado no JS — evita bug de fuso
 *   horário (mesmo cuidado do posicao_fila monotônico em includes/fila_leads.php).
 * - "desde_notif_id" (inteiro): eventos da tabela `notificacoes` (30/09/2026,
 *   "como sabemos cliente preencheu... notificação clicável") — cursor
 *   simples por id autoincrement, um por usuário.
 *
 * `?historico=1`: painel rolável do sino — últimas N notificações da
 * tabela (lidas+não lidas), marca tudo como lida na mesma chamada.
 */

require_once __DIR__ . '/_bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$db = getDB();
$perfil = $_SESSION['admin_perfil'];
$meuId  = (int)$_SESSION['admin_id'];

if (isset($_GET['historico'])) {
    marcarNotificacoesLidas($meuId);
    $itens = array_map(fn($n) => [
        'id'         => (int)$n['id'],
        'tipo'       => $n['tipo'],
        'titulo'     => $n['titulo'],
        'mensagem'   => $n['mensagem'],
        'url'        => $n['url'],
        'created_at' => $n['created_at'],
    ], listarNotificacoes($meuId, 30));
    echo json_encode(['itens' => $itens]);
    exit;
}

$agora = $db->query("SELECT datetime('now','localtime')")->fetchColumn();
$desde = trim((string)($_GET['desde'] ?? ''));
// Sem cursor (1ª chamada) ou cursor malformado: baseline é agora — nunca
// dispara notificação retroativa de leads antigos na primeira visita.
if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $desde)) {
    $desde = $agora;
}
$desdeNotifId = (int)($_GET['desde_notif_id'] ?? 0);

if ($perfil === 'super_admin') {
    $stmt = $db->prepare("
        SELECT o.id, o.veiculo_marca, o.veiculo_modelo, c.nome AS cliente_nome, o.created_at
        FROM oportunidades o JOIN clientes c ON c.id = o.cliente_id
        WHERE o.created_at > ? ORDER BY o.created_at ASC LIMIT 20
    ");
    $stmt->execute([$desde]);
} else {
    $stmt = $db->prepare("
        SELECT o.id, o.veiculo_marca, o.veiculo_modelo, c.nome AS cliente_nome, o.updated_at AS created_at
        FROM oportunidades o JOIN clientes c ON c.id = o.cliente_id
        WHERE o.responsavel_id = ? AND o.updated_at > ? ORDER BY o.updated_at ASC LIMIT 20
    ");
    $stmt->execute([$meuId, $desde]);
}
// 01/10/2026, "notificação marca 1, clico, não guarda histórico" — o painel
// (?historico=1) só lia a tabela `notificacoes`, nunca "lead novo" (sempre
// ao vivo, nunca persistido) — clicar o sino depois de um toast de lead
// novo mostrava "Nenhuma notificação ainda.", mesmo o contador tendo
// marcado 1. `created_at` entra aqui pra admin/_notify.php guardar esse
// item num histórico local (localStorage) e mesclar com o painel do
// servidor — nunca precisou virar linha na tabela `notificacoes` (lead
// novo já tem o próprio badge/contador, duplicar criaria 2 fontes de
// verdade pro mesmo evento).
$leads = array_map(fn($o) => [
    'id'      => (int)$o['id'],
    'tipo'    => 'novo_lead',
    'titulo'  => '🚗 Novo lead',
    'mensagem' => ($o['cliente_nome'] ?: '(sem nome)') . (trim(($o['veiculo_marca'] ?? '') . ' ' . ($o['veiculo_modelo'] ?? '')) !== '' ? ' — ' . trim(($o['veiculo_marca'] ?? '') . ' ' . ($o['veiculo_modelo'] ?? '')) : ''),
    'url'     => '/admin/oportunidade.php?id=' . $o['id'],
    'created_at' => $o['created_at'],
], $stmt->fetchAll());

$stmtNotif = $db->prepare("
    SELECT id, tipo, titulo, mensagem, url FROM notificacoes
    WHERE usuario_id = ? AND id > ? ORDER BY id ASC LIMIT 20
");
$stmtNotif->execute([$meuId, $desdeNotifId]);
$eventosLinhas = $stmtNotif->fetchAll();
$eventos = array_map(fn($n) => [
    'id'       => (int)$n['id'],
    'tipo'     => $n['tipo'],
    'titulo'   => $n['titulo'],
    'mensagem' => $n['mensagem'],
    'url'      => $n['url'],
], $eventosLinhas);
$proximoNotifId = $eventosLinhas ? (int)end($eventosLinhas)['id'] : $desdeNotifId;

echo json_encode([
    'novos'           => array_merge($leads, $eventos),
    'proximo_desde'   => $agora,
    'proximo_notif_id' => $proximoNotifId,
    'nao_lidos_notif' => contarNotificacoesNaoLidas($meuId),
]);
