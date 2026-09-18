<?php
/**
 * Categoriza retroativamente as cobranças do Asaas importadas ANTES da
 * categoria padrão existir (18/09/2026, achado real: "isso que puxamos do
 * assas são receitas de parcela dos veiculos temos organizar" — o usuário
 * confirmou "importamos ontem", ou seja, as cobranças já em
 * fin_lancamentos vieram sem categoria nenhuma, porque
 * asaasImportarCobrancas() só passou a aplicar `categoria_id` em INSERT
 * NOVO depois desta mudança — as já existentes ficam pra sempre com
 * categoria_id NULL sem esse backfill).
 *
 * Critério: só toca `fin_lancamentos` com `origem='asaas'` E
 * `categoria_id IS NULL` — nunca sobrescreve uma categoria que o
 * financeiro já tenha escolhido à mão depois da importação (mesma
 * disciplina fill-if-empty do resto do projeto).
 *
 * Uso:
 *   php install/asaas_categorizar_importados.php              — só lista (dry-run)
 *   php install/asaas_categorizar_importados.php --confirmar  — aplica de verdade
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Este script só roda via linha de comando (CLI).\n");
}

require_once __DIR__ . '/../includes/db.php';

$confirmar = in_array('--confirmar', $argv, true);
$db = getDB();

$categoriaId = getConfig('asaas_categoria_padrao_id');
if (!$categoriaId) {
    echo "❌ Nenhuma categoria padrão configurada (Configurações → Asaas). Configure primeiro e rode de novo.\n";
    exit(1);
}

$stmt = $db->prepare("SELECT nome, icone FROM fin_categorias WHERE id = ?");
$stmt->execute([$categoriaId]);
$categoria = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$categoria) {
    echo "❌ config.asaas_categoria_padrao_id aponta pro id {$categoriaId}, que não existe mais em fin_categorias.\n";
    exit(1);
}

$candidatos = $db->query("
    SELECT id, descricao, valor, data_vencimento, asaas_payment_id
    FROM fin_lancamentos
    WHERE origem = 'asaas' AND categoria_id IS NULL
    ORDER BY data_vencimento
")->fetchAll(PDO::FETCH_ASSOC);

if (!$candidatos) {
    echo "✅ Nenhuma cobrança do Asaas sem categoria — nada a fazer.\n";
    exit(0);
}

echo "Categoria padrão: {$categoria['icone']} {$categoria['nome']} (id {$categoriaId})\n";
echo count($candidatos) . " cobrança(s) do Asaas sem categoria:\n\n";
foreach ($candidatos as $c) {
    printf("  #%d | %s | R$ %s | venc. %s | %s\n",
        $c['id'], $c['descricao'], number_format((float)$c['valor'], 2, ',', '.'),
        $c['data_vencimento'], $c['asaas_payment_id']);
}

if (!$confirmar) {
    echo "\n(dry-run — rode com --confirmar pra aplicar a categoria em todas as linhas acima)\n";
    exit(0);
}

$upd = $db->prepare("UPDATE fin_lancamentos SET categoria_id = ?, updated_at = datetime('now','localtime') WHERE id = ?");
$total = 0;
foreach ($candidatos as $c) {
    $upd->execute([$categoriaId, $c['id']]);
    $total++;
}

echo "\n✅ {$total} lançamento(s) categorizado(s) como '{$categoria['nome']}'.\n";
