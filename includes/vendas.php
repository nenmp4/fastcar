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
 * negociação").
 *
 * 17/09/2026 — 2ª etapa do módulo (pedido José/Jean: "perfil vendedor,
 * ibox do vendedor igual compras, dasbord de vendas, vamos usar
 * qualificação do lead para vendas, vamos adcionar instancia só para
 * vendas"): ganhou funil de ENTRADA pelo WhatsApp, espelhando o funil de
 * compra — instância Z-API dedicada (includes/zapi_instancias.php,
 * config.zapi_instancia_vendas_*), qualificação por IA
 * (includes/ia_qualificacao_vendas.php) e perfil `vendedor` próprio
 * (fila/round-robin em includes/fila_vendas.php). A negociação CRIADA
 * MANUALMENTE a partir de um veículo já na frota (admin/veiculos.php,
 * botão "Vender") continua existindo como caminho alternativo — nem todo
 * comprador de revenda vem pelo WhatsApp (indicação, anúncio, presencial
 * continuam válidos); as duas origens (`vendas.origem`) convivem na mesma
 * tabela e no mesmo pipeline (admin/vendas.php).
 *
 * Diferente do funil de compra, o lead de venda pode chegar SEM saber
 * ainda qual veículo específico da frota quer (`oportunidade_id` nullable
 * enquanto `etapa` está em 'whatsapp'/'qualificacao_ia') — a IA capta o
 * que o comprador descreve (`veiculo_interesse_texto`) grounded na frota
 * REAL disponível (nunca inventa um veículo que não existe, regra #3), mas
 * o vínculo final com uma `oportunidade_id` específica é sempre confirmado
 * por um humano (vendedor, vincularVeiculoVenda()) — mesmo espírito de
 * "sistema nunca decide sozinho informação crítica" já usado no widget de
 * FIPE por placa.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/fila_vendas.php';
require_once __DIR__ . '/whatsapp_config.php';

// 'whatsapp'/'qualificacao_ia'/'sem_perfil' são exclusivas de leads que
// entraram por origem='whatsapp' — negociação manual nunca passa por elas.
const ETAPAS_VENDA_VALIDAS = ['whatsapp', 'qualificacao_ia', 'negociacao', 'contrato_enviado', 'vendido', 'cancelada', 'sem_perfil'];
const ETAPAS_VENDA_ATIVAS  = ['whatsapp', 'qualificacao_ia', 'negociacao', 'contrato_enviado'];
// Etapas do funil de PIPELINE (o que admin/vendas.php mostra na nav por
// padrão) — deixa 'whatsapp'/'qualificacao_ia'/'sem_perfil' fora da lista
// principal (aparecem juntas em "Leads (IA)"), igual o pipeline de compra
// separa etapa de entrada/qualificação do funil de negociação em si.
const ETAPAS_VENDA_PIPELINE = ['negociacao', 'contrato_enviado', 'vendido', 'cancelada'];
const ETAPAS_VENDA_LEAD_IA  = ['whatsapp', 'qualificacao_ia'];

function etapaVendaLabel(string $etapa): string {
    $labels = [
        'whatsapp'         => '💬 WhatsApp',
        'qualificacao_ia'  => '🤖 Qualificação IA',
        'negociacao'       => '🤝 Negociação',
        'contrato_enviado' => '📤 Contrato enviado',
        'vendido'          => '✅ Vendido',
        'cancelada'        => '❌ Cancelada',
        'sem_perfil'       => '⚪ Sem perfil de compra',
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
    if ($etapaNova === 'sem_perfil' && trim($observacao) === '') {
        throw new RuntimeException('Motivo é obrigatório.');
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
        } elseif ($etapaNova === 'sem_perfil') {
            $camposExtra = ', motivo_perda = ?';
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

/**
 * Cria (ou reaproveita) o lead de VENDA por telefone e já abre a
 * negociação na etapa 'whatsapp' — espelha
 * includes/oportunidades.php::criarOuAbrirOportunidade() (regra #2:
 * "salvar desde o primeiro contato"), mas sem tabela `clientes`: o
 * comprador de revenda é identificado direto pelas colunas
 * `vendas.comprador_*` (mesma decisão de design já tomada na 1ª versão do
 * módulo — ver comentário no topo do arquivo/schema.sql).
 * `oportunidade_id` fica NULL até o vendedor confirmar o match com um
 * veículo real da frota (vincularVeiculoVenda()).
 */
function criarOuAbrirVendaLead(string $telefone, string $nome = ''): array {
    $db = getDB();
    $telNorm = normalizarTelefone($telefone);
    if (!$telNorm || strlen($telNorm) < 12) {
        throw new InvalidArgumentException("Telefone inválido: {$telefone}");
    }

    // Já existe negociação ativa (ainda não tocada pelo vendedor — mesma
    // ideia de "não duplicar por causa da mesma mensagem de entrada" do
    // funil de compra) pra esse telefone? Reaproveita.
    $etapasAtivasPlaceholder = implode(',', array_fill(0, count(ETAPAS_VENDA_ATIVAS), '?'));
    $stmt = $db->prepare("
        SELECT id, responsavel_id FROM vendas
        WHERE comprador_telefone = ? AND etapa IN ({$etapasAtivasPlaceholder})
        ORDER BY id DESC LIMIT 1
    ");
    $stmt->execute([$telNorm, ...ETAPAS_VENDA_ATIVAS]);
    $existente = $stmt->fetch();

    if ($existente) {
        // fill-if-empty pro nome — mesma regra de sempre.
        if ($nome) {
            $db->prepare("UPDATE vendas SET comprador_nome = CASE WHEN comprador_nome = '' THEN ? ELSE comprador_nome END WHERE id = ?")
               ->execute([clean($nome), $existente['id']]);
        }
        return ['venda_id' => (int)$existente['id'], 'nova' => false, 'responsavel_id' => $existente['responsavel_id'] !== null ? (int)$existente['responsavel_id'] : null];
    }

    $db->prepare("
        INSERT INTO vendas (etapa, origem, comprador_nome, comprador_telefone)
        VALUES ('whatsapp', 'whatsapp', ?, ?)
    ")->execute([clean($nome), $telNorm]);
    $vendaId = (int)$db->lastInsertId();

    // Distribuição automática (mesmo padrão do rodízio de compra) já na
    // entrada, não só quando a IA termina de qualificar.
    $responsavelId = atribuirVendedorAutomatico();
    if ($responsavelId !== null) {
        $db->prepare("UPDATE vendas SET responsavel_id = ? WHERE id = ?")->execute([$responsavelId, $vendaId]);
    }

    $db->prepare("
        INSERT INTO venda_historico (venda_id, etapa_anterior, etapa_nova, responsavel_id, observacao)
        VALUES (?, '', 'whatsapp', ?, ?)
    ")->execute([$vendaId, $responsavelId, 'Lead de venda criado — entrada pelo WhatsApp' . ($responsavelId ? ' (atribuído automaticamente)' : '')]);

    notificarNovoLeadVendas($vendaId, $nome ?: '(sem nome)', $telNorm);

    return ['venda_id' => $vendaId, 'nova' => true, 'responsavel_id' => $responsavelId];
}

/**
 * Frota disponível pra vender AGORA (mesmo critério de
 * veiculoDisponivelParaVenda(), em lote) — usada pela qualificação por IA
 * (includes/ia_qualificacao_vendas.php) pra sempre responder o comprador
 * com base no que EXISTE de verdade, nunca inventar um veículo (regra #3
 * do CLAUDE.md). Resumo enxuto (id, marca/modelo/ano, valor de referência)
 * — o suficiente pra IA conversar sobre o que tem disponível sem expor
 * dado sensível (placa/chassi/financiamento) pro comprador.
 */
function listarFrotaDisponivelParaVenda(int $limite = 30): array {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT o.id AS oportunidade_id, o.veiculo_marca, o.veiculo_modelo, o.veiculo_ano,
               COALESCE(o.valor_fipe_referencia, o.valor_final) AS valor_referencia
        FROM oportunidades o
        WHERE o.etapa = 'fechado'
          AND NOT EXISTS (
              SELECT 1 FROM vendas v
              WHERE v.oportunidade_id = o.id AND v.etapa IN ('negociacao', 'contrato_enviado', 'vendido')
          )
        ORDER BY o.data_compra DESC
        LIMIT ?
    ");
    $stmt->bindValue(1, $limite, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

/**
 * Confirma o vínculo entre uma negociação de venda (lead qualificado pela
 * IA, `oportunidade_id` ainda NULL) e um veículo real da frota — SEMPRE
 * ação humana (vendedor escolhendo na tela, admin/venda.php), nunca a IA
 * decide sozinha: mesmo espírito do widget de FIPE por placa (o sistema
 * nunca aplica o candidato "óbvio" sozinho). Lança se o veículo não
 * estiver disponível (mesma checagem de criarVenda()).
 */
function vincularVeiculoVenda(int $vendaId, int $oportunidadeId): bool {
    if (!veiculoDisponivelParaVenda($oportunidadeId)) {
        throw new RuntimeException("Veículo #{$oportunidadeId} não está disponível pra venda (não é da frota, ou já tem negociação ativa/concluída).");
    }
    $db = getDB();
    $db->prepare("UPDATE vendas SET oportunidade_id = ?, updated_at = datetime('now','localtime') WHERE id = ?")
       ->execute([$oportunidadeId, $vendaId]);
    return true;
}

/**
 * Avisa por WhatsApp os números cadastrados em Configurações quando um
 * lead de VENDA novo entra — mesmo mecanismo de notificarNovoLeadWhatsapp()
 * (includes/oportunidades.php), reaproveitando a mesma lista de números
 * (notificacao_leads_whatsapp) e a instância padrão de envio: aviso interno
 * pra equipe, não é conversa com o comprador, não precisa da instância
 * dedicada de vendas pra isso. Nunca lança, nunca bloqueia a criação do lead.
 */
function notificarNovoLeadVendas(int $vendaId, string $nomeComprador, string $telefoneComprador): void {
    try {
        $lista = getConfig('notificacao_leads_whatsapp') ?: '';
        $numeros = array_filter(array_map('trim', explode(',', $lista)));
        if (!$numeros) return;

        $baseUrl = getConfig('app_base_url') ?: '';
        $link = $baseUrl ? rtrim($baseUrl, '/') . "/admin/venda.php?id={$vendaId}" : "venda #{$vendaId}";
        $msg = "🛒 Novo lead de VENDA no Fastcar CRM!\nComprador: {$nomeComprador}\nTelefone: {$telefoneComprador}\n{$link}";

        foreach ($numeros as $numero) {
            zapiEnviarTexto($numero, $msg);
        }
    } catch (Throwable $e) {
        // notificação nunca pode derrubar a criação do lead
    }
}

/**
 * Avisa o VENDEDOR RESPONSÁVEL, por WhatsApp, quando a IA termina de
 * qualificar um lead de venda — mesmo padrão de
 * notificarConsultorLeadQualificado() (includes/oportunidades.php). Sem
 * responsável definido ou sem WhatsApp cadastrado, cai no aviso genérico
 * de notificacao_leads_whatsapp como fallback.
 */
function notificarVendedorLeadQualificado(int $vendaId, string $motivo = 'Qualificação concluída'): void {
    try {
        $db = getDB();
        $stmt = $db->prepare("
            SELECT v.resumo_ia, v.responsavel_id, v.comprador_nome, v.comprador_telefone,
                   u.whatsapp AS vendedor_whatsapp
            FROM vendas v
            LEFT JOIN usuarios u ON u.id = v.responsavel_id
            WHERE v.id = ?
        ");
        $stmt->execute([$vendaId]);
        $v = $stmt->fetch();
        if (!$v) return;

        $baseUrl = getConfig('app_base_url') ?: '';
        $link = $baseUrl ? rtrim($baseUrl, '/') . "/admin/venda.php?id={$vendaId}" : "venda #{$vendaId}";
        $msg = "📋 Lead de venda pronto! ({$motivo})\n"
             . "Comprador: {$v['comprador_nome']}\nTelefone: {$v['comprador_telefone']}\n\n"
             . ($v['resumo_ia'] ? "{$v['resumo_ia']}\n\n" : '')
             . $link;

        if (!empty($v['vendedor_whatsapp'])) {
            zapiEnviarTexto($v['vendedor_whatsapp'], $msg);
            return;
        }

        $lista = getConfig('notificacao_leads_whatsapp') ?: '';
        foreach (array_filter(array_map('trim', explode(',', $lista))) as $numero) {
            zapiEnviarTexto($numero, $msg);
        }
    } catch (Throwable $e) {
        // notificação nunca pode travar o fluxo da qualificação
    }
}
