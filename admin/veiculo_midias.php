<?php
/**
 * Fotos/vídeos de um veículo da frota — 17/09/2026, pedido José/Jean:
 * "vamos implementar subir manual o veiculos fotos videos para ia vender
 * qualificar". Antes disso, o único jeito de chegar no catálogo de mídia
 * (`veiculo_midias_revenda`, já existia pro módulo de vendas) era abrir
 * `admin/venda.php` de uma negociação já iniciada — exigia clicar "Vender"
 * só pra poder subir uma foto, mesmo o veículo ainda nem tendo comprador.
 * Esta tela dá acesso direto a partir da Frota (`admin/veiculos.php`),
 * sem precisar criar negociação nenhuma — mesmas funções de
 * `includes/vendas.php` (`salvarMidiaRevenda()`/`listarMidiasRevenda()`/
 * `excluirMidiaRevenda()`), mesmo destino Drive/local, mesma mídia que a
 * IA de vendas manda sozinha pro comprador quando identifica interesse
 * (`includes/ia_qualificacao_vendas.php::enviarMidiaCatalogoParaComprador()`).
 *
 * Guard relaxado em 28/09/2026 — achado real: avaliador batendo 403 tentando
 * adicionar foto do veículo que acabou de cadastrar pela própria vistoria
 * (`admin/avaliacoes.php`). Ver requireAcessoCatalogoRevenda()
 * (`includes/security.php`) pro racional completo (mesmo nível de
 * confiança que vendedor já tem em `admin/venda.php`, não a galeria de
 * vistoria que exige aprovação).
 */

require_once __DIR__ . '/_bootstrap.php';
requireAcessoCatalogoRevenda();

$db = getDB();
$id = (int)($_GET['id'] ?? 0);

$stmt = $db->prepare("
    SELECT o.*, c.nome AS cliente_nome, c.telefone AS cliente_telefone
    FROM oportunidades o
    JOIN clientes c ON c.id = o.cliente_id
    WHERE o.id = ? AND o.etapa = 'fechado'
");
$stmt->execute([$id]);
$v = $stmt->fetch();

if (!$v) {
    http_response_code(404);
    exit('Veículo não encontrado (ou não está na frota — etapa precisa ser "fechado").');
}

$erro = '';
$sucesso = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        $erro = 'Sessão expirada, recarregue a página e tente de novo.';
    } else {
        $acao = (string)($_POST['acao'] ?? '');
        if ($acao === 'upload_midia_revenda') {
            // 28/09/2026 — "permita selecionar múltipla fotos depois cara
            // coloca legenda para ser mais rápido": 1 legenda só, aplicada
            // a todas as fotos/vídeos selecionados nesse envio (nunca 1
            // campo por arquivo — é exatamente o que deixaria mais lento).
            $resultado = salvarMidiasRevendaEmLote($id, $_FILES['midias'] ?? [], (string)($_POST['legenda'] ?? ''));
            if ($resultado['ok_count'] > 0) {
                $sucesso = $resultado['ok_count'] . ' de ' . $resultado['total'] . ' arquivo(s) adicionado(s) ao catálogo.';
                if ($resultado['erros']) {
                    $sucesso .= ' Falhou: ' . implode(' | ', $resultado['erros']);
                }
            } else {
                $erro = $resultado['erros'] ? implode(' | ', $resultado['erros']) : 'Nenhum arquivo enviado.';
            }
        } elseif ($acao === 'excluir_midia_revenda') {
            excluirMidiaRevenda((int)($_POST['midia_id'] ?? 0));
            $sucesso = 'Mídia removida do catálogo.';
        }
    }
}

$midias = listarMidiasRevenda($id);
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Fotos/vídeos — <?= e(trim($v['veiculo_marca'] . ' ' . $v['veiculo_modelo'])) ?: 'Veículo' ?> — Fastcar CRM</title>
<link rel="stylesheet" href="/admin/assets/style.css?v=<?= @filemtime(__DIR__ . '/assets/style.css') ?: 1 ?>">
<link rel="stylesheet" href="/admin/assets/mobile.css?v=<?= @filemtime(__DIR__ . '/assets/mobile.css') ?: 1 ?>">
<script src="/admin/assets/mobile.js?v=<?= @filemtime(__DIR__ . '/assets/mobile.js') ?: 1 ?>" defer></script>
<?php include __DIR__ . '/_pwa_head.php'; ?>
</head>
<body>
<?php include __DIR__ . '/_impersonando_banner.php'; ?>
<header class="topbar">
    <a href="/admin/veiculos.php" style="color:#fff">← Veículos</a>
    <strong><img class="topbar-logo" src="/admin/assets/img/icon-192.png" alt="Fastcar" onerror="this.style.display='none'"> Fast<b>Car</b></strong>
    <span>Olá, <?= e($_SESSION['admin_nome']) ?></span>
    <a href="/admin/logout.php">Sair</a>
</header>
<main>
<?php if (!empty($_GET['recem_cadastrado'])): ?>
    <div class="alerta-sucesso">✅ Veículo cadastrado na frota. Já pode adicionar fotos/vídeos abaixo — a IA de vendas usa esse catálogo pra mandar mídia sozinha pro comprador.</div>
<?php endif; ?>
<?php if ($erro): ?><div class="alerta-erro"><?= e($erro) ?></div><?php endif; ?>
<?php if ($sucesso): ?><div class="alerta-sucesso"><?= e($sucesso) ?></div><?php endif; ?>

<div class="card">
    <h2>#<?= (int)$v['id'] ?> — <?= e(trim($v['veiculo_marca'] . ' ' . $v['veiculo_modelo'])) ?: '—' ?> <?= e((string)($v['veiculo_ano'] ?? '')) ?></h2>
    <p><strong>Placa / Chassi:</strong> <?= e($v['veiculo_placa'] ?: '—') ?> / <?= e($v['veiculo_chassi'] ?: '—') ?></p>
    <p><strong>Comprado de:</strong> <a href="/admin/cliente_detalhe.php?id=<?= (int)$v['cliente_id'] ?>"><?= e($v['cliente_nome']) ?></a> — <?= e($v['cliente_telefone']) ?></p>
</div>

<div class="card">
    <h3>📸 Fotos e vídeos pra revenda</h3>
    <p><small>Fica ligado ao VEÍCULO (não a uma negociação específica) — disponível pra qualquer tentativa de venda
       futura desse carro. A IA de vendas manda essas mídias sozinha pro comprador quando identifica interesse forte
       nesse veículo específico.</small></p>
    <?php if ($midias): ?>
        <div style="display:flex;flex-wrap:wrap;gap:12px;margin-bottom:14px">
            <?php foreach ($midias as $m): ?>
                <div style="width:160px">
                    <?php if ($m['tipo'] === 'foto'): ?>
                        <a href="/admin/ver_midia_revenda.php?id=<?= (int)$m['id'] ?>" target="_blank">
                            <img src="/admin/ver_midia_revenda.php?id=<?= (int)$m['id'] ?>" loading="lazy" style="width:100%;height:120px;object-fit:cover;border-radius:8px">
                        </a>
                    <?php else: ?>
                        <video src="/admin/ver_midia_revenda.php?id=<?= (int)$m['id'] ?>" controls preload="metadata" style="width:100%;border-radius:8px"></video>
                    <?php endif; ?>
                    <?php if ($m['legenda']): ?><small><?= e($m['legenda']) ?></small><?php endif; ?>
                    <form method="post" onsubmit="return confirmarAcao(this, 'Remover essa mídia do catálogo?');" style="margin-top:4px">
                        <?= csrfField() ?>
                        <input type="hidden" name="acao" value="excluir_midia_revenda">
                        <input type="hidden" name="midia_id" value="<?= (int)$m['id'] ?>">
                        <button type="submit" class="perigo" style="margin-top:0;padding:3px 8px;font-size:11.5px">🗑️ Remover</button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <p><small>Nenhuma foto/vídeo cadastrado ainda pra esse veículo.</small></p>
    <?php endif; ?>
    <form method="post" enctype="multipart/form-data" id="mv-form-upload">
        <?= csrfField() ?>
        <input type="hidden" name="acao" value="upload_midia_revenda">
        <label>Fotos/vídeos (pode selecionar vários de uma vez — foto até 10MB, vídeo até 50MB cada, 80MB no total do envio)</label>
        <input type="file" id="mv-input-midias" name="midias[]" accept="image/*,video/*" multiple required>
        <small id="mv-tamanho-selecionado"></small>
        <label>Legenda (opcional — aplicada a todas as fotos/vídeos selecionados acima)</label>
        <input type="text" name="legenda" placeholder="Ex: Fotos da vistoria de entrada">
        <button type="submit">Adicionar ao catálogo</button>
    </form>
</div>
<script>
// 28/09/2026 — avisa ANTES de enviar se o lote passar do teto do servidor
// (VEICULO_MIDIA_MAX_BYTES_LOTE, 80MB) — evita mandar um lote grande
// demais só pra ver um erro cru de "413" ou de conexão cortada; nunca
// substitui a checagem do servidor, só melhora o feedback.
(function () {
    var TETO_MB = 80;
    var input = document.getElementById('mv-input-midias');
    var status = document.getElementById('mv-tamanho-selecionado');
    var form = document.getElementById('mv-form-upload');
    if (!input || !status || !form) return;

    function somaMb() {
        var total = 0;
        for (var i = 0; i < input.files.length; i++) total += input.files[i].size;
        return total / 1024 / 1024;
    }

    input.addEventListener('change', function () {
        if (!input.files.length) { status.textContent = ''; return; }
        var mb = somaMb();
        status.textContent = input.files.length + ' arquivo(s) selecionado(s) — ' + mb.toFixed(1) + 'MB no total';
        status.style.color = mb > TETO_MB ? '#c2410c' : '';
        if (mb > TETO_MB) {
            status.textContent += ' — passou do limite de ' + TETO_MB + 'MB, selecione menos arquivos';
        }
    });

    form.addEventListener('submit', function (e) {
        if (input.files.length && somaMb() > TETO_MB) {
            e.preventDefault();
            alert('Esse lote passa de ' + TETO_MB + 'MB no total. Selecione menos fotos/vídeos de uma vez (pode mandar em mais de um envio).');
        }
    });
})();
</script>
</main>
<?php include __DIR__ . '/_pwa_register.php'; ?>
<?php include __DIR__ . '/_notify.php'; ?>
<?php include __DIR__ . '/_scroll_restore.php'; ?>
<?php include __DIR__ . '/_acao_popup.php'; ?>
<?php include __DIR__ . '/_confirm_dialog.php'; ?>
<?php include __DIR__ . '/_zapi_status.php'; ?>
</body>
</html>
