<?php /**
 * Preserva a posição de rolagem ao redor de um submit de formulário —
 * 30/09/2026, achado real: "quando abrimos cliente tem ficar rolando pra
 * baixo... preencho algo ainda rola pra baixo, [tenho que] voltar". Telas
 * como admin/oportunidade.php/admin/venda.php/admin/cliente_detalhe.php
 * têm dezenas de <form> pequenos espalhados pela página (dados do
 * veículo, financiamento, documentos, avaliação...) — cada "Salvar" faz
 * um POST clássico (nunca AJAX) que recarrega a página inteira do zero,
 * sempre voltando pro TOPO, mesmo que o formulário editado estivesse lá
 * embaixo — o usuário precisava rolar de novo até achar onde estava.
 *
 * Solução genérica via sessionStorage (não precisa de âncora `#id` nem
 * mexer em cada um dos formulários espalhados pelo projeto): guarda
 * scrollY no momento do submit, sob uma chave por página+querystring
 * (nunca cruza entre registros diferentes — `?id=1` não restaura a
 * rolagem salva por `?id=2`); a próxima carga da MESMA página+query
 * consome a chave 1x (nunca restaura de novo num F5 manual posterior,
 * já que é removida assim que lida) e rola de volta. Roda 2x (na hora +
 * no `load`) porque imagem/fonte carregando depois pode empurrar o
 * layout um pouco — a 2ª chamada corrige isso.
 *
 * Incluído no mesmo ponto (perto do fim do body) das ~34 páginas cheias
 * do admin que já incluem _notify.php — script isolado, sem relação com
 * notificação, só reaproveitando o mesmo ponto de inclusão já bulk-
 * inserido em todo lugar que precisa.
 */
?>
<script>
(function () {
    var CHAVE = 'fastcar_scroll_' + location.pathname + location.search;

    document.addEventListener('submit', function () {
        try { sessionStorage.setItem(CHAVE, String(window.scrollY)); } catch (e) { /* sessionStorage indisponível — só não restaura, nunca quebra a página */ }
    }, true); // captura, pra pegar mesmo se algum handler do próprio form parar a propagação

    var salvo = null;
    try {
        salvo = sessionStorage.getItem(CHAVE);
        sessionStorage.removeItem(CHAVE); // consome 1x — F5 manual depois não restaura de novo
    } catch (e) { /* segue sem restaurar */ }

    if (salvo === null) return;
    var y = parseInt(salvo, 10);
    if (!isFinite(y) || y <= 40) return; // perto do topo já não precisa

    function restaurar() { window.scrollTo({ top: y, behavior: 'auto' }); }
    restaurar();
    window.addEventListener('load', restaurar);
})();
</script>
