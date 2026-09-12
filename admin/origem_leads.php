<?php
/**
 * Analytics de origem dos leads (bloco 1 do funil) — quanto vem de Meta
 * Ads (anúncio "Clique para WhatsApp", via referral capturado no webhook —
 * chatbot-whatsapp/includes/mensagens.php::extrairOrigemAnuncio()) vs.
 * contato direto, e o funil de conversão de cada campanha/anúncio.
 *
 * Restrito ao super_admin — dado de tráfego pago é decisão de negócio do
 * Jean, não operacional do dia a dia do consultor.
 *
 * ⚠️ Limitação conhecida: origem vive em `clientes` (first-touch por
 * telefone), não em `oportunidades` — um cliente que volta meses depois
 * pra negociar um 2º veículo clicando num anúncio diferente mantém a
 * origem do 1º contato. Value trade-off aceito por simplicidade; revisar
 * se isso virar problema real de relatório.
 */

require_once __DIR__ . '/_bootstrap.php';
requireSuperAdmin();

$db = getDB();
$linhas = $db->query("
    SELECT
        CASE WHEN c.canal_origem = '' THEN '(direto / sem anúncio)' ELSE c.canal_origem END AS canal,
        CASE WHEN c.campanha_origem = '' THEN '—' ELSE c.campanha_origem END AS campanha,
        CASE WHEN c.anuncio_origem = '' THEN '—' ELSE c.anuncio_origem END AS anuncio,
        COUNT(o.id) AS total_oportunidades,
        SUM(CASE WHEN o.etapa = 'fechado' THEN 1 ELSE 0 END) AS fechadas,
        SUM(CASE WHEN o.etapa = 'perdido' THEN 1 ELSE 0 END) AS perdidas,
        SUM(CASE WHEN o.etapa = 'sem_perfil' THEN 1 ELSE 0 END) AS sem_perfil,
        SUM(CASE WHEN o.etapa IN ('whatsapp','qualificacao_ia','crm_preenchido','atendimento','negociacao','presencial') THEN 1 ELSE 0 END) AS em_andamento
    FROM oportunidades o
    JOIN clientes c ON c.id = o.cliente_id
    GROUP BY c.canal_origem, c.campanha_origem, c.anuncio_origem
    ORDER BY total_oportunidades DESC
")->fetchAll();

$resumoCanal = $db->query("
    SELECT
        CASE WHEN c.canal_origem = '' THEN '(direto / sem anúncio)' ELSE c.canal_origem END AS canal,
        COUNT(o.id) AS total,
        SUM(CASE WHEN o.etapa = 'fechado' THEN 1 ELSE 0 END) AS fechadas
    FROM oportunidades o
    JOIN clientes c ON c.id = o.cliente_id
    GROUP BY canal
    ORDER BY total DESC
")->fetchAll();
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Origem dos leads — Fastcar CRM</title>
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
<div class="card">
    <h2>📣 Origem dos leads (bloco 1 — anúncio/tráfego)</h2>
    <p><small>Meta Ads é identificado pelo <code>referral</code> que a WhatsApp Cloud API manda quando o cliente clica
       num anúncio "Clique para WhatsApp" — capturado automaticamente na 1ª mensagem, sem link manual.
       ⚠️ Ainda não testado contra uma instância Z-API real (pendência #1 do CLAUDE.md — hospedagem/instância); validar
       o formato do campo assim que a instância existir.</small></p>

    <table class="tabela-oportunidades">
        <thead><tr><th>Canal</th><th>Total de oportunidades</th><th>Fechadas</th><th>Taxa de fechamento</th></tr></thead>
        <tbody>
        <?php if (!$resumoCanal): ?>
            <tr><td colspan="4">Nenhuma oportunidade registrada ainda.</td></tr>
        <?php endif; ?>
        <?php foreach ($resumoCanal as $r): ?>
            <tr>
                <td><?= e($r['canal']) ?></td>
                <td><?= (int)$r['total'] ?></td>
                <td><?= (int)$r['fechadas'] ?></td>
                <td><?= $r['total'] > 0 ? number_format($r['fechadas'] / $r['total'] * 100, 1) . '%' : '—' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="card">
    <h3>Detalhe por campanha/anúncio</h3>
    <table class="tabela-oportunidades">
        <thead>
            <tr>
                <th>Canal</th><th>Campanha (headline do anúncio)</th><th>ID do anúncio</th>
                <th>Total</th><th>Em andamento</th><th>Fechadas</th><th>Perdidas</th><th>Sem perfil</th>
            </tr>
        </thead>
        <tbody>
        <?php if (!$linhas): ?>
            <tr><td colspan="8">Nenhuma oportunidade registrada ainda.</td></tr>
        <?php endif; ?>
        <?php foreach ($linhas as $l): ?>
            <tr>
                <td><?= e($l['canal']) ?></td>
                <td><?= e($l['campanha']) ?></td>
                <td><?= e($l['anuncio']) ?></td>
                <td><?= (int)$l['total_oportunidades'] ?></td>
                <td><?= (int)$l['em_andamento'] ?></td>
                <td><?= (int)$l['fechadas'] ?></td>
                <td><?= (int)$l['perdidas'] ?></td>
                <td><?= (int)$l['sem_perfil'] ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
</main>
<?php include __DIR__ . '/_pwa_register.php'; ?>
</body>
</html>
