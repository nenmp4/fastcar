<?php
/**
 * Login do admin. Não usa admin/_bootstrap.php (que já exige sessão
 * logada) — senão vira loop de redirect pra si mesmo.
 *
 * 20/09/2026, "dois fatores usando código enviado pelo WhatsApp e ou
 * e-mail igual do jurídico Sass — tentativa de login" — 3 passos, sempre
 * nessa ordem, nunca abre sessão de admin de verdade antes do 3º:
 *   1. e-mail + senha (autenticar(), includes/usuarios.php — já cobre
 *      bloqueio automático por tentativa errada repetida)
 *   2. escolher canal do código — só aparece se o usuário tem WhatsApp E
 *      e-mail cadastrados; com só 1 canal disponível, pula direto pro 3
 *   3. digitar o código de 6 dígitos (includes/login_2fa.php)
 * 2FA é OBRIGATÓRIO pra todo mundo, sem exceção — inclusive super_admin
 * (confirmado com o usuário, não é opcional por conta).
 *
 * "Confiar neste dispositivo" (20/09/2026, "colocar para confiar no
 * dispositivo por 15 dias sem pedir novamente") — checkbox pré-marcado na
 * tela do código; quando marcado, grava um cookie (`LOGIN_2FA_DISPOSITIVO_COOKIE`,
 * includes/login_2fa.php) que pula o 2FA nos próximos logins DESSE mesmo
 * usuário nesse navegador por 15 dias (sliding window — cada uso renova).
 * Senha continua sempre exigida; só o código é pulado.
 */

// Mesmo header de admin/_bootstrap.php — este arquivo não passa por lá.
header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet', true);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/usuarios.php';
require_once __DIR__ . '/../includes/login_2fa.php';
require_once __DIR__ . '/../includes/auditoria.php';

startSecureSession();

/** vendedor→vendas, financeiro→financeiro — os dois perfis siloados (ver admin/_bootstrap.php) nunca caem no dashboard do funil de compra. */
function paginaInicialPorPerfil(string $perfil): string {
    return match ($perfil) {
        'vendedor' => '/admin/vendas.php',
        'financeiro' => '/admin/financeiro.php',
        'avaliador' => '/admin/avaliacoes.php',
        default => '/admin/index.php',
    };
}

if (!empty($_SESSION['admin_id'])) {
    header('Location: ' . paginaInicialPorPerfil((string)($_SESSION['admin_perfil'] ?? '')));
    exit;
}

/** Sempre a única saída de sucesso do fluxo — regenera a sessão, abre de verdade, audita e (opcional) renova/grava o cookie de dispositivo confiável. */
function finalizarLoginEExit(int $usuarioId, string $nome, string $perfil, ?array $cookieDispositivo, string $via): void {
    session_regenerate_id(true);
    $_SESSION['admin_id']     = $usuarioId;
    $_SESSION['admin_nome']   = $nome;
    $_SESSION['admin_perfil'] = $perfil;
    auditoriaRegistrar('login', $usuarioId, $nome, 'usuario', $usuarioId, "Via: {$via}");
    if ($cookieDispositivo) {
        setcookie($cookieDispositivo['nome'], $cookieDispositivo['valor'], [
            'expires' => $cookieDispositivo['expira'],
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => (($_SERVER['HTTPS'] ?? '') === 'on'),
        ]);
    }
    header('Location: ' . paginaInicialPorPerfil($perfil));
    exit;
}

$erro = '';
$aviso = '';
$acao = (string)($_POST['acao'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !validateCSRF($_POST['csrf_token'] ?? '')) {
    $erro = 'Sessão expirada, tente novamente.';
    unset($_SESSION['2fa_pendente']);
    $acao = '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $acao === 'login') {
    $email = (string)($_POST['email'] ?? '');
    $senha = (string)($_POST['senha'] ?? '');
    $resultado = autenticar($email, $senha);

    if ($resultado['status'] === 'bloqueado') {
        $minutos = max(1, (int)ceil((strtotime($resultado['bloqueado_ate']) - time()) / 60));
        $erro = "Conta temporariamente bloqueada por muitas tentativas erradas. Tente de novo em {$minutos} min.";
        $u = $resultado['user'];
        auditoriaRegistrar('login_bloqueado', (int)$u['id'], $u['nome'], 'usuario', (int)$u['id'], 'Tentativa de login enquanto a conta estava temporariamente bloqueada.');
    } elseif ($resultado['status'] !== 'ok') {
        $erro = 'E-mail ou senha inválidos.';
        // Só audita quando o e-mail bate com uma conta real — sem isso,
        // um e-mail inexistente geraria linha sem usuário nenhum pra
        // atribuir, e o volume de tentativa aleatória/varredura de e-mail
        // é bem maior que o de senha errada numa conta real.
        if ($resultado['user']) {
            $u = $resultado['user'];
            auditoriaRegistrar('login_falha', (int)$u['id'], $u['nome'], 'usuario', (int)$u['id'], 'Senha incorreta.');
        }
    } else {
        $usuario = $resultado['user'];

        $tokenDispositivo = (string)($_COOKIE[LOGIN_2FA_DISPOSITIVO_COOKIE] ?? '');
        $confiavel = $tokenDispositivo !== '' ? login2faVerificarDispositivoConfiavel((int)$usuario['id'], $tokenDispositivo) : null;
        if ($confiavel) {
            finalizarLoginEExit((int)$usuario['id'], $usuario['nome'], $usuario['perfil'], $confiavel, 'dispositivo confiável (sem pedir código)');
        }

        $canais = login2faCanaisDisponiveis($usuario);
        if (count($canais) > 1) {
            // Mais de 1 canal — deixa escolher, ainda sem mandar nenhum código.
            $_SESSION['2fa_pendente'] = [
                'usuario_id' => (int)$usuario['id'],
                'nome' => $usuario['nome'],
                'perfil' => $usuario['perfil'],
                'canal' => null,
                'canais_disponiveis' => $canais,
                'codigo_hash' => null,
                'expira_em' => null,
                'tentativas' => 0,
                'criado_em' => time(),
                'aguardando_canal' => true,
            ];
        } else {
            $envio = login2faEnviarCodigo($usuario, $canais[0]);
            if ($envio['ok']) {
                $aviso = 'Código enviado por ' . login2faRotuloCanal($canais[0]) . '.';
            } else {
                $erro = 'Não consegui enviar o código de verificação agora. Tente de novo em instantes.';
            }
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $acao === 'escolher_canal' && login2faPendenteValido() && !empty($_SESSION['2fa_pendente']['aguardando_canal'])) {
    $pendente = $_SESSION['2fa_pendente'];
    $canalEscolhido = (string)($_POST['canal'] ?? '');
    if (!in_array($canalEscolhido, $pendente['canais_disponiveis'], true)) {
        $erro = 'Canal inválido.';
    } else {
        $usuario = buscarUsuario((int)$pendente['usuario_id']);
        if (!$usuario) {
            unset($_SESSION['2fa_pendente']);
            $erro = 'Sessão de login expirada, comece de novo.';
        } else {
            $envio = login2faEnviarCodigo($usuario, $canalEscolhido);
            if ($envio['ok']) {
                $aviso = 'Código enviado por ' . login2faRotuloCanal($canalEscolhido) . '.';
            } else {
                $erro = 'Não consegui enviar o código de verificação agora. Tente de novo em instantes.';
            }
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $acao === 'reenviar_codigo' && login2faPendenteValido() && empty($_SESSION['2fa_pendente']['aguardando_canal'])) {
    $pendente = $_SESSION['2fa_pendente'];
    $usuario = buscarUsuario((int)$pendente['usuario_id']);
    if (!$usuario) {
        unset($_SESSION['2fa_pendente']);
        $erro = 'Sessão de login expirada, comece de novo.';
    } else {
        $envio = login2faEnviarCodigo($usuario, $pendente['canal']);
        if ($envio['ok']) {
            $aviso = 'Novo código enviado por ' . login2faRotuloCanal($pendente['canal']) . '.';
        } elseif ($envio['motivo'] === 'cooldown') {
            $erro = 'Aguarde ' . $envio['aguardar_segundos'] . 's antes de pedir um novo código.';
        } else {
            $erro = 'Não consegui reenviar o código agora. Tente de novo em instantes.';
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $acao === 'trocar_canal' && login2faPendenteValido()) {
    $_SESSION['2fa_pendente']['aguardando_canal'] = true;
    $_SESSION['2fa_pendente']['canal'] = null;
    $_SESSION['2fa_pendente']['codigo_hash'] = null;
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $acao === 'verificar_codigo' && login2faPendenteValido()) {
    $codigo = (string)($_POST['codigo'] ?? '');
    $r = login2faVerificarCodigo($codigo);
    if ($r['status'] === 'ok') {
        $cookieDispositivo = !empty($_POST['confiar_dispositivo'])
            ? login2faGerarTokenDispositivo($r['usuario_id'])
            : null;
        finalizarLoginEExit($r['usuario_id'], $r['nome'], $r['perfil'], $cookieDispositivo, 'senha + código de verificação');
    }
    $erro = match ($r['status']) {
        'expirado' => 'Código expirado. Peça um novo.',
        'max_tentativas' => 'Muitas tentativas erradas. Faça login de novo.',
        default => 'Código inválido.',
    };
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $acao === 'cancelar_2fa') {
    unset($_SESSION['2fa_pendente']);
}

$pendente = login2faPendenteValido() ? $_SESSION['2fa_pendente'] : null;
$etapa = $pendente ? (!empty($pendente['aguardando_canal']) ? 'canal' : 'codigo') : 'login';
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Login — Fastcar CRM</title>
<link rel="icon" type="image/png" href="/admin/assets/img/favicon.png">
<link rel="stylesheet" href="/admin/assets/style.css?v=<?= @filemtime(__DIR__ . '/assets/style.css') ?: 1 ?>">
<link rel="stylesheet" href="/admin/assets/mobile.css?v=<?= @filemtime(__DIR__ . '/assets/mobile.css') ?: 1 ?>">
<script src="/admin/assets/mobile.js?v=<?= @filemtime(__DIR__ . '/assets/mobile.js') ?: 1 ?>" defer></script>
</head>
<body class="pagina-login">
<div class="login-box">
    <h2>
        <img class="login-logo" src="/admin/assets/img/icon-192.png" alt="Fastcar" onerror="this.style.display='none'">
        <span class="login-wordmark">
            <span class="login-wordmark-nome">Fast<b>Car</b></span>
            <span class="login-wordmark-sub">Solutions</span>
        </span>
    </h2>
    <?php if ($erro): ?><div class="alerta-erro"><?= e($erro) ?></div><?php endif; ?>
    <?php if ($aviso): ?><div class="alerta-sucesso"><?= e($aviso) ?></div><?php endif; ?>

    <?php if ($etapa === 'login'): ?>
        <form method="post">
            <?= csrfField() ?>
            <input type="hidden" name="acao" value="login">
            <label for="email">E-mail</label>
            <input type="email" id="email" name="email" required autofocus>
            <label for="senha">Senha</label>
            <input type="password" id="senha" name="senha" required>
            <button type="submit">Entrar</button>
        </form>

    <?php elseif ($etapa === 'canal'): ?>
        <p>Olá, <?= e($pendente['nome']) ?>! Por onde você quer receber o código de verificação?</p>
        <form method="post">
            <?= csrfField() ?>
            <input type="hidden" name="acao" value="escolher_canal">
            <?php foreach ($pendente['canais_disponiveis'] as $c): ?>
                <button type="submit" name="canal" value="<?= e($c) ?>" style="margin-bottom:10px">
                    <?= $c === 'whatsapp' ? '💬 WhatsApp' : '✉️ E-mail' ?>
                </button>
            <?php endforeach; ?>
        </form>
        <form method="post" style="margin-top:8px">
            <?= csrfField() ?>
            <input type="hidden" name="acao" value="cancelar_2fa">
            <button type="submit" style="background:none;border:none;color:var(--azul,#2f6fed);cursor:pointer;padding:0;margin-top:0">Cancelar e voltar</button>
        </form>

    <?php elseif ($etapa === 'codigo'): ?>
        <p>Enviamos um código de 6 dígitos por <?= e(login2faRotuloCanal($pendente['canal'])) ?>. Ele vale por 10 minutos.</p>
        <form method="post">
            <?= csrfField() ?>
            <input type="hidden" name="acao" value="verificar_codigo">
            <label for="codigo">Código de verificação</label>
            <input type="text" id="codigo" name="codigo" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required autofocus>
            <label style="display:flex;align-items:center;gap:8px;font-weight:normal;margin-top:12px">
                <input type="checkbox" name="confiar_dispositivo" value="1" checked style="width:auto">
                Confiar neste dispositivo por 15 dias (não pedir código de novo aqui)
            </label>
            <button type="submit">Confirmar</button>
        </form>
        <form method="post" style="margin-top:8px">
            <?= csrfField() ?>
            <input type="hidden" name="acao" value="reenviar_codigo">
            <button type="submit" style="background:none;border:none;color:var(--azul,#2f6fed);cursor:pointer;padding:0;margin-top:0">Reenviar código</button>
        </form>
        <?php if (count($pendente['canais_disponiveis']) > 1): ?>
        <form method="post" style="margin-top:4px">
            <?= csrfField() ?>
            <input type="hidden" name="acao" value="trocar_canal">
            <button type="submit" style="background:none;border:none;color:var(--azul,#2f6fed);cursor:pointer;padding:0;margin-top:0">Usar outro canal</button>
        </form>
        <?php endif; ?>
        <form method="post" style="margin-top:4px">
            <?= csrfField() ?>
            <input type="hidden" name="acao" value="cancelar_2fa">
            <button type="submit" style="background:none;border:none;color:#94a3b8;cursor:pointer;padding:0;margin-top:0">Cancelar e voltar</button>
        </form>
    <?php endif; ?>
</div>
</body>
</html>
