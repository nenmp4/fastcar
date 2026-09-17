<?php
/**
 * cron/resumo_produtividade.php
 * Resumo diário de produtividade (visão geral da empresa) pro WhatsApp
 * pessoal de quem tem perfil=supervisor — 15/09/2026, pedido José/Jean
 * ("supervisores acompanhar a produtividade"). Reaproveita exatamente as
 * mesmas métricas de dashboardSuperAdmin() (includes/dashboard.php), sem
 * duplicar query nenhuma.
 *
 * Cron sugerido: 1x/dia, às 19h30 (fim do expediente).
 */

define('ROOT', dirname(__DIR__));
require_once ROOT . '/includes/db.php';
require_once ROOT . '/includes/security.php';
require_once ROOT . '/includes/whatsapp_config.php';
require_once ROOT . '/includes/dashboard.php';

function log_resumo_produtividade(string $msg): void {
    $dir = ROOT . '/storage/logs';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $line = '[' . date('H:i:s') . '] ' . rtrim($msg, "\n") . "\n";
    file_put_contents($dir . '/resumo_produtividade_' . date('Y-m-d') . '.log', $line, FILE_APPEND);
    echo $line;
}

// Trava contra rodadas sobrepostas — mesma classe de bug achada e corrigida
// em cron/followup.php (17/09/2026, investigando bloqueio de número no
// WhatsApp): o comentário original abaixo já cogitava "retry manual,
// crontab duplicado" como cenário real, mas o dedup por dia (getConfig/
// setConfig) faz checagem e gravação em passos separados, nunca atômico —
// se as duas rodadas caírem nesse intervalo, as duas passam pela checagem
// antes de qualquer uma gravar o guard, e cada supervisor recebe o resumo
// 2x. Risco bem menor que o do followup.php (roda 1x/dia, não a cada
// 30min), mas é a mesma falha estrutural, então mesma correção.
$lockPath = ROOT . '/storage/resumo_produtividade.lock';
$lockHandle = fopen($lockPath, 'c');
if (!$lockHandle || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    log_resumo_produtividade('Já existe uma execução em andamento — abortando esta pra evitar resumo duplicado.');
    exit;
}

$db = getDB();
log_resumo_produtividade('Iniciando em ' . date('d/m/Y H:i'));

// Dedup por dia — se o cron rodar 2x no mesmo dia (retry manual, crontab
// duplicado), não manda o resumo de novo.
$guardKey = 'resumo_prod_enviado_' . date('Y-m-d');
if (getConfig($guardKey)) {
    log_resumo_produtividade('Já enviado hoje, encerrando.');
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
    exit;
}

$supervisores = $db->query("
    SELECT nome, whatsapp FROM usuarios
    WHERE perfil = 'supervisor' AND bloqueado = 0 AND whatsapp IS NOT NULL AND whatsapp != ''
")->fetchAll();

if (!$supervisores) {
    log_resumo_produtividade('Nenhum supervisor com WhatsApp cadastrado — nada a enviar.');
    exit;
}

$m = dashboardSuperAdmin();

$fmtValor = fn(float $v) => 'R$ ' . number_format($v, 2, ',', '.');
$conversao = $m['taxa_conversao'] !== null ? $m['taxa_conversao'] . '%' : 'sem dados ainda';

$msg = "📊 *Resumo de produtividade — " . date('d/m/Y') . "*\n\n"
     . "🟢 Oportunidades ativas: {$m['ativas']}\n"
     . "⚠️ Atrasadas: {$m['atrasadas']}\n"
     . "🆕 Novas hoje: {$m['novas_hoje']} | Novas na semana: {$m['novas_semana']}\n"
     . "✅ Fechadas no mês: {$m['fechadas_mes']} ({$fmtValor($m['valor_fechado_mes'])})\n"
     . "📈 Taxa de conversão: {$conversao}\n\n"
     . "Resumo automático diário do Fastcar CRM.";

$enviados = 0;
foreach ($supervisores as $sup) {
    $ok = zapiEnviarTexto($sup['whatsapp'], $msg);
    log_resumo_produtividade(($ok ? '✅' : '❌') . " Resumo → {$sup['nome']} ({$sup['whatsapp']})");
    if ($ok) $enviados++;
}

// Só marca como enviado se pelo menos 1 foi de verdade — se todos falharem
// (Z-API fora do ar, por exemplo), o cron tenta de novo na próxima rodada
// em vez de desistir pro dia inteiro.
if ($enviados > 0) {
    setConfig($guardKey, date('Y-m-d H:i:s'));
}

log_resumo_produtividade("{$enviados}/" . count($supervisores) . ' supervisor(es) notificado(s).');
log_resumo_produtividade('Concluído.');

flock($lockHandle, LOCK_UN);
fclose($lockHandle);
