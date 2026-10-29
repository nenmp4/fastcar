<?php
/**
 * Fila de trabalho do módulo de checklist de vistoria/avaliação do veículo
 * (includes/veiculo_avaliacoes.php) — mesmo espírito de admin/vendas.php
 * pro perfil `vendedor`: home page do perfil `avaliador` (login.php já
 * manda direto pra cá), mas também acessível a super_admin/supervisor
 * (veem tudo) e consultor/vendedor (só usam pra atribuir/acompanhar as
 * próprias vistorias — o card fica em admin/oportunidade.php/admin/venda.php,
 * essa tela aqui é o painel geral, mais útil pro avaliador e pra gestão).
 */

require_once __DIR__ . '/_bootstrap.php';
requireAcessoAvaliacoes();

$perfil = $_SESSION['admin_perfil'];
$meuId = (int)$_SESSION['admin_id'];
$vejoTudo = perfilVeTudo(); // super_admin/supervisor

// $souAvaliador controla o FILTRO da fila (cada avaliador vê a própria
// carteira) e, junto do super_admin, quem vê o botão "➕ Nova vistoria".
// 27/10/2026, "Nova Vistoria — selecionar veículo ou digitar placa, busca
// no sistema, não pode duplicar placa": o botão tinha saído no mesmo dia
// porque duplicava vistoria; voltou com 2 travas no servidor —
// (1) placa já cadastrada nunca vira cadastro novo, obriga usar o existente
// (cadastroAtivoPorPlaca()); (2) placa que já tem vistoria de compra em
// aberto (em qualquer cadastro) abre a existente em vez de criar outra
// (vistoriaAtivaPorPlaca(), também aplicada dentro de criarAvaliacao()).
$souAvaliador = $perfil === 'avaliador';
$podeCriarVistoria = $souAvaliador || $perfil === 'super_admin';

if ($podeCriarVistoria && ($_GET['ajax'] ?? '') === 'buscar') {
    header('Content-Type: application/json; charset=utf-8');
    $tipoBusca = ($_GET['tipo'] ?? '') === 'venda' ? 'venda' : 'compra';
    $termoBusca = (string)($_GET['q'] ?? '');
    $placaNormBusca = veiculoPlacaNormalizada($termoBusca);
    echo json_encode([
        'candidatos' => buscarCandidatosVistoria($tipoBusca, $termoBusca),
        // Placa "completa" digitada que não existe em lugar nenhum → libera cadastrar novo.
        'placa_livre' => ($tipoBusca === 'compra' && preg_match('/^[A-Z]{3}[0-9][A-Z0-9][0-9]{2}$/', $placaNormBusca) && !cadastroAtivoPorPlaca($placaNormBusca)) ? $placaNormBusca : null,
    ]);
    exit;
}

$erro = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        $erro = 'Sessão expirada, recarregue a página e tente de novo.';
    } elseif (($_POST['acao'] ?? '') === 'nova_vistoria' && $podeCriarVistoria) {
        $tipoNova = ($_POST['tipo'] ?? '') === 'venda' ? 'venda' : 'compra';
        $tipoVeiculoNova = ($_POST['tipo_veiculo'] ?? '') === 'moto' ? 'moto' : 'carro';
        $avaliadorNova = $souAvaliador ? $meuId : (int)($_POST['avaliador_id'] ?? 0);
        try {
            if ($avaliadorNova <= 0) throw new RuntimeException('Escolha o avaliador.');
            if (!$souAvaliador) {
                $stAv = getDB()->prepare("SELECT 1 FROM usuarios WHERE id = ? AND perfil = 'avaliador' AND bloqueado = 0");
                $stAv->execute([$avaliadorNova]);
                if (!$stAv->fetchColumn()) throw new RuntimeException('Avaliador inválido.');
            }
            $opNova = (int)($_POST['oportunidade_id'] ?? 0);
            $vendaNova = $tipoNova === 'venda' ? (int)($_POST['venda_id'] ?? 0) : null;
            if (!empty($_POST['veiculo_novo']) && $tipoNova === 'compra') {
                $placaNova = veiculoPlacaNormalizada((string)($_POST['veiculo_placa'] ?? ''));
                if (!preg_match('/^[A-Z]{3}[0-9][A-Z0-9][0-9]{2}$/', $placaNova)) {
                    throw new RuntimeException('Placa inválida — use o formato ABC1234 ou ABC1D23.');
                }
                $jaExiste = cadastroAtivoPorPlaca($placaNova);
                if ($jaExiste) {
                    throw new RuntimeException("A placa {$placaNova} já está cadastrada (#{$jaExiste['id']} — "
                        . trim($jaExiste['veiculo_marca'] . ' ' . $jaExiste['veiculo_modelo']) . '). Selecione esse veículo na busca.');
                }
                $telNovo = trim((string)($_POST['vendedor_telefone'] ?? ''));
                $nomeNovo = trim((string)($_POST['vendedor_nome'] ?? ''));
                if ($telNovo === '') {
                    $telNovo = gerarTelefonePlaceholderVeiculoRecuperado();
                    if ($nomeNovo === '') $nomeNovo = 'Veículo recuperado pela Fastcar (sem vendedor identificado)';
                }
                if ($nomeNovo === '') throw new RuntimeException('Informe o nome do vendedor (ou deixe nome e telefone em branco se for veículo recuperado).');
                $novo = criarVeiculoManualFrota($nomeNovo, $telNovo, (string)($_POST['veiculo_marca'] ?? ''),
                    (string)($_POST['veiculo_modelo'] ?? ''), (string)($_POST['veiculo_ano'] ?? ''), $placaNova,
                    '', '', null, $meuId);
                $opNova = (int)$novo['oportunidade_id'];
            }
            if ($opNova <= 0 || ($tipoNova === 'venda' && !$vendaNova)) {
                throw new RuntimeException('Selecione o veículo na busca (ou cadastre a placa nova) antes de criar.');
            }
            $idNova = criarAvaliacao($opNova, $tipoNova, $vendaNova, $avaliadorNova, $meuId, $tipoVeiculoNova);
            header('Location: /admin/avaliacao.php?id=' . $idNova);
            exit;
        } catch (Throwable $e) {
            $erro = 'Não deu pra criar a vistoria: ' . $e->getMessage();
        }
    }
}
$avaliadoresLista = ($podeCriarVistoria && !$souAvaliador)
    ? array_values(array_filter(listarUsuarios(), fn($u) => $u['perfil'] === 'avaliador'))
    : [];

// avaliador só vê as próprias; super_admin/supervisor veem todas;
// consultor/vendedor também veem todas (precisam achar a do próprio
// negócio pra acompanhar, não têm "carteira de avaliador" própria).
$filtroAvaliador = ($souAvaliador && !$vejoTudo) ? $meuId : null;

$aba = (string)($_GET['aba'] ?? 'pendentes');
// 05/10/2026, "listagem de veículos vistoriados pra ele conferir todas
// suas vistorias" — busca por placa/marca/modelo/cliente + paginação de
// verdade na aba Concluídas (antes era um LIMIT 100 fixo, sem jeito de
// ver o que ficasse além disso nem de achar uma vistoria antiga
// específica sem rolar a lista inteira).
$busca = trim((string)($_GET['q'] ?? ''));
$pendentes = listarAvaliacoesPendentes($filtroAvaliador);
$totalConcluidas = 0;
$concluidas = [];
if ($aba === 'concluidas') {
    $totalConcluidas = contarAvaliacoesConcluidas($filtroAvaliador, $busca);
    $concluidas = listarAvaliacoesConcluidas($filtroAvaliador, $busca, ITENS_POR_PAGINA_PADRAO, paginacaoOffset());
}

function avTipoPill(string $tipo): string {
    $rotulo = $tipo === 'venda' ? '🛒 VENDA' : '🚗 COMPRA';
    return '<span class="av-tipo-pill tipo-' . e($tipo) . '">' . $rotulo . '</span>';
}
function avStatusBadge(string $status): string {
    return match ($status) {
        'concluida'    => '<span class="badge badge-ok">✅ Concluída</span>',
        'em_andamento' => '<span class="badge badge-info">🔧 Em andamento</span>',
        default        => '<span class="badge badge-aviso">⏳ Pendente</span>',
    };
}
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Avaliações/Vistoria — Fastcar CRM</title>
<link rel="stylesheet" href="/admin/assets/style.css?v=<?= @filemtime(__DIR__ . '/assets/style.css') ?: 1 ?>">
<link rel="stylesheet" href="/admin/assets/mobile.css?v=<?= @filemtime(__DIR__ . '/assets/mobile.css') ?: 1 ?>">
<script src="/admin/assets/mobile.js?v=<?= @filemtime(__DIR__ . '/assets/mobile.js') ?: 1 ?>" defer></script>
<?php include __DIR__ . '/_pwa_head.php'; ?>
</head>
<body>
<?php include __DIR__ . '/_impersonando_banner.php'; ?>
<header class="topbar">
    <?php if ($perfil !== 'avaliador'): ?><a href="/admin/index.php" style="color:#fff">← Voltar</a><?php endif; ?>
    <a class="topbar-brand" href="<?= e(paginaInicialPorPerfil($_SESSION['admin_perfil'] ?? '')) ?>"><img class="topbar-logo" src="/admin/assets/img/icon-192.png" alt="Fastcar" onerror="this.style.display='none'"><span class="topbar-wordmark">Fast<b>Car</b></span></a>
    <span>Olá, <?= e($_SESSION['admin_nome']) ?></span>
    <?php if (podeAcessarVendas()): ?><a href="/admin/vendas.php">💰 Vendas</a><a href="/admin/estoque.php">🚘 Estoque</a><?php endif; ?>
    <a href="/admin/meu_perfil.php">🙋 Meu perfil</a>
    <a href="/admin/logout.php">Sair</a>
</header>

<nav class="etapas-nav">
    <a href="/admin/avaliacoes.php?aba=pendentes" class="<?= $aba !== 'concluidas' ? 'ativo' : '' ?>">⏳ Pendentes/em andamento (<?= count($pendentes) ?>)</a>
    <a href="/admin/avaliacoes.php?aba=concluidas" class="<?= $aba === 'concluidas' ? 'ativo' : '' ?>">✅ Concluídas</a>
</nav>

<main>
<?php if ($erro): ?><div class="alerta-erro"><?= e($erro) ?></div><?php endif; ?>

<?php if ($podeCriarVistoria): ?>
<div style="display:flex;justify-content:flex-end;margin-bottom:1rem">
    <button type="button" class="btn-primary" style="width:auto;margin:0" onclick="document.getElementById('modal-vistoria').showModal();document.getElementById('nv-termo').focus()">➕ Nova vistoria</button>
</div>
<dialog id="modal-vistoria" class="modal-lancamento">
    <div style="display:flex;justify-content:space-between;align-items:center">
        <h2 style="margin:0">➕ Nova vistoria</h2>
        <button type="button" onclick="this.closest('dialog').close()" style="background:none;border:none;box-shadow:none;margin:0;font-size:1.6rem;color:var(--texto-suave);cursor:pointer" aria-label="Fechar">&times;</button>
    </div>
    <form method="post" id="form-nv">
        <?= csrfField() ?>
        <input type="hidden" name="acao" value="nova_vistoria">
        <input type="hidden" name="oportunidade_id" id="nv-op">
        <input type="hidden" name="venda_id" id="nv-venda">
        <input type="hidden" name="veiculo_novo" id="nv-novo" value="">
        <input type="hidden" name="veiculo_placa" id="nv-placa">

        <label>Tipo de vistoria</label>
        <select name="tipo" id="nv-tipo">
            <option value="compra">🚗 Compra (veículo chegando na Fastcar)</option>
            <option value="venda">🛒 Venda (entrega ao comprador)</option>
        </select>

        <label>Selecione o veículo ou digite a placa</label>
        <input type="text" id="nv-termo" placeholder="Placa, marca/modelo ou nome do cliente..." autocomplete="off" style="text-transform:uppercase">
        <div id="nv-resultados" style="margin-top:8px;max-height:260px;overflow-y:auto"></div>
        <div id="nv-selecionado" class="alerta-sucesso" style="display:none;margin-top:8px"></div>

        <div id="nv-bloco-novo" style="display:none;margin-top:8px;padding:12px;border:1px solid var(--borda);border-radius:var(--raio)">
            <strong id="nv-novo-titulo"></strong>
            <div class="grid-2">
                <div><label>Marca</label><input type="text" name="veiculo_marca" id="nv-marca"></div>
                <div><label>Modelo</label><input type="text" name="veiculo_modelo" id="nv-modelo"></div>
            </div>
            <label>Ano</label><input type="text" name="veiculo_ano" inputmode="numeric">
            <label>Nome do vendedor</label><input type="text" name="vendedor_nome">
            <label>Telefone do vendedor (com DDD)</label><input type="tel" name="vendedor_telefone">
            <small>Veículo recuperado sem vendedor? Deixe nome e telefone em branco.</small>
        </div>

        <label>Tipo de veículo</label>
        <select name="tipo_veiculo"><option value="carro">🚗 Carro</option><option value="moto">🏍️ Moto</option></select>

        <?php if (!$souAvaliador): ?>
            <label>Avaliador</label>
            <select name="avaliador_id" required>
                <option value="">— escolha um avaliador —</option>
                <?php foreach ($avaliadoresLista as $avU): ?><option value="<?= (int)$avU['id'] ?>"><?= e($avU['nome']) ?></option><?php endforeach; ?>
            </select>
        <?php endif; ?>

        <button type="submit" id="nv-criar" disabled style="margin-top:14px">Criar vistoria</button>
    </form>
</dialog>
<script>
(function () {
    var termo = document.getElementById('nv-termo'), tipo = document.getElementById('nv-tipo');
    var res = document.getElementById('nv-resultados'), sel = document.getElementById('nv-selecionado');
    var op = document.getElementById('nv-op'), venda = document.getElementById('nv-venda');
    var novo = document.getElementById('nv-novo'), placa = document.getElementById('nv-placa');
    var bloco = document.getElementById('nv-bloco-novo'), btn = document.getElementById('nv-criar');
    var timer = null, cand = [];
    function esc(t) { return String(t).replace(/[&<>"]/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); }
    function limpar() {
        op.value = ''; venda.value = ''; novo.value = ''; placa.value = '';
        sel.style.display = 'none'; bloco.style.display = 'none'; btn.disabled = true;
        document.getElementById('nv-marca').required = false; document.getElementById('nv-modelo').required = false;
    }
    function buscar() {
        limpar();
        clearTimeout(timer);
        var t = termo.value.trim();
        if (t.length < 2) { res.innerHTML = ''; return; }
        timer = setTimeout(function () {
            fetch('/admin/avaliacoes.php?ajax=buscar&tipo=' + tipo.value + '&q=' + encodeURIComponent(t), { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    cand = d.candidatos || [];
                    var h = cand.map(function (c, i) {
                        var aberta = c.vistoria_ativa_id
                            ? ' <a href="/admin/avaliacao.php?id=' + c.vistoria_ativa_id + '" class="badge badge-aviso" onclick="event.stopPropagation()">🔧 já tem vistoria aberta — abrir</a>' : '';
                        return '<div class="card" style="padding:10px 12px;margin:0 0 6px;cursor:pointer" data-i="' + i + '">' + esc(c.label) + aberta + '</div>';
                    }).join('');
                    if (d.placa_livre) {
                        h += '<div class="card" style="padding:10px 12px;margin:0 0 6px;cursor:pointer;border:2px dashed var(--azul)" data-novo="' + esc(d.placa_livre) + '">➕ Placa <strong>' + esc(d.placa_livre) + '</strong> não está no sistema — cadastrar veículo novo</div>';
                    }
                    if (!h) h = '<p><small>Nada encontrado (carro que já tem vistoria não aparece aqui). ' + (tipo.value === 'compra' ? 'Digite a placa completa (ex: ABC1D23) pra cadastrar o veículo.' : 'Vistoria de venda precisa de uma negociação com veículo vinculado.') + '</small></p>';
                    res.innerHTML = h;
                })
                .catch(function () { res.innerHTML = '<p><small>Erro ao buscar — tente de novo.</small></p>'; });
        }, 300);
    }
    res.addEventListener('click', function (ev) {
        var el = ev.target.closest('[data-i],[data-novo]');
        if (!el) return;
        limpar();
        if (el.hasAttribute('data-novo')) {
            novo.value = '1'; placa.value = el.getAttribute('data-novo');
            document.getElementById('nv-novo-titulo').textContent = 'Cadastrar veículo novo — placa ' + placa.value;
            document.getElementById('nv-marca').required = true; document.getElementById('nv-modelo').required = true;
            bloco.style.display = 'block';
        } else {
            var c = cand[+el.getAttribute('data-i')];
            op.value = c.oportunidade_id; venda.value = c.venda_id || '';
            sel.textContent = '✅ ' + c.label + (c.vistoria_ativa_id ? ' — já tem vistoria aberta, ela será aberta em vez de criar outra.' : '');
            sel.style.display = 'block';
        }
        btn.disabled = false;
    });
    termo.addEventListener('input', buscar);
    tipo.addEventListener('change', buscar);
    <?php if ($erro && ($_POST['acao'] ?? '') === 'nova_vistoria'): ?>document.getElementById('modal-vistoria').showModal();<?php endif; ?>
})();
</script>
<?php endif; ?>


<div class="card">
    <h2><?= $aba === 'concluidas' ? '✅ Vistorias concluídas' : '⏳ Vistorias pendentes / em andamento' ?></h2>
    <?php if ($aba === 'concluidas'): ?>
    <form method="get" style="margin-bottom:1rem;display:flex;gap:8px;flex-wrap:wrap">
        <input type="hidden" name="aba" value="concluidas">
        <input type="text" name="q" value="<?= e($busca) ?>" placeholder="Buscar por placa, marca, modelo ou cliente..." style="flex:1;min-width:220px">
        <button type="submit" style="width:auto">Buscar</button>
        <?php if ($busca): ?><a href="/admin/avaliacoes.php?aba=concluidas" class="btn-texto" style="align-self:center">Limpar</a><?php endif; ?>
    </form>
    <?php endif; ?>
    <?php $lista = $aba === 'concluidas' ? $concluidas : $pendentes; ?>
    <?php if (!$lista): ?>
        <p><small>Nenhuma vistoria <?= $aba === 'concluidas' ? 'concluída' : 'pendente' ?> <?= $filtroAvaliador ? 'atribuída a você' : '' ?><?= $busca ? ' pra essa busca' : '' ?> no momento.</small></p>
    <?php else: ?>
        <div style="overflow-x:auto">
        <table>
            <thead><tr><th>Veículo</th><th>Placa</th><th>Tipo</th><th>Status</th><th>Condição</th><th>Avaliador</th><th><?= $aba === 'concluidas' ? 'Concluída em' : 'Criada em' ?></th><th></th></tr></thead>
            <tbody>
            <?php foreach ($lista as $a):
                // 08/10/2026, "Mostra os score sugestão pronto para
                // revenda, Fazer Manutenção" — score só faz sentido
                // depois de concluída; antes disso nunca chuta.
                $scoreLinha = $a['status'] === 'concluida' ? veiculoAvaliacaoScore(listarItensAvaliacao((int)$a['id'])) : null;
            ?>
                <tr>
                    <td><?= $a['tipo_veiculo'] === 'moto' ? '🏍️' : '🚗' ?> <?= e(trim($a['veiculo_marca'] . ' ' . $a['veiculo_modelo'])) ?: '—' ?> <?= e((string)($a['veiculo_ano'] ?? '')) ?><br><small><?= e($a['cliente_nome']) ?></small></td>
                    <td><?= e($a['veiculo_placa'] ?: '—') ?></td>
                    <td><?= avTipoPill($a['tipo']) ?></td>
                    <td><?= avStatusBadge($a['status']) ?></td>
                    <td>
                        <?php if ($scoreLinha && $scoreLinha['percentual'] !== null): ?>
                            <span class="badge <?= veiculoAvaliacaoClasseBadgeScore($scoreLinha['percentual']) ?>" title="<?= e($scoreLinha['resumo']) ?>"><?= (int)$scoreLinha['percentual'] ?>/100</span>
                            <br><small><?= e(veiculoAvaliacaoSugestaoRevenda($scoreLinha['percentual'])) ?></small>
                        <?php elseif ($scoreLinha): ?>
                            <small title="<?= e($scoreLinha['resumo']) ?>">⚠️ sem item verificado</small>
                        <?php else: ?>
                            <small>—</small>
                        <?php endif; ?>
                    </td>
                    <td><?= $a['avaliador_nome'] ? e($a['avaliador_nome']) : '<em>não atribuído</em>' ?></td>
                    <td><?php
                        $dataLinha = $aba === 'concluidas' ? ($a['concluida_em'] ?: $a['created_at']) : $a['created_at'];
                        echo date('d/m/Y H:i', strtotime($dataLinha));
                    ?></td>
                    <td><a class="btn-primary" href="/admin/avaliacao.php?id=<?= (int)$a['id'] ?>">Abrir →</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php if ($aba === 'concluidas') renderPaginacao($totalConcluidas); ?>
    <?php endif; ?>
</div>
</main>
<?php include __DIR__ . '/_pwa_register.php'; ?>
<?php include __DIR__ . '/_notify.php'; ?>
<?php include __DIR__ . '/_scroll_restore.php'; ?>
<?php include __DIR__ . '/_acao_popup.php'; ?>
<?php include __DIR__ . '/_confirm_dialog.php'; ?>
<?php include __DIR__ . '/_zapi_status.php'; ?>
</body>
</html>
