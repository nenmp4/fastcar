<?php
/**
 * AJAX de apoio pra busca de valor FIPE por placa (PlacaFIPE,
 * includes/fipe.php::placafipeConsultarPorPlaca()) em
 * admin/oportunidade.php — este arquivo só decide qual ação chamar a
 * partir de `?acao=` e devolve JSON. Restrito a admin logado (mesma trava
 * de toda tela do painel) — nunca expõe o token, só os dados já
 * devolvidos pela consulta.
 */

require_once __DIR__ . '/_bootstrap.php';

header('Content-Type: application/json');

$acao = (string)($_GET['acao'] ?? '');

if ($acao === 'buscar_placa') {
    $placa = (string)($_GET['placa'] ?? '');
    $dados = placafipeConsultarPorPlaca($placa);

    if ($dados === null) {
        echo json_encode(['ok' => false, 'msg' => 'Placa inválida, token não configurado, ou a API não respondeu. Confira em Configurações → FIPE.']);
        exit;
    }
    if ((int)($dados['codigo'] ?? 0) !== 1) {
        echo json_encode(['ok' => false, 'msg' => $dados['msg'] ?? 'Não foi possível consultar essa placa.']);
        exit;
    }

    echo json_encode([
        'ok' => true,
        'msg' => $dados['msg'] ?? '',
        'veiculo' => $dados['informacoes_veiculo'] ?? null,
        'candidatos' => array_map(function ($f) {
            return [
                'marca' => $f['marca'] ?? '',
                'modelo' => $f['modelo'] ?? '',
                'ano_modelo' => $f['ano_modelo'] ?? '',
                'combustivel' => $f['combustivel'] ?? '',
                'codigo_fipe' => $f['codigo_fipe'] ?? '',
                'mes_referencia' => $f['mes_referencia'] ?? '',
                'correspondencia' => $f['correspondencia'] ?? '',
                'valor_texto' => (($f['unidade_valor'] ?? 'R$') . ' ' . number_format((float)($f['valor'] ?? 0), 2, ',', '.')),
                'valor_numero' => isset($f['valor']) ? (float)$f['valor'] : null,
            ];
        }, $dados['fipe'] ?? []),
    ]);
    exit;
}

echo json_encode(['erro' => 'ação inválida']);
