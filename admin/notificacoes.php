<?php
/**
 * Endpoint JSON de polling — novos leads + eventos persistentes (ex:
 * "cliente confirmou os documentos") pro usuário logado. Sem WebSocket/SSE
 * (shared-hosting-friendly, mesma filosofia do resto do projeto): o JS de
 * admin/_notify.php chama isso a cada ~20s.
 *
 * Dois cursores independentes, mesclados numa resposta só:
 * - "desde" (datetime): detecção de LEAD NOVO, computada ao vivo contra
 *   oportunidades.created_at/updated_at — mecanismo ORIGINAL, intocado,
 *   já validado em produção. Cursor OPACO (string no mesmo formato que o
 *   banco já usa), nunca calculado/parseado no JS — evita bug de fuso
 *   horário (mesmo cuidado do posicao_fila monotônico em includes/fila_leads.php).
 * - "desde_notif_id" (inteiro): eventos da tabela `notificacoes` (30/09/2026,
 *   "como sabemos cliente preencheu... notificação clicável") — cursor
 *   simples por id autoincrement, um por usuário.
 *
 * `?historico=1`: painel rolável do sino — últimas N notificações da
 * tabela (lidas+não lidas), marca tudo como lida na mesma chamada.
 *
 * `?modulo=vendas` (22/10/2026, "em vendas só aparecer notificações
 * somente da vendas") — passado pelas 4 telas do módulo de vendas
 * (admin/vendas.php/venda.php/vendas_inbox.php/promissorias.php, via
 * `$notifModulo='vendas'` setado antes de incluir admin/_notify.php).
 * Ausente (todo o resto do admin): comportamento IDÊNTICO a antes desta
 * feature, nunca muda. Presente: o "lead novo" live vira `vendas` em vez
 * de `oportunidades` (nova função — nunca existia um feed desse tipo pro
 * lado de vendas), e os eventos persistidos da tabela `notificacoes`
 * ficam restritos aos tipos de venda (tiposNotificacaoPorModulo(),
 * includes/notificacoes.php) — nunca mistura "cliente confirmou
 * documentos" do funil de COMPRA enquanto o usuário está no módulo de
 * vendas, mesmo sendo super_admin/supervisor vendo os dois funis.
 */

require_once __DIR__ . '/_bootstrap.php';
liberarSessao();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$db = getDB();
$perfil = $_SESSION['admin_perfil'];
$meuId  = (int)$_SESSION['admin_id'];
// 24/10/2026, "não misturar notificações vendas com compra": vendedor
// sempre vê só vendas; nas telas de compra (e qualquer outra sem
// ?modulo=vendas) os eventos de venda nunca aparecem.
$modulo = moduloNotificacaoAtual(($_GET['modulo'] ?? '') === 'vendas' ? 'vendas' : null, (string)$perfil);
$tiposFiltro = tiposNotificacaoPorModulo($modulo);

if (isset($_GET['historico'])) {
    marcarNotificacoesLidas($meuId, $tiposFiltro);
    $itens = array_map(fn($n) => [
        'id'         => (int)$n['id'],
        'tipo'       => $n['tipo'],
        'titulo'     => $n['titulo'],
        'mensagem'   => $n['mensagem'],
        'url'        => $n['url'],
        'created_at' => $n['created_at'],
    ], listarNotificacoes($meuId, 30, $tiposFiltro));

    // 08/10/2026, "ai tem vim os carros atribuidos... deixa uns 7 ultimos"
    // — achado real: pro perfil avaliador, o sino nunca tinha NADA
    // aplicável (o "novo_lead" acima só olha oportunidades.responsavel_id,
    // nunca atribuído a avaliador; "documentos_confirmados" só vai pro
    // responsável consultor/vendedor + super_admin/supervisor, ver
    // destinatariosNotificacao() em includes/notificacoes.php) — a tela
    // sempre mostrava "Nenhuma notificação ainda.", mesmo com vistoria
    // de verdade esperando. Mesclado aqui igual ao mecanismo de "lead
    // novo" (computado AO VIVO, nunca persistido em `notificacoes` — não
    // é um evento pontual, é um ESTADO atual: "o que está atribuído a
    // mim agora"), com as 7 atribuições mais recentes.
    if ($perfil === 'avaliador') {
        $pendentesAval = array_slice(array_reverse(listarAvaliacoesPendentes($meuId)), 0, 7);
        foreach ($pendentesAval as $a) {
            $veiculo = trim(($a['veiculo_marca'] ?? '') . ' ' . ($a['veiculo_modelo'] ?? ''));
            $emoji = ($a['tipo_veiculo'] ?? '') === 'moto' ? '🏍️' : '🚗';
            $itens[] = [
                'id'         => (int)$a['id'],
                'tipo'       => 'vistoria_atribuida',
                'titulo'     => $emoji . ' Vistoria atribuída',
                'mensagem'   => ($a['cliente_nome'] ?: '(sem nome)') . ($veiculo !== '' ? ' — ' . $veiculo : ''),
                'url'        => '/admin/avaliacao.php?id=' . (int)$a['id'],
                'created_at' => $a['created_at'],
            ];
        }
        usort($itens, fn($x, $y) => strcmp($y['created_at'], $x['created_at']));
    }

    echo json_encode(['itens' => $itens]);
    exit;
}

$agora = $db->query("SELECT datetime('now','localtime')")->fetchColumn();
$desde = trim((string)($_GET['desde'] ?? ''));
// Sem cursor (1ª chamada) ou cursor malformado: baseline é agora — nunca
// dispara notificação retroativa de leads antigos na primeira visita.
if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $desde)) {
    $desde = $agora;
}
$desdeNotifId = (int)($_GET['desde_notif_id'] ?? 0);

// 01/10/2026, "notificação marca 1, clico, não guarda histórico" — o painel
// (?historico=1) só lia a tabela `notificacoes`, nunca "lead novo" (sempre
// ao vivo, nunca persistido) — clicar o sino depois de um toast de lead
// novo mostrava "Nenhuma notificação ainda.", mesmo o contador tendo
// marcado 1. `created_at` entra aqui pra admin/_notify.php guardar esse
// item num histórico local (localStorage) e mesclar com o painel do
// servidor — nunca precisou virar linha na tabela `notificacoes` (lead
// novo já tem o próprio badge/contador, duplicar criaria 2 fontes de
// verdade pro mesmo evento).
if ($modulo === 'vendas') {
    // 22/10/2026 — nunca existia feed de "lead novo" pro funil de vendas;
    // criado só aqui, escopado ao módulo, nunca aparecendo misturado com
    // compra em nenhuma outra tela. perfilVeTudo() (não `$perfil ===
    // 'super_admin'` puro, diferente do branch de compra abaixo — aqui é
    // código novo, sem comportamento antigo pra preservar) deixa o
    // supervisor ver todo lead de venda novo, mesmo padrão "acompanha
    // tudo" já usado no resto do projeto.
    if (perfilVeTudo()) {
        $stmt = $db->prepare("
            SELECT v.id, v.comprador_nome, v.veiculo_interesse_texto, v.oportunidade_id, v.created_at
            FROM vendas v WHERE v.origem = 'whatsapp' AND v.created_at > ? ORDER BY v.created_at ASC LIMIT 20
        ");
        $stmt->execute([$desde]);
    } else {
        // 24/10/2026, "nas notificações sempre Novo Lead de Venda" — antes
        // usava updated_at, que muda a cada mensagem/IA/observação, então o
        // mesmo lead disparava "novo lead" toda hora. Agora só quando o lead
        // é NOVO de verdade (criado agora) ou acabou de ser PASSADO pra mim
        // (reatribuição/redistribuição gravada no histórico).
        $stmt = $db->prepare("
            SELECT v.id, v.comprador_nome, v.veiculo_interesse_texto, v.oportunidade_id,
                   MAX(v.created_at, COALESCE((SELECT MAX(h.created_at) FROM venda_historico h
                        WHERE h.venda_id = v.id AND h.created_at > ? AND h.etapa_anterior = h.etapa_nova
                          AND (h.observacao LIKE '%Reatribu%' OR h.observacao LIKE '%edistribu%' OR h.observacao LIKE '%devolvido%')), '')) AS created_at
            FROM vendas v
            WHERE v.responsavel_id = ?
              AND ((v.origem = 'whatsapp' AND v.created_at > ?) OR EXISTS (SELECT 1 FROM venda_historico h
                        WHERE h.venda_id = v.id AND h.created_at > ? AND h.etapa_anterior = h.etapa_nova
                          AND (h.observacao LIKE '%Reatribu%' OR h.observacao LIKE '%edistribu%' OR h.observacao LIKE '%devolvido%')))
            ORDER BY created_at ASC LIMIT 20
        ");
        $stmt->execute([$desde, $meuId, $desde, $desde]);
    }
    $leads = array_map(function ($v) use ($db) {
        $veiculo = trim((string)($v['veiculo_interesse_texto'] ?? ''));
        if ($v['oportunidade_id']) {
            $stmtOp = $db->prepare("SELECT veiculo_marca, veiculo_modelo FROM oportunidades WHERE id = ?");
            $stmtOp->execute([$v['oportunidade_id']]);
            $op = $stmtOp->fetch();
            $vinculado = $op ? trim(($op['veiculo_marca'] ?? '') . ' ' . ($op['veiculo_modelo'] ?? '')) : '';
            if ($vinculado !== '') $veiculo = $vinculado;
        }
        return [
            'id'         => (int)$v['id'],
            'tipo'       => 'novo_lead_venda',
            'titulo'     => '🛒 Novo lead de venda',
            'mensagem'   => ($v['comprador_nome'] ?: '(sem nome)') . ($veiculo !== '' ? ' — ' . $veiculo : ''),
            'url'        => '/admin/venda.php?id=' . $v['id'],
            'created_at' => $v['created_at'],
        ];
    }, $stmt->fetchAll());
} elseif ($perfil === 'super_admin') {
    $stmt = $db->prepare("
        SELECT o.id, o.veiculo_marca, o.veiculo_modelo, c.nome AS cliente_nome, o.created_at
        FROM oportunidades o JOIN clientes c ON c.id = o.cliente_id
        WHERE o.created_at > ? ORDER BY o.created_at ASC LIMIT 20
    ");
    $stmt->execute([$desde]);
    $leads = array_map(fn($o) => [
        'id'      => (int)$o['id'],
        'tipo'    => 'novo_lead',
        'titulo'  => '🚗 Novo lead de compra',
        'mensagem' => ($o['cliente_nome'] ?: '(sem nome)') . (trim(($o['veiculo_marca'] ?? '') . ' ' . ($o['veiculo_modelo'] ?? '')) !== '' ? ' — ' . trim(($o['veiculo_marca'] ?? '') . ' ' . ($o['veiculo_modelo'] ?? '')) : ''),
        'url'     => '/admin/oportunidade.php?id=' . $o['id'],
        'created_at' => $o['created_at'],
    ], $stmt->fetchAll());
} else {
    $stmt = $db->prepare("
        SELECT o.id, o.veiculo_marca, o.veiculo_modelo, c.nome AS cliente_nome, o.updated_at AS created_at
        FROM oportunidades o JOIN clientes c ON c.id = o.cliente_id
        WHERE o.responsavel_id = ? AND o.updated_at > ? ORDER BY o.updated_at ASC LIMIT 20
    ");
    $stmt->execute([$meuId, $desde]);
    $leads = array_map(fn($o) => [
        'id'      => (int)$o['id'],
        'tipo'    => 'novo_lead',
        'titulo'  => '🚗 Novo lead de compra',
        'mensagem' => ($o['cliente_nome'] ?: '(sem nome)') . (trim(($o['veiculo_marca'] ?? '') . ' ' . ($o['veiculo_modelo'] ?? '')) !== '' ? ' — ' . trim(($o['veiculo_marca'] ?? '') . ' ' . ($o['veiculo_modelo'] ?? '')) : ''),
        'url'     => '/admin/oportunidade.php?id=' . $o['id'],
        'created_at' => $o['created_at'],
    ], $stmt->fetchAll());
}

[$filtroNotifSql, $filtroNotifParams] = _notificacoesFiltroTipo($tiposFiltro);
$stmtNotif = $db->prepare("
    SELECT id, tipo, titulo, mensagem, url FROM notificacoes
    WHERE usuario_id = ? AND id > ?{$filtroNotifSql} ORDER BY id ASC LIMIT 20
");
$stmtNotif->execute(array_merge([$meuId, $desdeNotifId], $filtroNotifParams));
$eventosLinhas = $stmtNotif->fetchAll();
$eventos = array_map(fn($n) => [
    'id'       => (int)$n['id'],
    'tipo'     => $n['tipo'],
    'titulo'   => $n['titulo'],
    'mensagem' => $n['mensagem'],
    'url'      => $n['url'],
], $eventosLinhas);
$proximoNotifId = $eventosLinhas ? (int)end($eventosLinhas)['id'] : $desdeNotifId;

// 29/10/2026, "coloca popup do inbox clicável quando tiver mensagens" —
// mensagem nova de cliente no WhatsApp vira popup (1 por conversa) que abre
// a conversa direto. Cursor próprio por id (desde_msg_id); 1ª chamada só
// pega a referência, nunca dispara popup de mensagem antiga. Só conversas
// que o usuário pode ver: consultor = cliente com oportunidade ativa dele;
// vendedor = comprador de negociação dele; super_admin/supervisor = todas
// do módulo. Financeiro/avaliador não recebem.
$desdeMsgId = (int)($_GET['desde_msg_id'] ?? 0);
$msgs = [];
$maxMsgId = (int)$db->query("SELECT COALESCE(MAX(id), 0) FROM whatsapp_mensagens")->fetchColumn();
if ($desdeMsgId > 0 && $desdeMsgId < $maxMsgId && in_array($perfil, ['super_admin', 'supervisor', 'consultor', 'vendedor'], true)) {
    $phEtapas = implode(',', array_fill(0, count(ETAPAS_ATIVAS), '?'));
    if ($modulo === 'vendas') {
        $filtroConversa = "EXISTS (SELECT 1 FROM vendas v WHERE v.comprador_telefone = m.telefone"
            . (perfilVeTudo() ? '' : ' AND v.responsavel_id = ' . $meuId) . ")";
        $paramsConversa = [];
        $urlInbox = '/admin/vendas_inbox.php?telefone=';
    } else {
        $filtroConversa = "EXISTS (SELECT 1 FROM clientes c2 JOIN oportunidades o2 ON o2.cliente_id = c2.id
            WHERE c2.telefone = m.telefone AND o2.etapa IN ($phEtapas)" . (perfilVeTudo() ? '' : ' AND o2.responsavel_id = ' . $meuId) . ")";
        $paramsConversa = ETAPAS_ATIVAS;
        $urlInbox = '/admin/whatsapp_inbox.php?telefone=';
    }
    $stmtMsg = $db->prepare("
        SELECT m.telefone, COUNT(*) AS qtd, MAX(m.id) AS ultimo_id, MAX(m.created_at) AS created_at,
               (SELECT m3.mensagem FROM whatsapp_mensagens m3 WHERE m3.telefone = m.telefone AND m3.direcao = 'in' AND m3.id > ? ORDER BY m3.id DESC LIMIT 1) AS texto,
               (SELECT c.nome FROM clientes c WHERE c.telefone = m.telefone) AS nome_cliente,
               (SELECT v.comprador_nome FROM vendas v WHERE v.comprador_telefone = m.telefone ORDER BY v.id DESC LIMIT 1) AS nome_comprador
        FROM whatsapp_mensagens m
        WHERE m.id > ? AND m.direcao = 'in' AND m.created_at >= datetime('now','localtime','-10 minutes') AND {$filtroConversa}
        GROUP BY m.telefone ORDER BY ultimo_id ASC LIMIT 10
    ");
    $stmtMsg->execute(array_merge([$desdeMsgId, $desdeMsgId], $paramsConversa));
    foreach ($stmtMsg->fetchAll() as $r) {
        $nome = ($modulo === 'vendas' ? ($r['nome_comprador'] ?: $r['nome_cliente']) : ($r['nome_cliente'] ?: $r['nome_comprador'])) ?: $r['telefone'];
        $texto = trim((string)$r['texto']);
        $msgs[] = [
            'id'         => (int)$r['ultimo_id'],
            'tipo'       => 'mensagem_whatsapp',
            'telefone'   => $r['telefone'],
            'titulo'     => '💬 ' . ((int)$r['qtd'] > 1 ? (int)$r['qtd'] . ' mensagens de ' : 'Mensagem de ') . $nome,
            'mensagem'   => mb_strimwidth($texto !== '' ? $texto : '(mídia)', 0, 90, '…'),
            'url'        => $urlInbox . rawurlencode($r['telefone']),
            'created_at' => $r['created_at'],
        ];
    }
}

echo json_encode([
    'novos'           => array_merge($leads, $eventos, $msgs),
    'proximo_msg_id'  => $maxMsgId,
    'proximo_desde'   => $agora,
    'proximo_notif_id' => $proximoNotifId,
    'nao_lidos_notif' => contarNotificacoesNaoLidas($meuId, $tiposFiltro),
]);
