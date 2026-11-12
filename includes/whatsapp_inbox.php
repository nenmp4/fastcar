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
require_once __DIR__ . '/oportunidades.php'; // ETAPAS_ATIVAS
require_once __DIR__ . '/usuarios.php'; // buscarUsuario() — assinar mensagem com o nome do consultor
require_once __DIR__ . '/documentos.php'; // lerConteudoArquivoDocumento() — cache local de mídia já baixada do Drive
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
/** Quantas conversas a IA está qualificando agora (chip "🤖 IA qualificando"). */
function contarConversasEmQualificacaoWhatsapp(): int {
    return (int)getDB()->query("
        SELECT COUNT(*) FROM whatsapp_mensagens m
        JOIN (SELECT telefone, MAX(id) AS max_id FROM whatsapp_mensagens WHERE canal = 'compra' GROUP BY telefone) ult
          ON ult.telefone = m.telefone AND ult.max_id = m.id
        LEFT JOIN whatsapp_sessoes ws ON ws.telefone = m.telefone
        WHERE " . inboxSqlConversaEmQualificacao())->fetchColumn();
}

/**
 * Etapas em que a conversa aparece no WhatsApp Box do CONSULTOR (12/11/2026,
 * "notificações pro consultor quando lead entra qualificando acaba
 * atrapalhando a IA"): enquanto a IA ainda qualifica (whatsapp/qualificacao_ia),
 * a conversa fica escondida da lista dele — senão ele respondia no meio, a IA
 * pausava e a qualificação parava. Aparece quando a IA termina (CRM
 * preenchido). Super admin/supervisor continuam vendo tudo.
 */
function inboxEtapasVisiveisConsultor(): array {
    return array_values(array_diff(ETAPAS_ATIVAS, ['whatsapp', 'qualificacao_ia']));
}

/**
 * 12/11/2026, "até pro super admin — ela vai clicando": conversa que a IA
 * ainda está qualificando (etapa whatsapp/qualificacao_ia, IA não pausada)
 * sai da lista padrão de TODO mundo — quem clicava e respondia pausava a IA.
 * Fica no chip "🤖 IA qualificando" ($modoIa='so_ia') e aparece na busca.
 */
function inboxSqlConversaEmQualificacao(): string {
    return "(COALESCE(ws.ia_pausada, 0) = 0 AND EXISTS (SELECT 1 FROM oportunidades oq WHERE oq.cliente_id = m.cliente_id AND oq.etapa IN ('whatsapp', 'qualificacao_ia')))";
}

function listarConversasWhatsapp(string $busca = '', ?int $responsavelFiltro = null, int $limite = 100, bool $soNaoLidas = false, int $offset = 0, string $modoIa = 'esconder'): array {
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
        // 29/10/2026, "dando cliente encerrado ele não some da listagens" —
        // antes bastava ter tido QUALQUER oportunidade com o consultor (até
        // perdida/sem perfil/fechada) pra conversa ficar na lista dele pra
        // sempre. Agora só aparece enquanto ele tem oportunidade ATIVA com o
        // cliente; encerrou, sai da lista (a conversa continua acessível pelo
        // cadastro/oportunidade, e volta sozinha se o cliente abrir negócio novo).
        $etapasConsultor = inboxEtapasVisiveisConsultor();
        $phEtapas = implode(',', array_fill(0, count($etapasConsultor), '?'));
        $whereResponsavel = " AND EXISTS (SELECT 1 FROM oportunidades o WHERE o.cliente_id = m.cliente_id AND o.responsavel_id = ? AND o.etapa IN ($phEtapas))";
        $params[] = $responsavelFiltro;
        foreach ($etapasConsultor as $et) $params[] = $et;
    }
    if ($modoIa === 'so_ia') {
        $whereResponsavel .= ' AND ' . inboxSqlConversaEmQualificacao();
    } elseif ($modoIa === 'esconder' && $busca === '') {
        $whereResponsavel .= ' AND NOT ' . inboxSqlConversaEmQualificacao();
    }
    // 29/10/2026, "mostrar mensagens não lidas igual zap" — aba "Não lidas".
    if ($soNaoLidas) {
        $whereResponsavel .= " AND COALESCE(ws.ia_pausada, 0) = 1 AND EXISTS (SELECT 1 FROM whatsapp_mensagens m4 WHERE m4.telefone = m.telefone AND m4.canal = 'compra' AND m4.direcao = 'in' AND m4.lida = 0)";
    }

    $sql = "
        SELECT m.telefone, m.mensagem AS ultima_mensagem, m.direcao AS ultima_direcao,
               m.tipo AS ultima_tipo, m.created_at AS ultima_em, m.cliente_id, c.nome AS cliente_nome,
               c.foto_perfil_url,
               -- 12/11/2026: não lida só conta com a IA pausada (humano atendendo);
               -- com a IA ativa, é ela quem responde — não notifica a equipe.
               CASE WHEN COALESCE(ws.ia_pausada, 0) = 1 THEN (SELECT COUNT(*) FROM whatsapp_mensagens m2 WHERE m2.telefone = m.telefone AND m2.canal = 'compra' AND m2.direcao = 'in' AND m2.lida = 0) ELSE 0 END AS nao_lidas,
               COALESCE(ws.ia_pausada, 0) AS ia_pausada
        FROM whatsapp_mensagens m
        JOIN (SELECT telefone, MAX(id) AS max_id FROM whatsapp_mensagens WHERE canal = 'compra' GROUP BY telefone) ult
          ON ult.telefone = m.telefone AND ult.max_id = m.id
        LEFT JOIN clientes c ON c.id = m.cliente_id
        LEFT JOIN whatsapp_sessoes ws ON ws.telefone = m.telefone
        WHERE 1=1
        {$whereBusca}{$whereResponsavel}
        ORDER BY m.created_at DESC
        LIMIT ? OFFSET ?
    ";
    $params[] = $limite;
    $params[] = max(0, $offset);
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Quantas conversas têm mensagem não lida — contador da aba "Não lidas"
 * (29/10/2026). Query própria e barata (usa o índice parcial
 * idx_wpp_nao_lidas), nunca a listagem inteira de novo a cada polling.
 * Mesmo filtro de carteira do consultor de listarConversasWhatsapp().
 */
function contarConversasNaoLidasWhatsapp(?int $responsavelFiltro = null): int {
    $db = getDB();
    // 12/11/2026: só conversa com a IA pausada (humano atendendo) conta como não lida.
    $sql = "SELECT COUNT(DISTINCT m.telefone) FROM whatsapp_mensagens m
            JOIN whatsapp_sessoes ws ON ws.telefone = m.telefone AND ws.ia_pausada = 1
            WHERE m.canal = 'compra' AND m.direcao = 'in' AND m.lida = 0";
    $params = [];
    if ($responsavelFiltro !== null) {
        $etapasConsultor = inboxEtapasVisiveisConsultor();
        $phEtapas = implode(',', array_fill(0, count($etapasConsultor), '?'));
        // Mesmo critério da lista (m.cliente_id), senão o "(N)" contava conversa
        // que nem aparece na lista do consultor (29/10/2026, "marca 4 não lidas mas já leu").
        $sql .= " AND EXISTS (SELECT 1 FROM oportunidades o WHERE o.cliente_id = m.cliente_id AND o.responsavel_id = ? AND o.etapa IN ($phEtapas))";
        $params[] = $responsavelFiltro;
        foreach ($etapasConsultor as $et) $params[] = $et;
    }
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

/** Últimas $limite mensagens de uma conversa, em ordem cronológica (mais antiga primeiro). */
function buscarMensagensConversa(string $telefone, int $limite = 50, ?string $canal = null): array {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT m.*, u.nome AS usuario_nome
        FROM whatsapp_mensagens m
        LEFT JOIN usuarios u ON u.id = m.usuario_id
        WHERE m.telefone = ?" . ($canal ? " AND m.canal = " . getDB()->quote($canal) : '') . "
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
function buscarMensagensAntesId(string $telefone, int $antesDeId, int $limite = 50, ?string $canal = null): array {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT m.*, u.nome AS usuario_nome
        FROM whatsapp_mensagens m
        LEFT JOIN usuarios u ON u.id = m.usuario_id
        WHERE m.telefone = ? AND m.id < ?" . ($canal ? " AND m.canal = " . getDB()->quote($canal) : '') . "
        ORDER BY m.id DESC LIMIT ?
    ");
    $stmt->execute([normalizarTelefone($telefone), $antesDeId, $limite]);
    return array_reverse($stmt->fetchAll());
}

/** Mensagens novas desde $depoisDeId — usado pelo polling do front-end. */
function buscarMensagensNovasConversa(string $telefone, int $depoisDeId, ?string $canal = null): array {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT m.*, u.nome AS usuario_nome
        FROM whatsapp_mensagens m
        LEFT JOIN usuarios u ON u.id = m.usuario_id
        WHERE m.telefone = ? AND m.id > ?" . ($canal ? " AND m.canal = " . getDB()->quote($canal) : '') . "
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
 * Mídia recebida que ficou sem cópia salva (03/11/2026, achado real: "umas
 * fotos abriram, outras a IA descreveu mas não carregou") — devolve o tipo
 * esperado ('image'/'audio'/'video'/'document') ou null. Só mensagem do
 * cliente, com id da mensagem no WhatsApp (precisa dele pra baixar de novo).
 */
function midiaFaltandoMensagemWhatsapp(array $m): ?string {
    if (($m['direcao'] ?? '') !== 'in' || !empty($m['drive_file_id']) || !empty($m['arquivo_url'])) return null;
    if (empty($m['zapi_message_id'])) return null;
    $tipo = (string)($m['tipo'] ?? '');
    if (in_array($tipo, ['audio', 'image', 'video', 'document'], true)) return $tipo;
    $texto = (string)($m['mensagem'] ?? '');
    if (str_starts_with($texto, '🎤')) return 'audio';
    if (str_starts_with($texto, '🎥')) return 'video';
    if (str_starts_with($texto, '📷')) return 'image';
    return null;
}

/**
 * Baixa de novo pela Evolution uma mídia recebida que não ficou salva e
 * guarda a cópia. Tenta no máximo 1x a cada 30 min por mensagem (mídia
 * expirada no WhatsApp nunca vai voltar — não fica batendo à toa).
 * Retorna ['ok'=>bool, 'mensagem'=>linha atualizada|null, 'erro'=>string].
 */
function recuperarMidiaMensagemWhatsapp(int $mensagemId, bool $forcar = false): array {
    $db = getDB();
    $st = $db->prepare("SELECT * FROM whatsapp_mensagens WHERE id = ?");
    $st->execute([$mensagemId]);
    $m = $st->fetch();
    if (!$m) return ['ok' => false, 'mensagem' => null, 'erro' => 'Mensagem não encontrada.'];
    $tipo = midiaFaltandoMensagemWhatsapp($m);
    if ($tipo === null) return ['ok' => (bool)($m['drive_file_id'] || $m['arquivo_url']), 'mensagem' => $m, 'erro' => ''];

    $mimeDefault = ['audio' => 'audio/ogg', 'image' => 'image/jpeg', 'video' => 'video/mp4', 'document' => 'application/octet-stream'][$tipo];
    $stC = $db->prepare("SELECT id, nome FROM clientes WHERE telefone = ?");
    // 09/11/2026 — reserva guardada no disco na hora do download (antes da
    // IA): se o webhook morreu antes de salvar a cópia, recupera daqui sem
    // depender do WhatsApp liberar a mídia de novo.
    $pendente = lerMidiaPendente((string)$m['zapi_message_id']);
    if ($pendente !== null) {
        $stC->execute([$m['telefone']]);
        $cli = $stC->fetch() ?: ['id' => 0, 'nome' => ''];
        $erroSalvar = salvarMidiaWhatsappRecebida($mensagemId, $pendente['bytes'], $pendente['mime'] ?: $mimeDefault, $tipo, (int)$cli['id'], (string)$cli['nome']);
        $st->execute([$mensagemId]);
        $m = $st->fetch();
        if ($m['drive_file_id'] || $m['arquivo_url']) return ['ok' => true, 'mensagem' => $m, 'erro' => ''];
    }

    $chave = 'midia_retry_' . $mensagemId;
    $ultima = (int)getConfig($chave);
    // $forcar (botão "Tentar de novo", 09/11/2026): ignora a espera de 30 min,
    // mas nunca mais de 1x a cada 15s por mensagem.
    if ($ultima && time() - $ultima < ($forcar ? 15 : 1800)) return ['ok' => false, 'mensagem' => $m, 'erro' => 'Já tentei há pouco — a mídia pode ter expirado no WhatsApp.'];
    setConfig($chave, (string)time());

    require_once __DIR__ . '/whatsapp_evolution.php';
    $cred = null;
    if (($m['canal'] ?? '') === 'vendas' && function_exists('evolutionConfiguredVendas') && evolutionConfiguredVendas()) $cred = evolutionCredenciaisVendas();
    elseif (($m['canal'] ?? '') === 'financeiro' && function_exists('evolutionConfiguredFinanceiro') && evolutionConfiguredFinanceiro()) $cred = evolutionCredenciaisFinanceiro();
    $res = evolutionBaixarMidiaRecebida((string)$m['zapi_message_id'], $tipo, $cred);
    // Mensagem pode ter chegado pela instância principal e sido movida pra vendas.
    if ($res === null && $cred !== null) $res = evolutionBaixarMidiaRecebida((string)$m['zapi_message_id'], $tipo);
    if ($res === null) {
        return ['ok' => false, 'mensagem' => $m, 'erro' => 'Não deu pra baixar do WhatsApp (' . (evolutionUltimoErro() ?: 'sem detalhe') . ').'];
    }
    $stC->execute([$m['telefone']]);
    $cli = $stC->fetch() ?: ['id' => 0, 'nome' => ''];
    $mime = $res['mime'] ?: $mimeDefault;
    $erroSalvar = salvarMidiaWhatsappRecebida($mensagemId, $res['bytes'], $mime, $tipo, (int)$cli['id'], (string)$cli['nome']);
    $st->execute([$mensagemId]);
    $m = $st->fetch();
    $ok = (bool)($m['drive_file_id'] || $m['arquivo_url']);
    return ['ok' => $ok, 'mensagem' => $m, 'erro' => $ok ? '' : 'Baixou, mas não conseguiu salvar a cópia' . ($erroSalvar !== '' ? ': ' . mb_substr($erroSalvar, 0, 200) : '') . '.'];
}

/**
 * HTML do player/preview inline (<audio>/<video>/<img>) pra mídia salva de
 * uma mensagem — ou '' se não tem mídia. Sempre serve via
 * admin/ver_midia_whatsapp.php (nunca a URL do Drive/local direto — mesma
 * trava de quem pode ver a conversa, e o Drive não expõe link público).
 */
function renderizarMidiaWhatsapp(array $m): string {
    $midia = tipoMidiaMensagemWhatsapp($m);
    if (!$midia) {
        $faltando = midiaFaltandoMensagemWhatsapp($m);
        return $faltando ? '<div class="midia-faltando" data-mid="' . (int)$m['id'] . '" style="margin-top:6px;font-size:.8rem;color:var(--texto-fraco)">🔄 carregando ' . ($faltando === 'audio' ? 'áudio' : ($faltando === 'video' ? 'vídeo' : ($faltando === 'document' ? 'arquivo' : 'foto'))) . '…</div>' : '';
    }
    $url = '/admin/ver_midia_whatsapp.php?id=' . (int)$m['id'];
    return match ($midia) {
        'audio' => '<audio controls preload="none" src="' . e($url) . '" style="max-width:260px;display:block;margin-top:6px"></audio>',
        'video' => '<video controls preload="none" src="' . e($url) . '" style="max-width:260px;border-radius:8px;display:block;margin-top:6px"></video>',
        'image' => '<a href="' . e($url) . '" target="_blank" rel="noopener"><img src="' . e($url) . '" loading="lazy" width="220" height="165" style="height:auto;max-width:220px;border-radius:8px;display:block;margin-top:6px"></a>',
        'document' => '<a href="' . e($url) . '" target="_blank" rel="noopener" style="display:inline-flex;align-items:center;gap:6px;margin-top:6px;padding:6px 10px;background:#f1f5f9;border-radius:8px;text-decoration:none;color:inherit;font-size:.85rem">📎 ' . e(preg_replace('/^📎\s*/u', '', (string)$m['mensagem'])) . '</a>',
        default => '',
    };
}

/**
 * Cache LOCAL (disco, `storage/cache/whatsapp_midia/`) pra bytes de mídia
 * de mensagem já baixados do Drive — 06/10/2026, achado real: "inbox -
 * fotos está demorando abrir [...] da geral na velocidade". Causa: toda
 * vez que alguém abria uma foto/áudio/vídeo recebido do cliente,
 * admin/ver_midia_whatsapp.php refazia do zero a autenticação JWT +
 * download pela Drive API (lerConteudoArquivoDocumento()) — mesmo pra
 * REABRIR a mesma mensagem que já tinha sido vista segundos antes, e o
 * header de resposta era `no-store` (nunca deixava o navegador guardar
 * nada). Numa conversa com várias fotos (fotos do veículo, CRLV etc),
 * cada abertura de conversa disparava N round-trips reais pra Drive só
 * pra servir de novo o que já tinha sido baixado antes — o próprio custo
 * de rede da Drive API (autenticação + download) é o que sentia como
 * "lento", não uma query do SQLite.
 *
 * Seguro cachear por `drive_file_id` pra sempre (sem TTL, diferente do
 * cache de API externa documentado em includes/cnpj.php/fipe.php, que é
 * pra DADO que pode mudar com o tempo): `whatsapp_mensagens.drive_file_id`/
 * `arquivo_url` são escritos exatamente 1 vez (confirmado — só existe 1
 * UPDATE no projeto todo pra essas 2 colunas, em
 * chatbot-whatsapp/includes/mensagens.php, no momento em que a mídia é
 * salva pela 1ª vez) e nunca mais sobrescritos depois — diferente de
 * `oportunidade_documentos`/`contratos`, onde o mesmo id PODE trocar de
 * arquivo (documento reenviado, contrato assinado por cima do rascunho);
 * por isso esse cache é só pra mídia de WhatsApp, nunca generalizado pra
 * `lerConteudoArquivoDocumento()` em si (usada também por
 * admin/ver_documento.php/ver_contrato.php, onde cachear por esse motivo
 * serviria conteúdo velho depois de uma substituição).
 *
 * Mídia já local (sem `drive_file_id`, fallback `storage/uploads/`) nunca
 * passa por aqui — ler do próprio disco já é rápido, não precisa de cache
 * em cima de cache.
 */
function whatsappMidiaCacheDir(): string {
    $dir = dirname(__DIR__) . '/storage/cache/whatsapp_midia';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir;
}

/**
 * 23/10/2026, "está tendo demora para ouvir o audio" — o cache de 06/10 já
 * elimina a demora pra REABRIR algo já visto, e o cache de token do
 * Google Drive de 14/10 já elimina a reautenticação OAuth repetida; mas
 * nenhum dos dois tinha instrumentação pra saber se a demora relatada é no
 * 1º play de verdade (cache miss, inerente — autenticar+baixar do Drive
 * leva um tempo real de rede) ou alguma outra coisa. Mesmo padrão de
 * _logTimingAudioWhatsapp() (envio): loga sempre (nunca só em falha) hit/
 * miss do cache + quanto tempo o download real levou quando foi miss, pra
 * não precisar adivinhar de novo da próxima vez que alguém reclamar.
 */
function _logTimingLeituraMidiaWhatsapp(string $driveFileId, bool $cacheHit, ?float $tempoDownloadSeg, bool $sucesso): void {
    try {
        $dir = dirname(__DIR__) . '/storage/logs';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $linha = '[' . date('Y-m-d H:i:s') . "] drive_file_id={$driveFileId}"
            . ' cache=' . ($cacheHit ? 'hit' : 'miss')
            . ' download=' . ($tempoDownloadSeg === null ? 'n/a' : number_format($tempoDownloadSeg, 2) . 's')
            . ' sucesso=' . ($sucesso ? '1' : '0') . "\n";
        file_put_contents($dir . '/whatsapp_midia_leitura_timing.log', $linha, FILE_APPEND);
    } catch (Throwable $e) {
        // diagnóstico nunca pode quebrar a leitura de verdade
    }
}

function lerMidiaWhatsappComCache(?string $driveFileId, ?string $arquivoUrl): ?array {
    if (!$driveFileId) {
        return lerConteudoArquivoDocumento($driveFileId, $arquivoUrl);
    }

    $dir = whatsappMidiaCacheDir();
    // drive_file_id é um id do Google, sempre alfanumérico com -/_ — nunca
    // passa por um nome de arquivo cru vindo de fora, mas sanitiza mesmo
    // assim antes de montar o caminho (defesa a mais, sem custo).
    $chave = preg_replace('/[^A-Za-z0-9_-]/', '_', $driveFileId);
    $arquivoCache = $dir . '/' . $chave;
    $metaCache = $arquivoCache . '.meta.json';

    if (is_file($arquivoCache) && is_file($metaCache)) {
        $meta = json_decode((string)@file_get_contents($metaCache), true);
        $conteudo = @file_get_contents($arquivoCache);
        if ($conteudo !== false && is_array($meta) && isset($meta['mime'], $meta['name'])) {
            _logTimingLeituraMidiaWhatsapp($driveFileId, true, null, true);
            return ['content' => $conteudo, 'mime' => $meta['mime'], 'name' => $meta['name']];
        }
        // Cache corrompido/incompleto (ex: escrita anterior interrompida) —
        // cai pro caminho normal abaixo e regrava certo.
    }

    $inicioDownload = microtime(true);
    $arquivo = lerConteudoArquivoDocumento($driveFileId, $arquivoUrl);
    $tempoDownload = microtime(true) - $inicioDownload;
    _logTimingLeituraMidiaWhatsapp($driveFileId, false, $tempoDownload, $arquivo !== null);
    if ($arquivo) {
        // Best-effort — nunca bloqueia servir o conteúdo que já foi
        // baixado com sucesso só porque a escrita do cache falhou.
        @file_put_contents($arquivoCache, $arquivo['content']);
        @file_put_contents($metaCache, json_encode(['mime' => $arquivo['mime'], 'name' => $arquivo['name']]));
    }
    return $arquivo;
}

/** Marca toda mensagem recebida ('in') de um telefone como lida — chamado ao abrir a conversa. */
function marcarConversaLida(string $telefone, ?string $canal = null): void {
    // 29/10/2026, "marca 4 não lidas mas já leu": a mensagem pode ter sido
    // gravada com o telefone num formato diferente (cru, com/sem o 9º dígito)
    // — antes só batia o formato normalizado e a conversa ficava "não lida" pra sempre.
    $db = getDB();
    $variantes = whatsappVariantesTelefone($telefone);
    $ph = implode(',', array_fill(0, count($variantes), '?'));
    $db->prepare("UPDATE whatsapp_mensagens SET lida = 1 WHERE telefone IN ($ph) AND direcao = 'in' AND lida = 0" . ($canal ? " AND canal = " . $db->quote($canal) : ''))
        ->execute($variantes);
}

/** Formatos em que um mesmo número BR pode estar salvo (cru, com 55, com/sem o 9 do celular). */
function whatsappVariantesTelefone(string $telefone): array {
    $cru = preg_replace('/\D/', '', $telefone);
    $norm = normalizarTelefone($telefone);
    $v = [$telefone, $cru, $norm];
    if (strlen($norm) === 13 && str_starts_with($norm, '55') && $norm[4] === '9') $v[] = substr($norm, 0, 4) . substr($norm, 5);
    if (strlen($norm) === 12 && str_starts_with($norm, '55')) $v[] = substr($norm, 0, 4) . '9' . substr($norm, 4);
    return array_values(array_unique(array_filter($v, fn($x) => $x !== '')));
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
        return ['ok' => false, 'erro' => 'Falha ao enviar WhatsApp — confira a configuração do canal ativo em Configurações.' . whatsappDetalheUltimoErro()];
    }
    $id = inboxRegistrarEnviada($telNorm, $texto, 'text', $usuarioId);
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
function enviarAudioManualWhatsapp(string $telefone, string $audioBase64, string $mime, int $usuarioId, ?callable $responderAntesDaCopia = null): array {
    $tInicio = microtime(true);
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

    $tAntesEnvio = microtime(true);
    $enviouOk = zapiEnviarAudio($telNorm, 'data:' . $mime . ';base64,' . $audioBase64);
    $tempoEnvio = microtime(true) - $tAntesEnvio;
    if (!$enviouOk) {
        _logTimingAudioWhatsapp($telefone, strlen($bytes), $tempoEnvio, null, microtime(true) - $tInicio, false);
        return ['ok' => false, 'erro' => 'Falha ao enviar WhatsApp — confira a configuração do canal ativo em Configurações.' . whatsappDetalheUltimoErro()];
    }

    $id = inboxRegistrarEnviada($telNorm, '🎤 Áudio', 'audio', $usuarioId);
    pausarIA($telNorm);
    // 29/10/2026, "gravo e depois demora 20 segundos" — o áudio JÁ chegou no
    // cliente aqui; a cópia pro Drive (pra tocar de novo no CRM) é só
    // arquivo nosso. Quem chama pode responder pro navegador agora e deixar
    // o upload terminar depois, sem o consultor ficar esperando.
    if ($responderAntesDaCopia) {
        try { $responderAntesDaCopia(['ok' => true, 'id' => $id]); } catch (Throwable $e) {}
    }
    $tempoDrive = null;
    if ($id > 0) {
        $db = getDB();
        $stmt = $db->prepare("SELECT id, nome FROM clientes WHERE telefone = ?");
        $stmt->execute([$telNorm]);
        $cliente = $stmt->fetch();
        if ($cliente) {
            $tAntesDrive = microtime(true);
            salvarMidiaWhatsappRecebida($id, $bytes, $mime, 'audio', (int)$cliente['id'], $cliente['nome'] ?: $telNorm);
            $tempoDrive = microtime(true) - $tAntesDrive;
        }
    }
    _logTimingAudioWhatsapp($telefone, strlen($bytes), $tempoEnvio, $tempoDrive, microtime(true) - $tInicio, true);
    return ['ok' => true, 'id' => $id];
}

/**
 * Timing do envio de áudio (14/10/2026, achado real: "ao gravar um áudio
 * de 10 segundos demora 18 segundos para enviar") — depois dos 2 fixes já
 * aplicados nesta mesma investigação (cache do token do Drive, bug do
 * parsing de data URI que fazia a Evolution rejeitar com 400), ainda não
 * tínhamos NENHUM dado real de ONDE o tempo estava indo — só hipótese
 * (processamento de áudio na VPS self-hosted do Evolution, recurso
 * limitado). Em vez de continuar só especulando, loga sempre (nunca só em
 * falha) a duração de cada trecho caro — chamada ao provedor de WhatsApp
 * (Evolution/Z-API/Meta, o que estiver ativo) e upload pro Google Drive —
 * separado do tempo total, pra próxima vez que alguém reclamar de
 * demora a gente ter o número exato em vez de adivinhar de novo. Mesmo
 * padrão de `_zapiLogDiagnosticoContato()` (includes/whatsapp_config.php)
 * — nunca pode quebrar o envio em si, só observa.
 */
function _logTimingAudioWhatsapp(string $telefone, int $tamanhoBytes, float $tempoEnvioSeg, ?float $tempoDriveSeg, float $tempoTotalSeg, bool $sucesso): void {
    try {
        $dir = dirname(__DIR__) . '/storage/logs';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        // number_format() (não round()) de propósito — round(0.001, 2) vira
        // o float 0.0, que o PHP imprime como "0" sem casas decimais na
        // concatenação de string, deixando o log com formato inconsistente
        // (às vezes "0s", às vezes "1.23s") dependendo só da magnitude do
        // valor. number_format() sempre mostra as 2 casas.
        $linha = '[' . date('Y-m-d H:i:s') . "] telefone={$telefone} bytes={$tamanhoBytes}"
            . ' envio_provedor=' . number_format($tempoEnvioSeg, 2) . 's'
            . ' drive=' . ($tempoDriveSeg === null ? 'n/a' : number_format($tempoDriveSeg, 2) . 's')
            . ' total=' . number_format($tempoTotalSeg, 2) . 's'
            . ' sucesso=' . ($sucesso ? '1' : '0') . "\n";
        file_put_contents($dir . '/whatsapp_audio_timing.log', $linha, FILE_APPEND);
    } catch (Throwable $e) {
        // diagnóstico nunca pode quebrar o envio de verdade
    }
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
        return ['ok' => false, 'erro' => 'Falha ao enviar WhatsApp — confira a configuração do canal ativo em Configurações.' . whatsappDetalheUltimoErro()];
    }

    $tipoRegistro = $ehImagem ? 'image' : 'document';
    $emoji = $ehImagem ? '📷' : '📎';
    $id = inboxRegistrarEnviada($telNorm, "{$emoji} {$nomeArquivo}", $tipoRegistro, $usuarioId);
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

// ═══════════════════════════════════════════════════════════════════════════
// 09/11/2026 — "Dentro do inbox: limpar conversa, excluir mensagem, excluir
// áudio, verificar se o cliente visualizou, verificar se está online".
// Confirmado com o usuário: limpar só do CRM; excluir = apagar pra todos;
// compra e vendas; online só da conversa aberta. Tudo via Evolution.
// ═══════════════════════════════════════════════════════════════════════════

/** Prazo do WhatsApp pra apagar pra todos (margem de segurança, ~2 dias). */
const INBOX_APAGAR_PRAZO_HORAS = 48;

/**
 * Grava a mensagem que o inbox acabou de mandar, já com o id do WhatsApp
 * (precisa dele pra apagar pra todos e pra receber o "lido"). Se o eco da
 * própria mensagem já chegou pelo webhook antes (mesmo id), completa essa
 * linha com quem mandou em vez de duplicar.
 */
function inboxRegistrarEnviada(string $telNorm, string $texto, string $tipo, ?int $usuarioId): int {
    $waId = function_exists('evolutionConsumirUltimoIdEnviado') ? evolutionConsumirUltimoIdEnviado() : null;
    $id = registrarMensagem($telNorm, 'out', $texto, $waId ?: null, false, $tipo, $usuarioId);
    if ($waId) {
        $db = getDB();
        if (!$id) {
            $db->prepare("UPDATE whatsapp_mensagens SET mensagem = ?, tipo = ?, usuario_id = ?, direcao = 'out' WHERE zapi_message_id = ?")
                ->execute([$texto, $tipo, $usuarioId, $waId]);
            $st = $db->prepare("SELECT id FROM whatsapp_mensagens WHERE zapi_message_id = ?");
            $st->execute([$waId]);
            $id = (int)$st->fetchColumn();
        }
        if ($id) $db->prepare("UPDATE whatsapp_mensagens SET status_entrega = 'enviado' WHERE id = ? AND COALESCE(status_entrega,'') = ''")->execute([$id]);
    }
    return (int)$id;
}

/** Credenciais Evolution do canal da caixa ('compra' = principal). */
function inboxCredenciaisEvolution(string $canal): ?array {
    require_once __DIR__ . '/whatsapp_evolution.php';
    if ($canal === 'vendas' && evolutionConfiguredVendas()) return evolutionCredenciaisVendas();
    if ($canal === 'financeiro' && evolutionConfiguredFinanceiro()) return evolutionCredenciaisFinanceiro();
    return null; // principal
}

/** A mensagem pode ser apagada pra todos? (nossa, com id, não apagada, dentro do prazo) */
function inboxPodeApagarMensagem(array $m): bool {
    return ($m['direcao'] ?? '') === 'out' && !empty($m['zapi_message_id']) && empty($m['apagada_em'])
        && strtotime((string)$m['created_at']) > time() - INBOX_APAGAR_PRAZO_HORAS * 3600;
}

/** Apaga pra todos uma mensagem enviada nessa caixa. */
function inboxApagarMensagem(int $mensagemId, string $telefone, string $canal): array {
    $db = getDB();
    $st = $db->prepare("SELECT * FROM whatsapp_mensagens WHERE id = ? AND telefone = ? AND canal = ?");
    $st->execute([$mensagemId, normalizarTelefone($telefone), $canal]);
    $m = $st->fetch();
    if (!$m) return ['ok' => false, 'erro' => 'Mensagem não encontrada nessa conversa.'];
    if (!empty($m['apagada_em'])) return ['ok' => true];
    if (!inboxPodeApagarMensagem($m)) {
        return ['ok' => false, 'erro' => 'Só dá pra apagar mensagem enviada pelo sistema, até ' . INBOX_APAGAR_PRAZO_HORAS . 'h depois do envio.'];
    }
    require_once __DIR__ . '/whatsapp_evolution.php';
    $cred = inboxCredenciaisEvolution($canal);
    $ok = evolutionApagarParaTodos((string)$m['telefone'], (string)$m['zapi_message_id'], $cred);
    if (!$ok && $cred !== null) $ok = evolutionApagarParaTodos((string)$m['telefone'], (string)$m['zapi_message_id']);
    if (!$ok) return ['ok' => false, 'erro' => 'O WhatsApp não apagou (' . (evolutionUltimoErro() ?: 'sem detalhe') . ').'];
    $db->prepare("UPDATE whatsapp_mensagens SET apagada_em = datetime('now','localtime') WHERE id = ?")->execute([$mensagemId]);
    return ['ok' => true];
}

/** Limpa a conversa SÓ do CRM (o cliente continua vendo tudo no WhatsApp). */
function inboxLimparConversa(string $telefone, string $canal): int {
    $st = getDB()->prepare("DELETE FROM whatsapp_mensagens WHERE telefone = ? AND canal = ?");
    $st->execute([normalizarTelefone($telefone), $canal]);
    return $st->rowCount();
}

/** Status de entrega/apagada das últimas mensagens enviadas + presença do cliente. */
function inboxStatusConversa(string $telefone, string $canal): array {
    $tel = normalizarTelefone($telefone);
    $st = getDB()->prepare("SELECT id, direcao, zapi_message_id, status_entrega, apagada_em, created_at FROM whatsapp_mensagens
                            WHERE telefone = ? AND canal = ? AND direcao = 'out' ORDER BY id DESC LIMIT 80");
    $st->execute([$tel, $canal]);
    $msgs = [];
    foreach ($st->fetchAll() as $r) {
        $msgs[] = ['id' => (int)$r['id'], 'status' => (string)$r['status_entrega'], 'apagada' => !empty($r['apagada_em']),
                   'pode_apagar' => inboxPodeApagarMensagem($r)];
    }
    return ['mensagens' => $msgs, 'presenca' => inboxPresenca($tel, inboxCredenciaisEvolution($canal)),
            'visto_por_ultimo' => inboxVistoPorUltimoTexto($tel)];
}

/**
 * Presença do cliente ('online', 'digitando', 'gravando' ou '' = sem
 * informação). Só vale aviso dos últimos 2 min. Marca a conversa como
 * "observada" (o webhook só guarda presença de quem está com a conversa
 * aberta) e, no máximo 1x por minuto, pede pro WhatsApp mandar a presença.
 */
function inboxPresenca(string $tel, ?array $cred = null): string {
    // Grava no máximo 1x por minuto (a tela consulta a cada 4s — evita escrita à toa no banco).
    if (time() - (int)getConfig('presenca_watch_' . $tel) > 60) setConfig('presenca_watch_' . $tel, (string)time());
    $ultimaAssinatura = (int)getConfig('presenca_assinada_' . $tel);
    if (time() - $ultimaAssinatura > 60) {
        setConfig('presenca_assinada_' . $tel, (string)time());
        try { require_once __DIR__ . '/whatsapp_evolution.php'; evolutionAssinarPresenca($tel, $cred); } catch (Throwable $e) {}
    }
    $v = (string)getConfig('presenca_' . $tel);
    if ($v === '' || !str_contains($v, '|')) return '';
    [$quando, $estado] = explode('|', $v, 2);
    if (time() - (int)$quando > 120) return '';
    return match ($estado) { 'available' => 'online', 'composing' => 'digitando', 'recording' => 'gravando', default => '' };
}

/** Ticks de entrega (✓ ✓✓ ✓✓ azul) de uma mensagem enviada. */
function inboxTicksHtml(array $m): string {
    if (($m['direcao'] ?? '') !== 'out') return '';
    $s = (string)($m['status_entrega'] ?? '');
    $t = match ($s) { 'enviado' => ['✓', 'Enviada', ''], 'entregue' => ['✓✓', 'Entregue', ''], 'lido' => ['✓✓', 'Visualizada', ' lido'], default => null };
    if (!$t) return '';
    return ' <span class="wpp-tick' . $t[2] . '" data-tick="' . (int)$m['id'] . '" title="' . $t[1] . '">' . $t[0] . '</span>';
}

/**
 * Processa um evento de status (MESSAGES_UPDATE) da Evolution — grava
 * entregue/lido pela id do WhatsApp. Aceita os 2 formatos conhecidos:
 * v2 {keyId, status:"READ"} e v1 [{key:{id}, update:{status:4}}]. Status
 * nunca volta pra trás (lido não vira entregue). Devolve quantos gravou.
 */
function inboxProcessarStatusEvolution($data): int {
    $itens = (is_array($data) && array_is_list($data)) ? $data : [$data];
    $ordem = ['enviado' => 1, 'entregue' => 2, 'lido' => 3];
    $n = 0;
    foreach ($itens as $it) {
        if (!is_array($it)) continue;
        $id = (string)($it['keyId'] ?? $it['key']['id'] ?? $it['messageId'] ?? '');
        $bruto = $it['status'] ?? $it['update']['status'] ?? null;
        $novo = match (true) {
            in_array($bruto, ['READ', 'PLAYED', 4, 5, '4', '5'], true) => 'lido',
            in_array($bruto, ['DELIVERY_ACK', 3, '3'], true) => 'entregue',
            in_array($bruto, ['SERVER_ACK', 2, '2'], true) => 'enviado',
            default => null,
        };
        if ($id === '' || $novo === null) continue;
        // 10/11/2026 — avisos de lead quente (admin/avisos_lead_quente.php)
        // não ficam em whatsapp_mensagens: grava entregue/lido na tabela deles.
        if ($novo !== 'enviado') {
            if (!function_exists('avisoLeadQuenteAtualizarStatus')) require_once __DIR__ . '/aviso_lead_quente.php';
            avisoLeadQuenteAtualizarStatus($id, $novo);
        }
        $db = getDB();
        $st = $db->prepare("SELECT id, status_entrega FROM whatsapp_mensagens WHERE zapi_message_id = ?");
        $st->execute([$id]);
        $r = $st->fetch();
        if (!$r || ($ordem[$r['status_entrega']] ?? 0) >= $ordem[$novo]) continue;
        $db->prepare("UPDATE whatsapp_mensagens SET status_entrega = ? WHERE id = ?")->execute([$novo, (int)$r['id']]);
        $n++;
    }
    return $n;
}

/**
 * Processa um evento PRESENCE_UPDATE da Evolution — só guarda presença de
 * telefone com conversa aberta no inbox nos últimos 3 min (evita gravar
 * presença de todo contato). Formato: {id, presences:{jid:{lastKnownPresence}}}.
 */
function inboxProcessarPresencaEvolution($data): int {
    if (!is_array($data)) return 0;
    $n = 0;
    foreach ((array)($data['presences'] ?? []) as $jid => $p) {
        $tel = normalizarTelefone(preg_replace('/@.*/', '', (string)$jid));
        if (strlen($tel) < 12) continue;
        if (time() - (int)getConfig('presenca_watch_' . $tel) > 180) continue;
        $estado = (string)($p['lastKnownPresence'] ?? '');
        if ($estado === '') continue;
        // Mesmo estado gravado há menos de 15s: não grava de novo (o WhatsApp
        // repete "digitando" várias vezes por segundo — cada uma era uma escrita).
        $atual = (string)getConfig('presenca_' . $tel);
        if ($atual !== '' && str_contains($atual, '|')) {
            [$qAtual, $eAtual] = explode('|', $atual, 2);
            if ($eAtual === $estado && time() - (int)$qAtual < 15) continue;
        }
        setConfig('presenca_' . $tel, time() . '|' . $estado);
        // "Visto por último" (09/11/2026): online/digitando = agora; saiu
        // ('unavailable') = o lastSeen que o WhatsApp manda, se o cliente
        // não esconde isso na privacidade.
        if ($estado === 'unavailable') {
            $ls = (int)($p['lastSeen'] ?? 0);
            if ($ls > 1000000000 && $ls <= time() + 60) setConfig('presenca_ultimo_' . $tel, (string)$ls);
        } else {
            setConfig('presenca_ultimo_' . $tel, (string)time());
        }
        $n++;
    }
    return $n;
}

/** "hoje às 14:32" / "ontem às 09:10" / "07/11 às 18:00" — '' se nunca visto. */
function inboxVistoPorUltimoTexto(string $tel): string {
    $ts = (int)getConfig('presenca_ultimo_' . $tel);
    if ($ts <= 0) return '';
    $dia = date('Y-m-d', $ts);
    $quando = $dia === date('Y-m-d') ? 'hoje' : ($dia === date('Y-m-d', strtotime('-1 day')) ? 'ontem' : date('d/m', $ts));
    return $quando . ' às ' . date('H:i', $ts);
}
