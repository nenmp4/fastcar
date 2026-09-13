<?php
/**
 * Consulta e autenticação de usuários do admin (super_admin, consultor —
 * perfis definidos no schema; 'consultor' e o antigo 'closer' foram
 * mesclados em 13/09/2026, mesma pessoa atende e negocia/fecha).
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';

function buscarUsuarioPorEmail(string $email): ?array {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM usuarios WHERE email = ? AND bloqueado = 0");
    $stmt->execute([trim(strtolower($email))]);
    $u = $stmt->fetch();
    return $u ?: null;
}

function buscarUsuario(int $id): ?array {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM usuarios WHERE id = ?");
    $stmt->execute([$id]);
    $u = $stmt->fetch();
    return $u ?: null;
}

function listarUsuarios(bool $apenasAtivos = true): array {
    $db = getDB();
    $sql = "SELECT id, nome, email, perfil, bloqueado FROM usuarios";
    if ($apenasAtivos) $sql .= " WHERE bloqueado = 0";
    $sql .= " ORDER BY nome";
    return $db->query($sql)->fetchAll();
}

/** Confere e-mail/senha; retorna o usuário (sem bloqueio) ou null. */
function autenticar(string $email, string $senha): ?array {
    $u = buscarUsuarioPorEmail($email);
    if (!$u || !password_verify($senha, $u['senha_hash'])) {
        return null;
    }
    return $u;
}

function criarUsuario(string $nome, string $email, string $senha, string $perfil = 'consultor', string $whatsapp = ''): int {
    $db = getDB();
    $db->prepare("INSERT INTO usuarios (nome, email, senha_hash, perfil, whatsapp) VALUES (?, ?, ?, ?, ?)")
       ->execute([clean($nome), trim(strtolower($email)), password_hash($senha, PASSWORD_DEFAULT), $perfil, clean($whatsapp)]);
    return (int)$db->lastInsertId();
}

/**
 * Edita um usuário existente — NUNCA mexe em `perfil` pra 'super_admin'
 * nem tira de 'super_admin' por aqui (só o CLI install/create_admin.php
 * cria super_admin; admin/usuarios.php só deixa editar 'consultor', mesma
 * decisão de segurança de não ter tela de "criar admin" no painel).
 */
function atualizarUsuario(int $id, string $nome, string $email, string $whatsapp, string $perfil, bool $bloqueado): void {
    $db = getDB();
    $db->prepare("UPDATE usuarios SET nome = ?, email = ?, whatsapp = ?, perfil = ?, bloqueado = ? WHERE id = ?")
       ->execute([clean($nome), trim(strtolower($email)), clean($whatsapp), $perfil, $bloqueado ? 1 : 0, $id]);
}

function redefinirSenhaUsuario(int $id, string $novaSenha): void {
    $db = getDB();
    $db->prepare("UPDATE usuarios SET senha_hash = ? WHERE id = ?")
       ->execute([password_hash($novaSenha, PASSWORD_DEFAULT), $id]);
}
