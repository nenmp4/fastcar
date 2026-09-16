<?php
/**
 * Extração por IA dos documentos que o cliente sobe sozinho no wizard
 * público (public/documentos.php): CNH, comprovante de endereço, contrato
 * de financiamento. Pedido do José/Jean em 13/09/2026: em vez do cliente
 * digitar nome/CPF/endereço à mão (e poder errar sem ninguém perceber até
 * o contrato já estar pronto), a IA lê a foto/PDF de cada documento assim
 * que ele sobe e PRÉ-PREENCHE os campos — o cliente só revisa/corrige e
 * confirma, um documento por vez, antes de avançar pro próximo. Mesmo
 * mecanismo (Gemini multimodal, `inlineData` com o mime real, PDF incluso
 * sem precisar de biblioteca de OCR — já validado em produção no
 * JurídicoSaaS, api/financeiro-ler-comprovante.php) que já processa
 * áudio/imagem do WhatsApp aqui (includes/gemini.php::geminiCallComMidia()).
 *
 * Preenchimento é sempre "fill-if-empty" (nunca sobrescreve dado que já
 * existia — mesmo padrão de includes/ia_qualificacao.php) e a IA nunca
 * inventa: campo não legível na imagem volta como string vazia (regra #3
 * do CLAUDE.md). Sem chave Gemini configurada — único provedor multimodal
 * neste projeto, sem fallback OpenAI pra isso, mesma limitação já existente
 * pro áudio/imagem do WhatsApp — a extração simplesmente não roda; o
 * cliente digita manualmente na tela de revisão, sem travar o wizard.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/documentos.php';
require_once __DIR__ . '/gemini.php';

/**
 * 1 prompt por tipo de documento — pede só os campos que fazem sentido
 * extrair daquele documento específico, sempre em JSON.
 */
const EXTRACAO_DOCUMENTO_CAMPOS = [
    'cnh'                    => ['nome', 'cpf', 'rg', 'cnh'],
    'comprovante_endereco'   => ['endereco'],
    'contrato_financiamento' => [
        'banco_financiamento', 'veiculo_marca', 'veiculo_modelo', 'veiculo_ano',
        'veiculo_placa', 'veiculo_renavam', 'veiculo_chassi',
        'valor_parcela', 'parcelas_restantes', 'contrato_financiamento_numero',
    ],
    'crlv' => ['veiculo_marca', 'veiculo_modelo', 'veiculo_ano', 'veiculo_placa', 'veiculo_renavam', 'veiculo_chassi'],
];

function extracaoDocumentoPrompt(string $tipo): string {
    return match ($tipo) {
        'cnh' => "Leia esta CNH (Carteira Nacional de Habilitação) ou documento de identidade com foto e extraia os dados abaixo. NUNCA invente informação — se não conseguir ler algum campo com certeza, deixe como string vazia \"\".\n\n"
            . "Responda APENAS um JSON, sem texto fora dele nem markdown, no formato exato:\n"
            . '{"nome": "", "cpf": "", "rg": "", "cnh": ""}' . "\n"
            . '("cnh" é o número de registro da carteira de habilitação, se estiver visível — não confundir com o CPF.)',

        'comprovante_endereco' => "Leia este comprovante de endereço (conta de luz, água, telefone, internet etc.) e extraia o endereço completo (rua, número, bairro, cidade, estado, CEP — o que estiver visível, numa linha só). NUNCA invente informação — se não conseguir ler com certeza, deixe vazio.\n\n"
            . "Responda APENAS um JSON, sem texto fora dele nem markdown, no formato exato:\n"
            . '{"endereco": ""}',

        'contrato_financiamento' => "Leia este contrato de financiamento de veículo (com banco/financeira) e extraia o que estiver visível dos campos abaixo. NUNCA invente informação — campo não legível fica como string vazia \"\".\n\n"
            . "Responda APENAS um JSON, sem texto fora dele nem markdown, no formato exato:\n"
            . '{"banco_financiamento":"","veiculo_marca":"","veiculo_modelo":"","veiculo_ano":"","veiculo_placa":"","veiculo_renavam":"","veiculo_chassi":"","valor_parcela":"","parcelas_restantes":"","contrato_financiamento_numero":""}' . "\n"
            . '"valor_parcela" em número, ex: 850.50 (sem "R$", sem separador de milhar). "parcelas_restantes" só o número inteiro de parcelas que ainda faltam pagar, se estiver explícito no contrato.',

        'crlv' => "Leia este CRLV (Certificado de Registro e Licenciamento de Veículo, documento oficial do veículo — pode ser o CRLV-e digital) e extraia os dados abaixo. NUNCA invente informação — campo não legível fica como string vazia \"\".\n\n"
            . "Responda APENAS um JSON, sem texto fora dele nem markdown, no formato exato:\n"
            . '{"veiculo_marca":"","veiculo_modelo":"","veiculo_ano":"","veiculo_placa":"","veiculo_renavam":"","veiculo_chassi":""}' . "\n"
            . '"veiculo_ano" é o ano-modelo do veículo (ou ano de fabricação/modelo, o que estiver mais visível).',

        default => '',
    };
}

/**
 * Chama a IA pra ler 1 documento já salvo e devolve só os campos
 * previstos pro tipo dele (array associativo, valor '' quando a IA não
 * leu/não tinha certeza). Nunca lança — falha de rede/API/parse vira
 * array vazio, igual ao padrão do resto do projeto (provedor externo fora
 * do ar nunca trava o fluxo principal).
 */
function extrairDadosDocumentoComIA(string $tipo, array $arquivo): array {
    $campos = EXTRACAO_DOCUMENTO_CAMPOS[$tipo] ?? [];
    if (!$campos) return [];

    $geminiKey = getConfig('gemini_api_key') ?: '';
    if (!$geminiKey) return [];

    $prompt = extracaoDocumentoPrompt($tipo);
    if (!$prompt) return [];

    $resposta = geminiCallComMidia(
        $prompt, $arquivo['mime'], base64_encode($arquivo['content']),
        $geminiKey, getConfig('gemini_model') ?: 'gemini-3.5-flash-lite', 300, 0.1
    );
    if ($resposta === '') return [];

    // A IA às vezes envolve o JSON em ```json ... ``` mesmo pedindo texto
    // puro, ou deixa vírgula sobrando antes de "}" — mesma robustez já
    // validada em produção no JurídicoSaaS (api/financeiro-ler-comprovante.php).
    $limpo = preg_replace('/```(?:json)?/i', '', $resposta);
    preg_match('/\{[\s\S]*\}/', $limpo, $m);
    $bruto = trim($m[0] ?? '');
    $dados = json_decode($bruto, true);
    if (!is_array($dados)) {
        $tentativa = preg_replace('/,(\s*[}\]])/', '$1', $bruto);
        $tentativa = str_replace(["\u{201C}", "\u{201D}", "\u{2018}", "\u{2019}"], ['"', '"', "'", "'"], $tentativa);
        $dados = json_decode($tentativa, true);
    }
    if (!is_array($dados)) return [];

    $resultado = [];
    foreach ($campos as $campo) {
        $resultado[$campo] = trim((string)($dados[$campo] ?? ''));
    }
    return $resultado;
}

/**
 * Aplica os dados extraídos de 1 documento — "fill-if-empty" (nunca
 * sobrescreve o que já existia, seja de uma etapa anterior do wizard ou
 * de dados já coletados na qualificação por IA do bloco 3). Campos de
 * identidade/endereço vão pra `clientes`; campos do veículo/financiamento
 * vão pra `oportunidades` (mesmas tabelas que atualizarDadosPessoaisCliente()
 * e a tela do consultor em admin/oportunidade.php já usam).
 */
function aplicarDadosExtraidosDocumento(int $clienteId, int $oportunidadeId, string $tipo, array $dados): void {
    if (!$dados) return;
    $db = getDB();

    $camposCliente = ['nome', 'cpf', 'rg', 'cnh', 'endereco'];
    $camposOportunidade = [
        'banco_financiamento', 'veiculo_marca', 'veiculo_modelo', 'veiculo_ano',
        'veiculo_placa', 'veiculo_renavam', 'veiculo_chassi',
        'valor_parcela', 'parcelas_restantes', 'contrato_financiamento_numero',
    ];

    $setsCliente = []; $paramsCliente = [];
    $setsOp = []; $paramsOp = [];

    foreach ($dados as $campo => $valorBruto) {
        $valor = trim((string)$valorBruto);
        if ($valor === '') continue;

        if (in_array($campo, $camposCliente, true)) {
            $setsCliente[] = "{$campo} = CASE WHEN {$campo} IS NULL OR {$campo} = '' THEN ? ELSE {$campo} END";
            $paramsCliente[] = $valor;
        } elseif ($campo === 'valor_parcela') {
            $num = str_replace(',', '.', $valor);
            if (!is_numeric($num)) continue;
            $setsOp[] = "valor_parcela = CASE WHEN valor_parcela IS NULL THEN ? ELSE valor_parcela END";
            $paramsOp[] = (float)$num;
        } elseif ($campo === 'parcelas_restantes') {
            if (!is_numeric($valor)) continue;
            $setsOp[] = "parcelas_restantes = CASE WHEN parcelas_restantes IS NULL THEN ? ELSE parcelas_restantes END";
            $paramsOp[] = (int)$valor;
        } elseif (in_array($campo, $camposOportunidade, true)) {
            $setsOp[] = "{$campo} = CASE WHEN {$campo} IS NULL OR {$campo} = '' THEN ? ELSE {$campo} END";
            $paramsOp[] = $valor;
        }
    }

    if ($setsCliente) {
        $paramsCliente[] = $clienteId;
        $db->prepare("UPDATE clientes SET " . implode(', ', $setsCliente) . " WHERE id = ?")->execute($paramsCliente);
    }
    if ($setsOp) {
        $paramsOp[] = $oportunidadeId;
        $db->prepare("UPDATE oportunidades SET " . implode(', ', $setsOp) . " WHERE id = ?")->execute($paramsOp);
    }
}

const EXTRACAO_DOCUMENTO_LABELS = [
    'nome' => 'Nome', 'cpf' => 'CPF', 'rg' => 'RG', 'cnh' => 'Nº da CNH', 'endereco' => 'Endereço',
    'banco_financiamento' => 'Banco financiador', 'veiculo_marca' => 'Marca do veículo',
    'veiculo_modelo' => 'Modelo do veículo', 'veiculo_ano' => 'Ano do veículo', 'veiculo_placa' => 'Placa',
    'veiculo_renavam' => 'Renavam', 'veiculo_chassi' => 'Chassi', 'valor_parcela' => 'Valor da parcela',
    'parcelas_restantes' => 'Parcelas restantes', 'contrato_financiamento_numero' => 'Nº do contrato de financiamento',
];

/**
 * Compara o que a IA leu no documento com o que já estava cadastrado
 * (digitado numa etapa anterior do wizard OU coletado antes na qualificação
 * por IA do bloco 3, via WhatsApp — esse já é confiável, não precisa
 * revalidar). Pedido explícito do José/Jean: se o cliente subir um
 * comprovante de outra pessoa ou um contrato de financiamento de outro
 * veículo, isso tem que aparecer — aplicarDadosExtraidosDocumento() sozinha
 * NUNCA pegaria isso (só preenche campo vazio, nunca compara com o que já
 * existe). Aponta a divergência (nunca bloqueia — decisão de negócio é
 * sempre do consultor, regra #3 do CLAUDE.md), retorna lista de frases
 * pronta pra mostrar/logar; array vazio = nada divergente.
 */
function compararDivergenciasDocumento(array $dadosAtuais, array $dadosExtraidos): array {
    $normaliza = fn($v) => mb_strtolower(trim((string)preg_replace('/\s+/', ' ', (string)$v)));
    $soDigitos = fn($v) => preg_replace('/\D/', '', (string)$v);
    $soNumero  = fn($v) => is_numeric(str_replace(',', '.', (string)$v)) ? (float)str_replace(',', '.', (string)$v) : null;

    $divergencias = [];
    foreach ($dadosExtraidos as $campo => $novoValor) {
        $novoValor = trim((string)$novoValor);
        if ($novoValor === '') continue;
        $valorAtual = trim((string)($dadosAtuais[$campo] ?? ''));
        if ($valorAtual === '') continue; // nada cadastrado ainda — é preenchimento normal, não divergência

        if (in_array($campo, ['cpf'], true) && $soDigitos($novoValor) === $soDigitos($valorAtual)) continue;
        if (in_array($campo, ['valor_parcela', 'parcelas_restantes'], true)) {
            $a = $soNumero($novoValor); $b = $soNumero($valorAtual);
            if ($a !== null && $b !== null && abs($a - $b) < 0.01) continue;
        } elseif ($normaliza($novoValor) === $normaliza($valorAtual)) {
            continue;
        }

        $label = EXTRACAO_DOCUMENTO_LABELS[$campo] ?? $campo;
        $divergencias[] = "{$label}: já tínhamos \"{$valorAtual}\", o documento mostra \"{$novoValor}\".";
    }
    return $divergencias;
}
