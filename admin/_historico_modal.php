<?php
/**
 * ⚙️ Histórico do veículo em modal (29/10/2026, "engrenagem ficou legal, mais
 * legal modal pra não sair da tela"). Intercepta qualquer link pra
 * /admin/veiculo_historico.php e abre o mesmo conteúdo (?parcial=1) num
 * <dialog> — sem link (Ctrl/clique do meio) continua abrindo a página normal.
 * Só super admin vê os links, então só incluímos pra ele.
 */
if (($_SESSION['admin_perfil'] ?? '') !== 'super_admin') return;
?>
<dialog id="vh-modal" class="modal-lancamento" style="max-width:760px">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:8px">
        <strong>⚙️ Histórico do veículo</strong>
        <button type="button" class="secundario" style="margin:0;min-width:44px" onclick="document.getElementById('vh-modal').close()" aria-label="Fechar">✕</button>
    </div>
    <div id="vh-modal-corpo" style="margin-top:8px"></div>
</dialog>
<script>
(function () {
    var dlg = document.getElementById('vh-modal');
    var corpo = document.getElementById('vh-modal-corpo');
    if (!dlg) return;
    dlg.addEventListener('click', function (e) { if (e.target === dlg) dlg.close(); });
    document.addEventListener('click', function (e) {
        if (e.defaultPrevented || e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey) return;
        var a = e.target.closest && e.target.closest('a[href*="/admin/veiculo_historico.php"]');
        if (!a || a.closest('#vh-modal')) return;
        e.preventDefault();
        corpo.innerHTML = '<p>🔄 Carregando...</p>';
        if (!dlg.open) dlg.showModal();
        var url = a.getAttribute('href') + (a.getAttribute('href').indexOf('?') >= 0 ? '&' : '?') + 'parcial=1';
        fetch(url, { credentials: 'same-origin' })
            .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.text(); })
            .then(function (html) { corpo.innerHTML = html; })
            .catch(function () { corpo.innerHTML = '<p>⚠️ Não deu pra carregar. <a href="' + a.getAttribute('href') + '">Abrir em página →</a></p>'; });
    });
})();
</script>
