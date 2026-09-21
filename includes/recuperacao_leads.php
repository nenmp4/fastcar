<?php
/**
 * Recuperação de leads perdidos durante o bloqueio da instância principal
 * de WhatsApp (19-20/09/2026, "estamos deste ontem tav bloqueado wahatsApp
 * - conseguimos normalizar mais ficou quase 100 leads que não veio desde
 * sexta"). Busca direto na Z-API (`GET /chats`, confirmado contra a
 * instância real 20/09/2026, nunca documentado/usado antes neste projeto)
 * quem mandou mensagem durante o período e nunca virou lead aqui.
 *
 * ⚠️ Mudança de desenho (20/09/2026): a 1ª versão tentava recuperar o TEXTO
 * exato de cada mensagem via `GET /chat-messages/{phone}` e reinjetar no
 * pipeline normal como se tivesse acabado de chegar. Testado contra a
 * instância real, esse endpoint responde
 * `HTTP 400 {"error":"Does not work in multi device version"}` — não
 * funciona pra conta WhatsApp multi-dispositivo (praticamente toda conta
 * hoje), então não tem como recuperar o conteúdo original de jeito nenhum
 * (limitação da API, não bug daqui). Corrigido pra um desenho mais honesto
 * e mais seguro: em vez de FINGIR que recebemos a mensagem antiga,
 * mandamos uma mensagem PROATIVA de verdade (desculpa + pergunta se ainda
 * tem interesse) — quando/se a pessoa responder, o webhook normal (já
 * testado em produção) assume a conversa do zero e a IA qualifica de
 * verdade, sem inventar o que ela teria dito.
 *
 * Desenho confirmado com o usuário: (1) IA deve engajar esses leads de
 * verdade (regra #2, "salvar desde o primeiro contato", já cobre a
 * criação do registro; aqui o pedido explícito foi ir além e reengajar);
 * (2) nunca em rajada — mandar ~100 mensagens de uma vez logo depois de
 * reconectar é o MESMO padrão que já causou bloqueio de número antes
 * neste projeto (ver incidente "flood de mensagens duplicadas",
 * CLAUDE.md) — processa em lotes pequenos (10 por vez, a cada 15min via
 * cron, "vamos espalhar o envio cada 15 minutos blocos de 10"), nunca
 * tudo de uma vez.
 *
 * Idempotência simples e robusta: um telefone só é candidato a
 * recuperação enquanto NÃO existir em `clientes` — assim que processado
 * (ou se a pessoa mandar mensagem nova pelo canal normal antes do cron
 * rodar), some da lista sozinho, nunca precisa de marcação extra.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/whatsapp_config.php';
require_once __DIR__ . '/oportunidades.php'; // criarOuAbrirOportunidade()
require_once dirname(__DIR__) . '/chatbot-whatsapp/includes/mensagens.php'; // registrarMensagem()

/**
 * Variações da mensagem de desculpa + reengajamento — nunca geradas pela IA
 * (a IA só entra depois, quando/se a pessoa responder isso), 1 sorteada por
 * envio via `variarMensagem()` (`includes/whatsapp_config.php`). Antes era
 * 1 texto fixo só — achado real 21/09/2026: mandou a mesma frase pra ~48
 * clientes numa tarde só, exatamente o padrão que mais aciona antispam do
 * WhatsApp num número que já tinha sido bloqueado antes (ver incidente de
 * flood, CLAUDE.md). Faz as 2 coisas juntas (desculpa + pergunta) de
 * propósito em toda variação: é a ÚNICA mensagem proativa deste fluxo, não
 * faz sentido separar em duas.
 */
const RECUPERACAO_MSGS_REENGAJAMENTO = [
    'Oi! Peço desculpas pela demora no retorno — tivemos uma instabilidade técnica aqui e sua mensagem acabou ficando parada, sem resposta. Ainda tem interesse em vender seu veículo? Me conta um pouco mais que eu te ajudo! 😊',
    'Olá! Desculpa a demora — tivemos um problema técnico por aqui e sua mensagem não foi respondida a tempo. Você ainda pretende vender o seu veículo? Fico à disposição pra te ajudar! 🙂',
    'Oi, tudo bem? Passando pra pedir desculpas pela demora — tivemos uma instabilidade no sistema e sua mensagem ficou parada sem resposta. Ainda está pensando em vender o carro? Me conta mais detalhes!',
    'Olá! Foi mal a demora no retorno, tivemos um probleminha técnico aqui. Você ainda tem interesse em vender seu veículo? Se ainda tiver, me conta um pouco mais sobre ele 😊',
    'Oi! Peço desculpas — nosso sistema ficou fora do ar por um tempo e sua mensagem não chegou até a gente. Ainda pretende vender o veículo? Me dá mais detalhes que eu te ajudo a avaliar!',
    'Olá, tudo certo? Tivemos uma instabilidade técnica e acabamos não respondendo sua mensagem a tempo, desculpa por isso. Ainda tem interesse em vender seu carro? Conta um pouco mais pra mim!',
];

function recuperacaoLogDiagnostico(string $mensagem): void {
    $dir = __DIR__ . '/../storage/logs';
    @mkdir($dir, 0755, true);
    @file_put_contents($dir . '/recuperacao_leads_debug.log', '[' . date('Y-m-d H:i:s') . "] {$mensagem}\n", FILE_APPEND);
}

/**
 * Busca todas as conversas da instância principal na Z-API, paginado
 * (pageSize=100 confirmado contra a instância real). Nunca lança — retorna
 * array vazio e loga se a API falhar, quem chama decide o que fazer.
 */
function recuperacaoListarChatsZapi(): array {
    $instanceId = getConfig('zapi_instance_id');
    $token = getConfig('zapi_token');
    $clientToken = getConfig('zapi_client_token');
    if (!$instanceId || !$token) return [];

    $todas = [];
    $pagina = 1;
    do {
        $url = zapiBaseUrl() . "/instances/{$instanceId}/token/{$token}/chats?page={$pagina}&pageSize=100";
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $clientToken ? ["Client-Token: {$clientToken}"] : [],
            CURLOPT_TIMEOUT => 30,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code !== 200) {
            recuperacaoLogDiagnostico("Falha ao listar chats (página {$pagina}): HTTP {$code} — " . substr((string)$resp, 0, 500));
            break;
        }
        $lote = json_decode((string)$resp, true);
        if (!is_array($lote)) {
            recuperacaoLogDiagnostico("Resposta inesperada de /chats (página {$pagina}): " . substr((string)$resp, 0, 500));
            break;
        }
        $todas = array_merge($todas, $lote);
        $pagina++;
    } while (count($lote) === 100 && $pagina <= 20);

    return $todas;
}

/**
 * Cruza a lista de conversas da Z-API com `clientes` — retorna só quem
 * teve mensagem desde $desde, não é grupo, tem telefone válido, e AINDA
 * não é cliente cadastrado (nunca chegou a virar lead). Nunca inclui quem
 * já tem cadastro, mesmo que tenha sido criado por outro caminho enquanto
 * isso — a checagem é sempre contra o banco na hora.
 *
 * @return array<int, array{telefone:string, nome:string, ultima_msg:string}>
 */
function recuperacaoTelefonesFaltando(string $desde): array {
    $dataCorte = strtotime($desde . ' 00:00:00');
    $db = getDB();
    $faltando = [];

    foreach (recuperacaoListarChatsZapi() as $c) {
        if (!empty($c['isGroup']) || empty($c['phone'])) continue;
        $ts = (int)($c['lastMessageTime'] ?? 0);
        if ($ts <= 0 || ($ts / 1000) < $dataCorte) continue;

        $telNorm = normalizarTelefone((string)$c['phone']);
        if (!$telNorm || strlen($telNorm) < 12) continue;

        $stmt = $db->prepare('SELECT 1 FROM clientes WHERE telefone = ?');
        $stmt->execute([$telNorm]);
        if ($stmt->fetchColumn()) continue; // já é cliente, nunca reprocessa

        $faltando[] = [
            'telefone' => $telNorm,
            'nome' => (string)($c['name'] ?? ''),
            'ultima_msg' => date('Y-m-d H:i:s', (int)($ts / 1000)),
        ];
    }

    // Mais antigo primeiro — quem espera há mais tempo é atendido primeiro.
    usort($faltando, fn($a, $b) => $a['ultima_msg'] <=> $b['ultima_msg']);
    return $faltando;
}

/**
 * Processa até $tamanhoLote telefones pendentes: cria o cadastro (mesma
 * função que o webhook usa na entrada normal — regra #2, salva desde o
 * 1º contato) e manda a mensagem fixa de desculpa+reengajamento. Nunca
 * chama a IA diretamente aqui — não tem mensagem real do cliente pra
 * qualificar ainda; se a pessoa responder, o webhook de produção assume a
 * conversa do zero, exatamente como qualquer entrada nova. Best-effort por
 * telefone: uma falha não derruba o lote inteiro, só pula pro próximo.
 *
 * @return array{processados:int, pulados:int, detalhe:array}
 */
function recuperacaoProcessarLote(int $tamanhoLote = 10, string $desde = '2026-09-18'): array {
    $faltando = array_slice(recuperacaoTelefonesFaltando($desde), 0, $tamanhoLote);

    $processados = 0;
    $pulados = 0;
    $detalhe = [];

    foreach ($faltando as $c) {
        $telefone = $c['telefone'];
        try {
            $oportunidade = criarOuAbrirOportunidade($telefone, $c['nome']);
            $msg = variarMensagem(RECUPERACAO_MSGS_REENGAJAMENTO);

            if (!zapiEnviarTexto($telefone, $msg)) {
                $pulados++;
                $detalhe[] = ['telefone' => $telefone, 'ok' => false, 'motivo' => 'zapiEnviarTexto() falhou'];
                continue;
            }
            registrarMensagem($telefone, 'out', $msg, null, true);

            $processados++;
            $detalhe[] = ['telefone' => $telefone, 'ok' => true, 'oportunidade_id' => $oportunidade['oportunidade_id'] ?? null];
        } catch (Throwable $e) {
            $pulados++;
            $detalhe[] = ['telefone' => $telefone, 'ok' => false, 'motivo' => $e->getMessage()];
            recuperacaoLogDiagnostico("Falha processando recuperação de {$telefone}: " . $e->getMessage());
        }
    }

    return ['processados' => $processados, 'pulados' => $pulados, 'detalhe' => $detalhe];
}
