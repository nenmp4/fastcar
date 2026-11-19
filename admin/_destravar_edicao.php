<?php
/**
 * Bloco "🔓 Destravar com senha do supervisor" mostrado dentro do aviso de
 * registro travado (compra/venda). Espera $destravarAlvo = ['tipo' => ..., 'id' => ...]
 * e $destravarAtivo (edicaoDestravada()). Ver destravarEdicaoComSenhaSupervisor().
 */
$__sups = supervisoresParaDestravar();
?>
<?php if (!empty($destravarAtivo)): ?>
<div style="margin-bottom:12px;padding:10px 14px;border-radius:10px;background:var(--verde-bg, #f0fdf4);border-left:4px solid var(--verde, #16a34a)">
    🔓 <strong>Edição liberada</strong> por <?= e($destravarAtivo['por']) ?> até <?= e(date('H:i', (int)$destravarAtivo['ate'])) ?> — tudo que for alterado fica na auditoria.
</div>
<?php elseif ($__sups && ($_SESSION['admin_perfil'] ?? '') !== 'supervisor'): ?>
<details style="margin:-4px 0 12px">
    <summary class="btn-texto" style="cursor:pointer">🔓 Precisa corrigir? Destravar com a senha do supervisor</summary>
    <form method="post" style="max-width:420px;margin-top:8px">
        <?= csrfField() ?><input type="hidden" name="acao" value="destravar_edicao">
        <label>Supervisor que autoriza</label>
        <select name="supervisor_id" required>
            <?php foreach ($__sups as $__s): ?><option value="<?= (int)$__s['id'] ?>"><?= e($__s['nome']) ?><?= $__s['perfil'] === 'super_admin' ? ' (super admin)' : '' ?></option><?php endforeach; ?>
        </select>
        <label>Senha do supervisor</label>
        <input type="password" name="senha_supervisor" required autocomplete="off">
        <button type="submit" style="margin-top:6px">🔓 Liberar edição por <?= DESTRAVAR_EDICAO_MIN ?> min</button>
    </form>
</details>
<?php endif; ?>
