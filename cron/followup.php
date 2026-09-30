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
        $ok = zapiEnviarTextoInterno($op['responsavel_wpp'], $msg);
        log_followup(($ok ? '✅' : '❌') . " Alerta quente parado → oportunidade #{$op['id']} → {$op['responsavel_nome']}");
    } else {
        log_followup("⏭️ Oportunidade #{$op['id']} quente parada mas responsável sem WhatsApp cadastrado.");
    }

    setConfig($guardKey, date('Y-m-d H:i:s'));
}

// ── 3. Reengajamento — lead esfriando sem responsável ainda ─────────────────
// 30/09/2026 — passou a usar o gate central de conformidade
// (includes/whatsapp_conformidade.php::enviarAtivoComGate()), depois da
// conta Meta ter sido desativada permanentemente por "disparo em massa
// sem consentimento". Substitui o hardcode isolado de mais cedo hoje
// ($FOLLOWUP_REENGAJAMENTO_LEAD_ATIVO) — o comportamento observável
// continua idêntico (0 mensagens saem, já que `whatsapp_envio_ativo`
// nasce desligado), mas agora por um único mecanismo reutilizável,
// testável e auditável (whatsapp_envios_log), que pode ser religado
// deliberadamente em Configurações com todos os limites valendo (opt-in,
// 1/contato/7 dias, 2 tentativas totais, horário comercial, limite
// diário, circuit breaker de falha). Dedup antigo por `reeng_sent_*` em
// config e o horário comercial checado aqui foram substituídos — o gate
// já cobre os dois.
//
// Oportunidade ainda na entrada do funil (bot/IA), sem mensagem nova do
// cliente há 30-120min, sem responsável humano assumido ainda — mesma
// janela usada desde sempre neste bloco.
$esfriando = $db->query("
    SELECT o.id, c.id as cliente_id, c.nome, c.telefone,
           (SELECT MAX(created_at) FROM whatsapp_mensagens m WHERE m.telefone = c.telefone AND m.direcao='in') as ultima_msg_in,
           (SELECT MAX(created_at) FROM whatsapp_mensagens m WHERE m.telefone = c.telefone AND m.direcao='out') as ultima_msg_out
    FROM oportunidades o
    JOIN clientes c ON c.id = o.cliente_id
    WHERE o.etapa IN ('whatsapp','qualificacao_ia')
      AND o.responsavel_id IS NULL
")->fetchAll();

$reengajados = 0;
foreach ($esfriando as $op) {
    if (!$op['ultima_msg_in']) continue;

    $minutosParado = (time() - strtotime($op['ultima_msg_in'])) / 60;
    $respondeuDepois = $op['ultima_msg_out'] && strtotime($op['ultima_msg_out']) > strtotime($op['ultima_msg_in']);

    // Só considera candidato quem parou de responder entre 30 e 120 min
    // atrás e ainda não recebeu resposta nossa depois da última mensagem
    // dele — pré-filtro barato antes de gastar uma chamada do gate
    // (rate-limit/horário/opt-in já cobertos lá, mas não faz sentido nem
    // chegar no gate pra quem não está na janela certa de "esfriando").
    if ($minutosParado < 30 || $minutosParado > 120 || $respondeuDepois) continue;

    // Variações (21/09/2026, mesmo racional de RECUPERACAO_MSGS_REENGAJAMENTO
    // em includes/recuperacao_leads.php) — esse reengajamento roda o tempo
    // todo, não só numa campanha pontual, então frase idêntica repetida ao
    // longo do tempo também soma pro mesmo risco de padrão de antispam.
    $nome = $op['nome'] ?: '';
    $msg = $nome
        ? variarMensagem([
            "Oi {$nome}! Vi que a conversa ficou pela metade — ainda tá pensando em vender o veículo? Se quiser continuar é só me responder por aqui. 🙂",
            "Olá {$nome}, tudo bem? Percebi que nossa conversa parou no meio — ainda tem interesse em vender o veículo? Fico no aguardo!",
            "Oi {$nome}! Ficamos sem resposta por aqui — você ainda pretende vender o carro? Se sim, é só me chamar que a gente continua 😊",
            "Olá {$nome}! Vi que ficou pendente nossa conversa — ainda está pensando em vender o veículo? Me conta quando puder!",
        ])
        : variarMensagem([
            "Oi! Vi que a conversa ficou pela metade — ainda tá pensando em vender o veículo? Se quiser continuar é só me responder por aqui. 🙂",
            "Olá, tudo bem? Percebi que nossa conversa parou no meio — ainda tem interesse em vender o veículo? Fico no aguardo!",
            "Oi! Ficamos sem resposta por aqui — você ainda pretende vender o carro? Se sim, é só me chamar que a gente continua 😊",
            "Olá! Vi que ficou pendente nossa conversa — ainda está pensando em vender o veículo? Me conta quando puder!",
        ]);

    $ok = enviarAtivoComGate($op['cliente_id'], $op['telefone'], $msg, 'followup_reengajamento');
    log_followup(($ok ? '✅' : '⏭️') . " Reengajamento → oportunidade #{$op['id']} ({$op['telefone']})");
    if ($ok) {
        // Fica no histórico igual qualquer outra mensagem enviada ao cliente
        // (senão o consultor que assumir depois vê a resposta do cliente
        // sem a pergunta que a gerou, e o admin/oportunidade.php não mostra
        // que esse toque saiu).
        registrarMensagem($op['telefone'], 'out', $msg, null, true);
        $reengajados++;
    }
}
log_followup("{$reengajados} reengajamento(s) enviado(s).");

log_followup('Concluído.');
setConfig('followup_ultimo_run', date('Y-m-d H:i:s'));

flock($lockHandle, LOCK_UN);
fclose($lockHandle);
