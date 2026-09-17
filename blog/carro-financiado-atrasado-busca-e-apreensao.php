<?php
require_once __DIR__ . '/../includes/blog.php';
$artigo = blogArtigoPorSlug('carro-financiado-atrasado-busca-e-apreensao');
blogAbrirPagina($artigo['titulo'] . ' | Blog Fastcar', $artigo['resumo'], '/blog/carro-financiado-atrasado-busca-e-apreensao.php', true, $artigo);
?>
<div class="blog-wrap">
    <div class="blog-artigo-meta"><span>Fastcar</span><span><?= (int)$artigo['tempo_leitura'] ?> min de leitura</span></div>
    <h1 class="blog-titulo"><?= e($artigo['titulo']) ?></h1>

    <div class="blog-corpo">
        <p>Atrasar uma parcela do financiamento do carro acontece — o problema é não saber o que vem depois. A "busca e apreensão" é a consequência que mais assusta, mas ela não acontece do dia pra noite. Veja como o processo funciona de verdade.</p>

        <h2>O que a lei permite x o que os bancos fazem na prática</h2>
        <p>A regra que trata disso é o <strong>Decreto-Lei nº 911/1969</strong> (alterado pela Lei 13.043/2014). Juridicamente, o simples vencimento de uma parcela já caracteriza a mora — ou seja, <strong>tecnicamente uma parcela atrasada já bastaria</strong> pro banco iniciar o processo.</p>
        <p>Na prática, porém, os bancos costumam agir de forma escalonada: primeiro tentam cobrança amigável (ligações, mensagens, negociação), e só depois de <strong>2 a 3 parcelas em atraso — algo em torno de 60 a 90 dias —</strong> é que costumam protocolar a ação de busca e apreensão de fato. Isso varia de instituição pra instituição, mas é o padrão mais comum de mercado.</p>

        <h2>Como funciona o processo, passo a passo</h2>
        <ol>
            <li><strong>Notificação extrajudicial</strong> — o banco formaliza a mora, geralmente via cartório.</li>
            <li><strong>Ação judicial de busca e apreensão</strong> — o banco entra com o processo na Justiça.</li>
            <li><strong>Liminar e apreensão</strong> — se concedida, o veículo pode ser apreendido.</li>
            <li><strong>Prazo pra pagar (purgar a mora)</strong> — depois que a liminar é <em>executada</em> (o carro é efetivamente apreendido), você tem <strong>5 dias corridos</strong> pra quitar a dívida e reaver o veículo. Um ponto importante: esse pagamento hoje precisa ser do <strong>valor integral</strong> da dívida (não só das parcelas atrasadas), segundo entendimento do STJ.</li>
            <li><strong>Consolidação e leilão</strong> — sem pagamento nesse prazo, a propriedade se consolida com o banco, que deve levar o veículo a leilão público.</li>
        </ol>

        <div class="blog-destaque">
            ⚠️ <strong>Um mito comum:</strong> muita gente acha que, se o carro for apreendido e leiloado, a dívida acaba ali. Não é bem assim — se o valor do leilão não cobrir o saldo devedor total, a diferença continua sendo cobrada de quem financiou.
        </div>

        <h2>O que fazer antes de chegar nesse ponto</h2>
        <p>Se você já sabe que vai ter dificuldade de manter as parcelas em dia, o pior caminho é esperar a situação se resolver sozinha. As opções mais seguras são:</p>
        <ul>
            <li>Negociar diretamente com o banco (renegociação, alongamento de prazo);</li>
            <li>Buscar a <a href="/blog/transferencia-de-financiamento-de-veiculo.php">transferência do financiamento</a> pra alguém que assuma a dívida;</li>
            <li>Vender o veículo pra quem compra veículos ainda financiados, como a Fastcar — assumimos o saldo devedor formalmente, sem passar pelo desgaste de negociar sozinho com o banco.</li>
        </ul>
        <p>Quanto antes você agir, mais opções tem disponíveis — inclusive pra evitar a <a href="/blog/negativacao-por-atraso-financiamento-veiculo.php">negativação do seu nome</a>, que costuma acontecer bem antes da busca e apreensão em si.</p>

        <div class="blog-cta">
            <p><strong>Está com parcelas atrasadas e quer resolver antes que a situação piore?</strong> Fala com a gente — avaliamos seu caso rápido.</p>
            <a class="botao" href="<?= e(blogWhatsappLink('Olá! Estou com o financiamento do meu veículo atrasado e quero entender minhas opções.')) ?>" target="_blank" rel="noopener"><?= blogIconeWhatsapp() ?> Falar no WhatsApp</a>
        </div>
    </div>
</div>
<?php
blogRodape('carro-financiado-atrasado-busca-e-apreensao');
