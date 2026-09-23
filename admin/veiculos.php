<?php
/**
 * Veículos — frota comprada pela Fastcar (bloco 8, etapa='fechado').
 * Pedido explícito: buscar veículo por placa/chassi e ver de quem foi
 * comprado, quando, por quanto e há quantos meses está com a Fastcar.
 * Restrito ao super_admin, mesma trava das outras telas de relatório.
 *
 * "Vender este veículo" (15/09/2026, módulo de vendas) abre uma negociação
 * nova (includes/vendas.php::criarVenda()) pro veículo clicado e manda
 * direto pro detalhe (admin/venda.php) — busca por placa/chassi já existia
 * de propósito desde antes, pensando exatamente nisso: dar pra relacionar
 * o mesmo veículo físico com uma revenda sem remodelar nada.
 */

require_once __DIR__ . '/_bootstrap.php';
requireSuperAdmin();

$db = getDB();
$busca = trim((string)($_GET['busca'] ?? ''));
$erro = '';

$sucesso = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'iniciar_venda') {
    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        $erro = 'Sessão expirada, recarregue a página e tente de novo.';
    } else {
        try {
            $vendaId = criarVenda((int)$_POST['oportunidade_id'], (int)$_SESSION['admin_id']);
            header('Location: /admin/venda.php?id=' . $vendaId);
            exit;
        } catch (Throwable $e) {
            $erro = $e->getMessage();
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['acao'] ?? '', ['marcar_quitado', 'desmarcar_quitado'], true)) {
    // 19/09/2026, "ter botão veiculo quitado" — flag manual de que o
    // financiamento do banco (assumido na compra) já foi pago de verdade.
    // Simples toggle, sem formulário — a decisão de quando está quitado é
    // sempre humana (regra #3), o sistema nunca infere isso sozinho.
    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        $erro = 'Sessão expirada, recarregue a página e tente de novo.';
    } else {
        $quitar = ($_POST['acao'] === 'marcar_quitado');
        $db->prepare("
            UPDATE oportunidades SET financiamento_quitado = ?, financiamento_quitado_em = ? WHERE id = ?
        ")->execute([$quitar ? 1 : 0, $quitar ? date('Y-m-d H:i:s') : null, (int)$_POST['oportunidade_id']]);
        $sucesso = $quitar ? 'Veículo marcado como financiamento quitado.' : 'Veículo voltou a constar como financiamento em aberto.';
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'cadastrar_manual') {
    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        $erro = 'Sessão expirada, recarregue a página e tente de novo.';
    } else {
        try {
            $valorPagoPost = trim((string)($_POST['valor_final'] ?? ''));
            $r = criarVeiculoManualFrota(
                (string)($_POST['vendedor_nome'] ?? ''),
                (string)($_POST['vendedor_telefone'] ?? ''),
                (string)($_POST['veiculo_marca'] ?? ''),
                (string)($_POST['veiculo_modelo'] ?? ''),
                (string)($_POST['veiculo_ano'] ?? ''),
                (string)($_POST['veiculo_placa'] ?? ''),
                (string)($_POST['veiculo_chassi'] ?? ''),
                (string)($_POST['veiculo_renavam'] ?? ''),
                $valorPagoPost !== '' ? (float)str_replace(',', '.', preg_replace('/[^\d,.-]/', '', $valorPagoPost)) : null,
                (int)$_SESSION['admin_id'],
                !empty($_POST['responsavel_id']) ? (int)$_POST['responsavel_id'] : null
            );
            header('Location: /admin/veiculo_midias.php?id=' . $r['oportunidade_id'] . '&recem_cadastrado=1');
            exit;
        } catch (Throwable $e) {
            $erro = $e->getMessage();
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'excluir_veiculo') {
    // 23/09/2026, "permita super admin excuir veiculo veio duas bianca que
    // veio do outro sistema" — ver excluirVeiculoFrota() (includes/oportunidades.php)
    // pros guards (nunca apaga com venda ativa/concluída, dinheiro já pago,
    // contrato assinado ou termo de vistoria já enviado).
    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        $erro = 'Sessão expirada, recarregue a página e tente de novo.';
    } else {
        $opIdExcluir = (int)$_POST['oportunidade_id'];
        $r = excluirVeiculoFrota($opIdExcluir);
        if ($r['ok']) {
            auditoriaRegistrar('veiculo_excluido', (int)$_SESSION['admin_id'], (string)$_SESSION['admin_nome'], 'oportunidade', $opIdExcluir);
            $sucesso = 'Veículo excluído da frota.';
        } else {
            $erro = $r['erro'];
        }
    }
}

$consultoresParaCompra = array_values(array_filter(
    listarUsuarios(true),
    fn($u) => in_array($u['perfil'], ['consultor', 'super_admin'], true)
));

// 19/09/2026, "usa dados da assinatura do contrato" — o "tempo que o
// veículo está com a Fastcar" passou a preferir a data REAL de assinatura
// do contrato de compra (contratos.assinado_em, o momento em que a
// obrigação de quitar o financiamento junto ao banco começa de verdade),
// caindo pra data_compra/updated_at só quando não existe contrato assinado
// (ex: veículo cadastrado manualmente na frota, nunca passou pelo funil
// normal de compra — ver criarVeiculoManualFrota()).
$refPosseExpr = "COALESCE(ctr.assinado_em, o.data_compra, o.updated_at)";
$diasPosseExpr = "CAST((julianday('now','localtime') - julianday({$refPosseExpr})) AS INTEGER)";
$joinContratoAssinado = "
    LEFT JOIN (
        SELECT oportunidade_id, MAX(assinado_em) AS assinado_em
        FROM contratos WHERE tipo = 'compra' AND assinado_em IS NOT NULL
        GROUP BY oportunidade_id
    ) ctr ON ctr.oportunidade_id = o.id
";

// "ter aba veiculos perto de negociar financiamento apartir 12 meses 18 24"
// — 3 faixas (dias aproximados: 12/18/24 meses × 30,44 dias), mesmo padrão
// de badge clicável já usado em admin/financeiro-lancamentos.php pro atraso
// de cobrança. Só entra veículo com financiamento AINDA em aberto — uma vez
// marcado quitado (botão "✅ Marcar quitado"), some do alerta sozinho.
$fPrazo = (int)($_GET['prazo'] ?? 0);
$faixasPrazo = ['12' => [365, 547], '18' => [548, 729], '24' => [730, 999999]];

// 19/09/2026, achado direto no import do CRM antigo (Yaqar/IACAR):
// "temos que descobrir clientes não está completo, eliminar ele do
// estoque" — o import (includes/importar_crm_antigo.php) cria a
// oportunidade já em etapa='fechado' sem passar pelo checklist de
// fechamento normal (regra #7, mudarEtapa()), então um cliente que perdeu
// documento no meio de uma rodada com "database is locked" fica na Frota
// como se fosse um veículo comprado normal, mas sem CNH/comprovante/
// contrato — nunca deveria aparecer pra vender/negociar assim. Filtro
// aplica só em quem tem o marcador de histórico do import antigo
// (`Importado do CRM antigo`) — nunca em veículo do funil normal (esse já
// é estruturalmente impossível de chegar em 'fechado' com documento
// faltando, mudarEtapa() trava isso) nem no cadastro manual de frota
// legada (criarVeiculoManualFrota(), que nunca teve esses documentos como
// exigência — usa fotos/vídeos de revenda, coisa diferente). Escondido por
// padrão (nunca apagado — "só esconder da listagem", confirmado com o
// usuário), com um link pra ver também os incompletos quando precisar
// resolver um por um.
$condIncompletoImportAntigo = "(
    EXISTS (SELECT 1 FROM oportunidade_historico oh WHERE oh.oportunidade_id = o.id AND oh.observacao LIKE 'Importado do CRM antigo%')
    AND (
        (SELECT COUNT(*) FROM oportunidade_documentos od WHERE od.oportunidade_id = o.id AND od.obrigatorio = 1) = 0
        OR (SELECT COUNT(*) FROM oportunidade_documentos od WHERE od.oportunidade_id = o.id AND od.obrigatorio = 1
                AND ((od.arquivo_url IS NOT NULL AND od.arquivo_url != '') OR (od.drive_file_id IS NOT NULL AND od.drive_file_id != '')))
           < (SELECT COUNT(*) FROM oportunidade_documentos od WHERE od.oportunidade_id = o.id AND od.obrigatorio = 1)
    )
)";
$mostrarIncompletos = ($_GET['incompletos'] ?? '') === '1';

$where = "WHERE o.etapa = 'fechado'";
$params = [];
if ($busca !== '') {
    $where .= " AND (o.veiculo_placa LIKE ? OR o.veiculo_chassi LIKE ? OR o.veiculo_marca LIKE ? OR o.veiculo_modelo LIKE ? OR c.nome LIKE ?)";
    $like = '%' . $busca . '%';
    $params = [$like, $like, $like, $like, $like];
}
if (isset($faixasPrazo[(string)$fPrazo])) {
    [$diasMin, $diasMax] = $faixasPrazo[(string)$fPrazo];
    $where .= " AND o.financiamento_quitado = 0 AND {$diasPosseExpr} BETWEEN {$diasMin} AND {$diasMax}";
}
if (!$mostrarIncompletos) {
    $where .= " AND NOT {$condIncompletoImportAntigo}";
}

// Contagem separada (sem o filtro de incompletos) pro link "ver também
// incompletos" mostrar quantos estão escondidos agora.
$stmtIncompletos = $db->prepare("SELECT COUNT(*) FROM oportunidades o {$joinContratoAssinado} WHERE o.etapa = 'fechado' AND {$condIncompletoImportAntigo}");
$stmtIncompletos->execute();
$totalIncompletosImportAntigo = (int)$stmtIncompletos->fetchColumn();

$stmtTotal = $db->prepare("SELECT COUNT(*) FROM oportunidades o JOIN clientes c ON c.id = o.cliente_id {$joinContratoAssinado} {$where}");
$stmtTotal->execute($params);
$totalVeiculos = (int)$stmtTotal->fetchColumn();

$sql = "
    SELECT o.*, c.nome AS cliente_nome, c.telefone AS cliente_telefone,
           vd.id AS venda_id, vd.etapa AS venda_etapa,
           {$refPosseExpr} AS ref_posse,
           (SELECT COUNT(*) FROM veiculo_midias_revenda WHERE oportunidade_id = o.id) AS total_midias,
           {$condIncompletoImportAntigo} AS incompleto_import_antigo
    FROM oportunidades o
    JOIN clientes c ON c.id = o.cliente_id
    {$joinContratoAssinado}
    LEFT JOIN vendas vd ON vd.id = (
        SELECT id FROM vendas WHERE oportunidade_id = o.id AND etapa != 'cancelada'
        ORDER BY CASE etapa WHEN 'vendido' THEN 0 WHEN 'contrato_enviado' THEN 1 ELSE 2 END
        LIMIT 1
    )
    {$where}
    ORDER BY o.data_compra DESC, o.updated_at DESC
    LIMIT " . ITENS_POR_PAGINA_PADRAO . " OFFSET " . paginacaoOffset();

$stmt = $db->prepare($sql);
$stmt->execute($params);
$veiculos = $stmt->fetchAll();

// Soma de TODOS os veículos que batem com a busca, não só os da página
// atual — com paginação, array_sum() em cima de $veiculos somaria só os
// 25 da tela, dando um "total pago" errado assim que passasse de 1 página.
$stmtTotalPago = $db->prepare("SELECT SUM(o.valor_final) FROM oportunidades o JOIN clientes c ON c.id = o.cliente_id {$joinContratoAssinado} {$where}");
$stmtTotalPago->execute($params);
$totalPago = (float)($stmtTotalPago->fetchColumn() ?: 0);

// Contagem por faixa de "perto de negociar financiamento" — sempre global
// (sem filtro de busca/prazo ativo), mesmo espírito dos badges de atraso do
// financeiro: o número do badge sempre reflete o total real da faixa,
// independente do que está sendo visto na tabela no momento.
$bucketsPrazo = ['12' => 0, '18' => 0, '24' => 0];
$rBuckets = $db->query("
    SELECT
        SUM(CASE WHEN {$diasPosseExpr} BETWEEN 365 AND 547 THEN 1 ELSE 0 END) AS b12,
        SUM(CASE WHEN {$diasPosseExpr} BETWEEN 548 AND 729 THEN 1 ELSE 0 END) AS b18,
        SUM(CASE WHEN {$diasPosseExpr} >= 730 THEN 1 ELSE 0 END) AS b24
    FROM oportunidades o
    {$joinContratoAssinado}
    WHERE o.etapa = 'fechado' AND o.financiamento_quitado = 0
")->fetch();
if ($rBuckets) {
    $bucketsPrazo = ['12' => (int)($rBuckets['b12'] ?? 0), '18' => (int)($rBuckets['b18'] ?? 0), '24' => (int)($rBuckets['b24'] ?? 0)];
}

/**
 * Meses inteiros desde a referência de posse (`ref_posse` da query — a
 * data de assinatura do contrato de compra, quando existe; senão
 * data_compra/updated_at, ver comentário no SQL acima) até hoje.
 */
function mesesComAFastcar(?string $refPosse): int {
    if (!$refPosse) return 0;
    $inicio = new DateTime($refPosse);
    $agora = new DateTime();
    $diff = $inicio->diff($agora);
    return $diff->y * 12 + $diff->m;
}
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Veículos — Fastcar CRM</title>
<link rel="stylesheet" href="/admin/assets/style.css?v=<?= @filemtime(__DIR__ . '/assets/style.css') ?: 1 ?>">
<link rel="stylesheet" href="/admin/assets/mobile.css?v=<?= @filemtime(__DIR__ . '/assets/mobile.css') ?: 1 ?>">
<script src="/admin/assets/mobile.js?v=<?= @filemtime(__DIR__ . '/assets/mobile.js') ?: 1 ?>" defer></script>
<?php include __DIR__ . '/_pwa_head.php'; ?>
</head>
<body>
<header class="topbar">
    <a href="/admin/index.php" style="color:#fff">← Voltar</a>
    <strong><img class="topbar-logo" src="/admin/assets/img/icon-192.png" alt="Fastcar" onerror="this.style.display='none'"> Fast<b>Car</b></strong>
    <span>Olá, <?= e($_SESSION['admin_nome']) ?></span>
    <a href="/admin/vendas.php">💰 Vendas</a>
    <a href="/admin/configuracoes.php">⚙️ Configurações</a>
    <a href="/admin/logout.php">Sair</a>
</header>

<main>
<?php if ($erro): ?><div class="alerta-erro"><?= e($erro) ?></div><?php endif; ?>
<?php if ($sucesso): ?><div class="alerta-sucesso"><?= e($sucesso) ?></div><?php endif; ?>
<div class="card">
    <h2>🚗 Veículos comprados</h2>
    <p><small>Frota atual da Fastcar — todo veículo com negócio fechado (bloco 8). Busca por placa, chassi, marca/
       modelo ou nome do vendedor.</small></p>

    <form method="get">
        <input type="text" name="busca" value="<?= e($busca) ?>" placeholder="Placa, chassi, marca/modelo ou vendedor...">
        <button type="submit">Buscar</button>
    </form>
    <?php if ($mostrarIncompletos): ?>
        <p style="margin-top:10px"><small>⚠️ Mostrando também <?= $totalIncompletosImportAntigo ?> veículo(s) importado(s) do CRM antigo com documento incompleto (marcados abaixo).
            <a href="?busca=<?= urlencode($busca) ?>">Esconder de novo →</a></small></p>
    <?php elseif ($totalIncompletosImportAntigo > 0): ?>
        <p style="margin-top:10px"><small>⚠️ <?= $totalIncompletosImportAntigo ?> veículo(s) importado(s) do CRM antigo com documento incompleto estão escondidos desta listagem
            (nunca apagados, só fora do estoque até completar). <a href="?busca=<?= urlencode($busca) ?>&incompletos=1">Ver também incompletos →</a></small></p>
    <?php endif; ?>
</div>

<div class="card">
    <h3>➕ Adicionar veículo manualmente</h3>
    <p><small>Pra veículo que a Fastcar já tem, mas não passou pelo funil de compra pelo WhatsApp (negócio fechado
       fora do CRM, frota legada, etc) — entra direto na frota, pronto pra ganhar fotos/vídeos e ir pro módulo de
       vendas. Continua pedindo o vendedor/origem (nome + telefone), mesma disciplina de cadastro do resto do
       sistema.</small></p>
    <div class="form-group" style="max-width:420px;margin-bottom:14px">
        <label>📄 Subir pelo CRLV (opcional) — a IA lê o documento e preenche os campos do veículo abaixo</label>
        <input type="file" id="mv-crlv-arquivo" accept="image/jpeg,image/png,image/webp,application/pdf">
        <button type="button" onclick="lerCrlvManual()" style="margin-top:.4rem" id="mv-crlv-btn">📄 Ler CRLV com IA</button>
        <span id="mv-crlv-status" style="font-size:.8rem;color:var(--muted);margin-left:.5rem"></span>
    </div>
    <form method="post">
        <?= csrfField() ?>
        <input type="hidden" name="acao" value="cadastrar_manual">
        <div class="grid-2">
            <div>
                <label>Nome do vendedor/origem *</label>
                <input type="text" name="vendedor_nome" required>
                <label>Telefone do vendedor/origem *</label>
                <input type="text" name="vendedor_telefone" required placeholder="Ex: 31999998888">
                <label>Valor pago (R$)</label>
                <input type="text" name="valor_final" placeholder="0,00">
                <label>Consultor responsável pela compra</label>
                <select name="responsavel_id">
                    <option value="">— Eu mesmo (<?= e($_SESSION['admin_nome'] ?? '') ?>) —</option>
                    <?php foreach ($consultoresParaCompra as $u): ?>
                        <option value="<?= (int)$u['id'] ?>"><?= e($u['nome']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Marca</label>
                <input type="text" name="veiculo_marca" id="mv-marca">
                <label>Modelo</label>
                <input type="text" name="veiculo_modelo" id="mv-modelo">
                <label>Ano</label>
                <input type="text" name="veiculo_ano" id="mv-ano" style="max-width:120px">
                <label>Placa</label>
                <input type="text" name="veiculo_placa" id="mv-placa" style="max-width:160px">
                <label>Chassi</label>
                <input type="text" name="veiculo_chassi" id="mv-chassi">
                <label>RENAVAM</label>
                <input type="text" name="veiculo_renavam" id="mv-renavam">
            </div>
        </div>
        <button type="submit">Cadastrar e adicionar fotos/vídeos →</button>
    </form>
</div>

<script>
var csrfTokenVeiculos = <?= json_encode(generateCSRF()) ?>;

function lerCrlvManual() {
    var input = document.getElementById('mv-crlv-arquivo');
    if (!input.files.length) { alert('Escolha o arquivo do CRLV primeiro.'); return; }
    var status = document.getElementById('mv-crlv-status');
    var btn = document.getElementById('mv-crlv-btn');
    status.textContent = '🔄 Lendo CRLV...';
    btn.disabled = true;

    var fd = new FormData();
    fd.append('crlv', input.files[0]);
    fd.append('csrf_token', csrfTokenVeiculos);
    fetch('/admin/veiculo_crlv_ajax.php', { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            btn.disabled = false;
            if (!d.ok) { status.textContent = '⚠️ ' + d.erro; return; }
            // fill-if-empty — nunca sobrescreve o que já foi digitado/corrigido na mão.
            var campos = { marca: d.veiculo_marca, modelo: d.veiculo_modelo, ano: d.veiculo_ano, placa: d.veiculo_placa, chassi: d.veiculo_chassi, renavam: d.veiculo_renavam };
            Object.keys(campos).forEach(function (k) {
                var el = document.getElementById('mv-' + k);
                if (el && !el.value && campos[k]) el.value = campos[k];
            });
            status.textContent = '✅ Preenchido! Confira antes de cadastrar.';
        })
        .catch(function (err) { btn.disabled = false; status.textContent = '⚠️ Erro ao ler o CRLV.'; console.error(err); });
}
</script>

<div class="stat-grid">
    <div class="stat-card">
        <div class="valor"><?= $totalVeiculos ?></div>
        <div class="rotulo">Veículos na frota</div>
    </div>
    <div class="stat-card sucesso">
        <div class="valor">R$ <?= number_format($totalPago, 2, ',', '.') ?></div>
        <div class="rotulo">Total pago aos vendedores</div>
    </div>
</div>

<div class="card">
    <div style="font-size:.8rem;color:var(--muted);font-weight:600;margin-bottom:.5rem">
        ⏰ Perto de negociar financiamento — tempo com a Fastcar desde a assinatura do contrato de compra
        (financiamento ainda em aberto)
    </div>
    <div style="display:flex;gap:.75rem;flex-wrap:wrap">
        <?php
        $baseQsPrazo = $busca ? ('busca=' . urlencode($busca)) : '';
        $rotulosPrazo = ['12' => '12 a 17 meses', '18' => '18 a 23 meses', '24' => '24+ meses'];
        ?>
        <?php foreach ($rotulosPrazo as $n => $lbl): ?>
            <a href="?<?= $baseQsPrazo ?><?= $baseQsPrazo ? '&' : '' ?>prazo=<?= $n ?>" class="btn<?= $fPrazo === (int)$n ? '-primary' : '' ?>" style="width:auto;text-decoration:none">
                ⏰ <?= $lbl ?> — <strong><?= $bucketsPrazo[$n] ?></strong>
            </a>
        <?php endforeach; ?>
        <?php if ($fPrazo): ?><a href="?<?= $baseQsPrazo ?>" style="align-self:center">Ver todos os veículos</a><?php endif; ?>
    </div>
</div>

<div class="card">
    <table class="tabela-oportunidades">
        <thead>
            <tr>
                <th>Veículo</th><th>Placa / Chassi</th><th>Comprado de</th>
                <th>Valor pago</th><th>Data da compra</th><th>Meses com a Fastcar</th>
                <th>Contrato compra</th><th>Financiamento</th><th>Fotos/vídeos</th><th>Venda</th><th></th><th></th>
            </tr>
        </thead>
        <tbody>
        <?php if (!$veiculos): ?>
            <tr><td colspan="12"><?= $busca ? 'Nenhum veículo encontrado pra essa busca.' : 'Nenhum veículo comprado ainda.' ?></td></tr>
        <?php endif; ?>
        <?php foreach ($veiculos as $v): ?>
            <?php
            $mesesFastcar = mesesComAFastcar($v['ref_posse']);
            $diasFastcar = $v['ref_posse'] ? (int)((strtotime('now') - strtotime($v['ref_posse'])) / 86400) : 0;
            $faixaAlerta = null;
            if (!$v['financiamento_quitado']) {
                if ($diasFastcar >= 730) $faixaAlerta = '24+';
                elseif ($diasFastcar >= 548) $faixaAlerta = '18+';
                elseif ($diasFastcar >= 365) $faixaAlerta = '12+';
            }
            ?>
            <tr<?= $v['incompleto_import_antigo'] ? ' class="linha-sem-vinculo"' : '' ?>>
                <td><?= e(trim($v['veiculo_marca'] . ' ' . $v['veiculo_modelo'])) ?: '—' ?> <?= e($v['veiculo_ano']) ?>
                    <?php if ($v['incompleto_import_antigo']): ?><br><span class="badge badge-aviso" title="Importado do CRM antigo, faltando documento obrigatório">⚠️ doc. incompleto</span><?php endif; ?>
                </td>
                <td><?= e($v['veiculo_placa'] ?: '—') ?><?php if ($v['veiculo_chassi']): ?><br><small><?= e($v['veiculo_chassi']) ?></small><?php endif; ?></td>
                <td><a href="/admin/cliente_detalhe.php?id=<?= (int)$v['cliente_id'] ?>"><?= e($v['cliente_nome']) ?></a><br><small><?= e($v['cliente_telefone']) ?></small></td>
                <td><?= $v['valor_final'] !== null ? 'R$ ' . number_format((float)$v['valor_final'], 2, ',', '.') : '—' ?></td>
                <td><?= $v['data_compra'] ? date('d/m/Y', strtotime($v['data_compra'])) : '—' ?></td>
                <td>
                    <?= $mesesFastcar ?> mês(es)
                    <?php if ($faixaAlerta): ?><br><span class="badge badge-aviso">⏰ <?= $faixaAlerta ?> meses</span><?php endif; ?>
                </td>
                <td>
                    <?php if ($v['contrato_assinado'] ?? false): ?>
                        <span class="badge badge-ok">✅ assinado</span>
                    <?php else: ?>
                        <span class="badge badge-aviso">pendente</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($v['financiamento_quitado']): ?>
                        <span class="badge badge-ok">✅ quitado</span>
                        <?php if ($v['financiamento_quitado_em']): ?><br><small><?= date('d/m/Y', strtotime($v['financiamento_quitado_em'])) ?></small><?php endif; ?><br>
                        <form method="post" class="inline">
                            <?= csrfField() ?>
                            <input type="hidden" name="acao" value="desmarcar_quitado">
                            <input type="hidden" name="oportunidade_id" value="<?= (int)$v['id'] ?>">
                            <button type="submit" style="margin-top:2px;padding:3px 8px;font-size:11px" class="secundario">↩️ Desfazer</button>
                        </form>
                    <?php else: ?>
                        <span class="badge">⏳ em aberto</span><br>
                        <form method="post" class="inline">
                            <?= csrfField() ?>
                            <input type="hidden" name="acao" value="marcar_quitado">
                            <input type="hidden" name="oportunidade_id" value="<?= (int)$v['id'] ?>">
                            <button type="submit" style="margin-top:2px;padding:3px 8px;font-size:11px">✅ Marcar quitado</button>
                        </form>
                    <?php endif; ?>
                </td>
                <td><a href="/admin/veiculo_midias.php?id=<?= (int)$v['id'] ?>">📸 <?= (int)$v['total_midias'] ?></a></td>
                <td>
                    <?php if ($v['venda_id'] === null): ?>
                        <form method="post" class="inline">
                            <?= csrfField() ?>
                            <input type="hidden" name="acao" value="iniciar_venda">
                            <input type="hidden" name="oportunidade_id" value="<?= (int)$v['id'] ?>">
                            <button type="submit" style="margin-top:0;padding:5px 10px;font-size:12px">💰 Vender</button>
                        </form>
                    <?php elseif ($v['venda_etapa'] === 'vendido'): ?>
                        <a href="/admin/venda.php?id=<?= (int)$v['venda_id'] ?>"><span class="badge badge-ok">✅ vendido</span></a>
                    <?php else: ?>
                        <a href="/admin/venda.php?id=<?= (int)$v['venda_id'] ?>"><span class="badge"><?= e(etapaVendaLabel($v['venda_etapa'])) ?></span></a>
                    <?php endif; ?>
                </td>
                <td><a href="/admin/oportunidade.php?id=<?= (int)$v['id'] ?>">Abrir →</a></td>
                <td>
                    <form method="post" class="inline" onsubmit="return confirm('Excluir este veículo da frota? Ação sem volta — nunca funciona se já tiver venda/contrato assinado/dinheiro pago vinculado.');">
                        <?= csrfField() ?>
                        <input type="hidden" name="acao" value="excluir_veiculo">
                        <input type="hidden" name="oportunidade_id" value="<?= (int)$v['id'] ?>">
                        <button type="submit" style="margin-top:0;padding:5px 8px;font-size:12px;background:#dc2626" title="Excluir veículo da frota">🗑️</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php renderPaginacao($totalVeiculos); ?>
</div>
</main>
<?php include __DIR__ . '/_pwa_register.php'; ?>
<?php include __DIR__ . '/_notify.php'; ?>
<?php include __DIR__ . '/_zapi_status.php'; ?>
</body>
</html>
