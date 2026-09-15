<?php
/**
 * AJAX de apoio pros selects em cascata de FIPE v2 (marca→modelo→ano→valor)
 * em admin/oportunidade.php — includes/fipe.php faz o trabalho de verdade
 * (cache, chamada HTTP, nunca lança), este arquivo só decide qual ação
 * chamar a partir de `?acao=` e devolve JSON. Restrito a admin logado
 * (mesma trava de toda tela do painel) — não expõe token nenhum, só os
 * dados já públicos da FIPE.
 */

require_once __DIR__ . '/_bootstrap.php';

header('Content-Type: application/json');

$acao = (string)($_GET['acao'] ?? '');

if ($acao === 'marcas') {
    echo json_encode(['marcas' => fipeV2ListarMarcas()]);
    exit;
}

if ($acao === 'modelos') {
    $marca = (string)($_GET['marca'] ?? '');
    echo json_encode(['modelos' => fipeV2ListarModelos($marca)]);
    exit;
}

if ($acao === 'anos') {
    $marca = (string)($_GET['marca'] ?? '');
    $modelo = (string)($_GET['modelo'] ?? '');
    echo json_encode(['anos' => fipeV2ListarAnos($marca, $modelo)]);
    exit;
}

if ($acao === 'valor') {
    $marca = (string)($_GET['marca'] ?? '');
    $modelo = (string)($_GET['modelo'] ?? '');
    $ano = (string)($_GET['ano'] ?? '');
    $dados = fipeV2BuscarValor($marca, $modelo, $ano);
    if (!$dados) {
        echo json_encode(['ok' => false]);
        exit;
    }
    echo json_encode([
        'ok' => true,
        'preco_texto' => $dados['price'] ?? '',
        'preco_numero' => isset($dados['price']) ? fipeV2ParsearPreco((string)$dados['price']) : null,
        'modelo' => $dados['model'] ?? '',
        'marca' => $dados['brand'] ?? '',
        'ano_modelo' => $dados['modelYear'] ?? '',
        'combustivel' => $dados['fuel'] ?? '',
        'codigo_fipe' => $dados['codeFipe'] ?? '',
        'mes_referencia' => $dados['referenceMonth'] ?? '',
    ]);
    exit;
}

echo json_encode(['erro' => 'ação inválida']);
