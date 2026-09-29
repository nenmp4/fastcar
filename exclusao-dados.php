<?php
/**
 * exclusao-dados.php — Instruções de Exclusão de Dados, 29/09/2026,
 * pedido direto ("URL de instruções de exclusão de dados") — campo
 * separado no Meta App Dashboard ("Data Deletion Instructions URL"),
 * exigido pra apps que integram com o WhatsApp/Meta mesmo sem usar
 * Facebook Login (que teria um callback automático em vez de instrução
 * por texto).
 *
 * Reflete o processo REAL já documentado no item 9 da Política de
 * Privacidade (privacidade.php) — nunca inventa um fluxo automatizado
 * que não existe (regra #3 do projeto): o pedido de exclusão hoje é
 * sempre manual, por e-mail, verificado pelo próprio telefone que o
 * cliente usou no atendimento. Mesmo padrão de reaproveitar
 * blogAbrirPagina()/blogRodape() (includes/blog.php) pra herdar
 * identidade visual sem CSS novo, nunca depende de banco.
 */
require_once __DIR__ . '/includes/blog.php';

blogAbrirPagina(
    'Instruções de Exclusão de Dados | Fastcar Solutions',
    'Como solicitar a exclusão dos seus dados pessoais coletados pela Fastcar Solutions, inclusive dados de conversas pelo WhatsApp.',
    '/exclusao-dados.php'
);
?>
<div class="blog-wrap">
    <h1 class="blog-titulo">Instruções de Exclusão de Dados</h1>

    <div class="blog-corpo">
        <p><em>Última atualização: 29 de setembro de 2026</em></p>

        <p>Se você conversou com a Fastcar (inclusive pelo WhatsApp) ou preencheu algum formulário nosso, você pode pedir a exclusão dos seus dados pessoais a qualquer momento, conforme a Lei Geral de Proteção de Dados (LGPD) — ver também nossa <a href="/privacidade.php">Política de Privacidade</a>.</p>

        <h2>Como pedir a exclusão</h2>
        <ol>
            <li>Envie um e-mail para <a href="mailto:contato@fastcar.solutions?subject=Exclus%C3%A3o%20de%20dados">contato@fastcar.solutions</a>.</li>
            <li>Use o assunto <strong>"Exclusão de dados"</strong>.</li>
            <li>Informe o <strong>número de telefone</strong> (com DDD) que você usou pra falar com a gente pelo WhatsApp, ou o e-mail/nome usados no formulário. É assim que localizamos e confirmamos os seus dados antes de excluir.</li>
        </ol>

        <h2>O que é excluído</h2>
        <p>A partir da confirmação, apagamos seu cadastro, o histórico de conversas do WhatsApp e os dados de veículo/financiamento associados àquele telefone. Documentos e informações que precisamos manter por obrigação legal ou contratual (por exemplo, contrato já assinado de uma compra concluída) ficam retidos só pelo tempo exigido por lei, mesmo depois do pedido de exclusão do restante.</p>

        <h2>Prazo</h2>
        <p>Respondemos e confirmamos a exclusão em até <strong>15 dias</strong> a partir do recebimento do seu pedido.</p>

        <h2>Dúvidas</h2>
        <p>Qualquer dúvida sobre esse processo ou sobre como tratamos seus dados, escreva pra <a href="mailto:contato@fastcar.solutions">contato@fastcar.solutions</a> — respondemos pelo mesmo canal.</p>

        <p><a href="/">← Voltar pra página inicial</a></p>
    </div>
</div>
<?php
blogRodape();
