/* Fastcar CRM — comportamento mobile (assets/mobile.js)
   Sem dependências, idempotente (pode rodar 2x sem duplicar nada).
   1) Topbar → botão hambúrguer + gaveta com os links (≤900px, via CSS).
   2) Tabelas → cards no celular: copia o texto do <th> para data-label de
      cada <td>. Tabelas sem cabeçalho ganham rolagem horizontal.
   3) Observa conteúdo carregado depois (AJAX) e aplica o mesmo tratamento. */
(function () {
    'use strict';

    /* ── 1. Menu hambúrguer ─────────────────────────────────────────────── */
    function montarMenu() {
        var topbar = document.querySelector('header.topbar, .topbar');
        if (!topbar || topbar.dataset.mobileOk) return;
        topbar.dataset.mobileOk = '1';

        var links = Array.prototype.slice.call(topbar.querySelectorAll(':scope > a'));
        if (!links.length) return;

        var menu = document.createElement('nav');
        menu.className = 'topbar-menu';
        menu.id = 'topbar-menu';
        menu.setAttribute('aria-label', 'Menu principal');

        var ola = topbar.querySelector(':scope > span');
        if (ola && ola.textContent.trim()) {
            var o = document.createElement('div');
            o.className = 'topbar-menu-ola';
            o.textContent = ola.textContent.trim();
            menu.appendChild(o);
        }

        var aqui = location.pathname.split('/').pop();
        links.forEach(function (a) {
            var txt = a.textContent.trim();
            if (/^←|voltar/i.test(txt)) { a.classList.add('topbar-voltar'); return; } // fica na barra
            var c = a.cloneNode(true);
            var alvo = (a.getAttribute('href') || '').split('?')[0].split('/').pop();
            if (alvo && alvo === aqui) { c.classList.add('atual'); c.setAttribute('aria-current', 'page'); }
            if (/logout/.test(a.getAttribute('href') || '')) c.classList.add('topbar-sair');
            menu.appendChild(c);
        });

        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'topbar-toggle';
        btn.setAttribute('aria-label', 'Abrir menu');
        btn.setAttribute('aria-expanded', 'false');
        btn.setAttribute('aria-controls', 'topbar-menu');
        btn.textContent = '☰';
        topbar.appendChild(btn);
        document.body.appendChild(menu);

        function medir() {
            document.documentElement.style.setProperty('--topbar-h', topbar.getBoundingClientRect().bottom + 'px');
        }
        function abrir(sim) {
            medir();
            document.body.classList.toggle('menu-aberto', sim);
            btn.setAttribute('aria-expanded', sim ? 'true' : 'false');
            btn.setAttribute('aria-label', sim ? 'Fechar menu' : 'Abrir menu');
            btn.textContent = sim ? '✕' : '☰';
        }
        btn.addEventListener('click', function () { abrir(!document.body.classList.contains('menu-aberto')); });
        menu.addEventListener('click', function (e) { if (e.target.closest('a')) abrir(false); });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') abrir(false); });
        window.addEventListener('resize', function () { if (window.innerWidth > 900) abrir(false); medir(); });

        var zapi = document.getElementById('zapi-status-badge');
        if (zapi && !zapi.title) zapi.title = zapi.textContent.trim();
    }

    /* ── 2. Tabelas → cards ─────────────────────────────────────────────── */
    function soControles(td) {
        var t = td.textContent.replace(/\s+/g, '');
        var ctrl = td.querySelectorAll('a,button,input,select,form').length;
        return ctrl > 0 && t.length <= 40 && !td.querySelector('strong,b');
    }

    function tratarTabela(t) {
        if (t.dataset.mobileOk || t.closest('.tabela-scroll')) return;
        t.dataset.mobileOk = '1';

        var ths = t.querySelectorAll('thead tr:last-child th');
        if (!ths.length) {
            var r0 = t.querySelector('tr');
            if (r0 && r0.querySelectorAll('th').length && !r0.querySelector('td')) ths = r0.querySelectorAll('th');
        }
        if (!ths.length || t.classList.contains('sem-cards')) {   // sem cabeçalho → rolagem horizontal
            var w = document.createElement('div');
            w.className = 'tabela-scroll';
            t.parentNode.insertBefore(w, t);
            w.appendChild(t);
            return;
        }

        var nomes = [];
        Array.prototype.forEach.call(ths, function (th) {
            var n = th.textContent.replace(/\s+/g, ' ').trim();
            var span = parseInt(th.getAttribute('colspan') || '1', 10);
            for (var i = 0; i < span; i++) nomes.push(n);
        });

        Array.prototype.forEach.call(t.querySelectorAll('tr'), function (tr) {
            if (tr.parentNode.tagName === 'THEAD' || !tr.querySelector('td')) return;
            var col = 0, titulo = false;
            Array.prototype.forEach.call(tr.children, function (td) {
                if (td.tagName !== 'TD') { col++; return; }
                var nome = nomes[col] || '';
                if (/^(a[cç][oõ]es|op[cç][oõ]es|#)?$/i.test(nome) || soControles(td) && !nome) { nome = ''; td.classList.add('td-acoes'); }
                if (!td.hasAttribute('data-label')) td.setAttribute('data-label', nome);
                if (!titulo && td.textContent.trim() && !td.hasAttribute('colspan') && !td.classList.contains('td-acoes')) {
                    td.classList.add('td-titulo'); titulo = true;
                }
                col += parseInt(td.getAttribute('colspan') || '1', 10);
            });
        });
        t.classList.add('tabela-cards');
    }

    function tratarTudo(raiz) {
        Array.prototype.forEach.call((raiz || document).querySelectorAll('table'), tratarTabela);
    }

    function iniciar() {
        montarMenu();
        tratarTudo();
        if ('MutationObserver' in window) {
            var fila = null;
            new MutationObserver(function () {
                if (fila) return;
                fila = setTimeout(function () { fila = null; tratarTudo(); }, 120);
            }).observe(document.body, { childList: true, subtree: true });
        }
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', iniciar);
    else iniciar();
})();
