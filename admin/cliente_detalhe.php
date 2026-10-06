<?php
/**
 * Detalhe do cliente — dados pessoais + histórico de TODAS as
 * oportunidades dele (pode ter mais de um veículo negociado ao longo do
 * tempo, regra do Jean). Edição aqui é só dado cadastral; mudança de
 * etapa/negociação continua em admin/oportunidade.php.
 */

require_once __DIR__ . '/_bootstrap.php';

$id = (int)($_GET['id'] ?? 0);
$db = getDB();

$stmt = $db->prepare("SELECT * FROM clientes WHERE id = ?");
$stmt->execute([$id]);
$cliente = $stmt->fetch();

if (!$cliente) {
    http_response_code(404);
    exit('Cliente não encontrado.');
}

$erro = '';
$sucesso = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        $erro = 'Sessão expirada, recarregue a página e tente de novo.';
    } elseif (in_array($_SESSION['admin_perfil'], ['supervisor', 'financeiro'], true)) {
        // 18/09/2026, "financeiro tem ter acesso ao clientes" — liberado só
        // pra CONSULTAR (ex: conferir contato de quem está com conta
        // atrasada), mesma trava de só-acompanha do supervisor: financeiro
        // nunca edita cadastro de cliente por aqui, só pelo funil de
        // compra/vendas isso faz sentido.
        http_response_code(403);
        $erro = 'Este perfil só consulta o cadastro de cliente, não edita.';
    } elseif (($_POST['acao'] ?? 'salvar') === 'atualizar_foto_whatsapp') {
        // 16/09/2026, "puxa foto do zap e nome" — cliente já cadastrado
        // antes dessa função existir (ou cuja busca automática não achou
        // nada na hora) não tem como reaproveitar a criação automática do
        // cliente pra tentar de novo; botão manual cobre esse backfill.
        $contato = zapiBuscarContato($cliente['telefone']);
        if ($contato && ($contato['nome'] || $contato['foto_url'])) {
            // fill-if-empty pro nome — mas um nome já salvo que na verdade é
            // texto de status/presença do WhatsApp (achado real, 16/09/2026:
            // "online"/"disponível" salvos como nome) conta como "vazio" pra
            // esse fim, autocorrigindo com 1 clique nesse botão manual.
            $nomeAtualValido = $cliente['nome'] !== '' && nomeWhatsappPareceValido((string)$cliente['nome']);
            $podeAtualizarNome = !$nomeAtualValido && $contato['nome'] !== '';
            $novoNome = $podeAtualizarNome ? clean($contato['nome']) : null;
            $db->prepare("
                UPDATE clientes
                SET foto_perfil_url = COALESCE(NULLIF(?, ''), foto_perfil_url),
                    nome = COALESCE(?, nome)
                WHERE id = ?
            ")->execute([$contato['foto_url'], $novoNome, $id]);
            $sucesso = 'Nome/foto do WhatsApp atualizados.';
        } else {
            $erro = 'Não foi possível buscar nome/foto agora — confira se a Z-API está configurada e conectada.';
        }
        $stmt->execute([$id]);
        $cliente = $stmt->fetch();
    } else {
        try {
            $novoCpf = clean((string)($_POST['cpf'] ?? ''));
            $novoEmail = clean((string)($_POST['email'] ?? ''));
            $novoEndereco = clean((string)($_POST['endereco'] ?? ''));
            // 30/09/2026, "consultores precisa editar manual a documentação
            // do cliente ia preencheu faltando numero" — RG/CNH/nacionalidade/
            // estado civil/profissão já existiam no schema desde a 1ª etapa do
            // wizard público (public/documentos.php), mas até aqui só o
            // CLIENTE conseguia preencher/corrigir esses campos por lá — sem
            // nenhum jeito de o consultor editar manualmente pelo admin quando
            // a extração por IA vinha incompleta (ex: CNH sem número legível
            // na foto) e o cliente não dava pra reabrir o link. Mesmo padrão
            // fill-livre dos campos já existentes acima, nunca fill-if-empty —
            // é edição manual direta, sobrescreve sempre.
            $novoRg = clean((string)($_POST['rg'] ?? ''));
            $novoCnh = clean((string)($_POST['cnh'] ?? ''));
            // 06/10/2026, "preciso remover esses campos ja se repete" —
            // "Cidade"/"Estado" saíram do FORMULÁRIO (redundante na prática:
            // "Endereço completo" já traz cidade/UF embutidos no texto digitado/
            // confirmado pelo cliente) — nunca tiradas do UPDATE `clientes.*`
            // nem da coluna no schema (nunca apaga dado já existente, mesma
            // disciplina do "7 campos removidos" do card de venda em
            // 06/10/2026): a coluna continua recebendo preenchimento
            // automático via IA de qualificação (fill-if-empty,
            // includes/ia_qualificacao.php) e seguindo exibida (read-only,
            // "Cidade/UF") em admin/oportunidade.php — só parou de ser
            // editável manualmente por aqui.
            $db->prepare("
                UPDATE clientes SET nome = ?, cpf = ?, email = ?, endereco = ?,
                    rg = ?, cnh = ?, nacionalidade = ?, estado_civil = ?, profissao = ? WHERE id = ?
            ")->execute([
                clean((string)($_POST['nome'] ?? '')),
                $novoCpf,
                $novoEmail,
                $novoEndereco,
                $novoRg,
                $novoCnh,
                clean((string)($_POST['nacionalidade'] ?? '')),
                clean((string)($_POST['estado_civil'] ?? '')),
                clean((string)($_POST['profissao'] ?? '')),
                $id,
            ]);
            // 20/09/2026, "modulo auditoria" — só CPF/e-mail/endereço contam
            // como "dado sensível" pra esse evento (nome/cidade/estado ficam
            // de fora, mudam com frequência maior e são bem menos sensíveis);
            // nunca grava o valor antigo/novo no log, só QUAIS campos
            // mudaram — o CPF em si não precisa virar um 2º lugar de dado
            // sensível em repouso. RG/CNH entraram na mesma lista (30/09/2026,
            // mesma disciplina — também documento de identidade).
            $camposMudaram = [];
            if ((string)($cliente['cpf'] ?? '') !== $novoCpf) $camposMudaram[] = 'CPF';
            if ((string)($cliente['email'] ?? '') !== $novoEmail) $camposMudaram[] = 'e-mail';
            if ((string)($cliente['endereco'] ?? '') !== $novoEndereco) $camposMudaram[] = 'endereço';
            if ((string)($cliente['rg'] ?? '') !== $novoRg) $camposMudaram[] = 'RG';
            if ((string)($cliente['cnh'] ?? '') !== $novoCnh) $camposMudaram[] = 'CNH';
            if ($camposMudaram) {
                auditoriaRegistrar('cliente_dado_editado', (int)$_SESSION['admin_id'], (string)$_SESSION['admin_nome'], 'cliente', $id, 'Campo(s) alterado(s): ' . implode(', ', $camposMudaram) . '.');
            }
            $sucesso = 'Dados do cliente atualizados.';
        } catch (Throwable $e) {
            $erro = $e->getMessage();
        }
        $stmt->execute([$id]);
        $cliente = $stmt->fetch();
    }
}

// LEFT JOIN contratos pra trazer a data real de assinatura junto — pedido
// explícito ("ideal nos clientes ter qual veiculo comprado que dia ele
// assina contrato"). Uma oportunidade pode nunca ter tido contrato gerado
// ainda (LEFT JOIN cobre isso, coluna vem NULL) e, em tese, mais de um
// registro em contratos por oportunidade (regerar depois de recusa) — pega
// sempre o mais recente via MAX(c.id).
$stmtOps = $db->prepare("
    SELECT o.*, c.status AS contrato_status, c.assinado_em AS contrato_assinado_em
    FROM oportunidades o
    LEFT JOIN contratos c ON c.id = (
        SELECT id FROM contratos WHERE oportunidade_id = o.id ORDER BY id DESC LIMIT 1
    )
    WHERE o.cliente_id = ?
    ORDER BY o.id DESC
");
$stmtOps->execute([$id]);
$oportunidades = $stmtOps->fetchAll();

// Convertido = já teve pelo menos 1 veículo com negócio fechado (etapa='fechado').
// Cliente continua o mesmo cadastro mesmo convertido — pode abrir nova oportunidade
// com outro veículo depois (regra do Jean: 1 cadastro por telefone, N oportunidades).
$convertido = (bool)array_filter($oportunidades, fn($op) => $op['etapa'] === 'fechado');
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($cliente['nome'] ?: $cliente['telefone']) ?> — Fastcar CRM</title>
<link rel="stylesheet" href="/admin/assets/style.css?v=<?= @filemtime(__DIR__ . '/assets/style.css') ?: 1 ?>">
<link rel="stylesheet" href="/admin/assets/mobile.css?v=<?= @filemtime(__DIR__ . '/assets/mobile.css') ?: 1 ?>">
<script src="/admin/assets/mobile.js?v=<?= @filemtime(__DIR__ . '/assets/mobile.js') ?: 1 ?>" defer></script>
<?php include __DIR__ . '/_pwa_head.php'; ?>
</head>
<body>
<?php include __DIR__ . '/_impersonando_banner.php'; ?>
<header class="topbar">
    <a href="/admin/clientes.php" style="color:#fff">← Clientes</a>
    <a class="topbar-brand" href="<?= e(paginaInicialPorPerfil($_SESSION['admin_perfil'] ?? '')) ?>"><img class="topbar-logo" src="/admin/assets/img/icon-192.png" alt="Fastcar" onerror="this.style.display='none'"><span class="topbar-wordmark">Fast<b>Car</b></span></a>
    <span>Olá, <?= e($_SESSION['admin_nome']) ?></span>
    <a href="/admin/logout.php">Sair</a>
</header>

<main>
<?php if ($erro): ?><div class="alerta-erro"><?= e($erro) ?></div><?php endif; ?>
<?php if ($sucesso): ?><div class="alerta-sucesso"><?= e($sucesso) ?></div><?php endif; ?>

<div class="card">
    <h2 style="display:flex;align-items:center;gap:10px">
        <?php if (!empty($cliente['foto_perfil_url'])): ?>
            <img src="<?= e($cliente['foto_perfil_url']) ?>" alt="" style="width:36px;height:36px;border-radius:50%;object-fit:cover" onerror="this.remove()">
        <?php endif; ?>
        <?= e($cliente['nome'] ?: '(sem nome)') ?>
        <?php if ($convertido): ?>
            <span class="badge badge-ok" title="Já teve pelo menos um veículo com negócio fechado">🏆 Cliente convertido</span>
        <?php endif; ?>
    </h2>
    <form method="post" class="inline" style="margin-bottom:10px">
        <?= csrfField() ?>
        <input type="hidden" name="acao" value="atualizar_foto_whatsapp">
        <button type="submit" style="margin-top:0;padding:5px 12px;font-size:13px">🔄 Atualizar nome/foto do WhatsApp</button>
    </form>
    <form method="post">
        <?= csrfField() ?>
        <div class="grid-2">
            <div>
                <label>Nome completo</label>
                <input type="text" name="nome" value="<?= e($cliente['nome']) ?>">
                <label>Telefone (WhatsApp)</label>
                <input type="text" value="<?= e($cliente['telefone']) ?>" disabled>
                <small>Telefone é a chave de identificação — não editável por aqui.</small>
                <label>CPF</label>
                <input type="text" name="cpf" value="<?= e($cliente['cpf'] ?? '') ?>">
                <label>RG</label>
                <input type="text" name="rg" value="<?= e($cliente['rg'] ?? '') ?>">
                <label>Nº da CNH (se tiver)</label>
                <input type="text" name="cnh" value="<?= e($cliente['cnh'] ?? '') ?>">
                <label>E-mail</label>
                <input type="email" name="email" value="<?= e($cliente['email'] ?? '') ?>">
                <small>Usado também pra ZapSign avisar por e-mail quando mandar o contrato pra assinatura.</small>
            </div>
            <div>
                <label>Endereço completo</label>
                <input type="text" name="endereco" value="<?= e($cliente['endereco'] ?? '') ?>">
                <label>Nacionalidade</label>
                <input type="text" name="nacionalidade" value="<?= e($cliente['nacionalidade'] ?: 'Brasileiro(a)') ?>">
                <label>Estado civil</label>
                <input type="text" name="estado_civil" value="<?= e($cliente['estado_civil'] ?? '') ?>" placeholder="Solteiro(a), casado(a)...">
                <label>Profissão</label>
                <input type="text" name="profissao" value="<?= e($cliente['profissao'] ?? '') ?>">
            </div>
        </div>
        <small>Esses campos também aparecem pro cliente preencher no wizard de documentos (link de CNH/RG) — editar aqui sobrescreve o que ele já confirmou, útil quando a IA leu algo incompleto/errado da foto e o consultor precisa corrigir direto.</small>
        <p><small>Origem: <?= e($cliente['canal_origem'] ?: 'direto') ?>
           <?= $cliente['campanha_origem'] ? ' · ' . e($cliente['campanha_origem']) : '' ?></small></p>
        <button type="submit">Salvar</button>
    </form>
</div>

<div class="card">
    <h3>Oportunidades (<?= count($oportunidades) ?>)</h3>
    <table class="tabela-oportunidades">
        <thead><tr><th>#</th><th>Veículo</th><th>Placa</th><th>Etapa</th><th>Criada em</th><th>Contrato assinado em</th><th></th></tr></thead>
        <tbody>
        <?php if (!$oportunidades): ?>
            <tr><td colspan="7">Nenhuma oportunidade ainda.</td></tr>
        <?php endif; ?>
        <?php foreach ($oportunidades as $op): ?>
            <tr>
                <td>#<?= (int)$op['id'] ?></td>
                <td><?= e(trim($op['veiculo_marca'] . ' ' . $op['veiculo_modelo'])) ?: '—' ?> <?= e($op['veiculo_ano']) ?></td>
                <td><?= e($op['veiculo_placa'] ?: '—') ?></td>
                <td><?= e(etapaLabel($op['etapa'])) ?></td>
                <td><?= date('d/m/Y', strtotime($op['created_at'])) ?></td>
                <td>
                    <?php if ($op['contrato_assinado_em']): ?>
                        <span class="badge badge-ok">✅ <?= date('d/m/Y', strtotime($op['contrato_assinado_em'])) ?></span>
                    <?php elseif ($op['contrato_status']): ?>
                        <span class="badge badge-aviso"><?= e(ucfirst($op['contrato_status'])) ?></span>
                    <?php else: ?>
                        <span class="badge">— sem contrato ainda</span>
                    <?php endif; ?>
                </td>
                <td><a href="/admin/oportunidade.php?id=<?= (int)$op['id'] ?>">Abrir →</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
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
