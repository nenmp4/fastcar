<?php
require_once __DIR__ . '/../includes/security.php';
startSecureSession();
$_SESSION = [];
session_destroy();
header('Location: /admin/login.php');
exit;
