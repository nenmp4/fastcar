<?php
/**
 * Gera retroativamente a receita (entrada + parcelas) + comissão do
 * vendedor pra negociações de VENDA que já estavam `etapa='vendido'` antes
 * dessas funções existirem, ou que foram importadas de outra fonte
 * (`zapsignImportarContratoVendaComoNegociacaoManual()`,
 * `install/importar_crm_antigo.php` — a Fase 2 de venda, quando plugada)
 * sem nunca passar por `mudarEtapaVenda()` — mesma classe do bug já
 * corrigido do lado de compra (ver
 * install/gerar_lancamentos_fechados_retroativos.php), agora pro lado de
 * venda: 24/09/2026, pedido direto "precisamos agora buscar as receitas
 * dos contratos de vendas".
 *
 * Reaproveita as MESMAS funções de produção (`finGerarReceitaVendaAssinatura()`/
 * `finRegistrarComissaoVendaFechada()`, `includes/financeiro.php`), agora
 * com o parâmetro `$dataVenda` (novo) que faz as duas coisas: usa a data
 * REAL da venda (não "hoje") e, pra receita, pula o Asaas por completo —
 * uma venda de meses atrás nunca pode gerar cobrança real/link de
 * pagamento vivo agora (mesmo raciocínio de nunca recriar automação "isso
 * está acontecendo agora" pra dado histórico, já aplicado do lado de
 * compra). A entrada nasce 'pago' (já foi recebida — sabemos disso porque
 * a venda está `vendido`); as parcelas nascem 'pendente' com o vencimento
 * real calculado a partir da data da venda — o status individual de cada
 * parcela no passado é incerto sem registro de pagamento real (regra #3,
 * nunca chuta), fica pro financeiro marcar manualmente as que já sabe que
 * foram pagas.
 *
 * Idempotente: `finGerarReceitaVendaAssinatura()` já checa
 * `finContarLancamentosVenda()` (QUALQUER lançamento pra aquela venda,
 * inclusive um parcelamento manual já gerado antes) e
 * `finRegistrarComissaoVendaFechada()` checa por `origem='comissao_venda'`
 * — rodar este script 2x, ou depois de uma venda normal já ter gerado
 * tudo via `mudarEtapaVenda()`, nunca duplica nada.
 *
 * "conciliar com veículos" (pedido de acompanhamento, mesma conversa) — o
 * relatório sempre mostra placa/marca/modelo de cada venda, pra dar pra
 * cruzar direto com a coluna "Venda" de `admin/veiculos.php` sem precisar
 * abrir cada negociação uma por uma.
 *
 * Candidatas: `vendas.etapa='vendido'` com `preco_venda>0` e SEM nenhum
 * `fin_lancamentos` ainda (mesmo critério de `finContarLancamentosVenda()`
 * — nunca reaproveita id de venda que já tem QUALQUER lançamento, nem
 * manual nem automático). Não gera receita quando a venda é 100% à vista
 * (`preco_venda - valor_pago_contratacao <= 0`) ou sem `prazo_quitacao_meses`
 * definido — mesma regra de `finGerarReceitaVendaAssinatura()`, fica pro
 * lançamento manual nesses casos.
 *
 * Uso:
 *   php install/gerar_lancamentos_vendas_retroativos.php              — só lista (dry-run)
 *   php install/gerar_lancamentos_vendas_retroativos.php --confirmar  — aplica de verdade
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Este script só roda via linha de comando (CLI).\n");
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/financeiro.php';

$confirmar = in_array('--confirmar', $argv, true);
$db = getDB();

$candidatas = $db->query("
    SELECT v.id, v.preco_venda, v.valor_pago_contratacao, v.prazo_quitacao_meses,
           v.data_venda, v.responsavel_id, v.comprador_nome,
           o.veiculo_marca, o.veiculo_modelo, o.veiculo_placa,
           fc.id AS colaborador_id
    FROM vendas v
    LEFT JOIN oportunidades o ON o.id = v.oportunidade_id
    LEFT JOIN fin_colaboradores fc ON fc.usuario_id = v.responsavel_id AND fc.status = 'ativo'
    WHERE v.etapa = 'vendido'
      AND v.preco_venda IS NOT NULL AND v.preco_venda > 0
      AND NOT EXISTS (SELECT 1 FROM fin_lancamentos l WHERE l.venda_id = v.id)
    ORDER BY v.data_venda
")->fetchAll(PDO::FETCH_ASSOC);

if (!$candidatas) {
    echo "✅ Nenhuma venda 'vendido' sem lançamento nenhum — nada a fazer.\n";
    exit(0);
}

echo count($candidatas) . " venda(s) 'vendido' sem nenhum lançamento financeiro:\n\n";
$geraiamReceita = 0;
$geraiamComissao = 0;
foreach ($candidatas as $c) {
    $veiculo = trim(($c['veiculo_marca'] ?? '') . ' ' . ($c['veiculo_modelo'] ?? '')) ?: 'veículo';
    $placa = $c['veiculo_placa'] ?: '—';

    $valorEntrada = (float)($c['valor_pago_contratacao'] ?? 0);
    $restante = (float)$c['preco_venda'] - $valorEntrada;
    $numParcelas = (int)($c['prazo_quitacao_meses'] ?? 0);
    $receitaInfo = '— receita: ';
    if ($restante <= 0) {
        $receitaInfo .= 'não (à vista, sem saldo restante — só a entrada, fica pro lançamento manual)';
    } elseif ($numParcelas < 1) {
        $receitaInfo .= 'não (sem prazo de quitação definido)';
    } else {
        $valorParcela = round($restante / $numParcelas, 2);
        $receitaInfo .= sprintf('SIM, entrada R$ %s (pago) + %d parcela(s) de R$ %s (pendente)',
            number_format($valorEntrada, 2, ',', '.'), $numParcelas, number_format($valorParcela, 2, ',', '.'));
        $geraiamReceita++;
    }

    $comissaoInfo = '— comissão: ';
    if ($valorEntrada <= 0) {
        $comissaoInfo .= 'não (sem valor de entrada)';
    } elseif (empty($c['colaborador_id'])) {
        $comissaoInfo .= 'não (vendedor sem colaborador ativo vinculado)';
    } else {
        $comissaoInfo .= sprintf('SIM, R$ %s (5%% da entrada)', number_format($valorEntrada * 0.05, 2, ',', '.'));
        $geraiamComissao++;
    }

    printf(
        "  #%d | %s | placa %s | comprador %s | vendida em %s\n    %s\n    %s\n",
        $c['id'], $veiculo, $placa, $c['comprador_nome'] ?: '—',
        $c['data_venda'] ?: '—', $receitaInfo, $comissaoInfo
    );
}
echo "\n{$geraiamReceita} gerariam receita, {$geraiamComissao} gerariam comissão também.\n";

if (!$confirmar) {
    echo "\n(dry-run — rode com --confirmar pra gerar de verdade)\n";
    exit(0);
}

$processadas = 0;
foreach ($candidatas as $c) {
    $dataVenda = $c['data_venda'] ? substr((string)$c['data_venda'], 0, 10) : date('Y-m-d');
    finGerarReceitaVendaAssinatura((int)$c['id'], $c['responsavel_id'] ? (int)$c['responsavel_id'] : null, $dataVenda);
    finRegistrarComissaoVendaFechada((int)$c['id'], $dataVenda);
    $processadas++;
}

echo "\n✅ {$processadas} venda(s) processada(s) — receita/comissão geradas onde aplicável.\n";
