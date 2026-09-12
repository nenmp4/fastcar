<?php
/**
 * Consulta e autenticação de usuários do admin (super_admin, closer,
 * consultor — perfis definidos no schema, pendência #4 do CLAUDE.md até
 * confirmar com o Jean se bate com a equipe real).
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

function criarUsuario(string $nome, string $email, string $senha, string $perfil = 'consultor'): int {
    $db = getDB();
    $db->prepare("INSERT INTO usuarios (nome, email, senha_hash, perfil) VALUES (?, ?, ?, ?)")
       ->execute([clean($nome), trim(strtolower($email)), password_hash($senha, PASSWORD_DEFAULT), $perfil]);
    return (int)$db->lastInsertId();
}
