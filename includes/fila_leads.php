<?php
/**
 * Distribuição automática de leads — decisão do Jean (12/09/2026):
 *   - Consultor/closer liga/desliga disponibilidade manualmente no admin
 *   - Lead novo (bloco 2, na entrada) vai automaticamente pra quem estiver
 *     disponível, em rodízio (quem está há mais tempo sem receber é o
 *     próximo) — evita sobrecarregar sempre a mesma pessoa
 *   - Se TODO MUNDO estiver offline, cai no plantão de fim de expediente
 *     (usuarios.plantao_fim_expediente=1), que vira responsável normal da
 *     oportunidade — nenhum lead fica largado fora do horário
 *   - Sem ninguém disponível NEM plantão configurado: fica sem responsável
 *     mesmo (comportamento de hoje), alguém pega manualmente depois
 */

require_once __DIR__ . '/db.php';

/**
 * Escolhe o próximo usuário da fila e marca no rodízio (atualiza
 * ultimo_lead_recebido_em). Retorna null se não tiver ninguém disponível
 * nem plantão configurado — quem chama decide o que fazer (deixar sem
 * responsável, igual hoje).
 */
function atribuirResponsavelAutomatico(): ?int {
    $usuarioId = proximoDaFila(disponivelOnly: true);
    if ($usuarioId === null) {
        $usuarioId = proximoDaFila(disponivelOnly: false, apenasPlantao: true);
    }
    if ($usuarioId === null) {
        return null;
    }

    $db = getDB();
    $db->beginTransaction();
    try {
        // Contador monotônico, não timestamp: 2 leads no mesmo segundo não
        // podem empatar e cair sempre na mesma pessoa (bug real pego em
        // teste — SQLite datetime('now') só tem granularidade de segundo).
        $proximaPosicao = 1 + (int)$db->query("
            SELECT COALESCE(MAX(posicao_fila), 0) FROM usuarios WHERE perfil IN ('consultor','closer')
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

function proximoDaFila(bool $disponivelOnly, bool $apenasPlantao = false): ?int {
    $db = getDB();
    $condicoes = ["bloqueado = 0", "perfil IN ('consultor','closer')"];
    if ($apenasPlantao) {
        $condicoes[] = 'plantao_fim_expediente = 1';
    } else {
        $condicoes[] = 'plantao_fim_expediente = 0';
        if ($disponivelOnly) $condicoes[] = 'disponivel = 1';
    }
    $where = implode(' AND ', $condicoes);

    // posicao_fila menor = há mais tempo (ou nunca) sem receber — próximo
    // da fila. id ASC só desempata o caso raro de dois nunca terem
    // recebido nada ainda (posicao_fila=0 nos dois).
    $stmt = $db->query("
        SELECT id FROM usuarios
        WHERE {$where}
        ORDER BY posicao_fila ASC, id ASC
        LIMIT 1
    ");
    $id = $stmt->fetchColumn();
    return $id !== false ? (int)$id : null;
}

/** Toggle de disponibilidade — o próprio usuário liga/desliga no admin. */
function alternarDisponibilidade(int $usuarioId): bool {
    $db = getDB();
    $stmt = $db->prepare("SELECT disponivel FROM usuarios WHERE id = ?");
    $stmt->execute([$usuarioId]);
    $atual = (int)$stmt->fetchColumn();
    $novo = $atual ? 0 : 1;
    $db->prepare("UPDATE usuarios SET disponivel = ? WHERE id = ?")->execute([$novo, $usuarioId]);
    return (bool)$novo;
}

/** Marca/desmarca um usuário como plantão de fim de expediente (super_admin). */
function definirPlantaoFimExpediente(int $usuarioId, bool $ativo): void {
    $db = getDB();
    $db->prepare("UPDATE usuarios SET plantao_fim_expediente = ? WHERE id = ?")
       ->execute([$ativo ? 1 : 0, $usuarioId]);
}

/** Lista consultores/closers com status de disponibilidade/plantão, pro admin. */
function listarFilaConsultores(): array {
    $db = getDB();
    return $db->query("
        SELECT id, nome, perfil, disponivel, plantao_fim_expediente, ultimo_lead_recebido_em
        FROM usuarios
        WHERE perfil IN ('consultor','closer') AND bloqueado = 0
        ORDER BY nome
    ")->fetchAll();
}
