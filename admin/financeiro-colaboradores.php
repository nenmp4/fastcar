<?php
/** Colaboradores — CRUD simples (17/09/2026, módulo financeiro). */

require_once __DIR__ . '/_bootstrap.php';
requireAcessoFinanceiro();

// Cargo padrão sugerido ao puxar um usuário do sistema (usuarios.perfil) —
// só um ponto de partida, campo fica editável como qualquer outro colaborador.
const FINANCEIRO_PERFIL_LABEL = [
    'super_admin' => 'Administrador',
    'consultor' => 'Consultor de compra',
    'supervisor' => 'Supervisor',
    'vendedor' => 'Vendedor',
    'financeiro' => 'Financeiro',
];

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
            $periodicidade = in_array($_POST['periodicidade_pagamento'] ?? '', ['mensal', 'quinzenal'], true) ? $_POST['periodicidade_pagamento'] : 'mensal';
            $obs = clean((string)($_POST['observacoes'] ?? ''));
            if (!$nome) {
                $erro = 'Nome é obrigatório.';
            } elseif ($id) {
                $db->prepare("UPDATE fin_colaboradores SET nome=?, cargo=?, tipo_vinculo=?, salario_base=?, periodicidade_pagamento=?, observacoes=?, updated_at=datetime('now','localtime') WHERE id=?")->execute([$nome, $cargo, $tipoVinculo, $salario, $periodicidade, $obs, $id]);
                $sucesso = 'Colaborador atualizado.';
            } else {
                $db->prepare('INSERT INTO fin_colaboradores (nome, cargo, tipo_vinculo, salario_base, periodicidade_pagamento, observacoes) VALUES (?,?,?,?,?,?)')->execute([$nome, $cargo, $tipoVinculo, $salario, $periodicidade, $obs]);
                $sucesso = 'Colaborador criado.';
            }
        } elseif ($acao === 'inativar') {
            $db->prepare("UPDATE fin_colaboradores SET status='inativo' WHERE id=?")->execute([(int)($_POST['id'] ?? 0)]);
            $sucesso = 'Colaborador inativado.';
        } elseif ($acao === 'reativar') {
            $db->prepare("UPDATE fin_colaboradores SET status='ativo' WHERE id=?")->execute([(int)($_POST['id'] ?? 0)]);
            $sucesso = 'Colaborador reativado.';
        } elseif ($acao === 'adicionar_do_sistema') {
            // 17/09/2026, "nos colaboradores permita puxar do sistema" — em
            // vez de digitar de novo o nome de quem já tem login no CRM
            // (usuarios.perfil), puxa direto de lá. usuario_id já existia no
            // schema desde a 1ª versão do módulo (portado do JurídicoSaaS),
            // só nunca tinha ganhado UI pra usar — cadastro manual (form
            // acima) continua existindo do mesmo jeito, pra fornecedor/
            // colaborador que não tem login nenhum no sistema.
            $usuarioId = (int)($_POST['usuario_id'] ?? 0);
            $stmtU = $db->prepare('SELECT id, nome, perfil FROM usuarios WHERE id = ?');
            $stmtU->execute([$usuarioId]);
            $usuario = $stmtU->fetch(PDO::FETCH_ASSOC);
            if (!$usuario) {
                $erro = 'Selecione um usuário do sistema.';
            } else {
                $stmtJa = $db->prepare('SELECT id FROM fin_colaboradores WHERE usuario_id = ?');
                $stmtJa->execute([$usuarioId]);
                if ($stmtJa->fetch()) {
                    $erro = 'Esse usuário já está cadastrado como colaborador (confira a lista abaixo, pode estar inativo).';
                } else {
                    $cargoPadrao = FINANCEIRO_PERFIL_LABEL[$usuario['perfil']] ?? ucfirst($usuario['perfil']);
                    // Consultor ganha o fixo quinzenal (17/09/2026, "Os
                    // consultores eles ganha o fixo cada 15 dias mais
                    // comição") — só a sugestão inicial pro perfil que a
                    // regra de negócio já confirmou, os outros continuam
                    // mensal; sempre editável depois, nunca travado.
                    $periodicidadePadrao = $usuario['perfil'] === 'consultor' ? 'quinzenal' : 'mensal';
                    $db->prepare('INSERT INTO fin_colaboradores (nome, cargo, tipo_vinculo, periodicidade_pagamento, usuario_id) VALUES (?,?,?,?,?)')
                       ->execute([$usuario['nome'], $cargoPadrao, 'clt', $periodicidadePadrao, $usuarioId]);
                    $sucesso = 'Colaborador "' . $usuario['nome'] . '" adicionado a partir do usuário do sistema — confira/edite cargo, vínculo, periodicidade e salário na lista abaixo.';
                }
            }
        }
    }
}

$colaboradores = $db->query("SELECT * FROM fin_colaboradores ORDER BY (status='ativo') DESC, nome")->fetchAll(PDO::FETCH_ASSOC);
$usuariosDisponiveis = array_values(array_filter(
    listarUsuarios(true),
    fn($u) => !in_array((int)$u['id'], array_column($colaboradores, 'usuario_id'), true)
));
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
    <strong><img class="topbar-logo" src="/admin/assets/img/icon-192.png" alt="Fastcar" onerror="this.style.display='none'"> Fast<b>Car</b></strong>
    <span>Olá, <?= e($_SESSION['admin_nome']) ?></span>
    <a href="/admin/logout.php">Sair</a>
</header>
<main>
<?php if ($erro): ?><div class="alerta-erro"><?= e($erro) ?></div><?php endif; ?>
<?php if ($sucesso): ?><div class="alerta-sucesso"><?= e($sucesso) ?></div><?php endif; ?>

<?php if (!$editando): ?>
<div class="card" style="margin-bottom:1.5rem">
  <h3>🔗 Adicionar a partir de um usuário do sistema</h3>
  <p><small>Pra quem já tem login no CRM (consultor, vendedor, supervisor, financeiro, admin) — puxa o nome direto
     daqui, sem digitar de novo; cargo/vínculo/salário ficam editáveis normalmente depois. Quem não tem login
     nenhum no sistema continua indo pelo cadastro manual, no card abaixo.</small></p>
  <?php if (!$usuariosDisponiveis): ?>
    <p style="color:var(--muted)">Todos os usuários do sistema já estão cadastrados como colaborador.</p>
  <?php else: ?>
    <form method="POST" style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap">
        <?= csrfField() ?>
        <input type="hidden" name="acao" value="adicionar_do_sistema">
        <select name="usuario_id" required style="flex:1;min-width:220px">
            <option value="">Selecione um usuário...</option>
            <?php foreach ($usuariosDisponiveis as $u): ?>
                <option value="<?= (int)$u['id'] ?>"><?= e($u['nome']) ?> — <?= e(FINANCEIRO_PERFIL_LABEL[$u['perfil']] ?? ucfirst($u['perfil'])) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit">🔗 Adicionar colaborador</button>
    </form>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="card" style="margin-bottom:1.5rem">
  <h2><?= $editando ? '✏️ Editar colaborador' : '➕ Novo colaborador (manual)' ?></h2>
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
        <label>Periodicidade do fixo</label>
        <select name="periodicidade_pagamento">
          <?php foreach (['mensal' => 'Mensal', 'quinzenal' => 'Quinzenal (a cada 15 dias)'] as $k => $lbl): ?>
            <option value="<?= $k ?>" <?= ($editando['periodicidade_pagamento'] ?? 'mensal') === $k ? 'selected' : '' ?>><?= $lbl ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <p><small>Comissão (quando tiver) entra sempre como um lançamento manual à parte, em
       <a href="/admin/financeiro-lancamentos.php">Lançamentos</a> — este campo é só o fixo/salário.</small></p>
    <label>Observações</label>
    <textarea name="observacoes" rows="2"><?= e($editando['observacoes'] ?? '') ?></textarea>
    <button type="submit"><?= $editando ? 'Salvar' : 'Criar' ?></button>
    <?php if ($editando): ?><a href="/admin/financeiro-colaboradores.php">Cancelar</a><?php endif; ?>
  </form>
</div>

<div class="card">
  <h3>👥 Colaboradores (<?= count($colaboradores) ?>)</h3>
  <table class="tabela-oportunidades">
    <thead><tr><th>Nome</th><th>Cargo</th><th>Vínculo</th><th>Fixo</th><th>Origem</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($colaboradores as $c): ?>
      <tr>
        <td><?= e($c['nome']) ?></td>
        <td><?= e($c['cargo']) ?></td>
        <td><?= e(strtoupper($c['tipo_vinculo'])) ?></td>
        <td><?= ($c['periodicidade_pagamento'] ?? 'mensal') === 'quinzenal' ? 'Quinzenal' : 'Mensal' ?></td>
        <td><?= $c['usuario_id'] ? '🔗 usuário do sistema' : '✍️ manual' ?></td>
        <td><?= $c['status'] === 'ativo' ? '<span class="badge badge-ok">✅ ativo</span>' : '<span class="badge">⛔ inativo</span>' ?></td>
        <td style="white-space:nowrap">
          <a href="?action=edit&id=<?= (int)$c['id'] ?>">Editar</a>
          <?php if ($c['status'] === 'ativo'): ?>
            <form method="POST" style="display:inline"><?= csrfField() ?><input type="hidden" name="acao" value="inativar"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><button type="submit" class="btn-texto perigo">Inativar</button></form>
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
