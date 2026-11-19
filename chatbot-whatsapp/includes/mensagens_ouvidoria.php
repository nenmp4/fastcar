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
    return $st->fetch(PDO::FETCH_ASSOC) ?: ['telefone' => $telefone, 'nome' => '', 'assunto' => '', 'ia_pausada' => 0, 'ultima_auto_assunto' => '', 'ultima_auto_em' => null];
}

function ouvidoriaSalvarConversa(string $telefone, array $campos): void {
    $tel = normalizarTelefone($telefone) ?: $telefone;
    $db = getDB();
    $db->prepare("INSERT OR IGNORE INTO ouvidoria_conversas (telefone) VALUES (?)")->execute([$tel]);
    $sets = [];
    $vals = [];
    foreach ($campos as $k => $v) {
        if (!in_array($k, ['nome', 'assunto', 'ia_pausada', 'ultima_auto_assunto', 'ultima_auto_em'], true)) continue;
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
    if (preg_match('/\b(multa|multas|infracao|autuacao|notificacao de transito|detran|radar|condutor)/u', $t)) return 'multa';
    if (preg_match('/\b(paguei|pago|pagamento|comprovante|pix|boleto|transferi|depositei|quitei|parcela paga)/u', $t)) return 'pagamento';
    if (preg_match('/\b(contrato|clausula|assinatura|assinar)/u', $t)) return 'contrato';
    return null;
}

function _ouvidoriaPromptAssunto(): string {
    return "Você classifica mensagens que clientes da Fastcar (empresa que compra e revende veículos) mandam pra OUVIDORIA.\n"
        . "Responda SÓ um JSON: {\"assunto\":\"pagamento|multa|contrato|distrato|outro\"}.\n"
        . "- pagamento: pagou parcela/entrada, manda comprovante, pergunta de boleto/pix/baixa de pagamento.\n"
        . "- multa: multa de trânsito, notificação de infração, identificação de condutor.\n"
        . "- distrato: quer cancelar/encerrar o contrato, desistir, devolver o veículo.\n"
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

/** Foto/PDF sem texto: comprovante de pagamento ou notificação de multa? */
function ouvidoriaClassificarMidiaIA(string $bytes, string $mime): ?string {
    $geminiKey = getConfig('gemini_api_key') ?: '';
    if (!$geminiKey || $bytes === '' || !preg_match('#^(image/|application/pdf)#', $mime)) return null;
    $prompt = "Esse arquivo foi mandado por um cliente pra ouvidoria de uma empresa de veículos. "
        . "É um comprovante de pagamento (pix, boleto, transferência)? Uma notificação de multa/infração de trânsito? Ou outra coisa? "
        . "Responda SÓ um JSON: {\"assunto\":\"pagamento|multa|outro\"}.";
    $r = geminiCallComMidia($prompt, $mime, base64_encode($bytes), $geminiKey, getConfig('gemini_model') ?: 'gemini-3.5-flash-lite', 60, 0.0);
    return $r !== '' ? _ouvidoriaExtrairAssuntoJson($r) : null;
}

/** Texto fixo da resposta (nunca gerado pela IA). */
function ouvidoriaMontarResposta(string $assunto, bool $temArquivo, bool $dentroHorario, string $nome = ''): string {
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
        case 'contrato':
            return "{$oi}Recebemos sua dúvida sobre o contrato. {$horarioFixo} "
                . ($dentroHorario ? 'Um atendente te responde por aqui em breve.' : 'Respondemos no próximo dia útil.');
        default:
            return "{$oi}Recebemos sua mensagem na ouvidoria da Fastcar ✅ {$horario}";
    }
}

/** Avisa os super admins no sino (único perfil que vê este canal). */
function ouvidoriaNotificarSuperAdmins(string $telefone, string $assunto, string $resumo): void {
    try {
        $ids = getDB()->query("SELECT id FROM usuarios WHERE perfil = 'super_admin' AND bloqueado = 0")->fetchAll(PDO::FETCH_COLUMN);
        $rot = OUVIDORIA_ASSUNTOS[$assunto] ?? OUVIDORIA_ASSUNTOS['outro'];
        foreach ($ids as $id) {
            criarNotificacao((int)$id, 'ouvidoria', "📣 Ouvidoria — {$rot}", mb_substr($resumo, 0, 160), '/admin/ouvidoria_inbox.php?telefone=' . rawurlencode($telefone));
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
    $textoUtil = str_starts_with($texto, '[') ? '' : $texto;
    $assunto = $textoUtil !== '' ? ouvidoriaClassificarPorPalavra($textoUtil) : null;
    if (!$assunto && $textoUtil !== '') $assunto = ouvidoriaClassificarTextoIA($textoUtil);
    if ((!$assunto || $assunto === 'outro') && $temArquivo && $bytes !== null) {
        $assuntoArq = ouvidoriaClassificarMidiaIA($bytes, $mime);
        if ($assuntoArq && $assuntoArq !== 'outro') $assunto = $assuntoArq;
    }
    if ((!$assunto || $assunto === 'outro') && $conv['assunto'] !== '' && $conv['assunto'] !== 'outro') $assunto = $conv['assunto'];
    $assunto = $assunto ?: 'outro';
    if ($assunto !== 'outro' || $conv['assunto'] === '') ouvidoriaSalvarConversa($telNorm, ['assunto' => $assunto]);

    $resumoNotif = ($conv['nome'] ?: $telNorm) . ': ' . ($textoUtil !== '' ? $textoUtil : ($temArquivo ? 'mandou um arquivo' : 'mandou ' . $tipoRegistro));
    ouvidoriaNotificarSuperAdmins($telNorm, $assunto, $resumoNotif);

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
    if (!$responde) {
        return ['ignored' => null, 'telefone' => $phone, 'texto' => $texto, 'tipo' => $tipoRegistro, 'ia_pausada' => false, 'instancia' => $instancia, 'assunto' => $assunto, 'respondeu' => false];
    }

    $resposta = ouvidoriaMontarResposta($assunto, $temArquivo, ouvidoriaDentroHorario(), (string)$conv['nome']);
    $enviou = evolutionEnviarTexto($telNorm, $resposta, evolutionCredenciaisOuvidoria());
    if ($enviou) {
        registrarMensagem($telNorm, 'out', $resposta, null, true, 'text', null);
        ouvidoriaSalvarConversa($telNorm, ['ultima_auto_assunto' => $chaveResposta, 'ultima_auto_em' => date('Y-m-d H:i:s')]);
    }

    return [
        'ignored' => null, 'telefone' => $phone, 'texto' => $texto, 'tipo' => $tipoRegistro,
        'ia_pausada' => false, 'instancia' => $instancia, 'assunto' => $assunto, 'respondeu' => $enviou,
    ];
}
