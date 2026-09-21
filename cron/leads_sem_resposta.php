<?php
/**
 * cron/leads_sem_resposta.php — encerra automaticamente (marca 'perdido')
 * oportunidade ainda em 'whatsapp'/'qualificacao_ia' cujo cliente não
 * respondeu nada há mais de LEADS_DIAS_SILENCIO_ENCERRAR dias (padrão: 7)
 * — 21/09/2026, pedido direto depois de ver ~25 leads sem nome que
 * receberam reengajamento (cron/followup.php) e nunca responderam,
 * presos pra sempre em "Qualificação IA". Lógica completa em
 * encerrarLeadsSemResposta() (includes/oportunidades.php).
 *
 * Cron sugerido: 1x/dia — não é urgente, o limite é de dias, não de
 * minutos/horas como o reengajamento.
 */

define('ROOT', dirname(__DIR__));
require_once ROOT . '/includes/db.php';
require_once ROOT . '/includes/oportunidades.php';

function log_leads_sem_resposta(string $msg): void {
    $dir = ROOT . '/storage/logs';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $line = '[' . date('H:i:s') . '] ' . rtrim($msg, "\n") . "\n";
    file_put_contents($dir . '/leads_sem_resposta_' . date('Y-m-d') . '.log', $line, FILE_APPEND);
    echo $line;
}

// Mesma trava contra rodadas sobrepostas já usada em cron/followup.php —
// aqui o risco é bem menor (nenhuma mensagem sai, só UPDATE de etapa),
// mas mesma defesa em profundidade já aplicada em outro cron de risco
// baixo (cron/resumo_produtividade.php).
$lockPath = ROOT . '/storage/leads_sem_resposta.lock';
$lockHandle = fopen($lockPath, 'c');
if (!$lockHandle || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    log_leads_sem_resposta('Já existe uma execução em andamento — abortando.');
    exit;
}

log_leads_sem_resposta('Iniciando em ' . date('d/m/Y H:i'));

$r = encerrarLeadsSemResposta();
log_leads_sem_resposta("{$r['encerrados']} lead(s) encerrado(s) por silêncio (de {$r['total_candidatos']} candidato(s) analisado(s)).");
if ($r['ids']) {
    log_leads_sem_resposta('Oportunidades: #' . implode(', #', $r['ids']));
}

log_leads_sem_resposta('Concluído.');

flock($lockHandle, LOCK_UN);
fclose($lockHandle);
