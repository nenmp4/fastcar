<?php
/**
 * cron/vendas_negociacao_fim_turno.php
 *
 * Espelha cron/leads_crm_preenchido_fim_turno.php (compra) pro lado de
 * VENDAS — 22/10/2026, "em vendas crm preenchido notificar mesmo do
 * sistema". Ao terminar o turno (MESMO horário configurado em
 * config.leads_crm_preenchido_notificar_hora — Configurações → Fila,
 * reaproveitado de propósito, "mesmo do sistema" — nunca um config
 * próprio de vendas, é o mesmo conceito de fim de turno pros dois
 * funis), manda pro(s) número(s) de notificação genérica
 * (config.notificacao_leads_whatsapp) a lista de leads de VENDA que
 * chegaram HOJE e ainda estão parados em etapa='negociacao' (já
 * qualificados pela IA, esperando o vendedor assumir de verdade —
 * vendas não tem uma etapa própria tipo 'crm_preenchido', a transição
 * qualificacao_ia→negociacao já É esse momento).
 *
 * Mesmo padrão de dedup-por-dia + flock() de
 * cron/leads_crm_preenchido_fim_turno.php — idempotente, roda a cada
 * poucos minutos, só dispara 1x por dia (e só marca como enviado se pelo
 * menos 1 número de notificação recebeu de verdade).
 *
 * Cron sugerido: a cada 5-10 min (precisa de granularidade fina pra
 * disparar perto do horário configurado, não é evento de madrugada) —
 * mesma linha de install/setup_crontab.sh do equivalente de compra.
 */

define('ROOT', dirname(__DIR__));
require_once ROOT . '/includes/db.php';
require_once ROOT . '/includes/oportunidades.php'; // leadsCrmPreenchidoHorarioNotificar() — mesmo horário configurado, reaproveitado de propósito
require_once ROOT . '/includes/vendas.php'; // notificarVendasEmNegociacaoFimTurno()

function log_vendas_fim_turno(string $msg): void {
    $dir = ROOT . '/storage/logs';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $line = '[' . date('H:i:s') . '] ' . rtrim($msg, "\n") . "\n";
    file_put_contents($dir . '/vendas_negociacao_fim_turno_' . date('Y-m-d') . '.log', $line, FILE_APPEND);
    echo $line;
}

// Mesma proteção contra rodadas sobrepostas já usada no equivalente de
// compra — flock() libera sozinho se o processo morrer no meio, nunca
// fica "preso" precisando de remoção manual. Lock PRÓPRIO (nunca o mesmo
// arquivo do cron de compra) — os dois podem rodar ao mesmo tempo sem
// disputar nada entre si.
$lockPath = ROOT . '/storage/vendas_negociacao_fim_turno.lock';
$lockHandle = fopen($lockPath, 'c');
if (!$lockHandle || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    log_vendas_fim_turno('Já existe uma execução em andamento — abortando esta.');
    exit;
}

$hoje = date('Y-m-d');
$agora = date('H:i');
$dedupKey = "vendas_negociacao_fim_turno_enviado_{$hoje}";

if ($agora < leadsCrmPreenchidoHorarioNotificar()) {
    log_vendas_fim_turno('Ainda não chegou no horário configurado — nada a fazer.');
} elseif (getConfig($dedupKey)) {
    log_vendas_fim_turno('Já enviado hoje — nada a fazer.');
} else {
    $resultado = notificarVendasEmNegociacaoFimTurno();
    if ($resultado['ok']) {
        setConfig($dedupKey, '1');
        log_vendas_fim_turno("Enviado — {$resultado['total']} lead(s) de venda parado(s) em negociação hoje.");
    } else {
        $motivo = $resultado['motivo'] ?? 'falha desconhecida';
        log_vendas_fim_turno("Falhou ao enviar ({$motivo}) — tentará de novo na próxima rodada.");
    }
}

flock($lockHandle, LOCK_UN);
fclose($lockHandle);
