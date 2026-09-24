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
 * 24/09/2026, 2ª rodada da mesma dúvida — o usuário mandou print de
 * `admin/veiculos.php` mostrando "20 veículo(s) importado(s) do CRM antigo
 * com documento incompleto estão escondidos desta listagem" (46 visíveis +
 * 20 escondidos = 66, batendo com "Fechadas (66)" do dashboard). Achado
 * revisando o código: esse "esconder da Frota" é um filtro visual PRÓPRIO
 * de `admin/veiculos.php` ($condIncompletoImportAntigo), TOTALMENTE
 * separado do backfill financeiro — o script de lançamento retroativo
 * (`gerar_lancamentos_fechados_retroativos.php`) nunca olha documento
 * nenhum, só `etapa='fechado' AND valor_final > 0`. Então o backfill
 * cobre os 66 (ou quantos desses tiverem valor_final), não só os 46
 * visíveis na Frota — os 20 "incompletos" (documento faltando) já
 * receberam despesa/comissão igual aos outros, se tinham valor_final.
 * Ganhou o mesmo `$condIncompletoImportAntigo` de `admin/veiculos.php`
 * pra cruzar com a despesa e confirmar isso na hora.
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

// --- 4. Cruza com o MESMO filtro de "incompleto" que admin/veiculos.php usa
// pra esconder da Frota — confirma se o backfill já tocou os 20 escondidos.
$condIncompletoImportAntigo = "(
    EXISTS (SELECT 1 FROM oportunidade_historico oh WHERE oh.oportunidade_id = o.id AND oh.observacao LIKE 'Importado do CRM antigo%')
    AND (
        (SELECT COUNT(*) FROM oportunidade_documentos od WHERE od.oportunidade_id = o.id AND od.obrigatorio = 1) = 0
        OR (SELECT COUNT(*) FROM oportunidade_documentos od WHERE od.oportunidade_id = o.id AND od.obrigatorio = 1
                AND ((od.arquivo_url IS NOT NULL AND od.arquivo_url != '') OR (od.drive_file_id IS NOT NULL AND od.drive_file_id != '')))
           < (SELECT COUNT(*) FROM oportunidade_documentos od WHERE od.oportunidade_id = o.id AND od.obrigatorio = 1)
    )
)";

$totalIncompletos = (int)$db->query("SELECT COUNT(*) FROM oportunidades o WHERE o.etapa = 'fechado' AND {$condIncompletoImportAntigo}")->fetchColumn();
$incompletosComValor = (int)$db->query("SELECT COUNT(*) FROM oportunidades o WHERE o.etapa = 'fechado' AND {$condIncompletoImportAntigo} AND o.valor_final IS NOT NULL AND o.valor_final > 0")->fetchColumn();
$incompletosComDespesa = (int)$db->query("
    SELECT COUNT(*) FROM oportunidades o
    WHERE o.etapa = 'fechado' AND {$condIncompletoImportAntigo}
      AND EXISTS (SELECT 1 FROM fin_lancamentos l WHERE l.oportunidade_id = o.id AND l.origem = 'fechamento_compra')
")->fetchColumn();
$incompletosComValorSemDespesa = (int)$db->query("
    SELECT COUNT(*) FROM oportunidades o
    WHERE o.etapa = 'fechado' AND {$condIncompletoImportAntigo}
      AND o.valor_final IS NOT NULL AND o.valor_final > 0
      AND NOT EXISTS (SELECT 1 FROM fin_lancamentos l WHERE l.oportunidade_id = o.id AND l.origem = 'fechamento_compra')
")->fetchColumn();
$completosVisiveisFrota = $totalFechado - $totalIncompletos;

echo "\n=== 'Incompletos' (mesmo filtro que admin/veiculos.php usa pra esconder da Frota) ===\n";
echo "Total 'fechado' COM documento incompleto (escondidos da Frota): {$totalIncompletos}\n";
echo "Total 'fechado' SEM esse problema (aparecem na Frota, os '46'): {$completosVisiveisFrota}\n";
echo "  dos incompletos, com valor_final > 0 (elegíveis pro backfill mesmo assim): {$incompletosComValor}\n";
echo "  dos incompletos, JÁ com despesa lançada (backfill já pegou, documento nunca importou pra isso): {$incompletosComDespesa}\n";
echo "  dos incompletos, com valor mas AINDA sem despesa: {$incompletosComValorSemDespesa}\n";

echo "\n=== Resposta direta ===\n";
echo "O backfill (gerar_lancamentos_fechados_retroativos.php) NUNCA filtra por documento —\n";
echo "só etapa='fechado'+valor_final>0. Ele cobre os {$totalFechado} fechados no total\n";
echo "(inclusive os {$totalIncompletos} escondidos da Frota por documento faltando), não só\n";
echo "os {$completosVisiveisFrota} visíveis. \"Documento incompleto\" e \"despesa lançada\" são\n";
echo "coisas independentes — um pode ter documento faltando e já ter sido pago de verdade.\n";
