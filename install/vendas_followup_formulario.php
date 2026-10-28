<?php
/**
 * Follow-up dos leads de VENDAS que ficaram sem resposta — 28/10/2026,
 * "350 leads parados na instância de vendas... chegaram na instância mas
 * tinha caído a conexão" / "mandar o link do formulário" / "sem muito
 * disparo".
 *
 * Fonte dos contatos (as 2 juntas, sem repetir telefone):
 *   1. Conversas que existem na instância Evolution de VENDAS
 *      (evolutionListarConversas()) — pega quem escreveu enquanto a
 *      conexão estava caída e nunca chegou no CRM.
 *   2. Leads de vendas no CRM em whatsapp/qualificacao_ia/crm_preenchido
 *      cuja última mensagem é do cliente (ninguém respondeu).
 *
 * Nunca manda pra: quem pediu SAIR (optout), negociação já em
 * negociação/contrato/vendida/cancelada/sem perfil, número de usuário do
 * sistema, conversa cuja última mensagem fomos nós, quem já recebeu este
 * follow-up antes (config.vendas_fu_form_{telefone}).
 *
 * Ritmo (pra não queimar o número): só seg–sáb das 10h às 18h, 1 mensagem a
 * cada 3–6 min sorteados, no máximo --max por dia (padrão 80). Mensagem
 * sorteada entre várias versões. Sempre pela instância Evolution de VENDAS,
 * nunca pelo número principal.
 *
 * Uso (na VPS):
 *   php install/vendas_followup_formulario.php                 # simulação: lista quem receberia
 *   nohup php install/vendas_followup_formulario.php --confirmar >> storage/logs/vendas_followup.log 2>&1 &
 * Opções: --dias=N (conversas dos últimos N dias, padrão 15) --max=N (por dia, padrão 80)
 * Para sozinho ao bater o limite do dia ou fechar o horário; rode de novo no
 * dia seguinte (quem já recebeu nunca recebe de novo).
 */

if (php_sapi_name() !== 'cli') { http_response_code(403); exit('Só pela linha de comando.'); }

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/whatsapp_evolution.php';
require_once __DIR__ . '/../includes/vendas_formulario.php';

ob_implicit_flush(true);
date_default_timezone_set('America/Sao_Paulo'); // horário comercial é o de Brasília, não o do servidor
$confirmar = in_array('--confirmar', $argv, true);
$dias = 15; $maxDia = 80; $esperaFixa = null;
foreach ($argv as $a) {
    if (preg_match('/^--dias=(\d+)$/', $a, $m)) $dias = max(1, (int)$m[1]);
    if (preg_match('/^--max=(\d+)$/', $a, $m)) $maxDia = max(1, min(200, (int)$m[1]));
    if (preg_match('/^--espera=(\d+)$/', $a, $m)) $esperaFixa = (int)$m[1]; // só pra teste
}
$log = fn(string $t) => print('[' . date('d/m H:i:s') . "] {$t}\n");

if (!evolutionConfiguredVendas()) { $log('❌ Instância Evolution de VENDAS não configurada (Configurações).'); exit(1); }
$cred = evolutionCredenciaisVendas();

$lock = fopen(dirname(__DIR__) . '/storage/vendas_followup.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { $log('⏭️ Já tem outra rodada deste script rodando — saindo.'); exit(0); }

$db = getDB();
$limiteTs = time() - $dias * 86400;

// ── Monta a lista ───────────────────────────────────────────────────────
$candidatos = []; // telefone => ['nome','origem']
$conversas = evolutionListarConversas($cred);
if ($conversas === null) {
    $log('⚠️ Não consegui listar as conversas da instância de vendas: ' . evolutionUltimoErro() . ' — sigo só com os leads do CRM.');
} else {
    $log('Instância de vendas: ' . count($conversas) . ' conversa(s) individuais.');
    if (!$confirmar && $conversas) $log('Exemplo cru (pra conferir o formato): ' . substr(json_encode($conversas[0]['raw'], JSON_UNESCAPED_UNICODE), 0, 400));
    foreach ($conversas as $c) {
        if ($c['atualizado_ts'] && $c['atualizado_ts'] < $limiteTs) continue;
        if ($c['ultima_de_mim'] === true) continue; // já respondemos por último
        $candidatos[$c['telefone']] = ['nome' => $c['nome'], 'origem' => 'instância'];
    }
}
$stCrm = $db->query("
    SELECT v.comprador_telefone AS tel, v.comprador_nome AS nome
    FROM vendas v
    WHERE v.etapa IN ('whatsapp', 'qualificacao_ia', 'crm_preenchido') AND COALESCE(v.optout_whatsapp, 0) = 0
      AND v.created_at >= datetime('now', 'localtime', '-{$dias} days')
      AND (SELECT m.direcao FROM whatsapp_mensagens m WHERE m.telefone = v.comprador_telefone ORDER BY m.id DESC LIMIT 1) = 'in'
");
foreach ($stCrm->fetchAll() as $r) {
    $tel = normalizarTelefone((string)$r['tel']);
    if (strlen($tel) < 12) continue;
    if (!isset($candidatos[$tel])) $candidatos[$tel] = ['nome' => (string)$r['nome'], 'origem' => 'CRM'];
    elseif ($candidatos[$tel]['nome'] === '') $candidatos[$tel]['nome'] = (string)$r['nome'];
}

// ── Filtros que nunca recebem ───────────────────────────────────────────
$staff = [];
foreach ($db->query("SELECT whatsapp FROM usuarios WHERE COALESCE(whatsapp,'') != ''") as $u) $staff[normalizarTelefone((string)$u['whatsapp'])] = true;
$stVenda = $db->prepare("SELECT etapa, COALESCE(optout_whatsapp,0) AS optout FROM vendas WHERE comprador_telefone = ? ORDER BY id DESC LIMIT 1");
$stCli = $db->prepare("SELECT COALESCE(optout_whatsapp,0) FROM clientes WHERE telefone = ?");
$stUltima = $db->prepare("SELECT direcao FROM whatsapp_mensagens WHERE telefone = ? ORDER BY id DESC LIMIT 1");
$fila = [];
$pulados = ['staff' => 0, 'ja_recebeu' => 0, 'sair' => 0, 'negociacao_andando' => 0, 'ja_respondido' => 0];
foreach ($candidatos as $tel => $c) {
    if (isset($staff[$tel])) { $pulados['staff']++; continue; }
    if (getConfig('vendas_fu_form_' . $tel)) { $pulados['ja_recebeu']++; continue; }
    $stVenda->execute([$tel]); $v = $stVenda->fetch();
    if ($v && (int)$v['optout'] === 1) { $pulados['sair']++; continue; }
    $stCli->execute([$tel]); if ((int)$stCli->fetchColumn() === 1) { $pulados['sair']++; continue; }
    if ($v && !in_array($v['etapa'], ['whatsapp', 'qualificacao_ia', 'crm_preenchido'], true)) { $pulados['negociacao_andando']++; continue; }
    $stUltima->execute([$tel]);
    if ($stUltima->fetchColumn() === 'out') { $pulados['ja_respondido']++; continue; }
    $fila[$tel] = $c;
}
$log(count($fila) . ' contato(s) pra receber o link do formulário. Pulados: ' . json_encode($pulados));

if (!$confirmar) {
    foreach ($fila as $tel => $c) echo "  {$tel}  " . ($c['nome'] ?: '(sem nome)') . "  [{$c['origem']}]\n";
    $log('Simulação — nada foi enviado. Rode com --confirmar pra começar (devagar, 10h–18h, até ' . $maxDia . '/dia).');
    exit(0);
}

// ── Envio devagar ───────────────────────────────────────────────────────
function vendasFuMensagem(string $nome): string {
    $p = trim(explode(' ', trim($nome))[0] ?? '');
    $oi = $p !== '' ? "Oi, {$p}! " : 'Oi! ';
    $link = vendaFormularioUrl(['utm_source' => 'whatsapp', 'utm_campaign' => 'followup_recuperacao']);
    return $oi . variarMensagem([
        "Desculpa a demora pra te responder, tivemos uma instabilidade aqui no WhatsApp. Ainda procurando carro? Pra eu te mostrar as opções na promissória, preenche aqui rapidinho (leva 1 minuto): {$link}",
        "Aqui é da Fastcar 🚗 Vi que você chamou a gente e ficou sem resposta, me desculpa! Pra separar os carros que cabem no seu bolso, me conta aqui em 1 minutinho: {$link}",
        "Tudo bem? Você falou com a Fastcar e nosso WhatsApp ficou fora do ar, desculpa! Ainda tem interesse? Preenche esse formulário rapidinho que um vendedor já te chama com as opções: {$link}",
        "Passando pra retomar nosso contato — tivemos um problema no WhatsApp e sua mensagem ficou sem resposta. Se ainda quiser ver os carros na promissória, é só preencher aqui (1 minuto): {$link}",
    ]);
}
function vendasFuDentroHorario(): bool {
    $h = (int)date('G'); $dow = (int)date('N');
    return $dow <= 6 && $h >= 10 && $h < 18;
}

$enviados = 0; $falhas = 0;
foreach ($fila as $tel => $c) {
    $chaveDia = 'vendas_fu_form_total_' . date('Y-m-d');
    if ((int)getConfig($chaveDia) >= $maxDia) { $log("⏸️ Limite de {$maxDia} por dia atingido — rode de novo amanhã."); break; }
    if (!vendasFuDentroHorario()) { $log('⏸️ Fora do horário (seg–sáb 10h–18h) — rode de novo no próximo horário comercial.'); break; }
    if (getConfig('vendas_fu_form_' . $tel)) continue; // outra rodada já mandou

    $msg = vendasFuMensagem($c['nome']);
    if (evolutionEnviarTexto($tel, $msg, $cred)) {
        setConfig('vendas_fu_form_' . $tel, date('Y-m-d H:i:s'));
        setConfig($chaveDia, (string)((int)getConfig($chaveDia) + 1));
        try { registrarMensagem($tel, 'out', $msg, null, true); } catch (Throwable $e) {}
        $enviados++;
        $log("✅ {$tel} " . ($c['nome'] ?: '') . " (#{$enviados} hoje: " . getConfig($chaveDia) . ')');
    } else {
        $falhas++;
        $log("❌ {$tel}: " . evolutionUltimoErro());
        if ($falhas >= 3 && $enviados === 0) { $log('⛔ 3 falhas sem nenhum envio — parando (confira se a instância de vendas está conectada).'); break; }
    }
    $espera = $esperaFixa ?? random_int(180, 360);
    sleep($espera);
}
$log("Fim da rodada: {$enviados} enviado(s), {$falhas} falha(s).");
