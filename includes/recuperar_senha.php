<?php
/**
 * "Esqueci minha senha" — link de recuperação por e-mail (24/09/2026,
 * "Coloca recuperar a senha e enviar link para e-mail"). Sempre por
 * e-mail, nunca WhatsApp (número pessoal do consultor não é canal de
 * recuperação de conta, diferente do 2FA — includes/login_2fa.php — que
 * já tem WhatsApp cadastrado como 2º fator de um login já em andamento).
 *
 * Mesma disciplina de `autenticar()` (includes/usuarios.php): nunca
 * revela se um e-mail tem conta cadastrada ou não — `admin/esqueci_senha.php`
 * mostra a MESMA mensagem de sucesso genérica em qualquer caso (e-mail
 * existe, não existe, ou está em cooldown). Token de uso único
 * (`usado_em`), hash SHA-256 em repouso (nunca o valor cru — mesma
 * disciplina de senha/código 2FA/dispositivo confiável), expira em 1h.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/usuarios.php';
require_once __DIR__ . '/mail.php';
require_once __DIR__ . '/email_templates.php';
require_once __DIR__ . '/auditoria.php';

const RECUPERAR_SENHA_TTL_SEGUNDOS = 3600; // 1 hora
const RECUPERAR_SENHA_COOLDOWN_SEGUNDOS = 60; // mesmo cooldown do reenvio de código 2FA

/**
 * Sempre best-effort, nunca lança — quem chama (admin/esqueci_senha.php)
 * mostra a mesma mensagem de sucesso não importa o resultado real, pra
 * nunca vazar quais e-mails têm conta cadastrada. E-mail que não bate com
 * nenhuma conta, ou conta bloqueada (usuarios.bloqueado=1), simplesmente
 * não manda nada — silencioso de propósito.
 */
function recuperarSenhaSolicitar(string $email): void {
    try {
        $usuario = buscarUsuarioPorEmail($email);
        if (!$usuario) return;

        // Cooldown por usuário (mesmo padrão `2fa_enviado_{id}` de
        // includes/login_2fa.php) — sem isso, alguém batendo o formulário
        // repetido no e-mail de outra pessoa vira spam de link pra essa
        // pessoa, não pra quem está mandando o formulário.
        $guardKey = 'resetsenha_enviado_' . $usuario['id'];
        $ultimo = getConfig($guardKey);
        if ($ultimo && (time() - strtotime($ultimo)) < RECUPERAR_SENHA_COOLDOWN_SEGUNDOS) return;

        // Gera o token e tenta mandar ANTES de gravar no banco — mesma
        // ordem de login2faEnviarCodigo(): se o e-mail falhar (Gmail não
        // configurado, etc), nunca fica um token válido "órfão" que
        // ninguém recebeu, só pra expirar sozinho depois de 1h à toa.
        $tokenBruto = bin2hex(random_bytes(32));
        $url = appBaseUrl() . '/admin/redefinir_senha.php?token=' . $tokenBruto;
        $enviou = enviarEmail(
            $usuario['email'],
            'Recuperação de senha — Fastcar CRM',
            emailLayout(recuperarSenhaEmailCorpo($usuario['nome'], $url)),
            $usuario['nome']
        ) === true;

        if ($enviou) {
            $expira = date('Y-m-d H:i:s', time() + RECUPERAR_SENHA_TTL_SEGUNDOS);
            getDB()->prepare("
                INSERT INTO usuarios_reset_senha (usuario_id, token_hash, expira_em)
                VALUES (?, ?, ?)
            ")->execute([$usuario['id'], hash('sha256', $tokenBruto), $expira]);
            setConfig($guardKey, date('Y-m-d H:i:s'));
            auditoriaRegistrar('recuperacao_senha_solicitada', (int)$usuario['id'], $usuario['nome'], 'usuario', (int)$usuario['id'], 'Link de recuperação de senha enviado por e-mail.');
        }
    } catch (Throwable $e) {
        // Best-effort — nunca deixa o formulário de "esqueci minha senha" quebrar a tela.
    }
}

function recuperarSenhaEmailCorpo(string $nome, string $url): string {
    $nomeSeguro = e($nome);
    $botao = emailBotao('Definir nova senha', $url);
    return "<p>Olá, {$nomeSeguro}!</p>"
        . "<p>Recebemos um pedido pra redefinir a senha da sua conta no Fastcar CRM. Clique no botão abaixo pra escolher uma senha nova:</p>"
        . $botao
        . "<p>Esse link vale por 1 hora e só funciona uma vez. Se você não pediu essa recuperação, ignore este e-mail — sua senha continua a mesma, ninguém muda nada sem clicar nesse link.</p>";
}

/**
 * @return array{id:int, usuario_id:int, nome:string, email:string}|null
 *   Token válido (existe, não expirou, não foi usado ainda) ou null.
 */
function recuperarSenhaValidarToken(string $tokenBruto): ?array {
    if ($tokenBruto === '') return null;
    $db = getDB();
    $stmt = $db->prepare("
        SELECT r.id, r.usuario_id, r.expira_em, r.usado_em, u.nome, u.email
        FROM usuarios_reset_senha r
        JOIN usuarios u ON u.id = r.usuario_id
        WHERE r.token_hash = ?
    ");
    $stmt->execute([hash('sha256', $tokenBruto)]);
    $linha = $stmt->fetch();
    if (!$linha) return null;
    if ($linha['usado_em'] !== null) return null;
    if (strtotime($linha['expira_em']) < time()) return null;

    return [
        'id' => (int)$linha['id'],
        'usuario_id' => (int)$linha['usuario_id'],
        'nome' => $linha['nome'],
        'email' => $linha['email'],
    ];
}

/** Marca o token como usado (uso único) — chamada só depois da senha nova já ter sido gravada com sucesso. */
function recuperarSenhaConsumirToken(int $tokenId): void {
    getDB()->prepare("UPDATE usuarios_reset_senha SET usado_em = datetime('now','localtime') WHERE id = ?")->execute([$tokenId]);
}
