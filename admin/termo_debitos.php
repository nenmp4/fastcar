<?php
/**
 * Termo de ciência e confissão de débitos do veículo (26/11/2026) — opcional.
 * A equipe informa o valor dos débitos (multas/IPVA), quais são e o prazo;
 * o comprador assina NA TELA (canvas, igual à retirada da vistoria) e o PDF
 * sai com a assinatura + data/hora/IP, salvo como documento da negociação
 * ('termo_debitos_veiculo'). `?pdf=1` = prévia/impressão sem assinatura.
 * Nunca vai pra ZapSign. Ver gerarPdfTermoDebitosVeiculo() (includes/contratos_pdf.php).
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../includes/acesso_documentos.php';
requireAcessoVendas();

$id = (int)($_REQUEST['id'] ?? 0);
$campos = $id ? montarCamposContratoVenda($id) : null;
if (!$campos) {
    http_response_code(404);
    exit('Negociação não encontrada ou sem veículo vinculado.');
}
if (!usuarioPodeAbrirDocumentoNegocio(null, $id)) {
    negarAcessoDocumento('termo_debitos', $id, 'Termo de débitos do veículo — ' . auditoriaContextoNegocio(null, $id));
}

$valor = valorMonetario($_REQUEST['valor'] ?? null);
$prazo = (int)($_REQUEST['prazo'] ?? 30);
if ($prazo < 1 || $prazo > 365) $prazo = 30;
$descricao = mb_substr(trim(strip_tags((string)($_REQUEST['descricao'] ?? ''))), 0, 300);
$meuId = (int)$_SESSION['admin_id'];
$somenteLeitura = ($_SESSION['admin_perfil'] ?? '') === 'supervisor';
$erro = '';

// Assinatura colhida na tela.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        $erro = 'Sessão expirada — recarregue a página.';
    } elseif ($somenteLeitura) {
        http_response_code(403);
        exit('Supervisor só acompanha.');
    } elseif ($valor === null || $valor <= 0) {
        $erro = 'Informe o valor dos débitos.';
    } elseif (!preg_match('#^data:image/png;base64,(.+)$#', (string)($_POST['assinatura_dados'] ?? ''), $m)
              || ($bytes = base64_decode($m[1], true)) === false || $bytes === '') {
        $erro = 'Assinatura inválida — peça pro cliente assinar de novo.';
    } else {
        $tmpAss = tempnam(sys_get_temp_dir(), 'assin_debitos_') . '.png';
        file_put_contents($tmpAss, $bytes);
        $meta = ['assinado_em' => date('Y-m-d H:i:s'), 'ip' => auditoriaClienteIp()];
        $pdfPath = gerarPdfTermoDebitosVeiculo($campos, $valor, $descricao, $prazo, $tmpAss, $meta);

        $db = getDB();
        $cliId = (int)$campos['_cliente_id'];
        $stCli = $db->prepare("SELECT nome FROM clientes WHERE id = ?");
        $stCli->execute([$cliId]);
        $cliNome = (string)($stCli->fetchColumn() ?: "Cliente #{$cliId}");
        $copia = salvarArquivoGeradoComoDocumento($cliId, $cliNome, $pdfPath, "termo_debitos_venda_{$id}_" . time() . '.pdf', 'application/pdf', 'revenda');
        @unlink($pdfPath);
        @unlink($tmpAss);

        if (empty($copia['drive_file_id']) && empty($copia['arquivo_url'])) {
            $erro = 'Não deu pra salvar o termo assinado, tente de novo.';
        } else {
            try {
                $db->prepare("
                    INSERT INTO venda_documentos (venda_id, tipo, drive_file_id, arquivo_url, obrigatorio, enviado_pelo_cliente, updated_at)
                    VALUES (?, 'termo_debitos_veiculo', ?, ?, 0, 0, datetime('now','localtime'))
                    ON CONFLICT(venda_id, tipo) DO UPDATE SET
                        drive_file_id = excluded.drive_file_id, arquivo_url = excluded.arquivo_url,
                        updated_at = datetime('now','localtime')
                ")->execute([$id, $copia['drive_file_id'] ?? '', $copia['arquivo_url'] ?? '']);
            } catch (Throwable $e) {
                $erro = 'Falha ao gravar o termo, tente de novo.';
            }
            if (!$erro) {
                auditoriaRegistrar('termo_debitos_assinado', $meuId, (string)($_SESSION['admin_nome'] ?? ''), 'venda', $id,
                    'Termo de débitos assinado na tela — R$ ' . number_format($valor, 2, ',', '.') . ", prazo {$prazo} dias.");
                header('Location: /admin/venda.php?id=' . $id . '&termo_debitos=assinado');
                exit;
            }
        }
    }
}

// Prévia / impressão em branco.
if (($_GET['pdf'] ?? '') === '1') {
    if ($valor === null || $valor <= 0) {
        http_response_code(400);
        exit('Informe o valor dos débitos.');
    }
    auditoriaRegistrarDocumentoAberto('termo_debitos', $id, 'Termo de débitos do veículo (prévia) — ' . auditoriaContextoNegocio(null, $id));
    pdfAtivarMarcaDagua();
    $caminho = gerarPdfTermoDebitosVeiculo($campos, $valor, $descricao, $prazo);
    $pdf = (string)file_get_contents($caminho);
    @unlink($caminho);
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="termo_debitos_' . $id . '.pdf"');
    header('Content-Length: ' . strlen($pdf));
    header('Cache-Control: private, no-store');
    echo $pdf;
    exit;
}

$contratoNum = (($campos['_tipo_contrato'] ?? 'venda') === 'locacao' ? 'LOC-' : 'VENDA-') . $id;
$temValor = $valor !== null && $valor > 0;
$qsPrevia = http_build_query(['id' => $id, 'valor' => $temValor ? number_format($valor, 2, ',', '.') : '', 'descricao' => $descricao, 'prazo' => $prazo, 'pdf' => 1]);
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Termo de débitos — Fastcar CRM</title>
<link rel="stylesheet" href="/admin/assets/style.css?v=<?= @filemtime(__DIR__ . '/assets/style.css') ?: 1 ?>">
<link rel="stylesheet" href="/admin/assets/mobile.css?v=<?= @filemtime(__DIR__ . '/assets/mobile.css') ?: 1 ?>">
<script src="/admin/assets/mobile.js?v=<?= @filemtime(__DIR__ . '/assets/mobile.js') ?: 1 ?>" defer></script>
<?php include __DIR__ . '/_pwa_head.php'; ?>
<style>
.td-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:12px; }
.td-pad-wrap { position:relative; max-width:520px; }
.td-pad { display:block; width:100%; height:200px; background:#fff; border:2px dashed var(--borda); border-radius:var(--raio); touch-action:none; }
.td-pad-dica { position:absolute; inset:0; display:flex; align-items:center; justify-content:center; color:var(--cinza, #8891a3); pointer-events:none; }
.td-acoes { display:flex; gap:10px; flex-wrap:wrap; margin-top:10px; max-width:520px; }
.td-acoes button, .td-acoes a.btn { flex:1; min-height:48px; margin-top:0; }
</style>
</head>
<body>
<?php include __DIR__ . '/_impersonando_banner.php'; ?>
<?php include __DIR__ . '/_topbar.php'; ?>
<main>
<?php if ($erro): ?><div class="alerta-erro"><?= e($erro) ?></div><?php endif; ?>

<div class="card">
    <p style="margin:0 0 6px"><a href="/admin/venda.php?id=<?= $id ?>">← Voltar pra negociação</a></p>
    <h2 style="margin:0 0 6px">💰 Termo de débitos do veículo — contrato <?= e($contratoNum) ?></h2>
    <p style="margin:0 0 10px"><small>Opcional: use quando o carro sai com multa/IPVA em aberto. O comprador assume pagar no prazo e os custos se o carro for apreendido.</small></p>
    <div class="td-grid" style="margin-bottom:12px">
        <div><small>Comprador</small><br><strong><?= e((string)$campos['comprador_nome'] ?: '—') ?></strong>
            <br><small><?= e((string)$campos['comprador_cpf']) ?></small></div>
        <div><small>Veículo</small><br><strong><?= e(trim("{$campos['veiculo_marca']} {$campos['veiculo_modelo']} {$campos['veiculo_ano']}")) ?: '—' ?></strong>
            <br><small><?= e(strtoupper((string)$campos['veiculo_placa'])) ?></small></div>
    </div>

    <form method="get" class="td-grid">
        <input type="hidden" name="id" value="<?= $id ?>">
        <label>Valor dos débitos (R$)
            <input type="text" name="valor" inputmode="decimal" required placeholder="5.200,00" value="<?= $temValor ? e(number_format($valor, 2, ',', '.')) : '' ?>">
        </label>
        <label>Quais débitos
            <input type="text" name="descricao" maxlength="300" placeholder="Multas e IPVA" value="<?= e($descricao) ?>">
        </label>
        <label>Prazo pra regularizar (dias)
            <input type="number" name="prazo" min="1" max="365" value="<?= $prazo ?>" required>
        </label>
        <div style="align-self:end"><button type="submit" class="secundario" style="min-height:44px">🔄 Atualizar termo</button></div>
    </form>

    <?php if ($temValor): ?>
        <p style="margin:12px 0 0"><a class="btn" href="/admin/termo_debitos.php?<?= e($qsPrevia) ?>" target="_blank" rel="noopener">📄 Ver o termo (prévia / imprimir)</a></p>

        <?php if (!$somenteLeitura): ?>
        <div style="border-top:1px solid var(--borda);padding-top:12px;margin-top:12px">
            <h3 style="margin:0 0 4px">✍️ Assinatura do comprador</h3>
            <p style="margin:0 0 8px"><small>Mostre o termo pro comprador e peça pra ele assinar com o dedo aqui. Ao confirmar, o termo sai com a assinatura, data, hora e IP e fica salvo nos documentos da negociação.</small></p>
            <div class="td-pad-wrap">
                <canvas class="td-pad" id="td-pad"></canvas>
                <div class="td-pad-dica" id="td-pad-dica">✍️ Toque e assine aqui</div>
            </div>
            <div class="td-acoes">
                <button type="button" class="secundario" id="td-limpar">🗑️ Limpar</button>
                <button type="button" id="td-confirmar" disabled>✅ Confirmar assinatura</button>
            </div>
            <form method="post" id="td-form-assinar">
                <?= csrfField() ?>
                <input type="hidden" name="id" value="<?= $id ?>">
                <input type="hidden" name="valor" value="<?= e(number_format($valor, 2, ',', '.')) ?>">
                <input type="hidden" name="descricao" value="<?= e($descricao) ?>">
                <input type="hidden" name="prazo" value="<?= $prazo ?>">
                <input type="hidden" name="assinatura_dados" id="td-dados">
            </form>
        </div>
        <?php endif; ?>
    <?php else: ?>
        <div class="alerta-info" style="margin-top:12px">Informe o valor dos débitos e clique em "Atualizar termo" pra liberar a assinatura.</div>
    <?php endif; ?>
</div>
</main>
<script>
(function () {
    var canvas = document.getElementById('td-pad');
    if (!canvas) return;
    var dica = document.getElementById('td-pad-dica');
    var btnLimpar = document.getElementById('td-limpar');
    var btnOk = document.getElementById('td-confirmar');
    var ctx, temTraco = false, desenhando = false;
    function preparar() {
        var r = canvas.getBoundingClientRect();
        canvas.width = r.width; canvas.height = r.height;
        ctx = canvas.getContext('2d');
        ctx.strokeStyle = '#151722'; ctx.lineWidth = 2.6; ctx.lineCap = 'round'; ctx.lineJoin = 'round';
    }
    preparar();
    function pos(e) {
        var r = canvas.getBoundingClientRect();
        var t = (e.touches && e.touches.length) ? e.touches[0] : e;
        return { x: t.clientX - r.left, y: t.clientY - r.top };
    }
    function iniciar(e) {
        e.preventDefault(); desenhando = true;
        var p = pos(e); ctx.beginPath(); ctx.moveTo(p.x, p.y);
        if (!temTraco) { temTraco = true; dica.style.display = 'none'; btnOk.disabled = false; }
    }
    function mover(e) { if (!desenhando) return; e.preventDefault(); var p = pos(e); ctx.lineTo(p.x, p.y); ctx.stroke(); }
    function parar() { desenhando = false; }
    canvas.addEventListener('mousedown', iniciar);
    canvas.addEventListener('mousemove', mover);
    window.addEventListener('mouseup', parar);
    canvas.addEventListener('touchstart', iniciar, { passive: false });
    canvas.addEventListener('touchmove', mover, { passive: false });
    canvas.addEventListener('touchend', parar);
    btnLimpar.addEventListener('click', function () {
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        temTraco = false; dica.style.display = 'flex'; btnOk.disabled = true;
    });
    btnOk.addEventListener('click', function () {
        if (!temTraco) return;
        document.getElementById('td-dados').value = canvas.toDataURL('image/png');
        btnOk.disabled = true;
        var f = document.getElementById('td-form-assinar');
        if (f.requestSubmit) { f.requestSubmit(); } else { f.submit(); }
    });
})();
</script>
<?php include __DIR__ . '/_pwa_register.php'; ?>
<?php include __DIR__ . '/_notify.php'; ?>
<?php include __DIR__ . '/_scroll_restore.php'; ?>
<?php include __DIR__ . '/_acao_popup.php'; ?>
<?php include __DIR__ . '/_confirm_dialog.php'; ?>
<?php include __DIR__ . '/_zapi_status.php'; ?>
</body>
</html>
