<?php
/**
 * Redefine a senha de um usuário existente — CLI, pra quando esquece a
 * senha e não tem outro super_admin logado pra trocar por
 * admin/usuarios.php (ou o próprio usuário é o único super_admin).
 * Mesmo espírito do install/create_admin.php: sem tela web pra isso, de
 * propósito — sem passar por login nenhum, exige acesso SSH à VPS.
 *
 * Uso:
 *   php install/resetar_senha.php email@fastcar.com novaSenha123
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Este script só roda via linha de comando (CLI).\n");
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/usuarios.php';

[$script, $email, $senha] = array_pad($argv, 3, null);

if (!$email || !$senha) {
    fwrite(STDERR, "Uso: php install/resetar_senha.php email@fastcar.com novaSenha123\n");
    exit(1);
}

if (strlen($senha) < 8) {
    fwrite(STDERR, "Senha precisa ter pelo menos 8 caracteres.\n");
    exit(1);
}

$usuario = buscarUsuarioPorEmail($email);
if (!$usuario) {
    fwrite(STDERR, "Nenhum usuário ativo encontrado com esse e-mail.\n");
    exit(1);
}

redefinirSenhaUsuario((int)$usuario['id'], $senha);
echo "Senha redefinida pra {$usuario['nome']} ({$email}, perfil {$usuario['perfil']}).\n";
