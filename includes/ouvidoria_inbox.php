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
require_once __DIR__ . '/email_templates.php';
require_once __DIR__ . '/mail.php';
require_once __DIR__ . '/auditoria.php';
require_once dirname(__DIR__) . '/chatbot-whatsapp/includes/mensagens_ouvidoria.php';

/** Filtros da lista (21/11/2026, item 10 do backlog: chips viraram selects). */
const OUVIDORIA_FILTROS_STATUS = [
    ''               => 'Todos os status',
    'nao_lidas'      => '🔵 Não lidas',
    'esperando'      => '⏱ Esperando resposta',
    'urgente'        => '🚨 Urgentes',
    'aberto'         => '🆕 Ticket aberto',
    'em_atendimento' => '💬 Em atendimento',
    'resolvido'      => '✅ Só resolvidos',
];

function listarConversasOuvidoria(string $busca = '', string $assunto = '', int $limite = 100, string $status = '', string $ordem = ''): array {
    $where = '';
    $params = [];
    switch ($status) {
        case 'nao_lidas':      $where .= ' AND nl.n > 0'; break;
        case 'esperando':      $where .= " AND m.direcao = 'in'"; break;
        case 'urgente':        $where .= " AND EXISTS (SELECT 1 FROM ouvidoria_tickets t WHERE t.telefone = m.telefone AND t.status != 'resolvido' AND t.urgente = 1)"; break;
        case 'aberto':
        case 'em_atendimento': $where .= ' AND EXISTS (SELECT 1 FROM ouvidoria_tickets t WHERE t.telefone = m.telefone AND t.status = ?)'; $params[] = $status; break;
        case 'resolvido':      $where .= " AND EXISTS (SELECT 1 FROM ouvidoria_tickets t WHERE t.telefone = m.telefone)
                                            AND NOT EXISTS (SELECT 1 FROM ouvidoria_tickets t WHERE t.telefone = m.telefone AND t.status != 'resolvido')"; break;
    }
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
        SELECT m.telefone,
               -- 21/11/2026: prévia = última mensagem do CLIENTE (a do robô só se ele nunca escreveu).
               COALESCE(mi.mensagem, m.mensagem) AS ultima_mensagem,
               CASE WHEN mi.id IS NOT NULL THEN 'in' ELSE m.direcao END AS ultima_direcao,
               COALESCE(mi.tipo, m.tipo) AS ultima_tipo, m.created_at AS ultima_em,
               COALESCE(CASE WHEN TRIM(COALESCE(oc.nome,'')) IN ('', 'Você', 'você', 'Voce', 'voce', 'You') THEN NULL ELSE oc.nome END,
                        NULLIF(vv.comprador_nome,''), NULLIF(cc.nome,''), NULLIF(c.nome,''), NULLIF(v.nome,''), '') AS nome,
               COALESCE(oc.assunto, '') AS assunto,
               COALESCE(oc.ia_pausada, 0) AS ia_pausada,
               COALESCE(nl.n, 0) AS nao_lidas,
               -- Cliente falou por último e ninguém respondeu ainda: desde quando (selo de espera/SLA).
               CASE WHEN m.direcao = 'in' THEN COALESCE(
                   (SELECT MIN(m3.created_at) FROM whatsapp_mensagens m3 WHERE m3.telefone = m.telefone AND m3.canal = 'ouvidoria' AND m3.direcao = 'in'
                      AND m3.id > COALESCE((SELECT MAX(m4.id) FROM whatsapp_mensagens m4 WHERE m4.telefone = m.telefone AND m4.canal = 'ouvidoria' AND m4.direcao = 'out'), 0)),
                   m.created_at) END AS esperando_desde,
               (SELECT t.status FROM ouvidoria_tickets t WHERE t.telefone = m.telefone AND t.status != 'resolvido' ORDER BY t.id DESC LIMIT 1) AS ticket_status,
               (SELECT MAX(t.urgente) FROM ouvidoria_tickets t WHERE t.telefone = m.telefone AND t.status != 'resolvido') AS urgente
        FROM whatsapp_mensagens m
        JOIN (SELECT telefone, MAX(id) AS max_id FROM whatsapp_mensagens WHERE canal = 'ouvidoria' GROUP BY telefone) ult
          ON ult.telefone = m.telefone AND ult.max_id = m.id
        LEFT JOIN (SELECT telefone, MAX(id) AS max_id FROM whatsapp_mensagens WHERE canal = 'ouvidoria' AND direcao = 'in' GROUP BY telefone) ulti
          ON ulti.telefone = m.telefone
        LEFT JOIN whatsapp_mensagens mi ON mi.id = ulti.max_id
        LEFT JOIN (SELECT telefone, COUNT(*) AS n FROM whatsapp_mensagens WHERE canal = 'ouvidoria' AND direcao = 'in' AND lida = 0 GROUP BY telefone) nl
          ON nl.telefone = m.telefone
        LEFT JOIN ouvidoria_conversas oc ON oc.telefone = m.telefone
        LEFT JOIN clientes c ON c.telefone = m.telefone
        LEFT JOIN vendas vv ON vv.id = oc.venda_id
        LEFT JOIN oportunidades ov ON ov.id = oc.oportunidade_id
        LEFT JOIN clientes cc ON cc.id = ov.cliente_id
        LEFT JOIN (SELECT comprador_telefone, MAX(comprador_nome) AS nome FROM vendas GROUP BY comprador_telefone) v ON v.comprador_telefone = m.telefone
        WHERE 1=1 {$where}
        ORDER BY " . ($ordem === 'espera'
            ? "(m.direcao = 'in') DESC, esperando_desde ASC, m.created_at DESC"
            : 'm.created_at DESC') . "
        LIMIT ?
    ";
    $params[] = $limite;
    $st = getDB()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

/** Quantas conversas têm mensagem do cliente ainda não lida (botão "Não lidas"). */
function contarConversasNaoLidasOuvidoria(): int {
    return (int)getDB()->query("SELECT COUNT(DISTINCT telefone) FROM whatsapp_mensagens WHERE canal = 'ouvidoria' AND direcao = 'in' AND lida = 0")->fetchColumn();
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
    $id = ouvidoriaRegistrarEnviada($tel, $texto, false, $usuarioId);
    // Humano assumiu: a IA para de responder essa conversa.
    ouvidoriaSalvarConversa($tel, ['ia_pausada' => 1]);
    $protocolos = [];
    try {
        foreach (ouvidoriaTicketsAtivos($tel) as $t) $protocolos[] = $t['protocolo'];
        ouvidoriaTicketsMarcarAtendimento($tel);
    } catch (Throwable $e) {}
    auditoriaRegistrar('ouvidoria_mensagem_enviada', $usuarioId, $nome, 'ouvidoria', null,
        "Resposta pela caixa da ouvidoria pra {$tel}" . ($protocolos ? ' (' . implode(', ', $protocolos) . ')' : '') . ': ' . mb_substr($texto, 0, 300));
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

/** Tickets do telefone (mais recentes primeiro) pro cabeçalho da conversa. */
function ouvidoriaTicketsDoTelefone(string $telefone, int $limite = 10): array {
    $st = getDB()->prepare("SELECT * FROM ouvidoria_tickets WHERE telefone = ? ORDER BY (status = 'resolvido'), id DESC LIMIT ?");
    $st->execute([$telefone, $limite]);
    return $st->fetchAll();
}

/** Lista da tela de tickets. */
function listarTicketsOuvidoria(string $status = '', string $assunto = '', string $busca = '', int $limite = 25, int $offset = 0): array {
    [$where, $params] = _ouvidoriaTicketsWhere($status, $assunto, $busca);
    $sql = "SELECT t.*, u.nome AS resolvido_por_nome,
                   COALESCE(NULLIF(t.nome,''), NULLIF(oc.nome,''), '') AS nome_exib,
                   (julianday(COALESCE(t.primeira_resposta_em, 'now','localtime')) - julianday(t.aberto_em)) * 24 AS horas_sem_resposta
            FROM ouvidoria_tickets t
            LEFT JOIN ouvidoria_conversas oc ON oc.telefone = t.telefone
            LEFT JOIN usuarios u ON u.id = t.resolvido_por
            WHERE 1=1 {$where}
            ORDER BY CASE t.status WHEN 'aberto' THEN 0 WHEN 'em_atendimento' THEN 1 ELSE 2 END,
                     CASE WHEN t.status != 'resolvido' AND t.urgente = 1 THEN 0 ELSE 1 END,
                     t.aberto_em DESC
            LIMIT ? OFFSET ?";
    $params[] = $limite; $params[] = $offset;
    $st = getDB()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

function contarTicketsOuvidoria(string $status = '', string $assunto = '', string $busca = ''): int {
    [$where, $params] = _ouvidoriaTicketsWhere($status, $assunto, $busca);
    $st = getDB()->prepare("SELECT COUNT(*) FROM ouvidoria_tickets t LEFT JOIN ouvidoria_conversas oc ON oc.telefone = t.telefone WHERE 1=1 {$where}");
    $st->execute($params);
    return (int)$st->fetchColumn();
}

function _ouvidoriaTicketsWhere(string $status, string $assunto, string $busca): array {
    $where = ''; $params = [];
    if ($status === 'urgente') { $where .= " AND t.status != 'resolvido' AND t.urgente = 1"; }
    elseif (isset(OUVIDORIA_TICKET_STATUS[$status])) { $where .= ' AND t.status = ?'; $params[] = $status; }
    if (isset(OUVIDORIA_ASSUNTOS[$assunto])) { $where .= ' AND t.assunto = ?'; $params[] = $assunto; }
    if ($busca !== '') {
        $where .= " AND (t.protocolo LIKE ? OR t.telefone LIKE ? OR t.nome LIKE ? OR oc.nome LIKE ?)";
        $like = '%' . $busca . '%';
        array_push($params, $like, $like, $like, $like);
    }
    return [$where, $params];
}

/** Resumo do topo da tela de tickets. */
function resumoTicketsOuvidoria(): array {
    $db = getDB();
    $r = $db->query("SELECT
            SUM(status = 'aberto') AS abertos,
            SUM(status = 'em_atendimento') AS em_atendimento,
            SUM(status = 'resolvido' AND resolvido_em >= date('now','localtime','start of month')) AS resolvidos_mes,
            AVG(CASE WHEN status = 'resolvido' AND resolvido_em >= date('now','localtime','start of month')
                     THEN (julianday(resolvido_em) - julianday(aberto_em)) * 24 END) AS horas_media_resolucao,
            SUM(status = 'aberto' AND primeira_resposta_em IS NULL AND aberto_em <= datetime('now','localtime','-1 day')) AS sem_resposta_24h,
            SUM(status != 'resolvido' AND urgente = 1) AS urgentes
        FROM ouvidoria_tickets")->fetch();
    return array_map(fn($v) => $v === null ? 0 : $v, $r ?: []);
}

/**
 * Puxa o histórico que já estava no número da ouvidoria antes do webhook
 * (20/11/2026). Só grava: nunca responde, nunca notifica, nunca abre ticket,
 * nunca chama IA (custo). Assunto só por palavra-chave. Mídia antiga entra
 * como marcador ([image] etc) — o WhatsApp costuma expirar o arquivo.
 * Idempotente: dedup pelo id da mensagem (zapi_message_id).
 * @return array ['ok','conversas','mensagens','erro'?]
 */
function ouvidoriaImportarHistorico(int $dias = 90, int $maxConversas = 300): array {
    whatsappCanalRegistro('ouvidoria');
    if (!evolutionConfiguredOuvidoria()) return ['ok' => false, 'erro' => 'Instância da ouvidoria não configurada.'];
    @set_time_limit(600);
    $cred = evolutionCredenciaisOuvidoria();
    $chats = evolutionListarConversas($cred);
    if ($chats === null) return ['ok' => false, 'erro' => 'Não consegui listar as conversas: ' . (evolutionUltimoErro() ?? '')];
    $corte = $dias > 0 ? time() - $dias * 86400 : 0;
    $db = getDB();
    $ins = $db->prepare("INSERT OR IGNORE INTO whatsapp_mensagens
            (telefone, cliente_id, direcao, mensagem, tipo, enviado_por_ia, zapi_message_id, canal, lida, created_at)
        VALUES (?, (SELECT id FROM clientes WHERE telefone = ?), ?, ?, ?, 0, ?, 'ouvidoria', 1, ?)");
    $nConv = 0; $nMsg = 0; $falhas = 0;
    foreach (array_slice($chats, 0, $maxConversas) as $c) {
        if ($corte && $c['atualizado_ts'] && $c['atualizado_ts'] < $corte) continue;
        $tel = $c['telefone'];
        // 19/11/2026, "essa instância derrubou todos os canais": as 4 instâncias rodam no
        // MESMO servidor Evolution — buscar centenas de conversas sem pausa pesa nele e
        // derruba os outros números junto. Pausa de 0,5s entre conversas.
        if ($nConv + $falhas > 0) usleep(500000);
        $msgs = evolutionBuscarMensagensConversa($tel, $cred, 100);
        if ($msgs === null) { $falhas++; continue; }
        $novas = 0; $nome = nomeWhatsappPareceValido((string)$c['nome']) ? (string)$c['nome'] : ''; $textosCliente = [];
        try {
            $db->beginTransaction();
            foreach ($msgs as $m) {
                if ($corte && $m['ts'] && $m['ts'] < $corte) continue;
                if (jaProcessado($m['id'])) continue;
                $texto = $m['texto'] !== '' ? $m['texto'] : "[{$m['tipo']}]";
                if ($m['tipo'] !== 'text' && $m['texto'] !== '') $texto = "[{$m['tipo']}] " . $m['texto'];
                $quando = $m['ts'] ? date('Y-m-d H:i:s', $m['ts']) : date('Y-m-d H:i:s');
                $ins->execute([$tel, $tel, $m['de_mim'] ? 'out' : 'in', $texto, $m['tipo'], $m['id'], $quando]);
                if ($ins->rowCount() > 0) {
                    $novas++;
                    if (!$m['de_mim'] && $m['tipo'] === 'text') $textosCliente[] = $m['texto'];
                }
                if (!$m['de_mim'] && $m['nome'] !== '' && $nome === '') $nome = $m['nome'];
            }
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            $falhas++;
            continue;
        }
        if ($novas === 0) continue;
        $nConv++; $nMsg += $novas;
        $conv = ouvidoriaConversa($tel);
        $campos = [];
        if ($conv['nome'] === '' && $nome !== '' && nomeWhatsappPareceValido($nome)) $campos['nome'] = $nome;
        if ($conv['assunto'] === '') {
            $assunto = null;
            foreach (array_reverse($textosCliente) as $t) { if ($assunto = ouvidoriaClassificarPorPalavra($t)) break; }
            $campos['assunto'] = $assunto ?: 'outro';
        }
        if ($campos) ouvidoriaSalvarConversa($tel, $campos);
    }
    return ['ok' => true, 'conversas' => $nConv, 'mensagens' => $nMsg, 'falhas' => $falhas, 'total_chats' => count($chats)];
}

/**
 * "Notificar Jean" (20/11/2026, "tem caso que ele sabe ou que ouve manda
 * resumo do caso no tiket") — manda o resumo do ticket pro Jean: WhatsApp
 * pessoal (usuarios.whatsapp do usuário "Jean…", senão os números de
 * notificação), e-mail e sino. Ação manual, só pra equipe — nunca pro cliente.
 */
function ouvidoriaDestinatarioJean(): ?array {
    $st = getDB()->query("SELECT id, nome, email, whatsapp FROM usuarios WHERE bloqueado = 0 AND nome LIKE 'Jean%' ORDER BY (perfil = 'super_admin') DESC, id LIMIT 1");
    return $st->fetch() ?: null;
}

function ouvidoriaResumoTicketTexto(array $t, string $nota, string $quem): string {
    $db = getDB();
    $conv = ouvidoriaConversa((string)$t['telefone']);
    $nome = $t['nome'] ?: ($conv['nome'] ?: '—');
    $linhas = [
        "🎫 *Ouvidoria — {$t['protocolo']}*",
        "👤 {$nome} · {$t['telefone']}",
        "📌 " . (OUVIDORIA_ASSUNTOS[$t['assunto']] ?? $t['assunto']) . ' · ' . (OUVIDORIA_TICKET_STATUS[$t['status']] ?? $t['status']),
        "🕒 Aberto em " . date('d/m/Y H:i', strtotime((string)$t['aberto_em'])),
    ];
    $vincTipo = $t['venda_id'] ? 'venda' : ($t['oportunidade_id'] ? 'compra' : ($conv['venda_id'] ? 'venda' : ($conv['oportunidade_id'] ? 'compra' : '')));
    $vincId = (int)($t['venda_id'] ?: ($t['oportunidade_id'] ?: ($conv['venda_id'] ?: $conv['oportunidade_id'])));
    if ($vincTipo && $vincId && ($n = ouvidoriaResumoNegocio($vincTipo, $vincId))) {
        $v = ($vincTipo === 'venda' ? '🛒 Venda' : '🚗 Compra') . " #{$vincId} — " . trim(($n['marca'] ?? '') . ' ' . ($n['modelo'] ?? '')) . ($n['placa'] ? " ({$n['placa']})" : '') . " · {$n['etapa_label']}";
        if ($vincTipo === 'venda' && !empty($n['fin']) && (float)$n['fin']['atrasado'] > 0) $v .= ' · R$ ' . number_format((float)$n['fin']['atrasado'], 2, ',', '.') . ' em atraso';
        $linhas[] = $v;
    }
    $cq = ouvidoriaComQuemDoTicket($t);
    if ($cq && $cq['com'] !== 'nao_achado') {
        $quando = !empty($cq['data_ref']) ? 'em ' . date('d/m/Y', strtotime($cq['data_ref'])) : 'hoje';
        $linhas[] = "🚗 {$cq['placa']} {$cq['veiculo']} — {$quando} estava com: " . ($cq['com'] === 'comprador'
            ? $cq['nome'] . ($cq['cpf'] ? " · CPF {$cq['cpf']}" : '') . ($cq['cnh'] ? " · CNH {$cq['cnh']}" : '') . " (venda #{$cq['venda_id']})"
            : ($cq['com'] === 'fastcar' ? 'Fastcar (estoque)'
            : ($cq['com'] === 'antes_cadastro' ? 'sem registro (data anterior ao cadastro manual do veículo' . ($cq['nome'] !== '' ? ", vendedor cadastrado: {$cq['nome']}" : '') . ')'
            : ($cq['nome'] ?: 'vendedor original') . ' (antes da compra)')));
    }
    if ($nota !== '') $linhas[] = "\n📝 {$quem}: {$nota}";
    $st = $db->prepare("SELECT direcao, mensagem, created_at FROM whatsapp_mensagens
        WHERE telefone = ? AND canal = 'ouvidoria' AND created_at >= ? ORDER BY id DESC LIMIT 8");
    $st->execute([$t['telefone'], $t['aberto_em']]);
    $msgs = array_reverse($st->fetchAll());
    if ($msgs) {
        $linhas[] = "\n💬 Últimas mensagens:";
        foreach ($msgs as $m) {
            $linhas[] = ($m['direcao'] === 'in' ? '• Cliente' : '• Fastcar') . ' (' . date('d/m H:i', strtotime($m['created_at'])) . '): ' . mb_strimwidth(preg_replace('/\s+/', ' ', (string)$m['mensagem']), 0, 160, '…');
        }
    }
    $linhas[] = "\n🔗 " . appBaseUrl() . '/admin/ouvidoria_inbox.php?telefone=' . rawurlencode((string)$t['telefone']);
    return implode("\n", $linhas);
}

function ouvidoriaNotificarJean(int $ticketId, string $nota, int $usuarioId): array {
    $t = ouvidoriaBuscarTicket($ticketId);
    if (!$t) return ['ok' => false, 'erro' => 'Ticket não encontrado.'];
    $nota = trim($nota);
    $u = $usuarioId > 0 ? buscarUsuario($usuarioId) : null;
    $quem = $usuarioId > 0 ? trim((string)($u['nome'] ?? 'Equipe')) : 'Sistema';
    $texto = ouvidoriaResumoTicketTexto($t, $nota, $quem);
    $jean = ouvidoriaDestinatarioJean();

    $numeros = [];
    if ($jean && trim((string)$jean['whatsapp']) !== '') $numeros[] = (string)$jean['whatsapp'];
    if (!$numeros) $numeros = array_filter(array_map('trim', explode(',', (string)(getConfig('notificacao_leads_whatsapp') ?: ''))));
    $wppOk = false;
    foreach (array_unique($numeros) as $n) {
        try { if (zapiEnviarTextoInterno($n, $texto)) $wppOk = true; } catch (Throwable $e) {}
    }
    $emailOk = false;
    if ($jean && filter_var((string)$jean['email'], FILTER_VALIDATE_EMAIL)) {
        try {
            $html = emailLayout('<p>' . nl2br(e(str_replace('*', '', $texto))) . '</p>'
                . emailBotao('Abrir conversa', appBaseUrl() . '/admin/ouvidoria_inbox.php?telefone=' . rawurlencode((string)$t['telefone'])));
            $emailOk = enviarEmail((string)$jean['email'], "Ouvidoria {$t['protocolo']} — " . (OUVIDORIA_ASSUNTOS[$t['assunto']] ?? ''), $html, (string)$jean['nome']) === true;
        } catch (Throwable $e) {}
    }
    $sinoOk = false;
    if ($jean && (int)$jean['id'] !== $usuarioId) {
        try {
            criarNotificacao((int)$jean['id'], 'ouvidoria', ($usuarioId > 0 ? "🎫 {$quem} te mandou o ticket {$t['protocolo']}" : "🚨 Ticket urgente {$t['protocolo']}"), mb_substr($nota !== '' ? $nota : (string)$t['resumo'], 0, 160), '/admin/ouvidoria_inbox.php?telefone=' . rawurlencode((string)$t['telefone']));
            $sinoOk = true;
        } catch (Throwable $e) {}
    }
    if (!$wppOk && !$emailOk && !$sinoOk) {
        return ['ok' => false, 'erro' => $jean ? 'Não consegui avisar o Jean (WhatsApp e e-mail falharam).' : 'Nenhum usuário "Jean" ativo nem número de notificação configurado.'];
    }
    getDB()->prepare("UPDATE ouvidoria_tickets SET notificado_em = datetime('now','localtime'), notificado_por = ?, updated_at = datetime('now','localtime') WHERE id = ?")
        ->execute([$usuarioId > 0 ? $usuarioId : null, $ticketId]);
    $canais = array_keys(array_filter(['WhatsApp' => $wppOk, 'e-mail' => $emailOk, 'sino' => $sinoOk]));
    return ['ok' => true, 'canais' => $canais, 'protocolo' => $t['protocolo']];
}

/** "Com quem está o carro" do ticket, pronto pra tela (null = sem placa ou placa não achada). */
function ouvidoriaComQuemDoTicket(array $t): ?array {
    $placa = ouvidoriaPlacaDoTicket($t);
    if ($placa === '') return null;
    $r = ouvidoriaComQuemEstaCarro($placa, !empty($t['data_ref']) ? (string)$t['data_ref'] : null);
    return $r ?: ['com' => 'nao_achado', 'placa' => $placa, 'data_ref' => $t['data_ref'] ?? null];
}

function ouvidoriaComQuemHtml(?array $r): string {
    if (!$r) return '<span style="color:var(--texto-fraco)">🚗 Sem placa no ticket — informe a placa pra saber com quem está o carro.</span>';
    $quando = !empty($r['data_ref']) ? 'em ' . date('d/m/Y', strtotime($r['data_ref'])) : 'hoje';
    if ($r['com'] === 'nao_achado') return '🚗 Placa <strong>' . e($r['placa']) . '</strong> não encontrada em nenhum veículo do sistema.';
    $cab = '🚗 <strong>' . e($r['placa']) . '</strong> ' . e($r['veiculo']) . ' — ' . $quando . ' estava com: ';
    if ($r['com'] === 'comprador') {
        $tipo = $r['tipo_contrato'] === 'locacao' ? 'locação' : ($r['tipo_contrato'] === 'posse' ? 'transferência de posse' : 'venda');
        $dados = array_filter([
            $r['cpf'] ? 'CPF ' . $r['cpf'] : '', $r['cnh'] ? 'CNH ' . $r['cnh'] : '', $r['rg'] ? 'RG ' . $r['rg'] : '',
            $r['telefone'] ? 'tel ' . $r['telefone'] : '', $r['endereco'] ? $r['endereco'] : '',
        ]);
        $copiar = trim($r['nome'] . "\n" . implode("\n", $dados));
        return $cab . '<strong>' . e($r['nome'] ?: '—') . '</strong> (' . $tipo . ' <a href="/admin/venda.php?id=' . (int)$r['venda_id'] . '" target="_blank">#' . (int)$r['venda_id'] . '</a>, desde '
            . e(date('d/m/Y', strtotime($r['desde']))) . ($r['ate'] ? ' até ' . e(date('d/m/Y', strtotime($r['ate']))) : '') . ')'
            . ($dados ? ' · ' . e(implode(' · ', $dados)) : ' · <span style="color:var(--vermelho)">sem CPF/CNH cadastrados</span>')
            . ' <button type="button" class="btn-texto" data-copiar="' . e($copiar) . '" onclick="navigator.clipboard && navigator.clipboard.writeText(this.dataset.copiar).then(()=>{this.textContent=\'✅ copiado\'})">📋 copiar pra indicação</button>';
    }
    if ($r['com'] === 'antes_cadastro') {
        return $cab . '<strong>sem registro</strong> — essa data é anterior ao cadastro manual do veículo (' . e(date('d/m/Y', strtotime($r['ate'])))
            . ')' . ($r['nome'] !== '' ? ', provavelmente com <strong>' . e($r['nome']) . '</strong> (vendedor cadastrado)' : '')
            . ' · sem histórico de compra no sistema — <a href="/admin/oportunidade.php?id=' . (int)$r['oportunidade_id'] . '" target="_blank">ver cadastro do veículo #' . (int)$r['oportunidade_id'] . '</a>';
    }
    if ($r['com'] === 'vendedor_original') {
        return $cab . '<strong>' . e($r['nome'] ?: 'vendedor original') . '</strong> (antes de entregar pra Fastcar em ' . e(date('d/m/Y', strtotime($r['ate']))) . ') · <a href="/admin/oportunidade.php?id=' . (int)$r['oportunidade_id'] . '" target="_blank">compra #' . (int)$r['oportunidade_id'] . '</a>';
    }
    return $cab . '<strong>Fastcar</strong> (no estoque, sem venda ativa nessa data) · <a href="/admin/oportunidade.php?id=' . (int)$r['oportunidade_id'] . '" target="_blank">veículo #' . (int)$r['oportunidade_id'] . '</a>';
}

function ouvidoriaTicketSalvarPlaca(int $id, string $placa, string $data): array {
    $t = ouvidoriaBuscarTicket($id);
    if (!$t) return ['ok' => false, 'erro' => 'Ticket não encontrado.'];
    $placa = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $placa));
    if ($placa !== '' && !preg_match('/^[A-Z]{3}\d[A-Z0-9]\d{2}$/', $placa)) return ['ok' => false, 'erro' => 'Placa inválida.'];
    $dt = $data !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $data) ? $data : null;
    getDB()->prepare("UPDATE ouvidoria_tickets SET placa = ?, data_ref = ?, updated_at = datetime('now','localtime') WHERE id = ?")->execute([$placa, $dt, $id]);
    return ['ok' => true, 'ticket' => ouvidoriaBuscarTicket($id)];
}

/**
 * 20/11/2026, "coloca ia inbox pegar contexto sugerir a resposta" — a IA lê a
 * conversa, o ticket aberto e o contrato ligado e sugere a resposta. Nunca
 * envia: o texto vai pro campo de mensagem, a pessoa revisa e manda.
 * $rascunho: o que o atendente já digitou (a IA melhora em cima disso).
 */
function ouvidoriaSugerirResposta(string $telefone, string $rascunho = ''): array {
    $tel = normalizarTelefone($telefone) ?: $telefone;
    $db = getDB();
    $st = $db->prepare("SELECT direcao, mensagem, usuario_id, enviado_por_ia FROM whatsapp_mensagens
        WHERE telefone = ? AND canal = 'ouvidoria' ORDER BY id DESC LIMIT 20");
    $st->execute([$tel]);
    $msgs = array_reverse($st->fetchAll());
    if (!$msgs) return ['ok' => false, 'erro' => 'Ainda não tem mensagem nessa conversa.'];
    $temCliente = false;
    $linhas = [];
    foreach ($msgs as $m) {
        $txt = trim(preg_replace('/\s+/', ' ', (string)$m['mensagem']));
        if ($txt === '') continue;
        if ($m['direcao'] === 'in') $temCliente = true;
        $quem = $m['direcao'] === 'in' ? 'Cliente' : (!empty($m['usuario_id']) ? 'Fastcar (atendente)' : 'Fastcar (resposta automática)');
        $linhas[] = "{$quem}: " . mb_substr($txt, 0, 500);
    }
    if (!$temCliente) return ['ok' => false, 'erro' => 'O cliente ainda não escreveu nessa conversa.'];

    $conv = ouvidoriaConversa($tel);
    $ctx = [];
    if (($conv['nome'] ?? '') !== '') $ctx[] = 'Nome do cliente: ' . $conv['nome'];
    $ativos = ouvidoriaTicketsAtivos($tel);
    $assunto = '';
    if ($ativos) {
        $t = $ativos[0];
        $assunto = (string)$t['assunto'];
        $ctx[] = "Ticket aberto: protocolo {$t['protocolo']}, assunto " . (OUVIDORIA_ASSUNTOS[$t['assunto']] ?? $t['assunto'])
            . ', status ' . $t['status'] . (!empty($t['placa']) ? ", placa {$t['placa']}" : '')
            . (!empty($t['resumo']) ? '. Relato: ' . mb_substr((string)$t['resumo'], 0, 600) : '');
    }
    $neg = null;
    if (!empty($conv['venda_id'])) $neg = ouvidoriaResumoNegocio('venda', (int)$conv['venda_id']);
    elseif (!empty($conv['oportunidade_id'])) $neg = ouvidoriaResumoNegocio('compra', (int)$conv['oportunidade_id']);
    else {
        $n = ouvidoriaNegociosDoTelefone($tel);
        if ($n['vendas']) $neg = ouvidoriaResumoNegocio('venda', (int)$n['vendas'][0]['id']);
        elseif ($n['oportunidades']) $neg = ouvidoriaResumoNegocio('compra', (int)$n['oportunidades'][0]['id']);
    }
    if ($neg) {
        $veic = trim(($neg['marca'] ?? '') . ' ' . ($neg['modelo'] ?? '') . ' ' . ($neg['ano'] ?? '')) . (!empty($neg['placa']) ? " (placa {$neg['placa']})" : '');
        if ($neg['tipo'] === 'venda') {
            $f = $neg['fin'] ?? [];
            $ctx[] = "Cliente COMPROU um veículo da Fastcar (contrato de venda #{$neg['id']}, " . strip_tags((string)$neg['etapa_label']) . "): {$veic}."
                . ' Parcelas: pago R$ ' . number_format((float)($f['pago'] ?? 0), 2, ',', '.')
                . ', em atraso R$ ' . number_format((float)($f['atrasado'] ?? 0), 2, ',', '.')
                . ', a vencer R$ ' . number_format((float)($f['pendente'] ?? 0), 2, ',', '.')
                . (!empty($f['proximo_venc']) ? ', próximo vencimento ' . date('d/m/Y', strtotime((string)$f['proximo_venc'])) : '') . '.';
        } else {
            $ctx[] = "Cliente VENDEU o veículo pra Fastcar (negócio de compra #{$neg['id']}, " . strip_tags((string)$neg['etapa_label']) . "): {$veic}.";
            if ($assunto === 'quitacao' && ($pq = ouvidoriaPrazoQuitacaoTexto($tel, (int)$neg['id'])) !== '') $ctx[] = 'Prazo de quitação: ' . $pq;
        }
    } else {
        $ctx[] = 'Nenhum contrato ligado a este telefone no sistema.';
    }
    $ctx[] = 'Agora: ' . date('d/m/Y H:i') . (ouvidoriaDentroHorario() ? ' (dentro' : ' (fora') . ' do horário da ouvidoria, seg–sex 10h–19h).';

    $prompt = "Você ajuda a equipe da OUVIDORIA da Fastcar (empresa que compra veículos financiados e revende) a responder clientes no WhatsApp.\n"
        . "Escreva a PRÓXIMA mensagem que o atendente vai mandar, respondendo o que o cliente pediu por último, levando em conta TODA a conversa e o contexto abaixo.\n"
        . "Regras: português do Brasil, tom humano, educado e direto; no máximo 4 frases curtas; sem saudação longa se a conversa já está em andamento; "
        . "nunca invente valor, data, prazo, número de protocolo ou promessa que não esteja no contexto — se faltar a informação, diga que vai verificar e retorna; "
        . "nunca diga que é IA; não assine (a assinatura entra sozinha); sem markdown além de *negrito* do WhatsApp; nunca peça senha nem dado de cartão.\n"
        . "Responda SÓ com o texto da mensagem, sem aspas nem explicação.\n\n"
        . "CONTEXTO:\n- " . implode("\n- ", $ctx) . "\n\nCONVERSA (mais antiga primeiro):\n" . implode("\n", $linhas)
        . (trim($rascunho) !== '' ? "\n\nRASCUNHO DO ATENDENTE (use como base, mantenha a intenção e melhore o texto): " . mb_substr(trim($rascunho), 0, 800) : '');

    $texto = '';
    $geminiKey = getConfig('gemini_api_key') ?: '';
    if ($geminiKey) {
        $r = geminiCall($prompt, $geminiKey, getConfig('gemini_model') ?: 'gemini-3.5-flash-lite', 400, 0.4);
        if (is_string($r)) $texto = $r;
    }
    $openaiKey = getConfig('openai_api_key') ?: '';
    if ($texto === '' && $openaiKey) {
        $r = openaiCall($prompt, $openaiKey, getConfig('openai_model') ?: 'gpt-4o-mini', 400, 0.4);
        if (is_string($r)) $texto = $r;
    }
    $texto = trim(preg_replace('/^["“”\']+|["“”\']+$/u', '', trim($texto)));
    if ($texto === '') return ['ok' => false, 'erro' => 'A IA não respondeu agora (confira a chave em Configurações → IA) — tente de novo.'];
    return ['ok' => true, 'texto' => $texto];
}
