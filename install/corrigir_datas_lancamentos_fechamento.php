<?php
/**
 * Corrige a data de lançamentos `fechamento_compra`/`comissao_compra` já
 * gravados com a data ERRADA — 24/09/2026, achado real: rodar
 * `install/gerar_lancamentos_fechados_retroativos.php --confirmar` antes
 * da correção em `includes/financeiro.php` (`$dataCompra` opcional, ver
 * bullet no CLAUDE.md) gravou ~41 despesas retroativas com
 * `data_vencimento`/`data_pagamento` = "hoje" (o dia em que o script
 * rodou), em vez da data real de cada fechamento
 * (`oportunidades.data_compra`) — dezenas de negócios de meses atrás
 * (julho/agosto/setembro) todos somados de uma vez no mês corrente,
 * distorcendo "Despesas do mês"/"Saldo do mês" no dashboard financeiro
 * (usuário: "vamos organizar isso").
 *
 * Sempre corrige pra `oportunidades.data_compra` — nunca chuta uma data,
 * usa a mesma fonte que `mudarEtapa()` já grava como "quando o negócio
 * fechou de verdade" (regra #3). Idempotente/seguro rodar em QUALQUER
 * lançamento `fechamento_compra`/`comissao_compra`, não só os do backfill:
 * um lançamento gerado pelo caminho normal (`mudarEtapa()`) já nasce com
 * `data_vencimento`/`data_pagamento` == `data_compra` (as duas colunas
 * são gravadas na mesma transação, no mesmo instante), então rodar isso
 * nele é um no-op — só os desalinhados de verdade são tocados.
 *
 * Uso:
 *   php install/corrigir_datas_lancamentos_fechamento.php              — só lista (dry-run)
 *   php install/corrigir_datas_lancamentos_fechamento.php --confirmar  — aplica de verdade
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Este script só roda via linha de comando (CLI).\n");
}

require_once __DIR__ . '/../includes/db.php';

$confirmar = in_array('--confirmar', $argv, true);
$db = getDB();

$candidatos = $db->query("
    SELECT l.id, l.origem, l.descricao, l.data_vencimento AS data_atual, o.data_compra
    FROM fin_lancamentos l
    JOIN oportunidades o ON o.id = l.oportunidade_id
    WHERE l.origem IN ('fechamento_compra', 'comissao_compra')
      AND o.data_compra IS NOT NULL
      AND (l.data_vencimento != o.data_compra OR l.data_pagamento != o.data_compra)
    ORDER BY o.data_compra
")->fetchAll(PDO::FETCH_ASSOC);

if (!$candidatos) {
    echo "✅ Nenhum lançamento de fechamento_compra/comissao_compra com data desalinhada — nada a fazer.\n";
    exit(0);
}

echo count($candidatos) . " lançamento(s) com data errada (vão ser corrigidos pra data_compra real da oportunidade):\n\n";
foreach ($candidatos as $c) {
    printf(
        "  #%d [%s] %s | de %s → %s\n",
        $c['id'], $c['origem'], $c['descricao'], $c['data_atual'], $c['data_compra']
    );
}

if (!$confirmar) {
    echo "\n(dry-run — rode com --confirmar pra aplicar de verdade)\n";
    exit(0);
}

$stmt = $db->prepare("
    UPDATE fin_lancamentos
    SET data_vencimento = (SELECT data_compra FROM oportunidades WHERE id = fin_lancamentos.oportunidade_id),
        data_pagamento  = (SELECT data_compra FROM oportunidades WHERE id = fin_lancamentos.oportunidade_id),
        updated_at = datetime('now','localtime')
    WHERE id = ?
");
$corrigidos = 0;
foreach ($candidatos as $c) {
    $stmt->execute([$c['id']]);
    $corrigidos++;
}

echo "\n✅ {$corrigidos} lançamento(s) corrigido(s) — datas agora refletem a data real do fechamento.\n";
