<?php
/**
 * Rede de segurança da IA de qualificação (12/11/2026): responde mensagem de
 * cliente que ficou sem resposta porque o processamento do webhook morreu no
 * meio. Ver iaResgatarMensagensSemResposta() (chatbot-whatsapp/includes/mensagens.php).
 * Sugerido: a cada 2 min.
 */
define('ROOT', dirname(__DIR__));
@set_time_limit(300);
require_once ROOT . '/includes/db.php';
require_once ROOT . '/chatbot-whatsapp/includes/mensagens.php';

function log_resgate(string $msg): void {
    $dir = ROOT . '/storage/logs';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $line = '[' . date('H:i:s') . '] ' . $msg . "\n";
    file_put_contents($dir . '/ia_resgate_' . date('Y-m-d') . '.log', $line, FILE_APPEND);
    echo $line;
}

$lock = fopen(ROOT . '/storage/ia_resgate.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) exit;

$n = iaResgatarMensagensSemResposta(10, 'log_resgate');
if ($n > 0) log_resgate("{$n} mensagem(ns) sem resposta respondida(s).");

flock($lock, LOCK_UN);
fclose($lock);
