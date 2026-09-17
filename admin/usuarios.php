<?php
/**
 * Gestão de usuários (consultor) — restrito ao super_admin, mesma trava de
 * admin/configuracoes.php. NUNCA cria nem promove pra 'super_admin' por
 * aqui — só o CLI install/create_admin.php faz isso, decisão de segurança
 * de propósito (evita qualquer um com acesso ao painel criar outro
 * super_admin sozinho). Editar um usuário que já é super_admin não mexe no
 * perfil dele (fica travado, só mostrado).
 *
 * Perfis 'consultor' e 'closer' foram mesclados em 13/09/2026 (pedido do
 * José) — na prática é a mesma pessoa que atende (bloco 5) e negocia/fecha
 * (bloco 6), então não existe escolha de perfil "closer" aqui.
 *
 * 'supervisor' (15/09/2026, pedido José/Jean: "preciso ter perfil de
 * supervisão que vai acompanhar tudo que consultores está fazendo") volta
 * a dar 2 opções nessa tela — mesma visão do super_admin (todas as
 * oportunidades, WhatsApp Box inteiro, produtividade, qualidade da IA),
 * mas só acompanha, nunca age (ver includes/security.php::perfilVeTudo()).
 *
 * Instância Z-API própria por consultor foi RETIRADA daqui em 15/09/2026
 * (decisão do José/Jean: "manter só uma instância"). Atendimento agora é
 * todo pelo WhatsApp Box (admin/whatsapp_inbox.php), na instância
 * principal — usuarios.whatsapp virou só o número pessoal pra receber
 * notificação de lead novo (notificarConsultorLeadQualificado(),
 * includes/oportunidades.php), nunca mais canal de atendimento.
 * includes/zapi_instancias.php e a tabela zapi_instancias_consultores
 * continuam existindo (não removidas do schema — sem ganho real em
 * reconstruir a tabela no SQLite só por isso), só sem UI nova pra criar
 * instância — ver CLAUDE.md.
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
            // Só 'consultor'/'supervisor'/'vendedor' possíveis por aqui —
            // nunca 'super_admin' (só o CLI create_admin.php cria isso),
            // mesmo com POST forjado: qualquer outro valor cai pro padrão seguro.
            $perfilPost = (string)($_POST['perfil'] ?? '');
            $perfil = in_array($perfilPost, ['consultor', 'supervisor', 'vendedor'], true) ? $perfilPost : 'consultor';
            $whatsapp = trim((string)($_POST['whatsapp'] ?? ''));

            if (!$nome || !$email || strlen($senha) < 8) {
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
                // Nunca promove nem rebaixa super_admin por aqui; pra
                // qualquer outro usuário, só 'consultor'/'supervisor'/
                // 'vendedor' são valores aceitos vindos do POST (mesma
                // trava de 'criar').
                if ($alvo['perfil'] === 'super_admin') {
                    $perfil = 'super_admin';
                } else {
                    $perfilPost = (string)($_POST['perfil'] ?? '');
                    $perfil = in_array($perfilPost, ['consultor', 'supervisor', 'vendedor'], true) ? $perfilPost : 'consultor';
                }
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

$labelPerfil = ['super_admin' => 'Super admin', 'consultor' => 'Consultor', 'supervisor' => 'Supervisor (acompanhamento)', 'vendedor' => 'Vendedor (módulo de vendas)'];
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Usuários — Fastcar CRM</title>
<link rel="stylesheet" href="/admin/assets/style.css?v=<?= @filemtime(__DIR__ . '/assets/style.css') ?: 1 ?>">
<?php include __DIR__ . '/_pwa_head.php'; ?>
</head>
<body>
<header class="topbar">
    <a href="/admin/index.php" style="color:#fff">← Voltar</a>
    <strong><img class="topbar-logo" src="/admin/assets/img/icon-192.png" alt="Fastcar" onerror="this.style.display='none'"> Fast<b>Car</b> <span class="crm-tag">CRM</span></strong>
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
                <label>WhatsApp (só recebe notificação de lead novo — 1 instância Z-API só, não é um canal de atendimento)</label>
                <input type="text" name="whatsapp" value="<?= e($editando['whatsapp'] ?? '') ?>" placeholder="Ex: 31999998888">
            </div>
            <div>
                <label>Perfil</label>
                <?php if ($editando && $editando['perfil'] === 'super_admin'): ?>
                    <input type="text" value="Super admin" disabled>
                <?php else: ?>
                    <?php $perfilAtual = $editando['perfil'] ?? 'consultor'; ?>
                    <select name="perfil">
                        <option value="consultor" <?= $perfilAtual === 'consultor' ? 'selected' : '' ?>>Consultor (atende e negocia/fecha — funil de compra)</option>
                        <option value="supervisor" <?= $perfilAtual === 'supervisor' ? 'selected' : '' ?>>Supervisor (só acompanha, não age)</option>
                        <option value="vendedor" <?= $perfilAtual === 'vendedor' ? 'selected' : '' ?>>Vendedor (módulo de vendas/revenda)</option>
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
<?php include __DIR__ . '/_notify.php'; ?>
</body>
</html>
