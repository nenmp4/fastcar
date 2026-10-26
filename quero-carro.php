<?php
/**
 * Formulário público de entrada do lead de VENDAS (26/10/2026) — destino
 * dos anúncios ("só vem pro WhatsApp se ele passar pelo formulário").
 * Registra no CRM igual ao lead que a IA qualifica
 * (includes/vendas_formulario.php::registrarFormularioVenda()). Só quem
 * tem a entrada mínima recebe o botão do WhatsApp; o Pixel da Meta marca
 * "Lead" só pra esses, pra Meta aprender a entregar o anúncio pra quem
 * tem entrada de verdade.
 */
header('X-Robots-Tag: noindex, nofollow, noarchive', true);
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/auditoria.php';
require_once __DIR__ . '/includes/vendas_formulario.php';
startSecureSession();

$utm = [];
foreach (['utm_source', 'utm_campaign', 'utm_content'] as $k) {
    $v = trim((string)($_POST[$k] ?? $_GET[$k] ?? ''));
    if ($v !== '') $utm[$k] = mb_substr($v, 0, 200);
}
$pixelId = preg_replace('/\D/', '', (string)getConfig('meta_pixel_id'));
$numeroZap = normalizarTelefone((string)getConfig('vendas_whatsapp_numero'));

$erro = '';
$res = null;
$f = $_POST;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Honeypot (campo escondido que robô preenche) + limite por IP por hora.
    $ipChave = 'formvenda_ip_' . substr(hash('sha256', auditoriaClienteIp()), 0, 16) . '_' . date('YmdH');
    $tentativas = (int)getConfig($ipChave);
    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        $erro = 'Sua sessão expirou. Recarregue a página e tente de novo.';
    } elseif (trim((string)($_POST['site'] ?? '')) !== '') {
        $erro = 'Não foi possível enviar.';
    } elseif ($tentativas >= 8) {
        $erro = 'Muitas tentativas. Tente de novo daqui a pouco.';
    } else {
        setConfig($ipChave, (string)($tentativas + 1));
        $semEntrada = ($_POST['sem_entrada'] ?? '') === '1';
        try {
            $res = registrarFormularioVenda([
                'nome' => $_POST['nome'] ?? '',
                'telefone' => $_POST['telefone'] ?? '',
                'tipo' => $_POST['tipo'] ?? '',
                'uso' => $_POST['uso'] ?? '',
                'entrada' => $semEntrada ? 0.0 : valorMonetario($_POST['entrada'] ?? null),
                'parcela' => valorMonetario($_POST['parcela'] ?? null),
                'visita' => $_POST['visita'] ?? '',
            ] + $utm);
            if (!$res['ok']) { $erro = $res['erro']; $res = null; }
        } catch (Throwable $e) {
            error_log('quero-carro: ' . $e->getMessage());
            $erro = 'Tivemos uma instabilidade. Tente de novo em instantes.';
            $res = null;
        }
    }
}
$fmt = fn($v) => 'R$ ' . number_format((float)$v, 0, ',', '.');
$sel = fn($campo, $valor) => (($f[$campo] ?? '') === $valor) ? 'checked' : '';
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Fastcar — seu carro na promissória</title>
<meta property="og:title" content="Fastcar — carro e moto na promissória">
<meta property="og:description" content="Sem consulta ao SPC/Serasa. Preencha em 1 minuto e veja as opções que cabem no seu bolso.">
<?php if ($pixelId !== ''): ?>
<script>
!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');
fbq('init', '<?= e($pixelId) ?>');
fbq('track', 'PageView');
<?php if ($res && $res['qualificado']): ?>fbq('track', 'Lead');<?php endif; ?>
</script>
<?php endif; ?>
<style>
:root { --navy:#151722; --azul:#2f6fed; --borda:#e5e7eb; }
* { box-sizing:border-box; }
body { margin:0; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif; background:#f4f4f7; color:#151722; }
header { background:var(--navy); padding:16px; text-align:center; }
header img { max-height:44px; }
header .txt { color:#fff; font-weight:700; font-size:22px; }
main { max-width:560px; margin:0 auto; padding:16px; }
h1 { font-size:24px; margin:8px 0 6px; }
.sub { color:#444; margin:0 0 16px; }
.card { background:#fff; border:1px solid var(--borda); border-radius:14px; padding:18px; box-shadow:0 2px 8px rgba(0,0,0,.05); }
label.t { display:block; font-weight:700; margin:16px 0 6px; }
input[type=text], input[type=tel] { width:100%; font-size:17px; padding:12px; border:1px solid #cfd2da; border-radius:10px; }
.opcoes { display:flex; gap:8px; flex-wrap:wrap; }
.opcoes label { flex:1 1 140px; border:1px solid #cfd2da; border-radius:10px; padding:12px; font-size:16px; cursor:pointer; background:#fff; min-height:48px; display:flex; align-items:center; gap:8px; }
.opcoes input { width:20px; height:20px; }
.check { display:flex; gap:8px; align-items:center; margin-top:8px; font-size:15px; }
.check input { width:20px; height:20px; }
button { width:100%; margin-top:20px; background:var(--azul); color:#fff; border:none; border-radius:12px; padding:16px; font-size:18px; font-weight:700; cursor:pointer; }
.erro { background:#fdecec; color:#b42318; border-radius:10px; padding:12px; margin-bottom:12px; }
.btn-zap { display:block; text-align:center; background:#25d366; color:#fff; text-decoration:none; font-weight:700; font-size:18px; padding:16px; border-radius:12px; margin-top:16px; }
.oculto { position:absolute; left:-9999px; }
small { color:#666; }
.progresso { height:6px; background:#eceef3; border-radius:99px; overflow:hidden; display:none; }
.barra { height:100%; width:0; background:var(--azul); transition:width .25s; }
.passo-num { font-size:13px; color:#666; margin:8px 0 0; display:none; }
.nav-passos { display:none; gap:8px; }
.nav-passos button { flex:1; }
.nav-passos .voltar { background:#eceef3; color:var(--navy); flex:0 0 35%; }
/* modo wizard (só com JS ligado — sem JS o formulário aparece inteiro) */
.wizard .progresso, .wizard .passo-num { display:block; }
.wizard .nav-passos { display:flex; }
.wizard .passo { display:none; }
.wizard .passo.atual { display:block; animation:entra .2s ease; }
@keyframes entra { from { opacity:0; transform:translateX(12px); } to { opacity:1; transform:none; } }
footer { text-align:center; font-size:12px; color:#666; padding:20px; border-top:3px solid var(--azul); background:#fff; margin-top:30px; }
</style>
</head>
<body>
<header><img src="/public/assets/logo.png" alt="Fastcar" onerror="this.outerHTML='<span class=&quot;txt&quot;>FastCar</span>'"></header>
<main>
<?php if ($res && $res['qualificado']):
    $codigo = vendaFormularioCodigo((int)$res['venda_id']);
    $primeiro = trim(explode(' ', trim((string)($f['nome'] ?? '')))[0]);
    $texto = 'Olá! Acabei de preencher o formulário da Fastcar (cód. ' . $codigo . ').';
?>
    <div class="card">
        <h1>✅ Pronto<?= $primeiro !== '' ? ', ' . e($primeiro) : '' ?>!</h1>
        <p>Você tem as condições pra comprar na Fastcar. Toque no botão e fale com a gente no WhatsApp — um vendedor já vai te atender com as opções.</p>
        <?php if ($numeroZap): ?>
            <a class="btn-zap" href="https://wa.me/<?= e($numeroZap) ?>?text=<?= e(rawurlencode($texto)) ?>">💬 Falar com a Fastcar no WhatsApp</a>
        <?php else: ?>
            <p><strong>Seu cadastro foi recebido.</strong></p>
        <?php endif; ?>
    </div>
<?php elseif ($res): ?>
    <div class="card">
        <h1>Obrigado pelo interesse!</h1>
        <p>No momento, nossas condições começam a partir de <strong><?= e($fmt($res['minimo'])) ?> de entrada</strong> pra esse tipo de veículo.</p>
        <?php if (in_array($f['tipo'] ?? '', ['carro', 'utilitario'], true)): ?>
            <p>Uma <strong>moto</strong> te atende? Pra moto, a entrada começa em <?= e($fmt(5000)) ?>.</p>
            <a class="btn-zap" style="background:var(--azul)" href="<?= e(vendaFormularioUrl($utm)) ?>">Ver opção de moto</a>
        <?php endif; ?>
        <p><small>Quando tiver a entrada, é só voltar aqui e preencher de novo.</small></p>
    </div>
<?php else: ?>
    <h1>🚗 Seu carro ou moto na promissória</h1>
    <p class="sub">Sem consulta ao SPC/Serasa. Preencha em 1 minuto e veja as opções que cabem no seu bolso.</p>
    <?php if ($erro): ?><div class="erro"><?= e($erro) ?></div><?php endif; ?>
    <form method="post" class="card" id="qc-form">
        <?= csrfField() ?>
        <?php foreach ($utm as $k => $v): ?><input type="hidden" name="<?= e($k) ?>" value="<?= e($v) ?>"><?php endforeach; ?>
        <input type="text" name="site" class="oculto" tabindex="-1" autocomplete="off" aria-hidden="true">

        <div class="progresso"><div class="barra" id="qc-barra"></div></div>
        <p class="passo-num" id="qc-num"></p>

        <div class="passo" data-passo="1">
            <label class="t">O que você procura?</label>
            <div class="opcoes">
                <label><input type="radio" name="tipo" value="carro" required <?= $sel('tipo', 'carro') ?>> 🚗 Carro</label>
                <label><input type="radio" name="tipo" value="utilitario" <?= $sel('tipo', 'utilitario') ?>> 🛻 Utilitário <small>(picape, van, furgão)</small></label>
                <label><input type="radio" name="tipo" value="moto" <?= $sel('tipo', 'moto') ?>> 🏍️ Moto</label>
            </div>
        </div>

        <div class="passo" data-passo="2">
            <label class="t">Pra que vai usar?</label>
            <div class="opcoes">
                <?php foreach (VENDA_FORM_USOS as $k => $r): ?>
                    <label><input type="radio" name="uso" value="<?= e($k) ?>" required <?= $sel('uso', $k) ?>> <?= e($r) ?></label>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="passo" data-passo="3">
            <label class="t" for="entrada">Quanto você tem de entrada? (R$)</label>
            <input type="text" id="entrada" name="entrada" inputmode="decimal" placeholder="Ex: 10.000" value="<?= e($f['entrada'] ?? '') ?>">
            <label class="check"><input type="checkbox" name="sem_entrada" value="1" <?= ($f['sem_entrada'] ?? '') === '1' ? 'checked' : '' ?>
                onchange="var e=document.getElementById('entrada');e.disabled=this.checked;if(this.checked)e.value='';"> Não tenho entrada</label>
        </div>

        <div class="passo" data-passo="4">
            <label class="t" for="parcela">Quanto cabe de parcela por mês? (R$)</label>
            <input type="text" id="parcela" name="parcela" inputmode="decimal" placeholder="Ex: 900" value="<?= e($f['parcela'] ?? '') ?>">
            <label class="t" for="visita">Melhor dia e horário pra vir conhecer a loja <small>(opcional)</small></label>
            <input type="text" id="visita" name="visita" placeholder="Ex: sábado de manhã" value="<?= e($f['visita'] ?? '') ?>">
        </div>

        <div class="passo" data-passo="5">
            <label class="t" for="nome">Seu nome</label>
            <input type="text" id="nome" name="nome" required autocomplete="name" value="<?= e($f['nome'] ?? '') ?>">
            <label class="t" for="telefone">Seu WhatsApp (com DDD)</label>
            <input type="tel" id="telefone" name="telefone" required inputmode="tel" autocomplete="tel" placeholder="(11) 91234-5678" value="<?= e($f['telefone'] ?? '') ?>">
        </div>

        <div class="nav-passos">
            <button type="button" class="voltar" id="qc-voltar">← Voltar</button>
            <button type="button" id="qc-proximo">Próximo →</button>
        </div>
        <button type="submit" id="qc-enviar">Ver minhas opções</button>
        <p><small>Seus dados são usados só pra avaliar sua proposta. Ao enviar, você concorda com nossa
            <a href="/privacidade.php" target="_blank">política de privacidade</a>.</small></p>
    </form>
<?php endif; ?>
</main>
<footer><strong>FASTCAR SOLUTIONS</strong> · CNPJ 66.934.500/0001-09<br>Av. Sagitário, 138 — Alphaville Conde II, Barueri/SP</footer>
<script>
(function(){
    var c = document.querySelector('input[name=sem_entrada]');
    if (c && c.checked) document.getElementById('entrada').disabled = true;
    var form = document.getElementById('qc-form');
    if (!form) return;
    var passos = Array.prototype.slice.call(form.querySelectorAll('.passo'));
    var atual = 0, total = passos.length;
    var bVoltar = document.getElementById('qc-voltar'), bProx = document.getElementById('qc-proximo'), bEnviar = document.getElementById('qc-enviar');
    form.classList.add('wizard');
    form.querySelector('.nav-passos').appendChild(bEnviar);
    function mostrar(i) {
        atual = i;
        passos.forEach(function (p, k) { p.classList.toggle('atual', k === i); });
        document.getElementById('qc-barra').style.width = Math.round((i + 1) / total * 100) + '%';
        document.getElementById('qc-num').textContent = 'Passo ' + (i + 1) + ' de ' + total;
        bVoltar.style.display = i === 0 ? 'none' : '';
        var ultimo = i === total - 1;
        bProx.style.display = ultimo ? 'none' : '';
        bEnviar.style.display = ultimo ? '' : 'none';
        var foco = passos[i].querySelector('input[type=text]:not([disabled]),input[type=tel]');
        if (foco && i > 1) setTimeout(function () { foco.focus(); }, 50);
    }
    function valido(i) {
        var p = passos[i];
        if (p.querySelector('input[type=radio]') && !p.querySelector('input[type=radio]:checked')) { alert('Escolha uma opção pra continuar.'); return false; }
        if (p.dataset.passo === '3' && !c.checked && document.getElementById('entrada').value.trim() === '') {
            alert('Informe quanto tem de entrada, ou marque "Não tenho entrada".'); return false;
        }
        var campos = p.querySelectorAll('input[required]:not([type=radio])');
        for (var k = 0; k < campos.length; k++) { if (!campos[k].reportValidity()) return false; }
        return true;
    }
    bProx.addEventListener('click', function () { if (valido(atual)) mostrar(atual + 1); });
    bVoltar.addEventListener('click', function () { if (atual > 0) mostrar(atual - 1); });
    // Escolher uma opção já avança sozinho (passos 1 e 2).
    form.querySelectorAll('.passo input[type=radio]').forEach(function (r) {
        r.addEventListener('change', function () { setTimeout(function () { mostrar(atual + 1); }, 180); });
    });
    // Enter num campo de texto vai pro próximo passo em vez de enviar.
    form.addEventListener('keydown', function (ev) {
        if (ev.key === 'Enter' && atual < total - 1) { ev.preventDefault(); bProx.click(); }
    });
    form.addEventListener('submit', function (ev) { if (!valido(atual)) ev.preventDefault(); });
    mostrar(0);
})();
</script>

</body>
</html>
