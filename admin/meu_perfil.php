<?php
/**
 * admin/meu_perfil.php — autoedição do próprio perfil, 21/09/2026,
 * "Permita os usuários do sistema editar perfis deles trocar número
 * e-mail nome fazer upload de avatar". Disponível pra QUALQUER perfil
 * logado (super_admin/consultor/supervisor/vendedor/financeiro) — nunca
 * mexe em `perfil`/`bloqueado` (isso continua exclusivo de
 * admin/usuarios.php, restrito ao super_admin, mesma trava de sempre).
 */

require_once __DIR__ . '/_bootstrap.php';

$usuarioId = (int)$_SESSION['admin_id'];
$usuario = buscarUsuario($usuarioId);
if (!$usuario) {
    // Sessão de um usuário que não existe mais (ex: excluído por fora) —
    // nunca deveria acontecer via UI normal, mas encerra a sessão em vez
    // de estourar erro num array vazio.
    header('Location: /admin/logout.php');
    exit;
}

$erro = '';
$sucesso = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        $erro = 'Sessão expirada, recarregue a página e tente de novo.';
    } else {
        $acao = (string)($_POST['acao'] ?? '');

        if ($acao === 'atualizar_perfil') {
            $nome = (string)($_POST['nome'] ?? '');
            $email = (string)($_POST['email'] ?? '');
            $whatsapp = (string)($_POST['whatsapp'] ?? '');
            $r = atualizarPerfilProprio($usuarioId, $nome, $email, $whatsapp);
            if ($r['ok']) {
                // Topbar mostra "Olá, {nome}" direto da sessão — sem isso o
                // nome novo só apareceria depois de logout/login de novo.
                $_SESSION['admin_nome'] = clean(trim($nome));
                if ($r['campos_alterados']) {
                    auditoriaRegistrar(
                        'perfil_proprio_editado', $usuarioId, $_SESSION['admin_nome'],
                        'usuario', $usuarioId,
                        'Campos alterados: ' . implode(', ', $r['campos_alterados'])
                    );
                }
                $sucesso = 'Dados atualizados.';
                $usuario = buscarUsuario($usuarioId);
            } else {
                $erro = $r['erro'];
            }
        } elseif ($acao === 'upload_avatar') {
            $r = processarUploadAvatar($usuarioId, $_FILES['avatar'] ?? []);
            if ($r['ok']) {
                auditoriaRegistrar('avatar_atualizado', $usuarioId, $_SESSION['admin_nome'], 'usuario', $usuarioId, 'Foto de perfil enviada.');
                $sucesso = 'Foto de perfil atualizada.';
            } else {
                $erro = $r['erro'];
            }
        } elseif ($acao === 'remover_avatar') {
            removerAvatar($usuarioId);
            auditoriaRegistrar('avatar_atualizado', $usuarioId, $_SESSION['admin_nome'], 'usuario', $usuarioId, 'Foto de perfil removida.');
            $sucesso = 'Foto de perfil removida.';
        }
    }
}

$avatarAtual = avatarUrl($usuarioId);
$inicial = mb_strtoupper(mb_substr($usuario['nome'] ?: '?', 0, 1));

$rotulosPerfil = [
    'super_admin' => 'Super admin', 'supervisor' => 'Supervisor',
    'consultor' => 'Consultor', 'vendedor' => 'Vendedor', 'financeiro' => 'Financeiro',
];
$rotuloPerfil = $rotulosPerfil[$usuario['perfil']] ?? $usuario['perfil'];

$voltarPara = match ($usuario['perfil']) {
    'vendedor' => '/admin/vendas.php',
    'financeiro' => '/admin/financeiro.php',
    default => '/admin/index.php',
};
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Meu perfil — Fastcar</title>
<link rel="stylesheet" href="/admin/assets/style.css?v=<?= @filemtime(__DIR__ . '/assets/style.css') ?: 1 ?>">
<style>
.perfil-avatar-linha { display: flex; align-items: center; gap: 18px; margin-bottom: 8px; }
.perfil-avatar, .perfil-avatar-placeholder { width: 88px; height: 88px; border-radius: 50%; object-fit: cover; flex-shrink: 0; }
.perfil-avatar { background: var(--azul-claro); }
.perfil-avatar-placeholder { background: var(--azul); color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 34px; }
</style>
<?php include __DIR__ . '/_pwa_head.php'; ?>
</head>
<body>
<header class="topbar">
    <a href="<?= e($voltarPara) ?>" style="color:#fff">← Voltar</a>
    <strong><img class="topbar-logo" src="/admin/assets/img/icon-192.png" alt="Fastcar" onerror="this.style.display='none'"> Fast<b>Car</b></strong>
    <span>Olá, <?= e($_SESSION['admin_nome']) ?></span>
    <a href="/admin/logout.php">Sair</a>
</header>

<main>
<?php if ($erro): ?><div class="card alerta-erro"><?= e($erro) ?></div><?php endif; ?>
<?php if ($sucesso): ?><div class="card alerta-sucesso"><?= e($sucesso) ?></div><?php endif; ?>

<div class="card">
    <h2>🙋 Meu perfil</h2>
    <p><small>Perfil de acesso: <strong><?= e($rotuloPerfil) ?></strong> — pra trocar de perfil ou reativar uma conta bloqueada, fale com um super_admin (<a href="/admin/usuarios.php">Usuários</a>).</small></p>

    <div class="perfil-avatar-linha">
        <?php if ($avatarAtual): ?>
            <img class="perfil-avatar" src="<?= e($avatarAtual) ?>" alt="">
        <?php else: ?>
            <div class="perfil-avatar-placeholder"><?= e($inicial) ?></div>
        <?php endif; ?>
        <div>
            <form method="post" enctype="multipart/form-data" class="inline">
                <?= csrfField() ?>
                <input type="hidden" name="acao" value="upload_avatar">
                <input type="file" name="avatar" accept="image/png,image/jpeg,image/webp" required>
                <button type="submit" style="margin-top:8px">📷 Enviar foto</button>
            </form>
            <?php if ($avatarAtual): ?>
                <form method="post" class="inline" style="margin-top:6px" onsubmit="return confirm('Remover a foto de perfil?')">
                    <?= csrfField() ?>
                    <input type="hidden" name="acao" value="remover_avatar">
                    <button type="submit" class="btn-texto perigo">🗑️ Remover foto</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
    <p><small>PNG, JPG ou WEBP, até 5MB — a foto é recortada automaticamente pra um quadrado.</small></p>
</div>

<div class="card">
    <h3>Dados pessoais</h3>
    <form method="post">
        <?= csrfField() ?>
        <input type="hidden" name="acao" value="atualizar_perfil">
        <label>Nome</label>
        <input type="text" name="nome" value="<?= e($usuario['nome']) ?>" required>
        <label>E-mail (usado pra login)</label>
        <input type="email" name="email" value="<?= e($usuario['email']) ?>" required>
        <label>WhatsApp (recebe notificações de lead/avisos do sistema)</label>
        <input type="text" name="whatsapp" value="<?= e($usuario['whatsapp']) ?>" placeholder="Ex: 5511999998888">
        <button type="submit">Salvar</button>
    </form>
    <p><small>⚠️ Trocar o e-mail muda o login usado pra entrar no sistema — confirme que está certo antes de salvar.</small></p>
</div>
</main>
<?php include __DIR__ . '/_pwa_register.php'; ?>
<?php include __DIR__ . '/_notify.php'; ?>
</body>
</html>
