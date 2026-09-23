<?php
/**
 * Importar contratos da ZapSign (CRM antigo) — 19/09/2026, "zapasine tem
 * monte contrato do crm anti será possivel puxar concliar" → "pela api" →
 * "Listar + tentar vincular automaticamente... - esses clientes não está
 * no sistema" → "teria importar cadastrar todas infomaçoes". Puxa TODOS os
 * documentos já existentes na conta ZapSign (mesma conta usada por este
 * sistema pra assinatura eletrônica, `config.zapsign_api_token`), separa
 * em 3 grupos e deixa a decisão final sempre com o super_admin — nada é
 * criado sozinho (regra #3, mesmo espírito do cadastro manual de veículo):
 *   1. Já importado (contratos.zapsign_doc_token já existe) — só contagem.
 *   2. Telefone do signatário bate com cliente já cadastrado — mostra o
 *      cliente encontrado, mas não vincula sozinho (o cliente pode ter
 *      mais de 1 veículo — não dá pra saber qual oportunidade é a certa só
 *      pelo telefone); tela oferece o link pra abrir o cliente e anexar
 *      manualmente por lá.
 *   3. Sem match — formulário de importação, com 2 caminhos possíveis por
 *      documento (o admin decide qual, a ZapSign nunca diz sozinha — ver
 *      nota grande em includes/zapsign_importar.php, "como vai saber se
 *      contrato venda ou compra", 19/09/2026, confirmado que a conta tem
 *      os dois tipos misturados):
 *        a) Importar como COMPRA — nome/telefone pré-preenchidos da
 *           ZapSign, marca/modelo/placa/valor digitados na hora (mesmos
 *           campos de `admin/veiculos.php` → "Adicionar veículo
 *           manualmente"), vira veículo novo na frota.
 *        b) Importar como VENDA (revenda) — vincula a um veículo JÁ na
 *           frota (select com `listarFrotaDisponivelParaVenda()`), nome/
 *           telefone do comprador pré-preenchidos, preço digitado — nunca
 *           gera lançamento financeiro sozinho (venda histórica, não de
 *           hoje).
 *
 * Restrito ao super_admin, mesma trava de admin/veiculos.php.
 */
require_once __DIR__ . '/_bootstrap.php';
requireSuperAdmin();

$db = getDB();
$erro = '';
$sucesso = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'importar') {
    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        $erro = 'Sessão expirada, recarregue a página e tente de novo.';
    } else {
        $valorPost = trim((string)($_POST['valor_final'] ?? ''));
        $r = zapsignImportarContratoComoVeiculoManual(
            (string)($_POST['doc_token'] ?? ''),
            (string)($_POST['vendedor_nome'] ?? ''),
            (string)($_POST['vendedor_telefone'] ?? ''),
            (string)($_POST['veiculo_marca'] ?? ''),
            (string)($_POST['veiculo_modelo'] ?? ''),
            (string)($_POST['veiculo_ano'] ?? ''),
            (string)($_POST['veiculo_placa'] ?? ''),
            (string)($_POST['veiculo_chassi'] ?? ''),
            (string)($_POST['veiculo_renavam'] ?? ''),
            $valorPost !== '' ? (float)str_replace(',', '.', preg_replace('/[^\d,.-]/', '', $valorPost)) : null,
            (string)($_POST['data_assinatura'] ?? ''),
            (int)$_SESSION['admin_id']
        );
        if ($r['ok']) {
            header('Location: /admin/oportunidade.php?id=' . $r['oportunidade_id'] . '&importado_zapsign=1');
            exit;
        }
        $erro = $r['erro'];
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'importar_venda') {
    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        $erro = 'Sessão expirada, recarregue a página e tente de novo.';
    } else {
        $precoPost = trim((string)($_POST['preco_venda'] ?? ''));
        $r = zapsignImportarContratoVendaComoNegociacaoManual(
            (string)($_POST['doc_token'] ?? ''),
            (int)($_POST['oportunidade_id'] ?? 0),
            (string)($_POST['comprador_nome'] ?? ''),
            (string)($_POST['comprador_telefone'] ?? ''),
            $precoPost !== '' ? (float)str_replace(',', '.', preg_replace('/[^\d,.-]/', '', $precoPost)) : null,
            (string)($_POST['data_assinatura'] ?? ''),
            (int)$_SESSION['admin_id']
        );
        if ($r['ok']) {
            header('Location: /admin/venda.php?id=' . $r['venda_id'] . '&importado_zapsign=1');
            exit;
        }
        $erro = $r['erro'];
    }
}

$zapsignConfigurado = (bool)getConfig('zapsign_api_token');
$frotaDisponivelVenda = listarFrotaDisponivelParaVenda(100);
$documentos = [];
$erroListagem = '';
if ($zapsignConfigurado) {
    $resLista = zapsignListarTodosDocumentos();
    $documentos = $resLista['itens'];
    if (!$resLista['ok']) $erroListagem = $resLista['erro'];
}

$jaImportados = [];
$comMatch = [];
$semMatch = [];
foreach ($documentos as $doc) {
    $token = (string)($doc['token'] ?? '');
    if ($token === '') continue;

    $existe = $db->prepare('SELECT id FROM contratos WHERE zapsign_doc_token = ?');
    $existe->execute([$token]);
    if ($existe->fetchColumn()) {
        $jaImportados[] = $doc;
        continue;
    }

    $sig = zapsignExtrairSignatario($doc);
    $telNorm = $sig['telefone'] ? normalizarTelefone($sig['telefone']) : '';
    $clienteExistente = null;
    if ($telNorm) {
        $stmtCli = $db->prepare('SELECT id, nome FROM clientes WHERE telefone = ?');
        $stmtCli->execute([$telNorm]);
        $clienteExistente = $stmtCli->fetch() ?: null;
    }

    $item = ['doc' => $doc, 'sig' => $sig, 'telefone_normalizado' => $telNorm];
    if ($clienteExistente) {
        $item['cliente_existente'] = $clienteExistente;
        $comMatch[] = $item;
    } else {
        $semMatch[] = $item;
    }
}
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Importar contratos ZapSign — Fastcar CRM</title>
<link rel="stylesheet" href="/admin/assets/style.css?v=<?= @filemtime(__DIR__ . '/assets/style.css') ?: 1 ?>">
<?php include __DIR__ . '/_pwa_head.php'; ?>
</head>
<body>
<header class="topbar">
    <a href="/admin/configuracoes.php" style="color:#fff">← Voltar</a>
    <strong><img class="topbar-logo" src="/admin/assets/img/icon-192.png" alt="Fastcar" onerror="this.style.display='none'"> Fast<b>Car</b></strong>
    <span>Olá, <?= e($_SESSION['admin_nome']) ?></span>
    <a href="/admin/logout.php">Sair</a>
</header>

<main>
<?php if ($erro): ?><div class="alerta-erro"><?= e($erro) ?></div><?php endif; ?>
<?php if ($sucesso): ?><div class="alerta-sucesso"><?= e($sucesso) ?></div><?php endif; ?>

<div class="card">
    <h2>📥 Importar contratos da ZapSign (CRM antigo)</h2>
    <p><small>Puxa todos os documentos já existentes na conta ZapSign (mesma conta configurada em
       Configurações → ZapSign) e tenta casar cada um por telefone com um cliente já cadastrado aqui. Nada é criado
       automaticamente — cada importação é uma ação explícita, sempre revisada antes. A ZapSign nunca diz sozinha se
       um documento é contrato de COMPRA ou de VENDA (revenda) — não tem esse dado estruturado — então cada
       documento sem match mostra os 2 caminhos possíveis, e você escolhe o certo olhando o nome/signatário.</small></p>
</div>

<?php if (!$zapsignConfigurado): ?>
    <div class="alerta-info">ℹ️ Token da API ZapSign ainda não configurado — <a href="/admin/configuracoes.php">configure em Configurações → ZapSign</a> antes de importar.</div>
<?php else: ?>
    <?php if ($erroListagem): ?>
        <div class="alerta-erro">⚠️ <?= e($erroListagem) ?><?= $documentos ? ' (mostrando os ' . count($documentos) . ' documento(s) já obtidos antes da falha)' : '' ?></div>
    <?php endif; ?>

    <div class="grid-cards" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:1rem;margin-bottom:1.5rem">
        <div class="card"><div style="font-size:.8rem;color:var(--muted);font-weight:600">Total na ZapSign</div><div style="font-size:1.6rem;font-weight:800"><?= count($documentos) ?></div></div>
        <div class="card"><div style="font-size:.8rem;color:var(--muted);font-weight:600">✅ Já importados</div><div style="font-size:1.6rem;font-weight:800;color:#16a34a"><?= count($jaImportados) ?></div></div>
        <div class="card"><div style="font-size:.8rem;color:var(--muted);font-weight:600">🔍 Cliente já cadastrado</div><div style="font-size:1.6rem;font-weight:800;color:#2f6fed"><?= count($comMatch) ?></div></div>
        <div class="card"><div style="font-size:.8rem;color:var(--muted);font-weight:600">❓ Sem match — importar</div><div style="font-size:1.6rem;font-weight:800;color:#b45309"><?= count($semMatch) ?></div></div>
    </div>

    <?php if ($comMatch): ?>
    <div class="card" style="margin-bottom:1.5rem">
        <h3>🔍 Cliente já cadastrado — telefone bateu</h3>
        <p><small>Telefone do signatário na ZapSign bate com um cliente já existente aqui — como o mesmo cliente pode
           ter mais de 1 veículo, não vinculamos sozinho a nenhuma oportunidade específica. Abra o cliente e anexe o
           contrato manualmente na oportunidade certa (<code>admin/oportunidade.php</code> → card de documentos).</small></p>
        <table class="tabela-oportunidades">
            <thead><tr><th>Documento</th><th>Signatário (ZapSign)</th><th>Status</th><th>Cliente encontrado</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($comMatch as $item): ?>
                <tr>
                    <td><?= e($item['doc']['name'] ?? '(sem nome)') ?></td>
                    <td><?= e($item['sig']['nome'] ?: '—') ?><br><small><?= e($item['sig']['telefone'] ?: '—') ?></small></td>
                    <td><?= e($item['doc']['status'] ?? '—') ?></td>
                    <td><?= e($item['cliente_existente']['nome'] ?: '(sem nome)') ?></td>
                    <td><a href="/admin/cliente_detalhe.php?id=<?= (int)$item['cliente_existente']['id'] ?>">Abrir cliente →</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <?php if ($semMatch): ?>
    <div class="card">
        <h3>❓ Sem cliente correspondente — escolha compra ou venda</h3>
        <p><small>Abra o documento e escolha UM dos 2 caminhos — nunca os dois pro mesmo documento. Nome/telefone
           vêm pré-preenchidos da ZapSign nos dois formulários.</small></p>
        <?php foreach ($semMatch as $i => $item): $doc = $item['doc']; $sig = $item['sig']; ?>
        <details style="margin-bottom:.75rem;border:1px solid var(--border,#e2e8f0);border-radius:8px;padding:.5rem .75rem">
            <summary style="cursor:pointer;font-weight:600">
                <?= e($doc['name'] ?? '(sem nome)') ?>
                — <?= e($sig['nome'] ?: 'sem nome identificado') ?>
                <span style="color:var(--muted);font-weight:400"> · <?= e($doc['status'] ?? '—') ?></span>
            </summary>

            <h4 style="margin-top:1rem">🚗 Importar como COMPRA (veículo novo na frota)</h4>
            <form method="post" style="margin-top:.5rem">
                <?= csrfField() ?>
                <input type="hidden" name="acao" value="importar">
                <input type="hidden" name="doc_token" value="<?= e((string)($doc['token'] ?? '')) ?>">
                <input type="hidden" name="data_assinatura" value="<?= e((string)($doc['created_at'] ?? $doc['last_update_at'] ?? '')) ?>">
                <div class="grid-2">
                    <div>
                        <label>Nome do vendedor *</label>
                        <input type="text" name="vendedor_nome" value="<?= e($sig['nome']) ?>" required>
                        <label>Telefone do vendedor *</label>
                        <input type="text" name="vendedor_telefone" value="<?= e($sig['telefone']) ?>" required placeholder="Ex: 31999998888">
                        <label>Valor pago (R$)</label>
                        <input type="text" name="valor_final" placeholder="0,00">
                    </div>
                    <div>
                        <label>Marca</label>
                        <input type="text" name="veiculo_marca">
                        <label>Modelo</label>
                        <input type="text" name="veiculo_modelo">
                        <label>Ano</label>
                        <input type="text" name="veiculo_ano" style="max-width:120px">
                        <label>Placa</label>
                        <input type="text" name="veiculo_placa" style="max-width:160px">
                        <label>Chassi</label>
                        <input type="text" name="veiculo_chassi">
                        <label>RENAVAM</label>
                        <input type="text" name="veiculo_renavam">
                    </div>
                </div>
                <button type="submit">📥 Importar como compra</button>
            </form>

            <hr style="margin:1.25rem 0;border:none;border-top:1px dashed var(--border,#e2e8f0)">

            <h4>💰 Importar como VENDA (revenda de veículo já na frota)</h4>
            <?php if (!$frotaDisponivelVenda): ?>
                <p><small style="color:var(--muted)">Nenhum veículo disponível na frota ainda pra vincular — importe a compra
                   correspondente primeiro (aqui mesmo ou em Frota), depois volte pra importar esta venda.</small></p>
            <?php else: ?>
                <form method="post" style="margin-top:.5rem">
                    <?= csrfField() ?>
                    <input type="hidden" name="acao" value="importar_venda">
                    <input type="hidden" name="doc_token" value="<?= e((string)($doc['token'] ?? '')) ?>">
                    <input type="hidden" name="data_assinatura" value="<?= e((string)($doc['created_at'] ?? $doc['last_update_at'] ?? '')) ?>">
                    <label>Veículo da frota *</label>
                    <select name="oportunidade_id" required>
                        <option value="">— selecione —</option>
                        <?php foreach ($frotaDisponivelVenda as $v): ?>
                            <option value="<?= (int)$v['oportunidade_id'] ?>">
                                #<?= (int)$v['oportunidade_id'] ?> — <?= e(trim($v['veiculo_marca'] . ' ' . $v['veiculo_modelo'] . ' ' . $v['veiculo_ano'])) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <label>Nome do comprador *</label>
                    <input type="text" name="comprador_nome" value="<?= e($sig['nome']) ?>" required>
                    <label>Telefone do comprador *</label>
                    <input type="text" name="comprador_telefone" value="<?= e($sig['telefone']) ?>" required placeholder="Ex: 31999998888">
                    <label>Preço de venda (R$)</label>
                    <input type="text" name="preco_venda" placeholder="0,00">
                    <p><small style="color:var(--muted)">Não gera lançamento financeiro sozinho — é venda histórica, não de
                       hoje. Se quiser registrar a receita, faça manualmente em Financeiro → Lançamentos.</small></p>
                    <button type="submit">📥 Importar como venda</button>
                </form>
            <?php endif; ?>
        </details>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (!$documentos && !$erroListagem): ?>
        <div class="alerta-info">Nenhum documento encontrado na conta ZapSign.</div>
    <?php endif; ?>
<?php endif; ?>

</main>
<?php include __DIR__ . '/_pwa_register.php'; ?>
<?php include __DIR__ . '/_notify.php'; ?>
<?php include __DIR__ . '/_zapi_status.php'; ?>
</body>
</html>
