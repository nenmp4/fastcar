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
    try {
        $usuarioId = isset($_SESSION['admin_id']) ? (int)$_SESSION['admin_id'] : null;
        $usuarioNome = (string)($_SESSION['admin_nome'] ?? '');
        if (!$usuarioId) return;
        $stmt = getDB()->prepare("
            SELECT 1 FROM auditoria
            WHERE usuario_id = ? AND evento = 'documento_aberto' AND alvo_tipo = ? AND alvo_id = ?
              AND created_at >= datetime('now', 'localtime', ?)
            LIMIT 1
        ");
        $stmt->execute([$usuarioId, $alvoTipo, $alvoId, '-' . AUDITORIA_DOC_ABERTO_DEDUP_MIN . ' minutes']);
        if ($stmt->fetchColumn()) return;
        auditoriaRegistrar('documento_aberto', $usuarioId, $usuarioNome, $alvoTipo, $alvoId, $detalhe);
    } catch (Throwable $e) {
        // nunca impede o documento de abrir
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
    if ($evento !== '') {
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
        'usuario_tentativas_liberadas' => '🔓 Bloqueio temporário de login liberado antes do prazo',
        'usuario_senha_redefinida' => '🔑 Senha redefinida',
        'recuperacao_senha_solicitada' => '✉️ Link de recuperação de senha enviado',
        'conversa_excluida' => '🗑️ Conversa WhatsApp excluída',
        'conversa_whatsapp_limpa' => '🧹 Conversa WhatsApp limpa (só CRM)',
        'ia_pausada_carteira' => '⏸️ IA pausada na carteira inteira',
        'ia_reativada_carteira' => '▶️ IA reativada na carteira inteira',
        'mensagem_whatsapp_apagada' => '🗑️ Mensagem WhatsApp apagada pra todos',
        'atendimento_encerrado_inbox' => '🔒 Atendimento encerrado via WhatsApp Box',
        'veiculo_excluido' => '🗑️ Veículo excluído da frota',
        'venda_desfeita' => '↩️ Vendido desfeito (sem contrato)',
        'contrato_assinado_cancelado' => '🚫 Contrato assinado cancelado (com senha)',
        'senha_confirmacao_falhou' => '🔑 Senha errada ao confirmar ação',
        'edicao_bloqueada_contrato' => '🔒 Tentou alterar contrato assinado (bloqueado)',
        'edicao_contrato_assinado' => '✏️ Super admin alterou contrato assinado',
        'cliente_dado_editado' => '✏️ Dado sensível de cliente editado',
        'perfil_proprio_editado' => '🙋 Usuário editou o próprio perfil',
        'avatar_atualizado' => '🖼️ Avatar atualizado',
        'lancamento_cancelado' => '🚫 Lançamento financeiro cancelado',
        'veiculo_devolvido' => '🔙 Veículo devolvido (voltou pra Fastcar)',
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
