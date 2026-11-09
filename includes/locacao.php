<?php
/**
 * Contrato de LOCAÇÃO pura de veículo (27/10/2026, "vamos subir contrato de
 * locação — coloca botão seleciona tipo de contrato em vendas... abria o
 * modal") — transcrito do modelo real
 * 01_Contrato_Mestre_FASTCAR_Locacao_Pura.docx (Versão 1.0, Setembro/2026).
 *
 * Reaproveita a tabela `vendas` com `tipo_contrato='locacao'` em vez de uma
 * tabela nova: mesmo veículo da frota, mesmo "comprador" (aqui LOCATÁRIO,
 * colunas comprador_*), mesmo wizard de documentos, mesma geração/envio de
 * contrato pela ZapSign e mesma tela (admin/venda.php). As condições
 * específicas do Quadro-Resumo da locação moram em
 * `vendas.locacao_dados_json` (chave => valor de LOCACAO_CAMPOS) — são ~30
 * campos que nada no resto do sistema consulta por SQL, só o PDF.
 *
 * Assinou → etapa 'vendido' (exibida como "🔑 Locado"), o veículo fica
 * indisponível pra outra venda; quando o carro volta, usa-se o mesmo botão
 * "🔙 Voltou pra Fastcar" da Frota. Nunca gera receita/comissão automática
 * de venda (aluguel não é preço de compra — ver guards em
 * includes/financeiro.php).
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';

/**
 * Campos do Quadro-Resumo da locação — fonte única pra formulário (modal e
 * card da negociação), parsing do POST e PDF. tipo: text|money|int|date|
 * select (com 'opcoes'). 'padrao' só pré-preenche o formulário, nunca é
 * gravado sozinho.
 */
const LOCACAO_CAMPOS = [
    'modalidade'              => ['label' => 'Modalidade', 'tipo' => 'select', 'opcoes' => ['determinado' => 'Prazo determinado', 'indeterminado' => 'Prazo indeterminado']],
    'prazo_meses'             => ['label' => 'Prazo inicial (meses)', 'tipo' => 'int'],
    'data_inicio'             => ['label' => 'Data de início', 'tipo' => 'date'],
    // 28/10/2026 — "locação por semana: mensal ou semanal". Vazio = mensal
    // (contratos anteriores). A chave aluguel_mensal guarda o valor do PERÍODO.
    'periodicidade'           => ['label' => 'Pagamento do aluguel', 'tipo' => 'select', 'opcoes' => ['mensal' => 'Mensal', 'semanal' => 'Semanal'], 'padrao' => 'mensal'],
    'aluguel_mensal'          => ['label' => 'Valor do aluguel (R$) — por mês ou por semana', 'tipo' => 'money'],
    'vencimento_dia'          => ['label' => 'Vencimento — dia do mês (se mensal)', 'tipo' => 'int'],
    'vencimento_semana'       => ['label' => 'Vencimento — dia da semana (se semanal)', 'tipo' => 'select', 'opcoes' => ['segunda' => 'Segunda-feira', 'terca' => 'Terça-feira', 'quarta' => 'Quarta-feira', 'quinta' => 'Quinta-feira', 'sexta' => 'Sexta-feira', 'sabado' => 'Sábado', 'domingo' => 'Domingo']],
    'caucao_valor'            => ['label' => 'Caução/garantia (R$), se houver', 'tipo' => 'money'],
    'caucao_modalidade'       => ['label' => 'Modalidade da caução', 'tipo' => 'text', 'placeholder' => 'PIX, depósito, cheque...'],
    'km_inicial'              => ['label' => 'Quilometragem inicial (km)', 'tipo' => 'int'],
    'franquia_km'             => ['label' => 'Franquia de quilometragem (km)', 'tipo' => 'int'],
    'franquia_periodo'        => ['label' => 'Franquia por (vazio = mesmo período do aluguel)', 'tipo' => 'text'],
    'km_excedente_valor'      => ['label' => 'Km excedente (R$/km)', 'tipo' => 'money'],
    'ipva_resp'               => ['label' => 'IPVA — responsável', 'tipo' => 'text', 'padrao' => 'FASTCAR'],
    'licenciamento_resp'      => ['label' => 'Licenciamento — responsável', 'tipo' => 'text', 'padrao' => 'FASTCAR'],
    'seguro_texto'            => ['label' => 'Seguro/proteção', 'tipo' => 'text'],
    'franquia_sinistro_resp'  => ['label' => 'Franquia de sinistro — responsável', 'tipo' => 'text', 'padrao' => 'LOCATÁRIO'],
    'manutencao_resp'         => ['label' => 'Manutenção preventiva — responsável', 'tipo' => 'text'],
    'pneus_regra'             => ['label' => 'Pneus/itens de desgaste — regra', 'tipo' => 'text'],
    'combustivel_devolucao'   => ['label' => 'Combustível na devolução', 'tipo' => 'text', 'padrao' => 'Mesmo nível da entrega'],
    'rastreador'              => ['label' => 'Rastreador', 'tipo' => 'select', 'opcoes' => ['instalado' => 'Instalado', 'sera_instalado' => 'Será instalado', 'nao_aplicavel' => 'Não aplicável']],
    'uso'                     => ['label' => 'Uso autorizado', 'tipo' => 'select', 'opcoes' => ['pessoal' => 'Pessoal', 'profissional' => 'Profissional', 'aplicativo' => 'Aplicativo', 'outro' => 'Outro'], 'padrao' => 'aplicativo'], // 28/10/2026 — locação vai pra motorista de aplicativo
    'uso_outro'               => ['label' => 'Uso autorizado — se "outro", qual', 'tipo' => 'text'],
    'aviso_previo_dias'       => ['label' => 'Aviso prévio para devolução (dias)', 'tipo' => 'int'],
    'mora_multa_pct'          => ['label' => 'Encargos de mora — multa (%)', 'tipo' => 'text', 'padrao' => '2'],
    'mora_juros'              => ['label' => 'Encargos de mora — juros', 'tipo' => 'text', 'padrao' => '1% ao mês'],
    'mora_atualizacao'        => ['label' => 'Encargos de mora — atualização', 'tipo' => 'text'],
    'multa_saida_antecipada'  => ['label' => 'Multa por saída antecipada, se aplicável', 'tipo' => 'text'],
    'prazo_devolucao_resolucao' => ['label' => 'Prazo para devolução após resolução', 'tipo' => 'text', 'placeholder' => 'ex: 48 horas'],
    // Modelo "Readequado" (27/10/2026) — campos que antes ficavam nos anexos.
    'veiculo_cor'             => ['label' => 'Cor do veículo', 'tipo' => 'text'],
    'canal_whatsapp'          => ['label' => 'Canal oficial FASTCAR — WhatsApp', 'tipo' => 'text', 'placeholder' => '(11) 9....'],
    'proprietario_registral'  => ['label' => 'Proprietário registral (vazio = vendedor original do veículo)', 'tipo' => 'text'],
    'restricoes_conhecidas'   => ['label' => 'Restrições conhecidas', 'tipo' => 'text', 'placeholder' => 'ex: nenhuma'],
    'entrega_data'            => ['label' => 'Entrega prevista — data', 'tipo' => 'date'],
    'entrega_hora'            => ['label' => 'Entrega prevista — hora', 'tipo' => 'text', 'placeholder' => 'ex: 14h'],
    'entrega_local'           => ['label' => 'Entrega prevista — local', 'tipo' => 'text', 'padrao' => 'Sede FASTCAR — Barueri/SP'],
    'franquia_acumulavel'     => ['label' => 'Franquia de km acumulável?', 'tipo' => 'select', 'opcoes' => ['sim' => 'Sim', 'nao' => 'Não']],
    'cobertura_texto'         => ['label' => 'Cobertura: responsável, limites e exclusões (vazio = não contratada)', 'tipo' => 'text'],
    'local_devolucao'         => ['label' => 'Local de devolução', 'tipo' => 'text', 'padrao' => 'Sede FASTCAR — Barueri/SP'],
    'canal_privacidade'       => ['label' => 'Canal de privacidade e revogação de imagem e voz', 'tipo' => 'text', 'padrao' => 'Ouvidoria (11) 97816-4525'],
    'autoriza_imagem'         => ['label' => 'Uso de imagem e voz (vazio = marcar na assinatura)', 'tipo' => 'select', 'opcoes' => ['sim' => 'SIM', 'nao' => 'NÃO']],
];

/** Campos sem os quais o contrato de locação não é gerado. */
const LOCACAO_CAMPOS_OBRIGATORIOS = ['modalidade', 'data_inicio', 'aluguel_mensal'];

/** Campos que aparecem de cara no modal/card — o resto fica em "Mais condições". */
const LOCACAO_CAMPOS_PRINCIPAIS = ['periodicidade', 'aluguel_mensal', 'vencimento_dia', 'vencimento_semana', 'data_inicio',
    'modalidade', 'prazo_meses', 'caucao_valor', 'km_inicial', 'franquia_km', 'uso'];

/** 'semanal' ou 'mensal' (padrão — contratos anteriores não têm o campo). */
function locacaoPeriodicidade(array $dados): string {
    return ($dados['periodicidade'] ?? '') === 'semanal' ? 'semanal' : 'mensal';
}

function lerDadosLocacao(?string $json): array {
    $d = json_decode((string)$json, true);
    return is_array($d) ? $d : [];
}

/**
 * Extrai os campos de LOCACAO_CAMPOS de um array vindo do POST (name="loc[...]").
 * Valor vazio vira ausente (nunca grava valor chutado — regra #3); select
 * fora da lista de opções é descartado.
 */
function parseCamposLocacaoPost(array $loc): array {
    return parseCamposQuadroPost(LOCACAO_CAMPOS, $loc);
}

/**
 * Parsing genérico de um Quadro-Resumo (definições no formato de
 * LOCACAO_CAMPOS) — compartilhado com a promissória (includes/promissoria.php).
 */
function parseCamposQuadroPost(array $defs, array $post): array {
    $out = [];
    foreach ($defs as $k => $def) {
        $bruto = trim((string)($post[$k] ?? ''));
        if ($bruto === '') continue;
        switch ($def['tipo']) {
            case 'money':
                $val = valorMonetario($bruto);
                if ($val !== null) $out[$k] = $val;
                break;
            case 'int':
                $out[$k] = max(0, (int)preg_replace('/\D/', '', $bruto));
                break;
            case 'date':
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $bruto)) $out[$k] = $bruto;
                break;
            case 'select':
                if (isset($def['opcoes'][$bruto])) $out[$k] = $bruto;
                break;
            default:
                $out[$k] = clean($bruto);
        }
    }
    return $out;
}

/** Labels dos obrigatórios que ainda faltam. */
function locacaoCamposFaltando(array $dados): array {
    $faltando = [];
    foreach (LOCACAO_CAMPOS_OBRIGATORIOS as $k) {
        if (!isset($dados[$k]) || $dados[$k] === '' || $dados[$k] === 0 || $dados[$k] === 0.0) {
            $faltando[] = LOCACAO_CAMPOS[$k]['label'];
        }
    }
    // Vencimento depende da periodicidade: dia do mês (mensal) ou dia da semana (semanal).
    $kVenc = locacaoPeriodicidade($dados) === 'semanal' ? 'vencimento_semana' : 'vencimento_dia';
    if (empty($dados[$kVenc])) $faltando[] = LOCACAO_CAMPOS[$kVenc]['label'];
    return $faltando;
}

/** Renderiza os inputs (name="loc[chave]") — usado no modal e no card. */
function renderCamposLocacao(array $dados): string {
    return renderCamposQuadro(LOCACAO_CAMPOS, LOCACAO_CAMPOS_OBRIGATORIOS, 'loc', $dados, LOCACAO_CAMPOS_PRINCIPAIS);
}

/**
 * Renderização genérica de um Quadro-Resumo (name="{prefixo}[chave]") —
 * compartilhada com a promissória (includes/promissoria.php).
 */
/**
 * Exemplo de preenchimento mostrado embaixo de cada campo do Quadro-Resumo
 * (09/11/2026, "nos contratos pessoal ainda se perde campos — colocar um
 * exemplo de como preencher"). Chave = campo; o que muda por contrato vai em
 * "{prefixo}.{campo}" (prefixos: prom, posse, loc). Só texto de ajuda —
 * nunca preenche nada sozinho.
 */
const CAMPOS_QUADRO_EXEMPLOS = [
    // Comuns (promissória / posse / locação)
    'fipe_data_consulta'        => 'dia em que você consultou a FIPE do veículo — ex.: 09/11/2026',
    'comissao_aplicavel'        => 'só "Sim" se um terceiro (corretor/indicador) trouxe o comprador',
    'comissao_saldo_vencimento' => 'só se a entrada não cobriu a comissão toda — ex.: 10/12/2026',
    'vencimento_dia'            => 'dia fixo das parcelas — ex.: 10 (todo dia 10). Vazio = mesmo dia da 1ª parcela',
    'saldo_final_valor'         => 'parcela "balão" no final, se combinada — ex.: 5.000,00. Sem balão = deixe vazio',
    'saldo_final_vencimento'    => 'data dessa parcela final — ex.: 10/11/2027',
    'protecao_contratada'       => 'o comprador já tem seguro/proteção do carro? Sim ou Não',
    'protecao_responsavel'      => 'quem contratou/paga — ex.: COMPRADOR (Associação XYZ)',
    'protecao_coberturas'       => 'ex.: roubo, furto, colisão e terceiros até R$ 50.000',
    'entrega_data'              => 'dia em que o cliente leva o carro — ex.: 12/11/2026',
    'entrega_hora'              => 'ex.: 14h',
    'entrega_local'             => 'ex.: Sede FASTCAR — Barueri/SP (ou o endereço combinado)',
    'autoriza_imagem'           => 'cliente deixa usar foto/vídeo dele? Na dúvida, deixe vazio — ele marca na assinatura',
    'prazo_devolucao_horas'     => 'ex.: 48 (horas pra devolver o carro depois de notificado)',
    'prazo_saneamento_dias'     => 'ex.: 10 (dias úteis pra resolver um problema antes de rescindir)',
    // Posse
    'prazo_quitacao_meses'      => 'em quantos meses o comprador quita o banco — ex.: 15',
    'servico_quitacao'          => '"Sim" só se o comprador pagar R$ 1.000 pra FASTCAR negociar a quitação com o banco',
    'servico_forma_pagamento'   => 'ex.: PIX à vista',
    'servico_vencimento'        => 'ex.: 15/11/2026',
    'saldo_referencia_data'     => 'data do extrato/boleto do banco usado pro saldo — ex.: 05/11/2026',
    'responsavel_parcelas'      => 'quem paga as parcelas do banco até quitar — normalmente COMPRADOR/CESSIONÁRIO',
    'origem_recursos'           => 'ex.: recursos próprios do COMPRADOR/CESSIONÁRIO',
    'valor_banco_incluido'      => '"Sim" se parte do preço já vai direto pro banco',
    'valor_banco_valor'         => 'ex.: 18.500,00 (só se marcou "Sim" acima)',
    'baixa_gravame_responsavel' => 'ex.: COMPRADOR/CESSIONÁRIO',
    'baixa_gravame_prazo'       => 'ex.: 30 dias após a quitação',
    'transferencia_responsavel' => 'ex.: COMPRADOR/CESSIONÁRIO',
    'transferencia_prazo'       => 'ex.: 30 dias após a baixa do gravame',
    'negociacao_prazo'          => 'ex.: 15 dias antes do fim do prazo (só se contratou o serviço de quitação)',
    'beneficiario_preco'        => 'quem recebe o dinheiro da cessão — vazio = o vendedor original, por meio da FASTCAR',
    'beneficiario_conta'        => 'ex.: PIX CNPJ 66.934.500/0001-09 ou Banco C6, ag. 0001, cc 12345-6',
    'autoriza_recebimento'      => '"Sim" se o dinheiro passa pela conta da FASTCAR',
    // Locação
    'modalidade'                => 'Determinado = tem data pra acabar (ex.: 12 meses). Indeterminado = sem data',
    'prazo_meses'               => 'ex.: 12 (só se prazo determinado)',
    'data_inicio'               => 'dia em que o aluguel começa — ex.: 10/11/2026',
    'periodicidade'             => 'motorista de aplicativo normalmente paga Semanal',
    'aluguel_mensal'            => 'valor de CADA pagamento — ex.: 650,00 por semana ou 2.400,00 por mês',
    'loc.vencimento_dia'        => 'ex.: 5 (todo dia 5) — só se Mensal',
    'vencimento_semana'         => 'ex.: Segunda-feira — só se Semanal',
    'caucao_valor'              => 'ex.: 1.000,00. Sem caução = deixe vazio',
    'caucao_modalidade'         => 'ex.: PIX',
    'km_inicial'                => 'km do painel na entrega — ex.: 45230',
    'franquia_km'               => 'ex.: 1500 (km liberados por período). Sem limite = vazio',
    'franquia_periodo'          => 'ex.: por semana. Vazio = mesmo período do aluguel',
    'km_excedente_valor'        => 'ex.: 0,50 (por km acima da franquia)',
    'ipva_resp'                 => 'ex.: FASTCAR',
    'licenciamento_resp'        => 'ex.: FASTCAR',
    'seguro_texto'              => 'ex.: proteção veicular FASTCAR, franquia R$ 2.000 por conta do locatário',
    'franquia_sinistro_resp'    => 'ex.: LOCATÁRIO',
    'manutencao_resp'           => 'ex.: FASTCAR (troca de óleo a cada 10.000 km)',
    'pneus_regra'               => 'ex.: desgaste normal por conta da FASTCAR; furo/dano por conta do locatário',
    'combustivel_devolucao'     => 'ex.: mesmo nível da entrega',
    'rastreador'                => 'o carro tem rastreador? Normalmente "Instalado"',
    'uso'                       => 'ex.: Aplicativo (Uber/99)',
    'uso_outro'                 => 'só se escolheu "Outro" — ex.: entregas',
    'aviso_previo_dias'         => 'ex.: 7 (dias de aviso antes de devolver)',
    'mora_multa_pct'            => 'ex.: 2',
    'mora_juros'                => 'ex.: 1% ao mês',
    'mora_atualizacao'          => 'ex.: IPCA',
    'multa_saida_antecipada'    => 'ex.: 1 aluguel. Sem multa = vazio',
    'prazo_devolucao_resolucao' => 'ex.: 48 horas',
    'veiculo_cor'               => 'ex.: Prata',
    'canal_whatsapp'            => 'ex.: (11) 95834-7764',
    'proprietario_registral'    => 'nome no documento do carro. Vazio = vendedor original',
    'restricoes_conhecidas'     => 'ex.: nenhuma (ou "alienado ao Banco PAN")',
    'loc.entrega_data'          => 'dia em que o locatário retira o carro — ex.: 10/11/2026',
    'franquia_acumulavel'       => '"Sim" se o km que sobra numa semana passa pra próxima',
    'cobertura_texto'           => 'ex.: proteção FASTCAR — roubo/furto/colisão, franquia R$ 2.000. Sem cobertura = vazio',
    'local_devolucao'           => 'ex.: Sede FASTCAR — Barueri/SP',
    'canal_privacidade'         => 'ex.: Ouvidoria (11) 97816-4525',
];

function exemploCampoQuadro(string $prefixo, string $campo): string {
    return CAMPOS_QUADRO_EXEMPLOS[$prefixo . '.' . $campo] ?? CAMPOS_QUADRO_EXEMPLOS[$campo] ?? '';
}

function renderCamposQuadro(array $defs, array $obrigatorios, string $prefixo, array $dados, ?array $principais = null): string {
    // 29/10/2026 — "ficou muito campo pra preencher": com $principais, só esses
    // aparecem; o resto fica recolhido em "Mais condições" (padrões já preenchidos).
    if ($principais !== null) {
        $resto = array_diff_key($defs, array_flip($principais));
        $html = renderCamposQuadro(array_intersect_key($defs, array_flip($principais)), $obrigatorios, $prefixo, $dados);
        if ($resto) {
            $html .= '<details class="quadro-mais"><summary>➕ Mais condições do contrato (' . count($resto) . ' campos — opcional, os que têm padrão já vêm preenchidos)</summary>'
                . renderCamposQuadro($resto, $obrigatorios, $prefixo, $dados) . '</details>';
        }
        return $html;
    }
    $html = '<div class="grid-2">';
    $chaves = array_keys($defs);
    $metade = (int)ceil(count($chaves) / 2);
    foreach ([array_slice($chaves, 0, $metade), array_slice($chaves, $metade)] as $coluna) {
        $html .= '<div>';
        foreach ($coluna as $k) {
            $def = $defs[$k];
            $obrig = in_array($k, $obrigatorios, true);
            // Padrão só pré-preenche um cadastro NOVO (sem nada salvo ainda) —
            // nunca numa edição, senão a tela mostraria um valor que o PDF
            // não tem (campo apagado de propósito sairia "—" no contrato).
            $valor = array_key_exists($k, $dados) ? (string)$dados[$k] : ($dados ? '' : (string)($def['padrao'] ?? ''));
            $html .= '<label>' . e($def['label']) . ($obrig ? ' *' : '') . '</label>';
            $name = $prefixo . '[' . $k . ']';
            if ($def['tipo'] === 'select') {
                $html .= '<select name="' . $name . '"' . ($obrig ? ' required' : '') . '><option value="">— escolha —</option>';
                foreach ($def['opcoes'] as $ov => $ol) {
                    $html .= '<option value="' . e($ov) . '"' . ($valor === $ov ? ' selected' : '') . '>' . e($ol) . '</option>';
                }
                $html .= '</select>';
            } else {
                $attrs = match ($def['tipo']) {
                    'money' => 'type="text" inputmode="decimal" placeholder="0,00"',
                    'int'   => 'type="number" min="0" inputmode="numeric"',
                    'date'  => 'type="date"',
                    default => 'type="text"' . (isset($def['placeholder']) ? ' placeholder="' . e($def['placeholder']) . '"' : ''),
                };
                if ($def['tipo'] === 'money' && $valor !== '' && is_numeric($valor)) {
                    $valor = number_format((float)$valor, 2, ',', '.');
                }
                $html .= '<input ' . $attrs . ' name="' . $name . '" value="' . e($valor) . '"' . ($obrig ? ' required' : '') . '>';
            }
            $exemplo = exemploCampoQuadro($prefixo, $k);
            if ($exemplo !== '') $html .= '<small class="campo-exemplo">💡 ' . e($exemplo) . '</small>';
        }
        $html .= '</div>';
    }
    return $html . '</div>';
}

/**
 * Registra uma locação nova (modal "📄 Novo contrato → Locação" em
 * admin/vendas.php). Reaproveita criarVenda() (mesma checagem de veículo
 * disponível + histórico), só marca tipo_contrato='locacao' e grava o
 * locatário + Quadro-Resumo.
 */
function registrarLocacao(array $dados, int $responsavelId): array {
    require_once __DIR__ . '/vendas.php';
    $oportunidadeId = (int)($dados['oportunidade_id'] ?? 0);
    $nome = trim((string)($dados['locatario_nome'] ?? ''));
    $tel = normalizarTelefone((string)($dados['locatario_telefone'] ?? ''));
    if (!$oportunidadeId) throw new InvalidArgumentException('Escolha um veículo da frota.');
    if ($nome === '') throw new InvalidArgumentException('Nome do locatário é obrigatório.');
    if (!$tel || strlen($tel) < 12) throw new InvalidArgumentException('Telefone do locatário é obrigatório e precisa ser válido.');

    $loc = (array)($dados['loc'] ?? []);
    $vendaId = criarVenda($oportunidadeId, $responsavelId);
    getDB()->prepare("
        UPDATE vendas
        SET tipo_contrato = 'locacao', comprador_nome = ?, comprador_telefone = ?, comprador_cpf = ?, comprador_rg = ?,
            comprador_cnh = ?, comprador_email = ?, locacao_dados_json = ?, forma_pagamento = 'Locação',
            km_entrega = ?, updated_at = datetime('now','localtime')
        WHERE id = ?
    ")->execute([
        clean($nome), $tel,
        clean((string)($dados['locatario_cpf'] ?? '')),
        clean((string)($dados['locatario_rg'] ?? '')),
        clean((string)($dados['locatario_cnh'] ?? '')),
        clean((string)($dados['locatario_email'] ?? '')),
        json_encode($loc, JSON_UNESCAPED_UNICODE),
        isset($loc['km_inicial']) ? (int)$loc['km_inicial'] : null,
        $vendaId,
    ]);
    getDB()->prepare("UPDATE venda_historico SET observacao = 'Locação aberta' WHERE venda_id = ? AND etapa_nova = 'negociacao' AND etapa_anterior = ''")
        ->execute([$vendaId]);
    return ['venda_id' => $vendaId];
}

/** Salva o Quadro-Resumo editado no card de admin/venda.php. */
function salvarDadosLocacao(int $vendaId, array $loc): void {
    getDB()->prepare("UPDATE vendas SET locacao_dados_json = ?, km_entrega = ?, updated_at = datetime('now','localtime') WHERE id = ? AND tipo_contrato = 'locacao'")
        ->execute([json_encode($loc, JSON_UNESCAPED_UNICODE), isset($loc['km_inicial']) ? (int)$loc['km_inicial'] : null, $vendaId]);
}
