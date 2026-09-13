<?php
/**
 * Endpoint JSON de polling — novos leads pro usuário logado. Sem
 * WebSocket/SSE (shared-hosting-friendly, mesma filosofia do resto do
 * projeto): o JS de admin/_notify.php chama isso a cada ~20s.
 *
 * "desde" é um cursor OPAINCO — uma string datetime no MESMO formato que
 * o banco já usa (datetime('now','localtime')), nunca calculado/parseado
 * no JS. Cliente só guarda e devolve o que o servidor mandou da última
 * vez — evita qualquer bug de fuso horário entre JS e SQLite (mesmo tipo
 * de cuidado que motivou o posicao_fila monotônico em vez de timestamp
 * em includes/fila_leads.php).
 *
 * super_admin: notifica sobre QUALQUER lead novo (created_at) — visão da
 * empresa inteira. consultor: notifica quando uma oportunidade
 * passa a ser dele (updated_at, cobre atribuição automática da fila E
 * reatribuição manual em admin/oportunidade.php, que faz UPDATE direto
 * sem passar por mudarEtapa()/oportunidade_historico).
 */

require_once __DIR__ . '/_bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$db = getDB();
$agora = $db->query("SELECT datetime('now','localtime')")->fetchColumn();
$desde = trim((string)($_GET['desde'] ?? ''));
// Sem cursor (1ª chamada) ou cursor malformado: baseline é agora — nunca
// dispara notificação retroativa de leads antigos na primeira visita.
if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $desde)) {
    $desde = $agora;
}

$perfil = $_SESSION['admin_perfil'];
$meuId  = (int)$_SESSION['admin_id'];

if ($perfil === 'super_admin') {
    $stmt = $db->prepare("
        SELECT o.id, o.veiculo_marca, o.veiculo_modelo, c.nome AS cliente_nome
        FROM oportunidades o JOIN clientes c ON c.id = o.cliente_id
        WHERE o.created_at > ? ORDER BY o.created_at ASC LIMIT 20
    ");
    $stmt->execute([$desde]);
    $stmtTotal = $db->prepare("SELECT COUNT(*) FROM oportunidades WHERE created_at > ?");
    $stmtTotal->execute([$desde]);
    $total = (int)$stmtTotal->fetchColumn();
} else {
    $stmt = $db->prepare("
        SELECT o.id, o.veiculo_marca, o.veiculo_modelo, c.nome AS cliente_nome
        FROM oportunidades o JOIN clientes c ON c.id = o.cliente_id
        WHERE o.responsavel_id = ? AND o.updated_at > ? ORDER BY o.updated_at ASC LIMIT 20
    ");
    $stmt->execute([$meuId, $desde]);
    $stmtTotal = $db->prepare("SELECT COUNT(*) FROM oportunidades WHERE responsavel_id = ? AND updated_at > ?");
    $stmtTotal->execute([$meuId, $desde]);
    $total = (int)$stmtTotal->fetchColumn();
}

$novos = array_map(fn($o) => [
    'id'      => (int)$o['id'],
    'cliente' => $o['cliente_nome'] ?: '(sem nome)',
    'veiculo' => trim(($o['veiculo_marca'] ?? '') . ' ' . ($o['veiculo_modelo'] ?? '')),
], $stmt->fetchAll());

echo json_encode([
    'novos'       => $novos,
    'total'       => $total,
    'proximo_desde' => $agora,
]);
