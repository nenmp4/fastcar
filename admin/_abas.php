<?php
/**
 * Abas nas telas longas de detalhe (oportunidade/venda) — 27/10/2026,
 * "quando abrimos cliente tem rolar até embaixo, deixar em abas".
 *
 * Sem framework e sem mexer no HTML dos cards: todo filho DIRETO de <main>
 * com data-aba="Nome" vira conteúdo daquela aba; o que não tem data-aba
 * (cabeçalho, alertas de sucesso/erro, scripts) fica sempre visível.
 * A barra de abas entra logo antes do 1º elemento com data-aba.
 *
 * Aba ativa: ?aba= na URL, senão a guardada pra esta página+query
 * (sessionStorage — ao salvar um formulário a página recarrega e volta na
 * MESMA aba onde o usuário estava), senão a 1ª. Abas vazias (todos os
 * cards condicionais delas ausentes) nunca aparecem.
 */
// 27/10/2026, "volta layout anterior, deixa como opção em configurações" —
// desligado por padrão; liga em Configurações → 🗂️ Layout. Desligado, os
// atributos data-aba no HTML não fazem nada (página inteira, como antes).
if (getConfig('layout_abas_detalhe') !== '1') return;
?>
<style>
.abas-nav { display:flex; gap:6px; flex-wrap:nowrap; overflow-x:auto; margin:4px 0 14px;
    border-bottom:2px solid var(--borda); scroll-snap-type:x proximity; -webkit-overflow-scrolling:touch; }
.abas-nav button { margin:0; box-shadow:none; background:transparent; color:var(--texto-suave);
    border:none; border-bottom:3px solid transparent; border-radius:0; padding:10px 14px; min-height:44px;
    font-size:14px; font-weight:600; white-space:nowrap; cursor:pointer; scroll-snap-align:start; }
.abas-nav button:hover { color:var(--azul); background:transparent; }
.abas-nav button.ativa { color:var(--azul); border-bottom-color:var(--azul); }
.abas-nav .abas-qtd { font-size:11px; font-weight:600; opacity:.7; margin-left:4px; }
main > [data-aba].aba-oculta { display:none !important; }
</style>
<script>
(function () {
    var main = document.querySelector('main');
    if (!main) return;
    var itens = Array.prototype.filter.call(main.children, function (el) { return el.hasAttribute('data-aba'); });
    if (itens.length === 0) return;

    var nomes = [];
    itens.forEach(function (el) {
        var n = el.getAttribute('data-aba');
        if (nomes.indexOf(n) === -1) nomes.push(n);
    });
    // Ordem declarada (opcional) em <main data-abas-ordem="A|B|C">
    var ordem = (main.getAttribute('data-abas-ordem') || '').split('|').filter(Boolean);
    if (ordem.length) {
        nomes.sort(function (a, b) {
            var ia = ordem.indexOf(a), ib = ordem.indexOf(b);
            return (ia === -1 ? 999 : ia) - (ib === -1 ? 999 : ib);
        });
    }

    var chave = 'aba:' + location.pathname + location.search.replace(/[?&]aba=[^&]*/, '');
    var inicial = null;
    try { inicial = new URLSearchParams(location.search).get('aba'); } catch (e) {}
    if (!inicial || nomes.indexOf(inicial) === -1) {
        try { inicial = sessionStorage.getItem(chave); } catch (e) { inicial = null; }
    }
    if (!inicial || nomes.indexOf(inicial) === -1) inicial = nomes[0];

    var nav = document.createElement('nav');
    nav.className = 'abas-nav';
    nav.setAttribute('role', 'tablist');
    var botoes = {};
    nomes.forEach(function (n) {
        var b = document.createElement('button');
        b.type = 'button';
        b.setAttribute('role', 'tab');
        b.textContent = n;
        b.addEventListener('click', function () { ativar(n, true); });
        nav.appendChild(b);
        botoes[n] = b;
    });
    main.insertBefore(nav, itens[0]);

    function ativar(nome, rolar) {
        itens.forEach(function (el) {
            el.classList.toggle('aba-oculta', el.getAttribute('data-aba') !== nome);
        });
        nomes.forEach(function (n) {
            botoes[n].classList.toggle('ativa', n === nome);
            botoes[n].setAttribute('aria-selected', n === nome ? 'true' : 'false');
        });
        try { sessionStorage.setItem(chave, nome); } catch (e) {}
        if (rolar) {
            var y = nav.getBoundingClientRect().top + window.scrollY - 70;
            if (window.scrollY > y) window.scrollTo(0, Math.max(0, y));
        }
    }
    ativar(inicial, false);

    // Link interno pra um card de outra aba (ex.: #zapcar-card) abre a aba certa.
    function abrirPorHash() {
        if (!location.hash) return;
        var alvo = document.getElementById(location.hash.slice(1));
        while (alvo && alvo.parentElement !== main) alvo = alvo.parentElement;
        if (alvo && alvo.hasAttribute('data-aba')) ativar(alvo.getAttribute('data-aba'), false);
    }
    abrirPorHash();
    window.addEventListener('hashchange', abrirPorHash);
    window.abrirAba = function (nome) { if (botoes[nome]) ativar(nome, true); };
})();
</script>
