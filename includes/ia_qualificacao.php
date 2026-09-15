<?php
/**
 * Qualificação por IA — bloco 3 do funil. Resolve a pendência #3 do
 * CLAUDE.md: mesmo provedor e padrão do JurídicoSaaS — Gemini como
 * principal (includes/gemini.php, fallback de modelo aposentado já
 * embutido lá), OpenAI/GPT como fallback duplo (includes/openai.php)
 * quando o Gemini falha ou não está configurado — mesmo padrão de
 * chatbot-whatsapp/includes/whatsapp_bot.php::classificarAreaComGemini()
 * do JurídicoSaaS.
 *
 * ⚠️ O ROTEIRO/PROMPT abaixo é um RASCUNHO baseado só no que o CLAUDE.md já
 * define que precisa ser coletado (modelo, ano, banco, parcela, parcelas
 * restantes, atraso, cidade/estado, valor pretendido) — a ORDEM das
 * perguntas e o TOM de voz ainda não foram validados com o Jean. Revisar
 * antes de considerar definitivo (fica registrado como pendência de
 * validação no CLAUDE.md).
 *
 * Regra #3 do CLAUDE.md ("IA nunca inventa informação") é a linha mestra
 * de todo este arquivo: a extração de dados só preenche o que o cliente
 * disse explicitamente, nunca deduz ou arredonda.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/gemini.php';
require_once __DIR__ . '/openai.php';
require_once __DIR__ . '/oportunidades.php';

const IA_QUALIFICACAO_PROMPT_SISTEMA = <<<PROMPT
Você é da equipe Fastcar, empresa que COMPRA veículos que o dono ainda está
pagando financiado (o carro continua com financiamento em aberto, a Fastcar
assume a negociação com o banco). Você conversa pelo WhatsApp com quem
demonstrou interesse em vender o carro financiado, ANTES de passar pra um
consultor humano.

Nunca diga que é uma IA/robô se perguntarem diretamente — responda com
naturalidade que é da equipe Fastcar. Mais importante que isso: NÃO PAREÇA
um robô. Varie a forma de escrever entre uma mensagem e outra (nunca repita
a mesma estrutura de frase sempre), reaja de verdade ao que a pessoa acabou
de dizer antes de emendar a próxima pergunta, escreva num português solto
de WhatsApp (contrações tipo "pra", "cê" se fizer sentido, sem soar
formulário/roteiro). Nunca ofereça menu ou opção numerada — é sempre
conversa livre, a pessoa responde com as próprias palavras.

Assim que a conversa começar, se apresente rapidinho, pergunte com quem
está falando e já puxe assunto sobre o carro — no espírito de "Oi! Aqui é
da Fastcar 🚗 Com quem eu falo? E me conta rapidinho: qual é o modelo e o
ano do carro que você quer vender?" (adapte as palavras, não repita sempre
a frase igual — pode perguntar o nome já na primeira mensagem ou logo na
troca seguinte, o que soar mais natural). Se a pessoa já disser o nome sem
você perguntar, chame ela pelo nome depois — não pergunte de novo.

Precisa descobrir, em ordem de prioridade, SEM fazer todas as perguntas de
uma vez (uma ou duas por mensagem, num tom leve de conversa):
1. Nome da pessoa (como ela prefere ser chamada)
2. Marca, modelo e ano do veículo
3. Se ainda tem financiamento em aberto — banco, valor da parcela, quantas
   parcelas faltam, se tem alguma parcela atrasada
4. Cidade/estado onde o cliente está
5. Quanto o cliente gostaria de receber pelo veículo
6. Se precisa resolver isso rápido ou pode aguardar um pouco (urgência)

Perto do final, já com as informações principais em mãos, peça uma foto do
veículo de um jeito natural (ex: "manda uma fotinho dele pra eu dar uma
olhada?") — não insista se a pessoa não mandar, só pergunta uma vez.

Quando sentir que já tem o essencial, feche recapitulando rapidinho o que
entendeu (ex: "Show! Então recapitulando: [resumo curto do que foi dito] —
é isso mesmo?") e pergunte se pode passar o contato pra um consultor ligar.
Espere a resposta antes de considerar a conversa concluída.

Em algum momento da 1ª ou 2ª mensagem seguintes, sem soar burocrático,
avise em UMA frase curta que os dados são usados só pra avaliar a proposta
de compra do veículo (ex: "É rapidinho, uso essas informações só pra já te
trazer uma proposta certeira").

Se o cliente perguntar "quanto vocês pagam?"/"quanto dá pelo meu carro?" logo
de cara (é praticamente sempre a 1ª pergunta) — nunca ignore nem repita só
"isso o consultor vê depois" seco, isso soa evasivo. Explique em 1 frase
natural que a proposta depende de ver os detalhes reais do carro e do
financiamento, e emende puxando a próxima pergunta que falta (ex: "Isso
o consultor calcula certinho depois de ver os detalhes — mas já adianto:
me conta o modelo/ano que você quer vender?").

Se o cliente hesitar ou desconfiar de passar dado financeiro (banco, valor
da parcela) por WhatsApp — desconfiança legítima hoje em dia — explique em
1 frase por que a Fastcar precisa saber disso: é pra negociar direto com o
banco a transferência do financiamento, não é feito à toa. Nunca insista
depois de explicar 1 vez; se a pessoa continuar sem querer informar, segue
com o que já tiver e deixa o resto pro consultor confirmar por outro canal.

REGRAS QUE NÃO PODEM SER QUEBRADAS:
- Nunca invente, arredonde ou deduza um valor que o cliente não disse.
- Nunca prometa valor de compra, prazo ou condição — quem decide isso é
  sempre um humano (o consultor), depois.
- Se o cliente disser que o veículo já está quitado, tudo bem, só marque
  isso e não pergunte de banco/parcela.
- Se o cliente disser claramente que não quer vender, mudou de ideia, ou
  não tem perfil (ex: não é o dono do veículo), agradeça e encerre com
  educação — não insista.
- Seja breve. Mensagens curtas, como alguém digitando no celular.
PROMPT;

const IA_EXTRACAO_PROMPT = <<<PROMPT
Analise a conversa de WhatsApp abaixo entre a Fastcar (compra veículos
financiados) e um cliente. Extraia SOMENTE o que o cliente disse
explicitamente — nunca invente, deduza ou arredonde um valor não
mencionado. Campo não informado = null.

Responda APENAS com um JSON estrito, sem texto antes ou depois, nesse formato exato:
{"nome_cliente":null,"veiculo_marca":null,"veiculo_modelo":null,"veiculo_ano":null,"banco_financiamento":null,"valor_parcela":null,"parcelas_restantes":null,"parcelas_atraso":null,"cidade":null,"estado":null,"valor_pretendido":null,"urgencia":null,"temperatura_lead":null,"aceita_ligacao_consultor":null,"sem_perfil":false,"motivo_sem_perfil":null,"qualificacao_completa":false}

- nome_cliente: o nome que a própria pessoa deu na conversa (nunca o que já estava salvo antes). null se ela não disse o nome ainda.
- valor_parcela e valor_pretendido: número (sem "R$", sem separador de milhar; use ponto decimal). null se não informado.
- parcelas_restantes e parcelas_atraso: número inteiro. Se o cliente disse "já está quitado", parcelas_restantes=0 e banco_financiamento pode ficar null.
- urgencia: texto curto livre resumindo o que a pessoa disse sobre pressa/prazo (ex: "precisa vender essa semana, atrasando parcela", "sem pressa, só pesquisando"). null se não deu pra saber ainda.
- temperatura_lead: "frio", "morno" ou "quente" — SEU julgamento sobre o quanto essa pessoa parece engajada/decidida a vender AGORA, com base no tom e nas respostas até aqui (não pergunte isso ao cliente, é uma leitura sua da conversa). "quente" = respondendo rápido, decidido, já quer prosseguir; "morno" = interessado mas hesitante ou devagar; "frio" = respostas evasivas, parece só curioso ou sumindo. Preencha sempre que já houver conversa suficiente pra avaliar, mesmo que ainda não tenha todos os dados do veículo.
- aceita_ligacao_consultor: true se a pessoa confirmou que um consultor pode ligar, false se ela recusou/preferiu só texto, null se ainda não foi perguntado ou ela não respondeu isso.
- sem_perfil: true SOMENTE se o cliente disse claramente que não quer vender, não tem interesse, ou não se enquadra (não é o dono, veículo já vendido, etc).
- qualificacao_completa: true SOMENTE quando já se sabe modelo+ano, a situação do financiamento (banco+parcela OU confirmação de quitado), o valor pretendido pelo cliente, E a pessoa já respondeu se aceita a ligação do consultor (aceita_ligacao_consultor não é mais null).

Conversa:
PROMPT;

/** Monta o histórico da conversa no formato que a API Gemini espera. */
function iaMontarHistoricoGemini(string $telefone, int $limite = 30): array {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT direcao, mensagem FROM whatsapp_mensagens
        WHERE telefone = ? AND tipo = 'text'
        ORDER BY id DESC LIMIT ?
    ");
    $stmt->bindValue(1, normalizarTelefone($telefone));
    $stmt->bindValue(2, $limite, PDO::PARAM_INT);
    $stmt->execute();
    $linhas = array_reverse($stmt->fetchAll());

    $historico = [];
    foreach ($linhas as $l) {
        $historico[] = [
            'role' => $l['direcao'] === 'in' ? 'user' : 'model',
            'parts' => [['text' => $l['mensagem']]],
        ];
    }
    return $historico;
}

/** Gera a próxima resposta conversacional da IA — '' se falhar ou sem chave configurada. */
function iaGerarResposta(string $telefone): string {
    $historico = iaMontarHistoricoGemini($telefone);
    if (!$historico) return '';

    $geminiKey = getConfig('gemini_api_key') ?: '';
    if ($geminiKey) {
        $resposta = geminiCallChat(IA_QUALIFICACAO_PROMPT_SISTEMA, $historico, $geminiKey, getConfig('gemini_model') ?: 'gemini-3.5-flash-lite');
        if ($resposta !== '') return $resposta;
    }

    // Fallback duplo (mesmo padrão do JurídicoSaaS) — só entra em cena se
    // o Gemini falhou ou não está configurado.
    $openaiKey = getConfig('openai_api_key') ?: '';
    if ($openaiKey) {
        return openaiCallChat(IA_QUALIFICACAO_PROMPT_SISTEMA, $historico, $openaiKey, getConfig('openai_model') ?: 'gpt-4o-mini');
    }

    return '';
}

/**
 * Chama Gemini (prompt único, sem histórico) com fallback pro GPT — mesmo
 * padrão do JurídicoSaaS: tenta o principal, só cai pro GPT se o Gemini
 * falhar ou não tiver chave configurada.
 */
function iaChamarComFallback(string $prompt, int $maxTokens, float $temp): string {
    $geminiKey = getConfig('gemini_api_key') ?: '';
    if ($geminiKey) {
        $resposta = geminiCall($prompt, $geminiKey, getConfig('gemini_model') ?: 'gemini-3.5-flash-lite', $maxTokens, $temp);
        if (!is_array($resposta) && $resposta !== '') return $resposta;
    }

    $openaiKey = getConfig('openai_api_key') ?: '';
    if ($openaiKey) {
        $resposta = openaiCall($prompt, $openaiKey, getConfig('openai_model') ?: 'gpt-4o-mini', $maxTokens, $temp);
        if (!is_array($resposta) && $resposta !== '') return $resposta;
    }

    return '';
}

/**
 * Extrai dados estruturados da conversa até agora. Retorna null se a API
 * falhar ou não tiver chave — nunca lança, nunca bloqueia o fluxo principal.
 */
function iaExtrairDados(string $telefone): ?array {
    $historico = iaMontarHistoricoGemini($telefone, 40);
    if (!$historico) return null;
    if (!getConfig('gemini_api_key') && !getConfig('openai_api_key')) return null;

    $transcript = '';
    foreach ($historico as $h) {
        $quem = $h['role'] === 'user' ? 'Cliente' : 'Fastcar';
        $transcript .= "{$quem}: " . $h['parts'][0]['text'] . "\n";
    }

    $resposta = iaChamarComFallback(IA_EXTRACAO_PROMPT . $transcript, 400, 0.1);
    if ($resposta === '') return null;

    // Gemini/GPT às vezes envolvem o JSON em ```json ... ``` mesmo pedindo texto puro
    $limpo = trim(preg_replace('/^```(json)?|```$/m', '', $resposta));
    $json = json_decode($limpo, true);
    return is_array($json) ? $json : null;
}

/** Gera um resumo curto da conversa pro consultor (oportunidades.resumo_ia). */
function iaGerarResumo(string $telefone): string {
    $historico = iaMontarHistoricoGemini($telefone, 40);
    if (!$historico) return '';
    if (!getConfig('gemini_api_key') && !getConfig('openai_api_key')) return '';

    $transcript = '';
    foreach ($historico as $h) {
        $quem = $h['role'] === 'user' ? 'Cliente' : 'Fastcar';
        $transcript .= "{$quem}: " . $h['parts'][0]['text'] . "\n";
    }

    $prompt = "Resuma esta conversa de WhatsApp pra um consultor humano que vai assumir o "
        . "atendimento agora — ele não leu nada ainda, então precisa entender rápido o que já "
        . "foi confirmado e o que ainda falta perguntar. Responda em formato de checklist, uma "
        . "linha por item, EXATAMENTE nesse padrão (mantendo os emojis ✅/⚠️, sem título nem "
        . "texto antes ou depois da lista):\n"
        . "✅ [dado que o cliente já confirmou, direto — ex: \"Onix 2019, financiado pelo Bradesco\"]\n"
        . "⚠️ [dado que AINDA falta confirmar/perguntar — só inclua o que realmente falta]\n\n"
        . "Cubra nessa ordem quando já tiver a informação (pule a linha ⚠️ de um item se ele já "
        . "estiver ✅, e pule tanto ✅ quanto ⚠️ se não fizer sentido pro caso, ex: banco quando "
        . "o carro já está quitado): nome do cliente, veículo (marca/modelo/ano), situação do "
        . "financiamento (banco/parcela/atraso ou quitado), cidade/estado, valor pretendido, "
        . "urgência, se aceita ligação do consultor, e se mandou foto do veículo. Termine com uma "
        . "linha 🌡️ [frio/morno/quente] com uma frase curta do motivo dessa temperatura. "
        . "Não invente nenhum dado que não apareceu na conversa.\n\nConversa:\n{$transcript}";
    return iaChamarComFallback($prompt, 400, 0.3);
}

/**
 * Aplica os dados extraídos na oportunidade — só preenche campo que
 * estava vazio, nunca sobrescreve o que já tinha (consultor pode ter
 * corrigido à mão depois, isso tem prioridade sobre a IA). Exceções com
 * semântica própria: temperatura_lead (é uma leitura viva da conversa, tem
 * que poder mudar de turno pra turno) e aceita_ligacao_consultor (0/false é
 * resposta válida — recusou —, então checa NULL, não string vazia).
 *
 * @return bool true se algum dado REAL novo avançou (usado pelo contador de
 *   estagnação em iaProcessarTurno() — reavaliar temperatura_lead sozinho
 *   não conta como avanço, senão o contador nunca chegaria no limite numa
 *   conversa que só fica repetindo educadamente sem sair do lugar).
 */
function iaAplicarDadosExtraidos(int $oportunidadeId, array $dados): bool {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM oportunidades WHERE id = ?");
    $stmt->execute([$oportunidadeId]);
    $op = $stmt->fetch();
    if (!$op) return false;

    $avancouDadoReal = false;

    $campos = [
        'veiculo_marca', 'veiculo_modelo', 'veiculo_ano', 'banco_financiamento',
        'valor_parcela', 'parcelas_restantes', 'parcelas_atraso', 'valor_pretendido',
        'urgencia',
    ];
    $sets = [];
    $params = [];
    foreach ($campos as $c) {
        $vazio = $op[$c] === '' || $op[$c] === null;
        if ($vazio && isset($dados[$c]) && $dados[$c] !== null && $dados[$c] !== '') {
            $sets[] = "{$c} = ?";
            $params[] = $dados[$c];
            $avancouDadoReal = true;
        }
    }

    // aceita_ligacao_consultor: 0/false é resposta legítima ("recusou"), não
    // "vazio" — só entra quando ainda está NULL (nunca foi respondido).
    if ($op['aceita_ligacao_consultor'] === null && isset($dados['aceita_ligacao_consultor']) && $dados['aceita_ligacao_consultor'] !== null) {
        $sets[] = "aceita_ligacao_consultor = ?";
        $params[] = $dados['aceita_ligacao_consultor'] ? 1 : 0;
        $avancouDadoReal = true;
    }

    // temperatura_lead: julgamento vivo da IA sobre a conversa até agora —
    // sempre atualiza quando a IA reavalia, de propósito NÃO conta como
    // "avanço" pro contador de estagnação (senão a conversa nunca escalaria
    // mesmo girando em círculos, já que esse campo muda sozinho todo turno).
    if (!empty($dados['temperatura_lead']) && in_array($dados['temperatura_lead'], ['frio', 'morno', 'quente'], true)
        && $dados['temperatura_lead'] !== $op['temperatura_lead']) {
        $sets[] = "temperatura_lead = ?";
        $params[] = $dados['temperatura_lead'];
    }

    if ($sets) {
        $params[] = $oportunidadeId;
        $db->prepare("UPDATE oportunidades SET " . implode(', ', $sets) . ", updated_at = datetime('now','localtime') WHERE id = ?")
           ->execute($params);
    }

    // nome/cidade/estado vivem no cliente, não na oportunidade
    if (!empty($dados['nome_cliente']) || !empty($dados['cidade']) || !empty($dados['estado'])) {
        $stmtC = $db->prepare("SELECT nome, cidade, estado FROM clientes WHERE id = ?");
        $stmtC->execute([$op['cliente_id']]);
        $cli = $stmtC->fetch();
        if ($cli) {
            $novoNome   = (!$cli['nome']   && !empty($dados['nome_cliente'])) ? $dados['nome_cliente'] : $cli['nome'];
            $novaCidade = (!$cli['cidade'] && !empty($dados['cidade']))       ? $dados['cidade']       : $cli['cidade'];
            $novoEstado = (!$cli['estado'] && !empty($dados['estado']))       ? $dados['estado']       : $cli['estado'];
            if ($novoNome !== $cli['nome'] || $novaCidade !== $cli['cidade'] || $novoEstado !== $cli['estado']) {
                $db->prepare("UPDATE clientes SET nome = ?, cidade = ?, estado = ? WHERE id = ?")
                   ->execute([$novoNome, $novaCidade, $novoEstado, $op['cliente_id']]);
                if ($novoNome !== $cli['nome']) $avancouDadoReal = true;
            }
        }
    }

    return $avancouDadoReal;
}

/** Quantos turnos seguidos sem avanço real até desistir da IA e chamar um humano. */
const IA_LIMITE_TURNOS_SEM_AVANCO = 5;

/**
 * Atualiza whatsapp_sessoes.turnos_sem_avanco: zera quando o turno atual
 * avançou algum dado real, incrementa quando não avançou nada. Upsert
 * (mesmo padrão de pausarIA()/retomarIA() em mensagens.php) porque a linha
 * pode ainda não existir pro telefone na 1ª qualificação.
 */
function iaAtualizarContadorEstagnacao(string $telefone, bool $avancou): int {
    $db = getDB();
    $telNorm = normalizarTelefone($telefone);

    if ($avancou) {
        $db->prepare("
            INSERT INTO whatsapp_sessoes (telefone, turnos_sem_avanco, updated_at)
            VALUES (?, 0, datetime('now','localtime'))
            ON CONFLICT(telefone) DO UPDATE SET turnos_sem_avanco = 0, updated_at = datetime('now','localtime')
        ")->execute([$telNorm]);
        return 0;
    }

    $db->prepare("
        INSERT INTO whatsapp_sessoes (telefone, turnos_sem_avanco, updated_at)
        VALUES (?, 1, datetime('now','localtime'))
        ON CONFLICT(telefone) DO UPDATE SET turnos_sem_avanco = turnos_sem_avanco + 1, updated_at = datetime('now','localtime')
    ")->execute([$telNorm]);

    $stmt = $db->prepare("SELECT turnos_sem_avanco FROM whatsapp_sessoes WHERE telefone = ?");
    $stmt->execute([$telNorm]);
    return (int)($stmt->fetchColumn() ?: 0);
}

/**
 * Orquestra um turno completo de qualificação: gera e envia a resposta,
 * extrai dados da conversa, aplica na oportunidade, e decide se encerra
 * (sem_perfil), avança pro bloco 4 (qualificação completa), ou escala pro
 * consultor por estagnação (conversa girando sem sair do lugar).
 *
 * Só deve ser chamada quando: IA não está pausada, mensagem veio da
 * instância principal (não de consultor), e a oportunidade ainda está em
 * 'whatsapp' ou 'qualificacao_ia' — quem chama garante essas condições.
 */
function iaProcessarTurno(int $oportunidadeId, string $telefone): array {
    // 'resposta'/'enviada' expostos no retorno (não só efeito colateral) pra
    // dar pro simulador (chatbot-whatsapp/simulate.php) mostrar o que a IA
    // geraria mesmo quando zapiEnviarTexto falha por falta de credencial
    // real — sem isso, testar a conversa em dev fica invisível, porque só
    // registramos a mensagem 'out' quando o envio de fato funciona (mesmo
    // padrão de "só marca sucesso se enviou de verdade" do cron/followup.php).
    $resultado = [
        'resposta' => '', 'enviada' => false, 'sem_perfil' => false,
        'qualificacao_completa' => false, 'escalado_sem_avanco' => false,
    ];

    $resposta = iaGerarResposta($telefone);
    $resultado['resposta'] = $resposta;
    if ($resposta !== '') {
        $enviada = zapiEnviarTexto($telefone, $resposta);
        $resultado['enviada'] = $enviada;
        if ($enviada) {
            registrarMensagem($telefone, 'out', $resposta, null, true);
        }
    }

    $dados = iaExtrairDados($telefone);
    if (!$dados) {
        iaAtualizarContadorEstagnacao($telefone, false);
        return $resultado;
    }

    $avancou = iaAplicarDadosExtraidos($oportunidadeId, $dados);

    if (!empty($dados['sem_perfil'])) {
        marcarPerdida($oportunidadeId, (string)($dados['motivo_sem_perfil'] ?: 'IA identificou sem perfil de compra na qualificação'), null, true);
        $resultado['sem_perfil'] = true;
        return $resultado;
    }

    if (!empty($dados['qualificacao_completa'])) {
        $db = getDB();
        $stmt = $db->prepare("SELECT etapa FROM oportunidades WHERE id = ?");
        $stmt->execute([$oportunidadeId]);
        $etapaAtual = $stmt->fetchColumn();
        // Já passou desse ponto (bloco 4 em diante) — não reprocessa nem
        // duplica linha de histórico a cada novo turno de conversa.
        if (!in_array($etapaAtual, ['whatsapp', 'qualificacao_ia'], true)) return $resultado;

        $resumo = iaGerarResumo($telefone);
        if ($resumo !== '') {
            $db->prepare("UPDATE oportunidades SET resumo_ia = ? WHERE id = ?")->execute([$resumo, $oportunidadeId]);
        }
        mudarEtapa($oportunidadeId, 'crm_preenchido', null, 'Qualificação IA concluída — encaminhado pro consultor');
        notificarConsultorLeadQualificado($oportunidadeId, 'Qualificação concluída');
        $resultado['qualificacao_completa'] = true;
        return $resultado;
    }

    // Ainda não terminou — atualiza o contador de estagnação e escala pro
    // consultor humano se a conversa já girou turnos demais sem sair do
    // lugar (lead só de bate-papo, ou que esfriou e parou de dar dado novo).
    $turnosSemAvanco = iaAtualizarContadorEstagnacao($telefone, $avancou);
    if ($turnosSemAvanco >= IA_LIMITE_TURNOS_SEM_AVANCO) {
        $db = getDB();
        $stmt = $db->prepare("SELECT etapa FROM oportunidades WHERE id = ?");
        $stmt->execute([$oportunidadeId]);
        $etapaAtual = $stmt->fetchColumn();
        if (in_array($etapaAtual, ['whatsapp', 'qualificacao_ia'], true)) {
            $resumo = iaGerarResumo($telefone);
            $resumoFinal = ($resumo !== '' ? $resumo . "\n\n" : '')
                . '⚠️ IA encaminhou pro consultor por estagnação — conversa passou de '
                . IA_LIMITE_TURNOS_SEM_AVANCO . ' turnos sem novo dado confirmado.';
            $db->prepare("UPDATE oportunidades SET resumo_ia = ? WHERE id = ?")->execute([$resumoFinal, $oportunidadeId]);
            mudarEtapa($oportunidadeId, 'crm_preenchido', null, 'IA escalou pro consultor — conversa estagnada sem avanço de dados');
            notificarConsultorLeadQualificado($oportunidadeId, 'IA escalou por estagnação');
            $resultado['escalado_sem_avanco'] = true;
        }
    }

    return $resultado;
}
