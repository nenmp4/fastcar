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

$db = getDB();
$erro = '';
$sucesso = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'salvar_quitacao') {
    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        $erro = 'Sessão expirada, recarregue a página.';
    } else {
        $opId = (int)($_POST['oportunidade_id'] ?? 0);
        $valor = valorMonetario((string)($_POST['valor_quitacao'] ?? ''));
        $st = $db->prepare("UPDATE oportunidades SET valor_quitacao = ?, updated_at = datetime('now','localtime') WHERE id = ? AND etapa = 'fechado'");
        $st->execute([$valor, $opId]);
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
    } else {
        $opId = (int)($_POST['oportunidade_id'] ?? 0);
        $valorFipe = valorMonetario((string)($_POST['valor_fipe'] ?? ''));
        if ($valorFipe !== null && $valorFipe <= 0) $valorFipe = null;
        $st = $db->prepare("UPDATE oportunidades SET valor_fipe_referencia = ?, updated_at = datetime('now','localtime') WHERE id = ? AND etapa = 'fechado'");
        $st->execute([$valorFipe, $opId]);
        if ($st->rowCount()) {
            $db->prepare("INSERT INTO oportunidade_historico (oportunidade_id, etapa_anterior, etapa_nova, responsavel_id, observacao) VALUES (?, 'fechado', 'fechado', ?, ?)")
               ->execute([$opId, (int)$_SESSION['admin_id'], 'FIPE ' . ($valorFipe !== null ? 'definida em R$ ' . number_format($valorFipe, 2, ',', '.') : 'apagada') . ' pelo relatório de lucro.']);
            $_SESSION['rl_msg'] = ['ok', 'FIPE salva.'];
            header('Location: /admin/relatorio_lucro_veiculos.php?' . http_build_query($_GET) . '#op' . $opId);
            exit;
        }
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
    'zapsign' => '📥 Migrados da ZapSign',
    'sem_entrada' => '💳 Venda sem entrada/parcelas',
];
$pend = array_key_exists($_GET['pend'] ?? '', RL_PENDENCIAS) ? $_GET['pend'] : '';
$ordem = in_array($_GET['ordem'] ?? '', ['data', 'lucro_maior', 'lucro_menor'], true) ? $_GET['ordem'] : 'data';

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

// 04/11/2026, "mostra fipe, quitação calculado em 20% da FIPE" — sem
// quitação digitada, o custo usa uma estimativa de 20% da FIPE (marcada
// como estimada na tela/PDF); o valor digitado sempre vence.
const RL_QUITACAO_PCT_FIPE = 20;

// Venda mais recente não cancelada (e não locação) de cada veículo.
$sql = "
    SELECT o.id AS op_id, o.veiculo_marca, o.veiculo_modelo, o.veiculo_ano, o.veiculo_placa,
           o.valor_final, o.valor_quitacao, o.valor_fipe_referencia, o.data_compra, o.financiamento_quitado,
           v.id AS venda_id, v.etapa AS venda_etapa, v.comprador_nome, v.data_venda, v.preco_venda,
           v.valor_pago_contratacao, v.parcelamento_valor_parcela, v.parcelamento_qtd_parcelas,
           v.parcelamento_periodicidade,
           EXISTS (SELECT 1 FROM contratos ct WHERE ct.oportunidade_id = o.id AND ct.campos_json LIKE '%importado_zapsign%') AS migrado_zapsign,
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
$st = $db->prepare($sql);
$st->execute($params);
$linhas = $st->fetchAll();

$tot = ['pago' => 0, 'quitacao' => 0, 'venda' => 0, 'entrada' => 0, 'recebido' => 0, 'lucro' => 0, 'n_lucro' => 0, 'sem_quitacao' => 0, 'sem_pago' => 0, 'fipe' => 0, 'quit_estimada' => 0, 'quit_prevista' => 0, 'comissao' => 0];
$contPend = [];
$totalSemPend = count($linhas);
foreach ($linhas as &$l) {
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
    $marcas = [
        'sem_fipe' => !($fipe > 0),
        'sem_pago' => !($pago > 0),
        'sem_quitacao' => $l['valor_quitacao'] === null,
        'lucro_neg' => $l['_lucro'] !== null && $l['_lucro'] < -0.005,
        'lucro_zero' => $l['_lucro'] !== null && abs($l['_lucro']) < 0.005,
        'zapsign' => (bool)$l['migrado_zapsign'],
        'sem_entrada' => $l['venda_id'] && !($entrada > 0) && !($parcela > 0 && $qtd > 0),
    ];
    foreach ($marcas as $k => $sim) if ($sim) $contPend[$k] = ($contPend[$k] ?? 0) + 1;
    if ($pend !== '' && !$marcas[$pend]) { $l['_fora'] = true; continue; }
    $tot['quit_prevista'] += $l['_quit_prevista'] ?? 0;
    $tot['pago'] += $pago;
    if ($pago <= 0) $tot['sem_pago']++;
    $tot['quitacao'] += $quit ?? 0;
    if ($quit === null) $tot['sem_quitacao']++;
    if ($l['_quit_estimada']) $tot['quit_estimada']++;
    $tot['fipe'] += $fipe;
    $tot['comissao'] += $l['_comissao'];
    $tot['entrada'] += $entrada;
    $tot['recebido'] += (float)$l['recebido'];
    if ($vendaTotal !== null) {
        $tot['venda'] += $vendaTotal;
        $tot['lucro'] += $l['_lucro'];
        $tot['n_lucro']++;
    }
}
unset($l);
$linhas = array_values(array_filter($linhas, fn($l) => empty($l['_fora'])));
if ($ordem !== 'data') {
    usort($linhas, function ($a, $b) use ($ordem) {
        $la = $a['_lucro'] ?? ($ordem === 'lucro_maior' ? -INF : INF);
        $lb = $b['_lucro'] ?? ($ordem === 'lucro_maior' ? -INF : INF);
        return $ordem === 'lucro_maior' ? $lb <=> $la : $la <=> $lb;
    });
}

// Lote: lê até RL_LOTE_LEITURA contratos dos veículos da lista atual que
// estão sem FIPE ou sem valor pago (limite por clique pra não estourar o
// tempo da página — clicar de novo continua de onde parou).
const RL_LOTE_LEITURA = 8;
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
            rlMoeda($l['_lucro']),
        ];
        $pdf->SetFillColor(246, 247, 250);
        $pdf->SetFont('Helvetica', '', 7.2);
        foreach ($cols as $i => [$t, $cw, $al]) {
            $txt = _pdfTexto((string)$vals[$i]);
            while ($pdf->GetStringWidth($txt) > $cw - 1.5 && strlen($txt) > 3) $txt = substr($txt, 0, -2);
            if ($i === 13 && $l['_lucro'] !== null) {
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
    $pdf->MultiCell(0, 4, _pdfTexto('Custo = valor pago ao vendedor + quitação do financiamento (sem valor digitado, estimada em ' . RL_QUITACAO_PCT_FIPE . '% da FIPE, marcada com *) + comissões (consultor na compra + vendedor na venda, lançadas no financeiro). Total da venda = entrada + parcelas (quantidade × valor) ou o preço da venda quando não há parcelamento. Lucro previsto = total da venda − custo, contando parcelas ainda não pagas; "Recebido" é o que já está marcado como pago no financeiro. Locação fica de fora.'));
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
@media (min-width: 1100px) { main { max-width: 1680px; } }
.rl-tabela td, .rl-tabela th { white-space: nowrap; vertical-align: top; padding-left: 8px; padding-right: 8px; }
.rl-tabela td { font-size: 13px; }
.rl-tabela th { white-space: normal; line-height: 1.25; }
.rl-tabela th small { display: block; font-weight: 600; text-transform: none; }
.rl-tabela td.rl-veic { white-space: normal; min-width: 150px; max-width: 210px; }
.rl-veic .rl-nome { font-weight: 700; font-size: 13px; line-height: 1.3; display: block; overflow-wrap: anywhere; }
.rl-veic .rl-sub { font-size: 12px; color: var(--texto-suave); display: block; margin-top: 2px; }
.rl-tabela td.rl-num { text-align: right; font-variant-numeric: tabular-nums; }
.rl-tabela th.rl-num { text-align: right; }
.rl-lucro-pos { color: var(--verde); font-weight: 800; }
.rl-lucro-neg { color: var(--vermelho); font-weight: 800; }
.rl-quit-form { display: flex; gap: 6px; align-items: center; margin: 0; }
.rl-quit-form input { width: 100px; min-width: 100px; margin: 0; padding: 8px 10px; text-align: right; }
.rl-quit-form button { margin: 0; width: 38px; min-width: 38px; height: 40px; padding: 0; }
.rl-estimada { display: block; font-size: 11px; color: var(--texto-suave); margin-top: 3px; white-space: normal; }
.rl-aviso { display: block; font-size: 11px; color: var(--laranja); font-weight: 600; margin-top: 3px; white-space: normal; }
.rl-stats .stat-card .valor { font-size: 22px; }
.rl-stats a.stat-card { cursor: pointer; }
.rl-stats a.stat-card small { color: var(--azul); }
.rl-stats a.stat-card small.rl-alerta { color: var(--laranja); }
.rl-lnk { color: inherit; text-decoration: none; border-bottom: 1px dashed var(--borda); }
.rl-lnk:hover { color: var(--azul); border-bottom-color: var(--azul); text-decoration: none; }
.rl-stats .stat-card small { display: block; color: var(--laranja); font-weight: 600; margin-top: 4px; }
.rl-filtros { display: flex; gap: 10px; flex-wrap: wrap; align-items: flex-end; margin: 0; }
.rl-filtros > div { min-width: 140px; }
.rl-filtros .rl-busca { flex: 1; min-width: 220px; }
.rl-filtros button, .rl-filtros .btn { margin: 0; width: auto; white-space: nowrap; min-height: 44px; display: inline-flex; align-items: center; }
.rl-filtros > div.rl-acao { min-width: 0; }
.rl-tabela tfoot td { font-weight: 800; background: var(--azul-claro, #eef3fe); white-space: nowrap; }
@media (max-width: 700px) {
    .rl-tabela tfoot tr { display: block; width: auto; background: var(--azul-claro, #eef3fe); }
    .rl-tabela tfoot td { background: transparent; }
    .rl-tabela tfoot td:empty { display: none; }
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
<h2>📊 Lucro por veículo — <?= e($rotuloPeriodo) ?></h2>
<p><small>Custo = valor pago ao vendedor + quitação do financiamento (sem valor digitado, a quitação é estimada em <?= RL_QUITACAO_PCT_FIPE ?>% da FIPE). Venda = entrada + parcela × quantidade (ou o preço da venda, se não tiver parcelamento). Lucro previsto conta também as parcelas que ainda não foram pagas. Locação fica de fora.</small></p>
<?php if ($erro): ?><div class="alerta-erro"><?= e($erro) ?></div><?php endif; ?>
<?php if ($sucesso): ?><div class="alerta-sucesso"><?= e($sucesso) ?></div><?php endif; ?>

<div class="stat-grid rl-stats">
    <?php
    $baseQ = ['filtro' => $filtro, 'q' => $busca, 'mes' => $mes, 'ordem' => $ordem];
    $lnk = fn(array $extra) => '?' . e(http_build_query(array_merge($baseQ, $extra)));
    ?>
    <a class="stat-card" href="<?= $lnk(['pend' => 'sem_fipe']) ?>" title="Ver os veículos sem FIPE"><div class="valor"><?= rlMoeda($tot['fipe']) ?></div><div class="rotulo">FIPE dos veículos</div><small>ver sem FIPE →</small></a>
    <a class="stat-card" href="<?= $lnk(['pend' => 'sem_quitacao']) ?>" title="Ver quem ainda usa a quitação prevista"><div class="valor"><?= rlMoeda($tot['quit_prevista']) ?></div><div class="rotulo">Quitação prevista (<?= RL_QUITACAO_PCT_FIPE ?>% FIPE)</div><small>ver sem quitação real →</small></a>
    <a class="stat-card" href="<?= $lnk(['pend' => 'sem_pago']) ?>" title="Ver os veículos sem valor pago"><div class="valor"><?= rlMoeda($tot['pago']) ?></div><div class="rotulo">Pago aos vendedores</div><?php if ($tot['sem_pago']): ?><small class="rl-alerta"><?= $tot['sem_pago'] ?> sem valor pago →</small><?php endif; ?></a>
    <a class="stat-card" href="<?= $lnk(['pend' => 'sem_quitacao']) ?>" title="Ver os veículos sem quitação real"><div class="valor"><?= rlMoeda($tot['quitacao']) ?></div><div class="rotulo">Quitações (real + prevista)</div><?php if ($tot['quit_estimada']): ?><small class="rl-alerta"><?= $tot['quit_estimada'] ?> ainda sem valor real →</small><?php endif; ?><?php if ($tot['sem_quitacao']): ?><small class="rl-alerta"><?= $tot['sem_quitacao'] ?> sem quitação e sem FIPE</small><?php endif; ?></a>
    <a class="stat-card" href="/admin/financeiro-lancamentos.php?origem=comissao_compra&amp;todos_periodos=1" title="Ver comissões lançadas no financeiro"><div class="valor"><?= rlMoeda($tot['comissao']) ?></div><div class="rotulo">Comissões (compra + venda)</div><small>já descontadas do lucro →</small></a>
    <a class="stat-card" href="<?= $lnk(['pend' => '', 'ordem' => 'lucro_maior']) ?>" title="Ordenar pelo maior lucro"><div class="valor"><?= rlMoeda($tot['venda']) ?></div><div class="rotulo">Total das vendas</div><small>ordenar por maior lucro →</small></a>
    <a class="stat-card" href="/admin/promissorias.php" title="Ver parcelas recebidas em Promissórias"><div class="valor"><?= rlMoeda($tot['recebido']) ?></div><div class="rotulo">Já recebido</div><small>ver parcelas →</small></a>
    <a class="stat-card <?= $tot['lucro'] < 0 ? 'alerta' : 'sucesso' ?>" href="<?= $lnk(['pend' => 'lucro_neg', 'ordem' => 'lucro_menor']) ?>" title="Ver os veículos com prejuízo"><div class="valor"><?= rlMoeda($tot['lucro']) ?></div><div class="rotulo">Lucro previsto (<?= $tot['n_lucro'] ?> vendido<?= $tot['n_lucro'] === 1 ? '' : 's' ?>)</div><small>ver com prejuízo →</small></a>
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
            </select>
        </div>
        <?php if ($pend !== ''): ?><input type="hidden" name="pend" value="<?= e($pend) ?>"><?php endif; ?>
        <div class="rl-acao"><button type="submit">🔎 Filtrar</button></div>
        <?php if ($mes !== ''): ?><div class="rl-acao"><a class="btn" href="?<?= e(http_build_query(['filtro' => $filtro, 'q' => $busca, 'pend' => $pend, 'ordem' => $ordem])) ?>">Limpar mês</a></div><?php endif; ?>
        <?php if ($qtdLerLote): ?><div class="rl-acao"><button type="submit" form="rl-form-lote" title="Lê o contrato assinado e preenche FIPE/valor pago vazios">🤖 Ler contratos com IA (<?= min($qtdLerLote, RL_LOTE_LEITURA) ?> de <?= $qtdLerLote ?>)</button></div><?php endif; ?>
        <?php if ($qtdLerVendas): ?><div class="rl-acao"><button type="submit" form="rl-form-lote-venda" title="Lê o contrato de venda e preenche entrada/parcelas/preço vazios">💳 Ler entrada/parcelas das vendas (<?= min($qtdLerVendas, RL_LOTE_LEITURA) ?> de <?= $qtdLerVendas ?>)</button></div><?php endif; ?>
        <div class="rl-acao"><a class="btn btn-primary" target="_blank" href="?<?= e(http_build_query(['filtro' => $filtro, 'q' => $busca, 'mes' => $mes, 'pend' => $pend, 'ordem' => $ordem, 'pdf' => 1])) ?>">📄 Gerar PDF completo</a></div>
    </form>
</div>

<nav class="etapas-nav rl-pend">
    <a class="<?= $pend === '' ? 'ativo' : '' ?>" href="?<?= e(http_build_query($baseQ)) ?>">Todos (<?= $totalSemPend ?>)</a>
    <?php foreach (RL_PENDENCIAS as $k => $rot): ?>
        <a class="<?= $pend === $k ? 'ativo' : '' ?>" href="?<?= e(http_build_query($baseQ + ['pend' => $k])) ?>"><?= e($rot) ?> (<?= (int)($contPend[$k] ?? 0) ?>)</a>
    <?php endforeach; ?>
</nav>
<form id="rl-form-lote" method="post" style="display:none"><?= csrfField() ?><input type="hidden" name="acao" value="ler_contratos_lote"></form>
<form id="rl-form-lote-venda" method="post" style="display:none"><?= csrfField() ?><input type="hidden" name="acao" value="ler_vendas_lote"></form>
<div class="card">
<table class="tabela-oportunidades rl-tabela">
    <thead><tr>
        <th>Veículo</th><th>FIPE</th><th class="rl-num">Quitação prevista<small><?= RL_QUITACAO_PCT_FIPE ?>% da FIPE</small></th><th class="rl-num">Valor pago</th><th>Quitação real</th><th class="rl-num">Comissões<small>compra + venda</small></th><th class="rl-num">Custo total</th>
        <th class="rl-num">Entrada</th><th>Parcelas</th><th class="rl-num">Total venda</th><th class="rl-num">Recebido</th><th class="rl-num">Lucro</th>
    </tr></thead>
    <tbody>
    <?php if (!$linhas): ?><tr><td colspan="11">Nenhum veículo encontrado.</td></tr><?php endif; ?>
    <?php foreach ($linhas as $l): ?>
        <tr id="op<?= (int)$l['op_id'] ?>">
            <td class="rl-veic">
                <a class="rl-nome" href="/admin/oportunidade.php?id=<?= (int)$l['op_id'] ?>"><?= e(trim($l['veiculo_marca'] . ' ' . $l['veiculo_modelo'] . ' ' . $l['veiculo_ano'])) ?: 'Veículo #' . (int)$l['op_id'] ?></a>
                <?php if ($l['migrado_zapsign']): ?><span class="rl-sub">📥 migrado da ZapSign</span><?php endif; ?>
                <?php if ($l['veiculo_placa']): ?><span class="rl-sub">🔖 <?= e(strtoupper($l['veiculo_placa'])) ?></span><?php endif; ?>
                <?php if ($l['venda_id']): ?><span class="rl-sub"><a href="/admin/venda.php?id=<?= (int)$l['venda_id'] ?>">venda #<?= (int)$l['venda_id'] ?></a> · <?= e($l['comprador_nome'] ?: '—') ?><?= $l['data_venda'] ? ' · ' . date('d/m/Y', strtotime($l['data_venda'])) : '' ?><?= $l['venda_etapa'] !== 'vendido' ? ' (em negociação)' : '' ?></span><?php else: ?><span class="rl-sub">sem venda</span><?php endif; ?>
            </td>
            <td data-label="FIPE">
                <form method="post" class="rl-quit-form">
                    <?= csrfField() ?>
                    <input type="hidden" name="acao" value="salvar_fipe">
                    <input type="hidden" name="oportunidade_id" value="<?= (int)$l['op_id'] ?>">
                    <input type="text" name="valor_fipe" inputmode="decimal" placeholder="digitar FIPE" aria-label="Valor FIPE" value="<?= (float)$l['valor_fipe_referencia'] > 0 ? e(number_format((float)$l['valor_fipe_referencia'], 2, ',', '.')) : '' ?>">
                    <button type="submit" title="Salvar FIPE">💾</button>
                </form>
                <?php if (!((float)$l['valor_fipe_referencia'] > 0)): ?><span class="rl-aviso">sem FIPE</span><?php endif; ?>
                <?php if (!((float)$l['valor_fipe_referencia'] > 0) || !((float)$l['valor_final'] > 0)): ?>
                <form method="post" style="margin:4px 0 0"><?= csrfField() ?><input type="hidden" name="acao" value="ler_contrato"><input type="hidden" name="oportunidade_id" value="<?= (int)$l['op_id'] ?>">
                    <button type="submit" class="btn-texto" title="A IA lê o contrato assinado e preenche FIPE e valor pago">🤖 ler contrato</button></form>
                <?php endif; ?></td>
            <td class="rl-num"><?= rlMoeda($l['_quit_prevista']) ?></td>
            <td class="rl-num"><a class="rl-lnk" href="/admin/oportunidade.php?id=<?= (int)$l['op_id'] ?>" title="Abrir o veículo"><?= rlMoeda($l['valor_final'] !== null && (float)$l['valor_final'] > 0 ? (float)$l['valor_final'] : null) ?><?php if (!((float)$l['valor_final'] > 0)): ?><span class="rl-aviso">⚠️ sem valor pago</span><?php endif; ?></a></td>
            <td data-label="Quitação">
                <form method="post" class="rl-quit-form">
                    <?= csrfField() ?>
                    <input type="hidden" name="acao" value="salvar_quitacao">
                    <input type="hidden" name="oportunidade_id" value="<?= (int)$l['op_id'] ?>">
                    <input type="text" name="valor_quitacao" inputmode="decimal" placeholder="<?= $l['_quit_estimada'] ? e(number_format((float)$l['_quitacao'], 2, ',', '.')) : '0,00' ?>" aria-label="Valor da quitação" value="<?= $l['valor_quitacao'] !== null ? e(number_format((float)$l['valor_quitacao'], 2, ',', '.')) : '' ?>">
                    <button type="submit" title="Salvar quitação">💾</button>
                </form>
                <?php if ($l['_quit_estimada']): ?><span class="rl-estimada">usando a prevista</span><?php endif; ?>
                <?php if ($l['financiamento_quitado']): ?><small>✅ financiamento quitado</small><?php endif; ?>
            </td>
            <td class="rl-num" data-label="Comissões"><?php if ($l['_comissao'] > 0): ?><span title="Compra: <?= e(rlMoeda((float)$l['comissao_compra'])) ?> · Venda: <?= e(rlMoeda((float)$l['comissao_venda'])) ?>"><?= rlMoeda($l['_comissao']) ?></span><small style="display:block;color:var(--texto-fraco)"><?= (float)$l['comissao_compra'] > 0 ? 'C ' . rlMoeda((float)$l['comissao_compra']) : '' ?><?= (float)$l['comissao_compra'] > 0 && (float)$l['comissao_venda'] > 0 ? ' · ' : '' ?><?= (float)$l['comissao_venda'] > 0 ? 'V ' . rlMoeda((float)$l['comissao_venda']) : '' ?></small><?php else: ?>—<?php endif; ?></td>
            <td class="rl-num"><?= rlMoeda($l['_custo']) ?></td>
            <td class="rl-num"><?php if ($l['venda_id']): ?><a class="rl-lnk" href="/admin/venda.php?id=<?= (int)$l['venda_id'] ?>"><?= rlMoeda((float)($l['valor_pago_contratacao'] ?? 0)) ?></a><?php else: ?>—<?php endif; ?></td>
            <td>
                <?php if ((int)$l['parcelamento_qtd_parcelas'] > 0 && (float)$l['parcelamento_valor_parcela'] > 0): ?>
                    <?= (int)$l['parcelamento_qtd_parcelas'] ?>× <?= rlMoeda((float)$l['parcelamento_valor_parcela']) ?><?= ($l['parcelamento_periodicidade'] ?? '') === 'semanal' ? ' <small>(semanal)</small>' : '' ?>
                <?php else: ?>
                    <?= $l['venda_id'] ? '<small>à vista / sem parcelas</small>' : '—' ?>
                    <?php if ($l['venda_id'] && !((float)$l['valor_pago_contratacao'] > 0)): ?>
                        <form method="post" style="margin:4px 0 0"><?= csrfField() ?><input type="hidden" name="acao" value="ler_contrato_venda"><input type="hidden" name="venda_id" value="<?= (int)$l['venda_id'] ?>"><input type="hidden" name="oportunidade_id" value="<?= (int)$l['op_id'] ?>">
                            <button type="submit" class="btn-texto" title="A IA lê o contrato da venda e preenche entrada, parcelas e preço" onclick="this.textContent='🤖 lendo...'">🤖 ler entrada/parcelas</button></form>
                    <?php endif; ?>
                <?php endif; ?>
            </td>
            <td class="rl-num"><?php if ($l['venda_id']): ?><a class="rl-lnk" href="/admin/venda.php?id=<?= (int)$l['venda_id'] ?>"><?= rlMoeda($l['_venda_total']) ?></a><?php else: ?>—<?php endif; ?></td>
            <td class="rl-num"><?php if ($l['venda_id']): ?><a class="rl-lnk" href="/admin/venda.php?id=<?= (int)$l['venda_id'] ?>" title="Ver parcelas da venda"><?= rlMoeda((float)$l['recebido']) ?></a><?php else: ?>—<?php endif; ?></td>
            <td class="rl-num"><?php if ($l['_lucro'] === null): ?>—<?php else: ?><span class="<?= $l['_lucro'] < 0 ? 'rl-lucro-neg' : 'rl-lucro-pos' ?>"><?= rlMoeda($l['_lucro']) ?></span><?php endif; ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
    <?php if ($linhas): ?>
    <tfoot><tr>
        <td>Total (<?= count($linhas) ?>)</td>
        <td class="rl-num"><?= rlMoeda($tot['fipe']) ?></td>
        <td class="rl-num"><?= rlMoeda($tot['quit_prevista']) ?></td>
        <td class="rl-num"><?= rlMoeda($tot['pago']) ?></td>
        <td class="rl-num"><?= rlMoeda($tot['quitacao']) ?></td>
        <td class="rl-num"><?= rlMoeda($tot['comissao']) ?></td>
        <td class="rl-num"><?= rlMoeda($tot['pago'] + $tot['quitacao'] + $tot['comissao']) ?></td>
        <td class="rl-num"><?= rlMoeda($tot['entrada']) ?></td>
        <td data-label="Parcelas">—</td>
        <td class="rl-num"><?= rlMoeda($tot['venda']) ?></td>
        <td class="rl-num"><?= rlMoeda($tot['recebido']) ?></td>
        <td class="rl-num"><span class="<?= $tot['lucro'] < 0 ? 'rl-lucro-neg' : 'rl-lucro-pos' ?>"><?= rlMoeda($tot['lucro']) ?></span></td>
    </tr></tfoot>
    <?php endif; ?>
</table>
</div>
</main>
<?php include __DIR__ . '/_pwa_register.php'; ?>
<?php include __DIR__ . '/_notify.php'; ?>
<?php include __DIR__ . '/_scroll_restore.php'; ?>
<?php include __DIR__ . '/_acao_popup.php'; ?>
<?php include __DIR__ . '/_confirm_dialog.php'; ?>
<?php include __DIR__ . '/_zapi_status.php'; ?>
</body>
</html>
