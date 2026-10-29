/*
 * Wizard de documentos (29/10/2026, "link da locação pra subir documentos não
 * tá indo, cliente está tentando"): foto de celular costuma ter 4-12MB e o
 * servidor descarta o envio inteiro quando passa do limite — o cliente via
 * "Sessão expirada" e tentava de novo pra sempre. Aqui a foto é reduzida no
 * próprio aparelho (máx. 2000px, JPEG ~85%) antes de enviar. PDF e navegador
 * sem suporte seguem como estão — o servidor continua validando tudo.
 */
(function () {
    var LIMITE = 1.5 * 1024 * 1024;
    function compactar(arquivo) {
        return new Promise(function (ok) {
            if (!arquivo || !/^image\//.test(arquivo.type) || arquivo.size <= LIMITE || !window.HTMLCanvasElement) return ok(null);
            var url = URL.createObjectURL(arquivo);
            var img = new Image();
            img.onload = function () {
                var max = 2000, w = img.naturalWidth, h = img.naturalHeight;
                var esc = Math.min(1, max / Math.max(w, h));
                var cv = document.createElement('canvas');
                cv.width = Math.round(w * esc); cv.height = Math.round(h * esc);
                cv.getContext('2d').drawImage(img, 0, 0, cv.width, cv.height);
                URL.revokeObjectURL(url);
                cv.toBlob(function (b) {
                    if (!b || b.size >= arquivo.size) return ok(null);
                    ok(new File([b], (arquivo.name || 'foto').replace(/\.[^.]+$/, '') + '.jpg', { type: 'image/jpeg' }));
                }, 'image/jpeg', 0.85);
            };
            img.onerror = function () { URL.revokeObjectURL(url); ok(null); };
            img.src = url;
        });
    }
    document.addEventListener('submit', function (e) {
        var form = e.target;
        var input = form.querySelector && form.querySelector('input[type=file][name=arquivo]');
        if (!input || !input.files || !input.files[0] || form.dataset.compactado === '1') return;
        if (typeof DataTransfer === 'undefined') return;
        e.preventDefault();
        var botao = form.querySelector('button[type=submit],button:not([type])');
        if (botao) { botao.disabled = true; botao.dataset.txt = botao.textContent; botao.textContent = 'Enviando...'; }
        compactar(input.files[0]).then(function (novo) {
            if (novo) { var dt = new DataTransfer(); dt.items.add(novo); input.files = dt.files; }
        }).catch(function () {}).then(function () {
            form.dataset.compactado = '1';
            if (botao) botao.disabled = false;
            if (form.requestSubmit) form.requestSubmit(botao || undefined); else form.submit();
        });
    }, true);
})();
