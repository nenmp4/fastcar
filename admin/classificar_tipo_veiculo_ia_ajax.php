<?php
/**
 * Classifica em lote (heurística local + Gemini quando ela não resolve)
 * oportunidades sem tipo de veículo — 28/09/2026, "vamos roda api do
 * gemine para ja clasfica todas 130 amis rapido" / "colca botão". Botão
 * "🤖 Classificar com IA (Gemini)" em admin/index.php chama este endpoint
 * repetidamente em lotes pequenos (nunca tudo numa request só — ~130
 * chamadas Gemini de uma vez estouraria o timeout do Cloudflare/PHP).
 *
 * Tenta primeiro sugerirTipoVeiculo() (heurística local, grátis, instantânea
 * — já resolveu todos os 8 exemplos reais vistos em produção até agora) e
 * só cai pro Gemini pros que sobrarem sem sinal reconhecido, economizando
 * chamada. Diferente da sugestão em admin/index.php (só destaca o botão,
 * espera clique humano por lead), este endpoint APLICA direto — pedido
 * explícito do usuário. Mesmo assim nunca chuta (regra #3): o prompt
 * instrui a IA a responder "desconhecido" sem confiança real, e nesse caso
 * a linha fica sem tipo, sinalizada pra revisão manual — nunca marca
 * "outro" só pra preencher.
 */

require_once __DIR__ . '/_bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'erro' => 'Método não permitido.']);
    exit;
}
if (!validateCSRF($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'erro' => 'Sessão expirada, recarregue a página.']);
    exit;
}

$perfil = $_SESSION['admin_perfil'] ?? '';
if ($perfil === 'supervisor') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'erro' => 'Perfil de supervisão só acompanha, não altera oportunidades.']);
    exit;
}
if (!in_array($perfil, ['super_admin', 'consultor'], true)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'erro' => 'Acesso restrito ao funil de compra.']);
    exit;
}

$db = getDB();
$meuId = (int)($_SESSION['admin_id'] ?? 0);
$souDono = $perfil === 'consultor';

// Lote pequeno — cada chamada Gemini leva ~1-3s, 8 por request mantém bem
// dentro do timeout do PHP/Cloudflare mesmo se todas precisarem da IA.
$loteTamanho = 8;

$whereBase = "WHERE o.tipo_veiculo IS NULL";
$paramsBase = [];
if ($souDono) {
    // Consultor só classifica lead da própria carteira — mesma trava de
    // $souDono já usada em toda a página do dashboard.
    $whereBase .= " AND o.responsavel_id = ?";
    $paramsBase[] = $meuId;
}

$stmt = $db->prepare("
    SELECT o.id, o.veiculo_marca, o.veiculo_modelo, c.nome AS cliente_nome
    FROM oportunidades o
    JOIN clientes c ON c.id = o.cliente_id
    {$whereBase}
    ORDER BY o.id
    LIMIT {$loteTamanho}
");
$stmt->execute($paramsBase);
$lote = $stmt->fetchAll();

$geminiKey   = getConfig('gemini_api_key') ?: '';
$geminiModel = getConfig('gemini_model') ?: 'gemini-3.5-flash-lite';

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

$tiposVeiculoRotulos = ['carro' => 'Carro', 'moto' => 'Moto', 'caminhao' => 'Caminhão', 'outro' => 'Outro'];
$aplicados = [];
$semSinal  = [];
$erros     = [];

foreach ($lote as $c) {
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
            $texto = trim(preg_replace('/^```json\s*|```\s*$/m', '', (string)$resp));
            $json  = json_decode($texto, true);
            $tipoIa = strtolower(trim((string)($json['tipo'] ?? '')));
            if (in_array($tipoIa, ['carro', 'moto', 'caminhao', 'outro'], true)) {
                $tipo   = $tipoIa;
                $origem = 'Gemini';
            }
            // 'desconhecido' ou resposta não reconhecida: $tipo continua null, nunca chuta.
        }
    }

    if ($tipo !== null) {
        try {
            classificarTipoVeiculo((int)$c['id'], $tipo);
            $aplicados[] = ['id' => (int)$c['id'], 'rotulo' => $rotulo, 'tipo' => $tiposVeiculoRotulos[$tipo], 'origem' => $origem];
        } catch (Throwable $e) {
            $erros[] = "#{$c['id']} {$rotulo}: {$e->getMessage()}";
        }
    } else {
        $semSinal[] = ['id' => (int)$c['id'], 'rotulo' => $rotulo, 'cliente' => (string)($c['cliente_nome'] ?? '')];
    }
}

$stmtRestantes = $db->prepare("SELECT COUNT(*) FROM oportunidades o {$whereBase}");
$stmtRestantes->execute($paramsBase);
$restantes = (int)$stmtRestantes->fetchColumn();

echo json_encode([
    'ok'               => true,
    'processados'      => count($lote),
    'aplicados'        => $aplicados,
    'sem_sinal'        => $semSinal,
    'erros'            => $erros,
    'restantes'        => $restantes,
    'sem_chave_gemini' => !$geminiKey,
]);
