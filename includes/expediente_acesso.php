<?php
/**
 * Acesso só no horário de expediente (29/10/2026, "após o horário de
 * expediente bloquear o login... só com a permissão do supervisor, porque
 * eles podem chegar em casa e usar o celular pra entrar nas conversas, pegar
 * os leads").
 *
 * Decisões confirmadas com o usuário:
 *  - Vale pra consultor, vendedor e avaliador (super_admin/supervisor/
 *    financeiro nunca são bloqueados).
 *  - Mesmo horário e mesmos DIAS da fila de leads (Configurações → Fila,
 *    padrão 10:00–19:20, seg–sex — 10/11/2026: fim de semana bloqueado pra
 *    consultor/avaliador; vendedor só tem a trava de horário).
 *  - 30 min de tolerância depois do fechamento (aviso 10 min antes de cair).
 *  - Quem já está logado é derrubado no corte (checado a cada request em
 *    admin/_bootstrap.php), não só na tela de login.
 *  - Supervisor ou super admin libera um usuário até o fim do dia
 *    (usuarios.acesso_liberado_ate) — fica na auditoria.
 *  - Aviso de lead no WhatsApp do consultor/vendedor continua indo a
 *    qualquer hora: é aparelho da empresa, fica na Fastcar (29/10/2026,
 *    "ele não leva embora" — revertido o corte desses avisos).
 * Liga/desliga em config.expediente_bloqueio_login (ligado por padrão).
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/fila_leads.php';

const EXPEDIENTE_PERFIS_BLOQUEADOS = ['consultor', 'vendedor', 'avaliador'];

function expedienteBloqueioLigado(): bool {
    return getConfig('expediente_bloqueio_login') !== '0';
}

function expedienteToleranciaMin(): int {
    $v = getConfig('expediente_tolerancia_min');
    return ($v !== null && $v !== '' && ctype_digit((string)$v)) ? min(180, (int)$v) : 30;
}

/** Horário HH:MM em que o acesso cai (fechamento da fila + tolerância). */
function expedienteHorarioCorte(): string {
    [$h, $m] = array_map('intval', explode(':', filaHorarioFechamento()));
    $min = min(23 * 60 + 59, $h * 60 + $m + expedienteToleranciaMin());
    return sprintf('%02d:%02d', intdiv($min, 60), $min % 60);
}

/**
 * Agora está dentro da janela de acesso (dia aberto da fila, da abertura até
 * fechamento + tolerância)? 10/11/2026, "final de semana também": nos dias
 * desmarcados em Configurações → Fila (padrão sáb/dom) consultor e
 * avaliador não entram — só com liberação do supervisor. Vendedor: só horário.
 */
function expedienteDentroJanela(?string $perfil = null): bool {
    $agora = date('H:i');
    // Vendedor não tem fila e pode atender comprador no fim de semana
    // ("não vendedor não"): pra ele só vale o horário, nunca o dia.
    $diaOk = $perfil === 'vendedor' || filaDiaAberto();
    return $diaOk && $agora >= filaHorarioAbertura() && $agora < expedienteHorarioCorte();
}

/** Minutos que faltam até o corte (null se fora da janela). */
function expedienteMinutosParaCorte(?string $perfil = null): ?int {
    if (!expedienteDentroJanela($perfil)) return null;
    [$h, $m] = array_map('intval', explode(':', expedienteHorarioCorte()));
    $corte = mktime($h, $m, 0);
    return max(0, (int)ceil(($corte - time()) / 60));
}

/** Usuário tem liberação do supervisor válida agora? */
function expedienteLiberado(int $usuarioId): bool {
    $st = getDB()->prepare("SELECT acesso_liberado_ate FROM usuarios WHERE id = ?");
    $st->execute([$usuarioId]);
    $ate = (string)($st->fetchColumn() ?: '');
    return $ate !== '' && $ate >= date('Y-m-d H:i:s');
}

/** Esse usuário pode usar o sistema agora? */
function expedienteAcessoPermitido(int $usuarioId, string $perfil): bool {
    if (!expedienteBloqueioLigado()) return true;
    if (!in_array($perfil, EXPEDIENTE_PERFIS_BLOQUEADOS, true)) return true;
    if (expedienteDentroJanela($perfil)) return true;
    return expedienteLiberado($usuarioId);
}

/** Pode mandar dado de lead pro WhatsApp pessoal desse usuário agora? */
function expedienteAvisoPessoalPermitido(?int $usuarioId): bool {
    if (!$usuarioId) return true;
    $st = getDB()->prepare("SELECT perfil FROM usuarios WHERE id = ?");
    $st->execute([$usuarioId]);
    $perfil = (string)($st->fetchColumn() ?: '');
    return expedienteAcessoPermitido($usuarioId, $perfil);
}

function expedienteMensagemBloqueio(): string {
    return 'Fora do horário de expediente (' . filaDiasAbertosTexto() . ', ' . filaHorarioAbertura() . ' às ' . expedienteHorarioCorte()
        . '). Pra entrar agora, peça liberação ao supervisor.';
}

/** Libera o usuário até o fim do dia de hoje. */
function expedienteLiberarHoje(int $usuarioId): void {
    getDB()->prepare("UPDATE usuarios SET acesso_liberado_ate = ? WHERE id = ?")
        ->execute([date('Y-m-d') . ' 23:59:59', $usuarioId]);
}

function expedienteRevogarLiberacao(int $usuarioId): void {
    getDB()->prepare("UPDATE usuarios SET acesso_liberado_ate = NULL WHERE id = ?")->execute([$usuarioId]);
}

/** Quem pode liberar: supervisor e super admin. */
function expedientePodeLiberar(): bool {
    return in_array($_SESSION['admin_perfil'] ?? '', ['super_admin', 'supervisor'], true);
}
