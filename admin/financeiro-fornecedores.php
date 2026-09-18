<?php
/** Fornecedores — CRUD simples (17/09/2026, módulo financeiro). */

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
            $cnpjCpf = clean((string)($_POST['cnpj_cpf'] ?? ''));
            $contato = clean((string)($_POST['contato'] ?? ''));
            $obs = clean((string)($_POST['observacoes'] ?? ''));
            if (!$nome) {
                $erro = 'Nome é obrigatório.';
            } elseif ($id) {
                $db->prepare("UPDATE fin_fornecedores SET nome=?, cnpj_cpf=?, contato=?, observacoes=?, updated_at=datetime('now','localtime') WHERE id=?")->execute([$nome, $cnpjCpf, $contato, $obs, $id]);
                $sucesso = 'Fornecedor atualizado.';
            } else {
                $db->prepare('INSERT INTO fin_fornecedores (nome, cnpj_cpf, contato, observacoes) VALUES (?,?,?,?)')->execute([$nome, $cnpjCpf, $contato, $obs]);
                $sucesso = 'Fornecedor criado.';
            }
        } elseif ($acao === 'inativar') {
            $db->prepare("UPDATE fin_fornecedores SET status='inativo' WHERE id=?")->execute([(int)($_POST['id'] ?? 0)]);
            $sucesso = 'Fornecedor inativado.';
        } elseif ($acao === 'reativar') {
            $db->prepare("UPDATE fin_fornecedores SET status='ativo' WHERE id=?")->execute([(int)($_POST['id'] ?? 0)]);
            $sucesso = 'Fornecedor reativado.';
        }
    }
}

$fornecedores = $db->query("SELECT * FROM fin_fornecedores ORDER BY (status='ativo') DESC, nome")->fetchAll(PDO::FETCH_ASSOC);
$editando = null;
if (($_GET['action'] ?? '') === 'edit' && !empty($_GET['id'])) {
    $stmt = $db->prepare('SELECT * FROM fin_fornecedores WHERE id=?');
    $stmt->execute([(int)$_GET['id']]);
    $editando = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Fornecedores — Financeiro Fastcar</title>
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

<div class="card" style="margin-bottom:1.5rem">
  <h2><?= $editando ? '✏️ Editar fornecedor' : '➕ Novo fornecedor' ?></h2>
  <form method="POST">
    <?= csrfField() ?>
    <input type="hidden" name="acao" value="salvar">
    <input type="hidden" name="id" value="<?= (int)($editando['id'] ?? 0) ?>">
    <label><input type="checkbox" id="forn-sem-cnpj" style="width:auto;display:inline-block" onchange="alternarCnpjFornecedor()"> 🌎 Fornecedor estrangeiro / sem CNPJ no Brasil (ex: Anthropic e outros)</label>
    <div id="forn-cnpj-bloco">
        <label>CNPJ/CPF (opcional)</label>
        <div style="display:flex;gap:.5rem;align-items:center">
            <input type="text" name="cnpj_cpf" id="forn-cnpj" value="<?= e($editando['cnpj_cpf'] ?? '') ?>" placeholder="00.000.000/0000-00 — deixe em branco se não tiver" style="flex:1">
            <button type="button" id="forn-cnpj-btn" onclick="buscarCnpjFornecedor()">🔎 Buscar na Receita</button>
        </div>
        <p id="forn-cnpj-status" style="font-size:.8rem;color:var(--muted);margin:.3rem 0 0"></p>
    </div>
    <label>Nome</label>
    <input type="text" name="nome" id="forn-nome" required value="<?= e($editando['nome'] ?? '') ?>">
    <label>Contato</label>
    <input type="text" name="contato" id="forn-contato" value="<?= e($editando['contato'] ?? '') ?>">
    <label>Observações</label>
    <textarea name="observacoes" id="forn-observacoes" rows="2"><?= e($editando['observacoes'] ?? '') ?></textarea>
    <button type="submit"><?= $editando ? 'Salvar' : 'Criar' ?></button>
    <?php if ($editando): ?><a href="/admin/financeiro-fornecedores.php">Cancelar</a><?php endif; ?>
  </form>
</div>

<script>
// Toggle "fornecedor estrangeiro / sem CNPJ" (18/09/2026, pedido
// José/Jean: "cadastrar fornecedores que naó tem cnpj no brasil tipo
// antropic e utros") — CNPJ/CPF já era opcional no servidor (só "nome" é
// obrigatório pra salvar), mas nada na tela deixava isso óbvio: o campo
// vinha logo no topo, com placeholder em formato brasileiro e um botão de
// busca do lado, dando a impressão de que era obrigatório. Marcar esse
// checkbox esconde o campo de CNPJ (e limpa o valor, pra nunca submeter
// um resto de digitação antiga por engano) — nada novo no banco, só
// deixa claro na UI que dá pra cadastrar sem CNPJ nenhum.
function alternarCnpjFornecedor() {
    var marcado = document.getElementById('forn-sem-cnpj').checked;
    var bloco = document.getElementById('forn-cnpj-bloco');
    var input = document.getElementById('forn-cnpj');
    bloco.style.display = marcado ? 'none' : '';
    if (marcado) input.value = '';
}
document.addEventListener('DOMContentLoaded', function () {
    // Editando um fornecedor que já não tem CNPJ salvo — parte com o
    // checkbox já marcado e o campo já escondido, sem precisar clicar.
    var input = document.getElementById('forn-cnpj');
    var checkbox = document.getElementById('forn-sem-cnpj');
    <?php if ($editando): ?>
    if (!input.value) { checkbox.checked = true; alternarCnpjFornecedor(); }
    <?php endif; ?>
});

// Busca CNPJ na Receita (via BrasilAPI, includes/cnpj.php) e preenche
// nome/contato/observações fill-if-empty — pedido José/Jean: "no modulo
// financeiro em foncedores coloca api do cnpj para puxa da receita".
// Diferente do widget de FIPE por placa (vários candidatos, exige
// clique humano num deles), 1 CNPJ é uma chave única — só 1 resultado
// possível, então preenche direto como já faz a busca de marca/modelo/
// ano por placa em admin/oportunidade.php.
function buscarCnpjFornecedor() {
    var input = document.getElementById('forn-cnpj');
    var status = document.getElementById('forn-cnpj-status');
    var btn = document.getElementById('forn-cnpj-btn');
    var cnpj = (input.value || '').replace(/\D/g, '');
    if (cnpj.length !== 14) { status.textContent = '⚠️ Digite um CNPJ com 14 dígitos primeiro.'; return; }

    btn.disabled = true;
    status.textContent = '🔄 Consultando a Receita...';
    fetch('/admin/fornecedor_cnpj_ajax.php?cnpj=' + encodeURIComponent(cnpj))
        .then(function (r) { return r.json(); })
        .then(function (d) {
            btn.disabled = false;
            if (!d.ok) { status.textContent = '⚠️ ' + d.msg; return; }
            var v = d.dados;

            var campoNome = document.getElementById('forn-nome');
            var nomePreenchido = v.nome_fantasia || v.razao_social || '';
            if (campoNome && !campoNome.value && nomePreenchido) campoNome.value = nomePreenchido;

            var campoContato = document.getElementById('forn-contato');
            var contatoPartes = [v.telefone, v.email].filter(function (x) { return x; });
            if (campoContato && !campoContato.value && contatoPartes.length) campoContato.value = contatoPartes.join(' · ');

            var campoObs = document.getElementById('forn-observacoes');
            if (campoObs && !campoObs.value) {
                var obsPartes = [];
                if (v.razao_social && v.razao_social !== nomePreenchido) obsPartes.push('Razão social: ' + v.razao_social);
                if (v.situacao) obsPartes.push('Situação na Receita: ' + v.situacao);
                if (v.endereco) obsPartes.push('Endereço: ' + v.endereco);
                if (obsPartes.length) campoObs.value = obsPartes.join('\n');
            }

            status.textContent = '✅ Dados da Receita preenchidos — confira antes de salvar.';
        })
        .catch(function (err) { btn.disabled = false; status.textContent = '⚠️ Erro ao consultar a Receita.'; console.error(err); });
}
</script>

<div class="card">
  <h3>🏭 Fornecedores (<?= count($fornecedores) ?>)</h3>
  <table class="tabela-oportunidades">
    <thead><tr><th>Nome</th><th>CNPJ/CPF</th><th>Contato</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($fornecedores as $f): ?>
      <tr>
        <td><?= e($f['nome']) ?></td>
        <td><?= $f['cnpj_cpf'] ? e($f['cnpj_cpf']) : '<span style="color:var(--muted)">🌎 estrangeiro/sem CNPJ</span>' ?></td>
        <td><?= e($f['contato']) ?></td>
        <td><?= $f['status'] === 'ativo' ? '<span class="badge badge-ok">✅ ativo</span>' : '<span class="badge">⛔ inativo</span>' ?></td>
        <td style="white-space:nowrap">
          <a href="?action=edit&id=<?= (int)$f['id'] ?>">Editar</a>
          <?php if ($f['status'] === 'ativo'): ?>
            <form method="POST" style="display:inline"><?= csrfField() ?><input type="hidden" name="acao" value="inativar"><input type="hidden" name="id" value="<?= (int)$f['id'] ?>"><button type="submit" style="background:none;border:none;cursor:pointer">Inativar</button></form>
          <?php else: ?>
            <form method="POST" style="display:inline"><?= csrfField() ?><input type="hidden" name="acao" value="reativar"><input type="hidden" name="id" value="<?= (int)$f['id'] ?>"><button type="submit" style="background:none;border:none;cursor:pointer">Reativar</button></form>
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
