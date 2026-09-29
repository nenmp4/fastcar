<?php
/**
 * Banner fixo (na verdade fluxo normal, ANTES do topbar sticky — ver
 * nota abaixo) avisando que o super_admin está impersonando outro
 * usuário. 29/09/2026, "colocar inperviosnamento dos usurios pelo super
 * admin", confirmado: banner sempre visível enquanto durar + botão
 * "Voltar a ser super admin". Incluído logo depois de `<body>` (ou
 * `<body class="...">` nos 3 inboxes) em toda página cheia, ANTES do
 * `<header class="topbar">` — nunca `position:fixed` (regra de UX mobile
 * do projeto reserva o canto superior direito pro sino/badge Z-API, e o
 * `.topbar` já é `position:sticky;top:0`; um 2º elemento fixo ali
 * colidiria). Fluxo normal: no topo da página o banner aparece acima do
 * topbar; ao rolar, o topbar (sticky) assume o topo e o banner sai de
 * vista — comportamento aceitável pra um aviso que já é bem visível no
 * carregamento e reaparece a cada navegação.
 */
if (estaImpersonando()):
    $original = impersonandoOriginal();
?>
<div style="background:var(--laranja,#c2410c);color:#fff;padding:8px 16px;text-align:center;font-size:14px;display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:center">
    <span>🎭 <strong><?= e($original['nome']) ?></strong> está navegando como <strong><?= e((string)$_SESSION['admin_nome']) ?></strong> (<?= e((string)$_SESSION['admin_perfil']) ?>)</span>
    <form method="post" action="/admin/parar_impersonar.php" style="margin:0;display:inline">
        <?= csrfField() ?>
        <button type="submit" style="margin:0;padding:4px 12px;font-size:13px;background:#fff;color:var(--laranja,#c2410c);border-radius:6px;box-shadow:none">← Voltar a ser super admin</button>
    </form>
</div>
<?php endif; ?>
