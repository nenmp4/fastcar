<?php
/** Colaboradores — CRUD simples (17/09/2026, módulo financeiro). */

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
        if ($acao === 'salvar') {
            $id = (int)($_POST['id'] ?? 0);
            $nome = clean((string)($_POST['nome'] ?? ''));
            $cargo = clean((string)($_POST['cargo'] ?? ''));
            $tipoVinculo = clean((string)($_POST['tipo_vinculo'] ?? 'clt'));
            $salario = (float)str_replace(',', '.', preg_replace('/[^\d,.-]/', '', (string)($_POST['salario_base'] ?? ''))) ?: null;
            $obs = clean((string)($_POST['observacoes'] ?? ''));
            if (!$nome) {
                $erro = 'Nome é obrigatório.';
            } elseif ($id) {
                $db->prepare("UPDATE fin_colaboradores SET nome=?, cargo=?, tipo_vinculo=?, salario_base=?, observacoes=?, updated_at=datetime('now','localtime') WHERE id=?")->execute([$nome, $cargo, $tipoVinculo, $salario, $obs, $id]);
                $sucesso = 'Colaborador atualizado.';
            } else {
                $db->prepare('INSERT INTO fin_colaboradores (nome, cargo, tipo_vinculo, salario_base, observacoes) VALUES (?,?,?,?,?)')->execute([$nome, $cargo, $tipoVinculo, $salario, $obs]);
                $sucesso = 'Colaborador criado.';
            }
        } elseif ($acao === 'inativar') {
            $db->prepare("UPDATE fin_colaboradores SET status='inativo' WHERE id=?")->execute([(int)($_POST['id'] ?? 0)]);
            $sucesso = 'Colaborador inativado.';
        } elseif ($acao === 'reativar') {
            $db->prepare("UPDATE fin_colaboradores SET status='ativo' WHERE id=?")->execute([(int)($_POST['id'] ?? 0)]);
            $sucesso = 'Colaborador reativado.';
        }
    }
}

$colaboradores = $db->query("SELECT * FROM fin_colaboradores ORDER BY (status='ativo') DESC, nome")->fetchAll(PDO::FETCH_ASSOC);
$editando = null;
if (($_GET['action'] ?? '') === 'edit' && !empty($_GET['id'])) {
    $stmt = $db->prepare('SELECT * FROM fin_colaboradores WHERE id=?');
    $stmt->execute([(int)$_GET['id']]);
    $editando = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Colaboradores — Financeiro Fastcar</title>
<link rel="stylesheet" href="/admin/assets/style.css?v=<?= @filemtime(__DIR__ . '/assets/style.css') ?: 1 ?>">
<?php include __DIR__ . '/_pwa_head.php'; ?>
</head>
<body>
<header class="topbar">
    <a href="/admin/financeiro.php" style="color:#fff">← Voltar</a>
    <strong><img class="topbar-logo" src="/admin/assets/img/icon-192.png" alt="Fastcar" onerror="this.style.display='none'"> Fast<b>Car</b> <span class="crm-tag">CRM</span></strong>
    <span>Olá, <?= e($_SESSION['admin_nome']) ?></span>
    <a href="/admin/logout.php">Sair</a>
</header>
<main>
<?php if ($erro): ?><div class="alerta-erro"><?= e($erro) ?></div><?php endif; ?>
<?php if ($sucesso): ?><div class="alerta-sucesso"><?= e($sucesso) ?></div><?php endif; ?>

<div class="card" style="margin-bottom:1.5rem">
  <h2><?= $editando ? '✏️ Editar colaborador' : '➕ Novo colaborador' ?></h2>
  <form method="POST">
    <?= csrfField() ?>
    <input type="hidden" name="acao" value="salvar">
    <input type="hidden" name="id" value="<?= (int)($editando['id'] ?? 0) ?>">
    <div class="grid-2">
      <div>
        <label>Nome</label>
        <input type="text" name="nome" required value="<?= e($editando['nome'] ?? '') ?>">
        <label>Cargo</label>
        <input type="text" name="cargo" value="<?= e($editando['cargo'] ?? '') ?>">
      </div>
      <div>
        <label>Vínculo</label>
        <select name="tipo_vinculo">
          <?php foreach (['clt' => 'CLT', 'pj' => 'PJ', 'socio' => 'Sócio', 'autonomo' => 'Autônomo'] as $k => $lbl): ?>
            <option value="<?= $k ?>" <?= ($editando['tipo_vinculo'] ?? 'clt') === $k ? 'selected' : '' ?>><?= $lbl ?></option>
          <?php endforeach; ?>
        </select>
        <label>Salário/pró-labore base (R$)</label>
        <input type="text" name="salario_base" value="<?= e((string)($editando['salario_base'] ?? '')) ?>" placeholder="0,00">
      </div>
    </div>
    <label>Observações</label>
    <textarea name="observacoes" rows="2"><?= e($editando['observacoes'] ?? '') ?></textarea>
    <button type="submit"><?= $editando ? 'Salvar' : 'Criar' ?></button>
    <?php if ($editando): ?><a href="/admin/financeiro-colaboradores.php">Cancelar</a><?php endif; ?>
  </form>
</div>

<div class="card">
  <h3>👥 Colaboradores (<?= count($colaboradores) ?>)</h3>
  <table class="tabela-oportunidades">
    <thead><tr><th>Nome</th><th>Cargo</th><th>Vínculo</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($colaboradores as $c): ?>
      <tr>
        <td><?= e($c['nome']) ?></td>
        <td><?= e($c['cargo']) ?></td>
        <td><?= e(strtoupper($c['tipo_vinculo'])) ?></td>
        <td><?= $c['status'] === 'ativo' ? '<span class="badge badge-ok">✅ ativo</span>' : '<span class="badge">⛔ inativo</span>' ?></td>
        <td style="white-space:nowrap">
          <a href="?action=edit&id=<?= (int)$c['id'] ?>">Editar</a>
          <?php if ($c['status'] === 'ativo'): ?>
            <form method="POST" style="display:inline"><?= csrfField() ?><input type="hidden" name="acao" value="inativar"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><button type="submit" style="background:none;border:none;cursor:pointer">Inativar</button></form>
          <?php else: ?>
            <form method="POST" style="display:inline"><?= csrfField() ?><input type="hidden" name="acao" value="reativar"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><button type="submit" style="background:none;border:none;cursor:pointer">Reativar</button></form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
</main>
<?php include __DIR__ . '/_pwa_register.php'; ?>
<?php include __DIR__ . '/_notify.php'; ?>
</body>
</html>
