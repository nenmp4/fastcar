<?php
/**
 * Prazo de quitação da Frota (19/11/2026, "vai em frota adicione card carros
 * 6 meses 12 meses 18 meses - carros 3 anos de uso classificar como risco de
 * banco buscar prioridade para quitação" / "notificar nesse prazo").
 *
 * Tempo com a Fastcar = desde a assinatura do contrato de compra (cai pra
 * data_compra/updated_at sem contrato assinado — mesma referência de sempre
 * da Frota). Só veículo com financiamento AINDA em aberto entra.
 *
 * Faixas: 6–11 meses (planejar), 12–17 (prazo normal), 18–35 (passou do
 * prazo) e 36+ (🚨 risco de banco, prioridade de quitação).
 *
 * Aviso (cron/frota_prazo_aviso.php): só quando o veículo ENTRA numa faixa —
 * 1 vez por veículo por faixa (config.frota_prazo_aviso_{id}_{faixa}). Só
 * pra equipe (sino de super admin/supervisor/financeiro + WhatsApp de
 * notificação), nunca pro cliente.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/notificacoes.php';

/** chave => [dias mínimo, dias máximo, rótulo do card, dica] — dias ≈ meses × 30,44 */
const FROTA_PRAZO_FAIXAS = [
    '6'  => [183, 364, '6 a 11 meses', 'Planejar a quitação'],
    '12' => [365, 547, '12 a 17 meses', 'Prazo normal do contrato — negociar'],
    '18' => [548, 1094, '18 a 35 meses', 'Passou do prazo normal — urgente'],
    '36' => [1095, 999999, '3 anos ou mais', 'Risco de banco — prioridade de quitação'],
];

const FROTA_PRAZO_REF_EXPR = "COALESCE(ctr.assinado_em, o.data_compra, o.updated_at)";
const FROTA_PRAZO_DIAS_EXPR = "CAST((julianday('now','localtime') - julianday(COALESCE(ctr.assinado_em, o.data_compra, o.updated_at))) AS INTEGER)";
const FROTA_PRAZO_JOIN_CONTRATO = "
    LEFT JOIN (
        SELECT oportunidade_id, MAX(assinado_em) AS assinado_em
        FROM contratos WHERE tipo = 'compra' AND assinado_em IS NOT NULL
        GROUP BY oportunidade_id
    ) ctr ON ctr.oportunidade_id = o.id
";

/** Faixa ('6'/'12'/'18'/'36') pros dias com a Fastcar, ou null abaixo de 6 meses. */
function frotaPrazoFaixa(int $dias): ?string {
    $achada = null;
    foreach (FROTA_PRAZO_FAIXAS as $k => [$min]) {
        if ($dias >= $min) $achada = (string)$k;
    }
    return $achada;
}

/** Veículos da frota com financiamento em aberto e 6+ meses com a Fastcar. */
function frotaPrazoListarVeiculosEmAberto(): array {
    $rows = getDB()->query("
        SELECT o.id, o.veiculo_marca, o.veiculo_modelo, o.veiculo_ano, o.veiculo_placa,
               o.banco_financiamento, o.parcelas_restantes, c.nome AS cliente_nome,
               " . FROTA_PRAZO_DIAS_EXPR . " AS dias
        FROM oportunidades o
        JOIN clientes c ON c.id = o.cliente_id
        " . FROTA_PRAZO_JOIN_CONTRATO . "
        WHERE o.etapa = 'fechado'
          AND COALESCE(o.oculto_estoque_motivo, '') NOT LIKE 'Cadastro duplicado%'
          AND o.financiamento_quitado = 0
          AND " . FROTA_PRAZO_DIAS_EXPR . " >= 183
        ORDER BY dias DESC
    ")->fetchAll();
    foreach ($rows as &$r) {
        $r['dias'] = (int)$r['dias'];
        $r['faixa'] = frotaPrazoFaixa($r['dias']);
    }
    unset($r);
    return $rows;
}

function frotaPrazoDescricaoVeiculo(array $v): string {
    $nome = trim(($v['veiculo_marca'] ?? '') . ' ' . ($v['veiculo_modelo'] ?? '') . ' ' . ($v['veiculo_ano'] ?? ''));
    $placa = strtoupper(trim((string)($v['veiculo_placa'] ?? '')));
    return ($nome !== '' ? $nome : 'Veículo #' . (int)$v['id']) . ($placa !== '' ? " — {$placa}" : '');
}

/**
 * Roda 1x por dia (cron). Marca, pra cada veículo, a faixa em que ele está e
 * avisa só quem acabou de ENTRAR numa faixa nova. Faixas menores puladas
 * (ex: veículo cadastrado já com 20 meses) contam como já avisadas — o aviso
 * é só da faixa atual.
 *
 * 1ª execução (config.frota_prazo_inicializado vazio): marca tudo sem avisar,
 * exceto quem já está em risco de banco (36+) — evita despejar dezenas de
 * avisos antigos de uma vez no sino.
 *
 * @return array{novos:int, risco_total:int, enviado_whatsapp:bool, inicial:bool}
 */
function frotaPrazoProcessarAvisos(): array {
    $inicial = !getConfig('frota_prazo_inicializado');
    $veiculos = frotaPrazoListarVeiculosEmAberto();
    $novos = [];
    $riscoTotal = 0;

    foreach ($veiculos as $v) {
        if ($v['faixa'] === '36') $riscoTotal++;
        $chave = "frota_prazo_aviso_{$v['id']}_{$v['faixa']}";
        if (getConfig($chave)) continue;
        foreach (array_keys(FROTA_PRAZO_FAIXAS) as $k) {
            setConfig("frota_prazo_aviso_{$v['id']}_{$k}", date('Y-m-d'));
            if ((string)$k === $v['faixa']) break;
        }
        if (!$inicial || $v['faixa'] === '36') $novos[] = $v;
    }
    if ($inicial) setConfig('frota_prazo_inicializado', date('Y-m-d H:i:s'));

    if (!$novos) return ['novos' => 0, 'risco_total' => $riscoTotal, 'enviado_whatsapp' => false, 'inicial' => $inicial];

    // Sino — super admin, supervisor e financeiro. Frota é só do super admin:
    // supervisor abre a oportunidade, financeiro abre o painel dele.
    $usuarios = getDB()->query("SELECT id, perfil FROM usuarios WHERE bloqueado = 0 AND perfil IN ('super_admin','supervisor','financeiro')")->fetchAll();
    foreach ($novos as $v) {
        $risco = $v['faixa'] === '36';
        $titulo = ($risco ? '🚨 Risco de banco — ' : '⏰ ' . FROTA_PRAZO_FAIXAS[$v['faixa']][2] . ' — ') . frotaPrazoDescricaoVeiculo($v);
        $banco = trim((string)$v['banco_financiamento']);
        $meses = (int)floor($v['dias'] / 30.44);
        $msg = ($risco
            ? "Está há {$meses} meses com a Fastcar sem quitar o financiamento. Prioridade de quitação."
            : "Está há {$meses} meses com a Fastcar. " . FROTA_PRAZO_FAIXAS[$v['faixa']][3] . '.')
            . ($banco !== '' ? " Banco: {$banco}." : '')
            . ($v['parcelas_restantes'] ? " {$v['parcelas_restantes']} parcela(s) restante(s)." : '');
        foreach ($usuarios as $u) {
            $url = match ($u['perfil']) {
                'super_admin' => '/admin/veiculos.php?prazo=' . $v['faixa'],
                'supervisor'  => '/admin/oportunidade.php?id=' . (int)$v['id'],
                default       => '/admin/financeiro.php',
            };
            try { criarNotificacao((int)$u['id'], 'frota_prazo', $titulo, $msg, $url); } catch (Throwable $e) {}
        }
    }

    // WhatsApp de notificação — 1 resumo só.
    $porFaixa = [];
    foreach ($novos as $v) $porFaixa[$v['faixa']][] = $v;
    $txt = "🚗 *Frota — prazo de quitação (" . date('d/m') . ")*\n";
    foreach (['36', '18', '12', '6'] as $k) {
        if (empty($porFaixa[$k])) continue;
        $txt .= "\n" . ($k === '36' ? '🚨 *Entrou em RISCO DE BANCO (3 anos):*' : '⏰ *Fez ' . $k . ' meses:*') . "\n";
        foreach ($porFaixa[$k] as $v) {
            $banco = trim((string)$v['banco_financiamento']);
            $txt .= '• ' . frotaPrazoDescricaoVeiculo($v) . ($banco !== '' ? " — {$banco}" : '') . "\n";
        }
    }
    $base = rtrim((string)(getConfig('app_base_url') ?: 'https://sistema.fastcar.solutions'), '/');
    $txt .= "\nTotal em risco de banco: {$riscoTotal}\n{$base}/admin/veiculos.php?prazo=36";
    $enviado = false;
    $lista = array_filter(array_map('trim', explode(',', getConfig('notificacao_leads_whatsapp') ?: '')));
    foreach ($lista as $numero) {
        try { if (zapiEnviarTextoInterno($numero, $txt)) $enviado = true; } catch (Throwable $e) {}
    }

    return ['novos' => count($novos), 'risco_total' => $riscoTotal, 'enviado_whatsapp' => $enviado, 'inicial' => $inicial];
}
