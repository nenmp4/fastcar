<?php
/**
 * Termo Ciente — 06/10/2026, "Não enviar checlist de retirada do veiculo
 * pelo zapsiner - vamos fazer documento interno cliente só da uma aceite
 * ao receber no email o link - termo ciente". Substitui a assinatura
 * eletrônica via ZapSign do termo de entrega/vistoria (exclusivo de
 * venda — comprador novo recebendo o carro): o comprador recebe este
 * link por E-MAIL (includes/veiculo_avaliacoes.php::gerarEEnviarTermoAvaliacao())
 * e só CONFIRMA com 1 clique que está de acordo — nunca uma assinatura
 * eletrônica de verdade, é um registro interno de "recebi e não
 * reclamei nada na hora". Acesso só pelo token na URL (mesmo padrão de
 * public/documentos.php) — nunca pode ser indexado/cacheado por robô.
 *
 * A etapa é sempre derivada do banco (`termo_status`): o comprador pode
 * fechar a aba e reabrir o mesmo link depois, de outro aparelho, sem
 * perder nada — reabrir um termo JÁ confirmado só mostra a confirmação
 * de novo (confirmarTermoCiente() é idempotente, nunca sobrescreve a
 * 1ª data/IP/ressalva registrada).
 */

header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet', true);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/veiculo_avaliacoes.php';
require_once __DIR__ . '/../includes/auditoria.php'; // auditoriaClienteIp()

startSecureSession();

function _linkInvalido(): void {
    http_response_code(404);
    ?>
    <!doctype html><html lang="pt-br"><head><meta charset="utf-8"><title>Link inválido — Fastcar</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/png" href="/public/assets/favicon.png"></head>
    <body style="font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif;text-align:center;padding:60px 20px;background:#151722;color:#fff;min-height:100vh;margin:0">
        <img src="/public/assets/logo.png" alt="Fastcar" style="max-height:56px;margin-bottom:24px" onerror="this.style.display='none'">
        <h2>⚠️ Link inválido ou expirado</h2>
        <p style="color:#aab4d4">Fale com quem te atendeu na Fastcar pra receber um novo link.</p>
        <p style="color:#6b7aa0;font-size:11.5px;margin-top:40px;padding-top:16px;border-top:1px solid #2a3352">
        <strong style="color:#5b91ff">FASTCAR SOLUTIONS</strong> — CNPJ 66.934.500/0001-09<br>
        Av. Sagitário, 138 — Alphaville Conde II, Barueri/SP</p>
    </body></html>
    <?php
    exit;
}

$token = (string)($_GET['token'] ?? $_POST['token'] ?? '');
$av = buscarAvaliacaoPorTermoCienteToken($token);
if (!$av) {
    _linkInvalido();
}

$erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        $erro = 'Sessão expirada — atualize a página e tente de novo.';
    } elseif ((string)($_POST['acao'] ?? '') === 'confirmar') {
        if (empty($_POST['concordo'])) {
            $erro = 'Marque a caixinha confirmando que leu as informações antes de continuar.';
        } else {
            $ip = auditoriaClienteIp();
            $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
            $resultado = confirmarTermoCiente($token, (string)($_POST['ressalva'] ?? ''), $ip, $ua);
            if ($resultado['ok']) {
                $av = $resultado['avaliacao'];
            } else {
                $erro = $resultado['erro'] ?? 'Não foi possível confirmar agora — tente de novo.';
            }
        }
    }
}

$itens = listarItensAvaliacao((int)$av['id']);
$score = veiculoAvaliacaoScore($itens);
$fotos = listarFotosAvaliacao((int)$av['id']);
$confirmado = $av['termo_status'] === 'confirmado';
$veiculo = trim(($av['veiculo_marca'] ?? '') . ' ' . ($av['veiculo_modelo'] ?? '')) ?: 'Veículo';
$nomeComprador = (string)($av['comprador_nome'] ?: '');

function _itemClasse(string $status): string {
    return match ($status) {
        'ok' => 'ck-ok',
        'problema' => 'ck-warn',
        default => 'ck-neutral',
    };
}
function _itemIcone(string $status): string {
    return match ($status) {
        'ok' => '✓',
        'problema' => '!',
        default => '?',
    };
}
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Termo Ciente — Fastcar</title>
<link rel="icon" type="image/png" href="/public/assets/favicon.png">
<style>
:root {
    --navy: #151722;
    --navy-2: #101d40;
    --blue: #2f6fed;
    --blue-dark: #1a4fc4;
    --texto: #1c1e29;
    --ok: #157a3d;
    --ok-bg: #e4f7ea;
    --warn: #a85a05;
    --warn-bg: #fdf1de;
    --neutro: #5b6274;
    --neutro-bg: #eef0f5;
}
* { box-sizing: border-box; }
body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif; background: #f4f5f9; color: var(--texto); margin: 0; }
.marca { text-align: center; padding: 32px 16px 26px; background: linear-gradient(135deg, var(--navy) 0%, var(--navy-2) 100%); }
.marca img { max-height: 64px; margin-bottom: 6px; }
.marca .logotipo { font-size: 26px; font-weight: 700; color: #fff; letter-spacing: .2px; }
.marca .logotipo b { color: var(--blue); }
.marca .subtitulo { font-size: 12px; color: #aab4d4; letter-spacing: .5px; text-transform: uppercase; margin-top: 2px; }
.wrap { max-width: 520px; margin: 0 auto; padding: 20px 16px 60px; }
h1 { font-size: 20px; margin: 0 0 4px; }
.card { background: #fff; border-radius: 14px; padding: 20px 22px; margin-bottom: 16px; box-shadow: 0 8px 24px rgba(10,18,41,.14); }
.veiculo-info { font-size: 13.5px; color: #555; display: flex; flex-wrap: wrap; gap: 4px 16px; margin-bottom: 4px; }
.veiculo-info b { color: var(--texto); }
.alerta-erro { background: #fbe4e1; color: #a33; padding: 10px 14px; border-radius: 8px; margin-bottom: 14px; }
.alerta-info { background: #e8f0fc; color: var(--blue-dark); padding: 10px 14px; border-radius: 8px; margin-bottom: 14px; font-size: 13px; }
.checklist { display: flex; flex-direction: column; gap: 8px; margin: 10px 0 0; }
.ck-item { display: flex; align-items: flex-start; gap: 10px; padding: 10px 12px; border-radius: 10px; border: 1px solid #e4e7ee; background: #fafbfd; }
.ck-badge { flex-shrink: 0; width: 22px; height: 22px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 13px; font-weight: 700; }
.ck-ok .ck-badge { background: var(--ok-bg); color: var(--ok); }
.ck-warn .ck-badge { background: var(--warn-bg); color: var(--warn); }
.ck-neutral .ck-badge { background: var(--neutro-bg); color: var(--neutro); }
.ck-label { font-size: 13.5px; font-weight: 600; }
.ck-obs { font-size: 12.5px; color: #666; margin-top: 2px; }
.score-box { display: flex; align-items: center; gap: 16px; margin-top: 10px; padding: 14px 16px; border-radius: 10px; background: #f3f6fd; }
.score-num { font-size: 28px; font-weight: 800; color: var(--blue-dark); line-height: 1; flex-shrink: 0; font-variant-numeric: tabular-nums; }
.score-texto { font-size: 13px; color: #444; }
.fotos-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(90px, 1fr)); gap: 8px; margin-top: 10px; }
.fotos-grid img, .fotos-grid video { width: 100%; aspect-ratio: 1; object-fit: cover; border-radius: 8px; border: 1px solid #e4e7ee; }
label { display: block; font-size: 13px; color: #555; margin: 14px 0 4px; }
textarea { width: 100%; padding: 11px 12px; border: 1px solid #d8dce4; border-radius: 8px; font-size: 15px; font-family: inherit; resize: vertical; min-height: 70px; }
textarea:focus { outline: none; border-color: var(--blue); box-shadow: 0 0 0 3px rgba(47,111,237,.15); }
.check-linha { display: flex; align-items: flex-start; gap: 9px; font-size: 13.5px; margin-top: 16px; }
.check-linha input { margin-top: 3px; flex-shrink: 0; width: 18px; height: 18px; accent-color: var(--blue); }
button { width: 100%; margin-top: 18px; padding: 14px; border: none; border-radius: 10px;
    background: linear-gradient(135deg, var(--blue) 0%, var(--blue-dark) 100%); color: #fff;
    font-size: 16px; font-weight: 600; cursor: pointer; }
.conf-check { width: 56px; height: 56px; border-radius: 50%; background: var(--ok-bg); color: var(--ok);
    display: flex; align-items: center; justify-content: center; font-size: 26px; margin: 4px auto 14px; }
.conf-wrap { text-align: center; }
.rodape-empresa { text-align: center; font-size: 11.5px; color: #6b7aa0; line-height: 1.7; margin-top: 28px;
    padding: 18px 16px 4px; border-top: 2px solid transparent;
    border-image: linear-gradient(90deg, transparent, var(--blue) 50%, transparent) 1; }
.rodape-empresa strong { color: var(--blue-dark); font-size: 12.5px; letter-spacing: .02em; }
</style>
</head>
<body>
<div class="marca">
    <img src="/public/assets/logo.png" alt="Fastcar" onerror="this.style.display='none'">
    <div class="logotipo">Fast<b>Car</b></div>
    <div class="subtitulo">Soluções Financeiras</div>
</div>
<div class="wrap">

<?php if ($erro): ?><div class="alerta-erro"><?= e($erro) ?></div><?php endif; ?>

<?php if ($confirmado): ?>
    <div class="card conf-wrap">
        <div class="conf-check">✓</div>
        <h1>Recebimento confirmado</h1>
        <p style="color:#555;font-size:13.5px">
            <strong><?= e($nomeComprador) ?></strong> confirmou que está de acordo com a entrega do
            <strong><?= e($veiculo) ?></strong>.
        </p>
        <p style="color:#888;font-size:12px"><?= $av['termo_assinado_em'] ? date('d/m/Y \à\s H:i', strtotime((string)$av['termo_assinado_em'])) : '' ?></p>
        <?php if (($av['termo_ciente_ressalva'] ?? '') !== ''): ?>
            <div class="alerta-info" style="text-align:left;margin-top:10px">
                <strong>Sua observação:</strong><br><?= nl2br(e($av['termo_ciente_ressalva'])) ?>
            </div>
        <?php endif; ?>
        <p style="color:#888;font-size:12px;margin-top:14px">Guardamos esse registro pra controle interno. Qualquer divergência, é só falar com quem te atendeu.</p>
    </div>
<?php else: ?>
    <h1>Entrega do veículo</h1>
    <div class="card">
        <div class="veiculo-info">
            <span><b><?= e($veiculo) ?></b><?= $av['veiculo_ano'] ? ' ' . e((string)$av['veiculo_ano']) : '' ?></span>
            <?php if ($av['veiculo_placa']): ?><span>Placa <?= e($av['veiculo_placa']) ?></span><?php endif; ?>
        </div>
        <p style="font-size:13px;color:#666;margin:6px 0 0">Confira o checklist completo da vistoria antes de confirmar.</p>

        <div class="checklist">
            <?php foreach ($itens as $item): ?>
                <div class="ck-item <?= _itemClasse($item['status']) ?>">
                    <span class="ck-badge"><?= _itemIcone($item['status']) ?></span>
                    <div>
                        <div class="ck-label"><?= e(veiculoAvaliacaoRotuloItem($item['item'])) ?></div>
                        <?php if (($item['observacao'] ?? '') !== ''): ?>
                            <div class="ck-obs"><?= e($item['observacao']) ?></div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="score-box">
            <div class="score-num"><?= $score['percentual'] !== null ? $score['percentual'] . '%' : '—' ?></div>
            <div class="score-texto"><?= e($score['resumo']) ?></div>
        </div>

        <?php if (($av['observacoes_gerais'] ?? '') !== ''): ?>
            <p style="font-size:12.5px;color:#666;margin-top:10px"><strong>Observação geral do avaliador:</strong> <?= e($av['observacoes_gerais']) ?></p>
        <?php endif; ?>

        <?php if ($fotos): ?>
            <label style="margin-top:16px">Fotos da vistoria</label>
            <div class="fotos-grid">
                <?php foreach ($fotos as $f): $src = '/public/termo_ciente_foto.php?token=' . rawurlencode($token) . '&foto_id=' . (int)$f['id']; ?>
                    <?php if ($f['tipo'] === 'video'): ?>
                        <video src="<?= e($src) ?>" controls preload="none"></video>
                    <?php else: ?>
                        <img src="<?= e($src) ?>" alt="Foto da vistoria" loading="lazy">
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <p style="font-size:12px;color:#888;margin-top:16px">
            Este documento é um registro interno da FASTCAR SOLUTIONS, feito só pra controle da entrega —
            não é um contrato nem substitui o contrato de venda, que você já assinou separadamente.
        </p>

        <form method="post">
            <?= csrfField() ?>
            <input type="hidden" name="token" value="<?= e($token) ?>">
            <input type="hidden" name="acao" value="confirmar">
            <label>Quer registrar alguma observação? (opcional)</label>
            <textarea name="ressalva" placeholder="Ex: recebi, mas notei um ruído leve na suspensão..."></textarea>
            <div class="check-linha">
                <input type="checkbox" name="concordo" id="concordo" value="1" required>
                <label for="concordo" style="margin:0">Li as informações acima e estou de acordo com o estado do veículo na entrega.</label>
            </div>
            <button type="submit">✅ Estou ciente, confirmar recebimento</button>
        </form>
    </div>
<?php endif; ?>

<p class="rodape-empresa">
<strong>FASTCAR SOLUTIONS</strong> — CNPJ 66.934.500/0001-09<br>
Av. Sagitário, 138 — Alphaville Conde II, Barueri/SP — CEP 06473-073
</p>
</div>
</body>
</html>
