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

// 28/09/2026, "registro de todas as vistoria no veículo, com data e tudo
// — pois ele pode retornar fastcar" — histórico de vistorias POR PLACA,
// cruzando TODAS as oportunidades que já tiveram essa placa (não só a
// atual). Mesmo acesso aberto do endpoint acima.
if (($_GET['ajax'] ?? '') === 'historico_placa') {
    header('Content-Type: application/json; charset=utf-8');
    $itens = listarVistoriasPorPlaca((string)($_GET['placa'] ?? ''));
    echo json_encode(array_map(function ($v) {
        return [
            'id' => (int)$v['id'],
            'tipo' => $v['tipo'],
            'status' => $v['status'],
            'data' => date('d/m/Y', strtotime($v['created_at'])),
            'km' => $v['km_atual'],
            'oportunidade_id' => (int)$v['oportunidade_id'],
            'oportunidade_etapa' => $v['oportunidade_etapa'],
            'cliente_nome' => $v['cliente_nome'],
            'avaliador_nome' => $v['avaliador_nome'],
        ];
    }, $itens));
    exit;
}

$erro = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        $erro = 'Sessão expirada, recarregue a página e tente de novo.';
    } elseif (($_POST['acao'] ?? '') === 'criar_vistoria_avaliador' && $souAvaliador) {
        $tipoNova = ($_POST['tipo'] ?? '') === 'venda' ? 'venda' : 'compra';
        $tipoVeiculoNova = in_array($_POST['tipo_veiculo'] ?? '', ['carro', 'moto'], true) ? $_POST['tipo_veiculo'] : 'carro';

        // 28/09/2026, "se não tiver veículo, cadastrar novo veículo, usa a
        // api que puxa pela placa dados fipe, não zapcar" — mesmo caminho
        // já usado em admin/vendas.php ("Vender na Promissória"), só que a
        // busca de marca/modelo/ano é via placafipeConsultarPorPlaca()
        // (admin/fipe_ajax.php) em vez de leitura de CRLV por IA — não
        // gasta nenhuma consulta paga da ZapCar, só o dado gratuito/já
        // cacheado da FIPE. Só faz sentido pra COMPRA (vistoria de venda
        // exige um comprador+negociação já existente, não um veículo novo).
        if (!empty($_POST['veiculo_novo']) && $tipoNova === 'compra') {
            try {
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
                    (string)($_POST['veiculo_placa'] ?? ''),
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
<?php include __DIR__ . '/_impersonando_banner.php'; ?>
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
    <select name="tipo" id="av-tipo-busca" onchange="avLimparSelecao(); avBuscar(); avAtualizarVisibilidadeNovo();">
        <option value="compra">🚗 Compra (vendedor entregando o veículo)</option>
        <option value="venda">🛒 Venda (comprador recebendo o veículo)</option>
    </select>

    <div id="av-busca-bloco">
    <label>Buscar veículo/pessoa</label>
    <input type="text" id="av-termo-busca" placeholder="Nome, telefone, placa, marca ou modelo..." autocomplete="off" oninput="avBuscar()">

    <div id="av-resultados" style="margin-top:8px;max-height:220px;overflow-y:auto"></div>

    <div id="av-selecionado" class="alerta-sucesso" style="display:none;margin-top:10px"></div>
    <div id="av-historico-selecionado" style="margin-top:8px"></div>
    </div>

    <div id="av-bloco-novo-toggle" style="margin-top:12px">
      <label style="display:flex;align-items:center;gap:8px;font-weight:400">
        <input type="checkbox" id="av-veiculo-novo" name="veiculo_novo" value="1" onchange="avAlternarVeiculoNovo()">
        Veículo não está na lista? Cadastrar um novo agora
      </label>
    </div>

    <div id="av-bloco-novo" style="display:none;margin-top:10px;padding:12px;border:1px solid var(--borda);border-radius:8px;background:var(--fundo)">
        <p><small>O vendedor está com você agora e o veículo ainda não foi cadastrado por nenhum consultor? Registre aqui mesmo — a vistoria já nasce vinculada a ele.
        <?= getConfig('placafipe_token') ? 'Buscar por placa preenche marca/modelo/ano sozinho (dado FIPE, gratuito), mas você pode corrigir antes de salvar.' : '' ?></small></p>

        <label>Nome do vendedor (opcional)</label>
        <input type="text" id="av-vendedor-nome" name="vendedor_nome">
        <label>Telefone do vendedor com DDD (opcional)</label>
        <input type="tel" id="av-vendedor-telefone" name="vendedor_telefone" placeholder="Ex: 31999998888">
        <small style="color:var(--texto-fraco)">Deixe os 2 em branco se for um veículo <strong>recuperado pela Fastcar</strong>, sem vendedor/contato pra registrar.</small>

        <div style="margin-top:10px;padding:10px;border:1px dashed var(--borda);border-radius:6px">
            <label style="margin-top:0">📄 A busca por placa não funcionou? Suba o CRLV (foto ou PDF)</label>
            <input type="file" id="av-crlv-arquivo" accept="image/jpeg,image/png,image/webp,application/pdf">
            <button type="button" id="av-crlv-btn" style="margin-top:.4rem">📄 Ler CRLV com IA</button>
            <span id="av-crlv-status" style="font-size:12.5px;color:var(--texto-fraco);margin-left:.5rem"></span>
        </div>

        <?php if (getConfig('placafipe_token')): ?>
        <div style="display:flex;gap:8px;align-items:flex-end;margin-top:8px">
            <div style="flex:1">
                <label>Placa</label>
                <input type="text" id="av-veiculo-placa" name="veiculo_placa" style="width:100%;text-transform:uppercase" maxlength="8" placeholder="ABC1D23">
            </div>
            <button type="button" id="av-buscar-placa-btn" style="margin:0;padding:8px 14px;font-size:13px;white-space:nowrap">🔎 Buscar por placa (FIPE)</button>
        </div>
        <p id="av-fipe-resultado" style="font-size:12.5px;color:var(--texto-fraco);margin-top:4px"></p>
        <?php else: ?>
        <label>Placa</label>
        <input type="text" id="av-veiculo-placa" name="veiculo_placa" style="text-transform:uppercase" maxlength="8" placeholder="ABC1D23">
        <?php endif; ?>
        <div id="av-historico-placa" style="margin-top:6px"></div>

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
        document.getElementById('av-historico-selecionado').innerHTML = '';
    };

    // 28/09/2026, "registro de todas as vistoria no veículo com data e
    // tudo, pois ele pode retornar fastcar" — mostra TODO o histórico de
    // vistorias já feitas nesse veículo (por placa, cruzando qualquer
    // oportunidade que já teve essa placa, não só a atual/selecionada) —
    // pra nunca passar batido que o carro já esteve na Fastcar antes
    // (comprado, revendido e devolvido, por exemplo).
    function avMostrarHistoricoPlaca(placa, containerId) {
        var container = document.getElementById(containerId);
        placa = (placa || '').trim();
        if (!placa || placa.length < 6) { container.innerHTML = ''; return; }
        fetch('/admin/avaliacoes.php?ajax=historico_placa&placa=' + encodeURIComponent(placa), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (itens) {
                if (!itens.length) { container.innerHTML = ''; return; }
                var rotuloTipo = { compra: '🚗 Compra', venda: '🛒 Venda' };
                var rotuloStatus = { concluida: '✅ Concluída', em_andamento: '🔧 Em andamento', pendente: '⏳ Pendente' };
                var linhas = itens.map(function (v) {
                    return '<div style="padding:6px 8px;border-bottom:1px solid var(--borda);font-size:12.5px">'
                        + '<strong>' + v.data + '</strong> — ' + (rotuloTipo[v.tipo] || v.tipo) + ' — ' + (rotuloStatus[v.status] || v.status)
                        + '<br><small style="color:var(--texto-fraco)">Cliente: ' + (v.cliente_nome || '—').replace(/</g, '&lt;')
                        + (v.avaliador_nome ? ' · Avaliador: ' + v.avaliador_nome.replace(/</g, '&lt;') : '')
                        + (v.km ? ' · KM: ' + v.km : '')
                        + ' · <a href="/admin/avaliacao.php?id=' + v.id + '" target="_blank">ver vistoria →</a></small></div>';
                }).join('');
                container.innerHTML = '<div class="alerta-info" style="padding:8px;margin-top:6px">'
                    + '<strong style="font-size:12.5px">🕘 Esse veículo JÁ passou pela Fastcar antes — ' + itens.length + ' vistoria(s) registrada(s):</strong>'
                    + '<div style="max-height:160px;overflow-y:auto;margin-top:4px">' + linhas + '</div></div>';
            })
            .catch(function () { container.innerHTML = ''; });
    }

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
                        // 28/09/2026, "avalista digita placa, se não tiver
                        // cadastra um novo" — nenhum resultado (ex: placa
                        // ainda não cadastrada por nenhum consultor) já
                        // oferece o atalho direto pro cadastro novo, com o
                        // termo digitado (a placa) já aproveitado, sem
                        // precisar digitar de novo no sub-formulário.
                        resultadosEl.innerHTML = tipoEl.value === 'compra'
                            ? '<p><small>Nenhum resultado. <button type="button" class="btn-texto" onclick="avUsarTermoComoVeiculoNovo()" style="padding:0;color:var(--azul);text-decoration:underline;font-weight:600;font-size:12.5px">🚗 Cadastrar veículo novo com "' + termo.replace(/</g, '&lt;') + '"</button></small></p>'
                            : '<p><small>Nenhum resultado — tente outro termo.</small></p>';
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
        avMostrarHistoricoPlaca(c.placa, 'av-historico-selecionado');
    };

    // "Veículo não está na lista? Cadastrar um novo agora" — 28/09/2026,
    // só faz sentido pra COMPRA (vistoria de venda precisa de um
    // comprador/negociação já existente, não de um veículo novo).
    var checkboxNovo = document.getElementById('av-veiculo-novo');
    var blocoNovo = document.getElementById('av-bloco-novo');
    var blocoNovoToggle = document.getElementById('av-bloco-novo-toggle');
    var buscaBloco = document.getElementById('av-busca-bloco');
    // 28/09/2026, "tem campos que não tem necessidade... carro recuperado
    // pela fastcar", "pode ser opcional" — nome/telefone do vendedor NUNCA
    // são required aqui (diferente do mesmo card em admin/vendas.php, que
    // sempre tem um comprador de verdade do outro lado) — deixados em
    // branco, o servidor gera um placeholder (gerarTelefonePlaceholderVeiculoRecuperado()).

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
            btnCriar.disabled = false;
        } else {
            buscaBloco.style.display = 'block';
            blocoNovo.style.display = 'none';
            btnCriar.disabled = true;
        }
    };

    // Busca dados FIPE pela placa (includes/fipe.php::placafipeConsultarPorPlaca(),
    // mesmo endpoint já usado em admin/oportunidade.php) — nunca a ZapCar
    // (que é consulta paga de restrição/débito, sem relação com isso). Os
    // campos existem sempre no DOM (com ou sem token configurado) — só o
    // botão de busca em si é condicional (admin/fipe_ajax.php exige o
    // token de Configurações → FIPE pra funcionar de verdade).
    var campoPlaca = document.getElementById('av-veiculo-placa');
    var fipeResultadoEl = document.getElementById('av-fipe-resultado');
    var campoMarca = document.getElementById('av-veiculo-marca');
    var campoModelo = document.getElementById('av-veiculo-modelo');
    var campoAno = document.getElementById('av-veiculo-ano');
    var btnBuscarPlaca = document.getElementById('av-buscar-placa-btn');

    function avExecutarBuscaPlaca() {
        var placa = campoPlaca.value.trim();
        if (!placa) { if (fipeResultadoEl) fipeResultadoEl.textContent = '⚠️ Digite a placa primeiro.'; return; }
        avMostrarHistoricoPlaca(placa, 'av-historico-placa');
        if (btnBuscarPlaca) btnBuscarPlaca.disabled = true;
        if (fipeResultadoEl) fipeResultadoEl.textContent = 'Buscando…';
        fetch('/admin/fipe_ajax.php?acao=buscar_placa&placa=' + encodeURIComponent(placa))
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (btnBuscarPlaca) btnBuscarPlaca.disabled = false;
                if (!fipeResultadoEl) return;
                if (!data.ok) {
                    fipeResultadoEl.textContent = '⚠️ ' + (data.msg || 'Não consegui buscar essa placa.');
                    return;
                }
                var v = data.veiculo;
                var preenchido = false;
                // Fill-if-empty — nunca sobrescreve o que já foi digitado.
                if (v) {
                    if (!campoMarca.value && v.marca) { campoMarca.value = v.marca; preenchido = true; }
                    if (!campoModelo.value && v.modelo) { campoModelo.value = v.modelo; preenchido = true; }
                    if (!campoAno.value && v.ano_modelo) { campoAno.value = v.ano_modelo; preenchido = true; }
                }
                fipeResultadoEl.textContent = preenchido
                    ? '✅ Marca/modelo/ano preenchidos — confira antes de salvar.'
                    : (v ? 'Veículo encontrado, mas os campos já estavam preenchidos.' : 'Nenhum dado encontrado pra essa placa.');
            })
            .catch(function () {
                if (btnBuscarPlaca) btnBuscarPlaca.disabled = false;
                if (fipeResultadoEl) fipeResultadoEl.textContent = '⚠️ Erro ao buscar — tente de novo.';
            });
    }

    if (btnBuscarPlaca) btnBuscarPlaca.addEventListener('click', avExecutarBuscaPlaca);
    // Histórico por placa nunca depende do token FIPE — checa direto no
    // banco da Fastcar, então roda também ao sair do campo digitando a
    // placa na mão (sem token configurado, o botão de FIPE nem existe).
    campoPlaca.addEventListener('blur', function () { avMostrarHistoricoPlaca(campoPlaca.value, 'av-historico-placa'); });

    // Atalho do "Nenhum resultado" da busca normal — 28/09/2026, "avalista
    // digita placa, se não tiver cadastra um novo": pula direto pro
    // sub-formulário de cadastro já com a placa preenchida (nunca precisa
    // digitar de novo), já checa o histórico dessa placa na Fastcar, e
    // dispara a busca FIPE sozinha se o token estiver configurado.
    window.avUsarTermoComoVeiculoNovo = function () {
        var termo = termoEl.value.trim();
        checkboxNovo.checked = true;
        avAlternarVeiculoNovo();
        if (termo) {
            campoPlaca.value = termo.toUpperCase();
            if (btnBuscarPlaca) {
                avExecutarBuscaPlaca(); // já dispara o histórico por dentro
            } else {
                avMostrarHistoricoPlaca(campoPlaca.value, 'av-historico-placa');
            }
        }
        blocoNovo.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    };

    // 28/09/2026, "Permita subir documento do carro pra ler informações
    // caso api fipe não funcione" — mesmo endpoint/prompt já usado em
    // admin/vendas.php ("Vender na Promissória") e admin/veiculos.php,
    // reaproveitado sem duplicar nada (admin/veiculo_crlv_ajax.php, guard
    // relaxado pra também aceitar o perfil avaliador). Preenche
    // marca/modelo/ano/placa/chassi/renavam fill-if-empty, e dispara o
    // histórico por placa também (mesmo tratamento do botão de FIPE).
    var btnCrlv = document.getElementById('av-crlv-btn');
    if (btnCrlv) {
        var inputCrlv = document.getElementById('av-crlv-arquivo');
        var statusCrlv = document.getElementById('av-crlv-status');
        var csrfTokenModal = document.querySelector('#form-vistoria [name="csrf_token"]').value;

        btnCrlv.addEventListener('click', function () {
            if (!inputCrlv.files.length) { statusCrlv.textContent = '⚠️ Escolha o arquivo do CRLV primeiro.'; return; }
            statusCrlv.textContent = '🔄 Lendo CRLV...';
            btnCrlv.disabled = true;

            var fd = new FormData();
            fd.append('crlv', inputCrlv.files[0]);
            fd.append('csrf_token', csrfTokenModal);
            fetch('/admin/veiculo_crlv_ajax.php', { method: 'POST', body: fd })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    btnCrlv.disabled = false;
                    if (!d.ok) { statusCrlv.textContent = '⚠️ ' + d.erro; return; }
                    var campos = {
                        'av-veiculo-marca': d.veiculo_marca,
                        'av-veiculo-modelo': d.veiculo_modelo,
                        'av-veiculo-ano': d.veiculo_ano,
                        'av-veiculo-placa': d.veiculo_placa,
                        'av-veiculo-chassi': d.veiculo_chassi,
                        'av-veiculo-renavam': d.veiculo_renavam,
                    };
                    Object.keys(campos).forEach(function (id) {
                        var el = document.getElementById(id);
                        if (el && !el.value && campos[id]) el.value = campos[id];
                    });
                    statusCrlv.textContent = '✅ Preenchido! Confira antes de criar a vistoria.';
                    if (campoPlaca.value) avMostrarHistoricoPlaca(campoPlaca.value, 'av-historico-placa');
                })
                .catch(function () { btnCrlv.disabled = false; statusCrlv.textContent = '⚠️ Erro ao ler o CRLV.'; });
        });
    }
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
