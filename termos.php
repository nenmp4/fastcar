<?php
/**
 * termos.php — Termos de Uso/Serviço, 29/09/2026, pedido direto ("monta
 * termos de serviço") — mesmo padrão das outras 2 páginas legais
 * públicas (privacidade.php, exclusao-dados.php): reaproveita
 * blogAbrirPagina()/blogRodape() (includes/blog.php) pra herdar
 * cabeçalho/rodapé/CSS já usados no resto do site, nunca depende de
 * banco.
 *
 * Conteúdo reflete o negócio REAL descrito no CLAUDE.md e já publicado
 * no FAQ de index.php — nunca inventa cláusula: compra de veículo AINDA
 * financiado (assumindo o saldo devedor), atendimento inicial por IA no
 * WhatsApp que nunca inventa dado (regra #3 do projeto — informação não
 * confirmada fica pendente pro consultor humano revisar), toda proposta
 * de valor e condição sempre depende de aprovação humana, sem promessa
 * de valor/prazo genérico. Foro de eleição (Barueri/SP) é o mesmo já
 * usado nos contratos de compra/venda reais (includes/contratos_pdf.php).
 */
require_once __DIR__ . '/includes/blog.php';

blogAbrirPagina(
    'Termos de Uso | Fastcar Solutions',
    'Condições de uso do site, do atendimento pelo WhatsApp e do serviço de compra de veículos financiados da Fastcar Solutions.',
    '/termos.php'
);
?>
<div class="blog-wrap">
    <h1 class="blog-titulo">Termos de Uso</h1>

    <div class="blog-corpo">
        <p><em>Última atualização: 29 de setembro de 2026</em></p>

        <p>Estes Termos de Uso regulam o acesso ao site <strong>fastcar.solutions</strong> e o atendimento prestado pela <strong>FASTCAR SOLUTIONS LTDA</strong>, inscrita no CNPJ 66.934.500/0001-09, com sede na Av. Sagitário, 138 — Sala 1003, 10º andar, Torre City (Torre 2), Complexo Alpha Square Offices, Alphaville Conde II, Barueri/SP — CEP 06473-073 ("Fastcar", "nós"). Ao usar o site, conversar com a gente pelo WhatsApp ou solicitar uma avaliação de veículo, você concorda com estes Termos e com nossa <a href="/privacidade.php">Política de Privacidade</a>.</p>

        <h2>1. O que a Fastcar faz</h2>
        <p>A Fastcar compra veículos (carro, moto, caminhão, caminhonete, van ou jet ski) <strong>ainda financiados</strong>, assumindo o saldo devedor junto ao banco como parte da negociação — você não precisa quitar o financiamento antes de vender. Também operamos, como atividade separada, a revenda de veículos já adquiridos pela Fastcar.</p>

        <h2>2. Quem pode usar o serviço</h2>
        <p>O atendimento é destinado a pessoas maiores de 18 anos, com capacidade civil plena, proprietárias (ou responsáveis pelo financiamento) do veículo sobre o qual estão negociando. Ao entrar em contato, você declara que as informações fornecidas são verdadeiras e que tem autoridade para tratar sobre o veículo em questão.</p>

        <h2>3. Atendimento inicial por WhatsApp e IA</h2>
        <p>O primeiro contato normalmente é feito pelo WhatsApp e pode ser respondido por um assistente automatizado (IA), que coleta informações sobre você e o veículo pra entender a situação do financiamento. A IA <strong>nunca inventa ou estima dado que você não informou</strong> — qualquer informação não confirmada fica pendente pra um consultor humano revisar. Você pode pedir pra falar com uma pessoa a qualquer momento.</p>

        <h2>4. Nenhum valor ou condição é prometido antes da avaliação</h2>
        <p>Nada do que é dito na fase de qualificação (por IA ou por um consultor) constitui oferta vinculante de compra. <strong>Toda proposta de valor, prazo e condição de pagamento depende sempre de avaliação e aprovação humana</strong> — nunca é decidida automaticamente pelo sistema. A Fastcar pode, a seu critério, recusar a compra de qualquer veículo, inclusive após o início da conversa, sem obrigação de indenizar ou justificar.</p>

        <h2>5. Formalização do negócio</h2>
        <p>Quando uma proposta é aceita pelas duas partes, o negócio é formalizado por contrato próprio, assinado eletronicamente pela plataforma ZapSign, contendo as condições específicas daquela negociação (valor, prazo pra quitação do financiamento junto ao banco, documentação exigida etc.). Em caso de qualquer divergência entre estes Termos e o contrato específico assinado, prevalece o contrato.</p>

        <h2>6. Suas responsabilidades</h2>
        <ul>
            <li>Fornecer informações verdadeiras sobre você e o veículo (dados pessoais, situação do financiamento, débitos, avarias etc.);</li>
            <li>Enviar documentos autênticos quando solicitados (CNH/RG, comprovante de endereço, contrato de financiamento, CRLV);</li>
            <li>Não usar o site ou o canal de atendimento pra fins fraudulentos ou ilegais.</li>
        </ul>
        <p>Informação falsa ou documento adulterado pode resultar na recusa ou no cancelamento da negociação, sem prejuízo de outras medidas cabíveis.</p>

        <h2>7. Conteúdo do site e do blog</h2>
        <p>Os textos, artigos do blog e demais conteúdos do site são de titularidade da Fastcar ou usados com autorização, e servem apenas pra fins informativos. Os artigos do blog explicitamente <strong>não constituem aconselhamento jurídico ou financeiro individual</strong> — cada situação tem particularidades próprias; fale com um consultor da Fastcar ou um profissional habilitado pra orientação específica sobre o seu caso.</p>

        <h2>8. Limitação de responsabilidade</h2>
        <p>Fazemos o possível pra manter o site e o atendimento disponíveis e corretos, mas não garantimos disponibilidade ininterrupta nem ausência total de falhas (inclusive de terceiros, como WhatsApp/Meta e provedores de infraestrutura). A Fastcar não se responsabiliza por decisões tomadas com base só no conteúdo informativo do site, sem confirmação direta com um consultor.</p>

        <h2>9. Privacidade e dados pessoais</h2>
        <p>O tratamento dos seus dados pessoais, inclusive os coletados pelo WhatsApp, segue nossa <a href="/privacidade.php">Política de Privacidade</a>. Instruções pra solicitar a exclusão dos seus dados estão em <a href="/exclusao-dados.php">Instruções de Exclusão de Dados</a>.</p>

        <h2>10. Alterações destes Termos</h2>
        <p>Podemos atualizar estes Termos a qualquer momento. A versão vigente estará sempre disponível nesta página, com a data da última atualização.</p>

        <h2>11. Lei aplicável e foro</h2>
        <p>Estes Termos são regidos pelas leis da República Federativa do Brasil. Fica eleito o foro da Comarca de Barueri/SP pra dirimir eventuais controvérsias, respeitadas regras cogentes de competência (ex: foro do consumidor, quando aplicável).</p>

        <h2>12. Contato</h2>
        <p>Dúvidas sobre estes Termos: <a href="mailto:contato@fastcar.solutions">contato@fastcar.solutions</a> · (11) 9 5834-7764.</p>

        <p><a href="/">← Voltar pra página inicial</a></p>
    </div>
</div>
<?php
blogRodape();
