<?php
/**
 * includes/qualidade_ia.php — métricas de acerto da qualificação por IA
 * (bloco 3), cruzando o que a IA decidiu/classificou com o resultado real
 * depois (fechou, perdeu, continua ativo). Só consulta e soma, nunca muda
 * dado — mesmo espírito de includes/dashboard.php.
 *
 * A ideia (pedido do José/Jean, 13/09/2026): o modelo em si não é
 * retreinado — o ganho vem de olhar esses números periodicamente e ajustar
 * o PROMPT (`IA_QUALIFICACAO_PROMPT_SISTEMA`/`IA_EXTRACAO_PROMPT`, ambos em
 * includes/ia_qualificacao.php) com base no que os dados mostram. Um
 * "afiar com o uso" guiado por humano lendo o relatório, não automático.
 */

require_once __DIR__ . '/db.php';

/**
 * Funil de resultado da qualificação — toda oportunidade cai em 1 balde:
 * ainda em andamento (não saiu do bloco 2/3 ainda), sem perfil (IA
 * descartou), escalada por estagnação (IA desistiu de tentar sozinha) ou
 * qualificação completa (IA levou até o fim normalmente).
 */
function qualidadeIaFunilResultado(): array {
    $db = getDB();

    $emAndamento = (int)$db->query("
        SELECT COUNT(*) FROM oportunidades WHERE etapa IN ('whatsapp', 'qualificacao_ia')
    ")->fetchColumn();

    $semPerfil = (int)$db->query("SELECT COUNT(*) FROM oportunidades WHERE etapa = 'sem_perfil'")->fetchColumn();

    // "Escalado por estagnação" é identificado pelo texto gravado em
    // mudarEtapa() (includes/ia_qualificacao.php::iaProcessarTurno()) — não
    // tem coluna própria pra isso, só o histórico mesmo.
    $escaladoEstagnacao = (int)$db->query("
        SELECT COUNT(DISTINCT oportunidade_id) FROM oportunidade_historico
        WHERE observacao LIKE '%estagnada%'
    ")->fetchColumn();

    $totalPassouPelaIa = (int)$db->query("
        SELECT COUNT(*) FROM oportunidades WHERE etapa NOT IN ('whatsapp', 'qualificacao_ia', 'sem_perfil')
    ")->fetchColumn();
    $completa = max(0, $totalPassouPelaIa - $escaladoEstagnacao);

    return [
        'em_andamento'        => $emAndamento,
        'sem_perfil'          => $semPerfil,
        'escalado_estagnacao' => $escaladoEstagnacao,
        'completa'            => $completa,
    ];
}

/**
 * temperatura_lead (julgamento da IA sobre engajamento, ao longo da
 * conversa) cruzado com o resultado real depois — pra ver se "quente"
 * realmente fecha mais que "frio". Só conta oportunidade que JÁ saiu do
 * bloco 3 (senão o resultado ainda não existe pra comparar).
 */
function qualidadeIaTemperaturaVsResultado(): array {
    $db = getDB();
    $linhas = [];
    foreach (['quente', 'morno', 'frio'] as $temp) {
        $stmt = $db->prepare("
            SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN etapa = 'fechado' THEN 1 ELSE 0 END) AS fechadas,
                SUM(CASE WHEN etapa = 'perdido' THEN 1 ELSE 0 END) AS perdidas
            FROM oportunidades
            WHERE temperatura_lead = ? AND etapa NOT IN ('whatsapp', 'qualificacao_ia', 'sem_perfil')
        ");
        $stmt->execute([$temp]);
        $r = $stmt->fetch();
        $total = (int)$r['total'];
        $fechadas = (int)$r['fechadas'];
        $perdidas = (int)$r['perdidas'];
        $decididas = $fechadas + $perdidas;
        $linhas[] = [
            'temperatura'    => $temp,
            'total'          => $total,
            'fechadas'       => $fechadas,
            'perdidas'       => $perdidas,
            'ativas'         => $total - $decididas,
            'taxa_fechamento' => $decididas > 0 ? round($fechadas / $decididas * 100) : null, // null = ainda sem dado suficiente
        ];
    }
    return $linhas;
}

/**
 * O que aconteceu DEPOIS com quem a IA escalou por estagnação — se o
 * consultor consegue reverter (fecha/segue negociando) ou se realmente
 * era gente que ia esfriar mesmo, ajuda a calibrar se o limite de
 * IA_LIMITE_TURNOS_SEM_AVANCO (includes/ia_qualificacao.php) está bom.
 */
function qualidadeIaEscalacoesEstagnacaoDepois(): array {
    $db = getDB();
    $stmt = $db->query("
        SELECT o.etapa, COUNT(*) AS n
        FROM oportunidades o
        WHERE o.id IN (
            SELECT DISTINCT oportunidade_id FROM oportunidade_historico WHERE observacao LIKE '%estagnada%'
        )
        GROUP BY o.etapa
    ");
    return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
}
