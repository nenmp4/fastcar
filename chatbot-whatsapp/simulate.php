<?php
/**
 * Simulador de conversa do WhatsApp — CLI pra testar o funil (webhook +
 * abertura de oportunidade) sem precisar de credencial Z-API real nem da
 * IA de qualificação (pendência #3 do CLAUDE.md, provedor ainda não
 * decidido).
 *
 * Usa a MESMA função que o webhook real usa — processarMensagemZapi()
 * em chatbot-whatsapp/includes/mensagens.php — então o que acontece aqui
 * é garantidamente o que aconteceria com uma mensagem real do Z-API.
 *
 * ⚠️ Usa o banco de verdade do projeto (database/fastcar.db), não um banco
 * isolado — é uma ferramenta de teste manual em dev, use um telefone
 * fictício (padrão: 5531999990000) pra não misturar com dado real.
 *
 * Uso:
 *   php chatbot-whatsapp/simulate.php [telefone] [nome]
 *
 * Comandos dentro da sessão (linha sem "/" é tratada como mensagem do
 * cliente):
 *   /nome <nome>        muda o nome do contato simulado
 *   /tel <telefone>     muda o telefone simulado
 *   /instancia <id>     simula mensagens vindas da instância de um consultor
 *                       (id igual ao cadastrado em admin/configuracoes.php);
 *                       sem argumento volta pra instância principal
 *   /consultor <msg>    simula o consultor respondendo manualmente (fromMe),
 *                       pela instância ativa no momento (ver /instancia)
 *   /pausar             simula o consultor assumindo a conversa (pausa a IA)
 *   /retomar            devolve a conversa pra IA
 *   /grupo <msg>        simula mensagem de grupo (deve ser ignorada)
 *   /midia <tipo>       simula mídia sem texto (image, audio, document...)
 *   /repetir            reenvia a última mensagem com o mesmo messageId (testa dedup)
 *   /anuncio <headline> simula a PRÓXIMA mensagem como clique num anúncio
 *                       "Clique para WhatsApp" do Meta (referral) — só
 *                       funciona em cliente novo (telefone nunca visto),
 *                       origem é first-touch (ver includes/oportunidades.php)
 *   /status             mostra estado atual (etapa, ia_pausada, nº mensagens)
 *   /historico          mostra histórico de etapas da oportunidade
 *   /sair               encerra
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Este script só roda via linha de comando (CLI).\n");
}

define('ROOT', dirname(__DIR__));
require_once ROOT . '/includes/db.php';
require_once ROOT . '/includes/security.php';
require_once ROOT . '/includes/whatsapp_config.php';
require_once ROOT . '/includes/oportunidades.php';
require_once ROOT . '/includes/usuarios.php';
require_once ROOT . '/includes/zapi_instancias.php';
require_once ROOT . '/chatbot-whatsapp/includes/mensagens.php';

$telefone = $argv[1] ?? '5531999990000';
$nome     = $argv[2] ?? 'Cliente Simulado';
$ultimoMessageId = null;
$instanciaAtual = ''; // '' = instância principal
$referralPendente = null; // referral simulado (/anuncio), consumido na próxima mensagem só

function novoMessageId(): string {
    return 'SIM-' . bin2hex(random_bytes(4));
}

function descreverInstancia(array $instancia): string {
    if ($instancia['tipo'] === 'principal') return 'instância principal';
    if ($instancia['tipo'] === 'consultor') {
        $u = $instancia['usuario_id'] ? buscarUsuario($instancia['usuario_id']) : null;
        return 'instância do consultor: ' . ($u['nome'] ?? "usuário #{$instancia['usuario_id']}");
    }
    return 'instância desconhecida (não cadastrada em admin/configuracoes.php)';
}

function mostrarResultado(array $r): void {
    echo '   📡 via ' . descreverInstancia($r['instancia']) . "\n";
    if ($r['ignored']) {
        echo "   ⏭️  ignorado: {$r['ignored']}\n";
        return;
    }
    echo "   💾 mensagem salva ({$r['tipo']})\n";
    if ($r['oportunidade']) {
        $op = $r['oportunidade'];
        echo '   ' . ($op['nova'] ? '🆕 oportunidade criada' : '📎 oportunidade existente reaproveitada')
           . " #{$op['oportunidade_id']} (cliente #{$op['cliente_id']})\n";
    }
    if ($r['erro_oportunidade']) {
        echo "   ⚠️  erro ao abrir oportunidade: {$r['erro_oportunidade']}\n";
    }
    if ($r['ia_pausada']) {
        echo "   ⏸️  IA pausada — humano assumiu, nenhuma resposta automática seria enviada\n";
    } elseif ($r['ia_resultado']) {
        $ia = $r['ia_resultado'];
        if ($ia['resposta'] !== '') {
            echo "   🤖 Fastcar> {$ia['resposta']}\n";
            if (!$ia['enviada']) {
                echo "      (⚠️ não enviado de verdade — sem credencial Z-API real neste ambiente)\n";
            }
        } else {
            echo "   🤖 [sem resposta da IA — confira gemini_api_key em Configurações]\n";
        }
        if ($ia['sem_perfil']) echo "   ⚪ IA marcou como SEM PERFIL DE COMPRA e encerrou.\n";
        if ($ia['qualificacao_completa']) echo "   ✅ IA concluiu a qualificação — oportunidade avançou pro bloco 4.\n";
    } else {
        echo "   🤖 [IA não rodou nesse turno — fora dos blocos 2/3, ou veio de instância de consultor]\n";
    }
}

function mostrarStatus(string $telefone): void {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT o.id, o.etapa, o.veiculo_modelo, o.proxima_acao
        FROM oportunidades o JOIN clientes c ON c.id = o.cliente_id
        WHERE c.telefone = ? ORDER BY o.id DESC LIMIT 1
    ");
    $stmt->execute([normalizarTelefone($telefone)]);
    $op = $stmt->fetch();

    $stmt2 = $db->prepare("SELECT COUNT(*) FROM whatsapp_mensagens WHERE telefone = ?");
    $stmt2->execute([normalizarTelefone($telefone)]);
    $totalMsg = (int)$stmt2->fetchColumn();

    echo "── status ──\n";
    echo 'telefone: ' . normalizarTelefone($telefone) . "\n";
    echo 'ia_pausada: ' . (iaPausada($telefone) ? 'sim' : 'não') . "\n";
    echo "mensagens registradas: {$totalMsg}\n";
    if ($op) {
        echo "oportunidade #{$op['id']} — etapa: " . etapaLabel($op['etapa']) . "\n";
        echo 'veículo: ' . ($op['veiculo_modelo'] ?: '(não identificado ainda)') . "\n";
        echo 'próxima ação: ' . ($op['proxima_acao'] ?: '(nenhuma)') . "\n";
    } else {
        echo "nenhuma oportunidade aberta ainda pra esse telefone.\n";
    }
    echo "────────────\n";
}

function mostrarHistorico(string $telefone): void {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT h.etapa_anterior, h.etapa_nova, h.observacao, h.created_at
        FROM oportunidade_historico h
        JOIN oportunidades o ON o.id = h.oportunidade_id
        JOIN clientes c ON c.id = o.cliente_id
        WHERE c.telefone = ? ORDER BY h.id
    ");
    $stmt->execute([normalizarTelefone($telefone)]);
    $linhas = $stmt->fetchAll();
    if (!$linhas) {
        echo "(sem histórico ainda)\n";
        return;
    }
    foreach ($linhas as $l) {
        echo "[{$l['created_at']}] {$l['etapa_anterior']} → {$l['etapa_nova']}"
           . ($l['observacao'] ? " ({$l['observacao']})" : '') . "\n";
    }
}

echo "=== Simulador de conversa WhatsApp — Fastcar CRM ===\n";
echo "Telefone simulado: {$telefone} | Nome: {$nome}\n";
echo "Digite mensagens como se fosse o cliente. \"/\" pra comandos (ex: /status), /sair pra terminar.\n\n";

while (true) {
    echo 'cliente> ';
    $linha = fgets(STDIN);
    if ($linha === false) break;
    $linha = rtrim($linha, "\n");
    if ($linha === '') continue;

    if ($linha[0] === '/') {
        [$cmd, $arg] = array_pad(explode(' ', $linha, 2), 2, '');
        switch ($cmd) {
            case '/sair':
                exit(0);

            case '/nome':
                $nome = $arg;
                echo "   nome atualizado pra: {$nome}\n";
                continue 2;

            case '/tel':
                $telefone = $arg;
                echo "   telefone simulado agora é: {$telefone}\n";
                continue 2;

            case '/instancia':
                $instanciaAtual = $arg;
                if ($instanciaAtual === '') {
                    echo "   📡 voltou pra instância principal.\n";
                } else {
                    $info = zapiIdentificarInstancia($instanciaAtual);
                    echo '   📡 simulando a partir de: ' . descreverInstancia($info) . "\n";
                }
                continue 2;

            case '/anuncio':
                $referralPendente = [
                    'source_id' => 'AD-SIM-' . substr(md5($arg ?: 'anuncio'), 0, 8),
                    'source_type' => 'ad',
                    'headline' => $arg ?: 'Anúncio simulado',
                ];
                echo "   📣 próxima mensagem vai simular clique no anúncio \"" . ($arg ?: 'Anúncio simulado') . "\"\n";
                continue 2;

            case '/status':
                mostrarStatus($telefone);
                continue 2;

            case '/historico':
                mostrarHistorico($telefone);
                continue 2;

            case '/pausar':
                pausarIA($telefone);
                echo "   ⏸️  IA pausada pra {$telefone} — simula consultor assumindo a conversa.\n";
                continue 2;

            case '/retomar':
                retomarIA($telefone);
                echo "   ▶️  IA retomada pra {$telefone}.\n";
                continue 2;

            case '/consultor':
                $infoConsultor = zapiIdentificarInstancia($instanciaAtual);
                registrarMensagem($telefone, 'out', $arg, novoMessageId(), false, 'text', $infoConsultor['usuario_id']);
                echo "   💬 consultor> {$arg} (registrado como 'out', via " . descreverInstancia($infoConsultor) . ")\n";
                continue 2;

            case '/grupo':
                mostrarResultado(processarMensagemZapi([
                    'instanceId' => $instanciaAtual,
                    'messageId' => novoMessageId(),
                    'phone' => $telefone . '-group',
                    'isGroup' => true,
                    'text' => ['message' => $arg],
                ]));
                continue 2;

            case '/midia':
                $tipo = $arg ?: 'image';
                mostrarResultado(processarMensagemZapi([
                    'instanceId' => $instanciaAtual,
                    'messageId' => novoMessageId(),
                    'phone' => $telefone,
                    'senderName' => $nome,
                    $tipo => ['url' => 'https://exemplo.invalido/arquivo'],
                ]));
                continue 2;

            case '/repetir':
                if (!$ultimoMessageId) {
                    echo "   (nenhuma mensagem anterior nesta sessão pra repetir)\n";
                    continue 2;
                }
                mostrarResultado(processarMensagemZapi([
                    'instanceId' => $instanciaAtual,
                    'messageId' => $ultimoMessageId,
                    'phone' => $telefone,
                    'senderName' => $nome,
                    'text' => ['message' => '(reenvio simulado, mesmo messageId — testa dedup)'],
                ]));
                continue 2;

            default:
                echo "   comando desconhecido: {$cmd}\n";
                continue 2;
        }
    }

    $id = novoMessageId();
    $ultimoMessageId = $id;
    $payload = [
        'instanceId' => $instanciaAtual,
        'messageId' => $id,
        'phone' => $telefone,
        'senderName' => $nome,
        'text' => ['message' => $linha],
    ];
    if ($referralPendente) {
        $payload['referral'] = $referralPendente;
        $referralPendente = null; // consumido — só a próxima mensagem simula o clique
    }
    mostrarResultado(processarMensagemZapi($payload));
}
