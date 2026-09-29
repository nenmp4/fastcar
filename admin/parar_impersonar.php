<?php
/**
 * Encerra o impersonamento, restaura a sessão do super_admin original.
 * Fica no allowlist dos 3 perfis siloed (vendedor/financeiro/avaliador,
 * ver admin/_bootstrap.php) — senão o super_admin impersonando um deles
 * nunca conseguiria chegar aqui pra voltar (o guard de página redirecionaria
 * antes de qualquer lógica desta rodar).
 */
require __DIR__ . '/_bootstrap.php';

if (!estaImpersonando()) {
    header('Location: /admin/index.php');
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validateCSRF($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    exit('Sessão expirada, recarregue a página.');
}

$original = impersonandoOriginal();
$alvoId = (int)$_SESSION['admin_id'];
$alvoNome = (string)$_SESSION['admin_nome'];

encerrarImpersonacao();
auditoriaRegistrar('impersonacao_finalizada', $original['id'], $original['nome'], 'usuario', $alvoId, "Voltou a ser super admin (estava como {$alvoNome})");

header('Location: /admin/usuarios.php');
exit;
