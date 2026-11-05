<?php
/**
 * 05/11/2026, "consultores estão alegando que leads estão sumindo" — mostra
 * pra onde foi cada lead que saiu da carteira de um consultor: passou pra
 * outro consultor (redistribuição/equalização/falta/reatribuição/troca no
 * card "Próxima ação"), foi movido pra Vendas (IA ou manual), ou foi
 * encerrado (sem resposta por 7 dias, sem perfil, perdido). Lê só
 * oportunidade_historico — nunca altera nada.
 *
 * Consultor vê só o que envolve ele (lead dele hoje, ou histórico que cita
 * o nome dele); super admin/supervisor escolhe o consultor ou vê todos.
 */
require_once __DIR__ . '/_bootstrap.php';

$db = getDB();
$perfil = (string)($_SESSION['admin_perfil'] ?? '');
$meuId = (int)$_SESSION['admin_id'];
$veTudo = perfilVeTudo();

$dias = (int)($_GET['dias'] ?? 15);
if (!in_array($dias, [3, 7, 15, 30, 60], true)) $dias = 15;
$busca = trim((string)($_GET['q'] ?? ''));
$tipoFiltro = (string)($_GET['tipo'] ?? '');

$consultores = $db->query("SELECT id, nome FROM usuarios WHERE perfil IN ('consultor','super_admin') ORDER BY nome")->fetchAll();
$alvoId = $veTudo ? (int)($_GET['consultor'] ?? 0) : $meuId;
$alvoNome = '';
if ($alvoId) {
    $st = $db->prepare("SELECT nome FROM usuarios WHERE id = ?");
    $st->execute([$alvoId]);
    $alvoNome = (string)$st->fetchColumn();
    if ($alvoNome === '') $alvoId = 0;
}

const RASTREIO_TIPOS = [
    'outro_consultor' => '🔀 Passou pra outro consultor',
    'vendas' => '🛒 Movido pra Vendas',
    'sem_resposta' => '🔇 Encerrado: sem resposta',
    'encerrado' => '❌ Encerrado (perdido/sem perfil)',
    'atribuido' => '📥 Recebido / atribuído',
];

function rastreioTipo(array $h): string {
    $obs = (string)$h['observacao'];
    if (in_array($h['etapa_nova'], ['perdido', 'sem_perfil'], true)) {
        if (stripos($obs, 'encaminhado pra vendas') !== false) return 'vendas';
        if (stripos($obs, 'não respondeu') !== false) return 'sem_resposta';
        return 'encerrado';
    }
    if (preg_match('/(de .+ (pra|para) )|Responsável alterado|Reatribuído|ausente/iu', $obs)) return 'outro_consultor';
    return 'atribuido';
}

$where = ["h.created_at >= datetime('now','localtime', ?)",
    "(h.etapa_nova IN ('perdido','sem_perfil') OR h.observacao LIKE '%Redistribu%' OR h.observacao LIKE '%Equaliza%'
      OR h.observacao LIKE '%Reatribu%' OR h.observacao LIKE '%Responsável alterado%' OR h.observacao LIKE '%Abertura da fila%')"];
$params = ["-{$dias} days"];
if ($alvoId) {
    // Lead que é dele hoje, ou evento que cita o nome dele (ex: "de Fulano pra Beltrano").
    $where[] = "(o.responsavel_id = ? OR h.observacao LIKE ?)";
    $params[] = $alvoId;
    $params[] = '%' . $alvoNome . '%';
}
if ($busca !== '') {
    $tel = preg_replace('/\D/', '', $busca);
    $where[] = "(c.nome LIKE ? OR o.veiculo_modelo LIKE ? OR o.veiculo_placa LIKE ?" . ($tel !== '' ? " OR c.telefone LIKE ?" : '') . ")";
    array_push($params, "%{$busca}%", "%{$busca}%", "%{$busca}%");
    if ($tel !== '') $params[] = "%{$tel}%";
}
$st = $db->prepare("
    SELECT h.id AS hid, h.oportunidade_id, h.etapa_anterior, h.etapa_nova, h.observacao, h.created_at,
           o.etapa AS etapa_atual, o.veiculo_marca, o.veiculo_modelo, o.motivo_perda,
           c.nome AS cliente_nome, c.telefone,
           ua.nome AS responsavel_atual, ux.nome AS feito_por
    FROM oportunidade_historico h
    JOIN oportunidades o ON o.id = h.oportunidade_id
    JOIN clientes c ON c.id = o.cliente_id
    LEFT JOIN usuarios ua ON ua.id = o.responsavel_id
    LEFT JOIN usuarios ux ON ux.id = h.responsavel_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY h.created_at DESC, h.id DESC
    LIMIT 500
");
$st->execute($params);
$eventos = [];
$contagem = array_fill_keys(array_keys(RASTREIO_TIPOS), 0);
foreach ($st->fetchAll() as $h) {
    $h['_tipo'] = rastreioTipo($h);
    // Pro consultor, "recebido" não é sumiço — só mostra se ele pediu esse filtro.
    $contagem[$h['_tipo']]++;
    if ($tipoFiltro !== '' && $h['_tipo'] !== $tipoFiltro) continue;
    if ($tipoFiltro === '' && $h['_tipo'] === 'atribuido') continue;
    $eventos[] = $h;
}
$qs = static fn(array $mudar) => '?' . http_build_query(array_merge($_GET, $mudar));
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Rastreio de leads — Fastcar CRM</title>
<link rel="stylesheet" href="/admin/assets/style.css?v=<?= @filemtime(__DIR__ . '/assets/style.css') ?: 1 ?>">
<link rel="stylesheet" href="/admin/assets/mobile.css?v=<?= @filemtime(__DIR__ . '/assets/mobile.css') ?: 1 ?>">
<script src="/admin/assets/mobile.js?v=<?= @filemtime(__DIR__ . '/assets/mobile.js') ?: 1 ?>" defer></script>
<?php include __DIR__ . '/_pwa_head.php'; ?>
</head>
<body>
<?php include __DIR__ . '/_impersonando_banner.php'; ?>
<?php include __DIR__ . '/_topbar.php'; ?>

<main>
<div class="card">
    <h2>🔎 Rastreio de leads — pra onde foram</h2>
    <p><small>Mostra os leads que saíram da carteira<?= $alvoId ? ' de <strong>' . e($alvoNome) . '</strong>' : '' ?> nos últimos <?= $dias ?> dias e o motivo:
        passou pra outro consultor, foi movido pra Vendas (cliente queria comprar um carro) ou foi encerrado
        (inclusive automático por 7 dias sem resposta). Nada some sem deixar registro aqui.</small></p>
    <form method="get" class="grid-2" style="align-items:end">
        <div>
            <label>Buscar (nome, telefone, modelo ou placa)</label>
            <input type="text" name="q" value="<?= e($busca) ?>" placeholder="Ex: Maria ou 11999...">
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:end">
            <?php if ($veTudo): ?>
                <div><label>Consultor</label>
                <select name="consultor">
                    <option value="0">— todos —</option>
                    <?php foreach ($consultores as $c): ?>
                        <option value="<?= (int)$c['id'] ?>" <?= $alvoId === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['nome']) ?></option>
                    <?php endforeach; ?>
                </select></div>
            <?php endif; ?>
            <div><label>Período</label>
            <select name="dias">
                <?php foreach ([3, 7, 15, 30, 60] as $d): ?><option value="<?= $d ?>" <?= $d === $dias ? 'selected' : '' ?>>últimos <?= $d ?> dias</option><?php endforeach; ?>
            </select></div>
            <input type="hidden" name="tipo" value="<?= e($tipoFiltro) ?>">
            <button type="submit" style="width:auto;margin-top:0">Filtrar</button>
        </div>
    </form>
</div>

<nav class="etapas-nav">
    <a href="<?= e($qs(['tipo' => ''])) ?>" class="<?= $tipoFiltro === '' ? 'ativo' : '' ?>">Todas as saídas (<?= array_sum($contagem) - $contagem['atribuido'] ?>)</a>
    <?php foreach (RASTREIO_TIPOS as $k => $rot): ?>
        <a href="<?= e($qs(['tipo' => $k])) ?>" class="<?= $tipoFiltro === $k ? 'ativo' : '' ?>"><?= e($rot) ?> (<?= $contagem[$k] ?>)</a>
    <?php endforeach; ?>
</nav>

<div class="card">
<?php if (!$eventos): ?>
    <p>Nenhuma movimentação nesse período<?= $busca !== '' ? ' pra essa busca' : '' ?>.</p>
<?php else: ?>
    <table class="tabela-oportunidades">
        <thead><tr><th>Cliente</th><th>Quando</th><th>O que aconteceu</th><th>Detalhe</th><th>Hoje está com</th><th>Etapa atual</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($eventos as $h): ?>
            <tr>
                <td><strong><?= e($h['cliente_nome'] ?: '(sem nome)') ?></strong><br><small><?= e($h['telefone']) ?><?= trim($h['veiculo_marca'] . ' ' . $h['veiculo_modelo']) !== '' ? ' · ' . e(trim($h['veiculo_marca'] . ' ' . $h['veiculo_modelo'])) : '' ?></small></td>
                <td><?= e(date('d/m/Y H:i', strtotime($h['created_at']))) ?></td>
                <td><?= e(RASTREIO_TIPOS[$h['_tipo']]) ?></td>
                <td style="overflow-wrap:anywhere"><small><?= e($h['observacao'] ?: '—') ?><?= $h['feito_por'] ? ' — por ' . e($h['feito_por']) : ($h['_tipo'] !== 'encerrado' ? ' — automático' : '') ?></small></td>
                <td><?= e($h['responsavel_atual'] ?: '— sem responsável —') ?></td>
                <td><span class="badge <?= e(etapaBadgeClasse($h['etapa_atual'])) ?>"><?= e(etapaLabel($h['etapa_atual'])) ?></span></td>
                <td><a class="btn" href="/admin/oportunidade.php?id=<?= (int)$h['oportunidade_id'] ?>">Abrir</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php if (count($eventos) >= 500): ?><p><small>Mostrando os 500 mais recentes — use a busca ou um período menor.</small></p><?php endif; ?>
<?php endif; ?>
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
