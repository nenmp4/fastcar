<?php
/**
 * cron/financeiro_relatorio_mensal.php — envia o DRE Gerencial do mês
 * anterior via WhatsApp (documento) + e-mail (anexo) — 19/09/2026,
 * "essa parte é legal" (mostrando o Envio Automático Mensal do
 * JurídicoSaaS, repo irmão). Portado de lá
 * (`cron/financeiro_relatorio_mensal.php`), mas manda o PDF do DRE (não
 * um "extrato" de lançamentos, que este projeto não tem) pela instância
 * Z-API DEDICADA do financeiro (`zapiCredenciaisFinanceiro()`, já criada
 * pro WhatsApp Box de cobrança), nunca a principal. Executar diariamente
 * — o script decide sozinho se hoje é o dia configurado de envio
 * (`config.financeiro_relatorio_dia`).
 *
 * Diferente do original (que salvava o PDF público pra Z-API baixar via
 * URL): `zapiEnviarDocumento()` já aceita data URI base64 direto — nunca
 * precisa escrever nada em `public/`.
 */
define('ROOT', dirname(__DIR__));
require_once ROOT . '/includes/db.php';
require_once ROOT . '/includes/security.php';
require_once ROOT . '/includes/whatsapp_config.php';
require_once ROOT . '/includes/mail.php';
require_once ROOT . '/includes/financeiro_dre.php';

$db = getDB();

function log_finrel(string $msg): void {
    $dir = ROOT . '/storage/logs';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $line = '[' . date('H:i:s') . '] ' . rtrim($msg, "\n") . "\n";
    file_put_contents($dir . '/financeiro_relatorio_' . date('Y-m') . '.log', $line, FILE_APPEND);
    echo $line;
}

log_finrel('Iniciando em ' . date('d/m/Y H:i'));

$diaConf = (int)(getConfig('financeiro_relatorio_dia') ?: 1);

$idsSelecionados = array_map('intval', array_filter(explode(',', getConfig('financeiro_relatorio_user_ids') ?: '')));
$emailsConf = array_filter(array_map('trim', explode(',', getConfig('financeiro_relatorio_emails_extra') ?: '')));
$wppsConf = array_filter(array_map('trim', explode(',', getConfig('financeiro_relatorio_whatsapp_extra') ?: '')));
// 19/09/2026, "permita castrar o e-mail do contador para encaminhar pelo
// e-mail automático todo mês - cadastro email do contatador fica top" —
// e-mail do contador cadastrado em Dados da Empresa (admin/financeiro-empresa.php)
// SEMPRE entra no envio, sem precisar digitar de novo em "E-mails extras"
// — `array_unique()` logo abaixo já evita duplicar se alguém cadastrar o
// mesmo endereço nos dois lugares.
$contadorEmail = trim((string)(getConfig('contador_email') ?: ''));
if ($contadorEmail) $emailsConf[] = $contadorEmail;
if ($idsSelecionados) {
    $placeholders = implode(',', array_fill(0, count($idsSelecionados), '?'));
    $stmtUsers = $db->prepare("SELECT email, whatsapp FROM usuarios WHERE id IN ({$placeholders})");
    $stmtUsers->execute($idsSelecionados);
    foreach ($stmtUsers->fetchAll(PDO::FETCH_ASSOC) as $u) {
        if (!empty($u['email'])) $emailsConf[] = trim($u['email']);
        if (!empty($u['whatsapp'])) $wppsConf[] = trim($u['whatsapp']);
    }
}
$emailsConf = array_values(array_unique(array_filter($emailsConf)));
$wppsConf = array_values(array_unique(array_filter($wppsConf)));

if (empty($emailsConf) && empty($wppsConf)) {
    log_finrel('Nenhum destinatário configurado. Encerrando.');
    exit(0);
}

$diaMes = (int)date('j');
if ($diaMes !== $diaConf) {
    log_finrel("Hoje não é dia de envio (configurado: dia {$diaConf}). Encerrando.");
    exit(0);
}

// ── Período: mês anterior completo ──────────────────────────────────────
$de = date('Y-m-01', strtotime('first day of last month'));
$ate = date('Y-m-t', strtotime('last day of last month'));

$meses = ['01' => 'Janeiro', '02' => 'Fevereiro', '03' => 'Março', '04' => 'Abril', '05' => 'Maio', '06' => 'Junho',
    '07' => 'Julho', '08' => 'Agosto', '09' => 'Setembro', '10' => 'Outubro', '11' => 'Novembro', '12' => 'Dezembro'];
[$anoMes, $mesMes] = explode('-', $de);
$mesLabel = ($meses[$mesMes] ?? $mesMes) . '/' . $anoMes;

log_finrel("Gerando DRE de {$de} a {$ate}...");

try {
    $pdf = finGerarDrePdf($db, $de, $ate);
    $pdfBase64 = base64_encode($pdf->Output('S'));
} catch (Throwable $e) {
    log_finrel('Erro ao gerar PDF: ' . $e->getMessage());
    exit(1);
}
$fileName = 'dre_gerencial_' . str_replace('/', '-', $mesLabel) . '.pdf';

// ── Envia via WhatsApp (documento, instância dedicada do financeiro) ────
$enviadosWpp = 0;
$credenciaisFin = zapiCredenciaisFinanceiro();
foreach ($wppsConf as $phone) {
    $ok = zapiEnviarDocumento($phone, 'data:application/pdf;base64,' . $pdfBase64, $fileName, 'pdf', $credenciaisFin);
    log_finrel("WhatsApp {$phone}: " . ($ok ? 'OK' : 'FALHOU'));
    if ($ok) $enviadosWpp++;
    if (count($wppsConf) > 1) sleep(2);
}

// ── Envia via e-mail (PDF anexado) ───────────────────────────────────────
$enviadosEmail = 0;
if (!empty($emailsConf)) {
    $assunto = "📊 DRE Gerencial — {$mesLabel} — Fastcar";
    $urlSistema = 'https://fastcar.solutions/admin/financeiro-relatorios.php';
    $corpoHtml = "
<!DOCTYPE html>
<html lang='pt-BR'>
<head><meta charset='UTF-8'></head>
<body style='margin:0;padding:0;background:#f1f5f9;font-family:-apple-system,BlinkMacSystemFont,\"Segoe UI\",sans-serif'>
  <table width='100%' cellpadding='0' cellspacing='0' style='background:#f1f5f9;padding:32px 0'>
    <tr><td align='center'>
      <table width='560' cellpadding='0' cellspacing='0' style='max-width:560px;width:100%;background:#ffffff;border-radius:12px;overflow:hidden'>
        <tr><td style='background:#151722;padding:28px 36px;text-align:center'>
          <p style='margin:0 0 4px;font-size:12px;color:rgba(255,255,255,.6);letter-spacing:.08em;text-transform:uppercase'>DRE Gerencial</p>
          <h1 style='margin:0;font-size:22px;font-weight:800;color:#ffffff'>Fastcar</h1>
          <p style='margin:10px 0 0;font-size:13px;color:rgba(255,255,255,.75);background:rgba(255,255,255,.1);display:inline-block;padding:4px 14px;border-radius:20px'>📅 {$mesLabel}</p>
        </td></tr>
        <tr><td style='padding:28px 36px;text-align:center'>
          <p style='margin:0 0 16px;font-size:13px;color:#64748b'>📎 O DRE Gerencial completo do período está anexado a este e-mail.</p>
          <a href='{$urlSistema}' style='display:inline-block;background:#2f6fed;color:#ffffff;text-decoration:none;font-weight:700;font-size:14px;padding:12px 28px;border-radius:8px'>Ver no Sistema</a>
        </td></tr>
        <tr><td style='background:#f8fafc;padding:18px 36px;text-align:center;border-top:1px solid #e2e8f0'>
          <p style='margin:0;font-size:12px;color:#94a3b8'>Enviado automaticamente pelo sistema Fastcar · Relatório gerencial, sem validação contábil oficial</p>
        </td></tr>
      </table>
    </td></tr>
  </table>
</body>
</html>";

    foreach ($emailsConf as $email) {
        $r = enviarEmail($email, $assunto, $corpoHtml, '', [['nome' => $fileName, 'conteudo_base64' => $pdfBase64, 'mime' => 'application/pdf']]);
        $ok = $r === true;
        log_finrel("E-mail {$email}: " . ($ok ? 'OK' : 'FALHOU — ' . ($r['erro'] ?? '')));
        if ($ok) $enviadosEmail++;
    }
}

log_finrel("Concluído. WhatsApps: {$enviadosWpp}/" . count($wppsConf) . " · E-mails: {$enviadosEmail}/" . count($emailsConf));
