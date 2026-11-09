<?php
/**
 * Extras do WhatsApp Box (09/11/2026) — incluído pelos inbox de compra e de
 * vendas: ✓/✓✓/✓✓ azul nas mensagens enviadas, "🗑️ apagar pra todos",
 * mensagem apagada e "online/digitando" do cliente da conversa aberta.
 * Tudo vem de ?ajax=status (includes/whatsapp_inbox.php::inboxStatusConversa()).
 * Espera: $telefoneAtivo e $inboxPodeAgir (bool) definidos pela página.
 */
?>
<style>
.wpp-tick { font-size: 11px; color: var(--texto-fraco); margin-left: 4px; letter-spacing: -2px; }
.wpp-tick.lido { color: var(--azul); }
.wpp-apagar { background: none; border: none; box-shadow: none; padding: 0 4px; margin: 0 0 0 6px; color: var(--texto-fraco); font-size: 12px; cursor: pointer; min-height: 0; }
.wpp-apagar:hover { color: var(--vermelho); }
.msg.msg-apagada { font-style: italic; opacity: .6; }
.msg.msg-apagada img, .msg.msg-apagada audio, .msg.msg-apagada video, .msg.msg-apagada a { display: none; }
#wpp-presenca { font-size: 12px; color: var(--verde, #16a34a); font-weight: 600; }
</style>
<?php if (!empty($telefoneAtivo)): ?>
<form method="post" id="wpp-form-apagar" style="display:none">
    <?= csrfField() ?>
    <input type="hidden" name="acao" value="apagar_mensagem">
    <input type="hidden" name="telefone" value="<?= e($telefoneAtivo) ?>">
    <input type="hidden" name="mensagem_id" value="">
</form>
<script>
(function () {
    var telefone = <?= json_encode((string)$telefoneAtivo) ?>;
    var podeAgir = <?= !empty($inboxPodeAgir) ? 'true' : 'false' ?>;
    var thread = document.getElementById('wpp-thread');
    var presencaEl = document.getElementById('wpp-presenca');
    var formApagar = document.getElementById('wpp-form-apagar');
    var ROTULO = { enviado: ['✓', 'Enviada'], entregue: ['✓✓', 'Entregue'], lido: ['✓✓', 'Visualizada'] };

    function aplicar(m) {
        var div = thread && thread.querySelector('.msg-out[data-id="' + m.id + '"]');
        if (!div) return;
        var rodape = div.querySelector('small') || div;
        if (m.apagada) {
            if (!div.classList.contains('msg-apagada')) {
                div.classList.add('msg-apagada');
                var aviso = document.createElement('div');
                aviso.textContent = '🚫 Você apagou esta mensagem';
                div.insertBefore(aviso, div.firstChild);
            }
            var b = div.querySelector('.wpp-apagar'); if (b) b.remove();
            return;
        }
        var r = ROTULO[m.status];
        var tick = div.querySelector('.wpp-tick');
        if (r) {
            if (!tick) { tick = document.createElement('span'); rodape.appendChild(tick); }
            tick.className = 'wpp-tick' + (m.status === 'lido' ? ' lido' : '');
            tick.textContent = r[0];
            tick.title = r[1];
        }
        var btn = div.querySelector('.wpp-apagar');
        if (podeAgir && m.pode_apagar && !btn) {
            btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'wpp-apagar';
            btn.title = 'Apagar pra todos';
            btn.textContent = '🗑️';
            btn.addEventListener('click', function () {
                formApagar.querySelector('[name="mensagem_id"]').value = m.id;
                if (window.confirmarAcao) {
                    window.confirmarAcao(formApagar, 'Apagar essa mensagem pra todos? Ela some também do WhatsApp do cliente.');
                } else if (confirm('Apagar essa mensagem pra todos?')) {
                    formApagar.submit();
                }
            });
            rodape.appendChild(btn);
        } else if (btn && !m.pode_apagar) {
            btn.remove();
        }
    }

    function atualizar() {
        if (document.hidden) return;
        fetch('?ajax=status&telefone=' + encodeURIComponent(telefone))
            .then(function (r) { return r.json(); })
            .then(function (d) {
                (d.mensagens || []).forEach(aplicar);
                if (presencaEl) {
                    var p = d.presenca || '';
                    presencaEl.textContent = p === 'online' ? '● online' : (p === 'digitando' ? 'digitando…' : (p === 'gravando' ? 'gravando áudio…' : ''));
                }
            })
            .catch(function () {});
    }
    setTimeout(atualizar, 400);
    setInterval(atualizar, 4000);
})();
</script>
<?php endif; ?>
