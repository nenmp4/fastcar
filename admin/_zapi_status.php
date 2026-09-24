<?php /**
 * Badge de status da Z-API — instância PRINCIPAL (compra/leads), com
 * leitura de FALLBACK quando a principal está fora — incluído em toda
 * página cheia do admin (mesmo padrão do _notify.php — position:fixed,
 * sem tocar na estrutura própria de cada página) — 23/09/2026, "tem como
 * colocar status da instancia topo zpi conectado em destaque ai eu não
 * preciso ir no saude ver". Só as instâncias principal/fallback (compra/
 * leads) — as dedicadas de vendas/financeiro não entram aqui, mesmo
 * espírito de escopo enxuto já documentado no projeto; ver CLAUDE.md se
 * um dia pedirem os 3.
 *
 * **24/09/2026, achado real** ("Conectou o reserva lá na zpi mais no
 * painel a bolinha ficar vermelha deveria mudar para zpi reserva
 * Conectado") — antes disso o badge só olhava a principal: reconectar a
 * fallback nunca tirava o vermelho, mesmo sendo ela quem está atendendo
 * de verdade. Agora usa zapiStatusOperacionalCache() (includes/whatsapp_config.php),
 * que cai pra fallback quando a principal está fora e devolve um estado
 * PRÓPRIO (`reserva_conectado`, amarelo — nunca verde, é operação de
 * emergência, precisa continuar visualmente diferente do normal).
 *
 * Renderizado server-side com o valor já em cache (60s de TTL por
 * instância, nunca bate na Z-API a cada carregamento de página) e
 * atualizado via polling leve, só pra não ficar preso no valor de quando
 * a página abriu se a conexão cair/voltar enquanto o consultor está com
 * a aba aberta.
 */
$zapiStatusInicial = zapiStatusOperacionalCache();
$zapiPodeVerSaude = ($_SESSION['admin_perfil'] ?? '') === 'super_admin';
?>
<?php if ($zapiPodeVerSaude): ?>
<a id="zapi-status-badge" class="zapi-status-badge" href="/admin/saude.php" title="Ver diagnóstico completo em Saúde do sistema">
<?php else: ?>
<div id="zapi-status-badge" class="zapi-status-badge">
<?php endif; ?>
    <span id="zapi-status-texto">⚪ Z-API…</span>
<?= $zapiPodeVerSaude ? '</a>' : '</div>' ?>

<script>
(function () {
    var el = document.getElementById('zapi-status-badge');
    var texto = document.getElementById('zapi-status-texto');
    var ROTULOS = {
        conectado: ['🟢', 'Z-API conectado'],
        reserva_conectado: ['🟡', 'Z-API (reserva) conectado'],
        desconectado: ['🔴', 'Z-API desconectado'],
        erro: ['🟡', 'Z-API — erro ao verificar'],
        nao_configurado: ['⚪', 'Z-API não configurado']
    };

    function aplicar(estado) {
        var r = ROTULOS[estado] || ROTULOS.erro;
        texto.textContent = r[0] + ' ' + r[1];
        el.classList.remove('zapi-status-ok', 'zapi-status-off', 'zapi-status-warn');
        el.classList.add(estado === 'conectado' ? 'zapi-status-ok' : (estado === 'desconectado' ? 'zapi-status-off' : 'zapi-status-warn'));
    }

    aplicar(<?= json_encode($zapiStatusInicial['estado']) ?>);

    function checar() {
        if (document.hidden) return; // mesma economia do sino — não gasta requisição com aba em segundo plano
        fetch('/admin/zapi_status_ajax.php', { credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (data) { if (data && data.estado) aplicar(data.estado); })
            .catch(function () { /* falha de rede — mantém o último estado conhecido, tenta de novo no próximo ciclo */ });
    }

    setInterval(checar, 45000);
})();
</script>
