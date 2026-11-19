<?php
/**
 * Tickets da OUVIDORIA (20/11/2026, "será da para salvar esses tiket") — 1
 * ticket por assunto, protocolo OUV-AAAA-NNNNN mandado ao cliente na 1ª
 * resposta. Criados sozinhos pelo webhook (mensagens_ouvidoria.php); status
 * muda aqui ou no WhatsApp Box da ouvidoria. Só super admin.
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../includes/ouvidoria_inbox.php';
require_once __DIR__ . '/../includes/paginacao.php';
requireSuperAdmin();

$status = (string)($_GET['status'] ?? '');
if (!isset(OUVIDORIA_TICKET_STATUS[$status])) $status = '';
$assunto = (string)($_GET['assunto'] ?? '');
if (!isset(OUVIDORIA_ASSUNTOS[$assunto])) $assunto = '';
$busca = trim((string)($_GET['busca'] ?? ''));

$total = contarTicketsOuvidoria($status, $assunto, $busca);
$tickets = listarTicketsOuvidoria($status, $assunto, $busca, ITENS_POR_PAGINA_PADRAO, paginacaoOffset());
$resumo = resumoTicketsOuvidoria();
$sucesso = trim((string)($_GET['ok'] ?? ''));
$erro = trim((string)($_GET['erro'] ?? ''));

function _ouvTicketHoras(?float $h): string {
    if ($h === null || $h <= 0) return '—';
    if ($h < 1) return max(1, (int)round($h * 60)) . ' min';
    if ($h < 48) return number_format($h, 1, ',', '.') . ' h';
    return number_format($h / 24, 1, ',', '.') . ' dias';
}
function _ouvTicketsUrl(array $mudar): string {
    $q = array_filter(array_merge(['status' => $GLOBALS['status'], 'assunto' => $GLOBALS['assunto'], 'busca' => $GLOBALS['busca']], $mudar), fn($v) => $v !== '');
    return '/admin/ouvidoria_tickets.php' . ($q ? '?' . http_build_query($q) : '');
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Tickets da ouvidoria — Fastcar CRM</title>
<link rel="stylesheet" href="/admin/assets/style.css?v=<?= @filemtime(__DIR__ . '/assets/style.css') ?: 1 ?>">
<link rel="stylesheet" href="/admin/assets/mobile.css?v=<?= @filemtime(__DIR__ . '/assets/mobile.css') ?: 1 ?>">
<script src="/admin/assets/mobile.js?v=<?= @filemtime(__DIR__ . '/assets/mobile.js') ?: 1 ?>" defer></script>
<?php include __DIR__ . '/_pwa_head.php'; ?>
<style>
.ouvt-filtros { display:flex; flex-wrap:wrap; gap:8px; align-items:center; margin-bottom:12px; }
.ouvt-filtros a { font-size:12.5px; padding:6px 12px; border-radius:999px; border:1px solid var(--borda); color:inherit; text-decoration:none; background:var(--superficie); }
.ouvt-filtros a.ativo { background:var(--azul); color:#fff; border-color:var(--azul); }
.ouvt-busca { display:flex; gap:8px; max-width:520px; margin-bottom:12px; }
.ouvt-busca input { margin:0; }
.ouvt-busca button { margin:0; width:auto; }
.ouvt-resolver { display:flex; gap:6px; margin-top:6px; }
.ouvt-resolver input { margin:0; min-width:180px; }
.ouvt-resolver button { margin:0; width:auto; padding:6px 12px; font-size:12.5px; }
.ouvt-sla { color:var(--vermelho); font-weight:700; }
.ouvt-res { color:var(--texto-fraco); font-size:12.5px; overflow-wrap:anywhere; }
</style>
</head>
<body>
<?php include __DIR__ . '/_impersonando_banner.php'; ?>
<?php include __DIR__ . '/_topbar.php'; ?>
<main>

<?php if ($erro): ?><div class="alerta-erro"><?= e($erro) ?></div><?php endif; ?>
<?php if ($sucesso): ?><div class="alerta-sucesso"><?= e($sucesso) ?></div><?php endif; ?>

<h2 style="margin-top:0">🎫 Tickets da ouvidoria</h2>
<p style="color:var(--texto-fraco);margin-top:-6px;font-size:13px">1 ticket por assunto. Abre sozinho quando o cliente escreve um assunto novo; vira "em atendimento" quando alguém responde pela caixa da ouvidoria.</p>

<div class="stat-grid">
    <a class="stat-card <?= (int)$resumo['abertos'] > 0 ? 'alerta' : '' ?>" href="<?= e(_ouvTicketsUrl(['status' => 'aberto'])) ?>">
        <div class="valor"><?= (int)$resumo['abertos'] ?></div>
        <div class="rotulo">Abertos (sem resposta da equipe)</div>
    </a>
    <a class="stat-card <?= (int)$resumo['sem_resposta_24h'] > 0 ? 'alerta' : 'neutro' ?>" href="<?= e(_ouvTicketsUrl(['status' => 'aberto'])) ?>">
        <div class="valor"><?= (int)$resumo['sem_resposta_24h'] ?></div>
        <div class="rotulo">Abertos há mais de 24h ⚠️</div>
    </a>
    <a class="stat-card" href="<?= e(_ouvTicketsUrl(['status' => 'em_atendimento'])) ?>">
        <div class="valor"><?= (int)$resumo['em_atendimento'] ?></div>
        <div class="rotulo">Em atendimento</div>
    </a>
    <a class="stat-card sucesso" href="<?= e(_ouvTicketsUrl(['status' => 'resolvido'])) ?>">
        <div class="valor"><?= (int)$resumo['resolvidos_mes'] ?></div>
        <div class="rotulo">Resolvidos este mês</div>
    </a>
    <div class="stat-card neutro">
        <div class="valor"><?= e(_ouvTicketHoras($resumo['horas_media_resolucao'] ? (float)$resumo['horas_media_resolucao'] : null)) ?></div>
        <div class="rotulo">Tempo médio pra resolver (mês)</div>
    </div>
</div>

<div class="card">
    <form method="get" class="ouvt-busca">
        <?php if ($status): ?><input type="hidden" name="status" value="<?= e($status) ?>"><?php endif; ?>
        <?php if ($assunto): ?><input type="hidden" name="assunto" value="<?= e($assunto) ?>"><?php endif; ?>
        <input type="search" name="busca" value="<?= e($busca) ?>" placeholder="Protocolo, nome ou telefone...">
        <button type="submit">Buscar</button>
    </form>
    <nav class="ouvt-filtros" aria-label="Status">
        <a href="<?= e(_ouvTicketsUrl(['status' => ''])) ?>" class="<?= $status === '' ? 'ativo' : '' ?>">Todos</a>
        <?php foreach (OUVIDORIA_TICKET_STATUS as $k => $rot): ?>
            <a href="<?= e(_ouvTicketsUrl(['status' => $k])) ?>" class="<?= $status === $k ? 'ativo' : '' ?>"><?= e($rot) ?></a>
        <?php endforeach; ?>
    </nav>
    <nav class="ouvt-filtros" aria-label="Assunto">
        <a href="<?= e(_ouvTicketsUrl(['assunto' => ''])) ?>" class="<?= $assunto === '' ? 'ativo' : '' ?>">Todos os assuntos</a>
        <?php foreach (OUVIDORIA_ASSUNTOS as $k => $rot): ?>
            <a href="<?= e(_ouvTicketsUrl(['assunto' => $k])) ?>" class="<?= $assunto === $k ? 'ativo' : '' ?>"><?= e($rot) ?></a>
        <?php endforeach; ?>
    </nav>

    <div class="tabela-scroll">
    <table class="tabela-oportunidades">
        <thead><tr>
            <th>Protocolo</th><th>Cliente</th><th>Assunto</th><th>Status</th><th>Aberto em</th><th>1ª resposta</th><th>Resolução</th><th>Ações</th>
        </tr></thead>
        <tbody>
        <?php if (!$tickets): ?>
            <tr><td colspan="8" style="color:var(--texto-fraco)"><?= ($status || $assunto || $busca) ? 'Nenhum ticket encontrado.' : 'Nenhum ticket ainda — abrem sozinhos quando um cliente escreve pra ouvidoria.' ?></td></tr>
        <?php endif; ?>
        <?php foreach ($tickets as $t):
            $semResp = $t['primeira_resposta_em'] === null && $t['status'] === 'aberto';
            $atrasoSla = $semResp && (float)$t['horas_sem_resposta'] >= 24; ?>
            <tr class="<?= $atrasoSla ? 'linha-atrasada' : '' ?>">
                <td><strong><?= e($t['protocolo']) ?></strong></td>
                <td>
                    <?= e($t['nome_exib'] ?: '—') ?><br>
                    <small style="color:var(--texto-fraco)"><?= e($t['telefone']) ?></small>
                    <?php if ($t['venda_id']): ?><br><a href="/admin/venda.php?id=<?= (int)$t['venda_id'] ?>">🛒 Venda #<?= (int)$t['venda_id'] ?></a><?php endif; ?>
                    <?php if ($t['oportunidade_id']): ?><br><a href="/admin/oportunidade.php?id=<?= (int)$t['oportunidade_id'] ?>">🚗 Compra #<?= (int)$t['oportunidade_id'] ?></a><?php endif; ?>
                </td>
                <td><?= e(OUVIDORIA_ASSUNTOS[$t['assunto']] ?? $t['assunto']) ?></td>
                <td><?= e(OUVIDORIA_TICKET_STATUS[$t['status']] ?? $t['status']) ?></td>
                <td><?= e(date('d/m/Y H:i', strtotime($t['aberto_em']))) ?></td>
                <td>
                    <?php if ($t['primeira_resposta_em']): ?>
                        em <?= e(_ouvTicketHoras((float)$t['horas_sem_resposta'])) ?>
                    <?php elseif ($semResp): ?>
                        <span class="<?= $atrasoSla ? 'ouvt-sla' : '' ?>">aguardando há <?= e(_ouvTicketHoras((float)$t['horas_sem_resposta'])) ?></span>
                    <?php else: ?>—<?php endif; ?>
                </td>
                <td class="ouvt-res">
                    <?php if ($t['status'] === 'resolvido'): ?>
                        <?= e($t['resolucao']) ?><br>
                        <small><?= e($t['resolvido_por_nome'] ?: '—') ?> · <?= $t['resolvido_em'] ? e(date('d/m/Y H:i', strtotime($t['resolvido_em']))) : '' ?></small>
                    <?php else: ?>
                        <?= e(mb_strimwidth((string)$t['resumo'], 0, 120, '…')) ?>
                    <?php endif; ?>
                </td>
                <td>
                    <div class="acoes-linha">
                        <a class="chip-acao" href="/admin/ouvidoria_inbox.php?telefone=<?= e(rawurlencode($t['telefone'])) ?>">💬 Conversa</a>
                        <details>
                            <summary class="chip-acao">🔔 Jean<?= $t['notificado_em'] ? ' ✓' : '' ?></summary>
                            <form method="post" action="/admin/ouvidoria_inbox.php" class="ouvt-resolver">
                                <?= csrfField() ?>
                                <input type="hidden" name="acao" value="ticket_notificar_jean">
                                <input type="hidden" name="voltar" value="tickets">
                                <input type="hidden" name="ticket_id" value="<?= (int)$t['id'] ?>">
                                <input type="text" name="nota" maxlength="500" placeholder="Recado (opcional)">
                                <button type="submit">Enviar</button>
                            </form>
                            <?php if ($t['notificado_em']): ?><small class="ouvt-res">enviado <?= e(date('d/m H:i', strtotime($t['notificado_em']))) ?></small><?php endif; ?>
                        </details>
                        <?php if ($t['status'] === 'resolvido'): ?>
                            <form method="post" action="/admin/ouvidoria_inbox.php">
                                <?= csrfField() ?>
                                <input type="hidden" name="acao" value="ticket_status">
                                <input type="hidden" name="voltar" value="tickets">
                                <input type="hidden" name="ticket_id" value="<?= (int)$t['id'] ?>">
                                <input type="hidden" name="status" value="em_atendimento">
                                <button type="submit" class="chip-acao">↩️ Reabrir</button>
                            </form>
                        <?php else: ?>
                            <details>
                                <summary class="chip-acao">✅ Resolver</summary>
                                <form method="post" action="/admin/ouvidoria_inbox.php" class="ouvt-resolver">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="acao" value="ticket_status">
                                    <input type="hidden" name="voltar" value="tickets">
                                    <input type="hidden" name="ticket_id" value="<?= (int)$t['id'] ?>">
                                    <input type="hidden" name="status" value="resolvido">
                                    <input type="text" name="resolucao" required maxlength="500" placeholder="Como foi resolvido?">
                                    <button type="submit">OK</button>
                                </form>
                            </details>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php renderPaginacao($total); ?>
</div>

</main>
<?php include __DIR__ . '/_pwa_register.php'; ?>
<?php $notifModulo = 'ouvidoria'; include __DIR__ . '/_notify.php'; ?>
<?php include __DIR__ . '/_scroll_restore.php'; ?>
<?php include __DIR__ . '/_acao_popup.php'; ?>
<?php include __DIR__ . '/_confirm_dialog.php'; ?>
<?php include __DIR__ . '/_zapi_status.php'; ?>
</body>
</html>
