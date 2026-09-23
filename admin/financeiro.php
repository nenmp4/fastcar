<?php
/**
 * Financeiro — dashboard (17/09/2026, pedido José/Jean: "tem modulo
 * financeiro no iab boutique - precisamos copia de la para colocar aqui
 * criar perfil gestão financeira subir os lançamentos"). Portado do repo
 * irmão JurídicoSaaS, adaptado pro modelo de negócio Fastcar — ver nota
 * grande em install/schema.sql pras diferenças de propósito.
 */

require_once __DIR__ . '/_bootstrap.php';
requireAcessoFinanceiro();

$db = getDB();
finRecalcularAtrasados();

$mesRef = (string)($_GET['mes'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $mesRef)) $mesRef = date('Y-m');
$inicioMes = $mesRef . '-01';
$fimMes = date('Y-m-t', strtotime($inicioMes));

function finSoma(PDO $db, string $tipo, string $inicio, string $fim, ?string $natureza = null): float {
    $sql = "SELECT COALESCE(SUM(valor),0) FROM fin_lancamentos
            WHERE tipo=? AND status != 'cancelado'
              AND COALESCE(data_pagamento, data_vencimento) BETWEEN ? AND ?";
    $params = [$tipo, $inicio, $fim];
    if ($natureza) { $sql .= " AND natureza=?"; $params[] = $natureza; }
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return (float)$stmt->fetchColumn();
}

$totalReceitas = finSoma($db, 'receita', $inicioMes, $fimMes);
$totalDespesas = finSoma($db, 'despesa', $inicioMes, $fimMes);
$totalDespesasFixas = finSoma($db, 'despesa', $inicioMes, $fimMes, 'fixa');
$totalDespesasVariaveis = finSoma($db, 'despesa', $inicioMes, $fimMes, 'variavel');
$saldo = $totalReceitas - $totalDespesas;

$hoje = date('Y-m-d');
$contasVencer7 = $db->prepare("
    SELECT l.*, c.nome as categoria_nome, c.icone
    FROM fin_lancamentos l LEFT JOIN fin_categorias c ON c.id=l.categoria_id
    WHERE l.status='pendente' AND l.data_vencimento BETWEEN ? AND ?
    ORDER BY l.data_vencimento ASC LIMIT 20
");
$contasVencer7->execute([$hoje, date('Y-m-d', strtotime('+7 days'))]);
$contasVencer7 = $contasVencer7->fetchAll(PDO::FETCH_ASSOC);

$contasAtrasadas = (int)$db->query("SELECT COUNT(*) FROM fin_lancamentos WHERE status='atrasado'")->fetchColumn();
$asaasPendenteImportar = asaasConfigured();
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Financeiro — Fastcar CRM</title>
<link rel="stylesheet" href="/admin/assets/style.css?v=<?= @filemtime(__DIR__ . '/assets/style.css') ?: 1 ?>">
<link rel="stylesheet" href="/admin/assets/mobile.css?v=<?= @filemtime(__DIR__ . '/assets/mobile.css') ?: 1 ?>">
<script src="/admin/assets/mobile.js?v=<?= @filemtime(__DIR__ . '/assets/mobile.js') ?: 1 ?>" defer></script>
<?php include __DIR__ . '/_pwa_head.php'; ?>
</head>
<body>
<header class="topbar">
    <a href="/admin/index.php" style="color:#fff">← Voltar</a>
    <strong><img class="topbar-logo" src="/admin/assets/img/icon-192.png" alt="Fastcar" onerror="this.style.display='none'"> Fast<b>Car</b></strong>
    <span>Olá, <?= e($_SESSION['admin_nome']) ?></span>
    <a href="/admin/meu_perfil.php">🙋 Meu perfil</a>
    <a href="/admin/logout.php">Sair</a>
</header>
<main>

<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:1rem;margin-bottom:1.5rem">
  <h1 style="margin:0">🧾 Financeiro</h1>
  <div style="display:flex;gap:.5rem;flex-wrap:wrap">
    <a href="/admin/financeiro-lancamentos.php" class="btn-primary" style="width:auto">➕ Novo lançamento</a>
    <a href="/admin/financeiro-relatorios.php" class="btn" style="width:auto;background:#6d28d9;color:#fff;border:none">📊 Relatórios / DRE</a>
    <a href="/admin/financeiro-categorias.php" class="btn" style="width:auto">🏷️ Categorias</a>
    <a href="/admin/financeiro-fornecedores.php" class="btn" style="width:auto">🏭 Fornecedores</a>
    <a href="/admin/financeiro-colaboradores.php" class="btn" style="width:auto">👥 Colaboradores</a>
    <a href="/admin/financeiro-asaas.php" class="btn" style="width:auto">🔄 Asaas</a>
    <a href="/admin/clientes.php" class="btn" style="width:auto">🙋 Clientes</a>
    <a href="/admin/financeiro_inbox.php" class="btn" style="width:auto">💬 WhatsApp Cobrança</a>
  </div>
</div>

<?php if (!$asaasPendenteImportar): ?>
<div class="alerta-info">ℹ️ Integração com Asaas ainda não configurada — <a href="/admin/configuracoes.php">configure a chave da API</a> pra importar clientes/cobranças de lá.</div>
<?php endif; ?>

<form method="GET" class="card" style="margin-bottom:1.5rem">
  <label style="font-weight:600;margin-right:.5rem">Período:</label>
  <input type="month" name="mes" value="<?= e($mesRef) ?>" onchange="this.form.submit()">
</form>

<div class="grid-cards" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1rem;margin-bottom:1.5rem">
  <a class="card" href="/admin/financeiro-lancamentos.php?tipo=receita&de=<?= e($inicioMes) ?>&ate=<?= e($fimMes) ?>" style="display:block;color:inherit;text-decoration:none;border-top:4px solid #16a34a">
    <div style="font-size:.8rem;color:var(--muted);font-weight:600">📥 Receitas do mês</div>
    <div style="font-size:1.6rem;font-weight:800;color:#16a34a">R$ <?= number_format($totalReceitas, 2, ',', '.') ?></div>
  </a>
  <a class="card" href="/admin/financeiro-lancamentos.php?tipo=despesa&de=<?= e($inicioMes) ?>&ate=<?= e($fimMes) ?>" style="display:block;color:inherit;text-decoration:none;border-top:4px solid #dc2626">
    <div style="font-size:.8rem;color:var(--muted);font-weight:600">📤 Despesas do mês</div>
    <div style="font-size:1.6rem;font-weight:800;color:#dc2626">R$ <?= number_format($totalDespesas, 2, ',', '.') ?></div>
  </a>
  <a class="card" href="/admin/financeiro-lancamentos.php?tipo=despesa&natureza=fixa&de=<?= e($inicioMes) ?>&ate=<?= e($fimMes) ?>" style="display:block;color:inherit;text-decoration:none;border-top:4px solid #b45309">
    <div style="font-size:.8rem;color:var(--muted);font-weight:600">📌 Despesas fixas do mês</div>
    <div style="font-size:1.6rem;font-weight:800;color:#b45309">R$ <?= number_format($totalDespesasFixas, 2, ',', '.') ?></div>
  </a>
  <a class="card" href="/admin/financeiro-lancamentos.php?tipo=despesa&natureza=variavel&de=<?= e($inicioMes) ?>&ate=<?= e($fimMes) ?>" style="display:block;color:inherit;text-decoration:none;border-top:4px solid #7c3aed">
    <div style="font-size:.8rem;color:var(--muted);font-weight:600">📊 Despesas variáveis do mês</div>
    <div style="font-size:1.6rem;font-weight:800;color:#7c3aed">R$ <?= number_format($totalDespesasVariaveis, 2, ',', '.') ?></div>
  </a>
  <div class="card" style="border-top:4px solid <?= $saldo >= 0 ? '#16a34a' : '#dc2626' ?>">
    <div style="font-size:.8rem;color:var(--muted);font-weight:600">Saldo do mês</div>
    <div style="font-size:1.6rem;font-weight:800;color:<?= $saldo >= 0 ? '#16a34a' : '#dc2626' ?>">R$ <?= number_format($saldo, 2, ',', '.') ?></div>
  </div>
  <a class="card" href="/admin/financeiro-lancamentos.php?status=atrasado&todos_periodos=1" style="display:block;color:inherit;text-decoration:none;border-top:4px solid <?= $contasAtrasadas > 0 ? '#dc2626' : '#94a3b8' ?>">
    <div style="font-size:.8rem;color:var(--muted);font-weight:600">⏰ Contas atrasadas</div>
    <div style="font-size:1.6rem;font-weight:800"><?= $contasAtrasadas ?></div>
  </a>
</div>

<div class="card">
  <h2 style="margin-bottom:1rem">⏰ Contas a vencer nos próximos 7 dias</h2>
  <?php if (!$contasVencer7): ?>
    <p style="color:var(--muted);font-size:.85rem">Nenhuma conta a vencer nos próximos 7 dias.</p>
  <?php else: ?>
    <table class="tabela-oportunidades">
      <thead><tr><th>Vencimento</th><th>Descrição</th><th>Categoria</th><th>Valor</th></tr></thead>
      <tbody>
      <?php foreach ($contasVencer7 as $c): ?>
        <tr>
          <td><?= date('d/m', strtotime($c['data_vencimento'])) ?></td>
          <td><a href="/admin/financeiro-lancamentos.php?action=edit&id=<?= (int)$c['id'] ?>"><?= e($c['descricao']) ?></a></td>
          <td><?= e(($c['icone'] ?? '') . ' ' . ($c['categoria_nome'] ?? '—')) ?></td>
          <td style="font-weight:600"><?= $c['tipo'] === 'receita' ? '+' : '-' ?> R$ <?= number_format((float)$c['valor'], 2, ',', '.') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

</main>
<?php include __DIR__ . '/_pwa_register.php'; ?>
<?php include __DIR__ . '/_notify.php'; ?>
<?php include __DIR__ . '/_zapi_status.php'; ?>
</body>
</html>
