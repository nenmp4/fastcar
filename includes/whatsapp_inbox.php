<?php
/**
 * WhatsApp Box (admin/whatsapp_inbox.php) — caixa de entrada única, dentro
 * do CRM, pra toda a equipe conversar com o cliente pela MESMA instância
 * Z-API principal. Decisão de 15/09/2026 (José/Jean): "decidimos manter só
 * uma instância — e os números dos usuários somente para notificação de
 * novo lead — adicionar um whatsapp box igual do juridicoSaas".
 *
 * Isso substitui a arquitetura antiga de 1 instância Z-API por consultor
 * (zapi_instancias_consultores, includes/zapi_instancias.php — ver
 * admin/usuarios.php e CLAUDE.md): a partir de agora `usuarios.whatsapp`
 * serve só pro número PESSOAL do consultor receber notificação de lead
 * novo (notificarConsultorLeadQualificado(), includes/oportunidades.php —
 * isso não mudou), nunca mais como canal de atendimento próprio.
 *
 * Inspirado no admin/whatsapp-inbox.php do JurídicoSaaS (repo irmão), mas
 * adaptado/enxuto pro modelo de dados do Fastcar — sem os recursos
 * específicos de escritório de advocacia (templates jurídicos, stickers,
 * encaminhar conversa, spin_score, cache de foto de perfil): esses ficam
 * como possível próxima iteração se a equipe sentir falta, não implementados
 * agora de propósito (evitar over-engineering sem pedido real).
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';
require_once dirname(__DIR__) . '/chatbot-whatsapp/includes/mensagens.php'; // registrarMensagem(), iaPausada(), pausarIA(), retomarIA()

/**
 * Lista as conversas (1 linha por telefone que já trocou pelo menos 1
 * mensagem), com a última mensagem, contagem de não lidas e status da IA
 * — ordenado pela mais recente primeiro. $busca filtra por nome ou telefone.
 *
 * $responsavelFiltro (15/09/2026, pergunta direta do José/Jean — "inbox vai
 * mostrar todos ou leads do usuário que iniciou atendimento?"): super_admin
 * vê a caixa inteira (chama sem esse parâmetro); consultor vê só as
 * conversas de cliente onde ELE é responsavel_id em pelo menos 1
 * oportunidade (qualquer etapa — inclusive já fechada/perdida, pra não
 * sumir o histórico de quem já atendeu) — mesmo padrão "Minhas/Todas" já
 * usado em admin/index.php pro funil de compra, aplicado aqui também.
 * Conversa sem NENHUMA oportunidade vinculada ainda (não deveria acontecer
 * na prática — regra #2 cria a oportunidade já no 1º contato — mas por
 * segurança) só aparece pro super_admin, nunca pra um consultor aleatório.
 */
function listarConversasWhatsapp(string $busca = '', ?int $responsavelFiltro = null, int $limite = 100): array {
    $db = getDB();
    $whereBusca = '';
    $params = [];
    if ($busca !== '') {
        $whereBusca = ' AND (c.nome LIKE ? OR m.telefone LIKE ?)';
        $like = '%' . $busca . '%';
        $params = [$like, $like];
    }
    $whereResponsavel = '';
    if ($responsavelFiltro !== null) {
        $whereResponsavel = ' AND EXISTS (SELECT 1 FROM oportunidades o WHERE o.cliente_id = m.cliente_id AND o.responsavel_id = ?)';
        $params[] = $responsavelFiltro;
    }

    $sql = "
        SELECT m.telefone, m.mensagem AS ultima_mensagem, m.direcao AS ultima_direcao,
               m.tipo AS ultima_tipo, m.created_at AS ultima_em, m.cliente_id, c.nome AS cliente_nome,
               (SELECT COUNT(*) FROM whatsapp_mensagens m2 WHERE m2.telefone = m.telefone AND m2.direcao = 'in' AND m2.lida = 0) AS nao_lidas,
               COALESCE(ws.ia_pausada, 0) AS ia_pausada
        FROM whatsapp_mensagens m
        LEFT JOIN clientes c ON c.id = m.cliente_id
        LEFT JOIN whatsapp_sessoes ws ON ws.telefone = m.telefone
        WHERE m.id = (SELECT MAX(id) FROM whatsapp_mensagens m3 WHERE m3.telefone = m.telefone)
        {$whereBusca}{$whereResponsavel}
        ORDER BY m.created_at DESC
        LIMIT ?
    ";
    $params[] = $limite;
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/** Últimas $limite mensagens de uma conversa, em ordem cronológica (mais antiga primeiro). */
function buscarMensagensConversa(string $telefone, int $limite = 50): array {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT m.*, u.nome AS usuario_nome
        FROM whatsapp_mensagens m
        LEFT JOIN usuarios u ON u.id = m.usuario_id
        WHERE m.telefone = ?
        ORDER BY m.id DESC LIMIT ?
    ");
    $stmt->execute([normalizarTelefone($telefone), $limite]);
    return array_reverse($stmt->fetchAll());
}

/** Mensagens novas desde $depoisDeId — usado pelo polling do front-end. */
function buscarMensagensNovasConversa(string $telefone, int $depoisDeId): array {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT m.*, u.nome AS usuario_nome
        FROM whatsapp_mensagens m
        LEFT JOIN usuarios u ON u.id = m.usuario_id
        WHERE m.telefone = ? AND m.id > ?
        ORDER BY m.id ASC
    ");
    $stmt->execute([normalizarTelefone($telefone), $depoisDeId]);
    return $stmt->fetchAll();
}

/**
 * Consultor pode ver/responder essa conversa? null = super_admin, sem
 * restrição. Usado tanto pra decidir o que abrir na tela quanto pra travar
 * envio/pausa de IA por POST direto — a listagem filtrada
 * (listarConversasWhatsapp()) sozinha não bastava: um consultor digitando
 * ?telefone=X na URL, ou forjando o POST de enviar/pausar, contornaria o
 * filtro da barra lateral sem essa checagem separada.
 */
function usuarioPodeVerConversaWhatsapp(string $telefone, ?int $responsavelFiltro): bool {
    if ($responsavelFiltro === null) return true;
    $db = getDB();
    $stmt = $db->prepare("
        SELECT EXISTS (
            SELECT 1 FROM oportunidades o
            JOIN clientes c ON c.id = o.cliente_id
            WHERE c.telefone = ? AND o.responsavel_id = ?
        )
    ");
    $stmt->execute([normalizarTelefone($telefone), $responsavelFiltro]);
    return (bool)$stmt->fetchColumn();
}

/** Marca toda mensagem recebida ('in') de um telefone como lida — chamado ao abrir a conversa. */
function marcarConversaLida(string $telefone): void {
    $db = getDB();
    $db->prepare("UPDATE whatsapp_mensagens SET lida = 1 WHERE telefone = ? AND direcao = 'in' AND lida = 0")
        ->execute([normalizarTelefone($telefone)]);
}

/**
 * Envia uma mensagem de texto manual pelo WhatsApp Box — grava no histórico
 * e PAUSA a IA (regra #4 do CLAUDE.md: "passagem pro consultor pausa a
 * IA"). Diferente do fromMe detectado no webhook (que só registra, sem
 * pausar — pode ser o próprio bot ecoando), aqui a ação É de um humano
 * mandando pela caixa, então pausar é sempre certo.
 */
function enviarMensagemManualWhatsapp(string $telefone, string $texto, int $usuarioId): array {
    $telNorm = normalizarTelefone($telefone);
    $texto = trim($texto);
    if ($texto === '') {
        return ['ok' => false, 'erro' => 'Mensagem vazia.'];
    }
    if (!zapiEnviarTexto($telNorm, $texto)) {
        return ['ok' => false, 'erro' => 'Falha ao enviar pelo Z-API — confira a instância em Configurações.'];
    }
    $id = registrarMensagem($telNorm, 'out', $texto, null, false, 'text', $usuarioId);
    pausarIA($telNorm);
    return ['ok' => true, 'id' => $id];
}

/**
 * Apaga o histórico de uma conversa (whatsapp_mensagens + estado da IA em
 * whatsapp_sessoes) — 15/09/2026, pedido direto pra limpar as conversas de
 * lixo criadas no incidente do mesmo dia (eventos de presença/status da
 * Z-API caindo no webhook errado, ver includes/../chatbot-whatsapp/includes/mensagens.php).
 * Restrito ao super_admin (admin/whatsapp_inbox.php) — ação destrutiva,
 * sem confirmação em duas etapas não teria volta. Nunca mexe em
 * `clientes`/`oportunidades`: apagar a CONVERSA não é o mesmo que apagar o
 * lead/negócio — se a conversa era de um cliente real, o cadastro e o
 * histórico do funil continuam intactos, só a thread de mensagens some.
 */
function excluirConversaWhatsapp(string $telefone): void {
    $telNorm = normalizarTelefone($telefone);
    $db = getDB();
    $db->prepare("DELETE FROM whatsapp_mensagens WHERE telefone = ?")->execute([$telNorm]);
    $db->prepare("DELETE FROM whatsapp_sessoes WHERE telefone = ?")->execute([$telNorm]);
}
