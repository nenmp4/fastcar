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
                        data_entrega_posse = ?, updated_at = datetime('now','localtime')
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
                                if ($dadosExtraidos) {
                                    aplicarDadosExtraidosDocumento((int)$op['cliente_id'], $id, $tipoDoc, $dadosExtraidos);
                                }
                            }
                        }
                    } else {
                        $erro = $resultado['erro'] ?? 'Falha ao anexar documento.';
                    }
                }
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
$etapasFechaveis = array_merge(ETAPAS_ATIVAS, ['fechado']);
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
    <strong><img class="topbar-logo" src="/admin/assets/img/icon-192.png" alt="Fastcar" onerror="this.style.display='none'"> Fast<b>Car</b> <span class="crm-tag">CRM</span></strong>
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
                <input type="text" name="veiculo_marca" value="<?= e($op['veiculo_marca'] ?? '') ?>" placeholder="Ex: Toyota">
                <label>Modelo</label>
                <input type="text" name="veiculo_modelo" value="<?= e($op['veiculo_modelo'] ?? '') ?>" placeholder="Ex: Corolla">
                <label>Ano</label>
                <input type="text" name="veiculo_ano" value="<?= e($op['veiculo_ano'] ?? '') ?>" placeholder="Ex: 2019">
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
                <input type="number" step="0.01" name="valor_parcela" value="<?= e((string)($op['valor_parcela'] ?? '')) ?>">
                <label>Parcelas restantes</label>
                <input type="number" name="parcelas_restantes" value="<?= e((string)($op['parcelas_restantes'] ?? '')) ?>">
                <label>Parcelas em atraso</label>
                <input type="number" name="parcelas_atraso" value="<?= e((string)($op['parcelas_atraso'] ?? 0)) ?>">
                <label>Valor pretendido pelo cliente (R$)</label>
                <input type="number" step="0.01" name="valor_pretendido" value="<?= e((string)($op['valor_pretendido'] ?? '')) ?>">
            </div>
        </div>
        <button type="submit">Salvar dados do veículo</button>
    </form>
</div>

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
                <input type="number" step="0.01" name="saldo_financiamento_atual" value="<?= e((string)($op['saldo_financiamento_atual'] ?? '')) ?>">
            </div>
            <div>
                <label>Terceiro indicado pra quitação</label>
                <input type="text" name="terceiro_quitacao" value="<?= e($op['terceiro_quitacao'] ?? '') ?>" placeholder="a indicar, se ainda não tiver">
                <label>Data de entrega da posse</label>
                <input type="date" name="data_entrega_posse" value="<?= e($op['data_entrega_posse'] ?? '') ?>">
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
    <form method="post" onsubmit="return confirm('Gerar o contrato e enviar pra assinatura eletrônica?');">
        <?= csrfField() ?>
        <input type="hidden" name="acao" value="gerar_contrato">
        <button type="submit">📄 Gerar contrato e enviar pra assinatura</button>
    </form>

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
            ✅ Cliente confirmou os dados e documentos em <?= date('d/m/Y H:i', strtotime($op['documentos_confirmados_em'])) ?>.
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
            ?>
            <tr>
                <td><?= e($label) ?></td>
                <td>
                    <?php if (!$temArquivo): ?>
                        <span class="badge badge-atraso">⏳ pendente</span>
                    <?php elseif ($ehDocCliente && !$doc['dados_confirmados']): ?>
                        <span class="badge badge-atraso">📝 enviado, aguardando cliente confirmar dados</span>
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
                resultado.innerHTML = (v ? ('Veículo encontrado: ' + escapeHtml(v.marca || '') + ' ' + escapeHtml(v.modelo || '')
                    + ' (' + escapeHtml(v.ano_modelo || '') + ', ' + escapeHtml(v.cor || '') + ', ' + escapeHtml(v.uf || '') + '). ') : '')
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

</main>
<?php include __DIR__ . '/_pwa_register.php'; ?>
<?php include __DIR__ . '/_notify.php'; ?>
</body>
</html>
