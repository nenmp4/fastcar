<?php
/**
 * Catálogo público do estoque inteiro (24/10/2026, "crie link pra mandar o
 * catálogo inteiro do estoque"). O vendedor copia/manda o link no
 * 🚘 Estoque (admin/estoque.php). Sem login, autenticado pelo token do
 * vendedor (catalogoLinkPublicoValido()). Sempre lê o estoque AO VIVO:
 * carro vendido/em negociação/oculto some sozinho. Nunca mostra placa,
 * valor pago pela Fastcar nem dado do dono anterior.
 */
header('X-Robots-Tag: noindex, nofollow, noarchive', true);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/vendas.php';

$vid = (int)($_GET['v'] ?? 0);
$t = (string)($_GET['t'] ?? '');
$valido = catalogoLinkPublicoValido($vid, $t);

$vendedor = null;
$veiculos = [];
if ($valido) {
    $stmt = getDB()->prepare("SELECT nome, whatsapp FROM usuarios WHERE id = ? AND bloqueado = 0");
    $stmt->execute([$vid]);
    $vendedor = $stmt->fetch() ?: null;
    $veiculos = listarFrotaDisponivelParaVenda(1000);
}
$tipoFiltro = in_array($_GET['tipo'] ?? '', ['carro', 'moto', 'caminhao', 'outro'], true) ? $_GET['tipo'] : '';
if ($tipoFiltro !== '') $veiculos = array_values(array_filter($veiculos, fn($v) => ($v['tipo_veiculo'] ?? '') === $tipoFiltro));
$base = rtrim((string)(getConfig('app_base_url') ?: 'https://sistema.fastcar.solutions'), '/');
$primeiroNome = $vendedor ? explode(' ', (string)$vendedor['nome'])[0] : '';
$zap = '';
if ($vendedor && !empty($vendedor['whatsapp'])) {
    $zap = 'https://wa.me/' . normalizarTelefone((string)$vendedor['whatsapp']) . '?text=' . rawurlencode('Olá! Vi o catálogo da Fastcar e quero saber mais.');
}
$icone = ['carro' => '🚗', 'moto' => '🏍️', 'caminhao' => '🚚', 'outro' => '🚙'];
$capaOg = null;
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Catálogo Fastcar — veículos disponíveis</title>
<meta property="og:title" content="Catálogo Fastcar — <?= count($veiculos) ?> veículo(s) disponível(is)">
<meta property="og:description" content="Carros e motos disponíveis na Fastcar. Entrada facilitada — venha conhecer!">
<meta property="og:image" content="<?= e($base) ?>/admin/assets/img/icon-512.png">
<style>
:root { --navy:#151722; --azul:#2f6fed; --borda:#e5e7eb; }
* { box-sizing:border-box; }
body { margin:0; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif; background:#f4f4f7; color:#151722; }
header { background:var(--navy); padding:16px; text-align:center; }
header img { max-height:44px; }
header .txt { color:#fff; font-weight:700; font-size:22px; }
main { max-width:1100px; margin:0 auto; padding:16px; }
h1 { font-size:24px; margin:8px 0 4px; }
.filtros { display:flex; gap:8px; flex-wrap:wrap; margin:12px 0; }
.filtros a { text-decoration:none; color:var(--navy); background:#fff; border:1px solid var(--borda); border-radius:999px; padding:8px 14px; font-size:15px; }
.filtros a.ativo { background:var(--azul); color:#fff; border-color:var(--azul); }
.grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(240px,1fr)); gap:16px; }
.card { background:#fff; border:1px solid var(--borda); border-radius:14px; overflow:hidden; text-decoration:none; color:inherit; display:flex; flex-direction:column; box-shadow:0 2px 8px rgba(0,0,0,.05); }
.foto { aspect-ratio:4/3; background:var(--navy); display:flex; align-items:center; justify-content:center; font-size:56px; overflow:hidden; }
.foto img { width:100%; height:100%; object-fit:cover; }
.info { padding:12px 14px 16px; }
.info h2 { font-size:18px; margin:0 0 4px; }
.info small { color:#666; }
.ver { display:block; margin-top:10px; color:var(--azul); font-weight:700; }
.btn-zap { display:block; text-align:center; background:#25d366; color:#fff; text-decoration:none; font-weight:700; font-size:18px; padding:16px; border-radius:12px; margin:24px auto; max-width:520px; }
.aviso { background:#fff; border:1px solid var(--borda); border-radius:12px; padding:24px; text-align:center; margin-top:30px; }
footer { text-align:center; font-size:12px; color:#666; padding:20px; border-top:3px solid var(--azul); background:#fff; margin-top:30px; }
</style>
</head>
<body>
<header><img src="/public/assets/logo.png" alt="Fastcar" onerror="this.outerHTML='<span class=&quot;txt&quot;>FastCar</span>'"></header>
<main>
<?php if (!$valido): ?>
    <div class="aviso"><h2>Link inválido</h2><p>Peça um novo link pra quem te enviou.</p></div>
<?php else: ?>
    <h1>🚘 Veículos disponíveis na Fastcar</h1>
    <p>Documentação regularizada · entrada facilitada · atendimento Fastcar</p>
    <?php $qs = fn($tp) => '?v=' . $vid . '&t=' . urlencode($t) . ($tp ? '&tipo=' . $tp : ''); ?>
    <div class="filtros">
        <a href="<?= e($qs('')) ?>" class="<?= $tipoFiltro === '' ? 'ativo' : '' ?>">Todos</a>
        <a href="<?= e($qs('carro')) ?>" class="<?= $tipoFiltro === 'carro' ? 'ativo' : '' ?>">🚗 Carros</a>
        <a href="<?= e($qs('moto')) ?>" class="<?= $tipoFiltro === 'moto' ? 'ativo' : '' ?>">🏍️ Motos</a>
    </div>
    <?php if (!$veiculos): ?>
        <div class="aviso"><p>Nenhum veículo disponível agora — mas chegam novidades toda semana. Fale com a gente!</p></div>
    <?php else: ?>
    <div class="grid">
        <?php foreach ($veiculos as $v):
            $oid = (int)$v['oportunidade_id'];
            $titulo = trim(($v['veiculo_marca'] ?? '') . ' ' . ($v['veiculo_modelo'] ?? ''));
            $capa = veiculoCapaPublica($oid);
            $tok = veiculoLinkPublicoToken($oid, $vid);
            $linkVeic = '/public/veiculo.php?o=' . $oid . '&v=' . $vid . '&t=' . $tok;
        ?>
        <a class="card" href="<?= e($linkVeic) ?>">
            <div class="foto">
                <?php if ($capa): ?>
                    <img src="<?= e('/public/veiculo_foto.php?o=' . $oid . '&v=' . $vid . '&t=' . $tok . '&m=' . (int)$capa['id'] . (!empty($capa['_vistoria']) ? '&s=av' : '')) ?>" alt="<?= e($titulo) ?>" loading="lazy">
                <?php else: ?>
                    <?= $icone[$v['tipo_veiculo'] ?? ''] ?? '🚗' ?>
                <?php endif; ?>
            </div>
            <div class="info">
                <h2><?= e($titulo ?: 'Veículo') ?></h2>
                <small><?= e((string)($v['veiculo_ano'] ?? '')) ?></small>
                <span class="ver">Ver fotos e detalhes →</span>
            </div>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <?php if ($zap): ?><a class="btn-zap" href="<?= e($zap) ?>">💬 Gostei! Falar com a Fastcar</a><?php endif; ?>
<?php endif; ?>
</main>
<footer><strong>FASTCAR SOLUTIONS</strong> · CNPJ 66.934.500/0001-09<br>Av. Sagitário, 138 — Alphaville Conde II, Barueri/SP</footer>
</body>
</html>
