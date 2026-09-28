<?php
/**
 * Correção pontual — 28/09/2026: José Bonifácio de Souza Nogueira
 * (telefone 5511972254163) tinha 2 oportunidades ATIVAS pro mesmo carro —
 * #56 (entrada normal pelo WhatsApp, travada em qualificacao_ia) e #125
 * (cadastro manual na Frota, achada só depois) — confirmado com o usuário:
 * "mesmo carro, duplicado por engano". #125 é a pasta real (é a que tem o
 * veículo/valor cadastrados); #56 precisa fechar como duplicata, nunca
 * apagada (mantém rastro/histórico).
 *
 * Usa marcarPerdida() (função central, regra #6 — nunca UPDATE direto),
 * que já grava oportunidade_historico sozinha.
 *
 * Uso:
 *   php install/fechar_duplicata_jose_bonifacio_op56.php              — só mostra (dry-run)
 *   php install/fechar_duplicata_jose_bonifacio_op56.php --confirmar  — corrige de verdade
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Este script só roda via linha de comando (CLI).\n");
}

require_once __DIR__ . '/../includes/oportunidades.php';

$TELEFONE = '5511972254163';
$OP_DUPLICADA_ID = 56;
$OP_REAL_ID = 125;

$confirmar = in_array('--confirmar', $argv, true);
$db = getDB();

$opDuplicada = $db->query("SELECT o.*, c.nome, c.telefone FROM oportunidades o JOIN clientes c ON c.id = o.cliente_id WHERE o.id = {$OP_DUPLICADA_ID}")->fetch(PDO::FETCH_ASSOC);
$opReal = $db->query("SELECT o.*, c.nome, c.telefone FROM oportunidades o JOIN clientes c ON c.id = o.cliente_id WHERE o.id = {$OP_REAL_ID}")->fetch(PDO::FETCH_ASSOC);

if (!$opDuplicada || !$opReal) {
    fwrite(STDERR, "Oportunidade #{$OP_DUPLICADA_ID} ou #{$OP_REAL_ID} não encontrada — aborta, confere manualmente antes de rodar.\n");
    exit(1);
}
if (normalizarTelefone($opDuplicada['telefone']) !== normalizarTelefone($TELEFONE) || normalizarTelefone($opReal['telefone']) !== normalizarTelefone($TELEFONE)) {
    fwrite(STDERR, "Telefone de uma das 2 oportunidades não é mais o esperado ({$TELEFONE}) — aborta, alguém já deve ter mexido nesses registros.\n");
    exit(1);
}
if ($opDuplicada['etapa'] === 'perdido' || $opDuplicada['etapa'] === 'sem_perfil') {
    echo "Oportunidade #{$OP_DUPLICADA_ID} já está encerrada (etapa='{$opDuplicada['etapa']}') — nada a fazer.\n";
    exit(0);
}

echo "=== Fechar duplicata: José Bonifácio de Souza Nogueira ({$TELEFONE}) ===\n";
echo $confirmar ? "Modo: CONFIRMAR (grava de verdade)\n\n" : "Modo: DRY-RUN (só mostra, nada é gravado)\n\n";
echo "Oportunidade #{$OP_DUPLICADA_ID} (DUPLICATA a fechar): etapa atual = {$opDuplicada['etapa']}, veículo = {$opDuplicada['veiculo_marca']} {$opDuplicada['veiculo_modelo']}\n";
echo "Oportunidade #{$OP_REAL_ID} (pasta REAL, fica intocada): etapa atual = {$opReal['etapa']}, veículo = {$opReal['veiculo_marca']} {$opReal['veiculo_modelo']}, valor_final = {$opReal['valor_final']}\n";

if (!$confirmar) {
    echo "\nDry-run — nada foi gravado. Rode com --confirmar pra corrigir de verdade.\n";
    exit(0);
}

marcarPerdida(
    $OP_DUPLICADA_ID,
    "Duplicata — mesmo veículo já registrado na oportunidade #{$OP_REAL_ID} (cadastrado manualmente na Frota), confirmado com o usuário em 28/09/2026.",
    $opDuplicada['responsavel_id'],
    false
);

echo "\n✅ Oportunidade #{$OP_DUPLICADA_ID} marcada 'perdido' (duplicata). Pasta real continua #{$OP_REAL_ID}.\n";
echo "Veja em: /admin/oportunidade.php?id={$OP_DUPLICADA_ID}\n";
