<?php
/**
 * Diagnóstico pontual (só leitura, nunca apaga/altera nada) — 08/10/2026,
 * pedido direto: "as comissões do consultores parece que não está sendo
 * lanaçada" — checar por que a comissão automática não apareceu.
 *
 * Esse ambiente de dev não tem acesso ao banco de produção (só a VPS
 * tem) — rodar este script via SSH direto na VPS:
 *
 *   php install/diagnosticar_comissao_nao_lancada.php [dias]
 *
 * Sem argumento, olha os últimos 30 dias. Cobre os dois lados —
 * finRegistrarComissaoCompraFechada() (includes/financeiro.php) e
 * finRegistrarComissaoVendaFechada() — checando, pra cada negócio
 * fechado/vendido no período, EXATAMENTE as mesmas 3 travas que essas
 * funções já checam antes de gerar (regra #3, nunca chuta):
 *
 * COMPRA (etapa='fechado'):
 *   1. valor_final > 0 (sem isso a função nem tenta)
 *   2. valor_fipe_referencia > 0 — nem toda compra passa pela busca de
 *      FIPE; sem isso a função desiste sem gerar nada
 *   3. fechado_por tem um colaborador ATIVO em fin_colaboradores
 *      vinculado por usuario_id — essa é a trava mais fácil de cair sem
 *      perceber: um colaborador cadastrado pelo card "➕ Novo colaborador
 *      (manual)" NUNCA tem usuario_id preenchido (só o botão "🔗
 *      Adicionar a partir de um usuário do sistema" preenche), então um
 *      consultor com nome digitado à mão em Colaboradores nunca vai
 *      gerar comissão automática, pra sempre, mesmo fechando negócio
 *      todo santo dia — sem erro nenhum, silencioso, exatamente o
 *      sintoma relatado.
 *   4. já existe lançamento 'comissao_compra' pra essa oportunidade
 *      (idempotência — "já foi gerada" não é bug, é esperado)
 *
 * VENDA (etapa='vendido'):
 *   1. valor_pago_contratacao > 0 (a "entrada" — comissão é 5% dela,
 *      nunca das parcelas do saldo)
 *   2. responsavel_id (vendedor) tem colaborador ATIVO vinculado por
 *      usuario_id — mesma trava do item 3 acima
 *   3. já existe lançamento 'comissao_venda' pra essa venda
 *
 * Pra cada negócio sem comissão, imprime o motivo EXATO (qual das
 * travas bateu) — nunca um "não gerou" genérico.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Este script só roda via linha de comando (CLI).\n");
}

require_once __DIR__ . '/../includes/db.php';
$db = getDB();

$dias = isset($argv[1]) ? max(1, (int)$argv[1]) : 30;
$desde = date('Y-m-d', strtotime("-{$dias} days"));

echo "=== Diagnóstico de comissão automática — últimos {$dias} dia(s) (desde {$desde}) ===\n\n";

// Primeiro: colaboradores SEM usuario_id — a causa mais provável e mais
// fácil de corrigir, listada antes de qualquer outra coisa.
echo "--- Colaboradores cadastrados (origem) ---\n";
$colabs = $db->query("SELECT id, nome, usuario_id, status FROM fin_colaboradores ORDER BY (status='ativo') DESC, nome")->fetchAll(PDO::FETCH_ASSOC);
if (!$colabs) {
    echo "  NENHUM colaborador cadastrado — toda comissão automática vai falhar por falta de colaborador pra lançar.\n";
}
foreach ($colabs as $c) {
    $origem = $c['usuario_id'] ? "🔗 vinculado a usuario_id={$c['usuario_id']}" : "✍️ MANUAL — sem usuario_id, NUNCA gera comissão automática pra ele";
    $st = $c['status'] === 'ativo' ? '✅ ativo' : '⛔ inativo';
    echo "  #{$c['id']}  {$c['nome']}  [{$st}]  {$origem}\n";
    if ($c['status'] !== 'ativo') {
        echo "      (também nunca gera comissão automática enquanto estiver inativo)\n";
    }
}
echo "\n";

echo "--- COMPRA (oportunidades etapa='fechado' no período) ---\n";
$stmt = $db->prepare("
    SELECT o.id, o.veiculo_marca, o.veiculo_modelo, o.valor_final, o.valor_fipe_referencia,
           o.fechado_por, o.data_compra, u.nome AS fechado_por_nome
    FROM oportunidades o
    LEFT JOIN usuarios u ON u.id = o.fechado_por
    WHERE o.etapa = 'fechado' AND o.data_compra >= ?
    ORDER BY o.data_compra DESC
");
$stmt->execute([$desde]);
$compras = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$compras) {
    echo "  Nenhuma compra fechada nesse período.\n";
}

$totalCompras = 0;
$totalCompraOk = 0;
foreach ($compras as $c) {
    $totalCompras++;
    $veiculo = trim(($c['veiculo_marca'] ?? '') . ' ' . ($c['veiculo_modelo'] ?? '')) ?: 'veículo';
    $rotulo = "#{$c['id']} {$veiculo} ({$c['data_compra']}) — fechado por " . ($c['fechado_por_nome'] ?: '(sem responsável)');

    $stmtExiste = $db->prepare("SELECT valor FROM fin_lancamentos WHERE oportunidade_id = ? AND origem = 'comissao_compra'");
    $stmtExiste->execute([$c['id']]);
    $jaGerada = $stmtExiste->fetchColumn();
    if ($jaGerada !== false) {
        $totalCompraOk++;
        echo "  ✅ {$rotulo} — comissão já lançada (R$ " . number_format((float)$jaGerada, 2, ',', '.') . ")\n";
        continue;
    }

    $motivos = [];
    if ((float)($c['valor_final'] ?? 0) <= 0) $motivos[] = "valor_final vazio/zero";
    if ((float)($c['valor_fipe_referencia'] ?? 0) <= 0) $motivos[] = "valor_fipe_referencia vazio (nunca passou pela busca de FIPE)";
    if (!$c['fechado_por']) {
        $motivos[] = "sem responsável (fechado_por vazio)";
    } else {
        $stmtColab = $db->prepare("SELECT status FROM fin_colaboradores WHERE usuario_id = ?");
        $stmtColab->execute([$c['fechado_por']]);
        $statusColab = $stmtColab->fetchColumn();
        if ($statusColab === false) {
            $motivos[] = "{$c['fechado_por_nome']} (usuario_id={$c['fechado_por']}) NÃO tem colaborador cadastrado vinculado — provável causa";
        } elseif ($statusColab !== 'ativo') {
            $motivos[] = "{$c['fechado_por_nome']} tem colaborador cadastrado mas está INATIVO";
        }
    }

    if (!$motivos) {
        echo "  ⚠️  {$rotulo} — DEVERIA ter gerado comissão (passou em todas as travas) mas não gerou — investigar função direto.\n";
    } else {
        echo "  ❌ {$rotulo} — sem comissão porque: " . implode('; ', $motivos) . "\n";
    }
}
echo "\n  Resumo compra: {$totalCompraOk}/{$totalCompras} já têm comissão lançada.\n\n";

echo "--- VENDA (vendas etapa='vendido' no período) ---\n";
$stmtV = $db->prepare("
    SELECT v.id, v.comprador_nome, v.valor_pago_contratacao, v.responsavel_id, v.data_venda,
           u.nome AS responsavel_nome, o.veiculo_marca, o.veiculo_modelo
    FROM vendas v
    LEFT JOIN usuarios u ON u.id = v.responsavel_id
    LEFT JOIN oportunidades o ON o.id = v.oportunidade_id
    WHERE v.etapa = 'vendido' AND v.data_venda >= ?
    ORDER BY v.data_venda DESC
");
$stmtV->execute([$desde]);
$vendas = $stmtV->fetchAll(PDO::FETCH_ASSOC);

if (!$vendas) {
    echo "  Nenhuma venda concluída nesse período.\n";
}

$totalVendas = 0;
$totalVendaOk = 0;
foreach ($vendas as $v) {
    $totalVendas++;
    $veiculo = trim(($v['veiculo_marca'] ?? '') . ' ' . ($v['veiculo_modelo'] ?? '')) ?: 'veículo';
    $rotulo = "#{$v['id']} {$veiculo} pra {$v['comprador_nome']} ({$v['data_venda']}) — vendedor " . ($v['responsavel_nome'] ?: '(sem responsável)');

    $stmtExiste = $db->prepare("SELECT valor FROM fin_lancamentos WHERE venda_id = ? AND origem = 'comissao_venda'");
    $stmtExiste->execute([$v['id']]);
    $jaGerada = $stmtExiste->fetchColumn();
    if ($jaGerada !== false) {
        $totalVendaOk++;
        echo "  ✅ {$rotulo} — comissão já lançada (R$ " . number_format((float)$jaGerada, 2, ',', '.') . ")\n";
        continue;
    }

    $motivos = [];
    if ((float)($v['valor_pago_contratacao'] ?? 0) <= 0) $motivos[] = "valor_pago_contratacao (entrada) vazio/zero — comissão é 5% da entrada, nunca das parcelas";
    if (!$v['responsavel_id']) {
        $motivos[] = "sem vendedor responsável";
    } else {
        $stmtColab = $db->prepare("SELECT status FROM fin_colaboradores WHERE usuario_id = ?");
        $stmtColab->execute([$v['responsavel_id']]);
        $statusColab = $stmtColab->fetchColumn();
        if ($statusColab === false) {
            $motivos[] = "{$v['responsavel_nome']} (usuario_id={$v['responsavel_id']}) NÃO tem colaborador cadastrado vinculado — provável causa";
        } elseif ($statusColab !== 'ativo') {
            $motivos[] = "{$v['responsavel_nome']} tem colaborador cadastrado mas está INATIVO";
        }
    }

    if (!$motivos) {
        echo "  ⚠️  {$rotulo} — DEVERIA ter gerado comissão (passou em todas as travas) mas não gerou — investigar função direto.\n";
    } else {
        echo "  ❌ {$rotulo} — sem comissão porque: " . implode('; ', $motivos) . "\n";
    }
}
echo "\n  Resumo venda: {$totalVendaOk}/{$totalVendas} já têm comissão lançada.\n\n";

echo "=== Fim do diagnóstico ===\n";
echo "Se o motivo foi \"NÃO tem colaborador cadastrado vinculado\": vá em\n";
echo "Financeiro → Colaboradores → \"🔗 Adicionar a partir de um usuário do\n";
echo "sistema\" e selecione o consultor/vendedor — isso NUNCA gera retroativo\n";
echo "sozinho, só vale pra negócio fechado DEPOIS do vínculo existir. Pra\n";
echo "corrigir negócio já fechado sem comissão, usar\n";
echo "install/gerar_lancamentos_fechados_retroativos.php (compra) ou\n";
echo "install/gerar_lancamentos_vendas_retroativos.php (venda), depois de\n";
echo "criar o vínculo do colaborador.\n";
