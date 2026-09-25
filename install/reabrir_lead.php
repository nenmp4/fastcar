<?php
/**
 * Reabre 1 lead específico que foi marcado 'perdido'/'sem_perfil' — 25/09/2026,
 * pedido direto: "barbara tem voltar", depois de rodar
 * install/encerrar_leads_parados_whatsapp.php --confirmar (que fechou o
 * lote inteiro de leads mudos em 'whatsapp', incluindo a Bárbara, a mais
 * recente da lista — só 2 dias parada).
 *
 * Não existe botão na tela pra isso de propósito: o card "Mudar etapa" de
 * admin/oportunidade.php só aparece quando a etapa NÃO está em
 * ['fechado', 'perdido', 'sem_perfil'] — uma vez encerrada, a única forma
 * de voltar é aqui (CLI, mesma disciplina de toda ação de recuperação em
 * lote deste projeto: dry-run por padrão, --confirmar pra aplicar).
 *
 * Volta pra etapa='whatsapp' (o estado original antes do encerramento em
 * lote) e limpa motivo_perda — nunca deixa um motivo de "perda" velho
 * pendurado numa oportunidade que voltou a estar ativa. Passa por
 * mudarEtapa() normalmente (regra #6, grava oportunidade_historico).
 *
 * Uso:
 *   php install/reabrir_lead.php --telefone=5531XXXXXXXXX              — só mostra (dry-run)
 *   php install/reabrir_lead.php --telefone=5531XXXXXXXXX --confirmar  — reabre de verdade
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Este script só roda via linha de comando (CLI).\n");
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/oportunidades.php';

$confirmar = in_array('--confirmar', $argv, true);

$telefoneArg = null;
foreach ($argv as $a) {
    if (str_starts_with($a, '--telefone=')) {
        $telefoneArg = substr($a, strlen('--telefone='));
    }
}

if (!$telefoneArg) {
    echo "Uso: php install/reabrir_lead.php --telefone=5531XXXXXXXXX [--confirmar]\n";
    exit(1);
}

$telefone = normalizarTelefone($telefoneArg);
$db = getDB();

$stmt = $db->prepare("
    SELECT o.id, o.etapa, o.motivo_perda, o.created_at, c.nome, c.telefone
    FROM oportunidades o
    JOIN clientes c ON c.id = o.cliente_id
    WHERE c.telefone = ?
    ORDER BY o.id DESC
    LIMIT 1
");
$stmt->execute([$telefone]);
$op = $stmt->fetch();

if (!$op) {
    echo "Nenhum cliente encontrado com o telefone {$telefone} (normalizado de '{$telefoneArg}').\n";
    exit(1);
}

echo "Encontrado: #{$op['id']} " . ($op['nome'] !== '' ? $op['nome'] : '(sem nome)') . " -- {$op['telefone']}\n";
echo "Etapa atual: {$op['etapa']}\n";
if ($op['motivo_perda']) {
    echo "Motivo de perda registrado: {$op['motivo_perda']}\n";
}

if (!in_array($op['etapa'], ['perdido', 'sem_perfil'], true)) {
    echo "\n⚠️  Essa oportunidade não está encerrada (etapa='{$op['etapa']}') — nada pra reabrir.\n";
    exit(0);
}

if (!$confirmar) {
    echo "\nDry-run — nada foi alterado. Rode com --confirmar pra reabrir de verdade (volta pra etapa 'whatsapp').\n";
    exit(0);
}

$db->prepare("UPDATE oportunidades SET motivo_perda = NULL WHERE id = ?")->execute([$op['id']]);
mudarEtapa((int)$op['id'], 'whatsapp', null, 'Reaberta manualmente — encerramento em lote pegou por engano.');

echo "\n✅ Oportunidade #{$op['id']} reaberta, voltou pra etapa 'whatsapp'.\n";
