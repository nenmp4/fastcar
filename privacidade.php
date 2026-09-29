<?php
/**
 * privacidade.php — Política de Privacidade, 29/09/2026, pedido direto
 * ("escreva politica de privacidade para colocar app da meta no site")
 * — URL pública exigida pelo Meta App Dashboard (campo "Privacy Policy
 * URL") na configuração do app WhatsApp Cloud API / Business Manager.
 *
 * Conteúdo baseado num rascunho colado pelo usuário, mas com o endereço
 * corrigido: o rascunho trazia o endereço genérico antigo de Santana de
 * Parnaíba/SP (o mesmo já identificado e substituído em 13/09/2026 em
 * todo o resto do projeto, ver bullet "Endereço da sede corrigido" no
 * CLAUDE.md) — aqui usa o endereço real (Barueri/SP) já usado em
 * index.php, no rodapé do blog e no contrato assinado, nunca inventado.
 * Telefone/CNPJ do rascunho já batiam com o resto do site, mantidos.
 *
 * Reaproveita blogAbrirPagina()/blogRodape() (includes/blog.php) pra
 * herdar cabeçalho/rodapé/CSS (public/assets/blog.css) já usados no
 * resto do site público — mesma identidade visual, sem CSS novo. De
 * propósito nunca chama require db.php (mesmo espírito de index.php e
 * do blog: conteúdo estático, sempre no ar mesmo se o banco cair).
 */
require_once __DIR__ . '/includes/blog.php';

blogAbrirPagina(
    'Política de Privacidade | Fastcar Solutions',
    'Como a Fastcar Solutions coleta, usa e protege seus dados pessoais, inclusive no atendimento pelo WhatsApp, em conformidade com a LGPD.',
    '/privacidade.php'
);
?>
<div class="blog-wrap">
    <h1 class="blog-titulo">Política de Privacidade</h1>

    <div class="blog-corpo">
        <p><em>Última atualização: 29 de setembro de 2026</em></p>

        <p>Esta Política de Privacidade explica como a <strong>FASTCAR SOLUTIONS LTDA</strong>, inscrita no CNPJ 66.934.500/0001-09 ("Fastcar", "nós"), coleta, usa, armazena e protege os dados pessoais de clientes e visitantes que interagem com nosso site, sistemas e atendimento, inclusive pelo WhatsApp, em conformidade com a Lei Geral de Proteção de Dados (Lei nº 13.709/2018 — LGPD).</p>

        <h2>1. Quem é o controlador dos dados</h2>
        <p>
            <strong>FASTCAR SOLUTIONS LTDA</strong> — CNPJ 66.934.500/0001-09<br>
            Av. Sagitário, 138 — Sala 1003, 10º andar, Torre City (Torre 2), Complexo Alpha Square Offices, Alphaville Conde II, Barueri/SP — CEP 06473-073<br>
            E-mail: <a href="mailto:contato@fastcar.solutions">contato@fastcar.solutions</a> · Telefone/WhatsApp: (11) 9 3450-5474
        </p>

        <h2>2. Quais dados coletamos</h2>
        <ul>
            <li><strong>Dados de contato:</strong> nome, número de telefone/WhatsApp, e-mail.</li>
            <li><strong>Conteúdo das conversas:</strong> mensagens, arquivos e mídias que você nos envia pelo WhatsApp ou outros canais.</li>
            <li><strong>Dados do veículo e do financiamento:</strong> marca, modelo, ano, placa, banco, valor da parcela, parcelas restantes e demais informações necessárias pra avaliar a compra do veículo.</li>
            <li><strong>Documentos:</strong> CNH/RG, comprovante de endereço, contrato de financiamento e CRLV, quando você opta por enviá-los pra formalizar uma negociação.</li>
            <li><strong>Dados técnicos:</strong> endereço IP, data e hora de acesso, tipo de navegador e páginas visitadas no nosso site.</li>
        </ul>

        <h2>3. Para que usamos os dados</h2>
        <ul>
            <li>Responder dúvidas e prestar atendimento, inclusive por assistente automatizado (chatbot) no WhatsApp.</li>
            <li>Avaliar o veículo, elaborar propostas, formalizar contratos e executar a compra do veículo financiado.</li>
            <li>Enviar avisos, confirmações e comunicações relacionadas ao seu atendimento ou negociação.</li>
            <li>Enviar ofertas e novidades, somente com o seu consentimento, que pode ser retirado a qualquer momento.</li>
            <li>Cumprir obrigações legais e regulatórias e prevenir fraudes.</li>
        </ul>

        <h2>4. Bases legais</h2>
        <p>Tratamos seus dados com base no seu consentimento, na execução de contrato ou de procedimentos preliminares, no cumprimento de obrigação legal e no legítimo interesse, sempre respeitando seus direitos e expectativas (art. 7º da LGPD). Dado não informado por você fica pendente em nosso sistema — nunca preenchemos ou inferimos informação em seu lugar.</p>

        <h2>5. Atendimento pelo WhatsApp</h2>
        <p>Nosso atendimento pelo WhatsApp utiliza a Plataforma WhatsApp Business, fornecida pela Meta Platforms, Inc. Ao conversar conosco por esse canal, as mensagens trafegam pela infraestrutura do WhatsApp, sujeita também aos <a href="https://www.whatsapp.com/legal/privacy-policy" target="_blank" rel="noopener">termos e à política de privacidade do WhatsApp</a>. Parte do atendimento é feita por um assistente automatizado (IA), que nunca inventa dados — qualquer informação não confirmada por você fica pendente pra um consultor humano revisar antes de qualquer proposta. Você pode pedir pra falar com uma pessoa a qualquer momento.</p>
        <p>Pra parar de receber mensagens nossas, basta responder <strong>"SAIR"</strong> ou pedir o cancelamento na conversa.</p>

        <h2>6. Compartilhamento</h2>
        <p>Não vendemos seus dados. Podemos compartilhá-los apenas com:</p>
        <ul>
            <li>Fornecedores que nos ajudam a operar (hospedagem, sistemas, plataforma de mensagens, assinatura eletrônica de contratos), sob obrigação de confidencialidade;</li>
            <li>Instituições financeiras e demais partes envolvidas na formalização da compra do veículo que você solicitou;</li>
            <li>Autoridades públicas, quando exigido por lei ou ordem judicial.</li>
        </ul>

        <h2>7. Armazenamento e segurança</h2>
        <p>Mantemos os dados pelo tempo necessário pra cumprir as finalidades acima e as obrigações legais (inclusive documentais/contratuais). Adotamos medidas técnicas e administrativas pra protegê-los contra acesso não autorizado, perda ou alteração.</p>

        <h2>8. Seus direitos</h2>
        <p>Você pode, a qualquer momento, solicitar: confirmação e acesso aos seus dados; correção; anonimização, bloqueio ou eliminação; portabilidade; informação sobre compartilhamentos; e revogação do consentimento. Pra isso, escreva para <a href="mailto:contato@fastcar.solutions">contato@fastcar.solutions</a>.</p>

        <h2>9. Exclusão de dados</h2>
        <p>Pra pedir a exclusão dos seus dados, envie um e-mail para <a href="mailto:contato@fastcar.solutions">contato@fastcar.solutions</a> com o assunto "Exclusão de dados", informando o número de telefone usado no atendimento. Responderemos em até 15 dias. Veja o passo a passo completo em <a href="/exclusao-dados.php">Instruções de Exclusão de Dados</a>.</p>

        <h2>10. Alterações</h2>
        <p>Esta política pode ser atualizada. A versão vigente estará sempre disponível nesta página, com a data da última atualização.</p>

        <p><a href="/">← Voltar pra página inicial</a></p>
    </div>
</div>
<?php
blogRodape();
