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

/** Assuntos que já entram como URGENTE (🚨 no ticket e aviso automático pro Jean). */
const OUVIDORIA_ASSUNTOS_URGENTES = ['apreensao'];

const OUVIDORIA_ASSUNTOS = [
    'pagamento' => '💰 Pagamento',
    'multa'     => '🚦 Multa de trânsito',
    'contrato'  => '📄 Dúvida sobre contrato',
    'distrato'  => '✂️ Distrato',
    'quitacao'  => '🏦 Quitação do financiamento',
    'apreensao' => '🚨 Veículo apreendido',
    'defeito'   => '🔧 Problema no veículo',
    'documentos' => '📑 Documentos / transferência',
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
    // 20/11/2026, "engloba tudo ai para facilitar atendimento nos ticket" —
    // ordem = prioridade quando a mensagem mistura assuntos ("quero desfazer,
    // carro com problema, carro foi preso" → apreensão, o mais urgente).
    if (preg_match('/\b((carro|moto|veiculo|caminhao)( foi| ta| esta| ficou)? (preso|presa|apreendid[oa]|guinchad[oa]|recolhid[oa])|apreend(eram|ido|ida|eu)|guinch(aram|ado|ada)|patio|deposito do detran|blitz|policia (pegou|levou|parou)|roubad[oa]|furtad[oa])/u', $t)) return 'apreensao';
    if (preg_match('/\b(distrato|rescis|rescindir|cancelar (o |meu |a )?(contrato|negocio|compra)|desistir|desisto|desist|desfaz|disfaz|desmanchar (o )?negocio|devolver (o |a )?(carro|moto|veiculo)|quero (meu dinheiro|o dinheiro) de volta|reembolso|estorno)/u', $t)) return 'distrato';
    // Vendedor que passou o carro pra Fastcar e o banco está cobrando/ligando
    // sobre a quitação. Antes de 'pagamento' ("quitar" ≠ "paguei").
    if (preg_match('/\b(banco (esta |ta |fica |vive )?(me )?(ligando|ligou|liga|cobrando|cobra|mandando)|quitar|quitacao|quitam|quitarao|vao quitar|saldo devedor|financiamento (ainda |esta |ta )?(em aberto|no meu nome|nao (foi )?quitado)|nao quitaram|venceu (o )?prazo|prazo (vencido|venceu|ja passou|estourou)|passou (do|o) prazo|notificacao extrajudicial|busca e apreensao|nome (sujo|negativado)|negativad)/u', $t)) return 'quitacao';
    if (preg_match('/\b(multa|multas|infracao|autuacao|notificacao de transito|notificacao de autuacao|notificacao de penalidade|radar|condutor|indicacao|indicar (o )?(motorista|condutor)|pontos na (cnh|carteira)|pontuacao)/u', $t)) return 'multa';
    if (preg_match('/\b(problema(s)? (no|com o|com a|na) (carro|moto|veiculo|motor|cambio|freio|suspensao)|carro com problema|(carro|moto|veiculo) com problema|defeito|quebr(ou|ado|ada)|nao (liga|pega|anda)|parou de funcionar|barulho|vazamento|vazando|superaquec|luz (de injecao|do painel|acesa)|motor|cambio|embreagem|oficina|mecanico|garantia|vicio oculto)/u', $t)) return 'defeito';
    if (preg_match('/\b(transferencia|transferir (o |pro )?(carro|veiculo|nome|documento)|documento do (carro|veiculo)|crlv|dut|crv|recibo|licenciamento|ipva|vistoria do detran|placa|segunda via|2a via)/u', $t)) return 'documentos';
    if (preg_match('/\b(paguei|pago|pagamento|comprovante|pix|boleto|transferi|depositei|quitei|parcela paga|parcela|atrasad[oa]|segunda via do boleto)/u', $t)) return 'pagamento';
    if (preg_match('/\b(contrato|clausula|assinatura|assinar)/u', $t)) return 'contrato';
    return null;
}

function _ouvidoriaPromptAssunto(): string {
    return "Você classifica mensagens que clientes da Fastcar (empresa que compra e revende veículos) mandam pra OUVIDORIA.\n"
        . "Responda SÓ um JSON: {\"assunto\":\"apreensao|distrato|quitacao|multa|defeito|documentos|pagamento|contrato|outro\"}.\n"
        . "Se a mensagem misturar assuntos, escolha o PRIMEIRO desta lista que se aplica (o mais urgente).\n"
        . "- apreensao: o veículo foi preso, apreendido, guinchado, levado pro pátio, roubado ou furtado.\n"
        . "- distrato: quer cancelar/desfazer o negócio, desistir, devolver o veículo, dinheiro de volta.\n"
        . "- quitacao: quem VENDEU o veículo pra Fastcar e o banco do financiamento está ligando/cobrando, pergunta quando a Fastcar vai quitar o financiamento, nome negativado, notificação do banco.\n"
        . "- multa: multa de trânsito, notificação de autuação/penalidade, indicação/identificação de condutor, pontos na CNH.\n"
        . "- defeito: problema mecânico/elétrico no veículo, defeito, quebrou, não liga, garantia, oficina.\n"
        . "- documentos: transferência, CRLV/documento do carro, licenciamento, IPVA, placa, recibo.\n"
        . "- pagamento: pagou parcela/entrada, manda comprovante, pergunta de boleto/pix/baixa de pagamento.\n"
        . "- contrato: dúvida sobre cláusulas, condições ou prazos do contrato.\n"
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
        . "contrato ou distrato = contrato/distrato; cobrança/notificação do BANCO do financiamento (saldo devedor, quitação, busca e apreensão) = quitacao; auto/termo de apreensão, remoção ou recolhimento do veículo, boletim de ocorrência de roubo = apreensao; foto/vídeo de defeito, orçamento de oficina = defeito; CRLV, recibo, documento de transferência, IPVA/licenciamento = documentos; qualquer outra coisa = outro. "
        . "E descreva em 1 frase curta o que é (se for comprovante, diga valor, data e quem pagou quando estiver visível; se for multa: placa, data e valor da infração e o prazo pra indicar o condutor, quando estiver escrito). "
        . "Nunca invente o que não está visível. Responda SÓ um JSON: {\"assunto\":\"apreensao|distrato|quitacao|multa|defeito|documentos|pagamento|contrato|outro\",\"descricao\":\"...\"}.";
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
    $GLOBALS['_ouvidoriaQuitacaoVencida'] = false;
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
        $fim = strtotime(date('Y-m-d', strtotime((string)$inicio)) . " +{$meses} months");
        $iniTxt = date('d/m/Y', strtotime((string)$inicio));
        if ($fim < strtotime(date('Y-m-d'))) {
            // Prazo já passou: nunca dá "previsão" no passado — avisa a equipe com prioridade.
            $GLOBALS['_ouvidoriaQuitacaoVencida'] = true;
            return "O prazo previsto no seu contrato ({$meses} meses a partir de {$iniTxt}, até " . date('m/Y', $fim) . ") já passou — já avisamos nossa equipe pra verificar a situação da quitação com prioridade.";
        }
        return "No seu contrato, o prazo é de até {$meses} meses a partir de {$iniTxt} (previsão: até " . date('m/Y', $fim) . ").";
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
            // 20/11/2026, "maioria indicação de multas ou condutor" — o foco é
            // indicar o condutor dentro do prazo que vem na notificação.
            return $temArquivo
                ? "{$oi}Recebemos a notificação ✅ Vamos providenciar a indicação do condutor responsável. Se tiver outras notificações, manda todas por aqui (frente e verso). {$horario}"
                : "{$oi}Sobre multa / indicação de condutor: manda por aqui a foto da notificação (frente e verso), a placa do veículo e a data em que você recebeu — com isso a gente providencia a indicação do condutor responsável. Importante: o prazo pra indicar vem escrito na notificação, por isso manda o quanto antes. {$horario}";
        case 'distrato':
            $end = trim((string)(getConfig('empresa_endereco') ?: ''));
            $presencial = $end !== ''
                ? "Se preferir, podemos te atender presencialmente na nossa sede ({$end}) — é só combinar o melhor dia."
                : 'Se preferir, podemos te atender presencialmente na nossa sede — é só combinar o melhor dia.';
            return "{$oi}Recebemos seu pedido sobre distrato. {$horarioFixo} {$presencial}"
                . ($dentroHorario ? ' Um atendente te responde por aqui em breve.' : ' Respondemos no próximo dia útil.');
        case 'apreensao':
            return "{$oi}Sentimos muito pelo transtorno. Já avisamos nossa equipe pra verificar com prioridade. "
                . "Pra agilizar, manda por aqui: a placa, onde o veículo está (pátio/delegacia), o motivo informado e a foto do termo de apreensão/remoção (ou do boletim de ocorrência, se foi roubo/furto). "
                . ($dentroHorario ? 'Um atendente te responde por aqui em breve.' : 'Nosso horário de atendimento da ouvidoria é de segunda a sexta, das 10h às 19h — vamos tratar logo no próximo dia útil.');
        case 'defeito':
            return $temArquivo
                ? "{$oi}Recebemos ✅ Se tiver mais fotos, vídeo ou orçamento da oficina, pode mandar por aqui. {$horario}"
                : "{$oi}Sentimos pelo problema no veículo. Pra gente analisar, manda por aqui: a placa, o que está acontecendo e desde quando, e fotos ou vídeo do problema (se já levou na oficina, o orçamento também ajuda). {$horario}";
        case 'documentos':
            return $temArquivo
                ? "{$oi}Recebemos o documento ✅ {$horario}"
                : "{$oi}Sobre documentos/transferência do veículo: manda por aqui a placa e o que você precisa (ou o que está pendente) que a gente verifica. {$horario}";
        case 'quitacao':
            $num = trim((string)(getConfig('ouvidoria_numero_publico') ?: ''));
            $numTxt = $num !== '' ? "o número da nossa ouvidoria: *{$num}*" : 'este mesmo número da nossa ouvidoria';
            $vencido = !empty($GLOBALS['_ouvidoriaQuitacaoVencida']);
            return ($vencido
                    ? "{$oi}Pelo contrato, a quitação do financiamento junto ao banco é responsabilidade da Fastcar."
                    : "{$oi}Fique tranquilo(a): pelo contrato, a quitação do financiamento junto ao banco é responsabilidade da Fastcar e é feita dentro do prazo combinado no contrato.")
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

/** Placa brasileira (antiga ABC1234 ou Mercosul ABC1D23) num texto, normalizada. */
function ouvidoriaExtrairPlaca(string $texto): string {
    if (preg_match('/\b([A-Z]{3})[\s\-]?(\d[A-Z0-9]\d{2})\b/u', mb_strtoupper($texto), $m)) return $m[1] . $m[2];
    return '';
}

/** 1ª data dd/mm/aaaa do texto (ex: descrição da IA da notificação de multa) → Y-m-d. */
function ouvidoriaExtrairData(string $texto): ?string {
    if (preg_match('/\b(\d{1,2})\/(\d{1,2})\/(\d{4})\b/', $texto, $m) && checkdate((int)$m[2], (int)$m[1], (int)$m[3])) {
        return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
    }
    return null;
}

/**
 * Com quem está (ou estava) o carro — 20/11/2026, "saber com quem tá carro no
 * tiket" / "maioria indicação de multas ou condutor". Pela placa acha o
 * veículo e, nas vendas/locações dele, quem tinha a posse na data informada
 * (ou hoje, sem data): início = data_venda, fim = quando a venda foi
 * cancelada/devolvida (venda_historico). Sem venda cobrindo a data → com a
 * Fastcar. Nunca chuta: placa que não bate com veículo nenhum → null.
 */
function ouvidoriaComQuemEstaCarro(string $placa, ?string $data = null): ?array {
    $placa = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $placa));
    if (strlen($placa) < 7) return null;
    $db = getDB();
    $st = $db->prepare("SELECT o.id, o.veiculo_marca, o.veiculo_modelo, o.veiculo_ano, o.veiculo_placa, o.data_entrega_posse, o.data_compra, c.nome AS vendedor_nome
        FROM oportunidades o LEFT JOIN clientes c ON c.id = o.cliente_id
        WHERE REPLACE(REPLACE(UPPER(COALESCE(o.veiculo_placa,'')),'-',''),' ','') = ?
        ORDER BY (o.etapa = 'fechado') DESC, o.id DESC LIMIT 1");
    $st->execute([$placa]);
    $o = $st->fetch();
    if (!$o) return null;
    $ref = $data ?: date('Y-m-d');
    $veiculo = trim(($o['veiculo_marca'] ?? '') . ' ' . ($o['veiculo_modelo'] ?? '') . ' ' . ($o['veiculo_ano'] ?? ''));
    $st = $db->prepare("SELECT v.*,
            (SELECT MIN(h.created_at) FROM venda_historico h WHERE h.venda_id = v.id AND h.etapa_nova = 'cancelada') AS cancelada_em
        FROM vendas v WHERE v.oportunidade_id = ? AND v.etapa IN ('vendido','cancelada') AND v.data_venda IS NOT NULL AND v.data_venda != ''
        ORDER BY v.data_venda DESC, v.id DESC");
    $st->execute([(int)$o['id']]);
    foreach ($st->fetchAll() as $v) {
        $ini = substr((string)$v['data_venda'], 0, 10);
        $fim = $v['etapa'] === 'cancelada' ? substr((string)($v['cancelada_em'] ?: $v['updated_at']), 0, 10) : null;
        if ($ini <= $ref && ($fim === null || $fim >= $ref)) {
            return [
                'com' => 'comprador', 'venda_id' => (int)$v['id'], 'oportunidade_id' => (int)$o['id'],
                'tipo_contrato' => (string)($v['tipo_contrato'] ?: 'venda'),
                'nome' => (string)$v['comprador_nome'], 'cpf' => (string)$v['comprador_cpf'], 'cnh' => (string)$v['comprador_cnh'],
                'rg' => (string)$v['comprador_rg'], 'endereco' => (string)$v['comprador_endereco'], 'telefone' => (string)$v['comprador_telefone'],
                'desde' => $ini, 'ate' => $fim, 'veiculo' => $veiculo, 'placa' => (string)$o['veiculo_placa'], 'data_ref' => $data,
            ];
        }
    }
    $entrada = substr((string)($o['data_entrega_posse'] ?: $o['data_compra']), 0, 10);
    if ($entrada !== '' && $ref < $entrada) {
        return ['com' => 'vendedor_original', 'venda_id' => null, 'oportunidade_id' => (int)$o['id'], 'nome' => (string)$o['vendedor_nome'],
                'cpf' => '', 'cnh' => '', 'rg' => '', 'endereco' => '', 'telefone' => '', 'desde' => null, 'ate' => $entrada,
                'veiculo' => $veiculo, 'placa' => (string)$o['veiculo_placa'], 'data_ref' => $data, 'tipo_contrato' => ''];
    }
    return ['com' => 'fastcar', 'venda_id' => null, 'oportunidade_id' => (int)$o['id'], 'nome' => 'Fastcar (frota)', 'cpf' => '', 'cnh' => '',
            'rg' => '', 'endereco' => '', 'telefone' => '', 'desde' => $entrada ?: null, 'ate' => null, 'veiculo' => $veiculo,
            'placa' => (string)$o['veiculo_placa'], 'data_ref' => $data, 'tipo_contrato' => ''];
}

/** Placa do ticket: a gravada nele, senão a do negócio vinculado. */
function ouvidoriaPlacaDoTicket(array $t): string {
    if (!empty($t['placa'])) return (string)$t['placa'];
    $db = getDB();
    if (!empty($t['venda_id'])) {
        $st = $db->prepare("SELECT o.veiculo_placa FROM vendas v JOIN oportunidades o ON o.id = v.oportunidade_id WHERE v.id = ?");
        $st->execute([(int)$t['venda_id']]);
        return (string)($st->fetchColumn() ?: '');
    }
    if (!empty($t['oportunidade_id'])) {
        $st = $db->prepare("SELECT veiculo_placa FROM oportunidades WHERE id = ?");
        $st->execute([(int)$t['oportunidade_id']]);
        return (string)($st->fetchColumn() ?: '');
    }
    return '';
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
    $extraResposta = $assunto === 'quitacao' ? ouvidoriaPrazoQuitacaoTexto($telNorm, !empty($conv['oportunidade_id']) ? (int)$conv['oportunidade_id'] : null) : '';
    // O relato do cliente nas mensagens seguintes entra no resumo do ticket (até 600 caracteres).
    if ($ticket && !$ticketNovo && $textoUtil !== '' && mb_strlen($textoUtil) >= 15) {
        try {
            $novoResumo = trim((string)$ticket['resumo']);
            $trecho = mb_substr(preg_replace('/\s+/', ' ', $textoUtil), 0, 300);
            if (mb_strlen($novoResumo) < 600 && !str_contains($novoResumo, $trecho)) {
                $novoResumo = mb_substr(($novoResumo !== '' ? $novoResumo . ' | ' : '') . $trecho, 0, 600);
                getDB()->prepare("UPDATE ouvidoria_tickets SET resumo = ? WHERE id = ?")->execute([$novoResumo, (int)$ticket['id']]);
                $ticket['resumo'] = $novoResumo;
            }
        } catch (Throwable $e) {}
    }
    // Placa / data da infração lidas da mensagem (ou da descrição da foto) — "com quem está o carro".
    if ($ticket) {
        try {
            $sets = []; $vals = [];
            if (empty($ticket['placa']) && ($pl = ouvidoriaExtrairPlaca($texto . ' ' . $textoUtil)) !== '') { $sets[] = 'placa = ?'; $vals[] = $pl; $ticket['placa'] = $pl; }
            if (empty($ticket['data_ref']) && $assunto === 'multa' && $tipoRegistro !== 'text' && ($dt = ouvidoriaExtrairData($texto))) { $sets[] = 'data_ref = ?'; $vals[] = $dt; $ticket['data_ref'] = $dt; }
            if ($sets) { $vals[] = (int)$ticket['id']; getDB()->prepare('UPDATE ouvidoria_tickets SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($vals); }
        } catch (Throwable $e) {}
    }
    $urgente = in_array($assunto, OUVIDORIA_ASSUNTOS_URGENTES, true) || ($assunto === 'quitacao' && !empty($GLOBALS['_ouvidoriaQuitacaoVencida']));
    ouvidoriaNotificarSuperAdmins($telNorm, $assunto, ($urgente ? '🚨 URGENTE — ' : ($ticketNovo ? '🆕 ' : '')) . $resumoNotif, (string)($ticket['protocolo'] ?? ''));
    if ($urgente && $ticket && empty($ticket['urgente'])) {
        try { getDB()->prepare("UPDATE ouvidoria_tickets SET urgente = 1 WHERE id = ?")->execute([(int)$ticket['id']]); } catch (Throwable $e) {}
    }
    // Assunto urgente (veículo apreendido, ou quitação com prazo do contrato vencido): o Jean recebe o resumo na hora, 1x por ticket.
    if ($urgente && $ticket && empty($ticket['notificado_em']) && function_exists('ouvidoriaNotificarJean')) {
        try { ouvidoriaNotificarJean((int)$ticket['id'], $assunto === 'quitacao' ? 'Automático: cliente cobrando quitação e o prazo do contrato JÁ VENCEU.' : 'Automático: assunto urgente (veículo apreendido).', 0); } catch (Throwable $e) {}
    }

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

    $resposta = ouvidoriaMontarResposta($assunto, $temArquivo, ouvidoriaDentroHorario(), (string)$conv['nome'], $extraResposta);
    if ($ticketNovo && !empty($ticket['protocolo'])) {
        // 20/11/2026, "sempre pedir descreva situação, essa pergunta chave
        // pra entendermos o ticket" — toda abertura de ticket pede o relato.
        $resposta .= "\n\n👉 Pra gente entender seu caso: *descreva em poucas palavras a sua situação* (o que aconteceu, desde quando e o que você precisa).";
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
