<?php
/**
 * WhatsApp Box do módulo de VENDAS (17/09/2026, pedido José/Jean: "ibox do
 * vendedor igual compras") — espelha includes/whatsapp_inbox.php
 * (compra), mas as conversas vêm de `vendas`/`comprador_telefone`, não de
 * `clientes`/`oportunidades` (comprador de revenda não tem cadastro em
 * `clientes`, ver includes/vendas.php).
 *
 * Escopo intencionalmente mais enxuto que o inbox de compra nesta 1ª
 * versão (evitar over-engineering sem pedido real, mesmo espírito já
 * documentado no CLAUDE.md pro inbox de compra): SEM busca ao vivo de
 * foto de perfil do WhatsApp (zapiBuscarContato() — avatar sempre em
 * iniciais) — fica como possível próxima iteração se a equipe sentir
 * falta, igual outros recursos do inbox de compra que também começaram
 * de fora nesse espírito.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/vendas.php';
require_once __DIR__ . '/usuarios.php';
require_once dirname(__DIR__) . '/chatbot-whatsapp/includes/mensagens.php'; // registrarMensagem(), iaPausada(), pausarIA(), retomarIA()

/**
 * Lista as conversas de vendas (1 linha por telefone de comprador que já
 * trocou pelo menos 1 mensagem), última mensagem, não lidas, status da IA.
 * $responsavelFiltro: null = vê tudo (super_admin/supervisor); id = só
 * conversa de comprador onde ESSE vendedor é responsavel_id em pelo menos
 * 1 negociação — mesmo padrão "Minhas/Todas" do inbox de compra.
 */
function listarConversasVendas(string $busca = '', ?int $responsavelFiltro = null, int $limite = 100): array {
    $db = getDB();
    $whereBusca = '';
    $params = [];
    if ($busca !== '') {
        $whereBusca = ' AND (v.comprador_nome LIKE ? OR ultv.comprador_telefone LIKE ?)';
        $like = '%' . $busca . '%';
        $params = [$like, $like];
    }
    $whereResponsavel = '';
    if ($responsavelFiltro !== null) {
        $whereResponsavel = ' AND EXISTS (SELECT 1 FROM vendas v2 WHERE v2.comprador_telefone = ultv.comprador_telefone AND v2.responsavel_id = ?)';
        $params[] = $responsavelFiltro;
    }
    // 24/10/2026, "tem aparecer inbox da conversa pro vendedor" — a base é a
    // NEGOCIAÇÃO (1 linha por telefone de comprador), não a mensagem: lead
    // atribuído a um vendedor aparece na caixa dele mesmo antes de ter
    // qualquer mensagem trocada (ex: acabou de ser atribuído em massa ou
    // criado manualmente). Nunca mistura com conversa só do funil de compra.
    $sql = "
        SELECT ultv.comprador_telefone AS telefone,
               COALESCE(m.mensagem, '(sem mensagens ainda — clique pra iniciar a conversa)') AS ultima_mensagem,
               COALESCE(m.direcao, '') AS ultima_direcao,
               COALESCE(m.tipo, 'text') AS ultima_tipo,
               COALESCE(m.created_at, v.created_at) AS ultima_em,
               v.comprador_nome,
               (SELECT COUNT(*) FROM whatsapp_mensagens m2 WHERE m2.telefone = ultv.comprador_telefone AND m2.direcao = 'in' AND m2.lida = 0) AS nao_lidas,
               COALESCE(ws.ia_pausada, 0) AS ia_pausada
        FROM (SELECT comprador_telefone, MAX(id) AS max_id FROM vendas
              WHERE comprador_telefone IS NOT NULL AND comprador_telefone != ''
              GROUP BY comprador_telefone) ultv
        JOIN vendas v ON v.id = ultv.max_id
        LEFT JOIN (SELECT telefone, MAX(id) AS max_id FROM whatsapp_mensagens GROUP BY telefone) ult
          ON ult.telefone = ultv.comprador_telefone
        LEFT JOIN whatsapp_mensagens m ON m.id = ult.max_id
        LEFT JOIN whatsapp_sessoes ws ON ws.telefone = ultv.comprador_telefone
        WHERE (m.id IS NOT NULL OR v.etapa IN ('" . implode("','", ETAPAS_VENDA_ATIVAS) . "'))
        {$whereBusca}{$whereResponsavel}
        ORDER BY COALESCE(m.created_at, v.created_at) DESC
        LIMIT ?
    ";
    $params[] = $limite;
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Vendedor pode ver/responder essa conversa? null = super_admin/supervisor,
 * sem restrição (supervisor ainda assim nunca AGE, ver guard em
 * admin/vendas_inbox.php). Mesmo espírito de usuarioPodeVerConversaWhatsapp()
 * — a listagem filtrada sozinha não basta, protege POST forjado também.
 */
function usuarioPodeVerConversaVendas(string $telefone, ?int $responsavelFiltro): bool {
    if ($responsavelFiltro === null) return true;
    $db = getDB();
    $stmt = $db->prepare("SELECT EXISTS (SELECT 1 FROM vendas WHERE comprador_telefone = ? AND responsavel_id = ?)");
    $stmt->execute([normalizarTelefone($telefone), $responsavelFiltro]);
    return (bool)$stmt->fetchColumn();
}

/**
 * Envia mensagem manual pela caixa de vendas — grava histórico e PAUSA a
 * IA (regra #4), igual enviarMensagemManualWhatsapp() (compra), mas manda
 * pela instância DEDICADA de vendas (zapiCredenciaisVendas()).
 */
function enviarMensagemManualVendas(string $telefone, string $texto, int $usuarioId): array {
    $telNorm = normalizarTelefone($telefone);
    $texto = trim($texto);
    if ($texto === '') {
        return ['ok' => false, 'erro' => 'Mensagem vazia.'];
    }
    $usuario = buscarUsuario($usuarioId);
    $nomeVendedor = trim((string)($usuario['nome'] ?? ''));
    $textoAssinado = $nomeVendedor !== '' ? "*{$nomeVendedor}:*\n{$texto}" : $texto;
    if (!zapiEnviarTexto($telNorm, $textoAssinado, zapiCredenciaisVendas())) {
        return ['ok' => false, 'erro' => 'Falha ao enviar — confira a instância de vendas (ou o fallback Meta) em Configurações.' . whatsappDetalheUltimoErro()];
    }
    $id = registrarMensagem($telNorm, 'out', $texto, null, false, 'text', $usuarioId);
    pausarIA($telNorm);
    return ['ok' => true, 'id' => $id];
}
