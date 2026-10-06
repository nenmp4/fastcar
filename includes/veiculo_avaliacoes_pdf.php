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
 * gerarPdfContratoCompra()). `$score` opcional — 06/10/2026, Termo
 * Ciente ("coloca toda lista completa dos intens depois resumo e
 * score do veiculo"): resumo/contagem de `veiculoAvaliacaoScore()`,
 * sempre calculado em cima do checklist já marcado pelo avaliador,
 * nunca um julgamento novo da IA (regra #3).
 */
function gerarPdfTermoAvaliacao(array $av, array $itens, ?array $score = null): string {
    $score = $score ?? veiculoAvaliacaoScore($itens);
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

    // Lista COMPLETA dos itens — nunca um recorte/resumo só dos
    // problemáticos, todo item do checklist aparece sempre, com status e
    // observação (quando tiver).
    $pdf->Ln(3);
    _pdfTituloClausula($pdf, 'CHECKLIST DE VISTORIA — LISTA COMPLETA');
    foreach ($itens as $item) {
        $rotulo = veiculoAvaliacaoRotuloItem($item['item']);
        $linha = "• {$rotulo}: " . _avaliacaoStatusLabel($item['status']);
        if (($item['observacao'] ?? '') !== '') {
            $linha .= ' — ' . $item['observacao'];
        }
        _pdfCorpo($pdf, $linha);
    }

    // Resumo + score — contagem determinística do checklist acima, nunca
    // um julgamento/nota inventada; "não verificado" fica de fora da %
    // (regra #3, veiculoAvaliacaoScore()).
    $pdf->Ln(2);
    _pdfTituloClausula($pdf, 'RESUMO E SCORE DO VEÍCULO');
    $linhaScore = $score['resumo'];
    if ($score['percentual'] !== null) {
        $verificados = $score['ok'] + $score['problema'];
        $linhaScore .= " Score: {$score['percentual']}% dos itens verificados sem problema ({$score['ok']} de {$verificados}).";
    }
    _pdfCorpo($pdf, $linhaScore);
    if ($score['problemas']) {
        $pdf->Ln(1);
        _pdfCorpo($pdf, 'Itens com ressalva:');
        foreach ($score['problemas'] as $p) {
            _pdfCorpo($pdf, "  • {$p}");
        }
    }

    if (($av['observacoes_gerais'] ?? '') !== '') {
        $pdf->Ln(2);
        _pdfTituloClausula($pdf, 'OBSERVAÇÕES GERAIS');
        _pdfCorpo($pdf, $av['observacoes_gerais']);
    }

    $pdf->Ln(3);
    _pdfCorpo($pdf, $av['tipo'] === 'venda'
        ? 'O comprador declara ter recebido o veículo acima identificado nas condições descritas neste checklist, ' .
          'tendo tido a oportunidade de inspecioná-lo antes de confirmar este termo.'
        : 'O vendedor/proprietário original declara ter entregue o veículo acima identificado à FASTCAR nas ' .
          'condições descritas neste checklist, no estado em que se encontra.'
    );

    // 06/10/2026, Termo Ciente — nunca mais um bloco de "assinatura"
    // física (as 2 linhas tracejadas antigas): esse documento deixou de
    // ser assinatura eletrônica via ZapSign, é um registro INTERNO cuja
    // confirmação acontece por fora (link único por e-mail, 1 clique) —
    // o PDF nunca deve sugerir visualmente algo que não é mais verdade.
    if ($av['tipo'] === 'venda') {
        $pdf->Ln(10);
        _pdfTituloClausula($pdf, 'SOBRE A CONFIRMAÇÃO DESTE DOCUMENTO');
        _pdfCorpo($pdf,
            'Este é um documento de controle INTERNO da FASTCAR SOLUTIONS, sem valor de assinatura eletrônica/' .
            'contrato — o contrato de venda em si já foi assinado separadamente. A confirmação de recebimento pelo ' .
            "comprador ({$nomeParte}) é feita por um link único enviado por e-mail, com data/hora e demais dados " .
            'de acesso registrados no sistema da FASTCAR no momento da confirmação.'
        );
    }

    $pdf->Ln(6);
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
