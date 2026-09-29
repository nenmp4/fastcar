<?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/auditoria.php';
startSecureSession();
// 29/09/2026 — logout durante impersonamento também encerra ela (nunca
// fica "pendurada"); registra em nome de quem REALMENTE estava logado
// (o super_admin original), não do alvo — senão o log ficaria enganoso,
// parecendo que o alvo se deslogou sozinho.
if (estaImpersonando()) {
    $original = impersonandoOriginal();
    auditoriaRegistrar('impersonacao_finalizada', $original['id'], $original['nome'], 'usuario', (int)$_SESSION['admin_id'], 'Encerrada por logout — ' . (string)$_SESSION['admin_nome']);
} elseif (!empty($_SESSION['admin_id'])) {
    auditoriaRegistrar('logout', (int)$_SESSION['admin_id'], (string)($_SESSION['admin_nome'] ?? ''), 'usuario', (int)$_SESSION['admin_id']);
}
$_SESSION = [];
session_destroy();
header('Location: /admin/login.php');
exit;
