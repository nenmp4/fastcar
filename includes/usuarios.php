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

// 20/09/2026, "tentativa de login" — bloqueio AUTOMÁTICO e temporário por
// senha errada repetida, distinto de `usuarios.bloqueado` (manual/
// permanente, só o super_admin liga em admin/usuarios.php).
const LOGIN_MAX_TENTATIVAS = 5;
const LOGIN_BLOQUEIO_MINUTOS = 15;

/**
 * Confere e-mail/senha (só a senha — o 2º fator é conferido à parte, ver
 * includes/login_2fa.php). Nunca autentica sozinho: mesmo com status='ok',
 * quem chama (admin/login.php) ainda precisa do código de verificação
 * antes de abrir sessão de verdade.
 *
 * @return array{status:string, user:?array, bloqueado_ate:?string}
 *   status: 'ok' | 'senha_invalida' | 'bloqueado'.
 *   'senha_invalida' cobre tanto e-mail inexistente quanto senha errada —
 *   nunca revela qual dos dois, mesma mensagem genérica de sempre, pra
 *   não vazar quais e-mails têm conta cadastrada.
 */
function autenticar(string $email, string $senha): array {
    $u = buscarUsuarioPorEmail($email);
    if (!$u) {
        return ['status' => 'senha_invalida', 'user' => null, 'bloqueado_ate' => null];
    }

    if (!empty($u['bloqueado_ate']) && strtotime($u['bloqueado_ate']) > time()) {
        // 'user' preenchido aqui de propósito (diferente do caso "e-mail não
        // existe" acima) — nunca é mostrado ao cliente, só usado pra
        // auditoria server-side (admin/login.php); e-mail já bateu com uma
        // conta real, não há nada a mais sendo revelado.
        return ['status' => 'bloqueado', 'user' => $u, 'bloqueado_ate' => $u['bloqueado_ate']];
    }

    if (!password_verify($senha, $u['senha_hash'])) {
        registrarTentativaLoginFalha((int)$u['id']);
        return ['status' => 'senha_invalida', 'user' => $u, 'bloqueado_ate' => null];
    }

    resetarTentativasLogin((int)$u['id']);
    return ['status' => 'ok', 'user' => $u, 'bloqueado_ate' => null];
}

/** Soma 1 na senha errada; ao bater LOGIN_MAX_TENTATIVAS seguidas, bloqueia por LOGIN_BLOQUEIO_MINUTOS. */
function registrarTentativaLoginFalha(int $usuarioId): void {
    $db = getDB();
    $db->prepare("UPDATE usuarios SET tentativas_falhas = tentativas_falhas + 1 WHERE id = ?")->execute([$usuarioId]);

    $stmt = $db->prepare("SELECT tentativas_falhas FROM usuarios WHERE id = ?");
    $stmt->execute([$usuarioId]);
    $tentativas = (int)$stmt->fetchColumn();

    if ($tentativas >= LOGIN_MAX_TENTATIVAS) {
        $ate = date('Y-m-d H:i:s', time() + LOGIN_BLOQUEIO_MINUTOS * 60);
        $db->prepare("UPDATE usuarios SET bloqueado_ate = ? WHERE id = ?")->execute([$ate, $usuarioId]);
    }
}

/** Senha certa zera o contador de tentativas erradas e qualquer bloqueio automático em andamento. */
function resetarTentativasLogin(int $usuarioId): void {
    getDB()->prepare("UPDATE usuarios SET tentativas_falhas = 0, bloqueado_ate = NULL WHERE id = ?")->execute([$usuarioId]);
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
