<?php
/**
 * cron/zapsign_sync.php — fallback do webhook (api/zapsign_webhook.php).
 * Reconsulta a ZapSign pra todo contrato ainda em 'enviado' — cobre o caso
 * do webhook não chegar (rede, configuração, etc).
 *
 * Cron sugerido: a cada 5 min (06/10/2026, "30 minutos é muito" — ajustado
 * de 30 pra 5 min; volume de contrato pendente é baixo, sem risco real de
 * sobrecarregar a API da ZapSign nesse ritmo).
 */

define('ROOT', dirname(__DIR__));
require_once ROOT . '/includes/db.php';
require_once ROOT . '/includes/contratos.php';
require_once ROOT . '/includes/veiculo_avaliacoes.php';

function log_zapsign_sync(string $msg): void {
    $dir = ROOT . '/storage/logs';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $line = '[' . date('H:i:s') . '] ' . rtrim($msg, "\n") . "\n";
    file_put_contents($dir . '/zapsign_sync_' . date('Y-m-d') . '.log', $line, FILE_APPEND);
    echo $line;
}

$db = getDB();
log_zapsign_sync('Iniciando em ' . date('d/m/Y H:i'));

$pendentes = $db->query("
    SELECT id FROM contratos WHERE status IN ('enviado', 'visualizado') AND zapsign_doc_token != ''
")->fetchAll();

log_zapsign_sync(count($pendentes) . ' contrato(s) pendente(s) de assinatura.');

foreach ($pendentes as $c) {
    try {
        $antes = $db->prepare("SELECT status FROM contratos WHERE id = ?");
        $antes->execute([$c['id']]);
        $statusAntes = $antes->fetchColumn();

        zapsignSincronizarContrato((int)$c['id']);

        $depois = $db->prepare("SELECT status FROM contratos WHERE id = ?");
        $depois->execute([$c['id']]);
        $statusDepois = $depois->fetchColumn();

        if ($statusAntes !== $statusDepois) {
            log_zapsign_sync("Contrato #{$c['id']}: {$statusAntes} → {$statusDepois}");
        }
    } catch (Throwable $e) {
        log_zapsign_sync("Erro no contrato #{$c['id']}: " . $e->getMessage());
    }
}

// 30/10/2026 (#127): negociação com contrato de venda JÁ assinado mas que
// não virou 'vendido' (ex: marcada "sem perfil" depois do envio) — fecha
// agora. O loop acima só olha contrato ainda pendente, então isso nunca
// seria pego de novo sem esta varredura.
$presas = $db->query("
    SELECT DISTINCT v.id FROM vendas v
    JOIN contratos c ON c.venda_id = v.id AND c.tipo = 'venda' AND c.status = 'assinado'
    WHERE v.etapa NOT IN ('vendido', 'cancelada')
")->fetchAll(PDO::FETCH_COLUMN);
foreach ($presas as $vid) {
    try {
        if (vendaConcluirPorContratoAssinado((int)$vid)) log_zapsign_sync("Venda #{$vid}: contrato já assinado → vendido");
    } catch (Throwable $e) {
        log_zapsign_sync("Erro ao concluir venda #{$vid}: " . $e->getMessage());
    }
}

// Termos de entrega/vistoria (módulo de checklist de avaliação) — mesmo
// fallback, tabela própria, nunca passa por zapsignSincronizarContrato().
$pendentesAval = $db->query("
    SELECT id FROM veiculo_avaliacoes WHERE termo_status IN ('enviado') AND zapsign_doc_token != ''
")->fetchAll();

log_zapsign_sync(count($pendentesAval) . ' termo(s) de vistoria pendente(s) de assinatura.');

foreach ($pendentesAval as $a) {
    try {
        $antes = $db->prepare("SELECT termo_status FROM veiculo_avaliacoes WHERE id = ?");
        $antes->execute([$a['id']]);
        $statusAntes = $antes->fetchColumn();

        sincronizarTermoAvaliacao((int)$a['id']);

        $depois = $db->prepare("SELECT termo_status FROM veiculo_avaliacoes WHERE id = ?");
        $depois->execute([$a['id']]);
        $statusDepois = $depois->fetchColumn();

        if ($statusAntes !== $statusDepois) {
            log_zapsign_sync("Termo de vistoria #{$a['id']}: {$statusAntes} → {$statusDepois}");
        }
    } catch (Throwable $e) {
        log_zapsign_sync("Erro no termo de vistoria #{$a['id']}: " . $e->getMessage());
    }
}

log_zapsign_sync('Concluído.');
