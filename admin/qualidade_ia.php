<?php
/**
 * admin/qualidade_ia.php — "acerto" da qualificação por IA (bloco 3):
 * cruza o que a IA decidiu/classificou com o resultado real depois. Não
 * treina o modelo — é pro Jean/José olharem de vez em quando e ajustarem
 * o PROMPT (includes/ia_qualificacao.php) com base em padrão real, não
 * achismo. Restrito ao super_admin, mesma trava de configuracoes.php.
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../includes/qualidade_ia.php';
requireSuperAdmin();

$funil = qualidadeIaFunilResultado();
$porTemperatura = qualidadeIaTemperaturaVsResultado();
$posEstagnacao = qualidadeIaEscalacoesEstagnacaoDepois();
$totalEstagnacao = array_sum($posEstagnacao);

$totalFunil = array_sum($funil);
function pctFunil(int $n, int $total): string { return $total > 0 ? round($n / $total * 100) . '%' : '—'; }
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Qualidade da IA — Fastcar CRM</title>
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
<div class="card">
    <h2>🤖 Qualidade da qualificação por IA</h2>
    <p><small>O modelo (Gemini/GPT) não é retreinado com esses dados — o ganho vem de olhar esse relatório de vez
       em quando e ajustar o texto do prompt (<code>IA_QUALIFICACAO_PROMPT_SISTEMA</code>/
       <code>IA_EXTRACAO_PROMPT</code> em <code>includes/ia_qualificacao.php</code>) com base em padrão real, em vez
       de achismo. "Fica afiado" com o uso, mas guiado por quem lê aqui, não sozinho.</small></p>
</div>

<div class="card">
    <h3>Funil de resultado da qualificação</h3>
    <p><small>Toda oportunidade cai em 1 balde: ainda sendo qualificada, IA descartou (sem perfil), IA desistiu de
       tentar sozinha (escalou por estagnação) ou a IA levou até o fim normalmente.</small></p>
    <div class="stat-grid">
        <div class="stat-card neutro">
            <div class="valor"><?= (int)$funil['em_andamento'] ?></div>
            <div class="rotulo">Ainda em qualificação</div>
        </div>
        <div class="stat-card">
            <div class="valor"><?= (int)$funil['sem_perfil'] ?> (<?= pctFunil($funil['sem_perfil'], $totalFunil) ?>)</div>
            <div class="rotulo">IA descartou (sem perfil)</div>
        </div>
        <div class="stat-card <?= $funil['escalado_estagnacao'] > 0 ? 'alerta' : '' ?>">
            <div class="valor"><?= (int)$funil['escalado_estagnacao'] ?> (<?= pctFunil($funil['escalado_estagnacao'], $totalFunil) ?>)</div>
            <div class="rotulo">Escalou por estagnação</div>
        </div>
        <div class="stat-card sucesso">
            <div class="valor"><?= (int)$funil['completa'] ?> (<?= pctFunil($funil['completa'], $totalFunil) ?>)</div>
            <div class="rotulo">Qualificação completa</div>
        </div>
    </div>
    <p><small>% alto de "escalou por estagnação" pode indicar que o prompt está travando em alguma pergunta, ou que
       o limite de turnos (<code>IA_LIMITE_TURNOS_SEM_AVANCO</code>) está curto demais pro jeito que as pessoas
       realmente respondem.</small></p>
</div>

<div class="card">
    <h3>Temperatura do lead × resultado real</h3>
    <p><small>A IA classifica frio/morno/quente durante a conversa, sem perguntar pro cliente — é uma leitura dela
       sobre o quanto a pessoa parece decidida. Aqui dá pra ver se essa leitura bate com o que realmente
       aconteceu depois (só conta quem já saiu da qualificação, senão o resultado ainda não existe).</small></p>
    <table class="tabela-oportunidades">
        <thead>
            <tr><th>Temperatura</th><th>Total</th><th>Fechadas</th><th>Perdidas</th><th>Ainda ativas</th><th>Taxa de fechamento</th></tr>
        </thead>
        <tbody>
        <?php foreach ($porTemperatura as $l): ?>
            <?php $icone = ['quente' => '🔥', 'morno' => '🌤️', 'frio' => '❄️'][$l['temperatura']] ?? ''; ?>
            <tr>
                <td><?= $icone ?> <?= e(ucfirst($l['temperatura'])) ?></td>
                <td><?= (int)$l['total'] ?></td>
                <td><?= (int)$l['fechadas'] ?></td>
                <td><?= (int)$l['perdidas'] ?></td>
                <td><?= (int)$l['ativas'] ?></td>
                <td><?= $l['taxa_fechamento'] === null ? '— (pouco dado ainda)' : $l['taxa_fechamento'] . '%' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p><small>Se "quente" não estiver fechando visivelmente mais que "frio" com volume suficiente pra comparar,
       vale revisar o critério de classificação no prompt.</small></p>
</div>

<div class="card">
    <h3>Depois da estagnação — pra onde foi</h3>
    <p><small>Das <?= (int)$totalEstagnacao ?> oportunidade(s) que a IA escalou pro consultor por estagnação
       (conversa sem avançar dado novo), o que aconteceu depois — o consultor conseguiu reverter, ou realmente
       esfriou de vez?</small></p>
    <?php if (!$posEstagnacao): ?>
        <p>Nenhuma escalação por estagnação registrada ainda.</p>
    <?php else: ?>
        <table class="tabela-oportunidades">
            <thead><tr><th>Etapa atual</th><th>Quantidade</th><th>%</th></tr></thead>
            <tbody>
            <?php foreach ($posEstagnacao as $etapa => $n): ?>
                <tr>
                    <td><?= e(etapaLabel($etapa)) ?></td>
                    <td><?= (int)$n ?></td>
                    <td><?= pctFunil((int)$n, $totalEstagnacao) ?></td>
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
