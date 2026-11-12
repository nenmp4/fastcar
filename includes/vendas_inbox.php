<?php
/**
 * WhatsApp Box do módulo de VENDAS (17/09/2026, pedido José/Jean: "ibox do
 * vendedor igual compras") — espelha includes/whatsapp_inbox.php
 * (compra), mas as conversas vêm de `vendas`/`comprador_telefone`, não de
 * `clientes`/`oportunidades` (comprador de revenda não tem cadastro em
 * `clientes`, ver includes/vendas.php).
 *
 * Escopo intencionalmente mais enxuto que o inbox de compra nesta 1ª
 * versão (evitar over-engineering sem pedido real, mesmo espírito já
 * documentado no CLAUDE.md pro inbox de compra): SEM busca ao vivo de
 * foto de perfil do WhatsApp (zapiBuscarContato() — avatar sempre em
 * iniciais) — fica como possível próxima iteração se a equipe sentir
 * falta, igual outros recursos do inbox de compra que também começaram
 * de fora nesse espírito.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/vendas.php';
require_once __DIR__ . '/usuarios.php';
require_once __DIR__ . '/whatsapp_inbox.php'; // renderizarMidiaWhatsapp(), recuperarMidiaMensagemWhatsapp()
require_once dirname(__DIR__) . '/chatbot-whatsapp/includes/mensagens.php'; // registrarMensagem(), iaPausada(), pausarIA(), retomarIA()

/**
 * Lista as conversas de vendas (1 linha por telefone de comprador que já
 * trocou pelo menos 1 mensagem), última mensagem, não lidas, status da IA.
 * $responsavelFiltro: null = vê tudo (super_admin/supervisor); id = só
 * conversa de comprador onde ESSE vendedor é responsavel_id em pelo menos
 * 1 negociação — mesmo padrão "Minhas/Todas" do inbox de compra.
 */
function listarConversasVendas(string $busca = '', ?int $responsavelFiltro = null, int $limite = 100, bool $soNaoLidas = false): array {
    $db = getDB();
    $whereBusca = '';
    $params = [];
    if ($busca !== '') {
        $whereBusca = ' AND (v.comprador_nome LIKE ? OR ultv.comprador_telefone LIKE ?)';
        $like = '%' . $busca . '%';
        $params = [$like, $like];
    }
    $whereResponsavel = '';
    if ($responsavelFiltro !== null) {
        $whereResponsavel = ' AND EXISTS (SELECT 1 FROM vendas v2 WHERE v2.comprador_telefone = ultv.comprador_telefone AND v2.responsavel_id = ?)';
        $params[] = $responsavelFiltro;
    }
    // 24/10/2026, "tem aparecer inbox da conversa pro vendedor" — a base é a
    // NEGOCIAÇÃO (1 linha por telefone de comprador), não a mensagem: lead
    // atribuído a um vendedor aparece na caixa dele mesmo antes de ter
    // qualquer mensagem trocada (ex: acabou de ser atribuído em massa ou
    // criado manualmente). Nunca mistura com conversa só do funil de compra.
    $sql = "
        SELECT ultv.comprador_telefone AS telefone,
               COALESCE(m.mensagem, '(sem mensagens ainda — clique pra iniciar a conversa)') AS ultima_mensagem,
               COALESCE(m.direcao, '') AS ultima_direcao,
               COALESCE(m.tipo, 'text') AS ultima_tipo,
               COALESCE(m.created_at, v.created_at) AS ultima_em,
               v.comprador_nome,
               -- 12/11/2026: não lida só com a IA pausada OU cliente esperando > 5 min (IA falhou).
               CASE WHEN COALESCE(ws.ia_pausada, 0) = 1 OR (m.direcao = 'in' AND m.created_at <= datetime('now', 'localtime', '-5 minutes')) THEN (SELECT COUNT(*) FROM whatsapp_mensagens m2 WHERE m2.telefone = ultv.comprador_telefone AND m2.canal = 'vendas' AND m2.direcao = 'in' AND m2.lida = 0) ELSE 0 END AS nao_lidas,
               COALESCE(ws.ia_pausada, 0) AS ia_pausada
        FROM (SELECT comprador_telefone, MAX(id) AS max_id FROM vendas
              WHERE comprador_telefone IS NOT NULL AND comprador_telefone != ''
              GROUP BY comprador_telefone) ultv
        JOIN vendas v ON v.id = ultv.max_id
        LEFT JOIN (SELECT telefone, MAX(id) AS max_id FROM whatsapp_mensagens WHERE canal = 'vendas' GROUP BY telefone) ult
          ON ult.telefone = ultv.comprador_telefone
        LEFT JOIN whatsapp_mensagens m ON m.id = ult.max_id
        LEFT JOIN whatsapp_sessoes ws ON ws.telefone = ultv.comprador_telefone
        WHERE (m.id IS NOT NULL OR v.etapa IN ('" . implode("','", ETAPAS_VENDA_ATIVAS) . "'))
        {$whereBusca}{$whereResponsavel}" . ($soNaoLidas ? "
          AND (COALESCE(ws.ia_pausada, 0) = 1 OR (m.direcao = 'in' AND m.created_at <= datetime('now', 'localtime', '-5 minutes')))
          AND EXISTS (SELECT 1 FROM whatsapp_mensagens m3 WHERE m3.telefone = ultv.comprador_telefone AND m3.canal = 'vendas' AND m3.direcao = 'in' AND m3.lida = 0)" : '') . "

        ORDER BY COALESCE(m.created_at, v.created_at) DESC
        LIMIT ?
    ";
    $params[] = $limite;
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/** Quantas conversas de vendas têm mensagem não lida (chip "Não lidas"). */
function contarConversasNaoLidasVendas(?int $responsavelFiltro): int {
    return count(listarConversasVendas('', $responsavelFiltro, 1000, true));
}

/**
 * "+ Iniciar conversa" (09/11/2026, igual ao inbox de compra): telefone sem
 * nenhuma negociação vira um lead manual do vendedor que abriu — sem isso a
 * conversa nunca aparece (a lista de vendas nasce da negociação).
 */
function iniciarConversaVendas(string $telefone, int $usuarioId, string $perfil): ?string {
    $tel = normalizarTelefone($telefone);
    if (!$tel || strlen($tel) < 12) return null;
    $st = getDB()->prepare("SELECT 1 FROM vendas WHERE comprador_telefone = ? LIMIT 1");
    $st->execute([$tel]);
    if (!$st->fetchColumn()) {
        $r = criarOuAbrirVendaLead($tel, '');
        getDB()->prepare("UPDATE vendas SET origem = 'manual', responsavel_id = COALESCE(responsavel_id, ?) WHERE id = ?")
            ->execute([$perfil === 'vendedor' ? $usuarioId : null, (int)$r['venda_id']]);
    }
    return $tel;
}

/** "🔒 Encerrar atendimento": cancela a negociação ativa desse comprador (vai pra ❌ Encerradas). */
function encerrarAtendimentoVendas(string $telefone, string $motivo, int $usuarioId): array {
    $ph = implode(',', array_fill(0, count(ETAPAS_VENDA_ATIVAS), '?'));
    $st = getDB()->prepare("SELECT id FROM vendas WHERE comprador_telefone = ? AND etapa IN ({$ph}) ORDER BY id DESC LIMIT 1");
    $st->execute([normalizarTelefone($telefone), ...ETAPAS_VENDA_ATIVAS]);
    $id = (int)$st->fetchColumn();
    if (!$id) return ['ok' => false, 'erro' => 'Esse comprador não tem negociação ativa pra encerrar.'];
    mudarEtapaVenda($id, 'cancelada', $usuarioId, $motivo); // grava motivo_cancelamento + histórico
    return ['ok' => true, 'venda_id' => $id];
}

/** "🗑️ Excluir conversa" (só super_admin): apaga as mensagens do canal de vendas desse telefone. */
function excluirConversaVendas(string $telefone): void {
    getDB()->prepare("DELETE FROM whatsapp_mensagens WHERE telefone = ? AND canal = 'vendas'")->execute([normalizarTelefone($telefone)]);
}

/**
 * Vendedor pode ver/responder essa conversa? null = super_admin/supervisor,
 * sem restrição (supervisor ainda assim nunca AGE, ver guard em
 * admin/vendas_inbox.php). Mesmo espírito de usuarioPodeVerConversaWhatsapp()
 * — a listagem filtrada sozinha não basta, protege POST forjado também.
 */
function usuarioPodeVerConversaVendas(string $telefone, ?int $responsavelFiltro): bool {
    if ($responsavelFiltro === null) return true;
    $db = getDB();
    $stmt = $db->prepare("SELECT EXISTS (SELECT 1 FROM vendas WHERE comprador_telefone = ? AND responsavel_id = ?)");
    $stmt->execute([normalizarTelefone($telefone), $responsavelFiltro]);
    return (bool)$stmt->fetchColumn();
}

/**
 * Envia mensagem manual pela caixa de vendas — grava histórico e PAUSA a
 * IA (regra #4), igual enviarMensagemManualWhatsapp() (compra), mas manda
 * pela instância DEDICADA de vendas (zapiCredenciaisVendas()).
 */
function enviarMensagemManualVendas(string $telefone, string $texto, int $usuarioId): array {
    whatsappCanalRegistro('vendas');
    $telNorm = normalizarTelefone($telefone);
    $texto = trim($texto);
    if ($texto === '') {
        return ['ok' => false, 'erro' => 'Mensagem vazia.'];
    }
    $usuario = buscarUsuario($usuarioId);
    $nomeVendedor = trim((string)($usuario['nome'] ?? ''));
    $textoAssinado = $nomeVendedor !== '' ? "*{$nomeVendedor}:*\n{$texto}" : $texto;
    if (!zapiEnviarTexto($telNorm, $textoAssinado, zapiCredenciaisVendas())) {
        return ['ok' => false, 'erro' => 'Falha ao enviar — confira a instância de vendas (ou o fallback Meta) em Configurações.' . whatsappDetalheUltimoErro()];
    }
    $id = inboxRegistrarEnviada($telNorm, $texto, 'text', $usuarioId);
    pausarIA($telNorm);
    return ['ok' => true, 'id' => $id];
}

/* ─────────────────────────────────────────────────────────────────────────
 * 06/11/2026, "adicione opção de enviar fotos no inbox do vendedor, cria aba
 * em cards no inbox com carros do estoque" (mockup aprovado: "top").
 * 📷 manda uma foto do aparelho; 🚘 Estoque manda fotos do catálogo de
 * revenda de um veículo DISPONÍVEL. Tudo pela instância de vendas, fica na
 * conversa como foto (servida por admin/ver_midia_vendas.php) e pausa a IA.
 * ───────────────────────────────────────────────────────────────────────── */
require_once __DIR__ . '/documentos.php'; // UPLOADS_DIR, lerConteudoArquivoDocumento()

const VENDAS_INBOX_FOTO_MIMES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
const VENDAS_INBOX_MAX_ITENS_ESTOQUE = 10;

function _vendasInboxAnexarArquivo(int $msgId, string $driveFileId, string $arquivoUrl): void {
    if ($msgId <= 0) return;
    getDB()->prepare("UPDATE whatsapp_mensagens SET drive_file_id = ?, arquivo_url = ? WHERE id = ?")
        ->execute([$driveFileId, $arquivoUrl, $msgId]);
}

function _vendasInboxAssinatura(int $usuarioId): string {
    $nome = trim((string)(buscarUsuario($usuarioId)['nome'] ?? ''));
    return $nome !== '' ? "*{$nome}:*" : '';
}

/** 📷 Foto do aparelho do vendedor. */
function enviarFotoManualVendas(string $telefone, string $conteudoBase64, string $legenda, int $usuarioId): array {
    whatsappCanalRegistro('vendas');
    $telNorm = normalizarTelefone($telefone);
    $bytes = base64_decode($conteudoBase64, true);
    if ($bytes === false || $bytes === '') return ['ok' => false, 'erro' => 'Nenhuma foto selecionada.'];
    if (strlen($bytes) > WHATSAPP_MIDIA_MAX_BYTES) {
        return ['ok' => false, 'erro' => 'Foto maior que ' . (int)(WHATSAPP_MIDIA_MAX_BYTES / 1024 / 1024) . 'MB.'];
    }
    // Nunca confia no tipo que o navegador diz — olha os bytes.
    $mime = (string)(new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
    if (!isset(VENDAS_INBOX_FOTO_MIMES[$mime])) return ['ok' => false, 'erro' => 'Só foto (JPG, PNG ou WEBP).'];

    $legenda = trim(clean($legenda));
    $caption = trim(_vendasInboxAssinatura($usuarioId) . ($legenda !== '' ? "\n{$legenda}" : ''));
    $dataUri = 'data:' . $mime . ';base64,' . base64_encode($bytes);
    if (!zapiEnviarImagem($telNorm, $dataUri, $caption, zapiCredenciaisVendas())) {
        return ['ok' => false, 'erro' => 'Falha ao enviar a foto — confira a instância de vendas em Configurações.' . whatsappDetalheUltimoErro()];
    }

    // Cópia local pra foto aparecer na conversa (best-effort).
    $rel = 'whatsapp_vendas/' . date('Y-m') . '/' . bin2hex(random_bytes(8)) . '.' . VENDAS_INBOX_FOTO_MIMES[$mime];
    $arquivoUrl = '';
    if (@is_dir(dirname(UPLOADS_DIR . '/' . $rel)) || @mkdir(dirname(UPLOADS_DIR . '/' . $rel), 0775, true)) {
        if (@file_put_contents(UPLOADS_DIR . '/' . $rel, $bytes) !== false) $arquivoUrl = $rel;
    }
    $id = inboxRegistrarEnviada($telNorm, '📷 Foto' . ($legenda !== '' ? ": {$legenda}" : ''), 'image', $usuarioId);
    _vendasInboxAnexarArquivo($id, '', $arquivoUrl);
    pausarIA($telNorm);
    return ['ok' => true, 'id' => $id];
}

/** Veículos disponíveis com o catálogo de fotos — cards do 🚘 Estoque. */
/** 📎 Anexo (09/11/2026, igual ao inbox de compra): foto ou documento. */
const VENDAS_INBOX_DOC_MIMES = [
    'application/pdf' => 'pdf', 'application/msword' => 'doc', 'text/plain' => 'txt', 'text/csv' => 'csv',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
    'application/vnd.ms-excel' => 'xls', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
];
function enviarAnexoManualVendas(string $telefone, string $conteudoBase64, string $nomeArquivo, string $legenda, int $usuarioId): array {
    $bytes = base64_decode($conteudoBase64, true);
    if ($bytes === false || $bytes === '') return ['ok' => false, 'erro' => 'Nenhum arquivo selecionado.'];
    $mime = (string)(new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
    if (isset(VENDAS_INBOX_FOTO_MIMES[$mime])) return enviarFotoManualVendas($telefone, $conteudoBase64, $legenda, $usuarioId);
    $ext = VENDAS_INBOX_DOC_MIMES[$mime] ?? strtolower(pathinfo($nomeArquivo, PATHINFO_EXTENSION));
    if ($mime === 'application/zip' && in_array($ext, ['docx', 'xlsx'], true)) $mime = array_search($ext, VENDAS_INBOX_DOC_MIMES, true);
    if (!in_array($ext, VENDAS_INBOX_DOC_MIMES, true)) return ['ok' => false, 'erro' => 'Tipo de arquivo não aceito (foto, PDF, Word, Excel ou texto).'];
    if (strlen($bytes) > WHATSAPP_MIDIA_MAX_BYTES) {
        return ['ok' => false, 'erro' => 'Arquivo maior que ' . (int)(WHATSAPP_MIDIA_MAX_BYTES / 1024 / 1024) . 'MB.'];
    }
    whatsappCanalRegistro('vendas');
    $telNorm = normalizarTelefone($telefone);
    $nome = trim(preg_replace('/[^\w.\- ]+/u', '_', $nomeArquivo)) ?: ('documento.' . $ext);
    if (!zapiEnviarDocumento($telNorm, 'data:' . $mime . ';base64,' . base64_encode($bytes), $nome, $ext, zapiCredenciaisVendas())) {
        return ['ok' => false, 'erro' => 'Falha ao enviar o arquivo — confira a instância de vendas em Configurações.' . whatsappDetalheUltimoErro()];
    }
    $rel = 'whatsapp_vendas/' . date('Y-m') . '/' . bin2hex(random_bytes(8)) . '.' . $ext;
    $arquivoUrl = '';
    if (@is_dir(dirname(UPLOADS_DIR . '/' . $rel)) || @mkdir(dirname(UPLOADS_DIR . '/' . $rel), 0775, true)) {
        if (@file_put_contents(UPLOADS_DIR . '/' . $rel, $bytes) !== false) $arquivoUrl = $rel;
    }
    $id = inboxRegistrarEnviada($telNorm, '📎 ' . $nome, 'document', $usuarioId);
    _vendasInboxAnexarArquivo($id, '', $arquivoUrl);
    pausarIA($telNorm);
    return ['ok' => true, 'id' => $id];
}

/** 🎤 Áudio gravado no navegador (09/11/2026, "adicionar áudio no inbox da venda"). */
function enviarAudioManualVendas(string $telefone, string $audioBase64, string $mime, int $usuarioId): array {
    whatsappCanalRegistro('vendas');
    $telNorm = normalizarTelefone($telefone);
    if (!str_starts_with($mime, 'audio/')) return ['ok' => false, 'erro' => 'Arquivo não reconhecido como áudio.'];
    $bytes = base64_decode($audioBase64, true);
    if ($bytes === false || $bytes === '') return ['ok' => false, 'erro' => 'Nenhum áudio gravado.'];
    if (strlen($bytes) > WHATSAPP_MIDIA_MAX_BYTES) {
        return ['ok' => false, 'erro' => 'Áudio maior que ' . (int)(WHATSAPP_MIDIA_MAX_BYTES / 1024 / 1024) . 'MB.'];
    }
    if (!zapiEnviarAudio($telNorm, 'data:' . $mime . ';base64,' . base64_encode($bytes), zapiCredenciaisVendas())) {
        return ['ok' => false, 'erro' => 'Falha ao enviar o áudio — confira a instância de vendas em Configurações.' . whatsappDetalheUltimoErro()];
    }
    // Cópia local pro áudio tocar na conversa (best-effort, servido por ver_midia_vendas.php).
    $ext = str_contains($mime, 'ogg') ? 'ogg' : (str_contains($mime, 'mp4') ? 'm4a' : 'webm');
    $rel = 'whatsapp_vendas/' . date('Y-m') . '/' . bin2hex(random_bytes(8)) . '.' . $ext;
    $arquivoUrl = '';
    if (@is_dir(dirname(UPLOADS_DIR . '/' . $rel)) || @mkdir(dirname(UPLOADS_DIR . '/' . $rel), 0775, true)) {
        if (@file_put_contents(UPLOADS_DIR . '/' . $rel, $bytes) !== false) $arquivoUrl = $rel;
    }
    $id = inboxRegistrarEnviada($telNorm, '🎤 Áudio', 'audio', $usuarioId);
    _vendasInboxAnexarArquivo($id, '', $arquivoUrl);
    pausarIA($telNorm);
    return ['ok' => true, 'id' => $id];
}

function listarEstoqueParaInboxVendas(): array {
    $db = getDB();
    $saida = [];
    $stmtM = $db->prepare("SELECT id, tipo, legenda FROM veiculo_midias_revenda WHERE oportunidade_id = ? ORDER BY tipo, id");
    foreach (listarFrotaDisponivelParaVenda(300) as $v) {
        $stmtM->execute([(int)$v['oportunidade_id']]);
        $midias = array_map(fn($m) => ['id' => (int)$m['id'], 'tipo' => $m['tipo'], 'legenda' => (string)$m['legenda']], $stmtM->fetchAll());
        $capa = null;
        foreach ($midias as $m) { if ($m['tipo'] === 'foto') { $capa = $m['id']; break; } }
        $saida[] = [
            'id' => (int)$v['oportunidade_id'],
            'titulo' => trim(($v['veiculo_marca'] ?? '') . ' ' . ($v['veiculo_modelo'] ?? '')) ?: 'Veículo sem modelo',
            'ano' => (string)($v['veiculo_ano'] ?? ''),
            'placa' => (string)($v['veiculo_placa'] ?? ''),
            'moto' => ($v['tipo_veiculo'] ?? '') === 'moto',
            'capa' => $capa,
            'midias' => $midias,
        ];
    }
    return $saida;
}

/** 🚘 Manda as fotos escolhidas de um veículo do estoque. */
function enviarFotosEstoqueVendas(string $telefone, int $oportunidadeId, array $midiaIds, string $legenda, int $usuarioId): array {
    whatsappCanalRegistro('vendas');
    $telNorm = normalizarTelefone($telefone);
    if (!veiculoDisponivelParaVenda($oportunidadeId)) return ['ok' => false, 'erro' => 'Esse veículo não está mais disponível no estoque.'];
    $midiaIds = array_values(array_unique(array_filter(array_map('intval', $midiaIds))));
    if (!$midiaIds) return ['ok' => false, 'erro' => 'Marque pelo menos uma foto.'];
    if (count($midiaIds) > VENDAS_INBOX_MAX_ITENS_ESTOQUE) return ['ok' => false, 'erro' => 'No máximo ' . VENDAS_INBOX_MAX_ITENS_ESTOQUE . ' por vez.'];

    $db = getDB();
    $ph = implode(',', array_fill(0, count($midiaIds), '?'));
    $st = $db->prepare("SELECT * FROM veiculo_midias_revenda WHERE oportunidade_id = ? AND id IN ($ph) ORDER BY tipo, id");
    $st->execute(array_merge([$oportunidadeId], $midiaIds));
    $midias = $st->fetchAll();
    if (!$midias) return ['ok' => false, 'erro' => 'Fotos não encontradas pra esse veículo.'];

    $legenda = trim(clean($legenda));
    $cred = zapiCredenciaisVendas();
    $assinatura = _vendasInboxAssinatura($usuarioId);
    $enviados = 0;
    $falhas = 0;
    $ultimoId = 0;
    $total = count($midias);
    foreach ($midias as $i => $m) {
        $arq = lerConteudoArquivoDocumento($m['drive_file_id'] ?: null, $m['arquivo_url'] ?: null);
        if (!$arq || strlen($arq['content']) > WHATSAPP_MIDIA_MAX_BYTES) { $falhas++; continue; }
        // Assinatura + legenda só na 1ª; as outras vão sem texto (não vira spam de legenda repetida).
        $caption = $enviados === 0 ? trim($assinatura . ($legenda !== '' ? "\n{$legenda}" : '')) : '';
        $dataUri = 'data:' . $arq['mime'] . ';base64,' . base64_encode($arq['content']);
        $ehVideo = $m['tipo'] === 'video';
        $ok = $ehVideo ? zapiEnviarVideo($telNorm, $dataUri, $caption, $cred) : zapiEnviarImagem($telNorm, $dataUri, $caption, $cred);
        if (!$ok) { $falhas++; continue; }
        $enviados++;
        $texto = ($ehVideo ? '🎥 ' : '📷 ') . ($legenda !== '' ? $legenda : 'Foto do estoque') . " ({$enviados}/{$total})";
        $ultimoId = inboxRegistrarEnviada($telNorm, $texto, $ehVideo ? 'video' : 'image', $usuarioId);
        _vendasInboxAnexarArquivo($ultimoId, (string)$m['drive_file_id'], (string)$m['arquivo_url']);
    }
    if ($enviados === 0) {
        return ['ok' => false, 'erro' => 'Nenhuma foto saiu — confira a instância de vendas em Configurações.' . whatsappDetalheUltimoErro()];
    }
    pausarIA($telNorm);
    return ['ok' => true, 'enviados' => $enviados, 'falhas' => $falhas, 'id' => $ultimoId];
}

/** 🔗 Manda o link público do carro (public/veiculo.php — com og:image, vira card com foto no WhatsApp). */
function enviarLinkEstoqueVendas(string $telefone, int $oportunidadeId, string $legenda, int $usuarioId): array {
    whatsappCanalRegistro('vendas');
    $telNorm = normalizarTelefone($telefone);
    if (!veiculoDisponivelParaVenda($oportunidadeId)) return ['ok' => false, 'erro' => 'Esse veículo não está mais disponível no estoque.'];
    $url = veiculoLinkPublicoUrl($oportunidadeId, $usuarioId);
    $legenda = trim(clean($legenda));
    $texto = ($legenda !== '' ? "{$legenda}\n" : '') . "Fotos e detalhes aqui 👇\n{$url}";
    $assinatura = _vendasInboxAssinatura($usuarioId);
    if (!zapiEnviarTexto($telNorm, trim($assinatura . "\n" . $texto), zapiCredenciaisVendas())) {
        return ['ok' => false, 'erro' => 'Falha ao enviar — confira a instância de vendas em Configurações.' . whatsappDetalheUltimoErro()];
    }
    $id = inboxRegistrarEnviada($telNorm, $texto, 'text', $usuarioId);
    pausarIA($telNorm);
    return ['ok' => true, 'id' => $id];
}

/**
 * Foto/áudio/vídeo/arquivo de uma mensagem do WhatsApp Vendas (09/11/2026) —
 * mesma lógica do inbox de compra (renderizarMidiaWhatsapp(), inclusive o
 * "🔄 carregando" que baixa de novo mídia que não ficou salva), mas servido
 * por admin/ver_midia_vendas.php, a rota com a trava da caixa de vendas.
 */
function renderizarMidiaVendas(array $m): string {
    require_once __DIR__ . '/whatsapp_inbox.php';
    $html = renderizarMidiaWhatsapp($m);
    return str_replace('/admin/ver_midia_whatsapp.php?id=', '/admin/ver_midia_vendas.php?id=', $html);
}
