<?php
/**
 * Canal da OUVIDORIA (19/11/2026, "vamos criar ibox da ouvidoria abrir canal
 * só responder cliente ia verificar contexto"). Instância Evolution própria,
 * só super admin vê (admin/ouvidoria_inbox.php), mensagens gravadas com
 * canal='ouvidoria' — nunca misturam com compra/vendas/financeiro.
 *
 * Só RESPONDE quem escreveu — nunca manda mensagem sozinha pra ninguém. A IA
 * só identifica o ASSUNTO; o texto da resposta é fixo por assunto (nunca
 * inventa valor, prazo ou promessa — regra #3):
 *   - pagamento: confirma que recebeu o comprovante (ou pede pra mandar);
 *   - multa: pede pra encaminhar as notificações com as datas das multas;
 *   - contrato (dúvida): informa o horário da ouvidoria;
 *   - distrato: horário + oferece atendimento presencial na sede;
 *   - outro: confirma que recebeu + horário.
 * Ouvidoria atende seg–sex 10h–19h; fora disso (e no fim de semana) avisa
 * que responde no próximo dia útil. Quando alguém da equipe responde pela
 * caixa, a IA para de responder aquela conversa (ia_pausada).
 */

require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/security.php';
require_once dirname(__DIR__, 2) . '/includes/whatsapp_config.php';
require_once dirname(__DIR__, 2) . '/includes/whatsapp_evolution.php';
require_once dirname(__DIR__, 2) . '/includes/gemini.php';
require_once dirname(__DIR__, 2) . '/includes/openai.php';
require_once dirname(__DIR__, 2) . '/includes/notificacoes.php';
require_once __DIR__ . '/mensagens.php';

const OUVIDORIA_ASSUNTOS = [
    'pagamento' => '💰 Pagamento',
    'multa'     => '🚦 Multa de trânsito',
    'contrato'  => '📄 Dúvida sobre contrato',
    'distrato'  => '✂️ Distrato',
    'quitacao'  => '🏦 Quitação do financiamento',
    'outro'     => '💬 Outro assunto',
];

function ouvidoriaDentroHorario(?int $ts = null): bool {
    $ts ??= time();
    if ((int)date('N', $ts) > 5) return false;
    $hm = date('H:i', $ts);
    return $hm >= '10:00' && $hm < '19:00';
}

function ouvidoriaConversa(string $telefone): array {
    $st = getDB()->prepare('SELECT * FROM ouvidoria_conversas WHERE telefone = ?');
    $st->execute([normalizarTelefone($telefone) ?: $telefone]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: ['telefone' => $telefone, 'nome' => '', 'assunto' => '', 'ia_pausada' => 0, 'ultima_auto_assunto' => '', 'ultima_auto_em' => null, 'oportunidade_id' => null, 'venda_id' => null];
}

function ouvidoriaSalvarConversa(string $telefone, array $campos): void {
    $tel = normalizarTelefone($telefone) ?: $telefone;
    $db = getDB();
    $db->prepare("INSERT OR IGNORE INTO ouvidoria_conversas (telefone) VALUES (?)")->execute([$tel]);
    $sets = [];
    $vals = [];
    foreach ($campos as $k => $v) {
        if (!in_array($k, ['nome', 'assunto', 'ia_pausada', 'ultima_auto_assunto', 'ultima_auto_em', 'oportunidade_id', 'venda_id'], true)) continue;
        $sets[] = "{$k} = ?";
        $vals[] = $v;
    }
    if (!$sets) return;
    $vals[] = $tel;
    dbComRetry(fn() => $db->prepare('UPDATE ouvidoria_conversas SET ' . implode(', ', $sets) . ", updated_at = datetime('now','localtime') WHERE telefone = ?")->execute($vals));
}

/** Palavras-chave (sem IA) — decide sozinho os casos claros. */
function ouvidoriaClassificarPorPalavra(string $texto): ?string {
    $t = mb_strtolower(strtr($texto, ['á'=>'a','à'=>'a','ã'=>'a','â'=>'a','é'=>'e','ê'=>'e','í'=>'i','ó'=>'o','ô'=>'o','õ'=>'o','ú'=>'u','ç'=>'c','Á'=>'a','É'=>'e','Í'=>'i','Ó'=>'o','Ú'=>'u','Ç'=>'c']));
    if (preg_match('/\b(distrato|rescis|rescindir|cancelar (o |meu )?contrato|desistir|desisto|devolver (o |a )?(carro|moto|veiculo))/u', $t)) return 'distrato';
    // 20/11/2026 — vendedor que passou o carro pra Fastcar e o banco está
    // cobrando/ligando sobre a quitação. Antes de 'pagamento' ("quitar" ≠ "paguei").
    if (preg_match('/\b(banco (esta |ta |fica |vive )?(me )?(ligando|ligou|liga|cobrando|cobra|mandando)|quitar|quitacao|quitam|quitarao|vao quitar|saldo devedor|financiamento (ainda |esta |ta )?(em aberto|no meu nome|nao (foi )?quitado)|nao quitaram|notificacao extrajudicial|busca e apreensao|nome (sujo|negativado)|negativad)/u', $t)) return 'quitacao';
    if (preg_match('/\b(multa|multas|infracao|autuacao|notificacao de transito|detran|radar|condutor)/u', $t)) return 'multa';
    if (preg_match('/\b(paguei|pago|pagamento|comprovante|pix|boleto|transferi|depositei|quitei|parcela paga)/u', $t)) return 'pagamento';
    if (preg_match('/\b(contrato|clausula|assinatura|assinar)/u', $t)) return 'contrato';
    return null;
}

function _ouvidoriaPromptAssunto(): string {
    return "Você classifica mensagens que clientes da Fastcar (empresa que compra e revende veículos) mandam pra OUVIDORIA.\n"
        . "Responda SÓ um JSON: {\"assunto\":\"pagamento|multa|contrato|distrato|quitacao|outro\"}.\n"
        . "- pagamento: pagou parcela/entrada, manda comprovante, pergunta de boleto/pix/baixa de pagamento.\n"
        . "- multa: multa de trânsito, notificação de infração, identificação de condutor.\n"
        . "- distrato: quer cancelar/encerrar o contrato, desistir, devolver o veículo.\n"
        . "- quitacao: quem VENDEU o veículo pra Fastcar e o banco do financiamento está ligando/cobrando, pergunta quando a Fastcar vai quitar o financiamento, nome negativado, notificação do banco.\n"
        . "- contrato: dúvida sobre cláusulas, condições, prazos ou documentos do contrato.\n"
        . "- outro: qualquer outra coisa.\n";
}

function _ouvidoriaExtrairAssuntoJson(string $resposta): ?string {
    if (preg_match('/"assunto"\s*:\s*"([a-z]+)"/', $resposta, $mm) && isset(OUVIDORIA_ASSUNTOS[$mm[1]])) return $mm[1];
    return null;
}

/** IA (Gemini → GPT) pra texto que as palavras não resolveram. */
function ouvidoriaClassificarTextoIA(string $texto): ?string {
    $prompt = _ouvidoriaPromptAssunto() . "\nMensagem do cliente: \"" . mb_substr($texto, 0, 800) . "\"";
    $geminiKey = getConfig('gemini_api_key') ?: '';
    if ($geminiKey) {
        $r = geminiCall($prompt, $geminiKey, getConfig('gemini_model') ?: 'gemini-3.5-flash-lite', 60, 0.0);
        if (is_string($r) && ($a = _ouvidoriaExtrairAssuntoJson($r))) return $a;
    }
    $openaiKey = getConfig('openai_api_key') ?: '';
    if ($openaiKey) {
        $r = openaiCall($prompt, $openaiKey, getConfig('openai_model') ?: 'gpt-4o-mini', 60, 0.0);
        if (is_string($r) && ($a = _ouvidoriaExtrairAssuntoJson($r))) return $a;
    }
    return null;
}

/**
 * Foto/PDF/vídeo: a IA olha o arquivo, diz o assunto e descreve em 1 frase
 * (vai pro histórico da conversa, igual ao inbox de compra). Sem chave Gemini
 * → null (a mídia continua salva e visível, só sem descrição).
 */
function ouvidoriaAnalisarMidiaIA(string $bytes, string $mime): ?array {
    $geminiKey = getConfig('gemini_api_key') ?: '';
    if (!$geminiKey || $bytes === '' || !preg_match('#^(image/|video/|application/pdf)#', $mime)) return null;
    $prompt = "Esse arquivo foi mandado por um cliente pra ouvidoria de uma empresa que compra e revende veículos. "
        . "Diga o assunto: comprovante de pagamento (pix, boleto, transferência) = pagamento; notificação de multa/infração de trânsito = multa; "
        . "contrato ou distrato = contrato/distrato; cobrança/notificação do BANCO do financiamento (saldo devedor, quitação, busca e apreensão) = quitacao; qualquer outra coisa = outro. "
        . "E descreva em 1 frase curta o que é (se for comprovante, diga valor, data e quem pagou quando estiver visível; se for multa, data e valor da infração). "
        . "Nunca invente o que não está visível. Responda SÓ um JSON: {\"assunto\":\"pagamento|multa|contrato|distrato|quitacao|outro\",\"descricao\":\"...\"}.";
    $r = geminiCallComMidia($prompt, $mime, base64_encode($bytes), $geminiKey, getConfig('gemini_model') ?: 'gemini-3.5-flash-lite', 300, 0.0);
    if ($r === '') return null;
    $desc = '';
    if (preg_match('/"descricao"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"/u', $r, $mm)) $desc = trim((string)json_decode('"' . $mm[1] . '"'));
    return ['assunto' => _ouvidoriaExtrairAssuntoJson($r), 'descricao' => $desc];
}

/** Texto fixo da resposta (nunca gerado pela IA). */
/**
 * Prazo de quitação do contrato de COMPRA ligado ao telefone (20/11/2026,
 * "banco está me ligando quando vão quitar financiamento"). Só devolve texto
 * quando o contrato tem prazo E data de início — nunca chuta (regra #3).
 */
function ouvidoriaPrazoQuitacaoTexto(string $telefone, ?int $oportunidadeId = null): string {
    try {
        $db = getDB();
        if (!$oportunidadeId) {
            $st = $db->prepare("SELECT o.id FROM oportunidades o JOIN clientes c ON c.id = o.cliente_id
                WHERE c.telefone = ? AND o.etapa = 'fechado' ORDER BY o.id DESC LIMIT 1");
            $st->execute([$telefone]);
            $oportunidadeId = (int)($st->fetchColumn() ?: 0) ?: null;
        }
        if (!$oportunidadeId) return '';
        $st = $db->prepare("SELECT o.prazo_quitacao_meses, o.data_entrega_posse, o.data_compra,
                (SELECT MIN(ct.assinado_em) FROM contratos ct WHERE ct.oportunidade_id = o.id AND ct.tipo = 'compra' AND ct.status = 'assinado') AS assinado_em
            FROM oportunidades o WHERE o.id = ? AND o.etapa = 'fechado'");
        $st->execute([$oportunidadeId]);
        $o = $st->fetch();
        if (!$o || (int)$o['prazo_quitacao_meses'] <= 0) return '';
        $inicio = $o['data_entrega_posse'] ?: ($o['assinado_em'] ?: $o['data_compra']);
        if (!$inicio || !strtotime((string)$inicio)) return '';
        $meses = (int)$o['prazo_quitacao_meses'];
        $ate = date('m/Y', strtotime(date('Y-m-d', strtotime((string)$inicio)) . " +{$meses} months"));
        return "No seu contrato, o prazo é de até {$meses} meses a partir de " . date('d/m/Y', strtotime((string)$inicio)) . " (previsão: até {$ate}).";
    } catch (Throwable $e) {
        return '';
    }
}

function ouvidoriaMontarResposta(string $assunto, bool $temArquivo, bool $dentroHorario, string $nome = '', string $extra = ''): string {
    $oi = $nome !== '' ? "Olá, {$nome}! " : 'Olá! ';
    $horario = $dentroHorario
        ? 'Um atendente da ouvidoria te responde por aqui em breve.'
        : 'Nosso horário de atendimento da ouvidoria é de segunda a sexta, das 10h às 19h — respondemos no próximo dia útil.';
    $horarioFixo = 'A ouvidoria atende de segunda a sexta, das 10h às 19h.';
    switch ($assunto) {
        case 'pagamento':
            return $temArquivo
                ? "{$oi}Recebemos seu comprovante de pagamento ✅ Obrigado! Nosso financeiro vai conferir e dar baixa. {$horario}"
                : "{$oi}Sobre pagamento: pode mandar o comprovante por aqui (foto ou PDF) que a gente registra. {$horario}";
        case 'multa':
            return $temArquivo
                ? "{$oi}Recebemos a notificação ✅ Se tiver outras, pode mandar todas por aqui, com a data de cada multa. {$horario}"
                : "{$oi}Sobre multas de trânsito: encaminhe por aqui as notificações que você recebeu (foto ou PDF), com a data de cada multa. {$horario}";
        case 'distrato':
            $end = trim((string)(getConfig('empresa_endereco') ?: ''));
            $presencial = $end !== ''
                ? "Se preferir, podemos te atender presencialmente na nossa sede ({$end}) — é só combinar o melhor dia."
                : 'Se preferir, podemos te atender presencialmente na nossa sede — é só combinar o melhor dia.';
            return "{$oi}Recebemos seu pedido sobre distrato. {$horarioFixo} {$presencial}"
                . ($dentroHorario ? ' Um atendente te responde por aqui em breve.' : ' Respondemos no próximo dia útil.');
        case 'quitacao':
            $num = trim((string)(getConfig('ouvidoria_numero_publico') ?: ''));
            $numTxt = $num !== '' ? "o número da nossa ouvidoria: *{$num}*" : 'este mesmo número da nossa ouvidoria';
            return "{$oi}Fique tranquilo(a): pelo contrato, a quitação do financiamento junto ao banco é responsabilidade da Fastcar e é feita dentro do prazo combinado no contrato."
                . ($extra !== '' ? " {$extra}" : '')
                . " Se o banco te ligar, pode passar {$numTxt} — a gente atende e explica a situação pra eles. "
                . "Se chegar alguma notificação por escrito do banco, manda a foto por aqui. {$horario}";
        case 'contrato':
            return "{$oi}Recebemos sua dúvida sobre o contrato. {$horarioFixo} "
                . ($dentroHorario ? 'Um atendente te responde por aqui em breve.' : 'Respondemos no próximo dia útil.');
        default:
            return "{$oi}Recebemos sua mensagem na ouvidoria da Fastcar ✅ {$horario}";
    }
}

/* ---------------------------------------------------------------------------
 * TICKETS (20/11/2026, "será da para salvar esses tiket") — 1 ticket por
 * assunto: assunto novo = ticket novo; mesmo assunto continua no ticket
 * aberto; depois de resolvido, mensagem nova abre outro. Protocolo
 * OUV-AAAA-NNNNN vai pro cliente na 1ª resposta do ticket.
 * ------------------------------------------------------------------------- */

const OUVIDORIA_TICKET_STATUS = [
    'aberto'         => '🆕 Aberto',
    'em_atendimento' => '💬 Em atendimento',
    'resolvido'      => '✅ Resolvido',
];

function ouvidoriaTicketsAtivos(string $telefone): array {
    $st = getDB()->prepare("SELECT * FROM ouvidoria_tickets WHERE telefone = ? AND status != 'resolvido' ORDER BY id DESC");
    $st->execute([$telefone]);
    return $st->fetchAll();
}

function ouvidoriaBuscarTicket(int $id): ?array {
    $st = getDB()->prepare("SELECT * FROM ouvidoria_tickets WHERE id = ?");
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/**
 * Acha o ticket certo pra esta mensagem ou abre um novo.
 * Retorna [ticket, novo(bool)]. Um ticket 'outro' aberto vira o assunto real
 * quando ele aparece (evita 2 tickets pra mesma conversa: "oi" + "paguei").
 */
function ouvidoriaTicketParaMensagem(string $telefone, string $assunto, string $nome, array $conv, string $resumo): array {
    $db = getDB();
    $ativos = ouvidoriaTicketsAtivos($telefone);
    foreach ($ativos as $t) {
        if ($t['assunto'] === $assunto) return [$t, false];
    }
    if ($assunto === 'outro' && $ativos) return [$ativos[0], false];
    foreach ($ativos as $t) {
        if ($t['assunto'] === 'outro') {
            $db->prepare("UPDATE ouvidoria_tickets SET assunto = ?, updated_at = datetime('now','localtime') WHERE id = ?")
               ->execute([$assunto, $t['id']]);
            $t['assunto'] = $assunto;
            return [$t, false];
        }
    }
    $db->prepare("INSERT INTO ouvidoria_tickets (telefone, nome, assunto, resumo, venda_id, oportunidade_id) VALUES (?, ?, ?, ?, ?, ?)")
       ->execute([$telefone, $nome, $assunto, mb_substr($resumo, 0, 300),
                  !empty($conv['venda_id']) ? (int)$conv['venda_id'] : null,
                  !empty($conv['oportunidade_id']) ? (int)$conv['oportunidade_id'] : null]);
    $id = (int)$db->lastInsertId();
    $protocolo = 'OUV-' . date('Y') . '-' . str_pad((string)$id, 5, '0', STR_PAD_LEFT);
    $db->prepare("UPDATE ouvidoria_tickets SET protocolo = ? WHERE id = ?")->execute([$protocolo, $id]);
    return [ouvidoriaBuscarTicket($id), true];
}

/** Humano respondeu: tickets abertos da conversa passam pra "em atendimento". */
function ouvidoriaTicketsMarcarAtendimento(string $telefone): void {
    getDB()->prepare("UPDATE ouvidoria_tickets SET status = 'em_atendimento',
            primeira_resposta_em = COALESCE(primeira_resposta_em, datetime('now','localtime')),
            updated_at = datetime('now','localtime')
        WHERE telefone = ? AND status = 'aberto'")->execute([$telefone]);
}

function ouvidoriaTicketMudarStatus(int $id, string $status, int $usuarioId, string $resolucao = ''): array {
    if (!isset(OUVIDORIA_TICKET_STATUS[$status])) return ['ok' => false, 'erro' => 'Status inválido.'];
    $t = ouvidoriaBuscarTicket($id);
    if (!$t) return ['ok' => false, 'erro' => 'Ticket não encontrado.'];
    $resolucao = trim($resolucao);
    if ($status === 'resolvido' && $resolucao === '') return ['ok' => false, 'erro' => 'Escreva como foi resolvido.'];
    $db = getDB();
    if ($status === 'resolvido') {
        $db->prepare("UPDATE ouvidoria_tickets SET status = 'resolvido', resolucao = ?, resolvido_por = ?,
                resolvido_em = datetime('now','localtime'),
                primeira_resposta_em = COALESCE(primeira_resposta_em, datetime('now','localtime')),
                updated_at = datetime('now','localtime') WHERE id = ?")->execute([$resolucao, $usuarioId, $id]);
    } else {
        // Reabrir limpa a resolução anterior.
        $db->prepare("UPDATE ouvidoria_tickets SET status = ?, resolvido_em = NULL, resolvido_por = NULL,
                resolucao = CASE WHEN ? = 'em_atendimento' AND status = 'resolvido' THEN '' ELSE resolucao END,
                updated_at = datetime('now','localtime') WHERE id = ?")->execute([$status, $status, $id]);
    }
    return ['ok' => true, 'ticket' => ouvidoriaBuscarTicket($id)];
}

/** Avisa os super admins no sino (único perfil que vê este canal). */
function ouvidoriaNotificarSuperAdmins(string $telefone, string $assunto, string $resumo, string $protocolo = ''): void {
    try {
        $ids = getDB()->query("SELECT id FROM usuarios WHERE perfil = 'super_admin' AND bloqueado = 0")->fetchAll(PDO::FETCH_COLUMN);
        $rot = OUVIDORIA_ASSUNTOS[$assunto] ?? OUVIDORIA_ASSUNTOS['outro'];
        foreach ($ids as $id) {
            criarNotificacao((int)$id, 'ouvidoria', "📣 Ouvidoria — {$rot}" . ($protocolo !== '' ? " ({$protocolo})" : ''), mb_substr($resumo, 0, 160), '/admin/ouvidoria_inbox.php?telefone=' . rawurlencode($telefone));
        }
    } catch (Throwable $e) {
        // best-effort: notificação nunca derruba o webhook
    }
}

function processarMensagemOuvidoria(array $payload, ?array $instancia = null): array {
    whatsappCanalRegistro('ouvidoria');
    $vazio = ['ignored' => null, 'telefone' => '', 'texto' => null, 'tipo' => null, 'ia_pausada' => false, 'instancia' => $instancia];

    $messageId = (string)($payload['messageId'] ?? $payload['id'] ?? '');
    $phone     = (string)($payload['phone'] ?? '');
    $fromMe    = !empty($payload['fromMe']);
    $isGroup   = !empty($payload['isGroup']) || str_contains($phone, '-group');

    if (!$phone) return ['ignored' => 'no_phone'] + $vazio;
    if ($isGroup) return ['ignored' => 'group', 'telefone' => $phone] + $vazio;
    if ($messageId && jaProcessado($messageId)) return ['ignored' => 'duplicate', 'telefone' => $phone] + $vazio;
    if ($fromMe) {
        registrarMensagem($phone, 'out', extrairTexto($payload) ?? '[' . tipoMidia($payload) . ']', $messageId ?: null, false, 'text', null);
        return ['ignored' => 'from_me', 'telefone' => $phone] + $vazio;
    }

    $telNorm = normalizarTelefone($phone) ?: $phone;
    $nomeContato = trim((string)($payload['senderName'] ?? $payload['chatName'] ?? ''));
    $texto = extrairTexto($payload);
    $tipoRegistro = 'text';
    $bytes = null;
    $mime = '';
    $textoFalado = '';
    $assuntoArquivo = null;
    $legenda = '';
    if ($texto === null) {
        $tipoBruto = tipoMidia($payload);
        if ($tipoBruto === 'desconhecido') return ['ignored' => 'not_a_message', 'telefone' => $phone] + $vazio;
        $tipoRegistro = $tipoBruto;
        $legenda = '';
        foreach (['image', 'document', 'video'] as $bk) {
            if (!empty($payload[$bk]['caption'])) $legenda = trim((string)$payload[$bk]['caption']);
        }
        $texto = $legenda !== '' ? $legenda : '[' . $tipoRegistro . ']';
        if (in_array($tipoRegistro, ['image', 'document', 'audio', 'video'], true) && $messageId !== '') {
            $m = evolutionBaixarMidiaRecebida($messageId, $tipoRegistro, evolutionCredenciaisOuvidoria());
            if (is_array($m) && !empty($m['bytes'])) {
                $bytes = (string)$m['bytes'];
                $mime = (string)($m['mime'] ?? '');
            }
        }
        // Ouvir/ver (20/11/2026, "ouvir audio imagen"): áudio vira transcrição;
        // foto/PDF/vídeo ganham assunto + descrição da IA. A mídia em si fica salva.
        if ($bytes !== null && $tipoRegistro === 'audio') {
            $transcricao = trim(descreverMidiaComGemini($bytes, $mime ?: 'audio/ogg', 'audio'));
            if ($transcricao !== '') {
                $texto = '🎤 ' . $transcricao;
                $textoFalado = $transcricao;
            }
        } elseif ($bytes !== null && in_array($tipoRegistro, ['image', 'document', 'video'], true)) {
            $analise = ouvidoriaAnalisarMidiaIA($bytes, $mime);
            if ($analise) {
                $assuntoArquivo = $analise['assunto'];
                if ($analise['descricao'] !== '') {
                    $pref = ['image' => '📷 ', 'document' => '📄 ', 'video' => '🎥 '][$tipoRegistro];
                    $texto = $pref . $analise['descricao'] . ($legenda !== '' ? "\n" . $legenda : '');
                }
            }
        }
    }

    $msgId = registrarMensagem($phone, 'in', $texto, $messageId ?: null, false, $tipoRegistro, null);
    if ($bytes !== null && $msgId > 0) {
        $st = getDB()->prepare('SELECT id, nome FROM clientes WHERE telefone = ?');
        $st->execute([$telNorm]);
        $cli = $st->fetch() ?: ['id' => 0, 'nome' => ''];
        salvarMidiaWhatsappRecebida($msgId, $bytes, $mime ?: 'application/octet-stream', $tipoRegistro, (int)$cli['id'], (string)$cli['nome']);
    }

    $conv = ouvidoriaConversa($telNorm);
    if ($nomeContato !== '' && nomeWhatsappPareceValido($nomeContato) && $conv['nome'] === '') {
        ouvidoriaSalvarConversa($telNorm, ['nome' => $nomeContato]);
        $conv['nome'] = $nomeContato;
    }

    // Assunto: palavra-chave → IA (texto) → IA (arquivo) → assunto já da conversa → outro.
    $temArquivo = in_array($tipoRegistro, ['image', 'document'], true);
    // Texto que o cliente escreveu (ou falou no áudio, ou a legenda) — nunca a descrição da IA.
    $textoUtil = $tipoRegistro === 'text' ? $texto : ($textoFalado !== '' ? $textoFalado : $legenda);
    $assunto = $textoUtil !== '' ? ouvidoriaClassificarPorPalavra($textoUtil) : null;
    if (!$assunto && $textoUtil !== '') $assunto = ouvidoriaClassificarTextoIA($textoUtil);
    if ((!$assunto || $assunto === 'outro') && $assuntoArquivo && $assuntoArquivo !== 'outro') $assunto = $assuntoArquivo;
    if ((!$assunto || $assunto === 'outro') && $conv['assunto'] !== '' && $conv['assunto'] !== 'outro') $assunto = $conv['assunto'];
    $assunto = $assunto ?: 'outro';
    if ($assunto !== 'outro' || $conv['assunto'] === '') ouvidoriaSalvarConversa($telNorm, ['assunto' => $assunto]);

    $resumoNotif = ($conv['nome'] ?: $telNorm) . ': ' . ($textoUtil !== '' ? $textoUtil : (str_starts_with($texto, '[') ? 'mandou ' . $tipoRegistro : $texto));
    $ticket = null; $ticketNovo = false;
    try {
        [$ticket, $ticketNovo] = ouvidoriaTicketParaMensagem($telNorm, $assunto, (string)$conv['nome'], $conv, $resumoNotif);
    } catch (Throwable $e) {
        // best-effort: ticket nunca derruba o webhook
    }
    ouvidoriaNotificarSuperAdmins($telNorm, $assunto, ($ticketNovo ? '🆕 ' : '') . $resumoNotif, (string)($ticket['protocolo'] ?? ''));

    if (!empty($conv['ia_pausada'])) {
        return ['ignored' => null, 'telefone' => $phone, 'texto' => $texto, 'tipo' => $tipoRegistro, 'ia_pausada' => true, 'instancia' => $instancia];
    }

    // Nunca repete a mesma resposta: mesmo assunto em até 12h só responde de
    // novo quando chega um arquivo (comprovante/notificação) — e aí no máximo
    // 1 vez a cada 10 min (o cliente manda várias fotos seguidas).
    $chaveResposta = $assunto . ($temArquivo ? ':arquivo' : '');
    $ultimaEm = $conv['ultima_auto_em'] ? strtotime((string)$conv['ultima_auto_em']) : 0;
    $ultimaChave = (string)$conv['ultima_auto_assunto'];
    if ($temArquivo) {
        $responde = $ultimaChave !== $chaveResposta || (time() - $ultimaEm) > 600;
    } else {
        // Texto do mesmo assunto (inclusive depois de já ter confirmado o arquivo) não repete em 12h.
        $responde = explode(':', $ultimaChave)[0] !== $assunto || (time() - $ultimaEm) > 12 * 3600;
    }
    if ($ticketNovo) $responde = true; // ticket novo sempre recebe o protocolo
    if (!$responde) {
        return ['ignored' => null, 'telefone' => $phone, 'texto' => $texto, 'tipo' => $tipoRegistro, 'ia_pausada' => false, 'instancia' => $instancia, 'assunto' => $assunto, 'respondeu' => false];
    }

    $extraResposta = $assunto === 'quitacao' ? ouvidoriaPrazoQuitacaoTexto($telNorm, !empty($conv['oportunidade_id']) ? (int)$conv['oportunidade_id'] : null) : '';
    $resposta = ouvidoriaMontarResposta($assunto, $temArquivo, ouvidoriaDentroHorario(), (string)$conv['nome'], $extraResposta);
    if ($ticketNovo && !empty($ticket['protocolo'])) {
        $resposta .= "\n\nSeu protocolo de atendimento é *{$ticket['protocolo']}*.";
    }
    $enviou = evolutionEnviarTexto($telNorm, $resposta, evolutionCredenciaisOuvidoria());
    if ($enviou) {
        registrarMensagem($telNorm, 'out', $resposta, null, true, 'text', null);
        ouvidoriaSalvarConversa($telNorm, ['ultima_auto_assunto' => $chaveResposta, 'ultima_auto_em' => date('Y-m-d H:i:s')]);
    }

    return [
        'ignored' => null, 'telefone' => $phone, 'texto' => $texto, 'tipo' => $tipoRegistro,
        'ia_pausada' => false, 'instancia' => $instancia, 'assunto' => $assunto, 'respondeu' => $enviou,
        'protocolo' => $ticket['protocolo'] ?? null, 'ticket_novo' => $ticketNovo,
    ];
}
