<?php
/**
 * Camada central de manipulação de oportunidades — funil de 8 blocos.
 * Regra #6 do CLAUDE.md: NUNCA fazer UPDATE direto em oportunidades.etapa.
 * Toda mudança de etapa passa por mudarEtapa(), que grava o histórico junto.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';

const ETAPAS_VALIDAS = [
    'whatsapp', 'qualificacao_ia', 'crm_preenchido', 'atendimento',
    'negociacao', 'presencial', 'fechado', 'sem_perfil', 'perdido',
];

const ETAPAS_ATIVAS = [
    'whatsapp', 'qualificacao_ia', 'crm_preenchido', 'atendimento',
    'negociacao', 'presencial',
];

function etapaLabel(string $etapa): string {
    $labels = [
        'whatsapp'        => '💬 WhatsApp',
        'qualificacao_ia' => '🤖 Qualificação IA',
        'crm_preenchido'  => '📋 CRM preenchido',
        'atendimento'     => '📞 Atendimento',
        'negociacao'      => '🤝 Negociação',
        'presencial'      => '🚗 Presencial',
        'fechado'         => '✅ Pasta fechada',
        'sem_perfil'      => '⚪ Sem perfil de compra',
        'perdido'         => '❌ Perdido',
    ];
    return $labels[$etapa] ?? ucfirst($etapa);
}

/**
 * Cria (ou reaproveita) o cliente por telefone e já abre a oportunidade na
 * etapa 'whatsapp' — regra #2: "salvar desde o primeiro contato", mesmo
 * antes de qualquer qualificação.
 */
function criarOuAbrirOportunidade(string $telefone, string $nome = ''): array {
    $db = getDB();
    $telNorm = normalizarTelefone($telefone);
    if (!$telNorm || strlen($telNorm) < 12) {
        throw new InvalidArgumentException("Telefone inválido: {$telefone}");
    }

    $stmt = $db->prepare("SELECT id, nome FROM clientes WHERE telefone = ?");
    $stmt->execute([$telNorm]);
    $cliente = $stmt->fetch();

    if (!$cliente) {
        $db->prepare("INSERT INTO clientes (nome, telefone) VALUES (?, ?)")
           ->execute([clean($nome), $telNorm]);
        $clienteId = (int)$db->lastInsertId();
    } else {
        $clienteId = (int)$cliente['id'];
        // Preenche nome se ainda estava vazio (ex: cliente criado só com
        // telefone via webhook, nome veio depois via qualificação)
        if (empty($cliente['nome']) && $nome) {
            $db->prepare("UPDATE clientes SET nome = ? WHERE id = ?")->execute([clean($nome), $clienteId]);
        }
    }

    // Já existe oportunidade ativa aberta pra esse cliente? Reaproveita —
    // não cria uma segunda enquanto a primeira ainda está em andamento.
    // (Regra do Jean permite MAIS de um veículo por cliente, mas isso é
    // decisão explícita — ex: 2º carro depois do 1º já fechado/perdido —
    // não abertura automática de duplicata pela mesma mensagem de entrada.)
    $etapasAtivasPlaceholder = implode(',', array_fill(0, count(ETAPAS_ATIVAS), '?'));
    $stmtOp = $db->prepare("
        SELECT id FROM oportunidades
        WHERE cliente_id = ? AND etapa IN ({$etapasAtivasPlaceholder})
        ORDER BY id DESC LIMIT 1
    ");
    $stmtOp->execute([$clienteId, ...ETAPAS_ATIVAS]);
    $existente = $stmtOp->fetch();

    if ($existente) {
        return ['cliente_id' => $clienteId, 'oportunidade_id' => (int)$existente['id'], 'nova' => false];
    }

    $db->prepare("INSERT INTO oportunidades (cliente_id, etapa) VALUES (?, 'whatsapp')")
       ->execute([$clienteId]);
    $opId = (int)$db->lastInsertId();

    mudarEtapa($opId, 'whatsapp', null, 'Oportunidade criada — entrada pelo WhatsApp');

    return ['cliente_id' => $clienteId, 'oportunidade_id' => $opId, 'nova' => true];
}

/**
 * ÚNICO ponto do sistema que deve alterar oportunidades.etapa.
 * Grava o histórico (data + responsável) junto, sempre.
 */
function mudarEtapa(int $oportunidadeId, string $etapaNova, ?int $responsavelId = null, string $observacao = ''): bool {
    if (!in_array($etapaNova, ETAPAS_VALIDAS, true)) {
        throw new InvalidArgumentException("Etapa inválida: {$etapaNova}");
    }

    $db = getDB();
    $stmt = $db->prepare("SELECT etapa FROM oportunidades WHERE id = ?");
    $stmt->execute([$oportunidadeId]);
    $atual = $stmt->fetchColumn();
    if ($atual === false) {
        throw new RuntimeException("Oportunidade #{$oportunidadeId} não existe.");
    }

    // "Compra concluída exige checklist" (regra #7) — trava aqui, não só
    // na tela, pra nenhuma rota conseguir pular o checklist.
    if ($etapaNova === 'fechado' && !checklistFechamentoCompleto($oportunidadeId)) {
        throw new RuntimeException(
            "Oportunidade #{$oportunidadeId} não pode ser fechada: documentos obrigatórios pendentes."
        );
    }

    $db->beginTransaction();
    try {
        $db->prepare("UPDATE oportunidades SET etapa = ?, updated_at = datetime('now','localtime') WHERE id = ?")
           ->execute([$etapaNova, $oportunidadeId]);

        $db->prepare("
            INSERT INTO oportunidade_historico (oportunidade_id, etapa_anterior, etapa_nova, responsavel_id, observacao)
            VALUES (?, ?, ?, ?, ?)
        ")->execute([$oportunidadeId, $atual, $etapaNova, $responsavelId, clean($observacao)]);

        $db->commit();
        return true;
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

/**
 * Checklist do bloco 8 — regra #7: só libera 'fechado' se todo documento
 * marcado como obrigatório pra essa oportunidade estiver presente.
 */
function checklistFechamentoCompleto(int $oportunidadeId): bool {
    $db = getDB();
    // ⚠️ Bug real já pego em teste: contar só "pendentes" (obrigatorio=1
    // com arquivo vazio) dá 0 tanto faz se está tudo preenchido quanto se
    // NENHUM documento foi cadastrado ainda — falso-positivo de COUNT em
    // query vazia. Por isso exige explicitamente total>0: sem nenhum
    // documento obrigatório cadastrado, o checklist NUNCA está completo.
    $stmt = $db->prepare("
        SELECT
            COUNT(*) as total,
            SUM(CASE WHEN arquivo_url IS NULL OR arquivo_url = '' THEN 1 ELSE 0 END) as pendentes
        FROM oportunidade_documentos
        WHERE oportunidade_id = ? AND obrigatorio = 1
    ");
    $stmt->execute([$oportunidadeId]);
    $r = $stmt->fetch();
    return (int)$r['total'] > 0 && (int)$r['pendentes'] === 0;
}

/**
 * Marca perda em qualquer etapa — sempre exige motivo (regra do Jean:
 * "Sem perfil de compra" → registra motivo e encerra; "Perdido" também).
 */
function marcarPerdida(int $oportunidadeId, string $motivo, ?int $responsavelId = null, bool $semPerfil = false): bool {
    if (!$motivo) {
        throw new InvalidArgumentException('Motivo de perda é obrigatório.');
    }
    $db = getDB();
    $db->prepare("UPDATE oportunidades SET motivo_perda = ? WHERE id = ?")->execute([clean($motivo), $oportunidadeId]);
    return mudarEtapa($oportunidadeId, $semPerfil ? 'sem_perfil' : 'perdido', $responsavelId, $motivo);
}
