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
 *
 * Mesmo BEGIN IMMEDIATE de atribuirResponsavelAutomatico() (23/09/2026,
 * achado real do lado de compra — "leads caindo do Anderson pra Dayane",
 * ver comentário completo lá): escolha (proximoDaFilaVendas()) e reserva
 * precisam ser atômicas, senão uma rajada de leads de venda quase
 * simultâneos pode escolher o mesmo vendedor 2x antes de qualquer um
 * reservar a vez, quebrando a alternância — aplicado aqui por consistência/
 * defesa em profundidade, mesmo risco de concorrência, mesma classe de bug.
 */
function atribuirVendedorAutomatico(): ?int {
    $db = getDB();
    $db->exec('BEGIN IMMEDIATE');
    try {
        $usuarioId = proximoDaFilaVendas(disponivelOnly: true);
        if ($usuarioId === null) {
            $usuarioId = proximoDaFilaVendas(disponivelOnly: false, apenasPlantao: true);
        }
        if ($usuarioId === null) {
            $db->exec('COMMIT');
            return null;
        }

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
        $db->exec('COMMIT');
    } catch (Throwable $e) {
        $db->exec('ROLLBACK');
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
        SELECT id, nome, perfil, disponivel, plantao_fim_expediente, ultimo_lead_recebido_em, faltou_em
        FROM usuarios
        WHERE perfil = 'vendedor' AND bloqueado = 0
        ORDER BY nome
    ")->fetchAll();
}

/**
 * Marca o vendedor como ausente HOJE e redistribui na hora as negociações
 * de venda ainda não tocadas dele (ETAPAS_VENDA_LEAD_IA — antes de
 * 'negociacao', onde já haveria contato humano de verdade) pros outros
 * vendedores disponíveis — sempre pro que está com menos carga no
 * momento, um por um. 29/09/2026, "permita super admin deixar offline
 * usuario que faltar e pegar os lead que chegar" — espelha
 * marcarConsultorFaltou() (includes/fila_leads.php, 22/09/2026), arquivo
 * próprio de propósito (mesma disciplina de sempre deste módulo: nunca
 * parametrizar o de compra, já bem testado em produção, pra acomodar um
 * 2º perfil/tabela). Força `disponivel=0` na hora (nunca espera fechamento
 * de expediente) — quem faltou não deve continuar candidato a receber
 * lead novo enquanto o dia corre; como `proximoDaFilaVendas()` só escolhe
 * `disponivel=1`, os leads que CHEGAREM depois já caem sozinhos pros
 * outros, sem precisar de nenhum código extra além desse UPDATE. Nunca
 * mexe em negociação já em 'negociacao'/'contrato_enviado'/'vendido'/
 * 'cancelada' — contato humano de verdade já rolou ali.
 */
function marcarVendedorFaltou(int $usuarioId, int $executadoPor): array {
    $db = getDB();
    $hoje = date('Y-m-d');

    $stmt = $db->prepare("SELECT nome FROM usuarios WHERE id = ? AND perfil = 'vendedor'");
    $stmt->execute([$usuarioId]);
    $ausente = $stmt->fetch();
    if (!$ausente) {
        return ['ok' => false, 'motivo' => 'Usuário não encontrado ou não é vendedor.', 'movidas' => []];
    }

    $db->prepare("UPDATE usuarios SET faltou_em = ?, disponivel = 0 WHERE id = ?")
       ->execute([$hoje, $usuarioId]);

    $receptores = $db->query("
        SELECT id, nome FROM usuarios
        WHERE perfil = 'vendedor' AND bloqueado = 0 AND disponivel = 1 AND id != {$usuarioId}
        ORDER BY posicao_fila ASC, id ASC
    ")->fetchAll();

    $etapasPh = implode(',', array_fill(0, count(ETAPAS_VENDA_LEAD_IA), '?'));
    $movidas = [];

    if ($receptores) {
        $cargas = [];
        $stmtCarga = $db->prepare("SELECT COUNT(*) FROM vendas WHERE responsavel_id = ? AND etapa IN ({$etapasPh})");
        foreach ($receptores as $r) {
            $stmtCarga->execute(array_merge([(int)$r['id']], ETAPAS_VENDA_LEAD_IA));
            $cargas[(int)$r['id']] = (int)$stmtCarga->fetchColumn();
        }

        $stmt = $db->prepare("
            SELECT id, etapa, comprador_nome
            FROM vendas
            WHERE responsavel_id = ? AND etapa IN ({$etapasPh})
            ORDER BY created_at DESC
        ");
        $stmt->execute(array_merge([$usuarioId], ETAPAS_VENDA_LEAD_IA));
        $candidatas = $stmt->fetchAll();

        foreach ($candidatas as $venda) {
            usort($receptores, fn($a, $b) => $cargas[(int)$a['id']] <=> $cargas[(int)$b['id']]);
            $receptor = $receptores[0];
            $receptorId = (int)$receptor['id'];

            $db->beginTransaction();
            try {
                $db->prepare("UPDATE vendas SET responsavel_id = ? WHERE id = ?")
                   ->execute([$receptorId, $venda['id']]);
                $db->prepare("
                    INSERT INTO venda_historico (venda_id, etapa_anterior, etapa_nova, observacao, responsavel_id)
                    VALUES (?, ?, ?, ?, ?)
                ")->execute([
                    $venda['id'],
                    $venda['etapa'],
                    $venda['etapa'],
                    "Redistribuído automaticamente: {$ausente['nome']} marcado como ausente hoje, lead passou pra {$receptor['nome']}",
                    $executadoPor,
                ]);
                $db->commit();
            } catch (Throwable $e) {
                $db->rollBack();
                throw $e;
            }

            $cargas[$receptorId]++;
            $movidas[] = [
                'venda_id' => $venda['id'],
                'comprador_nome' => $venda['comprador_nome'],
                'para' => $receptor['nome'],
            ];
        }
    }

    return ['ok' => true, 'motivo' => '', 'ausente_nome' => $ausente['nome'], 'movidas' => $movidas];
}

/** Desfaz a marcação de falta do vendedor (engano, ou voltou no mesmo dia) — nunca desfaz a redistribuição já feita. */
function desmarcarVendedorFaltou(int $usuarioId): void {
    $db = getDB();
    $db->prepare("UPDATE usuarios SET faltou_em = NULL WHERE id = ?")->execute([$usuarioId]);
}
