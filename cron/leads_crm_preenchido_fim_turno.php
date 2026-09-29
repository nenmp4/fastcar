<?php
/**
 * cron/leads_crm_preenchido_fim_turno.php
 *
 * Ao terminar o turno (padrão 19:30, configurável em
 * config.leads_crm_preenchido_notificar_hora — Configurações → Fila),
 * manda pro(s) número(s) de notificação genérica
 * (config.notificacao_leads_whatsapp) a lista de leads que chegaram HOJE
 * e ainda estão parados em etapa='crm_preenchido' (já qualificados pela
 * IA, esperando o consultor assumir de verdade) — 29/09/2026, "ao
 * terminar turno 7:30 enviar todos leads crm preenchido que chegarem
 * para numero de notificação".
 *
 * Mesmo padrão de dedup-por-dia + flock() de
 * cron/fila_horario_expediente.php — idempotente, roda a cada poucos
 * minutos, só dispara 1x por dia (e só marca como enviado se pelo menos
 * 1 número de notificação recebeu de verdade, mesmo espírito de
 * cron/resumo_produtividade.php — se falhar, tenta de novo na próxima
 * rodada em vez de desistir o dia inteiro).
 *
 * Cron sugerido: a cada 5-10 min (precisa de granularidade fina pra
 * disparar perto do horário configurado, não é evento de madrugada).
 */

define('ROOT', dirname(__DIR__));
require_once ROOT . '/includes/db.php';
require_once ROOT . '/includes/oportunidades.php';

function log_leads_fim_turno(string $msg): void {
    $dir = ROOT . '/storage/logs';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $line = '[' . date('H:i:s') . '] ' . rtrim($msg, "\n") . "\n";
    file_put_contents($dir . '/leads_crm_preenchido_fim_turno_' . date('Y-m-d') . '.log', $line, FILE_APPEND);
    echo $line;
}

// Mesma proteção contra rodadas sobrepostas já usada em cron/followup.php,
// cron/resumo_produtividade.php e cron/fila_horario_expediente.php —
// flock() libera sozinho se o processo morrer no meio, nunca fica "preso"
// precisando de remoção manual.
$lockPath = ROOT . '/storage/leads_crm_preenchido_fim_turno.lock';
$lockHandle = fopen($lockPath, 'c');
if (!$lockHandle || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    log_leads_fim_turno('Já existe uma execução em andamento — abortando esta.');
    exit;
}

$hoje = date('Y-m-d');
$agora = date('H:i');
$dedupKey = "leads_crm_preenchido_enviado_{$hoje}";

if ($agora < leadsCrmPreenchidoHorarioNotificar()) {
    log_leads_fim_turno('Ainda não chegou no horário configurado — nada a fazer.');
} elseif (getConfig($dedupKey)) {
    log_leads_fim_turno('Já enviado hoje — nada a fazer.');
} else {
    $resultado = notificarLeadsCrmPreenchidoFimTurno();
    if ($resultado['ok']) {
        setConfig($dedupKey, '1');
        log_leads_fim_turno("Enviado — {$resultado['total']} lead(s) parado(s) em CRM preenchido hoje.");
    } else {
        $motivo = $resultado['motivo'] ?? 'falha desconhecida';
        log_leads_fim_turno("Falhou ao enviar ({$motivo}) — tentará de novo na próxima rodada.");
    }
}

flock($lockHandle, LOCK_UN);
fclose($lockHandle);
