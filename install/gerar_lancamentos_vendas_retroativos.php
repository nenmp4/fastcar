<?php
/**
 * Gera retroativamente a receita (entrada + parcelas) + comissão do
 * vendedor pra negociações de VENDA que já estavam `etapa='vendido'` antes
 * dessas funções existirem, ou que foram importadas de outra fonte
 * (`zapsignImportarContratoVendaComoNegociacaoManual()`,
 * `install/importar_crm_antigo.php` — a Fase 2 de venda, quando plugada)
 * sem nunca passar por `mudarEtapaVenda()` — mesma classe do bug já
 * corrigido do lado de compra (ver
 * install/gerar_lancamentos_fechados_retroativos.php), agora pro lado de
 * venda: 24/09/2026, pedido direto "precisamos agora buscar as receitas
 * dos contratos de vendas".
 *
 * Reaproveita as MESMAS funções de produção (`finGerarReceitaVendaAssinatura()`/
 * `finRegistrarComissaoVendaFechada()`, `includes/financeiro.php`), agora
 * com o parâmetro `$dataVenda` (novo) que faz as duas coisas: usa a data
 * REAL da venda (não "hoje") e, pra receita, pula o Asaas por completo —
 * uma venda de meses atrás nunca pode gerar cobrança real/link de
 * pagamento vivo agora (mesmo raciocínio de nunca recriar automação "isso
 * está acontecendo agora" pra dado histórico, já aplicado do lado de
 * compra). A entrada nasce 'pago' (já foi recebida — sabemos disso porque
 * a venda está `vendido`); as parcelas nascem 'pendente' com o vencimento
 * real calculado a partir da data da venda — o status individual de cada
 * parcela no passado é incerto sem registro de pagamento real (regra #3,
 * nunca chuta), fica pro financeiro marcar manualmente as que já sabe que
 * foram pagas.
 *
 * Idempotente: `finGerarReceitaVendaAssinatura()` já checa
 * `finContarLancamentosVenda()` (QUALQUER lançamento pra aquela venda,
 * inclusive um parcelamento manual já gerado antes) e
 * `finRegistrarComissaoVendaFechada()` checa por `origem='comissao_venda'`
 * — rodar este script 2x, ou depois de uma venda normal já ter gerado
 * tudo via `mudarEtapaVenda()`, nunca duplica nada.
 *
 * "conciliar com veículos" (pedido de acompanhamento, mesma conversa) — o
 * relatório sempre mostra placa/marca/modelo de cada venda, pra dar pra
 * cruzar direto com a coluna "Venda" de `admin/veiculos.php` sem precisar
 * abrir cada negociação uma por uma.
 *
 * Candidatas: `vendas.etapa='vendido'` com `preco_venda>0` e SEM nenhum
 * `fin_lancamentos` ainda (mesmo critério de `finContarLancamentosVenda()`
 * — nunca reaproveita id de venda que já tem QUALQUER lançamento, nem
 * manual nem automático). Não gera receita quando a venda é 100% à vista
 * (`preco_venda - valor_pago_contratacao <= 0`) ou sem `prazo_quitacao_meses`
 * definido — mesma regra de `finGerarReceitaVendaAssinatura()`, fica pro
 * lançamento manual nesses casos.
 *
 * ⚠️ Checagem de duplicidade com Asaas (24/09/2026, achado real do
 * usuário: "parece que receitas está todas no assas") — o critério de
 * "sem lançamento nenhum" olha `fin_lancamentos.venda_id`, mas cobrança
 * importada do Asaas (`asaasImportarCobrancas()`, `includes/asaas.php`)
 * só ganha `venda_id` quando alguém VINCULA manualmente o cliente Asaas à
 * venda (`admin/financeiro-asaas.php`, nunca automático — regra #3); uma
 * venda cujo comprador já tem cobrança real no Asaas mas ainda NUNCA foi
 * vinculada apareceria como "sem lançamento nenhum" pra este script, e
 * gerar a receita local por cima **duplicaria** o valor (uma vez no
 * Asaas, outra vez aqui). Corrigido cruzando telefone/CPF do comprador
 * contra `fin_asaas_clientes` (`venda_id IS NULL`, ainda não vinculado) —
 * candidata com match possível NUNCA é processada automaticamente (nem no
 * `--confirmar`), fica só listada à parte com instrução pra vincular
 * primeiro em Financeiro → Asaas; casamento por telefone/CPF nunca é
 * garantia 100% (poderia ser coincidência), então a decisão de vincular
 * ou não continua sempre humana.
 *
 * Uso:
 *   php install/gerar_lancamentos_vendas_retroativos.php              — só lista (dry-run)
 *   php install/gerar_lancamentos_vendas_retroativos.php --confirmar  — aplica de verdade
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Este script só roda via linha de comando (CLI).\n");
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/financeiro.php';

$confirmar = in_array('--confirmar', $argv, true);
$db = getDB();

$candidatas = $db->query("
    SELECT v.id, v.preco_venda, v.valor_pago_contratacao, v.prazo_quitacao_meses,
           v.data_venda, v.responsavel_id, v.comprador_nome, v.comprador_telefone, v.comprador_cpf,
           o.veiculo_marca, o.veiculo_modelo, o.veiculo_placa,
           fc.id AS colaborador_id
    FROM vendas v
    LEFT JOIN oportunidades o ON o.id = v.oportunidade_id
    LEFT JOIN fin_colaboradores fc ON fc.usuario_id = v.responsavel_id AND fc.status = 'ativo'
    WHERE v.etapa = 'vendido'
      AND v.preco_venda IS NOT NULL AND v.preco_venda > 0
      AND NOT EXISTS (SELECT 1 FROM fin_lancamentos l WHERE l.venda_id = v.id)
    ORDER BY v.data_venda
")->fetchAll(PDO::FETCH_ASSOC);

if (!$candidatas) {
    echo "✅ Nenhuma venda 'vendido' sem lançamento nenhum — nada a fazer.\n";
    exit(0);
}

// Clientes Asaas com cobrança real já importada, mas AINDA sem venda_id
// vinculado — casar por telefone/CPF pra nunca gerar receita local em cima
// de uma cobrança que já existe (duplicidade), ver comentário no topo.
$asaasNaoVinculados = $db->query("
    SELECT fac.id, fac.asaas_id, fac.nome, fac.telefone, fac.cpf_cnpj,
           (SELECT COUNT(*) FROM fin_lancamentos l WHERE l.asaas_customer_id = fac.asaas_id) AS qtd_cobrancas,
           (SELECT COALESCE(SUM(valor), 0) FROM fin_lancamentos l WHERE l.asaas_customer_id = fac.asaas_id) AS total_cobrancas
    FROM fin_asaas_clientes fac
    WHERE fac.venda_id IS NULL
")->fetchAll(PDO::FETCH_ASSOC);
$asaasPorTelefone = [];
$asaasPorCpf = [];
foreach ($asaasNaoVinculados as $a) {
    if ($a['qtd_cobrancas'] < 1) continue; // sem cobrança nenhuma ainda, não é risco de duplicidade
    $tel = normalizarTelefone((string)$a['telefone']);
    if ($tel !== '') $asaasPorTelefone[$tel] = $a;
    $cpf = preg_replace('/\D/', '', (string)$a['cpf_cnpj']);
    if ($cpf !== '') $asaasPorCpf[$cpf] = $a;
}

$seguras = [];
$duplicidade = [];
foreach ($candidatas as $c) {
    $tel = normalizarTelefone((string)($c['comprador_telefone'] ?? ''));
    $cpf = preg_replace('/\D/', '', (string)($c['comprador_cpf'] ?? ''));
    $match = ($tel !== '' && isset($asaasPorTelefone[$tel])) ? $asaasPorTelefone[$tel]
        : (($cpf !== '' && isset($asaasPorCpf[$cpf])) ? $asaasPorCpf[$cpf] : null);
    if ($match) {
        $c['_asaas_match'] = $match;
        $duplicidade[] = $c;
    } else {
        $seguras[] = $c;
    }
}

if ($duplicidade) {
    echo "⚠️  " . count($duplicidade) . " venda(s) com possível receita JÁ existente no Asaas (comprador bate por telefone/CPF com um cliente Asaas que já tem cobrança, mas nunca foi vinculado a essa venda) — NUNCA processadas por este script, pra não duplicar:\n\n";
    foreach ($duplicidade as $c) {
        $veiculo = trim(($c['veiculo_marca'] ?? '') . ' ' . ($c['veiculo_modelo'] ?? '')) ?: 'veículo';
        $m = $c['_asaas_match'];
        printf(
            "  #%d | %s | placa %s | comprador %s\n    → cliente Asaas \"%s\" (id %s) já tem %d cobrança(s) somando R$ %s, sem vínculo com essa venda.\n    → Resolve em Financeiro → Asaas: vincula esse cliente a esta venda (nunca automático aqui).\n",
            $c['id'], $veiculo, $c['veiculo_placa'] ?: '—', $c['comprador_nome'] ?: '—',
            $m['nome'] ?: '—', $m['asaas_id'], (int)$m['qtd_cobrancas'], number_format((float)$m['total_cobrancas'], 2, ',', '.')
        );
    }
    echo "\n";
}

$candidatas = $seguras;
if (!$candidatas) {
    echo "✅ Nenhuma venda sobrando pra gerar retroativo (as únicas pendentes já têm match no Asaas, acima) — nada a fazer aqui, resolve o vínculo primeiro.\n";
    exit(0);
}

echo count($candidatas) . " venda(s) 'vendido' sem nenhum lançamento financeiro e sem match no Asaas:\n\n";
$geraiamReceita = 0;
$geraiamComissao = 0;
foreach ($candidatas as $c) {
    $veiculo = trim(($c['veiculo_marca'] ?? '') . ' ' . ($c['veiculo_modelo'] ?? '')) ?: 'veículo';
    $placa = $c['veiculo_placa'] ?: '—';

    $valorEntrada = (float)($c['valor_pago_contratacao'] ?? 0);
    $restante = (float)$c['preco_venda'] - $valorEntrada;
    $numParcelas = (int)($c['prazo_quitacao_meses'] ?? 0);
    $receitaInfo = '— receita: ';
    if ($restante <= 0) {
        $receitaInfo .= 'não (à vista, sem saldo restante — só a entrada, fica pro lançamento manual)';
    } elseif ($numParcelas < 1) {
        $receitaInfo .= 'não (sem prazo de quitação definido)';
    } else {
        $valorParcela = round($restante / $numParcelas, 2);
        $receitaInfo .= sprintf('SIM, entrada R$ %s (pago) + %d parcela(s) de R$ %s (pendente)',
            number_format($valorEntrada, 2, ',', '.'), $numParcelas, number_format($valorParcela, 2, ',', '.'));
        $geraiamReceita++;
    }

    $comissaoInfo = '— comissão: ';
    if ($valorEntrada <= 0) {
        $comissaoInfo .= 'não (sem valor de entrada)';
    } elseif (empty($c['colaborador_id'])) {
        $comissaoInfo .= 'não (vendedor sem colaborador ativo vinculado)';
    } else {
        $comissaoInfo .= sprintf('SIM, R$ %s (5%% da entrada)', number_format($valorEntrada * 0.05, 2, ',', '.'));
        $geraiamComissao++;
    }

    printf(
        "  #%d | %s | placa %s | comprador %s | vendida em %s\n    %s\n    %s\n",
        $c['id'], $veiculo, $placa, $c['comprador_nome'] ?: '—',
        $c['data_venda'] ?: '—', $receitaInfo, $comissaoInfo
    );
}
echo "\n{$geraiamReceita} gerariam receita, {$geraiamComissao} gerariam comissão também.\n";

if (!$confirmar) {
    echo "\n(dry-run — rode com --confirmar pra gerar de verdade)\n";
    exit(0);
}

$processadas = 0;
foreach ($candidatas as $c) {
    $dataVenda = $c['data_venda'] ? substr((string)$c['data_venda'], 0, 10) : date('Y-m-d');
    finGerarReceitaVendaAssinatura((int)$c['id'], $c['responsavel_id'] ? (int)$c['responsavel_id'] : null, $dataVenda);
    finRegistrarComissaoVendaFechada((int)$c['id'], $dataVenda);
    $processadas++;
}

echo "\n✅ {$processadas} venda(s) processada(s) — receita/comissão geradas onde aplicável.\n";
