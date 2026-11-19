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
require_once __DIR__ . '/feriados.php';

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
            // Chegou atrasado e a falta foi desfeita: volta pra fila na hora (dentro do expediente).
            $perfil = (string)$db->query("SELECT perfil FROM usuarios WHERE id = " . (int)$usuarioId)->fetchColumn();
            if (in_array($perfil, ['consultor', 'vendedor'], true) && (!function_exists('filaDentroExpediente') || filaDentroExpediente())) {
                $db->prepare("UPDATE usuarios SET disponivel = 1 WHERE id = ? AND bloqueado = 0")->execute([$usuarioId]);
            }
        } catch (Throwable $e) {}
    }
}

/**
 * 20/11/2026, "marcou falta ele não consegue entrar em casa ou no celular" —
 * com falta marcada HOJE a pessoa não entra no sistema (login recusado e quem
 * já estava logado é desconectado). Super admin e supervisor nunca são barrados
 * (são eles que marcam). Desfazer a falta libera na hora.
 */
function pontoFaltaHojeBloqueia(int $usuarioId, string $perfil): bool {
    if (in_array($perfil, ['super_admin', 'supervisor'], true)) return false;
    try {
        $st = getDB()->prepare("SELECT 1 FROM ponto_registros WHERE usuario_id = ? AND data = ? AND falta = 1");
        $st->execute([$usuarioId, date('Y-m-d')]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return false; // nunca trava o acesso por erro do ponto
    }
}

const PONTO_MSG_FALTA_BLOQUEIO = 'Você está com falta marcada hoje — o acesso ao sistema fica bloqueado. Se chegou, peça pra supervisão desfazer a falta no Cartão de ponto e entre de novo.';

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

/** Resumo do dia pro card da tela inicial (só quem bate ponto: todos os ativos menos super admin). */
function pontoResumoDia(?string $data = null): array {
    $data = $data ?: date('Y-m-d');
    $db = getDB();
    $us = $db->query("SELECT id, nome FROM usuarios WHERE bloqueado = 0 AND perfil != 'super_admin' ORDER BY nome")->fetchAll();
    $regs = pontoRegistrosDoDia($data);
    $out = ['presentes' => [], 'faltas' => [], 'sem_registro' => []];
    foreach ($us as $u) {
        $r = $regs[(int)$u['id']] ?? null;
        if ($r && (int)$r['falta'] === 1) $out['faltas'][] = $u['nome'];
        elseif ($r && $r['entrada']) $out['presentes'][] = $u['nome'];
        else $out['sem_registro'][] = $u['nome'];
    }
    return $out;
}

const PONTO_ATRASO_TOLERANCIA_MIN = 10; // entrada até abertura da fila + 10 min não conta como atraso

/**
 * Relatório de faltas e horários do período (20/11/2026, "tem saber relatórios
 * de faltas e horários"). Só quem bate ponto (ativos, menos super admin).
 * Dia útil = dia aberto da fila (Configurações → Fila) até hoje.
 * Atraso = entrada depois da abertura da fila + PONTO_ATRASO_TOLERANCIA_MIN.
 * Feriado nacional (includes/feriados.php) não é dia útil: não conta como
 * falta nem "sem registro" (falta marcada em feriado aparece à parte).
 */
function pontoRelatorioPeriodo(string $de, string $ate): array {
    $hoje = date('Y-m-d');
    if ($ate > $hoje) $ate = $hoje;
    $db = getDB();
    $us = $db->query("SELECT id, nome, perfil FROM usuarios WHERE bloqueado = 0 AND perfil != 'super_admin'
        ORDER BY CASE perfil WHEN 'consultor' THEN 1 WHEN 'vendedor' THEN 2 WHEN 'avaliador' THEN 3 WHEN 'financeiro' THEN 4 WHEN 'supervisor' THEN 5 ELSE 6 END, nome")->fetchAll();
    $st = $db->prepare("SELECT * FROM ponto_registros WHERE data BETWEEN ? AND ? ORDER BY data");
    $st->execute([$de, $ate]);
    $regs = [];
    foreach ($st->fetchAll() as $r) $regs[(int)$r['usuario_id']][$r['data']] = $r;

    $diasUteis = [];
    $feriados = [];
    if ($de <= $ate) {
        for ($t = strtotime($de); $t <= strtotime($ate); $t += 86400) {
            $d = date('Y-m-d', $t);
            if (isFeriado($d)) { $feriados[$d] = feriadoNome($d); continue; }
            if (!function_exists('filaDiaAberto') || filaDiaAberto((int)date('N', $t))) $diasUteis[] = $d;
        }
    }
    $abertura = function_exists('filaHorarioAbertura') ? filaHorarioAbertura() : '10:00';
    [$h, $m] = array_map('intval', explode(':', $abertura));
    $limiteAtraso = sprintf('%02d:%02d', intdiv($h * 60 + $m + PONTO_ATRASO_TOLERANCIA_MIN, 60), ($h * 60 + $m + PONTO_ATRASO_TOLERANCIA_MIN) % 60);

    $linhas = [];
    foreach ($us as $u) {
        $rs = $regs[(int)$u['id']] ?? [];
        $l = ['id' => (int)$u['id'], 'nome' => $u['nome'], 'perfil' => $u['perfil'], 'presentes' => 0, 'faltas' => [], 'atrasos' => [],
              'sem_registro' => [], 'faltas_feriado' => [], 'minutos' => 0, 'entradas' => [], 'dias' => []];
        foreach ($rs as $data => $r) {
            if ((int)$r['falta'] === 1 && isset($feriados[$data])) {
                $l['faltas_feriado'][] = ['data' => $data, 'feriado' => $feriados[$data]];
            } elseif ((int)$r['falta'] === 1) {
                $l['faltas'][] = ['data' => $data, 'motivo' => (string)$r['falta_motivo']];
            } elseif ($r['entrada']) {
                $l['presentes']++;
                $l['minutos'] += (int)pontoMinutosTrabalhados($r);
                $hEnt = date('H:i', strtotime($r['entrada']));
                $l['entradas'][] = $hEnt;
                if ($hEnt > $limiteAtraso) $l['atrasos'][] = ['data' => $data, 'entrada' => $hEnt];
            }
            $l['dias'][$data] = $r;
        }
        foreach ($diasUteis as $d) {
            $r = $rs[$d] ?? null;
            if (!$r || (!$r['entrada'] && (int)$r['falta'] !== 1)) $l['sem_registro'][] = $d;
        }
        if ($l['entradas']) {
            $mins = array_map(fn($x) => (int)substr($x, 0, 2) * 60 + (int)substr($x, 3, 2), $l['entradas']);
            $med = (int)round(array_sum($mins) / count($mins));
            $l['entrada_media'] = sprintf('%02d:%02d', intdiv($med, 60), $med % 60);
        } else {
            $l['entrada_media'] = null;
        }
        $linhas[] = $l;
    }
    return ['de' => $de, 'ate' => $ate, 'dias_uteis' => count($diasUteis), 'feriados' => $feriados, 'limite_atraso' => $limiteAtraso, 'linhas' => $linhas];
}

/** Excel do relatório: aba Resumo (1 linha por pessoa) e aba Dias (1 linha por pessoa/dia). */
function pontoRelatorioExcel(array $rel): string {
    require_once __DIR__ . '/xlsx.php';
    $rot = ['consultor' => 'Consultor', 'vendedor' => 'Vendedor', 'avaliador' => 'Avaliador', 'financeiro' => 'Financeiro', 'supervisor' => 'Supervisor'];
    $per = date('d/m/Y', strtotime($rel['de'])) . ' a ' . date('d/m/Y', strtotime($rel['ate']));
    $res = [
        [['v' => 'Cartão de ponto — ' . $per, 's' => 'titulo']],
        ['Dias úteis no período: ' . $rel['dias_uteis'] . ' · atraso = entrada depois das ' . $rel['limite_atraso']
            . ($rel['feriados'] ? ' · feriados (não contam): ' . implode(', ', array_map(fn($d, $n) => date('d/m', strtotime($d)) . " $n", array_keys($rel['feriados']), $rel['feriados'])) : '')],
        [],
        array_map(fn($c) => ['v' => $c, 's' => 'cab'], ['Nome', 'Perfil', 'Dias presente', 'Faltas', 'Atrasos', 'Sem registro (dia útil)', 'Horas no total', 'Entrada média', 'Datas das faltas (motivo)']),
    ];
    foreach ($rel['linhas'] as $l) {
        $res[] = [$l['nome'], $rot[$l['perfil']] ?? $l['perfil'], $l['presentes'], count($l['faltas']), count($l['atrasos']), count($l['sem_registro']),
            pontoFormatarMinutos($l['minutos']), $l['entrada_media'] ?? '—',
            implode('; ', array_map(fn($f) => date('d/m', strtotime($f['data'])) . ($f['motivo'] !== '' ? " ({$f['motivo']})" : ''), $l['faltas']))];
    }
    $dias = [array_map(fn($c) => ['v' => $c, 's' => 'cab'], ['Data', 'Nome', 'Perfil', 'Entrada', 'Saída / último acesso', 'Horas', 'Situação', 'Motivo da falta'])];
    foreach ($rel['linhas'] as $l) {
        foreach ($l['dias'] as $data => $r) {
            $falta = (int)$r['falta'] === 1;
            $ent = $r['entrada'] ? date('H:i', strtotime($r['entrada'])) : '';
            $fim = pontoFim($r);
            $fer = feriadoNome($data);
            $sit = $falta && $fer ? "Falta em feriado ({$fer}) — não conta" : ($falta ? 'Falta' : ($ent ? ($ent > $rel['limite_atraso'] ? 'Atraso' : 'Presente') : ($fer ? "Feriado ({$fer})" : 'Sem entrada')));
            $dias[] = [['v' => $data, 's' => 'data'], $l['nome'], $rot[$l['perfil']] ?? $l['perfil'], $ent,
                $fim ? date('H:i', strtotime($fim)) . ($r['saida'] ? '' : ' (último acesso)') : '',
                $falta ? '' : pontoFormatarMinutos(pontoMinutosTrabalhados($r)), $sit, $falta ? (string)$r['falta_motivo'] : ''];
        }
    }
    $x = new XlsxSimples();
    $x->aba('Resumo', [28, 12, 13, 9, 9, 20, 14, 13, 50], $res);
    $x->aba('Dias', [12, 28, 12, 9, 22, 9, 12, 40], $dias);
    return $x->gerar();
}
