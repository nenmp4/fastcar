<?php
/**
 * Página pública de 1 veículo do estoque (24/10/2026) — o vendedor copia
 * o link no Estoque (admin/estoque.php) e manda pro cliente. Sem login,
 * autenticada pelo token do link (veiculoLinkPublicoValido()). Some
 * sozinha quando o carro entra em negociação/é vendido. Nunca mostra
 * placa, valor pago pela Fastcar nem nada do dono anterior.
 */
header('X-Robots-Tag: noindex, nofollow, noarchive', true);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/vendas.php';

$o = (int)($_GET['o'] ?? 0);
$vid = (int)($_GET['v'] ?? 0);
$t = (string)($_GET['t'] ?? '');
$valido = veiculoLinkPublicoValido($o, $vid, $t);
$disponivel = $valido && veiculoDisponivelParaVenda($o);

$db = getDB();
$veic = null; $midias = []; $vendedor = null;
if ($valido) {
    $stmt = $db->prepare("SELECT veiculo_marca, veiculo_modelo, veiculo_ano, tipo_veiculo FROM oportunidades WHERE id = ?");
    $stmt->execute([$o]);
    $veic = $stmt->fetch();
    $stmt = $db->prepare("SELECT nome, whatsapp FROM usuarios WHERE id = ? AND bloqueado = 0");
    $stmt->execute([$vid]);
    $vendedor = $stmt->fetch() ?: null;
    if ($disponivel) $midias = listarMidiasRevenda($o);
    // 24/10/2026 — sem foto de revenda ainda, usa as fotos da vistoria.
    if ($disponivel && !$midias) $midias = listarMidiasVistoriaVeiculo($o);
}
$titulo = $veic ? trim(($veic['veiculo_marca'] ?? '') . ' ' . ($veic['veiculo_modelo'] ?? '') . ' ' . ($veic['veiculo_ano'] ?? '')) : 'Veículo';
$base = rtrim((string)(getConfig('app_base_url') ?: 'https://sistema.fastcar.solutions'), '/');
$urlMidia = fn(array $m) => '/public/veiculo_foto.php?o=' . $o . '&v=' . $vid . '&t=' . urlencode($t) . '&m=' . (int)$m['id'] . (!empty($m['_vistoria']) ? '&s=av' : '');
$fotos = array_values(array_filter($midias, fn($m) => $m['tipo'] === 'foto'));
$videos = array_values(array_filter($midias, fn($m) => $m['tipo'] === 'video'));

$zap = '';
if ($vendedor && !empty($vendedor['whatsapp'])) {
    $numero = normalizarTelefone((string)$vendedor['whatsapp']);
    $zap = 'https://wa.me/' . $numero . '?text=' . rawurlencode("Olá! Vi o {$titulo} no link da Fastcar e quero saber mais.");
}
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($titulo) ?> — Fastcar</title>
<?php if ($disponivel): ?>
<meta property="og:title" content="<?= e($titulo) ?> — Fastcar">
<meta property="og:description" content="Veículo disponível na Fastcar. Venha conhecer!">
<?php if ($fotos): ?><meta property="og:image" content="<?= e($base . $urlMidia($fotos[0])) ?>"><?php endif; ?>
<?php endif; ?>
<style>
:root { --navy:#151722; --azul:#2f6fed; --borda:#e5e7eb; }
* { box-sizing:border-box; }
body { margin:0; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif; background:#f4f4f7; color:#151722; }
header { background:var(--navy); padding:16px; text-align:center; }
header img { max-height:44px; }
header .txt { color:#fff; font-weight:700; font-size:22px; }
main { max-width:720px; margin:0 auto; padding:16px; }
h1 { font-size:24px; margin:8px 0 4px; }
.galeria { display:flex; flex-direction:column; gap:10px; margin:16px 0; }
.galeria img, .galeria video { width:100%; border-radius:12px; background:#000; display:block; }
.sem-foto { background:var(--navy); color:#fff; border-radius:12px; padding:60px 20px; text-align:center; font-size:60px; }
.btn-zap { display:block; text-align:center; background:#25d366; color:#fff; text-decoration:none; font-weight:700; font-size:18px; padding:16px; border-radius:12px; margin:20px 0; }
.aviso { background:#fff; border:1px solid var(--borda); border-radius:12px; padding:24px; text-align:center; margin-top:30px; }
footer { text-align:center; font-size:12px; color:#666; padding:20px; border-top:3px solid var(--azul); background:#fff; margin-top:30px; }
</style>
</head>
<body>
<header><img src="/public/assets/logo.png" alt="Fastcar" onerror="this.outerHTML='<span class=&quot;txt&quot;>FastCar</span>'"></header>
<main>
<?php if (!$valido || !$veic): ?>
    <div class="aviso"><h2>Link inválido</h2><p>Peça um novo link pra quem te enviou.</p></div>
<?php elseif (!$disponivel): ?>
    <div class="aviso"><h2><?= e($titulo) ?></h2><p>Esse veículo não está mais disponível. 😕<br>Mas temos outras opções — fale com a gente!</p>
    <?php if ($zap): ?><a class="btn-zap" href="<?= e($zap) ?>">💬 Falar no WhatsApp</a><?php endif; ?></div>
<?php else: ?>
    <h1><?= e($titulo) ?></h1>
    <p>Disponível na Fastcar · documentação regularizada</p>
    <div class="galeria">
        <?php foreach ($fotos as $f): ?><img src="<?= e($urlMidia($f)) ?>" alt="<?= e($f['legenda'] ?: $titulo) ?>" loading="lazy"><?php endforeach; ?>
        <?php foreach ($videos as $vd): ?><video src="<?= e($urlMidia($vd)) ?>" controls preload="metadata" playsinline></video><?php endforeach; ?>
        <?php if (!$midias): ?><div class="sem-foto"><?= ($veic['tipo_veiculo'] ?? '') === 'moto' ? '🏍️' : '🚗' ?></div><?php endif; ?>
    </div>
    <?php if ($zap): ?>
        <a class="btn-zap" href="<?= e($zap) ?>">💬 Quero esse! Falar com <?= e(explode(' ', (string)$vendedor['nome'])[0]) ?></a>
    <?php endif; ?>
    <p style="text-align:center"><small>Condições de pagamento e visita: fale com nosso vendedor.</small></p>
<?php endif; ?>
</main>
<footer><strong>FASTCAR SOLUTIONS</strong> · CNPJ 66.934.500/0001-09<br>Av. Sagitário, 138 — Alphaville Conde II, Barueri/SP</footer>
</body>
</html>
