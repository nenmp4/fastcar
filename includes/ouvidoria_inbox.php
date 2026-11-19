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
    try { ouvidoriaTicketsMarcarAtendimento($tel); } catch (Throwable $e) {}
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
            ORDER BY CASE t.status WHEN 'aberto' THEN 0 WHEN 'em_atendimento' THEN 1 ELSE 2 END, t.aberto_em DESC
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
    if (isset(OUVIDORIA_TICKET_STATUS[$status])) { $where .= ' AND t.status = ?'; $params[] = $status; }
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
            SUM(status = 'aberto' AND primeira_resposta_em IS NULL AND aberto_em <= datetime('now','localtime','-1 day')) AS sem_resposta_24h
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
        $msgs = evolutionBuscarMensagensConversa($tel, $cred);
        if ($msgs === null) { $falhas++; continue; }
        $novas = 0; $nome = $c['nome']; $textosCliente = [];
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
    $u = buscarUsuario($usuarioId);
    $quem = trim((string)($u['nome'] ?? 'Equipe'));
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
            criarNotificacao((int)$jean['id'], 'ouvidoria', "🎫 {$quem} te mandou o ticket {$t['protocolo']}", mb_substr($nota !== '' ? $nota : (string)$t['resumo'], 0, 160), '/admin/ouvidoria_inbox.php?telefone=' . rawurlencode((string)$t['telefone']));
            $sinoOk = true;
        } catch (Throwable $e) {}
    }
    if (!$wppOk && !$emailOk && !$sinoOk) {
        return ['ok' => false, 'erro' => $jean ? 'Não consegui avisar o Jean (WhatsApp e e-mail falharam).' : 'Nenhum usuário "Jean" ativo nem número de notificação configurado.'];
    }
    getDB()->prepare("UPDATE ouvidoria_tickets SET notificado_em = datetime('now','localtime'), notificado_por = ?, updated_at = datetime('now','localtime') WHERE id = ?")
        ->execute([$usuarioId, $ticketId]);
    $canais = array_keys(array_filter(['WhatsApp' => $wppOk, 'e-mail' => $emailOk, 'sino' => $sinoOk]));
    return ['ok' => true, 'canais' => $canais, 'protocolo' => $t['protocolo']];
}
