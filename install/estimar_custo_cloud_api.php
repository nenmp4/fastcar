<?php
/**
 * Estimativa de custo mensal se o número principal (compra/leads) migrar
 * do Z-API pra WhatsApp Cloud API oficial (24/09/2026, "Levanta ai" —
 * pedido do usuário depois de perguntar sobre custo real de migrar).
 * CLI, SÓ LEITURA — nunca escreve nada no banco.
 *
 * Rodar via SSH na VPS: php install/estimar_custo_cloud_api.php [--dias=30]
 *
 * O que faz: na Cloud API, mensagem dentro da janela de 24h desde a
 * última mensagem do cliente é GRÁTIS (texto livre); mensagem fora dessa
 * janela (a empresa inicia contato) precisa de template pago. Esse
 * script reconstrói, mensagem por mensagem, pra cada telefone, se cada
 * saída ('out') caiu dentro ou fora da janela de 24h da última entrada
 * ('in') daquele telefone — é exatamente a regra real da Cloud API,
 * simulada em cima do histórico já salvo em whatsapp_mensagens.
 *
 * Limitações honestas (regra #3, nunca inventa precisão que não tem):
 * - Só conta CLIENTE (whatsapp_mensagens, telefone = cliente) — alertas
 *   internos (atraso, lead quente, resumo diário pro supervisor) NUNCA
 *   são persistidos em nenhuma tabela (só zapiEnviarTexto() direto, sem
 *   registrarMensagem()), não tem histórico nenhum pra contar de verdade;
 *   ficam de fora da conta, citados só como nota no relatório.
 * - Proxy de código 2FA por WhatsApp: conta login com "Via: senha + código
 *   de verificação" na auditoria — mas esse texto não diz se o código foi
 *   por WhatsApp ou e-mail (os 2 canais possíveis), então é um TETO
 *   MÁXIMO (assume que todos foram por WhatsApp), nunca o número exato.
 * - Categoria Marketing x Utilidade pra mensagem "fora da janela" depende
 *   de como a Meta classificaria o texto de verdade — mostra os 2
 *   cenários (preço de Utilidade e de Marketing) como faixa, nunca finge
 *   saber qual vale.
 */

require_once __DIR__ . '/../includes/db.php';

$dias = 30;
foreach ($argv as $arg) {
    if (preg_match('/^--dias=(\d+)$/', $arg, $m)) $dias = max(1, (int)$m[1]);
}

$db = getDB();
$desde = date('Y-m-d H:i:s', strtotime("-{$dias} days"));

echo "📊 Estimativa de custo Cloud API — últimos {$dias} dias (desde {$desde})\n";
echo str_repeat('=', 70) . "\n\n";

// ── 1. Mensagens ao cliente: dentro x fora da janela de 24h ──
$stmt = $db->prepare("
    SELECT telefone, direcao, created_at
    FROM whatsapp_mensagens
    WHERE created_at >= ?
    ORDER BY telefone, created_at
");
$stmt->execute([$desde]);

$ultimaEntrada = []; // telefone => timestamp da última mensagem 'in'
$dentroJanela = 0;
$foraJanela = 0;
$telefonesForaJanela = [];

while ($linha = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $tel = $linha['telefone'];
    $ts = strtotime($linha['created_at']);

    if ($linha['direcao'] === 'in') {
        $ultimaEntrada[$tel] = $ts;
        continue;
    }

    // 'out' — decide se caiu dentro ou fora da janela de 24h
    if (isset($ultimaEntrada[$tel]) && ($ts - $ultimaEntrada[$tel]) <= 86400) {
        $dentroJanela++;
    } else {
        $foraJanela++;
        $telefonesForaJanela[$tel] = true;
    }
}

echo "💬 Mensagens enviadas AO CLIENTE (whatsapp_mensagens, direcao='out'):\n";
echo "   ✅ Dentro da janela de 24h (GRÁTIS na Cloud API): {$dentroJanela}\n";
echo "   💰 Fora da janela (precisaria de template pago): {$foraJanela}";
echo " (telefones distintos: " . count($telefonesForaJanela) . ")\n\n";

// ── 2. Proxy de código 2FA via WhatsApp (teto máximo, ver limitação acima) ──
$temAuditoria = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='auditoria'")->fetchColumn();
$proxy2fa = 0;
if ($temAuditoria) {
    $stmt = $db->prepare("
        SELECT COUNT(*) FROM auditoria
        WHERE evento = 'login' AND created_at >= ? AND detalhe LIKE '%código de verificação%'
    ");
    $stmt->execute([$desde]);
    $proxy2fa = (int)$stmt->fetchColumn();
}
echo "🔐 Logins com código de verificação enviado (WhatsApp OU e-mail — TETO MÁXIMO, não distingue canal): {$proxy2fa}\n\n";

// ── 3. Projeção mensal (caso o período não seja exatamente 30 dias) ──
$fator = 30 / $dias;
$foraJanelaMes = round($foraJanela * $fator);
$proxy2faMes = round($proxy2fa * $fator);

echo str_repeat('-', 70) . "\n";
echo "📅 Projeção pra 30 dias (fator {$fator}x sobre o período coletado):\n";
echo "   Mensagens fora da janela: ~{$foraJanelaMes}/mês\n";
echo "   Códigos 2FA (teto máximo): ~{$proxy2faMes}/mês\n\n";

// ── 4. Custo estimado (preços Cloud API direto, sem BSP, BRL/mensagem) ──
$precoUtilidade = 0.035;
$precoAutenticacao = 0.035;
$precoMarketing = 0.3217;

$custoForaUtilidade = $foraJanelaMes * $precoUtilidade;
$custoForaMarketing = $foraJanelaMes * $precoMarketing;
$custo2fa = $proxy2faMes * $precoAutenticacao;

echo "💵 Custo mensal estimado:\n";
echo "   Mensagens fora da janela, se Utilidade (R$0,035/msg): R$ " . number_format($custoForaUtilidade, 2, ',', '.') . "\n";
echo "   Mensagens fora da janela, se Marketing (R$0,3217/msg): R$ " . number_format($custoForaMarketing, 2, ',', '.') . "\n";
echo "   Códigos 2FA — Autenticação (R$0,035/msg): R$ " . number_format($custo2fa, 2, ',', '.') . "\n";
echo "   ────────────────────────────────────────\n";
echo "   TOTAL (cenário Utilidade): R$ " . number_format($custoForaUtilidade + $custo2fa, 2, ',', '.') . "/mês\n";
echo "   TOTAL (cenário Marketing): R$ " . number_format($custoForaMarketing + $custo2fa, 2, ',', '.') . "/mês\n\n";

echo str_repeat('=', 70) . "\n";
echo "⚠️  Não incluído nessa conta (sem histórico persistido pra contar):\n";
echo "   - Alertas de atraso/lead quente pro consultor (cron/followup.php)\n";
echo "   - Resumo diário pro supervisor (cron/resumo_produtividade.php)\n";
echo "   Volume baixo (uso interno, poucos destinatários) — tende a\n";
echo "   adicionar só alguns reais/mês, não muda a ordem de grandeza.\n";
