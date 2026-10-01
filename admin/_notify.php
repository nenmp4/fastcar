<?php /**
 * Sino de notificação + toasts + som + painel de histórico, incluído em
 * toda página cheia do admin (não em login.php — igual ao padrão do
 * _pwa_register.php). Só polling simples (setInterval + fetch), sem
 * WebSocket/SSE — shared-hosting-friendly, mesma filosofia do resto do
 * projeto.
 *
 * Som sintetizado via Web Audio API (2 tons), sem precisar de arquivo de
 * áudio — mesma lógica de "sem asset externo, sem build step" do ícone
 * placeholder do PWA (admin/assets/img/icon-*.png), só que pra som em vez
 * de imagem.
 *
 * 30/09/2026 — 2 melhorias em cima do sino original ("vai aparecendo
 * vários leads um embaixo do outro"/"como sabemos cliente preencheu...
 * notificação clicável... rola pra cima pra ver os status das ações"):
 * (1) rajada de 4+ eventos na mesma checagem vira 1 toast agrupado, nunca
 * mais empilha um por um; (2) clicar no sino abre um painel rolável com
 * histórico (últimos 30 eventos, lidos+não lidos) em vez de navegar direto
 * — cobre tanto lead novo quanto o evento novo "cliente confirmou os
 * documentos" (admin/notificacoes.php mescla os dois, ver includes/notificacoes.php).
 *
 * 01/10/2026, achado real ("notificação marca 1, clico, não guarda
 * histórico") — "lead novo" nunca foi persistido na tabela `notificacoes`
 * (sempre ao vivo, comparando created_at/updated_at), só o evento
 * "cliente confirmou os documentos" é. Resultado: o contador do sino podia
 * marcar 1 por causa de um lead novo, mas abrir o painel (que só lê a
 * tabela) mostrava "Nenhuma notificação ainda." — o próprio evento que
 * disparou o badge sumia ao clicar. Nunca virou linha em `notificacoes`
 * (duplicaria a fonte de verdade do lead novo, que já tem o próprio
 * mecanismo validado em produção); em vez disso, cada lead novo recebido
 * no polling fica guardado também num histórico PRÓPRIO no localStorage
 * (`salvarLeadNoHistoricoLocal()`, até 20 itens), mesclado com o painel do
 * servidor na hora de abrir (`abrirPainel()`) — histórico só deste
 * navegador/aparelho, mesmo espírito de conveniência por-viewer já usado
 * no resto do projeto pra estado que não precisa ser 100% confiável/
 * compartilhado entre dispositivos.
 */
$meuId = (int)($_SESSION['admin_id'] ?? 0);
?>
<div id="notif-sino" class="notif-sino" title="Notificações" role="button" tabindex="0" aria-expanded="false">
    🔔<span id="notif-contador" class="notif-contador" hidden>0</span>
</div>
<div id="notif-toasts" class="notif-toasts" aria-live="polite"></div>
<div id="notif-painel" class="notif-painel" hidden>
    <div class="notif-painel-cabecalho">🔔 Notificações</div>
    <div id="notif-painel-lista"></div>
</div>

<script>
(function () {
    var CHAVE = 'fastcar_notif_desde_<?= $meuId ?>';
    var CHAVE_NOTIF_ID = 'fastcar_notif_id_<?= $meuId ?>';
    var CHAVE_LEADS_HIST = 'fastcar_notif_leads_hist_<?= $meuId ?>';
    var LEADS_HIST_MAX = 20;
    var desde = localStorage.getItem(CHAVE) || '';
    var desdeNotifId = parseInt(localStorage.getItem(CHAVE_NOTIF_ID) || '0', 10) || 0;
    var sino = document.getElementById('notif-sino');
    var contadorEl = document.getElementById('notif-contador');
    var toastsEl = document.getElementById('notif-toasts');
    var painelEl = document.getElementById('notif-painel');
    var painelListaEl = document.getElementById('notif-painel-lista');
    var naoLidosLeads = 0;   // contagem client-side (leads não vistos ainda, mesma lógica de sempre)
    var naoLidosNotif = 0;   // contagem AUTORITATIVA do servidor (tabela notificacoes)

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

    function mostrarToast(titulo, msg, href) {
        var el = document.createElement('div');
        el.className = 'toast';
        el.innerHTML = '<strong>' + titulo + '</strong><br>' + msg;
        el.addEventListener('click', function () { window.location.href = href; });
        toastsEl.appendChild(el);
        setTimeout(function () {
            el.classList.add('toast-saindo');
            setTimeout(function () { el.remove(); }, 300);
        }, 7000);
    }

    function atualizarContador() {
        var total = naoLidosLeads + naoLidosNotif;
        if (total > 0) {
            contadorEl.textContent = total > 9 ? '9+' : total;
            contadorEl.hidden = false;
            sino.classList.add('notif-sino-ativo');
        } else {
            contadorEl.hidden = true;
            sino.classList.remove('notif-sino-ativo');
        }
    }

    // Rajada (mais de 3 eventos na mesma checagem): 1 toast agrupado, nunca
    // mais empilha um embaixo do outro — clique abre o painel completo.
    function mostrarToasts(itens) {
        if (itens.length === 0) return;
        tocarSom();
        if (itens.length > 3) {
            var el = document.createElement('div');
            el.className = 'toast';
            el.innerHTML = '<strong>🔔 ' + itens.length + ' novidades</strong><br>clique pra ver';
            el.addEventListener('click', abrirPainel);
            toastsEl.appendChild(el);
            setTimeout(function () {
                el.classList.add('toast-saindo');
                setTimeout(function () { el.remove(); }, 300);
            }, 7000);
        } else {
            itens.forEach(function (n) { mostrarToast(n.titulo, n.mensagem, n.url); });
        }
    }

    // Histórico local de "lead novo" — nunca existe no servidor (ver
    // comentário do topo do arquivo), só pra o painel não ficar vazio
    // depois de um toast desse tipo. Sempre em try/catch: localStorage
    // pode lançar (aba anônima, storage bloqueado) e isso nunca pode
    // quebrar o resto do sino.
    function obterLeadsHistoricoLocal() {
        try {
            var bruto = localStorage.getItem(CHAVE_LEADS_HIST);
            var lista = bruto ? JSON.parse(bruto) : [];
            return Array.isArray(lista) ? lista : [];
        } catch (e) { return []; }
    }

    function salvarLeadsNoHistoricoLocal(itens) {
        if (!itens || itens.length === 0) return;
        try {
            var lista = obterLeadsHistoricoLocal();
            itens.forEach(function (n) {
                lista.unshift({ titulo: n.titulo, mensagem: n.mensagem, url: n.url, created_at: n.created_at || '' });
            });
            localStorage.setItem(CHAVE_LEADS_HIST, JSON.stringify(lista.slice(0, LEADS_HIST_MAX)));
        } catch (e) { /* storage indisponível — só o toast ao vivo continua funcionando */ }
    }

    function checar() {
        if (document.hidden) return; // não gasta requisição com aba em segundo plano
        var url = '/admin/notificacoes.php?desde=' + encodeURIComponent(desde) + '&desde_notif_id=' + desdeNotifId;
        fetch(url, { credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (data) {
                if (!data) return;
                if (data.novos && data.novos.length > 0) {
                    mostrarToasts(data.novos);
                    var novosLeads = [];
                    data.novos.forEach(function (n) {
                        if (n.tipo === 'novo_lead') { naoLidosLeads++; novosLeads.push(n); }
                    });
                    salvarLeadsNoHistoricoLocal(novosLeads);
                }
                if (typeof data.nao_lidos_notif === 'number') naoLidosNotif = data.nao_lidos_notif;
                atualizarContador();
                if (data.proximo_desde) {
                    desde = data.proximo_desde;
                    localStorage.setItem(CHAVE, desde);
                }
                if (data.proximo_notif_id) {
                    desdeNotifId = data.proximo_notif_id;
                    localStorage.setItem(CHAVE_NOTIF_ID, desdeNotifId);
                }
            })
            .catch(function () { /* falha de rede — tenta de novo no próximo ciclo, sem travar a página */ });
    }

    function renderPainel(itens) {
        if (itens.length === 0) {
            painelListaEl.innerHTML = '<div class="notif-painel-vazio">Nenhuma notificação ainda.</div>';
            return;
        }
        painelListaEl.innerHTML = '';
        itens.forEach(function (n) {
            var a = document.createElement('a');
            a.className = 'notif-painel-item';
            a.href = n.url || '#';
            a.innerHTML = '<strong>' + n.titulo + '</strong>' + (n.mensagem ? n.mensagem : '') + '<br><small>' + n.created_at + '</small>';
            painelListaEl.appendChild(a);
        });
    }

    function abrirPainel() {
        painelEl.hidden = false;
        sino.setAttribute('aria-expanded', 'true');
        painelListaEl.innerHTML = '<div class="notif-painel-vazio">Carregando…</div>';
        fetch('/admin/notificacoes.php?historico=1', { credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : { itens: [] }; })
            .then(function (data) {
                // Mescla o histórico do servidor (eventos persistidos, ex:
                // "cliente confirmou os documentos") com o histórico local
                // de "lead novo" (nunca persistido, ver comentário do topo
                // do arquivo) — sem isso o painel ficava vazio depois de um
                // toast de lead novo, mesmo o contador tendo marcado 1.
                var mesclado = (data.itens || []).concat(obterLeadsHistoricoLocal());
                mesclado.sort(function (a, b) {
                    return (b.created_at || '').localeCompare(a.created_at || '');
                });
                renderPainel(mesclado.slice(0, 30));
            })
            .catch(function () { painelListaEl.innerHTML = '<div class="notif-painel-vazio">Falha ao carregar.</div>'; });
        // Abrir o painel já marca tudo como visto — mesma disciplina do clique
        // antigo no sino, que sempre zerava o contador.
        naoLidosLeads = 0;
        naoLidosNotif = 0;
        atualizarContador();
    }

    function fecharPainel() {
        painelEl.hidden = true;
        sino.setAttribute('aria-expanded', 'false');
    }

    sino.addEventListener('click', function (e) {
        e.stopPropagation();
        if (painelEl.hidden) abrirPainel(); else fecharPainel();
    });
    document.addEventListener('click', function (e) {
        if (!painelEl.hidden && !painelEl.contains(e.target) && e.target !== sino) fecharPainel();
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !painelEl.hidden) fecharPainel();
    });

    checar();
    setInterval(checar, 20000);
})();
</script>
