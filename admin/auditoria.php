<?php
/**
 * Log de auditoria — versão enxuta (20/09/2026, "temos ter modulo
 * auditoria igual do jutidicosass"). Só consulta, nunca edita/apaga nada
 * daqui — o log em si não tem UI de gerenciamento, só de leitura/filtro.
 * Restrito ao super_admin, mesma trava de admin/usuarios.php/saude.php.
 */

require_once __DIR__ . '/_bootstrap.php';
requireSuperAdmin();

$evento = (string)($_GET['evento'] ?? '');
$q = trim((string)($_GET['q'] ?? ''));

$eventos = auditoriaEventosDistintos();
$linhas = auditoriaListar(200, $evento, $q);
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Auditoria — Fastcar CRM</title>
<link rel="stylesheet" href="/admin/assets/style.css?v=<?= @filemtime(__DIR__ . '/assets/style.css') ?: 1 ?>">
<link rel="stylesheet" href="/admin/assets/mobile.css?v=<?= @filemtime(__DIR__ . '/assets/mobile.css') ?: 1 ?>">
<script src="/admin/assets/mobile.js?v=<?= @filemtime(__DIR__ . '/assets/mobile.js') ?: 1 ?>" defer></script>
<?php include __DIR__ . '/_pwa_head.php'; ?>
</head>
<body>
<header class="topbar">
    <a href="/admin/index.php" style="color:#fff">← Voltar</a>
    <strong><img class="topbar-logo" src="/admin/assets/img/icon-192.png" alt="Fastcar" onerror="this.style.display='none'"> Fast<b>Car</b></strong>
    <span>Olá, <?= e($_SESSION['admin_nome']) ?></span>
    <a href="/admin/saude.php">🩺 Saúde do sistema</a>
    <a href="/admin/configuracoes.php">⚙️ Configurações</a>
    <a href="/admin/logout.php">Sair</a>
</header>

<main>
<div class="card">
    <h2>🕵️ Auditoria</h2>
    <p><small>Registro dos eventos mais sensíveis do sistema: login/logout, bloqueio de conta por tentativa
       errada, mudança de perfil/senha/bloqueio de usuário, exclusão de conversa do WhatsApp e edição de
       dado sensível de cliente (CPF/e-mail/endereço). Mostra os 200 mais recentes que batem com o filtro.</small></p>
    <form method="get" class="grid-2">
        <div>
            <label>Evento</label>
            <select name="evento">
                <option value="">— Todos —</option>
                <?php foreach ($eventos as $ev): ?>
                    <option value="<?= e($ev) ?>" <?= $evento === $ev ? 'selected' : '' ?>><?= e(auditoriaRotuloEvento($ev)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label>Buscar (usuário, detalhe ou IP)</label>
            <input type="text" name="q" value="<?= e($q) ?>" placeholder="Ex: nome do usuário, telefone, IP...">
        </div>
        <div style="grid-column:1/-1">
            <button type="submit">Filtrar</button>
            <?php if ($evento !== '' || $q !== ''): ?><a href="/admin/auditoria.php">Limpar filtro</a><?php endif; ?>
        </div>
    </form>
</div>

<div class="card">
    <h3>📜 Eventos (<?= count($linhas) ?><?= count($linhas) === 200 ? '+' : '' ?>)</h3>
    <?php if (!$linhas): ?>
        <p>Nenhum evento registrado ainda<?= ($evento !== '' || $q !== '') ? ' com esse filtro' : '' ?>.</p>
    <?php else: ?>
    <table class="tabela-oportunidades">
        <thead><tr><th>Quando</th><th>Evento</th><th>Usuário</th><th>Alvo</th><th>Detalhe</th><th>IP</th></tr></thead>
        <tbody>
        <?php foreach ($linhas as $l): ?>
            <tr>
                <td><?= e(date('d/m/Y H:i:s', strtotime($l['created_at']))) ?></td>
                <td><?= e(auditoriaRotuloEvento($l['evento'])) ?></td>
                <td><?= e($l['usuario_nome'] ?: '—') ?></td>
                <td><?= $l['alvo_tipo'] ? e($l['alvo_tipo']) . ($l['alvo_id'] ? ' #' . (int)$l['alvo_id'] : '') : '—' ?></td>
                <td><?= e($l['detalhe']) ?></td>
                <td><small><?= e($l['ip'] ?: '—') ?></small></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>
</main>
<?php include __DIR__ . '/_pwa_register.php'; ?>
<?php include __DIR__ . '/_notify.php'; ?>
<?php include __DIR__ . '/_zapi_status.php'; ?>
</body>
</html>
