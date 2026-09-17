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
        $whereBusca = ' AND (v.comprador_nome LIKE ? OR m.telefone LIKE ?)';
        $like = '%' . $busca . '%';
        $params = [$like, $like];
    }
    $whereResponsavel = '';
    if ($responsavelFiltro !== null) {
        $whereResponsavel = ' AND EXISTS (SELECT 1 FROM vendas v2 WHERE v2.comprador_telefone = m.telefone AND v2.responsavel_id = ?)';
        $params[] = $responsavelFiltro;
    }

    // Só telefone que tem pelo menos 1 negociação de venda (origem
    // 'whatsapp' ou manual com telefone já preenchido) — nunca mistura com
    // conversa que é só do funil de compra (telefone pode coincidir com um
    // cliente comprador em tese, mas essa caixa só mostra o lado venda).
    $sql = "
        SELECT m.telefone, m.mensagem AS ultima_mensagem, m.direcao AS ultima_direcao,
               m.tipo AS ultima_tipo, m.created_at AS ultima_em,
               v.comprador_nome,
               (SELECT COUNT(*) FROM whatsapp_mensagens m2 WHERE m2.telefone = m.telefone AND m2.direcao = 'in' AND m2.lida = 0) AS nao_lidas,
               COALESCE(ws.ia_pausada, 0) AS ia_pausada
        FROM whatsapp_mensagens m
        JOIN vendas v ON v.comprador_telefone = m.telefone
        LEFT JOIN whatsapp_sessoes ws ON ws.telefone = m.telefone
        WHERE m.id = (SELECT MAX(id) FROM whatsapp_mensagens m3 WHERE m3.telefone = m.telefone)
          AND v.id = (SELECT MAX(id) FROM vendas v3 WHERE v3.comprador_telefone = m.telefone)
        {$whereBusca}{$whereResponsavel}
        ORDER BY m.created_at DESC
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
        return ['ok' => false, 'erro' => 'Falha ao enviar pelo Z-API — confira a instância de vendas em Configurações.'];
    }
    $id = registrarMensagem($telNorm, 'out', $texto, null, false, 'text', $usuarioId);
    pausarIA($telNorm);
    return ['ok' => true, 'id' => $id];
}
