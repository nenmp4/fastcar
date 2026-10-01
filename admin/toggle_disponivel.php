<?php
/**
 * Toggle de disponibilidade — o próprio consultor liga/desliga ao
 * começar/terminar o expediente (fila de distribuição automática de
 * leads, includes/fila_leads.php). Qualquer usuário logado pode alternar
 * A PRÓPRIA disponibilidade; super_admin não entra na fila (não é
 * consultor), então alternar não tem efeito prático pra ele.
 */

require_once __DIR__ . '/_bootstrap.php';

$bloqueado = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && validateCSRF($_POST['csrf_token'] ?? '')) {
    $resultado = alternarDisponibilidade((int)$_SESSION['admin_id']);
    $bloqueado = !$resultado['ok'];
}

$voltar = $_POST['voltar'] ?? '/admin/index.php';
if (!str_starts_with($voltar, '/admin/')) $voltar = '/admin/index.php'; // nunca redirecionar pra fora do admin
if ($bloqueado) {
    $voltar .= (str_contains($voltar, '?') ? '&' : '?') . 'disponivel_bloqueado=1';
}
header('Location: ' . $voltar);
exit;
