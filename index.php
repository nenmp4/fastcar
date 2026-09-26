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
 * No ar em produção desde 17/09/2026 (DNS+nginx+certbot expandidos pro
 * domínio apex, feito manualmente na VPS — ver CLAUDE.md pendência #1).
 *
 * Visual refeito no mesmo dia ("poderia ficar mais bonita essa pagina") —
 * 1ª versão era funcional mas básica (cards simples, sem hierarquia visual
 * forte). Reforçado: hero com brilho radial, tira de confiança, cards de
 * veículo com ícone em destaque, timeline conectada em "Como funciona",
 * botão flutuante de WhatsApp sempre visível ao rolar a página — tudo só
 * CSS, nenhum JS novo, mesmo espírito leve do resto do projeto.
 *
 * De propósito NÃO depende de banco/config nenhum (nunca require db.php) —
 * é a única página do projeto pensada pra ficar sempre no ar mesmo que o
 * banco ou alguma integração externa esteja com problema; conteúdo e
 * contato (WhatsApp/CNPJ/endereço) são os mesmos já usados no rodapé do
 * contrato/wizard (includes/contratos_pdf.php, public/documentos.php),
 * nunca inventados.
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
<meta name="facebook-domain-verification" content="gy5w7l1u7vqmw515gd6x6vifl42isu" />
<title>Fastcar Solutions — Compra de veículos financiados</title>
<meta name="description" content="A Fastcar compra seu carro, moto, caminhão, caminhonete ou jet ski ainda financiado — assumimos o financiamento e fazemos uma proposta justa pelo seu veículo. Fale com a gente pelo WhatsApp.">
<meta name="robots" content="index, follow">
<meta name="author" content="Fastcar Solutions">
<meta name="theme-color" content="#151722">
<link rel="canonical" href="https://fastcar.solutions/">
<!-- Open Graph — usado pelo preview de link no WhatsApp/Facebook/Telegram
     quando alguém manda esse link numa conversa (pedido direto: "coloca
     seo para google meta dados whasApp"). og:image aponta pro ícone
     quadrado 512x512 já gerado por includes/marca.php (fundo sólido da
     marca, nunca transparente) — a logo do cabeçalho (public/assets/logo.png)
     é retangular/com fundo transparente, cortaria estranho no card de
     preview do WhatsApp, que espera algo próximo de quadrado. -->
<meta property="og:type" content="website">
<meta property="og:site_name" content="Fastcar Solutions">
<meta property="og:title" content="Fastcar Solutions — Compra de veículos financiados">
<meta property="og:description" content="Compramos veículos ainda em financiamento — carro, moto, caminhão, caminhonete e jet ski. Avaliação rápida pelo WhatsApp.">
<meta property="og:url" content="https://fastcar.solutions/">
<meta property="og:image" content="https://fastcar.solutions/admin/assets/img/icon-512.png">
<meta property="og:image:width" content="512">
<meta property="og:image:height" content="512">
<meta property="og:image:type" content="image/png">
<meta property="og:locale" content="pt_BR">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="Fastcar Solutions — Compra de veículos financiados">
<meta name="twitter:description" content="Compramos veículos ainda em financiamento — carro, moto, caminhão, caminhonete e jet ski. Avaliação rápida pelo WhatsApp.">
<meta name="twitter:image" content="https://fastcar.solutions/admin/assets/img/icon-512.png">
<link rel="icon" type="image/png" href="/public/assets/favicon.png">
<script type="application/ld+json">
{
    "@context": "https://schema.org",
    "@type": "LocalBusiness",
    "name": "Fastcar Solutions",
    "url": "https://fastcar.solutions/",
    "logo": "https://fastcar.solutions/public/assets/logo.png",
    "image": "https://fastcar.solutions/admin/assets/img/icon-512.png",
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
<!-- FAQPage — mesmo conteúdo textual da seção "Perguntas frequentes" abaixo,
     nunca deve dessincronizar: se o texto de alguma pergunta mudar, mudar
     aqui também. -->
<script type="application/ld+json">
{
    "@context": "https://schema.org",
    "@type": "FAQPage",
    "mainEntity": [
        {"@type": "Question", "name": "A Fastcar realmente assume o financiamento do meu veículo?", "acceptedAnswer": {"@type": "Answer", "text": "Sim — é exatamente esse o nosso foco: comprar veículos que ainda estão financiados, assumindo o saldo devedor junto ao banco como parte da negociação. Você não precisa quitar o financiamento antes de vender."}},
        {"@type": "Question", "name": "Preciso estar com as parcelas em dia para vender?", "acceptedAnswer": {"@type": "Answer", "text": "Não. Recebemos veículos com parcelas em dia ou em atraso — a situação do financiamento é só uma das informações que avaliamos pra montar a proposta."}},
        {"@type": "Question", "name": "Quais documentos preciso enviar?", "acceptedAnswer": {"@type": "Answer", "text": "Documento de identidade com foto (CNH ou RG), comprovante de endereço, contrato de financiamento do veículo com o banco e o CRLV. Depois do primeiro contato, mandamos um link pra você enviar tudo com calma, direto pelo celular."}},
        {"@type": "Question", "name": "Como é definido o valor da proposta?", "acceptedAnswer": {"@type": "Answer", "text": "Levamos em conta o veículo (modelo, ano, estado de conservação) e a situação do financiamento (valor da parcela, quanto ainda falta pagar). A proposta final é sempre conversada e aprovada com você antes de qualquer contrato."}},
        {"@type": "Question", "name": "Quanto tempo leva o processo?", "acceptedAnswer": {"@type": "Answer", "text": "Varia conforme a análise do veículo e do financiamento, mas o atendimento inicial é rápido — em geral um consultor já entra em contato no mesmo dia depois da conversa no WhatsApp."}},
        {"@type": "Question", "name": "A negociação é formalizada com contrato?", "acceptedAnswer": {"@type": "Answer", "text": "Sim. Depois de alinhadas as condições, formalizamos tudo em contrato assinado por ambas as partes antes da transferência do veículo."}},
        {"@type": "Question", "name": "Vocês compram só carro?", "acceptedAnswer": {"@type": "Answer", "text": "Não — também compramos moto, caminhão, caminhonete, van e jet ski ainda financiados."}}
    ]
}
</script>
<style>
:root {
    --navy: #151722;
    --navy-2: #0d1024;
    --blue: #2f6fed;
    --blue-dark: #1a4fc4;
    --blue-light: #6b9bff;
    --texto: #1c1e29;
    --texto-fraco: #5b5f72;
}
* { box-sizing: border-box; }
html { scroll-behavior: smooth; }
body {
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
    background: #f4f5f9;
    color: var(--texto);
    margin: 0;
}

/* ── Hero ─────────────────────────────────────────────────────────── */
.hero {
    position: relative;
    text-align: center;
    padding: 58px 16px 96px;
    background: radial-gradient(720px 420px at 50% -10%, rgba(47,111,237,.35), transparent 60%),
                linear-gradient(160deg, var(--navy) 0%, var(--navy-2) 100%);
    color: #fff;
    overflow: hidden;
}
.hero::after {
    content: '';
    position: absolute; left: 0; right: 0; bottom: -1px; height: 48px;
    background: #f4f5f9;
    border-radius: 50% 50% 0 0 / 100% 100% 0 0;
}
.hero-inner { position: relative; z-index: 1; }
.hero img.logo { max-height: 76px; margin-bottom: 10px; filter: drop-shadow(0 6px 18px rgba(0,0,0,.35)); }
.hero .logotipo { font-size: 34px; font-weight: 800; letter-spacing: .2px; }
.hero .logotipo b { color: var(--blue-light); }
.hero .subtitulo { font-size: 12.5px; color: #9fb0e0; letter-spacing: 2px; text-transform: uppercase; margin-top: 6px; font-weight: 600; }
.hero .headline {
    font-size: 22px; font-weight: 600; color: #eef1fb;
    max-width: 600px; margin: 28px auto 0; line-height: 1.5;
}
.hero .subhead { font-size: 15px; color: #aab4d4; max-width: 520px; margin: 12px auto 0; line-height: 1.6; }
.btn-whats {
    display: inline-flex; align-items: center; gap: 9px;
    margin-top: 30px;
    padding: 15px 32px;
    background: linear-gradient(135deg, var(--blue) 0%, var(--blue-dark) 100%);
    color: #fff;
    text-decoration: none;
    border-radius: 12px;
    font-size: 16.5px;
    font-weight: 700;
    box-shadow: 0 12px 28px -8px rgba(47,111,237,.65);
    transition: transform .15s ease, box-shadow .15s ease;
}
.btn-whats:hover { transform: translateY(-2px); box-shadow: 0 16px 34px -8px rgba(47,111,237,.8); }
.btn-whats svg { width: 20px; height: 20px; flex: none; }

/* Tira de confiança */
.confianca {
    display: flex; flex-wrap: wrap; justify-content: center; gap: 10px 22px;
    margin-top: 34px; padding-top: 26px; border-top: 1px solid rgba(255,255,255,.12);
    max-width: 560px; margin-left: auto; margin-right: auto;
}
.confianca span { font-size: 13px; color: #c3cdec; font-weight: 600; display: flex; align-items: center; gap: 6px; }

/* ── Conteúdo ─────────────────────────────────────────────────────── */
.wrap { max-width: 760px; margin: -48px auto 0; padding: 0 20px 60px; position: relative; z-index: 2; }
.secao-titulo { text-align: center; margin: 56px 0 24px; }
.secao-titulo:first-of-type { margin-top: 0; }
.secao-titulo h2 { font-size: 24px; margin: 0 0 8px; letter-spacing: -.2px; }
.secao-titulo p { color: var(--texto-fraco); font-size: 15px; margin: 0; max-width: 480px; margin-left: auto; margin-right: auto; line-height: 1.55; }

.card {
    background: #fff; border-radius: 16px; padding: 30px 32px;
    box-shadow: 0 10px 30px rgba(10,18,41,.07), 0 1px 2px rgba(10,18,41,.05);
}
.card p { line-height: 1.7; font-size: 15px; color: #3a3d4d; margin: 0; }

/* Grade de veículos */
.veiculos-grade { display: grid; grid-template-columns: repeat(auto-fit, minmax(110px, 1fr)); gap: 12px; margin-top: 22px; }
.veiculo-item {
    text-align: center; padding: 18px 8px; border-radius: 12px;
    background: #f6f8fd; border: 1px solid #e9edf7;
    transition: transform .15s ease, box-shadow .15s ease, border-color .15s ease;
}
.veiculo-item:hover { transform: translateY(-3px); box-shadow: 0 10px 20px rgba(47,111,237,.12); border-color: #cfdcfa; }
.veiculo-item .emoji { font-size: 28px; display: block; margin-bottom: 8px; }
.veiculo-item span.nome { font-size: 13px; font-weight: 700; color: var(--blue-dark); }

/* Timeline de passos */
.timeline { list-style: none; margin: 26px 0 0; padding: 0; position: relative; }
.timeline::before {
    content: ''; position: absolute; left: 19px; top: 8px; bottom: 8px; width: 2px;
    background: linear-gradient(var(--blue), #dbe4fb);
}
.timeline li { position: relative; padding: 4px 0 26px 56px; }
.timeline li:last-child { padding-bottom: 0; }
.timeline li::before {
    content: counter(passo); counter-increment: passo;
    position: absolute; left: 0; top: 0;
    width: 40px; height: 40px; border-radius: 50%;
    background: linear-gradient(135deg, var(--blue) 0%, var(--blue-dark) 100%); color: #fff;
    font-size: 15px; font-weight: 700;
    display: flex; align-items: center; justify-content: center;
    box-shadow: 0 6px 14px rgba(47,111,237,.35);
}
.timeline { counter-reset: passo; }
.timeline strong { display: block; font-size: 15.5px; margin-bottom: 3px; }
.timeline span { font-size: 14.5px; color: var(--texto-fraco); line-height: 1.6; }

/* FAQ — <details>/<summary> nativos, sem JS nenhum (mesmo espírito leve
   do resto da página: nada que precise de script pra funcionar). */
.faq-item { border-bottom: 1px solid #ececf3; }
.faq-item:last-child { border-bottom: none; }
.faq-item summary {
    cursor: pointer; list-style: none;
    display: flex; align-items: center; justify-content: space-between; gap: 16px;
    padding: 18px 4px; font-size: 15.5px; font-weight: 700; color: var(--texto);
}
.faq-item summary::-webkit-details-marker { display: none; }
.faq-item summary::after {
    content: '+'; flex: none; font-size: 22px; font-weight: 400; color: var(--blue);
    transition: transform .18s ease;
}
.faq-item[open] summary::after { transform: rotate(45deg); }
.faq-item p { margin: 0 4px 18px; font-size: 14.5px; color: var(--texto-fraco); line-height: 1.65; }

/* Prévia do blog — cards curtos, o conteúdo de verdade mora em /blog/ */
.blog-grade { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; margin-top: 4px; }
.blog-item {
    display: block; padding: 16px 18px; border-radius: 12px;
    background: #f6f8fd; border: 1px solid #e9edf7; text-decoration: none;
    transition: transform .15s ease, box-shadow .15s ease;
}
.blog-item:hover { transform: translateY(-2px); box-shadow: 0 10px 20px rgba(47,111,237,.1); }
.blog-item strong { display: block; font-size: 14px; color: var(--texto); line-height: 1.4; margin-bottom: 6px; }
.blog-item span { font-size: 12.5px; color: var(--blue-dark); font-weight: 700; }
.blog-ver-todos { display: block; text-align: center; margin-top: 18px; font-size: 14px; font-weight: 700; color: var(--blue-dark); text-decoration: none; }
.blog-ver-todos:hover { text-decoration: underline; }

/* Contato */
.contato-grade { display: grid; gap: 14px; margin-top: 22px; }
.contato-item {
    display: flex; align-items: flex-start; gap: 14px;
    padding: 16px 18px; border-radius: 12px; background: #f6f8fd; border: 1px solid #e9edf7;
}
.contato-item .icone {
    flex: none; width: 40px; height: 40px; border-radius: 10px;
    background: linear-gradient(135deg, var(--blue) 0%, var(--blue-dark) 100%);
    display: flex; align-items: center; justify-content: center; font-size: 18px;
}
.contato-item .texto { font-size: 14.5px; color: #3a3d4d; line-height: 1.55; padding-top: 2px; }
.contato-item .texto strong { display: block; font-size: 12px; text-transform: uppercase; letter-spacing: .4px; color: var(--texto-fraco); margin-bottom: 2px; font-weight: 700; }
.contato-item a { color: var(--blue-dark); text-decoration: none; font-weight: 700; }
.contato-item a:hover { text-decoration: underline; }

/* Rodapé */
.rodape-empresa {
    text-align: center; font-size: 11.5px; color: #8a93b0; line-height: 1.8;
    margin-top: 50px; padding-top: 22px; border-top: 1px solid #e3e7f2;
}
.rodape-empresa strong { color: var(--blue-dark); font-size: 12.5px; letter-spacing: .02em; }

/* Botão flutuante de WhatsApp */
.flutuante {
    position: fixed; right: 20px; bottom: 20px; z-index: 50;
    width: 58px; height: 58px; border-radius: 50%;
    background: linear-gradient(135deg, #29c060, #1fa84f);
    display: flex; align-items: center; justify-content: center;
    box-shadow: 0 10px 24px rgba(31,168,79,.45);
    text-decoration: none; font-size: 27px;
    transition: transform .15s ease;
}
.flutuante:hover { transform: scale(1.07); }

@media (max-width: 520px) {
    .hero { padding: 44px 16px 84px; }
    .hero .headline { font-size: 19px; }
    .card { padding: 24px 20px; }
    .btn-whats { width: 100%; justify-content: center; }
}
</style>
</head>
<body>

<section class="hero">
    <div class="hero-inner">
        <img class="logo" src="/public/assets/logo.png" alt="Fastcar" onerror="this.style.display='none'">
        <div class="logotipo">Fast<b>Car</b></div>
        <div class="subtitulo">Soluções Financeiras</div>
        <p class="headline">Compramos seu veículo ainda financiado</p>
        <p class="subhead">Assumimos a dívida e fazemos uma proposta justa — sem burocracia, direto pelo WhatsApp.</p>
        <a class="btn-whats" href="<?= htmlspecialchars($whatsappLink, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">
            <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12.04 2C6.58 2 2.13 6.45 2.13 11.91c0 1.75.46 3.45 1.32 4.95L2.05 22l5.25-1.38a9.9 9.9 0 0 0 4.74 1.21h.01c5.46 0 9.91-4.45 9.91-9.91 0-2.65-1.03-5.14-2.9-7.01A9.82 9.82 0 0 0 12.04 2zm5.8 14.09c-.24.68-1.41 1.3-1.94 1.36-.5.07-1.03.1-1.65-.1-.38-.12-.87-.28-1.5-.55-2.63-1.14-4.35-3.8-4.48-3.98-.13-.17-1.07-1.42-1.07-2.71s.68-1.92.92-2.18c.24-.27.53-.34.71-.34l.5.01c.16 0 .38-.06.6.46.24.56.8 1.94.87 2.08.07.14.11.3.02.48-.09.17-.14.28-.27.43-.14.16-.29.35-.41.47-.14.14-.28.28-.12.55.16.28.72 1.19 1.55 1.93 1.07.95 1.97 1.25 2.24 1.39.28.14.44.12.6-.07.16-.19.68-.79.87-1.06.18-.27.36-.22.6-.13.24.09 1.55.73 1.81.86.27.13.44.2.51.31.07.11.07.65-.17 1.32z"/></svg>
            Falar no WhatsApp
        </a>
        <div class="confianca">
            <span>🔒 Negociação segura</span>
            <span>📄 Contrato formalizado</span>
            <span>⚡ Resposta rápida</span>
        </div>
    </div>
</section>

<div class="wrap">

    <div class="secao-titulo">
        <h2>O que fazemos</h2>
        <p>A gente compra veículos que ainda estão em financiamento — o proprietário quer vender, mas o veículo segue com parcelas em aberto. Avaliamos o veículo e a situação do financiamento, e assumimos essa dívida na negociação.</p>
    </div>
    <div class="card">
        <div class="veiculos-grade">
            <div class="veiculo-item"><span class="emoji">🚗</span><span class="nome">Carro</span></div>
            <div class="veiculo-item"><span class="emoji">🏍️</span><span class="nome">Moto</span></div>
            <div class="veiculo-item"><span class="emoji">🚚</span><span class="nome">Caminhão</span></div>
            <div class="veiculo-item"><span class="emoji">🛻</span><span class="nome">Caminhonete</span></div>
            <div class="veiculo-item"><span class="emoji">🚐</span><span class="nome">Van</span></div>
            <div class="veiculo-item"><span class="emoji">🚤</span><span class="nome">Jet ski</span></div>
        </div>
    </div>

    <div class="secao-titulo">
        <h2>Como funciona</h2>
        <p>Do primeiro contato até a assinatura, em 4 passos simples.</p>
    </div>
    <div class="card">
        <ol class="timeline">
            <li><strong>Fale com a gente no WhatsApp</strong><span>Conte sobre o seu veículo — banco, valor da parcela, quanto falta pagar.</span></li>
            <li><strong>Avaliação da equipe</strong><span>Analisamos as informações e, se fizer sentido, um consultor entra em contato pra conversar melhor.</span></li>
            <li><strong>Alinhamento das condições</strong><span>Definimos valor, prazo de quitação do financiamento e entrega do veículo.</span></li>
            <li><strong>Contrato e transferência</strong><span>Assinamos o contrato e formalizamos a transferência com segurança.</span></li>
        </ol>
    </div>

    <div class="secao-titulo">
        <h2>Perguntas frequentes</h2>
        <p>As dúvidas mais comuns de quem está pensando em vender.</p>
    </div>
    <div class="card">
        <details class="faq-item" open>
            <summary>A Fastcar realmente assume o financiamento do meu veículo?</summary>
            <p>Sim — é exatamente esse o nosso foco: comprar veículos que ainda estão financiados, assumindo o saldo devedor junto ao banco como parte da negociação. Você não precisa quitar o financiamento antes de vender.</p>
        </details>
        <details class="faq-item">
            <summary>Preciso estar com as parcelas em dia para vender?</summary>
            <p>Não. Recebemos veículos com parcelas em dia ou em atraso — a situação do financiamento é só uma das informações que avaliamos pra montar a proposta.</p>
        </details>
        <details class="faq-item">
            <summary>Quais documentos preciso enviar?</summary>
            <p>Documento de identidade com foto (CNH ou RG), comprovante de endereço, contrato de financiamento do veículo com o banco e o CRLV. Depois do primeiro contato, mandamos um link pra você enviar tudo com calma, direto pelo celular.</p>
        </details>
        <details class="faq-item">
            <summary>Como é definido o valor da proposta?</summary>
            <p>Levamos em conta o veículo (modelo, ano, estado de conservação) e a situação do financiamento (valor da parcela, quanto ainda falta pagar). A proposta final é sempre conversada e aprovada com você antes de qualquer contrato.</p>
        </details>
        <details class="faq-item">
            <summary>Quanto tempo leva o processo?</summary>
            <p>Varia conforme a análise do veículo e do financiamento, mas o atendimento inicial é rápido — em geral um consultor já entra em contato no mesmo dia depois da conversa no WhatsApp.</p>
        </details>
        <details class="faq-item">
            <summary>A negociação é formalizada com contrato?</summary>
            <p>Sim. Depois de alinhadas as condições, formalizamos tudo em contrato assinado por ambas as partes antes da transferência do veículo.</p>
        </details>
        <details class="faq-item">
            <summary>Vocês compram só carro?</summary>
            <p>Não — também compramos moto, caminhão, caminhonete, van e jet ski ainda financiados.</p>
        </details>
    </div>

    <div class="secao-titulo">
        <h2>Aprenda mais</h2>
        <p>Dicas sobre financiamento e venda de veículos no nosso blog.</p>
    </div>
    <div class="card">
        <div class="blog-grade">
            <a class="blog-item" href="/blog/posso-vender-carro-financiado.php"><strong>Posso vender um carro financiado? Entenda como funciona</strong><span>Ler →</span></a>
            <a class="blog-item" href="/blog/carro-financiado-atrasado-busca-e-apreensao.php"><strong>Carro financiado atrasado: o que fazer</strong><span>Ler →</span></a>
            <a class="blog-item" href="/blog/contrato-de-gaveta-riscos-vender-carro-financiado.php"><strong>Contrato de gaveta: por que é um risco sério</strong><span>Ler →</span></a>
        </div>
        <a class="blog-ver-todos" href="/blog/">Ver todos os artigos →</a>
    </div>

    <div class="secao-titulo">
        <h2>Fale com a gente</h2>
        <p>Estamos disponíveis pelos canais abaixo.</p>
    </div>
    <div class="card">
        <div class="contato-grade">
            <div class="contato-item">
                <div class="icone">💬</div>
                <div class="texto"><strong>WhatsApp</strong><a href="<?= htmlspecialchars($whatsappLink, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener">(11) 9 5834-7764</a></div>
            </div>
            <div class="contato-item">
                <div class="icone">✉️</div>
                <div class="texto"><strong>E-mail</strong><a href="mailto:contato@fastcar.solutions">contato@fastcar.solutions</a></div>
            </div>
            <div class="contato-item">
                <div class="icone">📍</div>
                <div class="texto"><strong>Endereço</strong>Av. Sagitário, 138 — Sala 1003, 10º andar, Torre City (Torre 2), Complexo Alpha Square Offices, Alphaville Conde II, Barueri/SP — CEP 06473-073</div>
            </div>
        </div>
    </div>

    <footer class="rodape-empresa">
        <strong>FASTCAR SOLUTIONS</strong> — CNPJ 66.934.500/0001-09<br>
        Av. Sagitário, 138 — Sala 1003, 10º andar, Torre City (Torre 2), Complexo Alpha Square Offices<br>
        Alphaville Conde II, Barueri/SP — CEP 06473-073<br>
        © <?= (int)$anoAtual ?> Fastcar Solutions. Todos os direitos reservados.
    </footer>
</div>

<a class="flutuante" href="<?= htmlspecialchars($whatsappLink, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener" aria-label="Falar no WhatsApp" title="Falar no WhatsApp">💬</a>

</body>
</html>
