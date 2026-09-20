<?php
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/auditoria.php';
startSecureSession();
if (!empty($_SESSION['admin_id'])) {
    auditoriaRegistrar('logout', (int)$_SESSION['admin_id'], (string)($_SESSION['admin_nome'] ?? ''), 'usuario', (int)$_SESSION['admin_id']);
}
$_SESSION = [];
session_destroy();
header('Location: /admin/login.php');
exit;
