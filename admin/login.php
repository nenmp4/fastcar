<?php
/**
 * Login do admin. Não usa admin/_bootstrap.php (que já exige sessão
 * logada) — senão vira loop de redirect pra si mesmo.
 */

// Mesmo header de admin/_bootstrap.php — este arquivo não passa por lá.
header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet', true);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/usuarios.php';

startSecureSession();

if (!empty($_SESSION['admin_id'])) {
    header('Location: /admin/index.php');
    exit;
}

$erro = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        $erro = 'Sessão expirada, tente novamente.';
    } else {
        $email = (string)($_POST['email'] ?? '');
        $senha = (string)($_POST['senha'] ?? '');
        $user = autenticar($email, $senha);
        if ($user) {
            session_regenerate_id(true);
            $_SESSION['admin_id']     = (int)$user['id'];
            $_SESSION['admin_nome']   = $user['nome'];
            $_SESSION['admin_perfil'] = $user['perfil'];
            header('Location: /admin/index.php');
            exit;
        }
        $erro = 'E-mail ou senha inválidos.';
    }
}
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Login — Fastcar CRM</title>
<link rel="stylesheet" href="/admin/assets/style.css">
</head>
<body>
<div class="login-box">
    <h2>🚗 Fastcar CRM</h2>
    <?php if ($erro): ?><div class="alerta-erro"><?= e($erro) ?></div><?php endif; ?>
    <form method="post">
        <?= csrfField() ?>
        <label for="email">E-mail</label>
        <input type="email" id="email" name="email" required autofocus>
        <label for="senha">Senha</label>
        <input type="password" id="senha" name="senha" required>
        <button type="submit">Entrar</button>
    </form>
</div>
</body>
</html>
