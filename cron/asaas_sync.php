<?php
/**
 * cron/asaas_sync.php — mantém o financeiro sincronizado com o Asaas sem
 * precisar de clique manual (18/09/2026, achado real: "tenho que
 * sicornizar assas manual as cobranças de parcela dos carros" — o cron já
 * existia no código desde 17/09/2026, mas nunca tinha sido cadastrado em
 * install/setup_crontab.sh, então nunca rodou sozinho na VPS; e mesmo
 * rodando, só resincronizava STATUS de cobrança JÁ importada — uma
 * cobrança nova criada direto no painel do Asaas continuava exigindo o
 * clique manual em "Importar cobranças" pra aparecer no CRM). Agora faz 2
 * coisas por rodada: (1) `asaasImportarCobrancas()` — mesma função do
 * botão manual (`admin/financeiro-asaas.php`), dedup por
 * `asaas_payment_id`, importa cobrança nova E atualiza status de toda
 * cobrança já existente numa passada só; (2) `asaasSincronizarPendentes()`
 * — fallback do webhook (`api/asaas_webhook.php`) pra cobrança já
 * importada ainda pendente/atrasada, mesmo padrão do zapsign_sync.php,
 * mantido por ser mais barato (reconsulta só quem ainda não fechou,
 * individualmente) pro caso comum de só status mudando, sem cobrança nova.
 *
 * Cron sugerido: a cada 30-60 min, mesma frequência do zapsign_sync.php.
 */

define('ROOT', dirname(__DIR__));
require_once ROOT . '/includes/db.php';
require_once ROOT . '/includes/asaas.php';

function log_asaas_sync(string $msg): void {
    $dir = ROOT . '/storage/logs';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $line = '[' . date('H:i:s') . '] ' . rtrim($msg, "\n") . "\n";
    file_put_contents($dir . '/asaas_sync_' . date('Y-m-d') . '.log', $line, FILE_APPEND);
    echo $line;
}

log_asaas_sync('Iniciando em ' . date('d/m/Y H:i'));

if (!asaasConfigured()) {
    log_asaas_sync('Asaas não configurado, nada a fazer.');
    exit;
}

// 04/11/2026 — clientes primeiro (cliente novo criado no Asaas entra
// no cache), depois cobranças, depois o vínculo automático com a venda.
$rc = asaasImportarClientes();
log_asaas_sync($rc['ok'] ? "Clientes: {$rc['importados']} lido(s)." : 'Erro ao importar clientes: ' . $rc['erro']);

$ri = asaasImportarCobrancas();
if (!$ri['ok']) {
    log_asaas_sync('Erro ao importar cobranças: ' . $ri['erro']);
} else {
    log_asaas_sync("Importação: {$ri['novos']} nova(s), {$ri['atualizados']} atualizada(s).");
}

$rv = asaasVincularClientesAutomatico();
foreach ($rv['vinculados'] as $vv) log_asaas_sync("Vinculado: {$vv['cliente']} → venda #{$vv['venda_id']} (por {$vv['via']}).");
if ($rv['ambiguos']) log_asaas_sync("{$rv['ambiguos']} cliente(s) batem com mais de uma venda — vincular na mão em Financeiro → Asaas.");

$r = asaasSincronizarPendentes(100);
if (!$r['ok']) {
    log_asaas_sync('Erro: ' . $r['erro']);
} else {
    log_asaas_sync("{$r['atualizados']} cobrança(s) resincronizada(s).");
}

// 07/11/2026 — comissão do vendedor sobre a entrada já paga (aguardando aprovação).
require_once ROOT . '/includes/financeiro.php';
finSincronizarComissoesVendas();

log_asaas_sync('Concluído.');
