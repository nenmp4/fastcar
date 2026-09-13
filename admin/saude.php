<?php
/**
 * Saúde do sistema — mesmo padrão do JurídicoSaaS (admin/saude.php), mas
 * remapeado pros subsistemas de verdade do Fastcar (nada de AASP/DataJud/
 * Google Calendar/ferramentas jurídicas — ver "O que NÃO reaproveitar" no
 * CLAUDE.md). Cada grupo de checks aqui é um pedaço real do projeto:
 * banco, servidor, Z-API, IA, Drive, Assinafy, e-mail, backup, crons, fila.
 *
 * Restrito ao super_admin — mesma trava de configuracoes.php/backup.php.
 */

set_time_limit(30); // mesma cautela do JurídicoSaaS — todo check HTTP abaixo tem timeout curto
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../includes/backup.php';
requireSuperAdmin();

$db = getDB();
$checks = [];

function check(string $grupo, string $nome, string $status, string $valor, string $detalhe = ''): void {
    global $checks;
    $checks[] = compact('grupo', 'nome', 'status', 'valor', 'detalhe');
}

function tempoAtras(int $timestamp): string {
    $seg = time() - $timestamp;
    if ($seg < 60) return "{$seg}s atrás";
    if ($seg < 3600) return round($seg / 60) . 'min atrás';
    if ($seg < 86400) return round($seg / 3600, 1) . 'h atrás';
    return round($seg / 86400, 1) . 'd atrás';
}

// ── 1. BANCO DE DADOS ────────────────────────────────────────────────────
try {
    $db->query('SELECT 1');
    $dbMB = file_exists(DB_PATH) ? round(filesize(DB_PATH) / 1024 / 1024, 2) : 0;
    check('Banco de Dados', 'Conexão SQLite', 'ok', 'Conectado', "Tamanho: {$dbMB} MB");

    $nClientes = (int)$db->query("SELECT COUNT(*) FROM clientes")->fetchColumn();
    check('Banco de Dados', 'Clientes cadastrados', 'info', number_format($nClientes, 0, ',', '.'), '');

    $phAtivas = implode(',', array_fill(0, count(ETAPAS_ATIVAS), '?'));
    $stmt = $db->prepare("SELECT COUNT(*) FROM oportunidades WHERE etapa IN ({$phAtivas})");
    $stmt->execute(ETAPAS_ATIVAS);
    $nAtivas = (int)$stmt->fetchColumn();
    check('Banco de Dados', 'Oportunidades ativas', 'info', number_format($nAtivas, 0, ',', '.'), '');

    $novasHoje = (int)$db->query("SELECT COUNT(*) FROM oportunidades WHERE date(created_at) = date('now','localtime')")->fetchColumn();
    check('Banco de Dados', 'Oportunidades novas hoje', $novasHoje > 0 ? 'ok' : 'info', (string)$novasHoje, '');
} catch (Throwable $e) {
    check('Banco de Dados', 'Conexão SQLite', 'error', 'FALHOU', $e->getMessage());
}

// ── 2. SERVIDOR ──────────────────────────────────────────────────────────
$basePath  = dirname(__DIR__);
$diskTotal = disk_total_space($basePath);
$diskFree  = disk_free_space($basePath);
$totalGB   = $diskTotal ? round($diskTotal / 1024 / 1024 / 1024, 1) : 0;
$freeGB    = $diskFree  ? round($diskFree  / 1024 / 1024 / 1024, 1) : 0;
$pct       = $diskTotal ? round((1 - $diskFree / $diskTotal) * 100, 1) : 0;
$logMB     = 0;
foreach (glob($basePath . '/storage/logs/*.log') ?: [] as $lf) $logMB += filesize($lf);
$logMB = round($logMB / 1024 / 1024, 1);

check('Servidor', 'Disco do servidor', $pct > 90 ? 'error' : ($pct > 80 ? 'warn' : 'ok'), "{$pct}% usado", "{$freeGB} GB livres de {$totalGB} GB");
check('Servidor', 'Logs armazenados', $logMB > 50 ? 'warn' : 'ok', "{$logMB} MB", 'storage/logs/');
check('Servidor', 'Versão PHP', 'info', PHP_VERSION, '');
foreach (['pdo_sqlite', 'curl', 'openssl', 'gd', 'zip'] as $ext) {
    check('Servidor', "Extensão {$ext}", extension_loaded($ext) ? 'ok' : 'error', extension_loaded($ext) ? 'Disponível' : 'AUSENTE', '');
}
check('Servidor', 'storage/ gravável', is_writable($basePath . '/storage') ? 'ok' : 'error', is_writable($basePath . '/storage') ? 'Gravável' : 'SEM PERMISSÃO', '');
check('Servidor', 'database/ gravável', is_writable($basePath . '/database') ? 'ok' : 'error', is_writable($basePath . '/database') ? 'Gravável' : 'SEM PERMISSÃO', '');

// ── 3. WHATSAPP Z-API (instância principal) ──────────────────────────────
$zapiInst = getConfig('zapi_instance_id') ?: '';
$zapiTok  = getConfig('zapi_token') ?: '';
$zapiCli  = getConfig('zapi_client_token') ?: '';
if (!$zapiInst || !$zapiTok) {
    check('WhatsApp (Z-API)', 'Instância principal', 'warn', 'Não configurada', 'Configurações → Z-API principal');
} else {
    check('WhatsApp (Z-API)', 'Instância principal', 'ok', 'Configurada', "ID: " . substr($zapiInst, 0, 8) . '...');
}

try {
    $ultimaMsg = $db->query("SELECT direcao, created_at FROM whatsapp_mensagens ORDER BY created_at DESC LIMIT 1")->fetch();
    if ($ultimaMsg) {
        check('WhatsApp (Z-API)', 'Última mensagem', 'info', tempoAtras(strtotime($ultimaMsg['created_at'])), $ultimaMsg['direcao'] === 'in' ? 'Recebida' : 'Enviada');
    } else {
        check('WhatsApp (Z-API)', 'Última mensagem', 'info', 'Nenhuma ainda', '');
    }
    $in24h  = (int)$db->query("SELECT COUNT(*) FROM whatsapp_mensagens WHERE direcao='in'  AND created_at >= datetime('now','-24 hours','localtime')")->fetchColumn();
    $out24h = (int)$db->query("SELECT COUNT(*) FROM whatsapp_mensagens WHERE direcao='out' AND created_at >= datetime('now','-24 hours','localtime')")->fetchColumn();
    check('WhatsApp (Z-API)', 'Mensagens (24h)', ($in24h + $out24h) > 0 ? 'ok' : 'info', ($in24h + $out24h) . ' mensagens', "↓ recebidas: {$in24h} · ↑ enviadas: {$out24h}");
} catch (Throwable $e) {
    check('WhatsApp (Z-API)', 'Mensagens', 'warn', 'Erro ao consultar', $e->getMessage());
}

try {
    $instancias = $db->query("SELECT COUNT(*) AS total, SUM(ativo) AS ativas FROM zapi_instancias_consultores")->fetch();
    check('WhatsApp (Z-API)', 'Instâncias de consultores', 'info', (int)($instancias['ativas'] ?? 0) . ' ativa(s)', (int)($instancias['total'] ?? 0) . ' cadastrada(s) no total');
} catch (Throwable $e) {}

// ── 4. IA (Gemini + fallback OpenAI) ─────────────────────────────────────
$geminiKey = getConfig('gemini_api_key') ?: '';
$openaiKey = getConfig('openai_api_key') ?: '';
check('IA (Gemini/OpenAI)', 'Modelo Gemini', 'info', getConfig('gemini_model') ?: 'gemini-2.5-flash-lite', 'principal');
check('IA (Gemini/OpenAI)', 'Modelo OpenAI', 'info', getConfig('openai_model') ?: 'gpt-4o-mini', 'fallback');
if (!$geminiKey) check('IA (Gemini/OpenAI)', 'Gemini API Key', 'warn', 'Não configurada', 'Configurações → IA');
if (!$openaiKey) check('IA (Gemini/OpenAI)', 'OpenAI API Key', 'warn', 'Não configurada (fallback ficaria indisponível)', 'Configurações → IA');

// Custo/uso de tokens Gemini — reaproveita o registro que geminiRegistrarTokens()
// já grava em config (gemini_tokens_YYYY-MM-DD), mesmo padrão do JurídicoSaaS.
try {
    $chaveHoje = 'gemini_tokens_' . date('Y-m-d');
    $hoje = json_decode(getConfig($chaveHoje) ?? '{}', true) ?: [];
    $primeiroDia = date('Y-m-01');
    $stmt = $db->query("SELECT chave, valor FROM config WHERE chave LIKE 'gemini_tokens_%' ORDER BY chave DESC LIMIT 31");
    $mesIn = 0; $mesOut = 0; $mesChamadas = 0;
    foreach ($stmt->fetchAll() as $r) {
        $dia = str_replace('gemini_tokens_', '', $r['chave']);
        if ($dia < $primeiroDia) continue;
        $d = json_decode($r['valor'], true) ?: [];
        $mesIn += $d['in'] ?? 0; $mesOut += $d['out'] ?? 0; $mesChamadas += $d['calls'] ?? 0;
    }
    $chamadasHoje = $hoje['calls'] ?? 0;
    check('IA (Gemini/OpenAI)', 'Tokens hoje', $chamadasHoje > 0 ? 'ok' : 'info',
        number_format(($hoje['in'] ?? 0) + ($hoje['out'] ?? 0), 0, ',', '.') . ' tokens',
        "{$chamadasHoje} chamada(s)");
    check('IA (Gemini/OpenAI)', 'Tokens no mês', 'info', number_format($mesIn + $mesOut, 0, ',', '.') . ' tokens', "{$mesChamadas} chamada(s)");
} catch (Throwable $e) {
    check('IA (Gemini/OpenAI)', 'Tokens', 'info', 'Sem dados ainda', '');
}

// ── 5. GOOGLE DRIVE ───────────────────────────────────────────────────────
$drive = new GoogleDrive();
if (!$drive->hasCredentials()) {
    check('Google Drive', 'Credenciais', 'warn', 'Ausentes', 'config/google_drive_credentials.json (drop manual via SSH)');
} else {
    check('Google Drive', 'Credenciais', 'ok', $drive->getCredentialEmail(), 'config/google_drive_credentials.json');
    try {
        $auth = $drive->authenticate();
        check('Google Drive', 'Autenticação (JWT)', $auth ? 'ok' : 'error', $auth ? 'Token obtido' : 'Falhou', $auth ? '' : 'Verifique a service account e a API habilitada no Google Cloud');
    } catch (Throwable $e) {
        check('Google Drive', 'Autenticação (JWT)', 'error', 'Erro', $e->getMessage());
    }
}
try {
    $nDocsDrive = (int)$db->query("SELECT COUNT(*) FROM oportunidade_documentos WHERE drive_file_id IS NOT NULL AND drive_file_id != ''")->fetchColumn();
    $nClientesComPasta = (int)$db->query("SELECT COUNT(*) FROM clientes WHERE drive_folder_id IS NOT NULL AND drive_folder_id != ''")->fetchColumn();
    check('Google Drive', 'Documentos no Drive', 'info', "{$nDocsDrive} arquivo(s)", "{$nClientesComPasta} cliente(s) com pasta criada");
} catch (Throwable $e) {}

// ── 6. ASSINAFY ───────────────────────────────────────────────────────────
$assinafyKey = getConfig('assinafy_api_key') ?: '';
$assinafyAcc = getConfig('assinafy_account_id') ?: '';
if (!$assinafyKey || !$assinafyAcc) {
    check('Assinafy', 'Configuração', 'warn', 'Não configurada', 'Configurações → Assinafy');
} else {
    check('Assinafy', 'Configuração', 'ok', 'Configurada', "Account: " . substr($assinafyAcc, 0, 8) . '...');
    try {
        $r = assinafyRequest('GET', '/accounts/{account_id}/documents?limit=1');
        if ($r['code'] === 200) check('Assinafy', 'Conectividade', 'ok', 'Conectado', '');
        elseif (in_array($r['code'], [401, 403], true)) check('Assinafy', 'Conectividade', 'error', 'Chave inválida', '');
        else check('Assinafy', 'Conectividade', 'warn', "HTTP {$r['code']}", '');
    } catch (Throwable $e) {
        check('Assinafy', 'Conectividade', 'error', 'Erro de conexão', $e->getMessage());
    }
}
try {
    $porStatus = $db->query("SELECT status, COUNT(*) AS n FROM contratos GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
    if ($porStatus) {
        $partes = [];
        foreach ($porStatus as $st => $n) $partes[] = "{$st}: {$n}";
        check('Assinafy', 'Contratos', 'info', array_sum($porStatus) . ' no total', implode(' · ', $partes));
    } else {
        check('Assinafy', 'Contratos', 'info', 'Nenhum gerado ainda', '');
    }
} catch (Throwable $e) {}

// ── 7. E-MAIL (BREVO) ─────────────────────────────────────────────────────
$brevoKey = getConfig('brevo_api_key') ?: '';
if (!$brevoKey) {
    check('E-mail (Brevo)', 'API Key', 'warn', 'Não configurada', 'Configurações → E-mail (Brevo)');
} else {
    check('E-mail (Brevo)', 'API Key', 'ok', substr($brevoKey, 0, 10) . '...' . substr($brevoKey, -4), 'Configurada');
}

// ── 8. BACKUP ─────────────────────────────────────────────────────────────
$locaisBanco = glob(BACKUP_DIR . '/backup_db_*.db') ?: [];
if ($locaisBanco) {
    usort($locaisBanco, fn($a, $b) => filemtime($b) - filemtime($a));
    $dias = round((time() - filemtime($locaisBanco[0])) / 86400, 1);
    check('Backup', 'Banco (local)', $dias <= 1 ? 'ok' : ($dias <= 3 ? 'warn' : 'error'), date('d/m/Y H:i', filemtime($locaisBanco[0])), tempoAtras(filemtime($locaisBanco[0])));
} else {
    check('Backup', 'Banco (local)', 'warn', 'Nenhum backup ainda', 'cron/backup_db.php ou botão manual em Backup');
}
$locaisZip = glob(BACKUP_DIR . '/backup_completo_*.zip') ?: [];
if ($locaisZip) {
    usort($locaisZip, fn($a, $b) => filemtime($b) - filemtime($a));
    $dias = round((time() - filemtime($locaisZip[0])) / 86400, 1);
    check('Backup', 'Completo (local)', $dias <= 1 ? 'ok' : ($dias <= 3 ? 'warn' : 'error'), date('d/m/Y H:i', filemtime($locaisZip[0])), tempoAtras(filemtime($locaisZip[0])));
} else {
    check('Backup', 'Completo (local)', 'warn', 'Nenhum backup ainda', 'cron/backup.php ou botão manual em Backup');
}
$driveUltimo = getConfig('drive_backup_ultimo');
if (!$drive->hasCredentials()) {
    check('Backup', 'Envio ao Drive', 'warn', 'Drive não configurado', '');
} elseif (getConfig('drive_backup_ativo') === '0') {
    check('Backup', 'Envio ao Drive', 'warn', 'Desativado', 'Configurações → Backup automático');
} elseif ($driveUltimo) {
    $ts = strtotime($driveUltimo);
    $dias = round((time() - $ts) / 86400, 1);
    check('Backup', 'Envio ao Drive', $dias <= 1 ? 'ok' : ($dias <= 3 ? 'warn' : 'error'), date('d/m/Y H:i', $ts), tempoAtras($ts));
} else {
    check('Backup', 'Envio ao Drive', 'warn', 'Nunca executado', 'cron/backup_drive.php ou botão manual em Backup');
}

// ── 9. CRONS (frescor do log) ─────────────────────────────────────────────
$cronsEsperados = [
    'Follow-up'         => ['storage/logs/followup.log', 40],
    'Assinafy sync'      => ['storage/logs/assinafy_sync.log', 10],
    'Backup do banco'    => ['storage/logs/backup_db.log', 8 * 60],
    'Backup completo'    => ['storage/logs/backup.log', 26 * 60],
    'Backup Drive'       => ['storage/logs/backup_drive.log', 26 * 60],
];
foreach ($cronsEsperados as $nome => [$rel, $maxGapMin]) {
    $caminho = $basePath . '/' . $rel;
    if (!file_exists($caminho)) {
        check('Crons', $nome, 'warn', 'Sem log ainda', "Confirme o crontab: install/setup_crontab.sh — {$rel}");
        continue;
    }
    $gapMin = round((time() - filemtime($caminho)) / 60);
    $st = $gapMin <= $maxGapMin ? 'ok' : ($gapMin <= $maxGapMin * 3 ? 'warn' : 'error');
    check('Crons', $nome, $st, date('d/m H:i', filemtime($caminho)), tempoAtras(filemtime($caminho)));
}
$deployLog = $basePath . '/storage/logs/deploy_' . date('Y-m') . '.log';
if (file_exists($deployLog)) {
    check('Crons', 'Deploy automático', 'info', date('d/m H:i', filemtime($deployLog)), tempoAtras(filemtime($deployLog)) . ' (último push aplicado)');
} else {
    check('Crons', 'Deploy automático', 'info', 'Sem deploy via webhook ainda', 'Só aparece depois do 1º push com o webhook configurado');
}

// ── 10. FILA DE LEADS ──────────────────────────────────────────────────────
try {
    $disponiveis = (int)$db->query("SELECT COUNT(*) FROM usuarios WHERE disponivel = 1 AND bloqueado = 0")->fetchColumn();
    $plantao = (int)$db->query("SELECT COUNT(*) FROM usuarios WHERE plantao_fim_expediente = 1 AND bloqueado = 0")->fetchColumn();
    if ($disponiveis > 0) {
        check('Fila de leads', 'Consultores disponíveis', 'ok', (string)$disponiveis, 'Recebendo lead normalmente');
    } elseif ($plantao > 0) {
        check('Fila de leads', 'Consultores disponíveis', 'warn', '0 disponíveis', "{$plantao} em plantão — leads caem no plantão");
    } else {
        check('Fila de leads', 'Consultores disponíveis', 'error', '0 disponíveis, 0 em plantão', 'NINGUÉM recebe lead novo agora');
    }
} catch (Throwable $e) {
    check('Fila de leads', 'Consultores disponíveis', 'warn', 'Erro ao consultar', $e->getMessage());
}

// ── 11. CHAMADAS HTTP REAIS EM PARALELO (timeout curto) ───────────────────
$mh = curl_multi_init();
$reqs = [];
$addReq = function (string $key, string $url, array $opts = []) use (&$mh, &$reqs) {
    $ch = curl_init($url);
    curl_setopt_array($ch, array_replace([CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6], $opts));
    curl_multi_add_handle($mh, $ch);
    $reqs[$key] = $ch;
};

if ($zapiInst && $zapiTok) {
    $headers = ['Content-Type: application/json'];
    if ($zapiCli) $headers[] = 'client-token: ' . $zapiCli;
    $addReq('zapi', zapiBaseUrl() . "/instances/{$zapiInst}/token/{$zapiTok}/status", [CURLOPT_HTTPHEADER => $headers]);
}
if ($geminiKey) {
    $addReq('gemini', geminiBaseUrl() . '/models?key=' . $geminiKey);
}
if ($openaiKey) {
    $addReq('openai', openaiBaseUrl() . '/models', [CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $openaiKey]]);
}
if ($brevoKey) {
    $addReq('brevo', mailBaseUrl() . '/account', [CURLOPT_HTTPHEADER => ['api-key: ' . $brevoKey, 'Accept: application/json']]);
}

$running = null;
do { curl_multi_exec($mh, $running); if ($running) curl_multi_select($mh, 0.3); } while ($running > 0);
$resultados = [];
foreach ($reqs as $key => $ch) {
    $resultados[$key] = ['resp' => curl_multi_getcontent($ch), 'code' => curl_getinfo($ch, CURLINFO_HTTP_CODE), 'err' => curl_error($ch)];
    curl_multi_remove_handle($mh, $ch);
    curl_close($ch);
}
curl_multi_close($mh);

if (isset($resultados['zapi'])) {
    $r = $resultados['zapi'];
    $d = json_decode($r['resp'] ?: '{}', true);
    if ($r['err']) check('WhatsApp (Z-API)', 'Status da instância', 'error', 'Erro de conexão', $r['err']);
    elseif ($r['code'] === 200 && !empty($d['connected'])) check('WhatsApp (Z-API)', 'Status da instância', 'ok', 'Conectado', !empty($d['smartphoneConnected']) ? 'Smartphone vinculado' : '');
    elseif ($r['code'] === 200) check('WhatsApp (Z-API)', 'Status da instância', 'error', 'Desconectado', 'Reconecte no painel Z-API (QR Code)');
    else check('WhatsApp (Z-API)', 'Status da instância', 'warn', "HTTP {$r['code']}", '');
}
if (isset($resultados['gemini'])) {
    $r = $resultados['gemini'];
    if ($r['err']) check('IA (Gemini/OpenAI)', 'Gemini — conectividade', 'error', 'Erro de conexão', $r['err']);
    elseif ($r['code'] === 200) check('IA (Gemini/OpenAI)', 'Gemini — conectividade', 'ok', 'Conectado', '');
    elseif (in_array($r['code'], [401, 403], true)) check('IA (Gemini/OpenAI)', 'Gemini — conectividade', 'error', 'Chave inválida', '');
    elseif ($r['code'] === 429) check('IA (Gemini/OpenAI)', 'Gemini — conectividade', 'warn', 'Cota excedida (429)', '');
    else check('IA (Gemini/OpenAI)', 'Gemini — conectividade', 'warn', "HTTP {$r['code']}", '');
}
if (isset($resultados['openai'])) {
    $r = $resultados['openai'];
    if ($r['err']) check('IA (Gemini/OpenAI)', 'OpenAI — conectividade', 'error', 'Erro de conexão', $r['err']);
    elseif ($r['code'] === 200) check('IA (Gemini/OpenAI)', 'OpenAI — conectividade', 'ok', 'Conectado', '');
    elseif ($r['code'] === 401) check('IA (Gemini/OpenAI)', 'OpenAI — conectividade', 'error', 'Chave inválida', '');
    else check('IA (Gemini/OpenAI)', 'OpenAI — conectividade', 'warn', "HTTP {$r['code']}", '');
}
if (isset($resultados['brevo'])) {
    $r = $resultados['brevo'];
    $d = json_decode($r['resp'] ?: '{}', true);
    if ($r['err']) check('E-mail (Brevo)', 'Conectividade', 'error', 'Erro de conexão', $r['err']);
    elseif ($r['code'] === 200) check('E-mail (Brevo)', 'Conectividade', 'ok', $d['email'] ?? 'Conectado', isset($d['plan'][0]['credits']) ? "{$d['plan'][0]['credits']} créditos" : '');
    elseif ($r['code'] === 401) check('E-mail (Brevo)', 'Conectividade', 'error', 'Chave inválida', '');
    else check('E-mail (Brevo)', 'Conectividade', 'warn', "HTTP {$r['code']}", '');
}

// ── 12. ERROS RECENTES ────────────────────────────────────────────────────
$errorLogPath = ini_get('error_log');
if ($errorLogPath && file_exists($errorLogPath) && is_readable($errorLogPath)) {
    $tamanho = filesize($errorLogPath);
    $fp = fopen($errorLogPath, 'r');
    fseek($fp, -min($tamanho, 8000), SEEK_END);
    $chunk = fread($fp, 8000);
    fclose($fp);
    $linhas = array_filter(explode("\n", $chunk), fn($l) =>
        str_contains($l, 'Fatal error') || str_contains($l, 'Parse error') ||
        str_contains($l, 'Uncaught Error') || str_contains($l, 'Uncaught Exception'));
    $recentes = array_slice(array_values($linhas), -5);
    if ($recentes) {
        check('Erros', 'Erros recentes', 'warn', count($linhas) . ' encontrado(s) (últimos 5 abaixo)', basename($errorLogPath));
        foreach ($recentes as $linha) {
            check('Erros', 'Erro', 'error', substr(trim($linha), 0, 140), '');
        }
    } else {
        check('Erros', 'Erros recentes', 'ok', 'Nenhum erro fatal', basename($errorLogPath));
    }
} else {
    check('Erros', 'Erros recentes', 'info', 'error_log do PHP não configurado/legível', 'ini_get("error_log")');
}

// ── RENDER ─────────────────────────────────────────────────────────────────
$totalOk    = count(array_filter($checks, fn($c) => $c['status'] === 'ok'));
$totalWarn  = count(array_filter($checks, fn($c) => $c['status'] === 'warn'));
$totalError = count(array_filter($checks, fn($c) => $c['status'] === 'error'));
$totalInfo  = count(array_filter($checks, fn($c) => $c['status'] === 'info'));
$globalSt   = $totalError > 0 ? 'error' : ($totalWarn > 0 ? 'warn' : 'ok');
$labelStatus = ['ok' => '✅ OK', 'warn' => '⚠️ Atenção', 'error' => '❌ Erro', 'info' => 'ℹ️ Info'];
$classeStatus = ['ok' => 'badge-ok', 'warn' => 'badge-aviso', 'error' => 'badge-atraso', 'info' => 'badge-info'];
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Saúde do sistema — Fastcar CRM</title>
<link rel="stylesheet" href="/admin/assets/style.css">
<?php include __DIR__ . '/_pwa_head.php'; ?>
</head>
<body>
<header class="topbar">
    <a href="/admin/index.php" style="color:#fff">← Voltar</a>
    <strong>🚗 Fastcar CRM</strong>
    <span>Olá, <?= e($_SESSION['admin_nome']) ?></span>
    <a href="/admin/configuracoes.php">⚙️ Configurações</a>
    <a href="/admin/logout.php">Sair</a>
</header>

<main>
<div class="card stat-card <?= $globalSt === 'ok' ? 'sucesso' : ($globalSt === 'warn' ? '' : 'alerta') ?>" style="margin-bottom:16px">
    <div class="valor"><?= $globalSt === 'ok' ? '✅ Sistema saudável' : ($globalSt === 'warn' ? '⚠️ Atenção necessária' : '❌ Problemas detectados') ?></div>
    <div class="rotulo">Verificado em <?= date('d/m/Y \à\s H:i:s') ?> — <?= $totalOk ?> ok · <?= $totalWarn ?> atenção · <?= $totalError ?> erro(s)</div>
    <p style="margin-top:10px"><a href="/admin/saude.php">🔄 Verificar novamente</a></p>
</div>

<div class="stat-grid">
    <div class="stat-card sucesso"><div class="valor"><?= $totalOk ?></div><div class="rotulo">✅ OK</div></div>
    <div class="stat-card <?= $totalWarn > 0 ? '' : 'neutro' ?>"><div class="valor"><?= $totalWarn ?></div><div class="rotulo">⚠️ Atenção</div></div>
    <div class="stat-card <?= $totalError > 0 ? 'alerta' : 'neutro' ?>"><div class="valor"><?= $totalError ?></div><div class="rotulo">❌ Erro</div></div>
    <div class="stat-card neutro"><div class="valor"><?= $totalInfo ?></div><div class="rotulo">ℹ️ Info</div></div>
</div>

<?php
$grupos = [];
foreach ($checks as $c) $grupos[$c['grupo']][] = $c;
foreach ($grupos as $grupo => $itens):
    $temErro = (bool)array_filter($itens, fn($c) => $c['status'] === 'error');
    $temAviso = (bool)array_filter($itens, fn($c) => $c['status'] === 'warn');
    $icone = $temErro ? '❌' : ($temAviso ? '⚠️' : '✅');
?>
<div class="card">
    <h3><?= $icone ?> <?= e($grupo) ?></h3>
    <table class="tabela-oportunidades">
        <tbody>
        <?php foreach ($itens as $item): ?>
            <tr>
                <td style="width:220px"><?= e($item['nome']) ?></td>
                <td style="width:130px"><span class="badge <?= $classeStatus[$item['status']] ?>"><?= $labelStatus[$item['status']] ?></span></td>
                <td style="font-weight:600"><?= e($item['valor']) ?></td>
                <td style="color:#666;font-size:13px"><?= e($item['detalhe']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endforeach; ?>
</main>
<?php include __DIR__ . '/_pwa_register.php'; ?>
</body>
</html>
