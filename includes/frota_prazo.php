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
 * Faixas de tempo: 6–11 meses (planejar), 12–17 (prazo normal), 18+ (passou
 * do prazo).
 *
 * 🚨 Risco alto de busca e apreensão (21/11/2026, "veículos anos 2022 para
 * atualidade... são veículos risco alto busca apreensão depois 6 meses"):
 * carro do ano (modelo) de 4 anos atrás pra cá — hoje 2022 em diante, sobe
 * sozinho a cada ano — com 6+ meses com a Fastcar e financiamento em aberto.
 * É uma marca à parte das faixas de tempo (o carro está numa faixa E pode
 * estar em risco). Ano sem 4 dígitos legíveis nunca conta como risco.
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
    '18' => [548, 999999, '18 meses ou mais', 'Passou do prazo normal — urgente'],
];

/** Ano do modelo: últimos 4 caracteres de veiculo_ano ("2022/2023" → 2023, "2022" → 2022). */
const FROTA_ANO_EXPR = "CAST(SUBSTR(TRIM(COALESCE(o.veiculo_ano, '')), -4) AS INTEGER)";

/** Ano mínimo do carro pra contar como risco alto (4 anos atrás — 2022 em 2026). */
function frotaPrazoAnoMinimoRisco(): int {
    return (int)date('Y') - 4;
}

function frotaPrazoAnoVeiculo(?string $ano): int {
    $t = trim((string)$ano);
    return preg_match('/(\d{4})$/', $t, $m) ? (int)$m[1] : 0;
}

/** Risco alto de busca e apreensão: carro recente + 6 meses ou mais com a Fastcar. */
function frotaPrazoEhRisco(int $dias, ?string $ano): bool {
    return $dias >= 183 && frotaPrazoAnoVeiculo($ano) >= frotaPrazoAnoMinimoRisco();
}

/** Condição SQL do risco (precisa do join de contrato; financiamento em aberto fica com quem chama). */
function frotaPrazoSqlRisco(): string {
    return "(" . FROTA_PRAZO_DIAS_EXPR . " >= 183 AND " . FROTA_ANO_EXPR . " >= " . frotaPrazoAnoMinimoRisco() . ")";
}

const FROTA_PRAZO_REF_EXPR = "COALESCE(ctr.assinado_em, o.data_compra, o.updated_at)";
const FROTA_PRAZO_DIAS_EXPR = "CAST((julianday('now','localtime') - julianday(COALESCE(ctr.assinado_em, o.data_compra, o.updated_at))) AS INTEGER)";
const FROTA_PRAZO_JOIN_CONTRATO = "
    LEFT JOIN (
        SELECT oportunidade_id, MAX(assinado_em) AS assinado_em
        FROM contratos WHERE tipo = 'compra' AND assinado_em IS NOT NULL
        GROUP BY oportunidade_id
    ) ctr ON ctr.oportunidade_id = o.id
";

/** Faixa ('6'/'12'/'18') pros dias com a Fastcar, ou null abaixo de 6 meses. */
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
        $r['risco'] = frotaPrazoEhRisco($r['dias'], $r['veiculo_ano']);
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
 * 1ª execução (config.frota_prazo_inicializado vazio): marca as faixas de
 * tempo sem avisar — evita despejar dezenas de avisos antigos de uma vez no
 * sino. Risco alto sempre avisa (1x por veículo).
 *
 * @return array{novos:int, risco_total:int, enviado_whatsapp:bool, inicial:bool}
 */
function frotaPrazoProcessarAvisos(): array {
    $inicial = !getConfig('frota_prazo_inicializado');
    $veiculos = frotaPrazoListarVeiculosEmAberto();
    $novos = [];
    $riscoTotal = 0;

    foreach ($veiculos as $v) {
        if ($v['risco']) $riscoTotal++;
        $avisar = null;
        // Risco alto: aviso próprio, 1x por veículo (vale mais que a faixa de tempo).
        if ($v['risco'] && !getConfig("frota_prazo_aviso_{$v['id']}_risco")) {
            setConfig("frota_prazo_aviso_{$v['id']}_risco", date('Y-m-d'));
            $avisar = 'risco';
        }
        $chave = "frota_prazo_aviso_{$v['id']}_{$v['faixa']}";
        if (!getConfig($chave)) {
            foreach (array_keys(FROTA_PRAZO_FAIXAS) as $k) {
                setConfig("frota_prazo_aviso_{$v['id']}_{$k}", date('Y-m-d'));
                if ((string)$k === $v['faixa']) break;
            }
            if ($avisar === null && !$inicial) $avisar = $v['faixa'];
        }
        if ($avisar !== null) { $v['aviso'] = $avisar; $novos[] = $v; }
    }
    if ($inicial) setConfig('frota_prazo_inicializado', date('Y-m-d H:i:s'));

    if (!$novos) return ['novos' => 0, 'risco_total' => $riscoTotal, 'enviado_whatsapp' => false, 'inicial' => $inicial];

    // Sino — super admin, supervisor e financeiro. Frota é só do super admin:
    // supervisor abre a oportunidade, financeiro abre o painel dele.
    $usuarios = getDB()->query("SELECT id, perfil FROM usuarios WHERE bloqueado = 0 AND perfil IN ('super_admin','supervisor','financeiro')")->fetchAll();
    foreach ($novos as $v) {
        $risco = $v['aviso'] === 'risco';
        $titulo = ($risco ? '🚨 Risco alto de busca e apreensão — ' : '⏰ ' . FROTA_PRAZO_FAIXAS[$v['aviso']][2] . ' — ') . frotaPrazoDescricaoVeiculo($v);
        $banco = trim((string)$v['banco_financiamento']);
        $meses = (int)floor($v['dias'] / 30.44);
        $msg = ($risco
            ? "Carro " . frotaPrazoAnoVeiculo($v['veiculo_ano']) . " há {$meses} meses com a Fastcar sem quitar o financiamento. Prioridade de quitação."
            : "Está há {$meses} meses com a Fastcar. " . FROTA_PRAZO_FAIXAS[$v['aviso']][3] . '.')
            . ($banco !== '' ? " Banco: {$banco}." : '')
            . ($v['parcelas_restantes'] ? " {$v['parcelas_restantes']} parcela(s) restante(s)." : '');
        foreach ($usuarios as $u) {
            $url = match ($u['perfil']) {
                'super_admin' => '/admin/veiculos.php?prazo=' . $v['aviso'],
                'supervisor'  => '/admin/oportunidade.php?id=' . (int)$v['id'],
                default       => '/admin/financeiro.php',
            };
            try { criarNotificacao((int)$u['id'], 'frota_prazo', $titulo, $msg, $url); } catch (Throwable $e) {}
        }
    }

    // WhatsApp de notificação — 1 resumo só.
    $porFaixa = [];
    foreach ($novos as $v) $porFaixa[$v['aviso']][] = $v;
    $txt = "🚗 *Frota — prazo de quitação (" . date('d/m') . ")*\n";
    foreach (['risco', '18', '12', '6'] as $k) {
        if (empty($porFaixa[$k])) continue;
        $txt .= "\n" . ($k === 'risco' ? '🚨 *RISCO ALTO de busca e apreensão (carro ' . frotaPrazoAnoMinimoRisco() . '+ com 6+ meses):*' : '⏰ *Fez ' . $k . ' meses:*') . "\n";
        foreach ($porFaixa[$k] as $v) {
            $banco = trim((string)$v['banco_financiamento']);
            $txt .= '• ' . frotaPrazoDescricaoVeiculo($v) . ($banco !== '' ? " — {$banco}" : '') . "\n";
        }
    }
    $base = rtrim((string)(getConfig('app_base_url') ?: 'https://sistema.fastcar.solutions'), '/');
    $txt .= "\nTotal em risco alto: {$riscoTotal}\n{$base}/admin/veiculos.php?prazo=risco";
    $enviado = false;
    $lista = array_filter(array_map('trim', explode(',', getConfig('notificacao_leads_whatsapp') ?: '')));
    foreach ($lista as $numero) {
        try { if (zapiEnviarTextoInterno($numero, $txt)) $enviado = true; } catch (Throwable $e) {}
    }

    return ['novos' => count($novos), 'risco_total' => $riscoTotal, 'enviado_whatsapp' => $enviado, 'inicial' => $inicial];
}
