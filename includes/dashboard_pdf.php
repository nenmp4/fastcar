<?php
/**
 * includes/dashboard_pdf.php — Relatório em PDF da listagem de
 * oportunidades do dashboard (admin/index.php), 21/09/2026, "coloca botão
 * para gerar pdf relatório". Mesmo padrão paisagem de
 * includes/financeiro_extrato.php — reaproveita só _pdfTexto() de
 * includes/contratos_pdf.php (independente de orientação de página),
 * nunca _pdfNovo()/_pdfCabecalho() (fixos em retrato, usados pelos
 * contratos, nunca devem mudar).
 */
require_once __DIR__ . '/contratos_pdf.php';
require_once __DIR__ . '/oportunidades.php';

/** etapaLabel() tem emoji na frente (💬/🤖/✅ etc) — FPDF é ISO-8859-1,
 *  sem emoji; //TRANSLIT do iconv não tem um jeito bom de converter isso,
 *  então tira o token do emoji antes de mandar pro PDF, só pro texto ficar
 *  limpo (a cor/badge que substitui o emoji visualmente fica só na tela). */
function _dashboardPdfEtapaTexto(string $etapa): string {
    return preg_replace('/^\S+\s+/u', '', etapaLabel($etapa)) ?? etapaLabel($etapa);
}

/**
 * @param string $where Cláusula WHERE já pronta (mesma lógica de
 *   admin/index.php, sem LIMIT/OFFSET) — o PDF lista TUDO que bate com o
 *   filtro atual, não só a página visível na tela.
 * @param bool $comResumo 21/09/2026, "ideal gerar com detalhe trazer
 *   resumos das conversas" — opt-in (nunca o padrão): troca a tabela
 *   compacta por 1 bloco por oportunidade com o `resumo_ia` (já gerado
 *   pela qualificação, checklist ✅/⚠️ — nada novo sendo inventado aqui)
 *   embaixo do cabeçalho. Deixado opt-in de propósito — numa lista de 50+
 *   leads o resumo completo de cada um quebraria a visão rápida que a
 *   tabela padrão já serve bem pro dia a dia.
 */
function gerarRelatorioDashboardPdf(PDO $db, string $where, array $params, string $titulo, bool $comResumo = false): FPDF {
    $stmt = $db->prepare("
        SELECT o.*, c.nome AS cliente_nome, c.telefone AS cliente_telefone,
               u.nome AS responsavel_nome
        FROM oportunidades o
        JOIN clientes c ON c.id = o.cliente_id
        LEFT JOIN usuarios u ON u.id = o.responsavel_id
        {$where}
        ORDER BY o.created_at DESC
    ");
    $stmt->execute($params);
    $linhas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $razaoSocial = getConfig('empresa_razao_social') ?: 'FASTCAR SOLUTIONS';

    // ── PDF (paisagem — mesma decisão do extrato financeiro: colunas
    // demais pra caber direito em retrato) ─────────────────────────────
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
    $pdf->Cell(0, 6, _pdfTexto('RELATÓRIO DE OPORTUNIDADES'), 0, 1, 'C');
    $pdf->SetFillColor(201, 168, 76); // dourado
    $pdf->Rect(0, 20, 297, 1.2, 'F');
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetY(26);

    $pdf->SetFont('Helvetica', 'B', 11);
    $pdf->Cell(0, 6, _pdfTexto($razaoSocial), 0, 1, 'L');
    $pdf->SetFont('Helvetica', 'B', 12);
    $pdf->Cell(0, 7, _pdfTexto($titulo), 0, 1, 'L');
    $pdf->SetFont('Helvetica', '', 8.5);
    $pdf->SetTextColor(90, 90, 90);
    $pdf->Cell(0, 5, _pdfTexto(count($linhas) . ' oportunidade(s)'), 0, 1, 'L');
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Ln(2);
    $pdf->SetDrawColor(180, 180, 180);
    $pdf->Line(15, $pdf->GetY(), 282, $pdf->GetY());
    $pdf->Ln(4);

    if ($comResumo) {
        // Modo detalhado — 1 bloco por oportunidade (cabeçalho compacto +
        // resumo da IA embaixo), em vez da tabela linha-por-linha.
        foreach ($linhas as $l) {
            $veiculo = trim(($l['veiculo_modelo'] ?: '-') . ' ' . ($l['veiculo_ano'] ?: ''));
            $recebido = $l['created_at'] ? date('d/m/Y H:i', strtotime($l['created_at'])) : '-';

            $pdf->SetFont('Helvetica', 'B', 10);
            $pdf->MultiCell(0, 5.5, _pdfTexto(
                ($l['cliente_nome'] ?: '(sem nome)') . ' — ' . (string)$l['cliente_telefone']
            ));
            $pdf->SetFont('Helvetica', '', 8.5);
            $pdf->SetTextColor(90, 90, 90);
            $pdf->MultiCell(0, 5, _pdfTexto(
                'Veículo: ' . ($veiculo !== '-' ? $veiculo : '—') .
                '   •   Etapa: ' . _dashboardPdfEtapaTexto($l['etapa']) .
                '   •   Responsável: ' . ($l['responsavel_nome'] ?: '—') .
                '   •   Recebido em: ' . $recebido
            ));
            $pdf->SetTextColor(0, 0, 0);
            $pdf->Ln(1);

            $pdf->SetFont('Helvetica', '', 9);
            $resumo = trim((string)($l['resumo_ia'] ?? ''));
            if ($resumo !== '') {
                $pdf->MultiCell(0, 5, _pdfTexto($resumo));
            } else {
                $pdf->SetFont('Helvetica', 'I', 9);
                $pdf->SetTextColor(150, 150, 150);
                $pdf->Cell(0, 5, _pdfTexto('— sem resumo da IA ainda —'), 0, 1);
                $pdf->SetTextColor(0, 0, 0);
            }

            $pdf->Ln(2);
            $pdf->SetDrawColor(220, 220, 220);
            $pdf->Line(15, $pdf->GetY(), 282, $pdf->GetY());
            $pdf->Ln(4);
        }
        if (!$linhas) {
            $pdf->SetFont('Helvetica', 'I', 9);
            $pdf->Cell(0, 8, _pdfTexto('Nenhuma oportunidade encontrada com esse filtro.'), 0, 1, 'C');
        }
    } else {
        // Tabela — larguras somam 267mm (área útil em paisagem: 297-15-15)
        $pdf->SetFont('Helvetica', 'B', 9);
        $pdf->SetFillColor(241, 245, 249);
        $pdf->Cell(48, 7, _pdfTexto('Cliente'), 1, 0, 'C', true);
        $pdf->Cell(30, 7, _pdfTexto('Telefone'), 1, 0, 'C', true);
        $pdf->Cell(52, 7, _pdfTexto('Veículo'), 1, 0, 'C', true);
        $pdf->Cell(42, 7, _pdfTexto('Etapa'), 1, 0, 'C', true);
        $pdf->Cell(35, 7, _pdfTexto('Responsável'), 1, 0, 'C', true);
        $pdf->Cell(26, 7, _pdfTexto('Recebido em'), 1, 0, 'C', true);
        $pdf->Cell(0, 7, _pdfTexto('Próxima ação'), 1, 1, 'C', true);

        $pdf->SetFont('Helvetica', '', 8);
        foreach ($linhas as $l) {
            $veiculo = trim(($l['veiculo_modelo'] ?: '-') . ' ' . ($l['veiculo_ano'] ?: ''));
            $pdf->Cell(48, 6, _pdfTexto(mb_substr($l['cliente_nome'] ?: '(sem nome)', 0, 30)), 1);
            $pdf->Cell(30, 6, _pdfTexto((string)$l['cliente_telefone']), 1);
            $pdf->Cell(52, 6, _pdfTexto(mb_substr($veiculo, 0, 34)), 1);
            $pdf->Cell(42, 6, _pdfTexto(mb_substr(_dashboardPdfEtapaTexto($l['etapa']), 0, 28)), 1);
            $pdf->Cell(35, 6, _pdfTexto(mb_substr($l['responsavel_nome'] ?: '-', 0, 22)), 1);
            $pdf->Cell(26, 6, _pdfTexto($l['created_at'] ? date('d/m/Y', strtotime($l['created_at'])) : '-'), 1);
            $prox = $l['proxima_acao_em'] ? date('d/m/Y H:i', strtotime($l['proxima_acao_em'])) : '-';
            $pdf->Cell(0, 6, _pdfTexto($prox), 1, 1);
        }
        if (!$linhas) {
            $pdf->SetFont('Helvetica', 'I', 9);
            $pdf->Cell(0, 8, _pdfTexto('Nenhuma oportunidade encontrada com esse filtro.'), 1, 1, 'C');
        }
    }

    $pdf->Ln(6);
    $pdf->SetFont('Helvetica', 'I', 7);
    $pdf->SetTextColor(120, 120, 120);
    $pdf->Cell(0, 4, _pdfTexto('Gerado automaticamente em ' . date('d/m/Y H:i') . ' — documento confidencial, uso interno'), 0, 1);
    $pdf->SetTextColor(0, 0, 0);

    return $pdf;
}
