<?php
/**
 * Log de auditoria — versão enxuta (20/09/2026, "temos ter modulo
 * auditoria igual do jutidicosass"). Só consulta, nunca edita/apaga nada
 * daqui — o log em si não tem UI de gerenciamento, só de leitura/filtro.
 * Restrito ao super_admin, mesma trava de admin/usuarios.php/saude.php.
 */

require_once __DIR__ . '/_bootstrap.php';
requireSuperAdmin();

$sucesso = '';
$erro = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'salvar_alerta_documentos') {
    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        $erro = 'Sessão expirada, recarregue a página e tente de novo.';
    } else {
        $lim = trim((string)($_POST['limite'] ?? ''));
        if (!ctype_digit($lim) || (int)$lim > 500) {
            $erro = 'Limite inválido — use um número de 0 a 500 (0 desliga o alerta).';
        } else {
            setConfig('alerta_documentos_limite_hora', (string)(int)$lim);
            setConfig('alerta_documentos_whatsapp', trim(clean((string)($_POST['whatsapp'] ?? ''))));
            $sucesso = 'Alerta de documentos salvo.';
        }
    }
}

$evento = (string)($_GET['evento'] ?? '');
$q = trim((string)($_GET['q'] ?? ''));

$eventos = auditoriaEventosDistintos();
$linhas = auditoriaListar(200, $evento, $q);
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Auditoria — Fastcar CRM</title>
<link rel="stylesheet" href="/admin/assets/style.css?v=<?= @filemtime(__DIR__ . '/assets/style.css') ?: 1 ?>">
<link rel="stylesheet" href="/admin/assets/mobile.css?v=<?= @filemtime(__DIR__ . '/assets/mobile.css') ?: 1 ?>">
<script src="/admin/assets/mobile.js?v=<?= @filemtime(__DIR__ . '/assets/mobile.js') ?: 1 ?>" defer></script>
<?php include __DIR__ . '/_pwa_head.php'; ?>
</head>
<body>
<?php include __DIR__ . '/_impersonando_banner.php'; ?>
<?php include __DIR__ . '/_topbar.php'; ?>

<main>
<?php if ($erro): ?><div class="alerta-erro"><?= e($erro) ?></div><?php endif; ?>
<?php if ($sucesso): ?><div class="alerta-sucesso"><?= e($sucesso) ?></div><?php endif; ?>
<div class="card">
    <h2>🕵️ Auditoria</h2>
    <p><small>Registro dos eventos mais sensíveis do sistema: login/logout, bloqueio de conta por tentativa
       errada, mudança de perfil/senha/bloqueio de usuário, exclusão de conversa do WhatsApp e edição de
       dado sensível de cliente (CPF/e-mail/endereço). Mostra os 200 mais recentes que batem com o filtro.</small></p>
    <form method="get" class="grid-2">
        <div>
            <label>Evento</label>
            <select name="evento">
                <option value="">— Todos —</option>
                <option value="__ouvidoria" <?= $evento === '__ouvidoria' ? 'selected' : '' ?>>📣 Ouvidoria (tudo: protocolos, respostas, status)</option>
                <?php foreach ($eventos as $ev): ?>
                    <option value="<?= e($ev) ?>" <?= $evento === $ev ? 'selected' : '' ?>><?= e(auditoriaRotuloEvento($ev)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label>Buscar (usuário, detalhe ou IP)</label>
            <input type="text" name="q" value="<?= e($q) ?>" placeholder="Ex: nome do usuário, telefone, IP...">
        </div>
        <div style="grid-column:1/-1">
            <button type="submit">Filtrar</button>
            <?php if ($evento !== '' || $q !== ''): ?><a href="/admin/auditoria.php">Limpar filtro</a><?php endif; ?>
        </div>
    </form>
</div>

<div class="card">
    <h3>🚨 Alerta de muitos documentos abertos</h3>
    <p><small>Se a mesma pessoa abrir (ou tentar abrir, fora da carteira) mais documentos de clientes que o limite
       dentro de 1 hora, sai um aviso no WhatsApp e no sino dos super admins — 1 aviso por pessoa por hora.
       Documentos contam: CNH/comprovantes, contratos, comprovante financeiro, consulta ZapCar, termos de vistoria
       e backup. Consultor só abre documento da própria carteira; vendedor, da própria venda.</small></p>
    <form method="post" class="grid-2">
        <?= csrfField() ?>
        <input type="hidden" name="acao" value="salvar_alerta_documentos">
        <div>
            <label>Limite por hora (0 desliga)</label>
            <input type="number" inputmode="numeric" name="limite" min="0" max="500" value="<?= (int)auditoriaLimiteAlertaDocumentos() ?>">
        </div>
        <div>
            <label>WhatsApp que recebe (vazio = números de notificação)</label>
            <input type="text" name="whatsapp" value="<?= e((string)(getConfig('alerta_documentos_whatsapp') ?: '')) ?>" placeholder="Ex: 11999999999, 11988888888">
        </div>
        <div style="grid-column:1/-1"><button type="submit">Salvar alerta</button></div>
    </form>
</div>

<div class="card">
    <h3>📜 Eventos (<?= count($linhas) ?><?= count($linhas) === 200 ? '+' : '' ?>)</h3>
    <?php if (!$linhas): ?>
        <p>Nenhum evento registrado ainda<?= ($evento !== '' || $q !== '') ? ' com esse filtro' : '' ?>.</p>
    <?php else: ?>
    <table class="tabela-oportunidades">
        <thead><tr><th>Quando</th><th>Evento</th><th>Usuário</th><th>Alvo</th><th>Detalhe</th><th>IP</th></tr></thead>
        <tbody>
        <?php foreach ($linhas as $l): ?>
            <tr>
                <td><?= e(date('d/m/Y H:i:s', strtotime($l['created_at']))) ?></td>
                <td><?= e(auditoriaRotuloEvento($l['evento'])) ?></td>
                <td><?= e($l['usuario_nome'] ?: '—') ?></td>
                <td><?= $l['alvo_tipo'] ? e($l['alvo_tipo']) . ($l['alvo_id'] ? ' #' . (int)$l['alvo_id'] : '') : '—' ?></td>
                <td><?= e($l['detalhe']) ?></td>
                <td><small><?= e($l['ip'] ?: '—') ?></small></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>
</main>
<?php include __DIR__ . '/_pwa_register.php'; ?>
<?php include __DIR__ . '/_notify.php'; ?>
<?php include __DIR__ . '/_scroll_restore.php'; ?>
<?php include __DIR__ . '/_acao_popup.php'; ?>
<?php include __DIR__ . '/_confirm_dialog.php'; ?>
<?php include __DIR__ . '/_zapi_status.php'; ?>
</body>
</html>
