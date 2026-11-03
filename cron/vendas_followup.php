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

// Sinal de vida: mostra em Configurações se o cron está mesmo rodando na VPS.
setConfig('vendas_followup_cron_visto', date('Y-m-d H:i:s'));
// Mesmo ritmo/trava da rodada disparada pelo navegador — ver
// vendasFollowupTalvezRodar() (includes/vendas_followup.php).
$r = vendasFollowupTalvezRodar();
if ($r) echo $r['log'] . "\n";
