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
 *   /consultor <msg>    simula o consultor respondendo manualmente (fromMe)
 *   /pausar             simula o consultor assumindo a conversa (pausa a IA)
 *   /retomar            devolve a conversa pra IA
 *   /grupo <msg>        simula mensagem de grupo (deve ser ignorada)
 *   /midia <tipo>       simula mídia sem texto (image, audio, document...)
 *   /repetir            reenvia a última mensagem com o mesmo messageId (testa dedup)
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
require_once ROOT . '/chatbot-whatsapp/includes/mensagens.php';

$telefone = $argv[1] ?? '5531999990000';
$nome     = $argv[2] ?? 'Cliente Simulado';
$ultimoMessageId = null;

function novoMessageId(): string {
    return 'SIM-' . bin2hex(random_bytes(4));
}

function mostrarResultado(array $r): void {
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
    } else {
        echo "   🤖 [IA de qualificação ainda não plugada — pendência #3 do CLAUDE.md]\n";
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
                registrarMensagem($telefone, 'out', $arg, novoMessageId(), false);
                echo "   💬 consultor> {$arg} (registrado como 'out')\n";
                continue 2;

            case '/grupo':
                mostrarResultado(processarMensagemZapi([
                    'messageId' => novoMessageId(),
                    'phone' => $telefone . '-group',
                    'isGroup' => true,
                    'text' => ['message' => $arg],
                ]));
                continue 2;

            case '/midia':
                $tipo = $arg ?: 'image';
                mostrarResultado(processarMensagemZapi([
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
    mostrarResultado(processarMensagemZapi([
        'messageId' => $id,
        'phone' => $telefone,
        'senderName' => $nome,
        'text' => ['message' => $linha],
    ]));
}
