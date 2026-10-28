<?php
/**
 * Follow-up automático dos leads de vendas sem resposta (includes/vendas_followup.php).
 * Rodando a cada 5 min: se estiver ATIVO (Configurações → 📣 Follow-up),
 * manda 1 mensagem por rodada — devagar de propósito, pra não queimar o
 * número. Só seg–sáb 10h–18h, até 80/dia. Desliga sozinho quando a fila acaba.
 *   *\/5 * * * * php cron/vendas_followup.php >> storage/logs/vendas_followup.log 2>&1
 */
if (php_sapi_name() !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/vendas_followup.php';

if (!vendasFollowupAtivo()) exit(0);
$lock = fopen(dirname(__DIR__) . '/storage/vendas_followup.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) exit(0);

// Pequeno atraso sorteado (0–90s) pra não sair sempre no mesmo segundo.
sleep(random_int(0, 90));
$r = vendasFollowupEnviarProximo();
$log = '[' . date('d/m H:i:s') . '] ' . $r['status'] . (isset($r['telefone']) ? ' ' . $r['telefone'] : '') . (!empty($r['erro']) ? ' — ' . $r['erro'] : '');
echo $log . "\n";
setConfig('vendas_followup_ultimo_log', $log);

if ($r['status'] === 'fila_vazia' && empty($r['erro'])) {
    setConfig('vendas_followup_ativo', '0'); // acabou a lista
}
if ($r['status'] === 'falhou') {
    $falhas = (int)getConfig('vendas_followup_falhas_seguidas') + 1;
    setConfig('vendas_followup_falhas_seguidas', (string)$falhas);
    if ($falhas >= 3) setConfig('vendas_followup_ativo', '0'); // pausa: provável instância caída
} elseif ($r['status'] === 'enviado') {
    setConfig('vendas_followup_falhas_seguidas', '0');
}
