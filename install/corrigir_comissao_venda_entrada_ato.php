<?php
/**
 * Recalcula a comissão de VENDA já lançada pela regra nova (04/11/2026):
 * 5% só da entrada recebida NO ATO (finEntradaNoAtoVenda()), nunca da
 * entrada total — parte da entrada parcelada pra depois não conta.
 *
 *   php install/corrigir_comissao_venda_entrada_ato.php             (só mostra)
 *   php install/corrigir_comissao_venda_entrada_ato.php --confirmar (aplica)
 *
 * Só mexe em fin_lancamentos origem='comissao_venda' não cancelado. Se o
 * vendedor já recebeu o valor antigo, corrigir aqui não devolve dinheiro —
 * acerto fica com o financeiro.
 */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/financeiro.php';

$confirmar = in_array('--confirmar', $argv ?? [], true);
$db = getDB();
$rows = $db->query("
    SELECT l.id, l.venda_id, l.valor, l.status, l.descricao, v.data_venda, v.comprador_nome, v.valor_pago_contratacao
    FROM fin_lancamentos l JOIN vendas v ON v.id = l.venda_id
    WHERE l.origem = 'comissao_venda' AND l.status != 'cancelado'
    ORDER BY l.venda_id
")->fetchAll(PDO::FETCH_ASSOC);

$upd = $db->prepare("UPDATE fin_lancamentos SET valor = ?, descricao = ? WHERE id = ?");
$n = 0;
foreach ($rows as $r) {
    $entradaAto = finEntradaNoAtoVenda((int)$r['venda_id'], $r['data_venda'] ?: null);
    $novo = round($entradaAto * 5 / 100, 2);
    if (abs($novo - (float)$r['valor']) < 0.01) continue;
    $n++;
    printf("  venda #%d (%s) — entrada total R$ %s, no ato R$ %s — comissão R$ %s → R$ %s%s\n",
        $r['venda_id'], $r['comprador_nome'] ?: '—',
        number_format((float)$r['valor_pago_contratacao'], 2, ',', '.'), number_format($entradaAto, 2, ',', '.'),
        number_format((float)$r['valor'], 2, ',', '.'), number_format($novo, 2, ',', '.'),
        $r['status'] === 'pago' ? '  (já marcada paga — confira com o financeiro)' : '');
    if ($confirmar) {
        $desc = preg_replace('/5% da entrada( recebida no ato)? \(R\$ [\d.,]+\)/u', '5% da entrada recebida no ato (R$ ' . number_format($entradaAto, 2, ',', '.') . ')', (string)$r['descricao']);
        $upd->execute([$novo, $desc, $r['id']]);
    }
}
echo $n ? ($confirmar ? "\n{$n} comissão(ões) corrigida(s).\n" : "\n{$n} pra corrigir. Rode com --confirmar pra aplicar.\n") : "Nada pra corrigir — todas já seguem a regra da entrada no ato.\n";
