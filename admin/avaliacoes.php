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

// $souAvaliador controla o FILTRO da fila (logo abaixo) e também quem
// ganha o botão "➕ Nova vistoria (manual)" (self-atribuída) revivido em
// 10/10/2026 — removido em 08/10/2026 por estar confundindo (modal com
// busca+cadastro de veículo+FIPE+CRLV tudo junto), trazido de volta mais
// enxuto (sem FIPE/CRLV automáticos) e com a regra nova: placa já
// cadastrada no sistema BLOQUEIA o cadastro novo e obriga selecionar o
// veículo existente — nunca deixa criar duplicata (ver
// `buscarVeiculoAtivoPorPlaca()`, includes/oportunidades.php, mesmo
// bloqueio "duro" já usado em admin/vendas.php pro mesmo cenário).
// Consultor/vendedor continuam os únicos OUTROS caminhos de criar vistoria
// (card em admin/oportunidade.php/admin/venda.php, avaliador_id sempre
// obrigatório — isso não mudou).
$souAvaliador = $perfil === 'avaliador';

// AJAX de apoio ao modal "Nova vistoria (manual)" — curto-circuita antes
// de qualquer HTML. Mesmo acesso de quem já chega nesta página.
if (($_GET['ajax'] ?? '') === 'buscar') {
    header('Content-Type: application/json; charset=utf-8');
    $tipoBusca = ($_GET['tipo'] ?? '') === 'venda' ? 'venda' : 'compra';
    echo json_encode(buscarCandidatosVistoria($tipoBusca, (string)($_GET['q'] ?? '')));
    exit;
}

// Checagem proativa de placa duplicada + histórico — dispara ao sair do
// campo "Placa" no sub-formulário de cadastro novo. Devolve os 2 numa
// chamada só: se `duplicado` vier preenchido, o JS trava o cadastro novo
// e seleciona o veículo existente sozinho (nunca deixa prosseguir, mesma
// regra do servidor em `criar_vistoria_avaliador` abaixo).
if (($_GET['ajax'] ?? '') === 'verificar_placa') {
    header('Content-Type: application/json; charset=utf-8');
    $placaChk = (string)($_GET['placa'] ?? '');
    $dup = buscarVeiculoAtivoPorPlaca($placaChk);
    $hist = listarVistoriasPorPlaca($placaChk);
    echo json_encode([
        'duplicado' => $dup ? [
            'oportunidade_id' => (int)$dup['id'],
            'label' => trim($dup['veiculo_marca'] . ' ' . $dup['veiculo_modelo']) . ' — ' . $dup['nome'],
        ] : null,
        'historico' => array_map(function ($v) {
            return [
                'data' => date('d/m/Y', strtotime($v['created_at'])),
                'tipo' => $v['tipo'],
                'status' => $v['status'],
                'cliente_nome' => $v['cliente_nome'],
            ];
        }, $hist),
    ]);
    exit;
}

$erro = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        $erro = 'Sessão expirada, recarregue a página e tente de novo.';
    } elseif (($_POST['acao'] ?? '') === 'criar_vistoria_avaliador' && $souAvaliador) {
        $tipoNova = ($_POST['tipo'] ?? '') === 'venda' ? 'venda' : 'compra';
        $tipoVeiculoNova = in_array($_POST['tipo_veiculo'] ?? '', ['carro', 'moto'], true) ? $_POST['tipo_veiculo'] : 'carro';

        if (!empty($_POST['veiculo_novo']) && $tipoNova === 'compra') {
            try {
                // Mesmo bloqueio "duro" já usado em admin/vendas.php pro
                // mesmo cenário — nunca confia só na checagem proativa do
                // JS (`?ajax=verificar_placa`), o servidor revalida de
                // novo e recusa criar se a placa já existe (regra
                // confirmada com o usuário: "bloqueia e mostra o
                // existente, nunca deixa prosseguir").
                $placaNova = (string)($_POST['veiculo_placa'] ?? '');
                $duplicidadePlaca = buscarVeiculoAtivoPorPlaca($placaNova);
                if ($duplicidadePlaca) {
                    throw new RuntimeException(
                        "Essa placa já está cadastrada na frota — oportunidade #{$duplicidadePlaca['id']} " .
                        "({$duplicidadePlaca['veiculo_marca']} {$duplicidadePlaca['veiculo_modelo']}). " .
                        'Desmarque "cadastrar veículo novo" e busque esse veículo acima.'
                    );
                }
                // "tem campos que não tem necessidade... carro recuperado
                // pela fastcar", "pode ser opcional" — vendedor/telefone
                // viram opcionais: sem telefone informado, gera um
                // placeholder único (regra #1, clientes.telefone continua
                // UNIQUE) e um nome padrão explicando a situação, nunca
                // fingindo um contato real que não existe (regra #3).
                $vendedorTelefonePost = trim((string)($_POST['vendedor_telefone'] ?? ''));
                $vendedorNomePost = trim((string)($_POST['vendedor_nome'] ?? ''));
                if ($vendedorTelefonePost === '') {
                    $vendedorTelefonePost = gerarTelefonePlaceholderVeiculoRecuperado();
                    if ($vendedorNomePost === '') {
                        $vendedorNomePost = 'Veículo recuperado pela Fastcar (sem vendedor identificado)';
                    }
                }
                $novoVeiculo = criarVeiculoManualFrota(
                    $vendedorNomePost,
                    $vendedorTelefonePost,
                    (string)($_POST['veiculo_marca'] ?? ''),
                    (string)($_POST['veiculo_modelo'] ?? ''),
                    (string)($_POST['veiculo_ano'] ?? ''),
                    $placaNova,
                    (string)($_POST['veiculo_chassi'] ?? ''),
                    (string)($_POST['veiculo_renavam'] ?? ''),
                    valorMonetario($_POST['veiculo_valor_final'] ?? null),
                    $meuId
                );
                $novaId = criarAvaliacao((int)$novoVeiculo['oportunidade_id'], 'compra', null, $meuId, $meuId, $tipoVeiculoNova);
                header('Location: /admin/avaliacao.php?id=' . $novaId);
                exit;
            } catch (Throwable $e) {
                $erro = 'Erro ao cadastrar o veículo/vistoria: ' . $e->getMessage();
            }
        } else {
            $oportunidadeIdNova = (int)($_POST['oportunidade_id'] ?? 0);
            $vendaIdNova = $tipoNova === 'venda' ? (int)($_POST['venda_id'] ?? 0) : null;

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
}

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
  <button type="button" class="btn-primary" style="width:auto" onclick="document.getElementById('modal-vistoria').showModal()">➕ Nova vistoria (manual)</button>
</div>

<dialog id="modal-vistoria" class="modal-lancamento">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.5rem">
    <h2 style="margin:0">➕ Nova vistoria (manual)</h2>
    <button type="button" onclick="document.getElementById('modal-vistoria').close()" style="background:none;border:none;font-size:1.6rem;font-weight:700;cursor:pointer;line-height:1;padding:0 .25rem;color:var(--texto-suave)" aria-label="Fechar">&times;</button>
  </div>
  <p><small>Busque o veículo/negociação já cadastrado (nome, telefone, placa, marca ou modelo) — a vistoria já nasce atribuída a você. Se o veículo ainda não existir no sistema, cadastre pela placa abaixo.</small></p>

  <form method="post" id="form-vistoria">
    <?= csrfField() ?>
    <input type="hidden" name="acao" value="criar_vistoria_avaliador">
    <input type="hidden" name="oportunidade_id" id="av-oportunidade-id" value="">
    <input type="hidden" name="venda_id" id="av-venda-id" value="">

    <label>Tipo de vistoria</label>
    <select name="tipo" id="av-tipo-busca" onchange="avLimparSelecao(); avBuscar(); avAtualizarVisibilidadeNovo();">
        <option value="compra">🚗 Compra (vendedor entregando o veículo)</option>
        <option value="venda">🛒 Venda (comprador recebendo o veículo)</option>
    </select>

    <div id="av-busca-bloco">
    <label>Buscar veículo/pessoa</label>
    <input type="text" id="av-termo-busca" placeholder="Nome, telefone, placa, marca ou modelo..." autocomplete="off" oninput="avBuscar()">

    <div id="av-resultados" style="margin-top:8px;max-height:220px;overflow-y:auto"></div>

    <div id="av-selecionado" class="alerta-sucesso" style="display:none;margin-top:10px"></div>
    </div>

    <div id="av-bloco-novo-toggle" style="margin-top:12px">
      <label style="display:flex;align-items:center;gap:8px;font-weight:400">
        <input type="checkbox" id="av-veiculo-novo" name="veiculo_novo" value="1" onchange="avAlternarVeiculoNovo()">
        Veículo não está na lista? Cadastrar um novo pela placa
      </label>
    </div>

    <div id="av-bloco-novo" style="display:none;margin-top:10px;padding:12px;border:1px solid var(--borda);border-radius:8px;background:var(--fundo)">
        <p><small>O vendedor está com você agora e o veículo ainda não foi cadastrado? Digite a placa primeiro — se ela já existir no sistema, você será obrigado a selecionar o veículo existente em vez de cadastrar de novo.</small></p>

        <label>Placa</label>
        <input type="text" id="av-veiculo-placa" name="veiculo_placa" style="text-transform:uppercase" maxlength="8" placeholder="ABC1D23" onblur="avVerificarPlaca()">
        <div id="av-placa-aviso" style="margin-top:6px"></div>
        <div id="av-historico-placa" style="margin-top:6px"></div>

        <div id="av-campos-novo-resto">
        <label>Nome do vendedor (opcional)</label>
        <input type="text" id="av-vendedor-nome" name="vendedor_nome">
        <label>Telefone do vendedor com DDD (opcional)</label>
        <input type="tel" id="av-vendedor-telefone" name="vendedor_telefone" placeholder="Ex: 31999998888">
        <small style="color:var(--texto-fraco)">Deixe os 2 em branco se for um veículo <strong>recuperado pela Fastcar</strong>, sem vendedor/contato pra registrar.</small>

        <div class="grid-2">
            <div>
                <label>Marca</label>
                <input type="text" id="av-veiculo-marca" name="veiculo_marca">
            </div>
            <div>
                <label>Modelo</label>
                <input type="text" id="av-veiculo-modelo" name="veiculo_modelo">
            </div>
        </div>
        <div class="grid-2">
            <div>
                <label>Ano</label>
                <input type="text" id="av-veiculo-ano" name="veiculo_ano">
            </div>
            <div>
                <label>Valor pago (R$, se já souber)</label>
                <input type="number" step="0.01" inputmode="decimal" name="veiculo_valor_final">
            </div>
        </div>
        <div class="grid-2">
            <div>
                <label>Chassi</label>
                <input type="text" id="av-veiculo-chassi" name="veiculo_chassi">
            </div>
            <div>
                <label>Renavam</label>
                <input type="text" id="av-veiculo-renavam" name="veiculo_renavam">
            </div>
        </div>
        </div>
    </div>

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
                        resultadosEl.innerHTML = '<p><small>Nenhum resultado' + (tipoEl.value === 'compra' ? ' — se o veículo é novo, digite a placa abaixo em "Cadastrar um novo pela placa".' : ' — tente outro termo.') + '</small></p>';
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

    // "Veículo não está na lista? Cadastrar um novo pela placa" — só faz
    // sentido pra COMPRA (vistoria de venda precisa de um comprador/
    // negociação já existente, não de um veículo novo).
    var checkboxNovo = document.getElementById('av-veiculo-novo');
    var blocoNovo = document.getElementById('av-bloco-novo');
    var blocoNovoToggle = document.getElementById('av-bloco-novo-toggle');
    var buscaBloco = document.getElementById('av-busca-bloco');
    var camposRestoNovo = document.getElementById('av-campos-novo-resto');
    var avisoPlacaEl = document.getElementById('av-placa-aviso');
    var historicoPlacaEl = document.getElementById('av-historico-placa');
    var campoPlaca = document.getElementById('av-veiculo-placa');

    window.avAtualizarVisibilidadeNovo = function () {
        if (tipoEl.value === 'venda') {
            blocoNovoToggle.style.display = 'none';
            if (checkboxNovo.checked) { checkboxNovo.checked = false; avAlternarVeiculoNovo(); }
        } else {
            blocoNovoToggle.style.display = 'block';
        }
    };

    window.avAlternarVeiculoNovo = function () {
        var novo = checkboxNovo.checked;
        if (novo) {
            avLimparSelecao();
            buscaBloco.style.display = 'none';
            blocoNovo.style.display = 'block';
            btnCriar.disabled = true; // só libera depois de verificar a placa
        } else {
            buscaBloco.style.display = 'block';
            blocoNovo.style.display = 'none';
            btnCriar.disabled = true;
            avisoPlacaEl.innerHTML = '';
            historicoPlacaEl.innerHTML = '';
        }
    };

    // Checagem proativa de placa duplicada — "se placa tiver no sistema,
    // usuário obrigatório seleciona": achando match, trava o cadastro
    // novo (nunca deixa prosseguir) e seleciona o veículo existente
    // sozinho, como se tivesse vindo da busca normal.
    window.avVerificarPlaca = function () {
        var placa = campoPlaca.value.trim();
        avisoPlacaEl.innerHTML = '';
        historicoPlacaEl.innerHTML = '';
        if (placa.length < 6) { camposRestoNovo.style.display = 'block'; btnCriar.disabled = !!checkboxNovo.checked ? false : true; return; }
        avisoPlacaEl.textContent = 'Verificando...';
        fetch('/admin/avaliacoes.php?ajax=verificar_placa&placa=' + encodeURIComponent(placa), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.duplicado) {
                    // Trava de vez: some com os campos de cadastro, nunca
                    // deixa escolher "mesmo assim" — o único caminho é usar
                    // o veículo já cadastrado.
                    camposRestoNovo.style.display = 'none';
                    opIdEl.value = data.duplicado.oportunidade_id;
                    vendaIdEl.value = '';
                    btnCriar.disabled = false;
                    avisoPlacaEl.innerHTML = '<div class="alerta-erro" style="padding:8px">⚠️ Essa placa já está cadastrada: <strong>' + data.duplicado.label.replace(/</g, '&lt;') + '</strong>. Veículo selecionado automaticamente — clique em "Criar vistoria" pra abrir a vistoria dele, ou desmarque "cadastrar novo" e use a busca pra ver o histórico.</div>';
                } else {
                    camposRestoNovo.style.display = 'block';
                    opIdEl.value = '';
                    btnCriar.disabled = false;
                    if (placa) avisoPlacaEl.innerHTML = '<small style="color:var(--texto-fraco)">✅ Placa livre — pode cadastrar.</small>';
                }
                if (data.historico && data.historico.length) {
                    var rotuloTipo = { compra: '🚗 Compra', venda: '🛒 Venda' };
                    var rotuloStatus = { concluida: '✅ Concluída', em_andamento: '🔧 Em andamento', pendente: '⏳ Pendente' };
                    var linhas = data.historico.map(function (v) {
                        return '<div style="padding:4px 0;font-size:12.5px">' + v.data + ' — ' + (rotuloTipo[v.tipo] || v.tipo) + ' — ' + (rotuloStatus[v.status] || v.status) + ' — ' + (v.cliente_nome || '—').replace(/</g, '&lt;') + '</div>';
                    }).join('');
                    historicoPlacaEl.innerHTML = '<div class="alerta-info" style="padding:8px"><strong style="font-size:12.5px">🕘 Esse veículo já passou pela Fastcar antes:</strong>' + linhas + '</div>';
                }
            })
            .catch(function () { avisoPlacaEl.innerHTML = '<small>⚠️ Erro ao verificar — tente de novo.</small>'; });
    };
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
