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
COMPRAR um veículo, ANTES de passar pra um vendedor humano.

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
2. Que tipo de veículo procura (marca/modelo/categoria, faixa de preço se
   ela quiser dizer)
3. Forma de pagamento pretendida (à vista, financiado, entrada + parcelas)
4. Se precisa decidir rápido ou pode aguardar (urgência)

Quando sentir que já tem o essencial, feche recapitulando rapidinho o que
entendeu e avise que um vendedor da equipe vai continuar a conversa com
mais detalhes/fotos dos veículos disponíveis.

REGRAS QUE NÃO PODEM SER QUEBRADAS:
- Você só pode falar sobre veículos que estão REALMENTE disponíveis agora
  (lista abaixo). NUNCA invente, prometa ou descreva um veículo que não
  está nessa lista — se a pessoa perguntar por algo que não tem, seja
  honesta: diga que não tem esse específico disponível agora, mas que o
  vendedor pode avisar assim que entrar um veículo parecido, e continue a
  conversa perguntando o que mais ela procura.
- Nunca invente, arredonde ou prometa preço — quem decide isso é sempre um
  humano (o vendedor), depois. Se perguntarem valor de um veículo da lista,
  pode informar o valor de referência que está na lista (se tiver), sempre
  deixando claro que o valor final é confirmado com o vendedor.
- Se o cliente disser claramente que não quer mais comprar, mudou de ideia,
  ou não é essa a intenção dele (ex: número errado, queria vender e não
  comprar) — agradeça e encerre com educação, sem insistir.
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
{"nome_comprador":null,"veiculo_interesse_texto":null,"forma_pagamento_pretendida":null,"urgencia":null,"sem_perfil":false,"motivo_sem_perfil":null,"qualificacao_completa":false}

- nome_comprador: o nome que a própria pessoa deu na conversa. null se ela não disse ainda.
- veiculo_interesse_texto: resumo curto (texto livre) do que a pessoa disse estar procurando (ex: "SUV até R$ 60 mil, prefere automático"). null se ainda não deu pra saber.
- forma_pagamento_pretendida: texto curto (ex: "à vista", "financiado", "entrada + parcelas"). null se não informado.
- urgencia: texto curto livre sobre pressa/prazo pra decidir. null se não deu pra saber.
- sem_perfil: true se a pessoa disse claramente que não quer mais comprar, mudou de ideia, ou não era essa a intenção dela (ex: número errado, queria vender e não comprar). Preencha motivo_sem_perfil com um resumo curto.
- qualificacao_completa: true SOMENTE quando já se sabe o nome, o que a pessoa procura (veiculo_interesse_texto), E a forma de pagamento pretendida.

VEÍCULOS DISPONÍVEIS AGORA NA FASTCAR (contexto, pra você entender do que a conversa está falando):
{frota}

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

function iaVendasPromptSistema(): string {
    return str_replace('{frota}', iaVendasMontarResumoFrota(), IA_QUALIFICACAO_VENDAS_PROMPT_SISTEMA_BASE);
}

function iaVendasPromptExtracao(): string {
    return str_replace('{frota}', iaVendasMontarResumoFrota(), IA_EXTRACAO_VENDAS_PROMPT_BASE);
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
        . "Cubra: nome do comprador, o que procura, forma de pagamento pretendida, urgência. "
        . "Se algum veículo específico da frota bateu com o que a pessoa procura, mencione qual "
        . "(mas deixe claro que é uma sugestão, o vendedor confirma o vínculo). "
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
    $campos = ['veiculo_interesse_texto', 'forma_pagamento_pretendida', 'urgencia'];
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
