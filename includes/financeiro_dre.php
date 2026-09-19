<?php
/**
 * includes/financeiro_dre.php — DRE gerencial (Demonstração do Resultado
 * do Exercício), 19/09/2026, pedido direto: "dre para enviar para
 * contabiidade queremos exatamente nessa pegada" — José pediu pra olhar
 * como o financeiro/relatórios do JurídicoSaaS (repo irmão) resolveu isso
 * e replicar a mesma abordagem aqui. Portado de lá (`includes/financeiro_dre.php`,
 * `gerarDreFinanceiroPdf()`) — mesma ideia (agrupa categorias em blocos
 * com subtotal até chegar no resultado), mas **nunca copy-paste direto**:
 * grupos adaptados ao negócio da Fastcar (compra/revenda de veículo, não
 * escritório de advocacia — sem grupo "Repasses e Custas Processuais",
 * que não existe aqui) e reaproveita o cabeçalho/estilo de PDF já
 * existente em `includes/contratos_pdf.php` (`_pdfNovo()`/`_pdfCabecalho()`/
 * `_pdfTexto()`) em vez de duplicar a lógica de desenhar cabeçalho com
 * logo/marca — mesmo espírito de "reaproveitar o que já é genérico" do
 * resto do projeto.
 *
 * Uso interno de gestão — NÃO substitui o DRE contábil oficial que o
 * contador prepara pra fins fiscais/legais (mesmo aviso do JurídicoSaaS,
 * impresso no próprio PDF).
 */
require_once __DIR__ . '/contratos_pdf.php';

/**
 * Grupos de DESPESA do DRE, na ordem de exibição (a receita sempre vem
 * antes, fora desta lista). `fin_categorias.grupo_dre` já vinha
 * pré-semeado com esses valores desde a criação do módulo financeiro
 * (17/09/2026, `install/migrar.php`) — só faltava a tela de edição de
 * categoria expor o campo e este relatório existir. "Operacionais" é o
 * maior/mais específico da Fastcar (compra de veículo, comissão,
 * manutenção, despachante, combustível — o "custo do produto" de quem
 * compra/revende carro), por isso vem primeiro entre as despesas, logo
 * depois da receita — mesmo raciocínio de "custo direto antes de despesa
 * administrativa" de um DRE convencional.
 */
function finGruposDreOrdem(): array {
    return [
        'operacional'            => 'Despesas Operacionais',
        'pessoal'                => 'Despesas com Pessoal',
        'administrativas'        => 'Despesas Administrativas',
        'marketing'              => 'Despesas com Marketing',
        'impostos_contabilidade' => 'Impostos e Contabilidade',
        'outras'                 => 'Outras Despesas',
    ];
}

/** Grupos completos (inclui "Receita"), usados no `<select>` de categoria. */
function finGruposDreComRotulos(): array {
    return ['receita' => 'Receita'] + finGruposDreOrdem();
}

/**
 * Gera o PDF do DRE gerencial do período e retorna o objeto FPDF pronto
 * (chamador decide se manda pro navegador com Output('I',...) — não
 * salva em Drive/local, é sempre calculado na hora a partir dos
 * lançamentos atuais, nada pra versionar).
 *
 * Só entra no DRE lançamento com categoria vinculada (mesmo JOIN do
 * JurídicoSaaS) — lançamento sem categoria (`cliente_nome_manual` avulso,
 * por exemplo) fica de fora da soma. Diferente do original, isso nunca
 * fica silencioso aqui: conta quantos lançamentos do período ficaram de
 * fora por falta de categoria e imprime um aviso no rodapé do próprio PDF
 * — o contador não deveria receber um DRE que parece completo mas está
 * descontando/somando menos do que devia sem nenhum sinal disso.
 */
function finGerarDrePdf(PDO $db, string $de, string $ate): FPDF {
    $stmt = $db->prepare("
        SELECT c.grupo_dre, c.nome AS categoria_nome, c.icone, l.tipo, SUM(l.valor) AS total
        FROM fin_lancamentos l
        JOIN fin_categorias c ON c.id = l.categoria_id
        WHERE l.status != 'cancelado' AND COALESCE(l.data_pagamento, l.data_vencimento) BETWEEN ? AND ?
        GROUP BY c.grupo_dre, c.id
        ORDER BY c.grupo_dre, categoria_nome
    ");
    $stmt->execute([$de, $ate]);
    $linhas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $totalReceita = 0.0;
    $categoriasReceita = [];
    $gruposDespesa = []; // grupo => ['total' => float, 'categorias' => [['nome','icone','valor']]]
    foreach ($linhas as $l) {
        $grupo = $l['grupo_dre'] ?: ($l['tipo'] === 'receita' ? 'receita' : 'outras');
        $valor = (float)$l['total'];
        if ($grupo === 'receita' || $l['tipo'] === 'receita') {
            $totalReceita += $valor;
            $categoriasReceita[] = ['nome' => $l['categoria_nome'], 'icone' => $l['icone'], 'valor' => $valor];
        } else {
            if (!isset($gruposDespesa[$grupo])) $gruposDespesa[$grupo] = ['total' => 0.0, 'categorias' => []];
            $gruposDespesa[$grupo]['total'] += $valor;
            $gruposDespesa[$grupo]['categorias'][] = ['nome' => $l['categoria_nome'], 'icone' => $l['icone'], 'valor' => $valor];
        }
    }

    $totalDespesas = 0.0;
    foreach ($gruposDespesa as $g) $totalDespesas += $g['total'];
    // Sem grupo tipo "repasses" (pass-through, específico de escritório de
    // advocacia) pra separar operacional de líquido — resultado é um único
    // total, receita menos todos os grupos de despesa juntos.
    $resultadoLiquido = $totalReceita - $totalDespesas;

    $stmtSemCat = $db->prepare("
        SELECT COUNT(*) FROM fin_lancamentos
        WHERE status != 'cancelado' AND categoria_id IS NULL
          AND COALESCE(data_pagamento, data_vencimento) BETWEEN ? AND ?
    ");
    $stmtSemCat->execute([$de, $ate]);
    $semCategoria = (int)$stmtSemCat->fetchColumn();

    // ── PDF ────────────────────────────────────────────────────────────
    $pdf = _pdfNovo();
    _pdfCabecalho($pdf, 'DRE GERENCIAL');

    // 19/09/2026, "tem parta cadastrar os dados da empresa com logo para
    // ficar bacana dre" — razão social/CNPJ/endereço vêm de
    // admin/financeiro-empresa.php (config), pré-semeados com os mesmos
    // dados reais já usados no contrato (install/migrar.php) — nunca fica
    // em branco mesmo que a tela ainda não tenha sido salva manualmente.
    // Fallback pro texto fixo do contrato só na remota hipótese de rodar
    // isso antes da migração de seed (banco recém-criado sem passar por
    // migrar.php ainda).
    $razaoSocial = getConfig('empresa_razao_social') ?: 'FASTCAR SOLUTIONS';
    $cnpjEmpresa = getConfig('empresa_cnpj') ?: '66.934.500/0001-09';
    $enderecoEmpresa = getConfig('empresa_endereco') ?: '';
    $pdf->SetFont('Helvetica', 'B', 11);
    $pdf->Cell(0, 6, _pdfTexto($razaoSocial), 0, 1, 'L');
    $pdf->SetFont('Helvetica', '', 8.5);
    $pdf->SetTextColor(90, 90, 90);
    $pdf->Cell(0, 5, _pdfTexto('CNPJ: ' . $cnpjEmpresa), 0, 1, 'L');
    if ($enderecoEmpresa) {
        $pdf->MultiCell(0, 5, _pdfTexto($enderecoEmpresa));
    }
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Ln(2);

    $pdf->SetFont('Helvetica', 'B', 12);
    $pdf->Cell(0, 7, _pdfTexto('Período: ' . date('d/m/Y', strtotime($de)) . ' a ' . date('d/m/Y', strtotime($ate))), 0, 1, 'L');
    $pdf->SetFont('Helvetica', 'I', 8);
    $pdf->SetTextColor(120, 120, 120);
    $pdf->Cell(0, 5, _pdfTexto('Relatório de uso interno/gerencial — não substitui o DRE contábil oficial do contador.'), 0, 1, 'L');
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Ln(2);
    $pdf->SetDrawColor(180, 180, 180);
    $pdf->Line(20, $pdf->GetY(), 190, $pdf->GetY());
    $pdf->Ln(5);

    $linhaTotal = function (string $label, float $valor, bool $negrito = false, array $cor = [0, 0, 0]) use ($pdf) {
        $pdf->SetFont('Helvetica', $negrito ? 'B' : '', $negrito ? 11 : 10);
        $pdf->SetTextColor(...$cor);
        $pdf->Cell(120, 7, _pdfTexto($label), 0, 0);
        $pdf->Cell(0, 7, _pdfTexto(($valor < 0 ? '-' : '') . 'R$ ' . number_format(abs($valor), 2, ',', '.')), 0, 1, 'R');
        $pdf->SetTextColor(0, 0, 0);
    };
    $linhaDetalhe = function (string $label, float $valor) use ($pdf) {
        $pdf->SetFont('Helvetica', '', 9);
        $pdf->SetTextColor(90, 90, 90);
        $pdf->Cell(120, 5.5, _pdfTexto('   ' . $label), 0, 0);
        $pdf->Cell(0, 5.5, _pdfTexto('R$ ' . number_format($valor, 2, ',', '.')), 0, 1, 'R');
        $pdf->SetTextColor(0, 0, 0);
    };

    $linhaTotal('(+) RECEITA BRUTA', $totalReceita, true, [22, 101, 52]);
    foreach ($categoriasReceita as $c) $linhaDetalhe($c['icone'] . ' ' . $c['nome'], $c['valor']);
    $pdf->Ln(2);

    foreach (finGruposDreOrdem() as $grupoKey => $grupoLabel) {
        if (empty($gruposDespesa[$grupoKey])) continue;
        $g = $gruposDespesa[$grupoKey];
        $linhaTotal('(-) ' . mb_strtoupper($grupoLabel, 'UTF-8'), $g['total'], true, [153, 27, 27]);
        foreach ($g['categorias'] as $c) $linhaDetalhe($c['icone'] . ' ' . $c['nome'], $c['valor']);
        $pdf->Ln(2);
    }

    $pdf->Line(20, $pdf->GetY(), 190, $pdf->GetY());
    $pdf->Ln(3);

    $corLiquido = $resultadoLiquido >= 0 ? [22, 101, 52] : [153, 27, 27];
    $pdf->SetFillColor(248, 250, 252);
    $pdf->SetFont('Helvetica', 'B', 12);
    $pdf->SetTextColor(...$corLiquido);
    $pdf->Cell(120, 9, _pdfTexto('(=) RESULTADO LÍQUIDO DO PERÍODO'), 0, 0, 'L', true);
    $situacao = $resultadoLiquido >= 0 ? ' — SUPERÁVIT' : ' — DÉFICIT';
    $pdf->Cell(0, 9, _pdfTexto(($resultadoLiquido < 0 ? '-' : '') . 'R$ ' . number_format(abs($resultadoLiquido), 2, ',', '.') . $situacao), 0, 1, 'R', true);
    $pdf->SetTextColor(0, 0, 0);

    $pdf->Ln(8);
    // 19/09/2026, pergunta direta do usuário sobre reconhecimento de
    // receita de parcela de venda — este relatório é regime de CAIXA
    // (entrada/parcela lançadas na data de pagamento/vencimento, uma por
    // uma), não regime de competência (que reconheceria o valor total do
    // contrato na data da venda, com o saldo a receber virando ativo, não
    // receita futura) — a escrituração contábil formal pra fins fiscais é
    // sempre decisão/responsabilidade do contador, este relatório é só
    // controle gerencial interno de fluxo de caixa.
    $pdf->SetFont('Helvetica', '', 7.5);
    $pdf->SetTextColor(120, 120, 120);
    $pdf->MultiCell(0, 4, _pdfTexto('ℹ Regime de caixa: cada valor entra nesta soma na data em que foi efetivamente pago/recebido (entrada e cada parcela de venda são lançadas separadamente, conforme o vencimento) — não é o regime de competência da escrituração contábil oficial, que normalmente reconheceria o valor total do contrato na data da venda. O contrato assinado de cada negociação (armazenado no sistema) é o documento de suporte de cada lançamento.'));
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Ln(2);
    if ($semCategoria > 0) {
        $pdf->SetFont('Helvetica', 'B', 8);
        $pdf->SetTextColor(153, 27, 27);
        $plural = $semCategoria === 1 ? 'lançamento' : 'lançamentos';
        $pdf->MultiCell(0, 4, _pdfTexto("⚠ {$semCategoria} {$plural} do período sem categoria vinculada NÃO entraram nesta soma — confira em Financeiro > Lançamentos e vincule uma categoria pra esse(s) lançamento(s) aparecer(em) no DRE."));
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Ln(2);
    }
    $pdf->SetFont('Helvetica', 'I', 7);
    $pdf->SetTextColor(120, 120, 120);
    $pdf->Cell(0, 4, _pdfTexto('Gerado automaticamente em ' . date('d/m/Y H:i') . ' — relatório gerencial, sem validação contábil oficial.'), 0, 1);
    $pdf->SetTextColor(0, 0, 0);

    return $pdf;
}
