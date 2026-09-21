<?php
/**
 * Diagnóstico pontual (só leitura, nunca apaga/altera nada) — 21/09/2026,
 * pedido direto ao investigar um print de dezenas de leads "(sem nome)"
 * parados em etapa='whatsapp', todos criados na mesma janela de minutos em
 * 20/09/2026. Investigado: números de telefone válidos (não é o mesmo
 * padrão do flood antigo, ver limpar_leads_invalidos.php), causa raiz
 * confirmada pelo usuário: followup/campanha de reengajamento manual
 * disparada pra leads antigos, que gerou uma leva de respostas reais de
 * volta ao mesmo tempo.
 *
 * Separa, dentro de etapa='whatsapp': quem já respondeu pelo menos 1
 * mensagem de verdade (vai seguir o funil normal, IA ainda vai pedir o
 * nome) de quem está genuinamente mudo desde a entrada (só esses vão cair
 * no fechamento automático de cron/leads_sem_resposta.php, 7 dias de
 * silêncio total).
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

$mudos = $total - $comResposta;

echo "Total em etapa='whatsapp': {$total}\n";
echo "Já responderam pelo menos 1 mensagem: {$comResposta}\n";
echo "Mudos desde a entrada (nunca responderam nada): {$mudos}\n\n";

echo "--- Os mudos, com há quanto tempo entraram (esses fecham sozinhos com 7+ dias, cron/leads_sem_resposta.php) ---\n";
$stmt = $db->query("
    SELECT o.id, c.nome, c.telefone, o.created_at,
           CAST(julianday('now','localtime') - julianday(o.created_at) AS INTEGER) AS dias_parado
    FROM oportunidades o
    JOIN clientes c ON c.id = o.cliente_id
    WHERE o.etapa = 'whatsapp'
      AND NOT EXISTS (
          SELECT 1 FROM whatsapp_mensagens m
          WHERE m.telefone = c.telefone AND m.direcao = 'in'
      )
    ORDER BY o.created_at ASC
");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $nome = $r['nome'] ?: '(sem nome)';
    echo "#{$r['id']}  {$nome}  {$r['telefone']}  entrou {$r['created_at']}  ({$r['dias_parado']} dias parado)\n";
}
