<?php
/**
 * Pendências pós-venda (regra #8 do CLAUDE.md: "'Compra concluída' ≠ fim
 * de tudo — encerra o funil comercial, mas pendência futura (ex: quitação
 * de financiamento junto ao banco) continua vinculada à mesma pasta via
 * oportunidade_pendencias_pos_venda, num controle operacional separado do
 * funil de vendas"). Tabela existia no schema desde o início mas nunca
 * teve tela/fluxo — achado real, 16/09/2026: cliente (Bárbara) reclamando
 * que o financiamento de um carro JÁ vendido pra Fastcar não foi quitado
 * nem transferido, recebendo notificação extrajudicial em nome dela — o
 * sistema tratava isso como lead novo (oportunidade #63 rodando
 * qualificação por IA do zero) em vez de vincular à pasta ORIGINAL já
 * fechada, que é onde esse tipo de acompanhamento deveria morar.
 *
 * Só faz sentido criar pendência numa oportunidade que já fechou
 * (etapa='fechado') — antes disso a negociação inteira ainda está em
 * aberto no funil normal, "pendência pós-venda" não existe ainda.
 */

require_once __DIR__ . '/db.php';

function criarPendenciaPosVenda(int $oportunidadeId, string $descricao, ?string $prazoEstimado, ?int $responsavelId): int {
    $db = getDB();
    $stmt = $db->prepare("
        INSERT INTO oportunidade_pendencias_pos_venda (oportunidade_id, descricao, prazo_estimado, responsavel_id)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([$oportunidadeId, $descricao, $prazoEstimado ?: null, $responsavelId]);
    return (int)$db->lastInsertId();
}

function concluirPendenciaPosVenda(int $pendenciaId): void {
    $db = getDB();
    $db->prepare("
        UPDATE oportunidade_pendencias_pos_venda
        SET status = 'concluido', concluido_em = datetime('now','localtime')
        WHERE id = ? AND status = 'pendente'
    ")->execute([$pendenciaId]);
}

/** Reabre por engano marcada como concluída — sem soft-delete, só volta o status. */
function reabrirPendenciaPosVenda(int $pendenciaId): void {
    $db = getDB();
    $db->prepare("
        UPDATE oportunidade_pendencias_pos_venda SET status = 'pendente', concluido_em = NULL WHERE id = ?
    ")->execute([$pendenciaId]);
}

/** Todas as pendências de uma oportunidade específica, mais recente primeiro. */
function listarPendenciasDaOportunidade(int $oportunidadeId): array {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT p.*, u.nome AS responsavel_nome
        FROM oportunidade_pendencias_pos_venda p
        LEFT JOIN usuarios u ON u.id = p.responsavel_id
        WHERE p.oportunidade_id = ?
        ORDER BY p.status ASC, p.prazo_estimado IS NULL, p.prazo_estimado ASC, p.id DESC
    ");
    $stmt->execute([$oportunidadeId]);
    return $stmt->fetchAll();
}

/**
 * Painel geral (admin/pendencias_pos_venda.php) — todas as pendências
 * ainda em aberto entre todos os clientes/pastas fechadas, atrasadas
 * primeiro. $responsavelFiltro null = vê tudo (super_admin/supervisor);
 * um id = só as próprias (consultor).
 */
function listarPendenciasPosVendaAbertas(?int $responsavelFiltro = null): array {
    $db = getDB();
    $where = "p.status = 'pendente'";
    $params = [];
    if ($responsavelFiltro !== null) {
        $where .= " AND p.responsavel_id = ?";
        $params[] = $responsavelFiltro;
    }
    $stmt = $db->prepare("
        SELECT p.*, o.id AS oportunidade_id, o.cliente_id, o.veiculo_marca, o.veiculo_modelo, o.veiculo_placa,
               c.nome AS cliente_nome, c.telefone AS cliente_telefone,
               u.nome AS responsavel_nome,
               (p.prazo_estimado IS NOT NULL AND p.prazo_estimado < date('now','localtime')) AS atrasada
        FROM oportunidade_pendencias_pos_venda p
        JOIN oportunidades o ON o.id = p.oportunidade_id
        JOIN clientes c ON c.id = o.cliente_id
        LEFT JOIN usuarios u ON u.id = p.responsavel_id
        WHERE {$where}
        ORDER BY atrasada DESC, p.prazo_estimado IS NULL, p.prazo_estimado ASC, p.id DESC
    ");
    $stmt->execute($params);
    return $stmt->fetchAll();
}
