<?php /**
 * Sino de notificação + toasts + som, incluído em toda página cheia do
 * admin (não em login.php — igual ao padrão do _pwa_register.php). Só
 * polling simples (setInterval + fetch), sem WebSocket/SSE — shared-hosting-
 * friendly, mesma filosofia do resto do projeto.
 *
 * Som sintetizado via Web Audio API (2 tons), sem precisar de arquivo de
 * áudio — mesma lógica de "sem asset externo, sem build step" do ícone
 * placeholder do PWA (admin/assets/img/icon-*.png), só que pra som em vez
 * de imagem.
 */
$meuId = (int)($_SESSION['admin_id'] ?? 0);
?>
<div id="notif-sino" class="notif-sino" title="Notificações" role="button" tabindex="0">
    🔔<span id="notif-contador" class="notif-contador" hidden>0</span>
</div>
<div id="notif-toasts" class="notif-toasts" aria-live="polite"></div>

<script>
(function () {
    var CHAVE = 'fastcar_notif_desde_<?= $meuId ?>';
    var desde = localStorage.getItem(CHAVE) || '';
    var sino = document.getElementById('notif-sino');
    var contadorEl = document.getElementById('notif-contador');
    var toastsEl = document.getElementById('notif-toasts');
    var naoLidos = 0;

    function tocarSom() {
        try {
            var ctx = new (window.AudioContext || window.webkitAudioContext)();
            [880, 1180].forEach(function (freq, i) {
                var osc = ctx.createOscillator();
                var gain = ctx.createGain();
                osc.type = 'sine';
                osc.frequency.value = freq;
                gain.gain.setValueAtTime(0.15, ctx.currentTime + i * 0.12);
                gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + i * 0.12 + 0.25);
                osc.connect(gain).connect(ctx.destination);
                osc.start(ctx.currentTime + i * 0.12);
                osc.stop(ctx.currentTime + i * 0.12 + 0.25);
            });
        } catch (e) { /* navegador sem suporte a áudio — segue só visual */ }
    }

    function mostrarToast(msg, href) {
        var el = document.createElement('div');
        el.className = 'toast';
        el.innerHTML = '🚗 <strong>Novo lead</strong><br>' + msg;
        el.addEventListener('click', function () { window.location.href = href; });
        toastsEl.appendChild(el);
        setTimeout(function () {
            el.classList.add('toast-saindo');
            setTimeout(function () { el.remove(); }, 300);
        }, 7000);
    }

    function atualizarContador() {
        if (naoLidos > 0) {
            contadorEl.textContent = naoLidos > 9 ? '9+' : naoLidos;
            contadorEl.hidden = false;
            sino.classList.add('notif-sino-ativo');
        } else {
            contadorEl.hidden = true;
            sino.classList.remove('notif-sino-ativo');
        }
    }

    function checar() {
        if (document.hidden) return; // não gasta requisição com aba em segundo plano
        var url = '/admin/notificacoes.php' + (desde ? '?desde=' + encodeURIComponent(desde) : '');
        fetch(url, { credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (data) {
                if (!data) return;
                if (data.novos && data.novos.length > 0) {
                    tocarSom();
                    data.novos.forEach(function (n) {
                        var msg = (n.cliente || '(sem nome)') + (n.veiculo ? ' — ' + n.veiculo : '');
                        mostrarToast(msg, '/admin/oportunidade.php?id=' + n.id);
                    });
                    naoLidos += data.novos.length;
                    atualizarContador();
                }
                if (data.proximo_desde) {
                    desde = data.proximo_desde;
                    localStorage.setItem(CHAVE, desde);
                }
            })
            .catch(function () { /* falha de rede — tenta de novo no próximo ciclo, sem travar a página */ });
    }

    sino.addEventListener('click', function () {
        naoLidos = 0;
        atualizarContador();
        window.location.href = '/admin/index.php';
    });

    checar();
    setInterval(checar, 20000);
})();
</script>
