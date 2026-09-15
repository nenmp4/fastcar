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

$db = getDB();
$id = (int)($_GET['id'] ?? 0);

$stmtVenda = $db->prepare("
    SELECT v.*, o.veiculo_marca, o.veiculo_modelo, o.veiculo_ano, o.veiculo_placa, o.veiculo_renavam,
           o.veiculo_chassi, o.banco_financiamento, o.contrato_financiamento_numero, o.saldo_financiamento_atual,
           o.valor_fipe_referencia, c.nome AS vendedor_original_nome
    FROM vendas v
    JOIN oportunidades o ON o.id = v.oportunidade_id
    JOIN clientes c ON c.id = o.cliente_id
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
    } else {
        $acao = (string)($_POST['acao'] ?? '');
        try {
            if ($acao === 'atualizar_comprador') {
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
            } elseif ($acao === 'gerar_contrato') {
                $resultadoContrato = gerarEEnviarContratoVenda($id, (int)$_SESSION['admin_id']);
                if ($resultadoContrato['ok']) {
                    $sucesso = 'Contrato gerado e enviado pra assinatura.' . ($resultadoContrato['aviso'] ? ' ⚠️ ' . $resultadoContrato['aviso'] : '');
                } else {
                    $erro = $resultadoContrato['erro'];
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
                mudarEtapaVenda($id, (string)$_POST['etapa_nova'], (int)$_SESSION['admin_id'], clean((string)($_POST['observacao'] ?? '')));
                $sucesso = 'Etapa atualizada.';
            } elseif ($acao === 'cancelar_venda') {
                mudarEtapaVenda($id, 'cancelada', (int)$_SESSION['admin_id'], clean((string)($_POST['motivo'] ?? '')));
                $sucesso = 'Negociação cancelada — veículo liberado pra uma nova tentativa de venda.';
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
    <strong><img class="topbar-logo" src="/admin/assets/img/icon-192.png" alt="Fastcar" onerror="this.style.display='none'"> Fast<b>Car</b> <span class="crm-tag">CRM</span></strong>
    <span>Olá, <?= e($_SESSION['admin_nome']) ?></span>
    <a href="/admin/logout.php">Sair</a>
</header>

<main>
<?php if ($erro): ?><div class="alerta-erro"><?= e($erro) ?></div><?php endif; ?>
<?php if ($sucesso): ?><div class="alerta-sucesso"><?= e($sucesso) ?></div><?php endif; ?>

<div class="card">
    <h2>#<?= (int)$v['id'] ?> — <?= e(trim($v['veiculo_marca'] . ' ' . $v['veiculo_modelo'])) ?: '—' ?> <?= e($v['veiculo_ano']) ?>
        <span class="badge"><?= e(etapaVendaLabel($v['etapa'])) ?></span>
        <?php if ($atrasada): ?><span class="badge badge-atraso">⚠️ ação atrasada</span><?php endif; ?>
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

    <?php if (in_array($v['etapa'], ['negociacao', 'contrato_enviado'], true)): ?>
        <hr>
        <form method="post" onsubmit="return confirm('Gerar o contrato de venda e enviar pra assinatura eletrônica?');">
            <?= csrfField() ?>
            <input type="hidden" name="acao" value="gerar_contrato">
            <button type="submit">📄 Gerar contrato e enviar pra assinatura</button>
        </form>
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
        <?php else: ?>
            <p>Negociação encerrada — <?= e(etapaVendaLabel($v['etapa'])) ?><?= $v['motivo_cancelamento'] ? ': ' . e($v['motivo_cancelamento']) : '' ?>.</p>
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
