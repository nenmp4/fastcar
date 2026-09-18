<?php
/**
 * WhatsApp Box do financeiro (18/09/2026, pedido José/Jean: "vamos fazer
 * gestão desses clientes que não paga fazer cobrança pelo sistema vai ser
 * instancias só do finceir outro numero") — espelha
 * includes/vendas_inbox.php (mesmo padrão de arquivo próprio, nunca
 * parametriza o inbox de compra/vendas já validados em produção), mas a
 * "conversa" aqui não vem de `oportunidades`/`vendas` — vem de qualquer
 * telefone que apareça em `fin_lancamentos`, resolvido por 1 dos 3 jeitos
 * que um lançamento pode estar ligado a um contato de verdade:
 *   1. cliente_id      → clientes.telefone (funil de compra)
 *   2. venda_id        → vendas.comprador_telefone (revenda)
 *   3. asaas_customer_id → fin_asaas_clientes.telefone (importado do Asaas,
 *      o caso mais comum agora — cobrança de parcela de venda de veículo)
 * `cliente_nome_manual` sozinho (sem nenhum dos 3 vínculos) nunca aparece
 * aqui — não tem telefone pra mandar mensagem nenhuma.
 *
 * Sem "responsável"/round-robin — perfil `financeiro` já é um time pequeno
 * e restrito (super_admin + financeiro), todo mundo com acesso vê a caixa
 * inteira, mesmo espírito de super_admin/supervisor nos outros inboxes.
 *
 * Escopo intencionalmente enxuto nesta 1ª versão (mesmo espírito já
 * documentado pro inbox de vendas): SEM foto de perfil ao vivo, SEM envio
 * de áudio, e o mais importante — SEM NENHUM disparo automático de
 * cobrança. Toda mensagem sai só quando um humano do financeiro digita e
 * aperta enviar. Ver mensagens_financeiro.php pro racional de segurança
 * (risco de bloqueio de número por mensagem repetida em rajada, já
 * documentado no CLAUDE.md).
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/usuarios.php';
require_once dirname(__DIR__) . '/chatbot-whatsapp/includes/mensagens.php'; // registrarMensagem()

/**
 * Fragmento SQL reutilizado — telefone + nome + status/valor de cada
 * lançamento, resolvido pelos 3 caminhos possíveis, um por UNION. Usado
 * tanto pra listar conversas quanto pra validar acesso a uma.
 */
function _finInboxFonteSql(): string {
    return "
        SELECT c.telefone AS telefone, c.nome AS nome, l.status AS status, l.valor AS valor
        FROM fin_lancamentos l JOIN clientes c ON c.id = l.cliente_id
        WHERE l.cliente_id IS NOT NULL AND c.telefone IS NOT NULL AND c.telefone != ''
        UNION ALL
        SELECT v.comprador_telefone AS telefone, v.comprador_nome AS nome, l.status AS status, l.valor AS valor
        FROM fin_lancamentos l JOIN vendas v ON v.id = l.venda_id
        WHERE l.venda_id IS NOT NULL AND v.comprador_telefone IS NOT NULL AND v.comprador_telefone != ''
        UNION ALL
        SELECT fac.telefone AS telefone, fac.nome AS nome, l.status AS status, l.valor AS valor
        FROM fin_lancamentos l JOIN fin_asaas_clientes fac ON fac.asaas_id = l.asaas_customer_id
        WHERE l.asaas_customer_id IS NOT NULL AND fac.telefone IS NOT NULL AND fac.telefone != ''
    ";
}

/**
 * Lista as conversas do financeiro (1 linha por telefone que aparece em
 * algum fin_lancamentos), última mensagem, não lidas, e o total em atraso
 * daquele telefone (contexto de cobrança direto na sidebar).
 */
function listarConversasFinanceiro(string $busca = '', int $limite = 100): array {
    $db = getDB();
    $whereBusca = '';
    $params = [];
    if ($busca !== '') {
        $whereBusca = ' AND (fc.nome LIKE ? OR m.telefone LIKE ?)';
        $like = '%' . $busca . '%';
        $params = [$like, $like];
    }

    $fonte = _finInboxFonteSql();
    $sql = "
        SELECT m.telefone, m.mensagem AS ultima_mensagem, m.direcao AS ultima_direcao,
               m.tipo AS ultima_tipo, m.created_at AS ultima_em,
               fc.nome,
               fc.total_atrasado,
               (SELECT COUNT(*) FROM whatsapp_mensagens m2 WHERE m2.telefone = m.telefone AND m2.direcao = 'in' AND m2.lida = 0) AS nao_lidas
        FROM whatsapp_mensagens m
        JOIN (
            SELECT telefone, MAX(nome) AS nome, SUM(CASE WHEN status='atrasado' THEN valor ELSE 0 END) AS total_atrasado
            FROM ({$fonte}) x
            GROUP BY telefone
        ) fc ON fc.telefone = m.telefone
        WHERE m.id = (SELECT MAX(id) FROM whatsapp_mensagens m3 WHERE m3.telefone = m.telefone)
        {$whereBusca}
        ORDER BY m.created_at DESC
        LIMIT ?
    ";
    $params[] = $limite;
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Esse telefone tem algum fin_lancamentos ligado? Mesma checagem central
 * usada pra listar E pra travar acesso direto/POST forjado — sem essa
 * função em separado, a listagem filtrada sozinha não impediria alguém
 * digitando `?telefone=` de outro contato qualquer na URL.
 */
function usuarioPodeVerConversaFinanceiro(string $telefone): bool {
    $telNorm = normalizarTelefone($telefone);
    if ($telNorm === '') return false;
    $db = getDB();
    $fonte = _finInboxFonteSql();
    $stmt = $db->prepare("SELECT EXISTS (SELECT 1 FROM ({$fonte}) x WHERE x.telefone = ?)");
    $stmt->execute([$telNorm]);
    return (bool)$stmt->fetchColumn();
}

/** Nome + total em atraso de um telefone — usado no cabeçalho da thread. */
function finInboxContato(string $telefone): array {
    $db = getDB();
    $fonte = _finInboxFonteSql();
    $stmt = $db->prepare("
        SELECT MAX(nome) AS nome, SUM(CASE WHEN status='atrasado' THEN valor ELSE 0 END) AS total_atrasado
        FROM ({$fonte}) x WHERE x.telefone = ?
    ");
    $stmt->execute([normalizarTelefone($telefone)]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: ['nome' => null, 'total_atrasado' => 0];
}

/**
 * Envia mensagem manual pela caixa do financeiro — assina com o nome de
 * quem está respondendo (mesmo padrão de compra/vendas) e manda pela
 * instância DEDICADA do financeiro. Nunca dispara sozinha — só a partir
 * de um clique real de um humano nesta tela.
 */
function enviarMensagemManualFinanceiro(string $telefone, string $texto, int $usuarioId): array {
    $telNorm = normalizarTelefone($telefone);
    $texto = trim($texto);
    if ($texto === '') {
        return ['ok' => false, 'erro' => 'Mensagem vazia.'];
    }
    $usuario = buscarUsuario($usuarioId);
    $nome = trim((string)($usuario['nome'] ?? ''));
    $textoAssinado = $nome !== '' ? "*{$nome}:*\n{$texto}" : $texto;
    if (!zapiEnviarTexto($telNorm, $textoAssinado, zapiCredenciaisFinanceiro())) {
        return ['ok' => false, 'erro' => 'Falha ao enviar pelo Z-API — confira a instância do financeiro em Configurações.'];
    }
    $id = registrarMensagem($telNorm, 'out', $texto, null, false, 'text', $usuarioId);
    return ['ok' => true, 'id' => $id];
}
