<?php
// Só roda pelo terminal — nunca pela URL (28/10/2026, chamado via web gerava erro fatal).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * Diagnóstico SÓ LEITURA (25/10/2026, "referência correta na quantidade de
 * carros"): lista despesas automáticas de compra (origem='fechamento_compra')
 * que pertencem a cadastros já mesclados como duplicados (oculto_estoque_motivo
 * 'Cadastro duplicado...'). Essas despesas podem estar contando a mesma compra
 * 2x no financeiro. Nunca altera nada — cancelar é decisão do financeiro, em
 * Financeiro → Lançamentos (botão 🚫 Cancelar, fica registrado na auditoria).
 *
 * Uso: php install/diagnosticar_despesa_compra_duplicada.php
 */
require_once __DIR__ . '/../includes/db.php';
$db = getDB();
$rows = $db->query("
    SELECT fl.id AS lanc_id, fl.valor, fl.status, fl.data_vencimento,
           o.id AS op_id, o.veiculo_placa, o.veiculo_marca, o.veiculo_modelo, o.oculto_estoque_motivo
    FROM fin_lancamentos fl
    JOIN oportunidades o ON o.id = fl.oportunidade_id
    WHERE fl.origem = 'fechamento_compra' AND fl.status != 'cancelado'
      AND COALESCE(o.oculto_estoque_motivo, '') LIKE 'Cadastro duplicado%'
    ORDER BY o.veiculo_placa, o.id
")->fetchAll();
if (!$rows) { echo "✅ Nenhuma despesa de compra ativa em cadastro duplicado.\n"; exit(0); }
$total = 0;
foreach ($rows as $r) {
    $total += (float)$r['valor'];
    printf("Lançamento #%d — R$ %s (%s, %s) — op #%d %s %s placa %s — %s\n",
        $r['lanc_id'], number_format((float)$r['valor'], 2, ',', '.'), $r['status'], $r['data_vencimento'],
        $r['op_id'], $r['veiculo_marca'], $r['veiculo_modelo'], $r['veiculo_placa'], $r['oculto_estoque_motivo']);
}
printf("\n⚠️ %d despesa(s), total R$ %s, possivelmente duplicando compra já lançada no cadastro principal.\n",
    count($rows), number_format($total, 2, ',', '.'));
echo "Confira cada uma e cancele em Financeiro → Lançamentos (🚫 Cancelar) as que forem duplicata de verdade.\n";
