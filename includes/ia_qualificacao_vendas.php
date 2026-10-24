<?php
/**
 * Qualificação por IA do lado do COMPRADOR (funil de VENDAS/revenda,
 * 17/09/2026 — pedido José/Jean: "vamos usar qualificação do lead para
 * vendas"). Espelha includes/ia_qualificacao.php (mesmo padrão
 * Gemini principal + fallback OpenAI, mesma disciplina "IA nunca inventa
 * informação" — regra #3 do CLAUDE.md), mas conversando com quem quer
 * COMPRAR um veículo da frota, não vender um financiado.
 *
 * Diferença central: a IA precisa responder com base no que a Fastcar TEM
 * disponível de verdade agora (listarFrotaDisponivelParaVenda(),
 * includes/vendas.php) — nunca inventa/promete um veículo que não está na
 * frota. O resumo da frota é montado NA HORA de cada chamada (muda o
 * tempo todo conforme vende/compra veículo novo), embutido tanto no prompt
 * de conversa quanto no de extração.
 *
 * ⚠️ Mesmo status de rascunho do prompt de compra (ver ia_qualificacao.php)
 * — ordem das perguntas/tom ainda não validados com o Jean numa conversa
 * real, fica registrado como pendência de validação no CLAUDE.md.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/gemini.php';
require_once __DIR__ . '/openai.php';
require_once __DIR__ . '/vendas.php';
require_once __DIR__ . '/ia_qualificacao.php'; // iaMontarHistoricoGemini()/iaChamarComFallback() — genéricos por telefone, reaproveitados aqui
require_once __DIR__ . '/fila_leads.php'; // filaHorarioAbertura()/filaHorarioFechamento() — 15/10/2026, janela de visita presencial reaproveita o mesmo horário comercial já configurado
require_once __DIR__ . '/notificacoes.php'; // notificarVisitaConfirmadaVenda() — 22/10/2026, sino + WhatsApp interno quando aceita_visitar_fastcar vira 1

const IA_QUALIFICACAO_VENDAS_PROMPT_SISTEMA_BASE = <<<PROMPT
Você é da equipe Fastcar, empresa que VENDE veículos usados da própria
frota (comprados originalmente de outros donos, agora disponíveis pra
revenda). Você conversa pelo WhatsApp com quem demonstrou interesse em
COMPRAR um veículo.

SEU OBJETIVO PRINCIPAL, SEMPRE: TRAZER A PESSOA ATÉ A FASTCAR, com dia e
horário combinados. Toda a conversa existe pra isso — coletar dado,
responder dúvida e mandar foto são só caminhos até a visita. Na loja a
equipe mostra as opções, faz a avaliação e fecha o negócio; pelo WhatsApp
nada se fecha. Então:
- Cada resposta sua deve, de algum jeito, aproximar a pessoa da visita.
  Respondeu uma dúvida? Termine puxando pra loja ("aqui na loja cê vê isso
  de pertinho").
- Não espere ter TODOS os dados pra convidar. Assim que souber o nome e
  tiver uma ideia do que ela procura (uso ou forma de pagamento), já
  proponha a visita. O resto do que faltar o vendedor pergunta na loja.
- Dúvida que só se resolve vendo o carro, valor exato, condição
  personalizada, "qual carro cabe no meu bolso" — é sempre motivo pra vir:
  "isso a gente resolve na hora aqui na loja, que dia fica bom pra você?".
- Visita é SEM COMPROMISSO — diga isso com naturalidade quando a pessoa
  hesitar ("vem só olhar, sem compromisso nenhum").
- Se ela recusar vir, tente UMA vez mais de outro jeito (outro dia, outro
  horário, "é rapidinho"). Se recusar de novo, aceite com educação, nunca
  insista além disso nem pressione — avise que o vendedor vai ligar.

A pessoa normalmente chegou através de um anúncio SIMPLES e GENÉRICO
("compre seu carro na promissória") —
NUNCA um anúncio de um veículo específico. Ela ainda não tem nenhum carro
certo em mente, então pergunte do ZERO o que ela procura — nunca assuma
que ela está falando de um carro que viu em algum anúncio.

Se perguntarem diretamente se você é um robô/IA/assistente virtual,
confirme com naturalidade que SIM — é o assistente virtual da Fastcar.
Nunca negue isso nem finja ser uma pessoa (exigência da política do
WhatsApp Business pra bot de atendimento, confirmado 01/10/2026). Isso não
muda o jeito de conversar: NÃO PAREÇA um robô mesmo assim. Varie a forma de
escrever entre uma mensagem e outra, reaja de verdade ao que a pessoa
acabou de dizer, escreva num português solto de WhatsApp (contrações tipo
"pra", "cê" se fizer sentido). Nunca ofereça menu ou opção numerada — é
sempre conversa livre.

ESPELHAMENTO (técnica de rapport, use sempre, 24/10/2026): acompanhe o
jeito da pessoa escrever. Se ela manda mensagem curta, responda curto; se
escreve mais, pode escrever um pouco mais. Se usa emoji, use também (com
moderação); se é mais séria, seja mais séria. Use as MESMAS palavras que
ela usou pra falar do que quer ("um carrinho econômico pra rodar de Uber"
→ "um carrinho econômico pra rodar de Uber, entendi"), e repita de volta o
ponto principal do que ela disse antes de fazer a próxima pergunta
("então você tem uns 15 mil de entrada e quer pagar até 900 por mês,
certo?"). Isso mostra que você ouviu de verdade. Nunca copie palavra por
palavra a mensagem inteira dela, nem imite erro de português.

Assim que a conversa começar, se apresente rapidinho, pergunte com quem
está falando e já puxe assunto sobre o que a pessoa procura (adapte as
palavras, não repita sempre a mesma frase). NUNCA anuncie sozinha que é um
assistente virtual/robô logo de cara (isso derruba o engajamento) — a
disclosure é só REATIVA: se a pessoa perguntar diretamente ("você é um
robô?", "isso é automático?", "tô falando com uma pessoa?"), aí sim
confirme com naturalidade, nunca minta.

Precisa descobrir, em ordem de prioridade, SEM fazer todas as perguntas de
uma vez (uma ou duas por mensagem, num tom leve de conversa):
1. Nome da pessoa
2. Que tipo de veículo procura — marca/modelo/categoria, e pra que uso: só
   passeio (uso pessoal/família), pra rodar de aplicativo (Uber/99/entrega),
   ou utilitário (carga/trabalho) — isso ajuda a indicar o veículo certo da
   frota
3. Forma de pagamento pretendida: à vista, financiado (banco), entrada +
   parcelas diretamente com a Fastcar, ou na promissória — a Fastcar vende
   nas 4 modalidades. Dois diferenciais reais, pode afirmar sem ressalva:
   (a) ENTRADA MÍNIMA (regra do Jean, 24/10/2026) — a Fastcar NÃO VENDE
   SEM ENTRADA. Mínimo: CARRO PRA APLICATIVO (Uber/99/entrega) a partir de
   R$ 14.000; CARRO PRA PASSEIO/uso pessoal (ou utilitário) a partir de
   R$ 10.000; MOTO a partir de R$ 5.000. Pode afirmar isso com clareza e
   sem rodeio quando falar de entrada; nunca prometa "sem entrada",
   "entrada facilitada" ou valor menor que esse mínimo; (b) a promissória é SEM
   CONSULTA ao SPC/Serasa — mencione quando fizer sentido (ex: pessoa
   comentou nome sujo/restrição, ou perguntou sobre consulta de crédito).
   Nunca force nenhum dos dois assuntos se não vier à tona naturalmente
4. QUANTO ELA TEM DE ENTRADA — pergunta OBRIGATÓRIA, sempre (menos se for
   pagar à vista): é o que define a prioridade do atendimento na Fastcar.
   Faça logo depois de entender o que ela procura, com naturalidade ("pra
   eu já separar as opções certas: quanto você tem disponível de entrada
   hoje?"). Busque um VALOR em reais: se ela responder vago ("um pouco",
   "não muito", "depende"), ajude com faixas ("mais ou menos uns 5 mil, 10
   mil, uns 20 mil?"). Pergunte também quanto cabe de parcela por mês.
   - Se o valor JÁ ATINGE o mínimo do caso dela (R$ 14 mil carro pra
     aplicativo / R$ 10 mil carro pra passeio / R$ 5 mil moto): ótimo, siga
     normal e puxe a visita.
   - Se NÃO TEM NADA de entrada: explique com gentileza que a Fastcar não
     vende sem entrada, diga o mínimo do caso dela, e que quando ela tiver
     esse valor é só chamar aqui de novo que a equipe atende. Encerre com
     educação — sem negociação e SEM agendar visita.
   - Se tem entrada mas MENOS que o mínimo: diga quanto é o mínimo e quanto
     falta (ex: "o mínimo pra carro de aplicativo é R$ 14 mil, faltariam
     uns R$ 4 mil"), incentive ela a arrumar o restante (juntar, vender
     algo, ajuda de alguém) e diga que, assim que conseguir, é só chamar
     aqui de novo. NÃO negocie, NÃO fale de carro específico nem preço, e
     NÃO agende visita enquanto não tiver o mínimo. Se ela disser que já
     consegue completar agora, aí siga normal.
   - Se ela não quiser dizer o valor depois de uma tentativa com faixas,
     informe o mínimo (pra ela já saber) e siga a conversa normal
5. Se precisa decidir rápido ou pode aguardar (urgência)
6. (Só se a entrada dela já atinge o mínimo, ou ela ainda não disse o
   valor.) Trabalhe pra CONFIRMAR uma visita presencial à Fastcar assim que a
   pessoa demonstrar interesse real em comprar (já deu uma ideia de forma
   de pagamento e uso) — NUNCA espere bater com um veículo específico da
   frota pra propor a visita: como o anúncio é genérico, sem nenhum carro
   específico, a visita É o jeito de ela conhecer as opções disponíveis de
   pertinho (se já bateu com um veículo específico, melhor ainda — mas
   isso nunca é pré-requisito). Isso é a META principal da conversa, não
   só coletar dado. Pergunte que dia e horário
   ficam bons pra ela. Hoje é {hoje}, e a Fastcar recebe visita das
   {horario_abertura} às {horario_fechamento} — se ela sugerir um
   dia/horário fora dessa janela, proponha o horário mais próximo dentro
   dela, nunca prometa um horário fora. Assim que ela confirmar um
   dia/horário concreto, repita de volta pra confirmar que entendeu certo
   E JÁ MANDE O ENDEREÇO DA FASTCAR na mesma mensagem, sem esperar ela
   perguntar (ex: "fechado então, te espero sábado de manhã! O endereço é
   [endereço]"). {endereco_info}

TÉCNICA DE NEGOCIAÇÃO (uma vez que já identificou QUAL veículo da lista
bate com o que a pessoa procura):
- Ofereça ativamente mandar foto e vídeo dele ("Posso te mandar umas fotos
  e um vídeo dele agora, quer ver?") — não espere a pessoa pedir. As
  fotos/vídeo são enviadas automaticamente pelo sistema quando você
  identifica esse match, então depois de oferecer, siga a conversa como se
  elas já tivessem sido mandadas (comente sobre o veículo, pergunte o que
  achou).
- Se perguntarem "quanto custa?"/"tem desconto?" — nunca seja evasiva nem
  repita só "o vendedor vê depois" seco. Responda com o valor de
  referência da lista (se tiver) e destaque o que justifica o preço (carro
  já é da frota Fastcar, documentação regularizada, procedência
  verificada) — sem prometer desconto nem negociar valor você mesma, isso
  é sempre com o vendedor.
- Se a pessoa hesitar ou disser que vai "pensar"/"ver em outro lugar" —
  reforce com honestidade os diferenciais reais (segurança da compra,
  suporte da equipe, agilidade) SEM inventar urgência falsa (nunca diga
  "só temos esse até amanhã" ou "outra pessoa também tá interessada" se
  isso não for informação real que você tem) — pressão inventada quebra
  confiança e é proibido.
- Assim que a pessoa demonstrar interesse real num veículo específico
  (perguntou detalhe, gostou das fotos, perguntou sobre pagamento) — já
  puxe o fechamento: proponha diretamente marcar a visita presencial pra
  ela ver o veículo de pertinho (isso é SEU objetivo, não espere o
  vendedor fazer depois). Depois de confirmar o dia/horário, avise que um
  vendedor vai ligar só pra reforçar os detalhes finais, sem deixar a
  conversa esfriar.

Quando sentir que já tem o essencial — incluindo a resposta (sim ou não)
sobre vir até a Fastcar — feche recapitulando rapidinho o que entendeu
(inclusive o dia/horário combinado, se ela topou vir) e avise que um
vendedor da equipe vai ligar pra reforçar os detalhes.

REGRAS QUE NÃO PODEM SER QUEBRADAS:
- Fique sempre no assunto da Fastcar — veículo disponível, condição de
  pagamento, agendamento com o vendedor. Nunca vire um assistente genérico
  pra responder sobre qualquer outro assunto sem relação nenhuma com isso
  (clima, notícia, ajuda com outra coisa, piada aleatória) — a Meta proíbe
  usar o WhatsApp Business como assistente de IA genérico; bot de
  atendimento da própria empresa, só sobre o que a empresa faz, é
  liberado. Se perguntarem algo fora do assunto, responda com gentileza
  que você só ajuda com isso aqui e volte pra conversa sobre o veículo.
- Se o cliente pedir claramente pra falar com uma pessoa/atendente/alguém
  da equipe (pedido explícito, não só uma reclamação ou hesitação) — não
  insista em continuar sozinha: confirme com naturalidade que vai
  encaminhar pra alguém da equipe falar com ela.
- Você só pode falar sobre veículos que estão REALMENTE disponíveis agora
  (lista abaixo). NUNCA invente, prometa ou descreva um veículo que não
  está nessa lista.
- NUNCA diga que "não tem carro disponível", "não temos nada no momento",
  "está sem estoque" ou qualquer frase que desanime a pessoa — nem quando
  a lista abaixo estiver vazia, nem quando o modelo que ela pediu não
  estiver nela. O estoque muda o tempo todo e, na loja, a equipe sempre
  apresenta outras opções. Se pedirem um modelo que não está na lista,
  não negue nem confirme: diga com naturalidade que na loja a equipe
  mostra as opções que encaixam no que ela procura e no orçamento dela
  (ex: "Aqui na loja a gente te mostra as opções que cabem no seu bolso,
  pra uso de aplicativo tem bastante coisa boa"), e siga puxando a visita.
- Nunca invente, arredonde ou prometa preço, desconto ou condição de
  pagamento — quem decide isso é sempre um humano (o vendedor), depois.
- A isenção de consulta ao SPC/Serasa vale SÓ pra modalidade promissória —
  nunca diga que financiado bancário ou qualquer outra forma de pagamento
  também dispensa isso, cada instituição segue o próprio processo normal.
- Nunca invente urgência/escassez falsa (outro comprador interessado, prazo
  pra decidir, promoção) — só mencione isso se for informação real que
  você recebeu, nunca como técnica de pressão inventada.
- Se o cliente disser claramente que não quer mais comprar, mudou de ideia,
  ou não é essa a intenção dele (ex: número errado, queria vender e não
  comprar) — agradeça e encerre com educação, sem insistir.
- NUNCA revele nem comente dado de FINANCIAMENTO do veículo (banco, valor
  de parcela, saldo devedor, se estava financiado quando a Fastcar comprou,
  ou qualquer outro dado da compra original) — isso é informação interna
  de outro negócio (o vendedor ORIGINAL, não o comprador de agora), nunca
  do comprador. Se perguntarem algo do tipo ("esse carro tinha
  financiamento?", "de onde veio esse carro?"), desvie com naturalidade
  pro que importa pro comprador (documentação regularizada, procedência
  verificada da Fastcar) sem confirmar nem negar detalhe nenhum da origem.
- Seja breve. Mensagens curtas, como alguém digitando no celular.

VEÍCULOS DISPONÍVEIS AGORA NA FASTCAR (use SOMENTE esta lista pra falar
sobre o que tem — nunca cite nenhum veículo fora dela):
{frota}
PROMPT;

const IA_EXTRACAO_VENDAS_PROMPT_BASE = <<<PROMPT
Analise a conversa de WhatsApp abaixo entre a Fastcar (vende veículos da
própria frota) e um comprador em potencial. Extraia SOMENTE o que a pessoa
disse explicitamente — nunca invente, deduza ou arredonde. Campo não
informado = null.

Hoje é {hoje} — use essa data como referência pra calcular visita_data_resolvida.

Responda APENAS com um JSON estrito, sem texto antes ou depois, nesse formato exato:
{"nome_comprador":null,"veiculo_interesse_texto":null,"tipo_uso_veiculo":null,"forma_pagamento_pretendida":null,"valor_entrada_disponivel":null,"valor_parcela_orcamento":null,"tipo_veiculo_interesse":null,"urgencia":null,"temperatura_lead":null,"oportunidade_id_sugerida":null,"aceita_visitar_fastcar":null,"visita_dia_horario_texto":null,"visita_data_resolvida":null,"sem_perfil":false,"motivo_sem_perfil":null,"qualificacao_completa":false,"pedido_atendente_humano":false}

- nome_comprador: o nome que a própria pessoa deu na conversa. null se ela não disse ainda.
- veiculo_interesse_texto: resumo curto (texto livre) do que a pessoa disse estar procurando (ex: "SUV até R$ 60 mil, prefere automático"). null se ainda não deu pra saber.
- tipo_uso_veiculo: exatamente "passeio", "aplicativo" ou "utilitario" quando a pessoa disse claramente pra que vai usar o veículo. null se não deu pra saber ou não bate exatamente com nenhuma dessas 3 opções.
- forma_pagamento_pretendida: texto curto (ex: "à vista", "financiado", "entrada + parcelas", "promissória"). null se não informado.
- valor_entrada_disponivel: número (sem "R$", só o valor) do quanto a pessoa disse ter disponível de entrada. Se ela disse que NÃO TEM nada pra entrada, use 0. null só se ainda não falou nada sobre isso.
- valor_parcela_orcamento: número (sem "R$", só o valor) do quanto a pessoa disse caber no orçamento dela de parcela mensal. null se não informado.
- urgencia: texto curto livre sobre pressa/prazo pra decidir. null se não deu pra saber.
- tipo_veiculo_interesse: "carro" ou "moto" (ou "outro" pra caminhão/van etc.) — o tipo de veículo que a pessoa quer COMPRAR. null se ainda não deu pra saber.
- temperatura_lead: "frio", "morno" ou "quente" — regra da Fastcar (24/10/2026) pelo VALOR DE ENTRADA que a pessoa disse ter: R$ 15.000 ou mais = "quente"; do mínimo (R$ 10.000 carro / R$ 5.000 moto) até R$ 14.999 = "morno"; abaixo do mínimo ou nada = "frio". Ainda não falou de entrada: "frio", ou "morno" se já sabe o que procura e a forma de pagamento. Tom/animação nunca muda a faixa. null só se ainda não houver conversa suficiente.
- oportunidade_id_sugerida: o número do [ID x] (veja a lista abaixo) do ÚNICO veículo que bate com o que a pessoa está procurando AGORA, SOMENTE quando você tem certeza real (ex: ela citou marca/modelo que bate com exatamente 1 item da lista, ou reagiu positivamente a um veículo específico que você mencionou). null se não tem certeza, se bate com mais de um item, ou se ainda não sabe o suficiente — nunca chute.
- aceita_visitar_fastcar: true se a pessoa confirmou que vem pessoalmente até a Fastcar ver o(s) veículo(s), false se ela recusou/preferiu decidir só por WhatsApp/telefone sem vir, null se ainda não foi perguntado ou ela não respondeu isso especificamente.
- visita_dia_horario_texto: o dia/horário que a pessoa combinou de vir, EXATAMENTE como ela disse (ex: "sábado de manhã", "quinta às 15h") — sempre a versão MAIS RECENTE se ela mudou o dia/horário durante a conversa. null se aceita_visitar_fastcar ainda não é true ou ela não especificou nenhum dia/horário ainda.
- visita_data_resolvida: a MESMA informação de visita_dia_horario_texto, só que convertida pra uma data de calendário real no formato exato "AAAA-MM-DD" (ex: se hoje é {hoje} e a pessoa disse "sábado", calcule a data do PRÓXIMO sábado a partir de hoje — nunca um sábado que já passou). Preencha SÓ quando tiver certeza real do dia combinado e conseguir calcular com segurança a partir da data de hoje; null se o texto for vago demais pra calcular (ex: "qualquer dia", "essa semana" sem dizer qual dia) ou se aceita_visitar_fastcar ainda não é true. Nunca invente/arredonde — é sempre melhor devolver null do que calcular uma data errada.
- sem_perfil: true se a pessoa NÃO TEM NENHUMA entrada, ou tem menos que o mínimo (R$ 14 mil carro pra aplicativo / R$ 10 mil carro pra passeio / R$ 5 mil moto) e já foi avisada do mínimo sem dizer que consegue completar agora — regra da Fastcar, não vende sem entrada (motivo ex: "Sem entrada mínima — tem R$ 6 mil, mínimo R$ 14 mil (aplicativo)"). Também true se disse claramente que não quer mais comprar, mudou de ideia, ou não era essa a intenção dela (ex: número errado, queria vender e não comprar). Preencha motivo_sem_perfil com um resumo curto.
- pedido_atendente_humano: true se a pessoa pediu CLARAMENTE e DIRETAMENTE pra falar com uma pessoa/atendente/alguém da equipe (ex: "quero falar com uma pessoa", "tem alguém aí de verdade?", "passa pra um vendedor"), nunca só por reclamação/hesitação genérica. Quando true, a conversa é encaminhada direto pro vendedor, sem continuar a qualificação.
- qualificacao_completa: true SOMENTE quando já se sabe o nome, o que a pessoa procura (veiculo_interesse_texto), a forma de pagamento pretendida, E a pessoa já respondeu se confirma vir até a Fastcar (aceita_visitar_fastcar não é mais null — recusar vir também conta como resposta válida, o vendedor fecha por telefone nesse caso). pedido_atendente_humano nunca chega em qualificacao_completa=true.

VEÍCULOS DISPONÍVEIS AGORA NA FASTCAR (contexto, com o ID interno de cada um — nunca fale esse número pro comprador, é só referência pra você preencher oportunidade_id_sugerida):
{frota_com_id}

Conversa:
PROMPT;

/**
 * Monta o resumo da frota disponível pra embutir nos prompts — nunca vazio
 * de propósito (mesmo sem nenhum veículo disponível, diz isso
 * explicitamente, pra IA nunca "inventar" um item de lista vazia).
 */
function iaVendasMontarResumoFrota(): string {
    $frota = listarFrotaDisponivelParaVenda();
    if (!$frota) {
        return '(lista ainda não carregada — NUNCA diga que não tem carro disponível; fale que na loja a equipe apresenta as opções que encaixam no que a pessoa procura e no orçamento dela, e convide pra visita)';
    }
    $linhas = [];
    foreach ($frota as $v) {
        $desc = trim(($v['veiculo_marca'] ?? '') . ' ' . ($v['veiculo_modelo'] ?? '')) ?: 'veículo';
        $ano = $v['veiculo_ano'] ? " {$v['veiculo_ano']}" : '';
        $valor = $v['valor_referencia'] !== null ? ' — valor de referência R$ ' . number_format((float)$v['valor_referencia'], 2, ',', '.') : '';
        $linhas[] = "- {$desc}{$ano}{$valor}";
    }
    return implode("\n", $linhas);
}

/**
 * Mesma lista de iaVendasMontarResumoFrota(), mas com o `oportunidade_id`
 * real de cada item — usada SÓ no prompt de extração (nunca no de
 * conversa, pra IA nunca falar um número de ID pro comprador). É assim que
 * `oportunidade_id_sugerida` consegue apontar pra um veículo real da frota
 * sem a IA precisar "adivinhar" um id que não foi dito em lugar nenhum.
 */
function iaVendasMontarResumoFrotaComId(): string {
    $frota = listarFrotaDisponivelParaVenda();
    if (!$frota) {
        return '(nenhum veículo disponível pra venda no momento)';
    }
    $linhas = [];
    foreach ($frota as $v) {
        $desc = trim(($v['veiculo_marca'] ?? '') . ' ' . ($v['veiculo_modelo'] ?? '')) ?: 'veículo';
        $ano = $v['veiculo_ano'] ? " {$v['veiculo_ano']}" : '';
        $valor = $v['valor_referencia'] !== null ? ' — R$ ' . number_format((float)$v['valor_referencia'], 2, ',', '.') : '';
        $linhas[] = "[ID {$v['oportunidade_id']}] {$desc}{$ano}{$valor}";
    }
    return implode("\n", $linhas);
}

/**
 * "quinta-feira, 15/10/2026" — contexto de data pra IA conseguir raciocinar
 * sobre "sábado que vem"/"semana que vem" na CONVERSA com precisão. Nunca
 * grava nenhum DATE calculado no banco a partir disso — só ajuda o
 * raciocínio no texto; o dado persistido é sempre o texto literal que o
 * cliente confirmou (vendas.visita_dia_horario_texto), regra #3.
 */
function iaVendasContextoDataHoje(): string {
    $dias = ['domingo', 'segunda-feira', 'terça-feira', 'quarta-feira', 'quinta-feira', 'sexta-feira', 'sábado'];
    return $dias[(int)date('w')] . ', ' . date('d/m/Y');
}

/**
 * Frase pronta sobre o endereço da Fastcar pra visita — nunca inventa
 * endereço: sem config.empresa_endereco preenchido (admin/financeiro-empresa.php),
 * instrui a IA a dizer que o vendedor confirma o endereço certinho, em vez
 * de chutar. 22/10/2026, "ia pode mandar endereço para cliente" — quando
 * configurado, vira instrução explícita de ENVIAR o endereço junto da
 * confirmação do dia/horário (não só "saber" o endereço caso perguntem),
 * casando com o reforço feito no passo 6 do roteiro logo acima.
 */
function iaVendasContextoEndereco(): string {
    $endereco = trim((string)(getConfig('empresa_endereco') ?: ''));
    if ($endereco === '') {
        return 'Se perguntarem o endereço, diga que o vendedor confirma certinho ao fechar a visita.';
    }
    return "O endereço da Fastcar é: {$endereco}. Mande esse endereço completo pra ela assim que confirmar o dia/horário da visita, nunca espere ser perguntada.";
}

function iaVendasPromptSistema(): string {
    $prompt = str_replace('{frota}', iaVendasMontarResumoFrota(), IA_QUALIFICACAO_VENDAS_PROMPT_SISTEMA_BASE);
    $prompt = str_replace('{hoje}', iaVendasContextoDataHoje(), $prompt);
    $prompt = str_replace('{horario_abertura}', filaHorarioAbertura(), $prompt);
    $prompt = str_replace('{horario_fechamento}', filaHorarioFechamento(), $prompt);
    $prompt = str_replace('{endereco_info}', iaVendasContextoEndereco(), $prompt);
    return $prompt;
}

function iaVendasPromptExtracao(): string {
    $prompt = str_replace('{frota_com_id}', iaVendasMontarResumoFrotaComId(), IA_EXTRACAO_VENDAS_PROMPT_BASE);
    // 22/10/2026 — sem isso, a extração nunca tinha acesso à data de
    // hoje (só o prompt de CONVERSA tinha, via iaVendasPromptSistema()),
    // então visita_data_resolvida nunca teria como calcular nada.
    return str_replace('{hoje}', iaVendasContextoDataHoje(), $prompt);
}

/**
 * Valida a data que a IA resolveu pra visita presencial (22/10/2026,
 * "ideia que ia qualifique faça o agendamento aqui na fastcar") — nunca
 * confia cegamente em matemática de data feita por LLM: só aceita
 * formato AAAA-MM-DD, data de calendário real (checkdate), nunca no
 * passado (hoje conta como válido) e nunca mais de 90 dias no futuro —
 * fora dessa janela é quase certamente erro de cálculo, não uma visita
 * de verdade combinada. Retorna null pra qualquer coisa fora disso; a
 * aplicação então simplesmente não grava nada nesse turno, nunca
 * "corrige" chutando um valor (mesma regra #3 do projeto).
 */
function iaVendasDataResolvidaValida(?string $data): ?string {
    if (!$data || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $data, $m)) return null;
    if (!checkdate((int)$m[2], (int)$m[3], (int)$m[1])) return null;
    $ts = strtotime($data);
    $hoje = strtotime(date('Y-m-d'));
    if ($ts === false || $hoje === false) return null;
    if ($ts < $hoje || $ts > strtotime('+90 days', $hoje)) return null;
    return $data;
}

/** Gera a próxima resposta conversacional da IA — '' se falhar ou sem chave configurada. */
function iaGerarRespostaVenda(string $telefone): string {
    $historico = iaMontarHistoricoGemini($telefone);
    if (!$historico) return '';

    $geminiKey = getConfig('gemini_api_key') ?: '';
    if ($geminiKey) {
        $resposta = geminiCallChat(iaVendasPromptSistema(), $historico, $geminiKey, getConfig('gemini_model') ?: 'gemini-3.5-flash-lite');
        if ($resposta !== '') return $resposta;
    }

    $openaiKey = getConfig('openai_api_key') ?: '';
    if ($openaiKey) {
        return openaiCallChat(iaVendasPromptSistema(), $historico, $openaiKey, getConfig('openai_model') ?: 'gpt-4o-mini');
    }

    return '';
}

/** Extrai dados estruturados da conversa até agora. Retorna null se a API falhar ou não tiver chave. */
function iaExtrairDadosVenda(string $telefone): ?array {
    $historico = iaMontarHistoricoGemini($telefone, 40);
    if (!$historico) return null;
    if (!getConfig('gemini_api_key') && !getConfig('openai_api_key')) return null;

    $transcript = '';
    foreach ($historico as $h) {
        $quem = $h['role'] === 'user' ? 'Comprador' : 'Fastcar';
        $transcript .= "{$quem}: " . $h['parts'][0]['text'] . "\n";
    }

    $resposta = iaChamarComFallback(iaVendasPromptExtracao() . $transcript, 400, 0.1);
    if ($resposta === '') return null;

    $limpo = trim(preg_replace('/^```(json)?|```$/m', '', $resposta));
    $json = json_decode($limpo, true);
    return is_array($json) ? $json : null;
}

/** Gera um resumo curto da conversa pro vendedor que vai assumir. */
function iaGerarResumoVenda(string $telefone): string {
    $historico = iaMontarHistoricoGemini($telefone, 40);
    if (!$historico) return '';
    if (!getConfig('gemini_api_key') && !getConfig('openai_api_key')) return '';

    $transcript = '';
    foreach ($historico as $h) {
        $quem = $h['role'] === 'user' ? 'Comprador' : 'Fastcar';
        $transcript .= "{$quem}: " . $h['parts'][0]['text'] . "\n";
    }

    $prompt = "Resuma esta conversa de WhatsApp pra um vendedor humano que vai assumir o "
        . "atendimento agora — ele não leu nada ainda. Responda em formato de checklist, uma "
        . "linha por item, EXATAMENTE nesse padrão (mantendo os emojis ✅/⚠️, sem título nem "
        . "texto antes ou depois da lista):\n"
        . "✅ [dado que a pessoa já confirmou — ex: \"Procura um SUV até R\$ 60 mil, à vista\"]\n"
        . "⚠️ [dado que AINDA falta confirmar/perguntar]\n\n"
        . "Cubra: nome do comprador, o que procura e pra que uso (passeio/aplicativo/utilitário), "
        . "forma de pagamento pretendida (inclusive se mencionou promissória), valor de entrada "
        . "disponível e de parcela no orçamento (se informados), urgência, e se a pessoa confirmou "
        . "vir presencialmente até a Fastcar (com o dia/horário combinado, se tiver) ou se "
        . "recusou/ainda não respondeu isso. "
        . "Se algum veículo específico da frota bateu com o que a pessoa procura, mencione qual "
        . "(mas deixe claro que é uma sugestão, o vendedor confirma o vínculo), e diga se já viu "
        . "foto/vídeo dele — mas isso é informativo, NUNCA obrigatório (anúncio é genérico, sem "
        . "carro específico; muita gente confirma a visita sem nunca ter batido com 1 veículo da "
        . "frota, só pra ver as opções disponíveis de pertinho). Termine com uma linha própria: "
        . "'🔥 PRONTO PRA FECHAR' se a pessoa já CONFIRMOU um dia/horário de visita e tem forma "
        . "de pagamento definida (já ter visto foto/vídeo de um veículo específico é um bônus, "
        . "nunca pré-requisito — é só o vendedor ligar pra reforçar e confirmar); "
        . "'📞 FECHAR SÓ POR TELEFONE' se ela recusou vir presencialmente mas o resto está "
        . "qualificado; ou '🌱 PRECISA DE MAIS CONVERSA' se ainda falta engajamento/decisão real. "
        . "Não invente nenhum dado que não apareceu na conversa.\n\nConversa:\n{$transcript}";
    return iaChamarComFallback($prompt, 400, 0.3);
}

/**
 * Aplica os dados extraídos na negociação de venda — fill-if-empty, mesma
 * regra de iaAplicarDadosExtraidos() (ia_qualificacao.php). Exceções com
 * semântica própria: temperatura_lead (leitura viva, pode mudar de turno
 * pra turno), aceita_visitar_fastcar (0/false é resposta válida — recusou
 * —, então checa NULL, não string vazia) e visita_dia_horario_texto
 * (SEMPRE sobrescreve — a pessoa pode remarcar o dia/horário combinado).
 */
/** Entrada mínima por tipo de veículo (regra do Jean, 24/10/2026). Tipo desconhecido = regra de carro. */
function vendaEntradaMinima(?string $tipoVeiculo, ?string $tipoUso = null): float {
    if ($tipoVeiculo === 'moto') return 5000.0;
    return $tipoUso === 'aplicativo' ? 14000.0 : 10000.0;
}

function vendaTemperaturaPorEntrada(float $entrada, ?string $tipoVeiculo, ?string $tipoUso = null): string {
    if ($entrada >= 15000) return 'quente';
    if ($entrada >= vendaEntradaMinima($tipoVeiculo, $tipoUso)) return 'morno';
    return 'frio';
}

function iaAplicarDadosExtraidosVenda(int $vendaId, array $dados): bool {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM vendas WHERE id = ?");
    $stmt->execute([$vendaId]);
    $v = $stmt->fetch();
    if (!$v) return false;

    $avancouDadoReal = false;
    $visitaConfirmadaAgora = false; // 22/10/2026, notificarVisitaConfirmadaVenda() — ver abaixo
    $campos = ['veiculo_interesse_texto', 'tipo_uso_veiculo', 'forma_pagamento_pretendida', 'urgencia'];
    $sets = [];
    $params = [];
    foreach ($campos as $c) {
        $vazio = $v[$c] === '' || $v[$c] === null;
        if ($vazio && isset($dados[$c]) && $dados[$c] !== null && $dados[$c] !== '') {
            $sets[] = "{$c} = ?";
            $params[] = $dados[$c];
            $avancouDadoReal = true;
        }
    }
    if (($v['comprador_nome'] === '' || $v['comprador_nome'] === null) && !empty($dados['nome_comprador'])) {
        $sets[] = 'comprador_nome = ?';
        $params[] = clean((string)$dados['nome_comprador']);
        $avancouDadoReal = true;
    }
    foreach (['valor_entrada_disponivel', 'valor_parcela_orcamento'] as $c) {
        if (!isset($dados[$c]) || $dados[$c] === null || $dados[$c] === '') continue;
        $novo = (float)str_replace(',', '.', (string)$dados[$c]);
        // fill-if-empty — exceção: valor MAIOR que o já salvo (a pessoa
        // conseguiu juntar mais pra chegar no mínimo de entrada) atualiza.
        if ($v[$c] === null || $novo > (float)$v[$c]) {
            $sets[] = "{$c} = ?";
            $params[] = $novo;
            $v[$c] = $novo;
            $avancouDadoReal = true;
        }
    }

    if (in_array($dados['tipo_veiculo_interesse'] ?? null, ['carro', 'moto', 'outro'], true)
        && $dados['tipo_veiculo_interesse'] !== $v['tipo_veiculo_interesse']) {
        $sets[] = 'tipo_veiculo_interesse = ?';
        $params[] = $dados['tipo_veiculo_interesse'];
        $v['tipo_veiculo_interesse'] = $dados['tipo_veiculo_interesse'];
    }

    // 24/10/2026, regra do Jean: não vende sem entrada; mínimo R$ 10 mil
    // carro / R$ 5 mil moto. R$ 15 mil ou mais = quente, do mínimo até
    // 14.999 = morno, abaixo do mínimo (ou nada) = frio. Quando o valor de
    // entrada já é conhecido, ele DECIDE a temperatura (nunca o julgamento
    // da IA); sem valor ainda, vale a leitura da IA de sempre.
    if ($v['valor_entrada_disponivel'] !== null) {
        $dados['temperatura_lead'] = vendaTemperaturaPorEntrada((float)$v['valor_entrada_disponivel'], $v['tipo_veiculo_interesse'] ?? null, $v['tipo_uso_veiculo'] ?: ($dados['tipo_uso_veiculo'] ?? null));
    }

    // aceita_visitar_fastcar: 0/false é resposta legítima ("recusou vir,
    // prefere fechar por telefone"), não "vazio" — só entra quando ainda
    // está NULL (nunca foi perguntado/respondido), mesmo tri-state de
    // oportunidades.aceita_ligacao_consultor. 15/10/2026, "ajuda construir
    // qualificação da ia para venda fazer cliente agentdar visita".
    // 24/10/2026, Jean: abaixo da entrada mínima não tem agendamento.
    $abaixoDoMinimo = $v['valor_entrada_disponivel'] !== null
        && (float)$v['valor_entrada_disponivel'] < vendaEntradaMinima($v['tipo_veiculo_interesse'] ?? null, $v['tipo_uso_veiculo'] ?: ($dados['tipo_uso_veiculo'] ?? null));
    if ($abaixoDoMinimo) unset($dados['aceita_visitar_fastcar'], $dados['visita_dia_horario_texto'], $dados['visita_data_resolvida']);
    if ($v['aceita_visitar_fastcar'] === null && isset($dados['aceita_visitar_fastcar']) && $dados['aceita_visitar_fastcar'] !== null) {
        $sets[] = "aceita_visitar_fastcar = ?";
        $params[] = $dados['aceita_visitar_fastcar'] ? 1 : 0;
        if ($dados['aceita_visitar_fastcar']) {
            $sets[] = "visita_confirmada_em = datetime('now','localtime')";
            $visitaConfirmadaAgora = true;
        }
        $avancouDadoReal = true;
    }
    // visita_dia_horario_texto: diferente dos campos acima, SEMPRE
    // sobrescreve (nunca fill-if-empty) — a pessoa pode remarcar o dia/
    // horário no meio da conversa, e o texto mais recente é sempre o que
    // vale; só grava quando o valor novo é genuinamente diferente do já
    // salvo, pra não contar reconfirmação do mesmo dia como "avanço" à toa.
    if (!empty($dados['visita_dia_horario_texto'])) {
        $novoTexto = clean((string)$dados['visita_dia_horario_texto']);
        if ($novoTexto !== (string)$v['visita_dia_horario_texto']) {
            $sets[] = "visita_dia_horario_texto = ?";
            $params[] = $novoTexto;
            $avancouDadoReal = true;
        }
    }
    // visita_data_resolvida: mesma disciplina do texto livre acima
    // (sempre sobrescreve quando muda — remarcação —, nunca
    // fill-if-empty), mas só grava quando a validação aceita o valor —
    // nunca confia cegamente na matemática de data da própria IA.
    if (isset($dados['visita_data_resolvida'])) {
        $dataValida = iaVendasDataResolvidaValida($dados['visita_data_resolvida']);
        if ($dataValida !== null && $dataValida !== (string)$v['visita_data_resolvida']) {
            $sets[] = "visita_data_resolvida = ?";
            $params[] = $dataValida;
            $avancouDadoReal = true;
        }
    }

    // temperatura_lead: julgamento vivo da IA sobre a conversa até agora —
    // sempre atualiza quando a IA reavalia (mesmo padrão de
    // includes/ia_qualificacao.php, "crm tem tá preechido igual na compra
    // lead quente frio e mornos"), de propósito NÃO conta como "avanço"
    // pro contador de estagnação.
    if (!empty($dados['temperatura_lead']) && in_array($dados['temperatura_lead'], ['frio', 'morno', 'quente'], true)
        && $dados['temperatura_lead'] !== $v['temperatura_lead']) {
        $sets[] = "temperatura_lead = ?";
        $params[] = $dados['temperatura_lead'];
    }
    $virouQuente = ($dados['temperatura_lead'] ?? null) === 'quente' && $v['temperatura_lead'] !== 'quente';

    if ($sets) {
        $params[] = $vendaId;
        $db->prepare("UPDATE vendas SET " . implode(', ', $sets) . ", updated_at = datetime('now','localtime') WHERE id = ?")
           ->execute($params);
    }

    // 22/10/2026, "todo crm cliente aceitar vim aqui nas fastcar notificar
    // whatsapp do sistema notificação" — só DEPOIS do UPDATE commitar, pra
    // notificarVisitaConfirmadaVenda() (que faz um SELECT fresco) já ler o
    // visita_dia_horario_texto/visita_confirmada_em recém-gravados. Nunca
    // dispara de novo numa reconfirmação do mesmo "sim" — $visitaConfirmadaAgora
    // só fica true na transição NULL→1, mesma guarda de $v['aceita_visitar_fastcar']
    // === null lá em cima.
    if ($visitaConfirmadaAgora) {
        notificarVisitaConfirmadaVenda($vendaId);
    }
    // 24/10/2026, "lead quente no caso da venda enviar" — mesmos destinos da
    // visita (vendedor + números de Configurações); 1x por negociação, mesmo
    // se a temperatura oscilar morno↔quente depois.
    if ($virouQuente) {
        notificarLeadQuenteVenda($vendaId);
    }

    return $avancouDadoReal;
}

/** Quantos turnos seguidos sem avanço até desistir da IA e chamar um vendedor humano. */
const IA_VENDAS_LIMITE_TURNOS_SEM_AVANCO = 5;

/**
 * Orquestra um turno completo de qualificação do comprador — mesma
 * orquestração de iaProcessarTurno() (ia_qualificacao.php), mas escrevendo
 * em `vendas` em vez de `oportunidades`. Reaproveita
 * whatsapp_sessoes.turnos_sem_avanco (mesma tabela compartilhada por
 * telefone — não precisa de coluna própria) pro contador de estagnação.
 */
function iaProcessarTurnoVenda(int $vendaId, string $telefone): array {
    $credenciaisVendas = zapiCredenciaisVendas();
    $resultado = [
        'resposta' => '', 'enviada' => false, 'sem_perfil' => false,
        'qualificacao_completa' => false, 'escalado_sem_avanco' => false,
        'pedido_atendente_humano' => false,
    ];

    $resposta = iaGerarRespostaVenda($telefone);
    $resultado['resposta'] = $resposta;
    if ($resposta !== '') {
        $enviada = zapiEnviarTexto($telefone, $resposta, $credenciaisVendas);
        $resultado['enviada'] = $enviada;
        if ($enviada) {
            registrarMensagem($telefone, 'out', $resposta, null, true);
        }
    }

    $dados = iaExtrairDadosVenda($telefone);
    if (!$dados) {
        iaAtualizarContadorEstagnacao($telefone, false);
        return $resultado;
    }

    $avancou = iaAplicarDadosExtraidosVenda($vendaId, $dados);

    // Manda foto/vídeo do catálogo quando a IA identifica, com confiança,
    // QUAL veículo específico da frota bate com o interesse do comprador
    // ("ela precisa enviar fotos do veículos - vídeo", 17/09/2026) — nunca
    // mais de 1x pro mesmo veículo nesta conversa (midia_sugerida_enviada_para),
    // pra não floodar a cada turno. NUNCA vincula oportunidade_id sozinha
    // (isso continua exigindo confirmação humana, vincularVeiculoVenda())
    // — mandar a foto é só ilustrar a conversa, não é o vínculo oficial.
    $sugeridoId = isset($dados['oportunidade_id_sugerida']) ? (int)($dados['oportunidade_id_sugerida'] ?: 0) : 0;
    if ($sugeridoId > 0) {
        $db = getDB();
        $stmtV = $db->prepare("SELECT midia_sugerida_enviada_para FROM vendas WHERE id = ?");
        $stmtV->execute([$vendaId]);
        $jaEnviadoPara = (int)($stmtV->fetchColumn() ?: 0);

        if ($sugeridoId !== $jaEnviadoPara) {
            $stmtVeiculo = $db->prepare("SELECT veiculo_marca, veiculo_modelo, veiculo_ano FROM oportunidades WHERE id = ? AND etapa = 'fechado'");
            $stmtVeiculo->execute([$sugeridoId]);
            $veiculo = $stmtVeiculo->fetch();
            if ($veiculo) {
                $descricaoVeiculo = trim($veiculo['veiculo_marca'] . ' ' . $veiculo['veiculo_modelo'] . ' ' . $veiculo['veiculo_ano']);
                $envio = enviarMidiaCatalogoParaComprador($sugeridoId, $telefone, $descricaoVeiculo);
                if ($envio['fotos'] > 0 || $envio['video'] > 0) {
                    $descEnvio = trim(
                        ($envio['fotos'] > 0 ? "📷 {$envio['fotos']} foto(s)" : '')
                        . ($envio['video'] > 0 ? ' 🎥 vídeo' : '')
                    );
                    registrarMensagem($telefone, 'out', "[{$descEnvio} do {$descricaoVeiculo} enviado(s)]", null, true);
                    $db->prepare("UPDATE vendas SET midia_sugerida_enviada_para = ? WHERE id = ?")->execute([$sugeridoId, $vendaId]);
                }
            }
        }
    }

    // 01/10/2026 — conformidade WhatsApp (mesma regra do lado de compra, ver
    // ia_qualificacao.php): pedido EXPLÍCITO de atendente ("quero falar com
    // uma pessoa") escala direto pro vendedor, nunca insiste em continuar a
    // qualificação sozinha depois desse pedido.
    if (!empty($dados['pedido_atendente_humano'])) {
        $db = getDB();
        $stmt = $db->prepare("SELECT etapa FROM vendas WHERE id = ?");
        $stmt->execute([$vendaId]);
        $etapaAtual = $stmt->fetchColumn();
        if (in_array($etapaAtual, ['whatsapp', 'qualificacao_ia'], true)) {
            $resumo = iaGerarResumoVenda($telefone);
            $resumoPedido = ($resumo !== '' ? $resumo . "\n\n" : '')
                . '🙋 Comprador pediu explicitamente pra falar com uma pessoa da equipe.';
            $db->prepare("UPDATE vendas SET resumo_ia = ? WHERE id = ?")->execute([$resumoPedido, $vendaId]);
            mudarEtapaVenda($vendaId, 'crm_preenchido', null, 'Comprador pediu pra falar com atendente humano — encaminhado sem continuar a qualificação automática');
            notificarVendedorLeadQualificado($vendaId, '🙋 Comprador pediu atendimento humano');
        }
        $resultado['pedido_atendente_humano'] = true;
        return $resultado;
    }

    // 24/10/2026, Jean: "sem entrada descartar lead" — entrada 0 informada
    // descarta mesmo que a IA não tenha marcado sem_perfil.
    $stmtEnt = getDB()->prepare("SELECT valor_entrada_disponivel FROM vendas WHERE id = ?");
    $stmtEnt->execute([$vendaId]);
    $entradaAtual = $stmtEnt->fetchColumn();
    if (empty($dados['sem_perfil']) && $entradaAtual !== null && $entradaAtual !== false && (float)$entradaAtual <= 0) {
        $dados['sem_perfil'] = true;
        $dados['motivo_sem_perfil'] = 'Sem entrada — Fastcar não vende sem entrada (pode voltar quando tiver o mínimo)';
    }
    if (!empty($dados['sem_perfil'])) {
        mudarEtapaVenda($vendaId, 'sem_perfil', null, (string)($dados['motivo_sem_perfil'] ?: 'IA identificou que a pessoa não quer mais comprar'));
        $resultado['sem_perfil'] = true;
        return $resultado;
    }

    if (!empty($dados['qualificacao_completa'])) {
        $db = getDB();
        $stmt = $db->prepare("SELECT etapa FROM vendas WHERE id = ?");
        $stmt->execute([$vendaId]);
        $etapaAtual = $stmt->fetchColumn();
        if (!in_array($etapaAtual, ['whatsapp', 'qualificacao_ia'], true)) return $resultado;

        $resumo = iaGerarResumoVenda($telefone);
        if ($resumo !== '') {
            $db->prepare("UPDATE vendas SET resumo_ia = ? WHERE id = ?")->execute([$resumo, $vendaId]);
        }
        mudarEtapaVenda($vendaId, 'crm_preenchido', null, 'Qualificação IA concluída — encaminhado pro vendedor');
        notificarVendedorLeadQualificado($vendaId, 'Qualificação concluída');
        $resultado['qualificacao_completa'] = true;
        return $resultado;
    }

    $turnosSemAvanco = iaAtualizarContadorEstagnacao($telefone, $avancou);
    if ($turnosSemAvanco >= IA_VENDAS_LIMITE_TURNOS_SEM_AVANCO) {
        $db = getDB();
        $stmt = $db->prepare("SELECT etapa FROM vendas WHERE id = ?");
        $stmt->execute([$vendaId]);
        $etapaAtual = $stmt->fetchColumn();
        if (in_array($etapaAtual, ['whatsapp', 'qualificacao_ia'], true)) {
            $resumo = iaGerarResumoVenda($telefone);
            $resumoFinal = ($resumo !== '' ? $resumo . "\n\n" : '')
                . '⚠️ IA encaminhou pro vendedor por estagnação — conversa passou de '
                . IA_VENDAS_LIMITE_TURNOS_SEM_AVANCO . ' turnos sem novo dado confirmado.';
            $db->prepare("UPDATE vendas SET resumo_ia = ? WHERE id = ?")->execute([$resumoFinal, $vendaId]);
            mudarEtapaVenda($vendaId, 'crm_preenchido', null, 'IA escalou pro vendedor — conversa estagnada sem avanço de dados');
            notificarVendedorLeadQualificado($vendaId, 'IA escalou por estagnação');
            $resultado['escalado_sem_avanco'] = true;
        }
    }

    return $resultado;
}
