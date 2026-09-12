<?php
/**
 * Detalhe da oportunidade — histórico de etapas, conversa do WhatsApp,
 * resumo da IA e ações do consultor/closer (próxima ação, mudar etapa,
 * marcar perdida). Toda mudança de etapa passa por mudarEtapa()/
 * marcarPerdida() (includes/oportunidades.php) — nunca UPDATE direto.
 */

require_once __DIR__ . '/_bootstrap.php';

$db = getDB();
$id = (int)($_GET['id'] ?? 0);

$stmtOp = $db->prepare("
    SELECT o.*, c.nome AS cliente_nome, c.telefone AS cliente_telefone,
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        $erro = 'Sessão expirada, recarregue a página e tente de novo.';
    } else {
        $acao = (string)($_POST['acao'] ?? '');
        try {
            if ($acao === 'atualizar_proxima_acao') {
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

$stmtMsg = $db->prepare("SELECT * FROM whatsapp_mensagens WHERE telefone = ? ORDER BY id DESC LIMIT 50");
$stmtMsg->execute([$op['cliente_telefone']]);
$mensagens = array_reverse($stmtMsg->fetchAll());

$usuarios = listarUsuarios();
$etapasFechaveis = array_merge(ETAPAS_ATIVAS, ['fechado']);
$checklistOk = checklistFechamentoCompleto($id);
$atrasada = $op['proxima_acao_em'] && $op['proxima_acao_em'] < date('Y-m-d H:i:s');
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Oportunidade #<?= (int)$op['id'] ?> — Fastcar CRM</title>
<link rel="stylesheet" href="/admin/assets/style.css">
</head>
<body>
<header class="topbar">
    <a href="/admin/index.php" style="color:#fff">← Voltar</a>
    <strong>🚗 Fastcar CRM</strong>
    <span>Olá, <?= e($_SESSION['admin_nome']) ?></span>
    <a href="/admin/logout.php">Sair</a>
</header>

<main>
<?php if ($erro): ?><div class="alerta-erro"><?= e($erro) ?></div><?php endif; ?>
<?php if ($sucesso): ?><div class="alerta-sucesso"><?= e($sucesso) ?></div><?php endif; ?>

<div class="card">
    <h2>#<?= (int)$op['id'] ?> — <?= e($op['cliente_nome'] ?: '(sem nome)') ?>
        <span class="badge"><?= e(etapaLabel($op['etapa'])) ?></span>
        <?php if ($atrasada): ?><span class="badge badge-atraso">⚠️ ação atrasada</span><?php endif; ?>
    </h2>
    <div class="grid-2">
        <div>
            <p><strong>Telefone:</strong> <?= e($op['cliente_telefone']) ?></p>
            <p><strong>Cidade/UF:</strong> <?= e($op['cidade'] ?: '—') ?> / <?= e($op['estado'] ?: '—') ?></p>
            <p><strong>Origem:</strong> <?= e($op['canal_origem'] ?: '—') ?>
               <?= $op['campanha_origem'] ? ' · ' . e($op['campanha_origem']) : '' ?>
               <?= $op['anuncio_origem'] ? ' · ' . e($op['anuncio_origem']) : '' ?></p>
        </div>
        <div>
            <p><strong>Veículo:</strong> <?= e($op['veiculo_modelo'] ?: 'não identificado ainda') ?> <?= e($op['veiculo_ano']) ?></p>
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
    <h3>Conversa (últimas <?= count($mensagens) ?> mensagens)</h3>
    <div class="msg-thread">
        <?php if (!$mensagens): ?>
            <p><small>Nenhuma mensagem registrada ainda.</small></p>
        <?php endif; ?>
        <?php foreach ($mensagens as $m): ?>
            <div class="msg <?= $m['direcao'] === 'in' ? 'msg-in' : 'msg-out' ?>">
                <?= e($m['mensagem']) ?>
                <br><small><?= date('d/m H:i', strtotime($m['created_at'])) ?><?= $m['enviado_por_ia'] ? ' · 🤖 IA' : '' ?></small>
            </div>
        <?php endforeach; ?>
    </div>
</div>

</main>
</body>
</html>
