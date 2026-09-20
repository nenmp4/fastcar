<?php
/**
 * Recuperação de leads perdidos durante o bloqueio da instância principal
 * de WhatsApp (19-20/09/2026, "estamos deste ontem tav bloqueado wahatsApp
 * - conseguimos normalizar mais ficou quase 100 leads que não veio desde
 * sexta"). Nunca confiado no webhook sozinho pra recuperar — busca direto
 * na Z-API (endpoints `chats`/`chat-messages`, confirmados contra a
 * instância real 20/09/2026, nunca documentados/usados antes neste
 * projeto) quem mandou mensagem durante o período e nunca virou lead aqui.
 *
 * Desenho confirmado com o usuário: (1) IA deve qualificar esses leads de
 * verdade, não só criar o registro (regra #2, "salvar desde o primeiro
 * contato" já cobre a criação — aqui o pedido explícito foi a IA responder
 * de fato); (2) nunca em rajada — mandar ~100 mensagens de uma vez logo
 * depois de reconectar é o MESMO padrão que já causou bloqueio de número
 * antes neste projeto (ver incidente "flood de mensagens duplicadas",
 * CLAUDE.md) — processa em lotes pequenos (10 por vez, a cada 15min via
 * cron), nunca tudo de uma vez.
 *
 * Reaproveita 100% do pipeline real de mensagem recebida
 * (processarMensagemZapi(), chatbot-whatsapp/includes/mensagens.php) —
 * monta um payload SINTÉTICO com a última mensagem real do cliente
 * (recuperada da Z-API) no mesmo formato que o webhook usa, e deixa o
 * pipeline já testado em produção cuidar de criar a oportunidade, mudar
 * etapa e chamar a IA — nunca duplica essa lógica aqui. Mensagem de
 * desculpa pela demora é enviada ANTES, direto (fora da IA, texto fixo),
 * pra deixar claro pro cliente que a resposta atrasou por instabilidade
 * técnica, não que foi ignorado.
 *
 * Idempotência simples e robusta: um telefone só é candidato a
 * recuperação enquanto NÃO existir em `clientes` — assim que processado
 * (ou se a pessoa mandar mensagem nova pelo canal normal antes do cron
 * rodar), some da lista sozinho, nunca precisa de marcação extra.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/whatsapp_config.php';
require_once dirname(__DIR__) . '/chatbot-whatsapp/includes/mensagens.php';

/** Mensagem fixa de desculpa — nunca gerada pela IA, sempre a mesma, sempre antes da qualificação retomar. */
const RECUPERACAO_MSG_DESCULPA = 'Oi! Peço desculpas pela demora no retorno — tivemos uma instabilidade técnica aqui e sua mensagem acabou ficando parada. Vamos continuar? 😊';

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
 * Busca a última mensagem de verdade que o CLIENTE mandou (nunca `fromMe`)
 * nessa conversa, via `chat-messages`. Formato de resposta nunca confirmado
 * antes deste incidente — tenta os nomes de campo mais prováveis (mesma
 * cautela de todo endpoint Z-API novo neste projeto) e loga o corpo cru se
 * nenhum bater, em vez de adivinhar às cegas. Retorna null se não achar
 * nada aproveitável — quem chama pula esse telefone sem travar o lote.
 */
function recuperacaoBuscarUltimaMensagemCliente(string $telefoneNorm): ?string {
    $instanceId = getConfig('zapi_instance_id');
    $token = getConfig('zapi_token');
    $clientToken = getConfig('zapi_client_token');
    if (!$instanceId || !$token) return null;

    $url = zapiBaseUrl() . "/instances/{$instanceId}/token/{$token}/chat-messages/{$telefoneNorm}";
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
        recuperacaoLogDiagnostico("Falha ao buscar chat-messages de {$telefoneNorm}: HTTP {$code} — " . substr((string)$resp, 0, 500));
        return null;
    }
    $mensagens = json_decode((string)$resp, true);
    // Alguns endpoints Z-API envelopam a lista em {"messages": [...]} — cobre
    // os dois formatos possíveis sem travar se vier um array direto.
    if (isset($mensagens['messages']) && is_array($mensagens['messages'])) {
        $mensagens = $mensagens['messages'];
    }
    if (!is_array($mensagens) || !$mensagens) {
        recuperacaoLogDiagnostico("chat-messages de {$telefoneNorm} veio vazio/formato inesperado: " . substr((string)$resp, 0, 800));
        return null;
    }

    // Mais recente primeiro — mensagens costumam vir em ordem cronológica
    // crescente, mas não assume isso: ordena por momento explicitamente
    // quando o campo existir, senão confia na ordem da resposta.
    $campoMomento = null;
    foreach (['momment', 'moment', 'timestamp', 'messageTimestamp'] as $c) {
        if (isset($mensagens[0][$c])) { $campoMomento = $c; break; }
    }
    if ($campoMomento) {
        usort($mensagens, fn($a, $b) => (int)($b[$campoMomento] ?? 0) <=> (int)($a[$campoMomento] ?? 0));
    } else {
        $mensagens = array_reverse($mensagens);
    }

    foreach ($mensagens as $m) {
        if (!empty($m['fromMe'])) continue; // só interessa o que o CLIENTE mandou
        $texto = $m['text']['message'] ?? $m['body'] ?? $m['message'] ?? null;
        if (is_string($texto) && trim($texto) !== '') {
            return trim($texto);
        }
    }

    recuperacaoLogDiagnostico("chat-messages de {$telefoneNorm} não teve nenhuma mensagem de texto do cliente reconhecível: " . substr(json_encode($mensagens), 0, 800));
    return null;
}

/**
 * Processa até $tamanhoLote telefones pendentes: manda a desculpa fixa,
 * depois injeta a última mensagem real do cliente no pipeline normal de
 * webhook (processarMensagemZapi()) — a IA qualifica a partir daí, exatamente
 * como qualificaria uma mensagem recebida agora. Best-effort por telefone:
 * uma falha não derruba o lote inteiro, só pula pro próximo.
 *
 * @return array{processados:int, pulados:int, detalhe:array}
 */
function recuperacaoProcessarLote(int $tamanhoLote = 10, string $desde = '2026-09-18'): array {
    $faltando = array_slice(recuperacaoTelefonesFaltando($desde), 0, $tamanhoLote);
    $instanciaPrincipal = ['tipo' => 'principal', 'usuario_id' => null, 'client_token' => getConfig('zapi_client_token')];

    $processados = 0;
    $pulados = 0;
    $detalhe = [];

    foreach ($faltando as $c) {
        $telefone = $c['telefone'];
        try {
            $texto = recuperacaoBuscarUltimaMensagemCliente($telefone);
            if (!$texto) {
                $pulados++;
                $detalhe[] = ['telefone' => $telefone, 'ok' => false, 'motivo' => 'Sem mensagem de texto recuperável'];
                continue;
            }

            // Desculpa primeiro, sempre — fora da IA, texto fixo, registrada
            // como qualquer outra mensagem automática do sistema.
            if (zapiEnviarTexto($telefone, RECUPERACAO_MSG_DESCULPA)) {
                registrarMensagem($telefone, 'out', RECUPERACAO_MSG_DESCULPA, null, true);
            }

            // Payload sintético no mesmo formato que extrairTexto()/
            // processarMensagemZapi() já esperam de um webhook real —
            // messageId próprio e estável (idempotente: jaProcessado()
            // bloqueia reprocessar o mesmo se o script rodar 2x por engano).
            $payload = [
                'messageId' => 'recuperacao_' . $telefone . '_' . substr(md5($texto), 0, 8),
                'phone' => $telefone,
                'fromMe' => false,
                'isGroup' => false,
                'text' => ['message' => $texto],
                'senderName' => $c['nome'],
                'instanceId' => getConfig('zapi_instance_id'),
            ];
            $r = processarMensagemZapi($payload, $instanciaPrincipal);

            $processados++;
            $detalhe[] = ['telefone' => $telefone, 'ok' => true, 'oportunidade_id' => $r['oportunidade']['oportunidade_id'] ?? null];
        } catch (Throwable $e) {
            $pulados++;
            $detalhe[] = ['telefone' => $telefone, 'ok' => false, 'motivo' => $e->getMessage()];
            recuperacaoLogDiagnostico("Falha processando recuperação de {$telefone}: " . $e->getMessage());
        }
    }

    return ['processados' => $processados, 'pulados' => $pulados, 'detalhe' => $detalhe];
}
