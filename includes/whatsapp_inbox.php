<?php
/**
 * WhatsApp Box (admin/whatsapp_inbox.php) — caixa de entrada única, dentro
 * do CRM, pra toda a equipe conversar com o cliente pela MESMA instância
 * Z-API principal. Decisão de 15/09/2026 (José/Jean): "decidimos manter só
 * uma instância — e os números dos usuários somente para notificação de
 * novo lead — adicionar um whatsapp box igual do juridicoSaas".
 *
 * Isso substitui a arquitetura antiga de 1 instância Z-API por consultor
 * (zapi_instancias_consultores, includes/zapi_instancias.php — ver
 * admin/usuarios.php e CLAUDE.md): a partir de agora `usuarios.whatsapp`
 * serve só pro número PESSOAL do consultor receber notificação de lead
 * novo (notificarConsultorLeadQualificado(), includes/oportunidades.php —
 * isso não mudou), nunca mais como canal de atendimento próprio.
 *
 * Inspirado no admin/whatsapp-inbox.php do JurídicoSaaS (repo irmão), mas
 * adaptado/enxuto pro modelo de dados do Fastcar — sem os recursos
 * específicos de escritório de advocacia (templates jurídicos, stickers,
 * encaminhar conversa, spin_score, cache de foto de perfil): esses ficam
 * como possível próxima iteração se a equipe sentir falta, não implementados
 * agora de propósito (evitar over-engineering sem pedido real).
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/usuarios.php'; // buscarUsuario() — assinar mensagem com o nome do consultor
require_once dirname(__DIR__) . '/chatbot-whatsapp/includes/mensagens.php'; // registrarMensagem(), iaPausada(), pausarIA(), retomarIA()

/**
 * Lista as conversas (1 linha por telefone que já trocou pelo menos 1
 * mensagem), com a última mensagem, contagem de não lidas e status da IA
 * — ordenado pela mais recente primeiro. $busca filtra por nome ou telefone.
 *
 * $responsavelFiltro (15/09/2026, pergunta direta do José/Jean — "inbox vai
 * mostrar todos ou leads do usuário que iniciou atendimento?"): super_admin
 * vê a caixa inteira (chama sem esse parâmetro); consultor vê só as
 * conversas de cliente onde ELE é responsavel_id em pelo menos 1
 * oportunidade (qualquer etapa — inclusive já fechada/perdida, pra não
 * sumir o histórico de quem já atendeu) — mesmo padrão "Minhas/Todas" já
 * usado em admin/index.php pro funil de compra, aplicado aqui também.
 * Conversa sem NENHUMA oportunidade vinculada ainda (não deveria acontecer
 * na prática — regra #2 cria a oportunidade já no 1º contato — mas por
 * segurança) só aparece pro super_admin, nunca pra um consultor aleatório.
 */
function listarConversasWhatsapp(string $busca = '', ?int $responsavelFiltro = null, int $limite = 100): array {
    $db = getDB();
    $whereBusca = '';
    $params = [];
    if ($busca !== '') {
        $whereBusca = ' AND (c.nome LIKE ? OR m.telefone LIKE ?)';
        $like = '%' . $busca . '%';
        $params = [$like, $like];
    }
    $whereResponsavel = '';
    if ($responsavelFiltro !== null) {
        $whereResponsavel = ' AND EXISTS (SELECT 1 FROM oportunidades o WHERE o.cliente_id = m.cliente_id AND o.responsavel_id = ?)';
        $params[] = $responsavelFiltro;
    }

    $sql = "
        SELECT m.telefone, m.mensagem AS ultima_mensagem, m.direcao AS ultima_direcao,
               m.tipo AS ultima_tipo, m.created_at AS ultima_em, m.cliente_id, c.nome AS cliente_nome,
               c.foto_perfil_url,
               (SELECT COUNT(*) FROM whatsapp_mensagens m2 WHERE m2.telefone = m.telefone AND m2.direcao = 'in' AND m2.lida = 0) AS nao_lidas,
               COALESCE(ws.ia_pausada, 0) AS ia_pausada
        FROM whatsapp_mensagens m
        LEFT JOIN clientes c ON c.id = m.cliente_id
        LEFT JOIN whatsapp_sessoes ws ON ws.telefone = m.telefone
        WHERE m.id = (SELECT MAX(id) FROM whatsapp_mensagens m3 WHERE m3.telefone = m.telefone)
        {$whereBusca}{$whereResponsavel}
        ORDER BY m.created_at DESC
        LIMIT ?
    ";
    $params[] = $limite;
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/** Últimas $limite mensagens de uma conversa, em ordem cronológica (mais antiga primeiro). */
function buscarMensagensConversa(string $telefone, int $limite = 50): array {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT m.*, u.nome AS usuario_nome
        FROM whatsapp_mensagens m
        LEFT JOIN usuarios u ON u.id = m.usuario_id
        WHERE m.telefone = ?
        ORDER BY m.id DESC LIMIT ?
    ");
    $stmt->execute([normalizarTelefone($telefone), $limite]);
    return array_reverse($stmt->fetchAll());
}

/**
 * Mensagens ANTERIORES a $antesDeId, em ordem cronológica — usado pelo
 * "⬆️ Carregar mensagens anteriores" da tela (15/09/2026, achado real:
 * "inbox não está mostrando conversa inteira" — buscarMensagensConversa()
 * só carrega as últimas 50 mensagens ao abrir, sem nenhum jeito de ver o
 * que veio antes disso; conversa mais longa que 50 mensagens simplesmente
 * cortava o começo sem avisar ninguém).
 */
function buscarMensagensAntesId(string $telefone, int $antesDeId, int $limite = 50): array {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT m.*, u.nome AS usuario_nome
        FROM whatsapp_mensagens m
        LEFT JOIN usuarios u ON u.id = m.usuario_id
        WHERE m.telefone = ? AND m.id < ?
        ORDER BY m.id DESC LIMIT ?
    ");
    $stmt->execute([normalizarTelefone($telefone), $antesDeId, $limite]);
    return array_reverse($stmt->fetchAll());
}

/** Mensagens novas desde $depoisDeId — usado pelo polling do front-end. */
function buscarMensagensNovasConversa(string $telefone, int $depoisDeId): array {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT m.*, u.nome AS usuario_nome
        FROM whatsapp_mensagens m
        LEFT JOIN usuarios u ON u.id = m.usuario_id
        WHERE m.telefone = ? AND m.id > ?
        ORDER BY m.id ASC
    ");
    $stmt->execute([normalizarTelefone($telefone), $depoisDeId]);
    return $stmt->fetchAll();
}

/**
 * Consultor pode ver/responder essa conversa? null = super_admin, sem
 * restrição. Usado tanto pra decidir o que abrir na tela quanto pra travar
 * envio/pausa de IA por POST direto — a listagem filtrada
 * (listarConversasWhatsapp()) sozinha não bastava: um consultor digitando
 * ?telefone=X na URL, ou forjando o POST de enviar/pausar, contornaria o
 * filtro da barra lateral sem essa checagem separada.
 */
function usuarioPodeVerConversaWhatsapp(string $telefone, ?int $responsavelFiltro): bool {
    if ($responsavelFiltro === null) return true;
    $db = getDB();
    $stmt = $db->prepare("
        SELECT EXISTS (
            SELECT 1 FROM oportunidades o
            JOIN clientes c ON c.id = o.cliente_id
            WHERE c.telefone = ? AND o.responsavel_id = ?
        )
    ");
    $stmt->execute([normalizarTelefone($telefone), $responsavelFiltro]);
    return (bool)$stmt->fetchColumn();
}

/**
 * Que tipo de mídia (audio/image/video) essa mensagem carrega — ou null se
 * não tem mídia salva. Usado pra decidir se/como renderizar um
 * <audio>/<img>/<video> na thread (admin/whatsapp_inbox.php).
 *
 * `tipo` sozinho não basta: quando o Gemini descreve a mídia com sucesso
 * (chatbot-whatsapp/includes/mensagens.php::processarMensagemZapi()),
 * `tipo` fica 'text' de propósito (pra entrar no histórico da IA como
 * mensagem normal) — só o prefixo emoji (🎤/📷/🎥) na frente do texto
 * denuncia que veio de mídia nesse caso. Quando a descrição falha,
 * `tipo` já é o tipo bruto direto (audio/image/video).
 */
function tipoMidiaMensagemWhatsapp(array $m): ?string {
    if (!$m['drive_file_id'] && !$m['arquivo_url']) return null;
    if (in_array($m['tipo'], ['audio', 'image', 'video', 'document'], true)) return $m['tipo'];
    $texto = (string)$m['mensagem'];
    if (str_starts_with($texto, '🎤')) return 'audio';
    if (str_starts_with($texto, '🎥')) return 'video';
    if (str_starts_with($texto, '📷')) return 'image';
    if (str_starts_with($texto, '📎')) return 'document';
    return null;
}

/**
 * HTML do player/preview inline (<audio>/<video>/<img>) pra mídia salva de
 * uma mensagem — ou '' se não tem mídia. Sempre serve via
 * admin/ver_midia_whatsapp.php (nunca a URL do Drive/local direto — mesma
 * trava de quem pode ver a conversa, e o Drive não expõe link público).
 */
function renderizarMidiaWhatsapp(array $m): string {
    $midia = tipoMidiaMensagemWhatsapp($m);
    if (!$midia) return '';
    $url = '/admin/ver_midia_whatsapp.php?id=' . (int)$m['id'];
    return match ($midia) {
        'audio' => '<audio controls preload="none" src="' . e($url) . '" style="max-width:260px;display:block;margin-top:6px"></audio>',
        'video' => '<video controls preload="none" src="' . e($url) . '" style="max-width:260px;border-radius:8px;display:block;margin-top:6px"></video>',
        'image' => '<a href="' . e($url) . '" target="_blank" rel="noopener"><img src="' . e($url) . '" loading="lazy" style="max-width:220px;border-radius:8px;display:block;margin-top:6px"></a>',
        'document' => '<a href="' . e($url) . '" target="_blank" rel="noopener" style="display:inline-flex;align-items:center;gap:6px;margin-top:6px;padding:6px 10px;background:#f1f5f9;border-radius:8px;text-decoration:none;color:inherit;font-size:.85rem">📎 ' . e(preg_replace('/^📎\s*/u', '', (string)$m['mensagem'])) . '</a>',
        default => '',
    };
}

/** Marca toda mensagem recebida ('in') de um telefone como lida — chamado ao abrir a conversa. */
function marcarConversaLida(string $telefone): void {
    $db = getDB();
    $db->prepare("UPDATE whatsapp_mensagens SET lida = 1 WHERE telefone = ? AND direcao = 'in' AND lida = 0")
        ->execute([normalizarTelefone($telefone)]);
}

/**
 * Envia uma mensagem de texto manual pelo WhatsApp Box — grava no histórico
 * e PAUSA a IA (regra #4 do CLAUDE.md: "passagem pro consultor pausa a
 * IA"). Diferente do fromMe detectado no webhook (que só registra, sem
 * pausar — pode ser o próprio bot ecoando), aqui a ação É de um humano
 * mandando pela caixa, então pausar é sempre certo.
 */
function enviarMensagemManualWhatsapp(string $telefone, string $texto, int $usuarioId): array {
    $telNorm = normalizarTelefone($telefone);
    $texto = trim($texto);
    if ($texto === '') {
        return ['ok' => false, 'erro' => 'Mensagem vazia.'];
    }
    // Assina com o nome do consultor a mensagem que o CLIENTE recebe de
    // verdade no WhatsApp — 15/09/2026, pedido José/Jean: "as mensagens do
    // inbox tem que ser assinado pelo consultor se ele entrar na
    // conversa". O texto SALVO/exibido no CRM (registrarMensagem() abaixo)
    // fica sem essa assinatura duplicada — a bolha já mostra "👤 {nome}"
    // à parte, ver admin/whatsapp_inbox.php.
    $usuario = buscarUsuario($usuarioId);
    $nomeConsultor = trim((string)($usuario['nome'] ?? ''));
    $textoAssinado = $nomeConsultor !== '' ? "*{$nomeConsultor}:*\n{$texto}" : $texto;
    if (!zapiEnviarTexto($telNorm, $textoAssinado)) {
        return ['ok' => false, 'erro' => 'Falha ao enviar pelo Z-API — confira a instância em Configurações.'];
    }
    $id = registrarMensagem($telNorm, 'out', $texto, null, false, 'text', $usuarioId);
    pausarIA($telNorm);
    return ['ok' => true, 'id' => $id];
}

/**
 * Envia um áudio manual pelo WhatsApp Box (17/09/2026, "permita enviar
 * audio no inbox para o cliente") — mesmo espírito de
 * `enviarMensagemManualWhatsapp()`: grava no histórico e PAUSA a IA (regra
 * #4), mas manda via `zapiEnviarAudio()` em vez de `zapiEnviarTexto()`.
 * $audioBase64: conteúdo bruto em base64 (sem o prefixo `data:...;base64,`
 * — montado aqui na hora de chamar o Z-API), já validado no JS como
 * `audio/*` antes de subir. Envia PRIMEIRO pro Z-API (mesma ordem do envio
 * de texto — nunca grava histórico de algo que não chegou a sair de
 * verdade) e só registra + salva a cópia reproduzível na thread
 * (`salvarMidiaWhatsappRecebida()`, reaproveitada aqui mesmo sendo função
 * nomeada pro lado de RECEBER — ela só salva bytes numa linha de
 * `whatsapp_mensagens` já existente, não importa a direção) se o envio deu
 * certo. Tamanho travado no mesmo `WHATSAPP_MIDIA_MAX_BYTES` (20MB) já
 * usado pro download de mídia recebida, por consistência.
 */
function enviarAudioManualWhatsapp(string $telefone, string $audioBase64, string $mime, int $usuarioId): array {
    $telNorm = normalizarTelefone($telefone);
    if ($audioBase64 === '') {
        return ['ok' => false, 'erro' => 'Nenhum áudio selecionado.'];
    }
    if (!str_starts_with($mime, 'audio/')) {
        return ['ok' => false, 'erro' => 'Arquivo não reconhecido como áudio.'];
    }
    $bytes = base64_decode($audioBase64, true);
    if ($bytes === false || $bytes === '') {
        return ['ok' => false, 'erro' => 'Arquivo de áudio inválido.'];
    }
    if (strlen($bytes) > WHATSAPP_MIDIA_MAX_BYTES) {
        return ['ok' => false, 'erro' => 'Áudio maior que o limite de ' . (int)(WHATSAPP_MIDIA_MAX_BYTES / 1024 / 1024) . 'MB.'];
    }

    if (!zapiEnviarAudio($telNorm, 'data:' . $mime . ';base64,' . $audioBase64)) {
        return ['ok' => false, 'erro' => 'Falha ao enviar pelo Z-API — confira a instância em Configurações.'];
    }

    $id = registrarMensagem($telNorm, 'out', '🎤 Áudio', null, false, 'audio', $usuarioId);
    if ($id > 0) {
        $db = getDB();
        $stmt = $db->prepare("SELECT id, nome FROM clientes WHERE telefone = ?");
        $stmt->execute([$telNorm]);
        $cliente = $stmt->fetch();
        if ($cliente) {
            salvarMidiaWhatsappRecebida($id, $bytes, $mime, 'audio', (int)$cliente['id'], $cliente['nome'] ?: $telNorm);
        }
    }
    pausarIA($telNorm);
    return ['ok' => true, 'id' => $id];
}

/**
 * Envia um anexo (imagem ou documento) manual pelo WhatsApp Box do
 * consultor (18/09/2026, "adicionei opção de enviar anexo para clientes
 * no ibox do consultor") — mesmo espírito de `enviarAudioManualWhatsapp()`:
 * grava no histórico e PAUSA a IA (regra #4), envia PRIMEIRO pro Z-API e
 * só registra + salva a cópia reproduzível na thread se o envio deu certo
 * de verdade (mesma ordem/disciplina do resto). Diferente de áudio (sem
 * legenda no WhatsApp), imagem e documento ACEITAM legenda — vai assinada
 * com o nome do consultor, mesmo padrão/regra de
 * `enviarMensagemManualWhatsapp()` (15/09/2026, "as mensagens do inbox tem
 * que ser assinado pelo consultor"). Imagem vai por `zapiEnviarImagem()`
 * (já aceita data URI base64, mesmo caminho usado pra mandar foto do
 * catálogo de vendas); qualquer outro tipo (PDF, Word, planilha etc) vai
 * por `zapiEnviarDocumento()` — `send-document/{extensão}`, nunca
 * confirmado contra instância real ainda, mesma ressalva de "a validar em
 * produção" de todo endpoint Z-API que não seja texto puro.
 */
function enviarAnexoManualWhatsapp(string $telefone, string $conteudoBase64, string $mime, string $nomeArquivoOriginal, int $usuarioId): array {
    $telNorm = normalizarTelefone($telefone);
    if ($conteudoBase64 === '') {
        return ['ok' => false, 'erro' => 'Nenhum arquivo selecionado.'];
    }
    $bytes = base64_decode($conteudoBase64, true);
    if ($bytes === false || $bytes === '') {
        return ['ok' => false, 'erro' => 'Arquivo inválido.'];
    }
    if (strlen($bytes) > WHATSAPP_MIDIA_MAX_BYTES) {
        return ['ok' => false, 'erro' => 'Arquivo maior que o limite de ' . (int)(WHATSAPP_MIDIA_MAX_BYTES / 1024 / 1024) . 'MB.'];
    }

    $ehImagem = str_starts_with($mime, 'image/');
    $extensao = extensaoPorMime($mime);
    $nomeArquivo = trim($nomeArquivoOriginal);
    if ($nomeArquivo === '') {
        $nomeArquivo = 'anexo.' . ($extensao !== 'bin' ? $extensao : 'dat');
    } elseif ($extensao === 'bin' && str_contains($nomeArquivo, '.')) {
        // Mime não reconhecido no mapa — usa a extensão do nome original
        // (que o navegador já sabe) só pro parâmetro do send-document, nunca
        // grava esse valor de volta no nome exibido.
        $extensao = strtolower(substr($nomeArquivo, strrpos($nomeArquivo, '.') + 1));
    }

    $usuario = buscarUsuario($usuarioId);
    $nomeConsultor = trim((string)($usuario['nome'] ?? ''));
    $legenda = $nomeConsultor !== '' ? "*{$nomeConsultor}:*\n📎 {$nomeArquivo}" : "📎 {$nomeArquivo}";
    $dataUri = 'data:' . $mime . ';base64,' . $conteudoBase64;

    if ($ehImagem) {
        $ok = zapiEnviarImagem($telNorm, $dataUri, $legenda);
    } else {
        $ok = zapiEnviarDocumento($telNorm, $dataUri, $nomeArquivo, $extensao);
    }
    if (!$ok) {
        return ['ok' => false, 'erro' => 'Falha ao enviar pelo Z-API — confira a instância em Configurações.'];
    }

    $tipoRegistro = $ehImagem ? 'image' : 'document';
    $emoji = $ehImagem ? '📷' : '📎';
    $id = registrarMensagem($telNorm, 'out', "{$emoji} {$nomeArquivo}", null, false, $tipoRegistro, $usuarioId);
    if ($id > 0) {
        $db = getDB();
        $stmt = $db->prepare("SELECT id, nome FROM clientes WHERE telefone = ?");
        $stmt->execute([$telNorm]);
        $cliente = $stmt->fetch();
        if ($cliente) {
            salvarMidiaWhatsappRecebida($id, $bytes, $mime, $tipoRegistro, (int)$cliente['id'], $cliente['nome'] ?: $telNorm);
        }
    }
    pausarIA($telNorm);
    return ['ok' => true, 'id' => $id];
}

/**
 * Apaga o histórico de uma conversa (whatsapp_mensagens + estado da IA em
 * whatsapp_sessoes) — 15/09/2026, pedido direto pra limpar as conversas de
 * lixo criadas no incidente do mesmo dia (eventos de presença/status da
 * Z-API caindo no webhook errado, ver includes/../chatbot-whatsapp/includes/mensagens.php).
 * Restrito ao super_admin (admin/whatsapp_inbox.php) — ação destrutiva,
 * sem confirmação em duas etapas não teria volta. Nunca mexe em
 * `clientes`/`oportunidades`: apagar a CONVERSA não é o mesmo que apagar o
 * lead/negócio — se a conversa era de um cliente real, o cadastro e o
 * histórico do funil continuam intactos, só a thread de mensagens some.
 */
function excluirConversaWhatsapp(string $telefone): void {
    $telNorm = normalizarTelefone($telefone);
    $db = getDB();
    $db->prepare("DELETE FROM whatsapp_mensagens WHERE telefone = ?")->execute([$telNorm]);
    $db->prepare("DELETE FROM whatsapp_sessoes WHERE telefone = ?")->execute([$telNorm]);
}
