<?php
/**
 * Follow-up dos leads de vendas sem resposta — versão de linha de comando
 * (opcional). O jeito normal é pela tela: Configurações → 📣 Follow-up dos
 * leads sem resposta (simular / iniciar / pausar), com o envio feito pelo
 * cron/vendas_followup.php. Lógica em includes/vendas_followup.php.
 *
 *   php install/vendas_followup_formulario.php            # simulação: lista quem receberia
 *   php install/vendas_followup_formulario.php --dias=30  # janela maior
 */
if (php_sapi_name() !== 'cli') { http_response_code(403); exit('Só pela linha de comando.'); }
require_once __DIR__ . '/../includes/vendas_followup.php';

$dias = VENDAS_FU_DIAS;
foreach ($argv as $a) if (preg_match('/^--dias=(\d+)$/', $a, $m)) $dias = max(1, (int)$m[1]);

$r = vendasFollowupMontarFila($dias);
if ($r['erro']) echo "⚠️ {$r['erro']}\n";
if ($r['conversas'] !== null) echo "Instância de vendas: {$r['conversas']} conversa(s) individuais.\n";
if ($r['exemplo']) echo "Exemplo cru: {$r['exemplo']}\n";
echo count($r['fila']) . ' contato(s) pra receber o link. Pulados: ' . json_encode($r['pulados']) . "\n";
foreach ($r['fila'] as $tel => $c) echo "  {$tel}  " . ($c['nome'] ?: '(sem nome)') . "  [{$c['origem']}]\n";
echo "Simulação — nada foi enviado. Pra enviar: Configurações → 📣 Follow-up → Iniciar.\n";
