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

const CONTRATO_MODELOS = [
    'compra'            => ['Compra com quitação futura do financiamento', 'A Fastcar COMPRA o veículo financiado do dono e quita o financiamento depois.', 'gerarPdfContratoCompra'],
    'promissoria'       => ['Venda na promissória (Fastcar quita)', 'A Fastcar VENDE com entrada + parcelas e a FASTCAR quita o financiamento no banco.', 'gerarPdfContratoVenda'],
    'posse'             => ['Transferência de posse (cliente quita)', 'A Fastcar passa a posse e o CLIENTE quita o financiamento direto no banco (padrão 15 meses).', 'gerarPdfContratoPosse'],
    'locacao'           => ['Locação de veículo', 'Aluguel semanal ou mensal — ninguém quita, o carro continua da Fastcar.', 'gerarPdfContratoLocacao'],
    'termo_retirada'    => ['Termo de devolução amigável', 'Termo de retirada do veículo, impresso e assinado à mão.', 'gerarPdfTermoRetiradaAmigavel'],
];

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

/** Caminho de um PDF do modelo (gerado agora, em arquivo temporário). Lança se não existir. */
function contratoModeloPdf(string $tipo): string {
    if (!isset(CONTRATO_MODELOS[$tipo])) throw new RuntimeException('Modelo inexistente.');
    $fn = CONTRATO_MODELOS[$tipo][2];
    $c = contratoModeloCamposEmBranco();
    if ($tipo === 'locacao') $c['_tipo_contrato'] = 'locacao';
    return $fn($c);
}
