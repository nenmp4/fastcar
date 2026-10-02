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

/**
 * Candidatos a "testemunha 2" do contrato (02/10/2026) — qualquer usuário
 * ativo que já preencheu o próprio CPF no perfil (admin/meu_perfil.php) ou
 * teve preenchido pelo super_admin (admin/usuarios.php). Filtra por CPF de
 * propósito: escolher alguém sem CPF só resultaria na mesma linha em
 * branco de sempre no PDF, então nem aparece como opção — select em
 * admin/oportunidade.php/venda.php. $excetoId deixa de fora o próprio
 * responsável da negociação (que já é testemunha 1 automaticamente, nunca
 * faz sentido a mesma pessoa assinar como as duas testemunhas).
 */
function listarUsuariosParaTestemunha(?int $excetoId = null): array {
    $db = getDB();
    $sql = "SELECT id, nome, cpf FROM usuarios WHERE bloqueado = 0 AND TRIM(COALESCE(cpf, '')) != ''";
    $params = [];
    if ($excetoId !== null) {
        $sql .= " AND id != ?";
        $params[] = $excetoId;
    }
    $sql .= " ORDER BY nome";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
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

function criarUsuario(string $nome, string $email, string $senha, string $perfil = 'consultor', string $whatsapp = '', string $cpf = ''): int {
    $db = getDB();
    $db->prepare("INSERT INTO usuarios (nome, email, senha_hash, perfil, whatsapp, cpf) VALUES (?, ?, ?, ?, ?, ?)")
       ->execute([clean($nome), trim(strtolower($email)), password_hash($senha, PASSWORD_DEFAULT), $perfil, clean($whatsapp), clean($cpf)]);
    return (int)$db->lastInsertId();
}

/**
 * Edita um usuário existente — NUNCA mexe em `perfil` pra 'super_admin'
 * nem tira de 'super_admin' por aqui (só o CLI install/create_admin.php
 * cria super_admin; admin/usuarios.php só deixa editar 'consultor', mesma
 * decisão de segurança de não ter tela de "criar admin" no painel).
 */
function atualizarUsuario(int $id, string $nome, string $email, string $whatsapp, string $perfil, bool $bloqueado, string $cpf = ''): void {
    $db = getDB();
    $db->prepare("UPDATE usuarios SET nome = ?, email = ?, whatsapp = ?, perfil = ?, bloqueado = ?, cpf = ? WHERE id = ?")
       ->execute([clean($nome), trim(strtolower($email)), clean($whatsapp), $perfil, $bloqueado ? 1 : 0, clean($cpf), $id]);
}

function redefinirSenhaUsuario(int $id, string $novaSenha): void {
    $db = getDB();
    $db->prepare("UPDATE usuarios SET senha_hash = ? WHERE id = ?")
       ->execute([password_hash($novaSenha, PASSWORD_DEFAULT), $id]);
}

/**
 * Autoedição do próprio perfil (21/09/2026, "Permita os usuários do
 * sistema editar perfis deles trocar número e-mail nome") —
 * `admin/meu_perfil.php`, disponível pra QUALQUER perfil logado. Nunca
 * mexe em `perfil`/`bloqueado` (só `admin/usuarios.php`, restrito ao
 * super_admin, faz isso — mesma trava de sempre, ninguém se auto-promove
 * nem se desbloqueia por aqui). `email` é UNIQUE no schema — checado à
 * mão ANTES do UPDATE pra devolver uma mensagem legível em vez de deixar
 * a constraint do banco estourar como erro cru.
 * CPF (02/10/2026, "preencheria CPF no perfil do usuário para fazer
 * automaticamente na assinatura") — texto livre, sem validação de dígito
 * verificador (mesma disciplina já usada em todo outro campo de CPF do
 * projeto, ex: clientes.cpf, config.testemunha1_cpf de antes); é o que
 * libera esse usuário a virar testemunha de contrato (ver
 * includes/contratos.php::signatariosExtrasContrato()).
 * @return array{ok:bool, erro:?string, campos_alterados:array}
 */
function atualizarPerfilProprio(int $id, string $nome, string $email, string $whatsapp, string $cpf = ''): array {
    $nome = trim($nome);
    $email = trim(strtolower($email));
    $whatsapp = clean($whatsapp);
    $cpf = clean(trim($cpf));

    if ($nome === '') {
        return ['ok' => false, 'erro' => 'Nome não pode ficar vazio.', 'campos_alterados' => []];
    }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'erro' => 'E-mail inválido.', 'campos_alterados' => []];
    }

    $db = getDB();
    $stmt = $db->prepare("SELECT id FROM usuarios WHERE email = ? AND id != ?");
    $stmt->execute([$email, $id]);
    if ($stmt->fetch()) {
        return ['ok' => false, 'erro' => 'Esse e-mail já está em uso por outra conta.', 'campos_alterados' => []];
    }

    $atual = buscarUsuario($id);
    $camposAlterados = [];
    if ($atual) {
        if ($atual['nome'] !== $nome) $camposAlterados[] = 'nome';
        if ($atual['email'] !== $email) $camposAlterados[] = 'email';
        if ($atual['whatsapp'] !== $whatsapp) $camposAlterados[] = 'whatsapp';
        if (($atual['cpf'] ?? '') !== $cpf) $camposAlterados[] = 'cpf';
    }

    $db->prepare("UPDATE usuarios SET nome = ?, email = ?, whatsapp = ?, cpf = ? WHERE id = ?")
       ->execute([clean($nome), $email, $whatsapp, $cpf, $id]);

    return ['ok' => true, 'erro' => null, 'campos_alterados' => $camposAlterados];
}
