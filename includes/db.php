<?php
/**
 * Conexão SQLite — padrão reaproveitado do JurídicoSaaS.
 * PRAGMA busy_timeout é obrigatório aqui: evita "database is locked"
 * quando o webhook do WhatsApp (alta concorrência) escreve ao mesmo tempo
 * que um cron ou uma request do admin. Ver histórico de bugs desse tipo
 * documentado no CLAUDE.md do projeto irmão (iabadvocaciaboutique).
 *
 * 19/09/2026 — subido de 5000 pra 15000ms ("temos arrumar isso essa
 * disputa pelo bd", achado direto em admin/saude.php: "database is
 * locked" continuava aparecendo mesmo depois do webhook já ter try/catch
 * pra não derrubar cru — ver handler global logo abaixo). O SQLite já
 * espera automaticamente até esse tempo antes de desistir e lançar o
 * erro; 5s era curto demais pra uma rajada real de escrita concorrente
 * (webhook + cron + admin todos gravando quase junto), 15s dá bem mais
 * margem pra terminar sem precisar falhar, sem mudar nada mais no
 * comportamento (só espera mais antes de desistir de verdade).
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

// Handler global de exceção/erro fatal não capturada (19/09/2026, "temos
// arrumar isso essa disputa pelo bd") — achado real em admin/saude.php:
// "database is locked" (PDOException de SQLITE_BUSY) aparecia repetido
// como "PHP Fatal error: Uncaught..." bem DEPOIS do fix de 18/09/2026 que
// já tinha colocado try/catch ao redor do processamento do webhook do
// WhatsApp — a causa real é que `mudarEtapa()`/`mudarEtapaVenda()` (as
// funções centrais que gravam etapa+histórico, chamadas de vários lugares
// — ver CLAUDE.md regra #6) são chamadas SEM try/catch em vários pontos
// que não são o webhook, principalmente os handlers de POST do admin
// (`admin/oportunidade.php`, `admin/venda.php` — um consultor clicando
// "mudar etapa"/"marcar perdida"/"cancelar venda"): uma contenção de
// escrita genuína ali virava um PHP Fatal Error cru na tela do usuário,
// sem nenhum try/catch pra evitar — provável causa real do "Erro 500
// relatado ao salvar, não reproduzido" já registrado antes (nunca
// reproduzido em teste isolado porque contenção de verdade só acontece
// com concorrência real de produção, não dá pra simular sozinho). Em vez
// de caçar e envolver cada chamada de `mudarEtapa()`/`mudarEtapaVenda()`
// espalhada pelo admin uma por uma (arriscado esquecer alguma — eram ~15
// call sites diferentes), registrado aqui, em `includes/db.php`
// (carregado por PRATICAMENTE toda entrada do sistema — admin, webhook,
// crons, wizard público), como rede de segurança única: nenhum Throwable
// esquecido derruba mais uma tela com stack trace cru pro usuário — loga
// certo (mesmo `storage/logs/php_errors.log` de sempre, formato
// `"...Fatal error: Uncaught..."` que `admin/saude.php` já sabe
// reconhecer via `str_contains()`, nenhuma mudança precisou lá) e responde
// com algo limpo em vez do dump padrão do PHP. Nunca substitui um
// try/catch já existente (só herda o Throwable quando NENHUM catch pegou
// antes), e nunca impede a subida do busy_timeout acima de ser a correção
// de verdade — é só o que sobra depois de reduzir a contenção real.
set_exception_handler(function (Throwable $e): void {
    error_log(sprintf(
        'PHP Fatal error: Uncaught %s: %s in %s:%d',
        get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()
    ));
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(1);
    }
    if (!headers_sent()) {
        http_response_code(500);
    }
    echo 'Erro interno — tivemos uma instabilidade momentânea (ex: contenção de escrita no banco). '
       . 'Recarregue a página e tente de novo; se persistir, avise o suporte.';
});

// Complementa o handler acima: cobre erro fatal do PHP que NUNCA vira uma
// Throwable capturável (ex: função indefinida, esgotamento de memória) —
// set_exception_handler() só pega Throwable de verdade, não isso.
register_shutdown_function(function (): void {
    $err = error_get_last();
    if (!$err || !in_array($err['type'], [E_ERROR, E_PARSE, E_COMPILE_ERROR, E_CORE_ERROR], true)) {
        return;
    }
    error_log(sprintf('PHP Fatal error: %s in %s:%d', $err['message'], $err['file'], $err['line']));
    if (PHP_SAPI !== 'cli' && !headers_sent()) {
        http_response_code(500);
        echo 'Erro interno — tivemos uma instabilidade momentânea. '
           . 'Recarregue a página e tente de novo; se persistir, avise o suporte.';
    }
});

function getDB(): PDO {
    static $db = null;
    if ($db === null) {
        $novo = !file_exists(DB_PATH);
        $db = new PDO('sqlite:' . DB_PATH);
        $db->exec('PRAGMA busy_timeout=15000');
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
