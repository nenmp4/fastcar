<?php
/**
 * includes/dashboard.php — métricas do dashboard de cada perfil (consultor
 * cuida sozinho dos blocos 5+6 desde a mesclagem de 13/09/2026 — atende E
 * negocia/fecha —, super_admin tem visão geral). Só consulta e soma, nunca
 * muda dado — separado de includes/oportunidades.php (que é regra de
 * negócio de verdade, mudarEtapa() etc).
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/oportunidades.php';
require_once __DIR__ . '/vendas.php';

/**
 * Dashboard do consultor — carteira dele (bloco 5: ativas/atrasadas/
 * recebidas na semana/status da fila) + pipeline de negociação e resultado
 * do mês (bloco 6, ex-dashboard do closer, mesclado aqui em 13/09/2026).
 */
function dashboardConsultor(int $usuarioId): array {
    $db = getDB();
    $ph = implode(',', array_fill(0, count(ETAPAS_ATIVAS), '?'));

    $stmt = $db->prepare("SELECT COUNT(*) FROM oportunidades WHERE responsavel_id = ? AND etapa IN ({$ph})");
    $stmt->execute([$usuarioId, ...ETAPAS_ATIVAS]);
    $ativas = (int)$stmt->fetchColumn();

    $stmt = $db->prepare("
        SELECT COUNT(*) FROM oportunidades
        WHERE responsavel_id = ? AND etapa IN ({$ph})
          AND proxima_acao_em IS NOT NULL AND proxima_acao_em < datetime('now','localtime')
    ");
    $stmt->execute([$usuarioId, ...ETAPAS_ATIVAS]);
    $atrasadas = (int)$stmt->fetchColumn();

    $stmt = $db->prepare("
        SELECT COUNT(DISTINCT oportunidade_id) FROM oportunidade_historico
        WHERE responsavel_id = ? AND etapa_nova = 'atendimento'
          AND created_at >= datetime('now','-7 days','localtime')
    ");
    $stmt->execute([$usuarioId]);
    $recebidasSemana = (int)$stmt->fetchColumn();

    // 08/10/2026, "card clicavel leads de hoje leads de ontem... para
    // consultores" — mesmos 2 contadores que dashboardSuperAdmin() já
    // tinha (abaixo), só escopados à carteira do próprio consultor
    // (responsavel_id), pro card novo em admin/index.php. Mesma restrição
    // a ETAPAS_ATIVAS de lá — nunca conta lead já fechado/perdido/
    // sem_perfil, pra bater exatamente com o que ?filtro=hoje/ontem lista.
    $stmt = $db->prepare("SELECT COUNT(*) FROM oportunidades WHERE responsavel_id = ? AND etapa IN ({$ph}) AND date(created_at) = date('now','localtime')");
    $stmt->execute([$usuarioId, ...ETAPAS_ATIVAS]);
    $novasHoje = (int)$stmt->fetchColumn();
    $stmt = $db->prepare("SELECT COUNT(*) FROM oportunidades WHERE responsavel_id = ? AND etapa IN ({$ph}) AND date(created_at) = date('now','localtime','-1 day')");
    $stmt->execute([$usuarioId, ...ETAPAS_ATIVAS]);
    $novasOntem = (int)$stmt->fetchColumn();

    $stmt = $db->prepare("SELECT disponivel, posicao_fila, plantao_fim_expediente FROM usuarios WHERE id = ?");
    $stmt->execute([$usuarioId]);
    $eu = $stmt->fetch() ?: ['disponivel' => 0, 'posicao_fila' => null, 'plantao_fim_expediente' => 0];

    $stmt = $db->prepare("
        SELECT COUNT(*), COALESCE(SUM(valor_ofertado), 0)
        FROM oportunidades WHERE responsavel_id = ? AND etapa IN ('negociacao', 'presencial')
    ");
    $stmt->execute([$usuarioId]);
    [$emNegociacao, $valorEmNegociacao] = $stmt->fetch(PDO::FETCH_NUM);

    $stmt = $db->prepare("
        SELECT COUNT(*), COALESCE(SUM(valor_final), 0)
        FROM oportunidades
        WHERE fechado_por = ? AND etapa = 'fechado' AND COALESCE(oculto_estoque_motivo, '') NOT LIKE 'Cadastro duplicado%' AND data_compra >= date('now','localtime','start of month')
    ");
    $stmt->execute([$usuarioId]);
    [$fechadasMes, $valorFechadoMes] = $stmt->fetch(PDO::FETCH_NUM);

    // Taxa de conversão: das que passaram pela mão desse consultor (fechou
    // ou perdeu, contando tudo desde sempre), quantas viraram negócio de verdade.
    $stmt = $db->prepare("
        SELECT
            SUM(CASE WHEN etapa = 'fechado' THEN 1 ELSE 0 END) AS fechadas,
            SUM(CASE WHEN etapa = 'perdido' THEN 1 ELSE 0 END) AS perdidas
        FROM oportunidades WHERE fechado_por = ? OR (responsavel_id = ? AND etapa = 'perdido')
    ");
    $stmt->execute([$usuarioId, $usuarioId]);
    $r = $stmt->fetch();
    $totalDecididas = (int)($r['fechadas'] ?? 0) + (int)($r['perdidas'] ?? 0);
    $taxaConversao = $totalDecididas > 0 ? round(((int)$r['fechadas'] / $totalDecididas) * 100) : null;

    return [
        'ativas'              => $ativas,
        'atrasadas'           => $atrasadas,
        'novas_hoje'          => $novasHoje,
        'novas_ontem'         => $novasOntem,
        'recebidas_semana'    => $recebidasSemana,
        'disponivel'          => (bool)$eu['disponivel'],
        'plantao'             => (bool)$eu['plantao_fim_expediente'],
        'em_negociacao'       => (int)$emNegociacao,
        'valor_em_negociacao' => (float)$valorEmNegociacao,
        'fechadas_mes'        => (int)$fechadasMes,
        'valor_fechado_mes'   => (float)$valorFechadoMes,
        'taxa_conversao'      => $taxaConversao, // null = sem dado suficiente ainda
    ];
}

/**
 * Dashboard do vendedor (módulo de vendas, 17/09/2026) — carteira dele:
 * ativas/atrasadas/recebidas na semana + resultado do mês. Mesma estrutura
 * de dashboardConsultor(), espelhada pra `vendas`/perfil `vendedor`.
 */
function dashboardVendedor(int $usuarioId): array {
    $db = getDB();
    $ph = implode(',', array_fill(0, count(ETAPAS_VENDA_ATIVAS), '?'));

    $stmt = $db->prepare("SELECT COUNT(*) FROM vendas WHERE responsavel_id = ? AND etapa IN ({$ph})");
    $stmt->execute([$usuarioId, ...ETAPAS_VENDA_ATIVAS]);
    $ativas = (int)$stmt->fetchColumn();

    $stmt = $db->prepare("
        SELECT COUNT(*) FROM vendas
        WHERE responsavel_id = ? AND etapa IN ({$ph})
          AND proxima_acao_em IS NOT NULL AND proxima_acao_em < datetime('now','localtime')
    ");
    $stmt->execute([$usuarioId, ...ETAPAS_VENDA_ATIVAS]);
    $atrasadas = (int)$stmt->fetchColumn();

    $stmt = $db->prepare("
        SELECT COUNT(DISTINCT venda_id) FROM venda_historico
        WHERE responsavel_id = ? AND etapa_nova IN ('crm_preenchido', 'negociacao')
          AND created_at >= datetime('now','-7 days','localtime')
    ");
    $stmt->execute([$usuarioId]);
    $recebidasSemana = (int)$stmt->fetchColumn();

    // 22/10/2026, "em vendas não tem dasbord igual de consultores de
    // compras" — dashboardConsultor() já tinha esses 2 contadores desde
    // 08/10/2026 ("card clicavel leads de hoje leads de ontem"), nunca
    // tinha sido portado pra vendas. Mesmo cálculo, só trocando
    // oportunidades/ETAPAS_ATIVAS por vendas/ETAPAS_VENDA_ATIVAS.
    $stmt = $db->prepare("SELECT COUNT(*) FROM vendas WHERE responsavel_id = ? AND etapa IN ({$ph}) AND date(created_at) = date('now','localtime')");
    $stmt->execute([$usuarioId, ...ETAPAS_VENDA_ATIVAS]);
    $novasHoje = (int)$stmt->fetchColumn();
    $stmt = $db->prepare("SELECT COUNT(*) FROM vendas WHERE responsavel_id = ? AND etapa IN ({$ph}) AND date(created_at) = date('now','localtime','-1 day')");
    $stmt->execute([$usuarioId, ...ETAPAS_VENDA_ATIVAS]);
    $novasOntem = (int)$stmt->fetchColumn();

    $stmt = $db->prepare("SELECT disponivel, plantao_fim_expediente FROM usuarios WHERE id = ?");
    $stmt->execute([$usuarioId]);
    $eu = $stmt->fetch() ?: ['disponivel' => 0, 'plantao_fim_expediente' => 0];

    $stmt = $db->prepare("
        SELECT COUNT(*), COALESCE(SUM(preco_venda), 0)
        FROM vendas WHERE responsavel_id = ? AND etapa = 'vendido' AND data_venda >= date('now','localtime','start of month')
    ");
    $stmt->execute([$usuarioId]);
    [$vendidasMes, $valorVendidoMes] = $stmt->fetch(PDO::FETCH_NUM);

    $stmt = $db->prepare("
        SELECT
            SUM(CASE WHEN etapa = 'vendido' THEN 1 ELSE 0 END) AS vendidas,
            SUM(CASE WHEN etapa = 'cancelada' THEN 1 ELSE 0 END) AS canceladas
        FROM vendas WHERE responsavel_id = ?
    ");
    $stmt->execute([$usuarioId]);
    $r = $stmt->fetch();
    $totalDecididas = (int)($r['vendidas'] ?? 0) + (int)($r['canceladas'] ?? 0);
    $taxaConversao = $totalDecididas > 0 ? round(((int)$r['vendidas'] / $totalDecididas) * 100) : null;

    return [
        'ativas'            => $ativas,
        'atrasadas'         => $atrasadas,
        'recebidas_semana'  => $recebidasSemana,
        'novas_hoje'        => $novasHoje,
        'novas_ontem'       => $novasOntem,
        'disponivel'        => (bool)$eu['disponivel'],
        'plantao'           => (bool)$eu['plantao_fim_expediente'],
        'vendidas_mes'      => (int)$vendidasMes,
        'valor_vendido_mes' => (float)$valorVendidoMes,
        'taxa_conversao'    => $taxaConversao,
    ];
}

/** Dashboard geral de vendas (super_admin/supervisor) — visão da empresa inteira. */
function dashboardVendasGeral(): array {
    $db = getDB();
    $ph = implode(',', array_fill(0, count(ETAPAS_VENDA_ATIVAS), '?'));

    $stmt = $db->prepare("SELECT COUNT(*) FROM vendas WHERE etapa IN ({$ph})");
    $stmt->execute(ETAPAS_VENDA_ATIVAS);
    $ativas = (int)$stmt->fetchColumn();

    $stmt = $db->prepare("
        SELECT COUNT(*) FROM vendas WHERE etapa IN ({$ph})
          AND proxima_acao_em IS NOT NULL AND proxima_acao_em < datetime('now','localtime')
    ");
    $stmt->execute(ETAPAS_VENDA_ATIVAS);
    $atrasadas = (int)$stmt->fetchColumn();

    $r = $db->query("
        SELECT COUNT(*) AS qtd, COALESCE(SUM(preco_venda), 0) AS total
        FROM vendas WHERE etapa = 'vendido' AND data_venda >= date('now','localtime','start of month')
    ")->fetch();

    // 24/10/2026, "dasborda das vendas os leads chegando não tem igual de
    // compras" — mesmos contadores de dashboardSuperAdmin() (só etapa
    // ativa, batendo com a lista ao clicar no card ?filtro=hoje/ontem/semana).
    $stmt = $db->prepare("SELECT COUNT(*) FROM vendas WHERE etapa IN ({$ph}) AND date(created_at) = date('now','localtime')");
    $stmt->execute(ETAPAS_VENDA_ATIVAS);
    $novasHoje = (int)$stmt->fetchColumn();
    $stmt = $db->prepare("SELECT COUNT(*) FROM vendas WHERE etapa IN ({$ph}) AND date(created_at) = date('now','localtime','-1 day')");
    $stmt->execute(ETAPAS_VENDA_ATIVAS);
    $novasOntem = (int)$stmt->fetchColumn();
    $stmt = $db->prepare("SELECT COUNT(*) FROM vendas WHERE etapa IN ({$ph}) AND created_at >= datetime('now','localtime','-7 days')");
    $stmt->execute(ETAPAS_VENDA_ATIVAS);
    $novasSemana = (int)$stmt->fetchColumn();

    $rConv = $db->query("
        SELECT
            SUM(CASE WHEN etapa = 'vendido' THEN 1 ELSE 0 END) AS vendidas,
            SUM(CASE WHEN etapa = 'cancelada' THEN 1 ELSE 0 END) AS canceladas
        FROM vendas
    ")->fetch();
    $totalDecididas = (int)($rConv['vendidas'] ?? 0) + (int)($rConv['canceladas'] ?? 0);
    $taxaConversao = $totalDecididas > 0 ? round(((int)$rConv['vendidas'] / $totalDecididas) * 100) : null;

    return [
        'ativas'            => $ativas,
        'atrasadas'         => $atrasadas,
        'novas_hoje'        => $novasHoje,
        'novas_ontem'       => $novasOntem,
        'novas_semana'      => $novasSemana,
        'vendidas_mes'      => (int)$r['qtd'],
        'valor_vendido_mes' => (float)$r['total'],
        'taxa_conversao'    => $taxaConversao,
    ];
}

/** Dashboard do super_admin — visão geral da empresa. */
function dashboardSuperAdmin(): array {
    $db = getDB();
    $ph = implode(',', array_fill(0, count(ETAPAS_ATIVAS), '?'));

    $stmt = $db->prepare("SELECT COUNT(*) FROM oportunidades WHERE etapa IN ({$ph})");
    $stmt->execute(ETAPAS_ATIVAS);
    $ativas = (int)$stmt->fetchColumn();

    $stmt = $db->prepare("
        SELECT COUNT(*) FROM oportunidades WHERE etapa IN ({$ph})
          AND proxima_acao_em IS NOT NULL AND proxima_acao_em < datetime('now','localtime')
    ");
    $stmt->execute(ETAPAS_ATIVAS);
    $atrasadas = (int)$stmt->fetchColumn();

    // 21/09/2026, achado real (usuário colou a listagem de "?filtro=hoje" —
    // 17 linhas — contra o card mostrando 37/31): estas 3 contagens não
    // filtravam por etapa nenhuma (contavam TUDO criado no período, mesmo
    // já 'perdido'/'sem_perfil'/'fechado'), mas o clique no card
    // (?filtro=hoje/ontem/semana, admin/index.php) sempre restringiu a
    // ETAPAS_ATIVAS — o comentário de 18/09/2026 já documentava a intenção
    // ("listando exatamente o que o card está contando"), só a contagem
    // aqui nunca tinha sido alinhada com isso. Restringido a ETAPAS_ATIVAS
    // pra bater com o que a lista de verdade mostra ao clicar.
    $stmt = $db->prepare("SELECT COUNT(*) FROM oportunidades WHERE etapa IN ({$ph}) AND date(created_at) = date('now','localtime')");
    $stmt->execute(ETAPAS_ATIVAS);
    $novasHoje = (int)$stmt->fetchColumn();
    $stmt = $db->prepare("SELECT COUNT(*) FROM oportunidades WHERE etapa IN ({$ph}) AND date(created_at) = date('now','localtime','-1 day')");
    $stmt->execute(ETAPAS_ATIVAS);
    $novasOntem = (int)$stmt->fetchColumn();
    $stmt = $db->prepare("SELECT COUNT(*) FROM oportunidades WHERE etapa IN ({$ph}) AND created_at >= datetime('now','-7 days','localtime')");
    $stmt->execute(ETAPAS_ATIVAS);
    $novasSemana = (int)$stmt->fetchColumn();

    $r = $db->query("
        SELECT COUNT(*) AS qtd, COALESCE(SUM(valor_final), 0) AS total
        FROM oportunidades WHERE etapa = 'fechado' AND COALESCE(oculto_estoque_motivo, '') NOT LIKE 'Cadastro duplicado%' AND data_compra >= date('now','localtime','start of month')
    ")->fetch();

    $rConv = $db->query("
        SELECT
            SUM(CASE WHEN etapa = 'fechado' THEN 1 ELSE 0 END) AS fechadas,
            SUM(CASE WHEN etapa = 'perdido' THEN 1 ELSE 0 END) AS perdidas
        FROM oportunidades
    ")->fetch();
    $totalDecididas = (int)($rConv['fechadas'] ?? 0) + (int)($rConv['perdidas'] ?? 0);
    $taxaConversao = $totalDecididas > 0 ? round(((int)$rConv['fechadas'] / $totalDecididas) * 100) : null;

    return [
        'ativas'            => $ativas,
        'atrasadas'         => $atrasadas,
        'novas_hoje'        => $novasHoje,
        'novas_ontem'       => $novasOntem,
        'novas_semana'      => $novasSemana,
        'fechadas_mes'      => (int)$r['qtd'],
        'valor_fechado_mes' => (float)$r['total'],
        'taxa_conversao'    => $taxaConversao,
    ];
}

/**
 * Filtro de gestão de leads (26/10/2026, "filtro no dashboard dos
 * consultores/vendedores — por data, tipo de lead quente etc") —
 * compartilhado entre admin/index.php (compra) e admin/vendas.php
 * (vendas). Temperatura (?temp=quente|morno|frio|sem) + período de
 * ENTRADA do lead (?entrada=hoje|ontem|7|15|30|personalizado, com
 * ?entrada_de=&entrada_ate= no personalizado). Sempre whitelist, nunca
 * valor cru do GET no SQL.
 * Retorna [sql, params, qsPartes, temp, periodo, de, ate].
 */
function filtroGestaoLeads(array $get, string $colTemp, string $colData): array {
    $sql = ''; $params = []; $qs = [];
    $temp = in_array($get['temp'] ?? '', ['quente', 'morno', 'frio', 'sem'], true) ? $get['temp'] : '';
    if ($temp === 'sem') {
        $sql .= " AND COALESCE({$colTemp}, '') = ''";
    } elseif ($temp !== '') {
        $sql .= " AND {$colTemp} = ?";
        $params[] = $temp;
    }
    if ($temp !== '') $qs[] = 'temp=' . $temp;

    $periodo = in_array($get['entrada'] ?? '', ['hoje', 'ontem', '7', '15', '30', 'personalizado'], true) ? $get['entrada'] : '';
    $de = ''; $ate = '';
    switch ($periodo) {
        case 'hoje': $de = $ate = date('Y-m-d'); break;
        case 'ontem': $de = $ate = date('Y-m-d', strtotime('-1 day')); break;
        case '7': case '15': case '30':
            $de = date('Y-m-d', strtotime('-' . ((int)$periodo - 1) . ' days'));
            $ate = date('Y-m-d');
            break;
        case 'personalizado':
            $okDe = (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($get['entrada_de'] ?? ''));
            $okAte = (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($get['entrada_ate'] ?? ''));
            $de = $okDe ? $get['entrada_de'] : '';
            $ate = $okAte ? $get['entrada_ate'] : '';
            if (!$okDe && !$okAte) $periodo = '';
            break;
    }
    if ($periodo !== '') {
        if ($de !== '') { $sql .= " AND date({$colData}) >= ?"; $params[] = $de; }
        if ($ate !== '') { $sql .= " AND date({$colData}) <= ?"; $params[] = $ate; }
        $qs[] = 'entrada=' . $periodo;
        if ($periodo === 'personalizado') {
            if ($de !== '') $qs[] = 'entrada_de=' . $de;
            if ($ate !== '') $qs[] = 'entrada_ate=' . $ate;
        }
    }
    // 26/10/2026, "consultor organizar por data os leads — hoje a listagem
    // tá por ordem de tipo de lead" — ordenação escolhida pelo usuário.
    $ordem = array_key_exists($get['ordem'] ?? '', FILTRO_GESTAO_ORDENS) && ($get['ordem'] ?? '') !== '' ? $get['ordem'] : '';
    if ($ordem !== '') $qs[] = 'ordem=' . $ordem;
    return [$sql, $params, $qs, $temp, $periodo, $de, $ate, $ordem];
}

const FILTRO_GESTAO_ORDENS = [
    '' => 'Prioridade (lead quente primeiro)',
    'recentes' => 'Data de entrada — mais recentes',
    'antigos' => 'Data de entrada — mais antigos',
    'atualizados' => 'Última movimentação',
    'proxima_acao' => 'Próxima ação mais próxima',
];

/** ORDER BY da ordenação escolhida, ou null = deixa a ordem padrão da tela. */
function filtroGestaoOrdemSql(string $ordem, string $a): ?string {
    return match ($ordem) {
        'recentes' => "{$a}.created_at DESC, {$a}.id DESC",
        'antigos' => "{$a}.created_at ASC, {$a}.id ASC",
        'atualizados' => "{$a}.updated_at DESC, {$a}.id DESC",
        'proxima_acao' => "({$a}.proxima_acao_em IS NULL), {$a}.proxima_acao_em ASC, {$a}.created_at DESC",
        default => null,
    };
}

/** Hidden inputs pra um <form method="get"> não perder o filtro de gestão. */
function filtroGestaoHiddens(array $qsPartes): string {
    $h = '';
    foreach ($qsPartes as $p) {
        [$k, $v] = array_pad(explode('=', $p, 2), 2, '');
        $h .= '<input type="hidden" name="' . e($k) . '" value="' . e(urldecode($v)) . '">';
    }
    return $h;
}

/** Barra de filtro (temperatura + período de entrada), formulário GET próprio. */
function filtroGestaoForm(string $acao, array $manter, string $temp, string $periodo, string $de, string $ate, string $ordem = ''): string {
    $ops = fn(array $lista, string $atual) => implode('', array_map(
        fn($v, $r) => '<option value="' . e($v) . '"' . ($v === $atual ? ' selected' : '') . '>' . e($r) . '</option>',
        array_keys($lista), $lista));
    $h = '<form method="get" action="' . e($acao) . '" class="filtro-gestao" style="margin-top:10px;display:flex;gap:8px;align-items:center;flex-wrap:wrap">';
    foreach ($manter as $k => $v) $h .= '<input type="hidden" name="' . e($k) . '" value="' . e((string)$v) . '">';
    $h .= '<label style="margin:0">🌡️ Lead:</label><select name="temp" onchange="this.form.submit()" style="width:auto">'
        . $ops(['' => 'Todas as temperaturas', 'quente' => '🔥 Quente', 'morno' => '🌤️ Morno', 'frio' => '❄️ Frio', 'sem' => '❔ Sem classificação'], $temp)
        . '</select>';
    $h .= '<label style="margin:0">📅 Entrou:</label><select name="entrada" style="width:auto" onchange="var p=this.value===\'personalizado\';this.form.querySelectorAll(\'.fg-datas\').forEach(function(x){x.style.display=p?\'\':\'none\'});if(!p)this.form.submit()">'
        . $ops(['' => 'Qualquer data', 'hoje' => 'Hoje', 'ontem' => 'Ontem', '7' => 'Últimos 7 dias', '15' => 'Últimos 15 dias', '30' => 'Últimos 30 dias', 'personalizado' => 'Personalizado…'], $periodo)
        . '</select>';
    $h .= '<label style="margin:0">↕️ Ordenar:</label><select name="ordem" onchange="this.form.submit()" style="width:auto">'
        . $ops(FILTRO_GESTAO_ORDENS, $ordem) . '</select>';
    $mostra = $periodo === 'personalizado' ? '' : 'display:none';
    $h .= '<span class="fg-datas" style="' . $mostra . '">de <input type="date" name="entrada_de" value="' . e($de) . '" style="width:auto"> até <input type="date" name="entrada_ate" value="' . e($ate) . '" style="width:auto"></span>';
    $h .= '<button type="submit" class="fg-datas" style="width:auto;margin:0;' . $mostra . '">Filtrar</button>';
    if ($temp !== '' || $periodo !== '' || $ordem !== '') {
        $q = http_build_query($manter);
        $h .= '<a href="' . e($acao . ($q !== '' ? '?' . $q : '')) . '">Limpar filtro</a>';
    }
    return $h . '</form>';
}
