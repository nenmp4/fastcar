<?php
require_once __DIR__ . '/../includes/blog.php';

blogAbrirPagina(
    'Blog Fastcar — Dicas sobre financiamento e venda de veículos',
    'Tudo que você precisa saber sobre vender um veículo ainda financiado: busca e apreensão, transferência de financiamento, documentos, direitos do consumidor e mais.',
    '/blog/'
);
?>
<div class="blog-wrap">
    <h1 class="blog-titulo">Blog Fastcar</h1>
    <p style="color:var(--texto-fraco);font-size:15.5px;margin:-10px 0 28px;line-height:1.6">Dicas práticas sobre financiamento de veículos, o que fazer quando as parcelas atrasam, e como vender seu carro, moto ou caminhão ainda financiado com segurança.</p>

    <?php foreach (BLOG_ARTIGOS as $a): ?>
        <a class="blog-lista-item" href="/blog/<?= e($a['slug']) ?>.php">
            <h2><?= e($a['titulo']) ?></h2>
            <p><?= e($a['resumo']) ?></p>
            <span class="meta"><?= (int)$a['tempo_leitura'] ?> min de leitura →</span>
        </a>
    <?php endforeach; ?>
</div>
<?php
blogRodape();
