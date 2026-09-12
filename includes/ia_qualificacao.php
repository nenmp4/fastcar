<?php
/**
 * Qualificação por IA — bloco 3 do funil. Resolve a pendência #3 do
 * CLAUDE.md: mesmo provedor e padrão do JurídicoSaaS (Gemini, via
 * includes/gemini.php — fallback de modelos já embutido lá).
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
require_once __DIR__ . '/oportunidades.php';

const IA_QUALIFICACAO_PROMPT_SISTEMA = <<<PROMPT
Você é a assistente virtual da Fastcar, empresa que COMPRA veículos que o
dono ainda está pagando financiado (o carro continua com financiamento em
aberto, a Fastcar assume a negociação com o banco).

Sua função é conversar de forma humana e breve pelo WhatsApp pra entender
a situação do veículo do cliente ANTES de passar pra um consultor humano.
Nunca diga que é uma IA/robô se perguntarem diretamente, apenas responda
com naturalidade que é da equipe Fastcar.

Precisa descobrir, em ordem de prioridade, SEM fazer todas as perguntas de
uma vez (uma ou duas por mensagem, num tom leve de conversa):
1. Marca, modelo e ano do veículo
2. Se ainda tem financiamento em aberto — banco, valor da parcela, quantas
   parcelas faltam, se tem alguma parcela atrasada
3. Cidade/estado onde o cliente está
4. Quanto o cliente gostaria de receber pelo veículo

REGRAS QUE NÃO PODEM SER QUEBRADAS:
- Nunca invente, arredonde ou deduza um valor que o cliente não disse.
- Nunca prometa valor de compra, prazo ou condição — quem decide isso é
  sempre um humano (o closer), depois.
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
{"veiculo_marca":null,"veiculo_modelo":null,"veiculo_ano":null,"banco_financiamento":null,"valor_parcela":null,"parcelas_restantes":null,"parcelas_atraso":null,"cidade":null,"estado":null,"valor_pretendido":null,"sem_perfil":false,"motivo_sem_perfil":null,"qualificacao_completa":false}

- valor_parcela e valor_pretendido: número (sem "R$", sem separador de milhar; use ponto decimal). null se não informado.
- parcelas_restantes e parcelas_atraso: número inteiro. Se o cliente disse "já está quitado", parcelas_restantes=0 e banco_financiamento pode ficar null.
- sem_perfil: true SOMENTE se o cliente disse claramente que não quer vender, não tem interesse, ou não se enquadra (não é o dono, veículo já vendido, etc).
- qualificacao_completa: true SOMENTE quando já se sabe modelo+ano, a situação do financiamento (banco+parcela OU confirmação de quitado) E o valor pretendido pelo cliente.

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
    $apiKey = getConfig('gemini_api_key') ?: '';
    if (!$apiKey) return '';
    $modelo = getConfig('gemini_model') ?: 'gemini-2.5-flash';
    $historico = iaMontarHistoricoGemini($telefone);
    if (!$historico) return '';
    return geminiCallChat(IA_QUALIFICACAO_PROMPT_SISTEMA, $historico, $apiKey, $modelo);
}

/**
 * Extrai dados estruturados da conversa até agora. Retorna null se a API
 * falhar ou não tiver chave — nunca lança, nunca bloqueia o fluxo principal.
 */
function iaExtrairDados(string $telefone): ?array {
    $apiKey = getConfig('gemini_api_key') ?: '';
    if (!$apiKey) return null;
    $modelo = getConfig('gemini_model') ?: 'gemini-2.5-flash';

    $historico = iaMontarHistoricoGemini($telefone, 40);
    if (!$historico) return null;

    $transcript = '';
    foreach ($historico as $h) {
        $quem = $h['role'] === 'user' ? 'Cliente' : 'Fastcar';
        $transcript .= "{$quem}: " . $h['parts'][0]['text'] . "\n";
    }

    $resposta = geminiCall(IA_EXTRACAO_PROMPT . $transcript, $apiKey, $modelo, 400, 0.1);
    if (is_array($resposta)) return null; // erro — resposta seria ['erro' => ...]

    // Gemini às vezes envolve o JSON em ```json ... ``` mesmo pedindo texto puro
    $limpo = trim(preg_replace('/^```(json)?|```$/m', '', trim($resposta)));
    $json = json_decode($limpo, true);
    return is_array($json) ? $json : null;
}

/** Gera um resumo curto da conversa pro consultor (oportunidades.resumo_ia). */
function iaGerarResumo(string $telefone): string {
    $apiKey = getConfig('gemini_api_key') ?: '';
    if (!$apiKey) return '';
    $modelo = getConfig('gemini_model') ?: 'gemini-2.5-flash';
    $historico = iaMontarHistoricoGemini($telefone, 40);
    if (!$historico) return '';

    $transcript = '';
    foreach ($historico as $h) {
        $quem = $h['role'] === 'user' ? 'Cliente' : 'Fastcar';
        $transcript .= "{$quem}: " . $h['parts'][0]['text'] . "\n";
    }

    $prompt = "Resuma em até 4 linhas, pra um consultor humano que vai assumir o atendimento, "
        . "o que já foi conversado com esse cliente sobre o veículo e a intenção de venda. "
        . "Seja objetivo, sem repetir a conversa palavra por palavra.\n\nConversa:\n{$transcript}";
    $resposta = geminiCall($prompt, $apiKey, $modelo, 300, 0.3);
    return is_array($resposta) ? '' : $resposta;
}

/**
 * Aplica os dados extraídos na oportunidade — só preenche campo que
 * estava vazio, nunca sobrescreve o que já tinha (consultor pode ter
 * corrigido à mão depois, isso tem prioridade sobre a IA).
 */
function iaAplicarDadosExtraidos(int $oportunidadeId, array $dados): void {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM oportunidades WHERE id = ?");
    $stmt->execute([$oportunidadeId]);
    $op = $stmt->fetch();
    if (!$op) return;

    $campos = [
        'veiculo_marca', 'veiculo_modelo', 'veiculo_ano', 'banco_financiamento',
        'valor_parcela', 'parcelas_restantes', 'parcelas_atraso', 'valor_pretendido',
    ];
    $sets = [];
    $params = [];
    foreach ($campos as $c) {
        $vazio = $op[$c] === '' || $op[$c] === null;
        if ($vazio && isset($dados[$c]) && $dados[$c] !== null && $dados[$c] !== '') {
            $sets[] = "{$c} = ?";
            $params[] = $dados[$c];
        }
    }
    if ($sets) {
        $params[] = $oportunidadeId;
        $db->prepare("UPDATE oportunidades SET " . implode(', ', $sets) . ", updated_at = datetime('now','localtime') WHERE id = ?")
           ->execute($params);
    }

    // cidade/estado vivem no cliente, não na oportunidade
    if (!empty($dados['cidade']) || !empty($dados['estado'])) {
        $stmtC = $db->prepare("SELECT cidade, estado FROM clientes WHERE id = ?");
        $stmtC->execute([$op['cliente_id']]);
        $cli = $stmtC->fetch();
        if ($cli) {
            $novaCidade = (!$cli['cidade'] && !empty($dados['cidade'])) ? $dados['cidade'] : $cli['cidade'];
            $novoEstado = (!$cli['estado'] && !empty($dados['estado'])) ? $dados['estado'] : $cli['estado'];
            if ($novaCidade !== $cli['cidade'] || $novoEstado !== $cli['estado']) {
                $db->prepare("UPDATE clientes SET cidade = ?, estado = ? WHERE id = ?")
                   ->execute([$novaCidade, $novoEstado, $op['cliente_id']]);
            }
        }
    }
}

/**
 * Orquestra um turno completo de qualificação: gera e envia a resposta,
 * extrai dados da conversa, aplica na oportunidade, e decide se encerra
 * (sem_perfil) ou avança pro bloco 4 (qualificação completa).
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
    $resultado = ['resposta' => '', 'enviada' => false, 'sem_perfil' => false, 'qualificacao_completa' => false];

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
    if (!$dados) return $resultado;

    iaAplicarDadosExtraidos($oportunidadeId, $dados);

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
        $resultado['qualificacao_completa'] = true;
    }

    return $resultado;
}
