<?php
/**
 * Contrato de TRANSFERÊNCIA DE POSSE (09/11/2026, "esse contrato de quitação
 * futura que o cliente quita o veículo dentro de 15 meses") — "CONTRATO DE
 * CESSÃO ONEROSA E TRANSFERÊNCIA DE POSSE VEICULAR COM INTERMEDIAÇÃO",
 * transcrito do modelo Contrato_FASTCAR_Transferencia_Posse.docx.
 *
 * 3º modelo de contrato em Vendas (ao lado de Promissória e Locação),
 * confirmado com o usuário. Diferente da promissória: o COMPRADOR/CESSIONÁRIO
 * é quem quita o financiamento (prazo padrão 15 meses, editável), a FASTCAR
 * só intermedeia, e o VENDEDOR/CEDENTE (dono original do veículo — o cliente
 * que trouxe o carro na compra) não assina — a FASTCAR o representa por procuração (09/11/2026).
 *
 * Por baixo continua tipo_contrato='venda' (mesmo financeiro, comissão,
 * relatórios e parcelamento da promissória); só `vendas.modelo_contrato`
 * vira 'posse'. O Quadro-Resumo próprio mora no mesmo promissoria_dados_json,
 * com as chaves de POSSE_CAMPOS. Endereço da sede e foro corrigidos pra
 * Barueri/SP (o modelo trazia Santana de Parnaíba — mesma correção dos outros).
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/promissoria.php'; // PROMISSORIA_COMISSAO_PCT, lerDadosPromissoria(), renderCamposQuadro()

/** Serviço opcional de negociação da quitação junto ao banco (cl. 6-A.1). */
const POSSE_VALOR_SERVICO_QUITACAO = 1000.00;
/** Prazo padrão pro comprador quitar o financiamento. */
const POSSE_PRAZO_QUITACAO_PADRAO = 15;

const POSSE_CAMPOS = [
    'prazo_quitacao_meses'      => ['label' => 'Prazo pro comprador quitar o financiamento (meses)', 'tipo' => 'int', 'padrao' => '15'],
    'comissao_aplicavel'        => ['label' => 'Comissão de intermediação (15% da FIPE) se aplica?', 'tipo' => 'select', 'opcoes' => ['sim' => 'Sim — cobra 15% da FIPE', 'nao' => 'Não se aplica'], 'padrao' => 'sim'],
    'entrega_data'              => ['label' => 'Entrega da posse — data', 'tipo' => 'date'],
    'servico_quitacao'          => ['label' => 'Comprador contrata a FASTCAR pra negociar a quitação com o banco? (R$ 1.000,00 à parte)', 'tipo' => 'select', 'opcoes' => ['nao' => 'Não — o comprador quita por conta própria', 'sim' => 'Sim — contrata o serviço (R$ 1.000,00)'], 'padrao' => 'nao'],
    'servico_forma_pagamento'   => ['label' => 'Serviço de quitação — forma de pagamento', 'tipo' => 'text', 'placeholder' => 'ex: PIX'],
    'servico_vencimento'        => ['label' => 'Serviço de quitação — vencimento', 'tipo' => 'date'],
    'comissao_saldo_vencimento' => ['label' => 'Vencimento da comissão ainda a pagar (se a entrada não cobrir)', 'tipo' => 'date'],
    'fipe_data_consulta'        => ['label' => 'Data da consulta FIPE', 'tipo' => 'date'],
    'saldo_referencia_data'     => ['label' => 'Data do saldo de referência do financiamento', 'tipo' => 'date'],
    'responsavel_parcelas'      => ['label' => 'Quem paga as parcelas do financiamento até a quitação', 'tipo' => 'text', 'padrao' => 'COMPRADOR/CESSIONÁRIO'],
    'origem_recursos'           => ['label' => 'Origem dos recursos da quitação', 'tipo' => 'text', 'padrao' => 'recursos próprios do COMPRADOR/CESSIONÁRIO'],
    'valor_banco_incluido'      => ['label' => 'Valor destinado ao banco está incluído no preço da cessão?', 'tipo' => 'select', 'opcoes' => ['nao' => 'Não', 'sim' => 'Sim']],
    'valor_banco_valor'         => ['label' => 'Valor destinado ao banco (R$), se incluído', 'tipo' => 'money'],
    'baixa_gravame_responsavel' => ['label' => 'Responsável por obter a baixa do gravame', 'tipo' => 'text', 'padrao' => 'COMPRADOR/CESSIONÁRIO'],
    'baixa_gravame_prazo'       => ['label' => 'Prazo pra baixa do gravame', 'tipo' => 'text', 'placeholder' => 'ex: 30 dias após a quitação'],
    'transferencia_responsavel' => ['label' => 'Responsável pela transferência registral', 'tipo' => 'text', 'padrao' => 'COMPRADOR/CESSIONÁRIO'],
    'transferencia_prazo'       => ['label' => 'Prazo pra transferência registral', 'tipo' => 'text', 'placeholder' => 'ex: 30 dias após a baixa do gravame'],
    'negociacao_prazo'          => ['label' => 'Prazo pra FASTCAR apresentar a negociação (se contratado o serviço)', 'tipo' => 'text', 'placeholder' => 'ex: 15 dias antes do fim do prazo'],
    'beneficiario_preco'        => ['label' => 'Beneficiário do preço da cessão', 'tipo' => 'text', 'placeholder' => 'vazio = vendedor/cedente'],
    'beneficiario_conta'        => ['label' => 'Conta de pagamento do preço (banco/PIX)', 'tipo' => 'text'],
    'autoriza_recebimento'      => ['label' => 'Cedente autoriza a FASTCAR a receber o preço?', 'tipo' => 'select', 'opcoes' => ['sim' => 'Sim', 'nao' => 'Não']],
    'vencimento_dia'            => ['label' => 'Vencimento das demais parcelas (dia do mês) — vazio = mesmo dia da 1ª', 'tipo' => 'int'],
    'saldo_final_valor'         => ['label' => 'Saldo final adicional (R$), se houver', 'tipo' => 'money'],
    'saldo_final_vencimento'    => ['label' => 'Vencimento do saldo final', 'tipo' => 'date'],
    'protecao_contratada'       => ['label' => 'Proteção veicular contratada?', 'tipo' => 'select', 'opcoes' => ['sim' => 'Sim', 'nao' => 'Não']],
    'protecao_responsavel'      => ['label' => 'Proteção veicular — responsável', 'tipo' => 'text'],
    'protecao_coberturas'       => ['label' => 'Proteção veicular — coberturas', 'tipo' => 'text'],
    'entrega_hora'              => ['label' => 'Entrega da posse — hora', 'tipo' => 'text', 'placeholder' => 'ex: 14h'],
    'entrega_local'             => ['label' => 'Entrega da posse — local', 'tipo' => 'text', 'padrao' => 'Sede FASTCAR — Barueri/SP'],
    'autoriza_imagem'           => ['label' => 'Uso de imagem e voz (vazio = marcar na assinatura)', 'tipo' => 'select', 'opcoes' => ['sim' => 'SIM', 'nao' => 'NÃO']],
    'prazo_devolucao_horas'     => ['label' => 'Prazo de devolução após notificação (horas, cl. 18.1)', 'tipo' => 'int', 'padrao' => '48'],
    'prazo_saneamento_dias'     => ['label' => 'Prazo para sanar descumprimento (dias úteis, cl. 24.2)', 'tipo' => 'int', 'padrao' => '10'],
];

/** Os que aparecem direto no modal/card; o resto fica em "➕ Mais condições". */
const POSSE_CAMPOS_PRINCIPAIS = ['prazo_quitacao_meses', 'comissao_aplicavel', 'entrega_data', 'servico_quitacao', 'servico_forma_pagamento', 'servico_vencimento'];

function vendaEhPosse(array $v): bool {
    return ($v['tipo_contrato'] ?? 'venda') === 'venda' && ($v['modelo_contrato'] ?? 'promissoria') === 'posse';
}

function parseCamposPossePost(array $post): array {
    return parseCamposQuadroPost(POSSE_CAMPOS, $post);
}

/** $prefixo 'posse' — nome dos campos no POST: posse[chave]. */
function renderCamposPosse(array $dados): string {
    return renderCamposQuadro(POSSE_CAMPOS, [], 'posse', $dados, POSSE_CAMPOS_PRINCIPAIS);
}

/** Marca a negociação como transferência de posse e grava o Quadro-Resumo. */
function salvarDadosPosse(int $vendaId, array $dados): void {
    getDB()->prepare("UPDATE vendas SET modelo_contrato = 'posse', promissoria_dados_json = ?, updated_at = datetime('now','localtime') WHERE id = ? AND tipo_contrato = 'venda'")
        ->execute([json_encode($dados, JSON_UNESCAPED_UNICODE), $vendaId]);
}

/** Prazo de quitação efetivo (padrão 15). */
function possePrazoQuitacao(array $p): int {
    $m = (int)($p['prazo_quitacao_meses'] ?? 0);
    return $m > 0 ? $m : POSSE_PRAZO_QUITACAO_PADRAO;
}

/**
 * Valores calculados do Quadro-Resumo — única fonte pro PDF e pra prévia.
 * $c = montarCamposContratoVenda(). Mesma conta da promissória (comissão de
 * 15% da FIPE abatida da entrada), sem a composição 90/10 da parcela, e com o
 * serviço opcional de R$ 1.000,00 somado ao total só se contratado.
 */
function posseQuadro(array $c): array {
    $p = $c['_prom'] ?? [];
    $fipe = $c['valor_fipe_referencia'];
    $entrada = (float)($c['valor_pago_contratacao'] ?? 0);

    $comissaoAplicavel = ($p['comissao_aplicavel'] ?? 'sim') === 'sim';
    $comissao = ($comissaoAplicavel && $fipe) ? round($fipe * PROMISSORIA_COMISSAO_PCT / 100, 2) : null;
    $comissaoAbatida = $comissao !== null ? min($comissao, $entrada) : null;
    $saldoLiquidoEntrada = round($entrada - (float)($comissaoAbatida ?? 0), 2);
    $comissaoAPagar = $comissao !== null ? round($comissao - $comissaoAbatida, 2) : null;

    $qtd = (int)($c['parcelamento_qtd_parcelas'] ?? 0);
    $valorParcela = (float)($c['parcelamento_valor_parcela'] ?? 0);
    $temParcelas = $qtd > 0 && $valorParcela > 0;
    $totalParcelas = $temParcelas ? round($qtd * $valorParcela, 2) : null;

    $servico = ($p['servico_quitacao'] ?? 'nao') === 'sim';
    $saldoFinal = isset($p['saldo_final_valor']) ? (float)$p['saldo_final_valor'] : null;
    $totalAPagar = round($entrada + (float)$totalParcelas + (float)$saldoFinal + (float)$comissaoAPagar
        + ($servico ? POSSE_VALOR_SERVICO_QUITACAO : 0), 2);

    $diaDemais = !empty($p['vencimento_dia']) ? (int)$p['vencimento_dia'] : null;
    if ($diaDemais === null && !empty($c['parcelamento_primeira_parcela_data'])) {
        $diaDemais = (int)substr((string)$c['parcelamento_primeira_parcela_data'], 0, 2) ?: null;
    }

    return [
        'comissao_aplicavel' => $comissaoAplicavel,
        'comissao' => $comissao,
        'comissao_abatida' => $comissaoAbatida,
        'saldo_liquido_entrada' => $saldoLiquidoEntrada,
        'comissao_a_pagar' => $comissaoAPagar,
        'tem_parcelas' => $temParcelas,
        'total_parcelas' => $totalParcelas,
        'servico_quitacao' => $servico,
        'saldo_final' => $saldoFinal,
        'total_a_pagar' => $totalAPagar,
        'dia_demais' => $diaDemais,
        'prazo_quitacao_meses' => possePrazoQuitacao($p),
    ];
}

/** O que falta especificamente pro contrato de posse (além do de sempre). */
function posseCamposFaltando(array $c): array {
    // 09/11/2026: o dono original não é nomeado no contrato (FASTCAR o
    // representa por procuração) — nada dele é obrigatório pra gerar.
    return [];
}

/**
 * Cláusulas — [título, corpo]. Transcrito do modelo
 * Contrato_FASTCAR_Transferencia_Posse.docx; foro corrigido pra Barueri/SP.
 * Os 2 "[●]" (horas pra devolução, cl. 18.1; dias úteis pra sanar, cl. 24.2)
 * vêm do Quadro-Resumo — sem valor, sai linha em branco.
 */
function clausulasContratoPosse(int $horasDevolucao = 0, int $diasSaneamento = 0): array {
    $horas = $horasDevolucao > 0 ? (string)$horasDevolucao : '____';
    $dias = $diasSaneamento > 0 ? (string)$diasSaneamento : '____';
    return [
        ['CLÁUSULA 1ª – OBJETO E NATUREZA DA CONTRATAÇÃO',
            "1.1. O VENDEDOR/CEDENTE cede onerosamente ao COMPRADOR/CESSIONÁRIO os direitos possessórios que legitimamente detém sobre o veículo identificado no Quadro-Resumo e transmite sua posse direta mediante entrega. A FASTCAR intermedeia a negociação e pratica os atos expressamente assumidos neste contrato.\n\n" .
            "1.2. A operação tem por objeto a aquisição onerosa da posse e dos direitos expressamente descritos, sem aluguel, remuneração periódica pelo uso ou divisão das prestações em componentes de uso e aquisição.\n\n" .
            "1.3. A transferência da posse não equivale à transferência da propriedade registral, à cessão do financiamento ou à liberação de gravame. Eventual transferência da propriedade dependerá da legitimidade do titular, da quitação e baixa dos gravames, das anuências necessárias e dos atos próprios perante o órgão competente; este contrato não garante aquisição de direitos que o cedente não possua.\n\n" .
            "1.4. As partes reconhecem que a denominação contratual não afasta a aplicação das normas cogentes eventualmente incidentes sobre a operação."],
        ['CLÁUSULA 2ª – PREÇO DA CESSÃO E DESTINAÇÃO DOS PAGAMENTOS',
            "2.1. O preço da cessão onerosa da posse, a entrada, as parcelas, eventual saldo final e seus vencimentos serão discriminados no Quadro-Resumo. A integralidade da entrada líquida destinada ao preço e de cada parcela do preço paga será abatida do saldo devedor da cessão.\n\n" .
            "2.2. A comissão de intermediação será discriminada separadamente do preço. Não haverá conversão de pagamentos em aluguel em razão de atraso, desistência ou resolução.\n\n" .
            "2.3. Na prestação de contas do encerramento antecipado serão considerados todos os pagamentos efetivamente realizados, sua destinação e os encargos eventualmente devidos, vedada a perda automática integral e a compensação genérica.\n\n" .
            "2.4. Do pagamento inicial poderá ser abatida a comissão exigível nos termos da cláusula 2-A, desde que previamente destacada. O restante será creditado ao preço. O Quadro-Resumo identificará o beneficiário do preço, a conta de pagamento e eventual autorização do cedente para recebimento pela FASTCAR, que prestará contas dos valores recebidos."],
        ['CLÁUSULA 2-A – COMISSÃO DE INTERMEDIAÇÃO',
            "2-A.1. Pela intermediação efetiva entre o COMPRADOR/CESSIONÁRIO e o VENDEDOR/CEDENTE, o comprador pagará à FASTCAR comissão de 15% sobre o valor FIPE do veículo, conforme modelo, versão, ano e data da consulta indicados no Quadro-Resumo. A comissão e o custo total serão previamente destacados; não haverá cobrança sem efetiva intermediação.\n\n" .
            "2-A.2. A comissão será abatida da entrada efetivamente paga, respeitado o momento de sua exigibilidade. Se a entrada for inferior à comissão, o saldo e vencimento serão indicados no Quadro-Resumo, e a forma será informada ao comprador. O saldo líquido destinado à aquisição será creditado ao preço; é vedada cobrança em duplicidade.\n\n" .
            "2-A.3. A comissão será devida quando a intermediação alcançar resultado útil e o negócio for concluído, observada a lei. Após a conclusão, desistência posterior não afasta, por si só, a comissão; preservam-se a restituição exigida por lei, inclusive o direito de arrependimento do art. 49 do CDC quando aplicável, e a análise de falha da FASTCAR ou vício do negócio."],
        ['CLÁUSULA 3ª – INFORMAÇÃO SOBRE O PREÇO E A INTERMEDIAÇÃO',
            "3.1. O preço corresponde exclusivamente à cessão dos direitos possessórios descritos neste contrato e considera as condições do veículo e as restrições informadas. O valor FIPE utilizado para a comissão não representa declaração de propriedade livre de ônus nem garantia de valor de revenda.\n\n" .
            "3.2. O COMPRADOR/CESSIONÁRIO declara ter recebido informação prévia sobre o preço, a comissão, os destinatários dos pagamentos e o alcance dos direitos cedidos."],
        ['CLÁUSULA 4ª – PAGAMENTOS',
            "4.1. O COMPRADOR/CESSIONÁRIO pagará os valores e nas datas constantes do Quadro-Resumo.\n\n" .
            "4.2. O pagamento somente será considerado quitado após efetiva confirmação do crédito.\n\n" .
            "4.3. O recebimento de prestação posterior não importa, por si só, novação ou renúncia a prestações e encargos anteriores.\n\n" .
            "4.4. Eventuais alterações financeiras deverão constar de instrumento escrito ou eletrônico que permita comprovação de autoria e integridade."],
        ['CLÁUSULA 5ª – QUITAÇÃO E ALCANCE DOS DIREITOS CEDIDOS',
            "5.1. Cumpridas as obrigações financeiras, o VENDEDOR/CEDENTE e a FASTCAR, quanto aos valores que cada um receber, fornecerão quitação. A quitação do preço da posse não transfere automaticamente a propriedade nem extingue financiamento. Eventual obrigação de promover quitação bancária, baixa de gravame ou transferência registral deverá ser expressamente atribuída no Quadro-Resumo, com responsável e prazo.\n\n" .
            "5.2. Enquanto houver saldo do preço, o COMPRADOR/CESSIONÁRIO não poderá ceder os direitos adquiridos, alienar ou oferecer o veículo em garantia sem anuência escrita do VENDEDOR/CEDENTE, respeitados os direitos de terceiros e as anuências legalmente exigidas.\n\n" .
            "5.3. A mera posse ou o decurso do prazo não transferem automaticamente a propriedade."],
        ['CLÁUSULA 6ª – SITUAÇÃO DOMINIAL E DOCUMENTAL',
            "6.1. O Quadro-Resumo identificará o titular registral, o cedente, a origem e extensão dos direitos cedidos, financiamento, credor, saldo conhecido, gravames, restrições e eventual responsável por quitação e baixa. O cedente apresentará documentação que comprove sua legitimidade para transmitir a posse; a FASTCAR verificará e informará os documentos e restrições conhecidos no âmbito de sua intermediação.\n\n" .
            "6.2. O COMPRADOR/CESSIONÁRIO declara ter recebido, antes da contratação, as informações sobre a situação dominial e documental indicadas no Quadro-Resumo.\n\n" .
            "6.3. O VENDEDOR/CEDENTE responde pela legitimidade dos direitos cedidos e pelas obrigações que assumir. A FASTCAR responde por sua intermediação, informações prestadas, valores recebidos e demais obrigações próprias, sem exclusão de responsabilidade imposta por lei. A cessão não substitui o devedor perante o banco nem afasta direitos do credor; anuências necessárias deverão ser obtidas antes da execução dos atos que delas dependam."],
        ['CLÁUSULA 6-A – DA NEGOCIAÇÃO COM A INSTITUIÇÃO FINANCEIRA',
            "6-A.1. O COMPRADOR/CESSIONÁRIO é responsável por realizar e suportar o pagamento da quitação do financiamento. A FASTCAR não assume a obrigação de pagar a dívida perante a instituição financeira. O comprador poderá contratar, de forma opcional e mediante marcação e assinatura específicas no Quadro-Resumo, o serviço exclusivo de negociação de quitação veicular pela FASTCAR, pelo valor adicional de R$ 1.000,00 (mil reais), com forma de pagamento e vencimento ali preenchidos. Esse valor é distinto da comissão de 15% da FIPE e do preço da cessão e somente será cobrado se houver contratação expressa.\n\n" .
            "6-A.2. Contratado o serviço opcional, a FASTCAR conduzirá exclusivamente a negociação da quitação perante o credor, mediante as autorizações necessárias do titular do financiamento, e apresentará por escrito ao comprador o valor negociado, o vencimento, a identificação do credor e as instruções de pagamento, acompanhados de boleto, proposta, termo ou outro documento emitido ou confirmado pela instituição financeira. Caberá ao comprador pagar a quitação no valor e dentro do prazo apresentados pela FASTCAR, diretamente ao credor ou a destinatário formalmente autorizado por ele, encaminhando o comprovante à FASTCAR. A remuneração do serviço não se confunde com o valor da dívida e não garante desconto, aceitação de proposta ou resultado dependente do credor.\n\n" .
            "6-A.3. Durante a execução do serviço opcional, o comprador comunicará à FASTCAR cobranças, notificações e propostas recebidas e não celebrará negociação paralela de quitação sem sua anuência prévia e expressa. O pagamento conforme as instruções apresentadas pela FASTCAR não constitui negociação paralela. A apresentação das condições deverá ser comprovada e tempestiva, com antecedência suficiente para o pagamento; atraso ou falha imputável à FASTCAR não caracteriza mora do comprador. Se as condições expirarem antes da comunicação tempestiva, a FASTCAR obterá e apresentará condições atualizadas. Sem contratação do serviço, não haverá exclusividade em favor da FASTCAR, podendo o comprador providenciar diretamente a quitação.\n\n" .
            "6-A.4. Contratado o serviço, a FASTCAR acompanhará a confirmação da quitação e disponibilizará o respectivo termo assim que fornecido pelo credor. Em qualquer hipótese, preservam-se o acesso a informações e documentos e as medidas necessárias à proteção de direitos diante de descumprimento ou risco concreto de negativação, cobrança, vencimento antecipado ou busca e apreensão, com comunicação à FASTCAR quando o serviço estiver contratado, previamente sempre que possível e imediatamente após em caso de urgência. A atribuição contratual não exonera automaticamente o devedor original, não transfere o financiamento nem vincula o banco sem sua anuência quando exigida. O titular do financiamento e o cedente fornecerão os documentos e autorizações necessários. O Quadro-Resumo discriminará a destinação dos pagamentos, sem duplicidade. Permanecem inalterados os termos da comissão prevista na cláusula 2-A e da multa rescisória da cláusula 24."],
        ['CLÁUSULA 7ª – ENTREGA, VISTORIA E ESTADO DO VEÍCULO',
            "7.1. A entrega será confirmada por registro escrito com data, hora, local, quilometragem, combustível, pneus, lataria, pintura, vidros, interior, acessórios, chaves, documentos e avarias preexistentes. Se ocorrer após a assinatura, esse registro será confirmado pelas partes no ato da entrega.\n\n" .
            "7.2. Fotografias e vídeos da entrega ou devolução poderão ser conservados pelas partes como meios de prova, juntamente com o registro escrito.\n\n" .
            "7.3. O reconhecimento do estado aparente do veículo não importa renúncia a direitos legalmente indisponíveis."],
        ['CLÁUSULA 8ª – GUARDA, USO E MANUTENÇÃO',
            "8.1. A partir da entrega e posse efetiva, identificadas pela data e hora do registro de entrega, o COMPRADOR/CESSIONÁRIO assume perante a FASTCAR a guarda, conservação e responsabilidade pelo uso do veículo, inclusive quando conduzido por pessoa a quem permitir acesso, devendo utilizá-lo de modo regular, diligente e compatível com sua destinação:\n" .
            "- realizar as manutenções preventivas previstas e observar alertas do veículo;\n" .
            "- não adulterar chassi, placas, etiquetas, hodômetro ou outros sinais identificadores;\n" .
            "- não realizar modificações estruturais sem autorização;\n" .
            "- comunicar imediatamente defeito grave, acidente, apreensão, furto, roubo ou sinistro;\n" .
            "- não utilizar o veículo para finalidade ilícita ou incompatível com a contratação.\n\n" .
            "8.2. O COMPRADOR/CESSIONÁRIO suportará durante a posse os custos ordinários e necessários de uso, manutenção preventiva e corretiva decorrente do uso ou desgaste sob sua responsabilidade, tais como combustível, lubrificantes, pneus, revisões e reparos; realizará as manutenções exigidas pelo fabricante e apresentará comprovantes quando solicitados. Vícios preexistentes ou ocultos e reparos de responsabilidade legal ou contratual da FASTCAR não são transferidos automaticamente ao COMPRADOR/CESSIONÁRIO."],
        ['CLÁUSULA 9ª – MULTAS, CONDUTOR E ENCARGOS DE TRÂNSITO',
            "9.1. Infrações praticadas durante a posse serão de responsabilidade econômica do COMPRADOR/CESSIONÁRIO quando decorrentes de seu uso ou de condutor por ele autorizado, na medida legalmente aplicável; a identificação do condutor e as responsabilidades perante órgãos públicos obedecerão à legislação.\n\n" .
            "9.2. O COMPRADOR/CESSIONÁRIO fornecerá tempestivamente documentos e informações necessários à identificação do real condutor.\n\n" .
            "9.3. Valores suportados pela FASTCAR por omissão ou ato imputável ao COMPRADOR/CESSIONÁRIO poderão ser reembolsados mediante demonstrativo."],
        ['CLÁUSULA 10ª – TRIBUTOS, LICENCIAMENTO E OUTROS ENCARGOS',
            "10.1. Entre as partes, a partir da entrega efetiva e durante a posse, o COMPRADOR/CESSIONÁRIO arcará com IPVA, licenciamento e taxas correspondentes ao período, inclusive por rateio proporcional quando o tributo compreender período anterior e posterior à entrega; débitos anteriores, não atribuíveis ao COMPRADOR/CESSIONÁRIO, permanecem a cargo da FASTCAR ou de quem os deva. Custos de proteção veicular eventualmente contratada observarão as condições indicadas no Quadro-Resumo.\n\n" .
            "10.2. Esta distribuição opera entre as partes e não altera, por si só, o sujeito passivo tributário ou a responsabilidade legal perante autoridades ou terceiros. Valores suportados por uma parte e atribuíveis à outra serão reembolsados mediante demonstrativo e comprovantes."],
        ['CLÁUSULA 11ª – PROTEÇÃO VEICULAR E SINISTROS',
            "11.1. A FASTCAR recomenda ao COMPRADOR/CESSIONÁRIO considerar a contratação de proteção veicular durante a posse, com entidade habilitada ou regularmente cadastrada quando exigido, para mitigar riscos de colisão, perda, furto, roubo e outros eventos. A contratação é facultativa e não constitui condição automática para a celebração deste contrato.\n\n" .
            "11.2. Caso contratada, a existência da proteção, o responsável pelo pagamento e as coberturas serão indicados no Quadro-Resumo. Antes da adesão, o COMPRADOR/CESSIONÁRIO deverá ter acesso às condições do programa, inclusive entidade responsável, custos, participação em eventos, abrangência, exclusões, limites, prazos de carência e procedimentos de acionamento. Nenhuma cobertura, indenização ou regularidade será presumida.\n\n" .
            "11.3. A partir da posse efetiva, o COMPRADOR/CESSIONÁRIO deverá guardar o veículo e comunicar imediatamente à FASTCAR acidente, avaria, furto, roubo, desaparecimento ou perda total, registrar ocorrência quando cabível e cooperar com autoridades e, se contratada, com a entidade responsável pela proteção veicular, sem assumir perante terceiros responsabilidade além da lei.\n\n" .
            "11.4. O COMPRADOR/CESSIONÁRIO assume contratualmente, entre as partes e durante sua posse, o risco econômico de dano, deterioração extraordinária, perda, furto, roubo e sinistro, inclusive os resultantes de caso fortuito ou força maior, respeitados os direitos legalmente indisponíveis e a apuração da causa. Não responderá por vícios preexistentes ou ocultos, falha da FASTCAR, ato atribuível a ela ou risco cuja transferência seja vedada por lei.\n\n" .
            "11.5. Em caso de dano reparável, o custo será apurado por vistoria e orçamento ou comprovantes idôneos, descontados valores efetivamente recebidos da proteção veicular ou de terceiro. O COMPRADOR/CESSIONÁRIO arcará com a participação no evento e a parcela não coberta quando contratualmente atribuíveis e legalmente exigíveis, sem duplicidade.\n\n" .
            "11.6. Em caso de perda total, furto ou roubo, as partes apurarão o valor de referência do veículo na data do evento, preferencialmente pela Tabela FIPE do modelo e versão, ajustado por elementos comprovados, descontados valores efetivamente recebidos da proteção veicular, a recuperação do bem, salvados e valores recebidos de terceiros, evitando duplicidade. Direitos prioritários do credor fiduciário e a destinação dos valores recebidos serão respeitados e demonstrados.\n\n" .
            "11.7. A continuidade ou resolução do contrato e eventual saldo serão objeto de prestação de contas individualizada, considerando valores já pagos pelo COMPRADOR/CESSIONÁRIO, obrigações pendentes, responsabilidade pelo evento e valores efetivamente recebidos; não há perda automática integral de pagamentos, cobrança cumulativa do valor integral do veículo e das parcelas pelo mesmo prejuízo, nem presunção de renúncia a direitos legais."],
        ['CLÁUSULA 12ª – RASTREAMENTO E GEOLOCALIZAÇÃO',
            "12.1. O COMPRADOR/CESSIONÁRIO declara ciência ostensiva de que o veículo possui ou poderá possuir sistema de rastreamento e geolocalização.\n\n" .
            "12.2. O sistema poderá ser utilizado para proteção patrimonial, segurança, prevenção e apuração de fraude, localização do ativo, execução contratual, exercício regular de direitos e preservação de prova, observados os limites legais.\n\n" .
            "12.3. É vedado retirar, destruir, adulterar, bloquear ou deliberadamente impedir o funcionamento do rastreador.\n\n" .
            "12.4. Falha de sinal não será presumida automaticamente como fraude; a FASTCAR poderá solicitar apresentação do veículo ou verificação técnica quando houver motivo justificável.\n\n" .
            "12.5. A sabotagem comprovadamente intencional constitui inadimplemento contratual grave, sem prejuízo da apuração de danos."],
        ['CLÁUSULA 13ª – PROTEÇÃO DE DADOS PESSOAIS',
            "13.1. A FASTCAR tratará dados pessoais necessários à execução do contrato, proteção do crédito, prevenção a fraude, exercício regular de direitos, segurança e demais bases legais aplicáveis.\n\n" .
            "13.2. Poderão ser tratados dados cadastrais, documentos, contatos, informações financeiras, registros de pagamento, dados do veículo, multas, imagens de vistoria, atendimentos e geolocalização.\n\n" .
            "13.3. O compartilhamento poderá ocorrer, na medida necessária, com empresas de rastreamento, entidades responsáveis pela proteção veicular, despachantes, prestadores, cobrança, assessoria jurídica, órgãos públicos e autoridades competentes.\n\n" .
            "13.4. A FASTCAR adotará medidas razoáveis de segurança e limitará o acesso aos dados conforme finalidade e necessidade.\n\n" .
            "13.5. A utilização de imagem e voz do COMPRADOR/CESSIONÁRIO para publicidade depende da escolha específica indicada no Quadro-Resumo e observará a cláusula 13-A. A recusa não impede nem altera a contratação."],
        ['CLÁUSULA 13-A – USO OPCIONAL DE IMAGEM E VOZ',
            "13-A.1. Se assinalar SIM e rubricar ou assinar especificamente o campo do Quadro-Resumo, o COMPRADOR/CESSIONÁRIO autoriza gratuitamente a FASTCAR SOLUTIONS a captar, reproduzir e divulgar sua imagem e voz gravadas em vídeo, inclusive em depoimentos relativos à sua experiência com a FASTCAR, para divulgação institucional e publicitária da empresa em redes sociais, internet e demais mídias, por prazo indeterminado e sem remuneração pela utilização autorizada.\n\n" .
            "13-A.2. A autorização é opcional e independe da contratação. Se houver marcação NÃO, ausência de marcação, marcação ambígua ou falta de manifestação específica, não haverá autorização para uso publicitário. O tratamento necessário à execução contratual ou ao exercício regular de direitos observará suas próprias bases legais e finalidades, independentemente desta escolha.\n\n" .
            "13-A.3. A autorização poderá ser revogada a qualquer momento, gratuitamente, por notificação extrajudicial à ouvidoria pelo telefone (11) 97816-4525. Após o recebimento, a FASTCAR cessará novos usos baseados no consentimento e providenciará a retirada das publicações sob seu controle, observadas as possibilidades técnicas e as obrigações legais; não garante a remoção de cópias de terceiros. Os usos realizados anteriormente de forma legítima não são invalidados automaticamente.\n\n" .
            "13-A.4. A FASTCAR poderá editar o material para adequar formato e duração, sem alterar o sentido da fala ou expor o titular de modo ofensivo ou incompatível com a finalidade autorizada. A autorização não abrange imagem ou voz de outras pessoas, cuja autorização deverá ser obtida quando necessária."],
        ['CLÁUSULA 14ª – MORA E ENCARGOS',
            "14.1. O não pagamento no vencimento constitui mora nos termos da legislação e do contrato.\n\n" .
            "14.2. Incidirão somente os encargos expressamente indicados no Quadro-Resumo e juridicamente admissíveis.\n\n" .
            "14.3. É vedada a cumulação de penalidades de mesma natureza que resulte em duplicidade sancionatória."],
        ['CLÁUSULA 15ª – GRADAÇÃO DA INADIMPLÊNCIA',
            "- 1 a 30 dias — Atraso inicial: cobrança, registro e primeira notificação.\n" .
            "- 31 a 59 dias — Inadimplemento relevante: notificação reforçada e preparação documental.\n" .
            "- 60 dias ou mais — Inadimplemento qualificado: procedimento de resolução, exigência de devolução e medidas cabíveis, observado o procedimento aplicável.\n\n" .
            "15.1. Os prazos acima são contados em dias corridos, a partir do dia seguinte ao vencimento da obrigação não paga. Fraude, alienação irregular, ocultação deliberada, adulteração do veículo ou sabotagem intencional do rastreador poderão justificar providências anteriores, conforme a gravidade e a legislação aplicável."],
        ['CLÁUSULA 16ª – NOTIFICAÇÕES E ATUALIZAÇÃO CADASTRAL',
            "16.1. São canais contratuais os endereços, telefone/WhatsApp e e-mail indicados pelo COMPRADOR/CESSIONÁRIO.\n\n" .
            "16.2. O COMPRADOR/CESSIONÁRIO deverá comunicar alteração cadastral.\n\n" .
            "16.3. Comunicações ordinárias poderão ser realizadas por meios eletrônicos. Quando a lei exigir forma específica de constituição em mora ou notificação, será observado o procedimento correspondente.\n\n" .
            "16.4. A FASTCAR manterá histórico das comunicações relevantes no dossiê."],
        ['CLÁUSULA 17ª – INADIMPLEMENTO QUALIFICADO E RESOLUÇÃO',
            "17.1. Persistindo inadimplemento igual ou superior a 60 dias, o VENDEDOR/CEDENTE poderá promover a resolução da cessão e exigir a devolução voluntária do veículo, após notificação e observância do procedimento aplicável. A FASTCAR somente atuará em nome do cedente mediante poderes expressos, ressalvada a cobrança de seus próprios créditos. A devolução não será imposta por apreensão privada coercitiva.\n\n" .
            "17.2. A resolução será comunicada de forma documentada, com indicação do motivo, valores conhecidos e prazo de entrega.\n\n" .
            "17.3. A resolução não implica perda automática de todos os créditos econômicos do COMPRADOR/CESSIONÁRIO, os quais serão submetidos à prestação de contas prevista neste instrumento."],
        ['CLÁUSULA 18ª – DEVOLUÇÃO DO VEÍCULO',
            "18.1. Resolvido o contrato ou aceita a desistência, o COMPRADOR/CESSIONÁRIO disponibilizará voluntariamente o veículo ao VENDEDOR/CEDENTE ou a representante autorizado, no prazo indicado na notificação, não inferior a {$horas} horas, salvo urgência legalmente justificável.\n\n" .
            "18.2. A entrega será documentada por registro escrito, com vistoria, quilometragem, combustível, chaves, documentos e acessórios; fotografias poderão ser utilizadas como prova.\n\n" .
            "18.3. A entrega voluntária não importa quitação automática de valores ainda sujeitos à apuração."],
        ['CLÁUSULA 19ª – RECUPERAÇÃO DO ATIVO E LIMITES EXTRAJUDICIAIS',
            "19.1. A FASTCAR poderá contatar o COMPRADOR/CESSIONÁRIO, localizar o veículo, negociar sua apresentação e utilizar prepostos ou empresa especializada para receber entrega voluntária, sempre dentro dos limites legais.\n\n" .
            "19.2. Este contrato não constitui autorização para ingresso forçado em residência, garagem fechada ou propriedade particular, rompimento de obstáculos, ameaça, violência, constrangimento ou qualquer conduta ilícita.\n\n" .
            "19.3. Havendo resistência, conflito possessório ou necessidade de coerção, a FASTCAR adotará a medida judicial adequada.\n\n" .
            "19.4. O eventual uso de chave reserva observará a licitude da posse e do acesso ao local, não servindo como autorização autônoma para ingresso irregular."],
        ['CLÁUSULA 20ª – NÃO DEVOLUÇÃO APÓS A RESOLUÇÃO',
            "20.1. Resolvida validamente a cessão e vencido o prazo de devolução, a retenção injustificada do veículo poderá gerar indenização por prejuízos efetivos e comprovados, inclusive privação do uso quando cabível, sem cobrança automática de aluguel, sem duplicidade com outras verbas e observados os meios legais.\n\n" .
            "20.2. A permanência posterior não restabelece automaticamente a cessão resolvida."],
        ['CLÁUSULA 21ª – DESISTÊNCIA DO COMPRADOR/CESSIONÁRIO',
            "21.1. O COMPRADOR/CESSIONÁRIO poderá solicitar encerramento antecipado, sujeito às consequências previstas neste contrato.\n\n" .
            "21.2. A desistência não produzirá, por si só, perda integral dos valores pagos nem restituição integral automática, ressalvadas as hipóteses legais de restituição integral, inclusive o exercício válido do direito de arrependimento quando aplicável.\n\n" .
            "21.3. Após a devolução, será elaborado Demonstrativo de Encerramento Contratual."],
        ['CLÁUSULA 22ª – PRESTAÇÃO DE CONTAS E ENCONTRO DE CONTAS',
            "22.1. No encerramento serão apurados separadamente todos os pagamentos do COMPRADOR/CESSIONÁRIO, sua destinação e eventuais créditos e débitos das partes, com documentos e fundamento específico, respeitados os direitos aplicáveis e vedada a perda total automática.\n" .
            "Créditos do cedente ou da FASTCAR, conforme titularidade: saldo do preço vencido, sem duplicidade com restituições; comissão efetivamente devida, se não paga; multas e encargos exigíveis; danos e depreciação extraordinária comprovados; despesas atribuíveis e comprovadas; indenização por retenção injustificada, quando cabível; cláusula penal válida, sem duplicidade.\n" .
            "Créditos do COMPRADOR/CESSIONÁRIO: todos os pagamentos destinados ao preço da cessão; valores pagos a maior ou sem causa; restituições devidas após compensações individualizadas; outros créditos reconhecidos.\n\n" .
            "22.2. Todos os valores pagos ao preço da cessão serão considerados no encontro de contas. Não haverá retenção a título de aluguel, percentual de uso ou quilometragem contratada. Eventuais danos e depreciação extraordinária dependerão de demonstração específica e não poderão reproduzir prejuízo já compensado.\n\n" .
            "22.3. Cada parte restituirá os valores que lhe couberem conforme suas obrigações e os recebimentos demonstrados. Havendo, após a prestação de contas individualizada, valores ou saldo remanescente a restituir ao COMPRADOR/CESSIONÁRIO, o responsável pela restituição realizará a devolução em até 30 (trinta) dias úteis, contados a partir da data de assinatura do distrato, por meio acordado entre as partes. A apuração será realizada dentro desse prazo e não o suspenderá nem prorrogará automaticamente. A ausência de distrato assinado não afasta o direito à restituição ou outros prazos legalmente aplicáveis. Quando a legislação exigir restituição em prazo menor ou imediato, inclusive na hipótese do art. 49 do Código de Defesa do Consumidor, prevalecerá a regra legal.\n\n" .
            "22.4. Havendo saldo favorável ao VENDEDOR/CEDENTE ou à FASTCAR, o devedor será notificado para pagamento com discriminação do credor e da origem, sem duplicidade."],
        ['CLÁUSULA 23ª – AVARIAS E DEPRECIAÇÃO EXTRAORDINÁRIA',
            "23.1. Não haverá limite de quilometragem ou tarifa por quilômetro rodado. Em eventual devolução, somente serão apurados danos ou depreciação extraordinária imputáveis ao COMPRADOR/CESSIONÁRIO, mediante comprovação e sem duplicidade.\n\n" .
            "23.2. Avarias e depreciação extraordinária deverão ser demonstradas por vistoria, fotos, orçamento, nota fiscal, laudo ou outro meio idôneo, conforme o caso.\n\n" .
            "23.3. O desgaste ordinário decorrente de uso regular não será duplicadamente cobrado."],
        ['CLÁUSULA 24ª – CLÁUSULA PENAL',
            "24.1. Se descumprimento contratual relevante e imputável a uma parte resultar na resolução, a parte responsável ficará sujeita à multa compensatória de 20% sobre o preço da cessão indicado no Quadro-Resumo, observados a proporcionalidade, o cumprimento parcial e a legislação aplicável. A regra é recíproca e alcança apenas quem der causa à resolução, conforme suas obrigações.\n\n" .
            "24.2. Se o descumprimento for sanável, a parte prejudicada notificará a parte responsável, concedendo prazo de {$dias} dias úteis para regularização, ressalvada urgência ou impossibilidade de saneamento. A multa compensatória não incide automaticamente por simples atraso; encargos moratórios obedecerão à lei e ao contrato, vedada duplicidade."],
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
            "28.2. O COMPRADOR/CESSIONÁRIO comunicará fatos relevantes que afetem posse, localização, segurança, documentação ou conservação do veículo.\n\n" .
            "28.3. A FASTCAR fornecerá informações contratuais relevantes e demonstrativos de encerramento quando cabíveis."],
        ['CLÁUSULA 29ª – INVALIDADE PARCIAL',
            "29.1. A eventual invalidade ou ineficácia de uma disposição não prejudicará automaticamente as demais cláusulas autônomas que possam ser preservadas."],
        ['CLÁUSULA 30ª – SOLUÇÃO DE CONTROVÉRSIAS E FORO',
            "30.1. As partes buscarão inicialmente solução negocial, sem impedir o acesso ao Poder Judiciário.\n\n" .
            "30.2. Respeitadas as regras cogentes de competência, fica eleito o foro da Comarca de Barueri/SP para controvérsias decorrentes deste contrato.\n\n" .
            "30.3. Não se estabelece arbitragem compulsória unilateral."],
    ];
}
