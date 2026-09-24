<?php
/**
 * "Esqueci minha senha" — pedir o link de recuperação por e-mail
 * (24/09/2026). Não passa por admin/_bootstrap.php (exige sessão logada,
 * viraria loop de redirect), mesma estrutura standalone de admin/login.php.
 *
 * Mesma mensagem de sucesso SEMPRE, não importa se o e-mail bate com uma
 * conta real, está bloqueado ou já pediu o link há pouco — nunca revela
 * qual e-mail tem conta cadastrada (includes/recuperar_senha.php).
 */
header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet', true);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/recuperar_senha.php';

startSecureSession();

if (!empty($_SESSION['admin_id'])) {
    header('Location: /admin/index.php');
    exit;
}

$enviado = false;
$erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        $erro = 'Sessão expirada, tente de novo.';
    } else {
        $email = trim((string)($_POST['email'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $erro = 'Digite um e-mail válido.';
        } else {
            recuperarSenhaSolicitar($email);
            $enviado = true;
        }
    }
}
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Recuperar senha — Fastcar CRM</title>
<link rel="icon" type="image/png" href="/admin/assets/img/favicon.png">
<link rel="stylesheet" href="/admin/assets/style.css?v=<?= @filemtime(__DIR__ . '/assets/style.css') ?: 1 ?>">
<link rel="stylesheet" href="/admin/assets/mobile.css?v=<?= @filemtime(__DIR__ . '/assets/mobile.css') ?: 1 ?>">
<script src="/admin/assets/mobile.js?v=<?= @filemtime(__DIR__ . '/assets/mobile.js') ?: 1 ?>" defer></script>
</head>
<body class="pagina-login">
<div class="login-box">
    <h2>
        <img class="login-logo" src="/admin/assets/img/icon-192.png" alt="Fastcar" onerror="this.style.display='none'">
        <span class="login-wordmark">
            <span class="login-wordmark-nome">Fast<b>Car</b></span>
            <span class="login-wordmark-sub">Solutions</span>
        </span>
    </h2>

    <?php if ($enviado): ?>
        <div class="alerta-sucesso">Se esse e-mail tiver uma conta cadastrada, mandamos um link de recuperação pra ele agora. Confira sua caixa de entrada (e o spam) — o link vale por 1 hora.</div>
        <p style="margin-top:16px"><a href="/admin/login.php">← Voltar pro login</a></p>
    <?php else: ?>
        <p style="margin-bottom:16px;color:#555">Digite o e-mail da sua conta — se tiver cadastro, mandamos um link pra você escolher uma senha nova.</p>
        <?php if ($erro): ?><div class="alerta-erro"><?= e($erro) ?></div><?php endif; ?>
        <form method="post">
            <?= csrfField() ?>
            <label for="email">E-mail</label>
            <input type="email" id="email" name="email" required autofocus>
            <button type="submit">Mandar link de recuperação</button>
        </form>
        <p style="margin-top:16px"><a href="/admin/login.php">← Voltar pro login</a></p>
    <?php endif; ?>
</div>
</body>
</html>
