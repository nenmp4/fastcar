<?php
/**
 * Contrato de VENDA com quitação futura do financiamento (modelo
 * 01_Contrato_Mestre_FASTCAR_Venda_Quitacao_Futura.docx, 20 cláusulas).
 * 23/11/2026, "temos 3 contratos": volta como 3º modelo de venda
 * (vendas.modelo_contrato='quitacao'), ao lado de promissória e transferência
 * de posse. Texto restaurado do código de antes da troca pela promissória
 * (commit 24c1ed5^) — só o bloco de assinaturas foi atualizado pro padrão
 * atual (posições da assinatura eletrônica + bloco de testemunhas).
 * Usa os helpers de includes/contratos_pdf.php.
 */
require_once __DIR__ . '/contratos_pdf.php';

function gerarPdfContratoVendaQuitacao(array $c): string {
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
    // Réplica do sistema antigo (26/09/2026) — entrada pode vir em mais de
    // 1 parte via PIX, cada uma com data própria; lista cada uma quando
    // existir mais de 1, senão a linha acima já basta.
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
    _pdfLinhaResumo($pdf, 'Forma de pagamento do COMPRADOR', $c['forma_pagamento']);
    _pdfLinhaResumo($pdf, 'Saldo de preço devido pelo COMPRADOR à FASTCAR', $c['saldo_preco_devido'] ? _fmtMoeda($c['saldo_preco_devido']) : 'inexistente');
    if (!empty($c['parcelamento_qtd_parcelas']) && !empty($c['parcelamento_valor_parcela'])) {
        // 22/10/2026, "cliente quer pagar valor semanal" — a cadência
        // mensal é o padrão implícito de sempre, nunca precisou ser dita
        // explicitamente aqui; só a semanal ganha o parênteses, pra não
        // mudar o texto de nenhum contrato já gerado/assinado antes disso.
        $sufixoPeriodicidade = ($c['parcelamento_periodicidade'] ?? 'mensal') === 'semanal' ? ' (parcela semanal)' : '';
        _pdfLinhaResumo($pdf, 'Parcelamento do saldo remanescente', "{$c['parcelamento_qtd_parcelas']}x de " . _fmtMoeda($c['parcelamento_valor_parcela']) . ", 1ª parcela em {$c['parcelamento_primeira_parcela_data']}{$sufixoPeriodicidade}");
    }
    _pdfLinhaResumo($pdf, 'Natureza do gravame/restrição', 'Alienação fiduciária em favor da instituição financeira indicada abaixo');
    _pdfLinhaResumo($pdf, 'Instituição financeira/credor vinculado', $c['banco_financiamento']);
    // 06/10/2026 — "removermos campos não preenchido" (venda #32, print do
    // Quadro-Resumo real mostrando várias linhas em branco/"--"): os campos
    // abaixo já tinham o próprio input removido do formulário de
    // "Condições da venda" (admin/venda.php, mesmo dia, "não sabemos" —
    // consultor nunca sabe esses dados na hora de registrar a venda), mas
    // o Quadro-Resumo continuava imprimindo a linha mesmo vazia (com
    // fallback tipo "não informado"/"—" ou literalmente em branco) — pura
    // poluição visual num contrato que ninguém mais vai preencher. Agora
    // cada linha só aparece quando o dado existe de verdade; nunca decide
    // isso pela ausência de pergunta no formulário, só pelo valor real no
    // banco — então se um dia alguém preencher via outro caminho (ex:
    // "Contrato financeiro/referência", que vem do lado de COMPRA, não
    // foi removido de formulário nenhum), a linha volta a aparecer sozinha.
    if (!empty($c['contrato_financiamento_numero'])) {
        _pdfLinhaResumo($pdf, 'Contrato financeiro / referência', $c['contrato_financiamento_numero']);
    }
    _pdfLinhaResumo($pdf, 'Saldo estimado do financiamento na contratação', _fmtMoeda($c['saldo_financiamento_atual']));
    _pdfLinhaResumo($pdf, 'Responsável pela dívida perante a instituição', 'FASTCAR');
    _pdfLinhaResumo($pdf, 'Prazo máximo para quitação/baixa', "Até " . _mesesComExtenso((int)$c['prazo_quitacao_meses']) . ", contado da assinatura deste contrato");
    if (!empty($c['data_limite_quitacao'])) {
        _pdfLinhaResumo($pdf, 'Data-limite objetiva', $c['data_limite_quitacao']);
    }
    if (!empty($c['prestacao_contas_texto'])) {
        _pdfLinhaResumo($pdf, 'Prestação de contas de andamento', $c['prestacao_contas_texto']);
    }
    if (!empty($c['seguro_texto'])) {
        _pdfLinhaResumo($pdf, 'Seguro/proteção durante o período intermediário', $c['seguro_texto']);
    }
    if (!empty($c['ipva_responsavel_texto'])) {
        _pdfLinhaResumo($pdf, 'IPVA/licenciamento após entrega', $c['ipva_responsavel_texto']);
    }
    if (!empty($c['multas_texto'])) {
        _pdfLinhaResumo($pdf, 'Multas após entrega', $c['multas_texto']);
    }
    if (!empty($c['rastreador_texto'])) {
        _pdfLinhaResumo($pdf, 'Rastreador', $c['rastreador_texto']);
    }
    if ($c['prazo_transferencia_dias'] !== null) {
        _pdfLinhaResumo($pdf, 'Prazo para transferência após baixa', $c['prazo_transferencia_dias'] . ' dias úteis, observadas exigências do órgão de trânsito');
    }
    // 06/10/2026 — substitui "Penalidade por atraso imputável à FASTCAR"
    // (penalizava a empresa, protegia o COMPRADOR — Cláusula 13.4 antiga,
    // "não favor da fastcar") pela multa do lado oposto: atraso do
    // COMPRADOR nas próprias parcelas (Cláusula 12.5) — texto livre,
    // nunca preenchido sozinho (regra #3), some da linha quando vazio,
    // mesma disciplina já aplicada nos outros campos opcionais acima.
    if (!empty($c['multa_atraso_parcelas_texto'])) {
        _pdfLinhaResumo($pdf, 'Multa por atraso nas parcelas do COMPRADOR', $c['multa_atraso_parcelas_texto']);
    }

    foreach (clausulasContratoVendaQuitacao((int)$c['prazo_quitacao_meses']) as [$titulo, $corpo]) {
        _pdfTituloClausula($pdf, $titulo);
        _pdfCorpo($pdf, $corpo);
    }

    // Bloco de assinaturas inteiro na mesma página (assinatura eletrônica é
    // posicionada por coordenada — nunca pode partir no meio).
    if ($pdf->GetY() > 175) $pdf->AddPage();
    $pdf->Ln(6);
    $pdf->SetFont('Helvetica', '', 9);
    $pdf->Cell(0, 6, _pdfTexto("Barueri/SP, {$c['data_extenso']}."), 0, 1, 'L');
    $pdf->Ln(20);

    $pdf->SetFont('Helvetica', '', 8.5);
    $yAssin = $pdf->GetY();
    $pdf->Cell(85, 5, _pdfTexto('_______________________________'), 0, 0, 'C');
    $pdf->Cell(10, 5, '', 0, 0);
    $pdf->Cell(85, 5, _pdfTexto('_______________________________'), 0, 1, 'C');
    _pdfMarcarAssinatura($pdf, 'fastcar', 62.5, $yAssin);
    _pdfMarcarAssinatura($pdf, '_principal', 157.5, $yAssin);
    $pdf->Cell(85, 5, _pdfTexto('FASTCAR SOLUTIONS LTDA'), 0, 0, 'C');
    $pdf->Cell(10, 5, '', 0, 0);
    $pdf->Cell(85, 5, _pdfTexto('COMPRADOR(A)'), 0, 1, 'C');
    $pdf->Cell(85, 5, _pdfTexto('CNPJ 66.934.500/0001-09'), 0, 0, 'C');
    $pdf->Cell(10, 5, '', 0, 0);
    $pdf->Cell(85, 5, _pdfTexto("Nome/CPF: {$c['comprador_nome']} / {$c['comprador_cpf']}"), 0, 1, 'C');

    $test1 = 'Nome: ' . ($c['testemunha1_nome'] ?: '______________________') . ' CPF: ' . ($c['testemunha1_cpf'] ?: '______________');
    $test2 = 'Nome: ' . ($c['testemunha2_nome'] ?: '______________________') . ' CPF: ' . ($c['testemunha2_cpf'] ?: '______________');
    _pdfBlocoTestemunhas($pdf, $test1, $test2);

    _pdfRodapeEndereco($pdf);

    $caminho = tempnam(sys_get_temp_dir(), 'contrato_venda_quitacao_') . '.pdf';
    _pdfAssinaturasFim($pdf, $caminho);
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
function clausulasContratoVendaQuitacao(int $prazoMeses): array {
    $prazoTexto = _mesesComExtenso($prazoMeses);
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
            "3.1. A FASTCAR obriga-se a negociar com a instituição financeira/credor, promover a quitação do saldo e adotar as providências necessárias à baixa do gravame no prazo indicado no Quadro-Resumo, de {$prazoTexto} contados da assinatura/entrega.\n\n" .
            "3.2. A forma de negociação com o credor poderá envolver liquidação antecipada, acordo, renegociação ou cumprimento do contrato financeiro, desde que não imponha ao COMPRADOR obrigação não expressamente assumida e não exponha o veículo a risco evitável de retomada por inadimplemento da FASTCAR.\n\n" .
            "3.3. A FASTCAR deverá manter a obrigação financeira em situação regular ou formalmente negociada durante o período intermediário e preservar documentos capazes de demonstrar o andamento.\n\n" .
            "3.4. A obrigação de quitar e viabilizar a transferência é obrigação própria da FASTCAR perante o COMPRADOR e não fica afastada pelo simples fato de a instituição financeira adotar procedimentos internos, exigir documentos ou modificar condições de negociação.\n\n" .
            "3.5. Ocorrendo impedimento extraordinário não imputável à FASTCAR, as partes deverão documentá-lo e definir providência proporcional, sem prorrogação automática além do prazo indicado no Quadro-Resumo se isso esvaziar a finalidade econômica do contrato."],
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
            "12.3. Fora da hipótese prevista no item 12.4 abaixo, a existência de descumprimento pelo COMPRADOR não autoriza, por si só, retomada física coercitiva sem base jurídica e procedimento adequado.\n\n" .
            "12.4. AUTORIZAÇÃO DE RETIRADA DO VEÍCULO EM CASO DE INADIMPLÊNCIA SUPERIOR A 60 DIAS. Havendo saldo de preço devido pelo COMPRADOR à FASTCAR pago em parcelas (Quadro-Resumo), o atraso no pagamento de qualquer parcela por prazo superior a 60 (sessenta) dias corridos, sem que tenha sido devidamente sanado, autoriza, desde já e de forma livre e expressa, a FASTCAR a promover a retirada extrajudicial do veículo, diretamente ou por preposto devidamente identificado, mediante prévia notificação por escrito ao COMPRADOR (Cláusula 17ª) com antecedência mínima de 10 (dez) dias para regularização do débito. Ultrapassado esse prazo adicional sem a regularização, a retirada poderá ser efetivada em local de fácil acesso, sem uso de violência, arrombamento, ameaça ou constrangimento ao COMPRADOR ou a terceiros, e independentemente de nova notificação caso a inadimplência persista. Esta autorização não exclui o direito da FASTCAR de cobrar o saldo remanescente, encargos e demais perdas e danos legalmente cabíveis, nem a possibilidade de o COMPRADOR regularizar o débito e reaver o veículo enquanto este ainda estiver em poder da FASTCAR e não houver sido revendido a terceiro.\n\n" .
            "12.5. Em caso de atraso no pagamento de qualquer parcela do saldo de preço devido pelo COMPRADOR, incidirá, se indicada no Quadro-Resumo, a multa ali prevista ('Multa por atraso nas parcelas do COMPRADOR'), sem prejuízo da correção monetária e dos juros de mora legalmente cabíveis, e sem prejuízo da autorização de retirada prevista no item 12.4 quando aplicável."],
        ['CLÁUSULA 13ª – INADIMPLEMENTO DA FASTCAR E NÃO QUITAÇÃO NO PRAZO',
            "13.1. Constituem inadimplemento relevante da FASTCAR, sem prejuízo de outras hipóteses: (i) deixar vencer o prazo máximo sem quitação/regularização por fato imputável à FASTCAR; (ii) permitir inadimplemento do financiamento capaz de gerar retomada do veículo; (iii) criar novo ônus incompatível; (iv) ocultar restrição relevante; (v) recusar injustificadamente a transferência após a baixa.\n\n" .
            "13.2. Verificado risco antes do vencimento do prazo, o COMPRADOR poderá notificar a FASTCAR para apresentar comprovação de regularidade e plano de saneamento em prazo razoável.\n\n" .
            "13.3. Ultrapassada a data-limite sem quitação/baixa por motivo imputável à FASTCAR, o COMPRADOR poderá exigir cumprimento específico, perdas e danos quando cabíveis, ou resolução contratual com restituição dos valores pagos e demais consequências legalmente aplicáveis, observada a apuração do caso concreto.\n\n" .
            "13.4. Eventual penalidade contratual específica pela inadimplência da FASTCAR, se pactuada à parte entre as partes, deverá ser proporcional e não exclui indenização suplementar quando juridicamente admitida e comprovada."],
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
