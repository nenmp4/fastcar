<?php
/**
 * Classifica em lote (heurística local + Gemini quando ela não resolve)
 * TODA oportunidade sem tipo de veículo (`oportunidades.tipo_veiculo IS
 * NULL`, qualquer etapa) — 28/09/2026, "vamos roda api do gemine para ja
 * clasfica todas 130 amis rapido" → 1ª versão foi um botão web
 * (`admin/classificar_tipo_veiculo_ia_ajax.php`), mas "vamos rodar pelo
 * terminal ai pode remover esse botão" reverteu isso: rodando direto no
 * terminal via SSH não tem o limite de timeout do Cloudflare/PHP que uma
 * request HTTP em lotes tinha, então processa tudo numa passada só, sem
 * precisar de paginação/cursor.
 *
 * Tenta primeiro sugerirTipoVeiculo() (heurística local, grátis,
 * instantânea — includes/oportunidades.php, já resolve a maioria dos
 * casos reais vistos em produção, ex: "Onix"/"Crosser") e só cai pro
 * Gemini pros que sobrarem sem sinal reconhecido, economizando chamada.
 * Nunca chuta (regra #3 do CLAUDE.md): o prompt instrui a IA a responder
 * "desconhecido" sem confiança real, e nesse caso (ou sem marca/modelo
 * nenhum, que nem chega a chamar a IA) a linha fica sem tipo, listada no
 * relatório pra revisão manual — nunca marca "outro" só pra preencher.
 *
 * Idempotente — só olha `tipo_veiculo IS NULL`, rodar de novo depois de
 * já ter classificado uma leva nunca reprocessa quem já tem tipo; rodar
 * de novo pega só quem ficou sem sinal na rodada anterior (útil se algum
 * dado for corrigido manualmente no meio do caminho).
 *
 * Uso:
 *   php install/classificar_tipo_veiculo_ia.php             (dry-run)
 *   php install/classificar_tipo_veiculo_ia.php --confirmar (aplica de verdade)
 */

define('ROOT', dirname(__DIR__));
require_once ROOT . '/includes/db.php';
require_once ROOT . '/includes/oportunidades.php';
require_once ROOT . '/includes/gemini.php';

ob_implicit_flush(true); // mesma lição de install/zapsign_conciliar_vendas.php — progresso em tempo real, não só no final

$confirmar = in_array('--confirmar', $argv, true);
$db = getDB();

$candidatas = $db->query("
    SELECT o.id, o.veiculo_marca, o.veiculo_modelo, c.nome AS cliente_nome
    FROM oportunidades o
    JOIN clientes c ON c.id = o.cliente_id
    WHERE o.tipo_veiculo IS NULL
    ORDER BY o.id
")->fetchAll();

$total = count($candidatas);
echo "Encontradas $total oportunidade(s) sem tipo de veículo (qualquer etapa).\n";
echo $confirmar ? "MODO CONFIRMAR — vai aplicar de verdade.\n\n" : "DRY-RUN — nada será salvo (rode com --confirmar pra aplicar).\n\n";

$geminiKey   = getConfig('gemini_api_key') ?: '';
$geminiModel = getConfig('gemini_model') ?: 'gemini-3.5-flash-lite';
if (!$geminiKey) {
    echo "⚠️  Chave Gemini não configurada (Configurações → IA) — só a heurística local vai rodar.\n\n";
}

$promptBase = <<<PROMPT
Você classifica veículos usados no mercado brasileiro em EXATAMENTE uma
destas 4 categorias: carro, moto, caminhao, outro.

Responda SÓ um JSON, nada mais, neste formato: {"tipo": "carro"}
(ou "moto"/"caminhao"/"outro"/"desconhecido").

Use "desconhecido" se o texto não der pra identificar o tipo com confiança
real — NUNCA chute, é melhor admitir que não sabe do que errar.
"outro" é pra veículo que existe mas não é nenhum dos 3 (ex: jet ski,
trator, ônibus, van de passageiro).
Marca/modelo podem vir incompletos, com erro de digitação, ou só um
apelido popular (ex: "biz" é moto Honda Biz, "gol bola" é carro VW Gol).

Marca: %s
Modelo: %s
PROMPT;

$tiposRotulos  = ['carro' => 'Carro', 'moto' => 'Moto', 'caminhao' => 'Caminhão', 'outro' => 'Outro'];
$porHeuristica = 0;
$porGemini     = 0;
$semSinal      = [];
$erros         = [];

foreach ($candidatas as $i => $c) {
    $n      = $i + 1;
    $marca  = trim((string)($c['veiculo_marca'] ?? ''));
    $modelo = trim((string)($c['veiculo_modelo'] ?? ''));
    $rotulo = trim("$marca $modelo") ?: '(sem marca/modelo)';

    $tipo   = sugerirTipoVeiculo($marca, $modelo);
    $origem = 'heurística';

    if ($tipo === null && ($marca !== '' || $modelo !== '') && $geminiKey) {
        $prompt = sprintf($promptBase, $marca !== '' ? $marca : '(não informada)', $modelo !== '' ? $modelo : '(não informado)');
        $resp = geminiCall($prompt, $geminiKey, $geminiModel, 60, 0.1, 20);
        if (is_array($resp)) {
            $erros[] = "#{$c['id']} {$rotulo}: " . ($resp['erro'] ?? 'falha desconhecida');
        } else {
            $texto  = trim(preg_replace('/^```json\s*|```\s*$/m', '', (string)$resp));
            $json   = json_decode($texto, true);
            $tipoIa = strtolower(trim((string)($json['tipo'] ?? '')));
            if (in_array($tipoIa, ['carro', 'moto', 'caminhao', 'outro'], true)) {
                $tipo   = $tipoIa;
                $origem = 'Gemini';
            }
            // 'desconhecido' ou resposta não reconhecida: $tipo continua null, nunca chuta.
        }
    }

    if ($tipo === null) {
        $semSinal[] = "#{$c['id']} {$c['cliente_nome']} — {$rotulo}";
        echo "[$n/$total] #{$c['id']} \"{$rotulo}\" — SEM SINAL, fica manual\n";
        continue;
    }

    echo "[$n/$total] #{$c['id']} \"{$rotulo}\" — {$tiposRotulos[$tipo]} (via {$origem})\n";
    if ($origem === 'heurística') $porHeuristica++; else $porGemini++;

    if ($confirmar) {
        try {
            classificarTipoVeiculo((int)$c['id'], $tipo);
        } catch (Throwable $e) {
            $erros[] = "#{$c['id']} {$rotulo}: {$e->getMessage()}";
        }
    }
}

echo "\n== Resumo ==\n";
echo "Total processado: $total\n";
echo "Classificado por heurística local (grátis): $porHeuristica\n";
echo "Classificado pelo Gemini: $porGemini\n";
echo "Sem sinal confiável (ficou manual): " . count($semSinal) . "\n";
if ($semSinal) {
    echo "\nLeads que ficaram sem sinal (revisar manual na tela):\n";
    foreach ($semSinal as $s) echo "  - $s\n";
}
if ($erros) {
    echo "\nErros:\n";
    foreach ($erros as $e) echo "  - $e\n";
}
if (!$confirmar) {
    echo "\nDry-run — nada foi salvo. Rode com --confirmar pra aplicar de verdade.\n";
}
