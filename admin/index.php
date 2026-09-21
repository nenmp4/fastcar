<?php
/**
 * Dashboard — lista de oportunidades abertas, agrupável por etapa.
 * "Toda oportunidade aberta precisa de responsável e próxima ação, com
 * alerta de atraso" (regra #5 do CLAUDE.md) — linha atrasada fica
 * destacada aqui mesmo, sem esperar o cron avisar por WhatsApp.
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../includes/dashboard.php';

$db = getDB();
$perfil = $_SESSION['admin_perfil'];
$meuId = (int)$_SESSION['admin_id'];
// Consultor só vê as próprias oportunidades no funil — cuida da carteira
// dele (atendimento + negociação, blocos 5-6 mesclados); super_admin vê a
// empresa inteira (visão geral).
$souDono = $perfil === 'consultor';

$etapaFiltro = (string)($_GET['etapa'] ?? '');
$busca = trim((string)($_GET['q'] ?? ''));

// 18/09/2026, "coloca clicavil os cads tipo leads de hoje clicar em cima
// abri os leads" — os cards de KPI (Atrasadas/Leads novos hoje/na semana/
// Em negociação/Fechadas este mês) viraram link pra essa mesma tela com
// `?filtro=...`, listando exatamente o que o card está contando. Nunca se
// combina com `?etapa=` (filtro especial sempre vence — evita o usuário
// cair numa combinação impossível tipo "atrasadas" + "fechado").
$filtroEspecial = (string)($_GET['filtro'] ?? '');
$extraWhere = '';

// 18/09/2026, achado real do usuário: "em dasbord clientes que conluiio
// toda etapa ate pasta como consultor pode localizar nçao tem essa opção"
// — o dashboard inteiro (nav de etapas, busca, tabela) sempre foi
// hard-limitado a ETAPAS_ATIVAS; um cliente que chegou até 'fechado'
// (pasta fechada, bloco 8) literalmente não aparecia em lugar nenhum
// daqui, nem pela busca — a única tela que lista `etapa='fechado'` é a
// Frota (admin/veiculos.php), restrita ao super_admin, então consultor
// (e supervisor, mesmo gap) não tinha NENHUM jeito de achar um cliente já
// fechado a partir do dashboard. "✅ Fechadas" na nav abaixo busca fora do
// conjunto de etapas ativas — monta sua própria lista de etapas pro WHERE
// em vez de sempre usar ETAPAS_ATIVAS.
switch ($filtroEspecial) {
    case 'hoje':
        $etapasEscopo = ETAPAS_ATIVAS;
        $extraWhere = " AND date(o.created_at) = date('now','localtime')";
        break;
    case 'ontem':
        // 21/09/2026, "pode colocar fitro por dia ontem hoje para saber
        // lista por data dos leads" — mesmo padrão de 'hoje', só um dia
        // antes; permite conferir o que chegou no dia anterior, não só hoje.
        $etapasEscopo = ETAPAS_ATIVAS;
        $extraWhere = " AND date(o.created_at) = date('now','localtime','-1 day')";
        break;
    case 'semana':
        $etapasEscopo = ETAPAS_ATIVAS;
        $extraWhere = " AND o.created_at >= datetime('now','localtime','-7 days')";
        break;
    case 'atrasadas':
        $etapasEscopo = ETAPAS_ATIVAS;
        $extraWhere = " AND o.proxima_acao_em IS NOT NULL AND o.proxima_acao_em < datetime('now','localtime')";
        break;
    case 'negociacao':
        $etapasEscopo = ['negociacao', 'presencial'];
        break;
    case 'fechado_mes':
        $etapasEscopo = ['fechado'];
        $extraWhere = " AND o.data_compra >= date('now','localtime','start of month')";
        break;
    default:
        $etapasEscopo = match ($etapaFiltro) {
            'fechado' => ['fechado'],
            // 18/09/2026, "colocar os leads de encerrar oportunidade em aba
            // para futuras consultas" — perdido/sem_perfil nunca estavam em
            // ETAPAS_ATIVAS nem na aba Fechadas (só etapa='fechado'), então
            // um lead encerrado sem virar compra literalmente sumia do
            // dashboard pra sempre, sem nenhum jeito de consultar depois
            // (ex: cliente que recusou desta vez pode voltar meses depois).
            'encerradas' => ['perdido', 'sem_perfil'],
            default => ETAPAS_ATIVAS,
        };
}
// "Escopo fechado" (fechado_por em vez de responsavel_id, coluna extra de
// data de fechamento na tabela) vale tanto pra aba "✅ Fechadas" quanto pro
// card "Fechadas este mês" (?filtro=fechado_mes) — os dois terminam no
// mesmo conjunto de etapa ('fechado').
$etapaBuscandoFechadas = $etapasEscopo === ['fechado'];
// "Escopo encerradas" filtra por responsavel_id igual às etapas ativas
// (nunca teve um "fechado_por" próprio — só a transição pra 'fechado' grava
// isso) — quem estava com a oportunidade quando ela foi perdida/desqualificada.
$etapaBuscandoEncerradas = $etapasEscopo === ['perdido', 'sem_perfil'];
$placeholders = implode(',', array_fill(0, count($etapasEscopo), '?'));

// WHERE construído uma vez só e reaproveitado pra contar o total ANTES de
// paginar (precisa ser o total que bate com ESSE filtro específico —
// etapa + dono + busca — não $totalAtivas mais abaixo, que é sempre a soma
// de TODAS as etapas ativas, serve só pro contador "Todas (N)" da nav).
$where = "WHERE o.etapa IN ({$placeholders}){$extraWhere}";
$params = $etapasEscopo;
if ($souDono) {
    // "Fechadas" filtra por fechado_por (quem executou o fechamento —
    // mesmo campo que dashboardConsultor() já usa pro card "Fechadas este
    // mês", ver includes/dashboard.php) — as etapas ativas continuam
    // filtrando por responsavel_id (quem está cuidando da carteira em
    // aberto agora), que é um conceito diferente.
    $where .= $etapaBuscandoFechadas ? " AND o.fechado_por = ?" : " AND o.responsavel_id = ?";
    $params[] = $meuId;
}
if ($filtroEspecial === '' && $etapaFiltro && !$etapaBuscandoFechadas && in_array($etapaFiltro, ETAPAS_ATIVAS, true)) {
    $where .= " AND o.etapa = ?";
    $params[] = $etapaFiltro;
}
if ($busca !== '') {
    // Filtro de busca no dashboard (pedido direto) — nome/telefone do
    // cliente ou marca/modelo/placa do veículo, mesmo padrão de
    // admin/clientes.php e admin/veiculos.php. Precisa do JOIN de
    // clientes, que já existe no $sql abaixo (c.nome/c.telefone) — a
    // query de contagem por etapa também usa esse JOIN, ver mais abaixo.
    $where .= " AND (c.nome LIKE ? OR c.telefone LIKE ? OR o.veiculo_marca LIKE ? OR o.veiculo_modelo LIKE ? OR o.veiculo_placa LIKE ?)";
    $like = '%' . $busca . '%';
    array_push($params, $like, $like, $like, $like, $like);
}

$stmtTotalFiltrado = $db->prepare("SELECT COUNT(*) FROM oportunidades o JOIN clientes c ON c.id = o.cliente_id {$where}");
$stmtTotalFiltrado->execute($params);
$totalFiltrado = (int)$stmtTotalFiltrado->fetchColumn();

// 18/09/2026, pedido direto: "classifica os ledas quentes bem destacados
// prioriza em com os primeiros" — lead quente (dor financeira real, urgência
// de vender, ver critério em includes/ia_qualificacao.php) sobe pro topo de
// QUALQUER view (Minhas/Todas ou dentro de uma etapa específica), na frente
// de morno/frio/sem classificação — só depois disso entra o critério de
// sempre (próxima ação atrasada/mais próxima primeiro, depois mais recente).
// 21/09/2026, "sempre classificar mais recentes" — achado real (screenshot
// de vários leads quentes SEM próxima ação marcada, aparecendo fora de ordem
// de data): o desempate final usava updated_at (última vez que QUALQUER
// coisa mudou na oportunidade — inclusive um toque automático que não é
// "chegada" nenhuma), não created_at (a coluna "Recebido em" já mostrada na
// própria tabela) — pra quem não tem próxima ação, a ordem não batia com a
// data visível na tela. Trocado pra created_at DESC: mais recente primeiro,
// de verdade, consistente com o que a coluna "Recebido em" mostra.
$sql = "
    SELECT o.*, c.nome AS cliente_nome, c.telefone AS cliente_telefone,
           u.nome AS responsavel_nome
    FROM oportunidades o
    JOIN clientes c ON c.id = o.cliente_id
    LEFT JOIN usuarios u ON u.id = o.responsavel_id
    {$where}
    ORDER BY CASE o.temperatura_lead WHEN 'quente' THEN 0 WHEN 'morno' THEN 1 WHEN 'frio' THEN 2 ELSE 3 END,
             (o.proxima_acao_em IS NULL), o.proxima_acao_em ASC, o.created_at DESC
    LIMIT " . ITENS_POR_PAGINA_PADRAO . " OFFSET " . paginacaoOffset();

$stmt = $db->prepare($sql);
$stmt->execute($params);
$oportunidades = $stmt->fetchAll();

// Contadores da nav das etapas ATIVAS — sempre contra ETAPAS_ATIVAS
// (nunca $placeholders/$etapasEscopo, que agora podem estar reduzidos a
// só 'fechado' quando esse filtro está selecionado, ver acima) — a nav
// precisa mostrar as 6 etapas ativas + seus contadores independente de
// qual filtro está ativo no momento.
$placeholdersAtivas = implode(',', array_fill(0, count(ETAPAS_ATIVAS), '?'));
$sqlContagem = "SELECT o.etapa, COUNT(*) AS total FROM oportunidades o
                JOIN clientes c ON c.id = o.cliente_id
                WHERE o.etapa IN ({$placeholdersAtivas})";
$paramsContagem = ETAPAS_ATIVAS;
if ($souDono) {
    $sqlContagem .= " AND o.responsavel_id = ?";
    $paramsContagem[] = $meuId;
}
if ($busca !== '') {
    // Contadores da nav (Todas/etapa) também refletem a busca — senão o
    // clique numa etapa some com o filtro de busca (renderPaginacao já
    // preserva ?q= nos links de página, mas os links da nav de etapa são
    // montados à parte, ver mais abaixo).
    $sqlContagem .= " AND (c.nome LIKE ? OR c.telefone LIKE ? OR o.veiculo_marca LIKE ? OR o.veiculo_modelo LIKE ? OR o.veiculo_placa LIKE ?)";
    array_push($paramsContagem, $like, $like, $like, $like, $like);
}
$sqlContagem .= " GROUP BY o.etapa";
$stmtContagem = $db->prepare($sqlContagem);
$stmtContagem->execute($paramsContagem);
$contagemPorEtapa = array_column($stmtContagem->fetchAll(), 'total', 'etapa');
$totalAtivas = array_sum($contagemPorEtapa);

// Contador do badge "✅ Fechadas" — mesmo filtro por dono (fechado_por)/
// busca do bloco "Fechadas" acima, independente do filtro atual.
$sqlFechadas = "SELECT COUNT(*) FROM oportunidades o
                JOIN clientes c ON c.id = o.cliente_id
                WHERE o.etapa = 'fechado'";
$paramsFechadas = [];
if ($souDono) {
    $sqlFechadas .= " AND o.fechado_por = ?";
    $paramsFechadas[] = $meuId;
}
if ($busca !== '') {
    $sqlFechadas .= " AND (c.nome LIKE ? OR c.telefone LIKE ? OR o.veiculo_marca LIKE ? OR o.veiculo_modelo LIKE ? OR o.veiculo_placa LIKE ?)";
    array_push($paramsFechadas, $like, $like, $like, $like, $like);
}
$stmtFechadas = $db->prepare($sqlFechadas);
$stmtFechadas->execute($paramsFechadas);
$totalFechadas = (int)$stmtFechadas->fetchColumn();

// Contador do badge "❌ Encerradas" — mesmo padrão do de Fechadas acima,
// mas filtrado por responsavel_id (não fechado_por, que só existe pro
// fechamento de verdade).
$sqlEncerradas = "SELECT COUNT(*) FROM oportunidades o
                JOIN clientes c ON c.id = o.cliente_id
                WHERE o.etapa IN ('perdido', 'sem_perfil')";
$paramsEncerradas = [];
if ($souDono) {
    $sqlEncerradas .= " AND o.responsavel_id = ?";
    $paramsEncerradas[] = $meuId;
}
if ($busca !== '') {
    $sqlEncerradas .= " AND (c.nome LIKE ? OR c.telefone LIKE ? OR o.veiculo_marca LIKE ? OR o.veiculo_modelo LIKE ? OR o.veiculo_placa LIKE ?)";
    array_push($paramsEncerradas, $like, $like, $like, $like, $like);
}
$stmtEncerradas = $db->prepare($sqlEncerradas);
$stmtEncerradas->execute($paramsEncerradas);
$totalEncerradas = (int)$stmtEncerradas->fetchColumn();

$stats = match ($perfil) {
    'consultor' => dashboardConsultor($meuId),
    // supervisor vê a mesma visão geral do super_admin (regra do
    // perfilVeTudo() em includes/security.php), só não age.
    'super_admin', 'supervisor' => dashboardSuperAdmin(),
    default => [],
};

$agora = date('Y-m-d H:i:s');

function moeda(float $v): string { return 'R$ ' . number_format($v, 2, ',', '.'); }
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Fastcar CRM</title>
<link rel="stylesheet" href="/admin/assets/style.css?v=<?= @filemtime(__DIR__ . '/assets/style.css') ?: 1 ?>">
<?php include __DIR__ . '/_pwa_head.php'; ?>
</head>
<body>
<header class="topbar">
    <strong><img class="topbar-logo" src="/admin/assets/img/icon-192.png" alt="Fastcar" onerror="this.style.display='none'"> Fast<b>Car</b></strong>
    <span>Olá, <?= e($_SESSION['admin_nome']) ?> (<?= e($_SESSION['admin_perfil']) ?>)</span>
    <?php if ($_SESSION['admin_perfil'] === 'consultor'): ?>
        <?php $euAtual = buscarUsuario((int)$_SESSION['admin_id']); ?>
        <form method="post" action="/admin/toggle_disponivel.php" class="inline">
            <?= csrfField() ?>
            <input type="hidden" name="voltar" value="/admin/index.php">
            <button type="submit" style="margin-top:0;padding:4px 10px;font-size:12px">
                <?= $euAtual['disponivel'] ? '🟢 Disponível' : '⚪ Offline' ?>
            </button>
        </form>
    <?php endif; ?>
    <a href="/admin/clientes.php">👥 Clientes</a>
    <?php if (podeAcessarVendas()): ?><a href="/admin/vendas.php">💰 Vendas</a><?php endif; ?>
    <?php if (podeAcessarFinanceiro()): ?><a href="/admin/financeiro.php">🧾 Financeiro</a><?php endif; ?>
    <?php if (podeAcessarAvaliacoes()): ?><a href="/admin/avaliacoes.php">🔍 Vistorias</a><?php endif; ?>
    <a href="/admin/pendencias_pos_venda.php">📋 Pendências</a>
    <a href="/admin/whatsapp_inbox.php">💬 WhatsApp</a>
    <?php if (perfilVeTudo()): ?>
        <a href="/admin/produtividade.php">📊 Produtividade</a>
        <a href="/admin/origem_leads.php">📣 Origem dos leads</a>
        <a href="/admin/qualidade_ia.php">🤖 Qualidade da IA</a>
    <?php endif; ?>
    <?php if ($_SESSION['admin_perfil'] === 'super_admin'): ?>
        <a href="/admin/veiculos.php">🚗 Veículos</a>
        <a href="/admin/usuarios.php">👤 Usuários</a>
        <a href="/admin/auditoria.php">🕵️ Auditoria</a>
        <a href="/admin/backup.php">💾 Backup</a>
        <a href="/admin/saude.php">🩺 Saúde do sistema</a>
        <a href="/admin/configuracoes.php">⚙️ Configurações</a>
    <?php endif; ?>
    <a href="/admin/meu_perfil.php">🙋 Meu perfil</a>
    <a href="/admin/logout.php">Sair</a>
</header>

<?php $qsBusca = $busca !== '' ? '&q=' . urlencode($busca) : ''; ?>
<nav class="etapas-nav">
    <a href="/admin/index.php<?= $busca !== '' ? '?q=' . urlencode($busca) : '' ?>" class="<?= $etapaFiltro === '' && $filtroEspecial === '' ? 'ativo' : '' ?>"><?= $souDono ? 'Minhas' : 'Todas' ?> (<?= (int)$totalAtivas ?>)</a>
    <?php foreach (ETAPAS_ATIVAS as $et): ?>
        <a href="/admin/index.php?etapa=<?= urlencode($et) . $qsBusca ?>" class="<?= $etapaFiltro === $et && $filtroEspecial === '' ? 'ativo' : '' ?>">
            <?= e(etapaLabel($et)) ?> (<?= (int)($contagemPorEtapa[$et] ?? 0) ?>)
        </a>
    <?php endforeach; ?>
    <a href="/admin/index.php?etapa=fechado<?= $qsBusca ?>" class="<?= $etapaFiltro === 'fechado' && $filtroEspecial === '' ? 'ativo' : '' ?>">
        ✅ Fechadas (<?= $totalFechadas ?>)
    </a>
    <a href="/admin/index.php?etapa=encerradas<?= $qsBusca ?>" class="<?= $etapaFiltro === 'encerradas' && $filtroEspecial === '' ? 'ativo' : '' ?>">
        ❌ Encerradas (<?= $totalEncerradas ?>)
    </a>
</nav>

<main>

<?php
// Rótulo de cada ?filtro= especial, pro banner "filtro ativo" abaixo —
// mesmo texto usado no rótulo do card que originou o clique.
$filtroEspecialLabel = [
    'hoje' => 'Leads novos hoje', 'ontem' => 'Leads novos ontem',
    'semana' => 'Recebidos nos últimos 7 dias',
    'atrasadas' => 'Atrasadas', 'negociacao' => 'Em negociação/presencial',
    'fechado_mes' => 'Fechadas este mês',
][$filtroEspecial] ?? '';
if ($filtroEspecialLabel !== ''): ?>
<div class="card" style="display:flex;align-items:center;justify-content:space-between;padding:12px 20px;margin-bottom:14px">
    <span>🔎 Mostrando: <strong><?= e($filtroEspecialLabel) ?></strong></span>
    <a href="/admin/index.php<?= $busca !== '' ? '?q=' . urlencode($busca) : '' ?>">Limpar filtro</a>
</div>
<?php endif; ?>

<?php if ($perfil === 'consultor'): ?>
    <div class="stat-grid">
        <a class="stat-card" href="/admin/index.php">
            <div class="valor"><?= (int)$stats['ativas'] ?></div>
            <div class="rotulo">Minhas oportunidades ativas</div>
        </a>
        <a class="stat-card <?= $stats['atrasadas'] > 0 ? 'alerta' : '' ?>" href="/admin/index.php?filtro=atrasadas">
            <div class="valor"><?= (int)$stats['atrasadas'] ?></div>
            <div class="rotulo">Atrasadas</div>
        </a>
        <a class="stat-card neutro" href="/admin/index.php?filtro=semana">
            <div class="valor"><?= (int)$stats['recebidas_semana'] ?></div>
            <div class="rotulo">Recebidas nos últimos 7 dias</div>
        </a>
        <div class="stat-card <?= $stats['disponivel'] ? 'sucesso' : 'neutro' ?>">
            <div class="valor"><?= $stats['disponivel'] ? '🟢' : '⚪' ?></div>
            <div class="rotulo"><?= $stats['disponivel'] ? 'Disponível pra fila' : ($stats['plantao'] ? 'Offline (plantão)' : 'Offline') ?></div>
        </div>
        <a class="stat-card" href="/admin/index.php?filtro=negociacao">
            <div class="valor"><?= (int)$stats['em_negociacao'] ?></div>
            <div class="rotulo">Em negociação/presencial</div>
        </a>
        <a class="stat-card neutro" href="/admin/index.php?filtro=negociacao">
            <div class="valor"><?= moeda($stats['valor_em_negociacao']) ?></div>
            <div class="rotulo">Valor em negociação</div>
        </a>
        <a class="stat-card sucesso" href="/admin/index.php?filtro=fechado_mes">
            <div class="valor"><?= (int)$stats['fechadas_mes'] ?></div>
            <div class="rotulo">Fechadas este mês</div>
        </a>
        <a class="stat-card sucesso" href="/admin/index.php?filtro=fechado_mes">
            <div class="valor"><?= moeda($stats['valor_fechado_mes']) ?></div>
            <div class="rotulo">Valor fechado este mês</div>
        </a>
        <div class="stat-card neutro">
            <div class="valor"><?= $stats['taxa_conversao'] === null ? '—' : $stats['taxa_conversao'] . '%' ?></div>
            <div class="rotulo">Taxa de conversão</div>
        </div>
    </div>
<?php elseif (perfilVeTudo()): ?>
    <div class="stat-grid">
        <a class="stat-card" href="/admin/index.php">
            <div class="valor"><?= (int)$stats['ativas'] ?></div>
            <div class="rotulo">Oportunidades ativas</div>
        </a>
        <a class="stat-card <?= $stats['atrasadas'] > 0 ? 'alerta' : '' ?>" href="/admin/index.php?filtro=atrasadas">
            <div class="valor"><?= (int)$stats['atrasadas'] ?></div>
            <div class="rotulo">Atrasadas</div>
        </a>
        <a class="stat-card neutro" href="/admin/index.php?filtro=hoje">
            <div class="valor"><?= (int)$stats['novas_hoje'] ?></div>
            <div class="rotulo">Leads novos hoje</div>
        </a>
        <a class="stat-card neutro" href="/admin/index.php?filtro=ontem">
            <div class="valor"><?= (int)$stats['novas_ontem'] ?></div>
            <div class="rotulo">Leads novos ontem</div>
        </a>
        <a class="stat-card neutro" href="/admin/index.php?filtro=semana">
            <div class="valor"><?= (int)$stats['novas_semana'] ?></div>
            <div class="rotulo">Leads novos (7 dias)</div>
        </a>
        <a class="stat-card sucesso" href="/admin/index.php?filtro=fechado_mes">
            <div class="valor"><?= (int)$stats['fechadas_mes'] ?></div>
            <div class="rotulo">Fechadas este mês</div>
        </a>
        <a class="stat-card sucesso" href="/admin/index.php?filtro=fechado_mes">
            <div class="valor"><?= moeda($stats['valor_fechado_mes']) ?></div>
            <div class="rotulo">Valor fechado este mês</div>
        </a>
        <div class="stat-card neutro">
            <div class="valor"><?= $stats['taxa_conversao'] === null ? '—' : $stats['taxa_conversao'] . '%' ?></div>
            <div class="rotulo">Taxa de conversão geral</div>
        </div>
    </div>

    <div class="card">
        <h3>Funil (oportunidades ativas)</h3>
        <div class="funil-barra">
            <?php foreach (ETAPAS_ATIVAS as $et): ?>
                <?php $qtd = (int)($contagemPorEtapa[$et] ?? 0); $pct = $totalAtivas > 0 ? round($qtd / $totalAtivas * 100) : 0; ?>
                <div class="funil-linha">
                    <span class="etapa-nome"><?= e(etapaLabel($et)) ?></span>
                    <span class="barra-fundo"><span class="barra-preenchida" style="width:<?= $pct ?>%"></span></span>
                    <span class="qtd"><?= $qtd ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<div class="card">
    <form method="get">
        <?php if ($filtroEspecial !== ''): ?>
            <input type="hidden" name="filtro" value="<?= e($filtroEspecial) ?>">
        <?php elseif ($etapaFiltro !== ''): ?>
            <input type="hidden" name="etapa" value="<?= e($etapaFiltro) ?>">
        <?php endif; ?>
        <input type="text" name="q" value="<?= e($busca) ?>" placeholder="Buscar por nome, telefone, marca, modelo ou placa...">
        <button type="submit">Buscar</button>
        <?php if ($busca !== ''):
            $voltarQs = $filtroEspecial !== '' ? '?filtro=' . urlencode($filtroEspecial) : ($etapaFiltro !== '' ? '?etapa=' . urlencode($etapaFiltro) : '');
        ?><a href="/admin/index.php<?= $voltarQs ?>">Limpar</a><?php endif; ?>
    </form>
    <?php
        // 21/09/2026, "coloca botão para gerar pdf relatório" — PDF lista
        // exatamente o que a tabela abaixo está mostrando (mesmo filtro/
        // etapa/busca), não só a página atual (sem LIMIT/OFFSET no PDF).
        $pdfQsPartes = [];
        if ($filtroEspecial !== '') $pdfQsPartes[] = 'filtro=' . urlencode($filtroEspecial);
        elseif ($etapaFiltro !== '') $pdfQsPartes[] = 'etapa=' . urlencode($etapaFiltro);
        if ($busca !== '') $pdfQsPartes[] = 'q=' . urlencode($busca);
        $pdfQs = $pdfQsPartes ? '?' . implode('&', $pdfQsPartes) : '';
    ?>
    <a class="btn" style="margin-top:10px;display:inline-block" href="/admin/dashboard_relatorio_pdf.php<?= $pdfQs ?>" target="_blank">📄 Gerar PDF do relatório</a>
    <?php
        // 21/09/2026, "ideal gerar com detalhe trazer resumos das convesas"
        // — 2º link opt-in, nunca o padrão (relatório detalhado com o
        // resumo_ia de cada lead quebra a visão rápida de tabela pra
        // listas grandes — ver includes/dashboard_pdf.php).
        $pdfQsDetalhado = $pdfQs !== '' ? $pdfQs . '&detalhado=1' : '?detalhado=1';
    ?>
    <a class="btn" style="margin-top:10px;margin-left:8px;display:inline-block" href="/admin/dashboard_relatorio_pdf.php<?= $pdfQsDetalhado ?>" target="_blank">📄💬 PDF detalhado (com resumo da IA)</a>
</div>

<table class="tabela-oportunidades">
    <thead>
        <tr>
            <th>Cliente</th><th>Recebido em</th><th>Veículo</th><th>Etapa</th><th>Responsável</th><th>Próxima ação</th><th></th>
        </tr>
    </thead>
    <tbody>
    <?php if (!$oportunidades): ?>
        <tr><td colspan="7"><?= $busca !== '' ? 'Nenhuma oportunidade encontrada pra essa busca.' : 'Nenhuma oportunidade nessa etapa.' ?></td></tr>
    <?php endif; ?>
    <?php foreach ($oportunidades as $op): ?>
        <?php // "Atrasada" só faz sentido pra oportunidade ainda em aberto —
              // 'próxima ação' de um lead já fechado/perdido/sem_perfil é
              // resto de quando a etapa ainda estava ativa, não uma
              // pendência de verdade pra destacar em vermelho aqui. ?>
        <?php $atrasada = in_array($op['etapa'], ETAPAS_ATIVAS, true) && $op['proxima_acao_em'] && $op['proxima_acao_em'] < $agora; ?>
        <?php $quente = $op['temperatura_lead'] === 'quente'; ?>
        <tr class="<?= trim(($atrasada ? 'linha-atrasada ' : '') . ($quente ? 'linha-quente' : '')) ?>">
            <td>
                <a href="/admin/oportunidade.php?id=<?= (int)$op['id'] ?>"><?= e($op['cliente_nome'] ?: '(sem nome)') ?></a>
                <?php if ($quente): ?>
                    <span class="badge badge-quente">🔥 Quente</span>
                <?php elseif ($op['temperatura_lead'] === 'morno'): ?>
                    <span class="badge">🌤️ Morno</span>
                <?php elseif ($op['temperatura_lead'] === 'frio'): ?>
                    <span class="badge">❄️ Frio</span>
                <?php endif; ?>
                <br><small><?= e($op['cliente_telefone']) ?></small>
            </td>
            <td>
                <?= $op['created_at'] ? date('d/m/Y H:i', strtotime($op['created_at'])) : '—' ?>
                <?php if ($etapaBuscandoFechadas && $op['data_compra']): ?>
                    <br><small>fechado <?= date('d/m/Y', strtotime($op['data_compra'])) ?></small>
                <?php elseif ($etapaBuscandoEncerradas && $op['updated_at']): ?>
                    <br><small>encerrado <?= date('d/m/Y', strtotime($op['updated_at'])) ?></small>
                <?php endif; ?>
            </td>
            <td><?= e($op['veiculo_modelo'] ?: '—') ?> <?= e($op['veiculo_ano']) ?></td>
            <td>
                <span class="badge <?= e(etapaBadgeClasse($op['etapa'])) ?>"><?= e(etapaLabel($op['etapa'])) ?></span>
                <?php if ($etapaBuscandoEncerradas && $op['motivo_perda']): ?>
                    <br><small><?= e($op['motivo_perda']) ?></small>
                <?php endif; ?>
            </td>
            <td><?= e($op['responsavel_nome'] ?? '—') ?></td>
            <td>
                <?php if ($op['proxima_acao_em']): ?>
                    <span class="badge <?= $atrasada ? 'badge-atraso' : '' ?>">
                        <?= $atrasada ? '⚠️ ' : '' ?><?= date('d/m H:i', strtotime($op['proxima_acao_em'])) ?>
                    </span>
                    <br><small><?= e($op['proxima_acao']) ?></small>
                <?php else: ?>
                    <span class="sem-proxima-acao">sem próxima ação</span>
                <?php endif; ?>
            </td>
            <td><a href="/admin/oportunidade.php?id=<?= (int)$op['id'] ?>">Abrir →</a></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php renderPaginacao($totalFiltrado); ?>
</main>
<?php include __DIR__ . '/_pwa_register.php'; ?>
<?php include __DIR__ . '/_notify.php'; ?>
</body>
</html>
