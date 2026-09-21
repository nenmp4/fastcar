<?php
/**
 * includes/contratos_pdf.php — Geração do PDF do Contrato-Mestre de Compra
 * (FASTCAR compra veículo com quitação futura do financiamento, cessão da
 * posse e mandato de exploração). FPDF puro (includes/fpdf.php) — mesmo
 * padrão do JurídicoSaaS, funciona em cPanel/hospedagem compartilhada sem
 * shell_exec/LibreOffice.
 *
 * Texto das cláusulas transcrito do modelo fornecido pela Fastcar
 * (01_Contrato_Mestre_FASTCAR_Compra_Quitacao_Futura.docx, Setembro/2026).
 *
 * ⚠️ Módulo de VENDA (Fastcar vende pro próximo comprador) é outra etapa —
 * decisão do Jean de deixar pra depois ("módulo de vendas") — este arquivo
 * só cobre o contrato de COMPRA, que é o que já roda no funil atual.
 */

define('FPDF_FONTPATH', __DIR__ . '/font/');
require_once __DIR__ . '/fpdf.php';

/** FPDF usa ISO-8859-1 — converte texto UTF-8 do banco/formulário. */
function _pdfTexto(string $texto): string {
    return iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $texto) ?: $texto;
}

function _pdfNovo(): FPDF {
    $pdf = new FPDF('P', 'mm', 'A4');
    $pdf->SetMargins(20, 18, 20);
    $pdf->SetAutoPageBreak(true, 20);
    $pdf->AddPage();
    return $pdf;
}

function _pdfCabecalho(FPDF $pdf, string $subtitulo = 'CONTRATO-MESTRE DE COMPRA DE VEÍCULO COM QUITAÇÃO FUTURA DO FINANCIAMENTO'): void {
    $pdf->SetFillColor(21, 23, 34); // navy da marca (#151722)
    $pdf->Rect(0, 0, 210, 24, 'F');

    // Logo real no canto esquerdo do cabeçalho, se já tiver sido enviada
    // via Configurações (includes/marca.php) — pedido explícito ("faltou
    // logo topo para fechar"). Sem logo ainda, cabeçalho segue só com o
    // texto, igual sempre foi — nunca trava a geração do contrato por
    // causa de imagem ausente/corrompida.
    $logoPath = dirname(__DIR__) . '/public/assets/logo.png';
    if (is_file($logoPath)) {
        try {
            $dimensoes = @getimagesize($logoPath);
            if ($dimensoes) {
                $pdf->Image($logoPath, 20, 4, 0, 16);
            }
        } catch (Throwable $e) {
            // Logo corrompida ou formato que o FPDF não lê — segue sem ela.
        }
    }

    $pdf->SetY(5);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('Helvetica', 'B', 13);
    $pdf->Cell(0, 8, _pdfTexto('FASTCAR SOLUTIONS'), 0, 1, 'C');
    $pdf->SetFont('Helvetica', '', 9);
    $pdf->Cell(0, 6, _pdfTexto($subtitulo), 0, 1, 'C');
    $pdf->SetFillColor(201, 168, 76); // dourado
    $pdf->Rect(0, 24, 210, 1.2, 'F');
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetY(30);
}

function _pdfTituloClausula(FPDF $pdf, string $titulo): void {
    $pdf->Ln(2);
    $pdf->SetFont('Helvetica', 'B', 10);
    $pdf->MultiCell(0, 5.5, _pdfTexto($titulo));
    $pdf->Ln(1);
}

function _pdfCorpo(FPDF $pdf, string $texto): void {
    $pdf->SetFont('Helvetica', '', 9.5);
    $pdf->MultiCell(0, 5, _pdfTexto($texto));
}

/**
 * Conta em quantas linhas um texto vai quebrar dentro de $largura, na fonte
 * já selecionada no $pdf no momento da chamada — mesma lógica de quebra por
 * palavra que MultiCell() usa por baixo dos panos, só que sem desenhar nada
 * (chamador precisa saber a altura ANTES de desenhar a célula ao lado).
 */
function _pdfContarLinhas(FPDF $pdf, string $texto, float $largura): int {
    $palavras = preg_split('/\s+/', trim($texto));
    if ($palavras === [''] || $palavras === false) return 1;
    $linhas = 1;
    $linhaAtual = '';
    foreach ($palavras as $palavra) {
        $tentativa = $linhaAtual === '' ? $palavra : $linhaAtual . ' ' . $palavra;
        if ($pdf->GetStringWidth($tentativa) > $largura && $linhaAtual !== '') {
            $linhas++;
            $linhaAtual = $palavra;
        } else {
            $linhaAtual = $tentativa;
        }
    }
    return $linhas;
}

/**
 * Linha do Quadro-Resumo: rótulo à esquerda (cinza), valor à direita, cada
 * célula com 85mm de largura fixa. Altura da linha calculada ANTES de
 * desenhar (maior entre as linhas que rótulo/valor vão precisar quebrando
 * por palavra) — bug real achado testando o mockup: a versão anterior
 * usava Cell() de altura fixa (não quebra linha nunca), e valor mais
 * comprido que 85mm simplesmente estourava pra fora da célula em vez de
 * quebrar ("Exploração econômica pela FASTCAR", "Transferência final" e
 * "Seguro/proteção durante posse FASTCAR" — todos valores de negociação
 * que podem crescer, não é caso raro).
 */
function _pdfLinhaResumo(FPDF $pdf, string $label, string $valor): void {
    $largura = 85;
    $larguraUtil = $largura - 2; // ~1mm de margem de cada lado, mesmo espírito do cMargin padrão do FPDF
    $alturaLinha = 4.2;

    // Mede/desenha sempre o texto já convertido (ISO-8859-1) — GetStringWidth()
    // usa a tabela de largura de caractere da fonte core do FPDF, que é por
    // byte nessa codificação; medir a string UTF-8 original contaria cada
    // acento como 2 "caracteres" (bytes) e dava conta errada de quantas
    // linhas cabem.
    $labelPdf = _pdfTexto($label);
    $valorPdf = _pdfTexto($valor !== '' ? $valor : '—');

    $pdf->SetFont('Helvetica', 'B', 8.5);
    $linhasLabel = _pdfContarLinhas($pdf, $labelPdf, $larguraUtil);
    $pdf->SetFont('Helvetica', '', 8.5);
    $linhasValor = _pdfContarLinhas($pdf, $valorPdf, $larguraUtil);
    $altura = max(6, max($linhasLabel, $linhasValor) * $alturaLinha + 1.5);

    $x = $pdf->GetX();
    $y = $pdf->GetY();

    $pdf->SetFillColor(245, 245, 245);
    $pdf->Rect($x, $y, $largura, $altura, 'DF');
    $pdf->Rect($x + $largura, $y, $largura, $altura);

    $pdf->SetFont('Helvetica', 'B', 8.5);
    $pdf->SetXY($x + 1, $y + 1);
    $pdf->MultiCell($larguraUtil, $alturaLinha, $labelPdf, 0, 'L');

    $pdf->SetFont('Helvetica', '', 8.5);
    $pdf->SetXY($x + $largura + 1, $y + 1);
    $pdf->MultiCell($larguraUtil, $alturaLinha, $valorPdf, 0, 'L');

    $pdf->SetXY($x, $y + $altura);
}

function _fmtMoeda(?float $v): string {
    return $v === null ? '—' : 'R$ ' . number_format($v, 2, ',', '.');
}

/**
 * Número por extenso (1-24) pro padrão jurídico "18 (dezoito) meses" —
 * faixa fechada, só cobre o intervalo de prazo_quitacao_meses (nunca > 24,
 * regra travada em includes/contratos.php/admin/oportunidade.php).
 */
function _extensoMeses(int $n): string {
    $numeros = [
        1 => 'um', 2 => 'dois', 3 => 'três', 4 => 'quatro', 5 => 'cinco', 6 => 'seis',
        7 => 'sete', 8 => 'oito', 9 => 'nove', 10 => 'dez', 11 => 'onze', 12 => 'doze',
        13 => 'treze', 14 => 'quatorze', 15 => 'quinze', 16 => 'dezesseis', 17 => 'dezessete',
        18 => 'dezoito', 19 => 'dezenove', 20 => 'vinte', 21 => 'vinte e um', 22 => 'vinte e dois',
        23 => 'vinte e três', 24 => 'vinte e quatro',
    ];
    return $numeros[$n] ?? (string)$n;
}

/**
 * Gera o PDF do contrato de compra e retorna o caminho do arquivo temporário.
 * $campos: ver montarCamposContratoCompra() em includes/contratos.php.
 */
function gerarPdfContratoCompra(array $c): string {
    $pdf = _pdfNovo();
    _pdfCabecalho($pdf);

    $pdf->SetFillColor(247, 243, 231);
    $pdf->SetFont('Helvetica', 'B', 9);
    $pdf->MultiCell(0, 5.5, _pdfTexto(
        "ALERTA DE NATUREZA JURÍDICA\n" .
        "Este instrumento NÃO transfere nem pretende transferir à FASTCAR a propriedade plena do veículo " .
        "enquanto houver alienação fiduciária ou outro gravame incompatível com a transferência. A FASTCAR " .
        "recebe a posse contratual e poderes de administração/exploração nos limites deste contrato e da " .
        "procuração. Direitos do credor fiduciário e exigências do órgão de trânsito prevalecem."
    ), 0, 'L', true);
    $pdf->Ln(2);

    $pdf->SetFont('Helvetica', '', 9);
    $pdf->MultiCell(0, 5, _pdfTexto(
        "Pelo presente instrumento particular, de um lado, FASTCAR SOLUTIONS, pessoa jurídica de direito " .
        "privado, inscrita no CNPJ/MF sob o nº 66.934.500/0001-09, com sede na Avenida Sagitário, nº 138, " .
        "Sala 1003, 10º andar, Torre City (Torre 2), Complexo Alpha Square Offices, Alphaville Conde II, " .
        "Barueri/SP, CEP 06473-073, doravante " .
        "denominada COMPRADORA/FASTCAR; e, de outro lado, {$c['vendedor_nome']}, {$c['vendedor_nacionalidade']}, " .
        "{$c['vendedor_estado_civil']}, {$c['vendedor_profissao']}, RG nº {$c['vendedor_rg']}, CPF nº {$c['vendedor_cpf']}, " .
        "CNH nº {$c['vendedor_cnh']}, residente em {$c['vendedor_endereco']}, doravante VENDEDOR/PROPRIETÁRIO " .
        "REGISTRAL, firmam o presente contrato."
    ));

    $pdf->Ln(3);
    $pdf->SetFont('Helvetica', 'B', 11);
    $pdf->Cell(0, 7, _pdfTexto('QUADRO-RESUMO DA OPERAÇÃO'), 0, 1, 'C');
    $pdf->Ln(1);

    _pdfLinhaResumo($pdf, 'Veículo / versão', trim($c['veiculo_marca'] . ' ' . $c['veiculo_modelo']));
    _pdfLinhaResumo($pdf, 'Ano fabricação/modelo', $c['veiculo_ano']);
    _pdfLinhaResumo($pdf, 'Placa / RENAVAM / chassi', "{$c['veiculo_placa']} / {$c['veiculo_renavam']} / {$c['veiculo_chassi']}");
    _pdfLinhaResumo($pdf, 'Valor FIPE de referência na contratação', _fmtMoeda($c['valor_fipe_referencia']));
    _pdfLinhaResumo($pdf, 'Percentual pago pela FASTCAR ao VENDEDOR', $c['percentual_fipe'] . '% da FIPE, limitado contratualmente a 25%');
    _pdfLinhaResumo($pdf, 'Valor pago ao VENDEDOR', _fmtMoeda($c['valor_pago_vendedor']));
    _pdfLinhaResumo($pdf, 'Instituição financeira/credor', $c['banco_financiamento']);
    _pdfLinhaResumo($pdf, 'Contrato de financiamento nº', $c['contrato_financiamento_numero']);
    _pdfLinhaResumo($pdf, 'Saldo estimado do financiamento na data', _fmtMoeda($c['saldo_financiamento_atual']));
    _pdfLinhaResumo($pdf, 'Responsável registral perante o credor', $c['responsavel_registral']);
    _pdfLinhaResumo($pdf, 'Prazo pra quitação do financiamento', "Fica ajustado, entretanto, que o prazo supracitado será de até " .
        "{$c['prazo_quitacao_meses']} (" . _extensoMeses((int)$c['prazo_quitacao_meses']) . ") meses, contado de {$c['data_entrega_posse']}, " .
        "podendo ser excepcionalmente prorrogado por até 24 (vinte e quatro) meses.");
    _pdfLinhaResumo($pdf, 'Terceiro indicado pela FASTCAR para a quitação', $c['terceiro_quitacao'] ?: 'a indicar');
    _pdfLinhaResumo($pdf, 'Posse física entregue à FASTCAR em', $c['data_entrega_posse']);
    _pdfLinhaResumo($pdf, 'Exploração econômica pela FASTCAR', 'Autorizada, inclusive locação a terceiros, nos limites contratuais');
    _pdfLinhaResumo($pdf, 'Transferência final', 'Após quitação/baixa do gravame e cumprimento das formalidades legais');
    _pdfLinhaResumo($pdf, 'Seguro/proteção durante posse FASTCAR', $c['seguro_texto']);
    _pdfLinhaResumo($pdf, 'IPVA/licenciamento/multas após entrega', $c['encargos_texto']);

    foreach (clausulasContratoCompra((int)$c['prazo_quitacao_meses']) as [$titulo, $corpo]) {
        _pdfTituloClausula($pdf, $titulo);
        _pdfCorpo($pdf, $corpo);
    }

    $pdf->Ln(6);
    $pdf->SetFont('Helvetica', '', 9);
    $pdf->Cell(0, 6, _pdfTexto("Barueri/SP, {$c['data_extenso']}."), 0, 1, 'L');
    $pdf->Ln(14);

    $y = $pdf->GetY();
    $pdf->SetFont('Helvetica', '', 8.5);
    $pdf->Cell(85, 5, _pdfTexto('_______________________________'), 0, 0, 'C');
    $pdf->Cell(10, 5, '', 0, 0);
    $pdf->Cell(85, 5, _pdfTexto('_______________________________'), 0, 1, 'C');
    $pdf->Cell(85, 5, _pdfTexto('FASTCAR SOLUTIONS'), 0, 0, 'C');
    $pdf->Cell(10, 5, '', 0, 0);
    $pdf->Cell(85, 5, _pdfTexto('VENDEDOR/PROPRIETÁRIO'), 0, 1, 'C');
    $pdf->Cell(85, 5, _pdfTexto('CNPJ 66.934.500/0001-09'), 0, 0, 'C');
    $pdf->Cell(10, 5, '', 0, 0);
    $pdf->Cell(85, 5, _pdfTexto("Nome/CPF: {$c['vendedor_nome']} / {$c['vendedor_cpf']}"), 0, 1, 'C');

    // Testemunha não é obrigatória pra gerar o contrato — se ainda não foi
    // preenchida (Configurações → oportunidade → dados do contrato), a
    // linha sai em branco pro nome/CPF serem escritos à mão no presencial,
    // mesmo comportamento de antes dessas colunas existirem.
    $test1 = 'Nome: ' . ($c['testemunha1_nome'] ?: '______________________') . ' CPF: ' . ($c['testemunha1_cpf'] ?: '______________');
    $test2 = 'Nome: ' . ($c['testemunha2_nome'] ?: '______________________') . ' CPF: ' . ($c['testemunha2_cpf'] ?: '______________');

    $pdf->Ln(12);
    $pdf->Cell(85, 5, _pdfTexto('TESTEMUNHA 1'), 0, 0, 'C');
    $pdf->Cell(10, 5, '', 0, 0);
    $pdf->Cell(85, 5, _pdfTexto('TESTEMUNHA 2'), 0, 1, 'C');
    $pdf->Cell(85, 5, _pdfTexto($test1), 0, 0, 'C');
    $pdf->Cell(10, 5, '', 0, 0);
    $pdf->Cell(85, 5, _pdfTexto($test2), 0, 1, 'C');

    // Rodapé com o endereço real da sede — pedido do José/Jean em
    // 13/09/2026, endereço correto confirmado por ele (o modelo original
    // trazia um endereço genérico de Santana de Parnaíba/SP, corrigido em
    // todo o contrato — abertura, cidade da assinatura e foro).
    $pdf->Ln(10);
    $pdf->SetFont('Helvetica', '', 7.5);
    $pdf->SetTextColor(120, 120, 120);
    $pdf->Cell(0, 4, _pdfTexto('FASTCAR SOLUTIONS — CNPJ 66.934.500/0001-09'), 0, 1, 'C');
    $pdf->Cell(0, 4, _pdfTexto('Av. Sagitário, 138 — Sala 1003, 10º andar, Torre City (Torre 2), Complexo Alpha Square Offices'), 0, 1, 'C');
    $pdf->Cell(0, 4, _pdfTexto('Alphaville Conde II, Barueri/SP — CEP 06473-073'), 0, 1, 'C');
    $pdf->SetTextColor(0, 0, 0);

    $caminho = tempnam(sys_get_temp_dir(), 'contrato_compra_') . '.pdf';
    $pdf->Output('F', $caminho);
    return $caminho;
}

/**
 * Cláusulas do contrato-mestre de compra — [título, corpo].
 * $prazoMeses: prazo negociado pra quitar o financiamento (normal 12-18,
 * nunca > 24 — travado em admin/oportunidade.php/includes/contratos.php
 * antes de chegar aqui). 17/09/2026: até então vinha fixo em "24 meses"
 * hardcoded em todo lugar — ver bullet "Saldo do financiamento..." não,
 * ver CLAUDE.md seção "Módulo de contrato (só COMPRA)" pro histórico.
 */
function clausulasContratoCompra(int $prazoMeses = 24): array {
    return [
        ['CLÁUSULA 1ª – OBJETO E ESTRUTURA DA OPERAÇÃO',
            "1.1. O VENDEDOR negocia com a FASTCAR os direitos econômicos e a futura aquisição do veículo identificado no Quadro-Resumo, entregando-lhe desde já a posse direta e autorizando sua administração e exploração econômica, permanecendo a transferência registral definitiva condicionada à quitação e baixa de eventual gravame.\n\n" .
            "1.2. Como contraprestação inicial, a FASTCAR pagará ao VENDEDOR o valor indicado no Quadro-Resumo, correspondente ao percentual livremente ajustado, que não poderá exceder 25% do valor FIPE de referência utilizado na contratação.\n\n" .
            "1.3. O restante da estrutura econômica da operação consiste na quitação futura do financiamento/gravame, por terceiro indicado pela FASTCAR, dentro do prazo máximo de {$prazoMeses} meses, indicado no Quadro-Resumo, nunca superior a 24 meses.\n\n" .
            "1.4. O pagamento da dívida ao credor por terceiro não equivale, por si só, à assunção formal da dívida perante a instituição financeira, nem substitui eventual consentimento do credor exigido para mudança de devedor, novação ou alteração contratual.\n\n" .
            "1.5. Enquanto vigente alienação fiduciária, a titularidade resolúvel e os direitos do credor fiduciário permanecem preservados, não podendo este instrumento ser interpretado como autorização para frustrar, ocultar ou impedir o exercício legítimo desses direitos."],
        ['CLÁUSULA 2ª – DECLARAÇÕES DO VENDEDOR E SITUAÇÃO DO VEÍCULO',
            "2.1. O VENDEDOR declara ser o proprietário registral/devedor fiduciante ou possuir legitimidade documental suficiente para a operação, devendo revelar integralmente gravames, financiamento, parcelas vencidas, ações, restrições, multas, sinistros, débitos e qualquer fato relevante.\n\n" .
            "2.2. O VENDEDOR entregará CRLV-e, contrato/extrato do financiamento, demonstrativo de saldo, boletos ou meios de consulta, documentos pessoais, chaves e demais documentos indicados nos anexos.\n\n" .
            "2.3. A omissão dolosa de restrição relevante, bloqueio, sinistro estrutural, busca e apreensão, fraude, perda total ou impedimento de transferência caracteriza inadimplemento grave."],
        ['CLÁUSULA 3ª – PREÇO INICIAL E QUITAÇÃO AO VENDEDOR',
            "3.1. O valor pago pela FASTCAR ao VENDEDOR constitui a parcela imediata do preço desta operação, conforme Quadro-Resumo e comprovante.\n\n" .
            "3.2. O VENDEDOR dará recibo do valor efetivamente recebido, sem que isso importe declaração de quitação do financiamento perante a instituição financeira.\n\n" .
            "3.3. Nenhum valor adicional será devido ao VENDEDOR além do expressamente previsto neste contrato, ressalvado aditivo escrito."],
        ['CLÁUSULA 4ª – QUITAÇÃO FUTURA DO FINANCIAMENTO POR TERCEIRO INDICADO',
            "4.1. A FASTCAR deverá organizar a indicação de terceiro responsável por realizar a quitação do financiamento ou gravame no prazo máximo de {$prazoMeses} meses.\n\n" .
            "4.2. O terceiro pagador poderá quitar integralmente a dívida, negociar liquidação antecipada ou aderir a solução aceita pela instituição financeira que resulte na efetiva baixa do gravame dentro do prazo máximo contratual.\n\n" .
            "4.3. A indicação de terceiro não exonera a FASTCAR de sua obrigação contratual de gestão e resultado perante o VENDEDOR quanto à obtenção da quitação/baixa no prazo, salvo se o impedimento decorrer exclusivamente de ato do VENDEDOR ou de fato documental por ele omitido.\n\n" .
            "4.4. Pagamentos ao credor serão comprovados por documentos idôneos. A FASTCAR manterá trilha de acompanhamento e fornecerá ao VENDEDOR informações razoáveis sobre marcos relevantes.\n\n" .
            "4.5. Se a instituição exigir anuência, comparecimento, assinatura ou documento do VENDEDOR, este deverá cooperar em prazo razoável, sem assumir obrigações novas não previstas."],
        ["CLÁUSULA 5ª – PRAZO DE ATÉ {$prazoMeses} MESES",
            "5.1. A quitação integral da obrigação financeira objeto deste contrato deverá ocorrer, em regra, no prazo inicial de 12 (doze) meses, contados a partir da data da efetiva entrega do veículo à FASTCAR, indicada no Quadro-Resumo. Fica ajustado, entretanto, que o prazo supracitado será de até " .
            (int)$prazoMeses . " (" . _extensoMeses((int)$prazoMeses) . ") meses, podendo ser excepcionalmente prorrogado por até 24 (vinte e quatro) meses, desde que tal extensão derive de necessidade operacional, administrativa ou financeira, não configurando, em qualquer hipótese, inadimplemento, mora ou descumprimento contratual por parte da FASTCAR.\n\n" .
            "5.2. O prazo é limite máximo para obtenção da quitação e baixa do gravame, não simples prazo para início de negociação.\n\n" .
            "5.3. Nos 90, 60 e 30 dias anteriores ao termo final, a FASTCAR deverá promover revisão documentada do status da dívida e do plano de quitação."],
        ['CLÁUSULA 6ª – POSSE, GUARDA E ENTREGA',
            "6.1. O VENDEDOR entrega voluntariamente a posse física do veículo à FASTCAR mediante Termo de Entrega e Vistoria.\n\n" .
            "6.2. A entrega não altera, perante o credor ou o órgão de trânsito, a titularidade registral que dependa de quitação, baixa de gravame e demais formalidades.\n\n" .
            "6.3. A FASTCAR assume a guarda operacional do veículo a partir da entrega, nos limites das responsabilidades definidas neste contrato e nos anexos."],
        ['CLÁUSULA 7ª – EXPLORAÇÃO ECONÔMICA PELA FASTCAR',
            "7.1. Durante a vigência da operação, a FASTCAR poderá utilizar o veículo em sua atividade econômica e cedê-lo onerosamente a terceiros, inclusive mediante locação, desde que respeitados os limites do financiamento, da legislação, do seguro/proteção e deste contrato.\n\n" .
            "7.2. A receita obtida com a exploração do veículo pertence à FASTCAR, não gerando participação, aluguel ou prestação de contas de receitas ao VENDEDOR, salvo ajuste escrito em sentido diverso.\n\n" .
            "7.3. A FASTCAR poderá confiar a posse material a locatários, prepostos, oficinas, empresas de rastreamento, estacionamentos ou outros prestadores necessários à exploração regular.\n\n" .
            "7.4. É vedada qualquer exploração que viole proibição expressa do contrato de financiamento, ordem judicial, restrição administrativa ou condição essencial de seguro conhecida pela FASTCAR."],
        ['CLÁUSULA 8ª – PROCURAÇÃO E MANDATO',
            "8.1. O VENDEDOR outorgará procuração específica à FASTCAR, com poderes necessários à administração, uso, locação, manutenção, regularização documental, obtenção de informações, negociação de quitação, recebimento de documentos, representação perante prestadores e prática dos atos autorizados no Anexo de Procuração.\n\n" .
            "8.2. Poderes para alienação definitiva, assinatura de ATPV-e ou transferência a terceiro somente poderão ser exercidos após a quitação/baixa do gravame e desde que juridicamente disponíveis, observadas as exigências do órgão de trânsito e do credor.\n\n" .
            "8.3. A procuração não confere poder para assumir dívida em nome do VENDEDOR, constituir novo gravame ou contratar financiamento em nome dele sem autorização expressa e específica.\n\n" .
            "8.4. Se o órgão competente exigir procuração pública, assinatura eletrônica específica, reconhecimento de firma ou novo instrumento, o VENDEDOR deverá cooperar com a formalização, desde que o ato esteja dentro do objeto deste contrato."],
        ['CLÁUSULA 9ª – FINANCIAMENTO E DIREITOS DO CREDOR',
            "9.1. As partes reconhecem que o veículo poderá estar submetido à alienação fiduciária e que o inadimplemento perante o credor pode ensejar vencimento antecipado, consolidação da propriedade, busca e apreensão ou procedimentos extrajudiciais previstos em lei.\n\n" .
            "9.2. A FASTCAR deverá acompanhar o status financeiro e evitar que a exploração do veículo seja realizada ignorando situação de mora relevante ou medida de apreensão conhecida.\n\n" .
            "9.3. Nenhuma cláusula deste contrato limita direitos do credor que não seja parte deste instrumento."],
        ['CLÁUSULA 10ª – PARCELAS DO FINANCIAMENTO ATÉ A QUITAÇÃO',
            "10.1. A forma de manutenção das parcelas do financiamento durante o período de até {$prazoMeses} meses será descrita no Anexo Financeiro: quem paga mensalmente, como se dará a negociação, como será evitada mora.\n\n" .
            "10.2. Este campo é condição essencial. O veículo não deverá ser recebido pela FASTCAR sem definição documental sobre quem realizará os pagamentos correntes ou sobre a existência de acordo formal com o credor.\n\n" .
            "10.3. Havendo alteração do plano, deverá ser produzida evidência escrita e atualizada."],
        ['CLÁUSULA 11ª – TRIBUTOS, LICENCIAMENTO E MULTAS',
            "11.1. A distribuição econômica de IPVA, licenciamento, multas, pedágios e demais encargos constará do Anexo Financeiro e Operacional.\n\n" .
            "11.2. Infrações decorrentes da utilização do veículo após a entrega serão economicamente atribuídas ao responsável pela condução/uso, cabendo à FASTCAR organizar a identificação de condutor e o reembolso quando aplicável.\n\n" .
            "11.3. Débitos anteriores à entrega serão de responsabilidade do VENDEDOR, salvo assunção expressa pela FASTCAR."],
        ['CLÁUSULA 12ª – SEGURO, PROTEÇÃO E SINISTROS',
            "12.1. A FASTCAR deverá verificar e documentar a existência de seguro/proteção compatível com o modo de exploração do veículo.\n\n" .
            "12.2. O Anexo de Seguro definirá cobertura, condutores, franquia, perda total, furto, roubo e uso comercial/locação.\n\n" .
            "12.3. Em perda total, furto ou roubo, a indenização será aplicada conforme titularidade, gravame, direitos do credor e estrutura econômica da operação, mediante prestação de contas."],
        ['CLÁUSULA 13ª – MANUTENÇÃO, CONSERVAÇÃO E AVARIAS',
            "13.1. A FASTCAR realizará ou providenciará manutenção compatível com o uso e manterá registros essenciais.\n\n" .
            "13.2. Danos decorrentes de locatários ou terceiros poderão ser cobrados destes pela FASTCAR, sem prejuízo da proteção do patrimônio e dos direitos do credor.\n\n" .
            "13.3. Modificações estruturais que possam afetar segurança, valor, seguro ou transferência dependem de controle prévio."],
        ['CLÁUSULA 14ª – RASTREAMENTO E DADOS',
            "14.1. A FASTCAR poderá instalar ou manter rastreador para segurança, gestão da frota, localização, prevenção de fraude e exercício regular de direitos, observada a legislação de proteção de dados.\n\n" .
            "14.2. Informações de geolocalização deverão ter acesso restrito e finalidade legítima."],
        ['CLÁUSULA 15ª – TRANSFERÊNCIA A TERCEIRO APÓS QUITAÇÃO',
            "15.1. Quitado o financiamento, baixado o gravame e cumpridas as exigências legais, o VENDEDOR autoriza que o veículo seja transferido diretamente à FASTCAR ou a terceiro por ela indicado, conforme a estrutura documental e registral disponível.\n\n" .
            "15.2. A FASTCAR poderá indicar adquirente final diverso de si, inclusive terceiro que tenha realizado a quitação, sem novo pagamento ao VENDEDOR além do preço ajustado neste contrato.\n\n" .
            "15.3. O VENDEDOR obriga-se a praticar os atos de assinatura e confirmação necessários à transferência definitiva, desde que compatíveis com este contrato e após prova da quitação/baixa.\n\n" .
            "15.4. A transferência direta a terceiro não dispensa cadeia documental, recolhimentos e formalidades perante o órgão de trânsito."],
        ['CLÁUSULA 16ª – PROIBIÇÃO DE DUPLA VENDA E ONERAÇÃO',
            "16.1. Após receber o preço inicial e entregar a posse, o VENDEDOR não poderá vender, prometer vender, ceder, locar, retirar, onerar adicionalmente ou negociar o veículo com terceiro fora desta operação.\n\n" .
            "16.2. O VENDEDOR deverá informar imediatamente qualquer bloqueio, cobrança, notificação do credor ou ato judicial/administrativo relativo ao veículo."],
        ['CLÁUSULA 17ª – COOPERAÇÃO DOCUMENTAL',
            "17.1. O VENDEDOR manterá seus dados cadastrais atualizados e atenderá solicitações razoáveis de assinatura ou comparecimento para quitação, baixa e transferência.\n\n" .
            "17.2. A FASTCAR não poderá utilizar a cooperação documental para impor obrigação financeira nova ao VENDEDOR sem aditivo expresso."],
        ['CLÁUSULA 18ª – INADIMPLEMENTO DA FASTCAR',
            "18.1. Constituem inadimplemento relevante da FASTCAR: ausência injustificada de gestão da quitação; omissão perante mora grave conhecida; exploração contrária a restrição legal conhecida; não obtenção da quitação/baixa até o termo final por fato imputável à FASTCAR; ou recusa injustificada de prestar informações essenciais.\n\n" .
            "18.2. Verificado risco concreto ao patrimônio ou à posição do VENDEDOR perante o credor, as partes deverão adotar plano de saneamento imediato, sem prejuízo das medidas jurídicas cabíveis.\n\n" .
            "18.3. Se o prazo de {$prazoMeses} meses expirar sem quitação por fato imputável à FASTCAR ou a terceiro por ela indicado, a FASTCAR permanecerá responsável perante o VENDEDOR pelo cumprimento da obrigação contratual de resultado e pelos efeitos comprovadamente decorrentes do descumprimento, sem prejuízo de regressar contra o terceiro indicado."],
        ['CLÁUSULA 19ª – INADIMPLEMENTO DO VENDEDOR',
            "19.1. Constituem inadimplemento grave do VENDEDOR: informação falsa sobre financiamento ou propriedade; ocultação de restrição; revogação injustificada dos poderes necessários à execução contratual após recebimento do preço; dupla venda; criação de novo ônus; não cooperação injustificada; ou apropriação de valores destinados à quitação.\n\n" .
            "19.2. Nesses casos, a FASTCAR poderá exigir cumprimento específico, perdas e danos, restituição de valores e demais medidas cabíveis."],
        ['CLÁUSULA 20ª – FALECIMENTO, INCAPACIDADE OU EVENTO PESSOAL DO VENDEDOR',
            "20.1. Ocorrendo falecimento, incapacidade ou outro evento que afete a representação do VENDEDOR, o caso será imediatamente encaminhado ao jurídico para preservação da posse, da cadeia documental e das providências de quitação/transferência.\n\n" .
            "20.2. A FASTCAR manterá o dossiê completo para demonstrar a operação e os valores pagos."],
        ['CLÁUSULA 21ª – MEDIDAS DO CREDOR E APREENSÃO',
            "21.1. Recebida notificação, mandado, comunicação de consolidação ou notícia de busca e apreensão, a FASTCAR interromperá atos incompatíveis com a medida e acionará imediatamente o jurídico e o VENDEDOR.\n\n" .
            "21.2. Este contrato não autoriza ocultação do veículo nem resistência ilícita ao credor."],
        ['CLÁUSULA 22ª – ENCERRAMENTO ANTECIPADO',
            "22.1. O encerramento antecipado deverá ser formalizado com demonstrativo dos valores pagos, situação do financiamento, estado e localização do veículo e providências necessárias à restituição ou saneamento.\n\n" .
            "22.2. Nenhuma parte poderá simplesmente desfazer a operação sem considerar pagamentos já realizados, posse, gravame e direitos de terceiros."],
        ['CLÁUSULA 23ª – COMUNICAÇÕES E PROVAS',
            "23.1. Comunicações poderão ocorrer por WhatsApp, e-mail, plataforma eletrônica, carta ou outro canal cadastrado que permita comprovação.\n\n" .
            "23.2. Fotografias, vídeos, comprovantes, relatórios, extratos do credor, registros de rastreamento e documentos eletrônicos poderão integrar o dossiê probatório."],
        ['CLÁUSULA 24ª – LGPD',
            "24.1. Dados pessoais serão tratados para execução contratual, proteção do crédito, prevenção a fraude, segurança, cumprimento legal e exercício regular de direitos.\n\n" .
            "24.2. Poderão ser compartilhados, quando necessário, com instituição financeira, despachante, seguradora, locatários, prestadores, assessoria jurídica e órgãos competentes."],
        ['CLÁUSULA 25ª – AUSÊNCIA DE NOVAÇÃO BANCÁRIA AUTOMÁTICA',
            "25.1. Nada neste contrato altera, sem anuência do credor quando exigida, a identidade do devedor do financiamento, suas garantias ou condições bancárias.\n\n" .
            "25.2. O VENDEDOR permanece formalmente sujeito às obrigações perante a instituição financeira até a quitação ou modificação aceita pelo credor, sem prejuízo das obrigações internas assumidas pela FASTCAR neste contrato."],
        ['CLÁUSULA 26ª – BOA-FÉ E DEVER DE INFORMAÇÃO',
            "26.1. As partes atuarão com boa-fé, transparência, cooperação e preservação da documentação.\n\n" .
            "26.2. Nenhuma parte poderá criar aparência de transferência plena enquanto o gravame impedir a efetiva transferência registral."],
        ['CLÁUSULA 27ª – ANEXOS E PREVALÊNCIA',
            "27.1. Integram este contrato os anexos assinados. Dados específicos regularmente preenchidos no Quadro-Resumo e anexos prevalecem sobre referências genéricas, salvo norma cogente.\n\n" .
            "27.2. Campos essenciais de financiamento, pagamento corrente, seguro e responsabilidade tributária não poderão ficar em branco no momento da entrega do veículo."],
        ['CLÁUSULA 28ª – TÍTULO EXECUTIVO E ASSINATURA',
            "28.1. As partes poderão assinar fisicamente ou por meio eletrônico idôneo. Recomenda-se a assinatura de duas testemunhas para reforço da executividade das obrigações líquidas quando cabível.\n\n" .
            "28.2. Obrigações dependentes de apuração deverão ser acompanhadas do respectivo demonstrativo."],
        ['CLÁUSULA 29ª – SOLUÇÃO DE CONTROVÉRSIAS',
            "29.1. As partes buscarão solução negocial antes do litígio, sem impedir tutela urgente.\n\n" .
            "29.2. Respeitadas regras cogentes de competência, fica eleito o foro de Barueri/SP."],
        ['CLÁUSULA 30ª – DISPOSIÇÕES FINAIS',
            "30.1. Tolerância não constitui novação ou renúncia.\n\n" .
            "30.2. Invalidade parcial não prejudica cláusulas autônomas preserváveis.\n\n" .
            "30.3. Qualquer alteração relevante deverá ser documentada por escrito ou eletronicamente."],
    ];
}

/**
 * Gera o PDF do contrato de VENDA (Fastcar revende veículo já comprado pro
 * COMPRADOR) e retorna o caminho do arquivo temporário. Mesmo padrão
 * visual do contrato de compra (cabeçalho navy+logo, Quadro-Resumo,
 * cláusulas, assinatura+testemunhas, rodapé) — transcrito do modelo real
 * 01_Contrato_Mestre_FASTCAR_Venda_Quitacao_Futura.docx (20 cláusulas).
 * $c: ver montarCamposContratoVenda() em includes/contratos.php.
 */
function gerarPdfContratoVenda(array $c): string {
    $pdf = _pdfNovo();
    _pdfCabecalho($pdf, 'CONTRATO-MESTRE DE COMPRA E VENDA DE VEÍCULO COM QUITAÇÃO FUTURA DO FINANCIAMENTO');

    $pdf->SetFillColor(247, 243, 231);
    $pdf->SetFont('Helvetica', 'B', 9);
    $pdf->MultiCell(0, 5.5, _pdfTexto(
        "DECLARAÇÃO ESSENCIAL\n" .
        "O COMPRADOR declara que, antes da assinatura, foi informado de forma ostensiva de que o veículo possui " .
        "ou poderá possuir gravame/financiamento ainda não quitado e que a transferência definitiva depende da " .
        "quitação e baixa. A FASTCAR assume obrigação contratual própria de promover a regularização no prazo " .
        "pactuado, sem transferir automaticamente ao COMPRADOR a dívida bancária."
    ), 0, 'L', true);
    $pdf->Ln(2);

    $pdf->SetFont('Helvetica', '', 9);
    $pdf->MultiCell(0, 5, _pdfTexto(
        "Pelo presente instrumento particular, de um lado, FASTCAR SOLUTIONS, pessoa jurídica de direito " .
        "privado, inscrita no CNPJ/MF sob o nº 66.934.500/0001-09, com sede na Avenida Sagitário, nº 138, " .
        "Sala 1003, 10º andar, Torre City (Torre 2), Complexo Alpha Square Offices, Alphaville Conde II, " .
        "Barueri/SP, CEP 06473-073, doravante denominada VENDEDORA ou FASTCAR; e, de outro lado, " .
        "{$c['comprador_nome']}, {$c['comprador_nacionalidade']}, {$c['comprador_estado_civil']}, " .
        "{$c['comprador_profissao']}, RG nº {$c['comprador_rg']}, CPF/CNPJ nº {$c['comprador_cpf']}, " .
        "CNH nº {$c['comprador_cnh']}, endereço {$c['comprador_endereco']}, telefone/WhatsApp " .
        "{$c['comprador_telefone']}, e-mail {$c['comprador_email']}, doravante denominado(a) COMPRADOR(A); " .
        "têm entre si justo e contratado o presente CONTRATO DE COMPRA E VENDA DE VEÍCULO COM QUITAÇÃO FUTURA " .
        "DE FINANCIAMENTO/GRAVAME E TRANSFERÊNCIA DEFINITIVA CONDICIONADA, regido pelo Quadro-Resumo, pelas " .
        "cláusulas seguintes e pelos anexos integrantes."
    ));

    $pdf->Ln(3);
    $pdf->SetFont('Helvetica', 'B', 11);
    $pdf->Cell(0, 7, _pdfTexto('QUADRO-RESUMO DA OPERAÇÃO'), 0, 1, 'C');
    $pdf->Ln(1);

    _pdfLinhaResumo($pdf, 'Contrato nº', 'VENDA-' . $c['_venda_id']);
    _pdfLinhaResumo($pdf, 'Veículo / versão', trim($c['veiculo_marca'] . ' ' . $c['veiculo_modelo']));
    _pdfLinhaResumo($pdf, 'Ano fabricação/modelo', $c['veiculo_ano']);
    _pdfLinhaResumo($pdf, 'Placa / RENAVAM / chassi', "{$c['veiculo_placa']} / {$c['veiculo_renavam']} / {$c['veiculo_chassi']}");
    _pdfLinhaResumo($pdf, 'Quilometragem na entrega', $c['km_entrega'] !== null ? $c['km_entrega'] . ' km' : '—');
    _pdfLinhaResumo($pdf, 'Valor FIPE de referência', _fmtMoeda($c['valor_fipe_referencia']));
    _pdfLinhaResumo($pdf, 'Preço ajustado entre FASTCAR e COMPRADOR', _fmtMoeda($c['preco_venda']));
    _pdfLinhaResumo($pdf, 'Valor pago pelo COMPRADOR na contratação', _fmtMoeda($c['valor_pago_contratacao']));
    _pdfLinhaResumo($pdf, 'Forma de pagamento do COMPRADOR', $c['forma_pagamento']);
    _pdfLinhaResumo($pdf, 'Saldo de preço devido pelo COMPRADOR à FASTCAR', $c['saldo_preco_devido'] ? _fmtMoeda($c['saldo_preco_devido']) : 'inexistente');
    _pdfLinhaResumo($pdf, 'Natureza do gravame/restrição', 'Alienação fiduciária em favor da instituição financeira indicada abaixo');
    _pdfLinhaResumo($pdf, 'Instituição financeira/credor vinculado', $c['banco_financiamento']);
    _pdfLinhaResumo($pdf, 'Contrato financeiro / referência', $c['contrato_financiamento_numero']);
    _pdfLinhaResumo($pdf, 'Saldo estimado do financiamento na contratação', _fmtMoeda($c['saldo_financiamento_atual']));
    _pdfLinhaResumo($pdf, 'Responsável pela dívida perante a instituição', 'FASTCAR');
    _pdfLinhaResumo($pdf, 'Prazo máximo para quitação/baixa', "Até {$c['prazo_quitacao_meses']} meses, contado da assinatura deste contrato, nunca superior a 24 meses");
    _pdfLinhaResumo($pdf, 'Data-limite objetiva', $c['data_limite_quitacao'] ?: 'a definir conforme prazo acima');
    _pdfLinhaResumo($pdf, 'Prestação de contas de andamento', $c['prestacao_contas_texto']);
    _pdfLinhaResumo($pdf, 'Seguro/proteção durante o período intermediário', $c['seguro_texto']);
    _pdfLinhaResumo($pdf, 'IPVA/licenciamento após entrega', $c['ipva_responsavel_texto']);
    _pdfLinhaResumo($pdf, 'Multas após entrega', $c['multas_texto']);
    _pdfLinhaResumo($pdf, 'Rastreador', $c['rastreador_texto'] ?: 'não informado');
    _pdfLinhaResumo($pdf, 'Prazo para transferência após baixa', $c['prazo_transferencia_dias'] !== null ? $c['prazo_transferencia_dias'] . ' dias úteis, observadas exigências do órgão de trânsito' : '—');
    _pdfLinhaResumo($pdf, 'Penalidade por atraso imputável à FASTCAR', $c['penalidade_atraso_texto'] ?: 'a definir entre as partes');

    foreach (clausulasContratoVenda() as [$titulo, $corpo]) {
        _pdfTituloClausula($pdf, $titulo);
        _pdfCorpo($pdf, $corpo);
    }

    $pdf->Ln(6);
    $pdf->SetFont('Helvetica', '', 9);
    $pdf->Cell(0, 6, _pdfTexto("Barueri/SP, {$c['data_extenso']}."), 0, 1, 'L');
    $pdf->Ln(14);

    $y = $pdf->GetY();
    $pdf->SetFont('Helvetica', '', 8.5);
    $pdf->Cell(85, 5, _pdfTexto('_______________________________'), 0, 0, 'C');
    $pdf->Cell(10, 5, '', 0, 0);
    $pdf->Cell(85, 5, _pdfTexto('_______________________________'), 0, 1, 'C');
    $pdf->Cell(85, 5, _pdfTexto('FASTCAR SOLUTIONS'), 0, 0, 'C');
    $pdf->Cell(10, 5, '', 0, 0);
    $pdf->Cell(85, 5, _pdfTexto('COMPRADOR(A)'), 0, 1, 'C');
    $pdf->Cell(85, 5, _pdfTexto('CNPJ 66.934.500/0001-09'), 0, 0, 'C');
    $pdf->Cell(10, 5, '', 0, 0);
    $pdf->Cell(85, 5, _pdfTexto("Nome/CPF: {$c['comprador_nome']} / {$c['comprador_cpf']}"), 0, 1, 'C');

    // Testemunha não é obrigatória pra gerar o contrato — mesma regra do
    // contrato de compra (fixas em Configurações, includes/marca.php); sem
    // preenchimento, a linha sai em branco pra assinatura física.
    $test1 = 'Nome: ' . ($c['testemunha1_nome'] ?: '______________________') . ' CPF: ' . ($c['testemunha1_cpf'] ?: '______________');
    $test2 = 'Nome: ' . ($c['testemunha2_nome'] ?: '______________________') . ' CPF: ' . ($c['testemunha2_cpf'] ?: '______________');

    $pdf->Ln(12);
    $pdf->Cell(85, 5, _pdfTexto('TESTEMUNHA 1'), 0, 0, 'C');
    $pdf->Cell(10, 5, '', 0, 0);
    $pdf->Cell(85, 5, _pdfTexto('TESTEMUNHA 2'), 0, 1, 'C');
    $pdf->Cell(85, 5, _pdfTexto($test1), 0, 0, 'C');
    $pdf->Cell(10, 5, '', 0, 0);
    $pdf->Cell(85, 5, _pdfTexto($test2), 0, 1, 'C');

    $pdf->Ln(10);
    $pdf->SetFont('Helvetica', '', 7.5);
    $pdf->SetTextColor(120, 120, 120);
    $pdf->Cell(0, 4, _pdfTexto('FASTCAR SOLUTIONS — CNPJ 66.934.500/0001-09'), 0, 1, 'C');
    $pdf->Cell(0, 4, _pdfTexto('Av. Sagitário, 138 — Sala 1003, 10º andar, Torre City (Torre 2), Complexo Alpha Square Offices'), 0, 1, 'C');
    $pdf->Cell(0, 4, _pdfTexto('Alphaville Conde II, Barueri/SP — CEP 06473-073'), 0, 1, 'C');
    $pdf->SetTextColor(0, 0, 0);

    $caminho = tempnam(sys_get_temp_dir(), 'contrato_venda_') . '.pdf';
    $pdf->Output('F', $caminho);
    return $caminho;
}

/**
 * Cláusulas do contrato-mestre de venda — [título, corpo]. Transcrito do
 * modelo real 01_Contrato_Mestre_FASTCAR_Venda_Quitacao_Futura.docx
 * (Setembro/2026), foro corrigido pra Barueri/SP igual ao contrato de
 * compra (endereço real da sede — o modelo original trazia Santana de
 * Parnaíba/SP genérico, mesma correção já aplicada em clausulasContratoCompra()).
 */
function clausulasContratoVenda(): array {
    return [
        ['CLÁUSULA 1ª – OBJETO, PREÇO E NATUREZA DA OPERAÇÃO',
            "1.1. A FASTCAR vende ao COMPRADOR o veículo identificado no Quadro-Resumo, com entrega da posse direta nas condições deste contrato e obrigação de promover a futura regularização necessária à transferência registral definitiva.\n\n" .
            "1.2. O preço devido pelo COMPRADOR à FASTCAR é exclusivamente o indicado no Quadro-Resumo. Se o campo \"saldo de preço devido pelo COMPRADOR\" estiver marcado como inexistente, nenhum valor adicional de preço será exigido do COMPRADOR para que a FASTCAR cumpra a obrigação de quitar o financiamento/gravame.\n\n" .
            "1.3. O saldo existente perante a instituição financeira não se confunde com saldo de preço do COMPRADOR. Salvo instrumento expresso firmado também com o credor quando exigível, o COMPRADOR não assume a posição de devedor do financiamento originário.\n\n" .
            "1.4. A operação não deverá ser divulgada ou documentada como transferência registral imediata quando houver impedimento decorrente do gravame. A FASTCAR deverá informar com clareza a situação documental real do veículo."],
        ['CLÁUSULA 2ª – SITUAÇÃO DOMINIAL, FINANCIAMENTO E GRAVAME',
            "2.1. A situação registral, titularidade formal, financiamento, alienação fiduciária, gravames, restrições e demais vínculos relevantes deverão constar do Anexo II, acompanhado dos documentos disponíveis.\n\n" .
            "2.2. Se o veículo estiver submetido a alienação fiduciária ou outra garantia em favor de instituição financeira, as partes reconhecem que os direitos do credor e as restrições registrárias permanecem eficazes até sua regular baixa.\n\n" .
            "2.3. A FASTCAR declara possuir legitimidade contratual e documental suficiente para celebrar esta operação e entregar a posse, devendo indicar no Anexo III a origem de sua legitimidade quando o titular registral for terceiro.\n\n" .
            "2.4. O COMPRADOR não poderá ser induzido a acreditar que o gravame já foi baixado quando isso ainda não tiver ocorrido."],
        ['CLÁUSULA 3ª – OBRIGAÇÃO DE QUITAÇÃO FUTURA PELA FASTCAR',
            "3.1. A FASTCAR obriga-se a negociar com a instituição financeira/credor, promover a quitação do saldo e adotar as providências necessárias à baixa do gravame no prazo indicado no Quadro-Resumo, limitado a 24 (vinte e quatro) meses contados da assinatura/entrega.\n\n" .
            "3.2. A forma de negociação com o credor poderá envolver liquidação antecipada, acordo, renegociação ou cumprimento do contrato financeiro, desde que não imponha ao COMPRADOR obrigação não expressamente assumida e não exponha o veículo a risco evitável de retomada por inadimplemento da FASTCAR.\n\n" .
            "3.3. A FASTCAR deverá manter a obrigação financeira em situação regular ou formalmente negociada durante o período intermediário e preservar documentos capazes de demonstrar o andamento.\n\n" .
            "3.4. A obrigação de quitar e viabilizar a transferência é obrigação própria da FASTCAR perante o COMPRADOR e não fica afastada pelo simples fato de a instituição financeira adotar procedimentos internos, exigir documentos ou modificar condições de negociação.\n\n" .
            "3.5. Ocorrendo impedimento extraordinário não imputável à FASTCAR, as partes deverão documentá-lo e definir providência proporcional, sem prorrogação automática além de 24 meses se isso esvaziar a finalidade econômica do contrato."],
        ['CLÁUSULA 4ª – INFORMAÇÃO, PRESTAÇÃO DE CONTAS E COMPROVAÇÃO',
            "4.1. A FASTCAR fornecerá ao COMPRADOR, na periodicidade prevista no Quadro-Resumo e também quando houver evento relevante, informação objetiva sobre o status da quitação: documento/consulta de saldo ou situação do financiamento quando disponível; comprovantes de pagamentos, acordos ou liquidação relevantes quando aplicável; informação sobre eventual atraso, renegociação, restrição adicional ou risco que possa afetar o veículo; e comprovante de baixa do gravame quando concluída.\n\n" .
            "4.2. Dados sigilosos estranhos à operação poderão ser protegidos, desde que isso não impeça o COMPRADOR de verificar o cumprimento da obrigação relativa ao veículo."],
        ['CLÁUSULA 5ª – ENTREGA DA POSSE E VISTORIA',
            "5.1. A posse direta será entregue ao COMPRADOR mediante Termo de Entrega e Vistoria, com identificação do estado do veículo, quilometragem, combustível, pneus, lataria, interior, acessórios, documentos e avarias preexistentes.\n\n" .
            "5.2. Fotografias e vídeos deverão integrar o dossiê sempre que possível.\n\n" .
            "5.3. A entrega da posse não equivale, por si só, à baixa do gravame nem à conclusão da transferência perante o órgão de trânsito.\n\n" .
            "5.4. O COMPRADOR deverá conservar o veículo e apresentá-lo quando razoavelmente necessário para inspeção, regularização documental ou procedimento exigido pela instituição/órgão competente, mediante prévio agendamento."],
        ['CLÁUSULA 6ª – PROPRIEDADE, TRANSFERÊNCIA DEFINITIVA E COOPERAÇÃO',
            "6.1. A transferência registral definitiva ocorrerá após a quitação e baixa do gravame, disponibilidade documental e cumprimento das exigências administrativas.\n\n" .
            "6.2. A FASTCAR praticará os atos que lhe competirem para assinatura da autorização de transferência, ATPV-e ou documento equivalente, reconhecimento/assinatura eletrônica e demais providências.\n\n" .
            "6.3. O COMPRADOR fornecerá tempestivamente seus documentos, realizará vistoria, pagará taxas que lhe forem atribuídas no Quadro-Resumo e praticará os atos que dependam de sua presença ou assinatura.\n\n" .
            "6.4. A FASTCAR não poderá criar novo gravame, garantia, cessão ou obrigação incompatível com esta venda sobre o veículo após a contratação, salvo medida indispensável à própria regularização e previamente informada ao COMPRADOR.\n\n" .
            "6.5. Concluída a baixa, a FASTCAR deverá iniciar a transferência no prazo do Quadro-Resumo, ressalvado atraso exclusivamente imputável ao COMPRADOR ou ao órgão público devidamente comprovado."],
        ['CLÁUSULA 7ª – USO DO VEÍCULO DURANTE O PERÍODO INTERMEDIÁRIO',
            "7.1. Até a transferência definitiva, o COMPRADOR exercerá a posse direta nos limites deste contrato, devendo utilizar o veículo de forma lícita e diligente, em especial:\n\n" .
            "— não vender, prometer vender, ceder definitivamente, dar em garantia ou constituir ônus sobre o veículo antes da regularização registral;\n" .
            "— não adulterar sinais identificadores, hodômetro, placas, chassi, etiquetas ou rastreador;\n" .
            "— comunicar acidente grave, apreensão, furto, roubo, perda total, bloqueio judicial ou restrição administrativa;\n" .
            "— manter dados de contato atualizados;\n" .
            "— preservar o veículo para a finalidade de transferência futura."],
        ['CLÁUSULA 8ª – DESPESAS, TRIBUTOS, MULTAS E MANUTENÇÃO',
            "8.1. A distribuição econômica de IPVA, licenciamento, seguro/proteção, manutenção e demais despesas constará do Quadro-Resumo e anexos.\n\n" .
            "8.2. Multas e infrações decorrentes do uso após a entrega serão de responsabilidade econômica do COMPRADOR na extensão legalmente aplicável, devendo colaborar com a identificação do condutor.\n\n" .
            "8.3. Débitos anteriores à entrega ou decorrentes de obrigação própria da FASTCAR não serão transferidos ao COMPRADOR sem previsão expressa e válida.\n\n" .
            "8.4. A manutenção ordinária após a entrega seguirá o Anexo V, sem prejuízo de garantia legal ou contratual por vícios quando aplicável."],
        ['CLÁUSULA 9ª – SEGURO, SINISTRO, FURTO, ROUBO E PERDA TOTAL',
            "9.1. As condições de seguro/proteção constarão do Anexo VI.\n\n" .
            "9.2. Em sinistro com indenização securitária enquanto houver gravame, as partes reconhecem que poderão existir direitos prioritários do credor financeiro sobre a indenização, conforme o vínculo securitário e financeiro.\n\n" .
            "9.3. A FASTCAR e o COMPRADOR deverão cooperar para que eventual indenização seja corretamente aplicada, com prestação de contas quanto à quitação do credor, saldo remanescente, substituição do veículo ou encerramento da operação.\n\n" .
            "9.4. Se a perda do veículo decorrer de fato não imputável ao COMPRADOR e inviabilizar definitivamente a transferência, deverá ser apurado o resultado econômico de forma transparente, sem enriquecimento indevido de qualquer parte."],
        ['CLÁUSULA 10ª – RASTREAMENTO E PROTEÇÃO DE DADOS',
            "10.1. Se houver rastreador/geolocalização, sua existência, finalidade, fornecedor e regras de acesso constarão do Anexo VIII.\n\n" .
            "10.2. O tratamento de dados observará finalidades de execução contratual, segurança, prevenção de fraude, proteção do ativo, cumprimento de obrigações legais e exercício regular de direitos.\n\n" .
            "10.3. Geolocalização não autoriza violência, constrangimento, exposição pública ou ingresso forçado em residência/garagem."],
        ['CLÁUSULA 11ª – GARANTIAS SOBRE A LEGITIMIDADE E AUSÊNCIA DE DUPLA ALIENAÇÃO',
            "11.1. A FASTCAR declara que não celebrará venda incompatível do mesmo veículo com terceiro após a assinatura deste contrato.\n\n" .
            "11.2. A FASTCAR deverá comunicar imediatamente qualquer penhora, bloqueio, ordem judicial, mora perante o credor financeiro ou fato superveniente capaz de ameaçar a posse ou a transferência futura.\n\n" .
            "11.3. Se terceiro reivindicar direito incompatível não informado previamente, a FASTCAR deverá prestar assistência documental e jurídica adequada e adotar providências para preservar os direitos do COMPRADOR."],
        ['CLÁUSULA 12ª – INADIMPLEMENTO DO COMPRADOR',
            "12.1. Se houver saldo de preço efetivamente devido pelo COMPRADOR à FASTCAR, seu inadimplemento seguirá as condições específicas do Quadro-Resumo e do Kit operacional.\n\n" .
            "12.2. O descumprimento de obrigações de conservação, apresentação documental, multas, seguro ou proibição de alienação deverá ser previamente documentado e, quando sanável, objeto de oportunidade razoável de regularização.\n\n" .
            "12.3. A existência de descumprimento pelo COMPRADOR não autoriza, por si só, retomada física coercitiva sem base jurídica e procedimento adequado."],
        ['CLÁUSULA 13ª – INADIMPLEMENTO DA FASTCAR E NÃO QUITAÇÃO NO PRAZO',
            "13.1. Constituem inadimplemento relevante da FASTCAR, sem prejuízo de outras hipóteses: (i) deixar vencer o prazo máximo sem quitação/regularização por fato imputável à FASTCAR; (ii) permitir inadimplemento do financiamento capaz de gerar retomada do veículo; (iii) criar novo ônus incompatível; (iv) ocultar restrição relevante; (v) recusar injustificadamente a transferência após a baixa.\n\n" .
            "13.2. Verificado risco antes do vencimento do prazo, o COMPRADOR poderá notificar a FASTCAR para apresentar comprovação de regularidade e plano de saneamento em prazo razoável.\n\n" .
            "13.3. Ultrapassada a data-limite sem quitação/baixa por motivo imputável à FASTCAR, o COMPRADOR poderá exigir cumprimento específico, perdas e danos quando cabíveis, ou resolução contratual com restituição dos valores pagos e demais consequências legalmente aplicáveis, observada a apuração do caso concreto.\n\n" .
            "13.4. Penalidade contratual eventualmente pactuada deverá constar do Quadro-Resumo, ser proporcional e não excluir indenização suplementar quando juridicamente admitida e comprovada."],
        ['CLÁUSULA 14ª – RISCO DE RETOMADA PELO CREDOR FINANCEIRO',
            "14.1. A FASTCAR reconhece que o inadimplemento do financiamento/garantia pode conferir ao credor medidas próprias sobre o veículo, razão pela qual se obriga a não expor o COMPRADOR, por conduta sua, a risco evitável de retomada.\n\n" .
            "14.2. Recebida notificação do credor, ordem, aviso de mora, busca, apreensão, consolidação, bloqueio ou outro ato que possa atingir o veículo, a FASTCAR deverá comunicar imediatamente o COMPRADOR e encaminhar o caso ao jurídico.\n\n" .
            "14.3. Se o COMPRADOR perder a posse por ato do credor decorrente de inadimplemento imputável à FASTCAR, a FASTCAR responderá pelas consequências contratuais e legais cabíveis, sem prejuízo da apuração de danos."],
        ['CLÁUSULA 15ª – DESISTÊNCIA, RESOLUÇÃO E PRESTAÇÃO DE CONTAS',
            "15.1. Hipóteses de desistência consensual, impossibilidade definitiva, resolução ou substituição do veículo serão formalizadas por termo específico.\n\n" .
            "15.2. A prestação de contas deverá discriminar preço pago, despesas atribuíveis, débitos de uso, eventuais restituições, indenizações securitárias e demais rubricas, sem compensações genéricas.\n\n" .
            "15.3. A devolução física, quando cabível, será documentada por vistoria e não implica quitação automática."],
        ['CLÁUSULA 16ª – VÍCIOS, GARANTIA E CONDIÇÃO DO VEÍCULO',
            "16.1. O estado aparente será registrado na vistoria, sem afastar direitos relativos a vícios ocultos ou garantias legais aplicáveis.\n\n" .
            "16.2. Nenhuma cláusula deste instrumento deverá ser interpretada como exclusão genérica de responsabilidade legalmente indisponível.\n\n" .
            "16.3. Reparos e reclamações deverão ser documentados para permitir análise de causa, responsabilidade e cobertura."],
        ['CLÁUSULA 17ª – COMUNICAÇÕES E PROVAS',
            "17.1. São canais válidos os indicados no Quadro-Resumo, sem prejuízo de outros meios comprováveis.\n\n" .
            "17.2. As partes deverão preservar comprovantes de pagamento, mensagens, notificações, documentos do financiamento, vistorias, fotos, vídeos e protocolos de transferência.\n\n" .
            "17.3. Mudança de contato deverá ser informada. A omissão não invalida automaticamente comunicação comprovadamente recebida por outro canal legítimo."],
        ['CLÁUSULA 18ª – ASSINATURA ELETRÔNICA, INTEGRIDADE E TESTEMUNHAS',
            "18.1. O contrato poderá ser assinado física ou eletronicamente por método apto a comprovar autoria e integridade.\n\n" .
            "18.2. Anexos e aditivos integram a operação quando identificados e vinculados ao número do contrato.\n\n" .
            "18.3. Recomenda-se assinatura por duas testemunhas quando juridicamente útil à força executiva do instrumento, sem prejuízo de outras formas legalmente reconhecidas."],
        ['CLÁUSULA 19ª – BOA-FÉ, TRANSPARÊNCIA E NÃO NOVAÇÃO',
            "19.1. As partes agirão com boa-fé, cooperação e dever de informação, especialmente quanto à situação do financiamento e da transferência.\n\n" .
            "19.2. Tolerância pontual não importa novação ou renúncia definitiva.\n\n" .
            "19.3. Alterações relevantes dependerão de aditivo ou registro eletrônico comprovável."],
        ['CLÁUSULA 20ª – FORO E SOLUÇÃO DE CONTROVÉRSIAS',
            "20.1. As partes buscarão solução negocial documentada antes da judicialização quando isso não comprometer direito urgente.\n\n" .
            "20.2. Respeitadas as regras cogentes de competência, fica eleito o foro da Comarca de Barueri/SP.\n\n" .
            "20.3. A eleição de foro não afasta foro legalmente inderrogável quando aplicável."],
    ];
}
