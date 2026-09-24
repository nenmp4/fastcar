<?php
/**
 * Gera retroativamente a despesa de compra + comissão automática do
 * consultor pra oportunidades que JÁ estavam `etapa='fechado'` antes
 * dessas funções existirem — 24/09/2026, achado real: screenshot da aba
 * "✅ Fechadas" (admin/index.php?etapa=fechado) mostrando vários negócios
 * reais (Bianca, Pierre, Maria Aparecida, Gabriel Patrick etc — a maioria
 * vinda da importação do CRM antigo, install/importar_crm_antigo.php,
 * que grava `etapa='fechado'` direto no INSERT sem passar por
 * mudarEtapa()) seguido de "teria que refletir no finaneiro" — nenhuma
 * dessas oportunidades tinha despesa/comissão em fin_lancamentos, porque
 * finRegistrarDespesaCompraFechada()/finRegistrarComissaoCompraFechada()
 * (includes/financeiro.php) só disparam DE DENTRO de mudarEtapa(), no
 * exato momento da transição — uma oportunidade que nasceu ou foi
 * importada já fechada nunca passa por ali.
 *
 * Nunca duplica: reaproveita as MESMAS funções de produção (idempotentes
 * por oportunidade_id+origem, já testadas) — rodar este script 2x, ou
 * rodar depois de um fechamento normal via mudarEtapa() já ter gerado o
 * lançamento, nunca cria uma 2ª linha.
 *
 * Despesa: gera pra toda oportunidade fechada com valor_final > 0 sem
 * lançamento 'fechamento_compra' ainda. Comissão: só gera se a
 * oportunidade tiver valor_fipe_referencia preenchido E o consultor
 * (fechado_por) tiver colaborador ATIVO cadastrado — sem isso, mesma
 * regra de sempre (nunca chuta), fica de fora do relatório de "geraria".
 *
 * Uso:
 *   php install/gerar_lancamentos_fechados_retroativos.php              — só lista (dry-run)
 *   php install/gerar_lancamentos_fechados_retroativos.php --confirmar  — aplica de verdade
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Este script só roda via linha de comando (CLI).\n");
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/financeiro.php';

$confirmar = in_array('--confirmar', $argv, true);
$db = getDB();

$candidatos = $db->query("
    SELECT o.id, o.veiculo_marca, o.veiculo_modelo, o.valor_final, o.valor_fipe_referencia,
           o.fechado_por, o.data_compra, cl.nome AS cliente_nome,
           fc.id AS colaborador_id
    FROM oportunidades o
    JOIN clientes cl ON cl.id = o.cliente_id
    LEFT JOIN fin_colaboradores fc ON fc.usuario_id = o.fechado_por AND fc.status = 'ativo'
    WHERE o.etapa = 'fechado'
      AND o.valor_final IS NOT NULL AND o.valor_final > 0
      AND NOT EXISTS (
          SELECT 1 FROM fin_lancamentos l
          WHERE l.oportunidade_id = o.id AND l.origem = 'fechamento_compra'
      )
    ORDER BY o.data_compra
")->fetchAll(PDO::FETCH_ASSOC);

if (!$candidatos) {
    echo "✅ Nenhuma oportunidade fechada sem lançamento de despesa — nada a fazer.\n";
    exit(0);
}

echo count($candidatos) . " oportunidade(s) fechada(s) sem despesa de compra lançada:\n\n";
$geraiamComissao = 0;
foreach ($candidatos as $c) {
    $veiculo = trim(($c['veiculo_marca'] ?? '') . ' ' . ($c['veiculo_modelo'] ?? '')) ?: 'veículo';
    $comissaoInfo = '— comissão: ';
    if (empty($c['valor_fipe_referencia'])) {
        $comissaoInfo .= 'não (sem FIPE cadastrada)';
    } elseif (empty($c['colaborador_id'])) {
        $comissaoInfo .= 'não (consultor sem colaborador ativo vinculado)';
    } else {
        $pct = ((float)$c['valor_final'] / (float)$c['valor_fipe_referencia']) * 100;
        $taxa = $pct <= 20 ? 1.5 : 1.0;
        $comissaoInfo .= sprintf('SIM, R$ %s (%.1f%% — fechou a %.1f%% da FIPE)', number_format((float)$c['valor_final'] * $taxa / 100, 2, ',', '.'), $taxa, $pct);
        $geraiamComissao++;
    }
    printf(
        "  #%d | %s | %s | R$ %s | fechado em %s %s\n",
        $c['id'], $c['cliente_nome'], $veiculo,
        number_format((float)$c['valor_final'], 2, ',', '.'),
        $c['data_compra'] ?: '—', $comissaoInfo
    );
}
echo "\n{$geraiamComissao} delas também gerariam comissão automática do consultor.\n";

if (!$confirmar) {
    echo "\n(dry-run — rode com --confirmar pra gerar de verdade as despesas/comissões acima)\n";
    exit(0);
}

$despesas = 0;
foreach ($candidatos as $c) {
    finRegistrarDespesaCompraFechada((int)$c['id'], (float)$c['valor_final'], $c['fechado_por'] ? (int)$c['fechado_por'] : null);
    finRegistrarComissaoCompraFechada((int)$c['id'], (float)$c['valor_final'], $c['fechado_por'] ? (int)$c['fechado_por'] : null);
    $despesas++;
}

echo "\n✅ {$despesas} despesa(s) de compra gerada(s) retroativamente (+ comissão, quando aplicável).\n";
