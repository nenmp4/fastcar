<?php
/**
 * Histórico completo de um veículo físico (30/10/2026, "dados do carro se
 * cruzem — consultor e vendedor — evitar duplicação, clique na engrenagem
 * mostre todo histórico do veículo, simples").
 *
 * O mesmo carro pode ter mais de uma linha em `oportunidades` (comprado,
 * vendido, devolvido, comprado de novo — ou cadastrado em duplicidade).
 * Aqui tudo é cruzado pela PLACA (normalizada, sem hífen/espaço) ou pelo
 * CHASSI, e vira uma linha do tempo única: compra, vistorias, consultas
 * FIPE, negociações de venda/locação, contratos, devolução.
 * Só leitura — nunca altera nada.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';

function _veiculoPlacaNorm(?string $placa): string {
    return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)$placa));
}

/** Ids de todas as oportunidades que são o MESMO carro físico (placa ou chassi). */
function veiculoIdsMesmoCarro(int $oportunidadeId): array {
    $db = getDB();
    $st = $db->prepare("SELECT veiculo_placa, veiculo_chassi FROM oportunidades WHERE id = ?");
    $st->execute([$oportunidadeId]);
    $v = $st->fetch();
    if (!$v) return [];
    $placa = _veiculoPlacaNorm($v['veiculo_placa'] ?? '');
    $chassi = strtoupper(trim((string)($v['veiculo_chassi'] ?? '')));
    $ids = [$oportunidadeId];
    if (strlen($placa) >= 6 || strlen($chassi) >= 10) {
        $st = $db->prepare("SELECT id FROM oportunidades
            WHERE (? != '' AND upper(replace(replace(COALESCE(veiculo_placa,''),'-',''),' ','')) = ?)
               OR (? != '' AND upper(trim(COALESCE(veiculo_chassi,''))) = ?)");
        $st->execute([strlen($placa) >= 6 ? $placa : '', $placa, strlen($chassi) >= 10 ? $chassi : '', $chassi]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) $ids[] = (int)$id;
    }
    return array_values(array_unique($ids));
}

/**
 * Ficha + linha do tempo. Retorna ['veiculo'=>[], 'cadastros'=>[...], 'eventos'=>[...]],
 * eventos mais recentes primeiro: ['data','icone','titulo','detalhe','url'].
 */
function veiculoHistorico(int $oportunidadeId): array {
    $db = getDB();
    $ids = veiculoIdsMesmoCarro($oportunidadeId);
    if (!$ids) return ['veiculo' => null, 'cadastros' => [], 'eventos' => [], 'pagamentos' => []];
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $nomeUsuario = function ($id) use ($db) {
        if (!$id) return '';
        static $cache = [];
        if (!isset($cache[$id])) {
            $st = $db->prepare("SELECT nome FROM usuarios WHERE id = ?");
            $st->execute([$id]);
            $cache[$id] = (string)($st->fetchColumn() ?: '');
        }
        return $cache[$id];
    };
    $moeda = fn($v) => $v !== null && $v !== '' && (float)$v > 0 ? 'R$ ' . number_format((float)$v, 2, ',', '.') : '';

    $st = $db->prepare("SELECT o.*, c.nome AS cliente_nome, c.telefone AS cliente_telefone
        FROM oportunidades o JOIN clientes c ON c.id = o.cliente_id WHERE o.id IN ($ph) ORDER BY o.id");
    $st->execute($ids);
    $cadastros = $st->fetchAll();

    // Ficha: o cadastro pedido, completando campo vazio com o de outro cadastro do mesmo carro.
    $veiculo = [];
    foreach (array_merge(array_filter($cadastros, fn($c) => (int)$c['id'] === $oportunidadeId), $cadastros) as $c) {
        foreach (['veiculo_marca', 'veiculo_modelo', 'veiculo_ano', 'veiculo_placa', 'veiculo_chassi', 'veiculo_renavam', 'tipo_veiculo', 'valor_fipe_referencia'] as $k) {
            if (empty($veiculo[$k]) && !empty($c[$k])) $veiculo[$k] = $c[$k];
        }
    }

    $eventos = [];
    foreach ($cadastros as $c) {
        $url = '/admin/oportunidade.php?id=' . (int)$c['id'];
        $quem = $nomeUsuario($c['responsavel_id']);
        $eventos[] = ['data' => $c['created_at'], 'icone' => '📥',
            'titulo' => (($c['origem'] ?? '') === 'manual' ? 'Cadastrado à mão' : 'Lead de compra entrou') . ' (cadastro #' . $c['id'] . ')',
            'detalhe' => 'Vendedor original: ' . ($c['cliente_nome'] ?: $c['cliente_telefone']) . ($quem ? ' · consultor: ' . $quem : ''), 'url' => $url];
        if ($c['etapa'] === 'fechado') {
            $eventos[] = ['data' => ($c['data_compra'] ?: $c['updated_at']) . (strlen((string)$c['data_compra']) === 10 ? ' 12:00:00' : ''),
                'icone' => '🤝', 'titulo' => 'Fastcar comprou o veículo',
                'detalhe' => trim(($moeda($c['valor_final']) ? 'Valor pago: ' . $moeda($c['valor_final']) : '') . ($nomeUsuario($c['fechado_por']) ? ' · fechado por ' . $nomeUsuario($c['fechado_por']) : ''), ' ·'),
                'url' => $url];
        } elseif (in_array($c['etapa'], ['perdido', 'sem_perfil'], true)) {
            $eventos[] = ['data' => $c['updated_at'], 'icone' => '❌', 'titulo' => 'Negociação de compra encerrada',
                'detalhe' => (string)($c['motivo_perda'] ?: ''), 'url' => $url];
        }
        if (!empty($c['financiamento_quitado']) && !empty($c['financiamento_quitado_em'])) {
            $eventos[] = ['data' => $c['financiamento_quitado_em'], 'icone' => '✅', 'titulo' => 'Financiamento quitado', 'detalhe' => '', 'url' => $url];
        }
    }

    try {
        $st = $db->prepare("SELECT * FROM fipe_consultas WHERE oportunidade_id IN ($ph) ORDER BY id");
        $st->execute($ids);
        foreach ($st->fetchAll() as $f) {
            $eventos[] = ['data' => $f['created_at'], 'icone' => '📊', 'titulo' => 'Consulta FIPE: ' . $moeda($f['valor']),
                'detalhe' => trim(($f['descricao'] ?: '') . ($nomeUsuario($f['usuario_id']) ? ' · por ' . $nomeUsuario($f['usuario_id']) : '')),
                'url' => '/admin/oportunidade.php?id=' . (int)$f['oportunidade_id']];
        }
    } catch (Throwable $e) {
    }

    $st = $db->prepare("SELECT * FROM veiculo_avaliacoes WHERE oportunidade_id IN ($ph) ORDER BY id");
    $st->execute($ids);
    foreach ($st->fetchAll() as $a) {
        $eventos[] = ['data' => $a['concluida_em'] ?: $a['created_at'], 'icone' => '🔍',
            'titulo' => 'Vistoria de ' . ($a['tipo'] === 'venda' ? 'entrega (venda)' : 'recebimento (compra)') . ($a['status'] === 'concluida' ? ' concluída' : ' — ' . $a['status']),
            'detalhe' => trim(($a['km_atual'] ? 'KM ' . number_format((int)$a['km_atual'], 0, ',', '.') : '') . ($nomeUsuario($a['avaliador_id']) ? ' · avaliador: ' . $nomeUsuario($a['avaliador_id']) : ''), ' ·'),
            'url' => '/admin/avaliacao.php?id=' . (int)$a['id']];
    }

    $st = $db->prepare("SELECT * FROM vendas WHERE oportunidade_id IN ($ph) ORDER BY id");
    $st->execute($ids);
    foreach ($st->fetchAll() as $v) {
        $url = '/admin/venda.php?id=' . (int)$v['id'];
        $tipo = ($v['tipo_contrato'] ?? '') === 'locacao' ? 'locação' : 'venda';
        $eventos[] = ['data' => $v['created_at'], 'icone' => '🛒', 'titulo' => 'Negociação de ' . $tipo . ' aberta (#' . $v['id'] . ')',
            'detalhe' => 'Comprador: ' . ($v['comprador_nome'] ?: ($v['comprador_telefone'] ?: '—')) . ($nomeUsuario($v['responsavel_id']) ? ' · vendedor: ' . $nomeUsuario($v['responsavel_id']) : ''), 'url' => $url];
        if ($v['etapa'] === 'vendido') {
            $eventos[] = ['data' => ($v['data_venda'] ?: $v['updated_at']) . (strlen((string)$v['data_venda']) === 10 ? ' 12:00:00' : ''),
                'icone' => $tipo === 'locação' ? '🔑' : '💰', 'titulo' => $tipo === 'locação' ? 'Locado' : 'Vendido',
                'detalhe' => trim(($v['comprador_nome'] ?: '') . ($moeda($v['preco_venda']) ? ' · ' . $moeda($v['preco_venda']) : '')), 'url' => $url];
        } elseif ($v['etapa'] === 'cancelada') {
            $eventos[] = ['data' => $v['updated_at'], 'icone' => '🔙', 'titulo' => 'Negociação cancelada / veículo devolvido',
                'detalhe' => (string)($v['motivo_cancelamento'] ?: ''), 'url' => $url];
        }
    }

    $st = $db->prepare("SELECT * FROM contratos WHERE oportunidade_id IN ($ph) AND status IN ('enviado','visualizado','assinado','recusado') ORDER BY id");
    $st->execute($ids);
    foreach ($st->fetchAll() as $ct) {
        $assinado = $ct['status'] === 'assinado';
        $eventos[] = ['data' => $assinado && $ct['assinado_em'] ? $ct['assinado_em'] : $ct['created_at'], 'icone' => $assinado ? '✍️' : '📄',
            'titulo' => 'Contrato de ' . ($ct['tipo'] === 'venda' ? 'venda' : 'compra') . ' ' . ($assinado ? 'assinado' : ($ct['status'] === 'recusado' ? 'recusado' : 'enviado pra assinatura')),
            'detalhe' => '', 'url' => $ct['venda_id'] ? '/admin/venda.php?id=' . (int)$ct['venda_id'] : '/admin/oportunidade.php?id=' . (int)$ct['oportunidade_id']];
    }

    usort($eventos, fn($a, $b) => strcmp((string)$b['data'], (string)$a['data']));

    // Pagamentos ao vendedor original (29/10/2026, "coloca valores pagos pro
    // vendedor do veículo"): valor da compra de cada cadastro fechado + o
    // que está lançado no financeiro como despesa daquela compra.
    $pagamentos = [];
    foreach ($cadastros as $c) {
        if ($c['etapa'] !== 'fechado') continue;
        $pagamentos[] = ['oportunidade_id' => (int)$c['id'], 'vendedor' => $c['cliente_nome'] ?: $c['cliente_telefone'],
            'valor_final' => $c['valor_final'] !== null ? (float)$c['valor_final'] : null, 'data_compra' => $c['data_compra'],
            'pix' => (string)($c['pix_pagamento_cedente'] ?? ''), 'lancamentos' => []];
    }
    if ($pagamentos) {
        try {
            $st = $db->prepare("SELECT oportunidade_id, descricao, valor, status, data_pagamento, data_vencimento FROM fin_lancamentos
                WHERE oportunidade_id IN ($ph) AND tipo = 'despesa' AND status != 'cancelado'
                  AND (origem = 'fechamento_compra' OR categoria_id IN (SELECT id FROM fin_categorias WHERE nome LIKE 'Compra de veículo%'))
                ORDER BY COALESCE(data_pagamento, data_vencimento)");
            $st->execute($ids);
            foreach ($st->fetchAll() as $l) {
                foreach ($pagamentos as &$p) if ($p['oportunidade_id'] === (int)$l['oportunidade_id']) $p['lancamentos'][] = $l;
                unset($p);
            }
        } catch (Throwable $e) {
        }
    }
    return ['veiculo' => $veiculo, 'cadastros' => $cadastros, 'eventos' => $eventos, 'pagamentos' => $pagamentos];
}
