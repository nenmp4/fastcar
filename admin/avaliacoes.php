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

// 28/09/2026, "no avalista permita ele mesmo subir veiculo manual" — só o
// avalista ganha o botão "+ Nova vistoria" (self-atribuída); consultor/
// vendedor continuam criando pelo card de sempre em admin/oportunidade.php/
// admin/venda.php (regra de atribuição deles não mudou em nada).
$souAvaliador = $perfil === 'avaliador';

// AJAX de busca pro modal "+ Nova vistoria" — curto-circuita antes de
// qualquer HTML. Aberto a qualquer perfil com acesso a esta página (mesmo
// dado que já aparece nas próprias vistorias/dashboards), não só avalista.
if (($_GET['ajax'] ?? '') === 'buscar') {
    header('Content-Type: application/json; charset=utf-8');
    $tipoBusca = ($_GET['tipo'] ?? '') === 'venda' ? 'venda' : 'compra';
    echo json_encode(buscarCandidatosVistoria($tipoBusca, (string)($_GET['q'] ?? '')));
    exit;
}

$erro = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        $erro = 'Sessão expirada, recarregue a página e tente de novo.';
    } elseif (($_POST['acao'] ?? '') === 'criar_vistoria_avaliador' && $souAvaliador) {
        $tipoNova = ($_POST['tipo'] ?? '') === 'venda' ? 'venda' : 'compra';
        $oportunidadeIdNova = (int)($_POST['oportunidade_id'] ?? 0);
        $vendaIdNova = $tipoNova === 'venda' ? (int)($_POST['venda_id'] ?? 0) : null;
        $tipoVeiculoNova = in_array($_POST['tipo_veiculo'] ?? '', ['carro', 'moto'], true) ? $_POST['tipo_veiculo'] : 'carro';

        if ($oportunidadeIdNova <= 0 || ($tipoNova === 'venda' && !$vendaIdNova)) {
            $erro = 'Selecione um veículo na busca antes de criar a vistoria.';
        } else {
            try {
                $novaId = criarAvaliacao($oportunidadeIdNova, $tipoNova, $vendaIdNova, $meuId, $meuId, $tipoVeiculoNova);
                header('Location: /admin/avaliacao.php?id=' . $novaId);
                exit;
            } catch (Throwable $e) {
                $erro = 'Erro ao criar a vistoria: ' . $e->getMessage();
            }
        }
    }
}

// avaliador só vê as próprias; super_admin/supervisor veem todas;
// consultor/vendedor também veem todas (precisam achar a do próprio
// negócio pra acompanhar, não têm "carteira de avaliador" própria).
$filtroAvaliador = ($souAvaliador && !$vejoTudo) ? $meuId : null;

$aba = (string)($_GET['aba'] ?? 'pendentes');
$pendentes = listarAvaliacoesPendentes($filtroAvaliador);
$concluidas = $aba === 'concluidas' ? listarAvaliacoesConcluidas($filtroAvaliador) : [];

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
<header class="topbar">
    <?php if ($perfil !== 'avaliador'): ?><a href="/admin/index.php" style="color:#fff">← Voltar</a><?php endif; ?>
    <strong><img class="topbar-logo" src="/admin/assets/img/icon-192.png" alt="Fastcar" onerror="this.style.display='none'"> Fast<b>Car</b></strong>
    <span>Olá, <?= e($_SESSION['admin_nome']) ?></span>
    <a href="/admin/meu_perfil.php">🙋 Meu perfil</a>
    <a href="/admin/logout.php">Sair</a>
</header>

<nav class="etapas-nav">
    <a href="/admin/avaliacoes.php?aba=pendentes" class="<?= $aba !== 'concluidas' ? 'ativo' : '' ?>">⏳ Pendentes/em andamento (<?= count($pendentes) ?>)</a>
    <a href="/admin/avaliacoes.php?aba=concluidas" class="<?= $aba === 'concluidas' ? 'ativo' : '' ?>">✅ Concluídas</a>
</nav>

<main>
<?php if ($erro): ?><div class="alerta-erro"><?= e($erro) ?></div><?php endif; ?>

<?php if ($souAvaliador): ?>
<div style="display:flex;justify-content:flex-end;margin-bottom:1rem">
  <button type="button" class="btn-primary" style="width:auto" onclick="document.getElementById('modal-vistoria').showModal()">➕ Nova vistoria</button>
</div>

<dialog id="modal-vistoria" class="modal-lancamento">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.5rem">
    <h2 style="margin:0">➕ Nova vistoria (self-atribuída)</h2>
    <button type="button" onclick="document.getElementById('modal-vistoria').close()" style="background:none;border:none;font-size:1.6rem;font-weight:700;cursor:pointer;line-height:1;padding:0 .25rem;color:var(--texto-suave)" aria-label="Fechar">&times;</button>
  </div>
  <p><small>Busque o veículo/negociação já cadastrado no sistema (pelo nome, telefone, placa, marca ou modelo) — a vistoria já nasce atribuída a você, sem precisar que o consultor/vendedor atribua primeiro.</small></p>

  <form method="post" id="form-vistoria">
    <?= csrfField() ?>
    <input type="hidden" name="acao" value="criar_vistoria_avaliador">
    <input type="hidden" name="oportunidade_id" id="av-oportunidade-id" value="">
    <input type="hidden" name="venda_id" id="av-venda-id" value="">

    <label>Tipo de vistoria</label>
    <select name="tipo" id="av-tipo-busca" onchange="avLimparSelecao(); avBuscar();">
        <option value="compra">🚗 Compra (vendedor entregando o veículo)</option>
        <option value="venda">🛒 Venda (comprador recebendo o veículo)</option>
    </select>

    <label>Buscar veículo/pessoa</label>
    <input type="text" id="av-termo-busca" placeholder="Nome, telefone, placa, marca ou modelo..." autocomplete="off" oninput="avBuscar()">

    <div id="av-resultados" style="margin-top:8px;max-height:220px;overflow-y:auto"></div>

    <div id="av-selecionado" class="alerta-sucesso" style="display:none;margin-top:10px"></div>

    <label>Tipo de veículo</label>
    <select name="tipo_veiculo" required>
        <option value="carro">🚗 Carro</option>
        <option value="moto">🏍️ Moto</option>
    </select>

    <button type="submit" id="av-btn-criar" disabled style="margin-top:16px">Criar vistoria</button>
    <button type="button" onclick="document.getElementById('modal-vistoria').close()">Cancelar</button>
  </form>
</dialog>

<script>
(function () {
    var termoEl = document.getElementById('av-termo-busca');
    var tipoEl = document.getElementById('av-tipo-busca');
    var resultadosEl = document.getElementById('av-resultados');
    var selecionadoEl = document.getElementById('av-selecionado');
    var btnCriar = document.getElementById('av-btn-criar');
    var opIdEl = document.getElementById('av-oportunidade-id');
    var vendaIdEl = document.getElementById('av-venda-id');
    var timer = null;

    window.avLimparSelecao = function () {
        opIdEl.value = '';
        vendaIdEl.value = '';
        selecionadoEl.style.display = 'none';
        btnCriar.disabled = true;
        resultadosEl.innerHTML = '';
    };

    window.avBuscar = function () {
        avLimparSelecao();
        var termo = termoEl.value.trim();
        if (timer) clearTimeout(timer);
        if (termo.length < 2) { resultadosEl.innerHTML = ''; return; }
        timer = setTimeout(function () {
            fetch('/admin/avaliacoes.php?ajax=buscar&tipo=' + encodeURIComponent(tipoEl.value) + '&q=' + encodeURIComponent(termo), { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (candidatos) {
                    if (!candidatos.length) {
                        resultadosEl.innerHTML = '<p><small>Nenhum resultado — tente outro termo.</small></p>';
                        return;
                    }
                    resultadosEl.innerHTML = candidatos.map(function (c, i) {
                        return '<div class="card" style="padding:8px 12px;margin-bottom:6px;cursor:pointer" onclick="avSelecionar(' + i + ')">' + c.label.replace(/</g, '&lt;') + '</div>';
                    }).join('');
                    window._avCandidatos = candidatos;
                })
                .catch(function () { resultadosEl.innerHTML = '<p><small>Erro ao buscar — tente de novo.</small></p>'; });
        }, 300);
    };

    window.avSelecionar = function (i) {
        var c = window._avCandidatos[i];
        opIdEl.value = c.oportunidade_id;
        vendaIdEl.value = c.venda_id || '';
        selecionadoEl.style.display = 'block';
        selecionadoEl.textContent = '✅ Selecionado: ' + c.label;
        btnCriar.disabled = false;
    };
})();
</script>
<?php endif; ?>

<div class="card">
    <h2><?= $aba === 'concluidas' ? '✅ Vistorias concluídas' : '⏳ Vistorias pendentes / em andamento' ?></h2>
    <?php $lista = $aba === 'concluidas' ? $concluidas : $pendentes; ?>
    <?php if (!$lista): ?>
        <p><small>Nenhuma vistoria <?= $aba === 'concluidas' ? 'concluída' : 'pendente' ?> <?= $filtroAvaliador ? 'atribuída a você' : '' ?> no momento.</small></p>
    <?php else: ?>
        <div style="overflow-x:auto">
        <table>
            <thead><tr><th>Veículo</th><th>Tipo</th><th>Status</th><th>Avaliador</th><th>Criada em</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($lista as $a): ?>
                <tr>
                    <td><?= $a['tipo_veiculo'] === 'moto' ? '🏍️' : '🚗' ?> <?= e(trim($a['veiculo_marca'] . ' ' . $a['veiculo_modelo'])) ?: '—' ?> <?= e((string)($a['veiculo_ano'] ?? '')) ?><br><small><?= e($a['cliente_nome']) ?></small></td>
                    <td><?= avTipoPill($a['tipo']) ?></td>
                    <td><?= avStatusBadge($a['status']) ?></td>
                    <td><?= $a['avaliador_nome'] ? e($a['avaliador_nome']) : '<em>não atribuído</em>' ?></td>
                    <td><?= date('d/m/Y H:i', strtotime($a['created_at'])) ?></td>
                    <td><a class="btn-primary" href="/admin/avaliacao.php?id=<?= (int)$a['id'] ?>">Abrir →</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</div>
</main>
<?php include __DIR__ . '/_pwa_register.php'; ?>
<?php include __DIR__ . '/_notify.php'; ?>
<?php include __DIR__ . '/_zapi_status.php'; ?>
</body>
</html>
