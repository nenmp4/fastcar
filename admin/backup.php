<?php
/**
 * Backup — botão manual + listagem de backups locais e no Drive. Restrito
 * ao super_admin, mesma trava de admin/configuracoes.php. Lógica de
 * verdade mora em includes/backup.php (compartilhada com cron/), aqui só
 * chama e mostra o resultado.
 *
 * Não tem upload de credencial do Drive por aqui — mesma decisão de
 * admin/configuracoes.php: a credencial só entra via arquivo dropado
 * manualmente em config/google_drive_credentials.json (FTP/SSH), nunca por
 * formulário web.
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../includes/backup.php';
requireSuperAdmin();

$erro = '';
$sucesso = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        $erro = 'Sessão expirada, recarregue a página e tente de novo.';
    } else {
        $acao = (string)($_POST['acao'] ?? '');
        if ($acao === 'backup_banco') {
            $r = backupDbCopiar();
            $r['ok'] ? $sucesso = $r['mensagem'] . ' — ' . $r['arquivo'] : $erro = $r['mensagem'];
        } elseif ($acao === 'backup_completo') {
            $r = backupCompletoZip();
            $r['ok'] ? $sucesso = $r['mensagem'] . ' — ' . $r['arquivo'] : $erro = $r['mensagem'];
        } elseif ($acao === 'enviar_drive') {
            $r = backupEnviarDrive();
            $r['ok'] ? $sucesso = $r['mensagem'] : $erro = $r['mensagem'];
        }
    }
}

$locais = backupListarLocais();
$drive  = backupListarDrive();

function formatarBytes(int $bytes): string {
    if ($bytes >= 1048576) return round($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024) return round($bytes / 1024, 1) . ' KB';
    return $bytes . ' B';
}
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Backup — Fastcar CRM</title>
<link rel="stylesheet" href="/admin/assets/style.css">
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
    <h2>💾 Backup manual</h2>
    <p><small>O automático já roda pelo cron (banco várias vezes ao dia, completo 1x/dia, envio pro Drive na
       sequência — ver <code>install/setup_crontab.sh</code>). Use os botões abaixo só quando precisar de um
       backup avulso na hora (antes de uma mudança arriscada, por exemplo).</small></p>
    <form method="post" style="display:inline-block;margin-right:.5rem">
        <?= csrfField() ?>
        <input type="hidden" name="acao" value="backup_banco">
        <button type="submit">📄 Backup do banco agora</button>
    </form>
    <form method="post" style="display:inline-block;margin-right:.5rem">
        <?= csrfField() ?>
        <input type="hidden" name="acao" value="backup_completo">
        <button type="submit">🗜️ Backup completo agora</button>
    </form>
    <form method="post" style="display:inline-block">
        <?= csrfField() ?>
        <input type="hidden" name="acao" value="enviar_drive">
        <button type="submit">☁️ Enviar mais recente pro Drive</button>
    </form>
</div>

<div class="card">
    <h3>📋 Backups locais</h3>
    <table class="tabela-oportunidades">
        <thead><tr><th>Arquivo</th><th>Tipo</th><th>Tamanho</th><th>Data</th><th></th></tr></thead>
        <tbody>
        <?php if (!$locais): ?>
            <tr><td colspan="5">Nenhum backup local ainda.</td></tr>
        <?php endif; ?>
        <?php foreach ($locais as $b): ?>
            <tr>
                <td><?= e($b['nome']) ?></td>
                <td><?= $b['tipo'] === 'completo' ? 'Completo (zip)' : 'Só banco' ?></td>
                <td><?= formatarBytes($b['tamanho']) ?></td>
                <td><?= date('d/m/Y H:i', $b['modificado']) ?></td>
                <td><a href="/admin/baixar_backup.php?arquivo=<?= urlencode($b['nome']) ?>">Baixar</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="card">
    <h3>☁️ Backups no Google Drive</h3>
    <?php if (!$drive): ?>
        <p><small>Nenhum backup no Drive ainda — ou credenciais não configuradas em
           <a href="/admin/configuracoes.php">Configurações</a>.</small></p>
    <?php else: ?>
        <table class="tabela-oportunidades">
            <thead><tr><th>Arquivo</th><th>Tamanho</th><th>Criado em</th></tr></thead>
            <tbody>
            <?php foreach ($drive as $f): ?>
                <tr>
                    <td><?= e($f['name']) ?></td>
                    <td><?= isset($f['size']) ? formatarBytes((int)$f['size']) : '—' ?></td>
                    <td><?= date('d/m/Y H:i', strtotime($f['createdTime'] ?? 'now')) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
</main>
<?php include __DIR__ . '/_pwa_register.php'; ?>
<?php include __DIR__ . '/_notify.php'; ?>
</body>
</html>
