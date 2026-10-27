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
    $pdf->SetFillColor(47, 111, 237); // azul da marca (#2f6fed) — mesma cor do
    $pdf->Rect(0, 24, 210, 1.2, 'F');   // botão/rodapé dos e-mails (includes/email_templates.php),
    $pdf->SetTextColor(0, 0, 0);        // trocado do dourado original pra padronizar (23/09/2026,
    $pdf->SetY(30);                     // "esse precisa padronizaz deixe logo e as cores o template")
}

/**
 * Rodapé com o endereço real da sede, em faixa clara com borda azul no
 * topo — mesmo padrão visual do rodapé dos e-mails transacionais
 * (includes/email_templates.php::emailLayout()), 23/09/2026, pedido de
 * padronização. Antes era só texto cinza solto, sem nenhuma faixa;
 * compartilhado entre compra e venda (eram 2 cópias idênticas).
 */
function _pdfRodapeEndereco(FPDF $pdf): void {
    $pdf->Ln(8);
    if ($pdf->GetY() > 261) { // sem espaço pra faixa (22mm) antes da margem inferior — nunca corta no meio
        $pdf->AddPage();
    }
    $y = $pdf->GetY();
    $pdf->SetFillColor(244, 244, 247); // cinza-claro, igual ao fundo do rodapé do e-mail (#f4f4f7)
    $pdf->Rect(20, $y, 170, 22, 'F');
    $pdf->SetFillColor(47, 111, 237); // borda azul no topo da faixa, igual ao border-top do e-mail
    $pdf->Rect(20, $y, 170, 0.8, 'F');
    $pdf->SetY($y + 3);
    $pdf->SetFont('Helvetica', 'B', 8);
    $pdf->SetTextColor(21, 23, 34); // navy, igual ao <strong style="color:#151722"> do rodapé do e-mail
    $pdf->Cell(0, 4, _pdfTexto('FASTCAR SOLUTIONS — CNPJ 66.934.500/0001-09'), 0, 1, 'C');
    $pdf->SetFont('Helvetica', '', 7.5);
    $pdf->SetTextColor(107, 114, 128); // cinza, igual ao #6b7280 do rodapé do e-mail
    $pdf->Cell(0, 4, _pdfTexto('Av. Sagitário, 138 — Sala 1003, 10º andar, Torre City (Torre 2), Complexo Alpha Square Offices'), 0, 1, 'C');
    $pdf->Cell(0, 4, _pdfTexto('Alphaville Conde II, Barueri/SP — CEP 06473-073'), 0, 1, 'C');
    // 27/10/2026, "adicione e-mail corporativo no rodapé".
    $emailCorp = (function_exists('getConfig') ? (string)getConfig('email_from') : '') ?: 'contato@fastcar.solutions';
    $pdf->Cell(0, 4, _pdfTexto('E-mail: ' . $emailCorp), 0, 1, 'C');
    $pdf->SetTextColor(0, 0, 0);
}

/**
 * Linha "Representante Legal" sob "FASTCAR SOLUTIONS LTDA" no bloco de
 * assinatura — 02/10/2026, "na assinatura fast car vim Fastcar Solutions
 * Ltda, assinatura seria do Representante Legal". Antes, o lado FASTCAR da
 * assinatura só mostrava "FASTCAR SOLUTIONS"/CNPJ, sem nenhuma linha de
 * nome/CPF — a pessoa configurada em fastcar_signatario_nome/_cpf (admin/
 * configuracoes.php) só entrava como signatário na ZapSign, nunca era
 * impressa aqui, diferente do vendedor/comprador/testemunhas, que sempre
 * mostraram "Nome/CPF: ...". Compartilhada entre compra e venda (mesmo
 * texto/posição nos dois). Sem representante configurado ainda, sai linha
 * em branco pro nome/CPF serem escritos à mão — nunca bloqueia nada,
 * mesma disciplina de toda testemunha/representante deste contrato.
 */
function _pdfLinhaRepresentanteLegal(FPDF $pdf, array $c): void {
    $nome = trim((string)($c['fastcar_signatario_nome'] ?? ''));
    $texto = 'Representante Legal: ' . ($nome !== '' ? $nome : '______________________')
        . ' — CPF: ' . (($c['fastcar_signatario_cpf'] ?? '') ?: '______________');
    $pdf->Cell(85, 5, _pdfTexto($texto), 0, 1, 'C');
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

    // Garante que a linha INTEIRA (os 2 retângulos + rótulo + valor) nunca
    // fica partida entre 2 páginas — bug real achado em produção (02/10/2026,
    // venda #29, linha "Penalidade por atraso..."): Rect() desenha direto na
    // posição atual sem nunca disparar quebra de página sozinho (só
    // Cell()/MultiCell() fazem isso), então uma linha cujo Y já estava perto
    // do rodapé tinha o RÓTULO quebrando de página sozinho no meio do
    // MultiCell() (FPDF insere AddPage() automático lá dentro), enquanto o
    // $y capturado logo abaixo ficava desatualizado — o VALOR, desenhado em
    // seguida com esse $y antigo (da página anterior), quase sempre também
    // estourava o rodapé da página NOVA e disparava uma 2ª quebra: rótulo e
    // valor acabavam em páginas diferentes, cada um sozinho, sem o quadro
    // cinza ao redor (ele nunca "seguiu" a quebra, ficou só na 1ª página).
    // 277mm = 297mm (altura da A4) - 20mm (margem inferior de _pdfNovo()).
    if ($pdf->GetY() + $altura > 277) {
        $pdf->AddPage();
    }

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
 * Número por extenso (1-999) pro padrão jurídico "18 (dezoito) meses". O
 * prazo da VENDA deixou de ter teto em 06/10/2026 e o da COMPRA em
 * 27/10/2026 ("depende da financeira") — fora da faixa devolve null (nunca
 * um número repetido tipo "48 (48)"); quem chama decide o formato final.
 */
function _extensoMeses(int $n): ?string {
    $numeros = [
        1 => 'um', 2 => 'dois', 3 => 'três', 4 => 'quatro', 5 => 'cinco', 6 => 'seis',
        7 => 'sete', 8 => 'oito', 9 => 'nove', 10 => 'dez', 11 => 'onze', 12 => 'doze',
        13 => 'treze', 14 => 'quatorze', 15 => 'quinze', 16 => 'dezesseis', 17 => 'dezessete',
        18 => 'dezoito', 19 => 'dezenove', 20 => 'vinte', 21 => 'vinte e um', 22 => 'vinte e dois',
        23 => 'vinte e três', 24 => 'vinte e quatro',
    ];
    if (isset($numeros[$n])) return $numeros[$n];
    // 27/10/2026 — prazo da compra deixou de ter teto (depende da financeira):
    // extenso genérico de 25 a 999.
    if ($n < 25 || $n > 999) return null;
    $dezenas = [2 => 'vinte', 3 => 'trinta', 4 => 'quarenta', 5 => 'cinquenta', 6 => 'sessenta', 7 => 'setenta', 8 => 'oitenta', 9 => 'noventa'];
    $centenas = [1 => 'cento', 2 => 'duzentos', 3 => 'trezentos', 4 => 'quatrocentos', 5 => 'quinhentos', 6 => 'seiscentos', 7 => 'setecentos', 8 => 'oitocentos', 9 => 'novecentos'];
    $partes = [];
    $c = intdiv($n, 100); $r = $n % 100;
    if ($c > 0) $partes[] = ($n === 100) ? 'cem' : $centenas[$c];
    if ($r > 0) {
        if ($r < 20) $partes[] = $numeros[$r];
        else { $d = intdiv($r, 10); $u = $r % 10; $partes[] = $dezenas[$d] . ($u ? ' e ' . $numeros[$u] : ''); }
    }
    return implode(' e ', $partes);
}

/** Frase de prorrogação excepcional — só faz sentido enquanto o prazo é menor que 24 meses. */
function _prorrogacaoAte24(int $prazo): string {
    return $prazo < 24 ? ', podendo ser excepcionalmente prorrogado por até 24 (vinte e quatro) meses' : '';
}

/**
 * "N (extenso) meses" quando N tem extenso conhecido (1-24), senão só "N
 * meses" — nunca repete o número tipo "48 (48)". Usado pelo prazo de
 * quitação da VENDA, que não tem mais teto fixo.
 */
function _mesesComExtenso(int $n): string {
    $extenso = _extensoMeses($n);
    return $extenso !== null ? "{$n} ({$extenso}) meses" : "{$n} meses";
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
        "CNH nº {$c['vendedor_cnh']}, residente em {$c['vendedor_endereco']}, telefone/WhatsApp " .
        "{$c['vendedor_telefone']}, e-mail {$c['vendedor_email']}, doravante VENDEDOR/PROPRIETÁRIO " .
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
    _pdfLinhaResumo($pdf, 'Percentual pago pela FASTCAR ao VENDEDOR', $c['percentual_fipe'] . '% da FIPE');
    _pdfLinhaResumo($pdf, 'Valor pago ao VENDEDOR', _fmtMoeda($c['valor_pago_vendedor']));
    // 06/10/2026 — mesmo pedido já aplicado no Quadro-Resumo da VENDA
    // ("no de compras campos vazio não gerar também"): campo opcional sem
    // dado real no banco nunca mais imprime linha vazia/com fallback tipo
    // "a informar"/"a indicar" — some sozinho, só aparece quando alguém de
    // fato preencheu. Campos centrais do negócio (veículo, FIPE, valor
    // pago ao vendedor, prazo de quitação — já obrigatório pra gerar o
    // contrato) nunca entram nessa checagem, continuam sempre impressos.
    // 27/10/2026, "às vezes a Fastcar manda 50 depois manda 50 devido
    // alguma pendência" — texto livre, só sai quando preenchido.
    if (!empty($c['condicao_pagamento_cedente'])) {
        _pdfLinhaResumo($pdf, 'Condição de pagamento ao VENDEDOR', $c['condicao_pagamento_cedente']);
    }
    // 27/10/2026 — autorização de uso de imagem/voz, perguntada pelo consultor (Sim/Não).
    $autImg = $c['autoriza_uso_imagem'] ?? null;
    _pdfLinhaResumo($pdf, 'Autorização de uso de imagem e voz — Autorizo, de forma gratuita e definitiva, o uso de minha imagem e voz gravadas em vídeo para veiculação em redes sociais, internet e demais mídias pela FastCar, sem qualquer direito a indenização ou remuneração, por prazo indeterminado, podendo ser revogado mediante notificação extrajudicial via ouvidoria (11) 97816-4525.',
        '(' . ($autImg === 1 ? 'X' : ' ') . ') Sim     (' . ($autImg === 0 ? 'X' : ' ') . ') Não');
    // Cláusula 10.1 (27/10/2026) — período em que a FASTCAR paga as parcelas, quando combinado.
    if (!empty($c['parcelas_pagas_fastcar_texto'])) {
        _pdfLinhaResumo($pdf, 'Pagamento das parcelas do financiamento pela FASTCAR (cláusula 10.1)', $c['parcelas_pagas_fastcar_texto']);
    }
    if (!empty($c['pix_pagamento_cedente'])) {
        _pdfLinhaResumo($pdf, 'Chave PIX do VENDEDOR para pagamento', $c['pix_pagamento_cedente']);
    }
    // 27/10/2026 — só aparece quando há débito declarado ("se for não, não colocar nada").
    if (($c['debitos_existem'] ?? null) === 1 && !empty($c['debitos_referencias'])) {
        _pdfLinhaResumo($pdf, 'Débitos em aberto na presente data (IPVA, licenciamento ou multas)', 'Sim — ' . preg_replace('/\s*\R\s*/', '; ', $c['debitos_referencias']));
    }
    if (!empty($c['banco_financiamento'])) {
        _pdfLinhaResumo($pdf, 'Instituição financeira/credor', $c['banco_financiamento']);
    }
    if (!empty($c['contrato_financiamento_numero'])) {
        _pdfLinhaResumo($pdf, 'Contrato de financiamento nº', $c['contrato_financiamento_numero']);
    }
    _pdfLinhaResumo($pdf, 'Saldo estimado do financiamento na data', _fmtMoeda($c['saldo_financiamento_atual']));
    if (!empty($c['responsavel_registral'])) {
        _pdfLinhaResumo($pdf, 'Responsável registral perante o credor', $c['responsavel_registral']);
    }
    _pdfLinhaResumo($pdf, 'Prazo pra quitação do financiamento', "Fica ajustado, entretanto, que o prazo supracitado será de até " .
        _mesesComExtenso((int)$c['prazo_quitacao_meses']) . ", contado de {$c['data_entrega_posse']}" .
        _prorrogacaoAte24((int)$c['prazo_quitacao_meses']) . ".");
    if (!empty($c['terceiro_quitacao'])) {
        _pdfLinhaResumo($pdf, 'Terceiro indicado pela FASTCAR para a quitação', $c['terceiro_quitacao']);
    }
    if (!empty($c['data_entrega_posse'])) {
        _pdfLinhaResumo($pdf, 'Posse física entregue à FASTCAR em', $c['data_entrega_posse']);
    }
    _pdfLinhaResumo($pdf, 'Exploração econômica pela FASTCAR', 'Autorizada, inclusive locação a terceiros, nos limites contratuais');
    _pdfLinhaResumo($pdf, 'Transferência final', 'Após quitação/baixa do gravame e cumprimento das formalidades legais');
    // Linha de seguro/proteção removida em 27/10/2026 — cláusula 12.1: seguro é responsabilidade do VENDEDOR.
    if (!empty($c['encargos_texto'])) {
        _pdfLinhaResumo($pdf, 'IPVA/licenciamento/multas após entrega', $c['encargos_texto']);
    }

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
    $pdf->Cell(85, 5, _pdfTexto('FASTCAR SOLUTIONS LTDA'), 0, 0, 'C');
    $pdf->Cell(10, 5, '', 0, 0);
    $pdf->Cell(85, 5, _pdfTexto('VENDEDOR/PROPRIETÁRIO'), 0, 1, 'C');
    $pdf->Cell(85, 5, _pdfTexto('CNPJ 66.934.500/0001-09'), 0, 0, 'C');
    $pdf->Cell(10, 5, '', 0, 0);
    $pdf->Cell(85, 5, _pdfTexto("Nome/CPF: {$c['vendedor_nome']} / {$c['vendedor_cpf']}"), 0, 1, 'C');
    // 27/10/2026, "retirar representante legal, deixa só FASTCAR" — compra sem a linha.

    // Testemunha não é obrigatória pra gerar o contrato — se ainda não foi
    // preenchida (responsável pela negociação, ou testemunha 2 ainda não
    // escolhida em admin/oportunidade.php), a linha sai em branco pro
    // nome/CPF serem escritos à mão no presencial, mesmo comportamento de
    // antes dessas colunas existirem.
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
    _pdfRodapeEndereco($pdf);

    $caminho = tempnam(sys_get_temp_dir(), 'contrato_compra_') . '.pdf';
    $pdf->Output('F', $caminho);
    return $caminho;
}

/**
 * Cláusulas do contrato-mestre de compra — [título, corpo].
 * $prazoMeses: prazo negociado pra quitar o financiamento (normal 12-18;
 * sem teto de 24 desde 27/10/2026 — depende da financeira, até 240 em
 * admin/oportunidade.php). 17/09/2026: até então vinha fixo em "24 meses"
 * hardcoded em todo lugar — ver bullet "Saldo do financiamento..." não,
 * ver CLAUDE.md seção "Módulo de contrato (só COMPRA)" pro histórico.
 */
function clausulasContratoCompra(int $prazoMeses = 24): array {
    return [
        ['CLÁUSULA 1ª – OBJETO E ESTRUTURA DA OPERAÇÃO',
            "1.1. O VENDEDOR negocia com a FASTCAR os direitos econômicos e a futura aquisição do veículo identificado no Quadro-Resumo, entregando-lhe desde já a posse direta e autorizando sua administração e exploração econômica, permanecendo a transferência registral definitiva condicionada à quitação e baixa de eventual gravame.\n\n" .
            "1.2. Como contraprestação inicial, a FASTCAR pagará ao VENDEDOR o valor indicado no Quadro-Resumo, correspondente ao percentual livremente ajustado.\n\n" .
            "1.3. O restante da estrutura econômica da operação consiste na quitação futura do financiamento/gravame, por terceiro indicado pela FASTCAR, dentro do prazo máximo estipulado no Quadro-Resumo da operação.\n\n" .
            "1.4. O pagamento da dívida ao credor por terceiro não equivale, por si só, à assunção formal da dívida perante a instituição financeira, nem substitui eventual consentimento do credor exigido para mudança de devedor, novação ou alteração contratual.\n\n" .
            "1.5. Enquanto vigente alienação fiduciária, a titularidade resolúvel e os direitos do credor fiduciário permanecem preservados, não podendo este instrumento ser interpretado como autorização para frustrar, ocultar ou impedir o exercício legítimo desses direitos."],
        ['CLÁUSULA 2ª – DECLARAÇÕES DO VENDEDOR E SITUAÇÃO DO VEÍCULO',
            "2.1. O VENDEDOR declara ser o proprietário registral/devedor fiduciante ou possuir legitimidade documental suficiente para a operação, devendo revelar integralmente gravames, financiamento, parcelas vencidas, ações, restrições, multas, sinistros, débitos e qualquer fato relevante.\n\n" .
            "2.2. O VENDEDOR entregará CRLV-e, contrato/extrato do financiamento, demonstrativo de saldo, boletos ou meios de consulta, documentos pessoais, chaves e demais documentos indicados nos anexos.\n\n" .
            "2.3. A omissão de restrição relevante, bloqueio, sinistro estrutural, busca e apreensão, fraude, perda total ou impedimento de transferência ou em caso de eventual apontamento do laudo cautelar, caracteriza inadimplemento grave e em alguns casos, poderá ser abatido do crédito de compra as despesas comprovadas de regularização ou percentual de até 20% do crédito, sendo uma opção do comprador o desconto do crédito ou o desfazimento do negócio, ocasião em que o veículo poderá ser devolvido mediante o pagamento de todas as despesas e eventual crédito pago a título antecipatório."],
        ['CLÁUSULA 3ª – PREÇO INICIAL E QUITAÇÃO AO VENDEDOR',
            "3.1. O valor pago pela FASTCAR ao VENDEDOR constitui a parcela imediata do preço desta operação, conforme Quadro-Resumo e comprovante.\n\n" .
            "3.2. O VENDEDOR dará recibo do valor efetivamente recebido, sem que isso importe declaração de quitação do financiamento perante a instituição financeira.\n\n" .
            "3.3. Nenhum valor adicional será devido ao VENDEDOR além do expressamente previsto neste contrato, ressalvado aditivo escrito."],
        ['CLÁUSULA 4ª – QUITAÇÃO FUTURA DO FINANCIAMENTO POR TERCEIRO INDICADO',
            "4.1. A FASTCAR deverá organizar a indicação de terceiro responsável por realizar a quitação do financiamento ou gravame no prazo estipulado no Quadro-Resumo da operação.\n\n" .
            "4.2. O terceiro pagador poderá quitar integralmente a dívida, negociar liquidação antecipada ou aderir a solução aceita pela instituição financeira que resulte na efetiva baixa do gravame dentro do prazo máximo contratual.\n\n" .
            "4.3. A indicação de terceiro não exonera a FASTCAR de sua obrigação contratual de gestão e resultado perante o VENDEDOR quanto à obtenção da quitação/baixa no prazo, salvo se o impedimento decorrer exclusivamente de ato do VENDEDOR ou de fato documental por ele omitido.\n\n" .
            "4.4. Pagamentos ao credor serão comprovados por documentos idôneos. A FASTCAR manterá trilha de acompanhamento e fornecerá ao VENDEDOR informações razoáveis sobre marcos relevantes.\n\n" .
            "4.5. Se a instituição exigir anuência, comparecimento, assinatura ou documento do VENDEDOR, este deverá cooperar em prazo razoável, sem assumir obrigações novas não previstas."],
        ["CLÁUSULA 5ª – PRAZO DE ATÉ {$prazoMeses} MESES",
            "5.1. A quitação integral da obrigação financeira objeto deste contrato deverá ocorrer, em regra, no prazo inicial de 12 (doze) meses, contados a partir da data da efetiva entrega do veículo à FASTCAR, indicada no Quadro-Resumo. Fica ajustado, entretanto, que o prazo supracitado será de até " .
            _mesesComExtenso((int)$prazoMeses) . _prorrogacaoAte24((int)$prazoMeses) . ", desde que tal extensão derive de necessidade operacional, administrativa ou financeira, não configurando, em qualquer hipótese, inadimplemento, mora ou descumprimento contratual por parte da FASTCAR.\n\n" .
            "5.2. O prazo é limite máximo para obtenção da quitação e baixa do gravame, não simples prazo para início de negociação. A baixa do gravame depende da instituição financeira e não da FASTCAR.\n\n" .
            "5.3. Nos 90, 60 e 30 dias anteriores ao termo final, a FASTCAR deverá promover revisão documentada do status da dívida e do plano de quitação."],
        ['CLÁUSULA 6ª – POSSE, GUARDA E ENTREGA',
            "6.1. O VENDEDOR entrega voluntariamente a posse física do veículo à FASTCAR mediante Termo de Entrega e Vistoria.\n\n" .
            "6.2. A entrega não altera, perante o credor ou o órgão de trânsito, a titularidade registral que dependa de quitação, baixa de gravame e demais formalidades.\n\n" .
            "6.3. A FASTCAR assume a guarda operacional do veículo a partir da entrega, nos limites das responsabilidades definidas neste contrato."],
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
            "10.1. Em casos específicos, a FASTCAR poderá, de comum acordo com o VENDEDOR, dar continuidade ao pagamento das parcelas do financiamento durante o período que será descrito no Quadro-Resumo da operação."],
        ['CLÁUSULA 11ª – TRIBUTOS, LICENCIAMENTO E MULTAS',
            "11.1. A distribuição econômica de IPVA, licenciamento, multas, pedágios e demais encargos constará do Quadro de Resumo Operacional.\n\n" .
            "11.2. Infrações decorrentes da utilização do veículo após a entrega serão economicamente atribuídas ao responsável pela condução/uso, cabendo à FASTCAR organizar a identificação de condutor e o reembolso quando aplicável.\n\n" .
            "11.3. Débitos anteriores à entrega serão de responsabilidade do VENDEDOR, salvo assunção expressa pela FASTCAR."],
        ['CLÁUSULA 12ª – SEGURO, PROTEÇÃO E SINISTROS',
            "12.1. A FASTCAR não é responsável pela verificação e/ou documentação da existência de seguro/proteção compatível com o modo de exploração do veículo, sendo de inteira responsabilidade do VENDEDOR."],
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
            "18.3. Se o prazo estipulado no Quadro de Resumo Operacional expirar sem quitação por fato imputável à FASTCAR ou a terceiro por ela indicado, a FASTCAR permanecerá responsável perante o VENDEDOR pelo cumprimento da obrigação contratual de resultado e pelos efeitos comprovadamente decorrentes do descumprimento, sem prejuízo de regressar contra o terceiro indicado."],
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
        ['CLÁUSULA 26ª – DA NEGOCIAÇÃO COM A INSTITUIÇÃO FINANCEIRA',
            "26.1. O VENDEDOR compromete-se a não celebrar, sem a anuência prévia e expressa da FASTCAR, acordo, renegociação, refinanciamento ou qualquer outra operação com a instituição financeira que altere as condições do financiamento vinculado ao veículo ou interfira na quitação assumida pela FASTCAR.\n\n" .
            "26.2. O VENDEDOR deverá comunicar à FASTCAR eventual cobrança, proposta de acordo ou notificação recebida do banco.\n\n" .
            "26.3. O descumprimento desta cláusula, fora dessas hipóteses, poderá gerar responsabilidade pelos prejuízos efetivamente comprovados."],
        ['CLÁUSULA 27ª – BOA-FÉ E DEVER DE INFORMAÇÃO',
            "27.1. As partes atuarão com boa-fé, transparência, cooperação e preservação da documentação.\n\n" .
            "27.2. Nenhuma parte poderá criar aparência de transferência plena enquanto o gravame impedir a efetiva transferência registral."],
        ['CLÁUSULA 28ª – TÍTULO EXECUTIVO E ASSINATURA',
            "28.1. As partes poderão assinar fisicamente ou por meio eletrônico idôneo. Recomenda-se a assinatura de duas testemunhas para reforço da executividade das obrigações líquidas quando cabível.\n\n" .
            "28.2. Obrigações dependentes de apuração deverão ser acompanhadas do respectivo demonstrativo."],
        ['CLÁUSULA 29ª – SOLUÇÃO DE CONTROVÉRSIAS',
            "29.1. As partes buscarão solução negocial antes do litígio, sem impedir tutela urgente.\n\n" .
            "29.2. Respeitadas regras cogentes de competência, fica eleito o foro da Comarca de Barueri/SP para dirimir quaisquer litígios decorrentes deste contrato."],
        ['CLÁUSULA 30ª – DISPOSIÇÕES FINAIS',
            "30.1. Tolerância não constitui novação ou renúncia.\n\n" .
            "30.2. Invalidade parcial não prejudica cláusulas autônomas preserváveis.\n\n" .
            "30.3. Qualquer alteração relevante deverá ser documentada por escrito ou eletronicamente."],
    ];
}

/**
 * Gera o PDF do contrato de PROMISSÓRIA (venda parcelada, tipo_contrato=
 * 'venda') e retorna o caminho do arquivo temporário — "CONTRATO DE
 * AQUISIÇÃO DE POSSE VEICULAR COM PROMESSA CONDICIONADA DE COMPRA E VENDA",
 * transcrito do modelo Contrato_FASTCAR_PROMISSÓRIA.docx (27/10/2026,
 * "vamos substituir contrato atual no sistema de promissória") — substituiu o
 * antigo contrato-mestre de venda com quitação futura (20 cláusulas).
 * Endereço da sede e foro corrigidos pra Barueri/SP (o modelo trazia o
 * endereço antigo de Santana de Parnaíba/SP — mesma correção dos outros
 * contratos). Quadro-Resumo calculado em promissoriaQuadro()
 * (includes/promissoria.php). $c: ver montarCamposContratoVenda().
 */
function gerarPdfContratoVenda(array $c): string {
    $p = $c['_prom'] ?? [];
    $q = promissoriaQuadro($c);
    $na = 'não aplicável';
    $data = fn(?string $ymd) => $ymd ? date('d/m/Y', strtotime($ymd)) : '—';

    $pdf = _pdfNovo();
    _pdfCabecalho($pdf, 'AQUISIÇÃO DE POSSE VEICULAR COM PROMESSA CONDICIONADA DE COMPRA E VENDA');

    $pdf->SetFont('Helvetica', '', 9);
    $pdf->MultiCell(0, 5, _pdfTexto(
        "Pelo presente instrumento particular, de um lado, FASTCAR SOLUTIONS, pessoa jurídica de direito " .
        "privado, inscrita no CNPJ/MF sob o nº 66.934.500/0001-09, com sede na Avenida Sagitário, nº 138, " .
        "Sala 1003, 10º andar, Torre City (Torre 2), Complexo Alpha Square Offices, Alphaville Conde II, " .
        "Barueri/SP, CEP 06473-073, doravante denominada LOCADORA/PROMITENTE VENDEDORA ou FASTCAR;\n" .
        "e, de outro lado, {$c['comprador_nome']}, {$c['comprador_nacionalidade']}, {$c['comprador_estado_civil']}, " .
        "{$c['comprador_profissao']}, RG nº {$c['comprador_rg']}, CPF nº {$c['comprador_cpf']}, " .
        "CNH nº {$c['comprador_cnh']}, residente e domiciliado(a) à {$c['comprador_endereco']}, telefone/WhatsApp " .
        "{$c['comprador_telefone']}, e-mail {$c['comprador_email']}, doravante denominado(a) LOCATÁRIO/PROMITENTE COMPRADOR;\n" .
        "têm entre si justo e contratado o presente CONTRATO DE AQUISIÇÃO DE POSSE VEICULAR COM PROMESSA " .
        "CONDICIONADA DE COMPRA E VENDA, mediante as cláusulas seguintes."
    ));

    $pdf->Ln(3);
    $pdf->SetFont('Helvetica', 'B', 11);
    $pdf->Cell(0, 7, _pdfTexto('QUADRO-RESUMO DA CONTRATAÇÃO'), 0, 1, 'C');
    $pdf->Ln(1);

    $secao = function (string $titulo) use ($pdf) {
        if ($pdf->GetY() > 265) $pdf->AddPage();
        $pdf->SetFont('Helvetica', 'B', 9);
        $pdf->SetFillColor(21, 23, 34);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->Cell(170, 6, _pdfTexto($titulo), 0, 1, 'L', true);
        $pdf->SetTextColor(0, 0, 0);
    };

    _pdfLinhaResumo($pdf, 'Contrato nº', 'VENDA-' . $c['_venda_id']);

    $secao('VEÍCULO');
    _pdfLinhaResumo($pdf, 'Marca / modelo / versão / ano', trim("{$c['veiculo_marca']} {$c['veiculo_modelo']} {$c['veiculo_ano']}"));
    _pdfLinhaResumo($pdf, 'Placa / RENAVAM / chassi', "{$c['veiculo_placa']} / {$c['veiculo_renavam']} / {$c['veiculo_chassi']}");
    _pdfLinhaResumo($pdf, 'Valor FIPE e data da consulta', _fmtMoeda($c['valor_fipe_referencia']) . ' / ' . $data($p['fipe_data_consulta'] ?? null));
    $credor = $c['banco_financiamento'] ? $c['banco_financiamento'] . ' — alienação fiduciária' : 'sem gravame informado';
    $proprietario = ($p['proprietario_registral'] ?? '') ?: ($c['vendedor_original_nome'] ?? '');
    _pdfLinhaResumo($pdf, 'Proprietário registral / credor e gravame', ($proprietario ?: '—') . ' / ' . $credor);
    _pdfLinhaResumo($pdf, 'Responsável pela quitação / prazo e baixa do gravame', 'FASTCAR / a baixa do gravame se dará após a quitação do presente contrato perante a FASTCAR');
    _pdfLinhaResumo($pdf, 'Terceiro vendedor intermediado, se aplicável', ($p['terceiro_vendedor'] ?? '') ?: $na);

    $secao('ENTRADA E COMISSÃO');
    _pdfLinhaResumo($pdf, 'Preço do veículo', _fmtMoeda($c['preco_venda']));
    _pdfLinhaResumo($pdf, 'Comissão de intermediação, se aplicável', $q['comissao'] !== null
        ? PROMISSORIA_COMISSAO_PCT . '% da FIPE: ' . _fmtMoeda($q['comissao'])
        : ($q['comissao_aplicavel'] ? 'FIPE de referência não informada' : $na));
    _pdfLinhaResumo($pdf, 'Entrada total e forma de pagamento', _fmtMoeda((float)$c['valor_pago_contratacao']) . ' / ' . ($c['forma_pagamento'] ?: '—'));
    if (count($c['_entrada_partes'] ?? []) > 1) {
        foreach ($c['_entrada_partes'] as $i => $parte) {
            $dataParte = $parte['data_prevista'] ? date('d/m/Y', strtotime($parte['data_prevista'])) : 'sem data';
            _pdfLinhaResumo($pdf, "  · Entrada, parte " . ($i + 1) . " (PIX)", _fmtMoeda($parte['valor']) . " — {$dataParte}");
        }
    }
    if (!empty($c['bem_troca_recebido'])) {
        $descBem = $c['bem_troca_nome'] ?: 'bem não identificado';
        if ($c['bem_troca_tipo'] === 'veiculo') {
            $descBem .= trim(" — {$c['bem_troca_modelo_ano']} {$c['bem_troca_ano_fabricacao']} {$c['bem_troca_cor']}, placa {$c['bem_troca_placa']}, chassi {$c['bem_troca_chassi']}, RENAVAM {$c['bem_troca_renavam']}");
        }
        _pdfLinhaResumo($pdf, 'Bem recebido como parte da entrada', $descBem . ' — valor atribuído: ' . _fmtMoeda($c['bem_troca_valor']));
    }
    _pdfLinhaResumo($pdf, 'Comissão abatida da entrada', $q['comissao_abatida'] !== null ? _fmtMoeda($q['comissao_abatida']) : $na);
    _pdfLinhaResumo($pdf, 'Saldo líquido da entrada destinado ao preço', _fmtMoeda($q['saldo_liquido_entrada']));
    $comissaoPagar = $na;
    if ($q['comissao_a_pagar'] !== null && $q['comissao_a_pagar'] > 0) {
        $comissaoPagar = _fmtMoeda($q['comissao_a_pagar']) . ' / ' . $data($p['comissao_saldo_vencimento'] ?? null);
    }
    _pdfLinhaResumo($pdf, 'Comissão ainda a pagar, se houver / vencimento', $comissaoPagar);

    $secao('PARCELAMENTO');
    $semanal = ($c['parcelamento_periodicidade'] ?? 'mensal') === 'semanal';
    if ($q['tem_parcelas']) {
        _pdfLinhaResumo($pdf, 'Número de parcelas / valor de cada parcela', "{$c['parcelamento_qtd_parcelas']} parcelas de " . _fmtMoeda($c['parcelamento_valor_parcela']) . ($semanal ? ' (semanais)' : ''));
        _pdfLinhaResumo($pdf, 'Composição por parcela: locação ' . PROMISSORIA_PCT_LOCACAO . '% / residual ' . (100 - PROMISSORIA_PCT_LOCACAO) . '%', _fmtMoeda($q['parte_locacao']) . ' / ' . _fmtMoeda($q['parte_residual']));
        $demais = $semanal ? 'semanalmente, a cada 7 dias' : ($q['dia_demais'] ? 'dia ' . $q['dia_demais'] . ' de cada mês' : '—');
        _pdfLinhaResumo($pdf, 'Vencimento da primeira / demais parcelas', ($c['parcelamento_primeira_parcela_data'] ?: '—') . ' / ' . $demais);
        _pdfLinhaResumo($pdf, 'Valor total das parcelas', _fmtMoeda($q['total_parcelas']));
    } else {
        _pdfLinhaResumo($pdf, 'Número de parcelas / valor de cada parcela', 'a definir — parcelamento ainda não gerado');
    }
    _pdfLinhaResumo($pdf, 'Saldo final adicional, se houver / vencimento', $q['saldo_final'] !== null
        ? _fmtMoeda($q['saldo_final']) . ' / ' . $data($p['saldo_final_vencimento'] ?? null) : $na);
    _pdfLinhaResumo($pdf, 'Total a pagar pelo comprador, inclusive comissão', _fmtMoeda($q['total_a_pagar']));
    if (!empty($c['multa_atraso_parcelas_texto'])) {
        _pdfLinhaResumo($pdf, 'Encargos de mora (cláusula 14.2)', $c['multa_atraso_parcelas_texto']);
    }
    $prot = $p['protecao_contratada'] ?? '';
    _pdfLinhaResumo($pdf, 'Proteção veicular: contratada / responsável / coberturas',
        ($prot === 'sim' ? '(X) Sim  ( ) Não' : ($prot === 'nao' ? '( ) Sim  (X) Não' : '( ) Sim  ( ) Não')) .
        ' / ' . (($p['protecao_responsavel'] ?? '') ?: '—') . ' / ' . (($p['protecao_coberturas'] ?? '') ?: '—'));
    _pdfLinhaResumo($pdf, 'Entrega da posse: data, hora e local', $data($p['entrega_data'] ?? null) . ' / ' . (($p['entrega_hora'] ?? '') ?: '—') . ' / ' . (($p['entrega_local'] ?? '') ?: '—'));
    $img = $p['autoriza_imagem'] ?? '';
    _pdfLinhaResumo($pdf, 'Autorizo, de forma gratuita e definitiva, o uso de minha imagem e voz gravadas em vídeo para veiculação em redes sociais, internet e demais mídias pela FastCar, sem qualquer direito a indenização ou remuneração, por prazo indeterminado, podendo ser revogado mediante notificação extrajudicial via ouvidoria (11) 97816-4525.',
        $img === 'sim' ? '(X) SIM     ( ) NÃO' : ($img === 'nao' ? '( ) SIM     (X) NÃO' : '( ) SIM     ( ) NÃO'));
    _pdfLinhaResumo($pdf, 'Ciência específica do titular sobre a escolha', 'Rubrica / assinatura específica: __________________');

    $pdf->Ln(2);
    $pdf->SetFont('Helvetica', 'I', 8);
    $pdf->MultiCell(0, 4.5, _pdfTexto(
        'O Quadro-Resumo integra este contrato e deverá ser preenchido antes da assinatura quanto às condições ' .
        'essenciais. A entrega efetiva e a vistoria serão confirmadas por escrito no momento da entrega. Campos não ' .
        'aplicáveis serão identificados como tal. Havendo divergência, prevalecerá o dado específico regularmente ' .
        'preenchido, respeitadas as normas cogentes.'
    ));

    foreach (clausulasContratoVenda((int)($p['prazo_devolucao_horas'] ?? 0), (int)($p['prazo_saneamento_dias'] ?? 0)) as [$titulo, $corpo]) {
        _pdfTituloClausula($pdf, $titulo);
        _pdfCorpo($pdf, $corpo);
    }

    $pdf->Ln(6);
    $pdf->SetFont('Helvetica', '', 9);
    $pdf->Cell(0, 6, _pdfTexto("Barueri/SP, {$c['data_extenso']}."), 0, 1, 'L');
    $pdf->Ln(14);

    $pdf->SetFont('Helvetica', '', 8.5);
    $pdf->Cell(85, 5, _pdfTexto('_______________________________'), 0, 0, 'C');
    $pdf->Cell(10, 5, '', 0, 0);
    $pdf->Cell(85, 5, _pdfTexto('_______________________________'), 0, 1, 'C');
    $pdf->Cell(85, 5, _pdfTexto('FASTCAR SOLUTIONS LTDA'), 0, 0, 'C');
    $pdf->Cell(10, 5, '', 0, 0);
    $pdf->Cell(85, 5, _pdfTexto('LOCATÁRIO/PROMITENTE COMPRADOR'), 0, 1, 'C');
    $pdf->Cell(85, 5, _pdfTexto('CNPJ 66.934.500/0001-09'), 0, 0, 'C');
    $pdf->Cell(10, 5, '', 0, 0);
    $pdf->Cell(85, 5, _pdfTexto("Nome/CPF: {$c['comprador_nome']} / {$c['comprador_cpf']}"), 0, 1, 'C');
    _pdfLinhaRepresentanteLegal($pdf, $c);

    $test1 = 'Nome: ' . ($c['testemunha1_nome'] ?: '______________________') . ' CPF: ' . ($c['testemunha1_cpf'] ?: '______________');
    $test2 = 'Nome: ' . ($c['testemunha2_nome'] ?: '______________________') . ' CPF: ' . ($c['testemunha2_cpf'] ?: '______________');
    $pdf->Ln(12);
    $pdf->Cell(85, 5, _pdfTexto('TESTEMUNHA 1'), 0, 0, 'C');
    $pdf->Cell(10, 5, '', 0, 0);
    $pdf->Cell(85, 5, _pdfTexto('TESTEMUNHA 2'), 0, 1, 'C');
    $pdf->Cell(85, 5, _pdfTexto($test1), 0, 0, 'C');
    $pdf->Cell(10, 5, '', 0, 0);
    $pdf->Cell(85, 5, _pdfTexto($test2), 0, 1, 'C');

    _pdfRodapeEndereco($pdf);

    $caminho = tempnam(sys_get_temp_dir(), 'contrato_venda_') . '.pdf';
    $pdf->Output('F', $caminho);
    return $caminho;
}

/**
 * Cláusulas do contrato de promissória — [título, corpo]. Transcrito do
 * modelo Contrato_FASTCAR_PROMISSÓRIA.docx (27/10/2026); foro corrigido pra
 * Barueri/SP. Os 2 "[●]" do modelo (horas pra devolução, cl. 18.1; dias
 * úteis pra sanar, cl. 24.2) vêm do Quadro-Resumo — sem valor, sai linha em
 * branco, nunca um número chutado.
 */
function clausulasContratoVenda(int $horasDevolucao = 0, int $diasSaneamento = 0): array {
    $horas = $horasDevolucao > 0 ? (string)$horasDevolucao : '____';
    $dias = $diasSaneamento > 0 ? (string)$diasSaneamento : '____';
    return [
        ['CLÁUSULA 1ª – OBJETO E NATUREZA DA CONTRATAÇÃO',
            "1.1. A FASTCAR disponibiliza ao LOCATÁRIO a posse direta do veículo identificado no Quadro-Resumo, para uso temporário e oneroso, com promessa condicionada de futura compra e venda, conforme as condições deste contrato.\n\n" .
            "1.2. A posse direta é transmitida ao LOCATÁRIO nos limites deste contrato, sem transferência automática da propriedade.\n\n" .
            "1.3. A aquisição definitiva dependerá do cumprimento integral das condições financeiras, documentais e administrativas previstas neste instrumento e da formalização da transferência perante o órgão competente.\n\n" .
            "1.4. As partes reconhecem que a denominação contratual não afasta a aplicação das normas cogentes eventualmente incidentes sobre a operação."],
        ['CLÁUSULA 2ª – COMPOSIÇÃO ECONÔMICA DAS PRESTAÇÕES',
            "2.1. Cada prestação mensal será discriminada em 90% (noventa por cento) identificados como locação/uso e 10% (dez por cento) identificados como parcela residual destinada à aquisição, nos valores do Quadro-Resumo. Após o integral cumprimento das obrigações financeiras, 100% das prestações efetivamente pagas serão aproveitados na quitação do preço do veículo.\n\n" .
            "2.2. Cumpridas as condições financeiras da aquisição, o componente de locação/uso também será aproveitado no preço, juntamente com a parcela residual. Em encerramento antecipado, o tratamento de todos os valores pagos será apurado de forma individualizada, conforme as cláusulas 21 e 22 e a legislação aplicável.\n\n" .
            "2.3. Na prestação de contas do encerramento antecipado serão considerados todos os pagamentos efetivamente realizados, sua destinação e os encargos eventualmente devidos, vedada a perda automática integral e a compensação genérica.\n\n" .
            "2.4. Do pagamento inicial efetivamente recebido será abatida a comissão de intermediação quando aplicável e exigível, conforme cláusula 2-A. O saldo líquido destinado à aquisição será indicado no Quadro-Resumo e aproveitado no preço; demais destinações deverão ser discriminadas."],
        ['CLÁUSULA 2-A – COMISSÃO DE INTERMEDIAÇÃO',
            "2-A.1. Quando houver intermediação efetiva entre o LOCATÁRIO e terceiro vendedor identificado no Quadro-Resumo, o LOCATÁRIO pagará à FASTCAR comissão de 15% sobre o valor FIPE do veículo, considerado modelo, versão, ano e data da consulta indicados no quadro. A comissão e o custo total serão previamente destacados. Se a FASTCAR atuar em venda própria sem intermediação efetiva, esta cobrança não será aplicada automaticamente.\n\n" .
            "2-A.2. A comissão será abatida da entrada efetivamente paga, respeitado o momento de sua exigibilidade. Se a entrada for inferior à comissão, o saldo e vencimento serão indicados no Quadro-Resumo, e a forma será informada ao comprador. O saldo líquido destinado à aquisição será creditado ao preço; é vedada cobrança em duplicidade.\n\n" .
            "2-A.3. A comissão será devida quando a intermediação alcançar resultado útil e o negócio for concluído, observada a lei. Após a conclusão, desistência posterior não afasta, por si só, a comissão; preservam-se a restituição exigida por lei, inclusive o direito de arrependimento do art. 49 do CDC quando aplicável, e a análise de falha da FASTCAR ou vício do negócio."],
        ['CLÁUSULA 3ª – CRITÉRIO ECONÔMICO DA LOCAÇÃO',
            "3.1. O valor de locação considera, conforme aplicável, valor de mercado/FIPE, marca, modelo, ano, quilometragem, depreciação esperada, prazo, risco operacional, disponibilidade patrimonial e preços de veículos equivalentes.\n\n" .
            "3.2. O LOCATÁRIO declara ter recebido informação prévia sobre a composição econômica da operação."],
        ['CLÁUSULA 4ª – PAGAMENTOS',
            "4.1. O LOCATÁRIO pagará os valores e nas datas constantes do Quadro-Resumo.\n\n" .
            "4.2. O pagamento somente será considerado quitado após efetiva confirmação do crédito.\n\n" .
            "4.3. O recebimento de prestação posterior não importa, por si só, novação ou renúncia a prestações e encargos anteriores.\n\n" .
            "4.4. Eventuais alterações financeiras deverão constar de instrumento escrito ou eletrônico que permita comprovação de autoria e integridade."],
        ['CLÁUSULA 5ª – PROMESSA CONDICIONADA DE AQUISIÇÃO',
            "5.1. Cumpridas integralmente as obrigações financeiras do LOCATÁRIO, serão aproveitados no preço do veículo o saldo líquido da entrada destinado à aquisição e 100% das prestações efetivamente pagas, sem duplicidade. Reconhecida a quitação e satisfeitas as condições documentais, a FASTCAR praticará os atos que lhe competirem para a transferência, observados os direitos do credor, a baixa do gravame e as exigências legais.\n\n" .
            "5.2. Antes da transferência definitiva, o LOCATÁRIO não poderá alienar, prometer vender, ceder, doar, oferecer em garantia, sublocar ou constituir ônus sobre o veículo sem autorização expressa da FASTCAR.\n\n" .
            "5.3. A mera posse ou o decurso do prazo não transferem automaticamente a propriedade."],
        ['CLÁUSULA 6ª – SITUAÇÃO DOMINIAL E DOCUMENTAL',
            "6.1. A titularidade registral, o financiamento, a identificação do credor, o gravame, as restrições conhecidas, a responsabilidade e prazo para quitação e baixa constam do Quadro-Resumo; a legitimidade da FASTCAR deverá ser comprovada por documentação mantida no dossiê. Os direitos da instituição financeira e de terceiros permanecem preservados.\n\n" .
            "6.2. O LOCATÁRIO declara ter recebido, antes da contratação, as informações sobre a situação dominial e documental indicadas no Quadro-Resumo.\n\n" .
            "6.3. A FASTCAR manterá no dossiê os documentos que legitimem a disponibilização e a futura transferência, identificando titular registral, credor, gravame, responsável pela quitação e prazo. A obrigação da FASTCAR perante o LOCATÁRIO não exonera automaticamente o devedor perante a instituição financeira."],
        ['CLÁUSULA 6-A – DA NEGOCIAÇÃO COM A INSTITUIÇÃO FINANCEIRA',
            "6-A.1. O LOCATÁRIO/PROMITENTE COMPRADOR compromete-se a não celebrar, sem a anuência prévia e expressa da FASTCAR, acordo, renegociação, refinanciamento ou outra operação com a instituição financeira que altere as condições do financiamento vinculado ao veículo ou interfira na quitação assumida pela FASTCAR. O LOCATÁRIO/PROMITENTE COMPRADOR comunicará à FASTCAR eventual cobrança, proposta de acordo ou notificação bancária que receber.\n\n" .
            "6-A.2. Esta cláusula não impede a solicitação de informações ou documentos à instituição financeira nem o exercício de direitos administrativos ou judiciais. Havendo descumprimento pela FASTCAR ou risco concreto de negativação, cobrança, vencimento antecipado ou busca e apreensão, o LOCATÁRIO/PROMITENTE COMPRADOR poderá adotar medidas necessárias à proteção de seus direitos, inclusive negociar ou pagar o débito; sempre que possível, comunicará previamente a FASTCAR e, em caso urgente, o fará assim que possível, encaminhando os comprovantes.\n\n" .
            "6-A.3. O descumprimento da obrigação prevista no item 6-A.1, fora das hipóteses do item 6-A.2, poderá gerar responsabilidade pelos prejuízos efetivamente comprovados, observadas a legislação e a apuração do caso concreto. A cláusula vincula apenas as partes deste instrumento e não altera, por si só, o financiamento nem os direitos da instituição financeira ou de terceiros."],
        ['CLÁUSULA 7ª – ENTREGA, VISTORIA E ESTADO DO VEÍCULO',
            "7.1. A entrega será confirmada por registro escrito com data, hora, local, quilometragem, combustível, pneus, lataria, pintura, vidros, interior, acessórios, chaves, documentos e avarias preexistentes. Se ocorrer após a assinatura, esse registro será confirmado pelas partes no ato da entrega.\n\n" .
            "7.2. Fotografias e vídeos da entrega ou devolução poderão ser conservados pelas partes como meios de prova, juntamente com o registro escrito.\n\n" .
            "7.3. O reconhecimento do estado aparente do veículo não importa renúncia a direitos legalmente indisponíveis."],
        ['CLÁUSULA 8ª – GUARDA, USO E MANUTENÇÃO',
            "8.1. A partir da entrega e posse efetiva, identificadas pela data e hora do registro de entrega, o LOCATÁRIO assume perante a FASTCAR a guarda, conservação e responsabilidade pelo uso do veículo, inclusive quando conduzido por pessoa a quem permitir acesso, devendo utilizá-lo de modo regular, diligente e compatível com sua destinação, e ainda:\n" .
            "— realizar as manutenções preventivas previstas e observar alertas do veículo;\n" .
            "— não adulterar chassi, placas, etiquetas, hodômetro ou outros sinais identificadores;\n" .
            "— não realizar modificações estruturais sem autorização;\n" .
            "— comunicar imediatamente defeito grave, acidente, apreensão, furto, roubo ou sinistro;\n" .
            "— não utilizar o veículo para finalidade ilícita ou incompatível com a contratação.\n\n" .
            "8.2. O LOCATÁRIO suportará durante a posse os custos ordinários e necessários de uso, manutenção preventiva e corretiva decorrente do uso ou desgaste sob sua responsabilidade, tais como combustível, lubrificantes, pneus, revisões e reparos; realizará as manutenções exigidas pelo fabricante e apresentará comprovantes quando solicitados. Vícios preexistentes ou ocultos e reparos de responsabilidade legal ou contratual da FASTCAR não são transferidos automaticamente ao LOCATÁRIO."],
        ['CLÁUSULA 9ª – MULTAS, CONDUTOR E ENCARGOS DE TRÂNSITO',
            "9.1. Infrações praticadas durante a posse serão de responsabilidade econômica do LOCATÁRIO quando decorrentes de seu uso ou de condutor por ele autorizado, na medida legalmente aplicável; a identificação do condutor e as responsabilidades perante órgãos públicos obedecerão à legislação.\n\n" .
            "9.2. O LOCATÁRIO fornecerá tempestivamente documentos e informações necessários à identificação do real condutor.\n\n" .
            "9.3. Valores suportados pela FASTCAR por omissão ou ato imputável ao LOCATÁRIO poderão ser reembolsados mediante demonstrativo."],
        ['CLÁUSULA 10ª – TRIBUTOS, LICENCIAMENTO E OUTROS ENCARGOS',
            "10.1. Entre as partes, a partir da entrega efetiva e durante a posse, o LOCATÁRIO arcará com IPVA, licenciamento e taxas correspondentes ao período, inclusive por rateio proporcional quando o tributo compreender período anterior e posterior à entrega; débitos anteriores, não atribuíveis ao LOCATÁRIO, permanecem a cargo da FASTCAR ou de quem os deva. Custos de proteção veicular eventualmente contratada observarão as condições indicadas no Quadro-Resumo.\n\n" .
            "10.2. Esta distribuição opera entre as partes e não altera, por si só, o sujeito passivo tributário ou a responsabilidade legal perante autoridades ou terceiros. Valores suportados por uma parte e atribuíveis à outra serão reembolsados mediante demonstrativo e comprovantes."],
        ['CLÁUSULA 11ª – PROTEÇÃO VEICULAR E SINISTROS',
            "11.1. A FASTCAR recomenda ao LOCATÁRIO considerar a contratação de proteção veicular durante a posse, com entidade habilitada ou regularmente cadastrada quando exigido, para mitigar riscos de colisão, perda, furto, roubo e outros eventos. A contratação é facultativa e não constitui condição automática para a celebração deste contrato.\n\n" .
            "11.2. Caso contratada, a existência da proteção, o responsável pelo pagamento e as coberturas serão indicados no Quadro-Resumo. Antes da adesão, o LOCATÁRIO deverá ter acesso às condições do programa, inclusive entidade responsável, custos, participação em eventos, abrangência, exclusões, limites, prazos de carência e procedimentos de acionamento. Nenhuma cobertura, indenização ou regularidade será presumida.\n\n" .
            "11.3. A partir da posse efetiva, o LOCATÁRIO deverá guardar o veículo e comunicar imediatamente à FASTCAR acidente, avaria, furto, roubo, desaparecimento ou perda total, registrar ocorrência quando cabível e cooperar com autoridades e, se contratada, com a entidade responsável pela proteção veicular, sem assumir perante terceiros responsabilidade além da lei.\n\n" .
            "11.4. O LOCATÁRIO assume contratualmente, entre as partes e durante sua posse, o risco econômico de dano, deterioração extraordinária, perda, furto, roubo e sinistro, inclusive os resultantes de caso fortuito ou força maior, respeitados os direitos legalmente indisponíveis e a apuração da causa. Não responderá por vícios preexistentes ou ocultos, falha da FASTCAR, ato atribuível a ela ou risco cuja transferência seja vedada por lei.\n\n" .
            "11.5. Em caso de dano reparável, o custo será apurado por vistoria e orçamento ou comprovantes idôneos, descontados os valores efetivamente recebidos da proteção veicular ou de terceiro. O LOCATÁRIO arcará com participação no evento e parcela não coberta quando contratualmente atribuíveis e legalmente exigíveis. O desgaste normal remunerado pela locação não será cobrado novamente.\n\n" .
            "11.6. Em caso de perda total, furto ou roubo, as partes apurarão o valor de referência do veículo na data do evento, preferencialmente pela Tabela FIPE do modelo e versão, ajustado por elementos comprovados, descontados valores efetivamente recebidos da proteção veicular, a recuperação do bem, salvados e valores recebidos de terceiros, evitando duplicidade. Direitos prioritários do credor fiduciário e a destinação dos valores recebidos serão respeitados e demonstrados.\n\n" .
            "11.7. A continuidade ou resolução do contrato e eventual saldo serão objeto de prestação de contas individualizada, considerando valores já pagos pelo LOCATÁRIO, obrigações pendentes, responsabilidade pelo evento e valores efetivamente recebidos; não há perda automática integral de pagamentos, cobrança cumulativa do valor integral do veículo e das parcelas pelo mesmo prejuízo, nem presunção de renúncia a direitos legais."],
        ['CLÁUSULA 12ª – RASTREAMENTO E GEOLOCALIZAÇÃO',
            "12.1. O LOCATÁRIO declara ciência ostensiva de que o veículo possui ou poderá possuir sistema de rastreamento e geolocalização.\n\n" .
            "12.2. O sistema poderá ser utilizado para proteção patrimonial, segurança, prevenção e apuração de fraude, localização do ativo, execução contratual, exercício regular de direitos e preservação de prova, observados os limites legais.\n\n" .
            "12.3. É vedado retirar, destruir, adulterar, bloquear ou deliberadamente impedir o funcionamento do rastreador.\n\n" .
            "12.4. Falha de sinal não será presumida automaticamente como fraude; a FASTCAR poderá solicitar apresentação do veículo ou verificação técnica quando houver motivo justificável.\n\n" .
            "12.5. A sabotagem comprovadamente intencional constitui inadimplemento contratual grave, sem prejuízo da apuração de danos."],
        ['CLÁUSULA 13ª – PROTEÇÃO DE DADOS PESSOAIS',
            "13.1. A FASTCAR tratará dados pessoais necessários à execução do contrato, proteção do crédito, prevenção a fraude, exercício regular de direitos, segurança e demais bases legais aplicáveis.\n\n" .
            "13.2. Poderão ser tratados dados cadastrais, documentos, contatos, informações financeiras, registros de pagamento, dados do veículo, multas, imagens de vistoria, atendimentos e geolocalização.\n\n" .
            "13.3. O compartilhamento poderá ocorrer, na medida necessária, com empresas de rastreamento, entidades responsáveis pela proteção veicular, despachantes, prestadores, cobrança, assessoria jurídica, órgãos públicos e autoridades competentes.\n\n" .
            "13.4. A FASTCAR adotará medidas razoáveis de segurança e limitará o acesso aos dados conforme finalidade e necessidade.\n\n" .
            "13.5. A utilização de imagem e voz do LOCATÁRIO para publicidade depende da escolha específica indicada no Quadro-Resumo e observará a cláusula 13-A. A recusa não impede nem altera a contratação."],
        ['CLÁUSULA 13-A – USO OPCIONAL DE IMAGEM E VOZ',
            "13-A.1. Se assinalar SIM e rubricar ou assinar especificamente o campo do Quadro-Resumo, o LOCATÁRIO autoriza gratuitamente a FASTCAR SOLUTIONS a captar, reproduzir e divulgar sua imagem e voz gravadas em vídeo, inclusive em depoimentos relativos à sua experiência com a FASTCAR, para divulgação institucional e publicitária da empresa em redes sociais, internet e demais mídias, por prazo indeterminado e sem remuneração pela utilização autorizada.\n\n" .
            "13-A.2. A autorização é opcional e independe da contratação. Se houver marcação NÃO, ausência de marcação, marcação ambígua ou falta de manifestação específica, não haverá autorização para uso publicitário. O tratamento necessário à execução contratual ou ao exercício regular de direitos observará suas próprias bases legais e finalidades, independentemente desta escolha.\n\n" .
            "13-A.3. A autorização poderá ser revogada a qualquer momento, gratuitamente, por notificação extrajudicial à ouvidoria pelo telefone (11) 97816-4525. Após o recebimento, a FASTCAR cessará novos usos baseados no consentimento e providenciará a retirada das publicações sob seu controle, observadas as possibilidades técnicas e as obrigações legais; não garante a remoção de cópias de terceiros. Os usos realizados anteriormente de forma legítima não são invalidados automaticamente.\n\n" .
            "13-A.4. A FASTCAR poderá editar o material para adequar formato e duração, sem alterar o sentido da fala ou expor o titular de modo ofensivo ou incompatível com a finalidade autorizada. A autorização não abrange imagem ou voz de outras pessoas, cuja autorização deverá ser obtida quando necessária."],
        ['CLÁUSULA 14ª – MORA E ENCARGOS',
            "14.1. O não pagamento no vencimento constitui mora nos termos da legislação e do contrato.\n\n" .
            "14.2. Incidirão somente os encargos expressamente indicados no Quadro-Resumo e juridicamente admissíveis.\n\n" .
            "14.3. É vedada a cumulação de penalidades de mesma natureza que resulte em duplicidade sancionatória."],
        ['CLÁUSULA 15ª – GRADAÇÃO DA INADIMPLÊNCIA',
            "— 1 a 30 dias — Atraso inicial: cobrança, registro e primeira notificação.\n" .
            "— 31 a 59 dias — Inadimplemento relevante: notificação reforçada e preparação documental.\n" .
            "— 60 dias ou mais — Inadimplemento qualificado: procedimento de resolução, exigência de devolução e medidas cabíveis, observado o procedimento aplicável.\n\n" .
            "15.1. Os prazos acima são contados em dias corridos, a partir do dia seguinte ao vencimento da obrigação não paga. Fraude, alienação irregular, ocultação deliberada, adulteração do veículo ou sabotagem intencional do rastreador poderão justificar providências anteriores, conforme a gravidade e a legislação aplicável."],
        ['CLÁUSULA 16ª – NOTIFICAÇÕES E ATUALIZAÇÃO CADASTRAL',
            "16.1. São canais contratuais os endereços, telefone/WhatsApp e e-mail indicados pelo LOCATÁRIO.\n\n" .
            "16.2. O LOCATÁRIO deverá comunicar alteração cadastral.\n\n" .
            "16.3. Comunicações ordinárias poderão ser realizadas por meios eletrônicos. Quando a lei exigir forma específica de constituição em mora ou notificação, será observado o procedimento correspondente.\n\n" .
            "16.4. A FASTCAR manterá histórico das comunicações relevantes no dossiê."],
        ['CLÁUSULA 17ª – INADIMPLEMENTO QUALIFICADO E RESOLUÇÃO',
            "17.1. Persistindo inadimplemento igual ou superior a 60 (sessenta) dias, e observado o procedimento aplicável, a FASTCAR poderá declarar a resolução do contrato, exigir valores vencidos, determinar a cessação da autorização de uso e exigir devolução voluntária do veículo.\n\n" .
            "17.2. A resolução será comunicada de forma documentada, com indicação do motivo, valores conhecidos e prazo de entrega.\n\n" .
            "17.3. A resolução não implica perda automática de todos os créditos econômicos do LOCATÁRIO, os quais serão submetidos à prestação de contas prevista neste instrumento."],
        ['CLÁUSULA 18ª – DEVOLUÇÃO DO VEÍCULO',
            "18.1. Resolvido o contrato ou aceita a desistência, o LOCATÁRIO disponibilizará voluntariamente o veículo no prazo indicado na notificação, não inferior a {$horas} horas, salvo urgência legalmente justificável.\n\n" .
            "18.2. A entrega será documentada por registro escrito, com vistoria, quilometragem, combustível, chaves, documentos e acessórios; fotografias poderão ser utilizadas como prova.\n\n" .
            "18.3. A entrega voluntária não importa quitação automática de valores ainda sujeitos à apuração."],
        ['CLÁUSULA 19ª – RECUPERAÇÃO DO ATIVO E LIMITES EXTRAJUDICIAIS',
            "19.1. A FASTCAR poderá contatar o LOCATÁRIO, localizar o veículo, negociar sua apresentação e utilizar prepostos ou empresa especializada para receber entrega voluntária, sempre dentro dos limites legais.\n\n" .
            "19.2. Este contrato não constitui autorização para ingresso forçado em residência, garagem fechada ou propriedade particular, rompimento de obstáculos, ameaça, violência, constrangimento ou qualquer conduta ilícita.\n\n" .
            "19.3. Havendo resistência, conflito possessório ou necessidade de coerção, a FASTCAR adotará a medida judicial adequada.\n\n" .
            "19.4. O eventual uso de chave reserva observará a licitude da posse e do acesso ao local, não servindo como autorização autônoma para ingresso irregular."],
        // 27/10/2026, "mantem clausula anterior 12.4" — autorização de retirada
        // após 60 dias trazida do contrato de venda antigo (era a 12.4 dele),
        // adaptada aos termos deste (LOCATÁRIO, parcelas do Quadro-Resumo,
        // notificação pela cláusula 16). Compatível com a 19: nunca ingresso
        // forçado/violência; havendo resistência, segue a via judicial (19.3).
        ['CLÁUSULA 19-A – AUTORIZAÇÃO DE RETIRADA DO VEÍCULO EM CASO DE INADIMPLÊNCIA SUPERIOR A 60 DIAS',
            "19-A.1. O atraso no pagamento de qualquer parcela prevista no Quadro-Resumo por prazo superior a 60 (sessenta) dias corridos, sem que tenha sido devidamente sanado, autoriza, desde já e de forma livre e expressa, a FASTCAR a promover a retirada extrajudicial do veículo, diretamente ou por preposto devidamente identificado, mediante prévia notificação por escrito ao LOCATÁRIO (cláusula 16ª) com antecedência mínima de 10 (dez) dias para regularização do débito.\n\n" .
            "19-A.2. Ultrapassado esse prazo adicional sem a regularização, a retirada poderá ser efetivada em local de fácil acesso, sem uso de violência, arrombamento, ameaça ou constrangimento ao LOCATÁRIO ou a terceiros, e independentemente de nova notificação caso a inadimplência persista.\n\n" .
            "19-A.3. Esta autorização não exclui o direito da FASTCAR de cobrar o saldo remanescente, encargos e demais perdas e danos legalmente cabíveis, nem a possibilidade de o LOCATÁRIO regularizar o débito e reaver o veículo enquanto este ainda estiver em poder da FASTCAR e não houver sido revendido a terceiro, observada a prestação de contas da cláusula 22ª."],
        ['CLÁUSULA 20ª – NÃO DEVOLUÇÃO APÓS A RESOLUÇÃO',
            "20.1. Encerrada validamente a autorização de uso e vencido o prazo de devolução, a permanência do veículo com o LOCATÁRIO poderá gerar remuneração/indenização pelo período de utilização, além de danos, quilometragem, multas e despesas comprovadas, conforme a natureza jurídica aplicável.\n\n" .
            "20.2. A permanência posterior não restabelece automaticamente a promessa de aquisição."],
        ['CLÁUSULA 21ª – DESISTÊNCIA DO LOCATÁRIO',
            "21.1. O LOCATÁRIO poderá solicitar encerramento antecipado, sujeito às consequências previstas neste contrato.\n\n" .
            "21.2. A desistência não produzirá, por si só, perda integral dos valores pagos nem restituição integral automática, ressalvadas as hipóteses legais de restituição integral, inclusive o exercício válido do direito de arrependimento quando aplicável.\n\n" .
            "21.3. Após a devolução, será elaborado Demonstrativo de Encerramento Contratual."],
        ['CLÁUSULA 22ª – PRESTAÇÃO DE CONTAS E ENCONTRO DE CONTAS',
            "22.1. No encerramento serão apurados separadamente todos os pagamentos do LOCATÁRIO, sua destinação e eventuais créditos e débitos das partes, com documentos e fundamento específico, respeitados os direitos aplicáveis e vedada a perda total automática.\n" .
            "Podem compor créditos FASTCAR: locações vencidas/uso efetivamente devido; uso posterior ao encerramento, quando cabível; multas e encargos imputáveis; avarias além do desgaste normal; quilometragem excedente; franquias, despesas e custos comprovados; cláusula penal válida, quando aplicável.\n" .
            "Podem compor créditos do LOCATÁRIO: todos os pagamentos efetivamente realizados, a apurar conforme contrato e lei; saldo líquido do pagamento inicial destinado à aquisição, a apurar; caução ou pagamentos excedentes; outros créditos reconhecidos.\n\n" .
            "22.2. O desgaste normal já remunerado pela locação não será cobrado novamente como dano.\n\n" .
            "22.3. Havendo, após a prestação de contas individualizada, valores ou saldo remanescente a restituir ao LOCATÁRIO/PROMITENTE COMPRADOR, a FASTCAR realizará a devolução em até 30 (trinta) dias úteis, contados a partir da data de assinatura do distrato, por meio acordado entre as partes. A apuração será realizada dentro desse prazo e não o suspenderá nem prorrogará automaticamente. A ausência de distrato assinado não afasta o direito à restituição ou outros prazos legalmente aplicáveis. Quando a legislação exigir restituição em prazo menor ou imediato, inclusive na hipótese do art. 49 do Código de Defesa do Consumidor, prevalecerá a regra legal.\n\n" .
            "22.4. Havendo saldo favorável à FASTCAR, o LOCATÁRIO será notificado para pagamento, sem prejuízo das medidas legalmente cabíveis."],
        ['CLÁUSULA 23ª – QUILOMETRAGEM E DEPRECIAÇÃO EXTRAORDINÁRIA',
            "23.1. Não haverá cobrança de quilometragem excedente sem limite, preço por quilômetro e período expressamente acordados e informados previamente ao LOCATÁRIO.\n\n" .
            "23.2. Avarias e depreciação extraordinária deverão ser demonstradas por vistoria, fotos, orçamento, nota fiscal, laudo ou outro meio idôneo, conforme o caso.\n\n" .
            "23.3. O desgaste ordinário decorrente de uso regular não será duplicadamente cobrado."],
        ['CLÁUSULA 24ª – CLÁUSULA PENAL',
            "24.1. Se houver descumprimento contratual relevante e imputável a uma das partes que resulte na resolução, a parte responsável ficará sujeita à multa compensatória de 20% sobre o preço do veículo indicado no Quadro-Resumo, observados a proporcionalidade, o cumprimento parcial e a legislação aplicável. A regra é recíproca.\n\n" .
            "24.2. Se o descumprimento for sanável, a parte prejudicada notificará a outra, concedendo prazo de {$dias} dias úteis para regularização, ressalvada urgência ou impossibilidade de saneamento. A multa compensatória não incide automaticamente por simples atraso; encargos moratórios obedecerão à lei e ao contrato, vedada duplicidade."],
        ['CLÁUSULA 25ª – COBRANÇA, PROTESTO E PROTEÇÃO AO CRÉDITO',
            "25.1. Obrigações vencidas, líquidas e exigíveis poderão ser cobradas pelas vias legalmente admitidas.\n\n" .
            "25.2. Protesto e inscrição em banco de dados de proteção ao crédito somente serão realizados quando presentes os requisitos legais, observadas as comunicações aplicáveis.\n\n" .
            "25.3. Honorários extrajudiciais observarão a cláusula 25-A e somente serão exigidos com fundamento legal e contratual, sem transferência abusiva ou cobrança duplicada."],
        ['CLÁUSULA 25-A – HONORÁRIOS ADVOCATÍCIOS',
            "25-A.1. Se descumprimento imputável a uma parte tornar necessária a atuação administrativa ou extrajudicial de advogado, a parte responsável poderá ressarcir à prejudicada honorários efetivamente contratados e comprovados, até 10% do valor atualizado da obrigação objeto da medida, desde que demonstradas necessidade, prestação efetiva e razoabilidade. O direito é recíproco.\n\n" .
            "25-A.2. Em demanda judicial, honorários de sucumbência serão fixados pelo juízo; eventual ressarcimento contratual observará a lei e a decisão judicial, sem cobrança automática em duplicidade pelo mesmo fundamento."],
        ['CLÁUSULA 25-B – PROMISSÓRIA VINCULADA',
            "25-B.1. Eventual nota promissória dependerá de ajuste expresso que identifique emitente, beneficiário, valor, vencimento, obrigação vinculada e condições de baixa, devolução e cobrança. Pagamentos serão vinculados ao contrato e ao título, vedada cobrança em duplicidade da mesma obrigação; nenhum título é criado automaticamente por esta cláusula."],
        ['CLÁUSULA 26ª – FORÇA PROBATÓRIA E ASSINATURA',
            "26.1. O presente instrumento poderá ser assinado fisicamente ou por meio eletrônico apto a demonstrar autoria e integridade.\n\n" .
            "26.2. Quando físico, recomenda-se assinatura de duas testemunhas.\n\n" .
            "26.3. Comprovantes, registros eletrônicos, comunicações, fotografias e demonstrativos relativos à operação poderão ser conservados como meios de prova, sem necessidade de anexos contratuais."],
        ['CLÁUSULA 27ª – TOLERÂNCIA, NÃO NOVAÇÃO E ALTERAÇÕES',
            "27.1. Tolerância eventual não implica renúncia, novação ou alteração permanente do contrato.\n\n" .
            "27.2. Alterações relevantes dependerão de instrumento escrito ou eletrônico."],
        ['CLÁUSULA 28ª – BOA-FÉ, COOPERAÇÃO E DEVER DE INFORMAÇÃO',
            "28.1. As partes observarão boa-fé, transparência e cooperação.\n\n" .
            "28.2. O LOCATÁRIO comunicará fatos relevantes que afetem posse, localização, segurança, documentação ou conservação do veículo.\n\n" .
            "28.3. A FASTCAR fornecerá informações contratuais relevantes e demonstrativos de encerramento quando cabíveis."],
        ['CLÁUSULA 29ª – INVALIDADE PARCIAL',
            "29.1. A eventual invalidade ou ineficácia de uma disposição não prejudicará automaticamente as demais cláusulas autônomas que possam ser preservadas."],
        ['CLÁUSULA 30ª – SOLUÇÃO DE CONTROVÉRSIAS E FORO',
            "30.1. As partes buscarão inicialmente solução negocial, sem impedir o acesso ao Poder Judiciário.\n\n" .
            "30.2. Respeitadas as regras cogentes de competência, fica eleito o foro da Comarca de Barueri/SP para controvérsias decorrentes deste contrato.\n\n" .
            "30.3. Não se estabelece arbitragem compulsória unilateral."],
    ];
}

/**
 * Contrato-mestre de LOCAÇÃO pura de veículo (27/10/2026) — transcrito do
 * modelo real 01_Contrato_Mestre_FASTCAR_Locacao_Pura.docx (Versão 1.0,
 * Setembro/2026). Mesmo encanamento visual dos contratos de compra/venda.
 * Endereço da sede e foro corrigidos pra Barueri/SP (o modelo trazia o
 * endereço antigo de Santana de Parnaíba/SP — mesma correção já aplicada
 * nos outros 2 contratos). $c vem de montarCamposContratoVenda() com
 * tipo_contrato='locacao' ('_loc' = Quadro-Resumo, ver includes/locacao.php).
 */
function gerarPdfContratoLocacao(array $c): string {
    $l = $c['_loc'] ?? [];
    $v = fn(string $k, string $vazio = '—') => (isset($l[$k]) && $l[$k] !== '') ? (string)$l[$k] : $vazio;
    $marca = fn(bool $x) => $x ? '(X)' : '( )';

    $pdf = _pdfNovo();
    _pdfCabecalho($pdf, 'CONTRATO-MESTRE DE LOCAÇÃO DE VEÍCULO — LOCAÇÃO PURA');

    $pdf->SetFillColor(247, 243, 231);
    $pdf->SetFont('Helvetica', 'B', 9);
    $pdf->MultiCell(0, 5.5, _pdfTexto(
        "NATUREZA DA OPERAÇÃO\n" .
        "Este instrumento regula exclusivamente a locação temporária e onerosa de veículo. Os pagamentos de " .
        "aluguel não constituem parcelas de compra, não geram crédito para aquisição e não obrigam a FASTCAR a " .
        "vender ou transferir o veículo ao LOCATÁRIO."
    ), 0, 'L', true);
    $pdf->Ln(2);

    $pdf->SetFont('Helvetica', '', 9);
    $pdf->MultiCell(0, 5, _pdfTexto(
        "Pelo presente instrumento particular, de um lado, FASTCAR SOLUTIONS, pessoa jurídica de direito " .
        "privado, inscrita no CNPJ/MF sob o nº 66.934.500/0001-09, com sede na Avenida Sagitário, nº 138, " .
        "Sala 1003, 10º andar, Torre City (Torre 2), Complexo Alpha Square Offices, Alphaville Conde II, " .
        "Barueri/SP, CEP 06473-073, doravante denominada LOCADORA ou FASTCAR; e, de outro lado, " .
        "{$c['comprador_nome']}, {$c['comprador_nacionalidade']}, {$c['comprador_estado_civil']}, " .
        "{$c['comprador_profissao']}, RG nº {$c['comprador_rg']}, CPF/CNPJ nº {$c['comprador_cpf']}, " .
        "CNH nº {$c['comprador_cnh']}, endereço {$c['comprador_endereco']}, telefone/WhatsApp " .
        "{$c['comprador_telefone']}, e-mail {$c['comprador_email']}, doravante denominado(a) LOCATÁRIO(A); " .
        "têm entre si justo e contratado o presente CONTRATO DE LOCAÇÃO DE VEÍCULO, regido pelas condições do " .
        "Quadro-Resumo, pelas cláusulas abaixo e pelos anexos integrantes."
    ));

    $pdf->Ln(3);
    $pdf->SetFont('Helvetica', 'B', 11);
    $pdf->Cell(0, 7, _pdfTexto('QUADRO-RESUMO DA LOCAÇÃO'), 0, 1, 'C');
    $pdf->Ln(1);

    $modalidade = $l['modalidade'] ?? '';
    $rastreador = $l['rastreador'] ?? '';
    $uso = $l['uso'] ?? '';
    $caucao = isset($l['caucao_valor']) ? _fmtMoeda((float)$l['caucao_valor']) : 'não há';
    if (isset($l['caucao_valor']) && !empty($l['caucao_modalidade'])) $caucao .= ' / modalidade: ' . $l['caucao_modalidade'];

    _pdfLinhaResumo($pdf, 'Contrato nº', 'LOC-' . $c['_venda_id']);
    _pdfLinhaResumo($pdf, 'Veículo / versão', trim($c['veiculo_marca'] . ' ' . $c['veiculo_modelo']));
    _pdfLinhaResumo($pdf, 'Ano fabricação/modelo', $c['veiculo_ano'] ?: '—');
    _pdfLinhaResumo($pdf, 'Placa / RENAVAM', ($c['veiculo_placa'] ?: '—') . ' / ' . ($c['veiculo_renavam'] ?: '—'));
    _pdfLinhaResumo($pdf, 'Chassi', $c['veiculo_chassi'] ?: '—');
    _pdfLinhaResumo($pdf, 'Quilometragem inicial', $v('km_inicial') !== '—' ? $v('km_inicial') . ' km' : '—');
    _pdfLinhaResumo($pdf, 'Valor FIPE de referência', _fmtMoeda($c['valor_fipe_referencia']));
    _pdfLinhaResumo($pdf, 'Modalidade', $marca($modalidade === 'determinado') . ' prazo determinado  ' . $marca($modalidade === 'indeterminado') . ' prazo indeterminado');
    _pdfLinhaResumo($pdf, 'Prazo inicial', $v('prazo_meses') !== '—' ? $v('prazo_meses') . ' meses' : '—');
    _pdfLinhaResumo($pdf, 'Data de início', !empty($l['data_inicio']) ? date('d/m/Y', strtotime($l['data_inicio'])) : '—');
    _pdfLinhaResumo($pdf, 'Aluguel mensal', isset($l['aluguel_mensal']) ? _fmtMoeda((float)$l['aluguel_mensal']) : '—');
    _pdfLinhaResumo($pdf, 'Vencimento', $v('vencimento_dia') !== '—' ? 'dia ' . $v('vencimento_dia') . ' de cada mês' : '—');
    _pdfLinhaResumo($pdf, 'Caução/garantia, se houver', $caucao);
    _pdfLinhaResumo($pdf, 'Franquia de quilometragem', $v('franquia_km') !== '—' ? $v('franquia_km') . ' km por ' . $v('franquia_periodo', 'mês') : '—');
    _pdfLinhaResumo($pdf, 'Km excedente', isset($l['km_excedente_valor']) ? _fmtMoeda((float)$l['km_excedente_valor']) . '/km' : '—');
    _pdfLinhaResumo($pdf, 'IPVA', 'Responsável: ' . $v('ipva_resp'));
    _pdfLinhaResumo($pdf, 'Licenciamento', 'Responsável: ' . $v('licenciamento_resp'));
    _pdfLinhaResumo($pdf, 'Seguro/proteção', $v('seguro_texto'));
    _pdfLinhaResumo($pdf, 'Franquia de sinistro', 'Responsável: ' . $v('franquia_sinistro_resp'));
    _pdfLinhaResumo($pdf, 'Manutenção preventiva', 'Responsável: ' . $v('manutencao_resp'));
    _pdfLinhaResumo($pdf, 'Pneus/itens de desgaste', 'Regra: ' . $v('pneus_regra'));
    _pdfLinhaResumo($pdf, 'Combustível na devolução', $v('combustivel_devolucao'));
    _pdfLinhaResumo($pdf, 'Rastreador', $marca($rastreador === 'instalado') . ' instalado  ' . $marca($rastreador === 'sera_instalado') . ' será instalado  ' . $marca($rastreador === 'nao_aplicavel') . ' não aplicável');
    _pdfLinhaResumo($pdf, 'Uso autorizado', $marca($uso === 'pessoal') . ' pessoal  ' . $marca($uso === 'profissional') . ' profissional  ' . $marca($uso === 'aplicativo') . ' aplicativo  ' . $marca($uso === 'outro') . ' outro' . ($uso === 'outro' && !empty($l['uso_outro']) ? ': ' . $l['uso_outro'] : ''));
    _pdfLinhaResumo($pdf, 'Aviso prévio para devolução', $v('aviso_previo_dias') !== '—' ? $v('aviso_previo_dias') . ' dias' : '—');
    _pdfLinhaResumo($pdf, 'Encargos de mora', 'multa ' . $v('mora_multa_pct') . '% | juros ' . $v('mora_juros') . ' | atualização ' . $v('mora_atualizacao'));
    _pdfLinhaResumo($pdf, 'Multa por saída antecipada, se aplicável', $v('multa_saida_antecipada'));
    _pdfLinhaResumo($pdf, 'Prazo para devolução após resolução', $v('prazo_devolucao_resolucao'));
    $emailCorp = (function_exists('getConfig') ? (string)getConfig('email_from') : '') ?: 'contato@fastcar.solutions';
    _pdfLinhaResumo($pdf, 'Canal oficial FASTCAR', 'e-mail ' . $emailCorp);

    $pdf->Ln(2);
    $pdf->SetFont('Helvetica', 'I', 8);
    $pdf->MultiCell(0, 4.5, _pdfTexto(
        'O Quadro-Resumo integra o contrato e deverá ser preenchido antes da assinatura. Havendo divergência entre ' .
        'dado variável do Quadro-Resumo e referência genérica no corpo contratual, prevalecerá o dado específico ' .
        'regularmente preenchido, sem prejuízo de normas cogentes.'
    ));

    foreach (clausulasContratoLocacao() as [$titulo, $corpo]) {
        _pdfTituloClausula($pdf, $titulo);
        _pdfCorpo($pdf, $corpo);
    }

    $pdf->Ln(6);
    $pdf->SetFont('Helvetica', '', 9);
    $pdf->Cell(0, 6, _pdfTexto("Barueri/SP, {$c['data_extenso']}."), 0, 1, 'L');
    $pdf->Ln(14);

    $pdf->SetFont('Helvetica', '', 8.5);
    $pdf->Cell(85, 5, _pdfTexto('_______________________________'), 0, 0, 'C');
    $pdf->Cell(10, 5, '', 0, 0);
    $pdf->Cell(85, 5, _pdfTexto('_______________________________'), 0, 1, 'C');
    $pdf->Cell(85, 5, _pdfTexto('FASTCAR SOLUTIONS LTDA'), 0, 0, 'C');
    $pdf->Cell(10, 5, '', 0, 0);
    $pdf->Cell(85, 5, _pdfTexto('LOCATÁRIO(A)'), 0, 1, 'C');
    $pdf->Cell(85, 5, _pdfTexto('CNPJ 66.934.500/0001-09'), 0, 0, 'C');
    $pdf->Cell(10, 5, '', 0, 0);
    $pdf->Cell(85, 5, _pdfTexto("Nome/CPF: {$c['comprador_nome']} / {$c['comprador_cpf']}"), 0, 1, 'C');

    $test1 = 'Nome: ' . ($c['testemunha1_nome'] ?: '______________________') . ' CPF: ' . ($c['testemunha1_cpf'] ?: '______________');
    $test2 = 'Nome: ' . ($c['testemunha2_nome'] ?: '______________________') . ' CPF: ' . ($c['testemunha2_cpf'] ?: '______________');
    $pdf->Ln(12);
    $pdf->Cell(85, 5, _pdfTexto('TESTEMUNHA 1'), 0, 0, 'C');
    $pdf->Cell(10, 5, '', 0, 0);
    $pdf->Cell(85, 5, _pdfTexto('TESTEMUNHA 2'), 0, 1, 'C');
    $pdf->Cell(85, 5, _pdfTexto($test1), 0, 0, 'C');
    $pdf->Cell(10, 5, '', 0, 0);
    $pdf->Cell(85, 5, _pdfTexto($test2), 0, 1, 'C');

    _pdfRodapeEndereco($pdf);

    $caminho = tempnam(sys_get_temp_dir(), 'contrato_locacao_') . '.pdf';
    $pdf->Output('F', $caminho);
    return $caminho;
}

/** Cláusulas do contrato de locação pura — [título, corpo]. */
function clausulasContratoLocacao(): array {
    return [
        ['CLÁUSULA 1ª – OBJETO E NATUREZA DA LOCAÇÃO',
            "1.1. A FASTCAR entrega ao LOCATÁRIO, para posse direta temporária e uso oneroso, o veículo identificado no Quadro-Resumo e nos anexos.\n\n" .
            "1.2. A propriedade e a posse indireta permanecem com o titular registral e/ou com quem legitimamente disponibilize o veículo à FASTCAR, conforme a situação documental indicada no Anexo VIII.\n\n" .
            "1.3. Este contrato não contém promessa de compra e venda, opção automática de compra, financiamento, consórcio ou formação de crédito para aquisição. O pagamento dos aluguéis não confere ao LOCATÁRIO direito de propriedade sobre o veículo.\n\n" .
            "1.4. Eventual negociação futura para venda do veículo, se admitida pela FASTCAR, dependerá de instrumento autônomo, preço e condições próprios, não se presumindo pela duração da locação ou pelos valores pagos.\n\n" .
            "1.5. Aplicam-se ao contrato as normas cogentes incidentes à relação concreta, inclusive as de proteção do consumidor quando juridicamente aplicáveis."],
        ['CLÁUSULA 2ª – PRAZO, INÍCIO E PRORROGAÇÃO',
            "2.1. A locação inicia-se na data indicada no Quadro-Resumo e poderá ser contratada por prazo determinado ou indeterminado.\n\n" .
            "2.2. No prazo determinado, a permanência do veículo com o LOCATÁRIO após o termo final dependerá de prorrogação expressa ou das consequências juridicamente aplicáveis, sem converter pagamentos passados em preço de compra.\n\n" .
            "2.3. No prazo indeterminado, qualquer parte poderá encerrar a relação mediante aviso prévio no prazo indicado no Quadro-Resumo, ressalvadas hipóteses de resolução por inadimplemento ou infração grave.\n\n" .
            "2.4. A devolução física do veículo e a quitação financeira são atos distintos: a entrega encerra a posse direta, enquanto valores pendentes serão apurados em demonstrativo próprio."],
        ['CLÁUSULA 3ª – ALUGUEL, VENCIMENTO E PAGAMENTO',
            "3.1. O LOCATÁRIO pagará o aluguel mensal e demais valores contratualmente previstos nas datas indicadas no Quadro-Resumo.\n\n" .
            "3.2. O aluguel remunera a disponibilização e o uso do veículo durante o período correspondente. Não se transforma em crédito de compra e não é restituível apenas porque o contrato terminou ou foi resolvido, sem prejuízo de valores pagos indevidamente.\n\n" .
            "3.3. O pagamento será considerado quitado após confirmação do crédito no meio indicado pela FASTCAR.\n\n" .
            "3.4. O recebimento de pagamento posterior, isoladamente, não importa novação, renúncia a saldo anterior ou alteração permanente das condições.\n\n" .
            "3.5. Alterações de valor, vencimento, prazo, franquia ou demais condições essenciais dependerão de instrumento escrito ou eletrônico apto a comprovar autoria e integridade."],
        ['CLÁUSULA 4ª – CAUÇÃO E OUTRAS GARANTIAS',
            "4.1. Quando prevista no Quadro-Resumo, a caução ou garantia será tratada separadamente do aluguel e não constitui pagamento antecipado para aquisição do veículo.\n\n" .
            "4.2. Encerrada a locação e concluída a vistoria final, a FASTCAR elaborará demonstrativo discriminado, podendo compensar a garantia com obrigações líquidas, comprovadas e contratualmente imputáveis ao LOCATÁRIO.\n\n" .
            "4.3. Havendo saldo remanescente em favor do LOCATÁRIO, este será restituído no prazo indicado no Anexo X após a conclusão da apuração e disponibilização do demonstrativo.\n\n" .
            "4.4. A retenção não poderá ser genérica: toda compensação deverá identificar a rubrica, o valor e, quando cabível, a prova correspondente."],
        ['CLÁUSULA 5ª – ENTREGA, VISTORIA E ESTADO DO VEÍCULO',
            "5.1. A entrega ocorrerá mediante Termo de Entrega e Vistoria, com registro de quilometragem, combustível, pneus, lataria, pintura, vidros, interior, painel, acessórios, chaves, documentos e avarias preexistentes.\n\n" .
            "5.2. Fotografias e vídeos datados e identificados poderão integrar o dossiê eletrônico e servir como prova comparativa na devolução.\n\n" .
            "5.3. O reconhecimento do estado aparente não implica renúncia a direitos legalmente indisponíveis ou a vícios ocultos cuja responsabilidade seja definida pela legislação aplicável.\n\n" .
            "5.4. A FASTCAR poderá recusar a entrega enquanto o dossiê contratual essencial não estiver concluído."],
        ['CLÁUSULA 6ª – USO AUTORIZADO, GUARDA E CONDUTORES',
            "6.1. O veículo deverá ser utilizado de forma diligente, lícita e compatível com a destinação indicada no Quadro-Resumo. O LOCATÁRIO deverá:\n" .
            "a) manter CNH válida e respeitar restrições administrativas e securitárias;\n" .
            "b) não ceder a posse habitual, sublocar, emprestar comercialmente ou permitir uso por condutor não autorizado quando houver restrição contratual ou securitária;\n" .
            "c) não utilizar o veículo em competição, racha, teste de velocidade, transporte ilícito ou atividade incompatível com a contratação;\n" .
            "d) não alienar, prometer vender, oferecer em garantia ou constituir ônus sobre o veículo;\n" .
            "e) comunicar mudança relevante de endereço, telefone ou local habitual de guarda do veículo.\n\n" .
            "6.2. O uso por terceiros não exclui a responsabilidade contratual do LOCATÁRIO, sem prejuízo da individualização do condutor para fins legais e securitários."],
        ['CLÁUSULA 7ª – QUILOMETRAGEM E HODÔMETRO',
            "7.1. A franquia e o valor do quilômetro excedente constam do Quadro-Resumo.\n\n" .
            "7.2. O critério de acumulação ou não acumulação de franquia deverá constar expressamente do Anexo I; na ausência de indicação, não se presumirá acumulação entre períodos.\n\n" .
            "7.3. A quilometragem será registrada na entrega, nas inspeções autorizadas e na devolução.\n\n" .
            "7.4. É vedada qualquer adulteração, desconexão deliberada ou intervenção no hodômetro. Havendo defeito, o LOCATÁRIO deverá comunicar imediatamente a FASTCAR para registro e critério técnico de apuração."],
        ['CLÁUSULA 8ª – MANUTENÇÃO, CONSERVAÇÃO E ALERTAS DO VEÍCULO',
            "8.1. A distribuição das responsabilidades de manutenção constará do Quadro-Resumo e do Anexo VII.\n\n" .
            "8.2. O LOCATÁRIO deverá observar o plano de revisões, alertas do painel, níveis, temperatura, pressão de pneus e demais sinais que exijam interrupção do uso ou assistência.\n\n" .
            "8.3. O LOCATÁRIO não poderá autorizar reparo estrutural, modificação, instalação de equipamento, alteração estética relevante ou intervenção elétrica/mecânica significativa sem autorização da FASTCAR, salvo medida emergencial indispensável à segurança, devidamente documentada.\n\n" .
            "8.4. Danos decorrentes de mau uso, negligência, continuidade de rodagem após alerta grave ou modificação não autorizada poderão ser imputados ao LOCATÁRIO mediante prova e nexo com a conduta.\n\n" .
            "8.5. Desgaste natural compatível com tempo, quilometragem e uso regular não será cobrado novamente como avaria extraordinária."],
        ['CLÁUSULA 9ª – MULTAS, PEDÁGIOS E IDENTIFICAÇÃO DE CONDUTOR',
            "9.1. Infrações, pedágios, estacionamentos e demais encargos decorrentes da utilização durante a posse do LOCATÁRIO serão de sua responsabilidade na extensão legal e contratual aplicável.\n\n" .
            "9.2. O LOCATÁRIO deverá fornecer, dentro do prazo informado, documentos e assinatura necessários à identificação do real condutor.\n\n" .
            "9.3. Valores comprovadamente suportados pela FASTCAR por ato ou omissão imputável ao LOCATÁRIO poderão ser reembolsados mediante demonstrativo.\n\n" .
            "9.4. Taxa administrativa somente será cobrada se expressamente prevista, informada e juridicamente admissível."],
        ['CLÁUSULA 10ª – TRIBUTOS, LICENCIAMENTO E DESPESAS DO VEÍCULO',
            "10.1. A responsabilidade econômica por IPVA, licenciamento e outras despesas periódicas constará do Quadro-Resumo.\n\n" .
            "10.2. Nenhum encargo não previsto ou sem base legal será transferido ao LOCATÁRIO.\n\n" .
            "10.3. Despesas extraordinárias decorrentes de ato imputável ao LOCATÁRIO serão individualizadas e comprovadas sempre que necessário."],
        ['CLÁUSULA 11ª – SEGURO, PROTEÇÃO E SINISTRO',
            "11.1. As condições de seguro ou proteção, coberturas, exclusões, condutores, franquia, assistência, perda total, furto e roubo serão descritas no Anexo XI.\n\n" .
            "11.2. Em acidente, furto, roubo, incêndio, alagamento ou outro sinistro relevante, o LOCATÁRIO deverá: interromper o uso quando necessário à segurança; comunicar imediatamente a FASTCAR; seguir orientações da seguradora/proteção; preservar documentos, chaves e provas; e registrar ocorrência quando juridicamente ou operacionalmente pertinente.\n\n" .
            "11.3. O LOCATÁRIO não deverá assumir responsabilidade perante terceiro, autorizar reparo relevante ou celebrar acordo sobre o veículo sem ciência da FASTCAR, salvo providência emergencial indispensável.\n\n" .
            "11.4. Franquias, prejuízos não cobertos e demais valores somente serão imputados ao LOCATÁRIO conforme causa, responsabilidade, condições previamente informadas e legislação aplicável.\n\n" .
            "11.5. Em perda total, furto ou roubo, a continuidade ou encerramento da locação será formalizada após análise de cobertura, responsabilidade e disponibilidade do ativo."],
        ['CLÁUSULA 12ª – RASTREAMENTO E GEOLOCALIZAÇÃO',
            "12.1. O LOCATÁRIO declara ciência de que o veículo possui ou poderá possuir rastreamento/geolocalização, quando indicado no Quadro-Resumo ou Anexo IV.\n\n" .
            "12.2. A finalidade limita-se à proteção patrimonial, segurança, localização do ativo, prevenção e apuração de fraude, execução contratual, exercício regular de direitos e preservação de prova, observados os limites legais.\n\n" .
            "12.3. É vedado retirar, destruir, adulterar, bloquear ou impedir deliberadamente o funcionamento do equipamento.\n\n" .
            "12.4. Falha de sinal ou indisponibilidade técnica não será automaticamente presumida como fraude.\n\n" .
            "12.5. O rastreamento não autoriza ingresso forçado em residência, garagem ou propriedade privada, nem retirada mediante violência, ameaça ou constrangimento."],
        ['CLÁUSULA 13ª – PROTEÇÃO DE DADOS PESSOAIS',
            "13.1. A FASTCAR tratará dados necessários à contratação, identificação, cobrança, proteção do crédito, prevenção a fraude, segurança, gestão de multas e sinistros, rastreamento e exercício regular de direitos, conforme bases legais aplicáveis.\n\n" .
            "13.2. Poderão ser tratados dados cadastrais, financeiros, contratuais, de atendimento, do veículo, imagens técnicas, localização, multas e sinistros.\n\n" .
            "13.3. O compartilhamento poderá ocorrer, quando necessário e juridicamente adequado, com rastreamento, seguradoras/proteções, oficinas, despachantes, cobrança, recuperação lícita, assessoria jurídica, órgãos públicos e autoridades.\n\n" .
            "13.4. Autorização para publicidade, imagem, voz ou depoimento não integra automaticamente este contrato e dependerá de instrumento apartado, facultativo."],
        ['CLÁUSULA 14ª – MORA E ENCARGOS',
            "14.1. O não pagamento no vencimento constitui mora, observadas as regras legais aplicáveis.\n\n" .
            "14.2. Incidirão apenas os encargos expressamente indicados no Quadro-Resumo e juridicamente admissíveis.\n\n" .
            "14.3. Não haverá cumulação de penalidades de mesma natureza que produza duplicidade sancionatória.\n\n" .
            "14.4. A FASTCAR poderá realizar cobrança por canais cadastrados, preservando linguagem respeitosa e vedada exposição pública ou ameaça ilícita."],
        ['CLÁUSULA 15ª – INADIMPLEMENTO E RÉGUA DE TRATAMENTO',
            "15.1. Para fins operacionais, a FASTCAR poderá utilizar régua progressiva de cobrança e documentação, sem que a passagem por todas as etapas seja requisito automático quando houver infração grave, risco concreto ao ativo ou necessidade de medida urgente.\n\n" .
            "15.2. A FASTCAR poderá encaminhar avisos, notificações e oportunidades de regularização de forma progressiva e proporcional, conforme a situação concreta, sem que a tolerância em determinada etapa implique renúncia ou novação.\n\n" .
            "15.3. A resolução por inadimplemento dependerá da verificação dos requisitos contratuais e legais aplicáveis, inclusive eventual forma específica de constituição em mora, quando exigida.\n\n" .
            "15.4. Fraude documental, alienação irregular, ocultação deliberada, adulteração de identificadores, sabotagem comprovada do rastreador, uso ilícito grave ou risco concreto de desaparecimento poderão justificar escalonamento imediato ao jurídico."],
        ['CLÁUSULA 16ª – NOTIFICAÇÕES E ATUALIZAÇÃO CADASTRAL',
            "16.1. São canais contratuais o endereço, WhatsApp, telefone e e-mail informados pelo LOCATÁRIO.\n\n" .
            "16.2. O LOCATÁRIO deverá comunicar alteração cadastral em prazo razoável.\n\n" .
            "16.3. Comunicações ordinárias poderão ocorrer por meios eletrônicos que permitam registro. Quando a lei exigir forma específica de notificação ou constituição em mora, será observado o procedimento correspondente.\n\n" .
            "16.4. A FASTCAR manterá, sempre que possível, histórico das comunicações relevantes no dossiê da operação."],
        ['CLÁUSULA 17ª – RESOLUÇÃO POR INADIMPLEMENTO OU INFRAÇÃO GRAVE',
            "17.1. A FASTCAR poderá resolver o contrato nas hipóteses previstas neste instrumento e na lei, após observância dos requisitos aplicáveis ao caso concreto.\n\n" .
            "17.2. Poderão justificar resolução, conforme gravidade e circunstâncias: mora relevante; uso ilícito; cessão ou sublocação não autorizada; adulteração do veículo; fraude; sabotagem intencional de rastreador; não apresentação injustificada do veículo; perda de condição essencial de habilitação; dano grave por uso proibido; alienação ou tentativa de disposição do ativo.\n\n" .
            "17.3. A resolução cessará a autorização contratual de uso e permitirá convocação do LOCATÁRIO para devolução voluntária no prazo informado.\n\n" .
            "17.4. A resolução não autoriza, por si só, ingresso forçado, confronto, ameaça, retirada clandestina ou qualquer medida coercitiva fora das hipóteses legais e procedimentos adequados."],
        ['CLÁUSULA 18ª – RESCISÃO ANTECIPADA PELO LOCATÁRIO',
            "18.1. O LOCATÁRIO poderá solicitar encerramento antecipado, devendo observar aviso prévio, local de entrega, vistoria e eventual multa expressamente indicada no Quadro-Resumo.\n\n" .
            "18.2. Em contratos com prazo determinado, eventual multa será calculada segundo o critério previamente informado, com observância da proporcionalidade e das normas aplicáveis.\n\n" .
            "18.3. A FASTCAR não exigirá automaticamente todas as mensalidades vincendas como condição de devolução; eventual obrigação decorrente da saída antecipada observará a cláusula específica e a legislação aplicável.\n\n" .
            "18.4. A devolução deverá ser formalizada por termo e seguida de apuração financeira."],
        ['CLÁUSULA 19ª – DEVOLUÇÃO VOLUNTÁRIA E VISTORIA FINAL',
            "19.1. Encerrado ou resolvido o contrato, o veículo será devolvido no local e prazo informados pela FASTCAR ou ajustados por escrito.\n\n" .
            "19.2. A devolução seguirá as etapas: agendamento; entrega física; registro de quilometragem e combustível; vistoria e fotos/vídeos; conferência de chaves/documentos/acessórios; consulta de multas e sinistros; e posterior demonstrativo financeiro.\n\n" .
            "19.3. A equipe que recebe o veículo não é obrigada a definir imediatamente o saldo final. A apuração ocorrerá após conferência documental e, se necessário, obtenção de orçamento ou nota fiscal.\n\n" .
            "19.4. A assinatura do termo de entrega não representa quitação automática para nenhuma das partes."],
        ['CLÁUSULA 20ª – DESGASTE NATURAL E AVARIAS EXTRAORDINÁRIAS',
            "20.1. Considera-se desgaste natural a deterioração razoavelmente compatível com tempo, quilometragem e uso regular do veículo.\n\n" .
            "20.2. Avarias extraordinárias são danos além do desgaste natural, tais como colisões, amassados relevantes, riscos profundos, quebra de vidros ou acessórios, rasgos, ausência de peças, danos por negligência ou alterações não autorizadas, desde que comprovados.\n\n" .
            "20.3. A apuração de avarias deverá comparar a vistoria inicial com a final e, quando houver cobrança, apresentar prova e critério de valor.\n\n" .
            "20.4. Não será cobrado novamente como avaria aquilo que corresponda apenas a desgaste normal já inerente à remuneração da locação."],
        ['CLÁUSULA 21ª – APURAÇÃO E DEMONSTRATIVO DE ENCERRAMENTO',
            "21.1. Após a devolução, a FASTCAR elaborará demonstrativo com créditos e débitos aplicáveis à locação.\n\n" .
            "21.2. Poderão integrar a apuração: caução/garantia; aluguéis vencidos; uso posterior à resolução, quando cabível; quilometragem excedente; multas e encargos de trânsito; combustível; franquia/sinistro; avarias extraordinárias comprovadas; despesas legítimas de devolução/recuperação; e outros valores expressamente previstos e comprovados.\n\n" .
            "21.3. Cada rubrica relevante deverá ser individualizada, evitando duplicidade de cobrança pela mesma causa.\n\n" .
            "21.4. Havendo saldo a restituir ao LOCATÁRIO, será observado o prazo do Anexo X. Havendo saldo devido à FASTCAR, serão informadas as condições para pagamento ou composição."],
        ['CLÁUSULA 22ª – RECUPERAÇÃO DO VEÍCULO E LIMITES DE ATUAÇÃO',
            "22.1. A FASTCAR priorizará devolução voluntária e documentação do procedimento.\n\n" .
            "22.2. Empresa ou preposto de recuperação somente poderá atuar dentro de autorização formal, sem violência, ameaça, constrangimento, rompimento de obstáculos ou ingresso não autorizado em propriedade privada.\n\n" .
            "22.3. Havendo recusa, conflito, terceiro contestando a posse ou necessidade de coerção, a diligência operacional deverá ser interrompida e o caso encaminhado ao jurídico.\n\n" .
            "22.4. A natureza de eventual medida judicial será definida conforme a estrutura jurídica real da operação, sem classificação automática incompatível com os documentos do veículo."],
        ['CLÁUSULA 23ª – INSPEÇÃO E APRESENTAÇÃO DO VEÍCULO',
            "23.1. A FASTCAR poderá solicitar apresentação do veículo para manutenção, vistoria, regularização documental, campanha de segurança, sinistro ou verificação técnica, com aviso e justificativa compatíveis com a situação.\n\n" .
            "23.2. A inspeção deverá preservar a privacidade do LOCATÁRIO e limitar-se às finalidades contratuais legítimas.\n\n" .
            "23.3. A recusa injustificada e reiterada a apresentação indispensável poderá ser tratada como infração contratual, conforme gravidade."],
        ['CLÁUSULA 24ª – COMUNICAÇÃO DE ACIDENTE, APREENSÃO OU RESTRIÇÃO',
            "24.1. O LOCATÁRIO comunicará imediatamente acidente grave, apreensão, bloqueio, retenção policial, restrição judicial, furto, roubo ou fato que comprometa posse, segurança ou disponibilidade do veículo.\n\n" .
            "24.2. O LOCATÁRIO cooperará com entrega de documentos e esclarecimentos necessários à regularização, sem assumir obrigações não previstas em lei ou contrato."],
        ['CLÁUSULA 25ª – CESSÃO CONTRATUAL E ALTERAÇÕES',
            "25.1. O LOCATÁRIO não poderá ceder sua posição contratual sem autorização da FASTCAR.\n\n" .
            "25.2. A FASTCAR poderá realizar reorganizações internas ou substituição de prestadores sem reduzir direitos essenciais do LOCATÁRIO.\n\n" .
            "25.3. Alterações relevantes deste contrato dependerão de instrumento escrito ou eletrônico."],
        ['CLÁUSULA 26ª – TOLERÂNCIA E NÃO NOVAÇÃO',
            "26.1. Tolerância eventual de qualquer das partes não implica renúncia, novação ou alteração permanente do contrato.\n\n" .
            "26.2. A ausência de exercício imediato de direito não impede seu exercício posterior dentro dos limites legais."],
        ['CLÁUSULA 27ª – BOA-FÉ, COOPERAÇÃO E DEVER DE INFORMAÇÃO',
            "27.1. As partes observarão boa-fé, transparência, cooperação e preservação de provas relevantes.\n\n" .
            "27.2. O LOCATÁRIO comunicará fatos relevantes que afetem posse, localização, segurança, documentação ou conservação do veículo.\n\n" .
            "27.3. A FASTCAR disponibilizará informações contratuais relevantes e demonstrativo de encerramento quando cabível."],
        ['CLÁUSULA 28ª – INVALIDADE PARCIAL',
            "28.1. A eventual invalidade ou ineficácia de uma disposição não prejudicará automaticamente as demais cláusulas autônomas que possam ser preservadas."],
        ['CLÁUSULA 29ª – SOLUÇÃO DE CONTROVÉRSIAS E FORO',
            "29.1. As partes buscarão inicialmente solução negocial, sem impedir acesso ao Poder Judiciário.\n\n" .
            "29.2. Respeitadas as regras cogentes de competência e, quando aplicável, as normas de proteção do consumidor, fica indicado o foro da Comarca de Barueri/SP para controvérsias decorrentes deste contrato.\n\n" .
            "29.3. Não se estabelece arbitragem compulsória unilateral."],
        ['CLÁUSULA 30ª – ANEXOS INTEGRANTES',
            "30.1. Integram este contrato: Anexo I – Quadro-Resumo Particular; Anexo II – Entrega e Vistoria Inicial; Anexo III – Registro Fotográfico/Audiovisual; Anexo IV – Rastreamento e Geolocalização; Anexo V – Tratamento de Dados; Anexo VI – Multas e Condutores; Anexo VII – Manutenção e Conservação; Anexo VIII – Situação Dominial e Documental; Anexo IX – Procedimento de Devolução; Anexo X – Vistoria Final e Demonstrativo de Encerramento; Anexo XI – Seguro e Sinistro.\n\n" .
            "30.2. Os anexos deverão ser preenchidos conforme a operação concreta; campos econômicos, securitários, responsabilidades ou fatos não deverão ser completados retroativamente de modo incompatível com o que foi informado e assinado."],
    ];
}

/**
 * Termo de devolução amigável e autorização de retirada do veículo
 * (27/10/2026, "esse termo vai ter botão para imprimir, o cliente assina,
 * agente sobe novamente em documentos pois não tem data de preenchimento")
 * — transcrito do modelo Termo_FASTCAR_Retirada_Amigavel.docx. Sai já com
 * os dados do locatário/comprador, do contrato e do veículo; local de
 * retirada, local/data e assinaturas ficam em BRANCO de propósito — o termo
 * é impresso, preenchido e assinado à mão no momento da devolução, e a
 * cópia assinada volta pro sistema como documento da negociação
 * (venda_documentos, tipo 'termo_retirada_amigavel'). $c vem de
 * montarCamposContratoVenda(). Nunca vai pra ZapSign.
 */
function gerarPdfTermoRetiradaAmigavel(array $c): string {
    $linha = '________________________________________';
    $ou = fn($v) => trim((string)$v) !== '' ? (string)$v : $linha;
    $prefixo = ($c['_tipo_contrato'] ?? 'venda') === 'locacao' ? 'LOC-' : 'VENDA-';
    $contrato = $prefixo . $c['_venda_id'];

    $pdf = _pdfNovo();
    _pdfCabecalho($pdf, 'TERMO DE DEVOLUÇÃO AMIGÁVEL E AUTORIZAÇÃO DE RETIRADA DE VEÍCULO');

    $pdf->SetFont('Helvetica', 'B', 10);
    $pdf->Cell(0, 6, _pdfTexto("FASTCAR SOLUTIONS  |  CONTRATO Nº {$contrato}"), 0, 1, 'C');
    $pdf->Ln(2);
    _pdfCorpo($pdf,
        'Pelo presente instrumento, o(a) LOCATÁRIO(A)/PROMITENTE COMPRADOR(A), identificado(a) abaixo, manifesta sua ' .
        'vontade de devolver amigavelmente o veículo relacionado ao contrato indicado e autoriza sua retirada pela ' .
        'FASTCAR SOLUTIONS LTDA., CNPJ nº 66.934.500/0001-09, ou por representante devidamente identificado.'
    );
    $pdf->Ln(2);
    _pdfLinhaResumo($pdf, 'Nome', $ou($c['comprador_nome']));
    _pdfLinhaResumo($pdf, 'CPF', $ou($c['comprador_cpf']));
    _pdfLinhaResumo($pdf, 'Telefone', $ou($c['comprador_telefone']));
    _pdfLinhaResumo($pdf, 'Contrato nº', $contrato);

    $pdf->Ln(3);
    $pdf->SetFont('Helvetica', 'B', 10);
    $pdf->Cell(0, 6, _pdfTexto('IDENTIFICAÇÃO DO VEÍCULO'), 0, 1, 'L');
    _pdfLinhaResumo($pdf, 'Marca / modelo / versão', $ou(trim("{$c['veiculo_marca']} {$c['veiculo_modelo']}")));
    _pdfLinhaResumo($pdf, 'Ano fabricação / modelo / cor', ($c['veiculo_ano'] ?: '______') . ' / cor: ____________________');
    _pdfLinhaResumo($pdf, 'Placa / RENAVAM / chassi', ($c['veiculo_placa'] ?: '______') . ' / ' . ($c['veiculo_renavam'] ?: '______') . ' / ' . ($c['veiculo_chassi'] ?: '______'));
    _pdfLinhaResumo($pdf, 'Local informado para retirada', $linha . "\n" . $linha);

    $pdf->Ln(3);
    _pdfTituloClausula($pdf, 'AUTORIZAÇÃO DE RETIRADA');
    _pdfCorpo($pdf,
        'Em razão do encerramento da relação contratual, o(a) LOCATÁRIO(A)/PROMITENTE COMPRADOR(A) autoriza a FASTCAR ou ' .
        'seu preposto identificado a retirar o veículo, após comunicação da retirada ao possuidor pelos canais contratuais ' .
        'e observadas as comunicações anteriores e demais condições aplicáveis, no local onde for encontrado, tornando a ' .
        'retirada lícita e realizada de forma amigável mediante a assinatura dos presentes termos.' . "\n\n" .
        'Havendo oposição à retirada, conflito ou necessidade de coerção, a FASTCAR adotará a medida judicial cabível.' . "\n\n" .
        'A retirada será registrada com data, horário, local, identificação do responsável e, quando possível, condição ' .
        'aparente do veículo, chaves, documentos e acessórios, inclusive por fotografia. A assinatura deste termo autoriza ' .
        'a retirada amigável; não declara que o veículo já foi recebido, apenas após a efetiva posse por parte dos ' .
        'prepostos da FastCar.'
    );

    $pdf->Ln(6);
    $pdf->SetFont('Helvetica', '', 9);
    $pdf->Cell(0, 6, _pdfTexto('Local e data: ______________________________, ______ de ____________________ de ________.'), 0, 1, 'L');

    $assinatura = function (string $titulo, string $nome = '', string $cpf = '') use ($pdf) {
        if ($pdf->GetY() > 250) $pdf->AddPage();
        $pdf->Ln(12);
        $pdf->SetFont('Helvetica', '', 8.5);
        $pdf->Cell(0, 5, _pdfTexto('____________________________________________________'), 0, 1, 'C');
        $pdf->SetFont('Helvetica', 'B', 8.5);
        $pdf->Cell(0, 5, _pdfTexto($titulo), 0, 1, 'C');
        $pdf->SetFont('Helvetica', '', 8.5);
        $pdf->Cell(0, 5, _pdfTexto('Nome: ' . ($nome ?: '______________________________') . '   CPF: ' . ($cpf ?: '____________________')), 0, 1, 'C');
    };
    $assinatura('LOCATÁRIO(A)/PROMITENTE COMPRADOR(A)', (string)$c['comprador_nome'], (string)$c['comprador_cpf']);
    $assinatura('FASTCAR SOLUTIONS LTDA. – REPRESENTANTE'); // quem retira assina na hora — nem sempre o representante legal
    $assinatura('TESTEMUNHA 1');
    $assinatura('TESTEMUNHA 2');

    _pdfRodapeEndereco($pdf);

    $caminho = tempnam(sys_get_temp_dir(), 'termo_retirada_') . '.pdf';
    $pdf->Output('F', $caminho);
    return $caminho;
}
