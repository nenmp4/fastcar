<?php
/**
 * Encerra em lote os leads parados na etapa 'whatsapp' — 25/09/2026,
 * pedido direto do usuário em cima do relatório
 * `relatorio_detalhado_2026-09-24_215851.pdf` (63 oportunidades em
 * 'whatsapp', todas "-- sem resumo da IA ainda --", a maioria "(sem
 * nome)"): confirmado via 2 perguntas diretas antes de codar — (1) é pra
 * fechar o LOTE INTEIRO desse relatório, não só 1 lead; (2) é pra FORÇAR
 * agora, sem esperar os 7 dias de silêncio que `encerrarLeadsSemResposta()`
 * (includes/oportunidades.php, cron/leads_sem_resposta.php) já aplica
 * sozinho — inclusive os mais recentes (ex: Bárbara, só 2 dias).
 *
 * Diferente do cron de 7 dias: aqui a referência de "silêncio" não
 * importa, todo mundo que ainda está em etapa='whatsapp' no momento em
 * que o script roda entra na lista — é uma limpeza pontual do backlog
 * acumulado, não a regra automática de todo dia (essa continua rodando
 * igual, sem mudança nenhuma neste script).
 *
 * Reaproveita marcarPerdida() (mesma função usada pelo cron de 7 dias e
 * por toda ação manual de "marcar perdida" no admin) — nunca um UPDATE
 * direto: grava motivo_perda, passa por mudarEtapa() (regra #6, sempre
 * grava oportunidade_historico) e vira 'perdido' (nunca 'sem_perfil' — o
 * cliente pode ter perfil de compra genuíno, só nunca respondeu, mesmo
 * raciocínio já documentado em encerrarLeadsSemResposta()).
 *
 * Nunca toca oportunidade que já saiu de 'whatsapp' (qualificacao_ia em
 * diante) — escopo é exatamente o do relatório que gerou este pedido.
 *
 * Uso:
 *   php install/encerrar_leads_parados_whatsapp.php              — só lista (dry-run)
 *   php install/encerrar_leads_parados_whatsapp.php --confirmar  — encerra de verdade
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Este script só roda via linha de comando (CLI).\n");
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/oportunidades.php';

$confirmar = in_array('--confirmar', $argv, true);
$db = getDB();

$candidatos = $db->query("
    SELECT o.id, o.created_at, c.nome, c.telefone, o.veiculo_marca, o.veiculo_modelo,
           (SELECT MAX(m.created_at) FROM whatsapp_mensagens m
            WHERE m.telefone = c.telefone AND m.direcao = 'in') AS ultima_msg_in
    FROM oportunidades o
    JOIN clientes c ON c.id = o.cliente_id
    WHERE o.etapa = 'whatsapp'
    ORDER BY o.created_at
")->fetchAll();

if (!$candidatos) {
    echo "Nenhuma oportunidade em etapa 'whatsapp' no momento. Nada pra encerrar.\n";
    exit(0);
}

echo "=== Candidatos em etapa 'whatsapp': " . count($candidatos) . " ===\n\n";

foreach ($candidatos as $op) {
    $referencia = $op['ultima_msg_in'] ?: $op['created_at'];
    $dias = (int)floor((time() - strtotime($referencia)) / 86400);
    $veiculo = trim(($op['veiculo_marca'] ?? '') . ' ' . ($op['veiculo_modelo'] ?? ''));
    $nome = $op['nome'] !== '' ? $op['nome'] : '(sem nome)';
    printf(
        "#%-5d %-30s %-15s %-20s recebido %s (%d dia(s) parado)\n",
        $op['id'],
        mb_substr($nome, 0, 30),
        $op['telefone'],
        $veiculo !== '' ? mb_substr($veiculo, 0, 20) : '-',
        $op['created_at'],
        $dias
    );
}

if (!$confirmar) {
    echo "\nDry-run — nada foi alterado. Rode com --confirmar pra encerrar de verdade.\n";
    exit(0);
}

echo "\n=== Encerrando " . count($candidatos) . " oportunidade(s) ===\n\n";

$log = __DIR__ . '/../storage/logs/encerramento_leads_whatsapp_' . date('Y-m-d_His') . '.log';
if (!is_dir(dirname($log))) mkdir(dirname($log), 0755, true);

$ok = 0;
$falhas = 0;
foreach ($candidatos as $op) {
    $motivo = 'Lote de leads parados em WhatsApp encerrado manualmente (limpeza de backlog, 25/09/2026) — nunca respondeu/avançou na qualificação.';
    try {
        marcarPerdida((int)$op['id'], $motivo, null, false);
        $linha = "[OK] #{$op['id']} {$op['telefone']} ({$op['nome']}) encerrado.";
        $ok++;
    } catch (Throwable $e) {
        $linha = "[FALHA] #{$op['id']} {$op['telefone']}: " . $e->getMessage();
        $falhas++;
    }
    echo $linha . "\n";
    file_put_contents($log, $linha . "\n", FILE_APPEND);
}

echo "\n✅ {$ok} encerrada(s), {$falhas} falha(s). Log: {$log}\n";
