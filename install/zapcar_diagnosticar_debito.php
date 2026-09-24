<?php
/**
 * Diagnóstico pontual (só leitura, nunca apaga/altera nada) — 24/09/2026,
 * achado real: oportunidade #349 (Yamaha Crosser Z ABS 2024, placa
 * RYZ9D29) mostrou "LICENCIAMENTO: R$ 14.937,00" no resumo da Consulta
 * Veicular ZapCar — usuário suspeitou do valor ("eu acho que valor tá
 * puxando errado deve ser 1,493,7" / "só suspeito ultimo foi 2025" — o
 * último licenciamento consta 2025, incoerente com um débito de
 * licenciamento tão alto).
 *
 * `includes/zapcar.php::zapcarResumoTexto()`/`zapcarAplicarNaOportunidade()`
 * sempre dividem `valor_centavos` por 100 — de forma consistente entre o
 * item individual e o total (`debitos_total_centavos`), então não é um
 * bug de formatação/exibição: o valor exibido (R$14.937,00) só sai assim
 * se o próprio `valor_centavos` que a API da ZapCar devolveu já veio como
 * 1.493.700 (não 149.370, que resultaria em R$1.493,70 — o valor que o
 * usuário suspeita ser o certo). Este sandbox de dev não tem acesso à
 * produção (banco real fica só na VPS) pra confirmar isso direto — este
 * script serve pra rodar na VPS via SSH e mostrar o JSON CRU que a API
 * devolveu (`veiculo_json` — já normalizado por nós/pela ZapCar — e
 * `dados_json` — espelho cru da fonte, sem nenhuma normalização nossa,
 * salvos tal e qual em `zapcar_consultas` desde a consulta), pra separar
 * 3 hipóteses:
 *   (a) a fonte oficial (DETRAN) reporta mesmo um débito grande sob o
 *       tipo "licenciamento" (ex: acumulado de anos anteriores, mesmo com
 *       o ÚLTIMO licenciamento em dia) — não seria bug nenhum daqui;
 *   (b) a própria ZapCar normalizou errado (campo `dados` cru mostra um
 *       valor 10x menor que o `veiculo.debitos[].valor_centavos`
 *       normalizado) — bug do provedor, não do nosso código;
 *   (c) alguma outra coisa (campo duplicado, unidade diferente) —
 *       aparece olhando o `dados_json` bruto.
 * Comparar com o Portal do Cliente da ZapCar (`https://portal...`, mesma
 * placa) é o jeito mais rápido de confirmar contra a fonte, sem precisar
 * nem rodar este script.
 *
 * Uso:
 *   php install/zapcar_diagnosticar_debito.php               # última consulta, oportunidade #349
 *   php install/zapcar_diagnosticar_debito.php --oportunidade=349
 *   php install/zapcar_diagnosticar_debito.php --placa=RYZ9D29
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Este script só roda via linha de comando (CLI).\n");
}

require_once __DIR__ . '/../includes/db.php';
$db = getDB();

$oportunidadeId = null;
$placa = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--oportunidade=')) $oportunidadeId = (int)substr($arg, 15);
    if (str_starts_with($arg, '--placa=')) $placa = strtoupper(trim(substr($arg, 8)));
}
if (!$oportunidadeId && !$placa) $oportunidadeId = 349;

$sql = "SELECT * FROM zapcar_consultas WHERE status = 'concluido' ";
$params = [];
if ($oportunidadeId) {
    $sql .= "AND oportunidade_id = ? ";
    $params[] = $oportunidadeId;
}
if ($placa) {
    $sql .= "AND placa = ? ";
    $params[] = $placa;
}
$sql .= "ORDER BY concluido_em DESC LIMIT 1";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    echo "Nenhuma consulta concluída encontrada pra esse filtro.\n";
    exit(1);
}

echo "=== zapcar_consultas #{$row['id']} — oportunidade #{$row['oportunidade_id']} — placa {$row['placa']} ===\n";
echo "Serviço: {$row['servico']} | Concluída em: {$row['concluido_em']} | Valor cobrado: R$ " . number_format((float)$row['valor_cobrado'], 2, ',', '.') . "\n\n";

$veiculo = $row['veiculo_json'] ? json_decode($row['veiculo_json'], true) : null;
$dados = $row['dados_json'] ? json_decode($row['dados_json'], true) : null;

echo "--- veiculo (normalizado, é o que a aplicação usa pra exibir/aplicar) ---\n";
if ($veiculo && isset($veiculo['debitos']) && is_array($veiculo['debitos'])) {
    foreach ($veiculo['debitos'] as $d) {
        $centavos = $d['valor_centavos'] ?? null;
        $reais = $centavos !== null ? number_format(((float)$centavos) / 100, 2, ',', '.') : '—';
        echo "  tipo={$d['tipo']}  valor_centavos=" . var_export($centavos, true) . "  (= R$ {$reais})  valor_informado=" . var_export($d['valor_informado'] ?? null, true) . "\n";
    }
    echo "  debitos_total_centavos=" . var_export($veiculo['debitos_total_centavos'] ?? null, true) . "\n";
} else {
    echo "  (sem bloco 'debitos' no veiculo normalizado)\n";
}
echo "  ultimo_licenciamento=" . var_export($veiculo['ultimo_licenciamento'] ?? null, true) . "\n\n";

echo "--- dados (espelho CRU da fonte, nunca tocado pelo nosso código) ---\n";
if ($dados) {
    echo json_encode($dados, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";
} else {
    echo "  (dados_json vazio — API não mandou o espelho cru, ou a consulta é antiga de antes desse campo existir)\n\n";
}

echo "--- Diagnóstico ---\n";
if ($veiculo && isset($veiculo['debitos_total_centavos'])) {
    $somaItens = 0;
    foreach (($veiculo['debitos'] ?? []) as $d) {
        $somaItens += (float)($d['valor_centavos'] ?? 0);
    }
    $totalBanco = (float)$veiculo['debitos_total_centavos'];
    echo "Soma dos itens = {$somaItens} centavos | Total gravado = {$totalBanco} centavos";
    echo ($somaItens == $totalBanco) ? " — batem (consistente).\n" : " — DIVERGEM! Total não é a soma dos itens.\n";
}
echo "\nSe o 'dados' cru acima mostrar um valor ~10x MENOR pro mesmo débito, o problema é a\n";
echo "normalização feita pela própria ZapCar (fora do nosso controle, reportar pra eles).\n";
echo "Se o 'dados' cru já mostrar o valor grande também, é a fonte oficial (DETRAN) reportando\n";
echo "assim mesmo — vale conferir contra o Portal do Cliente ZapCar pra essa mesma placa.\n";
