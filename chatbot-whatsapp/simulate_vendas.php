<?php
/**
 * Simulador de conversa do WhatsApp — CLI pra testar o funil de VENDAS
 * (revenda de veículo da frota, lado do COMPRADOR) sem precisar de
 * credencial Z-API/Evolution real — 21/10/2026, "como ficou qualificação
 * de ia nas vendas manda um simulação". Espelha chatbot-whatsapp/simulate.php
 * (lado de compra), mesma disciplina já documentada no CLAUDE.md: arquivo
 * PRÓPRIO, nunca parametrizar o de compra pra acomodar os dois lados.
 *
 * Usa a MESMA função que o webhook real usa — processarMensagemVendasZapi()
 * em chatbot-whatsapp/includes/mensagens_vendas.php — então o que acontece
 * aqui é garantidamente o que aconteceria com uma mensagem real do Z-API/
 * Evolution na instância dedicada de vendas.
 *
 * ⚠️ Usa o banco de verdade do projeto (database/fastcar.db), não um banco
 * isolado — é uma ferramenta de teste manual em dev, use um telefone
 * fictício (padrão: 5531999990001, diferente do padrão do simulador de
 * compra pra nunca colidir com um teste do lado de compra rodado em
 * paralelo) pra não misturar com dado real.
 *
 * ⚠️ Precisa de gemini_api_key configurada em Configurações (ou OpenAI como
 * fallback) pra IA responder de verdade — sem isso o turno roda normal mas
 * a resposta vem vazia, mesmo aviso que o simulador de compra já mostra.
 *
 * ⚠️ A IA de vendas só oferece/identifica veículo que está REALMENTE
 * disponível pra venda agora (listarFrotaDisponivelParaVenda() —
 * oportunidades.etapa='fechado' sem negociação de venda ativa, regra #3,
 * nunca inventa veículo) — rode `/frota` primeiro pra conferir o que existe
 * no banco; num ambiente de dev vazio, sem nenhum veículo fechado ainda, a
 * IA nunca diz que não tem carro disponível — convida pra loja ver as opções (24/10/2026).
 *
 * Uso:
 *   php chatbot-whatsapp/simulate_vendas.php [telefone] [nome]
 *
 * Comandos dentro da sessão (linha sem "/" é tratada como mensagem do
 * comprador):
 *   /nome <nome>        muda o nome do contato simulado
 *   /tel <telefone>     muda o telefone simulado
 *   /frota              lista os veículos disponíveis pra venda agora
 *                       (o que a IA pode legitimamente oferecer)
 *   /vendedor <msg>     simula o vendedor respondendo manualmente (fromMe)
 *   /pausar             simula o vendedor assumindo a conversa (pausa a IA)
 *   /retomar            devolve a conversa pra IA
 *   /grupo <msg>        simula mensagem de grupo (deve ser ignorada)
 *   /midia <tipo>       simula mídia sem texto (image, audio, document...)
 *   /repetir            reenvia a última mensagem com o mesmo messageId (testa dedup)
 *   /status             mostra estado atual (etapa, ia_pausada, nº mensagens)
 *   /historico          mostra histórico de etapas da negociação
 *   /sair               encerra
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Este script só roda via linha de comando (CLI).\n");
}

define('ROOT', dirname(__DIR__));
// Conversa aqui é digitada linha a linha, ao vivo — não faz sentido esperar
// WHATSAPP_DEBOUNCE_SEGUNDOS (ver chatbot-whatsapp/includes/mensagens.php)
// depois de cada mensagem simulada.
define('WHATSAPP_DEBOUNCE_SEGUNDOS', 0);
require_once ROOT . '/includes/db.php';
require_once ROOT . '/includes/security.php';
require_once ROOT . '/includes/whatsapp_config.php';
require_once ROOT . '/includes/oportunidades.php';
require_once ROOT . '/includes/usuarios.php';
require_once ROOT . '/includes/zapi_instancias.php';
require_once ROOT . '/includes/vendas.php';
require_once ROOT . '/chatbot-whatsapp/includes/mensagens.php';
require_once ROOT . '/chatbot-whatsapp/includes/mensagens_vendas.php';

$telefone = $argv[1] ?? '5531999990001';
$nome     = $argv[2] ?? 'Comprador Simulado';
$ultimoMessageId = null;

// Simula sempre vindo da instância DEDICADA de vendas — nunca depende de
// uma instância Z-API/Evolution real configurada em Configurações, mesmo
// truque que processarMensagemVendasZapi() já aceita (2º parâmetro
// opcional, sobrescreve zapiIdentificarInstancia()): só o processamento
// com $instancia['tipo']==='vendas' é que roda a qualificação por IA.
function instanciaVendasSimulada(): array {
    return ['tipo' => 'vendas', 'usuario_id' => null, 'client_token' => '', 'canal' => 'zapi'];
}

function novoMessageId(): string {
    return 'SIMV-' . bin2hex(random_bytes(4));
}

function mostrarResultado(array $r): void {
    if ($r['ignored']) {
        echo "   ⏭️  ignorado: {$r['ignored']}\n";
        return;
    }
    echo "   💾 mensagem salva ({$r['tipo']})\n";
    if ($r['venda_lead']) {
        $vl = $r['venda_lead'];
        echo '   ' . ($vl['nova'] ? '🆕 negociação de venda criada' : '📎 negociação existente reaproveitada')
           . " #{$vl['venda_id']}\n";
    }
    if ($r['erro_venda']) {
        echo "   ⚠️  erro ao abrir negociação: {$r['erro_venda']}\n";
    }
    if ($r['ia_pausada']) {
        echo "   ⏸️  IA pausada — vendedor assumiu, nenhuma resposta automática seria enviada\n";
    } elseif ($r['ia_resultado']) {
        $ia = $r['ia_resultado'];
        if ($ia['resposta'] !== '') {
            echo "   🤖 Fastcar> {$ia['resposta']}\n";
            if (!$ia['enviada']) {
                echo "      (⚠️ não enviado de verdade — sem credencial Z-API/Evolution real neste ambiente)\n";
            }
        } else {
            echo "   🤖 [sem resposta da IA — confira gemini_api_key em Configurações]\n";
        }
        if ($ia['sem_perfil']) echo "   ⚪ IA marcou como SEM PERFIL e encerrou.\n";
        if ($ia['qualificacao_completa']) echo "   ✅ IA concluiu a qualificação — negociação avançou.\n";
        if ($ia['pedido_atendente_humano']) echo "   🙋 Comprador pediu atendimento humano — escalado direto.\n";
        if ($ia['escalado_sem_avanco']) echo "   ⏫ Escalado por estagnação (turnos sem avanço).\n";
    } else {
        echo "   🤖 [IA não rodou nesse turno — fora dos blocos whatsapp/qualificacao_ia]\n";
    }
}

function mostrarStatus(string $telefone): void {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT id, etapa, oportunidade_id, temperatura_lead, aceita_visitar_fastcar,
               visita_dia_horario_texto, visita_data_resolvida, visita_compareceu_em
        FROM vendas WHERE comprador_telefone = ? ORDER BY id DESC LIMIT 1
    ");
    $stmt->execute([normalizarTelefone($telefone)]);
    $v = $stmt->fetch();

    $stmt2 = $db->prepare("SELECT COUNT(*) FROM whatsapp_mensagens WHERE telefone = ?");
    $stmt2->execute([normalizarTelefone($telefone)]);
    $totalMsg = (int)$stmt2->fetchColumn();

    echo "── status ──\n";
    echo 'telefone: ' . normalizarTelefone($telefone) . "\n";
    echo 'ia_pausada: ' . (iaPausada($telefone) ? 'sim' : 'não') . "\n";
    echo "mensagens registradas: {$totalMsg}\n";
    if ($v) {
        echo "venda #{$v['id']} — etapa: " . etapaVendaLabel($v['etapa']) . "\n";
        echo 'veículo vinculado: ' . ($v['oportunidade_id'] ? "oportunidade #{$v['oportunidade_id']}" : '(ainda não vinculado)') . "\n";
        echo 'temperatura: ' . ($v['temperatura_lead'] ?: '(ainda não avaliada)') . "\n";
        echo 'confirmou visita: ' . ($v['aceita_visitar_fastcar'] === null ? '(ainda não perguntado)' : ($v['aceita_visitar_fastcar'] ? 'sim' : 'não')) . "\n";
        if ($v['visita_dia_horario_texto']) echo "  dia/horário combinado (texto): {$v['visita_dia_horario_texto']}\n";
        if ($v['visita_data_resolvida']) echo "  data resolvida pela IA: {$v['visita_data_resolvida']}\n";
        echo '  compareceu: ' . ($v['visita_compareceu_em'] ? "sim, em {$v['visita_compareceu_em']}" : 'ainda não') . "\n";
    } else {
        echo "nenhuma negociação aberta ainda pra esse telefone.\n";
    }
    echo "────────────\n";
}

function mostrarHistorico(string $telefone): void {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT h.etapa_anterior, h.etapa_nova, h.observacao, h.created_at
        FROM venda_historico h
        JOIN vendas v ON v.id = h.venda_id
        WHERE v.comprador_telefone = ? ORDER BY h.id
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

function mostrarFrota(): void {
    $frota = listarFrotaDisponivelParaVenda(30);
    if (!$frota) {
        echo "(nenhum veículo disponível pra venda agora — a IA vai dizer isso honestamente ao comprador)\n";
        return;
    }
    foreach ($frota as $f) {
        echo "  #{$f['oportunidade_id']} — {$f['veiculo_marca']} {$f['veiculo_modelo']} {$f['veiculo_ano']}"
           . ($f['valor_referencia'] ? ' — R$ ' . number_format((float)$f['valor_referencia'], 2, ',', '.') : '')
           . "\n";
    }
}

echo "=== Simulador de conversa WhatsApp — Fastcar CRM (VENDAS / comprador de revenda) ===\n";
echo "Telefone simulado: {$telefone} | Nome: {$nome}\n";
echo "Digite mensagens como se fosse o comprador. \"/\" pra comandos (ex: /status, /frota), /sair pra terminar.\n\n";

while (true) {
    echo 'comprador> ';
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

            case '/frota':
                mostrarFrota();
                continue 2;

            case '/status':
                mostrarStatus($telefone);
                continue 2;

            case '/historico':
                mostrarHistorico($telefone);
                continue 2;

            case '/pausar':
                pausarIA($telefone);
                echo "   ⏸️  IA pausada pra {$telefone} — simula vendedor assumindo a conversa.\n";
                continue 2;

            case '/retomar':
                retomarIA($telefone);
                echo "   ▶️  IA retomada pra {$telefone}.\n";
                continue 2;

            case '/vendedor':
                registrarMensagem($telefone, 'out', $arg, novoMessageId(), false, 'text', null);
                echo "   💬 vendedor> {$arg} (registrado como 'out', instância de vendas)\n";
                continue 2;

            case '/grupo':
                mostrarResultado(processarMensagemVendasZapi([
                    'messageId' => novoMessageId(),
                    'phone' => $telefone . '-group',
                    'isGroup' => true,
                    'text' => ['message' => $arg],
                ], instanciaVendasSimulada()));
                continue 2;

            case '/midia':
                $tipo = $arg ?: 'image';
                mostrarResultado(processarMensagemVendasZapi([
                    'messageId' => novoMessageId(),
                    'phone' => $telefone,
                    'senderName' => $nome,
                    $tipo => ['url' => 'https://exemplo.invalido/arquivo'],
                ], instanciaVendasSimulada()));
                continue 2;

            case '/repetir':
                if (!$ultimoMessageId) {
                    echo "   (nenhuma mensagem anterior nesta sessão pra repetir)\n";
                    continue 2;
                }
                mostrarResultado(processarMensagemVendasZapi([
                    'messageId' => $ultimoMessageId,
                    'phone' => $telefone,
                    'senderName' => $nome,
                    'text' => ['message' => '(reenvio simulado, mesmo messageId — testa dedup)'],
                ], instanciaVendasSimulada()));
                continue 2;

            default:
                echo "   comando desconhecido: {$cmd}\n";
                continue 2;
        }
    }

    $id = novoMessageId();
    $ultimoMessageId = $id;
    mostrarResultado(processarMensagemVendasZapi([
        'messageId' => $id,
        'phone' => $telefone,
        'senderName' => $nome,
        'text' => ['message' => $linha],
    ], instanciaVendasSimulada()));
}
