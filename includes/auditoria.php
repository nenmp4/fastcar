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
        'login_falha' => '⚠️ Senha errada',
        'login_bloqueado' => '🚫 Conta bloqueada (tentativas)',
        'logout' => '🔒 Logout',
        'usuario_criado' => '➕ Usuário criado',
        'usuario_perfil_alterado' => '🔄 Perfil alterado',
        'usuario_bloqueado' => '🚫 Usuário bloqueado',
        'usuario_desbloqueado' => '✅ Usuário desbloqueado',
        'usuario_senha_redefinida' => '🔑 Senha redefinida',
        'conversa_excluida' => '🗑️ Conversa WhatsApp excluída',
        'cliente_dado_editado' => '✏️ Dado sensível de cliente editado',
        'perfil_proprio_editado' => '🙋 Usuário editou o próprio perfil',
        'avatar_atualizado' => '🖼️ Avatar atualizado',
        default => $evento,
    };
}
