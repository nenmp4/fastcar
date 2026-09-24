<?php
/**
 * 2º fator do login do admin — código de 6 dígitos por WhatsApp OU e-mail
 * (20/09/2026, "dois fatores usando código enviado pelo WhatsApp e ou
 * e-mail igual do jurídico Sass", confirmado com o usuário: obrigatório
 * pra TODO login, sem exceção — inclusive super_admin —, e quando o
 * usuário tem os dois canais cadastrados, escolhe qual usar na hora do
 * login em vez de uma ordem fixa).
 *
 * Estado da verificação pendente fica só em $_SESSION (nunca em tabela) —
 * é de curtíssima duração (10min) e por definição não precisa sobreviver
 * a nada além da própria aba/sessão logando; guardar em banco só
 * adicionaria uma tabela e uma rotina de limpeza sem necessidade real.
 * Sessão já é HttpOnly+SameSite=Lax (includes/security.php::startSecureSession()),
 * então o hash do código nunca fica exposto a JS nem trafega fora do
 * cookie de sessão.
 *
 * Nunca guarda o código em texto puro em lugar nenhum — só o hash SHA-256
 * (mesma disciplina de senha, embora aqui sha256 puro já basta: é um
 * segredo de 6 dígitos com vida de 10 minutos, não uma senha de longo
 * prazo que precise de custo computacional alto tipo bcrypt).
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/whatsapp_config.php';
require_once __DIR__ . '/mail.php';
require_once __DIR__ . '/email_templates.php';

const LOGIN_2FA_CODIGO_TTL_SEGUNDOS = 600; // 10 minutos
const LOGIN_2FA_MAX_TENTATIVAS = 5;
const LOGIN_2FA_REENVIO_COOLDOWN_SEGUNDOS = 60;
const LOGIN_2FA_PENDENTE_MAX_IDADE_SEGUNDOS = 900; // 15min — depois disso, considera abandonado e força reiniciar do zero
const LOGIN_2FA_DISPOSITIVO_DIAS = 15; // 20/09/2026, "confiar no dispositivo por 15 dias sem pedir novamente"
const LOGIN_2FA_DISPOSITIVO_COOKIE = 'fastcar_2fa_confiavel';

/**
 * Canais de 2FA disponíveis pro usuário — e-mail sempre (campo obrigatório/
 * único em `usuarios`, nunca fica vazio), WhatsApp só se `usuarios.whatsapp`
 * estiver preenchido. Nunca fica "sem nenhum canal": e-mail é garantia.
 */
function login2faCanaisDisponiveis(array $usuario): array {
    $canais = ['email'];
    if (!empty($usuario['whatsapp'])) $canais[] = 'whatsapp';
    return $canais;
}

function login2faRotuloCanal(string $canal): string {
    return $canal === 'whatsapp' ? 'WhatsApp' : 'e-mail';
}

/**
 * Gera e dispara o código pro canal escolhido, grava o estado pendente em
 * $_SESSION. Dedup de 60s por usuário (`config`, mesmo padrão
 * `alerta_atraso_{id}` de cron/followup.php) — sem isso, alguém que já sabe
 * a senha (ex: senha vazada, mas sem acesso ao WhatsApp/e-mail de verdade)
 * podia ficar mandando login repetido só pra spammar o dono da conta de
 * código em código.
 *
 * @return array{ok:bool, motivo:?string, aguardar_segundos:?int}
 */
function login2faEnviarCodigo(array $usuario, string $canal): array {
    if (!in_array($canal, login2faCanaisDisponiveis($usuario), true)) {
        return ['ok' => false, 'motivo' => 'canal_invalido', 'aguardar_segundos' => null];
    }

    $guardKey = '2fa_enviado_' . $usuario['id'];
    $ultimo = getConfig($guardKey);
    if ($ultimo) {
        $decorrido = time() - strtotime($ultimo);
        if ($decorrido < LOGIN_2FA_REENVIO_COOLDOWN_SEGUNDOS) {
            return ['ok' => false, 'motivo' => 'cooldown', 'aguardar_segundos' => LOGIN_2FA_REENVIO_COOLDOWN_SEGUNDOS - $decorrido];
        }
    }

    $codigo = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);

    if ($canal === 'whatsapp') {
        $enviouOk = zapiEnviarTexto($usuario['whatsapp'], "🔐 *Fastcar CRM* — seu código de verificação é *{$codigo}*.\n\nVálido por 10 minutos. Nunca compartilhe esse código com ninguém, nem com a própria Fastcar.");
    } else {
        $enviouOk = enviarEmail($usuario['email'], 'Seu código de verificação — Fastcar CRM', emailLayout(login2faEmailCorpo($codigo)), $usuario['nome']) === true;
    }

    if (!$enviouOk) {
        return ['ok' => false, 'motivo' => 'falha_envio', 'aguardar_segundos' => null];
    }

    setConfig($guardKey, date('Y-m-d H:i:s'));

    $_SESSION['2fa_pendente'] = [
        'usuario_id' => (int)$usuario['id'],
        'nome' => $usuario['nome'],
        'perfil' => $usuario['perfil'],
        'canal' => $canal,
        'canais_disponiveis' => login2faCanaisDisponiveis($usuario),
        'codigo_hash' => hash('sha256', $codigo),
        'expira_em' => time() + LOGIN_2FA_CODIGO_TTL_SEGUNDOS,
        'tentativas' => 0,
        'criado_em' => time(),
        'aguardando_canal' => false,
    ];

    return ['ok' => true, 'motivo' => null, 'aguardar_segundos' => null];
}

function login2faEmailCorpo(string $codigo): string {
    $codigoEspacado = e($codigo);
    return "<p>Seu código de verificação pra entrar no Fastcar CRM é:</p>"
        . "<p style=\"font-size:32px;font-weight:bold;letter-spacing:8px;color:#151722;text-align:center;margin:24px 0\">{$codigoEspacado}</p>"
        . "<p>Válido por 10 minutos. Se você não tentou entrar agora, ignore este e-mail — sua conta continua segura, ninguém entra sem esse código.</p>";
}

/**
 * Confere o código digitado contra o estado pendente na sessão. Nunca
 * lança — quem chama trata cada caso de `status` (ver admin/login.php).
 *
 * @return array{status:string, usuario_id:?int, nome:?string, perfil:?string}
 *   status: 'ok' | 'invalido' | 'expirado' | 'max_tentativas' | 'sem_pendente'
 */
function login2faVerificarCodigo(string $codigoDigitado): array {
    $pendente = $_SESSION['2fa_pendente'] ?? null;
    if (!$pendente || !empty($pendente['aguardando_canal'])) {
        return ['status' => 'sem_pendente', 'usuario_id' => null, 'nome' => null, 'perfil' => null];
    }

    if (time() > $pendente['expira_em']) {
        unset($_SESSION['2fa_pendente']);
        return ['status' => 'expirado', 'usuario_id' => null, 'nome' => null, 'perfil' => null];
    }

    $codigoDigitado = preg_replace('/\D/', '', $codigoDigitado);
    if ($codigoDigitado !== '' && hash_equals($pendente['codigo_hash'], hash('sha256', $codigoDigitado))) {
        $usuarioId = (int)$pendente['usuario_id'];
        $nome = $pendente['nome'];
        $perfil = $pendente['perfil'];
        unset($_SESSION['2fa_pendente']);
        return ['status' => 'ok', 'usuario_id' => $usuarioId, 'nome' => $nome, 'perfil' => $perfil];
    }

    $_SESSION['2fa_pendente']['tentativas']++;
    if ($_SESSION['2fa_pendente']['tentativas'] >= LOGIN_2FA_MAX_TENTATIVAS) {
        unset($_SESSION['2fa_pendente']);
        return ['status' => 'max_tentativas', 'usuario_id' => null, 'nome' => null, 'perfil' => null];
    }

    return ['status' => 'invalido', 'usuario_id' => null, 'nome' => null, 'perfil' => null];
}

/** true se existe uma verificação pendente ainda válida (não expirada por idade máxima) — usado por admin/login.php pra decidir qual tela desenhar. */
function login2faPendenteValido(): bool {
    $pendente = $_SESSION['2fa_pendente'] ?? null;
    if (!$pendente) return false;
    if ((time() - $pendente['criado_em']) > LOGIN_2FA_PENDENTE_MAX_IDADE_SEGUNDOS) {
        unset($_SESSION['2fa_pendente']);
        return false;
    }
    return true;
}

/**
 * "Confiar neste dispositivo" (20/09/2026, "colocar para confiar no
 * dispositivo por 15 dias sem pedir novamente") — depois de um 2FA
 * concluído com sucesso, grava um token novo (cru só no cookie do
 * navegador, hash em `usuarios_dispositivos_confiaveis`) e devolve o
 * cookie já pronto pra `setcookie()`. Nunca lança — melhor esforço, uma
 * falha aqui não pode impedir o login que já foi validado de verdade.
 *
 * @return array{nome:string, valor:string, expira:int}|null
 */
function login2faGerarTokenDispositivo(int $usuarioId): ?array {
    try {
        $tokenBruto = bin2hex(random_bytes(32));
        $expira = time() + LOGIN_2FA_DISPOSITIVO_DIAS * 86400;
        getDB()->prepare("
            INSERT INTO usuarios_dispositivos_confiaveis (usuario_id, token_hash, expira_em, ultimo_uso_em)
            VALUES (?, ?, ?, datetime('now','localtime'))
        ")->execute([$usuarioId, hash('sha256', $tokenBruto), date('Y-m-d H:i:s', $expira)]);

        return ['nome' => LOGIN_2FA_DISPOSITIVO_COOKIE, 'valor' => $tokenBruto, 'expira' => $expira];
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Confere se o cookie de dispositivo confiável (se veio na request) bate
 * com um token ainda válido PRA ESSE usuário específico — nunca por
 * outro usuário que porventura tenha confiado no mesmo navegador antes
 * (o token é o elo, não o usuário sozinho). Encontrando um token expirado,
 * apaga a linha (limpeza preguiçosa, sem precisar de cron dedicado).
 * Achando válido, estende mais 15 dias (sliding window) — só pede o 2FA
 * de novo se o dispositivo ficar mais de 15 dias sem logar, não 15 dias
 * fixos desde o 1º "confiar".
 *
 * @return array{nome:string, valor:string, expira:int}|null novo cookie
 *   pra renovar a expiração no navegador, ou null se não havia token
 *   válido (nesse caso quem chama segue pro fluxo normal de 2FA).
 */
function login2faVerificarDispositivoConfiavel(int $usuarioId, string $tokenBruto): ?array {
    if ($tokenBruto === '') return null;
    try {
        $db = getDB();
        $hash = hash('sha256', $tokenBruto);
        $stmt = $db->prepare("SELECT id, expira_em FROM usuarios_dispositivos_confiaveis WHERE usuario_id = ? AND token_hash = ?");
        $stmt->execute([$usuarioId, $hash]);
        $linha = $stmt->fetch();
        if (!$linha) return null;

        if (strtotime($linha['expira_em']) < time()) {
            $db->prepare("DELETE FROM usuarios_dispositivos_confiaveis WHERE id = ?")->execute([$linha['id']]);
            return null;
        }

        $novaExpira = time() + LOGIN_2FA_DISPOSITIVO_DIAS * 86400;
        $db->prepare("
            UPDATE usuarios_dispositivos_confiaveis
            SET expira_em = ?, ultimo_uso_em = datetime('now','localtime')
            WHERE id = ?
        ")->execute([date('Y-m-d H:i:s', $novaExpira), $linha['id']]);

        return ['nome' => LOGIN_2FA_DISPOSITIVO_COOKIE, 'valor' => $tokenBruto, 'expira' => $novaExpira];
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * 24/09/2026 — chamada por admin/redefinir_senha.php ao concluir uma
 * recuperação de senha por e-mail: se a senha vazou, um cookie de
 * "dispositivo confiável" que já pulava o 2FA no navegador do atacante
 * não pode continuar valendo depois da troca — defesa em profundidade,
 * nunca deixa a recuperação de senha por si só reabrir a mesma brecha que
 * a troca de senha deveria fechar.
 */
function login2faInvalidarDispositivosConfiaveis(int $usuarioId): void {
    getDB()->prepare("DELETE FROM usuarios_dispositivos_confiaveis WHERE usuario_id = ?")->execute([$usuarioId]);
}
