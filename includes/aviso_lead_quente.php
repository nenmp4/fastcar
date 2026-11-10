<?php
/**
 * Avisos de lead quente com registro de entrega (10/11/2026, "preciso uma
 * tela que saiba que notificações de lead quente foi entregue — resumo
 * desse lead, telefone, nome do consultor, incluindo número do Jean" +
 * "horário que foi entregue no WhatsApp").
 *
 * Cada aviso vira 1 linha por destinatário em aviso_lead_quente_envios,
 * com o id da mensagem no WhatsApp. Quando a Evolution manda o status
 * (MESSAGES_UPDATE → inboxProcessarStatusEvolution()), a linha ganha
 * entregue_em / lido_em. Tela: admin/avisos_lead_quente.php.
 *
 * Nunca muda QUEM recebe — cada chamador decide (consultor/vendedor e,
 * quando $incluirNotificacao, os números de Configurações → notificação
 * de leads). Best-effort: nunca lança.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/whatsapp_config.php';

/** Nome de quem tem esse WhatsApp cadastrado em Usuários (ex.: "Jean") — senão ''. */
function avisoLeadQuenteNomePorTelefone(string $numero): string {
    static $mapa = null;
    if ($mapa === null) {
        $mapa = [];
        try {
            foreach (getDB()->query("SELECT nome, whatsapp FROM usuarios WHERE COALESCE(whatsapp,'') != ''")->fetchAll() as $u) {
                $mapa[normalizarTelefone((string)$u['whatsapp'])] = (string)$u['nome'];
            }
        } catch (Throwable $e) { /* sem nome, segue */ }
    }
    return $mapa[normalizarTelefone($numero)] ?? '';
}

function avisoLeadQuenteEnviar(string $refTipo, int $refId, string $motivo, ?string $responsavelNome, ?string $responsavelWhatsapp, string $msg, bool $incluirNotificacao): void {
    try {
        $destinos = [];
        $respNome = trim((string)$responsavelNome);
        if (trim((string)$responsavelWhatsapp) !== '') {
            $destinos[] = [$respNome ?: 'Responsável', (string)$responsavelWhatsapp];
        } else {
            // Sem WhatsApp do responsável: registra pra aparecer na tela.
            avisoLeadQuenteRegistrar($refTipo, $refId, $motivo, $respNome ?: 'Sem responsável', '', 'sem_whatsapp',
                $respNome ? 'Responsável sem WhatsApp cadastrado em Usuários' : 'Lead sem responsável', '');
        }
        if ($incluirNotificacao) {
            foreach (array_filter(array_map('trim', explode(',', getConfig('notificacao_leads_whatsapp') ?: ''))) as $num) {
                $destinos[] = [avisoLeadQuenteNomePorTelefone($num) ?: 'Número de notificação', $num];
            }
        }
        $jaFoi = [];
        foreach ($destinos as [$nome, $numero]) {
            $chave = normalizarTelefone($numero);
            if ($chave === '' || isset($jaFoi[$chave])) continue;
            $jaFoi[$chave] = true;
            if (function_exists('evolutionConsumirUltimoIdEnviado')) evolutionConsumirUltimoIdEnviado(); // limpa id velho
            $ok = zapiEnviarTextoInterno($numero, $msg);
            $waId = function_exists('evolutionConsumirUltimoIdEnviado') ? (string)(evolutionConsumirUltimoIdEnviado() ?? '') : '';
            $erro = '';
            if (!$ok) $erro = (function_exists('evolutionUltimoErro') ? (string)evolutionUltimoErro() : '') ?: 'WhatsApp recusou o envio';
            avisoLeadQuenteRegistrar($refTipo, $refId, $motivo, $nome, $chave, $ok ? 'enviado' : 'falhou', $erro, $waId);
        }
    } catch (Throwable $e) {
        // nunca trava quem chama
    }
}

function avisoLeadQuenteRegistrar(string $refTipo, int $refId, string $motivo, string $destinatario, string $telefone, string $status, string $erro, string $waId): void {
    try {
        getDB()->prepare("INSERT INTO aviso_lead_quente_envios (ref_tipo, ref_id, motivo, destinatario, telefone, status, erro, wa_message_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)")->execute([$refTipo, $refId, $motivo, $destinatario, $telefone, $status, mb_substr($erro, 0, 300), $waId]);
    } catch (Throwable $e) { /* best-effort */ }
}

/** Status vindo do WhatsApp ('entregue' | 'lido') — grava o horário 1x só. */
function avisoLeadQuenteAtualizarStatus(string $waId, string $novo): void {
    if ($waId === '' || !in_array($novo, ['entregue', 'lido'], true)) return;
    try {
        $db = getDB();
        $db->prepare("UPDATE aviso_lead_quente_envios SET entregue_em = COALESCE(entregue_em, datetime('now','localtime')) WHERE wa_message_id = ?")->execute([$waId]);
        if ($novo === 'lido') {
            $db->prepare("UPDATE aviso_lead_quente_envios SET lido_em = COALESCE(lido_em, datetime('now','localtime')) WHERE wa_message_id = ?")->execute([$waId]);
        }
    } catch (Throwable $e) { /* best-effort */ }
}
