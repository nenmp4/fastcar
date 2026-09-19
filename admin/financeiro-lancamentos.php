<?php
/**
 * Lançamentos financeiros — CRUD manual (17/09/2026, "subir os
 * lançamentos"). Vínculo com cliente é sempre opcional e pode ser manual
 * (cliente_nome_manual, texto livre) — nem todo lançamento tem uma linha
 * real em `clientes`/`vendas` pra linkar (ver nota em install/schema.sql).
 * Cobranças importadas do Asaas (origem='asaas') aparecem na mesma lista,
 * mas não são editáveis por aqui — o Asaas é a fonte de verdade delas (ver
 * admin/financeiro-asaas.php).
 */

require_once __DIR__ . '/_bootstrap.php';
requireAcessoFinanceiro();

$db = getDB();
$erro = '';
$sucesso = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        $erro = 'Sessão expirada, recarregue a página e tente de novo.';
    } else {
        $acao = (string)($_POST['acao'] ?? '');

        if ($acao === 'salvar') {
            $id = (int)($_POST['id'] ?? 0);
            $existente = $id ? $db->query("SELECT origem FROM fin_lancamentos WHERE id=" . $id)->fetch(PDO::FETCH_ASSOC) : null;
            if ($existente && $existente['origem'] === 'asaas') {
                $erro = 'Este lançamento veio do Asaas — não é editável por aqui.';
            } else {
                $tipo = in_array($_POST['tipo'] ?? '', ['receita', 'despesa'], true) ? $_POST['tipo'] : 'despesa';
                $categoriaId = (int)($_POST['categoria_id'] ?? 0) ?: null;
                $descricao = clean((string)($_POST['descricao'] ?? ''));
                $valor = (float)str_replace(',', '.', preg_replace('/[^\d,.-]/', '', (string)($_POST['valor'] ?? '0')));
                $natureza = in_array($_POST['natureza'] ?? '', ['fixa', 'variavel'], true) ? $_POST['natureza'] : '';
                $vencimento = trim((string)($_POST['data_vencimento'] ?? '')) ?: null;
                $pagamento = trim((string)($_POST['data_pagamento'] ?? '')) ?: null;
                $clienteId = (int)($_POST['cliente_id'] ?? 0) ?: null;
                $clienteNomeManual = clean((string)($_POST['cliente_nome_manual'] ?? ''));
                $oportunidadeId = (int)($_POST['oportunidade_id'] ?? 0) ?: null;
                $vendaId = (int)($_POST['venda_id'] ?? 0) ?: null;
                $funcionarioId = (int)($_POST['funcionario_id'] ?? 0) ?: null;
                $fornecedorId = (int)($_POST['fornecedor_id'] ?? 0) ?: null;
                $formaPgtoSel = (string)($_POST['forma_pagamento'] ?? '');
                $formaPgto = $formaPgtoSel === 'outro' ? clean((string)($_POST['forma_pagamento_outro'] ?? '')) : clean($formaPgtoSel);
                $recorrente = isset($_POST['recorrente']) ? 1 : 0;
                $recIntervalo = $recorrente ? (in_array($_POST['recorrencia_intervalo'] ?? '', ['mensal', 'quinzenal', 'anual'], true) ? $_POST['recorrencia_intervalo'] : 'mensal') : '';
                $obs = clean((string)($_POST['observacoes'] ?? ''));
                $userId = (int)($_SESSION['admin_id'] ?? 0);
                $status = finCalcularStatus($pagamento, $vencimento);

                $driveFileId = trim((string)($_POST['drive_file_id_atual'] ?? ''));
                $arquivoUrl = trim((string)($_POST['arquivo_url_atual'] ?? ''));
                if (!empty($_FILES['anexo']['tmp_name']) && is_uploaded_file($_FILES['anexo']['tmp_name'])) {
                    $novoAnexo = finSalvarAnexo($_FILES['anexo']);
                    if ($novoAnexo['drive_file_id'] || $novoAnexo['arquivo_url']) {
                        $driveFileId = $novoAnexo['drive_file_id'];
                        $arquivoUrl = $novoAnexo['arquivo_url'];
                    }
                }

                if (!$descricao) {
                    $erro = 'Descrição é obrigatória.';
                } elseif ($valor <= 0) {
                    $erro = 'Valor deve ser maior que zero.';
                } else {
                    if ($id) {
                        $db->prepare("
                            UPDATE fin_lancamentos SET tipo=?, categoria_id=?, descricao=?, valor=?, natureza=?, data_vencimento=?, data_pagamento=?, status=?,
                                cliente_id=?, cliente_nome_manual=?, oportunidade_id=?, venda_id=?, funcionario_id=?, fornecedor_id=?, forma_pagamento=?,
                                recorrente=?, recorrencia_intervalo=?, drive_file_id=?, arquivo_url=?, observacoes=?, updated_at=datetime('now','localtime')
                            WHERE id=?
                        ")->execute([$tipo, $categoriaId, $descricao, $valor, $natureza, $vencimento, $pagamento, $status, $clienteId, $clienteNomeManual, $oportunidadeId, $vendaId, $funcionarioId, $fornecedorId, $formaPgto, $recorrente, $recIntervalo, $driveFileId, $arquivoUrl, $obs, $id]);
                        $sucesso = 'Lançamento atualizado.';
                    } else {
                        $db->prepare("
                            INSERT INTO fin_lancamentos
                                (tipo, categoria_id, descricao, valor, natureza, data_vencimento, data_pagamento, status, cliente_id, cliente_nome_manual, oportunidade_id, venda_id, funcionario_id, fornecedor_id, forma_pagamento, recorrente, recorrencia_intervalo, drive_file_id, arquivo_url, observacoes, origem, created_by)
                            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?, 'manual', ?)
                        ")->execute([$tipo, $categoriaId, $descricao, $valor, $natureza, $vencimento, $pagamento, $status, $clienteId, $clienteNomeManual, $oportunidadeId, $vendaId, $funcionarioId, $fornecedorId, $formaPgto, $recorrente, $recIntervalo, $driveFileId, $arquivoUrl, $obs, $userId]);
                        $sucesso = 'Lançamento criado.';
                    }
                }
            }
        } elseif ($acao === 'marcar_pago') {
            $id = (int)($_POST['id'] ?? 0);
            $db->prepare("UPDATE fin_lancamentos SET data_pagamento=date('now','localtime'), status='pago', updated_at=datetime('now','localtime') WHERE id=? AND origem != 'asaas'")->execute([$id]);
            $sucesso = 'Marcado como pago.';
        } elseif ($acao === 'excluir') {
            $id = (int)($_POST['id'] ?? 0);
            $db->prepare("DELETE FROM fin_lancamentos WHERE id=? AND origem != 'asaas'")->execute([$id]);
            $sucesso = 'Lançamento excluído.';
        } elseif ($acao === 'classificar') {
            // 19/09/2026, "aqui muda as cores - provalmente as receitas que
            // entraram sem cliente deve se pagamento de entrada da compra
            // do veiculo - classificar dessa forma pode diferencia na cor"
            // → "vamos clasificar" — cobrança importada do Asaas sem
            // cliente_nome_manual resolvido (Pix avulso, sem match em
            // fin_asaas_clientes) é ambígua: pode ser a entrada da compra
            // ou uma parcela normal ainda sem vínculo. Reclassifica só a
            // CATEGORIA (nunca os outros campos — Asaas continua sendo a
            // fonte de verdade do valor/status/data) — categoria nunca é
            // tocada pela resincronização (`asaasImportarCobrancas()`, só
            // preenchida no INSERT de linha nova), então essa troca manual
            // nunca é perdida num resync futuro. Funciona pra QUALQUER
            // lançamento, inclusive `origem='asaas'` — diferente do resto
            // do formulário (bloqueado pra Asaas), classificar por
            // categoria sempre foi pensado como edição manual mesmo pra
            // linha importada (só não tinha UI nenhuma pra isso ainda).
            $id = (int)($_POST['id'] ?? 0);
            $tipoClass = (string)($_POST['tipo_classificacao'] ?? '');
            $mapaClass = [
                'entrada' => 'Venda de veículo — entrada',
                'parcela' => 'Venda de veículo — parcela',
            ];
            if (isset($mapaClass[$tipoClass])) {
                $catStmt = $db->prepare('SELECT id FROM fin_categorias WHERE nome=?');
                $catStmt->execute([$mapaClass[$tipoClass]]);
                $catId = $catStmt->fetchColumn();
                if ($catId) {
                    // Entrada é sempre parcela_numero=0 (mesma convenção de
                    // finGerarPlanoParcelamentoVenda()); parcela normal sem
                    // número identificado fica NULL, nunca chutado.
                    $novoParcelaNumero = $tipoClass === 'entrada' ? 0 : null;
                    $db->prepare('UPDATE fin_lancamentos SET categoria_id=?, parcela_numero=?, updated_at=datetime(\'now\',\'localtime\') WHERE id=?')
                        ->execute([$catId, $novoParcelaNumero, $id]);
                    $sucesso = 'Lançamento classificado como ' . ($tipoClass === 'entrada' ? 'entrada' : 'parcela') . '.';
                } else {
                    $erro = 'Categoria padrão não encontrada — confira em Categorias.';
                }
            }
        }
    }
}

finRecalcularAtrasados();

$editando = null;
if (($_GET['action'] ?? '') === 'edit' && !empty($_GET['id'])) {
    $stmt = $db->prepare('SELECT * FROM fin_lancamentos WHERE id=?');
    $stmt->execute([(int)$_GET['id']]);
    $editando = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}
// 18/09/2026, "lyout poderia novo lançamentos ser modal fica mais bonitos"
// — formulário de novo/editar lançamento virou <dialog> (nativo do
// navegador, sem lib nova) em vez de card sempre visível ocupando o topo
// da página; abre sozinho quando chega por aqui via edição (?action=edit)
// ou pelo botão "Novo lançamento" (?novo=1), sem mudar nada do POST/fluxo
// de salvar — mesma URL, mesmo formulário, só a apresentação mudou.
$abrirModalLancamento = $editando !== null || isset($_GET['novo']);
// 19/09/2026, "la no financeiro não conseguimos ver detalhes da receitas"
// — lançamento importado do Asaas (a maioria das receitas reais, parcela
// de venda de veículo) não tinha NENHUM link clicável na tabela, só o
// texto estático "via Asaas" (correto não poder editar — o Asaas é a
// fonte de verdade — mas errado não dar nem pra VER parcela/ID do
// pagamento/observações/anexo). Mesmo modal de sempre, só em modo
// somente-leitura (sem POST possível de qualquer forma, `origem != 'asaas'`
// já trava no servidor) via <fieldset disabled>.
$somenteLeituraLancamento = ($editando['origem'] ?? '') === 'asaas';

$fTipo = (string)($_GET['tipo'] ?? '');
$fStatus = (string)($_GET['status'] ?? '');
$fTodos = !empty($_GET['todos_periodos']);
$fDe = (string)($_GET['de'] ?? date('Y-m-01'));
$fAte = (string)($_GET['ate'] ?? date('Y-m-t'));
// 18/09/2026, "em atrasados da para colocar separados atraso 3 meses -
// atraso 2 meses - atraso de 1 mes pois vamos fazer gestão desses
// clientes que não paga" — bucket por dias em atraso a partir do
// vencimento (1-30 dias = "1 mês", 31-60 = "2 meses", 61+ = "3+ meses",
// mesmo corte usado nos artigos do blog sobre busca e apreensão — só faz
// sentido combinado com status=atrasado, ignorado em qualquer outro filtro.
$fAtraso = (int)($_GET['atraso'] ?? 0);
$diasAtrasoExpr = "CAST((julianday('now','localtime') - julianday(l.data_vencimento)) AS INTEGER)";

$where = [];
$params = [];
if (!$fTodos) {
    $where[] = "COALESCE(l.data_pagamento, l.data_vencimento, l.created_at) BETWEEN ? AND ?";
    $params[] = $fDe;
    $params[] = $fAte;
}
if ($fTipo) { $where[] = 'l.tipo=?'; $params[] = $fTipo; }
if ($fStatus) { $where[] = 'l.status=?'; $params[] = $fStatus; }
if ($fStatus === 'atrasado' && $fAtraso) {
    $where[] = "l.data_vencimento IS NOT NULL AND " . ($fAtraso === 1 ? "{$diasAtrasoExpr} BETWEEN 1 AND 30" : ($fAtraso === 2 ? "{$diasAtrasoExpr} BETWEEN 31 AND 60" : "{$diasAtrasoExpr} >= 61"));
}
$whereSql = $where ? implode(' AND ', $where) : '1=1';

$stmt = $db->prepare("
    SELECT l.*, c.nome as categoria_nome, c.icone, fo.nome as fornecedor_nome, fc.nome as funcionario_nome,
           CASE WHEN l.status='atrasado' AND l.data_vencimento IS NOT NULL THEN {$diasAtrasoExpr} ELSE NULL END AS dias_atraso
    FROM fin_lancamentos l
    LEFT JOIN fin_categorias c ON c.id=l.categoria_id
    LEFT JOIN fin_fornecedores fo ON fo.id=l.fornecedor_id
    LEFT JOIN fin_colaboradores fc ON fc.id=l.funcionario_id
    WHERE {$whereSql}
    ORDER BY COALESCE(l.data_vencimento, l.created_at) DESC LIMIT 200
");
$stmt->execute($params);
$lancamentos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Contagem por faixa de atraso, sempre sem filtro de tipo/período — mesmo
// escopo do card "Contas atrasadas" do dashboard financeiro (todo atrasado,
// qualquer mês) — usada nos badges clicáveis acima da tabela.
$bucketsAtraso = ['1' => 0, '2' => 0, '3' => 0];
if ($fStatus === 'atrasado') {
    $r = $db->query("
        SELECT
            SUM(CASE WHEN {$diasAtrasoExpr} BETWEEN 1 AND 30 THEN 1 ELSE 0 END) AS b1,
            SUM(CASE WHEN {$diasAtrasoExpr} BETWEEN 31 AND 60 THEN 1 ELSE 0 END) AS b2,
            SUM(CASE WHEN {$diasAtrasoExpr} >= 61 THEN 1 ELSE 0 END) AS b3
        FROM fin_lancamentos l
        WHERE l.status='atrasado' AND l.data_vencimento IS NOT NULL
    ")->fetch(PDO::FETCH_ASSOC);
    $bucketsAtraso = ['1' => (int)($r['b1'] ?? 0), '2' => (int)($r['b2'] ?? 0), '3' => (int)($r['b3'] ?? 0)];
}

$categorias = $db->query('SELECT * FROM fin_categorias WHERE ativo=1 ORDER BY tipo, nome')->fetchAll(PDO::FETCH_ASSOC);
$colaboradores = $db->query("SELECT id, nome FROM fin_colaboradores WHERE status='ativo' ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC);
$fornecedores = $db->query("SELECT id, nome FROM fin_fornecedores WHERE status='ativo' ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC);

$formasPagamento = ['pix' => 'Pix', 'boleto' => 'Boleto', 'cartao' => 'Cartão', 'dinheiro' => 'Dinheiro', 'transferencia' => 'Transferência'];
$statusLabels = [
    'pendente' => ['Pendente', '#92400e', '#fffbeb'],
    'pago' => ['Pago', '#166534', '#f0fdf4'],
    'atrasado' => ['Atrasado', '#991b1b', '#fef2f2'],
    'cancelado' => ['Cancelado', '#475569', '#f1f5f9'],
];
$origemLabels = ['manual' => '', 'parcelamento_venda' => '🚗 plano de parcelamento', 'asaas' => '🔄 Asaas', 'fechamento_compra' => '🚗 fechamento de compra', 'recorrencia_fixa' => '🔁 despesa fixa (automática)'];
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Lançamentos — Financeiro Fastcar</title>
<link rel="stylesheet" href="/admin/assets/style.css?v=<?= @filemtime(__DIR__ . '/assets/style.css') ?: 1 ?>">
<?php include __DIR__ . '/_pwa_head.php'; ?>
</head>
<body>
<header class="topbar">
    <a href="/admin/financeiro.php" style="color:#fff">← Voltar</a>
    <strong><img class="topbar-logo" src="/admin/assets/img/icon-192.png" alt="Fastcar" onerror="this.style.display='none'"> Fast<b>Car</b></strong>
    <span>Olá, <?= e($_SESSION['admin_nome']) ?></span>
    <a href="/admin/logout.php">Sair</a>
</header>
<main>

<?php if ($erro): ?><div class="alerta-erro"><?= e($erro) ?></div><?php endif; ?>
<?php if ($sucesso): ?><div class="alerta-sucesso"><?= e($sucesso) ?></div><?php endif; ?>

<div style="display:flex;justify-content:flex-end;margin-bottom:1rem">
  <a href="?novo=1" class="btn-primary" style="width:auto">➕ Novo lançamento</a>
</div>

<dialog id="modal-lancamento" class="modal-lancamento">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.5rem">
    <h2 style="margin:0"><?= $somenteLeituraLancamento ? '👁️ Detalhes do lançamento (Asaas)' : ($editando ? '✏️ Editar lançamento' : '➕ Novo lançamento') ?></h2>
    <button type="button" onclick="document.getElementById('modal-lancamento').close()" style="background:none;border:none;font-size:1.6rem;font-weight:700;cursor:pointer;line-height:1;padding:0 .25rem;color:var(--texto-suave)" aria-label="Fechar">&times;</button>
  </div>
  <?php if ($somenteLeituraLancamento): ?>
    <div class="alerta-info" style="margin-bottom:1rem">
      🔄 Este lançamento veio do Asaas — o Asaas é a fonte de verdade, então os campos abaixo são só pra consulta, não é possível editar por aqui.
      <ul style="margin:.5rem 0 0;padding-left:1.2rem">
        <?php if ((int)($editando['parcela_total'] ?? 0) > 1): ?>
          <li><strong>Parcela:</strong> <?= (int)$editando['parcela_numero'] === 0 ? 'Entrada' : ((int)$editando['parcela_numero'] . ' de ' . (int)$editando['parcela_total']) ?></li>
        <?php endif; ?>
        <?php if (!empty($editando['asaas_payment_id'])): ?>
          <li><strong>ID da cobrança no Asaas:</strong> <?= e($editando['asaas_payment_id']) ?></li>
        <?php endif; ?>
        <?php if (!empty($editando['asaas_customer_id'])): ?>
          <li><strong>ID do cliente no Asaas:</strong> <?= e($editando['asaas_customer_id']) ?></li>
        <?php endif; ?>
      </ul>
    </div>
  <?php endif; ?>
  <form method="POST" enctype="multipart/form-data">
    <?= csrfField() ?>
    <input type="hidden" name="acao" value="salvar">
    <input type="hidden" name="id" value="<?= (int)($editando['id'] ?? 0) ?>">
    <input type="hidden" name="drive_file_id_atual" value="<?= e($editando['drive_file_id'] ?? '') ?>">
    <input type="hidden" name="arquivo_url_atual" value="<?= e($editando['arquivo_url'] ?? '') ?>">
    <fieldset <?= $somenteLeituraLancamento ? 'disabled' : '' ?> style="border:none;padding:0;margin:0">

    <div class="grid-2">
      <div>
        <label>Tipo</label>
        <select name="tipo">
          <option value="despesa" <?= ($editando['tipo'] ?? 'despesa') === 'despesa' ? 'selected' : '' ?>>📤 Despesa</option>
          <option value="receita" <?= ($editando['tipo'] ?? '') === 'receita' ? 'selected' : '' ?>>📥 Receita</option>
        </select>
        <label>Categoria</label>
        <select name="categoria_id">
          <option value="">— Selecione —</option>
          <?php foreach ($categorias as $c): ?>
            <option value="<?= (int)$c['id'] ?>" <?= (int)($editando['categoria_id'] ?? 0) === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['icone'] . ' ' . $c['nome']) ?></option>
          <?php endforeach; ?>
        </select>
        <label>Descrição *</label>
        <input type="text" name="descricao" required value="<?= e($editando['descricao'] ?? '') ?>">
        <label>Valor (R$) *</label>
        <input type="text" name="valor" required value="<?= e((string)($editando['valor'] ?? '')) ?>" placeholder="0,00">
        <label>Forma de pagamento</label>
        <?php $formaAtual = (string)($editando['forma_pagamento'] ?? ''); $formaEhPadrao = in_array($formaAtual, $formasPagamento, true) || $formaAtual === ''; ?>
        <select name="forma_pagamento" onchange="document.getElementById('fl-forma-outro').style.display=this.value==='outro'?'block':'none'">
          <option value="">—</option>
          <?php foreach ($formasPagamento as $k => $lbl): ?>
            <option value="<?= e($lbl) ?>" <?= $formaAtual === $lbl ? 'selected' : '' ?>><?= e($lbl) ?></option>
          <?php endforeach; ?>
          <option value="outro" <?= !$formaEhPadrao ? 'selected' : '' ?>>Outro...</option>
        </select>
        <input type="text" name="forma_pagamento_outro" id="fl-forma-outro" value="<?= !$formaEhPadrao ? e($formaAtual) : '' ?>" placeholder="Especifique" style="display:<?= !$formaEhPadrao ? 'block' : 'none' ?>">
      </div>
      <div>
        <label>Vencimento</label>
        <input type="date" name="data_vencimento" value="<?= e($editando['data_vencimento'] ?? '') ?>">
        <label>Pagamento (deixe vazio se pendente)</label>
        <input type="date" name="data_pagamento" value="<?= e($editando['data_pagamento'] ?? '') ?>">
        <label>Cliente — nome (pode ser manual, sem precisar de cadastro)</label>
        <input type="text" name="cliente_nome_manual" value="<?= e($editando['cliente_nome_manual'] ?? '') ?>" placeholder="Ex: João da Silva">
        <label>Cliente — ID do cadastro (opcional, se já existe em Clientes)</label>
        <input type="number" name="cliente_id" value="<?= (int)($editando['cliente_id'] ?? 0) ?: '' ?>">
        <div class="grid-2">
          <div>
            <label>Oportunidade # (compra, opcional)</label>
            <input type="number" name="oportunidade_id" value="<?= (int)($editando['oportunidade_id'] ?? 0) ?: '' ?>">
          </div>
          <div>
            <label>Venda # (revenda, opcional)</label>
            <input type="number" name="venda_id" value="<?= (int)($editando['venda_id'] ?? 0) ?: '' ?>">
          </div>
        </div>
      </div>
    </div>

    <div class="grid-2">
      <div>
        <label>Natureza (só despesa)</label>
        <select name="natureza">
          <option value="">—</option>
          <option value="fixa" <?= ($editando['natureza'] ?? '') === 'fixa' ? 'selected' : '' ?>>Fixa</option>
          <option value="variavel" <?= ($editando['natureza'] ?? '') === 'variavel' ? 'selected' : '' ?>>Variável</option>
        </select>
        <small style="color:var(--muted)">🔁 "Fixa" lança sozinha o mês seguinte automaticamente (mesmo valor/categoria da última vez) — pra parar, marque o último mês como "Cancelado".</small>
        <label>Colaborador (salário/pró-labore)</label>
        <select name="funcionario_id">
          <option value="">—</option>
          <?php foreach ($colaboradores as $f): ?>
            <option value="<?= (int)$f['id'] ?>" <?= (int)($editando['funcionario_id'] ?? 0) === (int)$f['id'] ? 'selected' : '' ?>><?= e($f['nome']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label>Fornecedor</label>
        <select name="fornecedor_id">
          <option value="">—</option>
          <?php foreach ($fornecedores as $fo): ?>
            <option value="<?= (int)$fo['id'] ?>" <?= (int)($editando['fornecedor_id'] ?? 0) === (int)$fo['id'] ? 'selected' : '' ?>><?= e($fo['nome']) ?></option>
          <?php endforeach; ?>
        </select>
        <label><input type="checkbox" name="recorrente" value="1" <?= !empty($editando['recorrente']) ? 'checked' : '' ?> style="width:auto;display:inline-block"> 🔁 Recorrente</label>
        <select name="recorrencia_intervalo">
          <?php foreach (['mensal' => 'Mensal', 'quinzenal' => 'Quinzenal (a cada 15 dias)', 'anual' => 'Anual'] as $k => $lbl): ?>
            <option value="<?= $k ?>" <?= ($editando['recorrencia_intervalo'] ?? 'mensal') === $k ? 'selected' : '' ?>><?= $lbl ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <label>📎 Anexar comprovante</label>
    <input type="file" name="anexo" accept=".jpg,.jpeg,.png,.webp,.heic,.heif,.pdf">
    <?php if (!empty($editando['drive_file_id']) || !empty($editando['arquivo_url'])): ?>
      <div><small><a href="/admin/ver_anexo_financeiro.php?id=<?= (int)$editando['id'] ?>" target="_blank">📄 Ver anexo atual</a></small></div>
    <?php endif; ?>
    <label>Observações</label>
    <textarea name="observacoes" rows="2"><?= e($editando['observacoes'] ?? '') ?></textarea>

    </fieldset>
    <?php if (!$somenteLeituraLancamento): ?>
      <button type="submit"><?= $editando ? 'Salvar alterações' : 'Lançar' ?></button>
    <?php endif; ?>
    <button type="button" onclick="document.getElementById('modal-lancamento').close()"><?= $somenteLeituraLancamento ? 'Fechar' : 'Cancelar' ?></button>
  </form>
</dialog>
<?php if ($abrirModalLancamento): ?>
<script>document.getElementById('modal-lancamento').showModal();</script>
<?php endif; ?>

<div class="card" style="margin-bottom:1.5rem">
  <form method="GET" style="display:flex;gap:.75rem;flex-wrap:wrap;align-items:flex-end">
    <div><label>De</label><input type="date" name="de" value="<?= e($fDe) ?>" <?= $fTodos ? 'disabled' : '' ?>></div>
    <div><label>Até</label><input type="date" name="ate" value="<?= e($fAte) ?>" <?= $fTodos ? 'disabled' : '' ?>></div>
    <div><label><input type="checkbox" name="todos_periodos" value="1" <?= $fTodos ? 'checked' : '' ?> style="width:auto;display:inline-block"> Todos os períodos</label></div>
    <div><label>Tipo</label><select name="tipo"><option value="">Todos</option><option value="receita" <?= $fTipo === 'receita' ? 'selected' : '' ?>>Receita</option><option value="despesa" <?= $fTipo === 'despesa' ? 'selected' : '' ?>>Despesa</option></select></div>
    <div><label>Status</label><select name="status"><option value="">Todos</option><?php foreach ($statusLabels as $k => [$lbl,,]): ?><option value="<?= $k ?>" <?= $fStatus === $k ? 'selected' : '' ?>><?= $lbl ?></option><?php endforeach; ?></select></div>
    <?php if ($fAtraso): ?><input type="hidden" name="atraso" value="<?= (int)$fAtraso ?>"><?php endif; ?>
    <button type="submit" style="width:auto">Filtrar</button>
  </form>
</div>

<?php if ($fStatus === 'atrasado'): ?>
<div class="card" style="margin-bottom:1.5rem">
  <div style="font-size:.8rem;color:var(--muted);font-weight:600;margin-bottom:.5rem">🔴 Gestão de cobrança — separado por tempo de atraso</div>
  <div style="display:flex;gap:.75rem;flex-wrap:wrap">
    <?php
    $baseQs = 'status=atrasado&todos_periodos=1' . ($fTipo ? '&tipo=' . urlencode($fTipo) : '');
    $bucketsLabel = ['1' => '1 mês (1-30 dias)', '2' => '2 meses (31-60 dias)', '3' => '3+ meses (61+ dias)'];
    ?>
    <?php foreach ($bucketsLabel as $n => $lblBucket): ?>
      <a href="?<?= $baseQs ?>&atraso=<?= $n ?>" class="btn<?= $fAtraso === (int)$n ? '-primary' : '' ?>" style="width:auto;text-decoration:none">
        ⏰ <?= $lblBucket ?> — <strong><?= $bucketsAtraso[$n] ?></strong>
      </a>
    <?php endforeach; ?>
    <?php if ($fAtraso): ?><a href="?<?= $baseQs ?>" style="align-self:center">Ver todos os atrasados</a><?php endif; ?>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <h2>📋 Lançamentos (<?= count($lancamentos) ?>)</h2>
  <table class="tabela-oportunidades">
    <thead><tr><th>Vencimento</th><th>Descrição</th><th>Categoria</th><th>Cliente</th><th>Valor</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($lancamentos as $l): [$lbl, $cor, $bg] = $statusLabels[$l['status']] ?? ['—', '#475569', '#f1f5f9'];
      $vinculo = $l['cliente_nome_manual'] ?: ($l['fornecedor_nome'] ?? $l['funcionario_nome'] ?? '');
      // 19/09/2026, "aqui muda as cores - provalmente as receitas que
      // entraram sem cliente deve se pagamento de entrada da compra do
      // veiculo - classificar dessa forma pode diferencia na cor" →
      // "vamos clasificar" — lançamento sem NENHUM vínculo resolvido
      // (cliente/fornecedor/colaborador, mostrava só "—" antes) é ambíguo
      // o bastante pra merecer destaque visual + ação rápida de
      // classificar, nunca decidido sozinho (regra #3) — só sinaliza pro
      // financeiro revisar.
      $semVinculo = $vinculo === '';
    ?>
      <tr <?= $semVinculo ? 'class="linha-sem-vinculo"' : '' ?>>
        <td><?= $l['data_vencimento'] ? date('d/m/Y', strtotime($l['data_vencimento'])) : '—' ?></td>
        <td><?= e($l['descricao']) ?> <?php if ($origemLabels[$l['origem']] ?? ''): ?><br><small><?= e($origemLabels[$l['origem']]) ?></small><?php endif; ?></td>
        <td><?= e(($l['icone'] ?? '') . ' ' . ($l['categoria_nome'] ?? '—')) ?></td>
        <td>
          <?= e($vinculo ?: '—') ?>
          <?php if ($semVinculo): ?><br><small style="color:#c2410c">⚠️ sem cliente vinculado</small><?php endif; ?>
        </td>
        <td style="font-weight:700;color:<?= $l['tipo'] === 'receita' ? '#166534' : '#991b1b' ?>"><?= $l['tipo'] === 'receita' ? '+' : '-' ?> R$ <?= number_format((float)$l['valor'], 2, ',', '.') ?></td>
        <td>
          <span class="badge" style="background:<?= $bg ?>;color:<?= $cor ?>"><?= $lbl ?></span>
          <?php if ($l['dias_atraso'] !== null): ?>
            <br><small style="color:#991b1b"><?= (int)$l['dias_atraso'] ?> dia(s)</small>
          <?php endif; ?>
        </td>
        <td style="white-space:nowrap">
          <?php if ($l['origem'] !== 'asaas'): ?>
            <a href="?action=edit&id=<?= (int)$l['id'] ?>">✏️</a>
            <?php if ($l['status'] !== 'pago'): ?>
              <form method="POST" style="display:inline"><?= csrfField() ?><input type="hidden" name="acao" value="marcar_pago"><input type="hidden" name="id" value="<?= (int)$l['id'] ?>"><button type="submit" style="background:none;border:none;cursor:pointer" title="Marcar pago">✅</button></form>
            <?php endif; ?>
            <form method="POST" style="display:inline" onsubmit="return confirm('Excluir este lançamento?')"><?= csrfField() ?><input type="hidden" name="acao" value="excluir"><input type="hidden" name="id" value="<?= (int)$l['id'] ?>"><button type="submit" style="background:none;border:none;cursor:pointer" title="Excluir">🗑️</button></form>
          <?php else: ?>
            <a href="?action=edit&id=<?= (int)$l['id'] ?>" title="Ver detalhes">👁️ via Asaas</a>
          <?php endif; ?>
          <?php if ($semVinculo && $l['tipo'] === 'receita'): ?>
            <form method="POST" style="display:inline"><?= csrfField() ?><input type="hidden" name="acao" value="classificar"><input type="hidden" name="id" value="<?= (int)$l['id'] ?>"><input type="hidden" name="tipo_classificacao" value="entrada"><button type="submit" style="background:none;border:none;cursor:pointer;color:#c2410c" title="Classificar como entrada da venda">🏷️ Entrada</button></form>
            <form method="POST" style="display:inline"><?= csrfField() ?><input type="hidden" name="acao" value="classificar"><input type="hidden" name="id" value="<?= (int)$l['id'] ?>"><input type="hidden" name="tipo_classificacao" value="parcela">
              <button type="submit" style="background:none;border:none;cursor:pointer;color:#c2410c" title="Classificar como parcela normal">🏷️ Parcela</button></form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$lancamentos): ?><tr><td colspan="7" style="text-align:center;color:var(--muted)">Nenhum lançamento no período.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>

</main>
<?php include __DIR__ . '/_pwa_register.php'; ?>
<?php include __DIR__ . '/_notify.php'; ?>
</body>
</html>
