<?php
/**
 * Conexão SQLite — padrão reaproveitado do JurídicoSaaS.
 * PRAGMA busy_timeout=5000 é obrigatório aqui: evita "database is locked"
 * quando o webhook do WhatsApp (alta concorrência) escreve ao mesmo tempo
 * que um cron ou uma request do admin. Ver histórico de bugs desse tipo
 * documentado no CLAUDE.md do projeto irmão (iabadvocaciaboutique).
 */

define('DB_PATH', dirname(__DIR__) . '/database/fastcar.db');

// Garante um error_log que o próprio app controla e consegue ler de volta
// (admin/saude.php, seção "Erros recentes") — sem isso, o check depende de
// php.ini/pool do PHP-FPM da VPS já vir com error_log configurado, o que
// não é garantido (achado real: "Info — error_log do PHP não
// configurado/legível" em produção mesmo com o sistema funcionando normal).
// ini_set() só tem efeito quando error_log não está travado por
// php_admin_value no pool do PHP-FPM (hosting gerenciado costuma travar) —
// se estiver travado, essa chamada não faz nada e o check em saude.php
// continua refletindo o error_log real da VPS, nunca conflita com ele.
// Mesmo padrão de storage/logs/*.log usado no resto do projeto (webhook,
// deploy, mídia do WhatsApp etc).
$errorLogDir = dirname(__DIR__) . '/storage/logs';
if (!is_dir($errorLogDir)) @mkdir($errorLogDir, 0755, true);
ini_set('log_errors', '1');
ini_set('error_log', $errorLogDir . '/php_errors.log');

function getDB(): PDO {
    static $db = null;
    if ($db === null) {
        $novo = !file_exists(DB_PATH);
        $db = new PDO('sqlite:' . DB_PATH);
        $db->exec('PRAGMA busy_timeout=5000');
        $db->exec('PRAGMA journal_mode=WAL');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        if ($novo) {
            criarSchema($db);
        }
    }
    return $db;
}

function criarSchema(PDO $db): void {
    $sql = file_get_contents(dirname(__DIR__) . '/install/schema.sql');
    $db->exec($sql);
}

// Referência compartilhada entre getConfig()/setConfig() — sem isso,
// setConfig() não tinha como atualizar o cache estático de getConfig()
// (cada função tem seu próprio `static`, não existe jeito de um mexer no
// do outro diretamente). Achado real: 16/09/2026, teto configurável da
// fila de leads (fila_leads_max_ativas) parecia "não salvar" — na
// verdade salvava certo no banco, mas se getConfig() já tivesse rodado
// ANTES do setConfig() na mesma request (ex: alguma leitura de config
// cedo no bootstrap), as leituras seguintes na mesma request continuavam
// vendo o valor velho até a próxima request (PHP-FPM reseta `static`
// entre requests, então o bug só aparecia DENTRO da mesma request que
// salvava e logo em seguida lia de novo).
function &configCache(): array {
    static $cache = null;
    if ($cache === null) {
        $db = getDB();
        $cache = [];
        foreach ($db->query("SELECT chave, valor FROM config") as $row) {
            $cache[$row['chave']] = $row['valor'];
        }
    }
    return $cache;
}

function getConfig(?string $chave = null) {
    $cache = &configCache();
    if ($chave !== null) {
        return $cache[$chave] ?? null;
    }
    return $cache;
}

function setConfig(string $chave, string $valor): void {
    $db = getDB();
    $db->prepare("INSERT INTO config (chave, valor) VALUES (?, ?)
                  ON CONFLICT(chave) DO UPDATE SET valor = excluded.valor")
       ->execute([$chave, $valor]);
    $cache = &configCache();
    $cache[$chave] = $valor;
}
