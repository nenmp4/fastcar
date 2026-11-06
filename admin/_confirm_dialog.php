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
<script>
// 29/10/2026, "tentativa de envio foi 5 vezes" — form com class="envio-unico"
// (ex: gerar contrato e enviar pra ZapSign, que pode levar vários segundos)
// trava o botão depois que o envio de verdade começa, pra um 2º clique nunca
// criar outro documento/outro link pro cliente. Roda em bubble no document,
// então só age se o confirmarAcao() inline deixou o submit seguir.
document.addEventListener('submit', function (e) {
    var f = e.target;
    if (e.defaultPrevented || !f.classList || !f.classList.contains('envio-unico')) return;
    if (f.dataset.enviando === '1') { e.preventDefault(); return; }
    f.dataset.enviando = '1';
    f.querySelectorAll('button[type=submit], button:not([type])').forEach(function (b) {
        b.disabled = true;
        b.textContent = '⏳ Enviando… aguarde, não clique de novo';
    });
    // 06/11/2026, "coloca algum loading ao gerar contrato" — tela de espera
    // por cima da página enquanto o servidor trabalha (data-carregando="texto").
    var msg = f.getAttribute('data-carregando');
    if (msg) {
        var ov = document.createElement('div');
        ov.className = 'carregando-overlay';
        ov.setAttribute('role', 'status');
        ov.setAttribute('aria-live', 'polite');
        ov.innerHTML = '<div class="carregando-caixa"><div class="carregando-spinner"></div><b></b><small>Isso pode levar até 1 minuto. Não feche nem recarregue a página.</small></div>';
        ov.querySelector('b').textContent = msg;
        document.body.appendChild(ov);
    }
});
// Voltar pelo navegador nunca deixa a tela de espera presa.
window.addEventListener('pageshow', function () {
    document.querySelectorAll('.carregando-overlay').forEach(function (o) { o.remove(); });
    document.querySelectorAll('form.envio-unico[data-enviando]').forEach(function (f) { delete f.dataset.enviando; });
});
</script>
<style>
.carregando-overlay { position: fixed; inset: 0; z-index: 3000; background: rgba(21, 23, 34, .55); display: flex; align-items: center; justify-content: center; padding: 16px; }
.carregando-caixa { background: var(--superficie, #fff); color: var(--texto, #151722); border-radius: var(--raio, 12px); padding: 24px 28px; max-width: 360px; width: 100%; text-align: center; display: flex; flex-direction: column; gap: 10px; align-items: center; box-shadow: var(--sombra, 0 8px 30px rgba(0,0,0,.2)); }
.carregando-caixa small { color: var(--texto-suave, #6b7280); }
.carregando-spinner { width: 44px; height: 44px; border-radius: 50%; border: 4px solid var(--borda, #e5e7eb); border-top-color: var(--azul, #2f6fed); animation: carregando-gira .8s linear infinite; }
@keyframes carregando-gira { to { transform: rotate(360deg); } }
@media (prefers-reduced-motion: reduce) { .carregando-spinner { animation-duration: 2.4s; } }
</style>
