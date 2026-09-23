<?php
/**
 * Relatórios financeiros — 19/09/2026, "dre para enviar para contabiidade
 * queremos exatamente nessa pegada" + "essa parte é legal" (mostrando a
 * tela de Envio Automático Mensal do JurídicoSaaS, repo irmão). Portado da
 * tela `admin/financeiro-relatorios.php` de lá — mesma ideia (período +
 * botão de relatório + card de envio automático mensal configurável), mas
 * **nunca copy-paste direto**: só o DRE Gerencial existe aqui (o
 * JurídicoSaaS também tem "extrato completo em PDF" e "exportar ZIP com
 * comprovantes" — não implementados aqui, fora do que foi pedido) e o
 * envio automático manda o PDF do DRE (não um extrato, que não existe
 * neste projeto) pela instância Z-API DEDICADA do financeiro
 * (`zapiCredenciaisFinanceiro()`, já criada pro WhatsApp Box de cobrança),
 * nunca a principal.
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../includes/financeiro_dre.php';
requireAcessoFinanceiro();

$db = getDB();
$sucesso = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'salvar_envio_automatico') {
    if (validateCSRF($_POST['csrf_token'] ?? '')) {
        $idsValidos = array_map('intval', (array)($_POST['user_ids'] ?? []));
        setConfig('financeiro_relatorio_dia', (string)max(1, min(28, (int)($_POST['dia'] ?? 1))));
        setConfig('financeiro_relatorio_user_ids', implode(',', $idsValidos));
        setConfig('financeiro_relatorio_emails_extra', clean((string)($_POST['emails_extra'] ?? '')));
        setConfig('financeiro_relatorio_whatsapp_extra', clean((string)($_POST['whatsapp_extra'] ?? '')));
        $sucesso = 'Configuração de envio automático salva.';
    }
}

$usuariosSistema = $db->query("SELECT id, nome, email, whatsapp FROM usuarios WHERE (bloqueado IS NULL OR bloqueado=0) ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC);
$idsSelecionados = array_map('intval', array_filter(explode(',', getConfig('financeiro_relatorio_user_ids') ?: '')));
$diaEnvio = (int)(getConfig('financeiro_relatorio_dia') ?: 1);
$emailsExtra = getConfig('financeiro_relatorio_emails_extra') ?: '';
$whatsappExtra = getConfig('financeiro_relatorio_whatsapp_extra') ?: '';

$de = (string)($_GET['de'] ?? date('Y-m-01'));
$ate = (string)($_GET['ate'] ?? date('Y-m-t'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $de)) $de = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ate)) $ate = date('Y-m-t');
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Relatórios — Financeiro Fastcar</title>
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

<?php if ($sucesso): ?><div class="alerta-sucesso"><?= e($sucesso) ?></div><?php endif; ?>

<div class="card" style="max-width:640px;margin-bottom:1.5rem">
  <h2>📄 Relatórios</h2>
  <p style="color:var(--muted);font-size:.85rem;margin-bottom:1rem">Escolha o período e gere o DRE Gerencial em PDF, pronto pra mandar pro contador. Dados da empresa (CNPJ/razão social) que aparecem no cabeçalho ficam em <a href="/admin/financeiro-empresa.php">🏢 Dados da Empresa</a>.</p>

  <form method="GET" style="display:flex;gap:.75rem;flex-wrap:wrap;align-items:flex-end;margin-bottom:1.5rem">
    <div><label>De</label><input type="date" name="de" value="<?= e($de) ?>"></div>
    <div><label>Até</label><input type="date" name="ate" value="<?= e($ate) ?>"></div>
    <button type="submit" style="width:auto">Atualizar período</button>
  </form>

  <a href="/admin/financeiro-relatorio-dre.php?de=<?= e($de) ?>&ate=<?= e($ate) ?>" target="_blank" class="btn-primary" style="display:block;text-align:center;text-decoration:none">
    📊 Ver / Baixar DRE Gerencial
  </a>
  <a href="/admin/financeiro-relatorio-extrato.php?de=<?= e($de) ?>&ate=<?= e($ate) ?>" target="_blank" class="btn" style="display:block;text-align:center;text-decoration:none;margin-top:.6rem;background:#0f766e;color:#fff;border:none">
    📄 Ver / Baixar Extrato Completo (PDF)
  </a>

  <div style="margin-top:1.5rem;padding:1rem;background:#f8fafc;border-radius:.5rem;font-size:.82rem;color:var(--muted)">
    <strong>DRE Gerencial:</strong> agrupa as categorias em blocos (Receita, Despesas Operacionais, Pessoal, Administrativas, Marketing, Impostos, Outras) até chegar no Resultado Líquido — uso interno/gerencial, não substitui o DRE contábil oficial que o contador prepara.<br><br>
    <strong>Extrato Completo:</strong> lista todo lançamento do período (data, categoria, descrição, vínculo, forma de pagamento, status, valor) — o detalhe linha a linha que o DRE resume em blocos.
  </div>
</div>

<div class="card" style="max-width:640px">
  <h2>📬 Envio Automático Mensal</h2>
  <p style="color:var(--muted);font-size:.85rem;margin-bottom:1.25rem">Todo mês, no dia escolhido, o sistema manda sozinho o DRE Gerencial do mês anterior (PDF) por WhatsApp e/ou e-mail pra quem estiver marcado abaixo.</p>
  <form method="POST">
    <?= csrfField() ?>
    <input type="hidden" name="acao" value="salvar_envio_automatico">
    <label>Dia do mês pro envio</label>
    <input type="number" name="dia" min="1" max="28" value="<?= $diaEnvio ?>" style="width:80px">
    <small style="color:var(--muted);display:block;margin-top:.2rem">Ex: 1 = todo dia 1º do mês, manda o DRE do mês anterior completo.</small>

    <label style="margin-top:.75rem;display:block">Quem recebe (usuários do sistema)</label>
    <div style="border:1.5px solid var(--border);border-radius:.5rem;padding:.5rem .75rem;max-height:220px;overflow-y:auto">
      <?php foreach ($usuariosSistema as $u): ?>
      <label style="display:flex;align-items:center;gap:.5rem;padding:.35rem 0;font-size:.85rem;cursor:pointer">
        <input type="checkbox" name="user_ids[]" value="<?= (int)$u['id'] ?>" <?= in_array((int)$u['id'], $idsSelecionados, true) ? 'checked' : '' ?> style="width:auto">
        <span><?= e($u['nome']) ?></span>
        <?php if (empty($u['email']) && empty($u['whatsapp'])): ?>
          <span style="color:#dc2626;font-size:.72rem">(sem e-mail nem WhatsApp cadastrado)</span>
        <?php else: ?>
          <span style="color:var(--muted);font-size:.72rem"><?= implode(' · ', array_filter([$u['email'] ? '✉️' : '', $u['whatsapp'] ? '📱' : ''])) ?></span>
        <?php endif; ?>
      </label>
      <?php endforeach; ?>
      <?php if (!$usuariosSistema): ?><p style="color:var(--muted);font-size:.82rem;margin:0">Nenhum usuário cadastrado.</p><?php endif; ?>
    </div>
    <small style="color:var(--muted);display:block;margin-top:.3rem">Usa o e-mail e WhatsApp já cadastrados no perfil de cada usuário (Usuários → editar).</small>

    <label style="margin-top:.75rem;display:block">E-mails extras (opcional, separados por vírgula)</label>
    <input type="text" name="emails_extra" value="<?= e($emailsExtra) ?>" placeholder="contabilidade@escritorio.com">

    <label style="margin-top:.5rem;display:block">WhatsApp extras (opcional, separados por vírgula)</label>
    <input type="text" name="whatsapp_extra" value="<?= e($whatsappExtra) ?>" placeholder="5511999999999">

    <button type="submit" style="margin-top:.75rem">Salvar</button>
  </form>
  <?php if (!$idsSelecionados && !$emailsExtra && !$whatsappExtra): ?>
  <div class="alerta-info" style="margin-top:1rem">⚠️ Nenhum destinatário configurado ainda — o envio automático não vai fazer nada até você marcar pelo menos um usuário ou preencher um contato extra acima.</div>
  <?php endif; ?>
</div>

</main>
<?php include __DIR__ . '/_pwa_register.php'; ?>
<?php include __DIR__ . '/_notify.php'; ?>
<?php include __DIR__ . '/_zapi_status.php'; ?>
</body>
</html>
