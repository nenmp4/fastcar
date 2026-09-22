<?php /** Registro do service worker — include antes de </body>. */ ?>
<script>
if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('/admin/sw.js', { scope: '/admin/' }).catch(function(){});
}

// Banner próprio de "Instalar app" (22/09/2026, "não aparece popup para
// instalar app no mobile") — Chrome/Android só mostra o mini-infobar
// automático depois de heurísticas de engajamento que variam e podem nunca
// disparar sozinhas; Safari/iOS NUNCA tem prompt automático (Apple não
// oferece beforeinstallprompt) — só "Compartilhar → Adicionar à Tela de
// Início", manual, sem like nenhum jeito de disparar via JS. Banner próprio
// cobre os 2 casos: Android captura o evento nativo (menos dependente da
// heurística do navegador, o próprio clique já conta como sinal de
// engajamento), iOS mostra instrução manual.
(function () {
    try {
        var jaInstalado = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
        if (jaInstalado) return;
        if (localStorage.getItem('fastcar_pwa_banner_dispensado') === '1') return;
    } catch (e) { return; }

    var deferredPrompt = null;
    var ehIOS = /iphone|ipad|ipod/i.test(navigator.userAgent) && !window.MSStream;

    function criarBanner(textoBotao, aoClicar) {
        var banner = document.createElement('div');
        banner.style.cssText = 'position:fixed;left:12px;right:12px;bottom:12px;z-index:9999;'
            + 'background:#151722;color:#fff;padding:14px 16px;border-radius:12px;'
            + 'box-shadow:0 6px 24px rgba(0,0,0,.35);display:flex;align-items:center;gap:12px;'
            + 'font-family:inherit;font-size:14px;line-height:1.4;';
        var texto = document.createElement('span');
        texto.style.flex = '1';
        texto.textContent = ehIOS
            ? 'Instale o Fastcar CRM: toque em Compartilhar e depois em "Adicionar à Tela de Início".'
            : 'Instale o Fastcar CRM na tela inicial pra acesso rápido.';
        var botao = document.createElement('button');
        botao.type = 'button';
        botao.textContent = textoBotao;
        botao.style.cssText = 'background:#2f6fed;color:#fff;border:none;border-radius:8px;'
            + 'padding:8px 14px;font-size:14px;white-space:nowrap;cursor:pointer;flex-shrink:0;';
        botao.addEventListener('click', aoClicar);
        var fechar = document.createElement('button');
        fechar.type = 'button';
        fechar.textContent = '×';
        fechar.setAttribute('aria-label', 'Fechar');
        fechar.style.cssText = 'background:none;border:none;color:#9aa4b8;font-size:20px;'
            + 'line-height:1;cursor:pointer;padding:0 4px;flex-shrink:0;';
        fechar.addEventListener('click', function () {
            banner.remove();
            try { localStorage.setItem('fastcar_pwa_banner_dispensado', '1'); } catch (e) {}
        });
        banner.appendChild(texto);
        banner.appendChild(botao);
        banner.appendChild(fechar);
        document.body.appendChild(banner);
        return banner;
    }

    if (ehIOS) {
        // Sem beforeinstallprompt no iOS — só mostra a instrução manual.
        // "Entendi" some o banner (não conta como recusa permanente, o
        // usuário pode simplesmente não ter visto na hora certa).
        criarBanner('Entendi', function (ev) {
            ev.target.closest('div').remove();
        });
        return;
    }

    window.addEventListener('beforeinstallprompt', function (ev) {
        ev.preventDefault();
        deferredPrompt = ev;
        var banner = criarBanner('Instalar', function () {
            if (!deferredPrompt) return;
            deferredPrompt.prompt();
            deferredPrompt.userChoice.finally(function () {
                deferredPrompt = null;
                banner.remove();
                try { localStorage.setItem('fastcar_pwa_banner_dispensado', '1'); } catch (e) {}
            });
        });
    });

    window.addEventListener('appinstalled', function () {
        try { localStorage.setItem('fastcar_pwa_banner_dispensado', '1'); } catch (e) {}
    });
})();
</script>
