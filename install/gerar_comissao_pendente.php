<?php
/**
 * Correção pontual (dry-run por padrão, `--confirmar` pra aplicar) — 08/10/2026,
 * achado real via `install/diagnosticar_comissao_nao_lancada.php` em
 * produção: negócios marcados "⚠️ DEVERIA ter gerado comissão (passou em
 * todas as travas) mas não gerou" — venda #1 (Honda Start 160, Pedro
 * Henrique) e #2 (BYD KING GS DM, Cabeça), vendedor Jean Jesus de Souza.
 *
 * Causa raiz, não um bug em finRegistrarComissaoCompraFechada()/
 * finRegistrarComissaoVendaFechada() em si: as duas funções só disparam
 * de dentro de mudarEtapa()/mudarEtapaVenda(), no exato instante da
 * transição pra 'fechado'/'vendido' — se o vendedor/consultor responsável
 * AINDA NÃO tinha colaborador vinculado (fin_colaboradores.usuario_id)
 * NAQUELE momento, a trava bloqueou corretamente e nunca mais dispara
 * sozinha depois, mesmo o vínculo sendo criado em seguida (nada re-chama
 * a função quando o colaborador é vinculado — só a transição de etapa
 * chama). O diagnóstico só vê o estado ATUAL (colaborador já vinculado
 * agora), por isso reporta "passou em todas as travas" mesmo sabendo que
 * na hora real da transição a trava bateu.
 *
 * install/gerar_lancamentos_fechados_retroativos.php /
 * install/gerar_lancamentos_vendas_retroativos.php NÃO cobrem esse caso —
 * o critério de candidata deles é "zero lançamento financeiro nenhum"
 * (pensado pra negócio importado que nunca passou por mudarEtapa()/
 * mudarEtapaVenda() de jeito nenhum, tipo importação de contrato antigo
 * da ZapSign) — uma venda que JÁ tem receita lançada normalmente (e só
 * está faltando a COMISSÃO) nunca aparece como candidata lá, porque ela
 * já tem outros lançamentos.
 *
 * Este script cobre exatamente esse gap: reproduz as MESMAS travas que
 * includes/financeiro.php já checa (regra #3, nunca chuta), pros dois
 * lados — compra (etapa='fechado') e venda (etapa='vendido') — e chama
 * as MESMAS funções de produção (finRegistrarComissaoCompraFechada()/
 * finRegistrarComissaoVendaFechada()) só pra quem passa em TODAS as
 * travas e ainda não tem o lançamento 'comissao_compra'/'comissao_venda'
 * — idempotente por natureza (as próprias funções já checam isso antes
 * de inserir), sempre com a data REAL do fechamento/venda, nunca "hoje".
 *
 * Uso:
 *   php install/gerar_comissao_pendente.php              — só lista (dry-run)
 *   php install/gerar_comissao_pendente.php --confirmar  — aplica de verdade
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Este script só roda via linha de comando (CLI).\n");
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/financeiro.php';

$confirmar = in_array('--confirmar', $argv, true);
$db = getDB();

echo "=== Comissões pendentes — passam em todas as travas, mas ainda não foram lançadas ===\n\n";

// --- COMPRA ---
$compras = $db->query("
    SELECT o.id, o.veiculo_marca, o.veiculo_modelo, o.valor_final, o.valor_fipe_referencia,
           o.fechado_por, o.data_compra, u.nome AS fechado_por_nome
    FROM oportunidades o
    JOIN usuarios u ON u.id = o.fechado_por
    JOIN fin_colaboradores fc ON fc.usuario_id = o.fechado_por AND fc.status = 'ativo'
    WHERE o.etapa = 'fechado'
      AND o.valor_final > 0
      AND o.valor_fipe_referencia > 0
      AND NOT EXISTS (SELECT 1 FROM fin_lancamentos l WHERE l.oportunidade_id = o.id AND l.origem = 'comissao_compra')
    ORDER BY o.data_compra
")->fetchAll(PDO::FETCH_ASSOC);

echo "--- COMPRA (" . count($compras) . " candidata(s)) ---\n";
foreach ($compras as $c) {
    $veiculo = trim(($c['veiculo_marca'] ?? '') . ' ' . ($c['veiculo_modelo'] ?? '')) ?: 'veículo';
    $valorComissao = round((float)$c['valor_fipe_referencia'] * 1.0 / 100, 2);
    printf(
        "  #%d %s (%s) — fechado por %s — geraria R\$ %s (1%% de R\$ %s de FIPE)\n",
        $c['id'], $veiculo, $c['data_compra'], $c['fechado_por_nome'],
        number_format($valorComissao, 2, ',', '.'), number_format((float)$c['valor_fipe_referencia'], 2, ',', '.')
    );
}
if (!$compras) echo "  (nenhuma)\n";
echo "\n";

// --- VENDA ---
$vendas = $db->query("
    SELECT v.id, v.comprador_nome, v.valor_pago_contratacao, v.responsavel_id, v.data_venda,
           u.nome AS responsavel_nome, o.veiculo_marca, o.veiculo_modelo
    FROM vendas v
    JOIN usuarios u ON u.id = v.responsavel_id
    JOIN fin_colaboradores fc ON fc.usuario_id = v.responsavel_id AND fc.status = 'ativo'
    LEFT JOIN oportunidades o ON o.id = v.oportunidade_id
    WHERE v.etapa = 'vendido'
      AND v.valor_pago_contratacao > 0
      AND NOT EXISTS (SELECT 1 FROM fin_lancamentos l WHERE l.venda_id = v.id AND l.origem = 'comissao_venda')
    ORDER BY v.data_venda
")->fetchAll(PDO::FETCH_ASSOC);

echo "--- VENDA (" . count($vendas) . " candidata(s)) ---\n";
foreach ($vendas as $v) {
    $veiculo = trim(($v['veiculo_marca'] ?? '') . ' ' . ($v['veiculo_modelo'] ?? '')) ?: 'veículo';
    $valorComissao = round((float)$v['valor_pago_contratacao'] * 5 / 100, 2);
    printf(
        "  #%d %s pra %s (%s) — vendedor %s — geraria R\$ %s (5%% da entrada de R\$ %s)\n",
        $v['id'], $veiculo, $v['comprador_nome'], $v['data_venda'], $v['responsavel_nome'],
        number_format($valorComissao, 2, ',', '.'), number_format((float)$v['valor_pago_contratacao'], 2, ',', '.')
    );
}
if (!$vendas) echo "  (nenhuma)\n";
echo "\n";

if (!$compras && !$vendas) {
    echo "✅ Nada pendente — nenhuma comissão presa por vínculo de colaborador criado depois da data do negócio.\n";
    exit(0);
}

if (!$confirmar) {
    echo "(dry-run — rode com --confirmar pra gerar de verdade, depois de revisar com o financeiro)\n";
    exit(0);
}

$geradas = 0;
foreach ($compras as $c) {
    $data = $c['data_compra'] ? substr((string)$c['data_compra'], 0, 10) : null;
    finRegistrarComissaoCompraFechada((int)$c['id'], (float)$c['valor_final'], (int)$c['fechado_por'], $data);
    $geradas++;
}
foreach ($vendas as $v) {
    $data = $v['data_venda'] ? substr((string)$v['data_venda'], 0, 10) : null;
    finRegistrarComissaoVendaFechada((int)$v['id'], $data);
    $geradas++;
}

echo "✅ {$geradas} comissão(ões) gerada(s) com a data real do negócio.\n";
