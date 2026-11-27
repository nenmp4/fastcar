<?php
/**
 * Log de auditoria — versão enxuta (20/09/2026, "temos ter modulo auditoria
 * igual do jutidicosass" → "vai atrapalhar a operação?" → confirmado "sim"
 * pra ir com a versão enxuta em vez do módulo completo). Só os eventos que
 * importam pra segurança/rastreabilidade — nunca "logar toda escrita do
 * sistema", que adicionaria carga de escrita real bem na área onde o
 * projeto já teve incidentes reais de "database is locked" (SQLite sob
 * concorrência de webhook+admin+cron, ver CLAUDE.md).
 *
 * Sempre best-effort (`auditoriaRegistrar()` nunca lança) — mesmo espírito
 * de toda notificação/e-mail do projeto: auditoria nunca pode travar o
 * fluxo principal, nem que o próprio INSERT falhe.
 *
 * Eventos cobertos nesta 1ª versão: login (`login`), login com senha
 * errada (`login_falha`), bloqueio automático de conta (`login_bloqueado`),
 * logout (`logout`), usuário criado/editado/perfil promovido/bloqueado ou
 * desbloqueado/senha redefinida (`usuario_*`), conversa de WhatsApp
 * excluída (`conversa_excluida`), dado sensível de cliente editado
 * (`cliente_dado_editado`).
 */
require_once __DIR__ . '/db.php';

/** IP real do visitante — atrás da Cloudflare (ver install/SETUP_VPS.md), REMOTE_ADDR sozinho seria só o IP da Cloudflare, nunca o do cliente. */
function auditoriaClienteIp(): string {
    return (string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '');
}

/**
 * Grava 1 linha de auditoria. Nunca lança — se o INSERT falhar por
 * qualquer motivo (ex: contenção real de escrita), só não registra o
 * evento, nunca derruba a ação que estava sendo auditada.
 */
function auditoriaRegistrar(string $evento, ?int $usuarioId, string $usuarioNome, string $alvoTipo = '', ?int $alvoId = null, string $detalhe = ''): void {
    try {
        getDB()->prepare("
            INSERT INTO auditoria (usuario_id, usuario_nome, evento, alvo_tipo, alvo_id, detalhe, ip)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ")->execute([$usuarioId, $usuarioNome, $evento, $alvoTipo, $alvoId, $detalhe, auditoriaClienteIp()]);
    } catch (Throwable $e) {
        // auditoria nunca pode travar o fluxo principal
    }
}

/** Janela em que a MESMA pessoa abrindo o MESMO documento conta 1 vez só (recarregar a página não polui o histórico). */
const AUDITORIA_DOC_ABERTO_DEDUP_MIN = 30;

/**
 * Registra que o usuário logado abriu um documento sensível (18/11/2026,
 * "registro hora e dia que aquele documento foi aberto" → "só os documentos
 * sensíveis"). Chamado pelos endpoints que servem documento de cliente,
 * contrato, comprovante financeiro, consulta ZapCar, termo/assinatura de
 * vistoria e backup — NUNCA pelas mídias que carregam sozinhas na tela
 * (fotos/áudios do WhatsApp Box, fotos de vistoria/catálogo): essas gerariam
 * milhares de linhas sem ninguém ter escolhido abrir nada, e escrita a cada
 * carga de tela é exatamente o padrão que causou "database is locked" antes.
 *
 * $alvoTipo/$alvoId identificam o DOCUMENTO (não o cliente), pra deduplicar
 * por documento; o cliente/negociação vai legível no $detalhe. Mesma pessoa +
 * mesmo documento dentro de AUDITORIA_DOC_ABERTO_DEDUP_MIN = 1 linha só.
 * Best-effort: nunca lança, nunca impede o documento de abrir.
 */
function auditoriaRegistrarDocumentoAberto(string $alvoTipo, int $alvoId, string $detalhe): void {
    _auditoriaRegistrarDocumentoDedup('documento_aberto', $alvoTipo, $alvoId, $detalhe);
}

/**
 * Tentativa de abrir documento de cliente FORA da carteira (18/11/2026,
 * "ajuda evitar vazamento?" → trava por carteira, ver
 * includes/acesso_documentos.php). Mesma dedup do documento aberto — trocar
 * o id na URL várias vezes conta 1 linha por documento — e também entra na
 * contagem do alerta de volume (quem fica testando id é exatamente o caso).
 */
function auditoriaRegistrarDocumentoNegado(string $alvoTipo, int $alvoId, string $detalhe): void {
    _auditoriaRegistrarDocumentoDedup('documento_acesso_negado', $alvoTipo, $alvoId, $detalhe);
}

/** Corpo comum dos 2 acima. Best-effort, nunca lança. */
function _auditoriaRegistrarDocumentoDedup(string $evento, string $alvoTipo, int $alvoId, string $detalhe): void {
    try {
        $usuarioId = isset($_SESSION['admin_id']) ? (int)$_SESSION['admin_id'] : null;
        $usuarioNome = (string)($_SESSION['admin_nome'] ?? '');
        if (!$usuarioId) return;
        $stmt = getDB()->prepare("
            SELECT 1 FROM auditoria
            WHERE usuario_id = ? AND evento = ? AND alvo_tipo = ? AND alvo_id = ?
              AND created_at >= datetime('now', 'localtime', ?)
            LIMIT 1
        ");
        $stmt->execute([$usuarioId, $evento, $alvoTipo, $alvoId, '-' . AUDITORIA_DOC_ABERTO_DEDUP_MIN . ' minutes']);
        if ($stmt->fetchColumn()) return;
        auditoriaRegistrar($evento, $usuarioId, $usuarioNome, $alvoTipo, $alvoId, $detalhe);
        auditoriaVerificarVolumeDocumentos($usuarioId, $usuarioNome);
    } catch (Throwable $e) {
        // nunca impede o documento de abrir
    }
}

/** Padrão do alerta: documentos diferentes abertos (ou barrados) por 1 pessoa em 1 hora. */
const AUDITORIA_ALERTA_DOCS_PADRAO = 15;

/** Limite configurado em Auditoria (0 = alerta desligado). */
function auditoriaLimiteAlertaDocumentos(): int {
    $v = getConfig('alerta_documentos_limite_hora');
    if ($v === null || $v === '' || !ctype_digit((string)$v)) return AUDITORIA_ALERTA_DOCS_PADRAO;
    return (int)$v;
}

/** Números que recebem o alerta — vazio cai nos números de notificação (onde está o do Jean). */
function auditoriaNumerosAlertaDocumentos(): array {
    $lista = (string)(getConfig('alerta_documentos_whatsapp') ?: getConfig('notificacao_leads_whatsapp') ?: '');
    return array_values(array_filter(array_map('trim', preg_split('/[,;\n]+/', $lista))));
}

/**
 * Alerta de volume (18/11/2026): passou do limite de documentos diferentes
 * numa hora → WhatsApp pros números de alerta + sino dos super admins. Padrão
 * típico de quem está copiando a base. 1 alerta por pessoa por hora. Só roda
 * quando uma linha NOVA entra (a dedup de 30min já filtrou recarga de página),
 * então não pesa na abertura normal de documento.
 */
function auditoriaVerificarVolumeDocumentos(int $usuarioId, string $usuarioNome): void {
    try {
        $limite = auditoriaLimiteAlertaDocumentos();
        if ($limite <= 0) return;
        $db = getDB();
        $st = $db->prepare("
            SELECT SUM(evento = 'documento_aberto') AS abertos, SUM(evento = 'documento_acesso_negado') AS negados
            FROM auditoria
            WHERE usuario_id = ? AND evento IN ('documento_aberto', 'documento_acesso_negado')
              AND created_at >= datetime('now', 'localtime', '-60 minutes')
        ");
        $st->execute([$usuarioId]);
        $r = $st->fetch() ?: [];
        $abertos = (int)($r['abertos'] ?? 0);
        $negados = (int)($r['negados'] ?? 0);
        if ($abertos + $negados < $limite) return;

        $chave = 'alerta_docs_enviado_' . $usuarioId;
        $ultimo = (string)(getConfig($chave) ?: '');
        if ($ultimo !== '' && strtotime($ultimo) > time() - 3600) return;
        setConfig($chave, date('Y-m-d H:i:s')); // antes de mandar: 2 requests juntas nunca alertam 2x

        $nome = $usuarioNome !== '' ? $usuarioNome : 'usuário #' . $usuarioId;
        $base = function_exists('appBaseUrl') ? appBaseUrl() : 'https://sistema.fastcar.solutions';
        $url = '/admin/auditoria.php?q=' . rawurlencode($nome);
        $msg = "🚨 *Fastcar — muitos documentos abertos*\n\n"
             . "{$nome} abriu {$abertos} documento(s) de clientes na última hora"
             . ($negados ? " e teve {$negados} tentativa(s) barrada(s) por estar fora da carteira" : '')
             . " (limite: {$limite}).\n\nConfira na Auditoria: {$base}{$url}";

        auditoriaRegistrar('alerta_volume_documentos', $usuarioId, $usuarioNome, 'usuario', $usuarioId,
            "{$abertos} aberto(s) + {$negados} barrado(s) em 1h (limite {$limite})");

        require_once __DIR__ . '/whatsapp_config.php';
        foreach (auditoriaNumerosAlertaDocumentos() as $numero) {
            try { zapiEnviarTextoInterno($numero, $msg); } catch (Throwable $e) {}
        }
        require_once __DIR__ . '/notificacoes.php';
        $admins = $db->query("SELECT id FROM usuarios WHERE perfil = 'super_admin' AND bloqueado = 0")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($admins as $adminId) {
            if ((int)$adminId === $usuarioId) continue;
            try {
                criarNotificacao((int)$adminId, 'alerta_documentos', '🚨 Muitos documentos abertos',
                    "{$nome}: {$abertos} aberto(s)" . ($negados ? ", {$negados} barrado(s)" : '') . ' na última hora', $url);
            } catch (Throwable $e) {}
        }
    } catch (Throwable $e) {
        // alerta é best-effort
    }
}

/** "Fulano (oportunidade #12)" / "Comprador (venda #7)" pro detalhe — vazio se não achar. */
function auditoriaContextoNegocio(?int $oportunidadeId, ?int $vendaId = null): string {
    try {
        $db = getDB();
        if ($vendaId) {
            $st = $db->prepare("SELECT comprador_nome FROM vendas WHERE id = ?");
            $st->execute([$vendaId]);
            $nome = trim((string)$st->fetchColumn());
            return ($nome !== '' ? $nome . ' ' : '') . "(venda #{$vendaId})";
        }
        if ($oportunidadeId) {
            $st = $db->prepare("SELECT c.nome FROM oportunidades o JOIN clientes c ON c.id = o.cliente_id WHERE o.id = ?");
            $st->execute([$oportunidadeId]);
            $nome = trim((string)$st->fetchColumn());
            return ($nome !== '' ? $nome . ' ' : '') . "(oportunidade #{$oportunidadeId})";
        }
    } catch (Throwable $e) {}
    return '';
}

/** Nome legível de um tipo de documento (compra ou venda) — cai no próprio slug se não achar. */
function auditoriaRotuloTipoDocumento(string $tipo): string {
    foreach (['TIPOS_DOCUMENTOS_CLIENTE', 'TIPOS_DOCUMENTOS_FECHAMENTO', 'TIPOS_DOCUMENTOS_COMPRADOR',
              'TIPOS_DOCUMENTOS_VENDA_CONTRATO', 'TIPOS_DOCUMENTOS_VENDA_EXTRA'] as $c) {
        if (defined($c) && isset(constant($c)[$tipo])) {
            $v = constant($c)[$tipo];
            $v = is_array($v) ? (string)($v['label'] ?? $v['nome'] ?? $tipo) : (string)$v;
            return trim(explode(' (', $v)[0]);
        }
    }
    return $tipo;
}

/** Lista paginada simples pra tela de auditoria — mais recente primeiro. */
function auditoriaListar(int $limite = 200, string $evento = '', string $q = ''): array {
    $db = getDB();
    $where = [];
    $params = [];
    if ($evento === '__ouvidoria') {
        // Atalho: todos os eventos da ouvidoria (protocolos, respostas, status...).
        $where[] = "evento LIKE 'ouvidoria\\_%' ESCAPE '\\'";
    } elseif ($evento !== '') {
        $where[] = 'evento = ?';
        $params[] = $evento;
    }
    if ($q !== '') {
        $where[] = '(usuario_nome LIKE ? OR detalhe LIKE ? OR ip LIKE ?)';
        $like = '%' . $q . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }
    $sql = "SELECT * FROM auditoria";
    if ($where) $sql .= " WHERE " . implode(' AND ', $where);
    $sql .= " ORDER BY id DESC LIMIT ?";
    $params[] = $limite;
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/** Eventos distintos já gravados — popula o filtro da tela sem precisar de uma lista hardcoded que dessincroniza. */
function auditoriaEventosDistintos(): array {
    return getDB()->query("SELECT DISTINCT evento FROM auditoria ORDER BY evento")->fetchAll(PDO::FETCH_COLUMN);
}

/** Rótulo amigável por tipo de evento — cai no próprio nome cru se um evento novo for adicionado e esquecerem de rotular aqui. */
function auditoriaRotuloEvento(string $evento): string {
    return match ($evento) {
        'login' => '🔓 Login',
        'documento_aberto' => '📄 Documento aberto',
        'documento_acesso_negado' => '⛔ Documento barrado (fora da carteira)',
        'alerta_volume_documentos' => '🚨 Alerta: muitos documentos abertos',
        'quadro_promissoria_alterado' => '🧾 Quadro-Resumo da promissória alterado',
        'venda_lida_contrato_ia' => '🤖 Venda preenchida lendo o contrato',
        'contrato_movido_para_compra' => '🔁 Contrato movido de venda pra compra',
        'relatorio_mes_exportado' => '📈 Resumo do mês baixado',
        'contrato_venda_subido_manual' => '📄 Contrato de venda assinado enviado (fora do sistema)',
        'login_falha' => '⚠️ Senha errada',
        'login_bloqueado' => '🚫 Conta bloqueada (tentativas)',
        'login_fora_expediente' => '🌙 Login recusado (fora do expediente)',
        'logout_fora_expediente' => '🌙 Desconectado (fim do expediente)',
        'acesso_liberado_expediente' => '🔓 Acesso liberado fora do expediente',
        'acesso_revogado_expediente' => '🔒 Liberação fora do expediente revogada',
        'logout' => '🔒 Logout',
        'usuario_criado' => '➕ Usuário criado',
        'usuario_perfil_alterado' => '🔄 Perfil alterado',
        'usuario_bloqueado' => '🚫 Usuário bloqueado',
        'usuario_desbloqueado' => '✅ Usuário desbloqueado',
        'configuracoes_acesso_alterado' => '🔒 Acesso à tela de Configurações alterado',
        'usuario_tentativas_liberadas' => '🔓 Bloqueio temporário de login liberado antes do prazo',
        'usuario_senha_redefinida' => '🔑 Senha redefinida',
        'recuperacao_senha_solicitada' => '✉️ Link de recuperação de senha enviado',
        'conversa_excluida' => '🗑️ Conversa WhatsApp excluída',
        'conversa_whatsapp_limpa' => '🧹 Conversa WhatsApp limpa (só CRM)',
        'ia_pausada_carteira' => '⏸️ IA pausada na carteira inteira',
        'ia_reativada_carteira' => '▶️ IA reativada na carteira inteira',
        'mensagem_whatsapp_apagada' => '🗑️ Mensagem WhatsApp apagada pra todos',
        'atendimento_encerrado_inbox' => '🔒 Atendimento encerrado via WhatsApp Box',
        'ouvidoria_vinculada' => '🔗 Conversa da ouvidoria vinculada a um negócio',
        'ouvidoria_ticket_status' => '🎫 Ticket da ouvidoria: status alterado',
        'ouvidoria_historico_importado' => '📥 Histórico da ouvidoria importado',
        'ouvidoria_ticket_jean' => '🔔 Ticket da ouvidoria enviado ao Jean',
        'ouvidoria_ticket_aberto' => '🎫 Ouvidoria: protocolo aberto',
        'ouvidoria_ticket_reclassificado' => '🏷️ Ouvidoria: ticket reclassificado',
        'ouvidoria_ticket_reaberto' => '↩️ Ouvidoria: protocolo reaberto (mesmo assunto)',
        'ouvidoria_ticket_urgente' => '🚨 Ouvidoria: ticket urgente',
        'ouvidoria_ticket_placa' => '🚗 Ouvidoria: placa/data do ticket',
        'ouvidoria_mensagem_enviada' => '💬 Ouvidoria: resposta da equipe',
        'cobranca_enviada' => '💬 Cobrança enviada (WhatsApp da ouvidoria)',
        'cobranca_baixa' => '💰 Baixa de cobrança (pagamento confirmado)',
        'cobranca_automatica' => '🤖 Cobrança automática (WhatsApp da ouvidoria)',
        'ouvidoria_comprovante' => '🧾 Comprovante recebido na ouvidoria',
        'ouvidoria_assunto_alterado' => '🏷️ Ouvidoria: assunto alterado',
        'ouvidoria_ia_pausada' => '⏸️ Ouvidoria: IA pausada',
        'ouvidoria_ia_reativada' => '▶️ Ouvidoria: IA reativada',
        'ouvidoria_desvinculada' => '🔗 Ouvidoria: vínculo removido',
        'ouvidoria_conversa_aberta' => '👁️ Ouvidoria: conversa aberta',
        'evolution_qrcode' => '📲 WhatsApp: QR code pedido (conectar)',
        'lead_movido_vendas_inbox' => '🛒 Lead movido pra vendas via WhatsApp Box',
        'veiculo_excluido' => '🗑️ Veículo excluído da frota',
        'venda_desfeita' => '↩️ Vendido desfeito (sem contrato)',
        'venda_reativada' => '↩️ Negociação de venda reativada',
        'contrato_assinado_cancelado' => '🚫 Contrato assinado cancelado (com senha)',
        'senha_confirmacao_falhou' => '🔑 Senha errada ao confirmar ação',
        'edicao_bloqueada_contrato' => '🔒 Tentou alterar contrato assinado (bloqueado)',
        'edicao_contrato_assinado' => '✏️ Contrato assinado alterado (super admin ou liberado com senha)',
        'edicao_destravada' => '🔓 Edição de contrato liberada com senha do supervisor',
        'destravar_edicao_falhou' => '🔑 Senha do supervisor errada ao destravar',
        'cliente_dado_editado' => '✏️ Dado sensível de cliente editado',
        'perfil_proprio_editado' => '🙋 Usuário editou o próprio perfil',
        'avatar_atualizado' => '🖼️ Avatar atualizado',
        'ponto_falta_marcada' => '🕒 Ponto: falta marcada',
        'ponto_falta_desfeita' => '🕒 Ponto: falta desfeita',
        'ponto_feriado' => '🎉 Feriado (cartão de ponto)',
        'ponto_plantao' => '🌙 Ponto: plantão alterado',
        'login_falta_ponto' => '🤒 Login recusado (falta marcada no ponto)',
        'logout_falta_ponto' => '🤒 Desconectado (falta marcada no ponto)',
        'comissao_aprovada' => '🤝 Comissão aprovada (baixa)',
        'lancamento_cancelado' => '🚫 Lançamento financeiro cancelado',
        'veiculo_devolvido' => '🔙 Veículo devolvido (voltou pra Fastcar)',
        'veiculo_busca_apreensao' => '🚨 Busca e apreensão registrada',
        'veiculo_busca_apreensao_desfeita' => '↩️ Busca e apreensão desfeita',
        'carro_reserva_liberado' => '🔑 Carro reserva liberado',
        'carro_reserva_assinado' => '✍️ Termo de carro reserva assinado',
        'termo_debitos_assinado' => '✍️ Termo de débitos do veículo assinado',
        'carro_reserva_devolvido' => '↩️ Carro reserva devolvido',
        'carro_reserva_cancelado' => '🚫 Carro reserva cancelado',
        'despesa_operacional_lancada' => '💸 Despesa operacional lançada (comprovante)',
        'impersonacao_iniciada' => '🎭 Impersonação iniciada',
        'impersonacao_finalizada' => '🎭 Impersonação encerrada',
        'lgpd_exclusao_solicitada' => '⚖️ Pedido de exclusão de dados (LGPD)',
        'documento_anexado' => '📎 Documento anexado manualmente',
        'vistoria_reaberta' => '↩️ Vistoria concluída reaberta',
        'documento_excluido' => '🗑️ Documento anexado removido',
        default => $evento,
    };
}
