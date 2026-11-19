<?php
/**
 * 19/11/2026, "caiu conexão coloca para fazer login no qr code no sistema
 * mesmo" — conectar/reconectar o WhatsApp (Evolution) pelo próprio CRM,
 * sem abrir o Manager. Só super_admin, só POST + CSRF (pedir QR mexe no
 * estado da conexão). Card em Configurações → "📲 Conectar WhatsApp".
 * acao=qrcode → QR/código de pareamento; acao=status → pareado ou não
 * (a tela consulta a cada 4s até conectar).
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../includes/whatsapp_evolution.php';
require_once __DIR__ . '/../includes/auditoria.php';
requireSuperAdmin();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validateCSRF((string)($_POST['csrf_token'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'erro' => 'Requisição inválida — recarregue a página.']);
    exit;
}
liberarSessao();

$canais = [
    'principal'  => 'evolutionCredenciais',
    'vendas'     => 'evolutionCredenciaisVendas',
    'financeiro' => 'evolutionCredenciaisFinanceiro',
    'ouvidoria'  => 'evolutionCredenciaisOuvidoria',
];
$canal = (string)($_POST['canal'] ?? '');
if (!isset($canais[$canal])) { echo json_encode(['ok' => false, 'erro' => 'Canal inválido.']); exit; }
$cred = $canais[$canal]();
if (!$cred[0] || !$cred[1] || !$cred[2]) {
    echo json_encode(['ok' => false, 'erro' => 'Preencha URL, instância e API key desse canal antes de conectar.']);
    exit;
}

if (($_POST['acao'] ?? '') === 'qrcode') {
    $r = evolutionObterQrCode($cred);
    auditoriaRegistrar('evolution_qrcode', (int)($_SESSION['admin_id'] ?? 0) ?: null, (string)($_SESSION['admin_nome'] ?? ''), 'config', null,
        "Pediu QR code do WhatsApp ({$canal})" . ($r['conectado'] ? ' — já estava conectado.' : '.'));
    echo json_encode(['ok' => $r['erro'] === '' || $r['conectado'], 'conectado' => $r['conectado'], 'qr' => $r['qr'], 'codigo' => $r['codigo'], 'erro' => $r['erro']]);
    exit;
}

try {
    $txt = evolutionTestarConexao($cred);
    echo json_encode(['ok' => true, 'conectado' => str_contains($txt, '(pareado)'), 'estado' => $txt]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'conectado' => false, 'erro' => $e->getMessage()]);
}
