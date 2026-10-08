<?php
/**
 * Corrige o VALOR de comissão de compra já lançada com a fórmula ANTIGA
 * (errada) — 08/10/2026, achado real do usuário ("parece que comissão
 * está lannçando sobre valor pago pro cliente"): até esta correção,
 * `finRegistrarComissaoCompraFechada()` (includes/financeiro.php)
 * calculava a comissão sobre `valor_final` (o que foi pago ao vendedor,
 * em 2 faixas — até 20% da FIPE = 1,5%, acima = 1%), não sobre a FIPE de
 * verdade, apesar do pedido original já falar em "% da fipe". Corrigido
 * no código pra SEMPRE 1% de `valor_fipe_referencia` — este script
 * corrige retroativamente o que já tinha sido lançado com a fórmula
 * velha, recalculando como se a fórmula nova já tivesse rodado.
 *
 * Nunca chuta valor (regra #3) — sempre recalcula a partir de
 * `oportunidades.valor_fipe_referencia` da PRÓPRIA oportunidade do
 * lançamento, nunca um valor digitado à mão. Lançamento sem
 * `valor_fipe_referencia` preenchido (não deveria existir — a função só
 * gera com esse campo > 0 — mas fica como rede de segurança) fica de
 * fora, listado à parte pra revisão manual, nunca "corrigido" com valor
 * inventado. `status='cancelado'` sempre ignorado — comissão cancelada
 * de propósito nunca deve ser recalculada/reativada por este script.
 *
 * ⚠️ Antes de rodar com --confirmar: se algum consultor já recebeu o
 * valor ERRADO de verdade (dinheiro já saiu), corrigir só o REGISTRO no
 * sistema não ajusta o que já foi pago fisicamente — essa reconciliação
 * é decisão humana do financeiro, fora do escopo deste script. Revisar
 * a lista do dry-run com o financeiro antes de confirmar.
 *
 * Uso:
 *   php install/corrigir_comissao_compra_valor.php              — só lista (dry-run)
 *   php install/corrigir_comissao_compra_valor.php --confirmar  — aplica de verdade
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Este script só roda via linha de comando (CLI).\n");
}

require_once __DIR__ . '/../includes/db.php';

$confirmar = in_array('--confirmar', $argv, true);
$db = getDB();

$lancamentos = $db->query("
    SELECT l.id, l.descricao, l.valor AS valor_atual, l.status,
           o.id AS oportunidade_id, o.valor_fipe_referencia,
           o.veiculo_marca, o.veiculo_modelo, cl.nome AS cliente_nome
    FROM fin_lancamentos l
    JOIN oportunidades o ON o.id = l.oportunidade_id
    LEFT JOIN clientes cl ON cl.id = o.cliente_id
    WHERE l.origem = 'comissao_compra' AND l.status != 'cancelado'
    ORDER BY l.id
")->fetchAll(PDO::FETCH_ASSOC);

if (!$lancamentos) {
    echo "✅ Nenhum lançamento 'comissao_compra' ativo encontrado — nada a conferir.\n";
    exit(0);
}

$semFipe = [];
$divergentes = [];
$jaCorretos = 0;

foreach ($lancamentos as $l) {
    $fipe = (float)($l['valor_fipe_referencia'] ?? 0);
    if ($fipe <= 0) {
        $semFipe[] = $l;
        continue;
    }
    $valorCorreto = round($fipe * 1.0 / 100, 2);
    if (abs($valorCorreto - (float)$l['valor_atual']) < 0.01) {
        $jaCorretos++;
        continue;
    }
    $l['valor_correto'] = $valorCorreto;
    $l['fipe'] = $fipe;
    $divergentes[] = $l;
}

echo "=== Conferência de comissão de compra (fórmula: 1% do valor FIPE de referência) ===\n\n";
echo "  {$jaCorretos} já estão certos (nenhuma ação).\n";

if ($semFipe) {
    echo "\n  ⚠️  " . count($semFipe) . " lançamento(s) SEM valor_fipe_referencia na oportunidade — nunca recalculado automaticamente, revisar manual:\n";
    foreach ($semFipe as $l) {
        $veiculo = trim(($l['veiculo_marca'] ?? '') . ' ' . ($l['veiculo_modelo'] ?? '')) ?: 'veículo';
        printf("    #%d [oportunidade #%d, %s, %s] valor atual R$ %s\n",
            $l['id'], $l['oportunidade_id'], $veiculo, $l['cliente_nome'] ?: '(sem cliente)',
            number_format((float)$l['valor_atual'], 2, ',', '.'));
    }
}

if (!$divergentes) {
    echo "\n✅ Nenhum lançamento com valor divergente da fórmula nova — nada a corrigir.\n";
    exit(0);
}

echo "\n  " . count($divergentes) . " lançamento(s) com valor DIVERGENTE (fórmula antiga → nova):\n\n";
foreach ($divergentes as $l) {
    $veiculo = trim(($l['veiculo_marca'] ?? '') . ' ' . ($l['veiculo_modelo'] ?? '')) ?: 'veículo';
    printf(
        "  #%d [oportunidade #%d, %s, %s] R$ %s → R$ %s (1%% de R$ %s de FIPE)\n",
        $l['id'], $l['oportunidade_id'], $veiculo, $l['cliente_nome'] ?: '(sem cliente)',
        number_format((float)$l['valor_atual'], 2, ',', '.'),
        number_format($l['valor_correto'], 2, ',', '.'),
        number_format($l['fipe'], 2, ',', '.')
    );
}

if (!$confirmar) {
    echo "\n(dry-run — rode com --confirmar pra aplicar de verdade, depois de revisar com o financeiro)\n";
    exit(0);
}

$stmt = $db->prepare("
    UPDATE fin_lancamentos
    SET valor = ?,
        descricao = ?,
        updated_at = datetime('now','localtime')
    WHERE id = ?
");
$corrigidos = 0;
foreach ($divergentes as $l) {
    $veiculo = trim(($l['veiculo_marca'] ?? '') . ' ' . ($l['veiculo_modelo'] ?? '')) ?: 'veículo';
    $descricaoNova = sprintf(
        'Comissão de compra — %s — %s (oportunidade #%d) — 1.0%% do valor FIPE de referência (R$ %s)',
        $veiculo, $l['cliente_nome'] ?: '(sem cliente)', $l['oportunidade_id'],
        number_format($l['fipe'], 2, ',', '.')
    );
    $stmt->execute([$l['valor_correto'], $descricaoNova, $l['id']]);
    $corrigidos++;
}

echo "\n✅ {$corrigidos} lançamento(s) corrigido(s) — valor e descrição agora refletem 1% da FIPE de referência.\n";
