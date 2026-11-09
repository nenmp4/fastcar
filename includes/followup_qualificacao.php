<?php
/**
 * Follow-up da qualificação por IA (09/11/2026, "follow up está horrível —
 * a cada 2 horas e até mesmo acrescentar"). Confirmado com o usuário:
 *  - sai pelo MESMO número da conversa (canal principal), nunca um número
 *    separado — é continuação de uma conversa que o próprio cliente abriu;
 *  - só lead que parou de responder enquanto a IA qualificava (etapa
 *    'whatsapp'/'qualificacao_ia'), com ou sem consultor atribuído — nunca
 *    quem já está com consultor atendendo;
 *  - até 3 tentativas: 2h, 4h e 6h depois da última mensagem do cliente
 *    (sempre com pelo menos 2h entre uma e outra);
 *  - só no horário comercial (o mesmo da fila, Configurações → Fila).
 *
 * Substitui o bloco 3 antigo do cron/followup.php, que passava pelo gate de
 * envio ATIVO (pra quem nunca escreveu) e por isso nunca saía nada. Travas
 * mantidas aqui: opt-out (SAIR), IA pausada (humano assumiu), máximo de 3
 * por silêncio do cliente, limite diário global e mensagem sempre curta com
 * uma pergunta só.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/whatsapp_config.php';
require_once dirname(__DIR__) . '/chatbot-whatsapp/includes/mensagens.php'; // registrarMensagem(), iaPausada()

const FOLLOWUP_QUALIF_INTERVALO_HORAS = 2;
const FOLLOWUP_QUALIF_MAX_TENTATIVAS = 3;
const FOLLOWUP_QUALIF_LIMITE_DIA = 120;
/**
 * Janela de 24h (09/11/2026, "follow-up tá dentro da janela?"): nunca manda
 * follow-up mais de 23h depois da última mensagem do cliente — fica sempre
 * dentro da janela de atendimento do WhatsApp, com 1h de folga. Tentativa
 * que não coube (ex: fora do expediente) simplesmente não sai.
 */
const FOLLOWUP_QUALIF_MAX_HORAS = 23;

/** Mensagem da tentativa $n (1..3) — curta, simples, uma pergunta só. */
function followupQualificacaoMensagem(int $n, string $nome): string {
    $oi = $nome !== '' ? "Oi {$nome}" : 'Oi';
    $opcoes = match ($n) {
        1 => [
            "{$oi}! Podemos continuar a avaliação do seu veículo?",
            "{$oi}, tudo bem! Quer continuar de onde paramos?",
            "{$oi}! Ficou alguma dúvida? Me responde que a gente continua.",
        ],
        2 => [
            "{$oi}! Ainda tem interesse em vender o veículo?",
            "{$oi}, passando aqui de novo. Ainda pensa em vender o carro?",
            "{$oi}! Consigo te ajudar com a venda do veículo ainda?",
        ],
        default => [
            "{$oi}! Vou deixar a conversa aqui. Se quiser retomar, é só me mandar um oi.",
            "{$oi}, vou pausar por aqui. Quando quiser continuar, é só responder.",
        ],
    };
    return variarMensagem($opcoes);
}

/**
 * Roda uma passada (chamada pelo cron/followup.php a cada 30 min). Devolve
 * quantos follow-ups saíram. $agora só existe pra teste.
 */
function processarFollowupQualificacao(?callable $log = null, ?int $agora = null): int {
    $log = $log ?? function (string $m) {};
    $agora = $agora ?? time();
    if (!automacaoDentroHorarioComercial()) {
        $log('Follow-up da qualificação: fora do horário comercial, nada sai agora.');
        return 0;
    }
    $db = getDB();
    $chaveDia = 'followup_qualif_enviados_' . date('Y-m-d', $agora);
    $enviadosHoje = (int)getConfig($chaveDia);

    $leads = $db->query("
        SELECT o.id, c.id AS cliente_id, c.nome, c.telefone, COALESCE(c.optout_whatsapp, 0) AS optout,
               (SELECT MAX(id) FROM whatsapp_mensagens m WHERE m.telefone = c.telefone AND m.direcao = 'in' AND m.canal = 'compra') AS id_ultima_in,
               (SELECT MAX(created_at) FROM whatsapp_mensagens m WHERE m.telefone = c.telefone AND m.direcao = 'in' AND m.canal = 'compra') AS ultima_in,
               (SELECT MAX(created_at) FROM whatsapp_mensagens m WHERE m.telefone = c.telefone AND m.direcao = 'out' AND m.canal = 'compra') AS ultima_out
        FROM oportunidades o
        JOIN clientes c ON c.id = o.cliente_id
        WHERE o.etapa IN ('whatsapp', 'qualificacao_ia')
    ")->fetchAll();

    $enviados = 0;
    foreach ($leads as $op) {
        if ($enviadosHoje >= FOLLOWUP_QUALIF_LIMITE_DIA) {
            $log('Follow-up da qualificação: limite diário (' . FOLLOWUP_QUALIF_LIMITE_DIA . ') atingido.');
            break;
        }
        if (!$op['ultima_in'] || (int)$op['optout'] === 1) continue;
        $tIn = strtotime($op['ultima_in']);
        // Só quem ficou em silêncio DEPOIS de a gente responder (a bola tá com o cliente).
        if (!$op['ultima_out'] || strtotime($op['ultima_out']) < $tIn) continue;
        if ($agora - $tIn > FOLLOWUP_QUALIF_MAX_HORAS * 3600) continue;
        if (iaPausada((string)$op['telefone'])) continue; // humano assumiu a conversa

        // Contagem por silêncio: zera quando o cliente manda mensagem nova.
        $chave = 'followup_qualif_' . $op['telefone'];
        $estado = json_decode((string)getConfig($chave), true) ?: [];
        if ((int)($estado['ref'] ?? 0) !== (int)$op['id_ultima_in']) $estado = ['ref' => (int)$op['id_ultima_in'], 'n' => 0, 'ultimo' => 0];
        $n = (int)$estado['n'];
        if ($n >= FOLLOWUP_QUALIF_MAX_TENTATIVAS) continue;
        $intervalo = FOLLOWUP_QUALIF_INTERVALO_HORAS * 3600;
        if ($agora - $tIn < $intervalo * ($n + 1)) continue;
        if ($estado['ultimo'] && $agora - (int)$estado['ultimo'] < $intervalo) continue;

        $msg = followupQualificacaoMensagem($n + 1, trim((string)($op['nome'] ?? '')));
        $ok = zapiEnviarTexto((string)$op['telefone'], $msg);
        $log(($ok ? '✅' : '❌') . " Follow-up " . ($n + 1) . "/" . FOLLOWUP_QUALIF_MAX_TENTATIVAS . " → oportunidade #{$op['id']} ({$op['telefone']})");
        if (!$ok) continue;
        registrarMensagem((string)$op['telefone'], 'out', $msg, null, true);
        $estado['n'] = $n + 1;
        $estado['ultimo'] = $agora;
        setConfig($chave, json_encode($estado));
        $enviadosHoje++;
        setConfig($chaveDia, (string)$enviadosHoje);
        $enviados++;
    }
    return $enviados;
}
