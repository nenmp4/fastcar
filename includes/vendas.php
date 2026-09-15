<?php
/**
 * Camada central do módulo de VENDAS (Fastcar revende um veículo já
 * comprado — frota = oportunidades com etapa='fechado'). Mesma disciplina
 * do funil de compra (includes/oportunidades.php, regra #6 do CLAUDE.md):
 * NUNCA fazer UPDATE direto em vendas.etapa — toda mudança passa por
 * mudarEtapaVenda(), que grava o histórico (venda_historico) junto.
 *
 * 15/09/2026 — pedido direto do José/Jean ("você colocar galera para fazer
 * o fluxo e fechar pontas soltas"), seguindo o mesmo padrão já combinado
 * antes ("modulos de vendas seguir mesmo padrão de compra ter a telas de
 * negociação"). Escopo desta 1ª versão (documentado em CLAUDE.md como
 * decisão assumida, não confirmada com o Jean palavra por palavra — sinalizar
 * se ele quiser mudar): sem funil de entrada via WhatsApp/IA pro lado do
 * COMPRADOR (ao contrário do funil de compra, que nasce de um lead
 * qualificado pela IA) — o comprador de um veículo revendido normalmente
 * aparece por outro canal (indicação, anúncio de venda, presencial), então
 * a negociação de venda é sempre CRIADA MANUALMENTE pelo consultor/admin a
 * partir de um veículo já na frota, não por um bot.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';

const ETAPAS_VENDA_VALIDAS = ['negociacao', 'contrato_enviado', 'vendido', 'cancelada'];
const ETAPAS_VENDA_ATIVAS  = ['negociacao', 'contrato_enviado'];

function etapaVendaLabel(string $etapa): string {
    $labels = [
        'negociacao'       => '🤝 Negociação',
        'contrato_enviado' => '📤 Contrato enviado',
        'vendido'          => '✅ Vendido',
        'cancelada'        => '❌ Cancelada',
    ];
    return $labels[$etapa] ?? ucfirst($etapa);
}

/**
 * Um veículo (oportunidade com etapa='fechado') só pode ter 1 negociação
 * de venda ATIVA por vez (negociacao/contrato_enviado) — mesma regra do
 * índice único parcial idx_vendas_ativa_por_veiculo no schema (defesa em
 * dupla camada: aplicação + banco). "Disponível pra vender" = está na
 * frota E não tem negociação ativa nem já vendido.
 */
function veiculoDisponivelParaVenda(int $oportunidadeId): bool {
    $db = getDB();
    $stmtOp = $db->prepare("SELECT etapa FROM oportunidades WHERE id = ?");
    $stmtOp->execute([$oportunidadeId]);
    if ($stmtOp->fetchColumn() !== 'fechado') return false;

    $stmt = $db->prepare("
        SELECT COUNT(*) FROM vendas WHERE oportunidade_id = ? AND etapa IN ('negociacao', 'contrato_enviado', 'vendido')
    ");
    $stmt->execute([$oportunidadeId]);
    return (int)$stmt->fetchColumn() === 0;
}

/**
 * Abre uma nova negociação de venda pra um veículo da frota. Lança se o
 * veículo não existir/não estiver na frota, ou já tiver negociação ativa/
 * concluída — checagem em dobro com o índice único parcial do schema (essa
 * aqui dá mensagem de erro legível pro admin; o índice é a rede de
 * segurança final contra corrida de 2 requests simultâneas).
 */
function criarVenda(int $oportunidadeId, ?int $responsavelId = null): int {
    if (!veiculoDisponivelParaVenda($oportunidadeId)) {
        throw new RuntimeException("Veículo #{$oportunidadeId} não está disponível pra venda (não é da frota, ou já tem negociação ativa/concluída).");
    }

    $db = getDB();
    $db->prepare("
        INSERT INTO vendas (oportunidade_id, responsavel_id, etapa)
        VALUES (?, ?, 'negociacao')
    ")->execute([$oportunidadeId, $responsavelId]);
    $vendaId = (int)$db->lastInsertId();

    $db->prepare("
        INSERT INTO venda_historico (venda_id, etapa_anterior, etapa_nova, responsavel_id, observacao)
        VALUES (?, '', 'negociacao', ?, 'Negociação aberta')
    ")->execute([$vendaId, $responsavelId]);

    return $vendaId;
}

/**
 * Muda a etapa de uma negociação de venda, gravando o histórico junto —
 * nunca UPDATE direto (mesma regra #6 do funil de compra). Etapa
 * 'cancelada' exige $observacao (motivo) — mesmo espírito de
 * marcarPerdida() no funil de compra, nunca encerra sem dizer por quê.
 */
function mudarEtapaVenda(int $vendaId, string $etapaNova, ?int $responsavelId = null, string $observacao = ''): bool {
    if (!in_array($etapaNova, ETAPAS_VENDA_VALIDAS, true)) {
        throw new InvalidArgumentException("Etapa de venda inválida: {$etapaNova}");
    }

    $db = getDB();
    $stmt = $db->prepare("SELECT etapa FROM vendas WHERE id = ?");
    $stmt->execute([$vendaId]);
    $atual = $stmt->fetchColumn();
    if ($atual === false) {
        throw new RuntimeException("Venda #{$vendaId} não existe.");
    }

    if ($etapaNova === 'cancelada' && trim($observacao) === '') {
        throw new RuntimeException('Motivo do cancelamento é obrigatório.');
    }

    $db->beginTransaction();
    try {
        $camposExtra = '';
        $valoresExtra = [];
        if ($etapaNova === 'vendido') {
            $camposExtra = ", data_venda = date('now','localtime')";
        } elseif ($etapaNova === 'cancelada') {
            $camposExtra = ', motivo_cancelamento = ?';
            $valoresExtra[] = clean($observacao);
        }

        $db->prepare("
            UPDATE vendas SET etapa = ?, updated_at = datetime('now','localtime') {$camposExtra} WHERE id = ?
        ")->execute([$etapaNova, ...$valoresExtra, $vendaId]);

        $db->prepare("
            INSERT INTO venda_historico (venda_id, etapa_anterior, etapa_nova, responsavel_id, observacao)
            VALUES (?, ?, ?, ?, ?)
        ")->execute([$vendaId, $atual, $etapaNova, $responsavelId, clean($observacao)]);

        $db->commit();
        return true;
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}
