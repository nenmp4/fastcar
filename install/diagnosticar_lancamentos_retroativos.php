<?php
/**
 * Diagnóstico só-leitura (nunca apaga/altera nada) — 24/09/2026, dúvida
 * direta do usuário depois de rodar `gerar_lancamentos_fechados_retroativos.php
 * --confirmar`: "vai para 46 veiculos ok? ou 66 total - 20 incompleto pra
 * investigar - 89-66 crm antigo".
 *
 * Reconcilia os números reais: quantas oportunidades vieram da importação
 * do CRM antigo (install/importar_crm_antigo.php, marcadas via
 * config.crm_antigo_importado_clients_{id}), quantas delas ficaram
 * genuinamente 'fechado' (documentos completos) vs voltaram sozinhas pra
 * 'negociacao' (crmAntigoImportarCliente() faz isso via mudarEtapa() quando
 * falta documento obrigatório — nunca fica 'fechado' com pendência), e
 * quantas de TODAS as oportunidades 'fechado' do sistema (não só do CRM
 * antigo) já têm o lançamento retroativo (`fechamento_compra`) gerado.
 *
 * Uso:
 *   php install/diagnosticar_lancamentos_retroativos.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Este script só roda via linha de comando (CLI).\n");
}

require_once __DIR__ . '/../includes/db.php';
$db = getDB();

// --- 1. Quantos clients.csv do CRM antigo já foram marcados como importados
$totalCrmAntigo = (int)$db->query("
    SELECT COUNT(*) FROM config WHERE chave LIKE 'crm_antigo_importado_clients_%'
")->fetchColumn();

// --- 2. Dessas oportunidades (achadas via oportunidade_historico com a
// observação de importação do CRM antigo), quantas estão em cada etapa hoje
$porEtapaCrmAntigo = $db->query("
    SELECT o.etapa, COUNT(*) AS qtd
    FROM oportunidades o
    WHERE o.id IN (
        SELECT DISTINCT oportunidade_id FROM oportunidade_historico
        WHERE observacao LIKE '%Importado do CRM antigo (Yaqar/IACAR)%'
    )
    GROUP BY o.etapa
    ORDER BY qtd DESC
")->fetchAll(PDO::FETCH_KEY_PAIR);

// --- 3. Do total do sistema (qualquer origem) em etapa='fechado':
$totalFechado = (int)$db->query("SELECT COUNT(*) FROM oportunidades WHERE etapa = 'fechado'")->fetchColumn();
$fechadoComValor = (int)$db->query("SELECT COUNT(*) FROM oportunidades WHERE etapa = 'fechado' AND valor_final IS NOT NULL AND valor_final > 0")->fetchColumn();
$fechadoComDespesa = (int)$db->query("
    SELECT COUNT(*) FROM oportunidades o
    WHERE o.etapa = 'fechado'
      AND EXISTS (SELECT 1 FROM fin_lancamentos l WHERE l.oportunidade_id = o.id AND l.origem = 'fechamento_compra')
")->fetchColumn();
$fechadoSemDespesaAinda = (int)$db->query("
    SELECT COUNT(*) FROM oportunidades o
    WHERE o.etapa = 'fechado' AND o.valor_final IS NOT NULL AND o.valor_final > 0
      AND NOT EXISTS (SELECT 1 FROM fin_lancamentos l WHERE l.oportunidade_id = o.id AND l.origem = 'fechamento_compra')
")->fetchColumn();

echo "=== Importação do CRM antigo (clients.csv) ===\n";
echo "Clientes marcados como já importados: {$totalCrmAntigo}\n\n";
echo "Etapa atual das oportunidades vindas do CRM antigo:\n";
foreach ($porEtapaCrmAntigo as $etapa => $qtd) {
    $marca = $etapa === 'fechado' ? ' <- entrou no backfill (documentos completos)' : ' <- voltou/ficou aqui, documento faltando ou outra situação';
    echo "  {$etapa}: {$qtd}{$marca}\n";
}

echo "\n=== Total do SISTEMA (qualquer origem, não só CRM antigo) em etapa='fechado' ===\n";
echo "Total 'fechado': {$totalFechado}\n";
echo "  ...com valor_final > 0 (elegível pro backfill): {$fechadoComValor}\n";
echo "  ...já COM lançamento de despesa (fechamento_compra): {$fechadoComDespesa}\n";
echo "  ...AINDA sem lançamento (backfill precisaria rodar de novo neles): {$fechadoSemDespesaAinda}\n";

if ($fechadoSemDespesaAinda > 0) {
    echo "\n⚠️  Ainda existem {$fechadoSemDespesaAinda} oportunidade(s) 'fechado' sem despesa lançada —\n";
    echo "    rode 'php install/gerar_lancamentos_fechados_retroativos.php' (dry-run) pra ver quais.\n";
}
