<?php
/**
 * Detalhe de uma avaliação/vistoria do veículo — checklist, km, fotos,
 * geração/envio do termo de entrega assinado. Ver includes/veiculo_avaliacoes.php
 * pro desenho completo do módulo.
 *
 * Permissões (dentro do módulo, já filtrado por requireAcessoAvaliacoes()):
 * - EDITAR o checklist (itens/km/observações/concluir/reabrir/gerar termo/
 *   fotos): o avaliador ATRIBUÍDO a esta avaliação, ou super_admin. Nunca
 *   supervisor (mesma regra geral do projeto: supervisor só acompanha) nem
 *   outro avaliador que não seja o atribuído.
 * - ATRIBUIR avaliador: super_admin/supervisor sempre, ou o responsável do
 *   próprio negócio (consultor na compra, vendedor na venda) — confirmado
 *   com o usuário.
 * - APROVAR foto pro catálogo de vendas: super_admin ou vendedor (é uma
 *   decisão de vendas, não de vistoria em si) — "as fotos que colher tem
 *   que vendedor aprovar para ia usar".
 */

require_once __DIR__ . '/_bootstrap.php';
requireAcessoAvaliacoes();

$perfil = $_SESSION['admin_perfil'];
$meuId = (int)$_SESSION['admin_id'];
$id = (int)($_GET['id'] ?? 0);

$av = buscarAvaliacao($id);
if (!$av) {
    http_response_code(404);
    exit('Avaliação não encontrada.');
}

$souAvaliadorAtribuido = $perfil === 'avaliador' && (int)$av['avaliador_id'] === $meuId;
$podeEditarChecklist = $perfil === 'super_admin' || $souAvaliadorAtribuido;

$souResponsavelDoNegocio = ($av['tipo'] === 'compra' && $perfil === 'consultor' && (int)$av['oportunidade_responsavel_id'] === $meuId)
    || ($av['tipo'] === 'venda' && $perfil === 'vendedor' && (int)$av['venda_responsavel_id'] === $meuId);
$podeAtribuir = perfilVeTudo() || $souResponsavelDoNegocio;

$podeAprovarFoto = $perfil === 'super_admin' || $perfil === 'vendedor';

$erro = '';
$sucesso = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        $erro = 'Sessão expirada, recarregue a página e tente de novo.';
    } else {
        $acao = (string)($_POST['acao'] ?? '');

        if ($acao === 'atribuir' && $podeAtribuir) {
            $novoAvaliadorId = (int)($_POST['avaliador_id'] ?? 0) ?: null;
            atribuirAvaliador($id, $novoAvaliadorId);
            $sucesso = $novoAvaliadorId ? 'Avaliador atribuído.' : 'Avaliador removido — vistoria voltou pra fila.';
        } elseif ($acao === 'atualizar_item' && $podeEditarChecklist) {
            atualizarItemAvaliacao($id, (string)($_POST['item'] ?? ''), (string)($_POST['status'] ?? ''), (string)($_POST['observacao'] ?? ''));
            $sucesso = 'Item atualizado.';
        } elseif ($acao === 'salvar_km' && $podeEditarChecklist) {
            $km = trim((string)($_POST['km_atual'] ?? ''));
            atualizarKmAvaliacao($id, $km === '' ? null : (int)preg_replace('/\D/', '', $km));
            $sucesso = 'Quilometragem salva.';
        } elseif ($acao === 'salvar_observacoes' && $podeEditarChecklist) {
            atualizarObservacoesGeraisAvaliacao($id, (string)($_POST['observacoes_gerais'] ?? ''));
            $sucesso = 'Observações salvas.';
        } elseif ($acao === 'concluir' && $podeEditarChecklist) {
            concluirAvaliacao($id);
            $sucesso = 'Vistoria marcada como concluída.';
        } elseif ($acao === 'reabrir' && $podeEditarChecklist) {
            reabrirAvaliacao($id);
            $sucesso = 'Vistoria reaberta pra edição.';
        } elseif ($acao === 'upload_foto' && $podeEditarChecklist) {
            $resultado = salvarFotoAvaliacao($id, $_FILES['midia'] ?? [], (string)($_POST['legenda'] ?? ''));
            if ($resultado['ok']) { $sucesso = 'Foto/vídeo adicionado à vistoria.'; } else { $erro = $resultado['erro']; }
        } elseif ($acao === 'excluir_foto' && $podeEditarChecklist) {
            excluirFotoAvaliacao((int)($_POST['foto_id'] ?? 0));
            $sucesso = 'Mídia removida da vistoria.';
        } elseif ($acao === 'aprovar_foto' && $podeAprovarFoto) {
            $resultado = aprovarFotoParaCatalogo((int)($_POST['foto_id'] ?? 0), $meuId);
            if ($resultado['ok']) { $sucesso = 'Foto aprovada — já disponível no catálogo de vendas.'; } else { $erro = $resultado['erro']; }
        } elseif ($acao === 'gerar_termo' && $podeEditarChecklist) {
            $resultado = gerarEEnviarTermoAvaliacao($id);
            if ($resultado['ok']) { $sucesso = 'Termo gerado e enviado pra assinatura.'; } else { $erro = $resultado['erro']; }
        } else {
            $erro = 'Ação não permitida.';
        }

        $av = buscarAvaliacao($id); // recarrega após qualquer mudança
    }
}

$itens = listarItensAvaliacao($id);
$fotos = listarFotosAvaliacao($id);
$avaliadores = array_values(array_filter(listarUsuarios(), fn($u) => $u['perfil'] === 'avaliador'));

function avStatusLabel(string $status): string {
    return match ($status) {
        'concluida'    => '✅ Concluída',
        'em_andamento' => '🔧 Em andamento',
        default        => '⏳ Pendente',
    };
}
function avItemStatusLabel(string $status): string {
    return match ($status) {
        'ok'       => '✅ OK',
        'problema' => '⚠️ Problema',
        default    => '❔ Não verificado',
    };
}
function avTermoStatusLabel(string $status): string {
    return match ($status) {
        'enviado'  => '📨 Enviado — aguardando assinatura',
        'assinado' => '✅ Assinado',
        'recusado' => '❌ Recusado',
        'gerado'   => '📄 Gerado (ainda não enviado)',
        default    => '',
    };
}
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Vistoria #<?= (int)$av['id'] ?> — Fastcar CRM</title>
<link rel="stylesheet" href="/admin/assets/style.css?v=<?= @filemtime(__DIR__ . '/assets/style.css') ?: 1 ?>">
<?php include __DIR__ . '/_pwa_head.php'; ?>
</head>
<body>
<header class="topbar">
    <a href="/admin/avaliacoes.php" style="color:#fff">← Vistorias</a>
    <strong><img class="topbar-logo" src="/admin/assets/img/icon-192.png" alt="Fastcar" onerror="this.style.display='none'"> Fast<b>Car</b></strong>
    <span>Olá, <?= e($_SESSION['admin_nome']) ?></span>
    <a href="/admin/logout.php">Sair</a>
</header>
<main>
<?php if ($erro): ?><div class="alerta-erro"><?= e($erro) ?></div><?php endif; ?>
<?php if ($sucesso): ?><div class="alerta-sucesso"><?= e($sucesso) ?></div><?php endif; ?>

<div class="card">
    <h2>#<?= (int)$av['id'] ?> — <?= e(trim($av['veiculo_marca'] . ' ' . $av['veiculo_modelo'])) ?: '—' ?> <?= e((string)($av['veiculo_ano'] ?? '')) ?></h2>
    <p><strong>Placa:</strong> <?= e($av['veiculo_placa'] ?: '—') ?></p>

    <?php
        $nomeParte = $av['tipo'] === 'venda' ? ($av['comprador_nome'] ?: '—') : $av['cliente_nome'];
        $papelParte = $av['tipo'] === 'venda' ? 'Comprador (vai receber o carro)' : 'Vendedor original (vai entregar o carro)';
    ?>
    <div class="av-tipo-banner tipo-<?= $av['tipo'] ?>">
        <span class="av-tipo-titulo"><?= $av['tipo'] === 'venda' ? '🛒 VISTORIA DE VENDA — entrega ao comprador' : '🚗 VISTORIA DE COMPRA — recebimento do vendedor' ?></span>
        &nbsp;&nbsp;<span class="badge badge-info"><?= $av['tipo_veiculo'] === 'moto' ? '🏍️ MOTO' : '🚗 CARRO' ?></span><br>
        <?= e($papelParte) ?>: <strong><?= e($nomeParte) ?></strong><br>
        <?php if ($av['tipo'] === 'venda'): ?>
            📄 O termo de entrega vai ser assinado por: <strong><?= e($nomeParte) ?></strong>
        <?php else: ?>
            📋 Registro interno — sem assinatura, só pra documentar como o veículo entrou.
        <?php endif; ?>
    </div>

    <p><strong>Status:</strong> <?= avStatusLabel($av['status']) ?>
        <?php if ($av['concluida_em']): ?><small>— concluída em <?= date('d/m/Y H:i', strtotime($av['concluida_em'])) ?></small><?php endif; ?></p>
</div>

<div class="card">
    <h3>Avaliador responsável</h3>
    <?php if ($podeAtribuir): ?>
        <form method="post">
            <?= csrfField() ?>
            <input type="hidden" name="acao" value="atribuir">
            <select name="avaliador_id">
                <option value="">— não atribuído —</option>
                <?php foreach ($avaliadores as $u): ?>
                    <option value="<?= (int)$u['id'] ?>" <?= (int)$av['avaliador_id'] === (int)$u['id'] ? 'selected' : '' ?>><?= e($u['nome']) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit">Salvar</button>
        </form>
        <?php if (!$avaliadores): ?><p><small>Nenhum usuário com perfil "avaliador" cadastrado ainda — crie um em Usuários.</small></p><?php endif; ?>
    <?php else: ?>
        <p><?= $av['avaliador_nome'] ? e($av['avaliador_nome']) : '<em>não atribuído</em>' ?></p>
    <?php endif; ?>
</div>

<?php if (!$podeEditarChecklist): ?>
    <div class="card"><p><small>Você pode acompanhar esta vistoria, mas só o avaliador atribuído (ou o super_admin) preenche o checklist.</small></p></div>
<?php endif; ?>

<div class="card">
    <h3>📋 Checklist de vistoria</h3>
    <?php foreach ($itens as $item):
        $obsAtual = $item['observacao'] ?? '';
    ?>
        <div class="av-item">
            <div class="av-item-nome"><?= e(veiculoAvaliacaoRotuloItem($item['item'])) ?></div>
            <?php if ($podeEditarChecklist): ?>
                <div class="av-status-btns">
                    <?php foreach (['ok' => '✅ OK', 'problema' => '⚠️ Problema', 'nao_verificado' => '❔ Não verificado'] as $statusOpcao => $rotulo): ?>
                        <form method="post" style="display:contents">
                            <?= csrfField() ?>
                            <input type="hidden" name="acao" value="atualizar_item">
                            <input type="hidden" name="item" value="<?= e($item['item']) ?>">
                            <input type="hidden" name="status" value="<?= $statusOpcao ?>">
                            <input type="hidden" name="observacao" value="<?= e($obsAtual) ?>">
                            <button type="submit" class="av-btn-status <?= $item['status'] === $statusOpcao ? 'ativo-' . $statusOpcao : '' ?>"><?= $rotulo ?></button>
                        </form>
                    <?php endforeach; ?>
                </div>
                <?php if ($obsAtual !== ''): ?><div class="av-item-obs-existente"><?= nl2br(e($obsAtual)) ?></div><?php endif; ?>
                <form method="post" class="av-item-obs-form">
                    <?= csrfField() ?>
                    <input type="hidden" name="acao" value="atualizar_item">
                    <input type="hidden" name="item" value="<?= e($item['item']) ?>">
                    <input type="hidden" name="status" value="<?= e($item['status']) ?>">
                    <input type="text" name="observacao" value="<?= e($obsAtual) ?>" placeholder="Observação (opcional) — ex: amassado leve na porta traseira">
                    <button type="submit">Salvar observação</button>
                </form>
            <?php else: ?>
                <p>— <?= avItemStatusLabel($item['status']) ?></p>
                <?php if ($obsAtual !== ''): ?><div class="av-item-obs-existente"><?= nl2br(e($obsAtual)) ?></div><?php endif; ?>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>

    <?php if ($podeEditarChecklist): ?>
        <div class="grid-2" style="margin-top:14px">
            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="acao" value="salvar_km">
                <label>Quilometragem atual</label>
                <input type="text" inputmode="numeric" name="km_atual" value="<?= e((string)($av['km_atual'] ?? '')) ?>" placeholder="Ex: 45000">
                <button type="submit">Salvar KM</button>
            </form>
            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="acao" value="salvar_observacoes">
                <label>Observações gerais</label>
                <textarea name="observacoes_gerais" rows="3"><?= e($av['observacoes_gerais'] ?? '') ?></textarea>
                <button type="submit">Salvar observações</button>
            </form>
        </div>

        <div style="margin-top:14px">
            <?php if ($av['status'] === 'concluida'): ?>
                <form method="post" style="display:inline"><?= csrfField() ?><input type="hidden" name="acao" value="reabrir"><button type="submit" style="min-height:48px;font-size:15px">↩️ Reabrir vistoria</button></form>
            <?php else: ?>
                <form method="post" style="display:inline"><?= csrfField() ?><input type="hidden" name="acao" value="concluir"><button type="submit" style="min-height:48px;font-size:15px">✅ Marcar vistoria como concluída</button></form>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<div class="card">
    <h3>📸 Fotos e vídeos da vistoria</h3>
    <p><small>Galeria própria da vistoria — uma foto só entra no catálogo de vendas (o que a IA usa pra mandar mídia
       pro comprador) depois de aprovada explicitamente abaixo.</small></p>
    <?php if ($fotos): ?>
        <div class="av-foto-grid">
            <?php foreach ($fotos as $f): ?>
                <div>
                    <?php if ($f['tipo'] === 'foto'): ?>
                        <a href="/admin/ver_avaliacao_foto.php?id=<?= (int)$f['id'] ?>" target="_blank">
                            <img src="/admin/ver_avaliacao_foto.php?id=<?= (int)$f['id'] ?>" loading="lazy">
                        </a>
                    <?php else: ?>
                        <video src="/admin/ver_avaliacao_foto.php?id=<?= (int)$f['id'] ?>" controls preload="metadata"></video>
                    <?php endif; ?>
                    <?php if ($f['legenda']): ?><small><?= e($f['legenda']) ?></small><?php endif; ?>
                    <div style="margin-top:4px">
                        <?php if ((int)$f['aprovado_para_catalogo'] === 1): ?>
                            <span class="badge badge-ok">✅ no catálogo de vendas</span>
                        <?php elseif ($podeAprovarFoto): ?>
                            <form method="post">
                                <?= csrfField() ?>
                                <input type="hidden" name="acao" value="aprovar_foto">
                                <input type="hidden" name="foto_id" value="<?= (int)$f['id'] ?>">
                                <button type="submit">✅ Aprovar pro catálogo</button>
                            </form>
                        <?php else: ?>
                            <span class="badge badge-aviso">aguardando aprovação do vendedor</span>
                        <?php endif; ?>
                        <?php if ($podeEditarChecklist): ?>
                            <form method="post" onsubmit="return confirm('Remover essa mídia da vistoria?');">
                                <?= csrfField() ?>
                                <input type="hidden" name="acao" value="excluir_foto">
                                <input type="hidden" name="foto_id" value="<?= (int)$f['id'] ?>">
                                <button type="submit" class="perigo">🗑️ Remover</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <p><small>Nenhuma foto/vídeo adicionado ainda.</small></p>
    <?php endif; ?>
    <?php if ($podeEditarChecklist): ?>
        <form method="post" enctype="multipart/form-data">
            <?= csrfField() ?>
            <input type="hidden" name="acao" value="upload_foto">
            <label>Foto ou vídeo (JPG/PNG/WEBP até 10MB, ou vídeo MP4/MOV/WEBM até 50MB)</label>
            <input type="file" name="midia" accept="image/jpeg,image/png,image/webp,video/mp4,video/quicktime,video/webm" capture="environment" required>
            <small>No tablet, isso já abre a opção de tirar a foto na hora com a câmera, ou escolher um arquivo já salvo.</small>
            <label>Legenda (opcional)</label>
            <input type="text" name="legenda" placeholder="Ex: Avaria no para-choque traseiro">
            <button type="submit" style="min-height:48px">📷 Adicionar</button>
        </form>
    <?php endif; ?>
</div>

<?php if ($av['tipo'] === 'venda'): ?>
<div class="card">
    <h3>📄 Termo de entrega e vistoria</h3>
    <?php if ($av['termo_status']): ?>
        <p><?= avTermoStatusLabel($av['termo_status']) ?>
            <?php if ($av['termo_assinado_em']): ?><small>— <?= date('d/m/Y H:i', strtotime($av['termo_assinado_em'])) ?></small><?php endif; ?>
        </p>
        <?php if ($av['drive_file_id'] || $av['arquivo_url']): ?>
            <p><a href="/admin/ver_avaliacao_termo.php?id=<?= (int)$av['id'] ?>" target="_blank">👁️ Ver PDF</a></p>
        <?php endif; ?>
    <?php else: ?>
        <p><small>Nenhum termo gerado ainda.</small></p>
    <?php endif; ?>
    <?php if ($podeEditarChecklist): ?>
        <form method="post" onsubmit="return confirm('Gerar o termo e enviar pra assinatura eletrônica agora?');">
            <?= csrfField() ?>
            <input type="hidden" name="acao" value="gerar_termo">
            <button type="submit" style="min-height:48px;font-size:15px">📤 Gerar e enviar pra assinatura</button>
        </form>
        <p><small>Assina o comprador (dados da negociação).</small></p>
    <?php endif; ?>
</div>
<?php else: ?>
<div class="card">
    <p><small>📋 Esta vistoria de <strong>compra</strong> é um registro interno — documenta a situação de como o
       veículo entrou, sem precisar de assinatura de ninguém. O vendedor já assina o contrato de compra
       principal separadamente.</small></p>
</div>
<?php endif; ?>
</main>
<?php include __DIR__ . '/_pwa_register.php'; ?>
<?php include __DIR__ . '/_notify.php'; ?>
</body>
</html>
