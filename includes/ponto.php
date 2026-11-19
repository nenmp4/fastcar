<?php
/**
 * Cartão de ponto (20/11/2026, "adicione cartão ponto no sistema com botão
 * marcar falta ao logar registra ponto").
 *
 * 1 linha por pessoa por dia (`ponto_registros`):
 *  - entrada  = 1º login do dia (ou 1º acesso do dia de quem já estava logado
 *               pelo "confiar neste dispositivo" — senão quem nunca sai do
 *               sistema nunca bateria ponto);
 *  - ultima_atividade = último clique no sistema (gravado no máximo a cada
 *               10 min por sessão, pra não pesar no banco);
 *  - saida    = clicou "Sair" ou foi desconectado no fim do expediente.
 *  - falta    = marcada por super admin/supervisor. Consultor/vendedor
 *               marcado HOJE também sai da fila e tem os leads não tocados
 *               redistribuídos (mesma função de Configurações → Fila).
 * Tudo best-effort: ponto nunca pode impedir o login nem derrubar página.
 * Impersonação do super admin nunca bate ponto de ninguém.
 */

require_once __DIR__ . '/db.php';

const PONTO_ATIVIDADE_INTERVALO = 600; // segundos entre gravações de "última atividade"

function pontoRegistrarEntrada(int $usuarioId): void {
    try {
        $db = getDB();
        $hoje = date('Y-m-d');
        $db->prepare("INSERT OR IGNORE INTO ponto_registros (usuario_id, data) VALUES (?, ?)")->execute([$usuarioId, $hoje]);
        $ip = function_exists('auditoriaClienteIp') ? auditoriaClienteIp() : (string)($_SERVER['REMOTE_ADDR'] ?? '');
        $db->prepare("UPDATE ponto_registros SET entrada = datetime('now','localtime'), ip_entrada = ?,
                ultima_atividade = datetime('now','localtime'), updated_at = datetime('now','localtime')
            WHERE usuario_id = ? AND data = ? AND entrada IS NULL")->execute([$ip, $usuarioId, $hoje]);
    } catch (Throwable $e) {
        // ponto nunca trava o login
    }
}

/** Chamado em toda página do admin (via _bootstrap): entrada do dia + última atividade (com intervalo). */
function pontoBaterAcesso(int $usuarioId): void {
    if (session_status() !== PHP_SESSION_ACTIVE) return;
    // Polling/AJAX (sino, badge, inbox) não conta: aba aberta esquecida não é trabalho.
    $script = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    if (str_contains($script, 'ajax') || in_array($script, ['notificacoes.php', 'ver_midia_whatsapp.php'], true)
        || isset($_GET['ajax']) || isset($_POST['ajax']) || ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== ''
        || str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')) return;
    $hoje = date('Y-m-d');
    if (($_SESSION['ponto_dia'] ?? '') !== $hoje) {
        pontoRegistrarEntrada($usuarioId);
        $_SESSION['ponto_dia'] = $hoje;
        $_SESSION['ponto_ativ'] = time();
        return;
    }
    if (time() - (int)($_SESSION['ponto_ativ'] ?? 0) < PONTO_ATIVIDADE_INTERVALO) return;
    $_SESSION['ponto_ativ'] = time();
    try {
        getDB()->prepare("UPDATE ponto_registros SET ultima_atividade = datetime('now','localtime') WHERE usuario_id = ? AND data = ?")
            ->execute([$usuarioId, $hoje]);
    } catch (Throwable $e) {}
}

function pontoRegistrarSaida(int $usuarioId): void {
    try {
        getDB()->prepare("UPDATE ponto_registros SET saida = datetime('now','localtime'), ultima_atividade = datetime('now','localtime'),
                updated_at = datetime('now','localtime') WHERE usuario_id = ? AND data = ?")
            ->execute([$usuarioId, date('Y-m-d')]);
    } catch (Throwable $e) {}
}

/**
 * Marca falta num dia. Hoje + consultor/vendedor: também tira da fila e
 * redistribui os leads não tocados (marcarConsultorFaltou/marcarVendedorFaltou).
 */
function pontoMarcarFalta(int $usuarioId, string $data, string $motivo, int $por): array {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data) || $data > date('Y-m-d')) return ['ok' => false, 'erro' => 'Data inválida.'];
    $db = getDB();
    $st = $db->prepare("SELECT id, nome, perfil FROM usuarios WHERE id = ?");
    $st->execute([$usuarioId]);
    $u = $st->fetch();
    if (!$u) return ['ok' => false, 'erro' => 'Usuário não encontrado.'];
    $db->prepare("INSERT OR IGNORE INTO ponto_registros (usuario_id, data) VALUES (?, ?)")->execute([$usuarioId, $data]);
    $db->prepare("UPDATE ponto_registros SET falta = 1, falta_motivo = ?, falta_por = ?, falta_em = datetime('now','localtime'),
            updated_at = datetime('now','localtime') WHERE usuario_id = ? AND data = ?")
        ->execute([trim($motivo), $por, $usuarioId, $data]);
    $movidas = 0;
    if ($data === date('Y-m-d')) {
        try {
            if ($u['perfil'] === 'consultor' && function_exists('marcarConsultorFaltou')) {
                $r = marcarConsultorFaltou($usuarioId, $por);
                $movidas = count($r['movidas'] ?? []);
            } elseif ($u['perfil'] === 'vendedor' && function_exists('marcarVendedorFaltou')) {
                $r = marcarVendedorFaltou($usuarioId, $por);
                $movidas = count($r['movidas'] ?? []);
            }
        } catch (Throwable $e) {}
    }
    return ['ok' => true, 'nome' => $u['nome'], 'movidas' => $movidas];
}

function pontoDesmarcarFalta(int $usuarioId, string $data): void {
    $db = getDB();
    $db->prepare("UPDATE ponto_registros SET falta = 0, falta_motivo = '', falta_por = NULL, falta_em = NULL,
            updated_at = datetime('now','localtime') WHERE usuario_id = ? AND data = ?")->execute([$usuarioId, $data]);
    if ($data === date('Y-m-d')) {
        try {
            $db->prepare("UPDATE usuarios SET faltou_em = NULL WHERE id = ? AND faltou_em = ?")->execute([$usuarioId, $data]);
        } catch (Throwable $e) {}
    }
}

/** Registros de um dia, indexados por usuario_id. */
function pontoRegistrosDoDia(string $data): array {
    $st = getDB()->prepare("SELECT * FROM ponto_registros WHERE data = ?");
    $st->execute([$data]);
    $out = [];
    foreach ($st->fetchAll() as $r) $out[(int)$r['usuario_id']] = $r;
    return $out;
}

/** Registros de 1 pessoa num mês (AAAA-MM), indexados por data. */
function pontoRegistrosDoMes(int $usuarioId, string $mes): array {
    $st = getDB()->prepare("SELECT * FROM ponto_registros WHERE usuario_id = ? AND data BETWEEN ? AND ?");
    $st->execute([$usuarioId, $mes . '-01', date('Y-m-t', strtotime($mes . '-01'))]);
    $out = [];
    foreach ($st->fetchAll() as $r) $out[$r['data']] = $r;
    return $out;
}

/** Fim efetivo do dia: saída registrada, senão o último acesso. */
function pontoFim(array $r): ?string {
    return $r['saida'] ?: ($r['ultima_atividade'] ?: null);
}

/** Minutos entre entrada e fim (saída ou último acesso). */
function pontoMinutosTrabalhados(array $r): ?int {
    $fim = pontoFim($r);
    if (empty($r['entrada']) || !$fim) return null;
    return max(0, (int)round((strtotime($fim) - strtotime($r['entrada'])) / 60));
}

function pontoFormatarMinutos(?int $min): string {
    if ($min === null) return '—';
    return sprintf('%dh%02d', intdiv($min, 60), $min % 60);
}
