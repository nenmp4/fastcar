<?php
/**
 * Produtividade dos consultores/closers — volume de mensagens trocadas
 * com clientes pela instância própria de cada um (bloco 5+ do funil).
 * Restrito ao super_admin, mesma trava de admin/configuracoes.php.
 *
 * Métrica pedida como primeiro corte: só volume (enviadas/recebidas).
 * Tempo de resposta fica pra uma próxima iteração, quando tiver mais
 * dado acumulado pra fazer sentido.
 */

require_once __DIR__ . '/_bootstrap.php';
requireSuperAdmin();

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
<link rel="stylesheet" href="/admin/assets/style.css">
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
<div class="card">
    <h2>📊 Produtividade — volume de mensagens</h2>
    <p><small>Conta mensagens trocadas pela instância própria de cada consultor/closer (bloco 5+) — a instância
       principal (entrada/IA/follow-up) não entra nessa contagem de propósito, é volume do funil oficial, não de
       atendimento individual.</small></p>

    <nav class="etapas-nav">
        <a href="?dias=1" class="<?= $dias === 1 ? 'ativo' : '' ?>">Hoje</a>
        <a href="?dias=7" class="<?= $dias === 7 ? 'ativo' : '' ?>">Últimos 7 dias</a>
        <a href="?dias=30" class="<?= $dias === 30 ? 'ativo' : '' ?>">Últimos 30 dias</a>
    </nav>

    <table class="tabela-oportunidades">
        <thead>
            <tr>
                <th>Consultor/closer</th>
                <th>Enviadas</th>
                <th>Recebidas</th>
                <th>Clientes distintos</th>
                <th>Última mensagem</th>
            </tr>
        </thead>
        <tbody>
        <?php if (!$linhas): ?>
            <tr><td colspan="5">Nenhuma mensagem registrada por instância de consultor no período — ou ninguém
                tem instância configurada ainda em <a href="/admin/configuracoes.php">Configurações</a>.</td></tr>
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
</body>
</html>
