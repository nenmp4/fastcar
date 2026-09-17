<?php
/** Categorias financeiras — CRUD simples (17/09/2026, módulo financeiro). */

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
            $tipo = in_array($_POST['tipo'] ?? '', ['receita', 'despesa'], true) ? $_POST['tipo'] : 'despesa';
            $icone = clean((string)($_POST['icone'] ?? '')) ?: '💰';
            $natureza = in_array($_POST['natureza_sugerida'] ?? '', ['fixa', 'variavel'], true) ? $_POST['natureza_sugerida'] : '';
            if (!$nome) {
                $erro = 'Nome é obrigatório.';
            } elseif ($id) {
                $db->prepare('UPDATE fin_categorias SET nome=?, tipo=?, icone=?, natureza_sugerida=? WHERE id=?')->execute([$nome, $tipo, $icone, $natureza, $id]);
                $sucesso = 'Categoria atualizada.';
            } else {
                $db->prepare('INSERT INTO fin_categorias (nome, tipo, icone, natureza_sugerida) VALUES (?,?,?,?)')->execute([$nome, $tipo, $icone, $natureza]);
                $sucesso = 'Categoria criada.';
            }
        } elseif ($acao === 'desativar') {
            $db->prepare('UPDATE fin_categorias SET ativo=0 WHERE id=?')->execute([(int)($_POST['id'] ?? 0)]);
            $sucesso = 'Categoria desativada.';
        } elseif ($acao === 'reativar') {
            $db->prepare('UPDATE fin_categorias SET ativo=1 WHERE id=?')->execute([(int)($_POST['id'] ?? 0)]);
            $sucesso = 'Categoria reativada.';
        }
    }
}

$categorias = $db->query('SELECT * FROM fin_categorias ORDER BY ativo DESC, tipo, nome')->fetchAll(PDO::FETCH_ASSOC);
$editando = null;
if (($_GET['action'] ?? '') === 'edit' && !empty($_GET['id'])) {
    $stmt = $db->prepare('SELECT * FROM fin_categorias WHERE id=?');
    $stmt->execute([(int)$_GET['id']]);
    $editando = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Categorias — Financeiro Fastcar</title>
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
  <h2><?= $editando ? '✏️ Editar categoria' : '➕ Nova categoria' ?></h2>
  <form method="POST">
    <?= csrfField() ?>
    <input type="hidden" name="acao" value="salvar">
    <input type="hidden" name="id" value="<?= (int)($editando['id'] ?? 0) ?>">
    <div class="grid-2">
      <div>
        <label>Nome</label>
        <input type="text" name="nome" required value="<?= e($editando['nome'] ?? '') ?>">
        <label>Tipo</label>
        <select name="tipo">
          <option value="despesa" <?= ($editando['tipo'] ?? 'despesa') === 'despesa' ? 'selected' : '' ?>>Despesa</option>
          <option value="receita" <?= ($editando['tipo'] ?? '') === 'receita' ? 'selected' : '' ?>>Receita</option>
        </select>
      </div>
      <div>
        <label>Ícone (emoji)</label>
        <input type="text" name="icone" value="<?= e($editando['icone'] ?? '💰') ?>" maxlength="4" style="max-width:80px">
        <label>Natureza sugerida</label>
        <select name="natureza_sugerida">
          <option value="">—</option>
          <option value="fixa" <?= ($editando['natureza_sugerida'] ?? '') === 'fixa' ? 'selected' : '' ?>>Fixa</option>
          <option value="variavel" <?= ($editando['natureza_sugerida'] ?? '') === 'variavel' ? 'selected' : '' ?>>Variável</option>
        </select>
      </div>
    </div>
    <button type="submit"><?= $editando ? 'Salvar' : 'Criar' ?></button>
    <?php if ($editando): ?><a href="/admin/financeiro-categorias.php">Cancelar</a><?php endif; ?>
  </form>
</div>

<div class="card">
  <h3>🏷️ Categorias (<?= count($categorias) ?>)</h3>
  <table class="tabela-oportunidades">
    <thead><tr><th>Nome</th><th>Tipo</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($categorias as $c): ?>
      <tr>
        <td><?= e($c['icone'] . ' ' . $c['nome']) ?></td>
        <td><?= $c['tipo'] === 'receita' ? '📥 Receita' : '📤 Despesa' ?></td>
        <td><?= $c['ativo'] ? '<span class="badge badge-ok">✅ ativa</span>' : '<span class="badge">⛔ inativa</span>' ?></td>
        <td style="white-space:nowrap">
          <a href="?action=edit&id=<?= (int)$c['id'] ?>">Editar</a>
          <?php if ($c['ativo']): ?>
            <form method="POST" style="display:inline"><?= csrfField() ?><input type="hidden" name="acao" value="desativar"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><button type="submit" style="background:none;border:none;cursor:pointer">Desativar</button></form>
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
