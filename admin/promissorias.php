<?php
/**
 * Dashboard de vendas dentro do "módulo promissória" (26/09/2026, "Monta
 * desbord de venda dentro do módulo promissória para lançar automaticamente
 * no financeiro") — lista TODAS as vendas (confirmado com o usuário via
 * AskUserQuestion: não só as parceladas/com entrada em partes) com o
 * retrato financeiro de cada uma lado a lado: total já pago, pendente,
 * quantas parcelas atrasadas — sem duplicar dado nenhum de admin/vendas.php,
 * só soma o que já está em fin_lancamentos via finResumoLancamentosVenda()
 * (includes/financeiro.php).
 *
 * "Lançar automaticamente no financeiro" — a receita/comissão de uma venda
 * já JÁ é gerada sozinha no momento em que ela vira 'vendido' de verdade
 * (finGerarReceitaVendaAssinatura()/finRegistrarComissaoVendaFechada(),
 * chamadas de dentro de mudarEtapaVenda() — ver includes/vendas.php). Este
 * dashboard não substitui isso; ele é a camada de VISIBILIDADE + um botão
 * de recuperação pra venda que por algum motivo ficou 'vendido' sem nenhum
 * lançamento ainda (ex: importada da ZapSign antes dessas funções
 * existirem, ou uma falha silenciosa best-effort na hora da assinatura) —
 * mesmas 2 funções de produção, nunca duplicadas, sempre idempotentes.
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../includes/dashboard.php';
requireAcessoVendas();

$db = getDB();
$perfil = $_SESSION['admin_perfil'];
$meuId = (int)$_SESSION['admin_id'];
$souDono = $perfil === 'vendedor';

$erro = '';
$sucesso = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        $erro = 'Sessão expirada, recarregue a página e tente de novo.';
    } elseif ($perfil === 'supervisor') {
        // Perfil de acompanhamento (mesmo padrão do resto do projeto) —
        // vê tudo, nunca dispara lançamento financeiro.
        http_response_code(403);
        $erro = 'Perfil de supervisão só acompanha, não gera lançamentos.';
    } elseif ((string)($_POST['acao'] ?? '') === 'gerar_financeiro') {
        $vendaId = (int)($_POST['venda_id'] ?? 0);
        $stmtV = $db->prepare("SELECT etapa, data_venda FROM vendas WHERE id = ?");
        $stmtV->execute([$vendaId]);
        $venda = $stmtV->fetch();
        if (!$venda) {
            $erro = 'Venda não encontrada.';
        } elseif ($venda['etapa'] !== 'vendido') {
            $erro = 'Só dá pra gerar lançamento pra venda já concluída (vendido).';
        } elseif (finContarLancamentosVenda($vendaId) > 0) {
            $erro = 'Essa venda já tem lançamento no financeiro.';
        } else {
            // Mesma data real da venda (nunca "hoje") — mesmo racional do
            // backfill retroativo (install/gerar_lancamentos_vendas_retroativos.php):
            // essa venda já aconteceu, gerar como se fosse agora inflaria o
            // mês corrente com receita/comissão de um negócio antigo.
            $dataVenda = $venda['data_venda'] ?: date('Y-m-d');
            finGerarReceitaVendaAssinatura($vendaId, (int)$_SESSION['admin_id'], $dataVenda);
            finRegistrarComissaoVendaFechada($vendaId, $dataVenda);
            $sucesso = finContarLancamentosVenda($vendaId) > 0
                ? 'Lançamento(s) gerado(s) no financeiro.'
                : 'Nada gerado — confira se preço/entrada/prazo de quitação estão preenchidos na venda.';
        }
    }
}

$busca = trim((string)($_GET['q'] ?? ''));
$where = 'WHERE 1=1';
$params = [];
if ($souDono) {
    $where .= ' AND v.responsavel_id = ?';
    $params[] = $meuId;
}
if ($busca !== '') {
    $where .= ' AND (v.comprador_nome LIKE ? OR v.comprador_telefone LIKE ? OR o.veiculo_marca LIKE ? OR o.veiculo_modelo LIKE ?)';
    $like = '%' . $busca . '%';
    array_push($params, $like, $like, $like, $like);
}

finRecalcularAtrasados();

$stmtTotal = $db->prepare("SELECT COUNT(*) FROM vendas v LEFT JOIN oportunidades o ON o.id = v.oportunidade_id {$where}");
$stmtTotal->execute($params);
$totalFiltrado = (int)$stmtTotal->fetchColumn();

$sql = "
    SELECT v.*, o.veiculo_marca, o.veiculo_modelo, o.veiculo_ano, o.veiculo_placa,
           u.nome AS responsavel_nome
    FROM vendas v
    LEFT JOIN oportunidades o ON o.id = v.oportunidade_id
    LEFT JOIN usuarios u ON u.id = v.responsavel_id
    {$where}
    ORDER BY v.created_at DESC
    LIMIT " . ITENS_POR_PAGINA_PADRAO . " OFFSET " . paginacaoOffset();
$stmt = $db->prepare($sql);
$stmt->execute($params);
$vendas = $stmt->fetchAll();

function moedaPromissoria(float $v): string { return 'R$ ' . number_format($v, 2, ',', '.'); }
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Promissórias — Fastcar CRM</title>
<link rel="stylesheet" href="/admin/assets/style.css?v=<?= @filemtime(__DIR__ . '/assets/style.css') ?: 1 ?>">
<link rel="stylesheet" href="/admin/assets/mobile.css?v=<?= @filemtime(__DIR__ . '/assets/mobile.css') ?: 1 ?>">
<script src="/admin/assets/mobile.js?v=<?= @filemtime(__DIR__ . '/assets/mobile.js') ?: 1 ?>" defer></script>
<?php include __DIR__ . '/_pwa_head.php'; ?>
</head>
<body>
<header class="topbar">
    <a href="/admin/vendas.php" style="color:#fff">← Vendas</a>
    <strong><img class="topbar-logo" src="/admin/assets/img/icon-192.png" alt="Fastcar" onerror="this.style.display='none'"> Fast<b>Car</b></strong>
    <span>Olá, <?= e($_SESSION['admin_nome']) ?></span>
    <a href="/admin/meu_perfil.php">🙋 Meu perfil</a>
    <a href="/admin/logout.php">Sair</a>
</header>

<main>
<h2>💳 Promissórias — dashboard financeiro das vendas</h2>
<p><small>Cada venda de revenda (entrada em partes via PIX, bem de troca, parcelamento do saldo) com o retrato
   financeiro real ao lado — o que já foi lançado, pago, pendente e atrasado em <a href="/admin/financeiro.php">Financeiro</a>.
   Receita/comissão são geradas sozinhas assim que a venda é assinada; o botão "Gerar no financeiro" só aparece
   como recuperação pra uma venda concluída que por algum motivo ficou sem nenhum lançamento.</small></p>

<?php if ($erro): ?><p class="alerta alerta-erro"><?= e($erro) ?></p><?php endif; ?>
<?php if ($sucesso): ?><p class="alerta alerta-ok"><?= e($sucesso) ?></p><?php endif; ?>

<div class="card">
    <form method="get" style="display:flex;gap:8px;align-items:center">
        <input type="text" name="q" value="<?= e($busca) ?>" placeholder="Buscar por comprador, telefone ou veículo..." style="flex:1;margin:0">
        <button type="submit" style="margin:0">Buscar</button>
        <?php if ($busca): ?><a href="/admin/promissorias.php">Limpar</a><?php endif; ?>
    </form>
</div>

<div class="card">
    <table class="tabela-oportunidades">
        <thead>
            <tr>
                <th>Veículo</th><th>Comprador</th><th>Preço / entrada</th>
                <th>Etapa</th><th>Financeiro</th><th></th>
            </tr>
        </thead>
        <tbody>
        <?php if (!$vendas): ?>
            <tr><td colspan="6">Nenhuma venda <?= $busca ? 'encontrada' : 'ainda' ?>.</td></tr>
        <?php endif; ?>
        <?php foreach ($vendas as $v): ?>
            <?php
            $resumo = finResumoLancamentosVenda((int)$v['id']);
            $partes = listarEntradaPartesVenda((int)$v['id']);
            ?>
            <tr>
                <td>
                    <?php if ($v['veiculo_marca'] || $v['veiculo_modelo']): ?>
                        <?= e(trim($v['veiculo_marca'] . ' ' . $v['veiculo_modelo'])) ?> <?= e((string)($v['veiculo_ano'] ?? '')) ?>
                        <br><small><?= e($v['veiculo_placa'] ?: '—') ?></small>
                    <?php else: ?>
                        <small style="color:var(--texto-fraco)">🔍 ainda não vinculado</small>
                    <?php endif; ?>
                </td>
                <td>
                    <?= e($v['comprador_nome'] ?: '(sem nome ainda)') ?><br><small><?= e($v['comprador_telefone'] ?: '') ?></small>
                </td>
                <td>
                    <?= $v['preco_venda'] !== null ? moedaPromissoria((float)$v['preco_venda']) : '—' ?>
                    <?php if ($v['valor_pago_contratacao']): ?>
                        <br><small>entrada <?= moedaPromissoria((float)$v['valor_pago_contratacao']) ?><?= count($partes) > 1 ? ' em ' . count($partes) . ' partes' : '' ?></small>
                    <?php endif; ?>
                    <?php if ($v['bem_troca_recebido']): ?>
                        <br><small>🔁 + bem de troca (<?= moedaPromissoria((float)($v['bem_troca_valor'] ?? 0)) ?>)</small>
                    <?php endif; ?>
                </td>
                <td><span class="badge"><?= e(etapaVendaLabel($v['etapa'])) ?></span></td>
                <td>
                    <?php if ($resumo['qtd'] === 0): ?>
                        <small style="color:var(--texto-fraco)">sem lançamento ainda</small>
                    <?php else: ?>
                        <small>✅ pago <?= moedaPromissoria($resumo['total_pago']) ?></small><br>
                        <small>⏳ pendente <?= moedaPromissoria($resumo['total_pendente']) ?></small>
                        <?php if ($resumo['atrasados'] > 0): ?>
                            <br><span class="badge badge-atraso">⚠️ <?= $resumo['atrasados'] ?> atrasada(s)</span>
                        <?php endif; ?>
                    <?php endif; ?>
                </td>
                <td>
                    <a href="/admin/venda.php?id=<?= (int)$v['id'] ?>">Abrir →</a>
                    <?php if ($v['etapa'] === 'vendido' && $resumo['qtd'] === 0 && $perfil !== 'supervisor'): ?>
                        <form method="post" style="display:inline-block;margin-top:4px" onsubmit="return confirm('Gerar receita/comissão dessa venda no financeiro agora?');">
                            <?= csrfField() ?>
                            <input type="hidden" name="acao" value="gerar_financeiro">
                            <input type="hidden" name="venda_id" value="<?= (int)$v['id'] ?>">
                            <button type="submit" class="btn-texto">💳 Gerar no financeiro</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php renderPaginacao($totalFiltrado); ?>
</div>
</main>
<?php include __DIR__ . '/_pwa_register.php'; ?>
<?php include __DIR__ . '/_notify.php'; ?>
<?php include __DIR__ . '/_zapi_status.php'; ?>
</body>
</html>
