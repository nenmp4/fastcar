<?php
/**
 * includes/financeiro_excel.php — Relatório financeiro do período em Excel
 * (.xlsx), 13/11/2026, "gerar relatório financeiro para gerar em excel".
 * Mesmos números do Extrato/DRE em PDF (mesmos critérios: regime de caixa,
 * data = COALESCE(data_pagamento, data_vencimento), "realizado" = só
 * status='pago', "previsto" = status='pendente'), só que em planilha pra
 * filtrar/somar no Excel. 3 abas:
 *   - Resumo: receitas/despesas realizadas e previstas, saldo, atrasados;
 *   - DRE: blocos por grupo_dre e categoria (só pago, igual o DRE padrão);
 *   - Lançamentos: todo lançamento não cancelado do período, 1 por linha,
 *     com valor numérico (positivo receita, negativo despesa) e datas reais.
 */
require_once __DIR__ . '/xlsx.php';
require_once __DIR__ . '/financeiro_extrato.php'; // _finExtratoSoma()
require_once __DIR__ . '/financeiro_dre.php';     // finGruposDreOrdem()

function finGerarRelatorioExcel(PDO $db, string $de, string $ate): string
{
    $recPago = _finExtratoSoma($db, 'receita', $de, $ate, null, 'pago');
    $recPrev = _finExtratoSoma($db, 'receita', $de, $ate, null, 'previsto');
    $despPago = _finExtratoSoma($db, 'despesa', $de, $ate, null, 'pago');
    $despPrev = _finExtratoSoma($db, 'despesa', $de, $ate, null, 'previsto');
    $despFixa = _finExtratoSoma($db, 'despesa', $de, $ate, 'fixa', 'pago');
    $despVar = _finExtratoSoma($db, 'despesa', $de, $ate, 'variavel', 'pago');

    $st = $db->prepare("SELECT tipo, COALESCE(SUM(valor),0) FROM fin_lancamentos
                        WHERE status='atrasado' AND COALESCE(data_pagamento, data_vencimento) BETWEEN ? AND ?
                        GROUP BY tipo");
    $st->execute([$de, $ate]);
    $atrasado = ['receita' => 0.0, 'despesa' => 0.0];
    foreach ($st->fetchAll(PDO::FETCH_NUM) as [$t, $v]) $atrasado[$t] = (float)$v;

    $razao = getConfig('empresa_razao_social') ?: 'FASTCAR SOLUTIONS';
    $cnpj = getConfig('empresa_cnpj') ?: '66.934.500/0001-09';
    $periodo = date('d/m/Y', strtotime($de)) . ' a ' . date('d/m/Y', strtotime($ate));
    $m = fn(float $v, bool $b = false) => ['v' => $v, 's' => $b ? 'moeda_negrito' : 'moeda'];
    $n = fn(string $t) => ['v' => $t, 's' => 'negrito'];

    $x = new XlsxSimples();

    // ── Aba 1: Resumo ─────────────────────────────────────────────────
    $x->aba('Resumo', [42, 20], [
        [['v' => 'Relatório financeiro — ' . $razao, 's' => 'titulo']],
        ['CNPJ: ' . $cnpj],
        ['Período: ' . $periodo],
        [],
        [['v' => 'Item', 's' => 'cab'], ['v' => 'Valor', 's' => 'cab']],
        ['Receitas realizadas (pagas)', $m($recPago)],
        ['Despesas realizadas (pagas)', $m($despPago)],
        ['   Despesas fixas', $m($despFixa)],
        ['   Despesas variáveis', $m($despVar)],
        [$n('Saldo do período (realizado)'), $m($recPago - $despPago, true)],
        [],
        ['Receitas previstas (pendentes)', $m($recPrev)],
        ['Despesas previstas (pendentes)', $m($despPrev)],
        ['Receitas atrasadas', $m($atrasado['receita'])],
        ['Despesas atrasadas', $m($atrasado['despesa'])],
        [],
        ['Regime de caixa: cada lançamento entra na data em que foi pago/recebido (ou no vencimento, se ainda não pago).'],
        ['Previsto e atrasado NÃO entram no saldo realizado. Gerado em ' . date('d/m/Y H:i') . '.'],
    ]);

    // ── Aba 2: DRE (só pago, mesmo critério do DRE Gerencial padrão) ──
    $st = $db->prepare("
        SELECT c.grupo_dre, c.nome AS categoria, l.tipo, SUM(l.valor) AS total
        FROM fin_lancamentos l
        JOIN fin_categorias c ON c.id = l.categoria_id
        WHERE l.status = 'pago' AND COALESCE(l.data_pagamento, l.data_vencimento) BETWEEN ? AND ?
        GROUP BY c.grupo_dre, c.id
        ORDER BY c.grupo_dre, categoria
    ");
    $st->execute([$de, $ate]);
    $receitas = [];
    $grupos = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $l) {
        $g = $l['grupo_dre'] ?: ($l['tipo'] === 'receita' ? 'receita' : 'outras');
        if ($g === 'receita' || $l['tipo'] === 'receita') {
            $receitas[] = [$l['categoria'], (float)$l['total']];
        } else {
            $grupos[$g][] = [$l['categoria'], (float)$l['total']];
        }
    }
    $dre = [
        [['v' => 'DRE Gerencial — ' . $periodo, 's' => 'titulo']],
        [],
        [['v' => 'Conta', 's' => 'cab'], ['v' => 'Valor', 's' => 'cab']],
    ];
    $totRec = array_sum(array_column($receitas, 1));
    $dre[] = [$n('RECEITA BRUTA'), $m($totRec, true)];
    foreach ($receitas as [$cat, $v]) $dre[] = ['   ' . $cat, $m($v)];
    $totDesp = 0.0;
    $ordem = finGruposDreOrdem();
    foreach (array_keys($grupos) as $g) if (!isset($ordem[$g])) $ordem[$g] = ucfirst((string)$g);
    foreach ($ordem as $g => $rotulo) {
        if (empty($grupos[$g])) continue;
        $tg = array_sum(array_column($grupos[$g], 1));
        $totDesp += $tg;
        $dre[] = [];
        $dre[] = [$n('(-) ' . mb_strtoupper($rotulo)), $m(-$tg, true)];
        foreach ($grupos[$g] as [$cat, $v]) $dre[] = ['   ' . $cat, $m(-$v)];
    }
    $dre[] = [];
    $dre[] = [$n('RESULTADO LÍQUIDO DO PERÍODO'), $m($totRec - $totDesp, true)];
    $st = $db->prepare("SELECT COUNT(*) FROM fin_lancamentos WHERE status='pago' AND categoria_id IS NULL
                        AND COALESCE(data_pagamento, data_vencimento) BETWEEN ? AND ?");
    $st->execute([$de, $ate]);
    $semCat = (int)$st->fetchColumn();
    if ($semCat > 0) {
        $dre[] = [];
        $dre[] = ['⚠️ ' . $semCat . ' lançamento(s) pago(s) sem categoria ficaram fora do DRE — categorize em Financeiro → Lançamentos.'];
    }
    $x->aba('DRE', [52, 20], $dre);

    // ── Aba 3: Lançamentos ────────────────────────────────────────────
    $st = $db->prepare("
        SELECT l.*, c.nome AS categoria_nome, c.grupo_dre,
               fo.nome AS fornecedor_nome, fc.nome AS funcionario_nome
        FROM fin_lancamentos l
        LEFT JOIN fin_categorias c ON c.id = l.categoria_id
        LEFT JOIN fin_fornecedores fo ON fo.id = l.fornecedor_id
        LEFT JOIN fin_colaboradores fc ON fc.id = l.funcionario_id
        WHERE l.status != 'cancelado' AND COALESCE(l.data_pagamento, l.data_vencimento) BETWEEN ? AND ?
        ORDER BY COALESCE(l.data_pagamento, l.data_vencimento) ASC, l.id ASC
    ");
    $st->execute([$de, $ate]);
    $gruposRot = finGruposDreComRotulos();
    $linhas = [[
        ['v' => 'Nº', 's' => 'cab'], ['v' => 'Vencimento', 's' => 'cab'], ['v' => 'Pagamento', 's' => 'cab'],
        ['v' => 'Tipo', 's' => 'cab'], ['v' => 'Natureza', 's' => 'cab'], ['v' => 'Categoria', 's' => 'cab'],
        ['v' => 'Grupo DRE', 's' => 'cab'], ['v' => 'Descrição', 's' => 'cab'], ['v' => 'Cliente/fornecedor', 's' => 'cab'],
        ['v' => 'Forma de pagamento', 's' => 'cab'], ['v' => 'Status', 's' => 'cab'], ['v' => 'Parcela', 's' => 'cab'],
        ['v' => 'Origem', 's' => 'cab'], ['v' => 'Venda', 's' => 'cab'], ['v' => 'Oportunidade', 's' => 'cab'],
        ['v' => 'Valor (R$)', 's' => 'cab'],
    ]];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $l) {
        $valor = (float)$l['valor'];
        if ($l['tipo'] === 'despesa') $valor = -$valor;
        $parcela = '';
        if ($l['parcela_numero'] !== null && $l['parcela_numero'] !== '') {
            $parcela = (int)$l['parcela_numero'] === 0 ? 'Entrada'
                : (int)$l['parcela_numero'] . ($l['parcela_total'] ? '/' . (int)$l['parcela_total'] : '');
        }
        $vinculo = $l['cliente_nome_manual'] ?: ($l['fornecedor_nome'] ?: ($l['funcionario_nome'] ?: ''));
        $linhas[] = [
            (int)$l['id'],
            ['v' => $l['data_vencimento'] ?: null, 's' => 'data'],
            ['v' => $l['data_pagamento'] ?: null, 's' => 'data'],
            $l['tipo'] === 'receita' ? 'Receita' : 'Despesa',
            ['fixa' => 'Fixa', 'variavel' => 'Variável'][$l['natureza'] ?? ''] ?? '',
            (string)($l['categoria_nome'] ?? ''),
            $l['grupo_dre'] ? ($gruposRot[$l['grupo_dre']] ?? (string)$l['grupo_dre']) : '',
            (string)$l['descricao'],
            (string)$vinculo,
            (string)($l['forma_pagamento'] ?? ''),
            ucfirst((string)$l['status']),
            $parcela,
            (string)($l['origem'] ?? ''),
            $l['venda_id'] ? (int)$l['venda_id'] : null,
            $l['oportunidade_id'] ? (int)$l['oportunidade_id'] : null,
            $m($valor),
        ];
    }
    if (count($linhas) === 1) $linhas[] = ['Nenhum lançamento no período.'];
    $x->aba('Lançamentos', [7, 12, 12, 9, 10, 26, 22, 44, 28, 18, 11, 9, 18, 8, 12, 15], $linhas);

    return $x->gerar();
}
