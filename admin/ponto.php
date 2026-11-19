<?php
/**
 * 🕒 Cartão de ponto (20/11/2026). Regras em includes/ponto.php.
 * Super admin/supervisor: dia de todo mundo + marcar/desfazer falta + mês de
 * cada pessoa. Demais perfis: só o próprio mês ("Meu ponto").
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../includes/fila_vendas.php';

$db = getDB();
$perfil = (string)($_SESSION['admin_perfil'] ?? '');
$meuId = (int)$_SESSION['admin_id'];
$gestor = perfilVeTudo(); // super_admin ou supervisor
$erro = '';
$sucesso = '';

$dia = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['dia'] ?? '')) ? $_GET['dia'] : date('Y-m-d');
if ($dia > date('Y-m-d')) $dia = date('Y-m-d');
$mes = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['mes'] ?? '')) ? $_GET['mes'] : date('Y-m');
if ($mes > date('Y-m')) $mes = date('Y-m');
$usuarioMes = $gestor ? (int)($_GET['usuario'] ?? 0) : $meuId;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        $erro = 'Sessão expirada, recarregue a página.';
    } elseif (!$gestor) {
        http_response_code(403);
        $erro = 'Só super admin ou supervisor marcam falta.';
    } else {
        $acao = (string)($_POST['acao'] ?? '');
        $uid = (int)($_POST['usuario_id'] ?? 0);
        $dataPost = (string)($_POST['data'] ?? $dia);
        if ($acao === 'marcar_falta') {
            $r = pontoMarcarFalta($uid, $dataPost, (string)($_POST['motivo'] ?? ''), $meuId);
            if ($r['ok']) {
                auditoriaRegistrar('ponto_falta_marcada', $meuId, (string)$_SESSION['admin_nome'], 'usuario', $uid,
                    "Falta de {$r['nome']} em " . date('d/m/Y', strtotime($dataPost)) . (trim((string)($_POST['motivo'] ?? '')) !== '' ? ': ' . trim((string)$_POST['motivo']) : '')
                    . ($r['movidas'] ? " — {$r['movidas']} lead(s) redistribuído(s)" : ''));
                $sucesso = "Falta de {$r['nome']} marcada em " . date('d/m/Y', strtotime($dataPost)) . '.'
                    . ($r['movidas'] ? " {$r['movidas']} lead(s) não tocado(s) foram redistribuídos." : '');
            } else {
                $erro = $r['erro'];
            }
        } elseif ($acao === 'definir_plantao') {
            // 20/11/2026 — plantão de fim de expediente veio de Configurações → Fila pra cá.
            $alvo = buscarUsuario($uid);
            if ($alvo && in_array($alvo['perfil'], ['consultor', 'vendedor', 'avaliador'], true)) {
                $ativo = !empty($_POST['ativo']);
                definirPlantaoFimExpediente($uid, $ativo);
                auditoriaRegistrar('ponto_plantao', $meuId, (string)$_SESSION['admin_nome'], 'usuario', $uid,
                    ($ativo ? 'Plantão de fim de expediente ligado' : 'Plantão de fim de expediente removido') . " — {$alvo['nome']}.");
                $sucesso = $ativo ? "{$alvo['nome']} marcado(a) como plantão de fim de expediente." : "Plantão de {$alvo['nome']} removido.";
            } else {
                $erro = 'Plantão só vale pra consultor, vendedor ou avaliador.';
            }
        } elseif ($acao === 'feriado_add') {
            $fData = (string)($_POST['feriado_data'] ?? '');
            $fNome = trim((string)($_POST['feriado_nome'] ?? ''));
            $fTipo = (string)($_POST['feriado_tipo'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fData) || $fNome === '' || !isset(FERIADO_TIPOS[$fTipo]) || $fTipo === 'nacional') {
                $erro = 'Preencha data, nome e tipo do feriado.';
            } else {
                $lista = feriadosLocais();
                $chave = !empty($_POST['feriado_todo_ano']) ? substr($fData, 5) : $fData;
                $lista = array_values(array_filter($lista, fn($f) => $f['data'] !== $chave));
                $lista[] = ['data' => $chave, 'nome' => mb_substr($fNome, 0, 80), 'tipo' => $fTipo];
                feriadosLocaisSalvar($lista);
                auditoriaRegistrar('ponto_feriado', $meuId, (string)$_SESSION['admin_nome'], '', null,
                    'Feriado cadastrado: ' . date('d/m', strtotime($fData)) . (strlen($chave) === 5 ? ' (todo ano)' : '/' . substr($fData, 0, 4)) . " — {$fNome} (" . FERIADO_TIPOS[$fTipo] . ').');
                $sucesso = "Feriado \"{$fNome}\" cadastrado — não conta como falta nem dia útil.";
            }
        } elseif ($acao === 'feriado_remover') {
            $lista = feriadosLocais();
            $i = (int)($_POST['idx'] ?? -1);
            if (isset($lista[$i])) {
                $rem = $lista[$i];
                unset($lista[$i]);
                feriadosLocaisSalvar($lista);
                auditoriaRegistrar('ponto_feriado', $meuId, (string)$_SESSION['admin_nome'], '', null, "Feriado removido: {$rem['data']} — {$rem['nome']}.");
                $sucesso = "Feriado \"{$rem['nome']}\" removido.";
            }
        } elseif ($acao === 'desfazer_falta') {
            pontoDesmarcarFalta($uid, $dataPost);
            $alvo = buscarUsuario($uid);
            auditoriaRegistrar('ponto_falta_desfeita', $meuId, (string)$_SESSION['admin_nome'], 'usuario', $uid,
                'Falta de ' . ($alvo['nome'] ?? "#{$uid}") . ' em ' . date('d/m/Y', strtotime($dataPost)) . ' desfeita (leads já redistribuídos não voltam).');
            $sucesso = 'Falta desfeita.';
        }
    }
}

$rotuloPerfil = ['super_admin' => 'Super admin', 'supervisor' => 'Supervisor', 'consultor' => 'Consultor', 'vendedor' => 'Vendedor',
    'financeiro' => 'Financeiro', 'avaliador' => 'Avaliador'];
$usuarios = $db->query("SELECT id, nome, perfil, plantao_fim_expediente FROM usuarios WHERE bloqueado = 0 ORDER BY CASE perfil WHEN 'consultor' THEN 1 WHEN 'vendedor' THEN 2 WHEN 'avaliador' THEN 3 WHEN 'financeiro' THEN 4 WHEN 'supervisor' THEN 5 ELSE 6 END, nome")->fetchAll();
$registrosDia = $gestor ? pontoRegistrosDoDia($dia) : [];
// Faltas no mês do dia escolhido, por pessoa (coluna "Faltas no mês").
$faltasMes = [];
if ($gestor) {
    $stF = $db->prepare("SELECT usuario_id, data FROM ponto_registros WHERE falta = 1 AND data BETWEEN ? AND ?");
    $stF->execute([substr($dia, 0, 7) . '-01', date('Y-m-t', strtotime($dia))]);
    foreach ($stF->fetchAll() as $f) {
        if (!isFeriado($f['data'])) $faltasMes[(int)$f['usuario_id']] = ($faltasMes[(int)$f['usuario_id']] ?? 0) + 1; // feriado não conta
    }
}
// Relatório do período (faltas e horários de todo mundo).
$relDe = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['de'] ?? '')) ? $_GET['de'] : date('Y-m-01');
$relAte = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['ate'] ?? '')) ? $_GET['ate'] : date('Y-m-d');
if ($relAte > date('Y-m-d')) $relAte = date('Y-m-d');
if ($relDe > $relAte) $relDe = $relAte;
$relatorio = $gestor ? pontoRelatorioPeriodo($relDe, $relAte) : null;
$diaSemanaPt = ['domingo', 'segunda', 'terça', 'quarta', 'quinta', 'sexta', 'sábado'];
$hora = fn(?string $dt) => $dt ? date('H:i', strtotime($dt)) : '—';

$usuarioMesDados = $usuarioMes ? buscarUsuario($usuarioMes) : null;
$registrosMes = $usuarioMesDados ? pontoRegistrosDoMes($usuarioMes, $mes) : [];
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Cartão de ponto — Fastcar CRM</title>
<link rel="stylesheet" href="/admin/assets/style.css?v=<?= @filemtime(__DIR__ . '/assets/style.css') ?: 1 ?>">
<link rel="stylesheet" href="/admin/assets/mobile.css?v=<?= @filemtime(__DIR__ . '/assets/mobile.css') ?: 1 ?>">
<script src="/admin/assets/mobile.js?v=<?= @filemtime(__DIR__ . '/assets/mobile.js') ?: 1 ?>" defer></script>
<?php include __DIR__ . '/_pwa_head.php'; ?>
</head>
<body>
<?php include __DIR__ . '/_impersonando_banner.php'; ?>
<?php include __DIR__ . '/_topbar.php'; ?>

<main>
<?php if ($erro): ?><div class="alerta-erro"><?= e($erro) ?></div><?php endif; ?>
<?php if ($sucesso): ?><div class="alerta-sucesso"><?= e($sucesso) ?></div><?php endif; ?>

<div class="card">
    <h2>🕒 Cartão de ponto</h2>
    <p><small>O ponto bate sozinho: <strong>entrada</strong> = 1º acesso ao sistema no dia (login ou abrir o sistema já logado);
        <strong>saída</strong> = clicar em Sair ou ser desconectado no fim do expediente — sem isso, vale o <strong>último acesso</strong>.
        Falta é marcada aqui<?= $gestor ? '; quem tem falta hoje não entra no sistema (é desconectado em qualquer aparelho); consultor/vendedor sai da fila e os leads que chegaram hoje e ele ainda não atendeu vão pros outros. Se ele chegar atrasado, "Desfazer falta" libera o acesso e volta pra fila na hora' : ' pela supervisão' ?>.
        <?php if ($gestor): ?><strong>Plantão</strong> = quem recebe lead quando ninguém está disponível na fila (fim de expediente).<?php endif; ?></small></p>
</div>

<?php if ($gestor): ?>
<div class="card" id="dia">
    <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:space-between">
        <h3 style="margin:0">📅 <?= e(ucfirst($diaSemanaPt[(int)date('w', strtotime($dia))])) ?>, <?= e(date('d/m/Y', strtotime($dia))) ?><?php if ($fDia = feriadoNome($dia)): ?> <span class="badge">🎉 feriado: <?= e($fDia) ?></span><?php endif; ?></h3>
        <form method="get" action="#dia" style="display:flex;gap:6px;align-items:center;margin:0">
            <input type="date" name="dia" value="<?= e($dia) ?>" max="<?= date('Y-m-d') ?>" style="width:auto;margin:0" onchange="this.form.submit()">
            <noscript><button type="submit" class="btn">Ver</button></noscript>
        </form>
    </div>
    <table class="tabela-oportunidades">
        <thead><tr><th>Pessoa</th><th>Perfil</th><th>Entrada</th><th>Saída / último acesso</th><th>Horas</th><th>Situação</th><th>Faltas no mês</th><th>Plantão</th><th>Falta</th></tr></thead>
        <tbody>
        <?php foreach ($usuarios as $u): $r = $registrosDia[(int)$u['id']] ?? null; $falta = $r && (int)$r['falta'] === 1; ?>
            <tr<?= $falta ? ' style="background:var(--vermelho-bg, #fef2f2)"' : '' ?>>
                <td><a href="/admin/ponto.php?usuario=<?= (int)$u['id'] ?>&amp;mes=<?= e(substr($dia, 0, 7)) ?>#mes"><strong><?= e($u['nome']) ?></strong></a></td>
                <td data-label="Perfil"><?= e($rotuloPerfil[$u['perfil']] ?? $u['perfil']) ?></td>
                <td data-label="Entrada"><?= $r ? e($hora($r['entrada'])) : '—' ?></td>
                <td data-label="Saída / último acesso">
                    <?php if ($r && $r['saida']): ?><?= e($hora($r['saida'])) ?> <small>(saiu)</small>
                    <?php elseif ($r && $r['ultima_atividade']): ?><?= e($hora($r['ultima_atividade'])) ?> <small>(último acesso)</small>
                    <?php else: ?>—<?php endif; ?>
                </td>
                <td data-label="Horas"><?= $r ? e(pontoFormatarMinutos(pontoMinutosTrabalhados($r))) : '—' ?></td>
                <td data-label="Situação">
                    <?php if ($falta): ?><span class="badge badge-atraso">❌ falta</span><?php if ($r['falta_motivo'] !== ''): ?><br><small><?= e($r['falta_motivo']) ?></small><?php endif; ?>
                    <?php elseif ($r && $r['entrada']): ?><span class="badge badge-ok">✅ presente</span>
                    <?php else: ?><span class="badge">sem registro</span><?php endif; ?>
                </td>
                <td data-label="Faltas no mês"><?php $fm = $faltasMes[(int)$u['id']] ?? 0; ?>
                    <a href="/admin/ponto.php?usuario=<?= (int)$u['id'] ?>&amp;mes=<?= e(substr($dia, 0, 7)) ?>#mes"><?= $fm ? '<span class="badge badge-atraso">' . $fm . '</span>' : '<span class="badge">0</span>' ?></a>
                </td>
                <td data-label="Plantão">
                    <?php if (in_array($u['perfil'], ['consultor', 'vendedor', 'avaliador'], true)): ?>
                        <?php if ($u['plantao_fim_expediente']): ?><span class="badge">🌙 plantão</span><br><?php endif; ?>
                        <form method="post" class="inline">
                            <?= csrfField() ?><input type="hidden" name="acao" value="definir_plantao"><input type="hidden" name="usuario_id" value="<?= (int)$u['id'] ?>">
                            <input type="hidden" name="ativo" value="<?= $u['plantao_fim_expediente'] ? '0' : '1' ?>">
                            <button type="submit" class="btn-texto"><?= $u['plantao_fim_expediente'] ? 'Remover plantão' : '🌙 Marcar plantão' ?></button>
                        </form>
                    <?php else: ?>—<?php endif; ?>
                </td>
                <td data-label="Falta">
                    <?php if ($falta): ?>
                        <form method="post" class="inline" onsubmit="return confirmarAcao(this, 'Desfazer a falta de <?= e(addslashes($u['nome'])) ?>? Ele(a) volta a entrar no sistema e, dentro do expediente, volta pra fila de lead novo. Leads já redistribuídos não voltam sozinhos.');">
                            <?= csrfField() ?><input type="hidden" name="acao" value="desfazer_falta"><input type="hidden" name="usuario_id" value="<?= (int)$u['id'] ?>"><input type="hidden" name="data" value="<?= e($dia) ?>">
                            <button type="submit" class="btn-texto">↩️ Desfazer falta</button>
                        </form>
                    <?php else: ?>
                        <details>
                            <summary class="btn-texto perigo" style="cursor:pointer">❌ Marcar falta</summary>
                            <form method="post" style="margin-top:6px" onsubmit="return confirmarAcao(this, 'Marcar falta de <?= e(addslashes($u['nome'])) ?> em <?= e(date('d/m/Y', strtotime($dia))) ?>?<?= $dia === date('Y-m-d') && !in_array($u['perfil'], ['super_admin', 'supervisor'], true) ? ' Ele(a) é desconectado(a) e não entra mais no sistema hoje.' : '' ?><?= $dia === date('Y-m-d') && in_array($u['perfil'], ['consultor', 'vendedor'], true) ? ' Sai da fila agora e os leads que chegaram hoje (ainda não atendidos) vão pros outros.' : '' ?>');">
                                <?= csrfField() ?><input type="hidden" name="acao" value="marcar_falta"><input type="hidden" name="usuario_id" value="<?= (int)$u['id'] ?>"><input type="hidden" name="data" value="<?= e($dia) ?>">
                                <input type="text" name="motivo" placeholder="Motivo (opcional — atestado, folga...)" maxlength="200">
                                <button type="submit" class="perigo" style="margin-top:4px">Confirmar falta</button>
                            </form>
                        </details>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php if ($relatorio): ?>
<div class="card" id="relatorio">
    <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:space-between">
        <h3 style="margin:0">📊 Relatório de faltas e horários</h3>
        <form method="get" action="#relatorio" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin:0">
            <input type="hidden" name="dia" value="<?= e($dia) ?>">
            <label style="margin:0">De <input type="date" name="de" value="<?= e($relDe) ?>" max="<?= date('Y-m-d') ?>" style="width:auto;margin:0"></label>
            <label style="margin:0">até <input type="date" name="ate" value="<?= e($relAte) ?>" max="<?= date('Y-m-d') ?>" style="width:auto;margin:0"></label>
            <button type="submit" class="btn" style="margin:0">Ver</button>
            <a class="btn" href="/admin/ponto_relatorio_excel.php?de=<?= e($relDe) ?>&amp;ate=<?= e($relAte) ?>">📗 Baixar Excel</a>
        </form>
    </div>
    <p><small><?= e(date('d/m/Y', strtotime($relatorio['de']))) ?> a <?= e(date('d/m/Y', strtotime($relatorio['ate']))) ?> ·
        <?= (int)$relatorio['dias_uteis'] ?> dia(s) útil(eis) (dias abertos da fila, sem feriado) ·
        <?php if ($relatorio['feriados']): ?>🎉 feriado(s), não contam: <?= e(implode(', ', array_map(fn($d, $n) => date('d/m', strtotime($d)) . " $n", array_keys($relatorio['feriados']), $relatorio['feriados']))) ?> ·<?php endif; ?> <strong>atraso</strong> = entrada depois das <?= e($relatorio['limite_atraso']) ?>.</small></p>
    <table class="tabela-oportunidades">
        <thead><tr><th>Pessoa</th><th>Perfil</th><th>Presente</th><th>Faltas</th><th>Atrasos</th><th>Sem registro</th><th>Horas</th><th>Entrada média</th><th>Datas das faltas</th></tr></thead>
        <tbody>
        <?php foreach ($relatorio['linhas'] as $l): ?>
            <tr>
                <td><a href="/admin/ponto.php?usuario=<?= (int)$l['id'] ?>&amp;mes=<?= e(substr($relatorio['ate'], 0, 7)) ?>#mes"><strong><?= e($l['nome']) ?></strong></a></td>
                <td data-label="Perfil"><?= e($rotuloPerfil[$l['perfil']] ?? $l['perfil']) ?></td>
                <td data-label="Presente"><?= (int)$l['presentes'] ?></td>
                <td data-label="Faltas"><?= count($l['faltas']) ? '<span class="badge badge-atraso">' . count($l['faltas']) . '</span>' : '0' ?></td>
                <td data-label="Atrasos" title="<?= e(implode(', ', array_map(fn($a) => date('d/m', strtotime($a['data'])) . ' ' . $a['entrada'], $l['atrasos']))) ?>"><?= count($l['atrasos']) ? '<span class="badge badge-aviso">' . count($l['atrasos']) . '</span>' : '0' ?></td>
                <td data-label="Sem registro"><?= count($l['sem_registro']) ?></td>
                <td data-label="Horas"><?= e(pontoFormatarMinutos($l['minutos'])) ?></td>
                <td data-label="Entrada média"><?= e($l['entrada_media'] ?? '—') ?></td>
                <td data-label="Datas das faltas"><?= $l['faltas'] ? e(implode(' · ', array_map(fn($f) => date('d/m', strtotime($f['data'])) . ($f['motivo'] !== '' ? " ({$f['motivo']})" : ''), $l['faltas']))) : '—' ?><?php if ($l['faltas_feriado']): ?><br><small>em feriado, não conta: <?= e(implode(', ', array_map(fn($f) => date('d/m', strtotime($f['data'])), $l['faltas_feriado']))) ?></small><?php endif; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php if ($gestor): $anoFer = (int)substr($relAte, 0, 4); $listaFer = feriadosListaAno($anoFer); ?>
<div class="card" id="feriados">
    <h3 style="margin-top:0">🎉 Feriados <?= $anoFer ?></h3>
    <p><small>Feriado não conta como dia útil, nem falta, nem "sem registro". Os <strong>nacionais</strong> vêm sozinhos (BrasilAPI).
        Estadual, municipal (ex.: aniversário de Barueri) ou folga da empresa: cadastre abaixo.</small></p>
    <table class="tabela-oportunidades">
        <thead><tr><th>Feriado</th><th>Data</th><th>Tipo</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($listaFer as $f): ?>
            <tr>
                <td><strong><?= e($f['nome']) ?></strong></td>
                <td data-label="Data"><?= e(date('d/m/Y', strtotime($f['data']))) ?> <small><?= e($diaSemanaPt[(int)date('w', strtotime($f['data']))]) ?></small><?= !empty($f['todo_ano']) ? ' <small>(todo ano)</small>' : '' ?></td>
                <td data-label="Tipo"><span class="badge<?= $f['tipo'] === 'nacional' ? '' : ' badge-info' ?>"><?= e(FERIADO_TIPOS[$f['tipo']] ?? $f['tipo']) ?></span></td>
                <td>
                    <?php if ($f['local']): ?>
                        <form method="post" class="inline" onsubmit="return confirmarAcao(this, 'Remover o feriado <?= e(addslashes($f['nome'])) ?>?');">
                            <?= csrfField() ?><input type="hidden" name="acao" value="feriado_remover"><input type="hidden" name="idx" value="<?= (int)$f['idx'] ?>">
                            <button type="submit" class="btn-texto perigo">Remover</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <details style="margin-top:10px">
        <summary class="btn-texto" style="cursor:pointer">➕ Cadastrar feriado estadual, municipal ou folga</summary>
        <form method="post" action="#feriados" style="max-width:480px;margin-top:8px">
            <?= csrfField() ?><input type="hidden" name="acao" value="feriado_add">
            <label>Data</label><input type="date" name="feriado_data" required>
            <label>Nome</label><input type="text" name="feriado_nome" maxlength="80" placeholder="Ex.: Aniversário de Barueri" required>
            <label>Tipo</label>
            <select name="feriado_tipo"><?php foreach (FERIADO_TIPOS as $k => $n): if ($k === 'nacional') continue; ?><option value="<?= e($k) ?>"><?= e($n) ?></option><?php endforeach; ?></select>
            <label style="display:flex;gap:8px;align-items:center"><input type="checkbox" name="feriado_todo_ano" value="1" style="width:auto" checked> Repete todo ano nessa data</label>
            <button type="submit">Cadastrar feriado</button>
        </form>
    </details>
</div>
<?php endif; ?>

<div class="card" id="mes">
    <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:space-between">
        <h3 style="margin:0">🗓️ <?= $gestor ? 'Mês por pessoa' : 'Meu ponto' ?><?= $usuarioMesDados ? ' — ' . e($usuarioMesDados['nome']) : '' ?></h3>
        <form method="get" action="#mes" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin:0">
            <?php if ($gestor): ?>
                <input type="hidden" name="dia" value="<?= e($dia) ?>">
                <select name="usuario" style="width:auto;margin:0" onchange="this.form.submit()">
                    <option value="0">— escolha a pessoa —</option>
                    <?php foreach ($usuarios as $u): ?><option value="<?= (int)$u['id'] ?>" <?= $usuarioMes === (int)$u['id'] ? 'selected' : '' ?>><?= e($u['nome']) ?></option><?php endforeach; ?>
                </select>
            <?php endif; ?>
            <input type="month" name="mes" value="<?= e($mes) ?>" max="<?= date('Y-m') ?>" style="width:auto;margin:0" onchange="this.form.submit()">
            <noscript><button type="submit" class="btn">Ver</button></noscript>
        </form>
    </div>
    <?php if (!$usuarioMesDados): ?>
        <p style="color:var(--texto-suave)">Escolha uma pessoa pra ver o mês.</p>
    <?php else:
        $ultimoDia = $mes === date('Y-m') ? (int)date('j') : (int)date('t', strtotime($mes . '-01'));
        $totMin = 0; $diasPresente = 0; $diasFalta = 0; ?>
        <table class="tabela-oportunidades">
            <thead><tr><th>Dia</th><th>Entrada</th><th>Saída / último acesso</th><th>Horas</th><th>Situação</th></tr></thead>
            <tbody>
            <?php for ($d = $ultimoDia; $d >= 1; $d--):
                $data = sprintf('%s-%02d', $mes, $d); $r = $registrosMes[$data] ?? null;
                $dow = (int)date('w', strtotime($data)); $falta = $r && (int)$r['falta'] === 1;
                $min = $r ? pontoMinutosTrabalhados($r) : null; $fer = feriadoNome($data);
                if ($falta && !$fer) $diasFalta++; elseif ($r && $r['entrada']) { $diasPresente++; $totMin += (int)$min; } ?>
                <tr<?= $falta ? ' style="background:var(--vermelho-bg, #fef2f2)"' : '' ?>>
                    <td><strong><?= e(date('d/m', strtotime($data))) ?></strong> <small><?= e($diaSemanaPt[$dow]) ?></small></td>
                    <td data-label="Entrada"><?= $r ? e($hora($r['entrada'])) : '—' ?></td>
                    <td data-label="Saída / último acesso"><?= $r ? e($hora(pontoFim($r))) . ($r['saida'] ? '' : ($r['ultima_atividade'] ? ' <small>(último acesso)</small>' : '')) : '—' ?></td>
                    <td data-label="Horas"><?= e(pontoFormatarMinutos($min)) ?></td>
                    <td data-label="Situação">
                        <?php if ($falta): ?><span class="badge badge-atraso">❌ falta</span><?= $r['falta_motivo'] !== '' ? ' <small>' . e($r['falta_motivo']) . '</small>' : '' ?><?= $fer ? ' <small>(feriado — não conta)</small>' : '' ?>
                        <?php elseif ($r && $r['entrada']): ?><span class="badge badge-ok">✅ presente</span><?= $fer ? ' <small>🎉 ' . e($fer) . '</small>' : '' ?>
                        <?php elseif ($fer): ?><span class="badge">🎉 <?= e($fer) ?></span>
                        <?php elseif ($dow === 0): ?><small>domingo</small>
                        <?php else: ?><span class="badge">sem registro</span><?php endif; ?>
                    </td>
                </tr>
            <?php endfor; ?>
            </tbody>
        </table>
        <p><strong><?= $diasPresente ?></strong> dia(s) presente · <strong><?= $diasFalta ?></strong> falta(s) · <strong><?= e(pontoFormatarMinutos($totMin)) ?></strong> no total</p>
    <?php endif; ?>
</div>
</main>
<?php include __DIR__ . '/_pwa_register.php'; ?>
<?php include __DIR__ . '/_notify.php'; ?>
<?php include __DIR__ . '/_scroll_restore.php'; ?>
<?php include __DIR__ . '/_acao_popup.php'; ?>
<?php include __DIR__ . '/_confirm_dialog.php'; ?>
</body>
</html>
