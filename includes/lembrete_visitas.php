<?php
/**
 * includes/lembrete_visitas.php — lembrete diário das visitas agendadas na
 * Fastcar (17/11/2026, "Criar cron para enviar todos dias lembrete de
 * clientes agendados na Fastcar para Jean no WhatsApp e e-mail").
 *
 * Fonte: `vendas` com aceita_visitar_fastcar=1 e visita_data_resolvida
 * preenchida — o agendamento do formulário de vendas (quero-carro.php) e a
 * visita confirmada pela IA de vendas gravam esses dois campos. Visita só
 * com texto livre (sem data resolvida) não entra: não dá pra saber o dia.
 *
 * Mensagem: visitas de HOJE (detalhadas) + prévia de AMANHÃ. Destino:
 *  - WhatsApp: config.lembrete_visitas_whatsapp; vazio = os números de
 *    notificação (config.notificacao_leads_whatsapp — onde já está o do Jean);
 *  - e-mail: config.lembrete_visitas_emails; vazio = e-mail do usuário ativo
 *    cujo nome começa com "Jean".
 * Enviado por cron/lembrete_visitas.php 1x por dia no horário configurado
 * (padrão 08:00). Mensagem interna (staff) → zapiEnviarTextoInterno().
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/whatsapp_config.php';
require_once __DIR__ . '/mail.php';
require_once __DIR__ . '/email_templates.php';

const LEMBRETE_VISITAS_DIAS = [1 => 'segunda', 2 => 'terça', 3 => 'quarta', 4 => 'quinta', 5 => 'sexta', 6 => 'sábado', 7 => 'domingo'];

function lembreteVisitasHorario(): string {
    $h = (string)(getConfig('lembrete_visitas_hora') ?: '');
    return preg_match('/^\d{2}:\d{2}$/', $h) ? $h : '08:00';
}

function lembreteVisitasAtivo(): bool {
    return getConfig('lembrete_visitas_ativo') !== '0';
}

/** @return array{whatsapp:string[], emails:string[]} */
function lembreteVisitasDestinatarios(): array {
    $wpp = trim((string)(getConfig('lembrete_visitas_whatsapp') ?: ''));
    if ($wpp === '') $wpp = (string)(getConfig('notificacao_leads_whatsapp') ?: '');
    $numeros = array_values(array_unique(array_filter(array_map('trim', explode(',', $wpp)))));

    $mails = trim((string)(getConfig('lembrete_visitas_emails') ?: ''));
    $emails = array_filter(array_map('trim', explode(',', $mails)), fn($m) => filter_var($m, FILTER_VALIDATE_EMAIL));
    if (!$emails) {
        try {
            $st = getDB()->query("SELECT email FROM usuarios WHERE bloqueado = 0 AND nome LIKE 'Jean%' AND email != '' ORDER BY id LIMIT 1");
            $email = (string)($st->fetchColumn() ?: '');
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) $emails = [$email];
        } catch (Throwable $e) { /* sem e-mail */ }
    }
    return ['whatsapp' => $numeros, 'emails' => array_values(array_unique($emails))];
}

/** Hora "HH:MM" do texto da visita ("terça 17/11 às 12:00"), ou '' */
function lembreteVisitaHora(string $texto): string {
    return preg_match('/\b(\d{1,2})[:h](\d{2})\b/', $texto, $m) ? sprintf('%02d:%s', (int)$m[1], $m[2]) : '';
}

/** Visitas agendadas num dia (Y-m-d), ordenadas pelo horário. */
function listarVisitasAgendadas(string $data): array {
    $st = getDB()->prepare("
        SELECT v.id, v.comprador_nome, v.comprador_telefone, v.visita_dia_horario_texto,
               v.veiculo_interesse_texto, v.forma_pagamento_pretendida, v.valor_entrada_disponivel,
               o.veiculo_marca, o.veiculo_modelo, o.veiculo_ano, u.nome AS responsavel_nome
        FROM vendas v
        LEFT JOIN oportunidades o ON o.id = v.oportunidade_id
        LEFT JOIN usuarios u ON u.id = v.responsavel_id
        WHERE v.aceita_visitar_fastcar = 1 AND v.visita_data_resolvida = ?
          AND v.etapa NOT IN ('cancelada', 'sem_perfil')
    ");
    $st->execute([$data]);
    $lista = [];
    foreach ($st->fetchAll() as $v) {
        $v['hora'] = lembreteVisitaHora((string)$v['visita_dia_horario_texto']);
        $v['veiculo'] = trim(($v['veiculo_marca'] ?? '') . ' ' . ($v['veiculo_modelo'] ?? '') . ' ' . ($v['veiculo_ano'] ?? ''))
            ?: trim((string)($v['veiculo_interesse_texto'] ?? ''));
        $lista[] = $v;
    }
    usort($lista, fn($a, $b) => [$a['hora'] === '' ? '99' : $a['hora'], $a['id']] <=> [$b['hora'] === '' ? '99' : $b['hora'], $b['id']]);
    return $lista;
}

function _lembreteRotuloDia(string $data): string {
    $ts = strtotime($data);
    return LEMBRETE_VISITAS_DIAS[(int)date('N', $ts)] . ' ' . date('d/m', $ts);
}

function _lembreteTelefoneBonito(string $tel): string {
    $d = preg_replace('/\D/', '', $tel);
    if (strlen($d) >= 12 && str_starts_with($d, '55')) $d = substr($d, 2);
    if (strlen($d) === 11) return sprintf('(%s) %s-%s', substr($d, 0, 2), substr($d, 2, 5), substr($d, 7));
    if (strlen($d) === 10) return sprintf('(%s) %s-%s', substr($d, 0, 2), substr($d, 2, 4), substr($d, 6));
    return $tel;
}

function montarLembreteVisitasWhatsapp(string $hoje, array $visHoje, string $amanha, array $visAmanha): string {
    $base = appBaseUrl();
    $msg = "📅 *Visitas na Fastcar — " . _lembreteRotuloDia($hoje) . "*\n\n";
    if (!$visHoje) {
        $msg .= "Nenhuma visita agendada pra hoje.\n";
    } else {
        $msg .= count($visHoje) . " visita(s) hoje:\n";
        foreach ($visHoje as $v) {
            $msg .= "\n🕐 *" . ($v['hora'] ?: 'sem horário') . "* — " . ($v['comprador_nome'] ?: '(sem nome)') . "\n";
            if ($v['comprador_telefone']) $msg .= "📞 " . _lembreteTelefoneBonito((string)$v['comprador_telefone']) . "\n";
            if ($v['veiculo'] !== '') $msg .= "🚗 " . $v['veiculo'] . "\n";
            $msg .= "👤 Vendedor: " . ($v['responsavel_nome'] ?: 'sem responsável') . "\n";
            $msg .= "🔗 {$base}/admin/venda.php?id={$v['id']}\n";
        }
    }
    $msg .= "\n*Amanhã (" . _lembreteRotuloDia($amanha) . "):* ";
    if (!$visAmanha) {
        $msg .= "nenhuma visita agendada.";
    } else {
        $msg .= count($visAmanha) . " visita(s)\n";
        foreach ($visAmanha as $v) {
            $msg .= "• " . ($v['hora'] ?: '—') . " — " . ($v['comprador_nome'] ?: '(sem nome)') . "\n";
        }
    }
    return rtrim($msg);
}

function montarLembreteVisitasEmail(string $hoje, array $visHoje, string $amanha, array $visAmanha): string {
    $base = appBaseUrl();
    $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
    $html = '<h2 style="margin:0 0 4px;font-size:20px;color:#151722">📅 Visitas agendadas — ' . $h(_lembreteRotuloDia($hoje)) . '</h2>'
          . '<p style="margin:0 0 20px;color:#6b7280">' . ($visHoje ? count($visHoje) . ' cliente(s) vêm à Fastcar hoje.' : 'Nenhuma visita agendada pra hoje.') . '</p>';
    foreach ($visHoje as $v) {
        $html .= '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border:1px solid #e5e7eb;border-left:4px solid #2f6fed;border-radius:8px;margin:0 0 12px">'
               . '<tr><td style="padding:14px 16px">'
               . '<div style="font-size:18px;font-weight:bold;color:#2f6fed">' . $h($v['hora'] ?: 'sem horário') . '</div>'
               . '<div style="font-size:16px;font-weight:bold;color:#151722;margin:2px 0 6px">' . $h($v['comprador_nome'] ?: '(sem nome)') . '</div>'
               . ($v['comprador_telefone'] ? '<div style="color:#374151">📞 ' . $h(_lembreteTelefoneBonito((string)$v['comprador_telefone'])) . '</div>' : '')
               . ($v['veiculo'] !== '' ? '<div style="color:#374151">🚗 ' . $h($v['veiculo']) . '</div>' : '')
               . '<div style="color:#374151">👤 Vendedor: ' . $h($v['responsavel_nome'] ?: 'sem responsável') . '</div>'
               . '<div style="margin-top:8px"><a href="' . $h("{$base}/admin/venda.php?id={$v['id']}") . '" style="color:#2f6fed;font-weight:bold;text-decoration:none">Abrir negociação →</a></div>'
               . '</td></tr></table>';
    }
    $html .= '<h3 style="margin:24px 0 8px;font-size:15px;color:#151722">Amanhã (' . $h(_lembreteRotuloDia($amanha)) . ')</h3>';
    if (!$visAmanha) {
        $html .= '<p style="margin:0;color:#6b7280">Nenhuma visita agendada.</p>';
    } else {
        $html .= '<ul style="margin:0;padding-left:18px;color:#374151">';
        foreach ($visAmanha as $v) {
            $html .= '<li>' . $h($v['hora'] ?: '—') . ' — ' . $h($v['comprador_nome'] ?: '(sem nome)') . ($v['veiculo'] !== '' ? ' · ' . $h($v['veiculo']) : '') . '</li>';
        }
        $html .= '</ul>';
    }
    return emailLayout($html);
}

/**
 * Monta e envia o lembrete. Nunca lança.
 * @return array{ok:bool, whatsapp:int, emails:int, hoje:int, amanha:int, motivo?:string}
 */
function enviarLembreteVisitas(?string $hoje = null): array {
    try {
        $hoje = $hoje ?: date('Y-m-d');
        $amanha = date('Y-m-d', strtotime($hoje . ' +1 day'));
        $visHoje = listarVisitasAgendadas($hoje);
        $visAmanha = listarVisitasAgendadas($amanha);
        $dest = lembreteVisitasDestinatarios();
        if (!$dest['whatsapp'] && !$dest['emails']) {
            return ['ok' => false, 'whatsapp' => 0, 'emails' => 0, 'hoje' => count($visHoje), 'amanha' => count($visAmanha), 'motivo' => 'nenhum WhatsApp/e-mail de destino configurado'];
        }

        $okWpp = 0;
        $msg = montarLembreteVisitasWhatsapp($hoje, $visHoje, $amanha, $visAmanha);
        foreach ($dest['whatsapp'] as $numero) {
            if (zapiEnviarTextoInterno($numero, $msg)) $okWpp++;
        }

        $okMail = 0;
        $assunto = '📅 Visitas na Fastcar — ' . _lembreteRotuloDia($hoje) . ' (' . count($visHoje) . ')';
        $html = montarLembreteVisitasEmail($hoje, $visHoje, $amanha, $visAmanha);
        foreach ($dest['emails'] as $email) {
            if (enviarEmail($email, $assunto, $html) === true) $okMail++;
        }

        $r = ['ok' => ($okWpp + $okMail) > 0, 'whatsapp' => $okWpp, 'emails' => $okMail, 'hoje' => count($visHoje), 'amanha' => count($visAmanha)];
        if (!$r['ok']) $r['motivo'] = 'nenhum envio confirmado (WhatsApp/e-mail falharam)';
        return $r;
    } catch (Throwable $e) {
        return ['ok' => false, 'whatsapp' => 0, 'emails' => 0, 'hoje' => 0, 'amanha' => 0, 'motivo' => 'erro interno: ' . $e->getMessage()];
    }
}
