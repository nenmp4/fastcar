<?php
/**
 * WhatsApp Box da OUVIDORIA (19/11/2026) — só super admin, só mensagens com
 * canal='ouvidoria' (nunca mistura com compra/vendas/financeiro). Ver
 * chatbot-whatsapp/includes/mensagens_ouvidoria.php pras respostas
 * automáticas por assunto.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/usuarios.php';
require_once dirname(__DIR__) . '/chatbot-whatsapp/includes/mensagens_ouvidoria.php';

function listarConversasOuvidoria(string $busca = '', string $assunto = '', int $limite = 100): array {
    $where = '';
    $params = [];
    if ($busca !== '') {
        $where .= ' AND (COALESCE(NULLIF(oc.nome,\'\'), c.nome, v.nome, \'\') LIKE ? OR m.telefone LIKE ?)';
        $like = '%' . $busca . '%';
        array_push($params, $like, $like);
    }
    if ($assunto !== '' && isset(OUVIDORIA_ASSUNTOS[$assunto])) {
        $where .= ' AND oc.assunto = ?';
        $params[] = $assunto;
    }
    $sql = "
        SELECT m.telefone, m.mensagem AS ultima_mensagem, m.direcao AS ultima_direcao,
               m.tipo AS ultima_tipo, m.created_at AS ultima_em,
               COALESCE(NULLIF(oc.nome,''), c.nome, v.nome, '') AS nome,
               COALESCE(oc.assunto, '') AS assunto,
               COALESCE(oc.ia_pausada, 0) AS ia_pausada,
               (SELECT COUNT(*) FROM whatsapp_mensagens m2 WHERE m2.telefone = m.telefone AND m2.canal = 'ouvidoria' AND m2.direcao = 'in' AND m2.lida = 0) AS nao_lidas
        FROM whatsapp_mensagens m
        JOIN (SELECT telefone, MAX(id) AS max_id FROM whatsapp_mensagens WHERE canal = 'ouvidoria' GROUP BY telefone) ult
          ON ult.telefone = m.telefone AND ult.max_id = m.id
        LEFT JOIN ouvidoria_conversas oc ON oc.telefone = m.telefone
        LEFT JOIN clientes c ON c.telefone = m.telefone
        LEFT JOIN (SELECT comprador_telefone, MAX(comprador_nome) AS nome FROM vendas GROUP BY comprador_telefone) v ON v.comprador_telefone = m.telefone
        WHERE 1=1 {$where}
        ORDER BY m.created_at DESC
        LIMIT ?
    ";
    $params[] = $limite;
    $st = getDB()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

/** Negócios ligados ao telefone (pra abrir direto da conversa). */
function ouvidoriaNegociosDoTelefone(string $telefone): array {
    $tel = normalizarTelefone($telefone) ?: $telefone;
    $db = getDB();
    $st = $db->prepare("SELECT id, etapa FROM vendas WHERE comprador_telefone = ? ORDER BY id DESC LIMIT 5");
    $st->execute([$tel]);
    $vendas = $st->fetchAll();
    $st = $db->prepare("SELECT o.id, o.etapa FROM oportunidades o JOIN clientes c ON c.id = o.cliente_id WHERE c.telefone = ? ORDER BY o.id DESC LIMIT 5");
    $st->execute([$tel]);
    return ['vendas' => $vendas, 'oportunidades' => $st->fetchAll()];
}

function enviarMensagemManualOuvidoria(string $telefone, string $texto, int $usuarioId): array {
    whatsappCanalRegistro('ouvidoria');
    $tel = normalizarTelefone($telefone);
    $texto = trim($texto);
    if ($tel === '') return ['ok' => false, 'erro' => 'Telefone inválido.'];
    if ($texto === '') return ['ok' => false, 'erro' => 'Mensagem vazia.'];
    if (!evolutionConfiguredOuvidoria()) return ['ok' => false, 'erro' => 'Instância da ouvidoria não configurada (Configurações → Evolution API — Ouvidoria).'];
    $u = buscarUsuario($usuarioId);
    $nome = trim((string)($u['nome'] ?? ''));
    $assinado = $nome !== '' ? "*{$nome} (Ouvidoria):*\n{$texto}" : $texto;
    if (!evolutionEnviarTexto($tel, $assinado, evolutionCredenciaisOuvidoria())) {
        return ['ok' => false, 'erro' => 'Falha ao enviar pela instância da ouvidoria.' . whatsappDetalheUltimoErro()];
    }
    $id = registrarMensagem($tel, 'out', $texto, null, false, 'text', $usuarioId);
    // Humano assumiu: a IA para de responder essa conversa.
    ouvidoriaSalvarConversa($tel, ['ia_pausada' => 1]);
    return ['ok' => true, 'id' => $id];
}
