<?php
require_once __DIR__ . '/contratos_pdf.php'; // _pdfNovo()/_pdfCabecalho()/_pdfTexto()/_pdfTituloClausula()/_pdfCorpo()

/**
 * PDF do "Termo de Entrega e Vistoria do Veículo" — módulo de checklist de
 * avaliação (includes/veiculo_avaliacoes.php). Reaproveita os helpers de
 * baixo nível de includes/contratos_pdf.php (_pdfNovo/_pdfCabecalho/
 * _pdfTexto/_pdfTituloClausula/_pdfCorpo) sem duplicar nada deles — só a
 * montagem do conteúdo é própria, porque o documento em si não tem nada a
 * ver com contrato de compra/venda (é um checklist técnico, não cláusula
 * jurídica).
 */

function _avaliacaoStatusLabel(string $status): string {
    return match ($status) {
        'ok' => 'OK',
        'problema' => 'PROBLEMA IDENTIFICADO',
        default => 'Não verificado',
    };
}

/**
 * Gera o PDF e devolve o caminho do arquivo temporário (chamador é
 * responsável por apagar depois de usar — mesmo padrão de
 * gerarPdfContratoCompra()).
 */
function gerarPdfTermoAvaliacao(array $av, array $itens): string {
    $pdf = _pdfNovo();
    $subtitulo = $av['tipo'] === 'venda'
        ? 'TERMO DE ENTREGA E VISTORIA DO VEÍCULO — ENTREGA AO COMPRADOR'
        : 'TERMO DE ENTREGA E VISTORIA DO VEÍCULO — RECEBIMENTO PELA FASTCAR';
    _pdfCabecalho($pdf, $subtitulo);

    $pdf->SetFont('Helvetica', '', 9.5);
    $nomeParte = $av['tipo'] === 'venda' ? ($av['comprador_nome'] ?: '—') : ($av['cliente_nome'] ?: '—');
    $papelParte = $av['tipo'] === 'venda' ? 'Comprador' : 'Vendedor/proprietário original';
    $pdf->MultiCell(0, 5, _pdfTexto(
        "Veículo: {$av['veiculo_marca']} {$av['veiculo_modelo']} {$av['veiculo_ano']} — Placa {$av['veiculo_placa']}\n" .
        "{$papelParte}: {$nomeParte}\n" .
        "Data da vistoria: " . date('d/m/Y H:i') . "\n" .
        "Quilometragem registrada: " . ($av['km_atual'] !== null && $av['km_atual'] !== '' ? number_format((float)$av['km_atual'], 0, ',', '.') . ' km' : 'não informada')
    ));

    $pdf->Ln(3);
    _pdfTituloClausula($pdf, 'CHECKLIST DE VISTORIA');
    foreach ($itens as $item) {
        $rotulo = veiculoAvaliacaoRotuloItem($item['item']);
        $linha = "• {$rotulo}: " . _avaliacaoStatusLabel($item['status']);
        if (($item['observacao'] ?? '') !== '') {
            $linha .= ' — ' . $item['observacao'];
        }
        _pdfCorpo($pdf, $linha);
    }

    if (($av['observacoes_gerais'] ?? '') !== '') {
        $pdf->Ln(2);
        _pdfTituloClausula($pdf, 'OBSERVAÇÕES GERAIS');
        _pdfCorpo($pdf, $av['observacoes_gerais']);
    }

    $pdf->Ln(3);
    _pdfCorpo($pdf, $av['tipo'] === 'venda'
        ? 'O comprador declara ter recebido o veículo acima identificado nas condições descritas neste checklist, ' .
          'tendo tido a oportunidade de inspecioná-lo antes da assinatura deste termo.'
        : 'O vendedor/proprietário original declara ter entregue o veículo acima identificado à FASTCAR nas ' .
          'condições descritas neste checklist, no estado em que se encontra.'
    );

    $pdf->Ln(14);
    $pdf->SetFont('Helvetica', '', 8.5);
    $pdf->Cell(85, 5, _pdfTexto('_______________________________'), 0, 0, 'C');
    $pdf->Cell(10, 5, '', 0, 0);
    $pdf->Cell(85, 5, _pdfTexto('_______________________________'), 0, 1, 'C');
    $pdf->Cell(85, 5, _pdfTexto('FASTCAR SOLUTIONS'), 0, 0, 'C');
    $pdf->Cell(10, 5, '', 0, 0);
    $pdf->Cell(85, 5, _pdfTexto(strtoupper($papelParte)), 0, 1, 'C');
    $pdf->Cell(85, 5, _pdfTexto('CNPJ 66.934.500/0001-09'), 0, 0, 'C');
    $pdf->Cell(10, 5, '', 0, 0);
    $pdf->Cell(85, 5, _pdfTexto("Nome: {$nomeParte}"), 0, 1, 'C');

    $pdf->Ln(10);
    $pdf->SetFont('Helvetica', '', 7.5);
    $pdf->SetTextColor(120, 120, 120);
    $pdf->Cell(0, 4, _pdfTexto('FASTCAR SOLUTIONS — CNPJ 66.934.500/0001-09'), 0, 1, 'C');
    $pdf->Cell(0, 4, _pdfTexto('Av. Sagitário, 138 — Sala 1003, 10º andar, Torre City (Torre 2), Complexo Alpha Square Offices'), 0, 1, 'C');
    $pdf->Cell(0, 4, _pdfTexto('Alphaville Conde II, Barueri/SP — CEP 06473-073'), 0, 1, 'C');
    $pdf->SetTextColor(0, 0, 0);

    $caminho = tempnam(sys_get_temp_dir(), 'termo_vistoria_') . '.pdf';
    $pdf->Output('F', $caminho);
    return $caminho;
}
