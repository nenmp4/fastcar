<?php
/**
 * Modelos de contrato em branco pra baixar (Configurações → 📄 Modelos de contrato).
 * 23/11/2026, "disponibilizar esses modelos no sistema em configurações".
 *
 * Os modelos atuais são gerados NA HORA pelas mesmas funções que o sistema usa
 * pra gerar o contrato de verdade (includes/contratos_pdf.php) — o texto nunca
 * fica desatualizado. Os campos saem como linhas pra preencher.
 */
require_once __DIR__ . '/contratos_pdf.php';
require_once __DIR__ . '/promissoria.php';
require_once __DIR__ . '/posse.php';
require_once __DIR__ . '/locacao.php';
require_once __DIR__ . '/contrato_venda_quitacao_pdf.php';

const CONTRATO_MODELOS = [
    'compra'            => ['Compra com quitação futura do financiamento', 'Contrato-mestre de compra (Fastcar compra o veículo financiado).', 'gerarPdfContratoCompra'],
    'venda_quitacao'    => ['Venda com quitação futura do financiamento', 'Contrato-mestre de compra e venda — a Fastcar quita o financiamento no prazo combinado.', 'gerarPdfContratoVendaQuitacao'],
    'promissoria'       => ['Venda na promissória', 'Aquisição de posse com promessa condicionada de compra e venda — o contrato de venda gerado hoje.', 'gerarPdfContratoVenda'],
    'posse'             => ['Transferência de posse', 'Cessão onerosa e transferência de posse veicular com intermediação.', 'gerarPdfContratoPosse'],
    'locacao'           => ['Locação de veículo', 'Aluguel semanal ou mensal.', 'gerarPdfContratoLocacao'],
    'termo_retirada'    => ['Termo de devolução amigável', 'Termo de retirada do veículo, impresso e assinado à mão.', 'gerarPdfTermoRetiradaAmigavel'],
];

const CONTRATO_MODELO_ARQUIVO_FIXO = [];

/** Campos em branco pro modelo: todo $c['x'] usado no arquivo vira linha; valores em R$ ficam vazios. */
function contratoModeloCamposEmBranco(): array {
    $src = (string)file_get_contents(__DIR__ . '/contratos_pdf.php');
    preg_match_all("/\\\$c\['([a-z_0-9]+)'\]/", $src, $m);
    $c = [];
    foreach (array_unique($m[1]) as $k) $c[$k] = '______________';
    foreach (array_keys($c) as $k) {
        if (preg_match('/valor|preco|saldo|percentual|qtd|km|^bem_troca_recebido$|^debitos_existem$|^autoriza_uso_imagem$/', $k)) $c[$k] = null;
        if (str_starts_with($k, 'testemunha')) $c[$k] = '';
    }
    $c['_prom'] = [];
    $c['_loc'] = [];
    $c['_entrada_partes'] = [];
    $c['_tipo_contrato'] = 'venda';
    $c['_modelo_contrato'] = '';
    $c['_venda_id'] = '____';
    $c['prazo_quitacao_meses'] = 18;
    $c['data_entrega_posse'] = '___/___/______';
    $c['data_extenso'] = '____ de ______________ de ______';
    $c['parcelamento_periodicidade'] = 'mensal';
    $c['parcelamento_primeira_parcela_data'] = '';
    return $c;
}

/** Caminho de um PDF do modelo (gerado agora, em arquivo temporário, ou o fixo). Lança se não existir. */
function contratoModeloPdf(string $tipo): string {
    if (!isset(CONTRATO_MODELOS[$tipo])) throw new RuntimeException('Modelo inexistente.');
    if (isset(CONTRATO_MODELO_ARQUIVO_FIXO[$tipo])) {
        $arq = CONTRATO_MODELO_ARQUIVO_FIXO[$tipo];
        if (!is_file($arq)) throw new RuntimeException('Arquivo do modelo não encontrado.');
        return $arq;
    }
    $fn = CONTRATO_MODELOS[$tipo][2];
    $c = contratoModeloCamposEmBranco();
    if ($tipo === 'locacao') $c['_tipo_contrato'] = 'locacao';
    return $fn($c);
}
