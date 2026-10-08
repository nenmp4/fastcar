<?php
/**
 * Detalhe de uma negociação de venda (revenda de veículo da frota) —
 * dados do comprador, condições da venda, geração de contrato e histórico
 * de etapas. Mesmo padrão de admin/oportunidade.php pro funil de compra,
 * mas sem WhatsApp/qualificação por IA — o comprador de uma revenda entra
 * por outro canal (indicação, anúncio, presencial), não pelo bot
 * (ver includes/vendas.php pro racional completo). Toda mudança de etapa
 * passa por mudarEtapaVenda() (includes/vendas.php) — nunca UPDATE direto.
 */

require_once __DIR__ . '/_bootstrap.php';
requireAcessoVendas();

$db = getDB();
$id = (int)($_GET['id'] ?? 0);

// LEFT JOIN (17/09/2026) — negociação pode não ter veículo vinculado ainda
// (lead recém-entrado pelo WhatsApp, oportunidade_id NULL até o vendedor
// confirmar o match com vincularVeiculoVenda()).
$stmtVenda = $db->prepare("
    SELECT v.*, o.veiculo_marca, o.veiculo_modelo, o.veiculo_ano, o.veiculo_placa, o.veiculo_renavam,
           o.veiculo_chassi, o.banco_financiamento, o.contrato_financiamento_numero, o.saldo_financiamento_atual,
           o.valor_fipe_referencia, c.nome AS vendedor_original_nome
    FROM vendas v
    LEFT JOIN oportunidades o ON o.id = v.oportunidade_id
    LEFT JOIN clientes c ON c.id = o.cliente_id
    WHERE v.id = ?
");
$stmtVenda->execute([$id]);
$v = $stmtVenda->fetch();

if (!$v) {
    http_response_code(404);
    exit('Venda não encontrada.');
}

$erro = '';
$sucesso = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        $erro = 'Sessão expirada, recarregue a página e tente de novo.';
    } elseif ($_SESSION['admin_perfil'] === 'supervisor') {
        // Perfil de acompanhamento (mesmo padrão de admin/oportunidade.php)
        // — vê tudo, nunca age.
        http_response_code(403);
        $erro = 'Perfil de supervisão só acompanha, não altera negociações.';
    } else {
        $acao = (string)($_POST['acao'] ?? '');
        try {
            if ($acao === 'upload_midia_revenda') {
                if (!$v['oportunidade_id']) {
                    $erro = 'Vincule um veículo da frota antes de adicionar fotos/vídeos.';
                } else {
                    // 28/09/2026 — múltiplas fotos de uma vez + 1 legenda
                    // compartilhada, mesma mudança de admin/veiculo_midias.php.
                    $resultadoMidia = salvarMidiasRevendaEmLote((int)$v['oportunidade_id'], $_FILES['midias'] ?? [], (string)($_POST['legenda'] ?? ''));
                    if ($resultadoMidia['ok_count'] > 0) {
                        $sucesso = $resultadoMidia['ok_count'] . ' de ' . $resultadoMidia['total'] . ' arquivo(s) adicionado(s) ao catálogo.';
                        if ($resultadoMidia['erros']) {
                            $sucesso .= ' Falhou: ' . implode(' | ', $resultadoMidia['erros']);
                        }
                    } else {
                        $erro = $resultadoMidia['erros'] ? implode(' | ', $resultadoMidia['erros']) : 'Nenhum arquivo enviado.';
                    }
                }
            } elseif ($acao === 'excluir_midia_revenda') {
                excluirMidiaRevenda((int)($_POST['midia_id'] ?? 0));
                $sucesso = 'Mídia removida do catálogo.';
            } elseif ($acao === 'vincular_veiculo') {
                // Confirma o match entre um lead qualificado pela IA (sem
                // veículo ainda) e um veículo real da frota — sempre ação
                // humana, nunca a IA decide sozinha (ver includes/vendas.php).
                $oportunidadeEscolhida = (int)($_POST['oportunidade_id'] ?? 0);
                if (!$oportunidadeEscolhida) {
                    $erro = 'Escolha um veículo da lista.';
                } else {
                    vincularVeiculoVenda($id, $oportunidadeEscolhida);
                    $sucesso = 'Veículo vinculado a esta negociação.';
                }
            } elseif ($acao === 'atualizar_comprador') {
                $db->prepare("
                    UPDATE vendas
                    SET comprador_nome = ?, comprador_nacionalidade = ?, comprador_estado_civil = ?, comprador_profissao = ?,
                        comprador_rg = ?, comprador_cpf = ?, comprador_cnh = ?, comprador_endereco = ?,
                        comprador_telefone = ?, comprador_email = ?, updated_at = datetime('now','localtime')
                    WHERE id = ?
                ")->execute([
                    clean((string)($_POST['comprador_nome'] ?? '')),
                    clean((string)($_POST['comprador_nacionalidade'] ?? '')),
                    clean((string)($_POST['comprador_estado_civil'] ?? '')),
                    clean((string)($_POST['comprador_profissao'] ?? '')),
                    clean((string)($_POST['comprador_rg'] ?? '')),
                    clean((string)($_POST['comprador_cpf'] ?? '')),
                    clean((string)($_POST['comprador_cnh'] ?? '')),
                    clean((string)($_POST['comprador_endereco'] ?? '')),
                    clean((string)($_POST['comprador_telefone'] ?? '')),
                    clean((string)($_POST['comprador_email'] ?? '')),
                    $id,
                ]);
                $sucesso = 'Dados do comprador atualizados.';
            } elseif ($acao === 'atualizar_condicoes') {
                // valor_pago_contratacao NUNCA é escrito aqui — desde
                // 26/09/2026 é sempre a soma das partes da entrada
                // (ação salvar_entrada_partes, ver includes/vendas.php),
                // pra não ter 2 formulários competindo pelo mesmo campo.
                //
                // 06/10/2026, achado real via screenshot (venda #31) — "remover
                // campos onde esta seta... não sabemos": 6 campos removidos do
                // formulário (data_limite_quitacao/prestacao_contas_texto/
                // seguro_texto/ipva_responsavel_texto/multas_texto/
                // rastreador_texto/prazo_transferencia_dias — 7 no total,
                // confirmado via mensagem de acompanhamento "Penalidade por
                // atrazo essa fica" que excluiu só esse 1 da lista) — o
                // consultor nunca sabe esses dados na hora de registrar a
                // venda. Tirados também desta UPDATE (nunca mais sobrescritos
                // com string vazia a cada salvamento — preserva o que já
                // estava, mesma disciplina de nunca apagar dado existente só
                // porque o campo saiu da tela) — as colunas continuam no
                // schema, só sem jeito de editar por aqui.
                //
                // Mesmo dia, pedido de acompanhamento — "Penalidade por
                // atraso imputável à Fastcar" (que tinha ficado de fora da
                // remoção acima) saiu também: "remover esse campo pois não
                // [é a] favor da fastcar... multa por atraso nas parcelas
                // acho fica melhor" — esse campo protegia o COMPRADOR de a
                // FASTCAR atrasar a quitação (Cláusula 13.4), nunca a
                // empresa. Substituído por `multa_atraso_parcelas_texto`,
                // do lado oposto — multa sobre a parcela do COMPRADOR em
                // atraso (Cláusula 12.5, includes/contratos_pdf.php), mesma
                // disciplina de texto livre/nunca preenchido sozinho.
                // `penalidade_atraso_texto` nunca saiu do schema (dado já
                // gravado preservado), só parou de ser editado/exibido —
                // `montarCamposContratoVenda()`/`gerarPdfContratoVenda()`
                // (includes/contratos.php/contratos_pdf.php) refletem os 2
                // campos removidos do formulário: linha some do Quadro-
                // Resumo quando vazia, em vez do fallback de sempre.
                $db->prepare("
                    UPDATE vendas
                    SET km_entrega = ?, preco_venda = ?, forma_pagamento = ?,
                        saldo_preco_devido = ?, prazo_quitacao_meses = ?, multa_atraso_parcelas_texto = ?,
                        updated_at = datetime('now','localtime')
                    WHERE id = ?
                ")->execute([
                    $_POST['km_entrega'] !== '' ? (int)$_POST['km_entrega'] : null,
                    valorMonetario((string)($_POST['preco_venda'] ?? '')),
                    clean((string)($_POST['forma_pagamento'] ?? '')),
                    valorMonetario((string)($_POST['saldo_preco_devido'] ?? '')),
                    $_POST['prazo_quitacao_meses'] !== '' ? max(1, (int)$_POST['prazo_quitacao_meses']) : 24,
                    clean((string)($_POST['multa_atraso_parcelas_texto'] ?? '')),
                    $id,
                ]);
                $sucesso = 'Condições da venda atualizadas.';
            } elseif ($acao === 'salvar_testemunha2') {
                // 02/10/2026, achado de acompanhamento: "ao pedir para gerar
                // contrato, selecione a 2ª testemunha" — mudou de lugar (do
                // card de condições pra junto do botão de gerar contrato),
                // virou ação PRÓPRIA pelo mesmo motivo do lado de compra (ver
                // admin/oportunidade.php): nunca reaproveitar
                // 'atualizar_condicoes' pra não apagar os outros campos com
                // string vazia num form minúsculo só com esse select.
                $testemunha2Id = $_POST['testemunha2_usuario_id'] !== '' ? (int)$_POST['testemunha2_usuario_id'] : null;
                if ($testemunha2Id !== null && !buscarUsuario($testemunha2Id)) {
                    $testemunha2Id = null;
                }
                $db->prepare("UPDATE vendas SET testemunha2_usuario_id = ?, updated_at = datetime('now','localtime') WHERE id = ?")
                   ->execute([$testemunha2Id, $id]);
                $sucesso = 'Testemunha 2 atualizada.';
            } elseif ($acao === 'salvar_entrada_partes') {
                // Réplica do sistema antigo (26/09/2026, "Jean quer em
                // módulos promissórias vendas") — entrada paga em várias
                // partes via PIX, cada uma com valor/data próprios. Sempre
                // recalcula vendas.valor_pago_contratacao como a soma —
                // ver includes/vendas.php::salvarEntradaPartesVenda().
                $partesPost = [];
                $valoresPost = $_POST['parte_valor'] ?? [];
                $datasPost = $_POST['parte_data'] ?? [];
                foreach ((array)$valoresPost as $i => $valorBruto) {
                    $valor = valorMonetario((string)$valorBruto);
                    if ($valor === null || $valor <= 0) continue;
                    $partesPost[] = ['valor' => $valor, 'data_prevista' => (string)($datasPost[$i] ?? '') ?: null];
                }
                $totalPartes = salvarEntradaPartesVenda($id, $partesPost);
                $sucesso = 'Entrada atualizada — total de ' . count($partesPost) . ' parte(s), somando ' . number_format($totalPartes, 2, ',', '.') . '.';
            } elseif ($acao === 'salvar_bem_troca') {
                salvarBemTrocaVenda($id, [
                    'recebido' => !empty($_POST['bem_troca_recebido']),
                    'tipo' => (string)($_POST['bem_troca_tipo'] ?? ''),
                    'nome' => (string)($_POST['bem_troca_nome'] ?? ''),
                    'valor' => valorMonetario((string)($_POST['bem_troca_valor'] ?? '')),
                    'modelo_ano' => (string)($_POST['bem_troca_modelo_ano'] ?? ''),
                    'ano_fabricacao' => (string)($_POST['bem_troca_ano_fabricacao'] ?? ''),
                    'cor' => (string)($_POST['bem_troca_cor'] ?? ''),
                    'placa' => (string)($_POST['bem_troca_placa'] ?? ''),
                    'chassi' => (string)($_POST['bem_troca_chassi'] ?? ''),
                    'renavam' => (string)($_POST['bem_troca_renavam'] ?? ''),
                ]);
                $sucesso = 'Bem recebido como parte da entrada atualizado.';
            } elseif ($acao === 'gerar_parcelamento') {
                // Fastcar vende veículo da frota financiado pro comprador —
                // entrada + parcelas (pedido José/Jean, 17/09/2026). Até
                // 06/10/2026 tinha um checkbox aqui pra já cobrar de verdade
                // via Asaas — removido ("aparecer botão de gerar
                // parcelamentos no assas depois que contrato tiver
                // assinado... não faz sentindo ele aparecer antes"): esta
                // ação agora é SEMPRE o registro LOCAL (pra conferir os
                // números antes de assinar o contrato) — o botão de Asaas
                // de verdade só aparece DEPOIS do contrato assinado, ação
                // 'gerar_asaas_pos_assinatura' logo abaixo.
                $valorEntrada = (float)str_replace(',', '.', preg_replace('/[^\d,.-]/', '', (string)($_POST['valor_entrada'] ?? '0')));
                $numParcelas = (int)($_POST['num_parcelas'] ?? 0);
                $valorParcela = (float)str_replace(',', '.', preg_replace('/[^\d,.-]/', '', (string)($_POST['valor_parcela'] ?? '0')));
                $primeiraParcela = (string)($_POST['primeira_parcela_data'] ?? '');

                if (!$primeiraParcela) {
                    $erro = 'Informe a data de vencimento da 1ª parcela.';
                } else {
                    $r = finGerarPlanoParcelamentoVenda($id, $valorEntrada, $numParcelas, $valorParcela, $primeiraParcela, null, (string)$v['comprador_nome'], (int)$_SESSION['admin_id']);
                    if ($r['ok']) {
                        salvarParcelamentoTermosVenda($id, $valorParcela, $numParcelas, $primeiraParcela);
                        $sucesso = "Plano de parcelamento gerado no financeiro — {$r['criadas']} lançamento(s).";
                    } else {
                        $erro = $r['erro'];
                    }
                }
            } elseif ($acao === 'gerar_asaas_pos_assinatura') {
                // 06/10/2026, "adicionar gerar parcelamentos no assas botão
                // depois que gerar no sistema poi confere assina contrato
                // depois aparece botão gerar no assas" — confirmado
                // ("não faz sentindo ele aparecer antes"): o botão só
                // aparece com o contrato JÁ assinado (etapa='vendido'),
                // nunca antes — checado aqui no servidor também, nunca só
                // escondendo o botão na tela. Converte o plano LOCAL já
                // gerado/conferido (ação 'gerar_parcelamento' acima) em
                // cobrança real: cancela só as PARCELAS pendentes locais
                // (finCancelarParcelasPendentesVenda() — nunca mexe na
                // entrada, que nunca passa por Asaas) e recria via
                // asaasGerarCobrancaParceladaVenda() com os MESMOS termos já
                // salvos (vendas.parcelamento_*, gravados quando o plano
                // local foi gerado) — nunca pede pra digitar os números de
                // novo. finGerarReceitaVendaAssinatura() (gatilho automático
                // de quando a venda assina) nunca roda nesse caso — seu
                // próprio guard já desiste assim que vê o plano local já
                // existente, é exatamente essa lacuna que este botão cobre.
                if ($v['etapa'] !== 'vendido') {
                    $erro = 'Só dá pra gerar cobrança real no Asaas depois do contrato assinado.';
                } elseif (!asaasConfigured()) {
                    $erro = 'Chave da API Asaas não configurada em Configurações.';
                } elseif (!$v['parcelamento_qtd_parcelas'] || !$v['parcelamento_valor_parcela'] || !$v['parcelamento_primeira_parcela_data']) {
                    $erro = 'Nenhum plano de parcelamento local pra converter — gere um acima primeiro.';
                } else {
                    $asaasCustomerId = asaasCriarClienteSeNecessario((string)$v['comprador_nome'], (string)$v['comprador_cpf'], (string)$v['comprador_telefone'], (string)$v['comprador_email']);
                    if (!$asaasCustomerId) {
                        $erro = 'Não foi possível criar/localizar o cliente no Asaas — confira os dados do comprador (nome/CPF) e a chave da API.';
                    } else {
                        finCancelarParcelasPendentesVenda($id);
                        $r = asaasGerarCobrancaParceladaVenda(
                            $id, $asaasCustomerId, (float)$v['parcelamento_valor_parcela'], (int)$v['parcelamento_qtd_parcelas'],
                            (string)$v['parcelamento_primeira_parcela_data'], "Venda #{$id} — " . trim((string)$v['veiculo_marca'] . ' ' . $v['veiculo_modelo'])
                        );
                        if ($r['ok']) {
                            $sucesso = "Cobrança parcelada criada no Asaas — {$r['criadas']} parcela(s). A entrada continua como lançamento local, intocada.";
                        } else {
                            $erro = 'Falha ao gerar cobrança no Asaas: ' . $r['erro'];
                        }
                    }
                }
            } elseif ($acao === 'cancelar_parcelamento') {
                // 02/10/2026, achado real: "contrato foi gerado mas não
                // assinado, precisa fazer alteração no parcelamento de 36
                // pra 35" — até aqui não existia jeito de corrigir: cancelar
                // lançamento um a um em Financeiro nunca destravava o
                // formulário de gerar outro plano (finGerarPlanoParcelamentoVenda()
                // contava QUALQUER linha, até cancelada). Este botão cancela
                // de uma vez os lançamentos PENDENTES da venda (nunca mexe em
                // já pago — finCancelarLancamentosPendentesVenda() já é essa
                // mesma função usada na devolução de veículo vendido) e, com
                // finContarLancamentosAtivosVenda() agora ignorando cancelado,
                // o formulário de "Gerar plano de parcelamento" reaparece
                // sozinho na recarga — sem mexer na etapa da venda, que
                // continua intacta (nunca dispara mudarEtapaVenda()).
                $rCancel = finCancelarLancamentosPendentesVenda($id);
                if ($rCancel['canceladas'] > 0) {
                    $sucesso = "Parcelamento atual cancelado ({$rCancel['canceladas']} lançamento(s)) — já pode gerar outro abaixo.";
                    if ($rCancel['asaas_falhas'] > 0) {
                        $sucesso .= " ⚠️ {$rCancel['asaas_falhas']} cobrança(s) no Asaas não confirmaram cancelamento — confira lá manualmente.";
                    }
                } else {
                    $erro = 'Nenhum lançamento pendente pra cancelar (já pago fica intocado).';
                }
            } elseif ($acao === 'gerar_contrato') {
                if (!$v['oportunidade_id']) {
                    $erro = 'Vincule um veículo da frota a esta negociação antes de gerar o contrato.';
                } else {
                    $resultadoContrato = gerarEEnviarContratoVenda($id, (int)$_SESSION['admin_id']);
                    if ($resultadoContrato['ok']) {
                        $sucesso = 'Contrato gerado e enviado pra assinatura.' . ($resultadoContrato['aviso'] ? ' ⚠️ ' . $resultadoContrato['aviso'] : '');
                    } else {
                        $erro = $resultadoContrato['erro'];
                    }
                }
            } elseif ($acao === 'gerar_contrato_preview') {
                // 19/09/2026, "espelhar compra... analisar contrato antes
                // enviar" — mesmo espírito de gerarContratoCompraPreview():
                // gera o PDF SÓ pra conferir os dados mesclados antes do
                // comprador já ter recebido o link de assinatura de verdade.
                if (!$v['oportunidade_id']) {
                    $erro = 'Vincule um veículo da frota a esta negociação antes de gerar o contrato.';
                } else {
                    $resultadoPreview = gerarContratoVendaPreview($id, (int)$_SESSION['admin_id']);
                    if ($resultadoPreview['ok']) {
                        $sucesso = 'Rascunho do contrato gerado — confira os dados antes de enviar pra assinatura.';
                    } else {
                        $erro = $resultadoPreview['erro'];
                    }
                }
            } elseif ($acao === 'excluir_contrato_preview') {
                $resultadoExclusao = excluirContratoPreview((int)($_POST['contrato_id'] ?? 0));
                if ($resultadoExclusao['ok']) {
                    $sucesso = 'Rascunho do contrato excluído.';
                } else {
                    $erro = $resultadoExclusao['erro'];
                }
            } elseif ($acao === 'reenviar_link_assinatura_meta') {
                $resultadoReenvioLink = reenviarLinkAssinaturaContratoMeta((int)($_POST['contrato_id'] ?? 0));
                if ($resultadoReenvioLink['ok']) {
                    $sucesso = 'Link de assinatura reenviado pelo WhatsApp oficial da Meta.';
                } else {
                    $erro = $resultadoReenvioLink['erro'];
                }
            } elseif ($acao === 'reenviar_aviso_contrato_meta') {
                $resultadoReenvio = reenviarAvisoAssinaturaContratoMeta((int)($_POST['contrato_id'] ?? 0));
                if ($resultadoReenvio['ok']) {
                    $sucesso = 'Aviso reenviado pelo WhatsApp oficial da Meta.';
                } else {
                    $erro = $resultadoReenvio['erro'];
                }
            } elseif ($acao === 'sincronizar_contrato_zapsign') {
                // 06/10/2026 — achado real: consultor assinou de verdade
                // (ZapSign mostrando "2/4"), mas a tela continuava "0/4"
                // porque só a ZapSign (webhook) ou o cron de 30min
                // (cron/zapsign_sync.php) atualizavam o status — nunca a
                // própria tela, que só lê o que já está no banco. Botão
                // "🔄 Atualizar status" chama a MESMA função do webhook/
                // cron, sob demanda, pra não precisar esperar.
                zapsignSincronizarContrato((int)($_POST['contrato_id'] ?? 0));
                $sucesso = 'Status do contrato atualizado com a ZapSign.';
            } elseif ($acao === 'enviar_link_documentos_venda') {
                // 19/09/2026, "espelhar compra - subir os documentos
                // preencher tudo ter link igual de compra" — mesmo padrão
                // de admin/oportunidade.php (ação enviar_link_documentos),
                // mas pela instância DEDICADA de vendas (zapiCredenciaisVendas()),
                // nunca pela principal, e link pro wizard próprio do
                // comprador (public/documentos_venda.php).
                if (!$v['oportunidade_id']) {
                    $erro = 'Vincule um veículo da frota antes de mandar o link de documentos.';
                } else {
                    $tokenDoc = getOuCriarTokenDocumentosVenda($id);
                    if (!$tokenDoc) {
                        $erro = 'Não foi possível gerar o link — vincule um veículo da frota primeiro.';
                    } else {
                        $baseUrl = getConfig('app_base_url') ?: (($_SERVER['HTTPS'] ?? '') === 'on' ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'];
                        $link = rtrim($baseUrl, '/') . '/public/documentos_venda.php?token=' . $tokenDoc;
                        $msg = "Olá! Pra continuar a compra do veículo, preencha seus dados e envie os documentos por aqui:\n{$link}";

                        $enviado = marcaLogoConfigurada()
                            ? zapiEnviarImagem($v['comprador_telefone'], rtrim($baseUrl, '/') . '/public/assets/logo.png', $msg, zapiCredenciaisVendas())
                            : zapiEnviarTexto($v['comprador_telefone'], $msg, zapiCredenciaisVendas());

                        if ($enviado) {
                            $sucesso = 'Link enviado por WhatsApp.';
                        } else {
                            $erro = "Não deu pra enviar por WhatsApp (confira as credenciais da instância de vendas em Configurações). Link: {$link}";
                        }

                        // Cópia por e-mail, mesmo padrão do lado de compra —
                        // canal independente, nunca troca com o WhatsApp acima.
                        if (!empty($v['comprador_email'])) {
                            $corpoEmail = "<p>Olá, " . e($v['comprador_nome'] ?: '') . "!</p>"
                                . "<p>Pra continuar a compra do veículo, preencha seus dados e envie os documentos pelo link abaixo:</p>"
                                . emailBotao('Enviar documentos', $link)
                                . "<p style=\"font-size:12.5px;color:#6b7280\">Se o botão não funcionar, copie e cole este link no navegador:<br>"
                                . "<a href=\"" . e($link) . "\" style=\"color:#2f6fed\">" . e($link) . "</a></p>";
                            enviarEmail($v['comprador_email'], 'Fastcar — envio de documentos', emailLayout($corpoEmail), $v['comprador_nome'] ?: '');
                        }
                    }
                }
            } elseif ($acao === 'upload_documento_staff_venda') {
                $tipoDoc = (string)($_POST['tipo_documento'] ?? '');
                if (!isset(TIPOS_DOCUMENTOS_COMPRADOR[$tipoDoc])) {
                    $erro = 'Tipo de documento inválido.';
                } elseif (empty($_FILES['arquivo']) || ($_FILES['arquivo']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                    $erro = 'Selecione um arquivo pra enviar.';
                } elseif (!$v['oportunidade_id']) {
                    $erro = 'Vincule um veículo da frota antes de anexar documentos.';
                } else {
                    $resultado = salvarUploadDocumentoVenda($id, $tipoDoc, $_FILES['arquivo'], false);
                    if ($resultado['ok']) {
                        $sucesso = 'Documento anexado.';
                        // Mesma extração por IA e mesma proteção contra
                        // documento no slot errado do lado de compra (ver
                        // admin/oportunidade.php, ação upload_documento_staff).
                        $docSalvo = listarDocumentosVenda($id)[$tipoDoc] ?? null;
                        $arquivoLido = $docSalvo ? lerConteudoArquivoDocumento($docSalvo['drive_file_id'] ?: null, $docSalvo['arquivo_url'] ?: null) : null;
                        if ($arquivoLido) {
                            $dadosExtraidos = extrairDadosDocumentoComIA($tipoDoc, $arquivoLido);
                            if ($dadosExtraidos && !$dadosExtraidos['_documento_correto']) {
                                $tipoPercebido = $dadosExtraidos['_tipo_real_se_diferente'] ?: 'outro tipo de documento';
                                $sucesso = '';
                                $erro = 'Anexado, mas esse arquivo não parece ser ' . (TIPOS_DOCUMENTOS_COMPRADOR[$tipoDoc] ?? $tipoDoc)
                                    . ' — parece ser ' . $tipoPercebido . '. Confira e anexe o arquivo certo (os dados não foram preenchidos automaticamente).';
                            } elseif ($dadosExtraidos) {
                                aplicarDadosExtraidosDocumentoVenda($id, $dadosExtraidos);
                            }
                        }
                    } else {
                        $erro = $resultado['erro'] ?? 'Falha ao anexar documento.';
                    }
                }
            } elseif ($acao === 'confirmar_documento_staff_venda') {
                // "Aceite em nome do comprador" — mesmo espírito de
                // admin/oportunidade.php (ação confirmar_documento_staff):
                // quando o comprador não usa o wizard, o vendedor confirma
                // por ele; nunca reescreve os campos, só marca "já revisei".
                $tipoDocConfirmar = (string)($_POST['tipo_documento'] ?? '');
                if (!isset(TIPOS_DOCUMENTOS_COMPRADOR[$tipoDocConfirmar])) {
                    $erro = 'Tipo de documento inválido.';
                } else {
                    $db->prepare("
                        UPDATE venda_documentos SET dados_confirmados = 1, updated_at = datetime('now','localtime')
                        WHERE venda_id = ? AND tipo = ?
                    ")->execute([$id, $tipoDocConfirmar]);

                    $tiposComprador = array_keys(TIPOS_DOCUMENTOS_COMPRADOR);
                    $ph = implode(',', array_fill(0, count($tiposComprador), '?'));
                    $stmtPendentes = $db->prepare("
                        SELECT COUNT(*) FROM venda_documentos
                        WHERE venda_id = ? AND tipo IN ({$ph}) AND dados_confirmados = 0
                    ");
                    $stmtPendentes->execute([$id, ...$tiposComprador]);
                    if ((int)$stmtPendentes->fetchColumn() === 0) {
                        $db->prepare("UPDATE vendas SET documentos_confirmados_em = datetime('now','localtime') WHERE id = ?")->execute([$id]);
                    }

                    $sucesso = 'Documento confirmado em nome do comprador.';
                }
            } elseif ($acao === 'atualizar_proxima_acao') {
                $db->prepare("
                    UPDATE vendas
                    SET responsavel_id = ?, proxima_acao = ?, proxima_acao_em = ?, updated_at = datetime('now','localtime')
                    WHERE id = ?
                ")->execute([
                    $_POST['responsavel_id'] !== '' ? (int)$_POST['responsavel_id'] : null,
                    clean((string)($_POST['proxima_acao'] ?? '')),
                    $_POST['proxima_acao_em'] !== '' ? str_replace('T', ' ', (string)$_POST['proxima_acao_em']) . ':00' : null,
                    $id,
                ]);
                $sucesso = 'Próxima ação atualizada.';
            } elseif ($acao === 'mudar_etapa') {
                $etapaNovaPost = (string)($_POST['etapa_nova'] ?? '');
                if (in_array($etapaNovaPost, ['contrato_enviado', 'vendido'], true) && !$v['oportunidade_id']) {
                    $erro = 'Vincule um veículo da frota a esta negociação antes de avançar pra essa etapa.';
                } else {
                    mudarEtapaVenda($id, $etapaNovaPost, (int)$_SESSION['admin_id'], clean((string)($_POST['observacao'] ?? '')));
                    $sucesso = 'Etapa atualizada.';
                }
            } elseif ($acao === 'cancelar_venda') {
                // 19/09/2026, "cliente devolver veiculo agente vende para
                // outro" — a mesma ação/etapa ('cancelada') cobre cancelar
                // ANTES de vender e devolução DEPOIS de já vendido; captura
                // a etapa de origem antes da chamada só pra escolher a
                // mensagem certa (mudarEtapaVenda() já cancela os
                // lançamentos futuros nos dois casos, ver includes/vendas.php).
                $eraVendido = $v['etapa'] === 'vendido';
                mudarEtapaVenda($id, 'cancelada', (int)$_SESSION['admin_id'], clean((string)($_POST['motivo'] ?? '')));
                $sucesso = $eraVendido
                    ? 'Devolução registrada — veículo liberado pra uma nova venda. Parcelas futuras ainda pendentes foram canceladas no financeiro (o que já tinha sido pago continua como receita).'
                    : 'Negociação cancelada — veículo liberado pra uma nova tentativa de venda.';
            } elseif ($acao === 'criar_avaliacao') {
                // Checklist de vistoria (entrega ao comprador) — só faz
                // sentido depois do veículo vinculado (é o que dá
                // oportunidade_id, sempre obrigatório em veiculo_avaliacoes).
                // 08/10/2026, "só aparecer quando atribuir" — avaliador_id
                // virou OBRIGATÓRIO (nunca mais nasce "não atribuído
                // ainda"); nunca confia só no `required` do HTML.
                if (!$v['oportunidade_id']) {
                    $erro = 'Vincule um veículo da frota a esta negociação antes de criar a vistoria de entrega.';
                } else {
                    $novoAvaliadorId = (int)($_POST['avaliador_id'] ?? 0) ?: null;
                    if (!$novoAvaliadorId) {
                        $erro = 'Escolha um avaliador antes de criar a vistoria.';
                    } else {
                        $tipoVeiculoAvaliacao = ($_POST['tipo_veiculo'] ?? 'carro') === 'moto' ? 'moto' : 'carro';
                        $novaAvaliacaoId = criarAvaliacao((int)$v['oportunidade_id'], 'venda', $id, $novoAvaliadorId, (int)$_SESSION['admin_id'], $tipoVeiculoAvaliacao);
                        header('Location: /admin/avaliacao.php?id=' . $novaAvaliacaoId);
                        exit;
                    }
                }
            }
        } catch (Throwable $e) {
            $erro = $e->getMessage();
        }
        $stmtVenda->execute([$id]);
        $v = $stmtVenda->fetch();
    }
}

$stmtHist = $db->prepare("
    SELECT h.*, u.nome AS responsavel_nome
    FROM venda_historico h
    LEFT JOIN usuarios u ON u.id = h.responsavel_id
    WHERE h.venda_id = ?
    ORDER BY h.id DESC
");
$stmtHist->execute([$id]);
$historico = $stmtHist->fetchAll();

$stmtContratos = $db->prepare("SELECT * FROM contratos WHERE venda_id = ? ORDER BY id DESC");
$stmtContratos->execute([$id]);
$contratos = $stmtContratos->fetchAll();

$usuarios = listarUsuarios();
$avaliadoresDisponiveis = array_values(array_filter($usuarios, fn($u) => $u['perfil'] === 'avaliador'));
// 02/10/2026 — mesma mecânica do lado de compra: testemunha 1 é sempre o
// vendedor responsável atual, testemunha 2 é escolhida por negociação.
$respIdAtualVenda = $v['responsavel_id'] ? (int)$v['responsavel_id'] : null;
$responsavelAtualVenda = $respIdAtualVenda ? buscarUsuario($respIdAtualVenda) : null;
$testemunha2CandidatosVenda = listarUsuariosParaTestemunha($respIdAtualVenda);
$avaliacoesVeiculo = $v['oportunidade_id'] ? listarAvaliacoesDoVeiculo((int)$v['oportunidade_id']) : [];
$entradaPartes = listarEntradaPartesVenda($id);
$atrasada = $v['proxima_acao_em'] && $v['proxima_acao_em'] < date('Y-m-d H:i:s');
$percentualFipe = ($v['valor_fipe_referencia'] && $v['preco_venda'])
    ? round((float)$v['preco_venda'] / (float)$v['valor_fipe_referencia'] * 100, 2) : null;
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Venda #<?= (int)$v['id'] ?> — Fastcar CRM</title>
<link rel="stylesheet" href="/admin/assets/style.css?v=<?= @filemtime(__DIR__ . '/assets/style.css') ?: 1 ?>">
<link rel="stylesheet" href="/admin/assets/mobile.css?v=<?= @filemtime(__DIR__ . '/assets/mobile.css') ?: 1 ?>">
<script src="/admin/assets/mobile.js?v=<?= @filemtime(__DIR__ . '/assets/mobile.js') ?: 1 ?>" defer></script>
<?php include __DIR__ . '/_pwa_head.php'; ?>
</head>
<body>
<?php include __DIR__ . '/_impersonando_banner.php'; ?>
<header class="topbar">
    <a href="/admin/vendas.php" style="color:#fff">← Vendas</a>
    <a class="topbar-brand" href="<?= e(paginaInicialPorPerfil($_SESSION['admin_perfil'] ?? '')) ?>"><img class="topbar-logo" src="/admin/assets/img/icon-192.png" alt="Fastcar" onerror="this.style.display='none'"><span class="topbar-wordmark">Fast<b>Car</b></span></a>
    <span>Olá, <?= e($_SESSION['admin_nome']) ?></span>
    <a href="/admin/logout.php">Sair</a>
</header>

<main>
<?php if ($erro): ?><div class="alerta-erro"><?= e($erro) ?></div><?php endif; ?>
<?php if ($sucesso): ?><div class="alerta-sucesso"><?= e($sucesso) ?></div><?php endif; ?>

<?php if (($_GET['criado'] ?? '') === '1'): ?>
<?php
    // Resumo pós-cadastro do modal "Vender na Promissória"
    // (admin/vendas.php, 26/09/2026) — "gera resumo... já vem link pro
    // cliente conferir dados, mesma coisa do antigo": mesma disciplina de
    // sempre, nunca dispara o link sozinho, só deixa pronto pra copiar.
    $tokenDocResumoPromissoria = $v['oportunidade_id'] ? getOuCriarTokenDocumentosVenda($id) : null;
    $linkResumoPromissoria = $tokenDocResumoPromissoria
        ? rtrim(getConfig('app_base_url') ?: (($_SERVER['HTTPS'] ?? '') === 'on' ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'], '/')
            . '/public/documentos_venda.php?token=' . $tokenDocResumoPromissoria
        : null;
?>
<div class="alerta-sucesso" style="padding:16px;border-radius:8px;margin-bottom:1rem">
    <h3 style="margin:0 0 8px">✅ Venda registrada com sucesso!</h3>
    <p style="margin:0 0 6px"><strong>Comprador:</strong> <?= e($v['comprador_nome'] ?: '—') ?> · <?= e($v['comprador_telefone'] ?: '—') ?></p>
    <p style="margin:0 0 6px"><strong>Veículo:</strong> <?= e(trim($v['veiculo_marca'] . ' ' . $v['veiculo_modelo'] . ' ' . $v['veiculo_ano'])) ?: '—' ?></p>
    <?php if ($v['preco_venda']): ?>
        <p style="margin:0 0 6px"><strong>Preço de venda:</strong> R$ <?= number_format((float)$v['preco_venda'], 2, ',', '.') ?></p>
    <?php endif; ?>
    <?php if ($entradaPartes): ?>
        <p style="margin:0 0 6px"><strong>Entrada:</strong> R$ <?= number_format((float)($v['valor_pago_contratacao'] ?? 0), 2, ',', '.') ?>
           em <?= count($entradaPartes) ?> parte(s)</p>
    <?php endif; ?>
    <?php if ($v['bem_troca_recebido']): ?>
        <p style="margin:0 0 6px"><strong>Bem recebido como parte da entrada:</strong> <?= e($v['bem_troca_nome'] ?: '—') ?><?= $v['bem_troca_valor'] ? ' (R$ ' . number_format((float)$v['bem_troca_valor'], 2, ',', '.') . ')' : '' ?></p>
    <?php endif; ?>
    <?php if ($linkResumoPromissoria): ?>
        <p style="margin:10px 0 0">
            <strong>Link pro comprador conferir os dados/documentos:</strong><br>
            <code style="font-size:12px;word-break:break-all"><?= e($linkResumoPromissoria) ?></code>
            <button type="button" class="btn-texto" onclick='copiarTexto(<?= json_encode($linkResumoPromissoria) ?>, this)'>📋 Copiar link</button>
        </p>
        <p style="margin:6px 0 0"><small>Ainda não foi enviado — copie e mande manualmente, ou use o botão "Enviar link por WhatsApp" no card "📎 Documentos do comprador" abaixo.</small></p>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="card">
    <h2>#<?= (int)$v['id'] ?> — <?= e(trim($v['veiculo_marca'] . ' ' . $v['veiculo_modelo'])) ?: '—' ?> <?= e((string)($v['veiculo_ano'] ?? '')) ?>
        <span class="badge"><?= e(etapaVendaLabel($v['etapa'])) ?></span>
        <?php if ($atrasada): ?><span class="badge badge-atraso">⚠️ ação atrasada</span><?php endif; ?>
        <?php if ($v['temperatura_lead']): ?>
            <span class="badge" title="Leitura da IA sobre a prontidão de compra do lead">
                <?= ['quente' => '🔥 Quente', 'morno' => '🌤️ Morno', 'frio' => '❄️ Frio'][$v['temperatura_lead']] ?? '' ?>
            </span>
        <?php endif; ?>
    </h2>
    <div class="grid-2">
        <div>
            <p><strong>Placa / RENAVAM / Chassi:</strong> <?= e($v['veiculo_placa'] ?: '—') ?> / <?= e($v['veiculo_renavam'] ?: '—') ?> / <?= e($v['veiculo_chassi'] ?: '—') ?></p>
            <p><strong>Comprado originalmente de:</strong> <?= e($v['vendedor_original_nome'] ?: '—') ?></p>
            <p><strong>Financiamento em aberto:</strong> <?= e($v['banco_financiamento'] ?: '—') ?>
               <?= $v['saldo_financiamento_atual'] !== null ? ' · saldo R$ ' . number_format((float)$v['saldo_financiamento_atual'], 2, ',', '.') : '' ?></p>
        </div>
        <div>
            <p><strong>Comprador:</strong> <?= e($v['comprador_nome'] ?: 'ainda não preenchido') ?></p>
            <p><strong>Preço de venda:</strong> <?= $v['preco_venda'] !== null ? 'R$ ' . number_format((float)$v['preco_venda'], 2, ',', '.') : 'não definido' ?>
               <?php if ($percentualFipe !== null): ?> (<?= $percentualFipe ?>% da FIPE)<?php endif; ?></p>
            <p><strong>Data da venda:</strong> <?= $v['data_venda'] ? date('d/m/Y', strtotime($v['data_venda'])) : '—' ?></p>
            <?php if ($v['canal_origem']): ?>
                <?php
                    // 29/09/2026, CPL — mesmo enriquecimento de admin/oportunidade.php
                    // (campaign_name/ad_name de verdade quando já tem gasto sincronizado
                    // pra esse ad_id, senão cai no texto cru já salvo).
                    $origemNomesVenda = null;
                    if ($v['anuncio_origem']) {
                        $stmtOrigemNomesVenda = $db->prepare("SELECT campaign_name, ad_name FROM anuncio_gasto_diario WHERE ad_id = ? ORDER BY data DESC LIMIT 1");
                        $stmtOrigemNomesVenda->execute([$v['anuncio_origem']]);
                        $origemNomesVenda = $stmtOrigemNomesVenda->fetch();
                    }
                ?>
                <p><strong>Origem:</strong> <?= e($v['canal_origem']) ?>
                   <?php if ($origemNomesVenda): ?>
                       · <?= e($origemNomesVenda['campaign_name']) ?> › <?= e($origemNomesVenda['ad_name']) ?>
                   <?php else: ?>
                       <?= $v['campanha_origem'] ? ' · ' . e($v['campanha_origem']) : '' ?>
                   <?php endif; ?>
                </p>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($v['origem'] === 'whatsapp'): ?>
<div class="card">
    <h3>🤖 Lead qualificado por IA</h3>
    <p><small>Entrou sozinho pela instância de WhatsApp de vendas.
       <a href="/admin/vendas_inbox.php?telefone=<?= e($v['comprador_telefone']) ?>">Ver conversa no WhatsApp Vendas →</a></small></p>
    <?php if ($v['veiculo_interesse_texto']): ?><p><strong>O que procura:</strong> <?= e($v['veiculo_interesse_texto']) ?></p><?php endif; ?>
    <?php $labelUso = ['passeio' => 'Passeio', 'aplicativo' => 'Aplicativo (Uber/99/entrega)', 'utilitario' => 'Utilitário'][$v['tipo_uso_veiculo']] ?? null; ?>
    <?php if ($labelUso): ?><p><strong>Uso pretendido:</strong> <?= e($labelUso) ?></p><?php endif; ?>
    <?php if ($v['forma_pagamento_pretendida']): ?><p><strong>Forma de pagamento pretendida:</strong> <?= e($v['forma_pagamento_pretendida']) ?></p><?php endif; ?>
    <?php if ($v['valor_entrada_disponivel'] !== null || $v['valor_parcela_orcamento'] !== null): ?>
        <p><strong>Orçamento:</strong>
           <?= $v['valor_entrada_disponivel'] !== null ? 'entrada R$ ' . number_format((float)$v['valor_entrada_disponivel'], 2, ',', '.') : 'entrada não informada' ?>
           · <?= $v['valor_parcela_orcamento'] !== null ? 'parcela até R$ ' . number_format((float)$v['valor_parcela_orcamento'], 2, ',', '.') : 'parcela não informada' ?>
        </p>
    <?php endif; ?>
    <?php if ($v['urgencia']): ?><p><strong>Urgência:</strong> <?= e($v['urgencia']) ?></p><?php endif; ?>
    <?php if ($v['resumo_ia']): ?><p><strong>Resumo da IA:</strong><br><?= nl2br(e($v['resumo_ia'])) ?></p><?php endif; ?>
    <?php if ($v['etapa'] === 'sem_perfil'): ?>
        <p><span class="badge badge-atraso">⚪ Sem perfil de compra<?= $v['motivo_perda'] ? ': ' . e($v['motivo_perda']) : '' ?></span></p>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if (!$v['oportunidade_id'] && !in_array($v['etapa'], ['vendido', 'cancelada', 'sem_perfil'], true)): ?>
<div class="card">
    <h3>🔗 Vincular veículo da frota</h3>
    <p><small>Essa negociação ainda não tem um veículo confirmado — o sistema NUNCA vincula sozinho
       (mesma regra de "decisão crítica sempre humana" do resto do projeto), escolha manualmente entre os
       disponíveis agora.</small></p>
    <?php $frotaDisponivel = listarFrotaDisponivelParaVenda(); ?>
    <?php if (!$frotaDisponivel): ?>
        <p>Nenhum veículo disponível na frota no momento.</p>
    <?php else: ?>
        <form method="post">
            <?= csrfField() ?>
            <input type="hidden" name="acao" value="vincular_veiculo">
            <select name="oportunidade_id" required>
                <option value="">— escolha um veículo —</option>
                <?php foreach ($frotaDisponivel as $fv): ?>
                    <option value="<?= (int)$fv['oportunidade_id'] ?>">
                        <?= e(trim($fv['veiculo_marca'] . ' ' . $fv['veiculo_modelo'])) ?> <?= e((string)($fv['veiculo_ano'] ?? '')) ?>
                        <?= $fv['valor_referencia'] !== null ? ' — R$ ' . number_format((float)$fv['valor_referencia'], 2, ',', '.') : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="submit">Vincular</button>
        </form>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($v['oportunidade_id']): ?>
<div class="card">
    <h3>📸 Fotos e vídeos pra revenda</h3>
    <p><small>Fica ligado ao VEÍCULO (não a esta negociação específica) — some depois de qualquer nova tentativa de
       venda do mesmo carro. A IA de vendas manda essas mídias sozinha pro comprador quando identifica interesse
       forte nesse veículo, antes mesmo do vínculo ser confirmado aqui.</small></p>
    <?php $midiasRevenda = listarMidiasRevenda((int)$v['oportunidade_id']); ?>
    <?php if ($midiasRevenda): ?>
        <div style="display:flex;flex-wrap:wrap;gap:12px;margin-bottom:14px">
            <?php foreach ($midiasRevenda as $m): ?>
                <div style="width:160px">
                    <?php if ($m['tipo'] === 'foto'): ?>
                        <a href="/admin/ver_midia_revenda.php?id=<?= (int)$m['id'] ?>" target="_blank">
                            <img src="/admin/ver_midia_revenda.php?id=<?= (int)$m['id'] ?>" loading="lazy" style="width:100%;height:120px;object-fit:cover;border-radius:8px">
                        </a>
                    <?php else: ?>
                        <video src="/admin/ver_midia_revenda.php?id=<?= (int)$m['id'] ?>" controls preload="metadata" style="width:100%;border-radius:8px"></video>
                    <?php endif; ?>
                    <?php if ($m['legenda']): ?><small><?= e($m['legenda']) ?></small><?php endif; ?>
                    <?php if ($_SESSION['admin_perfil'] !== 'supervisor'): ?>
                        <form method="post" onsubmit="return confirmarAcao(this, 'Remover essa mídia do catálogo?');" style="margin-top:4px">
                            <?= csrfField() ?>
                            <input type="hidden" name="acao" value="excluir_midia_revenda">
                            <input type="hidden" name="midia_id" value="<?= (int)$m['id'] ?>">
                            <button type="submit" class="perigo" style="margin-top:0;padding:3px 8px;font-size:11.5px">🗑️ Remover</button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <p><small>Nenhuma foto/vídeo cadastrado ainda pra esse veículo.</small></p>
    <?php endif; ?>
    <?php if ($_SESSION['admin_perfil'] !== 'supervisor'): ?>
        <form method="post" enctype="multipart/form-data" id="vd-form-upload-midias">
            <?= csrfField() ?>
            <input type="hidden" name="acao" value="upload_midia_revenda">
            <label>Fotos/vídeos (pode selecionar vários de uma vez — foto até 10MB, vídeo até 50MB cada, 80MB no total do envio)</label>
            <input type="file" id="vd-input-midias" name="midias[]" accept="image/*,video/*" multiple required>
            <small id="vd-tamanho-selecionado"></small>
            <label>Legenda (opcional — aplicada a todas as fotos/vídeos selecionados acima)</label>
            <input type="text" name="legenda" placeholder="Ex: Lateral direita, km atual 42.000">
            <button type="submit">Adicionar ao catálogo</button>
        </form>
        <script>
        // 28/09/2026 — mesmo aviso pré-envio de admin/veiculo_midias.php,
        // ver comentário lá pro racional completo (VEICULO_MIDIA_MAX_BYTES_LOTE).
        (function () {
            var TETO_MB = 80;
            var input = document.getElementById('vd-input-midias');
            var status = document.getElementById('vd-tamanho-selecionado');
            var form = document.getElementById('vd-form-upload-midias');
            if (!input || !status || !form) return;

            function somaMb() {
                var total = 0;
                for (var i = 0; i < input.files.length; i++) total += input.files[i].size;
                return total / 1024 / 1024;
            }

            input.addEventListener('change', function () {
                if (!input.files.length) { status.textContent = ''; return; }
                var mb = somaMb();
                status.textContent = input.files.length + ' arquivo(s) selecionado(s) — ' + mb.toFixed(1) + 'MB no total';
                status.style.color = mb > TETO_MB ? '#c2410c' : '';
                if (mb > TETO_MB) {
                    status.textContent += ' — passou do limite de ' + TETO_MB + 'MB, selecione menos arquivos';
                }
            });

            form.addEventListener('submit', function (e) {
                if (input.files.length && somaMb() > TETO_MB) {
                    e.preventDefault();
                    alert('Esse lote passa de ' + TETO_MB + 'MB no total. Selecione menos fotos/vídeos de uma vez (pode mandar em mais de um envio).');
                }
            });
        })();
        </script>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="card">
    <h3>👤 Dados do comprador</h3>
    <p><small>Alimenta a qualificação do COMPRADOR no contrato-mestre de venda (includes/contratos_pdf.php).</small></p>
    <form method="post">
        <?= csrfField() ?>
        <input type="hidden" name="acao" value="atualizar_comprador">
        <div class="grid-2">
            <div>
                <label>Nome completo</label>
                <input type="text" name="comprador_nome" value="<?= e($v['comprador_nome'] ?? '') ?>">
                <label>CPF/CNPJ</label>
                <input type="text" name="comprador_cpf" value="<?= e($v['comprador_cpf'] ?? '') ?>" placeholder="000.000.000-00">
                <label>RG</label>
                <input type="text" name="comprador_rg" value="<?= e($v['comprador_rg'] ?? '') ?>">
                <label>Nº da CNH (se tiver)</label>
                <input type="text" name="comprador_cnh" value="<?= e($v['comprador_cnh'] ?? '') ?>">
                <label>Nacionalidade</label>
                <input type="text" name="comprador_nacionalidade" value="<?= e($v['comprador_nacionalidade'] ?: 'brasileiro(a)') ?>">
            </div>
            <div>
                <label>Estado civil</label>
                <input type="text" name="comprador_estado_civil" value="<?= e($v['comprador_estado_civil'] ?? '') ?>">
                <label>Profissão</label>
                <input type="text" name="comprador_profissao" value="<?= e($v['comprador_profissao'] ?? '') ?>">
                <label>Endereço completo</label>
                <input type="text" name="comprador_endereco" value="<?= e($v['comprador_endereco'] ?? '') ?>">
                <label>Telefone/WhatsApp</label>
                <input type="text" name="comprador_telefone" value="<?= e($v['comprador_telefone'] ?? '') ?>">
                <label>E-mail</label>
                <input type="email" name="comprador_email" value="<?= e($v['comprador_email'] ?? '') ?>">
            </div>
        </div>
        <button type="submit">Salvar dados do comprador</button>
    </form>
</div>

<?php if ($v['oportunidade_id']): ?>
<div class="card">
    <h3>📎 Documentos do comprador</h3>
    <p><small>Espelha o wizard de compra — mesmo rito (1 documento por vez, IA lê e pré-preenche, comprador revisa e
       confirma), mas só 2 etapas: CNH/RG e comprovante de endereço (comprador de revenda não tem financiamento
       ativo nem CRLV pra entregar).</small></p>

    <?php
        $tokenDocVendaAtual = getOuCriarTokenDocumentosVenda($id);
        $linkDocumentosVenda = $tokenDocVendaAtual
            ? rtrim(getConfig('app_base_url') ?: (($_SERVER['HTTPS'] ?? '') === 'on' ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'], '/')
                . '/public/documentos_venda.php?token=' . $tokenDocVendaAtual
            : null;
        $documentosVenda = listarDocumentosVenda($id);
    ?>

    <?php if ($v['documentos_confirmados_em']): ?>
        <div class="alerta-sucesso" style="padding:8px 12px;border-radius:6px;background:#e3f3e6;color:#2a7a3b;margin-bottom:10px">
            ✅ Dados e documentos confirmados em <?= date('d/m/Y H:i', strtotime($v['documentos_confirmados_em'])) ?>
            (pelo comprador via wizard, ou pelo vendedor em nome dele quando ele não conseguiu usar o link).
        </div>
    <?php elseif (array_filter($documentosVenda, fn($d) => $d['arquivo_url'] || $d['drive_file_id'])): ?>
        <div class="alerta-erro" style="padding:8px 12px;border-radius:6px;background:#fbe4e1;color:#a33;margin-bottom:10px">
            ⏳ Comprador ainda está no meio do wizard de documentos (não confirmou o resumo final ainda).
        </div>
    <?php endif; ?>

    <p>
        <code style="font-size:12px;word-break:break-all"><?= e($linkDocumentosVenda ?: '(gerado ao clicar em enviar)') ?></code>
        <?php if ($linkDocumentosVenda): ?>
            <button type="button" class="btn-texto" onclick='copiarTexto(<?= json_encode($linkDocumentosVenda) ?>, this)'>📋 Copiar link</button>
        <?php endif; ?>
        <br>
        <form method="post" class="inline" style="display:inline-block;margin-top:8px">
            <?= csrfField() ?>
            <input type="hidden" name="acao" value="enviar_link_documentos_venda">
            <button type="submit" style="margin-top:0">Enviar link por WhatsApp</button>
        </form>
        <small style="display:block;color:var(--texto-fraco);margin-top:4px">Sem depender do WhatsApp automático — copie o link e mande manualmente se a instância estiver com problema.</small>
    </p>

    <table class="tabela-oportunidades">
        <thead><tr><th>Documento</th><th>Status</th><th>Enviado por</th><th></th></tr></thead>
        <tbody>
        <?php foreach (TIPOS_DOCUMENTOS_COMPRADOR as $tipo => $label): ?>
            <?php
                $doc = $documentosVenda[$tipo] ?? null;
                $temArquivo = $doc && ($doc['arquivo_url'] || $doc['drive_file_id']);
            ?>
            <tr>
                <td><?= e($label) ?></td>
                <td>
                    <?php if (!$temArquivo): ?>
                        <span class="badge badge-atraso">⏳ pendente</span>
                    <?php elseif (!$doc['dados_confirmados']): ?>
                        <span class="badge badge-atraso">📝 enviado, aguardando comprador confirmar dados</span>
                        <?php if ($_SESSION['admin_perfil'] !== 'supervisor'): ?>
                            <form method="post" class="inline" style="margin-top:4px" onsubmit="return confirmarAcao(this, 'Confirmar que já revisou os dados desse documento em nome do comprador?');">
                                <?= csrfField() ?>
                                <input type="hidden" name="acao" value="confirmar_documento_staff_venda">
                                <input type="hidden" name="tipo_documento" value="<?= e($tipo) ?>">
                                <button type="submit" style="margin-top:2px;padding:3px 8px;font-size:11.5px">✅ Confirmar em nome do comprador</button>
                            </form>
                        <?php endif; ?>
                    <?php else: ?>
                        <span class="badge badge-ok">✅ enviado <?= date('d/m', strtotime($doc['updated_at'])) ?></span>
                    <?php endif; ?>
                </td>
                <td><?= $doc ? ($doc['enviado_pelo_cliente'] ? 'comprador' : 'equipe') : '—' ?></td>
                <td><?= $temArquivo ? '<a href="/admin/ver_documento_venda.php?id=' . (int)$doc['id'] . '" target="_blank">ver</a>' : '' ?></td>
            </tr>
        <?php endforeach; ?>
        <?php foreach (TIPOS_DOCUMENTOS_VENDA_CONTRATO as $tipo => $label): ?>
            <?php
                $doc = $documentosVenda[$tipo] ?? null;
                $temArquivo = $doc && ($doc['arquivo_url'] || $doc['drive_file_id']);
            ?>
            <tr>
                <td><?= e($label) ?></td>
                <td>
                    <?php if ($temArquivo): ?>
                        <span class="badge badge-ok">✅ assinado <?= date('d/m', strtotime($doc['updated_at'])) ?></span>
                    <?php else: ?>
                        <span class="badge badge-atraso">⏳ pendente</span>
                    <?php endif; ?>
                </td>
                <td>equipe</td>
                <td><?= $temArquivo ? '<a href="/admin/ver_documento_venda.php?id=' . (int)$doc['id'] . '" target="_blank">ver</a>' : '' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <?php if ($_SESSION['admin_perfil'] !== 'supervisor'): ?>
        <form method="post" enctype="multipart/form-data" style="margin-top:12px">
            <?= csrfField() ?>
            <input type="hidden" name="acao" value="upload_documento_staff_venda">
            <label>Anexar documento manualmente</label>
            <select name="tipo_documento">
                <?php foreach (TIPOS_DOCUMENTOS_COMPRADOR as $tipo => $label): ?>
                    <option value="<?= e($tipo) ?>"><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
            <input type="file" name="arquivo" accept="image/jpeg,image/png,image/webp,application/pdf" style="margin-top:8px">
            <button type="submit">Anexar</button>
        </form>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="card">
    <h3>💰 Entrada — pago via PIX (em partes)</h3>
    <p><small>Réplica do fluxo do sistema antigo: a entrada pode ser paga em mais de uma parte, cada uma com valor e
       data próprios. O total das partes abaixo é sempre o "Valor pago pelo comprador na contratação" do Quadro-Resumo
       — nunca digitado direto, sempre a soma daqui.</small></p>
    <form method="post" id="form-entrada-partes">
        <?= csrfField() ?>
        <input type="hidden" name="acao" value="salvar_entrada_partes">
        <div id="entrada-partes-linhas">
            <?php if (!$entradaPartes): $entradaPartes = [['valor' => '', 'data_prevista' => '']]; endif; ?>
            <?php foreach ($entradaPartes as $i => $parte): ?>
                <div class="grid-2 entrada-parte-linha" style="align-items:end">
                    <div>
                        <label>Valor da <?= $i + 1 ?>ª parte no PIX (R$)</label>
                        <input type="number" step="0.01" inputmode="decimal" name="parte_valor[]" value="<?= e((string)($parte['valor'] ?? '')) ?>">
                    </div>
                    <div style="display:flex;gap:8px;align-items:end">
                        <div style="flex:1">
                            <label>Data da <?= $i + 1 ?>ª parte no PIX</label>
                            <input type="date" name="parte_data[]" value="<?= e($parte['data_prevista'] ?? '') ?>">
                        </div>
                        <button type="button" class="btn-texto perigo" onclick="this.closest('.entrada-parte-linha').remove()" style="margin-bottom:2px">🗑️ remover</button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <button type="button" class="secundario" onclick="adicionarParteEntrada()">➕ Adicionar outra parte no PIX</button>
        <button type="submit">Salvar entrada</button>
    </form>
    <p><strong>Total da entrada: R$ <?= number_format((float)($v['valor_pago_contratacao'] ?? 0), 2, ',', '.') ?></strong></p>
</div>
<script>
function adicionarParteEntrada() {
    const wrap = document.getElementById('entrada-partes-linhas');
    const n = wrap.querySelectorAll('.entrada-parte-linha').length + 1;
    const div = document.createElement('div');
    div.className = 'grid-2 entrada-parte-linha';
    div.style.alignItems = 'end';
    div.innerHTML = `
        <div>
            <label>Valor da ${n}ª parte no PIX (R$)</label>
            <input type="number" step="0.01" inputmode="decimal" name="parte_valor[]" value="">
        </div>
        <div style="display:flex;gap:8px;align-items:end">
            <div style="flex:1">
                <label>Data da ${n}ª parte no PIX</label>
                <input type="date" name="parte_data[]" value="">
            </div>
            <button type="button" class="btn-texto perigo" onclick="this.closest('.entrada-parte-linha').remove()" style="margin-bottom:2px">🗑️ remover</button>
        </div>`;
    wrap.appendChild(div);
}
</script>

<div class="card">
    <h3>🔁 Bem recebido como parte da entrada</h3>
    <p><small>Veículo ou outro bem que o comprador entregou como parte do pagamento — entra no Quadro-Resumo do
       contrato, mas nunca soma na entrada em dinheiro acima (o valor do bem não gera receita/lançamento financeiro,
       é um ativo, não caixa; registrar no <a href="/admin/patrimonio.php">Patrimônio</a> continua sempre manual).</small></p>
    <form method="post" id="form-bem-troca">
        <?= csrfField() ?>
        <input type="hidden" name="acao" value="salvar_bem_troca">
        <label><input type="checkbox" name="bem_troca_recebido" value="1" id="bem-troca-check" <?= $v['bem_troca_recebido'] ? 'checked' : '' ?> onchange="document.getElementById('bem-troca-campos').style.display = this.checked ? '' : 'none'"> Recebemos um bem como parte da entrada</label>
        <div id="bem-troca-campos" style="<?= $v['bem_troca_recebido'] ? '' : 'display:none' ?>;margin-top:10px">
            <label>Tipo</label>
            <select name="bem_troca_tipo" id="bem-troca-tipo" onchange="document.getElementById('bem-troca-veiculo').style.display = this.value === 'veiculo' ? '' : 'none'">
                <option value="outro" <?= $v['bem_troca_tipo'] === 'outro' ? 'selected' : '' ?>>Outro bem</option>
                <option value="veiculo" <?= $v['bem_troca_tipo'] === 'veiculo' ? 'selected' : '' ?>>Veículo</option>
            </select>
            <label>Descrição do bem</label>
            <input type="text" name="bem_troca_nome" value="<?= e($v['bem_troca_nome'] ?? '') ?>" placeholder="Ex: Moto Honda CG 160, ou nome do bem">
            <label>Valor atribuído ao bem (R$)</label>
            <input type="number" step="0.01" inputmode="decimal" name="bem_troca_valor" value="<?= e((string)($v['bem_troca_valor'] ?? '')) ?>">
            <div id="bem-troca-veiculo" class="grid-2" style="<?= $v['bem_troca_tipo'] === 'veiculo' ? '' : 'display:none' ?>">
                <div>
                    <label>Modelo/ano</label>
                    <input type="text" name="bem_troca_modelo_ano" value="<?= e($v['bem_troca_modelo_ano'] ?? '') ?>">
                    <label>Ano de fabricação</label>
                    <input type="text" name="bem_troca_ano_fabricacao" value="<?= e($v['bem_troca_ano_fabricacao'] ?? '') ?>">
                    <label>Cor</label>
                    <input type="text" name="bem_troca_cor" value="<?= e($v['bem_troca_cor'] ?? '') ?>">
                </div>
                <div>
                    <label>Placa</label>
                    <input type="text" name="bem_troca_placa" value="<?= e($v['bem_troca_placa'] ?? '') ?>">
                    <label>Chassi</label>
                    <input type="text" name="bem_troca_chassi" value="<?= e($v['bem_troca_chassi'] ?? '') ?>">
                    <label>Renavam</label>
                    <input type="text" name="bem_troca_renavam" value="<?= e($v['bem_troca_renavam'] ?? '') ?>">
                </div>
            </div>
        </div>
        <button type="submit">Salvar bem recebido</button>
    </form>
</div>

<div class="card">
    <h3>📝 Condições da venda</h3>
    <p><small>Alimentam o Quadro-Resumo do contrato-mestre de venda — mesma disciplina do lado de compra: o consultor
       confirma com o comprador antes de gerar, nunca preenchido sozinho pelo sistema.</small></p>
    <form method="post">
        <?= csrfField() ?>
        <input type="hidden" name="acao" value="atualizar_condicoes">
        <div class="grid-2">
            <div>
                <label>Quilometragem na entrega</label>
                <input type="number" name="km_entrega" value="<?= e((string)($v['km_entrega'] ?? '')) ?>">
                <label>Preço ajustado (R$)</label>
                <input type="number" step="0.01" name="preco_venda" value="<?= e((string)($v['preco_venda'] ?? '')) ?>">
                <label>Valor pago pelo comprador na contratação (R$) — soma das partes do PIX acima, somente leitura</label>
                <input type="number" step="0.01" value="<?= e((string)($v['valor_pago_contratacao'] ?? '')) ?>" disabled>
                <label>Forma de pagamento</label>
                <input type="text" name="forma_pagamento" value="<?= e($v['forma_pagamento'] ?? '') ?>" placeholder="À vista, financiado, entrada + parcelas...">
                <label>Saldo de preço devido pelo comprador (R$) — deixe em branco se inexistente</label>
                <input type="number" step="0.01" name="saldo_preco_devido" value="<?= e((string)($v['saldo_preco_devido'] ?? '')) ?>">
                <label>Prazo máximo pra quitação do financiamento (meses)</label>
                <input type="number" min="1" name="prazo_quitacao_meses" value="<?= e((string)($v['prazo_quitacao_meses'] ?? 24)) ?>">
            </div>
            <div>
                <label>Multa por atraso nas parcelas do comprador</label>
                <input type="text" name="multa_atraso_parcelas_texto" value="<?= e($v['multa_atraso_parcelas_texto'] ?? '') ?>" placeholder="ex: 2% + 1% ao mês de mora sobre a parcela em atraso">
            </div>
        </div>

        <button type="submit">Salvar condições da venda</button>
    </form>

    <?php if (in_array($v['etapa'], ['negociacao', 'contrato_enviado'], true) && $v['oportunidade_id']): ?>
        <hr>
        <div class="card" style="background:var(--fundo);margin-bottom:14px">
            <strong style="font-size:13px">✍️ Testemunhas do contrato</strong>
            <p style="margin-top:6px"><small>Testemunha 1: <strong><?= $responsavelAtualVenda ? e($responsavelAtualVenda['nome']) : '— ainda sem responsável atribuído —' ?></strong>
               (vendedor responsável pela negociação, automático<?php if ($responsavelAtualVenda && !($responsavelAtualVenda['cpf'] ?? '')): ?> — ⚠️ sem CPF cadastrado ainda, a linha sai em branco no PDF até <a href="/admin/meu_perfil.php">ele preencher o próprio perfil</a><?php endif; ?>)</small></p>
            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="acao" value="salvar_testemunha2">
                <label>Testemunha 2 — selecione antes de gerar o contrato (usuário com CPF já cadastrado)</label>
                <select name="testemunha2_usuario_id">
                    <option value="">— nenhuma escolhida —</option>
                    <?php foreach ($testemunha2CandidatosVenda as $cand): ?>
                        <option value="<?= (int)$cand['id'] ?>" <?= (int)($v['testemunha2_usuario_id'] ?? 0) === (int)$cand['id'] ? 'selected' : '' ?>><?= e($cand['nome']) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if (!$testemunha2CandidatosVenda): ?>
                    <p><small>⚠️ Nenhum usuário com CPF cadastrado ainda — peça pra quem vai ser testemunha 2 preencher o próprio CPF em <a href="/admin/meu_perfil.php">Meu perfil</a>.</small></p>
                <?php endif; ?>
                <button type="submit" class="secundario">Salvar testemunha 2</button>
            </form>
        </div>
        <form method="post" style="display:inline-block;margin-right:8px">
            <?= csrfField() ?>
            <input type="hidden" name="acao" value="gerar_contrato_preview">
            <button type="submit" class="secundario">👁️ Gerar contrato (só visualizar)</button>
        </form>
        <form method="post" style="display:inline-block" onsubmit="return confirmarAcao(this, 'Gerar o contrato de venda e enviar pra assinatura eletrônica?');">
            <?= csrfField() ?>
            <input type="hidden" name="acao" value="gerar_contrato">
            <button type="submit">📄 Gerar contrato e enviar pra assinatura</button>
        </form>
        <p><small>"👁️ Gerar contrato" salva um rascunho só pra conferir, sem disparar assinatura nem
           avisar o comprador. Gerar de novo substitui o rascunho anterior (nunca empilha) — dá pra
           excluir manualmente também, na tabela abaixo.</small></p>
    <?php elseif (in_array($v['etapa'], ['negociacao', 'contrato_enviado'], true)): ?>
        <p><small>⏳ Vincule um veículo da frota (card acima) antes de gerar o contrato.</small></p>
    <?php endif; ?>

    <?php if ($contratos): ?>
        <table class="tabela-oportunidades" style="margin-top:12px">
            <thead><tr><th>Documento</th><th>Status</th><th>Gerado em</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($contratos as $ct): ?>
                <tr>
                    <td><?= e($ct['nome']) ?></td>
                    <td>
                        <?php
                            $badgeClasse = ['assinado' => 'badge-ok', 'recusado' => 'badge-atraso', 'erro' => 'badge-atraso', 'cancelado' => 'badge-atraso'][$ct['status']] ?? '';
                            $badgeIcone = ['gerado' => '📄', 'enviado' => '📤', 'visualizado' => '👀', 'assinado' => '✅', 'recusado' => '❌', 'erro' => '⚠️', 'cancelado' => '🚫'][$ct['status']] ?? '';
                        ?>
                        <span class="badge <?= $badgeClasse ?>"><?= $badgeIcone ?> <?= e($ct['status']) ?></span>
                    </td>
                    <td><?= date('d/m/Y H:i', strtotime($ct['created_at'])) ?></td>
                    <td>
                        <div class="acoes-linha">
                            <?php if ($ct['drive_file_id'] || $ct['arquivo_url']): ?>
                                <a class="chip-acao" href="/admin/ver_contrato.php?id=<?= (int)$ct['id'] ?>" target="_blank">📄 Ver PDF</a>
                            <?php endif; ?>
                            <?php if ($ct['status'] === 'gerado' && $_SESSION['admin_perfil'] !== 'supervisor'): ?>
                                <form method="post" onsubmit="return confirmarAcao(this, 'Excluir este rascunho de contrato? Ação sem volta.');">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="acao" value="excluir_contrato_preview">
                                    <input type="hidden" name="contrato_id" value="<?= (int)$ct['id'] ?>">
                                    <button type="submit" class="chip-acao perigo">🗑️ Excluir rascunho</button>
                                </form>
                            <?php endif; ?>
                            <?php // 02/10/2026 — contrato 'cancelado' (superado por outro mais
                                  // recente da mesma negociação) nunca mostra link de assinatura/
                                  // reenviar — evitar que alguém reenvie por engano o link antigo,
                                  // com dado errado, pro cliente. Ver cancelarContratosAnterioresDaNegociacao().
                            ?>
                            <?php if ($ct['sign_url'] && $ct['status'] !== 'assinado' && $ct['status'] !== 'cancelado'): ?>
                                <a class="chip-acao" href="<?= e($ct['sign_url']) ?>" target="_blank">🔗 Link de assinatura</a>
                                <button type="button" class="chip-acao" onclick='copiarTexto(<?= json_encode($ct['sign_url']) ?>, this)'>📋 Copiar</button>
                                <?php if ($_SESSION['admin_perfil'] !== 'supervisor'): ?>
                                    <form method="post" onsubmit="return confirmarAcao(this, 'Reenviar o link de assinatura por WhatsApp?');">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="acao" value="reenviar_link_assinatura_meta">
                                        <input type="hidden" name="contrato_id" value="<?= (int)$ct['id'] ?>">
                                        <button type="submit" class="chip-acao">📲 Reenviar (WhatsApp)</button>
                                    </form>
                                <?php endif; ?>
                                <?php if ($ct['envio_email_status'] === 'entregue'): ?>
                                    <span class="status-linha" style="color:#2a7a3b">📧 e-mail entregue <?= $ct['envio_email_em'] ? date('d/m H:i', strtotime($ct['envio_email_em'])) : '' ?></span>
                                <?php elseif ($ct['envio_email_status'] === 'sem_email'): ?>
                                    <span class="status-linha" style="color:#a3701a">📧 e-mail: sem cadastro</span>
                                <?php elseif ($ct['envio_email_status'] === 'falhou'): ?>
                                    <span class="status-linha" style="color:#a33">📧 e-mail: falha na API</span>
                                <?php endif; ?>
                            <?php endif; ?>

                            <?php
                                // 06/10/2026 — "como saber quem já assinou" + achado real
                                // "cliente e as testemunhas já assinaram" enquanto a tela
                                // continuava mostrando todo mundo em "também precisa assinar"
                                // pra sempre: o status AGREGADO (contratos.status) só vira
                                // 'assinado' quando TODO signatário termina, mas nunca
                                // distinguia quem especificamente já tinha ido. Ver
                                // contratoSignatariosComStatus()/zapsignAtualizarDetalhePorSignatario()
                                // em includes/contratos.php — reflete progresso PARCIAL em
                                // tempo real, atualizado a cada sincronização (webhook ou
                                // cron/zapsign_sync.php), não só no momento final.
                                $signatariosStatus = contratoSignatariosComStatus($ct);
                            ?>
                            <?php if ($ct['status'] !== 'cancelado'): ?>
                                <div class="status-linha" style="display:block;margin-top:4px">
                                    <strong>Assinaturas:</strong>
                                    <?php foreach ($signatariosStatus as $sig): ?>
                                        <?php if ($sig['assinado']): ?>
                                            <span style="display:inline-block;margin:2px 6px 2px 0;color:#2a7a3b">✅ <?= e($sig['label']) ?> assinou<?= $sig['assinado_em'] ? ' ' . date('d/m H:i', strtotime((string)$sig['assinado_em'])) : '' ?></span>
                                        <?php elseif ($ct['status'] !== 'assinado'): ?>
                                            <span style="display:inline-block;margin:2px 6px 2px 0;color:#a3701a">⏳ <?= e($sig['label']) ?> ainda não assinou<?php if ($sig['sign_url']): ?> <a href="<?= e($sig['sign_url']) ?>" target="_blank">🔗</a><?php endif; ?></span>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </div>
                                <?php if ($ct['zapsign_doc_token'] && $ct['status'] !== 'assinado' && $_SESSION['admin_perfil'] !== 'supervisor'): ?>
                                    <form method="post" style="display:inline-block;margin-top:4px">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="acao" value="sincronizar_contrato_zapsign">
                                        <input type="hidden" name="contrato_id" value="<?= (int)$ct['id'] ?>">
                                        <button type="submit" class="chip-acao">🔄 Atualizar status</button>
                                    </form>
                                <?php endif; ?>
                            <?php endif; ?>

                            <?php if ($ct['status'] === 'assinado'): ?>
                                <span class="status-linha">
                                    📱 WhatsApp:
                                    <?php if ($ct['aviso_whatsapp_enviado_em']): ?>
                                        <span style="color:#2a7a3b">✅ entregue <?= date('d/m H:i', strtotime($ct['aviso_whatsapp_enviado_em'])) ?></span>
                                    <?php else: ?>
                                        <span style="color:#a33">⏳ não confirmado</span>
                                    <?php endif; ?>
                                    &nbsp;·&nbsp;
                                    📧 E-mail:
                                    <?php if ($ct['aviso_email_enviado_em']): ?>
                                        <span style="color:#2a7a3b">✅ entregue <?= date('d/m H:i', strtotime($ct['aviso_email_enviado_em'])) ?></span>
                                    <?php else: ?>
                                        <span style="color:#a33">⏳ não confirmado</span>
                                    <?php endif; ?>
                                </span>
                                <?php if ($_SESSION['admin_perfil'] !== 'supervisor'): ?>
                                    <form method="post">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="acao" value="reenviar_aviso_contrato_meta">
                                        <input type="hidden" name="contrato_id" value="<?= (int)$ct['id'] ?>">
                                        <button type="submit" class="chip-acao">🔁 Reenviar aviso (WhatsApp)</button>
                                    </form>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<div class="card">
    <h3>💳 Financeiro — plano de parcelamento</h3>
    <p><small>Fastcar vende o veículo financiado pro comprador — entrada + parcelas. Gera 1x só; depois disso os
       lançamentos são acompanhados em <a href="/admin/financeiro.php">Financeiro</a>.</small></p>
    <?php
        $lancamentosVenda = finListarLancamentosVenda($id);
        // 02/10/2026 — "ativo" = ainda pendente/pago/atrasado; cancelado
        // nunca conta aqui (mesmo critério de finContarLancamentosAtivosVenda(),
        // calculado em PHP pra não bater no banco de novo com o que já veio).
        $lancamentosAtivosVenda = array_filter($lancamentosVenda, fn($l) => $l['status'] !== 'cancelado');
        // 06/10/2026 — parcela local (parcela_numero>0, nunca a entrada)
        // ainda não convertida pro Asaas (origem != 'asaas'), ainda ativa —
        // existir isso + contrato já assinado é o gatilho do botão "Gerar
        // cobrança real no Asaas" mais abaixo.
        $temParcelaLocalParaConverter = (bool)array_filter($lancamentosAtivosVenda, fn($l) => (int)$l['parcela_numero'] > 0 && $l['origem'] !== 'asaas');
        $podeGerarAsaasPosAssinatura = $v['etapa'] === 'vendido' && asaasConfigured() && $temParcelaLocalParaConverter;
    ?>
    <?php if ($lancamentosVenda): ?>
        <table class="tabela-oportunidades">
            <thead><tr><th>Parcela</th><th>Vencimento</th><th>Valor</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($lancamentosVenda as $l): ?>
                <tr>
                    <td><?= $l['parcela_numero'] == 0 ? 'Entrada' : "{$l['parcela_numero']}/{$l['parcela_total']}" ?></td>
                    <td><?= $l['data_vencimento'] ? date('d/m/Y', strtotime($l['data_vencimento'])) : '—' ?></td>
                    <td>R$ <?= number_format((float)$l['valor'], 2, ',', '.') ?></td>
                    <td><span class="badge"><?= e($l['status']) ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
    <?php if ($lancamentosAtivosVenda): ?>
        <p>
            <small>Plano já gerado e lançado no financeiro. Precisa corrigir número de parcelas/valor antes de
               assinar o contrato? Cancele o plano atual (nunca apaga o histórico acima, só marca como cancelado —
               e pede confirmação no Asaas também, se for o caso) e gere outro embaixo.</small>
        </p>
        <form method="post" onsubmit="return confirmarAcao(this, 'Cancelar o parcelamento atual? Os lançamentos pendentes (entrada/parcelas) viram \'cancelado\' — nunca apagados, só saem do fluxo de cobrança. Lançamento já PAGO não é afetado.')">
            <?= csrfField() ?>
            <input type="hidden" name="acao" value="cancelar_parcelamento">
            <button type="submit" class="chip-acao perigo">🚫 Cancelar parcelamento atual (pra gerar outro)</button>
        </form>
        <?php if ($podeGerarAsaasPosAssinatura): ?>
            <p style="margin-top:14px">
                <small>Contrato já assinado — agora dá pra transformar as parcelas acima em cobrança real,
                   que o comprador paga por boleto/Pix/cartão. A entrada continua como está, nunca passa por
                   aqui.</small>
            </p>
            <form method="post" onsubmit="return confirmarAcao(this, 'Gerar cobrança real no Asaas? Isso cancela as parcelas locais ainda pendentes e recria as mesmas como cobrança de verdade (boleto/Pix/cartão) no Asaas. A entrada continua local, intocada.')">
                <?= csrfField() ?>
                <input type="hidden" name="acao" value="gerar_asaas_pos_assinatura">
                <button type="submit">💳 Gerar cobrança real no Asaas</button>
            </form>
        <?php endif; ?>
    <?php elseif (!$v['oportunidade_id']): ?>
        <p><small>⏳ Vincule um veículo da frota antes de gerar o parcelamento.</small></p>
    <?php else: ?>
        <?php if ($lancamentosVenda): ?>
            <p><small>✅ Parcelamento anterior cancelado — pode gerar um novo abaixo.</small></p>
        <?php endif; ?>
        <form method="post">
            <?= csrfField() ?>
            <input type="hidden" name="acao" value="gerar_parcelamento">
            <div class="grid-2">
                <div>
                    <label>Valor de entrada (R$) — deixe 0 se não houver</label>
                    <input type="text" name="valor_entrada" placeholder="0,00">
                    <label>Número de parcelas</label>
                    <input type="number" name="num_parcelas" min="1" required>
                </div>
                <div>
                    <label>Valor de cada parcela (R$)</label>
                    <input type="text" name="valor_parcela" required placeholder="0,00">
                    <label>Vencimento da 1ª parcela</label>
                    <input type="date" name="primeira_parcela_data" required>
                </div>
            </div>
            <p><small>Isso gera só o registro local, pra conferir os números antes de assinar o contrato.
               <?= asaasConfigured() ? 'A cobrança real pelo Asaas (boleto/Pix/cartão) fica disponível depois que o contrato for assinado.' : 'Asaas não configurado — fica só registrado no financeiro do CRM.' ?></small></p>
            <button type="submit">Gerar plano de parcelamento</button>
        </form>
    <?php endif; ?>
</div>

<div class="grid-2">
    <div class="card">
        <h3>Próxima ação</h3>
        <form method="post">
            <?= csrfField() ?>
            <input type="hidden" name="acao" value="atualizar_proxima_acao">
            <label>Responsável</label>
            <select name="responsavel_id">
                <option value="">— sem responsável —</option>
                <?php foreach ($usuarios as $u): ?>
                    <option value="<?= (int)$u['id'] ?>" <?= (int)$v['responsavel_id'] === (int)$u['id'] ? 'selected' : '' ?>>
                        <?= e($u['nome']) ?> (<?= e($u['perfil']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
            <label>Descrição da próxima ação</label>
            <input type="text" name="proxima_acao" value="<?= e($v['proxima_acao'] ?? '') ?>" placeholder="Ex: ligar às 15h">
            <label>Data/hora</label>
            <input type="datetime-local" name="proxima_acao_em"
                   value="<?= $v['proxima_acao_em'] ? str_replace(' ', 'T', substr($v['proxima_acao_em'], 0, 16)) : '' ?>">
            <button type="submit">Salvar</button>
        </form>
    </div>

    <div class="card">
        <h3>Mudar etapa</h3>
        <?php if (in_array($v['etapa'], ['negociacao', 'contrato_enviado'], true)): ?>
            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="acao" value="mudar_etapa">
                <label>Nova etapa</label>
                <select name="etapa_nova" required>
                    <?php foreach (['negociacao', 'contrato_enviado', 'vendido'] as $et): ?>
                        <option value="<?= e($et) ?>" <?= $v['etapa'] === $et ? 'selected' : '' ?>><?= e(etapaVendaLabel($et)) ?></option>
                    <?php endforeach; ?>
                </select>
                <label>Observação</label>
                <input type="text" name="observacao" placeholder="Opcional">
                <button type="submit">Confirmar</button>
            </form>

            <hr>
            <form method="post" onsubmit="return confirmarAcao(this, 'Cancelar esta negociação? O veículo volta a ficar disponível pra uma nova venda.');">
                <?= csrfField() ?>
                <input type="hidden" name="acao" value="cancelar_venda">
                <label>Motivo do cancelamento (obrigatório)</label>
                <input type="text" name="motivo" required placeholder="Ex: comprador desistiu, não fechou preço...">
                <button type="submit" class="perigo">Cancelar negociação</button>
            </form>
        <?php elseif ($v['etapa'] === 'vendido'): ?>
            <p>✅ Vendido<?= $v['data_venda'] ? ' em ' . date('d/m/Y', strtotime($v['data_venda'])) : '' ?>.</p>
            <hr>
            <!-- 19/09/2026, "temos aquele problema de cliente devolver
                 veiculo agente vende para outro" — antes disso não existia
                 NENHUM jeito de reabrir um veículo já 'vendido' pra uma
                 nova venda: listarFrotaDisponivelParaVenda()/
                 veiculoDisponivelParaVenda() (includes/vendas.php) sempre
                 excluíam qualquer veículo com venda em etapa='vendido', e
                 esta tela só mostrava o botão de cancelar quando a etapa
                 ainda era negociacao/contrato_enviado. Reusa a MESMA ação
                 'cancelar_venda'/etapa 'cancelada' de sempre (nunca inventa
                 uma etapa nova só pra isso) — mudarEtapaVenda() já cancela
                 as parcelas futuras pendentes (includes/financeiro.php::
                 finCancelarLancamentosPendentesVenda()) e, uma vez
                 'cancelada', o veículo passa a bater de novo no critério de
                 "disponível" das duas funções acima, sem precisar de
                 nenhuma mudança nelas. -->
            <form method="post" onsubmit="return confirmarAcao(this, 'Registrar devolução deste veículo? O comprador devolveu o carro — as parcelas futuras ainda pendentes serão canceladas no financeiro (o que já foi pago continua como receita), e o veículo volta a ficar disponível pra uma nova venda.');">
                <?= csrfField() ?>
                <input type="hidden" name="acao" value="cancelar_venda">
                <label>Motivo da devolução (obrigatório)</label>
                <input type="text" name="motivo" required placeholder="Ex: comprador devolveu o veículo, inadimplência...">
                <button type="submit" class="perigo">🔙 Registrar devolução do veículo</button>
            </form>
        <?php elseif (in_array($v['etapa'], ['whatsapp', 'qualificacao_ia'], true)): ?>
            <p>🤖 Ainda em qualificação pela IA (<?= e(etapaVendaLabel($v['etapa'])) ?>) — assim que terminar, vira negociação
               automaticamente e aparece aqui pra mudar de etapa.</p>
        <?php else: ?>
            <p>Negociação encerrada — <?= e(etapaVendaLabel($v['etapa'])) ?><?= $v['motivo_cancelamento'] ? ': ' . e($v['motivo_cancelamento']) : ($v['motivo_perda'] ? ': ' . e($v['motivo_perda']) : '') ?>.</p>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <h3>Histórico de etapas</h3>
    <?php if (!$historico): ?>
        <p><small>Sem histórico ainda.</small></p>
    <?php endif; ?>
    <?php foreach ($historico as $h): ?>
        <div class="historico-item">
            <div class="quando"><?= date('d/m/Y H:i', strtotime($h['created_at'])) ?> — <?= e($h['responsavel_nome'] ?? 'sistema') ?></div>
            <?= e($h['etapa_anterior'] ?: '(criação)') ?> → <strong><?= e(etapaVendaLabel($h['etapa_nova'])) ?></strong>
            <?= $h['observacao'] ? '— ' . e($h['observacao']) : '' ?>
        </div>
    <?php endforeach; ?>
</div>

<?php if ($v['oportunidade_id']): ?>
<div class="card">
    <h3>🔍 Checklist de vistoria do veículo</h3>
    <?php if ($avaliacoesVeiculo): ?>
        <table>
            <thead><tr><th>Tipo</th><th>Status</th><th>Condição</th><th>Avaliador</th><th>Criada em</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($avaliacoesVeiculo as $a):
                $scoreLinha = veiculoAvaliacaoScore(listarItensAvaliacao((int)$a['id']));
            ?>
                <tr>
                    <td><?= $a['tipo'] === 'venda' ? '🛒 Venda' : '🚗 Compra' ?></td>
                    <td><?= match ($a['status']) { 'concluida' => '✅ Concluída', 'em_andamento' => '🔧 Em andamento', default => '⏳ Pendente' } ?></td>
                    <td>
                        <?php if ($scoreLinha['percentual'] !== null): ?>
                            <span class="badge <?= veiculoAvaliacaoClasseBadgeScore($scoreLinha['percentual']) ?>" title="<?= e($scoreLinha['resumo']) ?>"><?= (int)$scoreLinha['percentual'] ?>/100</span>
                            <br><small><?= e(veiculoAvaliacaoSugestaoRevenda($scoreLinha['percentual'])) ?></small>
                            <?php if ($scoreLinha['problema'] > 0): ?><br><small>⚠️ <?= (int)$scoreLinha['problema'] ?> item(ns) com ressalva</small><?php endif; ?>
                        <?php elseif ($a['status'] === 'concluida'): ?>
                            <small title="<?= e($scoreLinha['resumo']) ?>">⚠️ concluída sem item verificado</small>
                        <?php else: ?>
                            <small>—</small>
                        <?php endif; ?>
                    </td>
                    <td><?= $a['avaliador_nome'] ? e($a['avaliador_nome']) : '<em>não atribuído</em>' ?></td>
                    <td><?= date('d/m/Y H:i', strtotime($a['created_at'])) ?></td>
                    <td><a href="/admin/avaliacao.php?id=<?= (int)$a['id'] ?>">Abrir →</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <p><small>Nenhuma vistoria registrada ainda pra este veículo.</small></p>
    <?php endif; ?>
    <?php if ($_SESSION['admin_perfil'] !== 'supervisor'): ?>
        <form method="post" style="margin-top:10px">
            <?= csrfField() ?>
            <input type="hidden" name="acao" value="criar_avaliacao">
            <label>Tipo de veículo</label>
            <select name="tipo_veiculo" required>
                <option value="carro">🚗 Carro</option>
                <option value="moto">🏍️ Moto</option>
            </select>
            <?php if ($avaliadoresDisponiveis): ?>
            <label>Atribuir a</label>
            <select name="avaliador_id" required>
                <option value="">— escolha um avaliador —</option>
                <?php foreach ($avaliadoresDisponiveis as $u): ?>
                    <option value="<?= (int)$u['id'] ?>"><?= e($u['nome']) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit">+ Nova vistoria de entrega ao comprador</button>
            <?php else: ?>
            <p><small>⚠️ Nenhum avaliador cadastrado ainda — cadastre um usuário com perfil Avaliador em Usuários antes de criar a vistoria.</small></p>
            <?php endif; ?>
        </form>
    <?php endif; ?>
</div>
<?php endif; ?>

</main>
<script>
// 26/09/2026, "permita copia link para enviar sem depender da instância
// pois estamos com problemas" — mesmo padrão de admin/oportunidade.php:
// botão "📋 Copiar link" ao lado de todo link que hoje só sai automático
// pelo Z-API (wizard de documentos, assinatura do contrato), nunca depende
// de nenhuma instância, sempre funciona pro consultor/vendedor mandar
// manualmente pelo próprio WhatsApp se a instância estiver fora do ar.
function copiarTexto(texto, btn) {
    var original = btn.textContent;
    function marcarCopiado() {
        btn.textContent = '✅ Copiado!';
        setTimeout(function () { btn.textContent = original; }, 2000);
    }
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(texto).then(marcarCopiado).catch(function () { copiarTextoFallback(texto, marcarCopiado); });
    } else {
        copiarTextoFallback(texto, marcarCopiado);
    }
}
function copiarTextoFallback(texto, callback) {
    var ta = document.createElement('textarea');
    ta.value = texto;
    ta.style.position = 'fixed';
    ta.style.left = '-9999px';
    document.body.appendChild(ta);
    ta.select();
    try { document.execCommand('copy'); callback(); } catch (e) {}
    document.body.removeChild(ta);
}
</script>
<?php include __DIR__ . '/_pwa_register.php'; ?>
<?php include __DIR__ . '/_notify.php'; ?>
<?php include __DIR__ . '/_scroll_restore.php'; ?>
<?php include __DIR__ . '/_acao_popup.php'; ?>
<?php include __DIR__ . '/_confirm_dialog.php'; ?>
<?php include __DIR__ . '/_zapi_status.php'; ?>
</body>
</html>
