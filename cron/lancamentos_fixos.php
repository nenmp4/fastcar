<?php
/**
 * cron/lancamentos_fixos.php — gera automaticamente a próxima ocorrência
 * mensal de toda despesa marcada como "Fixa" (natureza='fixa') no
 * financeiro — 19/09/2026, pedido direto: "todas despesas fixas pode
 * lançar todo mês automático". Ver finGerarDespesasFixasDoMes()
 * (includes/financeiro.php) pra a lógica completa: agrupa em cadeias via
 * recorrencia_origem_id, idempotente (nunca duplica no mesmo mês), copia
 * o valor MAIS RECENTE da série (reflete reajuste feito à mão), e marcar
 * o último lançamento da série como 'cancelado' interrompe a geração
 * seguinte, sem precisar de campo novo.
 *
 * Cron sugerido: 1x/dia, de madrugada — idempotente e barato o bastante
 * pra rodar todo dia (só gera de verdade quando o mês vira e ainda não
 * tem lançamento gerado pra ele), sem precisar de agendamento fino tipo
 * "só no dia 1".
 */

define('ROOT', dirname(__DIR__));
require_once ROOT . '/includes/db.php';
require_once ROOT . '/includes/financeiro.php';

function log_lancamentos_fixos(string $msg): void {
    $dir = ROOT . '/storage/logs';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $line = '[' . date('H:i:s') . '] ' . rtrim($msg, "\n") . "\n";
    file_put_contents($dir . '/lancamentos_fixos_' . date('Y-m-d') . '.log', $line, FILE_APPEND);
    echo $line;
}

log_lancamentos_fixos('Iniciando em ' . date('d/m/Y H:i'));

$r = finGerarDespesasFixasDoMes();
log_lancamentos_fixos("{$r['criadas']} lançamento(s) gerado(s), {$r['puladas']} cadeia(s) sem necessidade de gerar.");
foreach ($r['erros'] as $erro) {
    log_lancamentos_fixos("Erro: {$erro}");
}

log_lancamentos_fixos('Concluído.');
