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
    'aluguel_mensal'          => ['label' => 'Aluguel mensal (R$)', 'tipo' => 'money'],
    'vencimento_dia'          => ['label' => 'Vencimento (dia do mês)', 'tipo' => 'int'],
    'caucao_valor'            => ['label' => 'Caução/garantia (R$), se houver', 'tipo' => 'money'],
    'caucao_modalidade'       => ['label' => 'Modalidade da caução', 'tipo' => 'text', 'placeholder' => 'PIX, depósito, cheque...'],
    'km_inicial'              => ['label' => 'Quilometragem inicial (km)', 'tipo' => 'int'],
    'franquia_km'             => ['label' => 'Franquia de quilometragem (km)', 'tipo' => 'int'],
    'franquia_periodo'        => ['label' => 'Franquia por', 'tipo' => 'text', 'padrao' => 'mês'],
    'km_excedente_valor'      => ['label' => 'Km excedente (R$/km)', 'tipo' => 'money'],
    'ipva_resp'               => ['label' => 'IPVA — responsável', 'tipo' => 'text', 'padrao' => 'FASTCAR'],
    'licenciamento_resp'      => ['label' => 'Licenciamento — responsável', 'tipo' => 'text', 'padrao' => 'FASTCAR'],
    'seguro_texto'            => ['label' => 'Seguro/proteção', 'tipo' => 'text'],
    'franquia_sinistro_resp'  => ['label' => 'Franquia de sinistro — responsável', 'tipo' => 'text', 'padrao' => 'LOCATÁRIO'],
    'manutencao_resp'         => ['label' => 'Manutenção preventiva — responsável', 'tipo' => 'text'],
    'pneus_regra'             => ['label' => 'Pneus/itens de desgaste — regra', 'tipo' => 'text'],
    'combustivel_devolucao'   => ['label' => 'Combustível na devolução', 'tipo' => 'text', 'padrao' => 'Mesmo nível da entrega'],
    'rastreador'              => ['label' => 'Rastreador', 'tipo' => 'select', 'opcoes' => ['instalado' => 'Instalado', 'sera_instalado' => 'Será instalado', 'nao_aplicavel' => 'Não aplicável']],
    'uso'                     => ['label' => 'Uso autorizado', 'tipo' => 'select', 'opcoes' => ['pessoal' => 'Pessoal', 'profissional' => 'Profissional', 'aplicativo' => 'Aplicativo', 'outro' => 'Outro']],
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
const LOCACAO_CAMPOS_OBRIGATORIOS = ['modalidade', 'data_inicio', 'aluguel_mensal', 'vencimento_dia'];

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
    return $faltando;
}

/** Renderiza os inputs (name="loc[chave]") — usado no modal e no card. */
function renderCamposLocacao(array $dados): string {
    return renderCamposQuadro(LOCACAO_CAMPOS, LOCACAO_CAMPOS_OBRIGATORIOS, 'loc', $dados);
}

/**
 * Renderização genérica de um Quadro-Resumo (name="{prefixo}[chave]") —
 * compartilhada com a promissória (includes/promissoria.php).
 */
function renderCamposQuadro(array $defs, array $obrigatorios, string $prefixo, array $dados): string {
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
