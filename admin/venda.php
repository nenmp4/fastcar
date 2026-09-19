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
                    $resultadoMidia = salvarMidiaRevenda((int)$v['oportunidade_id'], $_FILES['midia'] ?? [], (string)($_POST['legenda'] ?? ''));
                    if ($resultadoMidia['ok']) {
                        $sucesso = 'Mídia adicionada ao catálogo do veículo.';
                    } else {
                        $erro = $resultadoMidia['erro'];
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
                $db->prepare("
                    UPDATE vendas
                    SET km_entrega = ?, preco_venda = ?, valor_pago_contratacao = ?, forma_pagamento = ?,
                        saldo_preco_devido = ?, prazo_quitacao_meses = ?, data_limite_quitacao = ?,
                        prestacao_contas_texto = ?, seguro_texto = ?, ipva_responsavel_texto = ?, multas_texto = ?,
                        rastreador_texto = ?, prazo_transferencia_dias = ?, penalidade_atraso_texto = ?,
                        updated_at = datetime('now','localtime')
                    WHERE id = ?
                ")->execute([
                    $_POST['km_entrega'] !== '' ? (int)$_POST['km_entrega'] : null,
                    $_POST['preco_venda'] !== '' ? (float)$_POST['preco_venda'] : null,
                    $_POST['valor_pago_contratacao'] !== '' ? (float)$_POST['valor_pago_contratacao'] : null,
                    clean((string)($_POST['forma_pagamento'] ?? '')),
                    $_POST['saldo_preco_devido'] !== '' ? (float)$_POST['saldo_preco_devido'] : null,
                    $_POST['prazo_quitacao_meses'] !== '' ? min(24, (int)$_POST['prazo_quitacao_meses']) : 24,
                    $_POST['data_limite_quitacao'] !== '' ? (string)$_POST['data_limite_quitacao'] : null,
                    clean((string)($_POST['prestacao_contas_texto'] ?? '')),
                    clean((string)($_POST['seguro_texto'] ?? '')),
                    clean((string)($_POST['ipva_responsavel_texto'] ?? '')),
                    clean((string)($_POST['multas_texto'] ?? '')),
                    clean((string)($_POST['rastreador_texto'] ?? '')),
                    $_POST['prazo_transferencia_dias'] !== '' ? (int)$_POST['prazo_transferencia_dias'] : null,
                    clean((string)($_POST['penalidade_atraso_texto'] ?? '')),
                    $id,
                ]);
                $sucesso = 'Condições da venda atualizadas.';
            } elseif ($acao === 'gerar_parcelamento') {
                // Fastcar vende veículo da frota financiado pro comprador —
                // entrada + parcelas (pedido José/Jean, 17/09/2026). Cobra
                // de verdade via Asaas quando o comprador já tem cliente
                // Asaas vinculado (ver admin/financeiro-asaas.php) e a
                // integração está configurada; senão gera só o registro
                // LOCAL no financeiro (finGerarPlanoParcelamentoVenda) —
                // nunca bloqueia a operação por falta de Asaas.
                $valorEntrada = (float)str_replace(',', '.', preg_replace('/[^\d,.-]/', '', (string)($_POST['valor_entrada'] ?? '0')));
                $numParcelas = (int)($_POST['num_parcelas'] ?? 0);
                $valorParcela = (float)str_replace(',', '.', preg_replace('/[^\d,.-]/', '', (string)($_POST['valor_parcela'] ?? '0')));
                $primeiraParcela = (string)($_POST['primeira_parcela_data'] ?? '');
                $usarAsaas = !empty($_POST['usar_asaas']) && asaasConfigured();

                if (!$primeiraParcela) {
                    $erro = 'Informe a data de vencimento da 1ª parcela.';
                } elseif ($usarAsaas) {
                    $asaasCustomerId = asaasCriarClienteSeNecessario((string)$v['comprador_nome'], (string)$v['comprador_cpf'], (string)$v['comprador_telefone'], (string)$v['comprador_email']);
                    if (!$asaasCustomerId) {
                        $erro = 'Não foi possível criar/localizar o cliente no Asaas — confira os dados do comprador (nome/CPF) e a chave da API.';
                    } else {
                        $r = asaasGerarCobrancaParceladaVenda($id, $asaasCustomerId, $valorParcela, $numParcelas, $primeiraParcela, "Venda #{$id} — " . trim((string)$v['veiculo_marca'] . ' ' . $v['veiculo_modelo']));
                        if ($r['ok']) {
                            $sucesso = "Cobrança parcelada criada no Asaas — {$r['criadas']} parcela(s).";
                        } else {
                            $erro = 'Falha ao gerar cobrança no Asaas: ' . $r['erro'];
                        }
                    }
                } else {
                    $r = finGerarPlanoParcelamentoVenda($id, $valorEntrada, $numParcelas, $valorParcela, $primeiraParcela, null, (string)$v['comprador_nome'], (int)$_SESSION['admin_id']);
                    if ($r['ok']) {
                        $sucesso = "Plano de parcelamento gerado no financeiro — {$r['criadas']} lançamento(s).";
                    } else {
                        $erro = $r['erro'];
                    }
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
<?php include __DIR__ . '/_pwa_head.php'; ?>
</head>
<body>
<header class="topbar">
    <a href="/admin/vendas.php" style="color:#fff">← Vendas</a>
    <strong><img class="topbar-logo" src="/admin/assets/img/icon-192.png" alt="Fastcar" onerror="this.style.display='none'"> Fast<b>Car</b></strong>
    <span>Olá, <?= e($_SESSION['admin_nome']) ?></span>
    <a href="/admin/logout.php">Sair</a>
</header>

<main>
<?php if ($erro): ?><div class="alerta-erro"><?= e($erro) ?></div><?php endif; ?>
<?php if ($sucesso): ?><div class="alerta-sucesso"><?= e($sucesso) ?></div><?php endif; ?>

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
                        <form method="post" onsubmit="return confirm('Remover essa mídia do catálogo?');" style="margin-top:4px">
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
        <form method="post" enctype="multipart/form-data">
            <?= csrfField() ?>
            <input type="hidden" name="acao" value="upload_midia_revenda">
            <label>Arquivo (foto JPG/PNG/WEBP até 10MB, ou vídeo MP4/MOV/WEBM até 50MB)</label>
            <input type="file" name="midia" accept="image/jpeg,image/png,image/webp,video/mp4,video/quicktime,video/webm" required>
            <label>Legenda (opcional)</label>
            <input type="text" name="legenda" placeholder="Ex: Lateral direita, km atual 42.000">
            <button type="submit">Adicionar ao catálogo</button>
        </form>
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
        <code style="font-size:12px;word-break:break-all"><?= e($linkDocumentosVenda ?: '(gerado ao clicar em enviar)') ?></code><br>
        <form method="post" class="inline" style="display:inline-block;margin-top:8px">
            <?= csrfField() ?>
            <input type="hidden" name="acao" value="enviar_link_documentos_venda">
            <button type="submit" style="margin-top:0">Enviar link por WhatsApp</button>
        </form>
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
                            <form method="post" class="inline" style="margin-top:4px" onsubmit="return confirm('Confirmar que já revisou os dados desse documento em nome do comprador?');">
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
                <label>Valor pago pelo comprador na contratação (R$)</label>
                <input type="number" step="0.01" name="valor_pago_contratacao" value="<?= e((string)($v['valor_pago_contratacao'] ?? '')) ?>">
                <label>Forma de pagamento</label>
                <input type="text" name="forma_pagamento" value="<?= e($v['forma_pagamento'] ?? '') ?>" placeholder="À vista, financiado, entrada + parcelas...">
                <label>Saldo de preço devido pelo comprador (R$) — deixe em branco se inexistente</label>
                <input type="number" step="0.01" name="saldo_preco_devido" value="<?= e((string)($v['saldo_preco_devido'] ?? '')) ?>">
                <label>Prazo máximo pra quitação do financiamento (meses, até 24)</label>
                <input type="number" max="24" name="prazo_quitacao_meses" value="<?= e((string)($v['prazo_quitacao_meses'] ?? 24)) ?>">
                <label>Data-limite objetiva</label>
                <input type="date" name="data_limite_quitacao" value="<?= e($v['data_limite_quitacao'] ?? '') ?>">
            </div>
            <div>
                <label>Prestação de contas de andamento</label>
                <input type="text" name="prestacao_contas_texto" value="<?= e($v['prestacao_contas_texto'] ?? '') ?>" placeholder="Ex: a cada 3 meses">
                <label>Seguro/proteção durante o período intermediário</label>
                <input type="text" name="seguro_texto" value="<?= e($v['seguro_texto'] ?? '') ?>">
                <label>IPVA/licenciamento após entrega</label>
                <input type="text" name="ipva_responsavel_texto" value="<?= e($v['ipva_responsavel_texto'] ?? '') ?>">
                <label>Multas após entrega</label>
                <input type="text" name="multas_texto" value="<?= e($v['multas_texto'] ?? '') ?>">
                <label>Rastreador</label>
                <input type="text" name="rastreador_texto" value="<?= e($v['rastreador_texto'] ?? '') ?>" placeholder="Sim, regras no Anexo VIII / Não">
                <label>Prazo pra transferência após baixa (dias úteis)</label>
                <input type="number" name="prazo_transferencia_dias" value="<?= e((string)($v['prazo_transferencia_dias'] ?? '')) ?>">
                <label>Penalidade por atraso imputável à Fastcar</label>
                <input type="text" name="penalidade_atraso_texto" value="<?= e($v['penalidade_atraso_texto'] ?? '') ?>">
            </div>
        </div>
        <button type="submit">Salvar condições da venda</button>
    </form>

    <p><small>✍️ Testemunhas do contrato são fixas (sempre da própria Fastcar) — configura em
       <a href="/admin/configuracoes.php">Configurações</a>, não muda por venda.</small></p>

    <?php if (in_array($v['etapa'], ['negociacao', 'contrato_enviado'], true) && $v['oportunidade_id']): ?>
        <hr>
        <form method="post" style="display:inline-block;margin-right:8px">
            <?= csrfField() ?>
            <input type="hidden" name="acao" value="gerar_contrato_preview">
            <button type="submit" class="secundario">👁️ Gerar contrato (só visualizar)</button>
        </form>
        <form method="post" style="display:inline-block" onsubmit="return confirm('Gerar o contrato de venda e enviar pra assinatura eletrônica?');">
            <?= csrfField() ?>
            <input type="hidden" name="acao" value="gerar_contrato">
            <button type="submit">📄 Gerar contrato e enviar pra assinatura</button>
        </form>
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
                            $badgeClasse = ['assinado' => 'badge-ok', 'recusado' => 'badge-atraso', 'erro' => 'badge-atraso'][$ct['status']] ?? '';
                            $badgeIcone = ['gerado' => '📄', 'enviado' => '📤', 'visualizado' => '👀', 'assinado' => '✅', 'recusado' => '❌', 'erro' => '⚠️'][$ct['status']] ?? '';
                        ?>
                        <span class="badge <?= $badgeClasse ?>"><?= $badgeIcone ?> <?= e($ct['status']) ?></span>
                    </td>
                    <td><?= date('d/m/Y H:i', strtotime($ct['created_at'])) ?></td>
                    <td>
                        <?php if ($ct['drive_file_id'] || $ct['arquivo_url']): ?>
                            <a href="/admin/ver_contrato.php?id=<?= (int)$ct['id'] ?>" target="_blank">ver PDF</a>
                        <?php endif; ?>
                        <?php if ($ct['sign_url'] && $ct['status'] !== 'assinado'): ?>
                            · <a href="<?= e($ct['sign_url']) ?>" target="_blank">link de assinatura</a>
                        <?php endif; ?>
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
    <?php $lancamentosVenda = finListarLancamentosVenda($id); ?>
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
    <?php elseif (!$v['oportunidade_id']): ?>
        <p><small>⏳ Vincule um veículo da frota antes de gerar o parcelamento.</small></p>
    <?php else: ?>
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
            <?php if (asaasConfigured()): ?>
                <label><input type="checkbox" name="usar_asaas" value="1" checked style="width:auto;display:inline-block"> Cobrar de verdade pelo Asaas (cria cliente + cobrança parcelada lá)</label>
            <?php else: ?>
                <p><small>ℹ️ Asaas não configurado — o plano fica só registrado no financeiro do CRM, sem cobrar de verdade.</small></p>
            <?php endif; ?>
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
            <form method="post" onsubmit="return confirm('Cancelar esta negociação? O veículo volta a ficar disponível pra uma nova venda.');">
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
            <form method="post" onsubmit="return confirm('Registrar devolução deste veículo? O comprador devolveu o carro — as parcelas futuras ainda pendentes serão canceladas no financeiro (o que já foi pago continua como receita), e o veículo volta a ficar disponível pra uma nova venda.');">
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

</main>
<?php include __DIR__ . '/_pwa_register.php'; ?>
<?php include __DIR__ . '/_notify.php'; ?>
</body>
</html>
