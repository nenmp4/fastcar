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
               COALESCE(NULLIF(oc.nome,''), vv.comprador_nome, cc.nome, c.nome, v.nome, '') AS nome,
               COALESCE(oc.assunto, '') AS assunto,
               COALESCE(oc.ia_pausada, 0) AS ia_pausada,
               (SELECT COUNT(*) FROM whatsapp_mensagens m2 WHERE m2.telefone = m.telefone AND m2.canal = 'ouvidoria' AND m2.direcao = 'in' AND m2.lida = 0) AS nao_lidas
        FROM whatsapp_mensagens m
        JOIN (SELECT telefone, MAX(id) AS max_id FROM whatsapp_mensagens WHERE canal = 'ouvidoria' GROUP BY telefone) ult
          ON ult.telefone = m.telefone AND ult.max_id = m.id
        LEFT JOIN ouvidoria_conversas oc ON oc.telefone = m.telefone
        LEFT JOIN clientes c ON c.telefone = m.telefone
        LEFT JOIN vendas vv ON vv.id = oc.venda_id
        LEFT JOIN oportunidades ov ON ov.id = oc.oportunidade_id
        LEFT JOIN clientes cc ON cc.id = ov.cliente_id
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

/**
 * 20/11/2026, "associa numero ao compra ou venda dentro sistema ter acesso
 * cliente" — o cliente pode escrever de outro número; a equipe busca o
 * negócio (nome, CPF, telefone, placa) e liga à conversa. Nunca vincula
 * sozinho: o que bate pelo telefone só aparece como sugestão.
 */
function ouvidoriaBuscarNegocios(string $termo, int $limite = 10): array {
    $termo = trim($termo);
    if (mb_strlen($termo) < 3) return [];
    $db = getDB();
    $like = '%' . $termo . '%';
    $dig = preg_replace('/\D/', '', $termo);
    $likeDig = $dig !== '' && strlen($dig) >= 3 ? '%' . $dig . '%' : '___nada___';
    $placa = '%' . strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $termo)) . '%';
    $st = $db->prepare("
        SELECT 'venda' AS tipo, v.id, v.comprador_nome AS nome, v.comprador_telefone AS telefone, v.etapa, v.tipo_contrato,
               o.veiculo_marca AS marca, o.veiculo_modelo AS modelo, o.veiculo_placa AS placa
        FROM vendas v LEFT JOIN oportunidades o ON o.id = v.oportunidade_id
        WHERE v.comprador_nome LIKE ? OR v.comprador_telefone LIKE ? OR REPLACE(REPLACE(COALESCE(v.comprador_cpf,''),'.',''),'-','') LIKE ?
           OR REPLACE(UPPER(COALESCE(o.veiculo_placa,'')),'-','') LIKE ?
        ORDER BY v.id DESC LIMIT ?
    ");
    $st->execute([$like, $likeDig, $likeDig, $placa, $limite]);
    $res = $st->fetchAll();
    $st = $db->prepare("
        SELECT 'compra' AS tipo, o.id, c.nome, c.telefone, o.etapa, '' AS tipo_contrato,
               o.veiculo_marca AS marca, o.veiculo_modelo AS modelo, o.veiculo_placa AS placa
        FROM oportunidades o JOIN clientes c ON c.id = o.cliente_id
        WHERE c.nome LIKE ? OR c.telefone LIKE ? OR REPLACE(REPLACE(COALESCE(c.cpf,''),'.',''),'-','') LIKE ?
           OR REPLACE(UPPER(COALESCE(o.veiculo_placa,'')),'-','') LIKE ?
        ORDER BY o.id DESC LIMIT ?
    ");
    $st->execute([$like, $likeDig, $likeDig, $placa, $limite]);
    foreach (array_merge($res, $st->fetchAll()) as $r) {
        $r['etapa_label'] = $r['tipo'] === 'venda' ? etapaVendaLabel((string)$r['etapa'], (string)($r['tipo_contrato'] ?: 'venda')) : etapaLabel((string)$r['etapa']);
        $out[] = $r;
    }
    return $out ?? [];
}

/** Resumo do negócio vinculado (ou sugerido pelo telefone) pro topo da conversa. */
function ouvidoriaResumoNegocio(string $tipo, int $id): ?array {
    $db = getDB();
    if ($tipo === 'venda') {
        $st = $db->prepare("SELECT v.id, v.comprador_nome AS nome, v.comprador_telefone AS telefone, v.comprador_cpf AS cpf, v.etapa, v.tipo_contrato, v.preco_venda,
                o.veiculo_marca AS marca, o.veiculo_modelo AS modelo, o.veiculo_ano AS ano, o.veiculo_placa AS placa
            FROM vendas v LEFT JOIN oportunidades o ON o.id = v.oportunidade_id WHERE v.id = ?");
        $st->execute([$id]);
        $r = $st->fetch();
        if (!$r) return null;
        $st = $db->prepare("SELECT
                COALESCE(SUM(CASE WHEN status='atrasado' THEN valor END),0) AS atrasado,
                COALESCE(SUM(CASE WHEN status='pendente' THEN valor END),0) AS pendente,
                COALESCE(SUM(CASE WHEN status='pago' THEN valor END),0) AS pago,
                MIN(CASE WHEN status IN ('pendente','atrasado') THEN data_vencimento END) AS proximo_venc
            FROM fin_lancamentos WHERE venda_id = ? AND tipo = 'receita'");
        $st->execute([$id]);
        $r['fin'] = $st->fetch();
        $r['tipo'] = 'venda';
        $r['etapa_label'] = etapaVendaLabel((string)$r['etapa'], (string)($r['tipo_contrato'] ?: 'venda'));
        $r['url'] = '/admin/venda.php?id=' . $id;
        return $r;
    }
    $st = $db->prepare("SELECT o.id, c.id AS cliente_id, c.nome, c.telefone, c.cpf, o.etapa, o.valor_final,
            o.veiculo_marca AS marca, o.veiculo_modelo AS modelo, o.veiculo_ano AS ano, o.veiculo_placa AS placa
        FROM oportunidades o JOIN clientes c ON c.id = o.cliente_id WHERE o.id = ?");
    $st->execute([$id]);
    $r = $st->fetch();
    if (!$r) return null;
    $r['tipo'] = 'compra';
    $r['etapa_label'] = etapaLabel((string)$r['etapa']);
    $r['url'] = '/admin/oportunidade.php?id=' . $id;
    return $r;
}
