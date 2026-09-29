<?php
/**
 * Impersonamento — super_admin "entra como" outro usuário (sessão
 * temporária, nunca precisa saber a senha dele). 29/09/2026, "colocar
 * inperviosnamento dos usurios pelo super admin", confirmado via
 * AskUserQuestion: (1) sessão temporária, (2) qualquer perfil exceto
 * outro super_admin, (3) auditoria registra início/fim, sem expiração
 * automática por tempo.
 *
 * requireSuperAdmin() já barra sozinho qualquer tentativa de encadear
 * (impersonar enquanto já impersonando outra pessoa) — nesse estado
 * admin_perfil não é mais 'super_admin', é o do alvo atual.
 */
require __DIR__ . '/_bootstrap.php';
requireSuperAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validateCSRF($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    exit('Sessão expirada, recarregue a página.');
}

$alvoId = (int)($_POST['usuario_id'] ?? 0);
$stmt = getDB()->prepare('SELECT id, nome, perfil, bloqueado FROM usuarios WHERE id = ?');
$stmt->execute([$alvoId]);
$alvo = $stmt->fetch();

if (!$alvo) {
    http_response_code(404);
    exit('Usuário não encontrado.');
}
if ($alvo['perfil'] === 'super_admin') {
    http_response_code(403);
    exit('Não é possível impersonar outro super_admin.');
}
if ((int)$alvo['bloqueado'] === 1) {
    http_response_code(403);
    exit('Esse usuário está bloqueado — não dá pra entrar como ele.');
}

$meuId = (int)$_SESSION['admin_id'];
$meuNome = (string)$_SESSION['admin_nome'];

iniciarImpersonacao($alvo);
auditoriaRegistrar('impersonacao_iniciada', $meuId, $meuNome, 'usuario', (int)$alvo['id'], "Entrou como {$alvo['nome']} ({$alvo['perfil']})");

header('Location: ' . paginaInicialPorPerfil($alvo['perfil']));
exit;
