<?php
/**
 * Formulário público (sem login) — cliente sobe CNH, comprovante de
 * endereço e contrato de financiamento, e confirma dados pessoais.
 * Acesso só pelo token na URL (link mandado via WhatsApp) — ver
 * includes/documentos.php::getOuCriarTokenDocumentos().
 */

// Link com token na URL, sem login — nunca pode ser indexado nem cacheado
// por robô (um crawler que seguisse o link exporia o token de um cliente).
header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet', true);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/documentos.php';

startSecureSession();

$token = (string)($_GET['token'] ?? $_POST['token'] ?? '');
$op = buscarOportunidadePorToken($token);

if (!$op) {
    http_response_code(404);
    ?>
    <!doctype html><html lang="pt-br"><head><meta charset="utf-8"><title>Link inválido</title>
    <meta name="viewport" content="width=device-width, initial-scale=1"></head>
    <body style="font-family:sans-serif;text-align:center;padding:60px 20px;color:#333">
        <h2>⚠️ Link inválido ou expirado</h2>
        <p>Fale com seu consultor da Fastcar pra receber um novo link.</p>
    </body></html>
    <?php
    exit;
}

$erro = '';
$sucesso = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        $erro = 'Sessão expirada — atualize a página e tente de novo.';
    } else {
        atualizarDadosPessoaisCliente(
            $op['cliente_id'],
            (string)($_POST['nome'] ?? ''),
            (string)($_POST['cpf'] ?? ''),
            (string)($_POST['endereco'] ?? ''),
            (string)($_POST['rg'] ?? ''),
            (string)($_POST['cnh'] ?? ''),
            (string)($_POST['nacionalidade'] ?? ''),
            (string)($_POST['estado_civil'] ?? ''),
            (string)($_POST['profissao'] ?? '')
        );

        $algumEnviado = false;
        foreach (TIPOS_DOCUMENTOS_CLIENTE as $tipo => $label) {
            if (empty($_FILES[$tipo]) || ($_FILES[$tipo]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $resultado = salvarUploadDocumento($op['oportunidade_id'], $tipo, $_FILES[$tipo], true);
            if ($resultado['ok']) {
                $algumEnviado = true;
            } elseif ($resultado['erro']) {
                $erro .= ($erro ? ' ' : '') . "{$label}: {$resultado['erro']}";
            }
        }

        if (!$erro) {
            $sucesso = $algumEnviado ? 'Dados e documentos enviados! Obrigado.' : 'Dados atualizados.';
        }

        // Recarrega pra refletir o que já foi salvo (dados pessoais + docs).
        $op = buscarOportunidadePorToken($token);
    }
}

$documentos = listarDocumentos($op['oportunidade_id']);
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Envio de documentos — Fastcar</title>
<style>
* { box-sizing: border-box; }
body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif; background: #f4f5f7; color: #1c1e21; margin: 0; }
.wrap { max-width: 480px; margin: 0 auto; padding: 24px 16px 60px; }
h1 { font-size: 20px; }
.card { background: #fff; border-radius: 10px; padding: 18px 20px; margin-bottom: 16px; box-shadow: 0 1px 2px rgba(0,0,0,.06); }
label { display: block; font-size: 13px; color: #555; margin: 14px 0 4px; }
input[type=text] { width: 100%; padding: 10px; border: 1px solid #ccd0d5; border-radius: 6px; font-size: 15px; }
input[type=file] { width: 100%; margin-top: 4px; }
button { width: 100%; margin-top: 20px; padding: 12px; border: none; border-radius: 8px; background: #1a5fb4; color: #fff; font-size: 16px; }
.status-ok { color: #2a7a3b; font-size: 13px; }
.status-pendente { color: #a33; font-size: 13px; }
.alerta-erro { background: #fbe4e1; color: #a33; padding: 10px 14px; border-radius: 6px; margin-bottom: 14px; }
.alerta-sucesso { background: #e3f3e6; color: #2a7a3b; padding: 10px 14px; border-radius: 6px; margin-bottom: 14px; }
</style>
</head>
<body>
<div class="wrap">
    <h1>🚗 Fastcar — Envio de documentos</h1>
    <p>Olá<?= $op['nome'] ? ', ' . e($op['nome']) : '' ?>! Pra continuar a avaliação do seu
       <?= e(trim($op['veiculo_marca'] . ' ' . $op['veiculo_modelo'])) ?: 'veículo' ?>, preencha os dados
       e envie os documentos abaixo.</p>

    <?php if ($erro): ?><div class="alerta-erro"><?= e($erro) ?></div><?php endif; ?>
    <?php if ($sucesso): ?><div class="alerta-sucesso"><?= e($sucesso) ?></div><?php endif; ?>

    <form method="post" enctype="multipart/form-data">
        <?= csrfField() ?>
        <input type="hidden" name="token" value="<?= e($token) ?>">

        <div class="card">
            <strong>Seus dados</strong>
            <label>Nome completo</label>
            <input type="text" name="nome" value="<?= e($op['nome'] ?? '') ?>" required>
            <label>CPF</label>
            <input type="text" name="cpf" value="<?= e($op['cpf'] ?? '') ?>" placeholder="000.000.000-00">
            <label>RG</label>
            <input type="text" name="rg" value="<?= e($op['rg'] ?? '') ?>">
            <label>CNH (se tiver)</label>
            <input type="text" name="cnh" value="<?= e($op['cnh'] ?? '') ?>">
            <label>Nacionalidade</label>
            <input type="text" name="nacionalidade" value="<?= e($op['nacionalidade'] ?: 'Brasileiro(a)') ?>">
            <label>Estado civil</label>
            <input type="text" name="estado_civil" value="<?= e($op['estado_civil'] ?? '') ?>" placeholder="Solteiro(a), casado(a)...">
            <label>Profissão</label>
            <input type="text" name="profissao" value="<?= e($op['profissao'] ?? '') ?>">
            <label>Endereço completo</label>
            <input type="text" name="endereco" value="<?= e($op['endereco'] ?? '') ?>" placeholder="Rua, número, bairro, cidade">
            <label>Telefone (WhatsApp)</label>
            <input type="text" value="<?= e($op['telefone']) ?>" disabled>
        </div>

        <div class="card">
            <strong>Documentos</strong>
            <?php foreach (TIPOS_DOCUMENTOS_CLIENTE as $tipo => $label): ?>
                <label>
                    <?= e($label) ?><br>
                    <?php if (!empty($documentos[$tipo]['arquivo_url']) || !empty($documentos[$tipo]['drive_file_id'])): ?>
                        <span class="status-ok">✅ já enviado — envie de novo abaixo pra substituir</span>
                    <?php else: ?>
                        <span class="status-pendente">⏳ pendente</span>
                    <?php endif; ?>
                </label>
                <input type="file" name="<?= e($tipo) ?>" accept="image/jpeg,image/png,image/webp,application/pdf">
            <?php endforeach; ?>
        </div>

        <button type="submit">Enviar</button>
    </form>
</div>
</body>
</html>
