<?php
/**
 * includes/financeiro_extrato.php — Extrato financeiro completo do
 * período em PDF, 19/09/2026, "permita gerar sempre extrato completo
 * para contado[r]" — pedido logo depois do DRE Gerencial. Portado de
 * `includes/financeiro_pdf.php::gerarRelatorioFinanceiroPdf()` do
 * JurídicoSaaS (repo irmão), mas **nunca copy-paste direto**: paisagem
 * (landscape) igual lá — a tabela tem colunas (vínculo, forma de
 * pagamento) que não cabem direito em retrato sem espremer a coluna de
 * valor — só que `_pdfNovo()` (`includes/contratos_pdf.php`) é fixo em
 * retrato (usado pelos contratos, nunca deve mudar), então este arquivo
 * monta o próprio cabeçalho em paisagem em vez de reaproveitar
 * `_pdfCabecalho()` (que assume largura de página A4 retrato, 210mm —
 * usar em paisagem, 297mm, deixaria a faixa navy só cobrindo 2/3 da
 * página). Reaproveita só `_pdfTexto()` (conversão UTF-8→ISO-8859-1, essa
 * sim independente de orientação de página).
 */
require_once __DIR__ . '/contratos_pdf.php';

if (!function_exists('_finExtratoSoma')) {
    function _finExtratoSoma(PDO $db, string $tipo, string $de, string $ate, ?string $natureza = null): float {
        $sql = "SELECT COALESCE(SUM(valor),0) FROM fin_lancamentos
                WHERE tipo=? AND status != 'cancelado'
                  AND COALESCE(data_pagamento, data_vencimento) BETWEEN ? AND ?";
        $params = [$tipo, $de, $ate];
        if ($natureza) { $sql .= " AND natureza=?"; $params[] = $natureza; }
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return (float)$stmt->fetchColumn();
    }
}

function finGerarExtratoPdf(PDO $db, string $de, string $ate): FPDF {
    $totalReceitas = _finExtratoSoma($db, 'receita', $de, $ate);
    $totalDespesas = _finExtratoSoma($db, 'despesa', $de, $ate);
    $despFixas = _finExtratoSoma($db, 'despesa', $de, $ate, 'fixa');
    $despVar = _finExtratoSoma($db, 'despesa', $de, $ate, 'variavel');
    $saldo = $totalReceitas - $totalDespesas;

    $stmt = $db->prepare("
        SELECT l.*, c.nome AS categoria_nome, c.icone,
               fo.nome AS fornecedor_nome, fc.nome AS funcionario_nome
        FROM fin_lancamentos l
        LEFT JOIN fin_categorias c ON c.id = l.categoria_id
        LEFT JOIN fin_fornecedores fo ON fo.id = l.fornecedor_id
        LEFT JOIN fin_colaboradores fc ON fc.id = l.funcionario_id
        WHERE l.status != 'cancelado' AND COALESCE(l.data_pagamento, l.data_vencimento) BETWEEN ? AND ?
        ORDER BY COALESCE(l.data_pagamento, l.data_vencimento) ASC
    ");
    $stmt->execute([$de, $ate]);
    $lancamentos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $razaoSocial = getConfig('empresa_razao_social') ?: 'FASTCAR SOLUTIONS';
    $cnpjEmpresa = getConfig('empresa_cnpj') ?: '66.934.500/0001-09';

    // ── PDF (paisagem — mesma decisão do JurídicoSaaS) ─────────────────
    $pdf = new FPDF('L', 'mm', 'A4');
    $pdf->SetMargins(15, 15, 15);
    $pdf->SetAutoPageBreak(true, 18);
    $pdf->AddPage();

    $pdf->SetFillColor(21, 23, 34); // navy da marca (#151722)
    $pdf->Rect(0, 0, 297, 20, 'F');
    $logoPath = dirname(__DIR__) . '/public/assets/logo.png';
    if (is_file($logoPath)) {
        try {
            if (@getimagesize($logoPath)) $pdf->Image($logoPath, 15, 3, 0, 14);
        } catch (Throwable $e) {
            // logo corrompida/formato não suportado — segue sem ela
        }
    }
    $pdf->SetY(4);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('Helvetica', 'B', 12);
    $pdf->Cell(0, 7, _pdfTexto('FASTCAR SOLUTIONS'), 0, 1, 'C');
    $pdf->SetFont('Helvetica', '', 8);
    $pdf->Cell(0, 6, _pdfTexto('EXTRATO FINANCEIRO COMPLETO'), 0, 1, 'C');
    $pdf->SetFillColor(47, 111, 237); // azul da marca (#2f6fed) — padronizado com o
    $pdf->Rect(0, 20, 297, 1.2, 'F');   // resto dos PDFs/e-mails, 23/09/2026 (era dourado)
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetY(26);

    $pdf->SetFont('Helvetica', 'B', 11);
    $pdf->Cell(0, 6, _pdfTexto($razaoSocial), 0, 1, 'L');
    $pdf->SetFont('Helvetica', '', 8.5);
    $pdf->SetTextColor(90, 90, 90);
    $pdf->Cell(0, 5, _pdfTexto('CNPJ: ' . $cnpjEmpresa), 0, 1, 'L');
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Ln(2);
    $pdf->SetFont('Helvetica', 'B', 12);
    $pdf->Cell(0, 7, _pdfTexto('Extrato Financeiro — ' . date('d/m/Y', strtotime($de)) . ' a ' . date('d/m/Y', strtotime($ate))), 0, 1, 'L');
    $pdf->Ln(2);
    $pdf->SetDrawColor(180, 180, 180);
    $pdf->Line(15, $pdf->GetY(), 282, $pdf->GetY());
    $pdf->Ln(4);

    $pdf->SetFont('Helvetica', 'B', 10);
    $pdf->Cell(60, 7, _pdfTexto('Receitas'), 0, 0);
    $pdf->SetTextColor(22, 101, 52);
    $pdf->Cell(0, 7, _pdfTexto('R$ ' . number_format($totalReceitas, 2, ',', '.')), 0, 1);
    $pdf->SetTextColor(0, 0, 0);

    $pdf->SetFont('Helvetica', 'B', 10);
    $pdf->Cell(60, 7, _pdfTexto('Despesas'), 0, 0);
    $pdf->SetTextColor(153, 27, 27);
    $pdf->Cell(0, 7, _pdfTexto('R$ ' . number_format($totalDespesas, 2, ',', '.') . '  (Fixas: R$ ' . number_format($despFixas, 2, ',', '.') . ' | Variáveis: R$ ' . number_format($despVar, 2, ',', '.') . ')'), 0, 1);
    $pdf->SetTextColor(0, 0, 0);

    $pdf->SetFont('Helvetica', 'B', 11);
    $pdf->Cell(60, 8, _pdfTexto('Saldo do período'), 0, 0);
    $corSaldo = $saldo >= 0 ? [22, 101, 52] : [153, 27, 27];
    $pdf->SetTextColor(...$corSaldo);
    $situacao = $saldo >= 0 ? 'SUPERÁVIT' : 'DÉFICIT';
    $pdf->Cell(0, 8, _pdfTexto('R$ ' . number_format($saldo, 2, ',', '.') . '  —  ' . $situacao), 0, 1);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Ln(4);

    // Tabela — larguras somam 267mm (área útil em paisagem: 297-15-15)
    $pdf->SetFont('Helvetica', 'B', 9);
    $pdf->SetFillColor(241, 245, 249);
    $pdf->Cell(20, 7, _pdfTexto('Data'), 1, 0, 'C', true);
    $pdf->Cell(38, 7, _pdfTexto('Categoria'), 1, 0, 'C', true);
    $pdf->Cell(62, 7, _pdfTexto('Descrição'), 1, 0, 'C', true);
    $pdf->Cell(45, 7, _pdfTexto('Vínculo'), 1, 0, 'C', true);
    $pdf->Cell(28, 7, _pdfTexto('Pagamento'), 1, 0, 'C', true);
    $pdf->Cell(20, 7, _pdfTexto('Status'), 1, 0, 'C', true);
    $pdf->Cell(0, 7, _pdfTexto('Valor'), 1, 1, 'C', true);

    $pdf->SetFont('Helvetica', '', 8);
    foreach ($lancamentos as $l) {
        $data = $l['data_pagamento'] ?: $l['data_vencimento'];
        $vinculo = $l['cliente_nome_manual'] ?: ($l['fornecedor_nome'] ?? $l['funcionario_nome'] ?? '-');
        $pdf->Cell(20, 6, _pdfTexto($data ? date('d/m/Y', strtotime($data)) : '-'), 1);
        $pdf->Cell(38, 6, _pdfTexto(mb_substr($l['categoria_nome'] ?? '-', 0, 24)), 1);
        $pdf->Cell(62, 6, _pdfTexto(mb_substr($l['descricao'], 0, 40)), 1);
        $pdf->Cell(45, 6, _pdfTexto(mb_substr((string)$vinculo, 0, 28)), 1);
        $pdf->Cell(28, 6, _pdfTexto($l['forma_pagamento'] ?: '-'), 1);
        $pdf->Cell(20, 6, _pdfTexto(ucfirst((string)$l['status'])), 1);
        $sinal = $l['tipo'] === 'receita' ? '+' : '-';
        $pdf->Cell(0, 6, _pdfTexto($sinal . ' R$ ' . number_format((float)$l['valor'], 2, ',', '.')), 1, 1, 'R');
    }
    if (!$lancamentos) {
        $pdf->SetFont('Helvetica', 'I', 9);
        $pdf->Cell(0, 8, _pdfTexto('Nenhum lançamento no período.'), 1, 1, 'C');
    }

    $pdf->Ln(6);
    $pdf->SetDrawColor(47, 111, 237); // azul da marca, padronizado 23/09/2026 (era dourado)
    $pdf->SetLineWidth(0.3);
    $pdf->Line(15, $pdf->GetY(), 282, $pdf->GetY());
    $pdf->SetDrawColor(0, 0, 0);
    $pdf->Ln(2);
    // 19/09/2026, mesma nota do DRE (includes/financeiro_dre.php) — este
    // extrato também é regime de caixa (cada linha é a data efetiva de
    // pagamento/vencimento, entrada e parcela separadas), não regime de
    // competência.
    $pdf->SetFont('Helvetica', '', 7.5);
    $pdf->SetTextColor(120, 120, 120);
    $pdf->MultiCell(0, 4, _pdfTexto('ℹ Regime de caixa: cada lançamento aparece na data em que foi efetivamente pago/recebido (entrada e cada parcela de venda são lançadas separadamente, conforme o vencimento) — não é o regime de competência da escrituração contábil oficial. O contrato assinado de cada negociação (armazenado no sistema) é o documento de suporte de cada lançamento.'));
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Ln(1);
    $pdf->SetFont('Helvetica', 'I', 7);
    $pdf->SetTextColor(120, 120, 120);
    $pdf->Cell(0, 4, _pdfTexto('Gerado automaticamente em ' . date('d/m/Y H:i') . ' — documento confidencial, uso interno'), 0, 1);
    $pdf->SetTextColor(0, 0, 0);

    return $pdf;
}
