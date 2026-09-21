<?php
/**
 * Diagnóstico pontual (só leitura, nunca apaga/altera nada) — 21/09/2026,
 * pedido direto ao investigar um print de dezenas de leads "(sem nome)"
 * parados em etapa='whatsapp', todos criados na mesma janela de minutos em
 * 20/09/2026. Investigado: números de telefone válidos (não é o mesmo
 * padrão do flood antigo, ver limpar_leads_invalidos.php), causa raiz
 * confirmada: leva de leads criados por cron/recuperacao_leads.php (ver
 * bullet no CLAUDE.md) — não uma campanha manual.
 *
 * Separa, dentro de etapa='whatsapp', em 3 grupos:
 * 1. Já respondeu pelo menos 1 mensagem real — segue o funil normal.
 * 2. "Mudo mas contatado" — recebeu ao menos 1 mensagem NOSSA ('out',
 *    ex: a mensagem de reengajamento) mas ainda não respondeu nada.
 *    Comportamento esperado, só espera; fecha sozinho com 7+ dias
 *    (cron/leads_sem_resposta.php).
 * 3. "ÓRFÃO" — zero mensagens registradas, nem 'in' nem 'out'. Esse é o
 *    sinal do bug achado em recuperacaoProcessarLote()
 *    (includes/recuperacao_leads.php): a oportunidade é criada ANTES de
 *    checar se o envio deu certo, então se zapiEnviarTexto() falhar, o
 *    telefone já vira "cliente cadastrado" e nunca mais é reprocessado —
 *    fica pra sempre sem nenhuma mensagem, nem recebida nem enviada.
 *
 * Uso:
 *   php install/diagnosticar_leads_mudos.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Este script só roda via linha de comando (CLI).\n");
}

require_once __DIR__ . '/../includes/db.php';
$db = getDB();

$total = (int)$db->query("SELECT COUNT(*) FROM oportunidades WHERE etapa='whatsapp'")->fetchColumn();

$comResposta = (int)$db->query("
    SELECT COUNT(DISTINCT o.id)
    FROM oportunidades o
    JOIN clientes c ON c.id = o.cliente_id
    JOIN whatsapp_mensagens m ON m.telefone = c.telefone AND m.direcao = 'in'
    WHERE o.etapa = 'whatsapp'
")->fetchColumn();

$orfaos = (int)$db->query("
    SELECT COUNT(*)
    FROM oportunidades o
    JOIN clientes c ON c.id = o.cliente_id
    WHERE o.etapa = 'whatsapp'
      AND NOT EXISTS (SELECT 1 FROM whatsapp_mensagens m WHERE m.telefone = c.telefone)
")->fetchColumn();

$mudos = $total - $comResposta;
$mudoContatado = $mudos - $orfaos;

echo "Total em etapa='whatsapp': {$total}\n";
echo "Já responderam pelo menos 1 mensagem: {$comResposta}\n";
echo "Mudos (nunca responderam): {$mudos}\n";
echo "  ├─ receberam mensagem nossa, só não responderam ainda (normal, esperando): {$mudoContatado}\n";
echo "  └─ ÓRFÃOS — zero mensagens registradas, nem enviada nem recebida (bug): {$orfaos}\n\n";

echo "--- Os ÓRFÃOS (provável bug de recuperacaoProcessarLote()) ---\n";
$stmt = $db->query("
    SELECT o.id, c.nome, c.telefone, o.created_at,
           CAST(julianday('now','localtime') - julianday(o.created_at) AS INTEGER) AS dias_parado
    FROM oportunidades o
    JOIN clientes c ON c.id = o.cliente_id
    WHERE o.etapa = 'whatsapp'
      AND NOT EXISTS (SELECT 1 FROM whatsapp_mensagens m WHERE m.telefone = c.telefone)
    ORDER BY o.created_at ASC
");
$linhasOrfaos = $stmt->fetchAll(PDO::FETCH_ASSOC);
if (!$linhasOrfaos) {
    echo "(nenhum — ótimo sinal, não é isso)\n";
}
foreach ($linhasOrfaos as $r) {
    $nome = $r['nome'] ?: '(sem nome)';
    echo "#{$r['id']}  {$nome}  {$r['telefone']}  entrou {$r['created_at']}  ({$r['dias_parado']} dias parado)\n";
}

echo "\n--- Mudos mas CONTATADOS (receberam mensagem, aguardando resposta — normal) ---\n";
$stmt2 = $db->query("
    SELECT o.id, c.nome, c.telefone, o.created_at,
           CAST(julianday('now','localtime') - julianday(o.created_at) AS INTEGER) AS dias_parado
    FROM oportunidades o
    JOIN clientes c ON c.id = o.cliente_id
    WHERE o.etapa = 'whatsapp'
      AND EXISTS (SELECT 1 FROM whatsapp_mensagens m WHERE m.telefone = c.telefone AND m.direcao = 'out')
      AND NOT EXISTS (SELECT 1 FROM whatsapp_mensagens m WHERE m.telefone = c.telefone AND m.direcao = 'in')
    ORDER BY o.created_at ASC
");
foreach ($stmt2->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $nome = $r['nome'] ?: '(sem nome)';
    echo "#{$r['id']}  {$nome}  {$r['telefone']}  entrou {$r['created_at']}  ({$r['dias_parado']} dias parado)\n";
}
