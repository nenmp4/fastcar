<?php
/**
 * admin/whatsapp_conformidade.php — 30/09/2026, depois da conta Meta
 * Business da Fastcar ter sido desativada permanentemente por "disparo em
 * massa, sem consentimento, pra qualificar leads". Painel de acompanhamento
 * do gate de envio ativo (includes/whatsapp_conformidade.php) — só leitura,
 * nenhuma ação de envio aqui. Restrito ao super_admin/supervisor, mesma
 * trava de qualidade_ia.php.
 */

require_once __DIR__ . '/_bootstrap.php';
requireVisaoGeral();

$resumo = whatsappConformidadeResumo();
$filaManual = whatsappFilaRecontatoManual();
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Conformidade WhatsApp — Fastcar CRM</title>
<link rel="stylesheet" href="/admin/assets/style.css?v=<?= @filemtime(__DIR__ . '/assets/style.css') ?: 1 ?>">
<link rel="stylesheet" href="/admin/assets/mobile.css?v=<?= @filemtime(__DIR__ . '/assets/mobile.css') ?: 1 ?>">
<script src="/admin/assets/mobile.js?v=<?= @filemtime(__DIR__ . '/assets/mobile.js') ?: 1 ?>" defer></script>
<?php include __DIR__ . '/_pwa_head.php'; ?>
</head>
<body>
<?php include __DIR__ . '/_impersonando_banner.php'; ?>
<header class="topbar">
    <a href="/admin/index.php" style="color:#fff">← Voltar</a>
    <strong><img class="topbar-logo" src="/admin/assets/img/icon-192.png" alt="Fastcar" onerror="this.style.display='none'"> Fast<b>Car</b></strong>
    <span>Olá, <?= e($_SESSION['admin_nome']) ?></span>
    <a href="/admin/configuracoes.php">⚙️ Configurações</a>
    <a href="/admin/logout.php">Sair</a>
</header>

<main>
<div class="card">
    <h2>🛡️ Conformidade WhatsApp</h2>
    <p><small>Acompanhamento do único tipo de envio que a Meta apontou como causa do banimento — mensagem ATIVA
       disparada por automação (cron de recuperação de leads e reengajamento), sem o cliente ter escrito primeiro.
       Resposta normal da IA e mensagem manual do WhatsApp Box nunca passam por aqui. Configurar instância dedicada
       e ligar/desligar o envio ativo fica em <a href="/admin/configuracoes.php">Configurações</a>.</small></p>
</div>

<div class="card">
    <h3>Estado atual</h3>
    <div class="stat-grid">
        <div class="stat-card <?= $resumo['flag_ligada'] ? 'sucesso' : 'neutro' ?>">
            <div class="valor"><?= $resumo['flag_ligada'] ? 'Ligado' : 'Desligado' ?></div>
            <div class="rotulo">Envio ativo automático</div>
        </div>
        <div class="stat-card <?= $resumo['instancia_configurada'] ? 'sucesso' : 'alerta' ?>">
            <div class="valor"><?= $resumo['instancia_configurada'] ? 'Sim' : 'Não' ?></div>
            <div class="rotulo">Instância dedicada configurada</div>
        </div>
        <div class="stat-card <?= $resumo['circuit_breaker_ativo'] ? 'alerta' : 'neutro' ?>">
            <div class="valor"><?= $resumo['circuit_breaker_ativo'] ? '⚠️ Ativo' : 'Normal' ?></div>
            <div class="rotulo">Circuit breaker (taxa de falha)</div>
        </div>
    </div>
</div>

<div class="card">
    <h3>Envios ativos hoje</h3>
    <div class="stat-grid">
        <div class="stat-card sucesso">
            <div class="valor"><?= (int)$resumo['enviados_hoje'] ?></div>
            <div class="rotulo">Enviados</div>
        </div>
        <div class="stat-card neutro">
            <div class="valor"><?= (int)$resumo['bloqueados_hoje'] ?></div>
            <div class="rotulo">Bloqueados</div>
        </div>
        <div class="stat-card <?= $resumo['falhas_hoje'] > 0 ? 'alerta' : 'neutro' ?>">
            <div class="valor"><?= (int)$resumo['falhas_hoje'] ?></div>
            <div class="rotulo">Falharam</div>
        </div>
        <div class="stat-card <?= $resumo['taxa_falha_hoje'] > 2 ? 'alerta' : 'neutro' ?>">
            <div class="valor"><?= $resumo['taxa_falha_hoje'] ?>%</div>
            <div class="rotulo">Taxa de falha/bloqueio hoje</div>
        </div>
    </div>
    <p><small>Limite diário configurado: <?= (int)$resumo['limite_diario'] ?> envio(s). Total de contatos com
       opt-out registrado: <?= (int)$resumo['total_optouts'] ?>.</small></p>

    <?php if ($resumo['bloqueados_por_motivo']): ?>
        <table class="tabela-oportunidades">
            <thead><tr><th>Motivo do bloqueio</th><th>Quantidade hoje</th></tr></thead>
            <tbody>
            <?php foreach ($resumo['bloqueados_por_motivo'] as $m): ?>
                <tr>
                    <td><?= e(whatsappConformidadeRotuloMotivo((string)$m['motivo_bloqueio'])) ?></td>
                    <td><?= (int)$m['n'] ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <p>Nenhum bloqueio hoje.</p>
    <?php endif; ?>
</div>

<div class="card">
    <h3>Fila de recontato manual (últimos 7 dias)</h3>
    <p><small>Contatos bloqueados por falta de opt-in — nunca viraram cliente de verdade aqui, sem nenhuma
       mensagem trocada. Nunca dispara nada sozinho: candidatos pra ligação, SMS ou e-mail manual, fora do
       WhatsApp automático.</small></p>
    <?php if (!$filaManual): ?>
        <p>Nenhum contato pendente.</p>
    <?php else: ?>
        <table class="tabela-oportunidades">
            <thead><tr><th>Telefone</th><th>Nome</th><th>Último bloqueio</th></tr></thead>
            <tbody>
            <?php foreach ($filaManual as $f): ?>
                <tr>
                    <td><?= e($f['telefone']) ?></td>
                    <td><?= e($f['nome'] ?: '—') ?></td>
                    <td><?= e(date('d/m/Y H:i', strtotime($f['created_at']))) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
</main>
<?php include __DIR__ . '/_pwa_register.php'; ?>
<?php include __DIR__ . '/_notify.php'; ?>
<?php include __DIR__ . '/_scroll_restore.php'; ?>
<?php include __DIR__ . '/_acao_popup.php'; ?>
<?php include __DIR__ . '/_confirm_dialog.php'; ?>
<?php include __DIR__ . '/_zapi_status.php'; ?>
</body>
</html>
