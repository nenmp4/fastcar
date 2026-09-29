<?php
/**
 * Smoke test — roda ANTES de todo commit e pode rodar em produção.
 * Uso: php tests/smoke.php
 *
 * Mesmo padrão do JurídicoSaaS (.claude/skills/debug-php-legado): o smoke
 * é a memória imunológica do projeto. Achou um bug de padrão? Corrige em
 * TODO o repo e adiciona um guard aqui — nunca só no ponto onde apareceu.
 *
 * 3 camadas:
 *  1. LINT — php -l em todos os .php do repo (parse error nunca chega em produção)
 *  2. GUARDS — greps que codificam bugs JÁ COMETIDOS pra eles nunca voltarem
 *  3. SCHEMA — colunas/tabelas que o código usa existem no banco (se houver banco real)
 */

$root = dirname(__DIR__);
$falhas = 0;
$avisos = 0;

function ok(string $msg): void    { echo "  ✅ {$msg}\n"; }
function falha(string $msg): void { global $falhas; $falhas++; echo "  ❌ {$msg}\n"; }
function aviso(string $msg): void { global $avisos; $avisos++; echo "  ⚠️  {$msg}\n"; }

function arquivosPhp(string $root): RecursiveIteratorIterator {
    return new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            fn($f) => !in_array($f->getFilename(), ['storage', 'database', '.git', 'node_modules', 'vendor', 'config'], true)
        )
    );
}

// ─────────────────────────────────────────────────────────────
// 1. LINT — todos os .php (fora storage, database, .git, config, vendor)
// ─────────────────────────────────────────────────────────────
echo "== 1. Lint PHP ==\n";
$phpBin = PHP_BINARY;
$totalPhp = 0; $errosLint = 0;
foreach (arquivosPhp($root) as $f) {
    if ($f->getExtension() !== 'php') continue;
    $totalPhp++;
    $out = shell_exec(escapeshellarg($phpBin) . ' -l ' . escapeshellarg($f->getPathname()) . ' 2>&1');
    if (strpos((string)$out, 'No syntax errors') === false) {
        $errosLint++;
        falha("Parse error: " . str_replace($root . '/', '', $f->getPathname()) . " — " . trim((string)$out));
    }
}
if (!$errosLint) ok("{$totalPhp} arquivos PHP sem parse error");

// ─────────────────────────────────────────────────────────────
// 2. GUARDS — bugs já cometidos, codificados como grep
// ─────────────────────────────────────────────────────────────
echo "\n== 2. Guards de regressão ==\n";

/** Varre .php procurando padrão proibido; $permitidos são caminhos (substring) liberados. */
function guard(string $nome, string $regex, array $permitidos, string $porque): void {
    global $root;
    $violacoes = [];
    foreach (arquivosPhp($root) as $f) {
        if ($f->getExtension() !== 'php') continue;
        $rel = str_replace($root . '/', '', $f->getPathname());
        foreach ($permitidos as $p) { if (str_contains($rel, $p)) continue 2; }
        $conteudo = (string)file_get_contents($f->getPathname());
        if (preg_match($regex, $conteudo)) $violacoes[] = $rel;
    }
    if ($violacoes) {
        falha("[{$nome}] {$porque} — em: " . implode(', ', $violacoes));
    } else {
        ok("[{$nome}] limpo");
    }
}

// Bug 09/2026: chamada direta à API Gemini fora do helper quebra fallback
// de modelo aposentado e o registro de tokens — sempre usar geminiCall()/
// geminiCallChat() (includes/gemini.php).
guard(
    'gemini-chamada-direta',
    '/generativelanguage\.googleapis\.com/',
    ['includes/gemini.php', 'tests/'],
    'Chamada direta à API Gemini fora do helper (use geminiCall()/geminiCallChat())'
);

// Mesma lógica pro fallback OpenAI (includes/openai.php) — introduzido
// junto com o fallback duplo Gemini→GPT.
guard(
    'openai-chamada-direta',
    '/api\.openai\.com/',
    ['includes/openai.php', 'tests/'],
    'Chamada direta à API OpenAI fora do helper (use openaiCall()/openaiCallChat())'
);

// Mesma lógica pra Z-API — introduzido junto com admin/saude.php, que
// precisou do override ZAPI_BASE_URL pra ser testável contra fake server.
guard(
    'zapi-chamada-direta',
    '/api\.z-api\.io/',
    ['includes/whatsapp_config.php', 'tests/'],
    'Chamada direta à API Z-API fora do helper (use zapiBaseUrl() de includes/whatsapp_config.php)'
);

// Mesma lógica pra ZapSign — introduzido na troca de provedor de assinatura
// (13/09/2026, substituiu a Assinafy). zapsignBaseUrl() já suporta override
// via define() pra teste contra fake server local.
guard(
    'zapsign-chamada-direta',
    '/api\.zapsign\.com\.br/',
    ['includes/zapsign.php', 'tests/'],
    'Chamada direta à API ZapSign fora do helper (use zapsignBaseUrl() de includes/zapsign.php)'
);

// Bug real 09/2026: includes/mail.php usava `: true|array` como tipo de
// retorno — `true`/`false` como tipo standalone (union ou sozinho) só
// existe a partir do PHP 8.2. `php -l` nunca pegou isso no dev (roda PHP
// 8.4 aqui), só quebrou de verdade rodando na VPS de produção (Ubuntu
// 22.04 só tem PHP 8.1 no repositório padrão) — "Cannot use 'true' as
// class name as it is reserved". Trocar sempre por `bool|array` (ou só
// `bool`) em vez do tipo standalone.
guard(
    'tipo-standalone-true-false-php82',
    '/function\s+\w+\([^)]*\)\s*:\s*\??[\w|]*\b(?:true|false)\b[\w|]*\s*\{/',
    ['tests/'],
    'Tipo standalone true/false em assinatura de função exige PHP 8.2+ — produção roda PHP 8.1, use bool|... no lugar'
);

// Bug 09/2026 (achado neste projeto, mesmo padrão do JurídicoSaaS): 41
// conexões SQLite avulsas lá; aqui a regra é a mesma desde o primeiro
// commit — só includes/db.php::getDB() pode abrir conexão direta, com
// PRAGMA busy_timeout=5000 logo em seguida. O webhook do WhatsApp é o
// processo mais concorrente do sistema (dispara a cada mensagem).
$semBusyTimeout = [];
$permitidosPdo = ['includes/db.php', 'tests/', 'install/'];
foreach (arquivosPhp($root) as $f) {
    if ($f->getExtension() !== 'php') continue;
    $rel = str_replace($root . '/', '', $f->getPathname());
    foreach ($permitidosPdo as $p) { if (str_contains($rel, $p)) continue 2; }
    $conteudo = (string)file_get_contents($f->getPathname());
    if (preg_match_all("/new PDO\('sqlite:/", $conteudo, $mAll, PREG_OFFSET_CAPTURE)) {
        foreach ($mAll[0] as [, $offset]) {
            $trecho = substr($conteudo, $offset, 350);
            if (!str_contains($trecho, 'busy_timeout')) {
                $semBusyTimeout[] = $rel . ':' . (substr_count(substr($conteudo, 0, $offset), "\n") + 1);
            }
        }
    }
}
if ($semBusyTimeout) {
    falha('[sqlite-sem-busy-timeout] new PDO(sqlite:...) sem PRAGMA busy_timeout logo em seguida — em: ' . implode(', ', $semBusyTimeout));
} else {
    ok('[sqlite-sem-busy-timeout] limpo');
}

// Bug real 12/09/2026: chatbot-whatsapp/includes/mensagens.php passou a
// chamar zapiEnviarTexto() (via IA) sem declarar o require de
// includes/whatsapp_config.php — funcionava só por acidente de quem
// incluía antes (webhook.php/simulate.php já tinham incluído). Fatal
// error silencioso se algum dia mensagens.php for incluído sozinho.
$mensagensPath = $root . '/chatbot-whatsapp/includes/mensagens.php';
if (file_exists($mensagensPath)) {
    $conteudo = (string)file_get_contents($mensagensPath);
    if (str_contains($conteudo, 'zapiEnviarTexto(') && !str_contains($conteudo, "require_once dirname(__DIR__, 2) . '/includes/whatsapp_config.php'")) {
        falha('[mensagens-sem-require-zapi] mensagens.php chama zapiEnviarTexto() sem declarar require de includes/whatsapp_config.php');
    } else {
        ok('[mensagens-sem-require-zapi] limpo');
    }
} else {
    aviso('chatbot-whatsapp/includes/mensagens.php não encontrado — guard pulado');
}

// Bug real 12/09/2026: rodízio de leads (includes/fila_leads.php) ordenado
// só por timestamp (ultimo_lead_recebido_em) empatava quando 2 leads
// chegavam no mesmo segundo (granularidade do SQLite) e caía sempre na
// mesma pessoa. Fix: contador monotônico (posicao_fila). Guard: a query
// de proximoDaFila() precisa ordenar por posicao_fila, não só timestamp.
$filaPath = $root . '/includes/fila_leads.php';
if (file_exists($filaPath)) {
    $conteudo = (string)file_get_contents($filaPath);
    if (!preg_match('/ORDER BY\s+posicao_fila/i', $conteudo)) {
        falha('[fila-leads-sem-posicao-fila] proximoDaFila() não ordena por posicao_fila — volta o empate de timestamp em rajada (bug real já corrigido uma vez)');
    } else {
        ok('[fila-leads-sem-posicao-fila] limpo');
    }
} else {
    aviso('includes/fila_leads.php não encontrado — guard pulado');
}

// Bug real 12/09/2026: depois da integração com Google Drive, um documento
// pode estar em arquivo_url (fallback local) OU drive_file_id (Drive,
// preferido). checklistFechamentoCompleto() só checava arquivo_url e
// bloqueava TODO fechamento em produção assim que o Drive fosse
// configurado. Mesmo bug apareceu em admin/oportunidade.php e
// public/documentos.php (badges de status) — corrigido nos 3 juntos.
// Guard: nenhum arquivo pode checar "arquivo_url" (truthy/vazio) sem
// também checar "drive_file_id" na mesma expressão/bloco próximo.
foreach (['includes/oportunidades.php', 'admin/oportunidade.php', 'public/documentos.php'] as $arquivoDoc) {
    $caminho = $root . '/' . $arquivoDoc;
    if (!file_exists($caminho)) { aviso("{$arquivoDoc} não encontrado — guard pulado"); continue; }
    $conteudo = (string)file_get_contents($caminho);
    // Acha cada ocorrência de "arquivo_url" e confere se "drive_file_id"
    // aparece nos 150 caracteres ao redor (mesma condição/bloco).
    preg_match_all("/arquivo_url/", $conteudo, $mAll, PREG_OFFSET_CAPTURE);
    $semDrive = [];
    foreach ($mAll[0] as [, $offset]) {
        $inicio = max(0, $offset - 150);
        $trecho = substr($conteudo, $inicio, 300);
        if (!str_contains($trecho, 'drive_file_id')) {
            $semDrive[] = (substr_count(substr($conteudo, 0, $offset), "\n") + 1);
        }
    }
    if ($semDrive) {
        falha("[documento-status-so-arquivo-url] {$arquivoDoc} checa arquivo_url sem drive_file_id por perto — linha(s): " . implode(', ', $semDrive));
    } else {
        ok("[documento-status-so-arquivo-url] {$arquivoDoc} limpo");
    }
}

// admin-pagina-sem-pwa guard — toda página cheia do admin (tem <html>, não é
// _bootstrap/_pwa_*/_notify.php/login) precisa incluir os partials de PWA E
// de notificações, senão a instalação como app ou o sino de aviso de lead
// quebram silenciosamente numa tela específica (mesmo tipo de bug do
// head_scripts nas landing pages do JurídicoSaaS: página com <head> próprio
// que não passa pelo snippet compartilhado).
$semPwa = [];
$parciais = ['_bootstrap.php', '_pwa_head.php', '_pwa_register.php', '_notify.php', 'login.php', 'esqueci_senha.php', 'redefinir_senha.php'];
foreach (glob($root . '/admin/*.php') as $f) {
    $rel = str_replace($root . '/', '', $f);
    $base = basename($f);
    if (in_array($base, $parciais, true)) continue;
    $conteudo = (string)file_get_contents($f);
    if (!str_contains($conteudo, '<html')) continue; // não é página cheia (endpoint/ajax)
    $faltando = [];
    if (!str_contains($conteudo, '_pwa_head.php')) $faltando[] = 'pwa-head';
    if (!str_contains($conteudo, '_pwa_register.php')) $faltando[] = 'pwa-register';
    if (!str_contains($conteudo, '_notify.php')) $faltando[] = 'notify';
    if ($faltando) $semPwa[] = "{$rel} (falta " . implode('+', $faltando) . ')';
}
if ($semPwa) {
    falha('[admin-pagina-sem-pwa] página cheia do admin sem include de PWA/notificação — em: ' . implode(', ', $semPwa));
} else {
    ok('[admin-pagina-sem-pwa] limpo');
}

// version.json precisa ser JSON válido e semver
$vj = json_decode((string)@file_get_contents($root . '/version.json'), true);
if (!$vj || empty($vj['version']) || !preg_match('/^\d+\.\d+\.\d+$/', $vj['version'])) {
    falha('[version.json] inválido, ausente ou versão fora do semver');
} else {
    ok("[version.json] válido (v{$vj['version']})");
}

// ─────────────────────────────────────────────────────────────
// 3. SCHEMA — colunas que o código usa existem no banco real
// ─────────────────────────────────────────────────────────────
echo "\n== 3. Schema do banco ==\n";
$dbPath = $root . '/database/fastcar.db';
if (!file_exists($dbPath)) {
    aviso('banco não encontrado — checks de schema pulados (normal em dev sem banco criado ainda)');
} else {
    try {
        $db = new PDO('sqlite:' . $dbPath);
        $temConfig = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='config'")->fetchColumn();
        if (!$temConfig) {
            aviso('banco vazio/de teste (sem tabela config) — checks de schema pulados');
        } else {
            // Colunas que já causaram (ou causariam) erro fatal se sumissem,
            // ou que código novo depende. Formato: tabela => [colunas obrigatórias]
            $esperado = [
                'clientes'                => ['cpf', 'rg', 'cnh', 'drive_folder_id', 'canal_origem'],
                'oportunidades'           => ['documentos_token', 'veiculo_placa', 'valor_fipe_referencia', 'contrato_assinado'],
                'oportunidade_documentos' => ['drive_file_id', 'arquivo_url', 'enviado_pelo_cliente'],
                'usuarios'                => ['disponivel', 'plantao_fim_expediente', 'posicao_fila'],
                'whatsapp_mensagens'      => ['usuario_id', 'zapi_message_id'],
                'contratos'               => ['zapsign_doc_token', 'drive_file_id', 'arquivo_url', 'status'],
                'zapi_instancias_consultores' => ['usuario_id', 'instance_id'],
            ];
            foreach ($esperado as $tabela => $colunas) {
                $existe = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='{$tabela}'")->fetchColumn();
                if (!$existe) { falha("tabela ausente: {$tabela}"); continue; }
                $cols = array_column($db->query("PRAGMA table_info({$tabela})")->fetchAll(PDO::FETCH_ASSOC), 'name');
                $faltam = array_diff($colunas, $cols);
                if ($faltam) falha("colunas ausentes em {$tabela}: " . implode(', ', $faltam));
                else ok("{$tabela}: colunas críticas ok");
            }
        }
    } catch (Throwable $e) {
        aviso('erro ao abrir banco: ' . $e->getMessage());
    }
}

// ─────────────────────────────────────────────────────────────
// 4. PERFORMANCE (velocidade) — PRAGMAs de leitura + índices críticos
// ─────────────────────────────────────────────────────────────
echo "\n== 4. Performance (velocidade) ==\n";

// PRAGMAs de leitura (includes/db.php::getDB()) — 29/09/2026, "melhora
// desempenho velocidade do sistema". synchronous=NORMAL/cache_size/
// temp_store=MEMORY nunca persistem no arquivo do banco (diferente de
// journal_mode=WAL) — precisam ser reaplicados em TODA conexão. Se
// sumirem daqui num refactor futuro, o banco volta a fazer fsync a cada
// commit e ordenar em disco em vez de RAM, sem nenhum erro visível, só
// mais lento — exatamente o tipo de regressão silenciosa que esse guard
// existe pra pegar.
$dbPhpPath = $root . '/includes/db.php';
if (file_exists($dbPhpPath)) {
    $conteudo = (string)file_get_contents($dbPhpPath);
    $pragmasEsperados = [
        'synchronous=NORMAL' => 'PRAGMA synchronous=NORMAL',
        'cache_size'         => 'PRAGMA cache_size=',
        'temp_store=MEMORY'  => 'PRAGMA temp_store=MEMORY',
    ];
    $faltandoPragma = [];
    foreach ($pragmasEsperados as $nome => $trecho) {
        if (!str_contains($conteudo, $trecho)) $faltandoPragma[] = $nome;
    }
    if ($faltandoPragma) {
        falha('[db-sem-pragma-performance] includes/db.php sem PRAGMA de performance: ' . implode(', ', $faltandoPragma) . ' (getDB() precisa reaplicar em toda conexão, nunca persistem no arquivo do banco)');
    } else {
        ok('[db-sem-pragma-performance] limpo (synchronous/cache_size/temp_store presentes)');
    }
} else {
    aviso('includes/db.php não encontrado — guard de PRAGMA pulado');
}

// Índices críticos que já causaram lenteza real (queries mais repetidas
// do sistema) — 29/09/2026, "vamos melhora desempenho velocidade do
// sistema". Cada índice precisa existir nos DOIS lugares: install/schema.sql
// (fresh install) E install/migrar.php (produção já rodando, idempotente
// via CREATE INDEX IF NOT EXISTS) — nunca só um dos dois, senão instalação
// nova e banco de produção já existente divergem silenciosamente.
$indicesEsperados = [
    'idx_oportunidades_responsavel'  => 'carteira do consultor (WHERE responsavel_id=? AND etapa IN(...)), rodada em toda carga de admin/index.php pra quem não é super_admin/supervisor',
    'idx_oportunidades_created'      => 'ordenação/filtro por data de entrada do lead (?filtro=hoje/ontem/semana, desempate de ORDER BY)',
    'idx_oportunidades_tipo_veiculo' => 'aba de filtro por tipo de veículo em admin/index.php',
    'idx_vendas_responsavel'         => 'mesma query de carteira, lado do pipeline de vendas',
    'idx_fin_lancamentos_vencimento' => 'finRecalcularAtrasados() — roda em toda carga de admin/financeiro.php/financeiro-lancamentos.php/promissorias.php, full table scan sem esse índice',
    'idx_vendas_comprador_telefone'  => 'listarConversasVendas() (WhatsApp Box de vendas, polling 5s) — sem esse índice, SCAN vendas inteira a cada poll',
];
foreach (['install/schema.sql', 'install/migrar.php'] as $arquivoIdx) {
    $caminhoIdx = $root . '/' . $arquivoIdx;
    if (!file_exists($caminhoIdx)) { aviso("{$arquivoIdx} não encontrado — guard de índices pulado"); continue; }
    $conteudo = (string)file_get_contents($caminhoIdx);
    $faltandoIdx = [];
    foreach (array_keys($indicesEsperados) as $nomeIdx) {
        if (!str_contains($conteudo, $nomeIdx)) $faltandoIdx[] = $nomeIdx;
    }
    if ($faltandoIdx) {
        falha("[indices-criticos-ausentes] {$arquivoIdx} sem: " . implode(', ', $faltandoIdx));
    } else {
        ok("[indices-criticos-ausentes] {$arquivoIdx} limpo");
    }
}

// Confirma que os índices existem de verdade no banco (não só no SQL) —
// mesmo racional da seção 3 (colunas): útil se algum dia schema.sql for
// editado sem rodar migrar.php contra um banco já existente. Reaproveita
// a conexão $db já aberta na seção 3, se disponível.
if (isset($db) && $db instanceof PDO) {
    $idxReais = array_column($db->query("SELECT name FROM sqlite_master WHERE type='index'")->fetchAll(PDO::FETCH_ASSOC), 'name');
    $faltandoNoBanco = array_diff(array_keys($indicesEsperados), $idxReais);
    if ($faltandoNoBanco) {
        falha('[indices-criticos-ausentes-no-banco] existem no SQL mas não no banco real (rodar php install/migrar.php): ' . implode(', ', $faltandoNoBanco));
    } else {
        ok('[indices-criticos-ausentes-no-banco] todos presentes no banco real');
    }
}

// Anti-padrão "última mensagem por telefone" via subquery correlacionada
// (WHERE m.id = (SELECT MAX(id) FROM whatsapp_mensagens WHERE telefone=m.telefone))
// — 29/09/2026, achado via EXPLAIN QUERY PLAN nos 3 WhatsApp Box (compra/
// vendas/financeiro, todos com polling de 5s): forçava SCAN da tabela
// inteira a cada linha, mesmo com índice em telefone. Reescrito pro
// padrão JOIN (SELECT telefone, MAX(id)... GROUP BY telefone) — vira
// SCAN ... USING COVERING INDEX, sem tocar a tabela. Guard evita alguém
// reintroduzir o padrão lento copiando um dos 3 arquivos de novo.
$arquivosInbox = ['includes/whatsapp_inbox.php', 'includes/vendas_inbox.php', 'includes/financeiro_inbox.php'];
$comAntiPadrao = [];
foreach ($arquivosInbox as $arqInbox) {
    $caminhoInbox = $root . '/' . $arqInbox;
    if (!file_exists($caminhoInbox)) continue;
    if (preg_match('/WHERE\s+m\.id\s*=\s*\(SELECT MAX\(id\)/i', (string)file_get_contents($caminhoInbox))) {
        $comAntiPadrao[] = $arqInbox;
    }
}
if ($comAntiPadrao) {
    falha('[whatsapp-inbox-subquery-correlacionada] voltou o padrão lento (SCAN full table por poll): ' . implode(', ', $comAntiPadrao));
} else {
    ok('[whatsapp-inbox-subquery-correlacionada] limpo (os 3 inboxes usam JOIN por índice, não subquery correlacionada)');
}

// ─────────────────────────────────────────────────────────────
echo "\n══════════════════════════════════\n";
if ($falhas) {
    echo "❌ SMOKE FALHOU: {$falhas} falha(s), {$avisos} aviso(s) — NÃO commitar/deployar\n";
    exit(1);
}
echo "✅ SMOKE OK ({$avisos} aviso(s)) — seguro pra commit\n";
exit(0);
