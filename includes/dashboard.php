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
        WHERE fechado_por = ? AND etapa = 'fechado' AND data_compra >= date('now','localtime','start of month')
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

    $novasHoje = (int)$db->query("SELECT COUNT(*) FROM oportunidades WHERE date(created_at) = date('now','localtime')")->fetchColumn();
    $novasSemana = (int)$db->query("SELECT COUNT(*) FROM oportunidades WHERE created_at >= datetime('now','-7 days','localtime')")->fetchColumn();

    $r = $db->query("
        SELECT COUNT(*) AS qtd, COALESCE(SUM(valor_final), 0) AS total
        FROM oportunidades WHERE etapa = 'fechado' AND data_compra >= date('now','localtime','start of month')
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
        'novas_semana'      => $novasSemana,
        'fechadas_mes'      => (int)$r['qtd'],
        'valor_fechado_mes' => (float)$r['total'],
        'taxa_conversao'    => $taxaConversao,
    ];
}
