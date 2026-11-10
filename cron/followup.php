<?php
/**
 * cron/followup.php
 * Follow-up de oportunidades — mesmo espírito do cron/followup_leads.php
 * do JurídicoSaaS: três papéis num cron só.
 *
 * 1. Alerta de "próxima ação atrasada" — pro responsável (consultor),
 *    conforme a regra do Jean: "toda oportunidade aberta precisa de
 *    responsável e próxima ação, com alertas para atrasados".
 * 2. Lead "quente" parado sem ação rápida do consultor (15/09/2026,
 *    "fazer followup de lead quente... se não agir rápido") — cobre o
 *    caso mais urgente de todos, que o alerta #1 sozinho não pegava
 *    (lead recém-qualificado ainda não tem proxima_acao_em marcada).
 * 3. Reengajamento de lead esfriando — oportunidade parada na etapa
 *    'whatsapp' ou 'qualificacao_ia' sem nenhuma mensagem nova há muito
 *    tempo, antes de virar 'perdido' por abandono.
 *
 * Cron sugerido: a cada 30 min (mesmo intervalo do followup_leads.php lá).
 */

define('ROOT', dirname(__DIR__));
require_once ROOT . '/includes/db.php';
require_once ROOT . '/includes/security.php';
require_once ROOT . '/includes/whatsapp_config.php';
require_once ROOT . '/includes/whatsapp_conformidade.php';
require_once ROOT . '/chatbot-whatsapp/includes/mensagens.php'; // registrarMensagem() — log do reengajamento no histórico do cliente

function log_followup(string $msg): void {
    $dir = ROOT . '/storage/logs';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $line = '[' . date('H:i:s') . '] ' . rtrim($msg, "\n") . "\n";
    file_put_contents($dir . '/followup_' . date('Y-m-d') . '.log', $line, FILE_APPEND);
    echo $line;
}

// Trava contra rodadas sobrepostas — achado real (17/09/2026, investigando
// bloqueio do número no WhatsApp): o dedup por oportunidade/telefone
// (getConfig/setConfig abaixo) faz "lê, decide, grava" em passos separados,
// nunca atômico — se esse cron demorar mais que o intervalo de 30min (Z-API
// lenta, muitas oportunidades pendentes) e uma 2ª rodada começar antes da
// 1ª terminar, as duas leem o guard ainda vazio e mandam a MESMA mensagem
// pro MESMO contato. Reproduzido isolado (2 execuções concorrentes reais,
// não simulado): as duas mandaram o reengajamento pro mesmo telefone com
// 0.0002s de diferença. Esse é exatamente o tipo de padrão (mensagem
// duplicada em rajada) que a Z-API/WhatsApp mais pune com bloqueio de
// número. flock() em vez de um arquivo-marcador (padrão storage/.deploy do
// webhook de deploy) de propósito: a trava libera sozinha se o processo
// morrer no meio (nunca fica "presa" exigindo limpeza manual, diferente de
// um marcador que precisa ser removido explicitamente).
$lockPath = ROOT . '/storage/followup.lock';
$lockHandle = fopen($lockPath, 'c');
if (!$lockHandle || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    log_followup('Já existe uma execução em andamento — abortando esta pra evitar mensagem duplicada.');
    exit;
}

$db = getDB();
log_followup('Iniciando em ' . date('d/m/Y H:i'));

// ── 1. Próxima ação atrasada — alerta pro responsável ───────────────────────
$etapasAtivas = "'whatsapp','qualificacao_ia','crm_preenchido','atendimento','negociacao','presencial'";

$atrasadas = $db->query("
    SELECT o.id, o.veiculo_modelo, o.proxima_acao, o.proxima_acao_em,
           c.nome, c.telefone,
           u.nome as responsavel_nome, u.whatsapp as responsavel_wpp
    FROM oportunidades o
    JOIN clientes c ON c.id = o.cliente_id
    LEFT JOIN usuarios u ON u.id = o.responsavel_id
    WHERE o.etapa IN ({$etapasAtivas})
      AND o.proxima_acao_em IS NOT NULL
      AND o.proxima_acao_em < datetime('now','localtime')
")->fetchAll();

log_followup(count($atrasadas) . ' oportunidade(s) com próxima ação atrasada.');

foreach ($atrasadas as $op) {
    // Dedup: só alerta 1x a cada 4h por oportunidade, senão spamma o
    // responsável toda vez que o cron roda enquanto ele não resolve.
    $guardKey = 'alerta_atraso_' . $op['id'];
    $ultimoAlerta = getConfig($guardKey);
    if ($ultimoAlerta && (time() - strtotime($ultimoAlerta)) < 4 * 3600) {
        continue;
    }

    if (!empty($op['responsavel_wpp'])) {
        $nomeCliente = $op['nome'] ?: 'Cliente sem nome';
        $veiculo     = $op['veiculo_modelo'] ?: 'veículo não identificado ainda';
        $acao        = $op['proxima_acao'] ?: '(sem descrição registrada)';
        $msg = "⚠️ *Ação atrasada — Oportunidade #{$op['id']}*\n\n"
             . "👤 {$nomeCliente} ({$op['telefone']})\n"
             . "🚗 {$veiculo}\n"
             . "📋 Próxima ação: {$acao}\n"
             . "⏰ Estava marcada pra: " . date('d/m H:i', strtotime($op['proxima_acao_em'])) . "\n\n"
             . "Atualize a próxima ação ou o status no CRM.";
        $ok = zapiEnviarTextoInterno($op['responsavel_wpp'], $msg);
        log_followup(($ok ? '✅' : '❌') . " Alerta atraso → oportunidade #{$op['id']} → {$op['responsavel_nome']}");
    } else {
        log_followup("⏭️ Oportunidade #{$op['id']} atrasada mas sem responsável com WhatsApp cadastrado.");
    }

    setConfig($guardKey, date('Y-m-d H:i:s'));
}

// ── 2. Lead quente sem ação rápida do consultor ──────────────────────────────
// Regra de negócio 15/09/2026 (José/Jean: "fazer followup de lead quente
// cliente para consultor notificação... se não agir rápido") — lead
// classificado "quente" pela IA (financiamento atrasado/sem outra opção,
// ver includes/ia_qualificacao.php) acabou de cair pro consultor
// (crm_preenchido) mas ele ainda não avançou a oportunidade. Diferente do
// alerta de atraso (bloco 1 acima), que só dispara se JÁ existir uma
// proxima_acao_em marcada e vencida: um lead recém-qualificado
// normalmente ainda não tem isso definido (ninguém marcou "próxima ação"
// pra ele ainda), então nunca cairia no bloco 1 mesmo sendo o caso mais
// urgente de todos — esse bloco cobre esse buraco.
$IA_QUENTE_MINUTOS_LIMITE = 20;
$quentesParados = $db->query("
    SELECT o.id, c.nome, c.telefone, u.nome as responsavel_nome, u.whatsapp as responsavel_wpp
    FROM oportunidades o
    JOIN clientes c ON c.id = o.cliente_id
    LEFT JOIN usuarios u ON u.id = o.responsavel_id
    WHERE o.temperatura_lead = 'quente'
      AND o.etapa = 'crm_preenchido'
      AND o.responsavel_id IS NOT NULL
      AND o.updated_at < datetime('now','localtime','-{$IA_QUENTE_MINUTOS_LIMITE} minutes')
")->fetchAll();

log_followup(count($quentesParados) . ' lead(s) quente(s) sem ação rápida do consultor.');

foreach ($quentesParados as $op) {
    // Dedup de 1h — mais apertado que o alerta de atraso comum (4h): lead
    // quente é urgência real (financiamento atrasado), justifica cobrar
    // com mais frequência enquanto ninguém mexer na oportunidade.
    $guardKey = 'alerta_quente_' . $op['id'];
    $ultimoAlerta = getConfig($guardKey);
    if ($ultimoAlerta && (time() - strtotime($ultimoAlerta)) < 3600) {
        continue;
    }

    if (!empty($op['responsavel_wpp'])) {
        $nomeCliente = $op['nome'] ?: 'Cliente sem nome';
        $msg = "🔥 *Lead QUENTE parado — Oportunidade #{$op['id']}*\n\n"
             . "👤 {$nomeCliente} ({$op['telefone']})\n\n"
             . "Esse lead tem urgência real (financiamento atrasado, sem outra opção) e já está "
             . "com você há mais de {$IA_QUENTE_MINUTOS_LIMITE} min sem avançar. Ligue o quanto antes!";
        // 10/11/2026 — registrado em admin/avisos_lead_quente.php (entregue/lido).
        require_once __DIR__ . '/../includes/aviso_lead_quente.php';
        $antes = (int)getDB()->query("SELECT COUNT(*) FROM aviso_lead_quente_envios WHERE status = 'enviado'")->fetchColumn();
        avisoLeadQuenteEnviar('compra', (int)$op['id'], 'lembrete', $op['responsavel_nome'], $op['responsavel_wpp'], $msg, false);
        $ok = (int)getDB()->query("SELECT COUNT(*) FROM aviso_lead_quente_envios WHERE status = 'enviado'")->fetchColumn() > $antes;
        log_followup(($ok ? '✅' : '❌') . " Alerta quente parado → oportunidade #{$op['id']} → {$op['responsavel_nome']}");
    } else {
        log_followup("⏭️ Oportunidade #{$op['id']} quente parada mas responsável sem WhatsApp cadastrado.");
    }

    setConfig($guardKey, date('Y-m-d H:i:s'));
}

// ── 3. Follow-up da qualificação (09/11/2026) ───────────────────────────────
// Lead que parou de responder enquanto a IA qualificava: até 3 tentativas
// (2h, 4h, 6h), pelo mesmo número da conversa, só no horário comercial.
// Regras e travas em includes/followup_qualificacao.php. Substitui o
// reengajamento antigo (enviarAtivoComGate), que nunca saía na prática.
require_once ROOT . '/includes/followup_qualificacao.php';
$reengajados = processarFollowupQualificacao('log_followup');
log_followup("{$reengajados} follow-up(s) da qualificação enviado(s).");

log_followup('Concluído.');
setConfig('followup_ultimo_run', date('Y-m-d H:i:s'));

flock($lockHandle, LOCK_UN);
fclose($lockHandle);
