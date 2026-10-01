<?php
/**
 * Diagnóstico pontual (só leitura, nunca apaga/altera nada) — 01/10/2026,
 * pedido direto: "verifica rafael e anderson fecharam mesmo cliente caiu
 * mesmo lead para eles" — checar se o mesmo cliente/lead caiu pra dois
 * consultores diferentes.
 *
 * Esse ambiente de dev não tem acesso ao banco de produção (só a VPS
 * tem) — rodar este script via SSH direto na VPS:
 *
 *   php install/diagnosticar_lead_duplicado_consultores.php [nome1] [nome2]
 *
 * Sem argumentos, compara "Rafael" × "Anderson" (os 2 nomes do pedido).
 * Pode rodar com qualquer outro par de nomes depois, se precisar checar
 * outra dupla.
 *
 * Verifica 4 cenários, do lado de COMPRA (oportunidades) e de VENDA
 * (revenda), que são exatamente os padrões de bug real já documentados
 * no CLAUDE.md (seção "Fila de leads / plantão"):
 *
 * 1. Mesmo cliente (mesmo telefone) com 2+ oportunidades/vendas
 *    DIFERENTES, uma atribuída a cada consultor — pode ser legítimo
 *    (regra #1: 1 cliente pode ter mais de 1 veículo em negociação) ou
 *    pode ser cadastro duplicado por engano.
 * 2. A MESMA oportunidade/venda com responsável reatribuído entre os
 *    dois ao longo do tempo (via oportunidade_historico/venda_historico)
 *    — o padrão clássico da corrida de rodízio (23/09/2026, "corrida
 *    real no rodízio fazia lead 'pular' de um consultor pra outro em
 *    rajada") ou de redistribuição automática.
 * 3. Dentro do caso 2, destaca quando `consultor_tel_enviado_em` já
 *    estava preenchido ANTES da reatribuição — esse é o bug real já
 *    corrigido uma vez (22/09/2026, "Reatribuição automática deixava o
 *    cliente com contato de consultor errado": cliente já tinha
 *    recebido o WhatsApp PESSOAL de um consultor, mas o sistema
 *    reatribuiu pro outro mesmo assim). A trava (`consultor_tel_enviado_em
 *    IS NULL` nas queries de redistribuição) deveria impedir isso
 *    acontecer DE NOVO depois do fix — se aparecer aqui, é achado real.
 * 4. Oportunidade/venda já FECHADA com fechado_por/vendido por um dos
 *    dois, mas responsavel_id atual sendo o outro — combinação
 *    estranha, vale olhar manualmente.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Este script só roda via linha de comando (CLI).\n");
}

require_once __DIR__ . '/../includes/db.php';
$db = getDB();

$nome1 = $argv[1] ?? 'Rafael';
$nome2 = $argv[2] ?? 'Anderson';

function buscarUsuarios(PDO $db, string $nomeBusca): array {
    $stmt = $db->prepare("SELECT id, nome, perfil, bloqueado FROM usuarios WHERE nome LIKE ? ORDER BY id");
    $stmt->execute(['%' . $nomeBusca . '%']);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$usuarios1 = buscarUsuarios($db, $nome1);
$usuarios2 = buscarUsuarios($db, $nome2);

echo "=== Usuários encontrados ===\n";
echo "\"{$nome1}\":\n";
foreach ($usuarios1 as $u) {
    echo "  #{$u['id']}  {$u['nome']}  (perfil: {$u['perfil']}" . ($u['bloqueado'] ? ', BLOQUEADO' : '') . ")\n";
}
if (!$usuarios1) echo "  (nenhum usuário batendo com esse nome)\n";

echo "\"{$nome2}\":\n";
foreach ($usuarios2 as $u) {
    echo "  #{$u['id']}  {$u['nome']}  (perfil: {$u['perfil']}" . ($u['bloqueado'] ? ', BLOQUEADO' : '') . ")\n";
}
if (!$usuarios2) echo "  (nenhum usuário batendo com esse nome)\n";

if (!$usuarios1 || !$usuarios2) {
    echo "\n❌ Não deu pra achar os dois usuários — rode de novo com o nome exato\n";
    echo "   (ex: php install/diagnosticar_lead_duplicado_consultores.php \"Rafael Rocha\" \"Anderson Souza\")\n";
    exit(1);
}

$ids1 = array_column($usuarios1, 'id');
$ids2 = array_column($usuarios2, 'id');
$ph1 = implode(',', array_fill(0, count($ids1), '?'));
$ph2 = implode(',', array_fill(0, count($ids2), '?'));
$nomes1 = implode('/', array_column($usuarios1, 'nome'));
$nomes2 = implode('/', array_column($usuarios2, 'nome'));

// ---------------------------------------------------------------------
// 1a. COMPRA — mesmo cliente, oportunidades diferentes pros 2 consultores
// ---------------------------------------------------------------------
echo "\n\n=== 1) COMPRA — mesmo cliente com oportunidade(s) atribuída(s) aos dois ===\n";
$stmt = $db->prepare("
    SELECT c.id AS cliente_id, c.nome AS cliente_nome, c.telefone,
           o1.id AS op1_id, o1.etapa AS op1_etapa, o1.veiculo_marca AS op1_marca, o1.veiculo_modelo AS op1_modelo, o1.responsavel_id AS op1_resp,
           o2.id AS op2_id, o2.etapa AS op2_etapa, o2.veiculo_marca AS op2_marca, o2.veiculo_modelo AS op2_modelo, o2.responsavel_id AS op2_resp
    FROM clientes c
    JOIN oportunidades o1 ON o1.cliente_id = c.id AND o1.responsavel_id IN ($ph1)
    JOIN oportunidades o2 ON o2.cliente_id = c.id AND o2.responsavel_id IN ($ph2) AND o2.id != o1.id
    ORDER BY c.id
");
$stmt->execute(array_merge($ids1, $ids2));
$achados1 = $stmt->fetchAll(PDO::FETCH_ASSOC);
if (!$achados1) {
    echo "(nenhum — nenhum cliente tem oportunidade separada pra cada um dos dois)\n";
} else {
    foreach ($achados1 as $a) {
        echo "Cliente #{$a['cliente_id']} {$a['cliente_nome']} ({$a['telefone']}):\n";
        echo "  · oportunidade #{$a['op1_id']} [{$a['op1_etapa']}] {$a['op1_marca']} {$a['op1_modelo']} — responsável #{$a['op1_resp']} ({$nomes1})\n";
        echo "  · oportunidade #{$a['op2_id']} [{$a['op2_etapa']}] {$a['op2_marca']} {$a['op2_modelo']} — responsável #{$a['op2_resp']} ({$nomes2})\n";
    }
}

// ---------------------------------------------------------------------
// 1b. COMPRA — a MESMA oportunidade reatribuída entre os dois (histórico)
// ---------------------------------------------------------------------
echo "\n=== 2) COMPRA — mesma oportunidade com histórico passando pelos dois ===\n";
$stmt = $db->prepare("
    SELECT DISTINCT o.id
    FROM oportunidades o
    WHERE EXISTS (SELECT 1 FROM oportunidade_historico h WHERE h.oportunidade_id = o.id AND h.responsavel_id IN ($ph1))
      AND EXISTS (SELECT 1 FROM oportunidade_historico h WHERE h.oportunidade_id = o.id AND h.responsavel_id IN ($ph2))
    ORDER BY o.id
");
$stmt->execute(array_merge($ids1, $ids2));
$opsReatribuidas = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'id');

if (!$opsReatribuidas) {
    echo "(nenhuma — nenhuma oportunidade teve os dois como responsável em algum momento)\n";
} else {
    foreach ($opsReatribuidas as $opId) {
        $stmtOp = $db->prepare("
            SELECT o.id, o.etapa, o.veiculo_marca, o.veiculo_modelo, o.responsavel_id, o.consultor_tel_enviado_em,
                   c.nome AS cliente_nome, c.telefone
            FROM oportunidades o JOIN clientes c ON c.id = o.cliente_id WHERE o.id = ?
        ");
        $stmtOp->execute([$opId]);
        $op = $stmtOp->fetch(PDO::FETCH_ASSOC);

        echo "\nOportunidade #{$op['id']} [{$op['etapa']}] — {$op['cliente_nome']} ({$op['telefone']}) — {$op['veiculo_marca']} {$op['veiculo_modelo']}\n";
        echo "  responsável ATUAL: #{$op['responsavel_id']}\n";
        if ($op['consultor_tel_enviado_em']) {
            echo "  ⚠️  consultor_tel_enviado_em preenchido ({$op['consultor_tel_enviado_em']}) — cliente já recebeu o WhatsApp\n";
            echo "      PESSOAL de algum consultor; confirmar abaixo se foi ANTES ou DEPOIS da reatribuição.\n";
        }
        echo "  histórico de responsável:\n";
        $stmtHist = $db->prepare("
            SELECT h.created_at, h.etapa_anterior, h.etapa_nova, h.responsavel_id, h.observacao, u.nome AS resp_nome
            FROM oportunidade_historico h
            LEFT JOIN usuarios u ON u.id = h.responsavel_id
            WHERE h.oportunidade_id = ?
            ORDER BY h.id ASC
        ");
        $stmtHist->execute([$opId]);
        foreach ($stmtHist->fetchAll(PDO::FETCH_ASSOC) as $h) {
            $resp = $h['responsavel_id'] ? "#{$h['responsavel_id']} {$h['resp_nome']}" : '(sem responsável)';
            $obs = $h['observacao'] ? " — {$h['observacao']}" : '';
            echo "    {$h['created_at']}  {$h['etapa_anterior']}→{$h['etapa_nova']}  responsável: {$resp}{$obs}\n";
        }
    }
}

// ---------------------------------------------------------------------
// 1c. COMPRA — já fechada com fechado_por/responsavel_id cruzados
// ---------------------------------------------------------------------
echo "\n=== 3) COMPRA — fechada com fechado_por de um e responsável atual do outro ===\n";
$stmt = $db->prepare("
    SELECT o.id, o.veiculo_marca, o.veiculo_modelo, o.valor_final, o.data_compra, o.fechado_por, o.responsavel_id,
           c.nome AS cliente_nome, c.telefone
    FROM oportunidades o JOIN clientes c ON c.id = o.cliente_id
    WHERE o.etapa = 'fechado'
      AND ((o.fechado_por IN ($ph1) AND o.responsavel_id IN ($ph2))
        OR (o.fechado_por IN ($ph2) AND o.responsavel_id IN ($ph1)))
    ORDER BY o.data_compra DESC
");
$stmt->execute(array_merge($ids1, $ids2, $ids2, $ids1));
$fechCruzado = $stmt->fetchAll(PDO::FETCH_ASSOC);
if (!$fechCruzado) {
    echo "(nenhum)\n";
} else {
    foreach ($fechCruzado as $f) {
        echo "Oportunidade #{$f['id']} — {$f['cliente_nome']} ({$f['telefone']}) — {$f['veiculo_marca']} {$f['veiculo_modelo']}\n";
        echo "  fechado_por #{$f['fechado_por']}, responsável atual #{$f['responsavel_id']}, valor {$f['valor_final']}, data {$f['data_compra']}\n";
    }
}

// ---------------------------------------------------------------------
// 2a. VENDA (revenda) — mesmo comprador, 2 vendas diferentes
// ---------------------------------------------------------------------
echo "\n\n=== 4) VENDA (revenda) — mesmo telefone de comprador com negociação pra cada um ===\n";
$stmt = $db->prepare("
    SELECT v1.comprador_telefone, v1.comprador_nome AS nome1, v2.comprador_nome AS nome2,
           v1.id AS v1_id, v1.etapa AS v1_etapa, v1.responsavel_id AS v1_resp,
           v2.id AS v2_id, v2.etapa AS v2_etapa, v2.responsavel_id AS v2_resp
    FROM vendas v1
    JOIN vendas v2 ON v2.comprador_telefone = v1.comprador_telefone AND v2.id != v1.id
        AND v1.comprador_telefone != ''
    WHERE v1.responsavel_id IN ($ph1) AND v2.responsavel_id IN ($ph2)
    ORDER BY v1.comprador_telefone
");
$stmt->execute(array_merge($ids1, $ids2));
$achadosVenda = $stmt->fetchAll(PDO::FETCH_ASSOC);
if (!$achadosVenda) {
    echo "(nenhum)\n";
} else {
    foreach ($achadosVenda as $a) {
        echo "Comprador {$a['comprador_telefone']} ({$a['nome1']} / {$a['nome2']}):\n";
        echo "  · venda #{$a['v1_id']} [{$a['v1_etapa']}] — responsável #{$a['v1_resp']} ({$nomes1})\n";
        echo "  · venda #{$a['v2_id']} [{$a['v2_etapa']}] — responsável #{$a['v2_resp']} ({$nomes2})\n";
    }
}

// ---------------------------------------------------------------------
// 2b. VENDA — mesma negociação reatribuída entre os dois (histórico)
// ---------------------------------------------------------------------
echo "\n=== 5) VENDA (revenda) — mesma negociação com histórico passando pelos dois ===\n";
$stmt = $db->prepare("
    SELECT DISTINCT v.id
    FROM vendas v
    WHERE EXISTS (SELECT 1 FROM venda_historico h WHERE h.venda_id = v.id AND h.responsavel_id IN ($ph1))
      AND EXISTS (SELECT 1 FROM venda_historico h WHERE h.venda_id = v.id AND h.responsavel_id IN ($ph2))
    ORDER BY v.id
");
$stmt->execute(array_merge($ids1, $ids2));
$vendasReatribuidas = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'id');

if (!$vendasReatribuidas) {
    echo "(nenhuma)\n";
} else {
    foreach ($vendasReatribuidas as $vId) {
        $stmtV = $db->prepare("SELECT id, etapa, comprador_nome, comprador_telefone, responsavel_id FROM vendas WHERE id = ?");
        $stmtV->execute([$vId]);
        $v = $stmtV->fetch(PDO::FETCH_ASSOC);
        echo "\nVenda #{$v['id']} [{$v['etapa']}] — {$v['comprador_nome']} ({$v['comprador_telefone']})\n";
        echo "  responsável ATUAL: #{$v['responsavel_id']}\n";
        echo "  histórico de responsável:\n";
        $stmtHist = $db->prepare("
            SELECT h.created_at, h.etapa_anterior, h.etapa_nova, h.responsavel_id, h.observacao, u.nome AS resp_nome
            FROM venda_historico h LEFT JOIN usuarios u ON u.id = h.responsavel_id
            WHERE h.venda_id = ? ORDER BY h.id ASC
        ");
        $stmtHist->execute([$vId]);
        foreach ($stmtHist->fetchAll(PDO::FETCH_ASSOC) as $h) {
            $resp = $h['responsavel_id'] ? "#{$h['responsavel_id']} {$h['resp_nome']}" : '(sem responsável)';
            $obs = $h['observacao'] ? " — {$h['observacao']}" : '';
            echo "    {$h['created_at']}  {$h['etapa_anterior']}→{$h['etapa_nova']}  responsável: {$resp}{$obs}\n";
        }
    }
}

echo "\n\n=== Resumo ===\n";
$totalAchados = count($achados1) + count($opsReatribuidas) + count($fechCruzado) + count($achadosVenda) + count($vendasReatribuidas);
if ($totalAchados === 0) {
    echo "✅ Nada encontrado — nenhum cliente/lead parece ter caído duplicado pros dois.\n";
} else {
    echo "⚠️  {$totalAchados} situação(ões) encontrada(s) acima — revisar caso a caso.\n";
    echo "    Cliente com 2 veículos diferentes (seção 1/4) pode ser legítimo (regra #1 do\n";
    echo "    projeto: 1 cliente pode ter mais de 1 veículo em negociação). Reatribuição de\n";
    echo "    histórico (seção 2/5) com consultor_tel_enviado_em preenchido ANTES da troca\n";
    echo "    é o sinal mais forte de bug real — esse caso específico já tem trava desde\n";
    echo "    22/09/2026 (consultor_tel_enviado_em IS NULL nas queries de redistribuição).\n";
}
