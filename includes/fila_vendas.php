<?php
/**
 * Distribuição automática de leads de VENDA (comprador entrando pelo
 * WhatsApp dedicado de vendas, 17/09/2026) — mesmo conceito de
 * includes/fila_leads.php (round-robin, plantão de fim de expediente,
 * teto configurável), mas pra perfil='vendedor' e tabela `vendas` em vez
 * de 'consultor'/`oportunidades`. Arquivo PRÓPRIO (não parametriza o de
 * compra) de propósito — mesmo espírito já documentado no CLAUDE.md pro
 * WhatsApp Box ("portar o conceito, não o arquivo direto... modelo de
 * dado e regras de negócio diferentes demais pra copy-paste direto"):
 * mexer no arquivo de compra, já bem testado em produção, pra acomodar um
 * segundo perfil/tabela é risco desnecessário — os dois evoluem
 * separados.
 */

require_once __DIR__ . '/db.php';

/** Teto de vendas ATIVAS por vendedor no rodízio automático — mesmo
 *  conceito de filaLeadsMaxAtivas(), config própria (não compartilha
 *  limite com a fila de compra: volumes e equipes são diferentes). */
function filaVendasMaxAtivas(): int {
    $valor = (int)(getConfig('fila_vendas_max_ativas') ?: 5);
    return $valor > 0 ? $valor : 5;
}

/** Conta negociações de venda "ativas" (ainda em qualquer etapa do funil
 *  em andamento, nunca vendido/cancelada/sem_perfil) de um vendedor. */
function contarVendasAtivas(int $usuarioId): int {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT COUNT(*) FROM vendas
        WHERE responsavel_id = ? AND etapa NOT IN ('vendido','cancelada','sem_perfil')
    ");
    $stmt->execute([$usuarioId]);
    return (int)$stmt->fetchColumn();
}

/**
 * Escolhe o próximo vendedor da fila e marca no rodízio — mesma lógica de
 * atribuirResponsavelAutomatico() (fila_leads.php), só filtrando
 * perfil='vendedor' e usando contarVendasAtivas(). Retorna null se não
 * tiver ninguém disponível nem plantão configurado.
 */
function atribuirVendedorAutomatico(): ?int {
    $usuarioId = proximoDaFilaVendas(disponivelOnly: true);
    if ($usuarioId === null) {
        $usuarioId = proximoDaFilaVendas(disponivelOnly: false, apenasPlantao: true);
    }
    if ($usuarioId === null) {
        return null;
    }

    $db = getDB();
    $db->beginTransaction();
    try {
        // Contador monotônico compartilhado com a fila de compra
        // (usuarios.posicao_fila) seria incorreto aqui — um vendedor não
        // compete pelo mesmo rodízio de leads de compra, então a posição
        // precisa ser calculada só entre vendedores (mesmo raciocínio do
        // "não empatar 2 no mesmo segundo" da fila de compra, granularidade
        // do SQLite).
        $proximaPosicao = 1 + (int)$db->query("
            SELECT COALESCE(MAX(posicao_fila), 0) FROM usuarios WHERE perfil = 'vendedor'
        ")->fetchColumn();
        $db->prepare("
            UPDATE usuarios SET posicao_fila = ?, ultimo_lead_recebido_em = datetime('now','localtime') WHERE id = ?
        ")->execute([$proximaPosicao, $usuarioId]);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    return $usuarioId;
}

function proximoDaFilaVendas(bool $disponivelOnly, bool $apenasPlantao = false): ?int {
    $db = getDB();
    $condicoes = ["bloqueado = 0", "perfil = 'vendedor'"];
    if ($apenasPlantao) {
        $condicoes[] = 'plantao_fim_expediente = 1';
    } else {
        $condicoes[] = 'plantao_fim_expediente = 0';
        if ($disponivelOnly) $condicoes[] = 'disponivel = 1';
    }
    $where = implode(' AND ', $condicoes);

    $stmt = $db->query("
        SELECT id FROM usuarios
        WHERE {$where}
        ORDER BY posicao_fila ASC, id ASC
    ");
    $candidatos = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (!$disponivelOnly || $apenasPlantao) {
        return $candidatos ? (int)$candidatos[0] : null;
    }
    foreach ($candidatos as $id) {
        if (contarVendasAtivas((int)$id) < filaVendasMaxAtivas()) {
            return (int)$id;
        }
    }
    return null;
}

/** Lista vendedores com status de disponibilidade/plantão, pro admin (mesmo padrão de listarFilaConsultores()). */
function listarFilaVendedores(): array {
    $db = getDB();
    return $db->query("
        SELECT id, nome, perfil, disponivel, plantao_fim_expediente, ultimo_lead_recebido_em
        FROM usuarios
        WHERE perfil = 'vendedor' AND bloqueado = 0
        ORDER BY nome
    ")->fetchAll();
}
