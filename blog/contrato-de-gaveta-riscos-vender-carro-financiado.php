<?php
require_once __DIR__ . '/../includes/blog.php';
$artigo = blogArtigoPorSlug('contrato-de-gaveta-riscos-vender-carro-financiado');
blogAbrirPagina($artigo['titulo'] . ' | Blog Fastcar', $artigo['resumo'], '/blog/contrato-de-gaveta-riscos-vender-carro-financiado.php', true, $artigo);
?>
<div class="blog-wrap">
    <div class="blog-artigo-meta"><span>Fastcar</span><span><?= (int)$artigo['tempo_leitura'] ?> min de leitura</span></div>
    <h1 class="blog-titulo"><?= e($artigo['titulo']) ?></h1>

    <div class="blog-corpo">
        <p>É tentador: alguém quer o seu carro, oferece pagar as parcelas, vocês fazem um "recibo" e pronto — o financiamento continua no seu nome, mas quem usa o carro é a outra pessoa. Esse é o famoso <strong>contrato de gaveta</strong>, e ele é bem mais arriscado do que parece.</p>

        <h2>Por que vender sem autorização do banco pode ser crime</h2>
        <p>Como explicamos no <a href="/blog/posso-vender-carro-financiado.php">artigo sobre vender veículo financiado</a>, enquanto o financiamento não é quitado ou formalmente transferido, o veículo pertence ao banco — quem está pagando tem só a posse. Vender esse bem como se fosse seu, sem autorização do credor, pode ser enquadrado como <strong>estelionato</strong> (art. 171, §2º, I do Código Penal, combinado com a lei que trata de alienação fiduciária). É mais sério do que a maioria das pessoas imagina.</p>

        <h2>Os riscos pra quem vende</h2>
        <ul>
            <li>Você continua sendo o <strong>responsável legal</strong> pela dívida — se o comprador parar de pagar, a negativação recai sobre o seu nome;</li>
            <li>Multas de trânsito, IPVA e licenciamento seguem em seu nome;</li>
            <li>Se o veículo se envolver num acidente ou for usado em algo ilícito, você pode ser responsabilizado por ainda constar como responsável;</li>
            <li>Se as parcelas atrasarem, é o seu nome que vai pro SPC/Serasa e é contra você que a <a href="/blog/carro-financiado-atrasado-busca-e-apreensao.php">busca e apreensão</a> é movida — mesmo sem estar com o carro.</li>
        </ul>

        <h2>Os riscos pra quem compra</h2>
        <ul>
            <li>O comprador paga por um bem que, juridicamente, <strong>não é dele</strong> — o carro continua no nome de outra pessoa;</li>
            <li>Se o vendedor original morrer, tiver bens penhorados por outra dívida, ou simplesmente agir de má-fé, o comprador fica numa posição frágil, sem respaldo formal;</li>
            <li>Não há garantia de que vai conseguir regularizar a documentação no futuro sem a cooperação do vendedor original.</li>
        </ul>

        <div class="blog-destaque">
            📰 <strong>Isso acontece de verdade:</strong> há casos reportados na imprensa de pessoas que venderam veículo financiado sem quitar a dívida e depois tiveram conta bloqueada judicialmente por valores altos, cobrados pelo saldo que continuava em aberto no nome delas.
        </div>

        <h2>O caminho seguro é sempre o mesmo</h2>
        <p>Por mais que o contrato de gaveta pareça mais rápido, o caminho que realmente protege as duas partes é <strong>quitar o saldo ou formalizar a transferência do financiamento antes de repassar o veículo</strong> — nunca depois, e nunca só com um "recibo" informal.</p>
        <p>É exatamente esse o papel da Fastcar: compramos o veículo assumindo formalmente o saldo devedor junto ao banco, então você não precisa recorrer a um acordo informal só pra conseguir se desfazer do carro rápido.</p>

        <div class="blog-cta">
            <p><strong>Quer vender seu veículo financiado do jeito certo, sem contrato de gaveta?</strong> Fala com a gente.</p>
            <a class="botao" href="<?= e(blogWhatsappLink()) ?>" target="_blank" rel="noopener"><?= blogIconeWhatsapp() ?> Falar no WhatsApp</a>
        </div>
    </div>
</div>
<?php
blogRodape('contrato-de-gaveta-riscos-vender-carro-financiado');
