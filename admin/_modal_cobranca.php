<?php
// Modal "💬 Gerar cobrança" (botão Cobrar) — usado em Lançamentos e em Cobranças.
// A página que inclui trata o POST acao=enviar_cobranca.
?>
<dialog class="modal-lancamento" id="modal-cobranca">
  <form method="POST" id="form-cobranca">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:8px">
      <h2 style="margin:0">💬 Gerar cobrança</h2>
      <button type="button" class="btn-texto" onclick="document.getElementById('modal-cobranca').close()" aria-label="Fechar" style="font-size:1.4rem;min-width:44px;min-height:44px">×</button>
    </div>
    <?= csrfField() ?>
    <input type="hidden" name="acao" value="enviar_cobranca">
    <input type="hidden" name="id" id="cob-id" value="">
    <p id="cob-info" style="color:var(--muted)">Carregando…</p>
    <div id="cob-aviso"></div>
    <label>Mensagem (confira antes de enviar)
      <textarea name="mensagem" id="cob-msg" rows="12" style="width:100%"></textarea>
    </label>
    <label id="cob-forcar-wrap" style="display:none"><input type="checkbox" name="forcar" value="1"> Enviar mesmo assim</label>
    <p style="font-size:.85rem;color:var(--muted)">Sai pelo WhatsApp da <strong>ouvidoria</strong>, assinada com seu nome. A resposta do cliente aparece em Admin → 📣 Ouvidoria.</p>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <button type="submit" class="btn-primary" id="cob-enviar" disabled>📤 Enviar cobrança</button>
      <button type="button" class="btn-secundario" onclick="document.getElementById('modal-cobranca').close()">Cancelar</button>
    </div>
  </form>
</dialog>

<script>
function fcAbrirCobranca(id) {
    var dlg = document.getElementById('modal-cobranca');
    var info = document.getElementById('cob-info'), aviso = document.getElementById('cob-aviso');
    var msg = document.getElementById('cob-msg'), btn = document.getElementById('cob-enviar');
    document.getElementById('cob-id').value = id;
    info.textContent = 'Carregando…'; aviso.innerHTML = ''; msg.value = ''; btn.disabled = true;
    document.getElementById('cob-forcar-wrap').style.display = 'none';
    dlg.showModal();
    fetch('/admin/financeiro-lancamentos.php?ajax=cobranca&id=' + encodeURIComponent(id), {credentials: 'same-origin'})
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.ok) { info.textContent = d.erro || 'Não deu pra montar a cobrança.'; return; }
        info.textContent = (d.nome || 'Cliente') + ' — ' + d.telefone + ' — ' + d.itens + ' lançamento(s) em aberto, R$ ' +
            Number(d.total).toLocaleString('pt-BR', {minimumFractionDigits: 2, maximumFractionDigits: 2});
        var avisos = [];
        if (!d.ouvidoria_ok) avisos.push('⚠️ Instância da ouvidoria não configurada em Configurações.');
        if (!d.tem_link) avisos.push('ℹ️ Sem link do Asaas pra essa cobrança — a mensagem vai sem link de pagamento.');
        if (d.ultima_cobranca) avisos.push('🕒 Última cobrança pra esse número: ' + d.ultima_cobranca + '.');
        aviso.innerHTML = avisos.map(function (a) { var p = document.createElement('p'); p.className = 'alerta-info'; p.textContent = a; return p.outerHTML; }).join('');
        if (d.recente) document.getElementById('cob-forcar-wrap').style.display = '';
        msg.value = d.mensagem;
        btn.disabled = !d.ouvidoria_ok;
      })
      .catch(function (err) { console.error(err); info.textContent = 'Erro ao carregar. Tente de novo.'; });
}

// 23/09/2026 — cancelar (nunca exclui, sempre fica no histórico) funciona
// pra QUALQUER lançamento, inclusive origem='asaas' e os automáticos
// (comissão, fechamento de compra, etc). Pede um motivo opcional só pra
// deixar rastro melhor pra contabilidade; cancelar o prompt cancela a ação.
function fcConfirmarCancelamento(form) {
    var motivo = prompt('Cancelar este lançamento (fica registrado, nunca some).\nMotivo (opcional):', '');
    if (motivo === null) return false;
    form.motivo_cancelamento.value = motivo;
    return true;
}
(function () {
    var m = location.search.match(/[?&]cobrar=(\d+)/);
    if (m) document.addEventListener('DOMContentLoaded', function () { fcAbrirCobranca(m[1]); });
})();
</script>
