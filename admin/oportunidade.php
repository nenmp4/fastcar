<?php
/**
 * Detalhe da oportunidade — histórico de etapas, conversa do WhatsApp,
 * resumo da IA e ações do consultor (próxima ação, mudar etapa,
 * marcar perdida). Toda mudança de etapa passa por mudarEtapa()/
 * marcarPerdida() (includes/oportunidades.php) — nunca UPDATE direto.
 */

require_once __DIR__ . '/_bootstrap.php';

$db = getDB();
$id = (int)($_GET['id'] ?? 0);

$stmtOp = $db->prepare("
    SELECT o.*, c.nome AS cliente_nome, c.telefone AS cliente_telefone, c.email AS cliente_email,
           c.cidade, c.estado, c.canal_origem, c.campanha_origem, c.anuncio_origem
    FROM oportunidades o
    JOIN clientes c ON c.id = o.cliente_id
    WHERE o.id = ?
");
$stmtOp->execute([$id]);
$op = $stmtOp->fetch();

if (!$op) {
    http_response_code(404);
    exit('Oportunidade não encontrada.');
}

$erro = '';
$sucesso = '';
$marcaFeedback = null; // resultado de fipeValidarMarca() após salvar dados do veículo

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        $erro = 'Sessão expirada, recarregue a página e tente de novo.';
    } elseif ($_SESSION['admin_perfil'] === 'supervisor') {
        // Perfil de acompanhamento (15/09/2026, pedido José/Jean: "preciso
        // ter perfil de supervisão que vai acompanhar tudo que consultores
        // está fazendo") — vê tudo, mas não age em nome de ninguém.
        http_response_code(403);
        $erro = 'Perfil de supervisão só acompanha, não altera oportunidades.';
    } else {
        $acao = (string)($_POST['acao'] ?? '');
        try {
            if ($acao === 'atualizar_veiculo') {
                $marca = clean((string)($_POST['veiculo_marca'] ?? ''));
                $db->prepare("
                    UPDATE oportunidades
                    SET veiculo_marca = ?, veiculo_modelo = ?, veiculo_ano = ?, veiculo_placa = ?,
                        veiculo_renavam = ?, veiculo_chassi = ?, banco_financiamento = ?,
                        valor_parcela = ?, parcelas_restantes = ?, parcelas_atraso = ?, valor_pretendido = ?,
                        debito_ipva = ?, debito_licenciamento = ?, debito_multas = ?,
                        updated_at = datetime('now','localtime')
                    WHERE id = ?
                ")->execute([
                    $marca,
                    clean((string)($_POST['veiculo_modelo'] ?? '')),
                    clean((string)($_POST['veiculo_ano'] ?? '')),
                    clean((string)($_POST['veiculo_placa'] ?? '')),
                    clean((string)($_POST['veiculo_renavam'] ?? '')),
                    clean((string)($_POST['veiculo_chassi'] ?? '')),
                    clean((string)($_POST['banco_financiamento'] ?? '')),
                    $_POST['valor_parcela'] !== '' ? (float)$_POST['valor_parcela'] : null,
                    $_POST['parcelas_restantes'] !== '' ? (int)$_POST['parcelas_restantes'] : null,
                    $_POST['parcelas_atraso'] !== '' ? (int)$_POST['parcelas_atraso'] : 0,
                    $_POST['valor_pretendido'] !== '' ? (float)$_POST['valor_pretendido'] : null,
                    ($_POST['debito_ipva'] ?? '') !== '' ? (float)$_POST['debito_ipva'] : null,
                    ($_POST['debito_licenciamento'] ?? '') !== '' ? (float)$_POST['debito_licenciamento'] : null,
                    ($_POST['debito_multas'] ?? '') !== '' ? (float)$_POST['debito_multas'] : null,
                    $id,
                ]);
                // Só um sinal visual pro consultor — nunca sobrescreve o que
                // foi digitado (regra do Jean: não inventar/corrigir por
                // conta própria), a marca salva acima é sempre a literal.
                if ($marca !== '') {
                    $marcaFeedback = fipeValidarMarca($marca);
                }
                $sucesso = 'Dados do veículo atualizados.';
            } elseif ($acao === 'atualizar_contrato') {
                // Testemunhas saíram daqui em 13/09/2026 — são sempre da
                // própria Fastcar, fixas em Configurações
                // (admin/configuracoes.php), não mais por oportunidade.
                $db->prepare("
                    UPDATE oportunidades
                    SET valor_fipe_referencia = ?, valor_ofertado = ?, contrato_financiamento_numero = ?,
                        saldo_financiamento_atual = ?, terceiro_quitacao = ?, seguro_texto = ?, encargos_texto = ?,
                        data_entrega_posse = ?, prazo_quitacao_meses = ?, updated_at = datetime('now','localtime')
                    WHERE id = ?
                ")->execute([
                    $_POST['valor_fipe_referencia'] !== '' ? (float)$_POST['valor_fipe_referencia'] : null,
                    $_POST['valor_ofertado'] !== '' ? (float)$_POST['valor_ofertado'] : null,
                    clean((string)($_POST['contrato_financiamento_numero'] ?? '')),
                    $_POST['saldo_financiamento_atual'] !== '' ? (float)$_POST['saldo_financiamento_atual'] : null,
                    clean((string)($_POST['terceiro_quitacao'] ?? '')),
                    clean((string)($_POST['seguro_texto'] ?? '')),
                    clean((string)($_POST['encargos_texto'] ?? '')),
                    $_POST['data_entrega_posse'] !== '' ? (string)$_POST['data_entrega_posse'] : null,
                    // Nunca mais que 24 meses (limite contratual, cláusula
                    // 5ª/1.3) — travado no servidor, não só no max="24" do
                    // input, mesmo padrão já usado pro prazo do contrato de
                    // venda (admin/venda.php).
                    $_POST['prazo_quitacao_meses'] !== '' ? min(24, max(1, (int)$_POST['prazo_quitacao_meses'])) : null,
                    $id,
                ]);
                $sucesso = 'Dados do contrato atualizados.';
            } elseif ($acao === 'gerar_contrato') {
                $resultadoContrato = gerarEEnviarContratoCompra($id, (int)$_SESSION['admin_id']);
                if ($resultadoContrato['ok']) {
                    $sucesso = 'Contrato gerado e enviado pra assinatura.' . ($resultadoContrato['aviso'] ? ' ⚠️ ' . $resultadoContrato['aviso'] : '');
                } else {
                    $erro = $resultadoContrato['erro'];
                }
            } elseif ($acao === 'gerar_contrato_preview') {
                $resultadoContrato = gerarContratoCompraPreview($id, (int)$_SESSION['admin_id']);
                if ($resultadoContrato['ok']) {
                    $sucesso = 'Rascunho do contrato gerado — confira os dados antes de enviar pra assinatura.'
                        . ($resultadoContrato['aviso'] ? ' ⚠️ ' . $resultadoContrato['aviso'] : '');
                } else {
                    $erro = $resultadoContrato['erro'];
                }
            } elseif ($acao === 'atualizar_proxima_acao') {
                $db->prepare("
                    UPDATE oportunidades
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
                mudarEtapa($id, (string)$_POST['etapa_nova'], (int)$_SESSION['admin_id'], clean((string)($_POST['observacao'] ?? '')));
                $sucesso = 'Etapa atualizada.';
            } elseif ($acao === 'marcar_perdida') {
                marcarPerdida($id, clean((string)($_POST['motivo'] ?? '')), (int)$_SESSION['admin_id'], !empty($_POST['sem_perfil']));
                $sucesso = 'Oportunidade encerrada.';
            } elseif ($acao === 'enviar_link_documentos') {
                $token = getOuCriarTokenDocumentos($id);
                $baseUrl = getConfig('app_base_url') ?: (($_SERVER['HTTPS'] ?? '') === 'on' ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'];
                $link = rtrim($baseUrl, '/') . '/public/documentos.php?token=' . $token;
                $msg = "Olá! Pra continuar a avaliação do seu veículo, preencha seus dados e envie os documentos por aqui:\n{$link}";

                // Manda com a logo como capa (mais confiança/profissional do
                // que texto puro, pedido do José/Jean, 13/09/2026 — mesma
                // preocupação de "isso não é golpe?" do rodapé com endereço
                // real) sempre que já tiver logo enviada em Configurações;
                // sem logo ainda, cai no texto puro de sempre.
                $enviado = marcaLogoConfigurada()
                    ? zapiEnviarImagem($op['cliente_telefone'], rtrim($baseUrl, '/') . '/public/assets/logo.png', $msg)
                    : zapiEnviarTexto($op['cliente_telefone'], $msg);

                if ($enviado) {
                    $sucesso = 'Link enviado por WhatsApp.';
                } else {
                    $erro = "Não deu pra enviar por WhatsApp (confira as credenciais em Configurações). Link: {$link}";
                }

                // Cópia por e-mail (16/09/2026, "cria todos os templates")
                // — só se o cliente já tiver e-mail cadastrado; nunca troca
                // nem depende do resultado do envio por WhatsApp acima,
                // são canais independentes.
                if (!empty($op['cliente_email'])) {
                    $corpoEmail = "<p>Olá, " . e($op['cliente_nome'] ?: '') . "!</p>"
                        . "<p>Pra continuar a avaliação do seu veículo, preencha seus dados e envie os documentos pelo link abaixo:</p>"
                        . emailBotao('Enviar documentos', $link)
                        . "<p style=\"font-size:12.5px;color:#6b7280\">Se o botão não funcionar, copie e cole este link no navegador:<br>"
                        . "<a href=\"" . e($link) . "\" style=\"color:#2f6fed\">" . e($link) . "</a></p>";
                    enviarEmail($op['cliente_email'], 'Fastcar — envio de documentos', emailLayout($corpoEmail), $op['cliente_nome'] ?: '');
                }
            } elseif ($acao === 'upload_documento_staff') {
                $tipoDoc = (string)($_POST['tipo_documento'] ?? '');
                $tiposValidos = array_keys(TIPOS_DOCUMENTOS_CLIENTE + TIPOS_DOCUMENTOS_FECHAMENTO);
                if (!in_array($tipoDoc, $tiposValidos, true)) {
                    $erro = 'Tipo de documento inválido.';
                } elseif (empty($_FILES['arquivo']) || ($_FILES['arquivo']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                    $erro = 'Selecione um arquivo pra enviar.';
                } else {
                    $resultado = salvarUploadDocumento($id, $tipoDoc, $_FILES['arquivo'], false);
                    if ($resultado['ok']) {
                        $sucesso = 'Documento anexado.';
                        // Mesma extração por IA do wizard público (includes/extracao_documentos.php)
                        // — anexado pelo consultor (ex: foto que o cliente mandou no
                        // WhatsApp) já sai pré-preenchido, o cliente só confirma
                        // depois pelo link. Só se aplica aos 3 tipos que o cliente
                        // preenche — docs da pasta fechada não têm dado pra extrair.
                        if (isset(TIPOS_DOCUMENTOS_CLIENTE[$tipoDoc])) {
                            $docSalvo = listarDocumentos($id)[$tipoDoc] ?? null;
                            $arquivoLido = $docSalvo ? lerConteudoArquivoDocumento($docSalvo['drive_file_id'] ?: null, $docSalvo['arquivo_url'] ?: null) : null;
                            if ($arquivoLido) {
                                $dadosExtraidos = extrairDadosDocumentoComIA($tipoDoc, $arquivoLido);
                                // 17/09/2026, achado real ("consultor subiu
                                // documento do carro no lugar da cnh"): se a
                                // IA identificou que o arquivo NÃO é o tipo
                                // esperado pro slot (ex: CRLV no lugar da
                                // CNH), nunca aplica os campos extraídos —
                                // um documento errado podia "achar" um nome
                                // de pessoa (ex: dono anterior do carro no
                                // CRLV) e preencher o cadastro errado via
                                // fill-if-empty, sem ninguém perceber. O
                                // arquivo já foi salvo (dá pra ver/substituir
                                // reenviando o mesmo tipo), só os dados não
                                // são aplicados — vira aviso forte em vez do
                                // sucesso mudo de sempre.
                                if ($dadosExtraidos && !$dadosExtraidos['_documento_correto']) {
                                    $tipoPercebido = $dadosExtraidos['_tipo_real_se_diferente'] ?: 'outro tipo de documento';
                                    $sucesso = '';
                                    $erro = 'Anexado, mas esse arquivo não parece ser ' . (TIPOS_DOCUMENTOS_CLIENTE[$tipoDoc] ?? $tipoDoc)
                                        . ' — parece ser ' . $tipoPercebido . '. Confira e anexe o arquivo certo (os dados não foram preenchidos automaticamente).';
                                } elseif ($dadosExtraidos) {
                                    aplicarDadosExtraidosDocumento((int)$op['cliente_id'], $id, $tipoDoc, $dadosExtraidos);
                                }
                            }
                        }
                    } else {
                        $erro = $resultado['erro'] ?? 'Falha ao anexar documento.';
                    }
                }
            } elseif ($acao === 'confirmar_documento_staff') {
                // "Aceite em nome do cliente" (17/09/2026, achado real —
                // José: "tem cliente tem dificuldade de preencher o
                // wirzad - proprio consultor sobe os documentos ja da
                // aceite... maioria das vezes consultor sobe a
                // documentação"): quando o cliente não consegue/não usa o
                // wizard, o documento fica preso pra sempre em "📝 enviado,
                // aguardando cliente confirmar dados" — só o wizard
                // (public/documentos.php::confirmar_etapa) escrevia
                // dados_confirmados=1, e só o CLIENTE tinha acesso a essa
                // ação. Isso nunca bloqueava o fechamento de verdade
                // (checklistFechamentoCompleto() só olha se o arquivo
                // existe, não dados_confirmados — conferido antes de
                // implementar), mas deixava a tela mostrando um alarme
                // permanente e enganoso pra um documento que o consultor já
                // conferiu de verdade lendo a foto que o cliente mandou no
                // WhatsApp. Os DADOS extraídos (marca/modelo/banco/etc) já
                // são editáveis direto pelos cards "Dados do veículo"/
                // "Financiamento" desta mesma tela — esta ação só marca
                // "revisei, tá certo", nunca reescreve nenhum campo sozinha.
                $tipoDocConfirmar = (string)($_POST['tipo_documento'] ?? '');
                if (!isset(TIPOS_DOCUMENTOS_CLIENTE[$tipoDocConfirmar])) {
                    $erro = 'Tipo de documento inválido.';
                } else {
                    $db->prepare("
                        UPDATE oportunidade_documentos SET dados_confirmados = 1, updated_at = datetime('now','localtime')
                        WHERE oportunidade_id = ? AND tipo = ?
                    ")->execute([$id, $tipoDocConfirmar]);

                    // Mesmo sinal "tudo revisado" que o wizard grava ao
                    // finalizar (confirmar_final) — só quando TODOS os tipos
                    // de documento do cliente já estão com dados_confirmados=1,
                    // pra não marcar concluído cedo demais.
                    $tiposCliente = array_keys(TIPOS_DOCUMENTOS_CLIENTE);
                    $ph = implode(',', array_fill(0, count($tiposCliente), '?'));
                    $stmtPendentes = $db->prepare("
                        SELECT COUNT(*) FROM oportunidade_documentos
                        WHERE oportunidade_id = ? AND tipo IN ({$ph}) AND dados_confirmados = 0
                    ");
                    $stmtPendentes->execute([$id, ...$tiposCliente]);
                    if ((int)$stmtPendentes->fetchColumn() === 0) {
                        $db->prepare("UPDATE oportunidades SET documentos_confirmados_em = datetime('now','localtime') WHERE id = ?")->execute([$id]);
                    }

                    $sucesso = 'Documento confirmado em nome do cliente.';
                }
            } elseif ($acao === 'atualizar_nome_cliente') {
                $novoNome = trim((string)($_POST['nome_cliente'] ?? ''));
                if ($novoNome === '') {
                    $erro = 'Nome não pode ficar vazio.';
                } else {
                    $db->prepare("UPDATE clientes SET nome = ? WHERE id = ?")->execute([clean($novoNome), $op['cliente_id']]);
                    $sucesso = 'Nome do cliente atualizado.';
                }
            } elseif ($acao === 'criar_pendencia_pos_venda') {
                $descricaoPendencia = trim((string)($_POST['descricao'] ?? ''));
                if ($op['etapa'] !== 'fechado') {
                    $erro = 'Pendência pós-venda só pode ser criada numa pasta já fechada.';
                } elseif ($descricaoPendencia === '') {
                    $erro = 'Descreva a pendência.';
                } else {
                    $respPendencia = (int)($_POST['responsavel_id'] ?? 0) ?: null;
                    criarPendenciaPosVenda($id, clean($descricaoPendencia), (string)($_POST['prazo_estimado'] ?? '') ?: null, $respPendencia);
                    $sucesso = 'Pendência pós-venda registrada.';
                }
            } elseif ($acao === 'concluir_pendencia_pos_venda') {
                concluirPendenciaPosVenda((int)($_POST['pendencia_id'] ?? 0));
                $sucesso = 'Pendência marcada como concluída.';
            } elseif ($acao === 'reabrir_pendencia_pos_venda') {
                reabrirPendenciaPosVenda((int)($_POST['pendencia_id'] ?? 0));
                $sucesso = 'Pendência reaberta.';
            } elseif ($acao === 'criar_avaliacao') {
                // Checklist de vistoria (compra) — includes/veiculo_avaliacoes.php.
                // Consultor responsável já pode escolher um avaliador na hora
                // de criar (mesmo padrão "responsável" do resto do projeto).
                // 22/09/2026, "avaliação tem ter opção de moto carro" —
                // tipo de veículo escolhido aqui, checklist muda de verdade
                // conforme a escolha; nunca derivado sozinho (oportunidades
                // não guarda tipo de veículo hoje).
                $novoAvaliadorId = (int)($_POST['avaliador_id'] ?? 0) ?: null;
                $tipoVeiculoAvaliacao = ($_POST['tipo_veiculo'] ?? 'carro') === 'moto' ? 'moto' : 'carro';
                $novaAvaliacaoId = criarAvaliacao($id, 'compra', null, $novoAvaliadorId, (int)$_SESSION['admin_id'], $tipoVeiculoAvaliacao);
                header('Location: /admin/avaliacao.php?id=' . $novaAvaliacaoId);
                exit;
            }
        } catch (Throwable $e) {
            $erro = $e->getMessage();
        }
        // Recarrega os dados após a ação, sucesso ou erro.
        $stmtOp->execute([$id]);
        $op = $stmtOp->fetch();
    }
}

$stmtHist = $db->prepare("
    SELECT h.*, u.nome AS responsavel_nome
    FROM oportunidade_historico h
    LEFT JOIN usuarios u ON u.id = h.responsavel_id
    WHERE h.oportunidade_id = ?
    ORDER BY h.id DESC
");
$stmtHist->execute([$id]);
$historico = $stmtHist->fetchAll();

// LEFT JOIN usuarios pra mostrar por qual canal cada mensagem passou —
// NULL = instância principal (bot/IA/followup), preenchido = instância
// própria de um consultor (atendimento, sempre a partir do bloco 5).
$stmtMsg = $db->prepare("
    SELECT m.*, u.nome AS usuario_nome
    FROM whatsapp_mensagens m
    LEFT JOIN usuarios u ON u.id = m.usuario_id
    WHERE m.telefone = ? ORDER BY m.id DESC LIMIT 50
");
$stmtMsg->execute([$op['cliente_telefone']]);
$mensagens = array_reverse($stmtMsg->fetchAll());

$usuarios = listarUsuarios();
$avaliadoresDisponiveis = array_values(array_filter($usuarios, fn($u) => $u['perfil'] === 'avaliador'));
$avaliacoesVeiculo = listarAvaliacoesDoVeiculo($id);
$etapasFechaveis = array_merge(ETAPAS_ATIVAS, ['fechado']);
$pendenciasPosVenda = $op['etapa'] === 'fechado' ? listarPendenciasDaOportunidade($id) : [];
$checklistOk = checklistFechamentoCompleto($id);
$atrasada = $op['proxima_acao_em'] && $op['proxima_acao_em'] < date('Y-m-d H:i:s');
// Garante que os 6 tipos obrigatórios existem como linha assim que a tela
// é aberta — não só quando o link é mandado — pra checklistFechamentoCompleto()
// nunca dar falso-positivo por causa de tipo que ainda nem virou linha.
garantirLinhasDocumentosObrigatorios($id);
$documentos = listarDocumentos($id);

$stmtContratos = $db->prepare("SELECT * FROM contratos WHERE oportunidade_id = ? ORDER BY id DESC");
$stmtContratos->execute([$id]);
$contratos = $stmtContratos->fetchAll();
$linkDocumentos = rtrim(getConfig('app_base_url') ?: (($_SERVER['HTTPS'] ?? '') === 'on' ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'], '/')
    . '/public/documentos.php?token=' . ($op['documentos_token'] ?: '(gerado ao clicar em enviar)');
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Oportunidade #<?= (int)$op['id'] ?> — Fastcar CRM</title>
<link rel="stylesheet" href="/admin/assets/style.css?v=<?= @filemtime(__DIR__ . '/assets/style.css') ?: 1 ?>">
<?php include __DIR__ . '/_pwa_head.php'; ?>
</head>
<body>
<header class="topbar">
    <a href="/admin/index.php" style="color:#fff">← Voltar</a>
    <strong><img class="topbar-logo" src="/admin/assets/img/icon-192.png" alt="Fastcar" onerror="this.style.display='none'"> Fast<b>Car</b></strong>
    <span>Olá, <?= e($_SESSION['admin_nome']) ?></span>
    <?php if ($_SESSION['admin_perfil'] === 'consultor'): ?>
        <?php $euAtual = buscarUsuario((int)$_SESSION['admin_id']); ?>
        <form method="post" action="/admin/toggle_disponivel.php" class="inline">
            <?= csrfField() ?>
            <input type="hidden" name="voltar" value="/admin/oportunidade.php?id=<?= (int)$op['id'] ?>">
            <button type="submit" style="margin-top:0;padding:4px 10px;font-size:12px">
                <?= $euAtual['disponivel'] ? '🟢 Disponível' : '⚪ Offline' ?>
            </button>
        </form>
    <?php endif; ?>
    <a href="/admin/clientes.php">👥 Clientes</a>
    <a href="/admin/vendas.php">💰 Vendas</a>
    <a href="/admin/pendencias_pos_venda.php">📋 Pendências</a>
    <a href="/admin/whatsapp_inbox.php">💬 WhatsApp</a>
    <?php if ($_SESSION['admin_perfil'] === 'super_admin'): ?>
        <a href="/admin/produtividade.php">📊 Produtividade</a>
        <a href="/admin/veiculos.php">🚗 Veículos</a>
        <a href="/admin/origem_leads.php">📣 Origem dos leads</a>
        <a href="/admin/configuracoes.php">⚙️ Configurações</a>
    <?php endif; ?>
    <a href="/admin/logout.php">Sair</a>
</header>

<main>
<?php if ($erro): ?><div class="alerta-erro"><?= e($erro) ?></div><?php endif; ?>
<?php if ($sucesso): ?><div class="alerta-sucesso"><?= e($sucesso) ?></div><?php endif; ?>
<?php if ($marcaFeedback): ?>
    <?php if ($marcaFeedback['status'] === 'ok'): ?>
        <div class="alerta-sucesso">✅ Marca "<?= e($marcaFeedback['sugestao']) ?>" reconhecida na FIPE.</div>
    <?php elseif ($marcaFeedback['status'] === 'corrigida'): ?>
        <div class="alerta-erro">⚠️ Marca não bateu exatamente — você quis dizer "<?= e($marcaFeedback['sugestao']) ?>"? (o que foi digitado ficou salvo do jeito que está)</div>
    <?php elseif ($marcaFeedback['status'] === 'nao_encontrada'): ?>
        <div class="alerta-erro">⚠️ Marca não encontrada na lista da FIPE — confira a digitação.</div>
    <?php else: ?>
        <div class="alerta-erro">ℹ️ FIPE indisponível agora, não deu pra validar a marca.</div>
    <?php endif; ?>
<?php endif; ?>

<div class="card">
    <h2>#<?= (int)$op['id'] ?> — <?= e($op['cliente_nome'] ?: '(sem nome)') ?>
        <span class="badge"><?= e(etapaLabel($op['etapa'])) ?></span>
        <?php if ($atrasada): ?><span class="badge badge-atraso">⚠️ ação atrasada</span><?php endif; ?>
        <?php if ($op['temperatura_lead']): ?>
            <span class="badge" title="Leitura da IA sobre a urgência do lead">
                <?= ['quente' => '🔥 Quente', 'morno' => '🌤️ Morno', 'frio' => '❄️ Frio'][$op['temperatura_lead']] ?? '' ?>
            </span>
        <?php endif; ?>
    </h2>
    <?php if ($_SESSION['admin_perfil'] !== 'supervisor'): ?>
        <form method="post" class="inline" style="margin-bottom:10px">
            <?= csrfField() ?>
            <input type="hidden" name="acao" value="atualizar_nome_cliente">
            <input type="text" name="nome_cliente" value="<?= e($op['cliente_nome']) ?>" placeholder="Nome do cliente" style="width:240px;display:inline-block">
            <button type="submit" style="margin-top:0;padding:5px 12px;font-size:13px">Salvar nome</button>
        </form>
    <?php endif; ?>
    <div class="grid-2">
        <div>
            <p><strong>Telefone:</strong> <?= e($op['cliente_telefone']) ?></p>
            <p><strong>E-mail:</strong>
               <?php if ($op['cliente_email']): ?>
                   <?= e($op['cliente_email']) ?>
               <?php else: ?>
                   <span class="badge badge-aviso">não informado</span>
                   — <a href="/admin/cliente_detalhe.php?id=<?= (int)$op['cliente_id'] ?>">completar</a>
               <?php endif; ?>
            </p>
            <p><strong>Cidade/UF:</strong> <?= e($op['cidade'] ?: '—') ?> / <?= e($op['estado'] ?: '—') ?></p>
            <p><strong>Origem:</strong> <?= e($op['canal_origem'] ?: '—') ?>
               <?= $op['campanha_origem'] ? ' · ' . e($op['campanha_origem']) : '' ?>
               <?= $op['anuncio_origem'] ? ' · ' . e($op['anuncio_origem']) : '' ?></p>
        </div>
        <div>
            <p><strong>Veículo:</strong>
               <?= e(trim(($op['veiculo_marca'] ?? '') . ' ' . $op['veiculo_modelo']) ?: 'não identificado ainda') ?>
               <?= e($op['veiculo_ano']) ?></p>
            <p><strong>Financiamento:</strong> <?= e($op['banco_financiamento'] ?: '—') ?>
               <?= $op['valor_parcela'] !== null ? ' · parcela R$ ' . number_format((float)$op['valor_parcela'], 2, ',', '.') : '' ?>
               <?= $op['parcelas_restantes'] !== null ? ' · ' . (int)$op['parcelas_restantes'] . ' restantes' : '' ?></p>
            <p><strong>Valor pretendido:</strong>
               <?= $op['valor_pretendido'] !== null ? 'R$ ' . number_format((float)$op['valor_pretendido'], 2, ',', '.') : 'não informado' ?></p>
        </div>
    </div>
    <?php if ($op['resumo_ia']): ?>
        <p><strong>Resumo da IA:</strong><br><?= nl2br(e($op['resumo_ia'])) ?></p>
    <?php endif; ?>
</div>

<div class="card">
    <h3>Dados do veículo <small>(marca é conferida contra a lista oficial da FIPE ao salvar)</small></h3>
    <form method="post">
        <?= csrfField() ?>
        <input type="hidden" name="acao" value="atualizar_veiculo">
        <div class="grid-2">
            <div>
                <label>Marca</label>
                <input type="text" id="veiculo_marca" name="veiculo_marca" value="<?= e($op['veiculo_marca'] ?? '') ?>" placeholder="Ex: Toyota">
                <label>Modelo</label>
                <input type="text" id="veiculo_modelo" name="veiculo_modelo" value="<?= e($op['veiculo_modelo'] ?? '') ?>" placeholder="Ex: Corolla">
                <label>Ano</label>
                <input type="text" id="veiculo_ano" name="veiculo_ano" value="<?= e($op['veiculo_ano'] ?? '') ?>" placeholder="Ex: 2019">
                <label>Placa</label>
                <input type="text" name="veiculo_placa" value="<?= e($op['veiculo_placa'] ?? '') ?>">
                <label>RENAVAM</label>
                <input type="text" name="veiculo_renavam" value="<?= e($op['veiculo_renavam'] ?? '') ?>">
                <label>Chassi</label>
                <input type="text" name="veiculo_chassi" value="<?= e($op['veiculo_chassi'] ?? '') ?>">
            </div>
            <div>
                <label>Banco do financiamento</label>
                <input type="text" name="banco_financiamento" value="<?= e($op['banco_financiamento'] ?? '') ?>">
                <label>Valor da parcela (R$)</label>
                <input type="number" step="0.01" name="valor_parcela" id="valor_parcela" value="<?= e((string)($op['valor_parcela'] ?? '')) ?>">
                <small id="parcela-auto-hint" style="display:none;color:var(--texto-fraco)">🧮 calculado automaticamente (saldo ÷ parcelas restantes) — edite se for diferente</small>
                <label>Parcelas restantes</label>
                <input type="number" name="parcelas_restantes" id="parcelas_restantes" value="<?= e((string)($op['parcelas_restantes'] ?? '')) ?>">
                <small id="parcelas-restantes-auto-hint" style="display:none;color:var(--texto-fraco)">🧮 calculado automaticamente (saldo ÷ parcela) — edite se for diferente</small>
                <label>Parcelas em atraso</label>
                <input type="number" name="parcelas_atraso" value="<?= e((string)($op['parcelas_atraso'] ?? 0)) ?>">
                <label>Valor pretendido pelo cliente (R$)</label>
                <input type="number" step="0.01" name="valor_pretendido" value="<?= e((string)($op['valor_pretendido'] ?? '')) ?>">
            </div>
        </div>

        <h4 style="margin-top:16px">💰 Débitos do veículo</h4>
        <p><small>Preenche o consultor, confirmado com o vendedor — ajuda a avaliar o valor a oferecer. Deixe em
           branco enquanto não confirmado, nunca chuta um valor.</small></p>
        <div class="grid-2">
            <div>
                <label>IPVA em aberto (R$)</label>
                <input type="number" step="0.01" name="debito_ipva" id="debito_ipva" value="<?= e((string)($op['debito_ipva'] ?? '')) ?>">
                <label>Licenciamento em aberto (R$)</label>
                <input type="number" step="0.01" name="debito_licenciamento" id="debito_licenciamento" value="<?= e((string)($op['debito_licenciamento'] ?? '')) ?>">
            </div>
            <div>
                <label>Multas em aberto (R$)</label>
                <input type="number" step="0.01" name="debito_multas" id="debito_multas" value="<?= e((string)($op['debito_multas'] ?? '')) ?>">
                <p id="debitos-total" style="font-size:13px;color:var(--texto-fraco);margin-top:8px"></p>
            </div>
        </div>
        <button type="submit">Salvar dados do veículo</button>
    </form>
</div>

<?php if (zapcarConfigured()): ?>
<div class="card" id="zapcar-card">
    <h3>🔎 Consulta veicular (ZapCar)</h3>
    <p><small>22/09/2026 — consulta paga (desconta do saldo da conta ZapCar): restrições, débitos, sinistro, leilão
       e gravame pela placa oficial, direto na base. Ajuda a avaliar o veículo antes de fechar a compra — nunca
       preenche valor/decisão de compra sozinho, é só informação pra você revisar.</small></p>
    <div style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap">
        <div>
            <label style="font-size:12px">Placa</label>
            <input type="text" id="zapcar-placa" style="width:120px;text-transform:uppercase" maxlength="8"
                   value="<?= e($op['veiculo_placa'] ?? '') ?>" placeholder="ABC1D23">
        </div>
        <button type="button" id="zapcar-consultar-btn" style="margin:0;padding:8px 14px;font-size:13px;white-space:nowrap">Consultar (paga)</button>
    </div>
    <div id="zapcar-resultado" style="margin-top:10px;font-size:13px"></div>
</div>
<?php endif; ?>

<div class="card">
    <h3>📝 Financiamento e contrato de compra</h3>
    <p><small>Esses dados alimentam o Quadro-Resumo do contrato-mestre de compra (includes/contratos_pdf.php) — o
       consultor confirma com o cliente antes de gerar, a IA/sistema nunca preenche isso sozinho.</small></p>
    <?php if (getConfig('placafipe_token')): ?>
        <div id="fipe-busca" style="background:var(--fundo);border:1px solid var(--borda);border-radius:8px;padding:12px;margin-bottom:14px">
            <strong style="font-size:13px">🔍 Buscar valor FIPE pela placa</strong>
            <div style="display:flex;gap:8px;margin-top:8px;align-items:flex-end">
                <div style="flex:1">
                    <label style="font-size:12px">Placa do veículo</label>
                    <input type="text" id="fipe-placa" style="width:100%;text-transform:uppercase" maxlength="8"
                           value="<?= e($op['veiculo_placa'] ?? '') ?>" placeholder="ABC1D23">
                </div>
                <button type="button" id="fipe-buscar-btn" style="margin:0;padding:8px 14px;font-size:13px;white-space:nowrap">Buscar</button>
            </div>
            <p id="fipe-resultado" style="font-size:12.5px;color:var(--texto-fraco);margin-top:8px"></p>
            <div id="fipe-candidatos"></div>
        </div>
    <?php endif; ?>
    <form method="post">
        <?= csrfField() ?>
        <input type="hidden" name="acao" value="atualizar_contrato">
        <div class="grid-2">
            <div>
                <label>Valor FIPE de referência (R$)</label>
                <input type="number" step="0.01" name="valor_fipe_referencia" id="valor_fipe_referencia" value="<?= e((string)($op['valor_fipe_referencia'] ?? '')) ?>">
                <label>Valor ofertado ao vendedor (R$) — limitado a 25% da FIPE</label>
                <input type="number" step="0.01" name="valor_ofertado" value="<?= e((string)($op['valor_ofertado'] ?? '')) ?>">
                <label>Nº do contrato de financiamento</label>
                <input type="text" name="contrato_financiamento_numero" value="<?= e($op['contrato_financiamento_numero'] ?? '') ?>">
                <label>Saldo do financiamento atual (R$)</label>
                <input type="number" step="0.01" name="saldo_financiamento_atual" id="saldo_financiamento_atual" value="<?= e((string)($op['saldo_financiamento_atual'] ?? '')) ?>">
                <small id="saldo-auto-hint" style="display:none;color:var(--texto-fraco)">🧮 calculado automaticamente (parcela × parcelas restantes) — edite se for diferente</small>
            </div>
            <div>
                <label>Terceiro indicado pra quitação</label>
                <input type="text" name="terceiro_quitacao" value="<?= e($op['terceiro_quitacao'] ?? '') ?>" placeholder="a indicar, se ainda não tiver">
                <label>Data de entrega da posse</label>
                <input type="date" name="data_entrega_posse" value="<?= e($op['data_entrega_posse'] ?? '') ?>">
                <label>Prazo pra quitar o financiamento (meses)</label>
                <input type="number" min="1" max="24" name="prazo_quitacao_meses" value="<?= e((string)($op['prazo_quitacao_meses'] ?? '')) ?>" placeholder="normal: 12 a 18">
                <small style="color:var(--texto-fraco)">Normal fica entre 12 e 18 meses — o limite contratual é 24 (cláusula 5ª), nunca digitar mais que isso.</small>
                <label>Seguro/proteção durante a posse da FASTCAR</label>
                <input type="text" name="seguro_texto" value="<?= e($op['seguro_texto'] ?? '') ?>">
                <label>IPVA/licenciamento/multas após a entrega</label>
                <input type="text" name="encargos_texto" value="<?= e($op['encargos_texto'] ?? '') ?>">
            </div>
        </div>

        <button type="submit">Salvar dados do contrato</button>
    </form>

    <p><small>✍️ Testemunhas do contrato são fixas (sempre da própria Fastcar) — configura em
       <a href="/admin/configuracoes.php">Configurações</a>, não muda por oportunidade.</small></p>

    <?php if ($op['valor_fipe_referencia'] && $op['valor_ofertado']): ?>
        <?php $percentualAtual = round((float)$op['valor_ofertado'] / (float)$op['valor_fipe_referencia'] * 100, 2); ?>
        <p><small>Percentual atual: <strong><?= $percentualAtual ?>%</strong> da FIPE
            <?php if ($percentualAtual > 25): ?><span class="badge badge-atraso">⚠️ acima do limite de 25%</span><?php endif; ?>
        </small></p>
    <?php endif; ?>

    <hr>
    <form method="post" style="display:inline-block;margin-right:8px">
        <?= csrfField() ?>
        <input type="hidden" name="acao" value="gerar_contrato_preview">
        <button type="submit" class="secundario">👁️ Gerar contrato (só visualizar)</button>
    </form>
    <form method="post" style="display:inline-block" onsubmit="return confirm('Gerar o contrato e enviar pra assinatura eletrônica?');">
        <?= csrfField() ?>
        <input type="hidden" name="acao" value="gerar_contrato">
        <button type="submit">📄 Gerar contrato e enviar pra assinatura</button>
    </form>
    <p><small>Confere os dados mesclados no PDF (nome, veículo, valores, cláusulas) antes de mandar pro
       cliente — "👁️ Gerar contrato" salva um rascunho só pra você ver, sem disparar assinatura nem
       avisar o cliente. Quando estiver tudo certo, use "📄 ... e enviar pra assinatura".</small></p>

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
    <h3>📎 Documentos</h3>
    <p><small>O cliente sobe CNH, comprovante de endereço e contrato de financiamento sozinho, sem login, num wizard
       passo a passo pelo link abaixo — a cada envio a IA lê o documento e pré-preenche nome/CPF/endereço/dados do
       veículo, e o cliente só confirma. Documentos da pasta fechada (bloco 8) o consultor/Jean anexa manualmente
       aqui mesmo.</small></p>

    <?php if ($op['documentos_confirmados_em']): ?>
        <div class="alerta-sucesso" style="padding:8px 12px;border-radius:6px;background:#e3f3e6;color:#2a7a3b;margin-bottom:10px">
            ✅ Dados e documentos confirmados em <?= date('d/m/Y H:i', strtotime($op['documentos_confirmados_em'])) ?>
            (pelo cliente via wizard, ou pela equipe em nome dele quando ele não conseguiu usar o link).
        </div>
    <?php elseif (array_filter($documentos, fn($d) => $d['arquivo_url'] || $d['drive_file_id'])): ?>
        <div class="alerta-erro" style="padding:8px 12px;border-radius:6px;background:#fbe4e1;color:#a33;margin-bottom:10px">
            ⏳ Cliente ainda está no meio do wizard de documentos (não confirmou o resumo final ainda).
        </div>
    <?php endif; ?>

    <p>
        <code style="font-size:12px;word-break:break-all"><?= e($linkDocumentos) ?></code><br>
        <form method="post" class="inline" style="display:inline-block;margin-top:8px">
            <?= csrfField() ?>
            <input type="hidden" name="acao" value="enviar_link_documentos">
            <button type="submit" style="margin-top:0">Enviar link por WhatsApp</button>
        </form>
    </p>

    <table class="tabela-oportunidades">
        <thead><tr><th>Documento</th><th>Status</th><th>Enviado por</th><th></th></tr></thead>
        <tbody>
        <?php foreach (TIPOS_DOCUMENTOS_CLIENTE + TIPOS_DOCUMENTOS_FECHAMENTO as $tipo => $label): ?>
            <?php
                $doc = $documentos[$tipo] ?? null;
                // Documento pode estar em arquivo_url (fallback local) OU
                // drive_file_id (Drive, preferido) — checar só um dos dois
                // já causou "pendente" falso pra doc que tava no Drive.
                $temArquivo = $doc && ($doc['arquivo_url'] || $doc['drive_file_id']);
                $ehDocCliente = isset(TIPOS_DOCUMENTOS_CLIENTE[$tipo]);
                // 17/09/2026, "vamos deixar opcional o laudo e comprovante
                // de pagamento opcional para fechar pasta" — nunca bloqueia
                // o checklist (regra #7), badge neutro em vez do alarme
                // vermelho de "pendente" pros outros documentos de verdade
                // obrigatórios.
                $ehOpcional = in_array($tipo, TIPOS_DOCUMENTOS_FECHAMENTO_OPCIONAIS, true);
            ?>
            <tr>
                <td><?= e($label) ?><?= $ehOpcional ? ' <small style="color:var(--texto-fraco)">(opcional)</small>' : '' ?></td>
                <td>
                    <?php if (!$temArquivo && $ehOpcional): ?>
                        <span class="badge">— opcional, não enviado</span>
                    <?php elseif (!$temArquivo): ?>
                        <span class="badge badge-atraso">⏳ pendente</span>
                    <?php elseif ($ehDocCliente && !$doc['dados_confirmados']): ?>
                        <span class="badge badge-atraso">📝 enviado, aguardando cliente confirmar dados</span>
                        <?php if ($_SESSION['admin_perfil'] !== 'supervisor'): ?>
                            <form method="post" class="inline" style="margin-top:4px" onsubmit="return confirm('Confirmar que já revisou os dados desse documento (marca/modelo/banco etc já editados nos cards acima) em nome do cliente?');">
                                <?= csrfField() ?>
                                <input type="hidden" name="acao" value="confirmar_documento_staff">
                                <input type="hidden" name="tipo_documento" value="<?= e($tipo) ?>">
                                <button type="submit" style="margin-top:2px;padding:3px 8px;font-size:11.5px">✅ Confirmar em nome do cliente</button>
                            </form>
                        <?php endif; ?>
                    <?php else: ?>
                        <span class="badge badge-ok">✅ enviado <?= date('d/m', strtotime($doc['updated_at'])) ?></span>
                    <?php endif; ?>
                </td>
                <td><?= $doc ? ($doc['enviado_pelo_cliente'] ? 'cliente' : 'equipe') : '—' ?></td>
                <td><?= $temArquivo ? '<a href="/admin/ver_documento.php?id=' . (int)$doc['id'] . '" target="_blank">ver</a>' : '' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <form method="post" enctype="multipart/form-data" style="margin-top:12px">
        <?= csrfField() ?>
        <input type="hidden" name="acao" value="upload_documento_staff">
        <label>Anexar documento manualmente</label>
        <select name="tipo_documento">
            <?php foreach (TIPOS_DOCUMENTOS_CLIENTE + TIPOS_DOCUMENTOS_FECHAMENTO as $tipo => $label): ?>
                <option value="<?= e($tipo) ?>"><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
        <input type="file" name="arquivo" accept="image/jpeg,image/png,image/webp,application/pdf" style="margin-top:8px">
        <button type="submit">Anexar</button>
    </form>
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
                    <option value="<?= (int)$u['id'] ?>" <?= (int)$op['responsavel_id'] === (int)$u['id'] ? 'selected' : '' ?>>
                        <?= e($u['nome']) ?> (<?= e($u['perfil']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
            <label>Descrição da próxima ação</label>
            <input type="text" name="proxima_acao" value="<?= e($op['proxima_acao'] ?? '') ?>" placeholder="Ex: ligar às 15h">
            <label>Data/hora</label>
            <input type="datetime-local" name="proxima_acao_em"
                   value="<?= $op['proxima_acao_em'] ? str_replace(' ', 'T', substr($op['proxima_acao_em'], 0, 16)) : '' ?>">
            <button type="submit">Salvar</button>
        </form>
    </div>

    <div class="card">
        <h3>Mudar etapa</h3>
        <?php if (!in_array($op['etapa'], ['fechado', 'perdido', 'sem_perfil'], true)): ?>
            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="acao" value="mudar_etapa">
                <label>Nova etapa</label>
                <select name="etapa_nova" required>
                    <?php foreach ($etapasFechaveis as $et): ?>
                        <option value="<?= e($et) ?>" <?= $op['etapa'] === $et ? 'selected' : '' ?>><?= e(etapaLabel($et)) ?></option>
                    <?php endforeach; ?>
                </select>
                <label>Observação</label>
                <input type="text" name="observacao" placeholder="Opcional">
                <button type="submit">Confirmar</button>
            </form>
            <p><small>
                Checklist de fechamento:
                <span class="badge <?= $checklistOk ? 'badge-ok' : 'badge-atraso' ?>">
                    <?= $checklistOk ? '✅ completo' : '⏳ pendente' ?>
                </span> — obrigatório pra mudar pra "Pasta fechada".
            </small></p>

            <hr>
            <form method="post" onsubmit="return confirm('Encerrar esta oportunidade?');">
                <?= csrfField() ?>
                <input type="hidden" name="acao" value="marcar_perdida">
                <label>Motivo (obrigatório)</label>
                <input type="text" name="motivo" required placeholder="Ex: desistiu, achou proposta baixa, sem perfil...">
                <label><input type="checkbox" name="sem_perfil" value="1" style="width:auto;display:inline-block"> Marcar como "sem perfil de compra" (em vez de "perdido")</label>
                <button type="submit" class="perigo">Encerrar oportunidade</button>
            </form>
        <?php else: ?>
            <p>Oportunidade encerrada — <?= e(etapaLabel($op['etapa'])) ?><?= $op['motivo_perda'] ? ': ' . e($op['motivo_perda']) : '' ?>.</p>
        <?php endif; ?>
    </div>
</div>

<?php if ($op['etapa'] === 'fechado'): ?>
<div class="card">
    <h3>📋 Pendências pós-venda</h3>
    <p><small>Pasta fechada, mas pode sobrar pendência operacional (ex: quitação de financiamento junto ao banco,
       transferência do veículo) — fica registrada aqui, separada do funil comercial que já encerrou.</small></p>

    <?php if (!$pendenciasPosVenda): ?>
        <p><small>Nenhuma pendência registrada.</small></p>
    <?php endif; ?>
    <?php foreach ($pendenciasPosVenda as $p): ?>
        <div class="historico-item" style="<?= $p['status'] === 'concluido' ? 'opacity:.6' : '' ?>">
            <div class="quando">
                <?= $p['status'] === 'concluido' ? '✅ Concluído em ' . date('d/m/Y', strtotime($p['concluido_em'])) : '⏳ Pendente' ?>
                <?= $p['prazo_estimado'] ? ' · prazo estimado ' . date('d/m/Y', strtotime($p['prazo_estimado'])) : '' ?>
                <?= $p['responsavel_nome'] ? ' · responsável: ' . e($p['responsavel_nome']) : '' ?>
            </div>
            <?= e($p['descricao']) ?>
            <?php if ($_SESSION['admin_perfil'] !== 'supervisor'): ?>
                <form method="post" class="inline" style="margin-top:6px">
                    <?= csrfField() ?>
                    <input type="hidden" name="pendencia_id" value="<?= (int)$p['id'] ?>">
                    <?php if ($p['status'] === 'pendente'): ?>
                        <input type="hidden" name="acao" value="concluir_pendencia_pos_venda">
                        <button type="submit" style="margin-top:0;padding:4px 10px;font-size:12px">Marcar concluída</button>
                    <?php else: ?>
                        <input type="hidden" name="acao" value="reabrir_pendencia_pos_venda">
                        <button type="submit" style="margin-top:0;padding:4px 10px;font-size:12px">Reabrir</button>
                    <?php endif; ?>
                </form>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>

    <?php if ($_SESSION['admin_perfil'] !== 'supervisor'): ?>
        <form method="post" style="margin-top:14px">
            <?= csrfField() ?>
            <input type="hidden" name="acao" value="criar_pendencia_pos_venda">
            <label>Nova pendência</label>
            <input type="text" name="descricao" placeholder="Ex: quitação do financiamento junto ao banco X" required>
            <div class="grid-2">
                <div>
                    <label>Prazo estimado (opcional)</label>
                    <input type="date" name="prazo_estimado">
                </div>
                <div>
                    <label>Responsável</label>
                    <select name="responsavel_id">
                        <option value="">— sem responsável definido —</option>
                        <?php foreach ($usuarios as $u): ?>
                            <option value="<?= (int)$u['id'] ?>"<?= (int)$u['id'] === (int)$_SESSION['admin_id'] ? ' selected' : '' ?>><?= e($u['nome']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <button type="submit">Registrar pendência</button>
        </form>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="card">
    <h3>Histórico de etapas</h3>
    <?php if (!$historico): ?>
        <p><small>Sem histórico ainda.</small></p>
    <?php endif; ?>
    <?php foreach ($historico as $h): ?>
        <div class="historico-item">
            <div class="quando"><?= date('d/m/Y H:i', strtotime($h['created_at'])) ?> — <?= e($h['responsavel_nome'] ?? 'sistema') ?></div>
            <?= e($h['etapa_anterior'] ?: '(criação)') ?> → <strong><?= e(etapaLabel($h['etapa_nova'])) ?></strong>
            <?= $h['observacao'] ? '— ' . e($h['observacao']) : '' ?>
        </div>
    <?php endforeach; ?>
</div>

<div class="card">
    <h3>Conversa (últimas <?= count($mensagens) ?> mensagens)
        <a href="/admin/whatsapp_inbox.php?telefone=<?= e($op['cliente_telefone']) ?>" style="font-size:13px;font-weight:600">💬 Abrir no WhatsApp Box →</a>
    </h3>
    <p><small>Pra responder o cliente, usa o WhatsApp Box (link acima) — essa lista aqui é só pra contexto rápido.</small></p>
    <div class="msg-thread">
        <?php if (!$mensagens): ?>
            <p><small>Nenhuma mensagem registrada ainda.</small></p>
        <?php endif; ?>
        <?php foreach ($mensagens as $m): ?>
            <div class="msg <?= $m['direcao'] === 'in' ? 'msg-in' : 'msg-out' ?>">
                <?= e($m['mensagem']) ?>
                <br><small>
                    <?= date('d/m H:i', strtotime($m['created_at'])) ?>
                    <?= $m['enviado_por_ia'] ? ' · 🤖 IA' : '' ?>
                    · <?= $m['usuario_nome'] ? '👤 ' . e($m['usuario_nome']) : 'canal principal' ?>
                </small>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<?php if (getConfig('placafipe_token')): ?>
<script>
(function () {
    // Busca de valor FIPE pela placa (PlacaFIPE) — 15/09/2026, "vamos
    // integrar" (troca de provedor: a Parallelum original nunca foi pro
    // ar, substituída pela PlacaFIPE depois do usuário mandar a doc real).
    // A resposta pode trazer MAIS DE UMA correspondência (campo
    // `correspondencia`, % de match) — nunca aplica sozinho, sempre mostra
    // a lista pro consultor escolher qual bate certo e clicar "Usar este
    // valor"; mesmo espírito de "IA/sistema nunca preenche sozinho" já
    // documentado nesta tela. Clicar só preenche o campo, nunca submete o
    // formulário — o consultor ainda revisa e clica "Salvar dados do
    // contrato" pra persistir.
    var inputPlaca = document.getElementById('fipe-placa');
    var btnBuscar = document.getElementById('fipe-buscar-btn');
    var resultado = document.getElementById('fipe-resultado');
    var listaCandidatos = document.getElementById('fipe-candidatos');
    var campoValor = document.getElementById('valor_fipe_referencia');
    var campoMarca = document.getElementById('veiculo_marca');
    var campoModelo = document.getElementById('veiculo_modelo');
    var campoAno = document.getElementById('veiculo_ano');
    if (!inputPlaca) return;

    function escapeHtml(s) {
        var d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }

    btnBuscar.addEventListener('click', function () {
        var placa = inputPlaca.value.trim();
        if (!placa) { resultado.textContent = '⚠️ Digite a placa primeiro.'; return; }
        listaCandidatos.innerHTML = '';
        btnBuscar.disabled = true;
        resultado.textContent = 'Buscando…';
        fetch('/admin/fipe_ajax.php?acao=buscar_placa&placa=' + encodeURIComponent(placa))
            .then(function (r) { return r.json(); })
            .then(function (data) {
                btnBuscar.disabled = false;
                if (!data.ok) {
                    resultado.textContent = '⚠️ ' + (data.msg || 'Não consegui buscar essa placa.');
                    return;
                }
                var v = data.veiculo;
                var preenchidoAgora = false;
                // Fill-if-empty nos campos de "Dados do veículo" — nunca
                // sobrescreve o que o consultor já tinha digitado ali,
                // mesma regra de "sistema nunca decide sozinho por cima de
                // dado já confirmado" usada no resto do projeto. Diferente
                // do valor FIPE (múltiplos candidatos, precisa escolha
                // humana), marca/modelo/ano vêm da placa como 1 resposta só.
                if (v) {
                    if (campoMarca && !campoMarca.value && v.marca) { campoMarca.value = v.marca; preenchidoAgora = true; }
                    if (campoModelo && !campoModelo.value && v.modelo) { campoModelo.value = v.modelo; preenchidoAgora = true; }
                    if (campoAno && !campoAno.value && v.ano_modelo) { campoAno.value = v.ano_modelo; preenchidoAgora = true; }
                }
                resultado.innerHTML = (v ? ('Veículo encontrado: ' + escapeHtml(v.marca || '') + ' ' + escapeHtml(v.modelo || '')
                    + ' (' + escapeHtml(v.ano_modelo || '') + ', ' + escapeHtml(v.cor || '') + ', ' + escapeHtml(v.uf || '') + '). ') : '')
                    + (preenchidoAgora ? 'Marca/modelo/ano preenchidos no card "Dados do veículo" (confira antes de salvar). ' : '')
                    + 'Escolha abaixo qual valor FIPE bate certo:';

                if (!data.candidatos || !data.candidatos.length) {
                    listaCandidatos.innerHTML = '<p style="font-size:12.5px;color:var(--texto-fraco)">Nenhuma correspondência de valor FIPE encontrada pra essa placa.</p>';
                    return;
                }
                data.candidatos.forEach(function (c) {
                    var div = document.createElement('div');
                    div.style.cssText = 'display:flex;justify-content:space-between;align-items:center;gap:8px;padding:8px;border:1px solid var(--borda);border-radius:6px;margin-top:6px;font-size:12.5px;background:var(--superficie)';
                    div.innerHTML = '<span>' + escapeHtml(c.marca) + ' ' + escapeHtml(c.modelo)
                        + ' (' + escapeHtml(String(c.ano_modelo)) + ', ' + escapeHtml(c.combustivel) + ')'
                        + '<br><small style="color:var(--texto-fraco)">Correspondência ' + escapeHtml(c.correspondencia) + '% · referência ' + escapeHtml(c.mes_referencia) + '</small></span>'
                        + '<span style="white-space:nowrap"><strong>' + escapeHtml(c.valor_texto) + '</strong></span>';
                    var btn = document.createElement('button');
                    btn.type = 'button';
                    btn.textContent = 'Usar este valor';
                    btn.style.cssText = 'margin:0;padding:5px 10px;font-size:12px;white-space:nowrap';
                    btn.addEventListener('click', function () {
                        if (campoValor && c.valor_numero !== null) campoValor.value = c.valor_numero;
                        resultado.textContent = '✅ ' + c.valor_texto + ' preenchido no campo abaixo — confira antes de salvar.';
                    });
                    div.appendChild(btn);
                    listaCandidatos.appendChild(div);
                });
            })
            .catch(function () {
                btnBuscar.disabled = false;
                resultado.textContent = '⚠️ Falha ao buscar — tente de novo.';
            });
    });
})();
</script>
<?php endif; ?>

<?php if (zapcarConfigured()): ?>
<script>
(function () {
    // Consulta veicular ZapCar (Consulta Simples) — 22/09/2026, "vamos
    // integrar essa api no sistema em oportunidade compras... por enquanto
    // chamada consulta simples". Cria (POST, cobra) via admin/zapcar_ajax.php
    // e o navegador repolla o status (GET, grátis) a cada 4s até concluir/
    // errar — nunca um cron/webhook nesta 1ª versão. Ao abrir a tela,
    // busca a última consulta já feita pra essa oportunidade (nunca cobra
    // de novo só por recarregar a página) e retoma o polling se ainda
    // estiver 'processando'.
    var oportunidadeId = <?= (int)$op['id'] ?>;
    var inputPlaca = document.getElementById('zapcar-placa');
    var btnConsultar = document.getElementById('zapcar-consultar-btn');
    var resultado = document.getElementById('zapcar-resultado');
    var csrf = document.querySelector('input[name="csrf_token"]');
    if (!inputPlaca || !csrf) return;

    var pollTimer = null;
    var pollTentativas = 0;
    var POLL_INTERVALO_MS = 4000;
    var POLL_MAX_TENTATIVAS = 90; // ~6min, teto da doc é 5min de polling — folga pra latência de rede

    function escapeHtml(s) {
        var d = document.createElement('div');
        d.textContent = (s === null || s === undefined) ? '' : String(s);
        return d.innerHTML;
    }

    // Tri-estado (regra #7 da doc ZapCar): true = alerta (vermelho),
    // false = verificado e limpo (verde), null/ausente = NÃO VERIFICADO
    // (azul/neutro) — nunca mostrar "nada consta" pra falta de informação.
    function badgeTriEstado(valor, textoTrue, textoFalse) {
        if (valor === true) return '<span class="badge badge-atraso">⚠️ ' + escapeHtml(textoTrue) + '</span>';
        if (valor === false) return '<span class="badge badge-ok">✅ ' + escapeHtml(textoFalse) + '</span>';
        return '<span class="badge badge-info">❔ não verificado</span>';
    }

    function formatarMoeda(centavos) {
        return 'R$ ' + (centavos / 100).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function pararPolling() {
        if (pollTimer) { clearTimeout(pollTimer); pollTimer = null; }
    }

    function renderizar(data) {
        if (!data || !data.ok) {
            resultado.innerHTML = '<div class="alerta-erro">⚠️ ' + escapeHtml((data && data.erro) || 'Falha ao consultar.') + '</div>';
            return;
        }
        if (data.status === 'processando') {
            resultado.innerHTML = '<p style="color:var(--texto-fraco)">⏳ Consultando na base oficial… pode levar até alguns minutos '
                + '(placa "fria" demora mais). Pode continuar usando a tela normalmente, o resultado aparece sozinho aqui.</p>';
            return;
        }
        if (data.status === 'erro') {
            resultado.innerHTML = '<div class="alerta-erro">⚠️ Consulta não concluiu: '
                + escapeHtml(data.erro_mensagem || data.erro_codigo || 'erro desconhecido')
                + (data.erro_codigo ? ' <small>(' + escapeHtml(data.erro_codigo) + ')</small>' : '')
                + '<br><small>Nada foi cobrado por essa falha (consultas com erro são estornadas automaticamente).'
                + ' Clique em "Consultar" de novo pra tentar outra vez.</small></div>';
            return;
        }
        // concluido
        var v = data.veiculo || {};
        var naoVerificado = data.nao_verificado || [];
        var html = '';
        html += '<p><strong>' + escapeHtml(v.placa || data.placa) + '</strong> — '
            + escapeHtml((v.marca || '') + ' ' + (v.modelo || '')) + ' '
            + (v.ano_modelo ? '(' + escapeHtml(v.ano_modelo) + ')' : '')
            + (v.cor ? ' · ' + escapeHtml(v.cor) : '')
            + (v.situacao ? ' · ' + escapeHtml(v.situacao) : '')
            + (data.valor_cobrado !== null ? ' <small style="color:var(--texto-fraco)">(consulta R$ '
                + Number(data.valor_cobrado).toFixed(2).replace('.', ',') + ')</small>' : '')
            + '</p>';

        if (v.baixado === true) {
            html += '<div class="alerta-erro">🚫 Veículo com registro de BAIXA — confirme com o vendedor antes de seguir.</div>';
        }

        html += '<p>' + badgeTriEstado(v.recall, 'recall', 'sem recall')
            + ' ' + badgeTriEstado(v.sinistro, 'sinistro', 'sem sinistro');
        if (v.leilao && typeof v.leilao === 'object') {
            html += ' ' + badgeTriEstado(v.leilao.consta, 'passou por leilão (' + (v.leilao.fotos || 0) + ' foto(s))', 'sem leilão');
        } else {
            html += ' ' + badgeTriEstado(null, '', '');
        }
        html += '</p>';

        var restricoes = Array.isArray(v.restricoes) ? v.restricoes : [];
        var restricoesAtivas = restricoes.filter(function (r) { return r && r.ativa === true; });
        if (restricoesAtivas.length) {
            html += '<p><strong>⚠️ Restrições ativas:</strong></p><ul style="margin:4px 0 8px 18px">';
            restricoesAtivas.forEach(function (r) {
                html += '<li>' + escapeHtml(r.tipo || 'RESTRIÇÃO') + (r.descricao ? ' — ' + escapeHtml(r.descricao) : '') + '</li>';
            });
            html += '</ul>';
        } else if (restricoes.length) {
            html += '<p><span class="badge badge-ok">✅ nenhuma restrição ativa</span> <small style="color:var(--texto-fraco)">(entre as verificadas)</small></p>';
        }

        var debitos = Array.isArray(v.debitos) ? v.debitos : [];
        if (debitos.length) {
            html += '<p><strong>💰 Débitos encontrados:</strong></p><ul style="margin:4px 0 8px 18px">';
            debitos.forEach(function (d) {
                var valorTxt = (d.valor_informado === false)
                    ? 'valor não informado'
                    : formatarMoeda(d.valor_centavos || 0);
                html += '<li>' + escapeHtml(d.tipo || 'OUTRO') + (d.descricao ? ' (' + escapeHtml(d.descricao) + ')' : '') + ': <strong>' + valorTxt + '</strong></li>';
            });
            var totalPrefixo = debitos.some(function (d) { return d.valor_informado === false; }) ? 'a partir de ' : '';
            html += '</ul><p><small>Total ' + totalPrefixo + '<strong>' + formatarMoeda(v.debitos_total_centavos || 0) + '</strong></small></p>';
        } else {
            html += '<p><span class="badge badge-ok">✅ sem débitos encontrados</span></p>';
        }

        if (v.proprietario && v.proprietario.nome) {
            html += '<p><small>Proprietário no CRLV: ' + escapeHtml(v.proprietario.nome)
                + (v.proprietario.documento ? ' — ' + escapeHtml(v.proprietario.documento) : '') + '</small></p>';
        }

        if (naoVerificado.length) {
            html += '<p><small style="color:var(--texto-fraco)">❔ Não verificado nesta consulta: '
                + escapeHtml(naoVerificado.join(', ')) + '</small></p>';
        }

        if (data.pdf_url) {
            html += '<p><a href="' + escapeHtml(data.pdf_url) + '" target="_blank" rel="noopener">📄 Ver documento da consulta</a></p>';
        }

        resultado.innerHTML = html;
    }

    function poll(idLocal) {
        pararPolling();
        fetch('/admin/zapcar_ajax.php?acao=status&id_local=' + encodeURIComponent(idLocal))
            .then(function (r) { return r.json(); })
            .then(function (data) {
                renderizar(data);
                pollTentativas++;
                if (data && data.ok && data.status === 'processando' && pollTentativas < POLL_MAX_TENTATIVAS) {
                    pollTimer = setTimeout(function () { poll(idLocal); }, POLL_INTERVALO_MS);
                } else if (data && data.status === 'processando') {
                    resultado.innerHTML += '<p><small>Ainda processando depois de alguns minutos — recarregue a página '
                        + 'mais tarde pra conferir, o resultado fica salvo assim que a ZapCar concluir.</small></p>';
                }
            })
            .catch(function () {
                // Falha de rede no polling em si — tenta de novo no próximo
                // ciclo, nunca desiste silenciosamente enquanto não bater o teto.
                pollTentativas++;
                if (pollTentativas < POLL_MAX_TENTATIVAS) {
                    pollTimer = setTimeout(function () { poll(idLocal); }, POLL_INTERVALO_MS);
                }
            });
    }

    btnConsultar.addEventListener('click', function () {
        var placa = inputPlaca.value.trim();
        if (!placa) { resultado.innerHTML = '<p>⚠️ Digite a placa primeiro.</p>'; return; }
        if (!confirm('Consultar essa placa na ZapCar? Isso desconta do saldo da conta ZapCar (consulta paga).')) return;

        btnConsultar.disabled = true;
        resultado.innerHTML = '<p>Enviando…</p>';
        var body = new URLSearchParams();
        body.set('csrf_token', csrf.value);
        body.set('oportunidade_id', String(oportunidadeId));
        body.set('placa', placa);

        fetch('/admin/zapcar_ajax.php?acao=consultar', { method: 'POST', body: body })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                btnConsultar.disabled = false;
                if (!data.ok) {
                    resultado.innerHTML = '<div class="alerta-erro">⚠️ ' + escapeHtml(data.erro || 'Falha ao consultar.') + '</div>';
                    return;
                }
                pollTentativas = 0;
                poll(data.id_local);
            })
            .catch(function () {
                btnConsultar.disabled = false;
                resultado.innerHTML = '<div class="alerta-erro">⚠️ Falha ao consultar — tente de novo.</div>';
            });
    });

    // Ao abrir a tela: mostra a última consulta já feita (sem cobrar de
    // novo) e retoma o polling se ainda estava 'processando'.
    fetch('/admin/zapcar_ajax.php?acao=ultima&oportunidade_id=' + encodeURIComponent(oportunidadeId))
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (!data || data.vazio) return;
            renderizar(data);
            if (data.ok && data.status === 'processando') {
                pollTentativas = 0;
                poll(data.id_local);
            }
        })
        .catch(function () {});
})();
</script>
<?php endif; ?>

<script>
(function () {
    // Calculadora de financiamento (parcela × parcelas restantes = saldo)
    // — 17/09/2026, "pode calcular saldo do financiamento automático ao
    // preencher o valor da parcela", ampliada no mesmo dia pra funcionar
    // nos 3 sentidos ("ao digitar valor da parcela calcular parcelas
    // restante[s] ... preencher campo parcela restantes" — o pedido original
    // só calculava o saldo a partir de parcela+parcelas; faltava o caminho
    // inverso, útil quando o saldo já é conhecido — ex: extraído do
    // contrato de financiamento ou digitado pelo consultor primeiro — e só
    // falta a parcela OU as parcelas restantes). Estimativa simples (não
    // desconta juros/amortização), por isso sempre editável: os 3 campos
    // (`valor_parcela`, `parcelas_restantes`, `saldo_financiamento_atual`)
    // vivem em 2 forms diferentes ("Dados do veículo" e "Financiamento e
    // contrato de compra"), mas como estão na MESMA página, o JS enxerga
    // os 3 juntos — só o `<form>` que o consultor clicar "Salvar" persiste
    // de fato.
    //
    // Regra: sempre que exatamente 1 dos 3 campos estiver vazio e os
    // outros 2 tiverem valor válido, calcula e preenche o vazio — nunca
    // mexe num campo que já tem valor (nem quando só 1 dos outros dois
    // muda depois), porque nesse caso os 3 já estão preenchidos e não há
    // "vazio" pra calcular; é assim, sem precisar de flag de "editado
    // manualmente" separada, que um saldo já salvo (ex: 9999.99, diferente
    // de parcela×parcelas) nunca é sobrescrito só porque a parcela mudou
    // depois. Roda 1x já no carregamento da página (além de a cada
    // digitação) — bug real achado em produção: parcela+parcelas salvos
    // numa visita anterior (ex: via "Salvar dados do veículo") só
    // apareciam pré-preenchidos vindos do servidor no reload, sem disparar
    // nenhum evento "input" (isso só acontece em digitação de verdade) —
    // o saldo continuava vazio pra sempre até o consultor digitar de novo
    // manualmente num dos dois campos, mesmo com tudo que precisava pro
    // cálculo já ali na tela.
    var campoParcela = document.getElementById('valor_parcela');
    var campoParcelasRestantes = document.getElementById('parcelas_restantes');
    var campoSaldo = document.getElementById('saldo_financiamento_atual');
    if (!campoParcela || !campoParcelasRestantes || !campoSaldo) return;

    var dicaParcela = document.getElementById('parcela-auto-hint');
    var dicaParcelasRestantes = document.getElementById('parcelas-restantes-auto-hint');
    var dicaSaldo = document.getElementById('saldo-auto-hint');

    function esconder(dica) { if (dica) dica.style.display = 'none'; }
    function mostrar(dica) { if (dica) dica.style.display = 'block'; }

    function recalcularTudo() {
        var p = parseFloat(campoParcela.value);
        var n = parseInt(campoParcelasRestantes.value, 10);
        var s = parseFloat(campoSaldo.value);
        var pOk = campoParcela.value !== '' && p > 0;
        var nOk = campoParcelasRestantes.value !== '' && n > 0;
        var sOk = campoSaldo.value !== '' && s > 0;
        var vazios = (pOk ? 0 : 1) + (nOk ? 0 : 1) + (sOk ? 0 : 1);
        if (vazios !== 1) return; // só dá pra calcular com exatamente 1 incógnita

        if (!sOk) { campoSaldo.value = (p * n).toFixed(2); mostrar(dicaSaldo); }
        else if (!nOk) { campoParcelasRestantes.value = String(Math.round(s / p)); mostrar(dicaParcelasRestantes); }
        else if (!pOk) { campoParcela.value = (s / n).toFixed(2); mostrar(dicaParcela); }
    }

    campoParcela.addEventListener('input', function () { esconder(dicaParcela); recalcularTudo(); });
    campoParcelasRestantes.addEventListener('input', function () { esconder(dicaParcelasRestantes); recalcularTudo(); });
    campoSaldo.addEventListener('input', function () { esconder(dicaSaldo); recalcularTudo(); });

    recalcularTudo(); // cobre os 2 campos já vindos preenchidos do servidor
})();
</script>
<script>
(function () {
    // Total dos débitos do veículo (22/09/2026, "campo de preencher -
    // debitos do veilucos como ipva linciamento e multoas") — só um
    // somatório informativo pro consultor ver o total de cara, sem
    // precisar somar na cabeça; nunca grava nada sozinho, os 3 campos
    // continuam salvos separados.
    var campoIpva = document.getElementById('debito_ipva');
    var campoLicenciamento = document.getElementById('debito_licenciamento');
    var campoMultas = document.getElementById('debito_multas');
    var totalEl = document.getElementById('debitos-total');
    if (!campoIpva || !campoLicenciamento || !campoMultas || !totalEl) return;

    function recalcularTotal() {
        var soma = [campoIpva, campoLicenciamento, campoMultas].reduce(function (acc, campo) {
            var v = parseFloat(campo.value);
            return acc + (isNaN(v) ? 0 : v);
        }, 0);
        if (soma > 0) {
            totalEl.textContent = 'Total: R$ ' + soma.toFixed(2).replace('.', ',');
        } else {
            totalEl.textContent = '';
        }
    }

    [campoIpva, campoLicenciamento, campoMultas].forEach(function (campo) {
        campo.addEventListener('input', recalcularTotal);
    });
    recalcularTotal();
})();
</script>

<div class="card">
    <h3>🔍 Checklist de vistoria do veículo</h3>
    <?php if ($op['etapa'] === 'presencial' && !array_filter($avaliacoesVeiculo, fn($a) => $a['tipo'] === 'compra')): ?>
        <div class="alerta-info">📢 Cliente já trouxe o veículo pra avaliação? Registre uma vistoria de recebimento abaixo antes de fechar o negócio.</div>
    <?php endif; ?>
    <?php if ($avaliacoesVeiculo): ?>
        <table>
            <thead><tr><th>Tipo</th><th>Status</th><th>Avaliador</th><th>Criada em</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($avaliacoesVeiculo as $a): ?>
                <tr>
                    <td><?= $a['tipo'] === 'venda' ? '🛒 Venda' : '🚗 Compra' ?></td>
                    <td><?= match ($a['status']) { 'concluida' => '✅ Concluída', 'em_andamento' => '🔧 Em andamento', default => '⏳ Pendente' } ?></td>
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
            <label>Atribuir a (opcional)</label>
            <select name="avaliador_id">
                <option value="">— não atribuído ainda —</option>
                <?php foreach ($avaliadoresDisponiveis as $u): ?>
                    <option value="<?= (int)$u['id'] ?>"><?= e($u['nome']) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit">+ Nova vistoria de recebimento</button>
        </form>
    <?php endif; ?>
</div>

</main>
<?php include __DIR__ . '/_pwa_register.php'; ?>
<?php include __DIR__ . '/_notify.php'; ?>
</body>
</html>
