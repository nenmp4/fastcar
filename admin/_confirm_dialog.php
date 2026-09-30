<?php /**
 * Confirmação estilizada — substitui window.confirm() nativo em todo
 * `<form onsubmit="return confirm('...')">` do admin, sem precisar
 * reescrever cada formulário: troca só o texto do atributo pra
 * `onsubmit="return confirmarAcao(this, '...')"`. 30/09/2026, achado real
 * (print da caixa de diálogo cinza padrão do navegador): "essa ações da
 * para deixar ux bonito de outra forma acho feio" + "faz todo sistema" +
 * "coloca javascript ali".
 *
 * Como funciona (confirm() é síncrono — a chamada trava o JS até o
 * usuário responder e o valor de retorno decide a submissão na hora; um
 * <dialog> é sempre assíncrono, não dá pra "esperar" a resposta dentro do
 * mesmo onsubmit):
 *
 * 1ª chamada de confirmarAcao() SEMPRE retorna false na hora (bloqueia o
 * submit nativo do form) e abre o modal; se o usuário clicar "Confirmar",
 * o form ganha um marcador (`dataset.confirmado='1'`) e o próprio script
 * chama `form.requestSubmit()` de novo — isso dispara o evento `submit`
 * uma 2ª vez, `onsubmit="return confirmarAcao(this, '...')"` roda de novo,
 * mas agora vê o marcador e retorna `true` direto (removendo o marcador
 * em seguida), deixando a submissão de verdade acontecer sem reabrir o
 * modal. Cancelar, apertar Esc ou clicar fora fecha sem confirmar nada.
 */
?>
<dialog id="confirm-dialog" class="modal-confirm">
    <div class="confirm-icone">⚠️</div>
    <p id="confirm-dialog-msg"></p>
    <div class="confirm-acoes">
        <button type="button" class="secundario" id="confirm-dialog-cancelar">Cancelar</button>
        <button type="button" class="perigo" id="confirm-dialog-ok">Confirmar</button>
    </div>
</dialog>
<script>
(function () {
    var dlg = document.getElementById('confirm-dialog');
    if (!dlg) return;
    var msgEl = document.getElementById('confirm-dialog-msg');
    var btnOk = document.getElementById('confirm-dialog-ok');
    var btnCancelar = document.getElementById('confirm-dialog-cancelar');
    var formPendente = null;

    window.confirmarAcao = function (form, mensagem) {
        if (form.dataset.confirmado === '1') {
            delete form.dataset.confirmado;
            return true;
        }
        formPendente = form;
        msgEl.textContent = mensagem;
        dlg.showModal();
        return false;
    };

    function confirmar() {
        dlg.close();
        var form = formPendente;
        formPendente = null;
        if (form) {
            form.dataset.confirmado = '1';
            if (form.requestSubmit) form.requestSubmit();
            else form.submit();
        }
    }
    function cancelar() {
        dlg.close();
        formPendente = null;
    }

    btnOk.addEventListener('click', confirmar);
    btnCancelar.addEventListener('click', cancelar);
    dlg.addEventListener('cancel', function () { formPendente = null; }); // Esc
    dlg.addEventListener('click', function (e) {
        // Clique fora do conteúdo (no próprio <dialog>, que ocupa a tela
        // via ::backdrop em cima) fecha sem confirmar — mesmo padrão já
        // usado nos modais de lançamento/vistoria do projeto.
        if (e.target === dlg) cancelar();
    });
})();
</script>
