<?php
/**
 * Dados da Empresa — 19/09/2026, "tem parta cadastrar os dados da empresa
 * com logo para ficar bacana [o] dre". Usado SÓ no cabeçalho dos
 * relatórios financeiros em PDF (DRE Gerencial por enquanto) —
 * deliberadamente separado dos dados fixos já usados nos CONTRATOS
 * (includes/contratos_pdf.php, "FASTCAR SOLUTIONS"/CNPJ 66.934.500/0001-09/
 * endereço de Barueri, hardcoded, confirmados pelo José em 13/09/2026 e já
 * validados em contrato real assinado) — editar aqui NUNCA muda o texto do
 * contrato de compra/venda, só o cabeçalho dos relatórios. Pré-semeado com
 * os MESMOS dados corretos do contrato (`install/migrar.php`), então sai
 * certo desde o primeiro DRE gerado, sem precisar preencher nada — esta
 * tela só existe pra permitir corrigir/editar sem precisar mexer em código.
 * Logo é a mesma já cadastrada em Configurações → 🎨 Identidade visual
 * (`includes/marca.php`, `public/assets/logo.png`) — nunca duplicada aqui.
 * Busca de CNPJ reaproveita o MESMO endpoint já usado em Fornecedores
 * (`admin/fornecedor_cnpj_ajax.php` → `includes/cnpj.php::cnpjConsultar()`,
 * BrasilAPI) — já genérico o bastante (só recebe `?cnpj=`, devolve JSON),
 * nenhuma mudança precisou lá.
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
        setConfig('empresa_razao_social', clean((string)($_POST['razao_social'] ?? '')));
        setConfig('empresa_cnpj', clean((string)($_POST['cnpj'] ?? '')));
        setConfig('empresa_endereco', clean((string)($_POST['endereco'] ?? '')));
        setConfig('contador_email', clean((string)($_POST['contador_email'] ?? '')));
        $sucesso = 'Dados da empresa salvos.';
    }
}

$razaoSocial = getConfig('empresa_razao_social') ?: '';
$cnpjEmpresa = getConfig('empresa_cnpj') ?: '';
$enderecoEmpresa = getConfig('empresa_endereco') ?: '';
$contadorEmail = getConfig('contador_email') ?: '';
$temLogo = is_file(__DIR__ . '/../public/assets/logo.png');
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Dados da Empresa — Financeiro Fastcar</title>
<link rel="stylesheet" href="/admin/assets/style.css?v=<?= @filemtime(__DIR__ . '/assets/style.css') ?: 1 ?>">
<?php include __DIR__ . '/_pwa_head.php'; ?>
</head>
<body>
<header class="topbar">
    <a href="/admin/financeiro-relatorios.php" style="color:#fff">← Voltar</a>
    <strong><img class="topbar-logo" src="/admin/assets/img/icon-192.png" alt="Fastcar" onerror="this.style.display='none'"> Fast<b>Car</b></strong>
    <span>Olá, <?= e($_SESSION['admin_nome']) ?></span>
    <a href="/admin/logout.php">Sair</a>
</header>
<main>

<?php if (!empty($erro)): ?><div class="alerta-erro"><?= e($erro) ?></div><?php endif; ?>
<?php if ($sucesso): ?><div class="alerta-sucesso"><?= e($sucesso) ?></div><?php endif; ?>

<div class="card" style="max-width:640px">
  <h2>🏢 Dados da Empresa</h2>
  <p style="color:var(--muted);font-size:.85rem;margin-bottom:1rem">Usado no cabeçalho dos relatórios financeiros em PDF (DRE Gerencial). O logo é o mesmo já cadastrado em Configurações → Identidade visual — nunca muda o contrato de compra/venda, que tem os dados legais fixos da Fastcar já validados.</p>

  <?php if ($temLogo): ?>
    <div style="margin-bottom:1.25rem;display:flex;align-items:center;gap:.75rem">
      <img src="/public/assets/logo.png?v=<?= @filemtime(__DIR__ . '/../public/assets/logo.png') ?: 1 ?>" style="height:56px;border:1px solid var(--border);border-radius:.5rem;padding:6px;background:#fff">
      <span style="font-size:.8rem;color:var(--muted)">Logo atual (editar em Configurações → Identidade visual)</span>
    </div>
  <?php else: ?>
    <div class="alerta-info" style="margin-bottom:1.25rem">⚠️ Nenhum logo cadastrado ainda — o relatório sai só com o texto "FASTCAR SOLUTIONS". Cadastre em <a href="/admin/configuracoes.php">Configurações → Identidade visual</a>.</div>
  <?php endif; ?>

  <form method="POST">
    <?= csrfField() ?>
    <label>CNPJ</label>
    <div style="display:flex;gap:.5rem;align-items:center">
      <input type="text" id="emp-cnpj" name="cnpj" value="<?= e($cnpjEmpresa) ?>" placeholder="00.000.000/0000-00" style="flex:1" oninput="empMascaraCnpj(this)">
      <button type="button" onclick="empBuscarCnpj()" style="width:auto">🔎 Buscar</button>
    </div>
    <small id="emp-cnpj-status" style="color:var(--muted);display:block;margin-top:.25rem">Digite o CNPJ e clique em Buscar — razão social e endereço preenchem sozinhos (Receita/BrasilAPI).</small>

    <label>Razão Social</label>
    <input type="text" id="emp-razao" name="razao_social" value="<?= e($razaoSocial) ?>">

    <label>Endereço (linha única, aparece no rodapé do relatório)</label>
    <textarea id="emp-endereco" name="endereco" rows="2"><?= e($enderecoEmpresa) ?></textarea>

    <label style="margin-top:.5rem;display:block">📧 E-mail do contador/contabilidade</label>
    <input type="email" name="contador_email" value="<?= e($contadorEmail) ?>" placeholder="contabilidade@escritorio.com">
    <small style="color:var(--muted);display:block;margin-top:.2rem">Recebe automaticamente o DRE todo mês no <a href="/admin/financeiro-relatorios.php">Envio Automático Mensal</a> — não precisa digitar de novo lá em "E-mails extras".</small>

    <button type="submit" style="margin-top:.75rem">Salvar</button>
  </form>
</div>

</main>
<script>
function empMascaraCnpj(el) {
  var v = el.value.replace(/\D/g, '').slice(0, 14);
  v = v.replace(/^(\d{2})(\d)/, '$1.$2').replace(/^(\d{2})\.(\d{3})(\d)/, '$1.$2.$3').replace(/\.(\d{3})(\d)/, '.$1/$2').replace(/(\d{4})(\d{1,2})$/, '$1-$2');
  el.value = v;
}
function empBuscarCnpj() {
  var cnpj = document.getElementById('emp-cnpj').value.replace(/\D/g, '');
  var status = document.getElementById('emp-cnpj-status');
  if (cnpj.length !== 14) { status.textContent = 'CNPJ precisa ter 14 dígitos.'; return; }
  status.textContent = '🔄 Buscando...';
  fetch('/admin/fornecedor_cnpj_ajax.php?cnpj=' + cnpj)
    .then(function (r) { return r.json(); })
    .then(function (d) {
      if (!d.ok) { status.textContent = '⚠️ ' + d.msg; return; }
      if (d.dados.razao_social) document.getElementById('emp-razao').value = d.dados.razao_social;
      if (d.dados.endereco) document.getElementById('emp-endereco').value = d.dados.endereco;
      status.textContent = '✅ Encontrado — confira os campos antes de salvar.';
    })
    .catch(function () { status.textContent = '⚠️ Falha ao buscar — tente de novo.'; });
}
</script>
<?php include __DIR__ . '/_pwa_register.php'; ?>
<?php include __DIR__ . '/_notify.php'; ?>
</body>
</html>
