<?php
/**
 * includes/blog.php — blog institucional (SEO) da Fastcar, 17/09/2026,
 * pedido direto ("cria blog seo com alguns artigos sobre esse assunto
 * usando pesquisa mais no google" / "uns 10 artigos bons de puxar cliques
 * pro site"). Mesmo espírito do index.php: nunca chama require db.php —
 * conteúdo é estático, versionado no repo (cada blog/{slug}.php é só o
 * corpo do artigo, chamando blogAbrirPagina()/blogRodape() em volta), não
 * dado dinâmico que justifique depender do SQLite.
 *
 * Tema é financeiro/jurídico-adjacente (alienação fiduciária, busca e
 * apreensão, negativação SPC/Serasa) — todo artigo foi escrito em cima de
 * pesquisa real (WebSearch, fontes: bancos, Serasa, Jusbrasil, Conjur,
 * portais de trânsito), nunca inventando prazo/número. Onde a pesquisa
 * achou divergência entre "o que a lei permite tecnicamente" e "o que os
 * bancos fazem na prática" (ex: busca e apreensão juridicamente cabe com
 * 1 parcela em atraso, mas bancos normalmente só acionam depois de 2-3),
 * o texto do artigo deixa isso explícito em vez de simplificar demais.
 * Nenhum artigo é aconselhamento jurídico/financeiro individual — todo
 * artigo termina com esse aviso (`blogRodape()` imprime automaticamente).
 */

const BLOG_WHATSAPP_NUMERO = '5511958347764'; // mesmo canal oficial de entrada do funil (Z-API "FastCar | JEAN")

const BLOG_ARTIGOS = [
    [
        'slug' => 'posso-vender-carro-financiado',
        'titulo' => 'Posso vender um carro financiado? Entenda como funciona',
        'resumo' => 'A propriedade do veículo financiado é do banco até a quitação — entenda o que isso muda na hora de vender e quais são os caminhos legais.',
        'tempo_leitura' => 6,
    ],
    [
        'slug' => 'carro-financiado-atrasado-busca-e-apreensao',
        'titulo' => 'Carro financiado atrasado: o que acontece e como evitar a busca e apreensão',
        'resumo' => 'O que a lei permite, o que os bancos costumam fazer na prática, e os passos até a busca e apreensão de um veículo financiado.',
        'tempo_leitura' => 7,
    ],
    [
        'slug' => 'transferencia-de-financiamento-de-veiculo',
        'titulo' => 'Transferência de financiamento de veículo: como funciona na prática',
        'resumo' => 'Passar a dívida do financiamento pra outra pessoa não é automático — entenda como o banco avalia e por que isso é diferente de transferir só o veículo.',
        'tempo_leitura' => 5,
    ],
    [
        'slug' => 'negativacao-por-atraso-financiamento-veiculo',
        'titulo' => 'Atraso no financiamento do carro: quando o nome vai pro SPC/Serasa',
        'resumo' => 'Negativação e busca e apreensão são consequências diferentes do mesmo atraso, com prazos diferentes — veja como funciona cada uma.',
        'tempo_leitura' => 6,
    ],
    [
        'slug' => 'documentos-para-vender-carro-financiado',
        'titulo' => 'Documentos para vender um carro financiado: checklist completo',
        'resumo' => 'CRLV, contrato de financiamento, ATPV-e, baixa de gravame — a lista completa do que você precisa reunir antes de formalizar a venda.',
        'tempo_leitura' => 5,
    ],
    [
        'slug' => 'como-consultar-saldo-devedor-financiamento',
        'titulo' => 'Como consultar o saldo devedor do financiamento do seu veículo',
        'resumo' => 'Onde consultar, por que o saldo devedor não é só "parcelas × valor", e o seu direito de quitar antecipadamente com desconto de juros.',
        'tempo_leitura' => 5,
    ],
    [
        'slug' => 'financiamento-moto-caminhao-caminhonete',
        'titulo' => 'Financiamento de moto, caminhão e caminhonete: também dá pra vender?',
        'resumo' => 'O mecanismo legal é o mesmo de um carro de passeio — o que muda é o perfil de valores e prazos. Veja o que considerar em cada tipo de veículo.',
        'tempo_leitura' => 5,
    ],
    [
        'slug' => 'tabela-fipe-como-funciona-na-pratica',
        'titulo' => 'Tabela FIPE: o que é e como ela é usada na venda do seu veículo',
        'resumo' => 'A FIPE é uma referência de mercado, não um preço fixo — entenda como financiamento, seguro e negociação usam essa tabela na prática.',
        'tempo_leitura' => 5,
    ],
    [
        'slug' => 'direitos-do-consumidor-financiamento-veiculo',
        'titulo' => 'Seus direitos ao vender ou quitar um veículo financiado',
        'resumo' => 'Quitação antecipada com desconto de juros, nulidade de cláusula abusiva, prazo pra retirada do nome do SPC/Serasa — o que o CDC garante.',
        'tempo_leitura' => 6,
    ],
    [
        'slug' => 'contrato-de-gaveta-riscos-vender-carro-financiado',
        'titulo' => 'Contrato de gaveta em carro financiado: por que é um risco sério',
        'resumo' => 'Vender um veículo alienado sem autorização do banco pode configurar estelionato — entenda os riscos reais pra quem vende e pra quem compra "por fora".',
        'tempo_leitura' => 6,
    ],
];

function blogArtigoPorSlug(string $slug): ?array {
    foreach (BLOG_ARTIGOS as $a) {
        if ($a['slug'] === $slug) return $a;
    }
    return null;
}

function blogWhatsappLink(string $textoPersonalizado = ''): string {
    $msg = $textoPersonalizado ?: 'Olá! Vi o blog da Fastcar e quero saber mais sobre vender meu veículo financiado.';
    return 'https://wa.me/' . BLOG_WHATSAPP_NUMERO . '?text=' . rawurlencode($msg);
}

/** Ícone de balão de WhatsApp inline (mesmo SVG do index.php), reaproveitado no botão de CTA e no flutuante. */
function blogIconeWhatsapp(): string {
    return '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" style="width:18px;height:18px;flex:none"><path d="M12.04 2C6.58 2 2.13 6.45 2.13 11.91c0 1.75.46 3.45 1.32 4.95L2.05 22l5.25-1.38a9.9 9.9 0 0 0 4.74 1.21h.01c5.46 0 9.91-4.45 9.91-9.91 0-2.65-1.03-5.14-2.9-7.01A9.82 9.82 0 0 0 12.04 2zm5.8 14.09c-.24.68-1.41 1.3-1.94 1.36-.5.07-1.03.1-1.65-.1-.38-.12-.87-.28-1.5-.55-2.63-1.14-4.35-3.8-4.48-3.98-.13-.17-1.07-1.42-1.07-2.71s.68-1.92.92-2.18c.24-.27.53-.34.71-.34l.5.01c.16 0 .38-.06.6.46.24.56.8 1.94.87 2.08.07.14.11.3.02.48-.09.17-.14.28-.27.43-.14.16-.29.35-.41.47-.14.14-.28.28-.12.55.16.28.72 1.19 1.55 1.93 1.07.95 1.97 1.25 2.24 1.39.28.14.44.12.6-.07.16-.19.68-.79.87-1.06.18-.27.36-.22.6-.13.24.09 1.55.73 1.81.86.27.13.44.2.51.31.07.11.07.65-.17 1.32z"/></svg>';
}

/**
 * Abre a página do blog: <head> completo (SEO/OG/Twitter/JSON-LD Article,
 * quando é um artigo — $tipoArticle) + abre <body> + cabeçalho de marca
 * compacto com link "← Início" e "Blog". Todo artigo/index do blog chama
 * isso primeiro, termina com blogRodape().
 */
function blogAbrirPagina(string $titulo, string $descricao, string $caminhoCanonico, bool $ehArtigo = false, ?array $artigo = null): void {
    $urlCanonica = 'https://fastcar.solutions' . $caminhoCanonico;
    $imagemOg = 'https://fastcar.solutions/admin/assets/img/icon-512.png';
    ?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($titulo, ENT_QUOTES, 'UTF-8') ?></title>
<meta name="description" content="<?= htmlspecialchars($descricao, ENT_QUOTES, 'UTF-8') ?>">
<meta name="robots" content="index, follow">
<meta name="author" content="Fastcar Solutions">
<meta name="theme-color" content="#151722">
<link rel="canonical" href="<?= htmlspecialchars($urlCanonica, ENT_QUOTES, 'UTF-8') ?>">
<meta property="og:type" content="<?= $ehArtigo ? 'article' : 'website' ?>">
<meta property="og:site_name" content="Fastcar Solutions">
<meta property="og:title" content="<?= htmlspecialchars($titulo, ENT_QUOTES, 'UTF-8') ?>">
<meta property="og:description" content="<?= htmlspecialchars($descricao, ENT_QUOTES, 'UTF-8') ?>">
<meta property="og:url" content="<?= htmlspecialchars($urlCanonica, ENT_QUOTES, 'UTF-8') ?>">
<meta property="og:image" content="<?= $imagemOg ?>">
<meta property="og:image:width" content="512">
<meta property="og:image:height" content="512">
<meta property="og:locale" content="pt_BR">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?= htmlspecialchars($titulo, ENT_QUOTES, 'UTF-8') ?>">
<meta name="twitter:description" content="<?= htmlspecialchars($descricao, ENT_QUOTES, 'UTF-8') ?>">
<meta name="twitter:image" content="<?= $imagemOg ?>">
<link rel="icon" type="image/png" href="/public/assets/favicon.png">
<link rel="stylesheet" href="/public/assets/blog.css">
<?php if ($ehArtigo && $artigo): ?>
<script type="application/ld+json">
{
    "@context": "https://schema.org",
    "@type": "Article",
    "headline": <?= json_encode($artigo['titulo'], JSON_UNESCAPED_UNICODE) ?>,
    "description": <?= json_encode($artigo['resumo'], JSON_UNESCAPED_UNICODE) ?>,
    "mainEntityOfPage": <?= json_encode($urlCanonica) ?>,
    "author": {"@type": "Organization", "name": "Fastcar Solutions"},
    "publisher": {
        "@type": "Organization",
        "name": "Fastcar Solutions",
        "logo": {"@type": "ImageObject", "url": <?= json_encode($imagemOg) ?>}
    }
}
</script>
<?php endif; ?>
</head>
<body>
<div class="blog-topo">
    <div class="blog-topo-inner">
        <a class="marca-link" href="/">
            <img src="/public/assets/favicon.png" alt="Fastcar" onerror="this.style.display='none'">
            <span class="logotipo">Fast<b>Car</b></span>
        </a>
        <nav>
            <a href="/">Início</a>
            <a href="/blog/">Blog</a>
        </nav>
    </div>
</div>
<?php
}

/**
 * Fecha a página: bloco "continue lendo" (outros artigos, exceto o atual —
 * só aparece dentro de um artigo, $slugAtual != null), rodapé com
 * dados oficiais da empresa + aviso de conteúdo informativo, botão
 * flutuante de WhatsApp, fecha body/html.
 */
function blogRodape(?string $slugAtual = null): void {
    $anoAtual = date('Y');
    if ($slugAtual !== null) {
        $outros = array_values(array_filter(BLOG_ARTIGOS, fn($a) => $a['slug'] !== $slugAtual));
        ?>
        <div class="blog-continue">
            <h3>Continue lendo</h3>
            <div class="blog-continue-grade">
                <?php foreach (array_slice($outros, 0, 4) as $a): ?>
                    <a class="blog-continue-item" href="/blog/<?= e($a['slug']) ?>.php">
                        <span class="titulo"><?= e($a['titulo']) ?></span>
                        <span class="tempo"><?= (int)$a['tempo_leitura'] ?> min de leitura</span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }
    ?>
    <footer class="rodape-empresa">
        Conteúdo informativo, não é aconselhamento jurídico ou financeiro individual — cada situação tem suas particularidades, fale com um consultor da Fastcar ou um profissional pra orientação específica.<br><br>
        <strong>FASTCAR SOLUTIONS</strong> — CNPJ 66.934.500/0001-09<br>
        Av. Sagitário, 138 — Sala 1003, 10º andar, Torre City (Torre 2), Complexo Alpha Square Offices<br>
        Alphaville Conde II, Barueri/SP — CEP 06473-073<br>
        © <?= (int)$anoAtual ?> Fastcar Solutions. Todos os direitos reservados.
    </footer>
</div>
<a class="flutuante" href="<?= htmlspecialchars(blogWhatsappLink(), ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener" aria-label="Falar no WhatsApp" title="Falar no WhatsApp">💬</a>
</body>
</html>
<?php
}

/** Escapa texto pra HTML — mesmo helper `e()` usado no resto do projeto (includes/security.php); redeclarado aqui só se ainda não existir, já que blog/*.php nunca dá require em security.php (não precisa de sessão/CSRF, é conteúdo público estático). */
if (!function_exists('e')) {
    function e(?string $s): string {
        return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
    }
}
