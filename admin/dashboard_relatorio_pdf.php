<?php
/**
 * Streama o relatório em PDF da listagem do dashboard (admin/index.php)
 * direto pro navegador — 21/09/2026, "coloca botão para gerar pdf
 * relatório". Mesma lógica de filtro (?etapa=/?filtro=/?q=) de
 * admin/index.php, duplicada de propósito (sem LIMIT/OFFSET aqui — o PDF
 * lista TUDO que bate com o filtro, não só a página visível na tela) —
 * mesmo cuidado de ob_start() ANTES de tudo já usado nos outros relatórios
 * em PDF do projeto (admin/financeiro-relatorio-*.php): fpdf.php vaza um
 * \n depois da tag de fechamento do PHP, que quebra Output('I',...) sem
 * buffer ativo.
 */
ob_start();

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../includes/dashboard_pdf.php';

$db = getDB();
$perfil = $_SESSION['admin_perfil'];
$meuId = (int)$_SESSION['admin_id'];
$souDono = $perfil === 'consultor';

$etapaFiltro = (string)($_GET['etapa'] ?? '');
$busca = trim((string)($_GET['q'] ?? ''));
$filtroEspecial = (string)($_GET['filtro'] ?? '');
$extraWhere = '';

switch ($filtroEspecial) {
    case 'hoje':
        $etapasEscopo = ETAPAS_ATIVAS;
        $extraWhere = " AND date(o.created_at) = date('now','localtime')";
        break;
    case 'ontem':
        $etapasEscopo = ETAPAS_ATIVAS;
        $extraWhere = " AND date(o.created_at) = date('now','localtime','-1 day')";
        break;
    case 'semana':
        $etapasEscopo = ETAPAS_ATIVAS;
        $extraWhere = " AND o.created_at >= datetime('now','localtime','-7 days')";
        break;
    case 'atrasadas':
        $etapasEscopo = ETAPAS_ATIVAS;
        $extraWhere = " AND o.proxima_acao_em IS NOT NULL AND o.proxima_acao_em < datetime('now','localtime')";
        break;
    case 'negociacao':
        $etapasEscopo = ['negociacao', 'presencial'];
        break;
    case 'fechado_mes':
        $etapasEscopo = ['fechado'];
        $extraWhere = " AND o.data_compra >= date('now','localtime','start of month')";
        break;
    default:
        $etapasEscopo = match ($etapaFiltro) {
            'fechado' => ['fechado'],
            'encerradas' => ['perdido', 'sem_perfil'],
            default => ETAPAS_ATIVAS,
        };
}
$etapaBuscandoFechadas = $etapasEscopo === ['fechado'];
$placeholders = implode(',', array_fill(0, count($etapasEscopo), '?'));

$where = "WHERE o.etapa IN ({$placeholders}){$extraWhere}";
$params = $etapasEscopo;
if ($souDono) {
    $where .= $etapaBuscandoFechadas ? " AND o.fechado_por = ?" : " AND o.responsavel_id = ?";
    $params[] = $meuId;
}
if ($filtroEspecial === '' && $etapaFiltro && !$etapaBuscandoFechadas && in_array($etapaFiltro, ETAPAS_ATIVAS, true)) {
    $where .= " AND o.etapa = ?";
    $params[] = $etapaFiltro;
}
if ($busca !== '') {
    $where .= " AND (c.nome LIKE ? OR c.telefone LIKE ? OR o.veiculo_marca LIKE ? OR o.veiculo_modelo LIKE ? OR o.veiculo_placa LIKE ?)";
    $like = '%' . $busca . '%';
    array_push($params, $like, $like, $like, $like, $like);
}

$titulo = [
    'hoje' => 'Leads novos hoje', 'ontem' => 'Leads novos ontem',
    'semana' => 'Recebidos nos últimos 7 dias',
    'atrasadas' => 'Atrasadas', 'negociacao' => 'Em negociação/presencial',
    'fechado_mes' => 'Fechadas este mês',
][$filtroEspecial] ?? match ($etapaFiltro) {
    'fechado' => 'Pasta fechada',
    'encerradas' => 'Encerradas (perdido/sem perfil)',
    '' => $souDono ? 'Minhas oportunidades' : 'Todas as oportunidades',
    default => etapaLabel($etapaFiltro),
};
if ($busca !== '') $titulo .= ' — busca: "' . $busca . '"';

// 21/09/2026, "ideal gerar com detalhe trazer resumos das convesas" —
// opt-in via ?detalhado=1 (link separado em admin/index.php), nunca o
// padrão — ver includes/dashboard_pdf.php pro racional completo.
$comResumo = ($_GET['detalhado'] ?? '') === '1';
if ($comResumo) $titulo .= ' (com resumo da IA)';

$pdf = gerarRelatorioDashboardPdf($db, $where, $params, $titulo, $comResumo);
ob_end_clean();
$nomeArquivo = ($comResumo ? 'relatorio_detalhado_' : 'relatorio_oportunidades_') . date('Y-m-d_His') . '.pdf';
$pdf->Output('I', $nomeArquivo);
