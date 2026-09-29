<?php
/**
 * cron/meta_insights.php — Custo por Lead (CPL) das campanhas Meta
 * (29/09/2026, spec trazida pelo usuário via Google Docs).
 *
 * Puxa o gasto/impressões/cliques/conversas de cada anúncio (Marketing
 * API, GET /{ad_account}/insights?level=ad) das contas configuradas
 * (config.meta_ad_accounts) e faz UPSERT em anuncio_gasto_diario, por
 * (data, ad_id). Reprocessa HOJE + os últimos 3 dias sempre — a Meta
 * ajusta o número dela retroativamente (atribuição de conversão que chega
 * atrasada), então "só o dia corrente" ficaria com dado desatualizado
 * pros dias anteriores.
 *
 * USO (via crontab, install/setup_crontab.sh, a cada 3h):
 *   php cron/meta_insights.php
 *
 * Dry-run (não grava nada, só imprime — pra conferir antes de rodar de
 * verdade, mesmo padrão de todo script de backfill deste projeto):
 *   php cron/meta_insights.php --dry-run
 *   php cron/meta_insights.php --dry-run --since=2026-09-20 --until=2026-09-25
 *
 * Rate limit (código 17 ou 80004): espera com backoff exponencial e tenta
 * de novo, até 3 tentativas por chamada. Token inválido (código 190):
 * loga e grava config.meta_ads_ultimo_erro (mostrado como aviso no card
 * de Configurações → Meta Marketing API), nunca trava o resto do script —
 * outras contas configuradas continuam sendo processadas mesmo se 1
 * conta falhar.
 */

define('ROOT', dirname(__DIR__));
require_once ROOT . '/includes/db.php';
require_once ROOT . '/includes/meta_ads.php';

ob_implicit_flush(true); // progresso em tempo real, não só no fim (lição já
                          // documentada neste projeto — CLI sem isso fica
                          // minutos em silêncio até terminar)

$dryRun = in_array('--dry-run', $argv, true);
$since = null;
$until = null;
foreach ($argv as $arg) {
    if (preg_match('/^--since=(\d{4}-\d{2}-\d{2})$/', $arg, $m)) $since = $m[1];
    if (preg_match('/^--until=(\d{4}-\d{2}-\d{2})$/', $arg, $m)) $until = $m[1];
}
$desde = $since ?: date('Y-m-d', strtotime('-3 days'));
$ate = $until ?: date('Y-m-d');

function log_meta_insights(string $msg): void {
    $dir = ROOT . '/storage/logs';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $linha = '[' . date('Y-m-d H:i:s') . '] ' . rtrim($msg, "\n") . "\n";
    file_put_contents($dir . '/meta_insights_' . date('Y-m').'.log', $linha, FILE_APPEND);
    echo $linha;
}

if (!metaAdsConfigured()) {
    log_meta_insights('⏭️  Meta Ads não configurado (token/contas) — nada a fazer.');
    exit(0);
}

$contas = metaAdsContas();
log_meta_insights(($dryRun ? '🔎 DRY-RUN — ' : '') . "Buscando insights de " . count($contas) . " conta(s), período {$desde} a {$ate}.");

$totalLinhas = 0;
$totalGasto = 0.0;
$contasComErro = [];

foreach ($contas as $conta) {
    $tentativas = 0;
    $resultado = null;
    while ($tentativas < 3) {
        $tentativas++;
        $resultado = metaAdsBuscarInsights($conta, $desde, $ate);
        if ($resultado['ok']) break;

        $codigo = $resultado['erro_code'];
        if (in_array($codigo, [17, 80004], true) && $tentativas < 3) {
            $espera = 5 * (2 ** ($tentativas - 1)); // backoff: 5s, 10s, 20s
            log_meta_insights("⏳ {$conta}: rate limit (code {$codigo}), tentativa {$tentativas}/3 — esperando {$espera}s...");
            sleep($espera);
            continue;
        }
        break;
    }

    if (!$resultado['ok']) {
        $erro = $resultado['erro'];
        $codigo = $resultado['erro_code'];
        log_meta_insights("❌ {$conta}: falhou — {$erro}" . ($codigo !== null ? " (code {$codigo})" : ''));
        $contasComErro[] = $conta;
        if ($codigo === 190) {
            setConfig('meta_ads_ultimo_erro', date('Y-m-d H:i:s') . ' — token inválido/expirado (code 190): ' . $erro);
        }
        continue; // 1 conta com erro nunca impede as outras de processar
    }

    if (getConfig('meta_ads_ultimo_erro')) {
        setConfig('meta_ads_ultimo_erro', ''); // sucesso de verdade — limpa o alerta anterior
    }

    $linhas = $resultado['linhas'];
    $gastoConta = 0.0;
    foreach ($linhas as $linha) {
        $gastoConta += $linha['spend'];
        if ($dryRun) {
            echo "   {$linha['data']} | {$linha['campaign_name']} > {$linha['adset_name']} > {$linha['ad_name']} "
               . "(ad_id={$linha['ad_id']}) | gasto=R$ " . number_format($linha['spend'], 2, ',', '.')
               . " | conversas_meta={$linha['conversas_meta']}\n";
        } else {
            metaAdsSalvarGastoDiario($conta, $linha);
        }
    }
    $totalLinhas += count($linhas);
    $totalGasto += $gastoConta;
    log_meta_insights("✅ {$conta}: " . count($linhas) . " linha(s), R$ " . number_format($gastoConta, 2, ',', '.') . " de gasto.");
}

echo "\n";
log_meta_insights("Concluído. Total: {$totalLinhas} linha(s), R$ " . number_format($totalGasto, 2, ',', '.') . " de gasto"
    . ($contasComErro ? ', ' . count($contasComErro) . ' conta(s) com erro' : '')
    . ($dryRun ? ' (DRY-RUN — nada foi salvo)' : '') . '.');
