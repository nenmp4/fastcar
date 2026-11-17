<?php
/**
 * cron/lembrete_visitas.php — todo dia, no horário configurado (padrão
 * 08:00, Configurações → Fila), manda pro Jean (WhatsApp + e-mail) as
 * visitas agendadas na Fastcar de hoje + prévia de amanhã. Ver
 * includes/lembrete_visitas.php. Roda a cada 10 min; dedup por dia + flock().
 */

define('ROOT', dirname(__DIR__));
require_once ROOT . '/includes/db.php';
require_once ROOT . '/includes/lembrete_visitas.php';

function log_lembrete_visitas(string $msg): void {
    $dir = ROOT . '/storage/logs';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $line = '[' . date('H:i:s') . '] ' . rtrim($msg, "\n") . "\n";
    file_put_contents($dir . '/lembrete_visitas_' . date('Y-m') . '.log', $line, FILE_APPEND);
    echo $line;
}

$lock = fopen(ROOT . '/storage/lembrete_visitas.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    log_lembrete_visitas('Já existe uma execução em andamento — abortando esta.');
    exit;
}

$hoje = date('Y-m-d');
$dedup = "lembrete_visitas_enviado_{$hoje}";

// Lembrete pro CLIENTE que pediu (botão do formulário) — dedup por venda
// (lembrete_visita_enviado_para), independente do envio pro Jean.
if (lembreteVisitasAtivo() && date('H:i') >= lembreteVisitasHorario()) {
    $rc = enviarLembretesVisitaClientes($hoje);
    if ($rc['enviados'] || $rc['falhas']) {
        log_lembrete_visitas("Clientes: {$rc['enviados']} lembrete(s) enviado(s), {$rc['falhas']} falha(s).");
    }
}

if (!lembreteVisitasAtivo()) {
    // desligado em Configurações
} elseif (date('H:i') < lembreteVisitasHorario()) {
    // ainda não chegou no horário
} elseif (getConfig($dedup)) {
    // já enviado hoje
} else {
    $r = enviarLembreteVisitas($hoje);
    if ($r['ok']) {
        setConfig($dedup, '1');
        log_lembrete_visitas("Enviado — {$r['hoje']} visita(s) hoje, {$r['amanha']} amanhã; WhatsApp {$r['whatsapp']}, e-mail {$r['emails']}.");
    } else {
        log_lembrete_visitas('Falhou (' . ($r['motivo'] ?? '?') . ') — tenta de novo na próxima rodada.');
    }
}

flock($lock, LOCK_UN);
fclose($lock);
