<?php
/**
 * Define a senha nova a partir do link de recuperação por e-mail
 * (24/09/2026, `?token=` — includes/recuperar_senha.php). Standalone,
 * mesma estrutura de admin/login.php/admin/esqueci_senha.php.
 *
 * O token é revalidado no POST também, nunca só no GET — entre abrir a
 * tela e enviar o formulário, o token pode ter expirado ou sido consumido
 * (ex: 2 abas abertas com o mesmo link).
 */
header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet', true);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/usuarios.php';
require_once __DIR__ . '/../includes/recuperar_senha.php';
require_once __DIR__ . '/../includes/login_2fa.php';

startSecureSession();

if (!empty($_SESSION['admin_id'])) {
    header('Location: /admin/index.php');
    exit;
}

$tokenBruto = (string)($_POST['token'] ?? $_GET['token'] ?? '');
$tokenInfo = recuperarSenhaValidarToken($tokenBruto);
$erro = '';
$sucesso = false;

if ($tokenInfo && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        $erro = 'Sessão expirada, recarregue a página e tente de novo.';
    } else {
        $novaSenha = (string)($_POST['nova_senha'] ?? '');
        $confirmar = (string)($_POST['confirmar_senha'] ?? '');
        if (strlen($novaSenha) < 8) {
            $erro = 'A senha precisa ter pelo menos 8 caracteres.';
        } elseif ($novaSenha !== $confirmar) {
            $erro = 'As duas senhas digitadas são diferentes.';
        } else {
            redefinirSenhaUsuario($tokenInfo['usuario_id'], $novaSenha);
            recuperarSenhaConsumirToken($tokenInfo['id']);
            login2faInvalidarDispositivosConfiaveis($tokenInfo['usuario_id']);
            auditoriaRegistrar('usuario_senha_redefinida', $tokenInfo['usuario_id'], $tokenInfo['nome'], 'usuario', $tokenInfo['usuario_id'], 'Senha redefinida via link de recuperação por e-mail (autoatendimento).');
            $sucesso = true;
        }
    }
}
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Redefinir senha — Fastcar CRM</title>
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

    <?php if ($sucesso): ?>
        <div class="alerta-sucesso">Senha alterada com sucesso! Já pode entrar com a senha nova.</div>
        <p style="margin-top:16px"><a href="/admin/login.php">Ir pro login →</a></p>

    <?php elseif (!$tokenInfo): ?>
        <div class="alerta-erro">Esse link de recuperação é inválido, já foi usado, ou expirou (vale por 1 hora).</div>
        <p style="margin-top:16px"><a href="/admin/esqueci_senha.php">Pedir um link novo →</a></p>

    <?php else: ?>
        <p style="margin-bottom:16px;color:#555">Olá, <?= e($tokenInfo['nome']) ?>! Escolha sua senha nova.</p>
        <?php if ($erro): ?><div class="alerta-erro"><?= e($erro) ?></div><?php endif; ?>
        <form method="post">
            <?= csrfField() ?>
            <input type="hidden" name="token" value="<?= e($tokenBruto) ?>">
            <label for="nova_senha">Senha nova</label>
            <input type="password" id="nova_senha" name="nova_senha" required minlength="8" autocomplete="new-password" autofocus>
            <label for="confirmar_senha">Confirmar senha nova</label>
            <input type="password" id="confirmar_senha" name="confirmar_senha" required minlength="8" autocomplete="new-password">
            <button type="submit">Salvar senha nova</button>
        </form>
    <?php endif; ?>
</div>
</body>
</html>
