<?php
/**
 * Contrato de PROMISSÓRIA (27/10/2026, "vamos substituir contrato atual no
 * sistema de promissória") — "CONTRATO DE AQUISIÇÃO DE POSSE VEICULAR COM
 * PROMESSA CONDICIONADA DE COMPRA E VENDA", transcrito do modelo
 * Contrato_FASTCAR_PROMISSÓRIA.docx. Substitui o antigo contrato-mestre de
 * venda com quitação futura (gerarPdfContratoVenda()) pra toda negociação
 * tipo_contrato='venda'.
 *
 * O Quadro-Resumo novo é quase todo DERIVADO do que a negociação já tem
 * (preço, entrada, parcelamento, FIPE, banco, prazo) — comissão de 15% da
 * FIPE, abatimento da entrada, composição 90/10 da parcela e total a pagar
 * são CALCULADOS em promissoriaQuadro(), nunca digitados. Só o que não
 * existe em lugar nenhum mora em `vendas.promissoria_dados_json` (chaves de
 * PROMISSORIA_CAMPOS) — mesmo padrão da locação (includes/locacao.php).
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/locacao.php'; // parseCamposQuadroPost()/renderCamposQuadro()

/** Comissão de intermediação: 15% sobre a FIPE (cláusula 2-A.1). */
const PROMISSORIA_COMISSAO_PCT = 15;
/** Composição de cada parcela: 90% locação/uso, 10% residual (cláusula 2.1). */
const PROMISSORIA_PCT_LOCACAO = 90;

const PROMISSORIA_CAMPOS = [
    'fipe_data_consulta'       => ['label' => 'Data da consulta FIPE', 'tipo' => 'date'],
    'comissao_aplicavel'       => ['label' => 'Comissão de intermediação (15% da FIPE)', 'tipo' => 'select', 'opcoes' => ['nao' => 'Não aplicável', 'sim' => 'Sim — há intermediação']],
    'comissao_saldo_vencimento'=> ['label' => 'Vencimento da comissão ainda a pagar (se a entrada não cobrir)', 'tipo' => 'date'],
    'vencimento_dia'           => ['label' => 'Vencimento das demais parcelas (dia do mês) — vazio = mesmo dia da 1ª', 'tipo' => 'int'],
    'saldo_final_valor'        => ['label' => 'Saldo final adicional (R$), se houver', 'tipo' => 'money'],
    'saldo_final_vencimento'   => ['label' => 'Vencimento do saldo final', 'tipo' => 'date'],
    'protecao_contratada'      => ['label' => 'Proteção veicular contratada?', 'tipo' => 'select', 'opcoes' => ['sim' => 'Sim', 'nao' => 'Não']],
    'protecao_responsavel'     => ['label' => 'Proteção veicular — responsável', 'tipo' => 'text'],
    'protecao_coberturas'      => ['label' => 'Proteção veicular — coberturas', 'tipo' => 'text'],
    'entrega_data'             => ['label' => 'Entrega da posse — data', 'tipo' => 'date'],
    'entrega_hora'             => ['label' => 'Entrega da posse — hora', 'tipo' => 'text', 'placeholder' => 'ex: 14h'],
    'entrega_local'            => ['label' => 'Entrega da posse — local', 'tipo' => 'text', 'padrao' => 'Sede FASTCAR — Barueri/SP'],
    'autoriza_imagem'          => ['label' => 'Uso de imagem e voz (vazio = marcar na assinatura)', 'tipo' => 'select', 'opcoes' => ['sim' => 'SIM', 'nao' => 'NÃO']],
    'prazo_devolucao_horas'    => ['label' => 'Prazo de devolução após notificação (horas, cl. 18.1)', 'tipo' => 'int', 'padrao' => '48'],
    'prazo_saneamento_dias'    => ['label' => 'Prazo para sanar descumprimento (dias úteis, cl. 24.2)', 'tipo' => 'int', 'padrao' => '10'],
];

function lerDadosPromissoria(?string $json): array {
    $d = json_decode((string)$json, true);
    return is_array($d) ? $d : [];
}

function parseCamposPromissoriaPost(array $post): array {
    return parseCamposQuadroPost(PROMISSORIA_CAMPOS, $post);
}

function renderCamposPromissoria(array $dados): string {
    return renderCamposQuadro(PROMISSORIA_CAMPOS, [], 'prom', $dados);
}

function salvarDadosPromissoria(int $vendaId, array $dados): void {
    getDB()->prepare("UPDATE vendas SET promissoria_dados_json = ?, updated_at = datetime('now','localtime') WHERE id = ? AND tipo_contrato = 'venda'")
        ->execute([json_encode($dados, JSON_UNESCAPED_UNICODE), $vendaId]);
}

/**
 * Valores calculados do Quadro-Resumo — única fonte pro PDF e pra prévia na
 * tela. $c = montarCamposContratoVenda(). Nunca chuta: sem FIPE não há
 * comissão calculável (fica null), sem parcelamento não há composição.
 */
function promissoriaQuadro(array $c): array {
    $p = $c['_prom'] ?? [];
    $fipe = $c['valor_fipe_referencia'];
    $entrada = (float)($c['valor_pago_contratacao'] ?? 0);

    $comissaoAplicavel = ($p['comissao_aplicavel'] ?? 'nao') === 'sim';
    $comissao = ($comissaoAplicavel && $fipe) ? round($fipe * PROMISSORIA_COMISSAO_PCT / 100, 2) : null;
    $comissaoAbatida = $comissao !== null ? min($comissao, $entrada) : null;
    $saldoLiquidoEntrada = round($entrada - (float)($comissaoAbatida ?? 0), 2);
    $comissaoAPagar = $comissao !== null ? round($comissao - $comissaoAbatida, 2) : null;

    $qtd = (int)($c['parcelamento_qtd_parcelas'] ?? 0);
    $valorParcela = (float)($c['parcelamento_valor_parcela'] ?? 0);
    $temParcelas = $qtd > 0 && $valorParcela > 0;
    $parteLocacao = $temParcelas ? round($valorParcela * PROMISSORIA_PCT_LOCACAO / 100, 2) : null;
    $parteResidual = $temParcelas ? round($valorParcela - $parteLocacao, 2) : null;
    $totalParcelas = $temParcelas ? round($qtd * $valorParcela, 2) : null;

    $saldoFinal = isset($p['saldo_final_valor']) ? (float)$p['saldo_final_valor'] : null;
    $totalAPagar = round($entrada + (float)$totalParcelas + (float)$saldoFinal + (float)$comissaoAPagar, 2);

    // Dia de vencimento das demais parcelas: o informado, senão o mesmo dia
    // da 1ª parcela (data em d/m/Y vinda de montarCamposContratoVenda()).
    $diaDemais = !empty($p['vencimento_dia']) ? (int)$p['vencimento_dia'] : null;
    if ($diaDemais === null && !empty($c['parcelamento_primeira_parcela_data'])) {
        $diaDemais = (int)substr((string)$c['parcelamento_primeira_parcela_data'], 0, 2) ?: null;
    }

    return [
        'comissao_aplicavel' => $comissaoAplicavel,
        'comissao' => $comissao,
        'comissao_abatida' => $comissaoAbatida,
        'saldo_liquido_entrada' => $saldoLiquidoEntrada,
        'comissao_a_pagar' => $comissaoAPagar,
        'tem_parcelas' => $temParcelas,
        'parte_locacao' => $parteLocacao,
        'parte_residual' => $parteResidual,
        'total_parcelas' => $totalParcelas,
        'saldo_final' => $saldoFinal,
        'total_a_pagar' => $totalAPagar,
        'dia_demais' => $diaDemais,
    ];
}
