<?php
/**
 * cron/recuperacao_leads.php
 * Recuperação em lotes dos leads perdidos durante o bloqueio da instância
 * principal (19-20/09/2026) — ver includes/recuperacao_leads.php pro
 * desenho completo. Processa um lote pequeno por execução (10, confirmado
 * com o usuário: "vamos espalhar o envio cada 15 minutos blocos de 10") —
 * nunca tudo de uma vez, pra não repetir o mesmo padrão de rajada que já
 * causou bloqueio de número antes neste projeto.
 *
 * Cron sugerido: a cada 15 min, só enquanto durar a recuperação — REMOVER
 * do crontab assim que `recuperacaoTelefonesFaltando()` voltar vazio (não é
 * um cron permanente do sistema, é uma tarefa de recuperação pontual deste
 * incidente).
 */

define('ROOT', dirname(__DIR__));
require_once ROOT . '/includes/db.php';
require_once ROOT . '/includes/recuperacao_leads.php';

function log_recuperacao(string $msg): void {
    $dir = ROOT . '/storage/logs';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $line = '[' . date('H:i:s') . '] ' . rtrim($msg, "\n") . "\n";
    file_put_contents($dir . '/recuperacao_leads_' . date('Y-m-d') . '.log', $line, FILE_APPEND);
    echo $line;
}

// Mesma trava contra rodadas sobrepostas do cron/followup.php — esse cron
// especificamente manda mensagem pra gente nova, então sobreposição aqui é
// ainda mais arriscada (duplicaria a "desculpa pela demora" + a qualificação
// pro mesmo lead recém-recuperado).
$lockPath = ROOT . '/storage/recuperacao_leads.lock';
$lockHandle = fopen($lockPath, 'c');
if (!$lockHandle || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    log_recuperacao('Já existe uma execução em andamento — abortando esta.');
    exit;
}

log_recuperacao('Iniciando lote de recuperação em ' . date('d/m/Y H:i'));

$restantesAntes = count(recuperacaoTelefonesFaltando('2026-09-18'));
log_recuperacao("Restantes antes deste lote: {$restantesAntes}");

if ($restantesAntes === 0) {
    log_recuperacao('Nada pra recuperar — pode remover este cron do crontab.');
    exit;
}

$resultado = recuperacaoProcessarLote(10, '2026-09-18');
log_recuperacao("Processado(s): {$resultado['processados']} | Pulado(s): {$resultado['pulados']}");
foreach ($resultado['detalhe'] as $d) {
    if ($d['ok']) {
        log_recuperacao("  OK — {$d['telefone']} → oportunidade #{$d['oportunidade_id']}");
    } else {
        log_recuperacao("  PULADO — {$d['telefone']} — {$d['motivo']}");
    }
}

$restantesDepois = count(recuperacaoTelefonesFaltando('2026-09-18'));
log_recuperacao("Restantes depois deste lote: {$restantesDepois}");
