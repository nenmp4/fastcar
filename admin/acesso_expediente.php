<?php
/**
 * 🌙 Acesso fora do expediente (29/10/2026). Supervisor ou super admin vê
 * quem está bloqueado agora e libera um consultor/vendedor/avaliador até o
 * fim do dia (fica na auditoria). Super admin também liga/desliga a regra e
 * ajusta a tolerância. Regra em includes/expediente_acesso.php.
 */

require_once __DIR__ . '/_bootstrap.php';
requireVisaoGeral();

$db = getDB();
$erro = '';
$sucesso = '';
$souSuper = ($_SESSION['admin_perfil'] ?? '') === 'super_admin';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        $erro = 'Sessão expirada, recarregue a página.';
    } elseif (!expedientePodeLiberar()) {
        http_response_code(403);
        $erro = 'Sem permissão.';
    } else {
        $acao = (string)($_POST['acao'] ?? '');
        $uid = (int)($_POST['usuario_id'] ?? 0);
        $alvo = $uid ? buscarUsuario($uid) : null;
        if (in_array($acao, ['liberar', 'revogar'], true)) {
            if (!$alvo || !in_array($alvo['perfil'], EXPEDIENTE_PERFIS_BLOQUEADOS, true)) {
                $erro = 'Usuário inválido.';
            } elseif ($acao === 'liberar') {
                expedienteLiberarHoje($uid);
                auditoriaRegistrar('acesso_liberado_expediente', (int)$_SESSION['admin_id'], (string)$_SESSION['admin_nome'], 'usuario', $uid,
                    "Liberou {$alvo['nome']} até 23:59 de hoje.");
                $sucesso = "{$alvo['nome']} liberado(a) até 23:59 de hoje.";
            } else {
                expedienteRevogarLiberacao($uid);
                auditoriaRegistrar('acesso_revogado_expediente', (int)$_SESSION['admin_id'], (string)$_SESSION['admin_nome'], 'usuario', $uid,
                    "Revogou a liberação de {$alvo['nome']}.");
                $sucesso = "Liberação de {$alvo['nome']} revogada.";
            }
        } elseif ($acao === 'config' && $souSuper) {
            setConfig('expediente_bloqueio_login', !empty($_POST['ligado']) ? '1' : '0');
            setConfig('expediente_tolerancia_min', (string)max(0, min(180, (int)($_POST['tolerancia'] ?? 30))));
            $sucesso = 'Configuração salva.';
        }
    }
}

$ph = implode(',', array_fill(0, count(EXPEDIENTE_PERFIS_BLOQUEADOS), '?'));
$st = $db->prepare("SELECT id, nome, perfil, acesso_liberado_ate FROM usuarios WHERE bloqueado = 0 AND perfil IN ($ph) ORDER BY perfil, nome");
$st->execute(EXPEDIENTE_PERFIS_BLOQUEADOS);
$usuarios = $st->fetchAll();
$dentro = expedienteDentroJanela();
$ligado = expedienteBloqueioLigado();
$rotuloPerfil = ['consultor' => 'Consultor', 'vendedor' => 'Vendedor', 'avaliador' => 'Avaliador'];
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Acesso fora do expediente — Fastcar CRM</title>
<link rel="stylesheet" href="/admin/assets/style.css?v=<?= @filemtime(__DIR__ . '/assets/style.css') ?: 1 ?>">
<link rel="stylesheet" href="/admin/assets/mobile.css?v=<?= @filemtime(__DIR__ . '/assets/mobile.css') ?: 1 ?>">
<script src="/admin/assets/mobile.js?v=<?= @filemtime(__DIR__ . '/assets/mobile.js') ?: 1 ?>" defer></script>
<?php include __DIR__ . '/_pwa_head.php'; ?>
</head>
<body>
<?php include __DIR__ . '/_impersonando_banner.php'; ?>
<header class="topbar">
    <a href="/admin/index.php" style="color:#fff">← Voltar</a>
    <a class="topbar-brand" href="<?= e(paginaInicialPorPerfil($_SESSION['admin_perfil'] ?? '')) ?>"><img class="topbar-logo" src="/admin/assets/img/icon-192.png" alt="Fastcar" onerror="this.style.display='none'"><span class="topbar-wordmark">Fast<b>Car</b></span></a>
    <span>Olá, <?= e($_SESSION['admin_nome']) ?></span>
    <a href="/admin/logout.php">Sair</a>
</header>

<main>
<?php if ($erro): ?><div class="alerta-erro"><?= e($erro) ?></div><?php endif; ?>
<?php if ($sucesso): ?><div class="alerta-sucesso"><?= e($sucesso) ?></div><?php endif; ?>

<div class="card">
    <h2>🌙 Acesso fora do expediente</h2>
    <p>Consultor, vendedor e avaliador só entram no sistema das <strong><?= e(filaHorarioAbertura()) ?></strong>
       às <strong><?= e(expedienteHorarioCorte()) ?></strong> (fechamento da fila <?= e(filaHorarioFechamento()) ?> + <?= expedienteToleranciaMin() ?> min de tolerância), todos os dias.
       Fora disso, quem estiver logado é desconectado.
       Supervisor e super admin podem liberar alguém até o fim do dia.</p>
    <p>
        <?php if (!$ligado): ?><span class="badge badge-aviso">⏸️ Bloqueio desligado — todos entram a qualquer hora</span>
        <?php elseif ($dentro): ?><span class="badge badge-ok">🟢 Agora: dentro do expediente</span>
        <?php else: ?><span class="badge badge-atraso">🌙 Agora: fora do expediente — acesso bloqueado</span><?php endif; ?>
    </p>
    <p><small>O horário de abertura/fechamento é o mesmo da fila de leads (Configurações → Fila).</small></p>
</div>

<div class="card">
    <table class="tabela-oportunidades">
        <thead><tr><th>Usuário</th><th>Perfil</th><th>Situação agora</th><th>Ações</th></tr></thead>
        <tbody>
        <?php if (!$usuarios): ?><tr><td colspan="4">Nenhum consultor, vendedor ou avaliador ativo.</td></tr><?php endif; ?>
        <?php foreach ($usuarios as $u): $lib = expedienteLiberado((int)$u['id']); ?>
            <tr>
                <td><?= e($u['nome']) ?></td>
                <td><?= e($rotuloPerfil[$u['perfil']] ?? $u['perfil']) ?></td>
                <td>
                    <?php if ($lib): ?><span class="badge badge-ok">🔓 liberado até <?= e(date('H:i', strtotime($u['acesso_liberado_ate']))) ?></span>
                    <?php elseif (!$ligado || $dentro): ?><span class="badge">pode entrar (expediente)</span>
                    <?php else: ?><span class="badge badge-atraso">🔒 bloqueado</span><?php endif; ?>
                </td>
                <td>
                    <?php if ($lib): ?>
                        <form method="post" class="inline" onsubmit="return confirmarAcao(this, 'Revogar a liberação de <?= e(addslashes($u['nome'])) ?>? Se estiver fora do expediente, ele(a) é desconectado(a) no próximo clique.');">
                            <?= csrfField() ?><input type="hidden" name="acao" value="revogar"><input type="hidden" name="usuario_id" value="<?= (int)$u['id'] ?>">
                            <button type="submit" class="btn-texto perigo">🔒 Revogar</button>
                        </form>
                    <?php else: ?>
                        <form method="post" class="inline">
                            <?= csrfField() ?><input type="hidden" name="acao" value="liberar"><input type="hidden" name="usuario_id" value="<?= (int)$u['id'] ?>">
                            <button type="submit" style="margin:0">🔓 Liberar hoje</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php if ($souSuper): ?>
<div class="card">
    <h3 style="margin-top:0">⚙️ Configuração (super admin)</h3>
    <form method="post" style="max-width:420px">
        <?= csrfField() ?><input type="hidden" name="acao" value="config">
        <label style="display:flex;gap:8px;align-items:center"><input type="checkbox" name="ligado" value="1" style="width:auto" <?= $ligado ? 'checked' : '' ?>> Bloquear acesso fora do expediente</label>
        <label>Tolerância depois do fechamento (minutos)</label>
        <input type="number" inputmode="numeric" name="tolerancia" min="0" max="180" value="<?= expedienteToleranciaMin() ?>">
        <button type="submit">Salvar</button>
    </form>
</div>
<?php endif; ?>
</main>
<?php include __DIR__ . '/_pwa_register.php'; ?>
<?php include __DIR__ . '/_notify.php'; ?>
<?php include __DIR__ . '/_scroll_restore.php'; ?>
<?php include __DIR__ . '/_acao_popup.php'; ?>
<?php include __DIR__ . '/_confirm_dialog.php'; ?>
<?php include __DIR__ . '/_zapi_status.php'; ?>
</body>
</html>
