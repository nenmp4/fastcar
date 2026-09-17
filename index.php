<?php
/**
 * index.php — página pública institucional da Fastcar, servida na raiz do
 * domínio (fastcar.solutions), 17/09/2026: "faz pagina publica fastcar
 * solutions para verificação no google" — pedido pra dar suporte à
 * verificação do Google Business Profile (ficha da empresa no Google Maps/
 * Busca), que cruza nome/endereço/telefone do perfil cadastrado com o que
 * aparece num site real — até então o domínio só tinha
 * sistema.fastcar.solutions (CRM, atrás de login) e o wizard de documentos
 * (público mas com noindex/token, nunca feito pra ser achado/indexado).
 *
 * De propósito NÃO depende de banco/config nenhum (nunca require db.php) —
 * é a única página do projeto pensada pra ficar sempre no ar mesmo que o
 * banco ou alguma integração externa esteja com problema; conteúdo e
 * contato (WhatsApp/CNPJ/endereço) são os mesmos já usados no rodapé do
 * contrato/wizard (includes/contratos_pdf.php, public/documentos.php),
 * nunca inventados.
 *
 * ⚠️ Só fica acessível em https://fastcar.solutions/ depois de 3 passos
 * manuais fora do código (sem acesso SSH/DNS daqui pra fazer isso):
 * 1) registro DNS (A/CNAME) do domínio APEX fastcar.solutions apontando
 *    pra VPS (hoje só sistema.fastcar.solutions tem registro confirmado,
 *    ver CLAUDE.md pendência #1);
 * 2) nginx: adicionar "fastcar.solutions www.fastcar.solutions" ao
 *    server_name existente (mesmo root /var/www/fastcar, mesma app —
 *    não precisa de vhost novo, só mais nomes no já existente);
 * 3) certbot: expandir o certificado SSL pra cobrir os nomes novos
 *    (`certbot --nginx -d sistema.fastcar.solutions -d fastcar.solutions
 *    -d www.fastcar.solutions`, ou como já for gerenciado na VPS).
 */

$whatsappNumero = '5511958347764'; // Z-API "FastCar | JEAN", já é o canal oficial de entrada do funil
$whatsappTexto = rawurlencode('Olá! Tenho um veículo financiado e quero saber mais sobre a compra pela Fastcar.');
$whatsappLink = "https://wa.me/{$whatsappNumero}?text={$whatsappTexto}";
$anoAtual = date('Y');
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Fastcar Solutions — Compra de veículos financiados</title>
<meta name="description" content="A Fastcar compra seu carro, moto, caminhão, caminhonete ou jet ski ainda financiado — assumimos o financiamento e fazemos uma proposta justa pelo seu veículo. Fale com a gente pelo WhatsApp.">
<link rel="canonical" href="https://fastcar.solutions/">
<meta property="og:type" content="website">
<meta property="og:title" content="Fastcar Solutions — Compra de veículos financiados">
<meta property="og:description" content="Compramos veículos ainda em financiamento — carro, moto, caminhão, caminhonete e jet ski. Avaliação rápida pelo WhatsApp.">
<meta property="og:url" content="https://fastcar.solutions/">
<meta property="og:image" content="https://fastcar.solutions/public/assets/logo.png">
<meta property="og:locale" content="pt_BR">
<link rel="icon" type="image/png" href="/public/assets/favicon.png">
<script type="application/ld+json">
{
    "@context": "https://schema.org",
    "@type": "LocalBusiness",
    "name": "Fastcar Solutions",
    "url": "https://fastcar.solutions/",
    "logo": "https://fastcar.solutions/public/assets/logo.png",
    "image": "https://fastcar.solutions/public/assets/logo.png",
    "telephone": "+5511958347764",
    "email": "contato@fastcar.solutions",
    "taxID": "66.934.500/0001-09",
    "description": "Compra de veículos ainda em financiamento — carro, moto, caminhão, caminhonete e jet ski.",
    "address": {
        "@type": "PostalAddress",
        "streetAddress": "Av. Sagitário, 138 — Sala 1003, 10º andar, Torre City (Torre 2), Complexo Alpha Square Offices",
        "addressLocality": "Barueri",
        "addressRegion": "SP",
        "postalCode": "06473-073",
        "addressCountry": "BR"
    }
}
</script>
<style>
:root {
    --navy: #151722;
    --navy-2: #101d40;
    --blue: #2f6fed;
    --blue-dark: #1a4fc4;
    --texto: #1c1e29;
}
* { box-sizing: border-box; }
body {
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
    background: #f4f5f9;
    color: var(--texto);
    margin: 0;
}
.marca {
    text-align: center;
    padding: 48px 16px 40px;
    background: linear-gradient(135deg, var(--navy) 0%, var(--navy-2) 100%);
    color: #fff;
}
.marca img { max-height: 72px; margin-bottom: 10px; }
.marca .logotipo { font-size: 32px; font-weight: 700; letter-spacing: .2px; }
.marca .logotipo b { color: var(--blue); }
.marca .subtitulo { font-size: 13px; color: #aab4d4; letter-spacing: .5px; text-transform: uppercase; margin-top: 4px; }
.marca .headline { font-size: 19px; color: #dfe4f5; max-width: 560px; margin: 22px auto 0; line-height: 1.5; }
.btn-whats {
    display: inline-block;
    margin-top: 26px;
    padding: 14px 30px;
    background: linear-gradient(135deg, var(--blue) 0%, var(--blue-dark) 100%);
    color: #fff;
    text-decoration: none;
    border-radius: 10px;
    font-size: 16px;
    font-weight: 600;
}
.wrap { max-width: 720px; margin: 0 auto; padding: 44px 20px 60px; }
h2 { font-size: 21px; margin: 0 0 14px; }
.card { background: #fff; border-radius: 14px; padding: 26px 28px; margin-bottom: 22px; box-shadow: 0 8px 24px rgba(10,18,41,.08); }
.card p { line-height: 1.65; font-size: 15px; color: #3a3d4d; }
.veiculos { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 14px; }
.veiculos span { background: #e8f0fc; color: var(--blue-dark); font-size: 13.5px; font-weight: 600; padding: 6px 14px; border-radius: 20px; }
.passos { list-style: none; margin: 14px 0 0; padding: 0; counter-reset: passo; }
.passos li { counter-increment: passo; padding: 10px 0 10px 42px; position: relative; font-size: 15px; color: #3a3d4d; }
.passos li::before {
    content: counter(passo);
    position: absolute; left: 0; top: 8px;
    width: 26px; height: 26px; border-radius: 50%;
    background: var(--blue); color: #fff; font-size: 13px; font-weight: 700;
    display: flex; align-items: center; justify-content: center;
}
.contato-grid { display: grid; gap: 10px; font-size: 14.5px; color: #3a3d4d; }
.contato-grid a { color: var(--blue-dark); text-decoration: none; font-weight: 600; }
.rodape-empresa {
    text-align: center;
    font-size: 11.5px;
    color: #6b7aa0;
    line-height: 1.7;
    margin-top: 10px;
    padding: 18px 16px 4px;
    border-top: 2px solid transparent;
    border-image: linear-gradient(90deg, transparent, var(--blue) 50%, transparent) 1;
}
.rodape-empresa strong { color: var(--blue-dark); font-size: 12.5px; letter-spacing: .02em; }
</style>
</head>
<body>

<div class="marca">
    <img src="/public/assets/logo.png" alt="Fastcar" onerror="this.style.display='none'">
    <div class="logotipo">Fast<b>Car</b></div>
    <div class="subtitulo">Soluções Financeiras</div>
    <p class="headline">Compramos seu veículo ainda financiado. Assumimos a dívida e fazemos uma proposta justa — sem burocracia, direto pelo WhatsApp.</p>
    <a class="btn-whats" href="<?= htmlspecialchars($whatsappLink, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">💬 Falar no WhatsApp</a>
</div>

<div class="wrap">
    <div class="card">
        <h2>O que fazemos</h2>
        <p>A Fastcar compra veículos que ainda estão em financiamento — o proprietário quer vender, mas o carro (ou moto, caminhão, caminhonete, van, jet ski) segue com parcelas em aberto. A gente avalia o veículo e a situação do financiamento, e assume essa dívida na negociação.</p>
        <div class="veiculos">
            <span>🚗 Carro</span>
            <span>🏍️ Moto</span>
            <span>🚚 Caminhão</span>
            <span>🛻 Caminhonete</span>
            <span>🚐 Van</span>
            <span>🚤 Jet ski</span>
        </div>
    </div>

    <div class="card">
        <h2>Como funciona</h2>
        <ol class="passos">
            <li>Você chama a gente no WhatsApp e conta sobre o seu veículo (banco, valor da parcela, quanto falta pagar).</li>
            <li>Nossa equipe avalia as informações e, se fizer sentido, um consultor entra em contato pra conversar melhor.</li>
            <li>Alinhamos as condições da compra — valor, prazo de quitação do financiamento e entrega do veículo.</li>
            <li>Assinamos o contrato e formalizamos a transferência com segurança.</li>
        </ol>
    </div>

    <div class="card">
        <h2>Fale com a gente</h2>
        <div class="contato-grid">
            <div>💬 WhatsApp: <a href="<?= htmlspecialchars($whatsappLink, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">(11) 9 5834-7764</a></div>
            <div>✉️ E-mail: <a href="mailto:contato@fastcar.solutions">contato@fastcar.solutions</a></div>
            <div>📍 Av. Sagitário, 138 — Sala 1003, 10º andar, Torre City (Torre 2), Complexo Alpha Square Offices, Alphaville Conde II, Barueri/SP — CEP 06473-073</div>
        </div>
    </div>

    <footer class="rodape-empresa">
        <strong>FASTCAR SOLUTIONS</strong> — CNPJ 66.934.500/0001-09<br>
        Av. Sagitário, 138 — Sala 1003, 10º andar, Torre City (Torre 2), Complexo Alpha Square Offices<br>
        Alphaville Conde II, Barueri/SP — CEP 06473-073<br>
        © <?= (int)$anoAtual ?> Fastcar Solutions. Todos os direitos reservados.
    </footer>
</div>

</body>
</html>
