<?php
/**
 * Integração Asaas — importar/sincronizar clientes e cobranças já
 * existentes no Asaas ("puxar tudo de lá") + vincular manualmente cada
 * cliente importado a um cliente/venda do CRM (nunca automático — ver nota
 * em install/schema.sql). A chave da API é configurada em
 * admin/configuracoes.php, não aqui.
 */

require_once __DIR__ . '/_bootstrap.php';
requireAcessoFinanceiro();

$db = getDB();
$erro = '';
$sucesso = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        $erro = 'Sessão expirada, recarregue a página e tente de novo.';
    } else {
        $acao = (string)($_POST['acao'] ?? '');
        if ($acao === 'importar_clientes') {
            $r = asaasImportarClientes();
            $sucesso = $r['ok'] ? "✅ {$r['importados']} cliente(s) importado(s)/atualizado(s)." : null;
            $erro = $r['ok'] ? '' : ('Falha ao importar clientes: ' . $r['erro']);
        } elseif ($acao === 'importar_cobrancas') {
            $r = asaasImportarCobrancas();
            $sucesso = $r['ok'] ? "✅ {$r['novos']} cobrança(s) nova(s), {$r['atualizados']} atualizada(s)." : null;
            $erro = $r['ok'] ? '' : ('Falha ao importar cobranças: ' . $r['erro']);
        } elseif ($acao === 'vincular') {
            $id = (int)($_POST['id'] ?? 0);
            $clienteId = (int)($_POST['cliente_id'] ?? 0) ?: null;
            $vendaId = (int)($_POST['venda_id'] ?? 0) ?: null;
            $db->prepare("UPDATE fin_asaas_clientes SET cliente_id=?, venda_id=?, updated_at=datetime('now','localtime') WHERE id=?")->execute([$clienteId, $vendaId, $id]);
            // Propaga o vínculo pros lançamentos já importados desse cliente Asaas,
            // pra não precisar linkar cobrança por cobrança na tela de lançamentos.
            $asaasId = $db->prepare("SELECT asaas_id FROM fin_asaas_clientes WHERE id=?");
            $asaasId->execute([$id]);
            $asaasIdVal = $asaasId->fetchColumn();
            if ($asaasIdVal) {
                $db->prepare("UPDATE fin_lancamentos SET cliente_id=?, venda_id=? WHERE asaas_customer_id=?")->execute([$clienteId, $vendaId, $asaasIdVal]);
            }
            $sucesso = 'Vínculo salvo.';
        }
    }
}

$clientesAsaas = $db->query("
    SELECT ac.*, cl.nome as cliente_vinculado_nome, v.comprador_nome as venda_vinculada_nome
    FROM fin_asaas_clientes ac
    LEFT JOIN clientes cl ON cl.id = ac.cliente_id
    LEFT JOIN vendas v ON v.id = ac.venda_id
    ORDER BY (ac.cliente_id IS NULL AND ac.venda_id IS NULL) DESC, ac.nome
")->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Asaas — Financeiro Fastcar</title>
<link rel="stylesheet" href="/admin/assets/style.css?v=<?= @filemtime(__DIR__ . '/assets/style.css') ?: 1 ?>">
<link rel="stylesheet" href="/admin/assets/mobile.css?v=<?= @filemtime(__DIR__ . '/assets/mobile.css') ?: 1 ?>">
<script src="/admin/assets/mobile.js?v=<?= @filemtime(__DIR__ . '/assets/mobile.js') ?: 1 ?>" defer></script>
<?php include __DIR__ . '/_pwa_head.php'; ?>
</head>
<body>
<header class="topbar">
    <a href="/admin/financeiro.php" style="color:#fff">← Voltar</a>
    <strong><img class="topbar-logo" src="/admin/assets/img/icon-192.png" alt="Fastcar" onerror="this.style.display='none'"> Fast<b>Car</b></strong>
    <span>Olá, <?= e($_SESSION['admin_nome']) ?></span>
    <a href="/admin/logout.php">Sair</a>
</header>
<main>
<?php if ($erro): ?><div class="alerta-erro"><?= e($erro) ?></div><?php endif; ?>
<?php if ($sucesso): ?><div class="alerta-sucesso"><?= e($sucesso) ?></div><?php endif; ?>

<div class="card" style="margin-bottom:1.5rem">
  <h2>🔄 Asaas</h2>
  <?php if (!asaasConfigured()): ?>
    <p>⏳ Chave da API Asaas ainda não configurada — <a href="/admin/configuracoes.php">configure aqui</a> antes de importar.</p>
  <?php else: ?>
    <p>Importa clientes e cobranças já cadastrados no Asaas pro financeiro do Fastcar. Rodar de novo é seguro — cobranças já importadas são só atualizadas (status/valor), nunca duplicadas.</p>
    <form method="POST" style="display:inline-block;margin-right:.5rem">
      <?= csrfField() ?>
      <input type="hidden" name="acao" value="importar_clientes">
      <button type="submit">👥 Importar clientes do Asaas</button>
    </form>
    <form method="POST" style="display:inline-block">
      <?= csrfField() ?>
      <input type="hidden" name="acao" value="importar_cobrancas">
      <button type="submit">💳 Importar cobranças do Asaas</button>
    </form>
  <?php endif; ?>
</div>

<div class="card">
  <h3>👥 Clientes Asaas (<?= count($clientesAsaas) ?>) — vincular a um cliente/venda do CRM</h3>
  <p><small>O vínculo é sempre manual — o sistema nunca decide sozinho qual cliente/venda bate com um cliente do Asaas, mesmo se o nome parecer óbvio.</small></p>
  <table class="tabela-oportunidades">
    <thead><tr><th>Nome (Asaas)</th><th>CPF/CNPJ</th><th>Telefone</th><th>Vínculo</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($clientesAsaas as $ac): ?>
      <tr>
        <td><?= e($ac['nome']) ?></td>
        <td><?= e($ac['cpf_cnpj']) ?></td>
        <td><?= e($ac['telefone']) ?></td>
        <td>
          <?php if ($ac['cliente_vinculado_nome']): ?>
            👤 <?= e($ac['cliente_vinculado_nome']) ?> (cliente #<?= (int)$ac['cliente_id'] ?>)
          <?php elseif ($ac['venda_vinculada_nome']): ?>
            💰 <?= e($ac['venda_vinculada_nome']) ?> (venda #<?= (int)$ac['venda_id'] ?>)
          <?php else: ?>
            <span style="color:var(--muted)">— ainda não vinculado —</span>
          <?php endif; ?>
        </td>
        <td>
          <form method="POST" style="display:flex;gap:.35rem;align-items:center">
            <?= csrfField() ?>
            <input type="hidden" name="acao" value="vincular">
            <input type="hidden" name="id" value="<?= (int)$ac['id'] ?>">
            <input type="number" name="cliente_id" placeholder="cliente #" value="<?= (int)($ac['cliente_id'] ?? 0) ?: '' ?>" style="width:90px">
            <input type="number" name="venda_id" placeholder="venda #" value="<?= (int)($ac['venda_id'] ?? 0) ?: '' ?>" style="width:90px">
            <button type="submit" style="width:auto">Salvar</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$clientesAsaas): ?><tr><td colspan="5" style="text-align:center;color:var(--muted)">Nenhum cliente importado ainda.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
</main>
<?php include __DIR__ . '/_pwa_register.php'; ?>
<?php include __DIR__ . '/_notify.php'; ?>
<?php include __DIR__ . '/_zapi_status.php'; ?>
</body>
</html>
