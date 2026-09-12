<?php
/**
 * GitHub Webhook — Auto Deploy (mesmo padrão do JurídicoSaaS)
 * URL: https://SEU-DOMINIO/api/webhook_deploy.php
 *
 * Só VALIDA a assinatura e agenda um marcador (storage/.deploy) — quem faz
 * o `git pull` de verdade é uma linha de crontab (ver install/setup_crontab.sh),
 * de propósito: nunca roda `git pull` disparado direto por uma requisição
 * HTTP externa, mesmo com assinatura validada — decoupling é defesa em
 * profundidade (um bug na validação não vira execução arbitrária na hora).
 *
 * Configurar no GitHub: Settings → Webhooks → Add webhook
 *   Payload URL: https://SEU-DOMINIO/api/webhook_deploy.php
 *   Content type: application/json
 *   Secret: (mesmo valor de `webhook_secret` em Configurações → Deploy)
 *   Events: Just the push event
 */

define('ROOT', dirname(__DIR__));
require_once ROOT . '/includes/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); exit('Method Not Allowed');
}

$secret  = getConfig('webhook_secret') ?: '';
$payload = file_get_contents('php://input');

$sigHeader = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
if (empty($secret) || empty($sigHeader)) {
    http_response_code(401);
    exit('Unauthorized — configure webhook_secret em Configurações → Deploy.');
}

$expected = 'sha256=' . hash_hmac('sha256', $payload, $secret);
if (!hash_equals($expected, $sigHeader)) {
    http_response_code(403);
    exit('Forbidden — assinatura inválida.');
}

$data   = json_decode($payload, true);
$branch = $data['ref'] ?? '';
if ($branch !== 'refs/heads/main') {
    http_response_code(200);
    exit('Ignorado — não é push na main.');
}

$flagPath = ROOT . '/storage/.deploy';
$info = [
    'pusher'    => $data['pusher']['name'] ?? 'unknown',
    'commits'   => count($data['commits'] ?? []),
    'mensagem'  => $data['head_commit']['message'] ?? '',
    'timestamp' => date('Y-m-d H:i:s'),
];
file_put_contents($flagPath, json_encode($info));

$logDir = ROOT . '/storage/logs';
if (!is_dir($logDir)) mkdir($logDir, 0755, true);
file_put_contents(
    $logDir . '/deploy_' . date('Y-m') . '.log',
    "[" . date('d/m/Y H:i:s') . "] Deploy solicitado por {$info['pusher']} — {$info['commits']} commit(s): {$info['mensagem']}\n",
    FILE_APPEND
);

http_response_code(200);
echo json_encode(['ok' => true, 'msg' => 'Deploy agendado']);
