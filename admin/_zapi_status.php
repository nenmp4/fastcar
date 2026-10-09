<?php /**
 * Badge de status do CANAL PRINCIPAL (compra/leads) — incluído em toda
 * página cheia do admin (mesmo padrão do _notify.php — position:fixed,
 * sem tocar na estrutura própria de cada página) — 23/09/2026, "tem como
 * colocar status da instancia topo zpi conectado em destaque ai eu não
 * preciso ir no saude ver". Só a principal (compra/leads) — as dedicadas
 * de vendas/financeiro não entram aqui, mesmo espírito de escopo enxuto já
 * documentado no projeto; ver CLAUDE.md se um dia pedirem as 3.
 *
 * 28/09/2026, "vamos remover fallback" — a instância fallback (e o estado
 * `reserva_conectado` que ela alimentava aqui) foi removida do projeto.
 *
 * 29/09/2026, "mudei mais dica zpi bolinha" — achado real: o badge sempre
 * mostrava status da Z-API mesmo depois do toggle `whatsapp_provider_principal`
 * já estar em 'oficial' (Meta). Trocado pra `canalPrincipalStatusCache()`
 * (includes/whatsapp_config.php), que devolve `provider` junto — o rótulo
 * agora reflete o canal DE VERDADE, não sempre Z-API.
 *
 * Renderizado server-side com o valor já em cache (60s de TTL, nunca bate
 * no provedor a cada carregamento de página) e atualizado via polling leve,
 * só pra não ficar preso no valor de quando a página abriu se a conexão
 * cair/voltar enquanto o consultor está com a aba aberta.
 */
$canalStatusInicial = canalPrincipalStatusCache();
$zapiPodeVerSaude = ($_SESSION['admin_perfil'] ?? '') === 'super_admin';
?>
<?php if ($zapiPodeVerSaude): ?>
<a id="zapi-status-badge" class="zapi-status-badge" href="/admin/saude.php" title="Ver diagnóstico completo em Saúde do sistema">
<?php else: ?>
<div id="zapi-status-badge" class="zapi-status-badge">
<?php endif; ?>
    <span id="zapi-status-texto">⚪ WhatsApp…</span>
<?= $zapiPodeVerSaude ? '</a>' : '</div>' ?>

<script>
(function () {
    var el = document.getElementById('zapi-status-badge');
    var texto = document.getElementById('zapi-status-texto');
    var ROTULOS = {
        zapi: {
            conectado: ['🟢', 'Z-API conectado'],
            desconectado: ['🔴', 'Z-API desconectado'],
            erro: ['🟡', 'Z-API — erro ao verificar'],
            nao_configurado: ['⚪', 'Z-API não configurado']
        },
        oficial: {
            conectado: ['🟢', 'Meta (oficial) conectado'],
            desconectado: ['🔴', 'Meta (oficial) — token inválido'],
            erro: ['🟡', 'Meta (oficial) — erro ao verificar'],
            nao_configurado: ['⚪', 'Meta (oficial) não configurado']
        },
        evolution: {
            conectado: ['🟣', 'Evolution conectado'],
            desconectado: ['🔴', 'Evolution desconectado'],
            erro: ['🟡', 'Evolution — erro ao verificar'],
            nao_configurado: ['⚪', 'Evolution não configurado']
        }
    };

    function aplicar(estado, provider) {
        var grupo = ROTULOS[provider] || ROTULOS.zapi;
        var r = grupo[estado] || grupo.erro;
        texto.textContent = r[0] + ' ' + r[1];
        el.classList.remove('zapi-status-ok', 'zapi-status-off', 'zapi-status-warn');
        el.classList.add(estado === 'conectado' ? 'zapi-status-ok' : (estado === 'desconectado' ? 'zapi-status-off' : 'zapi-status-warn'));
    }

    aplicar(<?= json_encode($canalStatusInicial['estado']) ?>, <?= json_encode($canalStatusInicial['provider']) ?>);

    function checar() {
        if (document.hidden) return; // mesma economia do sino — não gasta requisição com aba em segundo plano
        fetch('/admin/zapi_status_ajax.php', { credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (data) { if (data && data.estado) aplicar(data.estado, data.provider); })
            .catch(function () { /* falha de rede — mantém o último estado conhecido, tenta de novo no próximo ciclo */ });
    }

    setInterval(checar, 45000);
})();
</script>
