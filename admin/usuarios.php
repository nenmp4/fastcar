<?php
/**
 * Gestão de usuários (closer/consultor) — restrito ao super_admin, mesma
 * trava de admin/configuracoes.php. NUNCA cria nem promove pra
 * 'super_admin' por aqui — só o CLI install/create_admin.php faz isso,
 * decisão de segurança de propósito (evita qualquer um com acesso ao
 * painel criar outro super_admin sozinho). Editar um usuário que já é
 * super_admin não mexe no perfil dele (fica travado, só mostrado).
 */

require_once __DIR__ . '/_bootstrap.php';
requireSuperAdmin();

$db = getDB();
$erro = '';
$sucesso = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        $erro = 'Sessão expirada, recarregue a página e tente de novo.';
    } else {
        $acao = (string)($_POST['acao'] ?? '');

        if ($acao === 'criar') {
            $nome  = trim((string)($_POST['nome'] ?? ''));
            $email = trim((string)($_POST['email'] ?? ''));
            $senha = (string)($_POST['senha'] ?? '');
            $perfil = (string)($_POST['perfil'] ?? 'consultor');
            $whatsapp = trim((string)($_POST['whatsapp'] ?? ''));

            if (!in_array($perfil, ['consultor', 'closer'], true)) {
                $erro = 'Perfil inválido.';
            } elseif (!$nome || !$email || strlen($senha) < 8) {
                $erro = 'Preencha nome, e-mail e uma senha com pelo menos 8 caracteres.';
            } elseif (buscarUsuarioPorEmail($email)) {
                $erro = 'Já existe um usuário ativo com esse e-mail.';
            } else {
                try {
                    criarUsuario($nome, $email, $senha, $perfil, $whatsapp);
                    $sucesso = "Usuário {$nome} criado.";
                } catch (Throwable $e) {
                    $erro = 'Falha ao criar: ' . $e->getMessage();
                }
            }
        } elseif ($acao === 'editar') {
            $id = (int)($_POST['id'] ?? 0);
            $alvo = buscarUsuario($id);
            if (!$alvo) {
                $erro = 'Usuário não encontrado.';
            } else {
                $nome  = trim((string)($_POST['nome'] ?? ''));
                $email = trim((string)($_POST['email'] ?? ''));
                $whatsapp = trim((string)($_POST['whatsapp'] ?? ''));
                // Perfil só muda se o alvo NÃO for super_admin — preserva o
                // perfil atual em qualquer outro caso (nunca promove nem
                // rebaixa super_admin por aqui).
                $perfil = $alvo['perfil'] === 'super_admin'
                    ? 'super_admin'
                    : (in_array($_POST['perfil'] ?? '', ['consultor', 'closer'], true) ? $_POST['perfil'] : $alvo['perfil']);
                $bloqueado = !empty($_POST['bloqueado']);

                if ($id === (int)$_SESSION['admin_id'] && $bloqueado) {
                    $erro = 'Você não pode bloquear a própria conta.';
                } elseif (!$nome || !$email) {
                    $erro = 'Nome e e-mail são obrigatórios.';
                } else {
                    try {
                        atualizarUsuario($id, $nome, $email, $whatsapp, $perfil, $bloqueado);
                        $novaSenha = (string)($_POST['nova_senha'] ?? '');
                        if ($novaSenha !== '') {
                            if (strlen($novaSenha) < 8) {
                                $erro = 'Dados salvos, mas a nova senha precisa de pelo menos 8 caracteres — não foi alterada.';
                            } else {
                                redefinirSenhaUsuario($id, $novaSenha);
                            }
                        }
                        if (!$erro) $sucesso = "Usuário {$nome} atualizado.";
                    } catch (Throwable $e) {
                        $erro = 'Falha ao salvar: ' . $e->getMessage();
                    }
                }
            }
        }
    }
}

$usuarios = $db->query("SELECT id, nome, email, whatsapp, perfil, bloqueado, disponivel FROM usuarios ORDER BY (perfil = 'super_admin') DESC, perfil, nome")->fetchAll();
$editandoId = (int)($_GET['editar'] ?? 0);
$editando = $editandoId ? buscarUsuario($editandoId) : null;

$labelPerfil = ['super_admin' => 'Super admin', 'closer' => 'Closer', 'consultor' => 'Consultor'];
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Usuários — Fastcar CRM</title>
<link rel="stylesheet" href="/admin/assets/style.css">
<?php include __DIR__ . '/_pwa_head.php'; ?>
</head>
<body>
<header class="topbar">
    <a href="/admin/index.php" style="color:#fff">← Voltar</a>
    <strong>🚗 Fastcar CRM</strong>
    <span>Olá, <?= e($_SESSION['admin_nome']) ?></span>
    <a href="/admin/configuracoes.php">⚙️ Configurações</a>
    <a href="/admin/logout.php">Sair</a>
</header>

<main>
<?php if ($erro): ?><div class="alerta-erro"><?= e($erro) ?></div><?php endif; ?>
<?php if ($sucesso): ?><div class="alerta-sucesso"><?= e($sucesso) ?></div><?php endif; ?>

<div class="card">
    <h2><?= $editando ? '✏️ Editar usuário' : '➕ Novo usuário' ?></h2>
    <?php if ($editando && $editando['perfil'] === 'super_admin'): ?>
        <p><small>Este usuário é <strong>super_admin</strong> — perfil não pode ser alterado por aqui (só via CLI,
           <code>install/create_admin.php</code>), nem esta conta pode ser bloqueada nesta tela se for a sua própria.</small></p>
    <?php endif; ?>
    <form method="post" autocomplete="off">
        <?= csrfField() ?>
        <input type="hidden" name="acao" value="<?= $editando ? 'editar' : 'criar' ?>">
        <?php if ($editando): ?><input type="hidden" name="id" value="<?= (int)$editando['id'] ?>"><?php endif; ?>
        <div class="grid-2">
            <div>
                <label>Nome completo</label>
                <input type="text" name="nome" value="<?= e($editando['nome'] ?? '') ?>" required>
                <label>E-mail (login)</label>
                <input type="email" name="email" value="<?= e($editando['email'] ?? '') ?>" required>
                <label>WhatsApp</label>
                <input type="text" name="whatsapp" value="<?= e($editando['whatsapp'] ?? '') ?>" placeholder="Ex: 31999998888">
            </div>
            <div>
                <label>Perfil</label>
                <?php if ($editando && $editando['perfil'] === 'super_admin'): ?>
                    <input type="text" value="Super admin" disabled>
                <?php else: ?>
                    <select name="perfil">
                        <option value="consultor" <?= ($editando['perfil'] ?? 'consultor') === 'consultor' ? 'selected' : '' ?>>Consultor (atendimento, não define valor)</option>
                        <option value="closer" <?= ($editando['perfil'] ?? '') === 'closer' ? 'selected' : '' ?>>Closer (negocia/aprova valor)</option>
                    </select>
                <?php endif; ?>
                <label><?= $editando ? 'Nova senha (deixe em branco pra manter a atual)' : 'Senha (mínimo 8 caracteres)' ?></label>
                <input type="password" name="<?= $editando ? 'nova_senha' : 'senha' ?>" autocomplete="new-password" <?= $editando ? '' : 'required minlength="8"' ?>>
                <?php if ($editando): ?>
                    <label>
                        <input type="checkbox" name="bloqueado" <?= $editando['bloqueado'] ? 'checked' : '' ?>
                               <?= (int)$editando['id'] === (int)$_SESSION['admin_id'] ? 'disabled' : '' ?>
                               style="width:auto;display:inline-block">
                        Bloqueado (não consegue logar)
                        <?php if ((int)$editando['id'] === (int)$_SESSION['admin_id']): ?><small>— não dá pra bloquear a própria conta</small><?php endif; ?>
                    </label>
                <?php endif; ?>
            </div>
        </div>
        <button type="submit"><?= $editando ? 'Salvar' : 'Criar usuário' ?></button>
        <?php if ($editando): ?><a href="/admin/usuarios.php">Cancelar edição</a><?php endif; ?>
    </form>
</div>

<div class="card">
    <h3>👥 Usuários (<?= count($usuarios) ?>)</h3>
    <table class="tabela-oportunidades">
        <thead><tr><th>Nome</th><th>E-mail</th><th>Perfil</th><th>Status</th><th>Fila</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($usuarios as $u): ?>
            <tr>
                <td><?= e($u['nome']) ?></td>
                <td><?= e($u['email']) ?></td>
                <td><?= e($labelPerfil[$u['perfil']] ?? $u['perfil']) ?></td>
                <td>
                    <?php if ($u['bloqueado']): ?>
                        <span class="badge badge-atraso">🚫 bloqueado</span>
                    <?php else: ?>
                        <span class="badge badge-ok">✅ ativo</span>
                    <?php endif; ?>
                </td>
                <td><?= $u['disponivel'] ? '🟢 disponível' : '⚪ offline' ?></td>
                <td><a href="/admin/usuarios.php?editar=<?= (int)$u['id'] ?>">Editar →</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
</main>
<?php include __DIR__ . '/_pwa_register.php'; ?>
</body>
</html>
