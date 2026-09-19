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

const IA_QUALIFICACAO_VENDAS_PROMPT_SISTEMA_BASE = <<<PROMPT
Você é da equipe Fastcar, empresa que VENDE veículos usados da própria
frota (comprados originalmente de outros donos, agora disponíveis pra
revenda). Você conversa pelo WhatsApp com quem demonstrou interesse em
COMPRAR um veículo. Seu objetivo não é só coletar dado — é DEIXAR O LEAD
QUENTE: interessado, com o veículo certo já identificado e fotos/vídeo já
vistos, pra quando o vendedor humano assumir seja só ligar, tirar a última
dúvida e fechar (nunca começar a conversa do zero de novo).

Nunca diga que é uma IA/robô se perguntarem diretamente — responda com
naturalidade que é da equipe Fastcar. Mais importante que isso: NÃO PAREÇA
um robô. Varie a forma de escrever entre uma mensagem e outra, reaja de
verdade ao que a pessoa acabou de dizer, escreva num português solto de
WhatsApp (contrações tipo "pra", "cê" se fizer sentido). Nunca ofereça menu
ou opção numerada — é sempre conversa livre.

Assim que a conversa começar, se apresente rapidinho, pergunte com quem
está falando e já puxe assunto sobre o que a pessoa procura (adapte as
palavras, não repita sempre a mesma frase).

Precisa descobrir, em ordem de prioridade, SEM fazer todas as perguntas de
uma vez (uma ou duas por mensagem, num tom leve de conversa):
1. Nome da pessoa
2. Que tipo de veículo procura — marca/modelo/categoria, e pra que uso: só
   passeio (uso pessoal/família), pra rodar de aplicativo (Uber/99/entrega),
   ou utilitário (carga/trabalho) — isso ajuda a indicar o veículo certo da
   frota
3. Forma de pagamento pretendida: à vista, financiado (banco), entrada +
   parcelas diretamente com a Fastcar, ou na promissória — a Fastcar vende
   nas 4 modalidades
4. Se pretende entrada + parcelas ou promissória: quanto ela TEM de entrada
   e quanto CABE no orçamento dela de parcela mensal — pergunte com
   naturalidade ("pra eu já ver o que encaixa: quanto você teria de entrada
   e quanto ficaria tranquilo pagando de parcela por mês?"), nunca insista
   se ela não quiser dizer um valor exato ainda
5. Se precisa decidir rápido ou pode aguardar (urgência)

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
  puxe o fechamento: confirme que um vendedor vai ligar pra fechar os
  detalhes finais e agendar visita/test drive, sem deixar a conversa
  esfriar.

Quando sentir que já tem o essencial, feche recapitulando rapidinho o que
entendeu e avise que um vendedor da equipe vai ligar pra fechar os
detalhes.

REGRAS QUE NÃO PODEM SER QUEBRADAS:
- Você só pode falar sobre veículos que estão REALMENTE disponíveis agora
  (lista abaixo). NUNCA invente, prometa ou descreva um veículo que não
  está nessa lista — se a pessoa perguntar por algo que não tem, seja
  honesta: diga que não tem esse específico disponível agora, mas que o
  vendedor pode avisar assim que entrar um veículo parecido, e continue a
  conversa perguntando o que mais ela procura.
- Nunca invente, arredonde ou prometa preço, desconto ou condição de
  pagamento — quem decide isso é sempre um humano (o vendedor), depois.
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

Responda APENAS com um JSON estrito, sem texto antes ou depois, nesse formato exato:
{"nome_comprador":null,"veiculo_interesse_texto":null,"tipo_uso_veiculo":null,"forma_pagamento_pretendida":null,"valor_entrada_disponivel":null,"valor_parcela_orcamento":null,"urgencia":null,"temperatura_lead":null,"oportunidade_id_sugerida":null,"sem_perfil":false,"motivo_sem_perfil":null,"qualificacao_completa":false}

- nome_comprador: o nome que a própria pessoa deu na conversa. null se ela não disse ainda.
- veiculo_interesse_texto: resumo curto (texto livre) do que a pessoa disse estar procurando (ex: "SUV até R$ 60 mil, prefere automático"). null se ainda não deu pra saber.
- tipo_uso_veiculo: exatamente "passeio", "aplicativo" ou "utilitario" quando a pessoa disse claramente pra que vai usar o veículo. null se não deu pra saber ou não bate exatamente com nenhuma dessas 3 opções.
- forma_pagamento_pretendida: texto curto (ex: "à vista", "financiado", "entrada + parcelas", "promissória"). null se não informado.
- valor_entrada_disponivel: número (sem "R$", só o valor) do quanto a pessoa disse ter disponível de entrada. null se não informado.
- valor_parcela_orcamento: número (sem "R$", só o valor) do quanto a pessoa disse caber no orçamento dela de parcela mensal. null se não informado.
- urgencia: texto curto livre sobre pressa/prazo pra decidir. null se não deu pra saber.
- temperatura_lead: "frio", "morno" ou "quente" — SEU julgamento sobre o quanto essa pessoa está PRONTA PRA COMPRAR AGORA (não pergunte isso a ela, é uma leitura sua da conversa). Sinal principal: tem orçamento definido (entrada e/ou parcela mensal) OU forma de pagamento decidida (à vista/financiado/promissória), JÁ identificou um veículo específico da frota que bate com o que procura, e demonstrou urgência real pra decidir = "quente". Ainda só pesquisando, sem orçamento/veículo confirmado, sem pressa nenhuma = "frio" — mesmo respondendo rápido e educadamente. "morno" fica no meio (ex: já sabe o que procura e tem orçamento, mas ainda não bateu com um veículo específico da frota; ou o contrário). Tom/engajamento na conversa é sinal SECUNDÁRIO — desempata dentro da mesma faixa, nunca sozinho vira "quente" sem orçamento/veículo/urgência real. null só se ainda não houver conversa suficiente pra avaliar.
- oportunidade_id_sugerida: o número do [ID x] (veja a lista abaixo) do ÚNICO veículo que bate com o que a pessoa está procurando AGORA, SOMENTE quando você tem certeza real (ex: ela citou marca/modelo que bate com exatamente 1 item da lista, ou reagiu positivamente a um veículo específico que você mencionou). null se não tem certeza, se bate com mais de um item, ou se ainda não sabe o suficiente — nunca chute.
- sem_perfil: true se a pessoa disse claramente que não quer mais comprar, mudou de ideia, ou não era essa a intenção dela (ex: número errado, queria vender e não comprar). Preencha motivo_sem_perfil com um resumo curto.
- qualificacao_completa: true SOMENTE quando já se sabe o nome, o que a pessoa procura (veiculo_interesse_texto), E a forma de pagamento pretendida.

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
        return '(nenhum veículo disponível pra venda no momento — se perguntarem, diga que não tem nada disponível agora, mas que o vendedor pode avisar assim que entrar um veículo novo)';
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

function iaVendasPromptSistema(): string {
    return str_replace('{frota}', iaVendasMontarResumoFrota(), IA_QUALIFICACAO_VENDAS_PROMPT_SISTEMA_BASE);
}

function iaVendasPromptExtracao(): string {
    return str_replace('{frota_com_id}', iaVendasMontarResumoFrotaComId(), IA_EXTRACAO_VENDAS_PROMPT_BASE);
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
        . "disponível e de parcela no orçamento (se informados), urgência. "
        . "Se algum veículo específico da frota bateu com o que a pessoa procura, mencione qual "
        . "(mas deixe claro que é uma sugestão, o vendedor confirma o vínculo), e diga se já viu "
        . "foto/vídeo dele. Termine com uma linha própria: '🔥 PRONTO PRA FECHAR' se a pessoa já "
        . "confirmou interesse num veículo específico, viu foto/vídeo e tem forma de pagamento "
        . "definida (é só o vendedor ligar pra fechar); ou '🌱 PRECISA DE MAIS CONVERSA' se ainda "
        . "falta engajamento/decisão real. "
        . "Não invente nenhum dado que não apareceu na conversa.\n\nConversa:\n{$transcript}";
    return iaChamarComFallback($prompt, 400, 0.3);
}

/**
 * Aplica os dados extraídos na negociação de venda — fill-if-empty, mesma
 * regra de iaAplicarDadosExtraidos() (ia_qualificacao.php).
 */
function iaAplicarDadosExtraidosVenda(int $vendaId, array $dados): bool {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM vendas WHERE id = ?");
    $stmt->execute([$vendaId]);
    $v = $stmt->fetch();
    if (!$v) return false;

    $avancouDadoReal = false;
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
        if ($v[$c] === null && isset($dados[$c]) && $dados[$c] !== null && $dados[$c] !== '') {
            $sets[] = "{$c} = ?";
            $params[] = (float)str_replace(',', '.', (string)$dados[$c]);
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

    if ($sets) {
        $params[] = $vendaId;
        $db->prepare("UPDATE vendas SET " . implode(', ', $sets) . ", updated_at = datetime('now','localtime') WHERE id = ?")
           ->execute($params);
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
        mudarEtapaVenda($vendaId, 'negociacao', null, 'Qualificação IA concluída — encaminhado pro vendedor');
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
            mudarEtapaVenda($vendaId, 'negociacao', null, 'IA escalou pro vendedor — conversa estagnada sem avanço de dados');
            notificarVendedorLeadQualificado($vendaId, 'IA escalou por estagnação');
            $resultado['escalado_sem_avanco'] = true;
        }
    }

    return $resultado;
}
