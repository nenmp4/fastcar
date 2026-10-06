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
 * - ATRIBUIR avaliador: super_admin/supervisor sempre; do lado da COMPRA,
 *   QUALQUER consultor (não só o responsável daquela oportunidade —
 *   23/09/2026, "permita qualquer consultor atribuir uma avaliação até
 *   super admin", confirmado direto que é só pro lado de compra); do lado
 *   da VENDA continua só o vendedor responsável daquela negociação
 *   específica (usuário confirmou manter como estava, "lado da compra").
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

// Compra: QUALQUER consultor atribui (não só o responsável daquela
// oportunidade). Venda: continua só o vendedor responsável daquela
// negociação específica — usuário confirmou manter como estava.
$souConsultorNaCompra = $av['tipo'] === 'compra' && $perfil === 'consultor';
$souResponsavelDaVenda = $av['tipo'] === 'venda' && $perfil === 'vendedor' && (int)$av['venda_responsavel_id'] === $meuId;
$podeAtribuir = perfilVeTudo() || $souConsultorNaCompra || $souResponsavelDaVenda;

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
            if ($resultado['ok']) {
                $sucesso = $resultado['aviso'] ?: 'Termo gerado e link mandado por e-mail pro comprador.';
                $linkTermoGerado = $resultado['link'] ?? null;
            } else {
                $erro = $resultado['erro'];
            }
        } elseif ($acao === 'excluir_avaliacao' && $perfil === 'super_admin') {
            $resultado = excluirAvaliacao($id);
            if ($resultado['ok']) {
                header('Location: /admin/avaliacoes.php');
                exit;
            }
            $erro = $resultado['erro'];
        } else {
            $erro = 'Ação não permitida.';
        }

        $av = buscarAvaliacao($id); // recarrega após qualquer mudança
    }
}

$itens = listarItensAvaliacao($id);
$score = veiculoAvaliacaoScore($itens);
$fotos = listarFotosAvaliacao($id);
$avaliadores = array_values(array_filter(listarUsuarios(), fn($u) => $u['perfil'] === 'avaliador'));

// Link do Termo Ciente — sempre reconstruído a partir do token já salvo
// (nunca gera um novo aqui, só exibe), pra "Copiar link" funcionar mesmo
// depois de recarregar a página, não só no instante do envio.
$linkTermoAtual = (!empty($linkTermoGerado))
    ? $linkTermoGerado
    : (($av['termo_ciente_token'] ?? '') !== '' ? appBaseUrl() . '/public/termo_ciente.php?token=' . $av['termo_ciente_token'] : null);

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
    // 06/10/2026, Termo Ciente: 'confirmado' é o estado novo (cliente
    // clicou "estou ciente" no link recebido por e-mail); 'assinado'/
    // 'recusado' só aparecem em termo já enviado via ZapSign ANTES dessa
    // mudança (histórico, nenhum código novo escreve mais nesses 2).
    return match ($status) {
        'enviado'   => '📧 Link enviado — aguardando confirmação do cliente',
        'confirmado'=> '✅ Cliente confirmou o recebimento',
        'assinado'  => '✅ Assinado (via ZapSign, antes do Termo Ciente)',
        'recusado'  => '❌ Recusado',
        'gerado'    => '📄 Gerado (ainda não enviado)',
        default     => '',
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
<link rel="stylesheet" href="/admin/assets/mobile.css?v=<?= @filemtime(__DIR__ . '/assets/mobile.css') ?: 1 ?>">
<script src="/admin/assets/mobile.js?v=<?= @filemtime(__DIR__ . '/assets/mobile.js') ?: 1 ?>" defer></script>
<?php include __DIR__ . '/_pwa_head.php'; ?>
</head>
<body>
<?php include __DIR__ . '/_impersonando_banner.php'; ?>
<header class="topbar">
    <a href="/admin/avaliacoes.php" style="color:#fff">← Vistorias</a>
    <a class="topbar-brand" href="<?= e(paginaInicialPorPerfil($_SESSION['admin_perfil'] ?? '')) ?>"><img class="topbar-logo" src="/admin/assets/img/icon-192.png" alt="Fastcar" onerror="this.style.display='none'"><span class="topbar-wordmark">Fast<b>Car</b></span></a>
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
    <?php if ($perfil === 'super_admin' && empty($av['termo_status'])): ?>
        <form method="post" onsubmit="return confirmarAcao(this, 'Excluir esta vistoria inteira (itens e fotos)? Ação sem volta — use só pra limpar duplicata criada por engano.');" style="margin-top:10px">
            <?= csrfField() ?>
            <input type="hidden" name="acao" value="excluir_avaliacao">
            <button type="submit" class="perigo">🗑️ Excluir esta vistoria (duplicata)</button>
        </form>
    <?php endif; ?>
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
    <p><small>
        <?= e($score['resumo']) ?>
        <?php if ($score['percentual'] !== null): ?> — <strong>Score: <?= (int)$score['percentual'] ?>%</strong><?php endif; ?>
    </small></p>
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
                            <form method="post" onsubmit="return confirmarAcao(this, 'Remover essa mídia da vistoria?');">
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
            <input type="file" name="midia" accept="image/*,video/*" required>
            <small>Toque pra tirar a foto na hora com a câmera, ou escolher um arquivo já salvo.</small>
            <label>Legenda (opcional)</label>
            <input type="text" name="legenda" placeholder="Ex: Avaria no para-choque traseiro">
            <button type="submit" style="min-height:48px">📷 Adicionar</button>
        </form>
    <?php endif; ?>
</div>

<?php if (podeAcessarCatalogoRevenda()): ?>
<div class="card">
    <h3>🛒 Catálogo de fotos pra revenda</h3>
    <p><small>Fotos/vídeos gerais do veículo pra IA de vendas usar — diferente da galeria da vistoria acima
       (que é sempre técnica/checklist e passa por aprovação). Útil pra já aproveitar a visita e fotografar o carro
       pronto pra vender, sem precisar esperar um vendedor.</small></p>
    <a href="/admin/veiculo_midias.php?id=<?= (int)$av['oportunidade_id'] ?>" class="btn btn-primary">📸 Adicionar fotos ao catálogo →</a>
</div>
<?php endif; ?>

<?php if ($av['tipo'] === 'venda'): ?>
<div class="card">
    <h3>📄 Termo Ciente — entrega e vistoria</h3>
    <p><small>Registro interno, sem assinatura eletrônica — o comprador só confirma com 1 clique no link
       recebido por e-mail que está de acordo com o estado do veículo na entrega.</small></p>
    <?php if ($av['termo_status']): ?>
        <p><?= avTermoStatusLabel($av['termo_status']) ?>
            <?php if ($av['termo_status'] === 'confirmado' && $av['termo_assinado_em']): ?>
                <small>— <?= date('d/m/Y H:i', strtotime($av['termo_assinado_em'])) ?></small>
            <?php elseif ($av['termo_ciente_enviado_em']): ?>
                <small>— link enviado em <?= date('d/m/Y H:i', strtotime($av['termo_ciente_enviado_em'])) ?></small>
            <?php endif; ?>
        </p>
        <?php if (($av['termo_ciente_ressalva'] ?? '') !== ''): ?>
            <p class="alerta-info">💬 Observação do comprador: <?= e($av['termo_ciente_ressalva']) ?></p>
        <?php endif; ?>
        <?php if ($av['drive_file_id'] || $av['arquivo_url']): ?>
            <p><a href="/admin/ver_avaliacao_termo.php?id=<?= (int)$av['id'] ?>" target="_blank">👁️ Ver PDF</a></p>
        <?php endif; ?>
        <?php if ($linkTermoAtual && $av['termo_status'] !== 'confirmado'): ?>
            <p>
                <a href="<?= e($linkTermoAtual) ?>" target="_blank">🔗 Link do Termo Ciente</a>
                &nbsp;
                <button type="button" onclick='copiarTexto(<?= json_encode($linkTermoAtual) ?>, this)'>📋 Copiar</button>
            </p>
        <?php endif; ?>
    <?php else: ?>
        <p><small>Nenhum termo gerado ainda.</small></p>
    <?php endif; ?>
    <?php if ($podeEditarChecklist && $av['termo_status'] !== 'confirmado'): ?>
        <form method="post" onsubmit="return confirmarAcao(this, 'Gerar o termo e mandar o link de ciência pro comprador agora?');">
            <?= csrfField() ?>
            <input type="hidden" name="acao" value="gerar_termo">
            <button type="submit" style="min-height:48px;font-size:15px">
                <?= $av['termo_status'] ? '📧 Gerar de novo e reenviar o link' : '📧 Gerar e enviar o link por e-mail' ?>
            </button>
        </form>
        <p><small>Manda pro e-mail do comprador (dados da negociação) — sem e-mail cadastrado, o link ainda é
           gerado pra copiar e mandar manualmente.</small></p>
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
<script>
// Botão "📋 Copiar" do link do Termo Ciente — mesmo padrão já usado em
// admin/oportunidade.php/admin/venda.php pro link de documentos/contrato.
function copiarTexto(texto, btn) {
    var original = btn.textContent;
    function marcarCopiado() {
        btn.textContent = '✅ Copiado!';
        setTimeout(function () { btn.textContent = original; }, 2000);
    }
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(texto).then(marcarCopiado).catch(function () { copiarTextoFallback(texto, marcarCopiado); });
    } else {
        copiarTextoFallback(texto, marcarCopiado);
    }
}
function copiarTextoFallback(texto, callback) {
    var ta = document.createElement('textarea');
    ta.value = texto;
    ta.style.position = 'fixed';
    ta.style.left = '-9999px';
    document.body.appendChild(ta);
    ta.select();
    try { document.execCommand('copy'); callback(); } catch (e) {}
    document.body.removeChild(ta);
}
</script>
<?php include __DIR__ . '/_pwa_register.php'; ?>
<?php include __DIR__ . '/_notify.php'; ?>
<?php include __DIR__ . '/_scroll_restore.php'; ?>
<?php include __DIR__ . '/_acao_popup.php'; ?>
<?php include __DIR__ . '/_confirm_dialog.php'; ?>
<?php include __DIR__ . '/_zapi_status.php'; ?>
</body>
</html>
