<?php
/**
 * Produtividade dos consultores — volume de mensagens enviadas por cada um.
 * Restrito ao super_admin, mesma trava de admin/configuracoes.php.
 *
 * Reaproveitada em 15/09/2026 depois da decisão de "1 instância Z-API só":
 * antes contava mensagens da instância PRÓPRIA de cada consultor
 * (zapi_instancias_consultores, arquitetura retirada); agora
 * whatsapp_mensagens.usuario_id é preenchido pelo WhatsApp Box
 * (admin/whatsapp_inbox.php::enviarMensagemManualWhatsapp()) sempre que
 * alguém manda mensagem manual — a query (zapiContarMensagensPorConsultor(),
 * includes/zapi_instancias.php) não mudou, só a fonte do dado. "Recebidas"
 * sempre fica 0 por consultor agora (mensagem que entra não tem remetente
 * interno — é só do cliente), campo mantido só por compatibilidade.
 *
 * Métrica pedida como primeiro corte: só volume (enviadas/recebidas).
 * Tempo de resposta fica pra uma próxima iteração, quando tiver mais
 * dado acumulado pra fazer sentido.
 */

require_once __DIR__ . '/_bootstrap.php';
requireVisaoGeral();

$dias = (int)($_GET['dias'] ?? 7);
if (!in_array($dias, [1, 7, 30], true)) $dias = 7;

$linhas = zapiContarMensagensPorConsultor($dias);
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Produtividade — Fastcar CRM</title>
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
    <a href="/admin/configuracoes.php">⚙️ Configurações</a>
    <a href="/admin/logout.php">Sair</a>
</header>

<main>
<div class="card">
    <h2>📊 Produtividade — volume de mensagens</h2>
    <p><small>Conta mensagens enviadas manualmente por cada consultor pelo
       <a href="/admin/whatsapp_inbox.php">WhatsApp Box</a> — mensagem automática da IA ou do follow-up do cron não
       entra nessa contagem de propósito, é volume de atendimento humano, não do funil automático.</small></p>

    <nav class="etapas-nav">
        <a href="?dias=1" class="<?= $dias === 1 ? 'ativo' : '' ?>">Hoje</a>
        <a href="?dias=7" class="<?= $dias === 7 ? 'ativo' : '' ?>">Últimos 7 dias</a>
        <a href="?dias=30" class="<?= $dias === 30 ? 'ativo' : '' ?>">Últimos 30 dias</a>
    </nav>

    <table class="tabela-oportunidades">
        <thead>
            <tr>
                <th>Consultor</th>
                <th>Enviadas</th>
                <th>Recebidas</th>
                <th>Clientes distintos</th>
                <th>Última mensagem</th>
            </tr>
        </thead>
        <tbody>
        <?php if (!$linhas): ?>
            <tr><td colspan="5">Ninguém mandou mensagem manual pelo
                <a href="/admin/whatsapp_inbox.php">WhatsApp Box</a> nesse período ainda.</td></tr>
        <?php endif; ?>
        <?php foreach ($linhas as $l): ?>
            <tr>
                <td><?= e($l['nome']) ?></td>
                <td><?= (int)$l['enviadas'] ?></td>
                <td><?= (int)$l['recebidas'] ?></td>
                <td><?= (int)$l['clientes_distintos'] ?></td>
                <td><?= $l['ultima_mensagem'] ? date('d/m H:i', strtotime($l['ultima_mensagem'])) : '—' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
</main>
<?php include __DIR__ . '/_pwa_register.php'; ?>
<?php include __DIR__ . '/_notify.php'; ?>
<?php include __DIR__ . '/_zapi_status.php'; ?>
</body>
</html>
