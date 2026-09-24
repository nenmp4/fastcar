<?php
/**
 * Patrimônio da empresa — cadastro de bens fixos (imóvel, informática,
 * mobiliário, outros). Restrito ao super_admin, mesma trava de
 * admin/veiculos.php/admin/backup.php. Ver includes/patrimonio.php pro
 * racional completo (por que é separado da Frota e do Financeiro).
 */

require_once __DIR__ . '/_bootstrap.php';
requireSuperAdmin();

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
            $categoria = array_key_exists($_POST['categoria'] ?? '', PATRIMONIO_CATEGORIAS) ? $_POST['categoria'] : 'outros';
            $nome = clean((string)($_POST['nome'] ?? ''));
            $valorBruto = trim((string)($_POST['valor_aquisicao'] ?? ''));
            $valor = $valorBruto === '' ? null : (float)str_replace(',', '.', preg_replace('/[^\d,.-]/', '', $valorBruto));
            $dataAquisicao = trim((string)($_POST['data_aquisicao'] ?? '')) ?: null;
            $local = clean((string)($_POST['local_responsavel'] ?? ''));
            $status = array_key_exists($_POST['status'] ?? '', PATRIMONIO_STATUS) ? $_POST['status'] : 'ativo';
            $obs = clean((string)($_POST['observacoes'] ?? ''));

            if (!$nome) {
                $erro = 'Nome/descrição do bem é obrigatório.';
            } else {
                $dados = [
                    'categoria' => $categoria,
                    'nome' => $nome,
                    'valor_aquisicao' => $valor,
                    'data_aquisicao' => $dataAquisicao,
                    'local_responsavel' => $local,
                    'status' => $status,
                    'observacoes' => $obs,
                    'created_by' => (int)($_SESSION['admin_id'] ?? 0),
                ];
                if ($id) {
                    atualizarItemPatrimonio($id, $dados);
                    $sucesso = 'Item atualizado.';
                } else {
                    criarItemPatrimonio($dados);
                    $sucesso = 'Item cadastrado.';
                }
            }
        } elseif ($acao === 'excluir') {
            $id = (int)($_POST['id'] ?? 0);
            excluirItemPatrimonio($id);
            $sucesso = 'Item excluído.';
        }
    }
}

$editandoId = (int)($_GET['editar'] ?? 0);
$editando = $editandoId ? buscarItemPatrimonio($editandoId) : null;
$abrirModal = $editando !== null || isset($_GET['novo']);

$fCategoria = array_key_exists($_GET['categoria'] ?? '', PATRIMONIO_CATEGORIAS) ? $_GET['categoria'] : '';
$fStatus = array_key_exists($_GET['status'] ?? '', PATRIMONIO_STATUS) ? $_GET['status'] : '';
$fQ = trim((string)($_GET['q'] ?? ''));

$itens = listarPatrimonio($fCategoria, $fStatus, $fQ);
$valorTotalAtivo = patrimonioValorTotalAtivo();
$porCategoria = patrimonioContagemPorCategoria();
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Patrimônio — Fastcar CRM</title>
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
    <a href="/admin/logout.php">Sair</a>
</header>
<main>

<?php if ($erro): ?><div class="alerta-erro"><?= e($erro) ?></div><?php endif; ?>
<?php if ($sucesso): ?><div class="alerta-sucesso"><?= e($sucesso) ?></div><?php endif; ?>

<div class="grid-2" style="margin-bottom:1.5rem">
  <div class="card">
    <div style="font-size:.8rem;color:var(--muted);font-weight:600">Valor total (itens ativos)</div>
    <div style="font-size:1.6rem;font-weight:700">R$ <?= number_format($valorTotalAtivo, 2, ',', '.') ?></div>
    <small style="color:var(--muted)">Só soma item com valor de aquisição cadastrado — item sem valor conhecido não entra na conta.</small>
  </div>
  <div class="card">
    <div style="font-size:.8rem;color:var(--muted);font-weight:600;margin-bottom:.35rem">Por categoria (ativos)</div>
    <div style="display:flex;gap:.75rem;flex-wrap:wrap">
      <?php foreach (PATRIMONIO_CATEGORIAS as $k => $lbl): ?>
        <span class="badge badge-info"><?= e($lbl) ?>: <?= (int)($porCategoria[$k] ?? 0) ?></span>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<div style="display:flex;justify-content:flex-end;margin-bottom:1rem">
  <a href="?novo=1" class="btn-primary" style="width:auto">➕ Novo item</a>
</div>

<dialog id="modal-patrimonio" class="modal-lancamento">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.5rem">
    <h2 style="margin:0"><?= $editando ? '✏️ Editar item' : '➕ Novo item de patrimônio' ?></h2>
    <button type="button" onclick="document.getElementById('modal-patrimonio').close()" style="background:none;border:none;font-size:1.6rem;font-weight:700;cursor:pointer;line-height:1;padding:0 .25rem;color:var(--texto-suave)" aria-label="Fechar">&times;</button>
  </div>
  <form method="POST">
    <?= csrfField() ?>
    <input type="hidden" name="acao" value="salvar">
    <input type="hidden" name="id" value="<?= (int)($editando['id'] ?? 0) ?>">

    <label>Categoria</label>
    <select name="categoria">
      <?php foreach (PATRIMONIO_CATEGORIAS as $k => $lbl): ?>
        <option value="<?= e($k) ?>" <?= ($editando['categoria'] ?? 'outros') === $k ? 'selected' : '' ?>><?= e($lbl) ?></option>
      <?php endforeach; ?>
    </select>

    <label>Nome/descrição do bem *</label>
    <input type="text" name="nome" required value="<?= e($editando['nome'] ?? '') ?>" placeholder="Ex: Notebook Dell Inspiron, Sala comercial Alphaville...">

    <div class="grid-2">
      <div>
        <label>Valor de aquisição (R$) — deixe em branco se não souber</label>
        <input type="text" name="valor_aquisicao" value="<?= e((string)($editando['valor_aquisicao'] ?? '')) ?>" placeholder="0,00">
      </div>
      <div>
        <label>Data de aquisição</label>
        <input type="date" name="data_aquisicao" value="<?= e($editando['data_aquisicao'] ?? '') ?>">
      </div>
    </div>

    <label>Local/responsável</label>
    <input type="text" name="local_responsavel" value="<?= e($editando['local_responsavel'] ?? '') ?>" placeholder="Ex: Sede Alphaville, sala do financeiro / José">

    <label>Status</label>
    <select name="status">
      <?php foreach (PATRIMONIO_STATUS as $k => $lbl): ?>
        <option value="<?= e($k) ?>" <?= ($editando['status'] ?? 'ativo') === $k ? 'selected' : '' ?>><?= e($lbl) ?></option>
      <?php endforeach; ?>
    </select>

    <label>Observações</label>
    <textarea name="observacoes" rows="2"><?= e($editando['observacoes'] ?? '') ?></textarea>

    <button type="submit"><?= $editando ? 'Salvar alterações' : 'Cadastrar' ?></button>
    <button type="button" onclick="document.getElementById('modal-patrimonio').close()">Cancelar</button>
  </form>
</dialog>
<?php if ($abrirModal): ?><script>document.getElementById('modal-patrimonio').showModal();</script><?php endif; ?>

<div class="card" style="margin-bottom:1.5rem">
  <form method="GET" style="display:flex;gap:.75rem;flex-wrap:wrap;align-items:flex-end">
    <div>
      <label>Categoria</label>
      <select name="categoria">
        <option value="">Todas</option>
        <?php foreach (PATRIMONIO_CATEGORIAS as $k => $lbl): ?>
          <option value="<?= e($k) ?>" <?= $fCategoria === $k ? 'selected' : '' ?>><?= e($lbl) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label>Status</label>
      <select name="status">
        <option value="">Todos</option>
        <?php foreach (PATRIMONIO_STATUS as $k => $lbl): ?>
          <option value="<?= e($k) ?>" <?= $fStatus === $k ? 'selected' : '' ?>><?= e($lbl) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div><label>Buscar</label><input type="text" name="q" value="<?= e($fQ) ?>" placeholder="Nome, local, observação..."></div>
    <button type="submit" style="width:auto">Filtrar</button>
  </form>
</div>

<div class="card">
  <h2>📦 Itens de patrimônio (<?= count($itens) ?>)</h2>
  <table class="tabela-oportunidades">
    <thead><tr><th>Nome</th><th>Categoria</th><th>Valor</th><th>Aquisição</th><th>Local/responsável</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($itens as $it): ?>
      <tr>
        <td><?= e($it['nome']) ?></td>
        <td><?= e(PATRIMONIO_CATEGORIAS[$it['categoria']] ?? $it['categoria']) ?></td>
        <td><?= $it['valor_aquisicao'] !== null ? 'R$ ' . number_format((float)$it['valor_aquisicao'], 2, ',', '.') : '—' ?></td>
        <td><?= $it['data_aquisicao'] ? date('d/m/Y', strtotime($it['data_aquisicao'])) : '—' ?></td>
        <td><?= e($it['local_responsavel'] ?: '—') ?></td>
        <td>
          <?php
            $corStatus = ['ativo' => ['#166534', '#f0fdf4'], 'vendido' => ['#92400e', '#fffbeb'], 'baixado' => ['#475569', '#f1f5f9']][$it['status']] ?? ['#475569', '#f1f5f9'];
          ?>
          <span class="badge" style="background:<?= $corStatus[1] ?>;color:<?= $corStatus[0] ?>"><?= e(PATRIMONIO_STATUS[$it['status']] ?? $it['status']) ?></span>
        </td>
        <td style="white-space:nowrap">
          <a href="?editar=<?= (int)$it['id'] ?>">✏️</a>
          <form method="POST" style="display:inline" onsubmit="return confirm('Excluir este item de patrimônio? Não pode ser desfeito.')">
            <?= csrfField() ?>
            <input type="hidden" name="acao" value="excluir">
            <input type="hidden" name="id" value="<?= (int)$it['id'] ?>">
            <button type="submit" class="btn-texto perigo" title="Excluir">🗑️</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$itens): ?><tr><td colspan="7" style="text-align:center;color:var(--muted)">Nenhum item cadastrado.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>

</main>
<?php include __DIR__ . '/_pwa_register.php'; ?>
<?php include __DIR__ . '/_notify.php'; ?>
<?php include __DIR__ . '/_zapi_status.php'; ?>
</body>
</html>
