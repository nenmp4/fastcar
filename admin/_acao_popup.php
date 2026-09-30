<?php /**
 * Converte o banner estático de $erro/$sucesso (renderizado por PHP, quase
 * sempre logo no topo de <main>/<body>) num popup flutuante — 30/09/2026,
 * achado real: "se fazemos uma ação status da ação aparece lá em cima só...
 * mostrar popup em todas". Desde que admin/_scroll_restore.php passou a
 * manter a página rolada onde o usuário estava depois de um "Salvar", esse
 * banner ficava fora da tela (lá em cima), o usuário não via confirmação
 * nenhuma da ação sem rolar manualmente pra cima.
 *
 * Puro JS, sem mudar NENHUMA das ~35 páginas que já renderizam esses
 * banners (cada uma com `$erro`/`$sucesso`, `$erroPromissoria`,
 * `$sincErro`/`$sincSucesso`, `$_GET['bulk_*']` etc — nomes de variável
 * diferentes, mas o MARKUP renderizado é sempre consistente: um
 * `.alerta-erro`/`.alerta-sucesso` como filho DIRETO de `<main>` ou
 * `<body>`, sempre antes de qualquer `.card`). Nunca mexe em alerta que
 * está mais profundo na árvore (ex: card "📎 Documentos" mostrando
 * "✅ Dados confirmados em..." — isso é ESTADO permanente do registro, não
 * resultado de uma ação que acabou de rodar, tem que continuar sempre
 * visível na tela, nunca sumir depois de alguns segundos) nem em elemento
 * com `id` (controlado por outro JS, ex: `#av-selecionado` em
 * admin/avaliacoes.php) ou já escondido de propósito
 * (`style="display:none"`).
 *
 * Esconde o banner original (nunca duplica a mensagem) e mostra só a
 * versão popup — clicável pra fechar antes da hora, some sozinho em 6s.
 */
?>
<script>
(function () {
    var alvos = document.querySelectorAll(
        'main > .alerta-sucesso, main > .alerta-erro, body > .alerta-sucesso, body > .alerta-erro'
    );
    if (!alvos.length) return;

    var wrap = null;
    alvos.forEach(function (el) {
        if (el.id) return; // elemento dinâmico controlado por outro JS — nunca mexe
        var styleAttr = (el.getAttribute('style') || '').replace(/\s+/g, '').toLowerCase();
        if (styleAttr.indexOf('display:none') !== -1) return; // já escondido de propósito
        if (!el.textContent.trim()) return; // vazio, nada a mostrar

        if (!wrap) {
            wrap = document.createElement('div');
            wrap.className = 'acao-popup-wrap';
            wrap.setAttribute('aria-live', 'polite');
            document.body.appendChild(wrap);
        }

        var isErro = el.classList.contains('alerta-erro');
        var popup = document.createElement('div');
        popup.className = 'acao-popup ' + (isErro ? 'acao-popup-erro' : 'acao-popup-sucesso');
        popup.innerHTML = el.innerHTML; // preserva <strong>/emoji já formatado no banner original
        wrap.appendChild(popup);

        var tempo = setTimeout(fechar, 6000);
        function fechar() {
            clearTimeout(tempo);
            popup.classList.add('acao-popup-saindo');
            setTimeout(function () { popup.remove(); }, 300);
        }
        popup.addEventListener('click', fechar);

        el.style.display = 'none';
    });
})();
</script>
