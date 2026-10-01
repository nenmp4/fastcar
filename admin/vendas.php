<?php
/**
 * Vendas — pipeline de revenda de veículos já comprados (frota). Cada
 * linha é uma negociação com um comprador (includes/vendas.php); nasce a
 * partir de admin/veiculos.php (botão "Vender" num veículo disponível) OU,
 * desde 17/09/2026, sozinha pela instância Z-API dedicada de vendas
 * (comprador entra pelo WhatsApp, qualificado por IA — ver
 * includes/ia_qualificacao_vendas.php) — as duas origens convivem no mesmo
 * pipeline. Também serve de DASHBOARD do vendedor (mesmo espírito de
 * admin/index.php pro funil de compra): cards de resultado + funil filtrado
 * por responsável.
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../includes/dashboard.php';
requireAcessoVendas();

$db = getDB();
$perfil = $_SESSION['admin_perfil'];
$meuId = (int)$_SESSION['admin_id'];
// vendedor só vê a própria carteira; super_admin/supervisor veem tudo.
$souDono = $perfil === 'vendedor';

/*
 * "Vender na Promissória" (26/09/2026) — modal único que registra a
 * negociação inteira numa passada só, consolidando o que hoje vive
 * espalhado em vários cards separados de admin/venda.php (comprador,
 * condições, entrada em partes, bem de troca) — "mesma coisa do antigo".
 * Reaproveita registrarVendaPromissoria() (includes/vendas.php), que só
 * orquestra as mesmas funções já testadas, na ordem certa. Nunca dispara
 * o link de documentos automaticamente (confirmado com o usuário) — isso
 * continua um clique separado, feito depois em admin/venda.php.
 *
 * Se o veículo não estiver na frota disponível (checkbox
 * "cadastrar_veiculo_novo"), cadastra primeiro via criarVeiculoManualFrota()
 * (mesma função já usada em admin/veiculos.php, inclusive a leitura de
 * CRLV por IA — admin/veiculo_crlv_ajax.php, cujo guard foi relaxado de
 * requireSuperAdmin() pra requireAcessoVendas() pra funcionar aqui também)
 * e usa o id recém-criado como veículo da venda.
 */
$erroPromissoria = '';
$modalPromissoriaAbrir = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'vender_promissoria') {
    $modalPromissoriaAbrir = true;
    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        $erroPromissoria = 'Sessão expirada, recarregue a página e tente de novo.';
    } elseif ($perfil === 'supervisor') {
        http_response_code(403);
        exit('Ação não permitida — perfil de acompanhamento só consulta.');
    } else {
        try {
            $oportunidadeIdEscolhida = (int)($_POST['oportunidade_id'] ?? 0);

            if (!empty($_POST['cadastrar_veiculo_novo'])) {
                $novoVeiculo = criarVeiculoManualFrota(
                    (string)($_POST['vendedor_nome'] ?? ''),
                    (string)($_POST['vendedor_telefone'] ?? ''),
                    (string)($_POST['veiculo_marca'] ?? ''),
                    (string)($_POST['veiculo_modelo'] ?? ''),
                    (string)($_POST['veiculo_ano'] ?? ''),
                    (string)($_POST['veiculo_placa'] ?? ''),
                    (string)($_POST['veiculo_chassi'] ?? ''),
                    (string)($_POST['veiculo_renavam'] ?? ''),
                    valorMonetario((string)($_POST['veiculo_valor_final'] ?? '')),
                    $meuId
                );
                $oportunidadeIdEscolhida = (int)$novoVeiculo['oportunidade_id'];
            }

            $entradaPartesPost = [];
            $valoresPartesPost = $_POST['parte_valor'] ?? [];
            $datasPartesPost = $_POST['parte_data'] ?? [];
            foreach ((array)$valoresPartesPost as $i => $valorBruto) {
                $valorParte = valorMonetario((string)$valorBruto);
                if ($valorParte === null || $valorParte <= 0) continue;
                $entradaPartesPost[] = ['valor' => $valorParte, 'data_prevista' => (string)($datasPartesPost[$i] ?? '') ?: null];
            }

            $bemTrocaPost = [
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
            ];

            $parcelamentoPost = null;
            $vpParcela = valorMonetario((string)($_POST['parcelamento_valor_parcela'] ?? ''));
            $qtdParcelasPost = (int)($_POST['parcelamento_qtd_parcelas'] ?? 0);
            $primeiraParcelaPost = (string)($_POST['parcelamento_primeira_parcela_data'] ?? '');
            if ($vpParcela && $vpParcela > 0 && $qtdParcelasPost > 0 && $primeiraParcelaPost !== '') {
                $parcelamentoPost = ['valor_parcela' => $vpParcela, 'qtd_parcelas' => $qtdParcelasPost, 'primeira_parcela_data' => $primeiraParcelaPost];
            }

            $resultadoPromissoria = registrarVendaPromissoria([
                'oportunidade_id' => $oportunidadeIdEscolhida,
                'comprador_nome' => (string)($_POST['comprador_nome'] ?? ''),
                'comprador_telefone' => (string)($_POST['comprador_telefone'] ?? ''),
                'comprador_cpf' => (string)($_POST['comprador_cpf'] ?? ''),
                'comprador_rg' => (string)($_POST['comprador_rg'] ?? ''),
                'comprador_email' => (string)($_POST['comprador_email'] ?? ''),
                'preco_venda' => valorMonetario((string)($_POST['preco_venda'] ?? '')),
                'forma_pagamento' => (string)($_POST['forma_pagamento'] ?? ''),
                'saldo_preco_devido' => valorMonetario((string)($_POST['saldo_preco_devido'] ?? '')),
                'prazo_quitacao_meses' => $_POST['prazo_quitacao_meses'] !== '' ? (int)$_POST['prazo_quitacao_meses'] : null,
                'entrada_partes' => $entradaPartesPost,
                'bem_troca' => $bemTrocaPost,
                'parcelamento' => $parcelamentoPost,
            ], $meuId);

            header('Location: /admin/venda.php?id=' . $resultadoPromissoria['venda_id'] . '&criado=1');
            exit;
        } catch (Throwable $e) {
            $erroPromissoria = $e->getMessage();
        }
    }
}

// 29/09/2026, mesmo pedido do lado de compra (ver admin/index.php) —
// seleção em massa na tabela, pra cancelar várias negociações de uma vez.
// Reaproveita cancelarVendaEmMassa() (includes/vendas.php) — nunca apaga
// nada, só encerra com motivo/histórico via mudarEtapaVenda(), mesma
// disciplina de "cancelar" de sempre.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'cancelar_venda_massa') {
    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        exit('Sessão expirada, recarregue a página.');
    }
    if ($perfil === 'supervisor') {
        http_response_code(403);
        exit('Ação não permitida — perfil de acompanhamento só consulta.');
    }
    $idsPost = array_map('intval', (array)($_POST['ids'] ?? []));
    $motivoPost = clean((string)($_POST['motivo'] ?? ''));
    $bulkQs = [];
    if (!$idsPost) {
        $bulkQs = ['bulk_erro' => 'Nenhuma negociação selecionada.'];
    } elseif ($motivoPost === '') {
        $bulkQs = ['bulk_erro' => 'Motivo é obrigatório.'];
    } else {
        try {
            $r = cancelarVendaEmMassa($idsPost, $motivoPost, $meuId, $souDono ? $meuId : null);
            $bulkQs = ['bulk_sucesso' => $r['sucesso'], 'bulk_ignorados' => $r['ignorados']];
        } catch (Throwable $e) {
            $bulkQs = ['bulk_erro' => $e->getMessage()];
        }
    }
    $qs = $_GET;
    unset($qs['bulk_sucesso'], $qs['bulk_ignorados'], $qs['bulk_erro']);
    header('Location: /admin/vendas.php?' . http_build_query(array_merge($qs, $bulkQs)));
    exit;
}

// 01/10/2026, "adicionar campo para recado tanto na compra e revenda" —
// mesma mecânica de admin/index.php (atualizarObservacaoManual()): texto
// livre editável direto na linha, só enquanto a negociação ainda está
// ativa; nunca é mudança de etapa, nunca grava histórico.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'atualizar_observacao_manual') {
    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        exit('Sessão expirada, recarregue a página.');
    }
    if ($perfil === 'supervisor') {
        http_response_code(403);
        exit('Perfil de supervisão só acompanha, não altera negociações.');
    }
    $idObsPost = (int)($_POST['id'] ?? 0);
    $obsPost = (string)($_POST['observacao'] ?? '');
    try {
        if ($souDono) {
            $stmtDonoObs = $db->prepare('SELECT responsavel_id FROM vendas WHERE id = ?');
            $stmtDonoObs->execute([$idObsPost]);
            $vDonoObs = $stmtDonoObs->fetch();
            if (!$vDonoObs || (int)$vDonoObs['responsavel_id'] !== $meuId) {
                throw new RuntimeException('Essa negociação não é da sua carteira.');
            }
        }
        atualizarObservacaoManualVenda($idObsPost, $obsPost);
    } catch (Throwable $e) {
        // best-effort — se falhar (id inválido, não é dono), só não salva.
    }
    header('Location: /admin/vendas.php?' . http_build_query($_GET));
    exit;
}

$frotaDisponivelPromissoria = listarFrotaDisponivelParaVenda();

$etapaFiltro = (string)($_GET['etapa'] ?? '');
$busca = trim((string)($_GET['q'] ?? ''));

// 19/09/2026, "aproveita adciona dasbord também em vendas igual de compras
// etapas igual de compras" — mesmo padrão de admin/index.php: nav sempre
// limitada a ETAPAS_VENDA_ATIVAS não deixava achar/buscar negociação já
// 'vendido' nem 'cancelada'/'sem_perfil' (nenhum lugar listava). `?etapa=`
// reaproveita o mesmo valor real da etapa terminal ('vendido', igual
// compra usa 'fechado') como aba especial, e 'encerradas' cobre
// cancelada+sem_perfil juntos — cada uma monta seu próprio escopo de
// etapa pro WHERE em vez de sempre ETAPAS_VENDA_ATIVAS.
$etapasEscopo = match ($etapaFiltro) {
    'vendido'    => ['vendido'],
    'encerradas' => ['cancelada', 'sem_perfil'],
    default      => ETAPAS_VENDA_ATIVAS,
};
$placeholders = implode(',', array_fill(0, count($etapasEscopo), '?'));

// Vendas nunca teve um "fechado_por" próprio (diferente de compra) — só
// responsavel_id existe, então as 3 abas (ativas/vendido/encerradas) usam
// o mesmo campo de dono, sem distinção.
$where = "WHERE v.etapa IN ({$placeholders})";
$params = $etapasEscopo;
if ($souDono) {
    $where .= " AND v.responsavel_id = ?";
    $params[] = $meuId;
}
if (in_array($etapaFiltro, ETAPAS_VENDA_ATIVAS, true)) {
    $where .= " AND v.etapa = ?";
    $params[] = $etapaFiltro;
}
if ($busca !== '') {
    $where .= " AND (v.comprador_nome LIKE ? OR v.comprador_telefone LIKE ? OR o.veiculo_marca LIKE ? OR o.veiculo_modelo LIKE ? OR v.veiculo_interesse_texto LIKE ?)";
    $like = '%' . $busca . '%';
    array_push($params, $like, $like, $like, $like, $like);
}

// LEFT JOIN — desde 17/09/2026 uma negociação pode não ter veículo
// vinculado ainda (lead recém-entrado pelo WhatsApp, ver
// includes/vendas.php::criarOuAbrirVendaLead()), diferente do JOIN
// original (que assumia oportunidade_id sempre preenchido).
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
    ORDER BY CASE v.temperatura_lead WHEN 'quente' THEN 0 WHEN 'morno' THEN 1 WHEN 'frio' THEN 2 ELSE 3 END,
             (v.proxima_acao_em IS NULL), v.proxima_acao_em ASC, v.created_at DESC
    LIMIT " . ITENS_POR_PAGINA_PADRAO . " OFFSET " . paginacaoOffset();
$stmt = $db->prepare($sql);
$stmt->execute($params);
$vendas = $stmt->fetchAll();

// Contadores da nav das etapas ATIVAS — sempre contra ETAPAS_VENDA_ATIVAS
// (nunca $placeholders/$etapasEscopo, que podem estar reduzidos a só
// 'vendido'/'encerradas' quando uma dessas abas está selecionada — bug
// real já corrigido uma vez no mesmo padrão em admin/index.php: usar a
// mesma variável pro `IN(...)` da nav quebraria o número de binds).
$placeholdersAtivas = implode(',', array_fill(0, count(ETAPAS_VENDA_ATIVAS), '?'));
$sqlContagem = "SELECT v.etapa, COUNT(*) AS total FROM vendas v LEFT JOIN oportunidades o ON o.id = v.oportunidade_id
                WHERE v.etapa IN ({$placeholdersAtivas})";
$paramsContagem = ETAPAS_VENDA_ATIVAS;
if ($souDono) {
    $sqlContagem .= " AND v.responsavel_id = ?";
    $paramsContagem[] = $meuId;
}
if ($busca !== '') {
    $sqlContagem .= " AND (v.comprador_nome LIKE ? OR v.comprador_telefone LIKE ? OR o.veiculo_marca LIKE ? OR o.veiculo_modelo LIKE ? OR v.veiculo_interesse_texto LIKE ?)";
    array_push($paramsContagem, $like, $like, $like, $like, $like);
}
$sqlContagem .= " GROUP BY v.etapa";
$stmtContagem = $db->prepare($sqlContagem);
$stmtContagem->execute($paramsContagem);
$contagemPorEtapa = array_column($stmtContagem->fetchAll(), 'total', 'etapa');
$totalAtivas = array_sum($contagemPorEtapa);

// Contador do badge "✅ Vendidas" — mesmo filtro de dono/busca, independente
// do filtro atual (mesma disciplina do "✅ Fechadas" de admin/index.php).
$sqlVendidas = "SELECT COUNT(*) FROM vendas v LEFT JOIN oportunidades o ON o.id = v.oportunidade_id WHERE v.etapa = 'vendido'";
$paramsVendidas = [];
if ($souDono) { $sqlVendidas .= " AND v.responsavel_id = ?"; $paramsVendidas[] = $meuId; }
if ($busca !== '') {
    $sqlVendidas .= " AND (v.comprador_nome LIKE ? OR v.comprador_telefone LIKE ? OR o.veiculo_marca LIKE ? OR o.veiculo_modelo LIKE ? OR v.veiculo_interesse_texto LIKE ?)";
    array_push($paramsVendidas, $like, $like, $like, $like, $like);
}
$stmtVendidas = $db->prepare($sqlVendidas);
$stmtVendidas->execute($paramsVendidas);
$totalVendidas = (int)$stmtVendidas->fetchColumn();

// Contador do badge "❌ Encerradas" (cancelada + sem_perfil) — mesmo padrão.
$sqlEncerradasVenda = "SELECT COUNT(*) FROM vendas v LEFT JOIN oportunidades o ON o.id = v.oportunidade_id WHERE v.etapa IN ('cancelada', 'sem_perfil')";
$paramsEncerradasVenda = [];
if ($souDono) { $sqlEncerradasVenda .= " AND v.responsavel_id = ?"; $paramsEncerradasVenda[] = $meuId; }
if ($busca !== '') {
    $sqlEncerradasVenda .= " AND (v.comprador_nome LIKE ? OR v.comprador_telefone LIKE ? OR o.veiculo_marca LIKE ? OR o.veiculo_modelo LIKE ? OR v.veiculo_interesse_texto LIKE ?)";
    array_push($paramsEncerradasVenda, $like, $like, $like, $like, $like);
}
$stmtEncerradasVenda = $db->prepare($sqlEncerradasVenda);
$stmtEncerradasVenda->execute($paramsEncerradasVenda);
$totalEncerradasVenda = (int)$stmtEncerradasVenda->fetchColumn();

$stats = match (true) {
    $souDono => dashboardVendedor($meuId),
    default  => dashboardVendasGeral(),
};

function moedaVenda(float $v): string { return 'R$ ' . number_format($v, 2, ',', '.'); }
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Vendas — Fastcar CRM</title>
<link rel="stylesheet" href="/admin/assets/style.css?v=<?= @filemtime(__DIR__ . '/assets/style.css') ?: 1 ?>">
<link rel="stylesheet" href="/admin/assets/mobile.css?v=<?= @filemtime(__DIR__ . '/assets/mobile.css') ?: 1 ?>">
<script src="/admin/assets/mobile.js?v=<?= @filemtime(__DIR__ . '/assets/mobile.js') ?: 1 ?>" defer></script>
<?php include __DIR__ . '/_pwa_head.php'; ?>
</head>
<body>
<?php include __DIR__ . '/_impersonando_banner.php'; ?>
<header class="topbar">
    <a href="/admin/index.php" style="color:#fff">← Voltar</a>
    <a class="topbar-brand" href="<?= e(paginaInicialPorPerfil($_SESSION['admin_perfil'] ?? '')) ?>"><img class="topbar-logo" src="/admin/assets/img/icon-192.png" alt="Fastcar" onerror="this.style.display='none'"><span class="topbar-wordmark">Fast<b>Car</b></span></a>
    <span>Olá, <?= e($_SESSION['admin_nome']) ?></span>
    <a href="/admin/vendas_inbox.php">💬 WhatsApp Vendas</a>
    <a href="/admin/promissorias.php">💳 Promissórias</a>
    <?php if (perfilVeTudo()): ?><a href="/admin/veiculos.php">🚗 Veículos</a><?php endif; ?>
    <a href="/admin/avaliacoes.php">🔍 Vistorias</a>
    <a href="/admin/meu_perfil.php">🙋 Meu perfil</a>
    <a href="/admin/logout.php">Sair</a>
</header>

<nav class="etapas-nav">
    <a href="/admin/vendas.php<?= $busca !== '' ? '?q=' . urlencode($busca) : '' ?>" class="<?= $etapaFiltro === '' ? 'ativo' : '' ?>"><?= $souDono ? 'Minhas' : 'Todas' ?> (<?= (int)$totalAtivas ?>)</a>
    <?php foreach (ETAPAS_VENDA_ATIVAS as $et): ?>
        <a href="/admin/vendas.php?etapa=<?= urlencode($et) ?><?= $busca !== '' ? '&q=' . urlencode($busca) : '' ?>" class="<?= $etapaFiltro === $et ? 'ativo' : '' ?>">
            <?= e(etapaVendaLabel($et)) ?> (<?= (int)($contagemPorEtapa[$et] ?? 0) ?>)
        </a>
    <?php endforeach; ?>
    <a href="/admin/vendas.php?etapa=vendido<?= $busca !== '' ? '&q=' . urlencode($busca) : '' ?>" class="<?= $etapaFiltro === 'vendido' ? 'ativo' : '' ?>">✅ Vendidas (<?= $totalVendidas ?>)</a>
    <a href="/admin/vendas.php?etapa=encerradas<?= $busca !== '' ? '&q=' . urlencode($busca) : '' ?>" class="<?= $etapaFiltro === 'encerradas' ? 'ativo' : '' ?>">❌ Encerradas (<?= $totalEncerradasVenda ?>)</a>
</nav>

<main>
<?php if ($erroPromissoria): ?><div class="alerta-erro"><?= e($erroPromissoria) ?></div><?php endif; ?>
<?php if (isset($_GET['bulk_sucesso'])): ?>
    <div class="alerta-sucesso">✅ <?= (int)$_GET['bulk_sucesso'] ?> negociação(ões) cancelada(s)<?= isset($_GET['bulk_ignorados']) && (int)$_GET['bulk_ignorados'] > 0 ? ' — ' . (int)$_GET['bulk_ignorados'] . ' ignorada(s) (fora da carteira ou já encerrada)' : '' ?>.</div>
<?php elseif (isset($_GET['bulk_erro'])): ?>
    <div class="alerta-erro">⚠️ <?= e((string)$_GET['bulk_erro']) ?></div>
<?php endif; ?>

<div style="display:flex;justify-content:flex-end;margin-bottom:1rem">
    <button type="button" class="btn-primary" style="width:auto" onclick="document.getElementById('modal-promissoria').showModal()">💳 Vender na Promissória</button>
</div>

<?php
    $postVp = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : [];
    $vpSemFrota = !$frotaDisponivelPromissoria;
    $vpCadastrarNovo = !empty($postVp['cadastrar_veiculo_novo']) || $vpSemFrota;

    $vpPartes = [];
    if (!empty($postVp['parte_valor'])) {
        foreach ((array)$postVp['parte_valor'] as $i => $val) {
            if (trim((string)$val) === '') continue;
            $vpPartes[] = ['valor' => $val, 'data_prevista' => $postVp['parte_data'][$i] ?? ''];
        }
    }
    if (!$vpPartes) $vpPartes = [['valor' => '', 'data_prevista' => '']];
?>
<dialog id="modal-promissoria" class="modal-lancamento">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.5rem">
        <h2 style="margin:0">💳 Vender na Promissória</h2>
        <button type="button" onclick="document.getElementById('modal-promissoria').close()" style="background:none;border:none;font-size:1.6rem;font-weight:700;cursor:pointer;line-height:1;padding:0 .25rem;color:var(--texto-suave)" aria-label="Fechar">&times;</button>
    </div>
    <p><small>Registra a negociação inteira numa passada só — comprador, condições, entrada em partes (PIX) e bem de
       troca, tudo junto — igual o sistema antigo fazia. Depois de registrar, o link do wizard de documentos já vem
       pronto pra copiar (enviar continua sendo um passo separado). Gerar o plano de parcelamento do saldo financiado
       (Asaas ou local) continua no card próprio da tela da negociação, depois de registrar aqui.</small></p>

    <form method="post" id="form-vender-promissoria">
        <?= csrfField() ?>
        <input type="hidden" name="acao" value="vender_promissoria">

        <div id="vp-step-1">

        <h3 style="margin-top:1.2rem">🚗 Veículo</h3>
        <label>
            <input type="checkbox" name="cadastrar_veiculo_novo" id="vp-check-novo" value="1"
                   <?= $vpCadastrarNovo ? 'checked' : '' ?> <?= $vpSemFrota ? 'disabled' : '' ?>
                   onchange="vpAlternarVeiculoNovo(this.checked)">
            Veículo não está na lista? Cadastrar um novo agora (lendo o CRLV)
        </label>
        <?php if ($vpSemFrota): ?>
            <p><small>⚠️ Nenhum veículo disponível na frota pra vender agora — cadastre um novo abaixo pra continuar.</small></p>
        <?php endif; ?>

        <div id="vp-bloco-select" style="<?= $vpCadastrarNovo ? 'display:none' : '' ?>;margin-top:8px">
            <label>Veículo da frota</label>
            <select name="oportunidade_id" id="vp-select-veiculo" <?= $vpCadastrarNovo ? '' : 'required' ?>>
                <option value="">— Escolha —</option>
                <?php foreach ($frotaDisponivelPromissoria as $fv): ?>
                    <option value="<?= (int)$fv['oportunidade_id'] ?>" <?= (int)($postVp['oportunidade_id'] ?? 0) === (int)$fv['oportunidade_id'] ? 'selected' : '' ?>>
                        #<?= (int)$fv['oportunidade_id'] ?> — <?= e(trim($fv['veiculo_marca'] . ' ' . $fv['veiculo_modelo'] . ' ' . $fv['veiculo_ano'])) ?>
                        <?= $fv['valor_referencia'] ? ' (ref. R$ ' . number_format((float)$fv['valor_referencia'], 2, ',', '.') . ')' : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div id="vp-bloco-novo" style="<?= $vpCadastrarNovo ? '' : 'display:none' ?>;margin-top:8px">
            <div class="form-group" style="margin-bottom:12px">
                <label>📄 Subir pelo CRLV (opcional) — a IA lê o documento e preenche os campos abaixo</label>
                <input type="file" id="vp-crlv-arquivo" accept="image/jpeg,image/png,image/webp,application/pdf">
                <button type="button" onclick="vpLerCrlv()" style="margin-top:.4rem" id="vp-crlv-btn">📄 Ler CRLV com IA</button>
                <span id="vp-crlv-status" style="font-size:.8rem;color:var(--texto-suave);margin-left:.5rem"></span>
            </div>
            <div class="grid-2">
                <div>
                    <label>Nome do vendedor/origem <?= $vpCadastrarNovo ? '*' : '' ?></label>
                    <input type="text" name="vendedor_nome" value="<?= e($postVp['vendedor_nome'] ?? '') ?>" <?= $vpCadastrarNovo ? 'required' : '' ?>>
                    <label>Telefone do vendedor/origem <?= $vpCadastrarNovo ? '*' : '' ?></label>
                    <input type="text" name="vendedor_telefone" value="<?= e($postVp['vendedor_telefone'] ?? '') ?>" <?= $vpCadastrarNovo ? 'required' : '' ?> placeholder="Ex: 31999998888">
                    <label>Valor pago pelo veículo (R$)</label>
                    <input type="text" name="veiculo_valor_final" value="<?= e($postVp['veiculo_valor_final'] ?? '') ?>" placeholder="0,00">
                </div>
                <div>
                    <label>Marca</label>
                    <input type="text" name="veiculo_marca" id="vp-marca" value="<?= e($postVp['veiculo_marca'] ?? '') ?>">
                    <label>Modelo</label>
                    <input type="text" name="veiculo_modelo" id="vp-modelo" value="<?= e($postVp['veiculo_modelo'] ?? '') ?>">
                    <label>Ano</label>
                    <input type="text" name="veiculo_ano" id="vp-ano" value="<?= e($postVp['veiculo_ano'] ?? '') ?>" style="max-width:120px">
                    <label>Placa</label>
                    <input type="text" name="veiculo_placa" id="vp-placa" value="<?= e($postVp['veiculo_placa'] ?? '') ?>" style="max-width:160px">
                    <label>Chassi</label>
                    <input type="text" name="veiculo_chassi" id="vp-chassi" value="<?= e($postVp['veiculo_chassi'] ?? '') ?>">
                    <label>RENAVAM</label>
                    <input type="text" name="veiculo_renavam" id="vp-renavam" value="<?= e($postVp['veiculo_renavam'] ?? '') ?>">
                </div>
            </div>
        </div>

        <h3 style="margin-top:1.4rem">🙋 Dados básicos do comprador</h3>
        <div class="grid-2">
            <div>
                <label>Nome do comprador *</label>
                <input type="text" name="comprador_nome" value="<?= e($postVp['comprador_nome'] ?? '') ?>" required>
                <label>Telefone do comprador *</label>
                <input type="text" name="comprador_telefone" value="<?= e($postVp['comprador_telefone'] ?? '') ?>" required placeholder="Ex: 31999998888">
            </div>
            <div>
                <label>CPF</label>
                <input type="text" name="comprador_cpf" value="<?= e($postVp['comprador_cpf'] ?? '') ?>" placeholder="000.000.000-00">
                <label>RG</label>
                <input type="text" name="comprador_rg" value="<?= e($postVp['comprador_rg'] ?? '') ?>">
                <label>E-mail</label>
                <input type="email" name="comprador_email" value="<?= e($postVp['comprador_email'] ?? '') ?>">
            </div>
        </div>

        <h3 style="margin-top:1.4rem">📋 Condições da venda</h3>
        <div class="grid-2">
            <div>
                <label>Preço de venda (R$)</label>
                <input type="number" step="0.01" inputmode="decimal" name="preco_venda" value="<?= e((string)($postVp['preco_venda'] ?? '')) ?>">
                <label>Forma de pagamento</label>
                <input type="text" name="forma_pagamento" value="<?= e($postVp['forma_pagamento'] ?? '') ?>" placeholder="À vista, financiado, entrada + parcelas, promissória...">
            </div>
            <div>
                <label>Saldo de preço devido depois da entrada (R$)</label>
                <input type="number" step="0.01" inputmode="decimal" name="saldo_preco_devido" value="<?= e((string)($postVp['saldo_preco_devido'] ?? '')) ?>">
                <label>Prazo pra quitar o saldo (meses, até 24)</label>
                <input type="number" name="prazo_quitacao_meses" value="<?= e((string)($postVp['prazo_quitacao_meses'] ?? '24')) ?>" min="1" max="24">
            </div>
        </div>

        <h3 style="margin-top:1.4rem">💰 Entrada — pago via PIX (em partes)</h3>
        <p><small>A entrada pode ser paga em mais de uma parte, cada uma com valor e data próprios — o total das
           partes abaixo é sempre o "valor pago pelo comprador na contratação".</small></p>
        <div id="vp-partes-linhas">
            <?php foreach ($vpPartes as $i => $parte): ?>
                <div class="grid-2 vp-parte-linha" style="align-items:end">
                    <div>
                        <label>Valor da <?= $i + 1 ?>ª parte no PIX (R$)</label>
                        <input type="number" step="0.01" inputmode="decimal" name="parte_valor[]" value="<?= e((string)($parte['valor'] ?? '')) ?>">
                    </div>
                    <div style="display:flex;gap:8px;align-items:end">
                        <div style="flex:1">
                            <label>Data da <?= $i + 1 ?>ª parte no PIX</label>
                            <input type="date" name="parte_data[]" value="<?= e($parte['data_prevista'] ?? '') ?>">
                        </div>
                        <button type="button" class="btn-texto perigo" onclick="this.closest('.vp-parte-linha').remove()" style="margin-bottom:2px">🗑️ remover</button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <button type="button" class="secundario" onclick="vpAdicionarParteEntrada()">➕ Adicionar outra parte no PIX</button>

        <h3 style="margin-top:1.4rem">🔁 Bem recebido como parte da entrada</h3>
        <label>
            <input type="checkbox" name="bem_troca_recebido" value="1" id="vp-bem-troca-check"
                   <?= !empty($postVp['bem_troca_recebido']) ? 'checked' : '' ?>
                   onchange="document.getElementById('vp-bem-troca-campos').style.display = this.checked ? '' : 'none'">
            Recebemos um bem como parte da entrada
        </label>
        <div id="vp-bem-troca-campos" style="<?= !empty($postVp['bem_troca_recebido']) ? '' : 'display:none' ?>;margin-top:10px">
            <label>Tipo</label>
            <select name="bem_troca_tipo" id="vp-bem-troca-tipo" onchange="document.getElementById('vp-bem-troca-veiculo').style.display = this.value === 'veiculo' ? '' : 'none'">
                <option value="outro" <?= ($postVp['bem_troca_tipo'] ?? '') === 'outro' ? 'selected' : '' ?>>Outro bem</option>
                <option value="veiculo" <?= ($postVp['bem_troca_tipo'] ?? '') === 'veiculo' ? 'selected' : '' ?>>Veículo</option>
            </select>
            <label>Descrição do bem</label>
            <input type="text" name="bem_troca_nome" value="<?= e($postVp['bem_troca_nome'] ?? '') ?>" placeholder="Ex: Moto Honda CG 160, ou nome do bem">
            <label>Valor atribuído ao bem (R$)</label>
            <input type="number" step="0.01" inputmode="decimal" name="bem_troca_valor" value="<?= e((string)($postVp['bem_troca_valor'] ?? '')) ?>">
            <div id="vp-bem-troca-veiculo" class="grid-2" style="<?= ($postVp['bem_troca_tipo'] ?? '') === 'veiculo' ? '' : 'display:none' ?>">
                <div>
                    <label>Modelo/ano</label>
                    <input type="text" name="bem_troca_modelo_ano" value="<?= e($postVp['bem_troca_modelo_ano'] ?? '') ?>">
                    <label>Ano de fabricação</label>
                    <input type="text" name="bem_troca_ano_fabricacao" value="<?= e($postVp['bem_troca_ano_fabricacao'] ?? '') ?>">
                    <label>Cor</label>
                    <input type="text" name="bem_troca_cor" value="<?= e($postVp['bem_troca_cor'] ?? '') ?>">
                </div>
                <div>
                    <label>Placa</label>
                    <input type="text" name="bem_troca_placa" value="<?= e($postVp['bem_troca_placa'] ?? '') ?>">
                    <label>Chassi</label>
                    <input type="text" name="bem_troca_chassi" value="<?= e($postVp['bem_troca_chassi'] ?? '') ?>">
                    <label>Renavam</label>
                    <input type="text" name="bem_troca_renavam" value="<?= e($postVp['bem_troca_renavam'] ?? '') ?>">
                </div>
            </div>
        </div>

        <h3 style="margin-top:1.4rem">📆 Parcelamento do saldo (opcional)</h3>
        <p><small>Só se você já souber os números agora — senão deixe em branco e gere o plano depois, no card de
           Financeiro da negociação (lá dá pra escolher entre cobrança real via Asaas ou registro local).</small></p>
        <div class="grid-2">
            <div>
                <label>Valor de cada parcela (R$)</label>
                <input type="number" step="0.01" inputmode="decimal" name="parcelamento_valor_parcela" value="<?= e((string)($postVp['parcelamento_valor_parcela'] ?? '')) ?>">
            </div>
            <div>
                <label>Quantidade de parcelas</label>
                <input type="number" name="parcelamento_qtd_parcelas" value="<?= e((string)($postVp['parcelamento_qtd_parcelas'] ?? '')) ?>">
                <label>Vencimento da 1ª parcela</label>
                <input type="date" name="parcelamento_primeira_parcela_data" value="<?= e($postVp['parcelamento_primeira_parcela_data'] ?? '') ?>">
            </div>
        </div>

        <div style="display:flex;gap:10px;margin-top:1.4rem">
            <button type="button" class="btn-primary" onclick="vpRevisar()">👁️ Revisar antes de registrar</button>
            <button type="button" onclick="document.getElementById('modal-promissoria').close()">Cancelar</button>
        </div>

        </div>

        <div id="vp-step-2" style="display:none">
            <h3 style="margin-top:1.2rem">📋 Confira antes de registrar</h3>
            <p><small>Nada foi salvo ainda — só grava no banco depois de clicar em "Confirmar e registrar" abaixo.
               Pra corrigir algo, volte e edite.</small></p>
            <div id="vp-resumo-conteudo" style="background:var(--fundo-suave, #f4f6fb);border-radius:10px;padding:14px 16px;font-size:.92rem"></div>
            <div style="display:flex;gap:10px;margin-top:1.2rem">
                <button type="submit" class="btn-primary">✅ Confirmar e registrar</button>
                <button type="button" onclick="vpVoltarEditar()">← Voltar e editar</button>
                <button type="button" onclick="document.getElementById('modal-promissoria').close()">Cancelar</button>
            </div>
        </div>
    </form>
</dialog>
<script>
var csrfTokenVendaPromissoria = <?= json_encode(generateCSRF()) ?>;

function vpAlternarVeiculoNovo(novo) {
    document.getElementById('vp-bloco-select').style.display = novo ? 'none' : '';
    document.getElementById('vp-bloco-novo').style.display = novo ? '' : 'none';
    document.getElementById('vp-select-veiculo').required = !novo;
    document.querySelector('input[name="vendedor_nome"]').required = novo;
    document.querySelector('input[name="vendedor_telefone"]').required = novo;
}

function vpLerCrlv() {
    var input = document.getElementById('vp-crlv-arquivo');
    if (!input.files.length) { alert('Escolha o arquivo do CRLV primeiro.'); return; }
    var status = document.getElementById('vp-crlv-status');
    var btn = document.getElementById('vp-crlv-btn');
    status.textContent = '🔄 Lendo CRLV...';
    btn.disabled = true;

    var fd = new FormData();
    fd.append('crlv', input.files[0]);
    fd.append('csrf_token', csrfTokenVendaPromissoria);
    fetch('/admin/veiculo_crlv_ajax.php', { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            btn.disabled = false;
            if (!d.ok) { status.textContent = '⚠️ ' + d.erro; return; }
            // fill-if-empty — nunca sobrescreve o que já foi digitado/corrigido na mão.
            var campos = { marca: d.veiculo_marca, modelo: d.veiculo_modelo, ano: d.veiculo_ano, placa: d.veiculo_placa, chassi: d.veiculo_chassi, renavam: d.veiculo_renavam };
            Object.keys(campos).forEach(function (k) {
                var el = document.getElementById('vp-' + k);
                if (el && !el.value && campos[k]) el.value = campos[k];
            });
            status.textContent = '✅ Preenchido! Confira antes de registrar.';
        })
        .catch(function (err) { btn.disabled = false; status.textContent = '⚠️ Erro ao ler o CRLV.'; console.error(err); });
}

function vpFmtMoeda(v) {
    var n = parseFloat(String(v || '').replace(',', '.'));
    if (isNaN(n) || n === 0) return null;
    return 'R$ ' + n.toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d)(?=,))/g, '.');
}
function vpEsc(s) {
    return String(s || '').replace(/[&<>"']/g, function (c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
}
function vpFmtData(v) {
    if (!v) return null;
    var p = v.split('-');
    return p.length === 3 ? (p[2] + '/' + p[1] + '/' + p[0]) : v;
}
function vpVal(name) {
    var el = document.querySelector('#form-vender-promissoria [name="' + name + '"]');
    return el ? el.value.trim() : '';
}

function vpRevisar() {
    var form = document.getElementById('form-vender-promissoria');
    if (!form.reportValidity()) return; // validação nativa (campos *) já avisa o que falta

    var linhas = [];

    // Veículo
    if (document.getElementById('vp-check-novo').checked) {
        var partesVeiculo = [vpVal('veiculo_marca'), vpVal('veiculo_modelo'), vpVal('veiculo_ano')].filter(Boolean).join(' ');
        linhas.push('<p><strong>🚗 Veículo (novo cadastro):</strong> ' + vpEsc(partesVeiculo || '(sem marca/modelo informados)') +
            (vpVal('veiculo_placa') ? ' — placa ' + vpEsc(vpVal('veiculo_placa')) : '') + '</p>');
        linhas.push('<p><strong>Vendedor/origem:</strong> ' + vpEsc(vpVal('vendedor_nome')) + ' — ' + vpEsc(vpVal('vendedor_telefone')) + '</p>');
    } else {
        var sel = document.getElementById('vp-select-veiculo');
        var texto = sel.selectedIndex >= 0 ? sel.options[sel.selectedIndex].text : '';
        linhas.push('<p><strong>🚗 Veículo (da frota):</strong> ' + vpEsc(texto || '(nenhum selecionado)') + '</p>');
    }

    // Comprador
    linhas.push('<p><strong>🙋 Comprador:</strong> ' + vpEsc(vpVal('comprador_nome')) + ' — ' + vpEsc(vpVal('comprador_telefone')) +
        (vpVal('comprador_cpf') ? ' — CPF ' + vpEsc(vpVal('comprador_cpf')) : '') + '</p>');

    // Condições
    var condicoes = [];
    if (vpFmtMoeda(vpVal('preco_venda'))) condicoes.push('Preço: ' + vpFmtMoeda(vpVal('preco_venda')));
    if (vpVal('forma_pagamento')) condicoes.push('Forma: ' + vpEsc(vpVal('forma_pagamento')));
    if (vpFmtMoeda(vpVal('saldo_preco_devido'))) condicoes.push('Saldo devido: ' + vpFmtMoeda(vpVal('saldo_preco_devido')));
    if (vpVal('prazo_quitacao_meses')) condicoes.push('Prazo: ' + vpEsc(vpVal('prazo_quitacao_meses')) + ' meses');
    if (condicoes.length) linhas.push('<p><strong>📋 Condições:</strong> ' + condicoes.join(' · ') + '</p>');

    // Entrada em partes
    var totalEntrada = 0, partesTexto = [];
    document.querySelectorAll('.vp-parte-linha').forEach(function (linha) {
        var valorEl = linha.querySelector('[name="parte_valor[]"]');
        var dataEl = linha.querySelector('[name="parte_data[]"]');
        var v = parseFloat(String(valorEl.value || '').replace(',', '.'));
        if (!v) return;
        totalEntrada += v;
        var d = vpFmtData(dataEl.value);
        partesTexto.push(vpFmtMoeda(v) + (d ? ' em ' + d : ''));
    });
    if (partesTexto.length) {
        linhas.push('<p><strong>💰 Entrada via PIX (' + partesTexto.length + ' parte' + (partesTexto.length > 1 ? 's' : '') + '):</strong> ' +
            partesTexto.join(', ') + ' — total ' + vpFmtMoeda(totalEntrada) + '</p>');
    } else {
        linhas.push('<p><strong>💰 Entrada via PIX:</strong> nenhuma informada</p>');
    }

    // Bem de troca
    if (document.getElementById('vp-bem-troca-check').checked) {
        var tipoBem = document.getElementById('vp-bem-troca-tipo').value === 'veiculo' ? 'Veículo' : 'Outro bem';
        var descBem = [tipoBem + ':', vpVal('bem_troca_nome')].filter(Boolean).join(' ');
        var valorBem = vpFmtMoeda(vpVal('bem_troca_valor'));
        linhas.push('<p><strong>🔁 Bem recebido na entrada:</strong> ' + vpEsc(descBem) + (valorBem ? ' — ' + valorBem : '') + '</p>');
    }

    // Parcelamento do saldo
    var qtdParc = vpVal('parcelamento_qtd_parcelas');
    var valorParc = vpFmtMoeda(vpVal('parcelamento_valor_parcela'));
    if (qtdParc && valorParc) {
        var primeiraData = vpFmtData(vpVal('parcelamento_primeira_parcela_data'));
        linhas.push('<p><strong>📆 Parcelamento do saldo:</strong> ' + vpEsc(qtdParc) + 'x de ' + valorParc +
            (primeiraData ? ', 1ª parcela em ' + primeiraData : '') + '</p>');
    }

    document.getElementById('vp-resumo-conteudo').innerHTML = linhas.join('');
    document.getElementById('vp-step-1').style.display = 'none';
    document.getElementById('vp-step-2').style.display = '';
}

function vpVoltarEditar() {
    document.getElementById('vp-step-2').style.display = 'none';
    document.getElementById('vp-step-1').style.display = '';
}

function vpAdicionarParteEntrada() {
    var wrap = document.getElementById('vp-partes-linhas');
    var n = wrap.querySelectorAll('.vp-parte-linha').length + 1;
    var div = document.createElement('div');
    div.className = 'grid-2 vp-parte-linha';
    div.style.alignItems = 'end';
    div.innerHTML = ''
        + '<div>'
        + '<label>Valor da ' + n + 'ª parte no PIX (R$)</label>'
        + '<input type="number" step="0.01" inputmode="decimal" name="parte_valor[]" value="">'
        + '</div>'
        + '<div style="display:flex;gap:8px;align-items:end">'
        + '<div style="flex:1">'
        + '<label>Data da ' + n + 'ª parte no PIX</label>'
        + '<input type="date" name="parte_data[]" value="">'
        + '</div>'
        + '<button type="button" class="btn-texto perigo" onclick="this.closest(\'.vp-parte-linha\').remove()" style="margin-bottom:2px">🗑️ remover</button>'
        + '</div>';
    wrap.appendChild(div);
}

<?php if ($modalPromissoriaAbrir): ?>
document.getElementById('modal-promissoria').showModal();
<?php endif; ?>
</script>

<div class="stat-grid">
    <div class="stat-card">
        <div class="valor"><?= (int)$stats['ativas'] ?></div>
        <div class="rotulo"><?= $souDono ? 'Minhas' : '' ?> negociações ativas</div>
    </div>
    <div class="stat-card <?= $stats['atrasadas'] > 0 ? 'alerta' : '' ?>">
        <div class="valor"><?= (int)$stats['atrasadas'] ?></div>
        <div class="rotulo">Atrasadas</div>
    </div>
    <?php if ($souDono): ?>
        <div class="stat-card neutro">
            <div class="valor"><?= (int)$stats['recebidas_semana'] ?></div>
            <div class="rotulo">Recebidas nos últimos 7 dias</div>
        </div>
        <div class="stat-card <?= $stats['disponivel'] ? 'sucesso' : 'neutro' ?>">
            <div class="valor"><?= $stats['disponivel'] ? '🟢' : '⚪' ?></div>
            <div class="rotulo"><?= $stats['disponivel'] ? 'Disponível pra fila' : ($stats['plantao'] ? 'Offline (plantão)' : 'Offline') ?></div>
        </div>
    <?php endif; ?>
    <div class="stat-card sucesso">
        <div class="valor"><?= (int)$stats['vendidas_mes'] ?></div>
        <div class="rotulo">Vendidas este mês</div>
    </div>
    <div class="stat-card sucesso">
        <div class="valor"><?= moedaVenda($stats['valor_vendido_mes']) ?></div>
        <div class="rotulo">Valor vendido este mês</div>
    </div>
    <?php if ($souDono && $stats['taxa_conversao'] !== null): ?>
        <div class="stat-card neutro">
            <div class="valor"><?= $stats['taxa_conversao'] ?>%</div>
            <div class="rotulo">Taxa de conversão</div>
        </div>
    <?php endif; ?>
</div>

<div class="card">
    <form method="get" style="display:flex;gap:8px;align-items:center">
        <?php if ($etapaFiltro): ?><input type="hidden" name="etapa" value="<?= e($etapaFiltro) ?>"><?php endif; ?>
        <input type="text" name="q" value="<?= e($busca) ?>" placeholder="Buscar por comprador, telefone ou veículo..." style="flex:1;margin:0">
        <button type="submit" style="margin:0">Buscar</button>
        <?php if ($busca): ?><a href="/admin/vendas.php<?= $etapaFiltro ? '?etapa=' . urlencode($etapaFiltro) : '' ?>">Limpar</a><?php endif; ?>
    </form>
</div>

<?php
// 29/09/2026, mesmo pedido do lado de compra — seleção em massa pra
// cancelar várias negociações de uma vez. Nunca aparece pro supervisor
// (só acompanha).
$podeSelecionarEmMassa = $perfil !== 'supervisor';
if ($podeSelecionarEmMassa):
?>
<form id="form-bulk-cancelar" method="post" onsubmit="return confirmarAcao(this, 'Cancelar as negociações selecionadas? Motivo fica gravado no histórico de cada uma; o veículo volta a ficar disponível pra uma nova venda.');">
    <?= csrfField() ?>
    <input type="hidden" name="acao" value="cancelar_venda_massa">
</form>
<div id="barra-bulk-cancelar" class="card" style="display:none;margin-bottom:14px;background:var(--laranja-bg,#fff4ec);border:1px solid var(--laranja,#ea580c);display:flex;flex-wrap:wrap;align-items:center;gap:10px;padding:12px 16px">
    <strong><span id="bulk-contagem">0</span> selecionada(s)</strong>
    <input type="text" name="motivo" form="form-bulk-cancelar" id="bulk-motivo" required
           placeholder="Motivo (obrigatório) — ex: desistiu, achou proposta baixa..."
           style="flex:1;min-width:220px;margin:0">
    <button type="submit" form="form-bulk-cancelar" class="perigo" style="margin:0">🚫 Cancelar selecionadas</button>
    <button type="button" class="btn-texto" onclick="bulkLimparSelecao()">Limpar seleção</button>
</div>
<?php endif; ?>

<div class="card">
    <table class="tabela-oportunidades">
        <thead>
            <tr>
                <?php if ($podeSelecionarEmMassa): ?>
                <th><input type="checkbox" id="bulk-chk-todos" onchange="bulkToggleTodos(this)" title="Selecionar todas as visíveis"></th>
                <?php endif; ?>
                <th>Veículo</th><th>Comprador</th><th>Preço/interesse</th>
                <th>Etapa</th><th>Responsável</th><th>Atualizado em</th><th>Observação</th><th></th>
            </tr>
        </thead>
        <tbody>
        <?php if (!$vendas): ?>
            <tr><td colspan="<?= $podeSelecionarEmMassa ? 9 : 8 ?>">Nenhuma <?= $souDono ? 'venda sua' : 'venda' ?> <?= $busca ? 'encontrada' : ($etapaFiltro ? 'nessa etapa' : 'iniciada ainda') ?>.</td></tr>
        <?php endif; ?>
        <?php foreach ($vendas as $v): ?>
            <?php
            // Mesma classe de bug já corrigida em admin/index.php: sem
            // restringir a ETAPAS_VENDA_ATIVAS, uma venda já 'vendido'/
            // 'cancelada'/'sem_perfil' com proxima_acao_em velho (resto de
            // quando ainda estava ativa) apareceria com destaque de atraso
            // como se ainda precisasse de ação — nunca mais faz sentido
            // numa negociação já encerrada.
            $atrasada = in_array($v['etapa'], ETAPAS_VENDA_ATIVAS, true) && $v['proxima_acao_em'] && $v['proxima_acao_em'] < date('Y-m-d H:i:s');
            $quente = $v['temperatura_lead'] === 'quente';
            $ativaParaSelecao = in_array($v['etapa'], ETAPAS_VENDA_ATIVAS, true);
            ?>
            <tr class="<?= trim(($atrasada ? 'linha-atrasada ' : '') . ($quente ? 'linha-quente' : '')) ?>">
                <?php if ($podeSelecionarEmMassa): ?>
                <td data-label="">
                    <?php if ($ativaParaSelecao): ?>
                    <input type="checkbox" class="bulk-chk-lead" name="ids[]" value="<?= (int)$v['id'] ?>" form="form-bulk-cancelar" onchange="bulkAtualizar()">
                    <?php endif; ?>
                </td>
                <?php endif; ?>
                <td>
                    <?php if ($v['veiculo_marca'] || $v['veiculo_modelo']): ?>
                        <?= e(trim($v['veiculo_marca'] . ' ' . $v['veiculo_modelo'])) ?> <?= e((string)($v['veiculo_ano'] ?? '')) ?>
                        <br><small><?= e($v['veiculo_placa'] ?: '—') ?></small>
                    <?php else: ?>
                        <small style="color:var(--texto-fraco)">🔍 ainda não vinculado</small>
                        <?php if ($v['veiculo_interesse_texto']): ?><br><small><?= e(mb_strimwidth($v['veiculo_interesse_texto'], 0, 40, '…')) ?></small><?php endif; ?>
                    <?php endif; ?>
                </td>
                <td>
                    <?= e($v['comprador_nome'] ?: '(sem nome ainda)') ?><br><small><?= e($v['comprador_telefone'] ?: '') ?></small>
                    <?php if ($v['temperatura_lead'] === 'quente'): ?>
                        <br><span class="badge badge-quente">🔥 Quente</span>
                    <?php elseif ($v['temperatura_lead'] === 'morno'): ?>
                        <br><span class="badge">🌤️ Morno</span>
                    <?php elseif ($v['temperatura_lead'] === 'frio'): ?>
                        <br><span class="badge">❄️ Frio</span>
                    <?php endif; ?>
                </td>
                <td><?= $v['preco_venda'] !== null ? moedaVenda((float)$v['preco_venda']) : '—' ?></td>
                <td>
                    <span class="badge"><?= e(etapaVendaLabel($v['etapa'])) ?></span>
                    <?php if ($atrasada): ?><br><span class="badge badge-atraso">⚠️ atrasada</span><?php endif; ?>
                    <?php if ($v['etapa'] === 'vendido' && $v['data_venda']): ?>
                        <br><small>vendido <?= date('d/m/Y', strtotime($v['data_venda'])) ?></small>
                    <?php elseif (in_array($v['etapa'], ['cancelada', 'sem_perfil'], true)): ?>
                        <?php $motivo = $v['motivo_cancelamento'] ?: $v['motivo_perda']; ?>
                        <?php if ($motivo): ?><br><small><?= e(mb_strimwidth($motivo, 0, 50, '…')) ?></small><?php endif; ?>
                    <?php endif; ?>
                </td>
                <td><?= e($v['responsavel_nome'] ?? '—') ?></td>
                <td><?= date('d/m/Y H:i', strtotime($v['updated_at'])) ?></td>
                <td data-label="Observação">
                    <?php if ($ativaParaSelecao && $perfil !== 'supervisor'): ?>
                        <?php // 01/10/2026, "adicionar campo para recado tanto na
                              // compra e revenda" — mesma mecânica de admin/index.php,
                              // texto livre editável direto na linha, só enquanto a
                              // negociação ainda está ativa. ?>
                        <form method="post" style="display:flex;gap:4px;min-width:160px">
                            <?= csrfField() ?>
                            <input type="hidden" name="acao" value="atualizar_observacao_manual">
                            <input type="hidden" name="id" value="<?= (int)$v['id'] ?>">
                            <input type="text" name="observacao" value="<?= e($v['observacao_manual'] ?? '') ?>" placeholder="anotação..." style="flex:1;min-width:0;font-size:13px;padding:4px 6px">
                            <button type="submit" class="btn-texto" style="padding:4px 8px" title="Salvar observação">💾</button>
                        </form>
                    <?php else: ?>
                        <?php // Encerrada (cancelada/sem_perfil) — reaproveita o
                              // motivo já mostrado no card de etapa em vez de
                              // aceitar edição aqui, mesma disciplina de compra. ?>
                        <small><?= e(($v['motivo_cancelamento'] ?: $v['motivo_perda']) ?: ($v['observacao_manual'] ?? '') ?: '—') ?></small>
                    <?php endif; ?>
                </td>
                <td><a href="/admin/venda.php?id=<?= (int)$v['id'] ?>">Abrir →</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php renderPaginacao($totalFiltrado); ?>
</div>
</main>
<?php if ($podeSelecionarEmMassa): ?>
<script>
function bulkAtualizar() {
    var checks = document.querySelectorAll('.bulk-chk-lead:checked');
    document.getElementById('bulk-contagem').textContent = checks.length;
    document.getElementById('barra-bulk-cancelar').style.display = checks.length > 0 ? 'flex' : 'none';
}
function bulkToggleTodos(master) {
    document.querySelectorAll('.bulk-chk-lead').forEach(function (c) { c.checked = master.checked; });
    bulkAtualizar();
}
function bulkLimparSelecao() {
    document.querySelectorAll('.bulk-chk-lead').forEach(function (c) { c.checked = false; });
    document.getElementById('bulk-chk-todos').checked = false;
    document.getElementById('bulk-motivo').value = '';
    bulkAtualizar();
}
</script>
<?php endif; ?>
<?php include __DIR__ . '/_pwa_register.php'; ?>
<?php include __DIR__ . '/_notify.php'; ?>
<?php include __DIR__ . '/_scroll_restore.php'; ?>
<?php include __DIR__ . '/_acao_popup.php'; ?>
<?php include __DIR__ . '/_confirm_dialog.php'; ?>
<?php include __DIR__ . '/_zapi_status.php'; ?>
</body>
</html>
