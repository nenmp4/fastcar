<?php
/**
 * Produtividade dos consultores — resultado no funil (leads, fechadas, valor,
 * perdidas, conversão) + volume de mensagens enviadas por cada um.
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
require_once __DIR__ . '/../includes/dashboard.php';
requireVisaoGeral();

function moedaProd(float $v): string { return 'R$ ' . number_format($v, 2, ',', '.'); }

$dias = (int)($_GET['dias'] ?? 7);
if (!in_array($dias, [1, 7, 30], true)) $dias = 7;
$time = ($_GET['time'] ?? '') === 'vendas' ? 'vendas' : 'compra'; // 09/11/2026: aba Vendedores

// 09/11/2026, "produtividade também já arrumar": junta o volume de
// mensagens com o resultado do funil (leads, fechadas, valor, perdidas) por
// consultor — antes só tinha mensagens, e "Recebidas" sempre dava 0.
$msgs = [];
foreach (zapiContarMensagensPorConsultor($dias) as $l) $msgs[(int)$l['usuario_id']] = $l;
$funil = produtividadeFunilPorConsultor($dias);
$nomes = [];
foreach (getDB()->query("SELECT id, nome, perfil, bloqueado FROM usuarios")->fetchAll() as $u) $nomes[(int)$u['id']] = $u;
$ids = array_keys($msgs + $funil);
foreach ($nomes as $uid => $u) if ($u['perfil'] === 'consultor' && !(int)$u['bloqueado']) $ids[] = $uid;
$linhas = [];
foreach (array_unique($ids) as $uid) {
    if (!isset($nomes[$uid]) || $nomes[$uid]['perfil'] !== 'consultor') continue; // só consultores (09/11/2026)
    $f = $funil[$uid] ?? ['leads' => 0, 'fechadas' => 0, 'valor' => 0.0, 'perdidas' => 0];
    $m = $msgs[$uid] ?? ['enviadas' => 0, 'clientes_distintos' => 0, 'ultima_mensagem' => null];
    $decididas = $f['fechadas'] + $f['perdidas'];
    $linhas[] = ['id' => $uid, 'nome' => $nomes[$uid]['nome']] + $f + [
        'conversao' => $decididas > 0 ? round($f['fechadas'] / $decididas * 100) : null,
        'enviadas' => (int)$m['enviadas'], 'clientes_distintos' => (int)$m['clientes_distintos'], 'ultima_mensagem' => $m['ultima_mensagem'],
    ];
}
usort($linhas, fn($a, $b) => [$b['fechadas'], $b['valor'], $b['enviadas']] <=> [$a['fechadas'], $a['valor'], $a['enviadas']]);
$tot = ['leads' => 0, 'fechadas' => 0, 'valor' => 0.0, 'perdidas' => 0, 'enviadas' => 0];
foreach ($linhas as $l) foreach ($tot as $k => $_) $tot[$k] += $l[$k];

// Vendedores (funil de revenda) — mesma estrutura, só perfil vendedor.
$linhasV = [];
$funilV = produtividadeFunilPorVendedor($dias);
$idsV = array_keys($funilV);
foreach ($nomes as $uid => $u) if ($u['perfil'] === 'vendedor' && !(int)$u['bloqueado']) $idsV[] = $uid;
foreach (array_unique($idsV) as $uid) {
    if (!isset($nomes[$uid]) || $nomes[$uid]['perfil'] !== 'vendedor') continue;
    $f = $funilV[$uid] ?? ['leads' => 0, 'vendidas' => 0, 'locacoes' => 0, 'valor' => 0.0, 'perdidas' => 0, 'enviadas' => 0, 'clientes_distintos' => 0, 'ultima_mensagem' => null];
    $decididas = $f['vendidas'] + $f['locacoes'] + $f['perdidas'];
    $linhasV[] = ['id' => $uid, 'nome' => $nomes[$uid]['nome']] + $f + [
        'conversao' => $decididas > 0 ? round(($f['vendidas'] + $f['locacoes']) / $decididas * 100) : null,
    ];
}
usort($linhasV, fn($a, $b) => [$b['vendidas'], $b['valor'], $b['enviadas']] <=> [$a['vendidas'], $a['valor'], $a['enviadas']]);
$totV = ['leads' => 0, 'vendidas' => 0, 'locacoes' => 0, 'valor' => 0.0, 'perdidas' => 0, 'enviadas' => 0];
foreach ($linhasV as $l) foreach ($totV as $k => $_) $totV[$k] += $l[$k];
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
<?php include __DIR__ . '/_impersonando_banner.php'; ?>
<?php include __DIR__ . '/_topbar.php'; ?>

<main>
<div class="card">
    <h2>📊 Produtividade</h2>
    <nav class="etapas-nav">
        <a href="?time=compra&amp;dias=<?= $dias ?>" class="<?= $time === 'compra' ? 'ativo' : '' ?>">🚗 Consultores (compra)</a>
        <a href="?time=vendas&amp;dias=<?= $dias ?>" class="<?= $time === 'vendas' ? 'ativo' : '' ?>">🛒 Vendedores (venda)</a>
    </nav>
    <nav class="etapas-nav">
        <a href="?time=<?= $time ?>&amp;dias=1" class="<?= $dias === 1 ? 'ativo' : '' ?>">Hoje</a>
        <a href="?time=<?= $time ?>&amp;dias=7" class="<?= $dias === 7 ? 'ativo' : '' ?>">Últimos 7 dias</a>
        <a href="?time=<?= $time ?>&amp;dias=30" class="<?= $dias === 30 ? 'ativo' : '' ?>">Últimos 30 dias</a>
    </nav>
<?php if ($time === 'compra'): ?>
    <p><small>Resultado no funil de compra + volume de atendimento. <strong>Fechadas</strong> conta por quem fechou
       (data da compra no período); <strong>Mensagens</strong> são só as enviadas à mão pelo
       <a href="/admin/whatsapp_inbox.php">WhatsApp Box</a> (IA e cron não entram).</small></p>

    <div class="stat-grid" style="margin:12px 0">
        <div class="stat-card neutro"><div class="valor"><?= (int)$tot['leads'] ?></div><div class="rotulo">Leads recebidos</div></div>
        <div class="stat-card sucesso"><div class="valor"><?= (int)$tot['fechadas'] ?></div><div class="rotulo">Compras fechadas</div></div>
        <div class="stat-card sucesso"><div class="valor"><?= moedaProd($tot['valor']) ?></div><div class="rotulo">Valor fechado</div></div>
        <div class="stat-card alerta"><div class="valor"><?= (int)$tot['perdidas'] ?></div><div class="rotulo">Perdidas / sem perfil</div></div>
        <div class="stat-card neutro"><div class="valor"><?= (int)$tot['enviadas'] ?></div><div class="rotulo">Mensagens enviadas</div></div>
    </div>

    <?php $maxF = max(1, ...array_map(fn($l) => (int)$l['fechadas'], $linhas ?: [['fechadas' => 1]])); ?>
    <table class="tabela-oportunidades">
        <thead>
            <tr>
                <th>Consultor</th>
                <th>Leads</th>
                <th>Fechadas</th>
                <th>Valor fechado</th>
                <th>Perdidas</th>
                <th>Conversão</th>
                <th>Mensagens</th>
                <th>Clientes atendidos</th>
                <th>Última mensagem</th>
            </tr>
        </thead>
        <tbody>
        <?php if (!$linhas): ?>
            <tr><td colspan="9">Nenhum consultor com movimento nesse período.</td></tr>
        <?php endif; ?>
        <?php foreach ($linhas as $i => $l): ?>
            <tr>
                <td><strong><?= ($l['fechadas'] > 0 && $i < 3) ? ['🥇 ', '🥈 ', '🥉 '][$i] : '' ?><?= e($l['nome']) ?></strong>
                    <div class="fech-barra"><span style="width:<?= round($l['fechadas'] / $maxF * 100) ?>%"></span></div></td>
                <td data-label="Leads"><?= (int)$l['leads'] ?></td>
                <td data-label="Fechadas"><strong><?= (int)$l['fechadas'] ?></strong></td>
                <td data-label="Valor fechado"><?= moedaProd($l['valor']) ?></td>
                <td data-label="Perdidas"><?= (int)$l['perdidas'] ?></td>
                <td data-label="Conversão"><?= $l['conversao'] === null ? '—' : $l['conversao'] . '%' ?></td>
                <td data-label="Mensagens"><?= (int)$l['enviadas'] ?></td>
                <td data-label="Clientes atendidos"><?= (int)$l['clientes_distintos'] ?></td>
                <td data-label="Última mensagem"><?= $l['ultima_mensagem'] ? date('d/m H:i', strtotime($l['ultima_mensagem'])) : '—' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php else: ?>
    <p><small>Resultado no funil de venda (revenda) + volume de atendimento. <strong>Vendidas</strong> conta pela data da venda
       (contrato assinado) no período — locação aparece separada e não soma no valor; <strong>Mensagens</strong> são só as enviadas
       à mão pelo <a href="/admin/vendas_inbox.php">WhatsApp de vendas</a> (IA e cron não entram).</small></p>
    <div class="stat-grid" style="margin:12px 0">
        <div class="stat-card neutro"><div class="valor"><?= (int)$totV['leads'] ?></div><div class="rotulo">Leads recebidos</div></div>
        <div class="stat-card sucesso"><div class="valor"><?= (int)$totV['vendidas'] ?></div><div class="rotulo">Vendas fechadas</div></div>
        <div class="stat-card sucesso"><div class="valor"><?= moedaProd($totV['valor']) ?></div><div class="rotulo">Valor vendido</div></div>
        <div class="stat-card neutro"><div class="valor"><?= (int)$totV['locacoes'] ?></div><div class="rotulo">Locações</div></div>
        <div class="stat-card alerta"><div class="valor"><?= (int)$totV['perdidas'] ?></div><div class="rotulo">Canceladas / sem perfil</div></div>
        <div class="stat-card neutro"><div class="valor"><?= (int)$totV['enviadas'] ?></div><div class="rotulo">Mensagens enviadas</div></div>
    </div>
    <?php $maxV = max(1, ...array_map(fn($l) => (int)$l['vendidas'], $linhasV ?: [['vendidas' => 1]])); ?>
    <table class="tabela-oportunidades">
        <thead>
            <tr>
                <th>Vendedor</th>
                <th>Leads</th>
                <th>Vendidas</th>
                <th>Valor vendido</th>
                <th>Locações</th>
                <th>Canceladas</th>
                <th>Conversão</th>
                <th>Mensagens</th>
                <th>Clientes atendidos</th>
                <th>Última mensagem</th>
            </tr>
        </thead>
        <tbody>
        <?php if (!$linhasV): ?>
            <tr><td colspan="10">Nenhum vendedor com movimento nesse período.</td></tr>
        <?php endif; ?>
        <?php foreach ($linhasV as $i => $l): ?>
            <tr>
                <td><strong><?= ($l['vendidas'] > 0 && $i < 3) ? ['🥇 ', '🥈 ', '🥉 '][$i] : '' ?><?= e($l['nome']) ?></strong>
                    <div class="fech-barra"><span style="width:<?= round($l['vendidas'] / $maxV * 100) ?>%"></span></div></td>
                <td data-label="Leads"><?= (int)$l['leads'] ?></td>
                <td data-label="Vendidas"><strong><?= (int)$l['vendidas'] ?></strong></td>
                <td data-label="Valor vendido"><?= moedaProd($l['valor']) ?></td>
                <td data-label="Locações"><?= (int)$l['locacoes'] ?></td>
                <td data-label="Canceladas"><?= (int)$l['perdidas'] ?></td>
                <td data-label="Conversão"><?= $l['conversao'] === null ? '—' : $l['conversao'] . '%' ?></td>
                <td data-label="Mensagens"><?= (int)$l['enviadas'] ?></td>
                <td data-label="Clientes atendidos"><?= (int)$l['clientes_distintos'] ?></td>
                <td data-label="Última mensagem"><?= $l['ultima_mensagem'] ? date('d/m H:i', strtotime($l['ultima_mensagem'])) : '—' ?></td>
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
