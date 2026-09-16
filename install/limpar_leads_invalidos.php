<?php
/**
 * Limpa clientes/oportunidades FALSAS geradas pelo incidente de flood de
 * mensagens duplicadas (15/09/2026, ver CLAUDE.md) — antes do guard em
 * chatbot-whatsapp/includes/mensagens.php::processarMensagemZapi() existir,
 * evento de presença/status/conexão da Z-API (sem mensagem de verdade por
 * trás) virava "mídia não suportada" e criava cliente+oportunidade com um
 * "telefone" que na verdade era o ID do evento (ex: 164059295019141, 15
 * dígitos) — nunca um telefone brasileiro de verdade. O guard já existe
 * pra eventos NOVOS (não cria mais nada); este script limpa o que já foi
 * criado antes do guard, achado real em produção 16/09/2026 (consultora
 * com 42 "leads" acumulados, a maioria dessa sobra).
 *
 * Critério de "falso": telefone não bate com o formato normalizarTelefone()
 * produz pra número real (55 + DDD + 8 ou 9 dígitos = 12 ou 13 dígitos, só
 * números). Sinal forte e específico — nenhum contato de WhatsApp de
 * verdade gera telefone fora desse tamanho.
 *
 * Segurança: NUNCA apaga cliente/oportunidade que já chegou em
 * 'atendimento' ou além (contato humano de verdade) — mesmo que o
 * telefone bata no critério de "falso" (não deveria acontecer, mas por
 * segurança extra fica de fora do apagamento automático, listado à parte
 * pra revisão manual).
 *
 * Uso:
 *   php install/limpar_leads_invalidos.php              — só lista (dry-run)
 *   php install/limpar_leads_invalidos.php --confirmar  — apaga de verdade
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Este script só roda via linha de comando (CLI).\n");
}

require_once __DIR__ . '/../includes/db.php';

$confirmar = in_array('--confirmar', $argv, true);
$db = getDB();

$ETAPAS_TOCADAS = ['atendimento', 'negociacao', 'presencial', 'fechado', 'sem_perfil', 'perdido'];

$candidatos = $db->query("
    SELECT c.id AS cliente_id, c.nome, c.telefone, c.created_at,
           COUNT(o.id) AS total_oportunidades,
           GROUP_CONCAT(DISTINCT o.etapa) AS etapas
    FROM clientes c
    LEFT JOIN oportunidades o ON o.cliente_id = c.id
    WHERE LENGTH(c.telefone) NOT IN (12, 13) OR c.telefone GLOB '*[^0-9]*'
    GROUP BY c.id
    ORDER BY c.created_at
")->fetchAll();

if (!$candidatos) {
    echo "Nenhum cliente com telefone em formato inválido encontrado. Nada pra limpar.\n";
    exit(0);
}

$seguros = [];
$paraRevisao = [];
foreach ($candidatos as $c) {
    $etapasDesseCliente = $c['etapas'] ? explode(',', $c['etapas']) : [];
    $tocado = array_intersect($etapasDesseCliente, $ETAPAS_TOCADAS);
    if ($tocado) {
        $paraRevisao[] = $c;
    } else {
        $seguros[] = $c;
    }
}

echo "=== Candidatos com telefone inválido: " . count($candidatos) . " ===\n\n";

if ($paraRevisao) {
    echo "⚠️  " . count($paraRevisao) . " ficaram de fora da limpeza automática (já têm oportunidade em etapa avançada — revisar manualmente):\n";
    foreach ($paraRevisao as $c) {
        echo "  - cliente #{$c['cliente_id']} telefone={$c['telefone']} nome=\"{$c['nome']}\" etapas=({$c['etapas']})\n";
    }
    echo "\n";
}

echo ($confirmar ? "Apagando" : "Seriam apagados") . " " . count($seguros) . " cliente(s):\n";
foreach ($seguros as $c) {
    echo "  - cliente #{$c['cliente_id']} telefone={$c['telefone']} nome=\"{$c['nome']}\" oportunidades={$c['total_oportunidades']} criado_em={$c['created_at']}\n";
}

if (!$seguros) {
    echo "\nNada seguro pra apagar automaticamente.\n";
    exit(0);
}

if (!$confirmar) {
    echo "\nModo dry-run — nada foi apagado. Rode com --confirmar pra apagar de verdade:\n";
    echo "  php install/limpar_leads_invalidos.php --confirmar\n";
    exit(0);
}

$logPath = __DIR__ . '/../storage/logs/limpeza_leads_' . date('Y-m-d_His') . '.log';
$log = [];
$totalOportunidadesApagadas = 0;

foreach ($seguros as $c) {
    $clienteId = (int)$c['cliente_id'];
    $db->beginTransaction();
    try {
        $oportunidadeIds = $db->query("SELECT id FROM oportunidades WHERE cliente_id = {$clienteId}")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($oportunidadeIds as $opId) {
            $db->prepare("DELETE FROM oportunidade_historico WHERE oportunidade_id = ?")->execute([$opId]);
            $db->prepare("DELETE FROM oportunidade_documentos WHERE oportunidade_id = ?")->execute([$opId]);
            $db->prepare("DELETE FROM oportunidade_pendencias_pos_venda WHERE oportunidade_id = ?")->execute([$opId]);
            $db->prepare("DELETE FROM contratos WHERE oportunidade_id = ?")->execute([$opId]);
            $db->prepare("DELETE FROM vendas WHERE oportunidade_id = ?")->execute([$opId]);
        }
        $db->prepare("DELETE FROM oportunidades WHERE cliente_id = ?")->execute([$clienteId]);
        $db->prepare("DELETE FROM whatsapp_mensagens WHERE telefone = ?")->execute([$c['telefone']]);
        $db->prepare("DELETE FROM whatsapp_sessoes WHERE telefone = ?")->execute([$c['telefone']]);
        $db->prepare("DELETE FROM clientes WHERE id = ?")->execute([$clienteId]);
        $db->commit();

        $totalOportunidadesApagadas += count($oportunidadeIds);
        $log[] = date('Y-m-d H:i:s') . " apagado cliente #{$clienteId} telefone={$c['telefone']} nome=\"{$c['nome']}\" oportunidades=" . count($oportunidadeIds);
    } catch (Throwable $e) {
        $db->rollBack();
        $log[] = date('Y-m-d H:i:s') . " ERRO ao apagar cliente #{$clienteId}: " . $e->getMessage();
        fwrite(STDERR, "Erro ao apagar cliente #{$clienteId}: " . $e->getMessage() . "\n");
    }
}

@mkdir(dirname($logPath), 0755, true);
@file_put_contents($logPath, implode("\n", $log) . "\n");

echo "\n✅ " . count($seguros) . " cliente(s) apagado(s), {$totalOportunidadesApagadas} oportunidade(s) junto. Log em " . basename($logPath) . "\n";
