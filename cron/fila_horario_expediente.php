<?php
/**
 * cron/fila_horario_expediente.php
 * Liga/desliga a fila de leads (`usuarios.disponivel`) sozinha nos
 * horários configurados (padrão 10:00 abertura / 19:20 fechamento) —
 * 22/09/2026, pedido José/Jean: "Colocar usuarios para ficar off line as
 * 19:20 ... online 10 horas da manha", confirmado como regra permanente
 * todo dia ("Quero que vire padrão todo dia"), não um toggle manual único.
 *
 * Quem está marcado `faltou_em` = hoje (admin/configuracoes.php, "marcar
 * falta") NUNCA é ligado de volta sozinho na abertura — só volta a ser
 * candidato normal no dia seguinte (a flag expira sozinha comparando
 * contra a data de hoje, sem precisar de nenhuma limpeza).
 *
 * Ver aplicarHorarioExpedienteFila() (includes/fila_leads.php) pra lógica
 * completa (dedup por dia via config, idempotente rodando várias vezes).
 *
 * Cron sugerido: a cada 5-10 min (precisa de granularidade fina pra
 * disparar perto do horário configurado, não é evento de 1x/dia como o
 * resumo de produtividade).
 */

define('ROOT', dirname(__DIR__));
require_once ROOT . '/includes/db.php';
require_once ROOT . '/includes/fila_leads.php';

function log_fila_horario(string $msg): void {
    $dir = ROOT . '/storage/logs';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $line = '[' . date('H:i:s') . '] ' . rtrim($msg, "\n") . "\n";
    file_put_contents($dir . '/fila_horario_expediente_' . date('Y-m-d') . '.log', $line, FILE_APPEND);
    echo $line;
}

// Mesma proteção contra rodadas sobrepostas já usada em cron/followup.php
// e cron/resumo_produtividade.php — flock() libera sozinho se o processo
// morrer no meio, nunca fica "preso" precisando de remoção manual.
$lockPath = ROOT . '/storage/fila_horario_expediente.lock';
$lockHandle = fopen($lockPath, 'c');
if (!$lockHandle || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    log_fila_horario('Já existe uma execução em andamento — abortando esta.');
    exit;
}

$resultado = aplicarHorarioExpedienteFila();

if ($resultado['abriu']) {
    log_fila_horario("Abertura da fila disparada — {$resultado['afetados']} consultor(es) ligado(s) (exceto quem faltou hoje).");
}
if ($resultado['fechou']) {
    log_fila_horario('Fechamento da fila disparado — todos os consultores desligados.');
}
if (!$resultado['abriu'] && !$resultado['fechou']) {
    log_fila_horario('Nada a fazer (fora do horário de disparo, ou já disparado hoje).');
}

flock($lockHandle, LOCK_UN);
fclose($lockHandle);
