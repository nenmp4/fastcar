<?php
/**
 * Topbar única de todo o admin + breadcrumb (05/11/2026, plano de UX
 * CLAUDE_2.md, item A1). Antes cada uma das ~40 páginas tinha a própria
 * topbar copiada à mão — links diferentes em cada tela (Configurações em
 * umas, Veículos em outras, "← Voltar" apontando pra lugares diferentes) e
 * a do painel de compra com 25 links soltos quebrando em 3 linhas.
 *
 * Agora: 1 linha só, links agrupados em menus (Funil / Cadastros /
 * Financeiro / Relatórios / Admin), cada item filtrado pelo perfil (mesma
 * regra das travas de cada página e do allowlist central dos perfis
 * vendedor/financeiro/avaliador em _bootstrap.php — nunca mostra link que
 * daria 403/redirecionaria). "← Voltar" virou breadcrumb (Início › Pai ›
 * Página). No celular, mobile.js monta a gaveta a partir desses mesmos
 * grupos.
 *
 * Variáveis opcionais que a página pode definir ANTES do include:
 *   $tbTitulo  — título da página atual no breadcrumb (ex: "Venda #12")
 *   $tbPai     — [rótulo, url] pai no breadcrumb, quando difere do mapa
 */

// 06/11/2026 — TUDO roda dentro de uma função: incluído direto no escopo da
// página, o foreach daqui sobrescrevia variáveis da própria página (ex:
// $itens do checklist em admin/avaliacao.php → "Erro interno" em toda
// vistoria). Nada deste arquivo pode vazar pro escopo de quem inclui.
(static function (?string $tbTitulo, ?array $tbPai): void {

$tbPerfil = (string)($_SESSION['admin_perfil'] ?? '');
$tbAqui = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
$tbSuper = $tbPerfil === 'super_admin';
$tbCompra = in_array($tbPerfil, ['super_admin', 'supervisor', 'consultor'], true);
$tbVeTudo = perfilVeTudo();
$tbVendas = podeAcessarVendas();
$tbFin = podeAcessarFinanceiro();
$tbAval = podeAcessarAvaliacoes();
$tbHome = paginaInicialPorPerfil($tbPerfil);

// [rótulo, href, visível]
$tbGrupos = [
    'Funil' => [
        ['🧲 Painel de compra', '/admin/index.php', $tbCompra],
        ['💰 Vendas', '/admin/vendas.php', $tbVendas],
        ['💬 WhatsApp compra', '/admin/whatsapp_inbox.php', $tbCompra],
        ['💬 WhatsApp vendas', '/admin/vendas_inbox.php', $tbVendas],
        ['📋 Pendências pós-venda', '/admin/pendencias_pos_venda.php', $tbCompra],
        ['🔍 Vistorias', '/admin/avaliacoes.php', $tbAval],
    ],
    'Cadastros' => [
        ['👥 Clientes', $tbPerfil === 'vendedor' ? '/admin/clientes.php?aba=compradores' : '/admin/clientes.php',
            $tbCompra || $tbPerfil === 'financeiro' || $tbPerfil === 'vendedor'],
        ['🚗 Veículos (frota)', '/admin/veiculos.php', $tbSuper],
        ['🚘 Estoque', '/admin/estoque.php', $tbVendas],
        ['📦 Patrimônio', '/admin/patrimonio.php', $tbSuper],
    ],
    'Financeiro' => [
        ['🧾 Financeiro', '/admin/financeiro.php', $tbFin],
        ['📒 Lançamentos', '/admin/financeiro-lancamentos.php', $tbFin],
        ['📈 Relatórios financeiros', '/admin/financeiro-relatorios.php', $tbFin],
        ['💬 WhatsApp cobrança', '/admin/financeiro_inbox.php', $tbFin],
        ['📊 Lucro por veículo', '/admin/relatorio_lucro_veiculos.php', $tbSuper],
        ['💳 Promissórias', '/admin/promissorias.php', $tbVendas],
    ],
    'Relatórios' => [
        ['🔎 Pra onde foram os leads', '/admin/leads_rastreio.php', $tbCompra],
        ['📊 Produtividade', '/admin/produtividade.php', $tbVeTudo],
        ['📣 Origem dos leads', '/admin/origem_leads.php', $tbVeTudo],
        ['💸 Custo por lead', '/admin/relatorio_cpl.php', $tbVeTudo],
        ['🤖 Qualidade da IA', '/admin/qualidade_ia.php', $tbVeTudo],
        ['🌙 Acesso fora do horário', '/admin/acesso_expediente.php', $tbVeTudo],
    ],
    'Admin' => [
        ['👤 Usuários', '/admin/usuarios.php', $tbSuper],
        ['🕵️ Auditoria', '/admin/auditoria.php', $tbSuper],
        ['🛡️ Conformidade WhatsApp', '/admin/whatsapp_conformidade.php', $tbVeTudo],
        ['💾 Backup', '/admin/backup.php', $tbSuper],
        ['🩺 Saúde do sistema', '/admin/saude.php', $tbSuper],
        ['⚙️ Configurações', '/admin/configuracoes.php', $tbSuper],
    ],
];

// Mapa do breadcrumb: página => [título, página pai (ou null = só Início)].
$tbMapa = [
    'index.php' => ['Painel de compra', null],
    'oportunidade.php' => ['Oportunidade', 'index.php'],
    'whatsapp_inbox.php' => ['WhatsApp compra', 'index.php'],
    'pendencias_pos_venda.php' => ['Pendências pós-venda', 'index.php'],
    'leads_rastreio.php' => ['Pra onde foram os leads', 'index.php'],
    'vendas.php' => ['Vendas', null],
    'venda.php' => ['Venda', 'vendas.php'],
    'vendas_inbox.php' => ['WhatsApp vendas', 'vendas.php'],
    'promissorias.php' => ['Promissórias', 'vendas.php'],
    'estoque.php' => ['Estoque', 'vendas.php'],
    'avaliacoes.php' => ['Vistorias', null],
    'avaliacao.php' => ['Vistoria', 'avaliacoes.php'],
    'clientes.php' => ['Clientes', null],
    'cliente_detalhe.php' => ['Cliente', 'clientes.php'],
    'veiculos.php' => ['Veículos (frota)', null],
    'veiculo_midias.php' => ['Fotos e vídeos do veículo', 'veiculos.php'],
    'veiculo_historico.php' => ['Histórico do veículo', 'veiculos.php'],
    'relatorio_lucro_veiculos.php' => ['Lucro por veículo', 'veiculos.php'],
    'patrimonio.php' => ['Patrimônio', null],
    'financeiro.php' => ['Financeiro', null],
    'financeiro-lancamentos.php' => ['Lançamentos', 'financeiro.php'],
    'financeiro-categorias.php' => ['Categorias', 'financeiro.php'],
    'financeiro-fornecedores.php' => ['Fornecedores', 'financeiro.php'],
    'financeiro-colaboradores.php' => ['Colaboradores', 'financeiro.php'],
    'financeiro-asaas.php' => ['Asaas', 'financeiro.php'],
    'financeiro-relatorios.php' => ['Relatórios financeiros', 'financeiro.php'],
    'financeiro-empresa.php' => ['Dados da empresa', 'financeiro-relatorios.php'],
    'financeiro_inbox.php' => ['WhatsApp cobrança', 'financeiro.php'],
    'relatorio_mes.php' => ['Relatório do mês', 'financeiro.php'],
    'produtividade.php' => ['Produtividade', null],
    'origem_leads.php' => ['Origem dos leads', null],
    'relatorio_cpl.php' => ['Custo por lead', 'origem_leads.php'],
    'qualidade_ia.php' => ['Qualidade da IA', null],
    'acesso_expediente.php' => ['Acesso fora do horário', null],
    'usuarios.php' => ['Usuários', null],
    'auditoria.php' => ['Auditoria', null],
    'backup.php' => ['Backup', null],
    'saude.php' => ['Saúde do sistema', null],
    'configuracoes.php' => ['Configurações', null],
    'whatsapp_conformidade.php' => ['Conformidade WhatsApp', 'configuracoes.php'],
    'zapsign_importar.php' => ['Importar contratos da ZapSign', 'configuracoes.php'],
    'meu_perfil.php' => ['Meu perfil', null],
];

// Páginas que cada perfil enxerga (pra nunca linkar um pai que daria 403).
$tbVisiveis = [];
foreach ($tbGrupos as $itens) {
    foreach ($itens as [$rot, $href, $vis]) {
        if ($vis) $tbVisiveis[basename(strtok($href, '?'))] = $href;
    }
}
$tbVisiveis['configuracoes.php'] = $tbSuper ? '/admin/configuracoes.php' : null;

$tbAtivo = static function (string $href) use ($tbAqui): bool {
    return basename((string)strtok($href, '?')) === $tbAqui;
};

// Breadcrumb
$tbMigalhas = [];
$tbInfo = $tbMapa[$tbAqui] ?? null;
$tbPaiArq = $tbInfo[1] ?? null;
if ($tbPai !== null) {
    $tbMigalhas[] = [$tbPai[0], $tbPai[1]];
} elseif ($tbPaiArq && !empty($tbVisiveis[$tbPaiArq]) && '/admin/' . $tbPaiArq !== $tbHome) {
    $tbMigalhas[] = [$tbMapa[$tbPaiArq][0] ?? $tbPaiArq, $tbVisiveis[$tbPaiArq]];
}
$tbTituloAtual = $tbTitulo !== null && $tbTitulo !== '' ? $tbTitulo : ($tbInfo[0] ?? '');
$tbEhHome = '/admin/' . $tbAqui === $tbHome && $tbTitulo === null;
$tbNome = (string)($_SESSION['admin_nome'] ?? '');
?>
<header class="topbar topbar-v2">
    <a class="topbar-brand" href="<?= e($tbHome) ?>"><img class="topbar-logo" src="/admin/assets/img/icon-192.png" alt="Fastcar" onerror="this.style.display='none'"><span class="topbar-wordmark">Fast<b>Car</b></span></a>
    <nav class="tb-nav" aria-label="Menu principal">
        <?php foreach ($tbGrupos as $grupo => $itens):
            $vis = array_filter($itens, static fn($i) => $i[2]);
            if (!$vis) continue;
            $temAtual = (bool)array_filter($vis, static fn($i) => $tbAtivo($i[1]));
        ?>
            <details class="tb-grupo<?= $temAtual ? ' tem-atual' : '' ?>" data-grupo="<?= e($grupo) ?>">
                <summary><?= e($grupo) ?></summary>
                <div class="tb-drop">
                    <?php foreach ($vis as [$rot, $href]): ?>
                        <a href="<?= e($href) ?>"<?= $tbAtivo($href) ? ' class="atual" aria-current="page"' : '' ?>><?= e($rot) ?></a>
                    <?php endforeach; ?>
                </div>
            </details>
        <?php endforeach; ?>
    </nav>
    <?php if ($tbPerfil === 'consultor' && function_exists('buscarUsuario')):
        $tbEu = buscarUsuario((int)$_SESSION['admin_id']); ?>
        <form method="post" action="/admin/toggle_disponivel.php" class="inline tb-disponivel">
            <?= csrfField() ?>
            <input type="hidden" name="voltar" value="<?= e((string)($_SERVER['REQUEST_URI'] ?? '/admin/index.php')) ?>">
            <button type="submit"><?= !empty($tbEu['disponivel']) ? '🟢 Disponível' : '⚪ Offline' ?></button>
        </form>
    <?php endif; ?>
    <details class="tb-conta" data-nome="<?= e($tbNome) ?>">
        <summary title="<?= e($tbNome) ?>">👤 <span class="tb-conta-nome"><?= e($tbNome) ?></span></summary>
        <div class="tb-drop tb-drop-dir">
            <a href="/admin/meu_perfil.php"<?= $tbAqui === 'meu_perfil.php' ? ' class="atual" aria-current="page"' : '' ?>>🙋 Meu perfil</a>
            <a href="/admin/logout.php" class="tb-sair">Sair</a>
        </div>
    </details>
</header>
<?php if (!$tbEhHome && $tbTituloAtual !== ''): ?>
<nav class="breadcrumb" aria-label="Você está em">
    <a href="<?= e($tbHome) ?>">Início</a>
    <?php foreach ($tbMigalhas as [$rot, $href]): ?>
        <span aria-hidden="true">›</span><a href="<?= e($href) ?>"><?= e($rot) ?></a>
    <?php endforeach; ?>
    <span aria-hidden="true">›</span><span aria-current="page"><?= e($tbTituloAtual) ?></span>
</nav>
<?php endif; ?>
<script>
(function () {
    // Só 1 menu aberto por vez; fecha clicando fora ou com Esc.
    var grupos = document.querySelectorAll('.topbar-v2 details');
    grupos.forEach(function (d) {
        d.addEventListener('toggle', function () {
            if (!d.open) return;
            grupos.forEach(function (o) { if (o !== d) o.open = false; });
        });
    });
    document.addEventListener('click', function (e) {
        if (e.target.closest('.topbar-v2 details')) return;
        grupos.forEach(function (o) { o.open = false; });
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') grupos.forEach(function (o) { o.open = false; });
    });
})();
</script>
<?php
})(isset($tbTitulo) ? (string)$tbTitulo : null, isset($tbPai) && is_array($tbPai) ? $tbPai : null);
