<?php
/** Categorias financeiras — CRUD simples (17/09/2026, módulo financeiro). */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../includes/financeiro_dre.php';
requireAcessoFinanceiro();

$db = getDB();
$erro = '';
$sucesso = '';
// 19/09/2026, "dre para enviar para contabiidade queremos exatamente
// nessa pegada" — `grupo_dre` já existia na coluna desde a criação do
// módulo financeiro (17/09/2026, pré-semeado nas categorias padrão), mas
// esta tela nunca deixava ver/editar o campo — só dava pra corrigir direto
// no banco. `finGruposDreComRotulos()` (includes/financeiro_dre.php)
// centraliza a lista, mesma usada pelo gerador do relatório.
$gruposDre = finGruposDreComRotulos();

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
            $grupoDre = array_key_exists($_POST['grupo_dre'] ?? '', $gruposDre)
                ? $_POST['grupo_dre']
                : ($tipo === 'receita' ? 'receita' : 'outras');
            if (!$nome) {
                $erro = 'Nome é obrigatório.';
            } elseif ($id) {
                $db->prepare('UPDATE fin_categorias SET nome=?, tipo=?, icone=?, natureza_sugerida=?, grupo_dre=? WHERE id=?')->execute([$nome, $tipo, $icone, $natureza, $grupoDre, $id]);
                $sucesso = 'Categoria atualizada.';
            } else {
                $db->prepare('INSERT INTO fin_categorias (nome, tipo, icone, natureza_sugerida, grupo_dre) VALUES (?,?,?,?,?)')->execute([$nome, $tipo, $icone, $natureza, $grupoDre]);
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
        <label>Grupo no DRE</label>
        <select name="grupo_dre">
          <?php foreach ($gruposDre as $k => $lbl): ?>
            <option value="<?= e($k) ?>" <?= ($editando['grupo_dre'] ?? '') === $k ? 'selected' : '' ?>><?= e($lbl) ?></option>
          <?php endforeach; ?>
        </select>
        <small style="color:var(--muted)">Usado só pelo relatório DRE Gerencial (Financeiro → Relatórios) pra agrupar as categorias em blocos com subtotal.</small>
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
        <td>
          <?= e($c['icone'] . ' ' . $c['nome']) ?>
          <?php if (!empty($c['grupo_dre']) && isset($gruposDre[$c['grupo_dre']])): ?>
            <br><small style="color:#6d28d9">📊 <?= e($gruposDre[$c['grupo_dre']]) ?></small>
          <?php endif; ?>
        </td>
        <td><?= $c['tipo'] === 'receita' ? '📥 Receita' : '📤 Despesa' ?></td>
        <td><?= $c['ativo'] ? '<span class="badge badge-ok">✅ ativa</span>' : '<span class="badge">⛔ inativa</span>' ?></td>
        <td style="white-space:nowrap">
          <a href="?action=edit&id=<?= (int)$c['id'] ?>">Editar</a>
          <?php if ($c['ativo']): ?>
            <form method="POST" style="display:inline"><?= csrfField() ?><input type="hidden" name="acao" value="desativar"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><button type="submit" class="btn-texto perigo">Desativar</button></form>
          <?php else: ?>
            <form method="POST" style="display:inline"><?= csrfField() ?><input type="hidden" name="acao" value="reativar"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><button type="submit" class="btn-texto">Reativar</button></form>
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
<?php include __DIR__ . '/_zapi_status.php'; ?>
</body>
</html>
