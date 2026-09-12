<?php
/**
 * Seed do primeiro usuário (super_admin) — CLI, uso único por instalação.
 * Não existe tela de "criar admin" no próprio admin de propósito (senão
 * qualquer um com acesso ao painel poderia criar um super_admin sozinho).
 *
 * Uso:
 *   php install/create_admin.php "Nome" email@fastcar.com senha123 [perfil]
 *   perfil padrão: super_admin — outros valores válidos: closer, consultor
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Este script só roda via linha de comando (CLI).\n");
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/usuarios.php';

[$script, $nome, $email, $senha, $perfil] = array_pad($argv, 5, null);
$perfil = $perfil ?? 'super_admin';

if (!$nome || !$email || !$senha) {
    fwrite(STDERR, "Uso: php install/create_admin.php \"Nome\" email@fastcar.com senha123 [super_admin|closer|consultor]\n");
    exit(1);
}

if (!in_array($perfil, ['super_admin', 'closer', 'consultor'], true)) {
    fwrite(STDERR, "Perfil inválido: {$perfil}\n");
    exit(1);
}

if (strlen($senha) < 8) {
    fwrite(STDERR, "Senha precisa ter pelo menos 8 caracteres.\n");
    exit(1);
}

if (buscarUsuarioPorEmail($email)) {
    fwrite(STDERR, "Já existe um usuário ativo com esse e-mail.\n");
    exit(1);
}

$id = criarUsuario($nome, $email, $senha, $perfil);
echo "Usuário #{$id} criado ({$perfil}): {$email}\n";
