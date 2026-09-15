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
    } elseif ($_SESSION['admin_perfil'] === 'supervisor') {
        http_response_code(403);
        $erro = 'Perfil de supervisão só acompanha, não edita cadastro de cliente.';
    } else {
        try {
            $db->prepare("
                UPDATE clientes SET nome = ?, cidade = ?, estado = ?, cpf = ?, email = ?, endereco = ? WHERE id = ?
            ")->execute([
                clean((string)($_POST['nome'] ?? '')),
                clean((string)($_POST['cidade'] ?? '')),
                clean((string)($_POST['estado'] ?? '')),
                clean((string)($_POST['cpf'] ?? '')),
                clean((string)($_POST['email'] ?? '')),
                clean((string)($_POST['endereco'] ?? '')),
                $id,
            ]);
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
<?php include __DIR__ . '/_pwa_head.php'; ?>
</head>
<body>
<header class="topbar">
    <a href="/admin/clientes.php" style="color:#fff">← Clientes</a>
    <strong><img class="topbar-logo" src="/admin/assets/img/icon-192.png" alt="Fastcar" onerror="this.style.display='none'"> Fast<b>Car</b> <span class="crm-tag">CRM</span></strong>
    <span>Olá, <?= e($_SESSION['admin_nome']) ?></span>
    <a href="/admin/logout.php">Sair</a>
</header>

<main>
<?php if ($erro): ?><div class="alerta-erro"><?= e($erro) ?></div><?php endif; ?>
<?php if ($sucesso): ?><div class="alerta-sucesso"><?= e($sucesso) ?></div><?php endif; ?>

<div class="card">
    <h2>
        <?= e($cliente['nome'] ?: '(sem nome)') ?>
        <?php if ($convertido): ?>
            <span class="badge badge-ok" title="Já teve pelo menos um veículo com negócio fechado">🏆 Cliente convertido</span>
        <?php endif; ?>
    </h2>
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
                <label>E-mail</label>
                <input type="email" name="email" value="<?= e($cliente['email'] ?? '') ?>">
                <small>Usado também pra ZapSign avisar por e-mail quando mandar o contrato pra assinatura.</small>
            </div>
            <div>
                <label>Cidade</label>
                <input type="text" name="cidade" value="<?= e($cliente['cidade']) ?>">
                <label>Estado</label>
                <input type="text" name="estado" value="<?= e($cliente['estado']) ?>">
                <label>Endereço completo</label>
                <input type="text" name="endereco" value="<?= e($cliente['endereco'] ?? '') ?>">
            </div>
        </div>
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
</body>
</html>
