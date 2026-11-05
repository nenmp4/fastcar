<?php
/**
 * Relatório de lucro por veículo (04/11/2026, "tela valor pago no veículo e
 * lucro do veículo, valor das parcelas, entrada, quantidade de parcelas —
 * deixa campo pra adicionar valor da quitação").
 *
 * Uma linha por veículo comprado (oportunidade etapa='fechado'):
 *   custo  = valor pago ao vendedor (valor_final) + quitação do financiamento
 *            + comissões (compra + venda, fin_lancamentos não cancelados)
 *            (oportunidades.valor_quitacao, digitada aqui — nunca chutada)
 *   venda  = entrada + parcela × quantidade (termos salvos na venda);
 *            sem parcelamento, usa o preço da venda
 *   lucro  = venda − custo  (previsto: conta parcela ainda não paga)
 *   recebido = receitas da venda já marcadas pagas no financeiro
 * Locação fica de fora do cálculo (aluguel não é preço de venda).
 */
// ob_start antes de tudo: o fpdf.php vaza uma quebra de linha no fim do arquivo e quebraria o PDF.
ob_start();
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../includes/contratos_pdf.php';
require_once __DIR__ . '/../includes/contratos.php';
requireSuperAdmin();

// 04/11/2026, "mostra fipe, quitação calculado em 20% da FIPE" — sem
// quitação digitada, o custo usa uma estimativa de 20% da FIPE (marcada
// como estimada na tela/PDF); o valor digitado sempre vence.
const RL_QUITACAO_PCT_FIPE = 20;
const RL_LOTE_LEITURA = 8;

$db = getDB();
$erro = '';
$sucesso = '';

// 05/11/2026 (plano de UX) — edição inline: o mesmo POST com ajax=1 devolve
// os valores novos da linha em JSON (sem recarregar); sem JS, segue o
// redirect de sempre.
function rlAjax(): bool { return !empty($_POST['ajax']); }
function rlResponderAjax(?int $opId, string $erro = ''): void {
    header('Content-Type: application/json; charset=utf-8');
    if ($erro !== '' || !$opId) { http_response_code(400); echo json_encode(['ok' => false, 'erro' => $erro ?: 'Veículo não encontrado.']); exit; }
    $ls = rlConsultar(['o.id = ?'], [$opId]);
    if (!$ls) { http_response_code(404); echo json_encode(['ok' => false, 'erro' => 'Veículo não encontrado.']); exit; }
    $l = $ls[0];
    rlCalcularLinha($l);
    echo json_encode(['ok' => true, 'linha' => rlDadosLinha($l)], JSON_UNESCAPED_UNICODE);
    exit;
}
/** Números crus da linha (data-* no <tr>, usados pelo JS pra refazer os totais). */
function rlDadosLinha(array $l): array {
    return [
        'fipe' => (float)($l['valor_fipe_referencia'] ?? 0), 'pago' => (float)($l['valor_final'] ?? 0),
        'quit' => (float)($l['_quitacao'] ?? 0), 'quitest' => $l['_quit_estimada'] ? 1 : 0,
        'quitreal' => $l['valor_quitacao'] !== null ? (float)$l['valor_quitacao'] : null,
        'quitprev' => $l['_quit_prevista'], 'com' => (float)$l['_comissao'], 'custo' => (float)$l['_custo'],
        'venda' => $l['_venda_total'], 'receb' => (float)$l['recebido'], 'lucro' => $l['_lucro'],
        'incompleta' => $l['_incompleta'] ? 1 : 0, 'entrada' => (float)($l['valor_pago_contratacao'] ?? 0),
    ];
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'salvar_quitacao') {
    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        $erro = 'Sessão expirada, recarregue a página.';
        if (rlAjax()) rlResponderAjax(null, $erro);
    } else {
        $opId = (int)($_POST['oportunidade_id'] ?? 0);
        $valor = valorMonetario((string)($_POST['valor_quitacao'] ?? ''));
        $st = $db->prepare("UPDATE oportunidades SET valor_quitacao = ?, updated_at = datetime('now','localtime') WHERE id = ? AND etapa = 'fechado'");
        $st->execute([$valor, $opId]);
        if (rlAjax()) rlResponderAjax($st->rowCount() ? $opId : null);
        if ($st->rowCount()) {
            $q = $_GET;
            $q['salvo'] = $opId;
            header('Location: /admin/relatorio_lucro_veiculos.php?' . http_build_query($q) . '#op' . $opId);
            exit;
        }
        $erro = 'Veículo não encontrado.';
    }
}
if (!empty($_GET['salvo'])) $sucesso = 'Valor da quitação salvo.';

// 05/11/2026, "coloca opção digitar FIPE" — mesmo esquema da quitação.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'salvar_fipe') {
    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        $erro = 'Sessão expirada, recarregue a página.';
        if (rlAjax()) rlResponderAjax(null, $erro);
    } else {
        $opId = (int)($_POST['oportunidade_id'] ?? 0);
        $valorFipe = valorMonetario((string)($_POST['valor_fipe'] ?? ''));
        if ($valorFipe !== null && $valorFipe <= 0) $valorFipe = null;
        $st = $db->prepare("UPDATE oportunidades SET valor_fipe_referencia = ?, updated_at = datetime('now','localtime') WHERE id = ? AND etapa = 'fechado'");
        $st->execute([$valorFipe, $opId]);
        if ($st->rowCount()) {
            $db->prepare("INSERT INTO oportunidade_historico (oportunidade_id, etapa_anterior, etapa_nova, responsavel_id, observacao) VALUES (?, 'fechado', 'fechado', ?, ?)")
               ->execute([$opId, (int)$_SESSION['admin_id'], 'FIPE ' . ($valorFipe !== null ? 'definida em R$ ' . number_format($valorFipe, 2, ',', '.') : 'apagada') . ' pelo relatório de lucro.']);
            if (rlAjax()) rlResponderAjax($opId);
            $_SESSION['rl_msg'] = ['ok', 'FIPE salva.'];
            header('Location: /admin/relatorio_lucro_veiculos.php?' . http_build_query($_GET) . '#op' . $opId);
            exit;
        }
        if (rlAjax()) rlResponderAjax(null);
        $erro = 'Veículo não encontrado.';
    }
}
if (!empty($_SESSION['rl_msg'])) { [$tipoMsg, $txtMsg] = $_SESSION['rl_msg']; unset($_SESSION['rl_msg']); if ($tipoMsg === 'ok') $sucesso = $txtMsg; else $erro = $txtMsg; }

// 05/11/2026, "FIPE: coloca botão IA ler informações do contrato ZapSign" —
// lê o contrato assinado e preenche FIPE / valor pago / saldo do
// financiamento que estiverem vazios (nunca sobrescreve).
function rlTextoLeitura(array $r): string {
    if (!$r['ok']) return $r['erro'];
    return $r['preenchidos'] ? 'preencheu ' . implode(', ', $r['preenchidos']) : 'nada novo no contrato';
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'ler_contrato') {
    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        $erro = 'Sessão expirada, recarregue a página.';
    } else {
        $opId = (int)($_POST['oportunidade_id'] ?? 0);
        $r = preencherValoresPeloContratoCompra($opId);
        $_SESSION['rl_msg'] = [$r['ok'] ? 'ok' : 'erro', '🤖 Contrato lido: ' . rlTextoLeitura($r) . '.'];
        header('Location: /admin/relatorio_lucro_veiculos.php?' . http_build_query($_GET) . '#op' . $opId);
        exit;
    }
}

// 05/11/2026, "contratos antigos puxados do CRM antigo: valor da parcela/
// entrada continua zerado mas tem contrato — botão pra ler" — lê o contrato
// da VENDA com IA e preenche entrada/parcelas/preço vazios. Contrato que na
// verdade é a COMPRA do carro (importação antiga) nunca preenche a venda:
// avisa e manda pra tela da venda, onde dá pra mover pra compra.
function rlLerContratoVenda(int $vendaId): array {
    $lc = lerContratoImportadoVendaIA($vendaId);
    if (!$lc['ok']) return ['tipo' => 'erro', 'msg' => $lc['erro']];
    if ($lc['tipo_contrato'] === 'compra') {
        $_SESSION['venda_leitura_contrato'][$vendaId] = $lc;
        return ['tipo' => 'compra', 'msg' => "venda #{$vendaId}: o contrato é da COMPRA do carro (" . ($lc['parte']['nome'] ?: 'vendedor') . ' vendeu pra Fastcar) — abra a venda e use "Mover pra compra"'];
    }
    $pre = aplicarLeituraContratoNaVenda($vendaId, $lc, (int)$_SESSION['admin_id']);
    return ['tipo' => $pre ? 'ok' : 'nada', 'msg' => "venda #{$vendaId}: " . ($pre ? 'preencheu ' . implode(', ', $pre) : 'nada novo no contrato')];
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'ler_contrato_venda') {
    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        $erro = 'Sessão expirada, recarregue a página.';
    } else {
        @set_time_limit(120);
        $vId = (int)($_POST['venda_id'] ?? 0);
        $r = rlLerContratoVenda($vId);
        $_SESSION['rl_msg'] = [$r['tipo'] === 'erro' || $r['tipo'] === 'compra' ? 'erro' : 'ok', '🤖 ' . $r['msg'] . '.'];
        header('Location: /admin/relatorio_lucro_veiculos.php?' . http_build_query($_GET) . '#op' . (int)($_POST['oportunidade_id'] ?? 0));
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'mover_contrato_compra_rl') {
    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        $erro = 'Sessão expirada, recarregue a página.';
    } else {
        $vId = (int)($_POST['venda_id'] ?? 0);
        $lc = contratoCompraDetectadoNaVenda($vId);
        if (!$lc) {
            $_SESSION['rl_msg'] = ['erro', 'Contrato de compra não encontrado nessa venda — leia o contrato de novo.'];
        } else {
            $rm = moverContratoVendaParaCompra($vId, (int)$lc['contrato_id'], $lc, false, (int)$_SESSION['admin_id']);
            if ($rm['ok']) {
                auditoriaRegistrar('contrato_movido_para_compra', (int)$_SESSION['admin_id'], (string)$_SESSION['admin_nome'], 'venda', $vId,
                    'Contrato #' . (int)$lc['contrato_id'] . ' → compra (pelo relatório de lucro)');
                $_SESSION['rl_msg'] = ['ok', "🔁 Contrato movido pra compra" . ($rm['preenchidos'] ? ' (preencheu ' . implode(', ', $rm['preenchidos']) . ')' : '')
                    . ". Agora suba o contrato de venda da venda #{$vId} e diga pra quem foi."];
            } else {
                $_SESSION['rl_msg'] = ['erro', $rm['erro']];
            }
        }
        header('Location: /admin/relatorio_lucro_veiculos.php?' . http_build_query($_GET) . '#op' . (int)($_POST['oportunidade_id'] ?? 0));
        exit;
    }
}

$filtro = in_array($_GET['filtro'] ?? '', ['vendidos', 'estoque', 'todos'], true) ? $_GET['filtro'] : 'vendidos';
$busca = trim((string)($_GET['q'] ?? ''));
// Mês (AAAA-MM): vendido pela data da venda; sem venda pela data da compra.
$mes = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['mes'] ?? '')) ? $_GET['mes'] : '';
// 05/11/2026, "coloca filtro pra facilitar" — atalhos de pendência e ordenação.
const RL_PENDENCIAS = [
    'sem_fipe' => '❗ Sem FIPE',
    'sem_pago' => '❗ Sem valor pago',
    'sem_quitacao' => '✏️ Sem quitação real',
    'lucro_neg' => '🔻 Lucro negativo',
    'lucro_zero' => '⚠️ Lucro zerado',
    'incompletos' => '🧩 Dados incompletos',
    'zapsign' => '📥 Migrados da ZapSign',
    'sem_entrada' => '💳 Venda sem entrada/parcelas',
    'contrato_compra' => '⚠️ Contrato da venda é de compra',
    'sem_contrato_venda' => '📄 Vendido sem contrato de venda',
];
$pend = array_key_exists($_GET['pend'] ?? '', RL_PENDENCIAS) ? $_GET['pend'] : '';
$ordem = in_array($_GET['ordem'] ?? '', ['data', 'lucro_maior', 'lucro_menor', 'custo_maior', 'venda_maior'], true) ? $_GET['ordem'] : 'data';

$where = ["o.etapa = 'fechado'", "COALESCE(o.oculto_estoque_motivo, '') NOT LIKE 'Cadastro duplicado%'"];
$params = [];
if ($filtro === 'vendidos') $where[] = "v.id IS NOT NULL AND v.etapa = 'vendido'";
if ($filtro === 'estoque') $where[] = 'v.id IS NULL';
if ($mes !== '') {
    $where[] = "substr(COALESCE(v.data_venda, o.data_compra), 1, 7) = ?";
    $params[] = $mes;
}
if ($busca !== '') {
    $buscaPlaca = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $busca));
    $where[] = "(o.veiculo_marca LIKE ? OR o.veiculo_modelo LIKE ? OR (o.veiculo_marca || ' ' || o.veiculo_modelo) LIKE ? OR v.comprador_nome LIKE ?"
        . ($buscaPlaca !== '' ? " OR UPPER(REPLACE(REPLACE(o.veiculo_placa,'-',''),' ','')) LIKE ?" : '') . ')';
    $like = '%' . $busca . '%';
    array_push($params, $like, $like, $like, $like);
    if ($buscaPlaca !== '') $params[] = $buscaPlaca . '%';
}

// Venda mais recente não cancelada (e não locação) de cada veículo.
function rlConsultar(array $where, array $params): array {
    $sql = "
        SELECT o.id AS op_id, o.veiculo_marca, o.veiculo_modelo, o.veiculo_ano, o.veiculo_placa,
               o.valor_final, o.valor_quitacao, o.valor_fipe_referencia, o.data_compra, o.financiamento_quitado,
               v.id AS venda_id, v.etapa AS venda_etapa, v.comprador_nome, v.data_venda, v.preco_venda,
               v.valor_pago_contratacao, v.parcelamento_valor_parcela, v.parcelamento_qtd_parcelas,
               v.parcelamento_periodicidade,
               EXISTS (SELECT 1 FROM contratos ct WHERE ct.oportunidade_id = o.id AND ct.campos_json LIKE '%importado_zapsign%') AS migrado_zapsign,
               EXISTS (SELECT 1 FROM contratos cc WHERE cc.venda_id = v.id AND cc.tipo = 'venda' AND cc.status != 'cancelado'
                       AND cc.campos_json LIKE '%\"ia_tipo_detectado\":\"compra\"%') AS contrato_e_compra,
               EXISTS (SELECT 1 FROM contratos cv WHERE cv.venda_id = v.id AND cv.tipo = 'venda' AND cv.status = 'assinado'
                       AND cv.campos_json NOT LIKE '%\"ia_tipo_detectado\":\"compra\"%') AS tem_contrato_venda,
               (SELECT COALESCE(SUM(l.valor), 0) FROM fin_lancamentos l
                  WHERE l.venda_id = v.id AND l.tipo = 'receita' AND l.status = 'pago') AS recebido,
               (SELECT COALESCE(SUM(l.valor), 0) FROM fin_lancamentos l
                  WHERE l.oportunidade_id = o.id AND l.origem = 'comissao_compra' AND l.status != 'cancelado') AS comissao_compra,
               (SELECT COALESCE(SUM(l.valor), 0) FROM fin_lancamentos l
                  WHERE l.venda_id = v.id AND l.origem = 'comissao_venda' AND l.status != 'cancelado') AS comissao_venda
        FROM oportunidades o
        LEFT JOIN vendas v ON v.id = (
            SELECT v2.id FROM vendas v2
            WHERE v2.oportunidade_id = o.id AND v2.etapa NOT IN ('cancelada', 'sem_perfil')
                  AND COALESCE(v2.tipo_contrato, 'venda') != 'locacao'
            ORDER BY v2.id DESC LIMIT 1
        )
        WHERE " . implode(' AND ', $where) . "
        ORDER BY COALESCE(v.data_venda, o.data_compra) DESC, o.id DESC
    ";
    $st = getDB()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

/**
 * Calcula custo/venda/lucro de uma linha (fórmula intocada) + marcas de
 * pendência. 05/11/2026 (plano de UX): venda "incompleta" = sem entrada/
 * parcelas E sem contrato de venda, ou com contrato que é a compra do carro
 * — o lucro dela não é prejuízo, é dado faltando: não entra no total nem no
 * chip "Lucro negativo" (só apresentação; a conta em si é a mesma).
 */
function rlCalcularLinha(array &$l): array {
    $pago = (float)($l['valor_final'] ?? 0);
    $fipe = (float)($l['valor_fipe_referencia'] ?? 0);
    $quit = $l['valor_quitacao'] !== null ? (float)$l['valor_quitacao'] : null;
    $l['_quit_prevista'] = $fipe > 0 ? round($fipe * RL_QUITACAO_PCT_FIPE / 100, 2) : null;
    $l['_quit_estimada'] = false;
    if ($quit === null && $fipe > 0) {
        $quit = round($fipe * RL_QUITACAO_PCT_FIPE / 100, 2);
        $l['_quit_estimada'] = true;
    }
    $l['_quitacao'] = $quit;
    $entrada = (float)($l['valor_pago_contratacao'] ?? 0);
    $parcela = (float)($l['parcelamento_valor_parcela'] ?? 0);
    $qtd = (int)($l['parcelamento_qtd_parcelas'] ?? 0);
    $vendaTotal = null;
    if ($l['venda_id']) {
        $vendaTotal = ($parcela > 0 && $qtd > 0) ? $entrada + $parcela * $qtd : ((float)($l['preco_venda'] ?? 0) ?: ($entrada ?: null));
    }
    // 05/11/2026 — comissões (consultor na compra + vendedor na venda) entram no custo.
    $l['_comissao'] = (float)$l['comissao_compra'] + (float)$l['comissao_venda'];
    $l['_custo'] = $pago + ($quit ?? 0) + $l['_comissao'];
    $l['_venda_total'] = $vendaTotal;
    $l['_lucro'] = $vendaTotal !== null ? $vendaTotal - $l['_custo'] : null;
    $semEntrada = $l['venda_id'] && !($entrada > 0) && !($parcela > 0 && $qtd > 0);
    $l['_sem_contrato_venda'] = $l['venda_id'] && $l['venda_etapa'] === 'vendido' && !$l['tem_contrato_venda'] && !$l['contrato_e_compra'];
    $l['_incompleta'] = $l['venda_id'] && ($l['contrato_e_compra'] || ($semEntrada && !$l['tem_contrato_venda']));
    $l['_sem_entrada'] = $semEntrada;
    $completa = $l['_lucro'] !== null && !$l['_incompleta'];
    return [
        'sem_fipe' => !($fipe > 0),
        'sem_pago' => !($pago > 0),
        'sem_quitacao' => $l['valor_quitacao'] === null,
        'lucro_neg' => $completa && $l['_lucro'] < -0.005,
        'lucro_zero' => $completa && abs($l['_lucro']) < 0.005,
        'incompletos' => (bool)$l['_incompleta'],
        'zapsign' => (bool)$l['migrado_zapsign'],
        'sem_entrada' => (bool)$semEntrada,
        'contrato_compra' => (bool)$l['contrato_e_compra'],
        'sem_contrato_venda' => (bool)$l['_sem_contrato_venda'],
    ];
}

$linhas = rlConsultar($where, $params);

$tot = ['pago' => 0, 'quitacao' => 0, 'venda' => 0, 'venda_completa' => 0, 'entrada' => 0, 'recebido' => 0, 'lucro' => 0, 'n_lucro' => 0,
    'n_incompletas' => 0, 'sem_quitacao' => 0, 'sem_pago' => 0, 'fipe' => 0, 'quit_estimada' => 0, 'quit_prevista' => 0, 'comissao' => 0];
$contPend = [];
$totalSemPend = count($linhas);
foreach ($linhas as &$l) {
    $marcas = rlCalcularLinha($l);
    foreach ($marcas as $k => $sim) if ($sim) $contPend[$k] = ($contPend[$k] ?? 0) + 1;
    if ($pend !== '' && !$marcas[$pend]) { $l['_fora'] = true; continue; }
    $pago = (float)($l['valor_final'] ?? 0);
    $tot['quit_prevista'] += $l['_quit_prevista'] ?? 0;
    $tot['pago'] += $pago;
    if ($pago <= 0) $tot['sem_pago']++;
    $tot['quitacao'] += $l['_quitacao'] ?? 0;
    if ($l['_quitacao'] === null) $tot['sem_quitacao']++;
    if ($l['_quit_estimada']) $tot['quit_estimada']++;
    $tot['fipe'] += (float)($l['valor_fipe_referencia'] ?? 0);
    $tot['comissao'] += $l['_comissao'];
    $tot['entrada'] += (float)($l['valor_pago_contratacao'] ?? 0);
    $tot['recebido'] += (float)$l['recebido'];
    if ($l['_venda_total'] !== null) $tot['venda'] += $l['_venda_total'];
    if ($l['_incompleta']) {
        $tot['n_incompletas']++;
    } elseif ($l['_lucro'] !== null) {
        $tot['venda_completa'] += $l['_venda_total'];
        $tot['lucro'] += $l['_lucro'];
        $tot['n_lucro']++;
    }
}
unset($l);
$linhas = array_values(array_filter($linhas, fn($l) => empty($l['_fora'])));
if ($ordem !== 'data') {
    // Incompletas sempre no fim (o lucro delas não é comparável).
    usort($linhas, function ($a, $b) use ($ordem) {
        if ($ordem === 'custo_maior') return $b['_custo'] <=> $a['_custo'];
        if ($ordem === 'venda_maior') return ($b['_venda_total'] ?? -INF) <=> ($a['_venda_total'] ?? -INF);
        $va = ($a['_incompleta'] || $a['_lucro'] === null) ? null : $a['_lucro'];
        $vb = ($b['_incompleta'] || $b['_lucro'] === null) ? null : $b['_lucro'];
        if ($va === null || $vb === null) return ($va === null) <=> ($vb === null);
        return $ordem === 'lucro_maior' ? $vb <=> $va : $va <=> $vb;
    });
}

// Lote: lê até RL_LOTE_LEITURA contratos dos veículos da lista atual que
// estão sem FIPE ou sem valor pago (limite por clique pra não estourar o
// tempo da página — clicar de novo continua de onde parou).
$pendentesLeitura = array_values(array_filter($linhas, fn($l) => !((float)$l['valor_fipe_referencia'] > 0) || !((float)$l['valor_final'] > 0)));
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'ler_contratos_lote' && validateCSRF($_POST['csrf_token'] ?? '')) {
    @set_time_limit(300);
    $ignorar = array_map('intval', $_SESSION['rl_lote_ignorar'] ?? []);
    $feitos = 0; $preencheu = 0; $falhas = 0;
    foreach ($pendentesLeitura as $pl) {
        if ($feitos >= RL_LOTE_LEITURA) break;
        if (in_array((int)$pl['op_id'], $ignorar, true)) continue;
        $r = preencherValoresPeloContratoCompra((int)$pl['op_id']);
        $feitos++;
        $ignorar[] = (int)$pl['op_id']; // já lido — o próximo clique segue pros outros
        if ($r['ok'] && $r['preenchidos']) $preencheu++;
        else $falhas++;
    }
    $_SESSION['rl_lote_ignorar'] = $ignorar;
    $_SESSION['rl_msg'] = ['ok', "🤖 {$feitos} contrato(s) lido(s): {$preencheu} veículo(s) preenchido(s)" . ($falhas ? ", {$falhas} sem contrato ou sem valor novo" : '') . '.'];
    header('Location: /admin/relatorio_lucro_veiculos.php?' . http_build_query($_GET));
    exit;
}
// Lote das vendas sem entrada/parcelas (mesmo limite por clique).
$pendentesVenda = array_values(array_filter($linhas, fn($l) => $l['venda_id'] && !((float)$l['valor_pago_contratacao'] > 0)
    && !((float)$l['parcelamento_valor_parcela'] > 0 && (int)$l['parcelamento_qtd_parcelas'] > 0)));
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'ler_vendas_lote' && validateCSRF($_POST['csrf_token'] ?? '')) {
    @set_time_limit(300);
    $ignorarV = array_map('intval', $_SESSION['rl_lote_venda_ignorar'] ?? []);
    $feitos = 0; $cont = ['ok' => 0, 'nada' => 0, 'compra' => [], 'erro' => 0];
    foreach ($pendentesVenda as $pv) {
        if ($feitos >= RL_LOTE_LEITURA) break;
        if (in_array((int)$pv['venda_id'], $ignorarV, true)) continue;
        $r = rlLerContratoVenda((int)$pv['venda_id']);
        $feitos++;
        $ignorarV[] = (int)$pv['venda_id'];
        if ($r['tipo'] === 'compra') $cont['compra'][] = '#' . (int)$pv['venda_id'];
        else $cont[$r['tipo']]++;
    }
    $_SESSION['rl_lote_venda_ignorar'] = $ignorarV;
    $msg = "🤖 {$feitos} contrato(s) de venda lido(s): {$cont['ok']} preenchido(s)";
    if ($cont['nada']) $msg .= ", {$cont['nada']} sem valor novo";
    if ($cont['erro']) $msg .= ", {$cont['erro']} sem contrato/erro";
    if ($cont['compra']) $msg .= '. ⚠️ ' . count($cont['compra']) . ' na verdade são contrato de COMPRA (vendas ' . implode(', ', $cont['compra']) . ') — abra cada venda e use "Mover pra compra"';
    $_SESSION['rl_msg'] = [$cont['compra'] ? 'erro' : 'ok', $msg . '.'];
    header('Location: /admin/relatorio_lucro_veiculos.php?' . http_build_query($_GET));
    exit;
}
$ignoradosVenda = array_map('intval', $_SESSION['rl_lote_venda_ignorar'] ?? []);
$qtdLerVendas = count(array_filter($pendentesVenda, fn($l) => !in_array((int)$l['venda_id'], $ignoradosVenda, true)));
$ignoradosLote = array_map('intval', $_SESSION['rl_lote_ignorar'] ?? []);
$qtdLerLote = count(array_filter($pendentesLeitura, fn($l) => !in_array((int)$l['op_id'], $ignoradosLote, true)));

function rlMoeda(?float $v): string {
    return $v === null ? '—' : 'R$ ' . number_format($v, 2, ',', '.');
}
/** Célula de lucro: valor + margem, ou "dados incompletos" (nunca prejuízo falso). */
function rlLucroHtml(array $l): string {
    if ($l['_lucro'] === null) return '—';
    if ($l['_incompleta']) return '—<span class="rl-incompleto" title="Venda sem entrada/parcelas e sem contrato de venda, ou contrato que é a compra do carro">dados incompletos</span>';
    $m = (float)$l['_venda_total'] > 0 ? $l['_lucro'] / (float)$l['_venda_total'] * 100 : null;
    return '<span class="rl-lucro ' . ($l['_lucro'] < 0 ? 'neg' : 'pos') . '">' . rlMoeda($l['_lucro']) . '</span>'
        . ($m !== null ? '<span class="rl-margem-l">margem ' . number_format($m, 1, ',', '.') . '%</span>' : '');
}
/** Célula de quitação: real; senão a prevista em itálico com etiqueta. */
function rlQuitHtml(array $l): string {
    if ($l['valor_quitacao'] !== null) return rlMoeda((float)$l['valor_quitacao']);
    if ($l['_quit_prevista'] !== null) return '<span class="rl-prevista" title="Sem valor real: estimada em ' . RL_QUITACAO_PCT_FIPE . '% da FIPE">'
        . rlMoeda($l['_quit_prevista']) . '</span><span class="rl-sub-n">prevista (' . RL_QUITACAO_PCT_FIPE . '% FIPE)</span>';
    return '<span class="rl-vazio">digitar</span>';
}
/** "R$ 3.000 + 36× R$ 1.000" / "à vista". */
function rlCondicaoTxt(array $l): string {
    $ent = (float)($l['valor_pago_contratacao'] ?? 0);
    $qtd = (int)($l['parcelamento_qtd_parcelas'] ?? 0);
    $par = (float)($l['parcelamento_valor_parcela'] ?? 0);
    $f = fn(float $v) => 'R$ ' . number_format($v, 0, ',', '.');
    if ($qtd > 0 && $par > 0) return ($ent > 0 ? $f($ent) . ' + ' : '') . $qtd . '× ' . $f($par) . (($l['parcelamento_periodicidade'] ?? '') === 'semanal' ? ' sem.' : '');
    return $ent > 0 ? 'entrada ' . $f($ent) . ' · sem parcelas' : 'à vista / sem parcelas';
}

$mesesNome = ['01'=>'janeiro','02'=>'fevereiro','03'=>'março','04'=>'abril','05'=>'maio','06'=>'junho','07'=>'julho','08'=>'agosto','09'=>'setembro','10'=>'outubro','11'=>'novembro','12'=>'dezembro'];
$rotuloPeriodo = $mes !== '' ? ucfirst($mesesNome[substr($mes, 5, 2)] ?? '') . '/' . substr($mes, 0, 4) : 'Todo o período';

if (!empty($_GET['pdf'])) {
    $pdf = new FPDF('L', 'mm', 'A4');
    $pdf->SetMargins(10, 12, 10);
    $pdf->SetAutoPageBreak(true, 14);
    $pdf->AddPage();
    $pdf->SetFillColor(21, 23, 34);
    $pdf->Rect(0, 0, 297, 20, 'F');
    $logoPath = dirname(__DIR__) . '/public/assets/logo.png';
    if (is_file($logoPath)) { try { if (@getimagesize($logoPath)) $pdf->Image($logoPath, 10, 3, 0, 14); } catch (Throwable $e) {} }
    $pdf->SetY(4);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('Helvetica', 'B', 12);
    $pdf->Cell(0, 7, _pdfTexto('FASTCAR SOLUTIONS'), 0, 1, 'C');
    $pdf->SetFont('Helvetica', '', 8);
    $pdf->Cell(0, 6, _pdfTexto('RELATÓRIO DE LUCRO POR VEÍCULO'), 0, 1, 'C');
    $pdf->SetFillColor(47, 111, 237);
    $pdf->Rect(0, 20, 297, 1.2, 'F');
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetY(25);
    $rotFiltro = ['vendidos' => 'Vendidos', 'estoque' => 'Ainda sem venda', 'todos' => 'Todos'][$filtro];
    $pdf->SetFont('Helvetica', 'B', 12);
    $pdf->Cell(0, 7, _pdfTexto('Período: ' . $rotuloPeriodo . '   |   ' . $rotFiltro . ($pend !== '' ? '   |   ' . preg_replace('/^\S+\s+/u', '', RL_PENDENCIAS[$pend]) : '') . ($busca !== '' ? '   |   busca: ' . $busca : '')), 0, 1);
    $pdf->SetFont('Helvetica', '', 8);
    $pdf->SetTextColor(90, 90, 90);
    $pdf->Cell(0, 5, _pdfTexto(count($linhas) . ' veículo(s) — gerado em ' . date('d/m/Y H:i')), 0, 1);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Ln(1);

    // Resumo
    $resumo = [
        ['FIPE dos veículos', $tot['fipe']], ['Quitação prevista (' . RL_QUITACAO_PCT_FIPE . '% FIPE)', $tot['quit_prevista']], ['Pago aos vendedores', $tot['pago']], ['Quitações (real+prev.)', $tot['quitacao']], ['Comissões (compra+venda)', $tot['comissao']],
        ['Total das vendas', $tot['venda']], ['Já recebido', $tot['recebido']],
        ['Lucro previsto', $tot['lucro']],
    ];
    $w = 277 / count($resumo);
    $y = $pdf->GetY();
    foreach ($resumo as $i => [$rot, $val]) {
        $x = 10 + $i * $w;
        $pdf->SetFillColor(244, 244, 247);
        $pdf->Rect($x + 1, $y, $w - 2, 15, 'F');
        $pdf->SetXY($x + 1, $y + 1.5);
        $pdf->SetFont('Helvetica', '', 7.5);
        $pdf->SetTextColor(90, 90, 90);
        $pdf->Cell($w - 2, 4, _pdfTexto($rot), 0, 2, 'C');
        $pdf->SetFont('Helvetica', 'B', 11);
        if ($rot === 'Lucro previsto') $pdf->SetTextColor($val < 0 ? 220 : 21, $val < 0 ? 38 : 128, $val < 0 ? 38 : 61);
        else $pdf->SetTextColor(21, 23, 34);
        $pdf->Cell($w - 2, 7, _pdfTexto(rlMoeda((float)$val)), 0, 0, 'C');
    }
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetY($y + 18);
    if ($tot['quit_estimada']) {
        $pdf->SetFont('Helvetica', 'I', 7.5);
        $pdf->SetTextColor(90, 90, 90);
        $pdf->Cell(0, 4, _pdfTexto($tot['quit_estimada'] . ' veículo(s) ainda sem quitação real — o custo usa a quitação prevista (' . RL_QUITACAO_PCT_FIPE . '% da FIPE), marcada com *.'), 0, 1);
        $pdf->SetTextColor(0, 0, 0);
    }
    if ($tot['sem_quitacao']) {
        $pdf->SetFont('Helvetica', 'I', 7.5);
        $pdf->SetTextColor(150, 90, 0);
        $pdf->Cell(0, 4, _pdfTexto($tot['sem_quitacao'] . ' veículo(s) sem quitação e sem FIPE — entra como zero no custo, o lucro desses aparece maior.'), 0, 1);
        $pdf->SetTextColor(0, 0, 0);
    }
    $pdf->Ln(1);

    $cols = [
        ['Veículo / placa', 33, 'L'], ['Comprador', 22, 'L'], ['Data venda', 15, 'C'], ['FIPE', 19, 'R'], ['Quit. prevista', 19, 'R'], ['Valor pago', 19, 'R'],
        ['Quitação', 19, 'R'], ['Comissões', 17, 'R'], ['Custo total', 20, 'R'], ['Entrada', 18, 'R'], ['Parcelas', 20, 'C'],
        ['Total venda', 20, 'R'], ['Recebido', 16, 'R'], ['Lucro', 20, 'R'],
    ];
    $cab = function () use ($pdf, $cols) {
        $pdf->SetFont('Helvetica', 'B', 7.5);
        $pdf->SetFillColor(21, 23, 34);
        $pdf->SetTextColor(255, 255, 255);
        foreach ($cols as [$t, $cw, $al]) $pdf->Cell($cw, 6, _pdfTexto($t), 0, 0, 'C', true);
        $pdf->Ln();
        $pdf->SetTextColor(0, 0, 0);
    };
    $cab();
    $fill = false;
    foreach ($linhas as $l) {
        if ($pdf->GetY() > 190) { $pdf->AddPage(); $cab(); }
        $veic = trim($l['veiculo_marca'] . ' ' . $l['veiculo_modelo'] . ' ' . $l['veiculo_ano']) . ($l['veiculo_placa'] ? ' · ' . strtoupper($l['veiculo_placa']) : '');
        $parcTxt = ((int)$l['parcelamento_qtd_parcelas'] > 0 && (float)$l['parcelamento_valor_parcela'] > 0)
            ? (int)$l['parcelamento_qtd_parcelas'] . 'x ' . rlMoeda((float)$l['parcelamento_valor_parcela']) . (($l['parcelamento_periodicidade'] ?? '') === 'semanal' ? ' sem.' : '')
            : ($l['venda_id'] ? 'à vista' : '—');
        $vals = [
            $veic, $l['venda_id'] ? ($l['comprador_nome'] ?: '—') : 'sem venda',
            $l['data_venda'] ? date('d/m/Y', strtotime($l['data_venda'])) : '—',
            rlMoeda((float)$l['valor_fipe_referencia'] > 0 ? (float)$l['valor_fipe_referencia'] : null),
            rlMoeda($l['_quit_prevista']),
            rlMoeda($l['valor_final'] !== null ? (float)$l['valor_final'] : null),
            rlMoeda($l['_quitacao']) . ($l['_quit_estimada'] ? '*' : ''),
            $l['_comissao'] > 0 ? rlMoeda($l['_comissao']) : '—',
            rlMoeda($l['_custo']),
            $l['venda_id'] ? rlMoeda((float)($l['valor_pago_contratacao'] ?? 0)) : '—',
            $parcTxt, rlMoeda($l['_venda_total']),
            $l['venda_id'] ? rlMoeda((float)$l['recebido']) : '—',
            $l['_incompleta'] ? 'incompleto' : rlMoeda($l['_lucro']),
        ];
        $pdf->SetFillColor(246, 247, 250);
        $pdf->SetFont('Helvetica', '', 7.2);
        foreach ($cols as $i => [$t, $cw, $al]) {
            $txt = _pdfTexto((string)$vals[$i]);
            while ($pdf->GetStringWidth($txt) > $cw - 1.5 && strlen($txt) > 3) $txt = substr($txt, 0, -2);
            if ($i === 13 && $l['_lucro'] !== null && !$l['_incompleta']) {
                $pdf->SetFont('Helvetica', 'B', 7.2);
                $pdf->SetTextColor($l['_lucro'] < 0 ? 220 : 21, $l['_lucro'] < 0 ? 38 : 128, $l['_lucro'] < 0 ? 38 : 61);
            }
            $pdf->Cell($cw, 6, $txt, 0, 0, $al, $fill);
            $pdf->SetTextColor(0, 0, 0);
        }
        $pdf->Ln();
        $fill = !$fill;
    }
    // Total
    $pdf->SetFont('Helvetica', 'B', 7.5);
    $pdf->SetFillColor(225, 232, 252);
    $totVals = ['TOTAL', '', '', rlMoeda($tot['fipe']), rlMoeda($tot['quit_prevista']), rlMoeda($tot['pago']), rlMoeda($tot['quitacao']), rlMoeda($tot['comissao']), rlMoeda($tot['pago'] + $tot['quitacao'] + $tot['comissao']),
        rlMoeda($tot['entrada']), '', rlMoeda($tot['venda']), rlMoeda($tot['recebido']), rlMoeda($tot['lucro'])];
    foreach ($cols as $i => [$t, $cw, $al]) $pdf->Cell($cw, 7, _pdfTexto($totVals[$i]), 0, 0, $al, true);
    $pdf->Ln(9);
    $pdf->SetFont('Helvetica', '', 7);
    $pdf->SetTextColor(110, 110, 110);
    $pdf->MultiCell(0, 4, _pdfTexto('Custo = valor pago ao vendedor + quitação do financiamento (sem valor digitado, estimada em ' . RL_QUITACAO_PCT_FIPE . '% da FIPE, marcada com *) + comissões (consultor na compra + vendedor na venda, lançadas no financeiro). Total da venda = entrada + parcelas (quantidade × valor) ou o preço da venda quando não há parcelamento. Lucro previsto = total da venda − custo, contando parcelas ainda não pagas; venda sem entrada/parcelas e sem contrato de venda (ou com contrato que é a compra do carro) aparece como "incompleto" e não entra no total; "Recebido" é o que já está marcado como pago no financeiro. Locação fica de fora.'));
    ob_end_clean();
    $pdf->Output('I', 'lucro_por_veiculo_' . ($mes ?: 'todos') . '.pdf');
    exit;
}
ob_end_flush();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Lucro por veículo — Fastcar CRM</title>
<link rel="stylesheet" href="/admin/assets/style.css?v=<?= @filemtime(__DIR__ . '/assets/style.css') ?: 1 ?>">
<link rel="stylesheet" href="/admin/assets/mobile.css?v=<?= @filemtime(__DIR__ . '/assets/mobile.css') ?: 1 ?>">
<script src="/admin/assets/mobile.js?v=<?= @filemtime(__DIR__ . '/assets/mobile.js') ?: 1 ?>" defer></script>
<?php include __DIR__ . '/_pwa_head.php'; ?>
<style>
/* 05/11/2026 — plano de UX (CLAUDE_1.md): sem scroll horizontal na página,
   Lucro na 2ª coluna, edição inline, "dados incompletos" ≠ prejuízo. */
main { min-width: 0; max-width: 100%; }
@media (min-width: 1100px) { main { max-width: 1680px; } }
.rl-topo h2 { margin-bottom: 6px; }
.rl-topo details { font-size: 13px; color: var(--texto-suave); margin-bottom: 12px; }
.rl-topo summary { cursor: pointer; color: var(--azul); font-weight: 600; }

/* KPIs em 3 blocos */
.rl-kpis { display: grid; grid-template-columns: 1fr 1fr 1.15fr; gap: 14px; margin-bottom: 16px; }
.rl-bloco { background: var(--superficie); border: 1px solid var(--borda); border-radius: var(--raio); box-shadow: var(--sombra); padding: 14px 16px; }
.rl-bloco h3 { margin: 0 0 8px; font-size: 12px; text-transform: uppercase; letter-spacing: .06em; color: var(--texto-fraco); }
.rl-kl { display: flex; justify-content: space-between; gap: 10px; padding: 5px 0; border-bottom: 1px dashed var(--borda); font-size: 14px; }
.rl-kl:last-of-type { border-bottom: 0; }
.rl-kl b, .rl-kpi-num { font-variant-numeric: tabular-nums; white-space: nowrap; }
.rl-kl small a, .rl-kl-sub a { color: var(--laranja); font-weight: 600; text-decoration: none; }
.rl-kl-sub { display: block; font-size: 12px; margin-top: -2px; padding-bottom: 4px; }
.rl-kl-ref { color: var(--texto-fraco); }
.rl-kl-total { border-top: 2px solid var(--borda); border-bottom: 0; margin-top: 4px; padding-top: 8px; font-size: 16px; font-weight: 800; }
.rl-barra { height: 8px; background: var(--borda); border-radius: 99px; overflow: hidden; margin-top: 6px; }
.rl-barra span { display: block; height: 100%; background: var(--verde); }
.rl-hero { border-left: 5px solid var(--verde); }
.rl-hero.neg { border-left-color: var(--vermelho); }
.rl-hero .rl-kpi-num { font-size: 30px; font-weight: 800; color: var(--verde); display: block; line-height: 1.15; }
.rl-hero.neg .rl-kpi-num { color: var(--vermelho); }
.rl-hero .rl-margem { font-size: 14px; color: var(--texto-suave); }
.rl-hero .rl-incompl { display: inline-block; margin-top: 8px; font-size: 13px; font-weight: 600; color: var(--laranja); text-decoration: none; }

/* Filtros */
.rl-filtros { display: flex; gap: 10px; flex-wrap: wrap; align-items: flex-end; margin: 0; }
.rl-filtros > div { min-width: 140px; }
.rl-filtros .rl-busca { flex: 1; min-width: 220px; }
.rl-filtros button, .rl-filtros .btn { margin: 0; width: auto; white-space: nowrap; min-height: 44px; display: inline-flex; align-items: center; }
.rl-filtros > div.rl-acao { min-width: 0; }
.rl-filtros .rl-dir { margin-left: auto; }
a.btn.rl-contorno { background: transparent; color: var(--azul); border: 1px solid var(--azul); box-shadow: none; }

/* Chips em dois grupos */
.rl-chips { display: flex; gap: 8px 16px; flex-wrap: wrap; align-items: center; margin: 12px 0; }
.rl-chips .rl-grupo { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; }
.rl-chips .rl-grupo > span { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; color: var(--texto-fraco); }
.rl-chip { display: inline-flex; align-items: center; min-height: 34px; padding: 4px 12px; border-radius: 99px; border: 1px solid var(--borda);
    background: var(--superficie); color: var(--texto); font-size: 13px; text-decoration: none; white-space: nowrap; }
.rl-chip:hover { border-color: var(--azul); text-decoration: none; }
.rl-chip.ambar { border-color: var(--laranja); color: var(--laranja); background: var(--laranja-bg); }
.rl-chip[aria-pressed="true"] { background: var(--azul); border-color: var(--azul); color: #fff; }
.rl-chip-acao { border-style: dashed; }
.rl-chip-acao button { all: unset; cursor: pointer; }

/* Tabela: scroll só dentro do bloco; cabeçalho, total e 1ª coluna fixos */
.rl-wrap { max-width: 100%; overflow: auto; max-height: calc(100vh - 110px); border: 1px solid var(--borda); border-radius: var(--raio); background: var(--superficie); }
.rl-tabela { margin: 0; border-collapse: separate; border-spacing: 0; width: 100%; box-shadow: none; }
.rl-tabela th, .rl-tabela td { padding: 9px 10px; vertical-align: top; border-bottom: 1px solid var(--borda); }
.rl-tabela thead th { position: sticky; top: 0; z-index: 3; background: var(--fundo, #f6f7fa); font-size: 12px; line-height: 1.2; white-space: nowrap; }
.rl-tabela thead tr:nth-child(2) th { top: 31px; }
.rl-tabela thead th.rl-grupo { text-align: center; font-size: 11px; letter-spacing: .08em; color: var(--texto-fraco); border-bottom: 2px solid var(--borda); }
.rl-tabela thead th a { color: inherit; text-decoration: none; }
.rl-tabela thead th a:hover { color: var(--azul); }
.rl-tabela tfoot td { position: sticky; bottom: 0; z-index: 2; background: var(--azul-claro, #eef3fe); font-weight: 800; white-space: nowrap; border-top: 2px solid var(--borda); }
.rl-tabela .rl-col-veic { position: sticky; left: 0; z-index: 1; background: var(--superficie); min-width: 220px; max-width: 260px; box-shadow: 4px 0 6px -4px rgba(0,0,0,.15); }
.rl-tabela thead th.rl-col-veic { z-index: 4; background: var(--fundo, #f6f7fa); }
.rl-tabela tfoot td.rl-col-veic { z-index: 3; background: var(--azul-claro, #eef3fe); }
.rl-num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
.rl-tabela tr[id^="op"] { scroll-margin-top: 140px; }
.rl-nome { font-weight: 700; font-size: 13.5px; line-height: 1.3; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.rl-meta { display: block; font-size: 12px; color: var(--texto-suave); margin-top: 2px; }
.rl-comprador { display: block; font-size: 12px; color: var(--texto-suave); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 240px; }
.rl-selos { display: flex; flex-wrap: wrap; gap: 4px; margin-top: 5px; }
.rl-selo { display: inline-flex; align-items: center; gap: 4px; min-height: 26px; padding: 2px 9px; border-radius: 99px; font-size: 11.5px; font-weight: 600;
    background: var(--laranja-bg); color: var(--laranja); border: 1px solid var(--laranja); text-decoration: none; cursor: pointer; }
.rl-selo:hover { text-decoration: none; filter: brightness(.95); }
.rl-selo.neutro { background: var(--fundo, #f1f2f6); color: var(--texto-suave); border-color: var(--borda); cursor: default; }
.rl-selo.perigo { background: var(--vermelho-bg); color: var(--vermelho); border-color: var(--vermelho); }
form.rl-selo-form { display: inline; margin: 0; }
form.rl-selo-form button { margin: 0; font: inherit; }

.rl-lucro { font-size: 16px; font-weight: 800; }
.rl-lucro.pos { color: var(--verde); }
.rl-lucro.neg { color: var(--vermelho); }
.rl-margem-l { display: block; font-size: 11.5px; color: var(--texto-fraco); font-weight: 600; }
.rl-incompleto { display: inline-block; margin-top: 3px; padding: 1px 8px; border-radius: 99px; background: var(--fundo, #f1f2f6); color: var(--texto-fraco); font-size: 11px; font-weight: 700; }
.rl-sub-n { display: block; font-size: 11.5px; color: var(--texto-fraco); white-space: nowrap; }
.rl-prevista { color: var(--texto-fraco); font-style: italic; }
.rl-lnk { color: inherit; text-decoration: none; border-bottom: 1px dashed var(--borda); }
.rl-lnk:hover { color: var(--azul); border-bottom-color: var(--azul); text-decoration: none; }

/* Edição inline: sem JS mostra o form; com JS, valor clicável */
.rl-edit-form { display: flex; gap: 6px; align-items: center; margin: 0; justify-content: flex-end; }
.rl-edit-form input { width: 110px; max-width: 110px; margin: 0; padding: 7px 9px; text-align: right; }
.rl-edit-form button { margin: 0; width: 40px; min-width: 40px; height: 40px; padding: 0; }
.rl-edit-ver { display: none; }
.rl-js .rl-edit-ver { display: inline-flex; align-items: center; gap: 4px; background: none; border: 1px solid transparent; border-radius: 6px; padding: 3px 6px;
    margin: 0; color: inherit; font: inherit; font-variant-numeric: tabular-nums; cursor: pointer; box-shadow: none; min-height: 32px; }
.rl-js .rl-edit-ver:hover, .rl-js .rl-edit-ver:focus-visible { border-color: var(--azul); outline: none; }
.rl-js .rl-edit-ver .lapis { opacity: 0; font-size: 12px; }
.rl-js .rl-edit-ver:hover .lapis, .rl-js .rl-edit-ver:focus-visible .lapis { opacity: .7; }
.rl-js .rl-edit-form { display: none; }
.rl-js .rl-edit.editando .rl-edit-form { display: flex; }
.rl-js .rl-edit.editando .rl-edit-ver { display: none; }
.rl-edit-form input.erro { border-color: var(--vermelho); }
.rl-edit-msg { display: block; font-size: 11px; margin-top: 2px; }
.rl-edit-msg.ok { color: var(--verde); }
.rl-edit-msg.erro { color: var(--vermelho); }
.rl-vazio { color: var(--laranja); font-weight: 600; }
.rl-mob { display: none; }

@media (max-width: 1100px) { .rl-kpis { grid-template-columns: 1fr 1fr; } .rl-hero { grid-column: 1 / -1; order: -1; } }
@media (max-width: 700px) {
    .rl-kpis { grid-template-columns: 1fr; }
    .rl-wrap { max-height: none; overflow: visible; border: 0; background: transparent; }
    .rl-tabela .rl-col-veic { position: static; box-shadow: none; max-width: none; min-width: 0; }
    .rl-tabela tfoot tr { display: block; background: var(--azul-claro, #eef3fe); border-radius: 12px; padding: 6px 14px; }
    .rl-tabela tfoot td { position: static; background: transparent; }
    .rl-tabela tfoot td:empty { display: none; }
    .rl-tabela td.rl-lucro-td .rl-lucro { font-size: 20px; }
    .rl-js .rl-tabela tr:not(.rl-aberto) td.rl-det { display: none; }
    .rl-mob { display: flex; }
    .rl-edit-form input { font-size: 16px; max-width: 140px; width: 140px; }
    .rl-js .rl-edit-ver { min-height: 44px; }
    .rl-chips { flex-wrap: nowrap; overflow-x: auto; scroll-snap-type: x proximity; padding-bottom: 4px; }
    .rl-chips .rl-grupo { flex-wrap: nowrap; }
    .rl-chip { scroll-snap-align: start; min-height: 40px; }
    .rl-comprador { max-width: none; }
}
</style>
</head>
<body>
<?php include __DIR__ . '/_impersonando_banner.php'; ?>
<header class="topbar">
    <a href="/admin/veiculos.php" style="color:#fff">← Veículos</a>
    <a class="topbar-brand" href="<?= e(paginaInicialPorPerfil($_SESSION['admin_perfil'] ?? '')) ?>"><img class="topbar-logo" src="/admin/assets/img/icon-192.png" alt="Fastcar" onerror="this.style.display='none'"><span class="topbar-wordmark">Fast<b>Car</b></span></a>
    <span>Olá, <?= e($_SESSION['admin_nome']) ?></span>
    <a href="/admin/logout.php">Sair</a>
</header>
<main>
<?php
$baseQ = ['filtro' => $filtro, 'q' => $busca, 'mes' => $mes, 'ordem' => $ordem];
$lnk = fn(array $extra) => '?' . e(http_build_query(array_merge($baseQ, $extra)));
$custoTotal = $tot['pago'] + $tot['quitacao'] + $tot['comissao'];
$margemTot = $tot['venda_completa'] > 0 ? $tot['lucro'] / $tot['venda_completa'] * 100 : null;
$pctRecebido = $tot['venda'] > 0 ? min(100, $tot['recebido'] / $tot['venda'] * 100) : 0;
?>
<div class="rl-topo">
    <h2>📊 Lucro por veículo — <?= e($rotuloPeriodo) ?></h2>
    <details><summary>Como o lucro é calculado</summary>
        <p>Custo = valor pago ao vendedor + quitação do financiamento (sem valor digitado, a quitação é estimada em <?= RL_QUITACAO_PCT_FIPE ?>% da FIPE) + comissões (compra + venda).
        Venda = entrada + parcela × quantidade (ou o preço da venda, se não tiver parcelamento). Lucro previsto conta também as parcelas que ainda não foram pagas.
        Venda sem entrada/parcelas e sem contrato de venda — ou com contrato que é a compra do carro — aparece como <b>dados incompletos</b> e fica fora do total. Locação fica de fora.</p>
    </details>
</div>
<?php if ($erro): ?><div class="alerta-erro"><?= e($erro) ?></div><?php endif; ?>
<?php if ($sucesso): ?><div class="alerta-sucesso"><?= e($sucesso) ?></div><?php endif; ?>

<div class="rl-kpis">
    <section class="rl-bloco">
        <h3>Custo</h3>
        <div class="rl-kl rl-kl-ref"><span>FIPE dos veículos <small>(referência)</small></span><b data-tot="fipe"><?= rlMoeda($tot['fipe']) ?></b></div>
        <div class="rl-kl"><span>Pago aos vendedores</span><b data-tot="pago"><?= rlMoeda($tot['pago']) ?></b></div>
        <?php if ($tot['sem_pago']): ?><span class="rl-kl-sub"><a href="<?= $lnk(['pend' => 'sem_pago']) ?>"><?= $tot['sem_pago'] ?> sem valor pago →</a></span><?php endif; ?>
        <div class="rl-kl"><span>Quitações <small>(real + prevista)</small></span><b data-tot="quit"><?= rlMoeda($tot['quitacao']) ?></b></div>
        <span class="rl-kl-sub" data-tot-quitest-wrap <?= $tot['quit_estimada'] ? '' : 'hidden' ?>><a href="<?= $lnk(['pend' => 'sem_quitacao']) ?>"><span data-tot="quitest"><?= $tot['quit_estimada'] ?></span> ainda sem valor real →</a></span>
        <div class="rl-kl"><span><a class="rl-lnk" href="/admin/financeiro-lancamentos.php?origem=comissao_compra&amp;todos_periodos=1">Comissões</a></span><b data-tot="com"><?= rlMoeda($tot['comissao']) ?></b></div>
        <div class="rl-kl rl-kl-total"><span>Custo total</span><b data-tot="custo"><?= rlMoeda($custoTotal) ?></b></div>
    </section>
    <section class="rl-bloco">
        <h3>Venda</h3>
        <div class="rl-kl"><span>Total das vendas</span><b data-tot="venda"><?= rlMoeda($tot['venda']) ?></b></div>
        <div class="rl-kl"><span><a class="rl-lnk" href="/admin/promissorias.php">Já recebido</a></span><b data-tot="receb"><?= rlMoeda($tot['recebido']) ?></b></div>
        <div class="rl-barra" title="<?= number_format($pctRecebido, 0) ?>% do total das vendas já recebido"><span style="width:<?= number_format($pctRecebido, 1, '.', '') ?>%"></span></div>
        <small class="rl-kl-ref"><?= number_format($pctRecebido, 0) ?>% recebido</small>
    </section>
    <section class="rl-bloco rl-hero <?= $tot['lucro'] < 0 ? 'neg' : '' ?>" data-tot-hero>
        <h3>Resultado</h3>
        <span class="rl-kpi-num" data-tot="lucro"><?= rlMoeda($tot['lucro']) ?></span>
        <span class="rl-margem">Lucro previsto (<span data-tot="nlucro"><?= $tot['n_lucro'] ?></span> completa<?= $tot['n_lucro'] === 1 ? '' : 's' ?>)<span data-tot="margem"><?= $margemTot !== null ? ' · margem ' . number_format($margemTot, 1, ',', '.') . '%' : '' ?></span></span>
        <?php if ($tot['n_incompletas']): ?><br><a class="rl-incompl" href="<?= $lnk(['pend' => 'incompletos']) ?>">🧩 <span data-tot="nincompl"><?= $tot['n_incompletas'] ?></span> venda<?= $tot['n_incompletas'] === 1 ? '' : 's' ?> com dados incompletos → corrigir</a><?php endif; ?>
    </section>
</div>

<div class="card">
    <form method="get" class="rl-filtros">
        <div class="rl-busca"><label>Buscar</label><input type="text" name="q" value="<?= e($busca) ?>" placeholder="Placa, marca, modelo ou comprador"></div>
        <div><label>Mostrar</label>
            <select name="filtro">
                <option value="vendidos" <?= $filtro === 'vendidos' ? 'selected' : '' ?>>Vendidos</option>
                <option value="estoque" <?= $filtro === 'estoque' ? 'selected' : '' ?>>Ainda sem venda</option>
                <option value="todos" <?= $filtro === 'todos' ? 'selected' : '' ?>>Todos</option>
            </select>
        </div>
        <div><label>Mês</label><input type="month" name="mes" value="<?= e($mes) ?>"></div>
        <div><label>Ordenar</label>
            <select name="ordem">
                <option value="data" <?= $ordem === 'data' ? 'selected' : '' ?>>Mais recentes</option>
                <option value="lucro_maior" <?= $ordem === 'lucro_maior' ? 'selected' : '' ?>>Maior lucro</option>
                <option value="lucro_menor" <?= $ordem === 'lucro_menor' ? 'selected' : '' ?>>Menor lucro</option>
                <option value="custo_maior" <?= $ordem === 'custo_maior' ? 'selected' : '' ?>>Maior custo</option>
                <option value="venda_maior" <?= $ordem === 'venda_maior' ? 'selected' : '' ?>>Maior venda</option>
            </select>
        </div>
        <?php if ($pend !== ''): ?><input type="hidden" name="pend" value="<?= e($pend) ?>"><?php endif; ?>
        <div class="rl-acao"><button type="submit" class="btn-primary">🔎 Filtrar</button></div>
        <?php if ($mes !== ''): ?><div class="rl-acao"><a class="btn rl-contorno" href="?<?= e(http_build_query(['filtro' => $filtro, 'q' => $busca, 'pend' => $pend, 'ordem' => $ordem])) ?>">Limpar mês</a></div><?php endif; ?>
        <div class="rl-acao rl-dir"><a class="btn rl-contorno" target="_blank" href="?<?= e(http_build_query(['filtro' => $filtro, 'q' => $busca, 'mes' => $mes, 'pend' => $pend, 'ordem' => $ordem, 'pdf' => 1])) ?>">📄 Gerar PDF</a></div>
    </form>
</div>

<?php
$chipsVer = ['lucro_neg', 'lucro_zero', 'incompletos'];
$chipsCorrigir = ['sem_fipe', 'sem_pago', 'sem_quitacao', 'sem_entrada', 'sem_contrato_venda', 'contrato_compra', 'zapsign'];
$chip = function (string $k, bool $ambar) use ($pend, $baseQ, $contPend): string {
    $n = (int)($contPend[$k] ?? 0);
    if ($n === 0 && $pend !== $k) return '';
    $ativo = $pend === $k;
    return '<a class="rl-chip' . ($ambar && !$ativo ? ' ambar' : '') . '" aria-pressed="' . ($ativo ? 'true' : 'false') . '" href="?'
        . e(http_build_query($baseQ + ['pend' => $k])) . '">' . e(RL_PENDENCIAS[$k]) . ' (' . $n . ')</a>';
};
?>
<nav class="rl-chips" aria-label="Filtros rápidos">
    <div class="rl-grupo"><span>Ver</span>
        <a class="rl-chip" aria-pressed="<?= $pend === '' ? 'true' : 'false' ?>" href="?<?= e(http_build_query($baseQ)) ?>">Todos (<?= $totalSemPend ?>)</a>
        <?php foreach ($chipsVer as $k) echo $chip($k, false); ?>
    </div>
    <div class="rl-grupo"><span>Corrigir dados</span>
        <?php foreach ($chipsCorrigir as $k) echo $chip($k, true); ?>
        <?php if ($qtdLerLote): ?><span class="rl-chip ambar rl-chip-acao"><button type="submit" form="rl-form-lote" title="Lê o contrato assinado e preenche FIPE/valor pago vazios">🤖 Ler FIPE/pago (<?= min($qtdLerLote, RL_LOTE_LEITURA) ?> de <?= $qtdLerLote ?>)</button></span><?php endif; ?>
        <?php if ($qtdLerVendas): ?><span class="rl-chip ambar rl-chip-acao"><button type="submit" form="rl-form-lote-venda" title="Lê o contrato de venda e preenche entrada/parcelas/preço vazios">💳 Ler entrada/parcelas (<?= min($qtdLerVendas, RL_LOTE_LEITURA) ?> de <?= $qtdLerVendas ?>)</button></span><?php endif; ?>
    </div>
</nav>
<form id="rl-form-lote" method="post" style="display:none"><?= csrfField() ?><input type="hidden" name="acao" value="ler_contratos_lote"></form>
<form id="rl-form-lote-venda" method="post" style="display:none"><?= csrfField() ?><input type="hidden" name="acao" value="ler_vendas_lote"></form>

<?php
$ordLnk = function (string $rotulo, string $asc, ?string $desc = null) use ($ordem, $baseQ, $pend): string {
    $ativo = $ordem === $asc || ($desc !== null && $ordem === $desc);
    $prox = ($desc !== null && $ordem === $asc) ? $desc : $asc;
    $q = $baseQ; $q['ordem'] = $prox; if ($pend !== '') $q['pend'] = $pend;
    return '<a href="?' . e(http_build_query($q)) . '">' . $rotulo . ($ativo ? ($ordem === 'lucro_menor' ? ' ▲' : ' ▼') : '') . '</a>';
};
$ariaSort = fn(array $vals) => in_array($ordem, $vals, true) ? ($ordem === 'lucro_menor' ? 'ascending' : 'descending') : 'none';
?>
<div class="rl-wrap">
<table class="tabela-oportunidades rl-tabela">
    <thead>
    <tr>
        <th rowspan="2" class="rl-col-veic" aria-sort="<?= $ariaSort(['data']) ?>"><?= $ordLnk('Veículo', 'data') ?></th>
        <th rowspan="2" class="rl-num" aria-sort="<?= $ariaSort(['lucro_maior', 'lucro_menor']) ?>"><?= $ordLnk('Lucro', 'lucro_maior', 'lucro_menor') ?></th>
        <th colspan="5" class="rl-grupo">CUSTO</th>
        <th colspan="2" class="rl-grupo">VENDA</th>
    </tr>
    <tr>
        <th class="rl-num">FIPE</th><th class="rl-num">Pago</th><th class="rl-num">Quitação</th><th class="rl-num">Comissões</th>
        <th class="rl-num" aria-sort="<?= $ariaSort(['custo_maior']) ?>"><?= $ordLnk('Custo total', 'custo_maior') ?></th>
        <th class="rl-num" aria-sort="<?= $ariaSort(['venda_maior']) ?>"><?= $ordLnk('Total venda', 'venda_maior') ?></th>
        <th class="rl-num">Recebido</th>
    </tr>
    </thead>
    <tbody>
    <?php if (!$linhas): ?><tr><td colspan="9">Nenhum veículo encontrado.</td></tr><?php endif; ?>
    <?php foreach ($linhas as $l):
        $opId = (int)$l['op_id'];
        $nome = trim($l['veiculo_marca'] . ' ' . $l['veiculo_modelo'] . ' ' . $l['veiculo_ano']) ?: 'Veículo #' . $opId;
        $dados = rlDadosLinha($l);
        $semFipe = !((float)$l['valor_fipe_referencia'] > 0);
        $semPago = !((float)$l['valor_final'] > 0);
    ?>
        <tr id="op<?= $opId ?>"<?php foreach ($dados as $k => $vv): ?> data-<?= $k ?>="<?= $vv === null ? '' : e((string)$vv) ?>"<?php endforeach; ?>>
            <td class="rl-col-veic" data-label="Veículo">
                <a class="rl-nome" href="/admin/oportunidade.php?id=<?= $opId ?>" title="<?= e($nome) ?>"><?= e($nome) ?></a>
                <span class="rl-meta"><?= $l['veiculo_placa'] ? e(strtoupper($l['veiculo_placa'])) . ' · ' : '' ?><?php if ($l['venda_id']): ?><a href="/admin/venda.php?id=<?= (int)$l['venda_id'] ?>">venda #<?= (int)$l['venda_id'] ?></a><?= $l['data_venda'] ? ' · ' . date('d/m/Y', strtotime($l['data_venda'])) : '' ?><?= $l['venda_etapa'] !== 'vendido' ? ' · em negociação' : '' ?><?php else: ?>sem venda<?php endif; ?></span>
                <?php if ($l['venda_id']): ?><span class="rl-comprador" title="<?= e($l['comprador_nome'] ?: '—') ?>"><?= e($l['comprador_nome'] ?: '—') ?></span><?php endif; ?>
                <div class="rl-selos">
                    <?php if ($l['contrato_e_compra']): ?>
                        <form method="post" class="rl-selo-form" onsubmit="return confirmarAcao(this, 'Mover este contrato pra compra do veículo? A venda continua aberta pra você subir o contrato de venda verdadeiro.')"><?= csrfField() ?>
                            <input type="hidden" name="acao" value="mover_contrato_compra_rl"><input type="hidden" name="venda_id" value="<?= (int)$l['venda_id'] ?>"><input type="hidden" name="oportunidade_id" value="<?= $opId ?>">
                            <button type="submit" class="rl-selo perigo" title="O contrato pendurado nesta venda é a compra do carro">⚠️ Contrato é de compra · mover</button></form>
                    <?php endif; ?>
                    <?php if ($l['_sem_contrato_venda']): ?>
                        <a class="rl-selo" href="/admin/venda.php?id=<?= (int)$l['venda_id'] ?>#subir-contrato-venda" title="Subir o contrato de venda e dizer pra quem foi">📄 Falta contrato de venda</a>
                    <?php endif; ?>
                    <?php if ($l['_sem_entrada'] && !$l['contrato_e_compra']): ?>
                        <form method="post" class="rl-selo-form"><?= csrfField() ?><input type="hidden" name="acao" value="ler_contrato_venda"><input type="hidden" name="venda_id" value="<?= (int)$l['venda_id'] ?>"><input type="hidden" name="oportunidade_id" value="<?= $opId ?>">
                            <button type="submit" class="rl-selo" title="A IA lê o contrato da venda e preenche entrada, parcelas e preço" onclick="this.textContent='🤖 lendo...'">🤖 Ler entrada/parcelas</button></form>
                    <?php endif; ?>
                    <?php if ($semFipe || $semPago): ?>
                        <form method="post" class="rl-selo-form"><?= csrfField() ?><input type="hidden" name="acao" value="ler_contrato"><input type="hidden" name="oportunidade_id" value="<?= $opId ?>">
                            <button type="submit" class="rl-selo" title="A IA lê o contrato assinado e preenche FIPE e valor pago" onclick="this.textContent='🤖 lendo...'">🤖 Ler <?= $semFipe && $semPago ? 'FIPE/pago' : ($semFipe ? 'FIPE' : 'valor pago') ?></button></form>
                    <?php endif; ?>
                    <?php if ($l['migrado_zapsign']): ?><span class="rl-selo neutro">📥 Migrado da ZapSign</span><?php endif; ?>
                </div>
            </td>
            <td class="rl-num rl-lucro-td" data-label="Lucro" data-c="lucro"><?= rlLucroHtml($l) ?></td>
            <td class="rl-num rl-det" data-label="FIPE">
                <div class="rl-edit" data-campo="fipe">
                    <button type="button" class="rl-edit-ver" aria-label="Editar FIPE"><span data-c="fipe"><?= $semFipe ? '<span class="rl-vazio">digitar</span>' : rlMoeda((float)$l['valor_fipe_referencia']) ?></span><span class="lapis" aria-hidden="true">✏️</span></button>
                    <form method="post" class="rl-edit-form"><?= csrfField() ?>
                        <input type="hidden" name="acao" value="salvar_fipe"><input type="hidden" name="oportunidade_id" value="<?= $opId ?>">
                        <input type="text" name="valor_fipe" inputmode="decimal" placeholder="0,00" aria-label="Valor FIPE" value="<?= $semFipe ? '' : e(number_format((float)$l['valor_fipe_referencia'], 2, ',', '.')) ?>">
                        <button type="submit" aria-label="Salvar FIPE" title="Salvar FIPE">💾</button>
                    </form>
                </div>
            </td>
            <td class="rl-num rl-det" data-label="Pago"><a class="rl-lnk" href="/admin/oportunidade.php?id=<?= $opId ?>" title="Abrir o veículo"><?= $semPago ? '<span class="rl-vazio">sem valor</span>' : rlMoeda((float)$l['valor_final']) ?></a></td>
            <td class="rl-num rl-det" data-label="Quitação">
                <div class="rl-edit" data-campo="quitacao">
                    <button type="button" class="rl-edit-ver" aria-label="Editar quitação real"><span data-c="quit"><?= rlQuitHtml($l) ?></span><span class="lapis" aria-hidden="true">✏️</span></button>
                    <form method="post" class="rl-edit-form"><?= csrfField() ?>
                        <input type="hidden" name="acao" value="salvar_quitacao"><input type="hidden" name="oportunidade_id" value="<?= $opId ?>">
                        <input type="text" name="valor_quitacao" inputmode="decimal" placeholder="<?= $l['_quit_prevista'] !== null ? e(number_format((float)$l['_quit_prevista'], 2, ',', '.')) : '0,00' ?>" aria-label="Valor da quitação real" value="<?= $l['valor_quitacao'] !== null ? e(number_format((float)$l['valor_quitacao'], 2, ',', '.')) : '' ?>">
                        <button type="submit" aria-label="Salvar quitação" title="Salvar quitação">💾</button>
                    </form>
                </div>
                <?php if ($l['financiamento_quitado']): ?><span class="rl-sub-n">✅ quitado</span><?php endif; ?>
            </td>
            <td class="rl-num rl-det" data-label="Comissões"><?php if ($l['_comissao'] > 0): ?><?= rlMoeda($l['_comissao']) ?><span class="rl-sub-n"><?= (float)$l['comissao_compra'] > 0 ? 'C ' . rlMoeda((float)$l['comissao_compra']) : '' ?><?= (float)$l['comissao_compra'] > 0 && (float)$l['comissao_venda'] > 0 ? ' · ' : '' ?><?= (float)$l['comissao_venda'] > 0 ? 'V ' . rlMoeda((float)$l['comissao_venda']) : '' ?></span><?php else: ?>—<?php endif; ?></td>
            <td class="rl-num" data-label="Custo total"><b data-c="custo"><?= rlMoeda($l['_custo']) ?></b></td>
            <td class="rl-num" data-label="Total venda"><?php if ($l['venda_id']): ?><a class="rl-lnk" href="/admin/venda.php?id=<?= (int)$l['venda_id'] ?>"><?= rlMoeda($l['_venda_total']) ?></a><span class="rl-sub-n"><?= e(rlCondicaoTxt($l)) ?></span><?php else: ?>—<?php endif; ?></td>
            <td class="rl-num" data-label="Recebido"><?php if ($l['venda_id']): ?><a class="rl-lnk" href="/admin/venda.php?id=<?= (int)$l['venda_id'] ?>" title="Ver parcelas da venda"><?= rlMoeda((float)$l['recebido']) ?></a><?php else: ?>—<?php endif; ?></td>
            <td class="rl-mob" data-label=""><button type="button" class="btn-texto rl-det-toggle" aria-expanded="false">Detalhes ▾</button></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
    <?php if ($linhas): ?>
    <tfoot><tr>
        <td class="rl-col-veic" data-label="">Total (<?= count($linhas) ?>)</td>
        <td class="rl-num" data-label="Lucro (completas)"><span class="rl-lucro <?= $tot['lucro'] < 0 ? 'neg' : 'pos' ?>" data-tot="lucro"><?= rlMoeda($tot['lucro']) ?></span></td>
        <td class="rl-num" data-label="FIPE" data-tot="fipe"><?= rlMoeda($tot['fipe']) ?></td>
        <td class="rl-num" data-label="Pago" data-tot="pago"><?= rlMoeda($tot['pago']) ?></td>
        <td class="rl-num" data-label="Quitação" data-tot="quit"><?= rlMoeda($tot['quitacao']) ?></td>
        <td class="rl-num" data-label="Comissões" data-tot="com"><?= rlMoeda($tot['comissao']) ?></td>
        <td class="rl-num" data-label="Custo total" data-tot="custo"><?= rlMoeda($custoTotal) ?></td>
        <td class="rl-num" data-label="Total venda" data-tot="venda"><?= rlMoeda($tot['venda']) ?></td>
        <td class="rl-num" data-label="Recebido" data-tot="receb"><?= rlMoeda($tot['recebido']) ?></td>
        <td class="rl-mob" data-label=""></td>
    </tr></tfoot>
    <?php endif; ?>
</table>
</div>
</main>
<script>
// 05/11/2026 — edição inline (FIPE/quitação): clica no valor, Enter/blur
// salva via fetch, Esc cancela; atualiza linha + totais sem recarregar.
(function () {
    document.body.classList.add('rl-js');
    var brl = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' });
    function moeda(v) { return (v === null || v === '' || isNaN(v)) ? '—' : brl.format(Number(v)).replace(/ /g, ' '); }
    function num(v) { return v === '' || v === undefined ? null : Number(v); }
    function esc(t) { var d = document.createElement('div'); d.textContent = t; return d.innerHTML; }

    function lucroHtml(d) {
        if (d.venda === null || d.venda === '') return '—';
        if (Number(d.incompleta) === 1) return '—<span class="rl-incompleto" title="Venda sem entrada/parcelas e sem contrato de venda, ou contrato que é a compra do carro">dados incompletos</span>';
        var l = Number(d.lucro), m = Number(d.venda) > 0 ? l / Number(d.venda) * 100 : null;
        return '<span class="rl-lucro ' + (l < 0 ? 'neg' : 'pos') + '">' + moeda(l) + '</span>' + (m !== null ? '<span class="rl-margem-l">margem ' + m.toFixed(1).replace('.', ',') + '%</span>' : '');
    }
    function quitHtml(d) {
        if (d.quitreal !== null && d.quitreal !== '') return moeda(d.quitreal);
        if (d.quitprev !== null && d.quitprev !== '') return '<span class="rl-prevista" title="Sem valor real: estimada em <?= RL_QUITACAO_PCT_FIPE ?>% da FIPE">' + moeda(d.quitprev) + '</span><span class="rl-sub-n">prevista (<?= RL_QUITACAO_PCT_FIPE ?>% FIPE)</span>';
        return '<span class="rl-vazio">digitar</span>';
    }
    function recalcularTotais() {
        var t = { fipe: 0, pago: 0, quit: 0, com: 0, custo: 0, venda: 0, receb: 0, lucro: 0, vendaC: 0, nlucro: 0, nincompl: 0, quitest: 0 };
        document.querySelectorAll('.rl-tabela tbody tr[id^="op"]').forEach(function (tr) {
            var d = tr.dataset;
            ['fipe', 'pago', 'quit', 'com', 'custo', 'receb'].forEach(function (k) { t[k] += Number(d[k] || 0); });
            if (Number(d.quitest) === 1) t.quitest++;
            if (d.venda !== '') {
                t.venda += Number(d.venda);
                if (Number(d.incompleta) === 1) t.nincompl++;
                else if (d.lucro !== '') { t.lucro += Number(d.lucro); t.vendaC += Number(d.venda); t.nlucro++; }
            }
        });
        document.querySelectorAll('[data-tot]').forEach(function (el) {
            var k = el.dataset.tot;
            if (k === 'nlucro' || k === 'nincompl' || k === 'quitest') el.textContent = t[k];
            else if (k === 'margem') el.textContent = t.vendaC > 0 ? ' · margem ' + (t.lucro / t.vendaC * 100).toFixed(1).replace('.', ',') + '%' : '';
            else if (k in t) el.textContent = moeda(t[k]);
            if (k === 'lucro' && el.classList.contains('rl-lucro')) { el.classList.toggle('neg', t.lucro < 0); el.classList.toggle('pos', t.lucro >= 0); }
        });
        var w = document.querySelector('[data-tot-quitest-wrap]'); if (w) w.hidden = t.quitest === 0;
        var h = document.querySelector('[data-tot-hero]'); if (h) h.classList.toggle('neg', t.lucro < 0);
    }
    function aplicarLinha(tr, d) {
        Object.keys(d).forEach(function (k) { tr.dataset[k] = d[k] === null ? '' : d[k]; });
        tr.querySelector('[data-c="lucro"]').innerHTML = lucroHtml(d);
        tr.querySelector('[data-c="custo"]').textContent = moeda(d.custo);
        tr.querySelector('[data-c="fipe"]').innerHTML = d.fipe > 0 ? esc(moeda(d.fipe)) : '<span class="rl-vazio">digitar</span>';
        tr.querySelector('[data-c="quit"]').innerHTML = quitHtml(d);
        recalcularTotais();
    }
    document.querySelectorAll('.rl-edit').forEach(function (box) {
        var form = box.querySelector('form'), input = form.querySelector('input[type=text]'), ver = box.querySelector('.rl-edit-ver');
        var original = input.value, salvando = false;
        function msg(texto, tipo) {
            var m = box.querySelector('.rl-edit-msg'); if (!m) { m = document.createElement('span'); m.className = 'rl-edit-msg'; box.appendChild(m); }
            m.textContent = texto; m.className = 'rl-edit-msg ' + tipo;
            if (tipo === 'ok') setTimeout(function () { m.textContent = ''; }, 1500);
        }
        function abrir() { box.classList.add('editando'); original = input.value; input.focus(); input.select(); }
        function fechar() { box.classList.remove('editando'); input.classList.remove('erro'); }
        function salvar() {
            if (salvando) return;
            if (input.value === original) { fechar(); return; }
            salvando = true;
            var fd = new FormData(form); fd.append('ajax', '1');
            fetch(location.pathname + location.search, { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function (r) { return r.json().catch(function () { return { ok: false, erro: 'Resposta inválida do servidor.' }; }); })
                .then(function (j) {
                    salvando = false;
                    if (!j.ok) { input.classList.add('erro'); msg(j.erro || 'Não foi possível salvar.', 'erro'); return; }
                    original = input.value; fechar(); aplicarLinha(box.closest('tr'), j.linha); msg('✓ salvo', 'ok'); ver.focus();
                })
                .catch(function () { salvando = false; input.classList.add('erro'); msg('Sem conexão — tente de novo.', 'erro'); });
        }
        ver.addEventListener('click', abrir);
        form.addEventListener('submit', function (ev) { ev.preventDefault(); salvar(); });
        input.addEventListener('keydown', function (ev) {
            if (ev.key === 'Escape') { ev.preventDefault(); input.value = original; fechar(); ver.focus(); }
        });
        input.addEventListener('blur', function () { setTimeout(function () { if (box.classList.contains('editando') && !box.contains(document.activeElement)) salvar(); }, 120); });
    });
    // Celular: "Detalhes" mostra FIPE/Pago/Quitação/Comissões do card.
    document.querySelectorAll('.rl-det-toggle').forEach(function (b) {
        b.addEventListener('click', function () {
            var tr = b.closest('tr'), aberto = tr.classList.toggle('rl-aberto');
            b.setAttribute('aria-expanded', aberto ? 'true' : 'false'); b.textContent = aberto ? 'Menos ▴' : 'Detalhes ▾';
        });
    });
})();
</script>
<?php include __DIR__ . '/_pwa_register.php'; ?>
<?php include __DIR__ . '/_notify.php'; ?>
<?php include __DIR__ . '/_scroll_restore.php'; ?>
<?php include __DIR__ . '/_acao_popup.php'; ?>
<?php include __DIR__ . '/_confirm_dialog.php'; ?>
<?php include __DIR__ . '/_zapi_status.php'; ?>
</body>
</html>
