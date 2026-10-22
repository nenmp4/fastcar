<?php /**
 * Badge de status da instância DEDICADA de VENDAS — mesmo padrão exato de
 * admin/_zapi_status.php (canal principal), só que checando o canal de
 * vendas (canalVendasStatusCache(), includes/whatsapp_config.php). 22/10/2026,
 * "em vendadas precisa mostrar se instancia de vendas está conectada" —
 * antes disso, as telas do módulo de vendas incluíam o badge do canal
 * PRINCIPAL (compra/leads), que não diz nada sobre se o número que o
 * vendedor usa pra falar com o comprador está no ar.
 *
 * Incluído só nas telas do módulo de vendas (admin/vendas.php, venda.php,
 * vendas_inbox.php, promissorias.php) — NO LUGAR de _zapi_status.php, nunca
 * junto (2 badges na mesma topbar seria ruído, e o canal de compra não é
 * relevante nessas telas).
 *
 * Renderizado server-side com o valor já em cache (60s TTL) e atualizado
 * via polling leve, mesma economia de bateria/rede do badge principal
 * (pausa quando a aba fica em segundo plano).
 */
$canalVendasStatusInicial = canalVendasStatusCache();
$zapiVendasPodeVerSaude = ($_SESSION['admin_perfil'] ?? '') === 'super_admin';
?>
<?php if ($zapiVendasPodeVerSaude): ?>
<a id="zapi-status-badge-vendas" class="zapi-status-badge" href="/admin/saude.php" title="Ver diagnóstico completo em Saúde do sistema">
<?php else: ?>
<div id="zapi-status-badge-vendas" class="zapi-status-badge">
<?php endif; ?>
    <span id="zapi-status-texto-vendas">⚪ Instância de vendas…</span>
<?= $zapiVendasPodeVerSaude ? '</a>' : '</div>' ?>

<script>
(function () {
    var el = document.getElementById('zapi-status-badge-vendas');
    var texto = document.getElementById('zapi-status-texto-vendas');
    var ROTULOS = {
        zapi: {
            conectado: ['🟢', 'Z-API (vendas) conectado'],
            desconectado: ['🔴', 'Z-API (vendas) desconectado'],
            erro: ['🟡', 'Z-API (vendas) — erro ao verificar'],
            nao_configurado: ['⚪', 'Z-API (vendas) não configurado']
        },
        oficial: {
            conectado: ['🟢', 'Meta (vendas) conectado'],
            desconectado: ['🔴', 'Meta (vendas) — token inválido'],
            erro: ['🟡', 'Meta (vendas) — erro ao verificar'],
            nao_configurado: ['⚪', 'Meta (vendas) não configurado']
        },
        evolution: {
            conectado: ['🟣', 'Evolution (vendas) conectado'],
            desconectado: ['🔴', 'Evolution (vendas) desconectado'],
            erro: ['🟡', 'Evolution (vendas) — erro ao verificar'],
            nao_configurado: ['⚪', 'Evolution (vendas) não configurado']
        }
    };

    function aplicar(estado, provider) {
        var grupo = ROTULOS[provider] || ROTULOS.zapi;
        var r = grupo[estado] || grupo.erro;
        texto.textContent = r[0] + ' ' + r[1];
        el.classList.remove('zapi-status-ok', 'zapi-status-off', 'zapi-status-warn');
        el.classList.add(estado === 'conectado' ? 'zapi-status-ok' : (estado === 'desconectado' ? 'zapi-status-off' : 'zapi-status-warn'));
    }

    aplicar(<?= json_encode($canalVendasStatusInicial['estado']) ?>, <?= json_encode($canalVendasStatusInicial['provider']) ?>);

    function checar() {
        if (document.hidden) return; // mesma economia do badge principal — não gasta requisição com aba em segundo plano
        fetch('/admin/zapi_status_vendas_ajax.php', { credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (data) { if (data && data.estado) aplicar(data.estado, data.provider); })
            .catch(function () { /* falha de rede — mantém o último estado conhecido, tenta de novo no próximo ciclo */ });
    }

    setInterval(checar, 45000);
})();
</script>
