<?php
/**
 * Migração de colunas novas em banco já existente — install/schema.sql só
 * roda inteiro na 1ª conexão (banco não existe ainda); depois disso, uma
 * coluna nova adicionada ao schema.sql precisa ser aplicada aqui também
 * pra bater com um banco já em produção. Idempotente: rodar de novo não dá
 * erro, só pula o que já existe.
 *
 * Uso (CLI, na raiz do projeto):
 *   php install/migrar.php
 *
 * Toda vez que uma coluna nova entrar no schema.sql, adiciona a mesma
 * ALTER TABLE aqui na lista $migracoes — não deixa o schema.sql e o banco
 * real divergirem silenciosamente.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Este script só roda via linha de comando (CLI).\n");
}

require_once __DIR__ . '/../includes/db.php';

$db = getDB();

/** Checa se uma coluna existe numa tabela (pra migração condicional, ex: RENAME COLUMN). */
function colunaExiste(PDO $db, string $tabela, string $coluna): bool {
    foreach ($db->query("PRAGMA table_info({$tabela})")->fetchAll() as $col) {
        if ($col['name'] === $coluna) return true;
    }
    return false;
}

// 15/09/2026 — módulo de vendas ("você colocar galera para fazer o fluxo e
// fechar pontas soltas"). Tabelas NOVAS (não coluna em tabela existente)
// não entram no loop de ALTER TABLE abaixo — CREATE TABLE IF NOT EXISTS é
// idempotente por si só, mas só roda de verdade num banco já existente se
// chamado explicitamente aqui: criarSchema() (includes/db.php) só roda o
// schema.sql inteiro na 1ª conexão de um banco que ainda não existe, então
// um banco de produção já criado antes de hoje nunca veria `vendas`/
// `venda_historico` sem isso. Texto idêntico ao de install/schema.sql —
// se um dia divergir, o schema.sql é a fonte de verdade.
$tabelasVendas = "
    CREATE TABLE IF NOT EXISTS vendas (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        oportunidade_id INTEGER NOT NULL REFERENCES oportunidades(id),
        etapa TEXT NOT NULL DEFAULT 'negociacao'
            CHECK (etapa IN ('negociacao', 'contrato_enviado', 'vendido', 'cancelada')),
        responsavel_id INTEGER REFERENCES usuarios(id),
        proxima_acao TEXT DEFAULT '',
        proxima_acao_em DATETIME,
        motivo_cancelamento TEXT DEFAULT '',
        comprador_nome TEXT DEFAULT '',
        comprador_nacionalidade TEXT DEFAULT 'brasileiro(a)',
        comprador_estado_civil TEXT DEFAULT '',
        comprador_profissao TEXT DEFAULT '',
        comprador_rg TEXT DEFAULT '',
        comprador_cpf TEXT DEFAULT '',
        comprador_cnh TEXT DEFAULT '',
        comprador_endereco TEXT DEFAULT '',
        comprador_telefone TEXT DEFAULT '',
        comprador_email TEXT DEFAULT '',
        km_entrega INTEGER,
        preco_venda REAL,
        valor_pago_contratacao REAL,
        forma_pagamento TEXT DEFAULT '',
        saldo_preco_devido REAL,
        prazo_quitacao_meses INTEGER DEFAULT 24,
        data_limite_quitacao DATE,
        prestacao_contas_texto TEXT DEFAULT '',
        seguro_texto TEXT DEFAULT '',
        ipva_responsavel_texto TEXT DEFAULT '',
        multas_texto TEXT DEFAULT 'COMPRADOR, na extensão legal aplicável',
        rastreador_texto TEXT DEFAULT '',
        prazo_transferencia_dias INTEGER,
        penalidade_atraso_texto TEXT DEFAULT '',
        data_venda DATE,
        created_at DATETIME DEFAULT (datetime('now','localtime')),
        updated_at DATETIME DEFAULT (datetime('now','localtime'))
    );
    CREATE INDEX IF NOT EXISTS idx_vendas_oportunidade ON vendas(oportunidade_id);
    CREATE INDEX IF NOT EXISTS idx_vendas_etapa ON vendas(etapa);
    CREATE INDEX IF NOT EXISTS idx_vendas_proxima_acao ON vendas(proxima_acao_em);
    CREATE UNIQUE INDEX IF NOT EXISTS idx_vendas_ativa_por_veiculo
        ON vendas(oportunidade_id) WHERE etapa IN ('negociacao', 'contrato_enviado');

    CREATE TABLE IF NOT EXISTS venda_historico (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        venda_id INTEGER NOT NULL REFERENCES vendas(id),
        etapa_anterior TEXT DEFAULT '',
        etapa_nova TEXT NOT NULL,
        responsavel_id INTEGER REFERENCES usuarios(id),
        observacao TEXT DEFAULT '',
        created_at DATETIME DEFAULT (datetime('now','localtime'))
    );
    CREATE INDEX IF NOT EXISTS idx_venda_historico_venda ON venda_historico(venda_id);
";
try {
    $db->exec($tabelasVendas);
    echo "✅ vendas/venda_historico: tabelas ok\n";
} catch (Throwable $e) {
    echo "❌ vendas/venda_historico: {$e->getMessage()}\n";
}

$migracoes = [
    // 09/2026 — testemunhas do contrato-mestre de compra
    'oportunidades.testemunha1_nome' => "ALTER TABLE oportunidades ADD COLUMN testemunha1_nome TEXT DEFAULT ''",
    'oportunidades.testemunha1_cpf'  => "ALTER TABLE oportunidades ADD COLUMN testemunha1_cpf TEXT DEFAULT ''",
    'oportunidades.testemunha2_nome' => "ALTER TABLE oportunidades ADD COLUMN testemunha2_nome TEXT DEFAULT ''",
    'oportunidades.testemunha2_cpf'  => "ALTER TABLE oportunidades ADD COLUMN testemunha2_cpf TEXT DEFAULT ''",

    // 09/2026 — qualificação por IA mais completa (urgência, temperatura do
    // lead, confirmação de ligação, e o contador de turnos sem avanço que
    // decide quando escalar pro consultor humano)
    'oportunidades.urgencia'                  => "ALTER TABLE oportunidades ADD COLUMN urgencia TEXT DEFAULT ''",
    'oportunidades.temperatura_lead'          => "ALTER TABLE oportunidades ADD COLUMN temperatura_lead TEXT DEFAULT ''",
    'oportunidades.aceita_ligacao_consultor'  => "ALTER TABLE oportunidades ADD COLUMN aceita_ligacao_consultor INTEGER",
    'whatsapp_sessoes.turnos_sem_avanco'      => "ALTER TABLE whatsapp_sessoes ADD COLUMN turnos_sem_avanco INTEGER DEFAULT 0",

    // 13/09/2026 — dá pra visualizar o PDF do contrato no próprio sistema
    // (admin/ver_contrato.php) desde a geração, não só depois de assinado
    'contratos.arquivo_url' => "ALTER TABLE contratos ADD COLUMN arquivo_url TEXT DEFAULT ''",

    // 13/09/2026 — wizard de documentos com extração por IA
    // (public/documentos.php, includes/extracao_documentos.php)
    'oportunidade_documentos.dados_confirmados' => "ALTER TABLE oportunidade_documentos ADD COLUMN dados_confirmados INTEGER DEFAULT 0",
    'oportunidades.documentos_confirmados_em'   => "ALTER TABLE oportunidades ADD COLUMN documentos_confirmados_em DATETIME",

    // 13/09/2026 — data real de assinatura do contrato, pro detalhe do
    // cliente mostrar "que dia ele assina contrato"
    'contratos.assinado_em' => "ALTER TABLE contratos ADD COLUMN assinado_em DATETIME",

    // 14/09/2026 — e-mail do cliente, faltava na 1ª etapa do wizard de
    // documentos (pedido direto do José/Jean, "faltou esse dado")
    'clientes.email' => "ALTER TABLE clientes ADD COLUMN email TEXT DEFAULT ''",

    // 15/09/2026 — módulo de vendas: contratos.venda_id desambigua qual
    // negociação de revenda gerou o contrato quando tipo='venda' (NULL pra
    // contrato de compra) — coluna nova numa tabela já existente
    // (`contratos`), as tabelas `vendas`/`venda_historico` em si já foram
    // criadas acima.
    'contratos.venda_id' => "ALTER TABLE contratos ADD COLUMN venda_id INTEGER REFERENCES vendas(id)",

    // 15/09/2026 — mídia recebida no WhatsApp (áudio/imagem/vídeo) agora é
    // salva de verdade (Drive/local), não só descrita em texto pelo Gemini
    // e descartada — achado real: "mídia não estou visualizado".
    'whatsapp_mensagens.drive_file_id' => "ALTER TABLE whatsapp_mensagens ADD COLUMN drive_file_id TEXT DEFAULT ''",

    // 16/09/2026 — nome/foto de perfil do WhatsApp cacheados no cliente
    // ("puxa foto do zap e nome"), via zapiBuscarContato()
    'clientes.foto_perfil_url' => "ALTER TABLE clientes ADD COLUMN foto_perfil_url TEXT DEFAULT NULL",

    // 16/09/2026 — tela de pendências pós-venda (regra #8 do CLAUDE.md),
    // achado real: cliente reclamando de financiamento não quitado de um
    // carro JÁ vendido pra Fastcar, tratado por engano como lead novo
    'oportunidade_pendencias_pos_venda.responsavel_id' => "ALTER TABLE oportunidade_pendencias_pos_venda ADD COLUMN responsavel_id INTEGER REFERENCES usuarios(id)",

    // 17/09/2026 — prazo pra quitar o financiamento vira negociável por
    // oportunidade (normal 12-18 meses, nunca mais que 24) em vez de fixo
    // em 24 meses direto nas cláusulas do contrato-mestre de compra
    'oportunidades.prazo_quitacao_meses' => "ALTER TABLE oportunidades ADD COLUMN prazo_quitacao_meses INTEGER",

    // 19/09/2026 — "ter botão veiculo quitado": flag manual de que o
    // financiamento do banco que a Fastcar assumiu na compra já foi
    // quitado de verdade — separado de oportunidades.etapa='fechado'
    // (que só marca o NEGÓCIO de compra concluído) e separado também de
    // oportunidade_pendencias_pos_venda (genérico pra qualquer pendência
    // pós-venda; este aqui é um flag simples e específico, pensado só pra
    // alimentar/desligar o alerta de "perto de negociar financiamento"
    // em admin/veiculos.php, um clique só, sem precisar abrir formulário).
    'oportunidades.financiamento_quitado' => "ALTER TABLE oportunidades ADD COLUMN financiamento_quitado INTEGER NOT NULL DEFAULT 0",
    'oportunidades.financiamento_quitado_em' => "ALTER TABLE oportunidades ADD COLUMN financiamento_quitado_em DATETIME",

    // 19/09/2026 — "espelhar compra" no módulo de vendas: link de
    // documentos do comprador (mesmo mecanismo de
    // oportunidades.documentos_token) e temperatura do lead comprador
    // (mesmo conceito de oportunidades.temperatura_lead, "crm tem tá
    // preechido igual na compra lead quente frio e mornos").
    'vendas.documentos_token' => "ALTER TABLE vendas ADD COLUMN documentos_token TEXT",
    'vendas.temperatura_lead' => "ALTER TABLE vendas ADD COLUMN temperatura_lead TEXT DEFAULT ''",
];

foreach ($migracoes as $nome => $sql) {
    try {
        $db->exec($sql);
        echo "✅ {$nome}: adicionada\n";
    } catch (Throwable $e) {
        if (str_contains($e->getMessage(), 'duplicate column name')) {
            echo "⏭️  {$nome}: já existia\n";
        } else {
            echo "❌ {$nome}: {$e->getMessage()}\n";
        }
    }
}

try {
    $db->exec("CREATE INDEX IF NOT EXISTS idx_pendencias_oportunidade ON oportunidade_pendencias_pos_venda(oportunidade_id)");
    echo "✅ idx_pendencias_oportunidade: ok\n";
} catch (Throwable $e) {
    echo "❌ idx_pendencias_oportunidade: {$e->getMessage()}\n";
}

// 19/09/2026 — "espelhar compra" no módulo de vendas: documentos que o
// COMPRADOR sobe sozinho no wizard público (public/documentos_venda.php) —
// tabela PRÓPRIA, nunca reaproveita oportunidade_documentos (que é sobre o
// vendedor original). Texto idêntico ao de install/schema.sql.
try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS venda_documentos (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            venda_id INTEGER NOT NULL REFERENCES vendas(id),
            tipo TEXT NOT NULL,
            arquivo_url TEXT DEFAULT '',
            drive_file_id TEXT DEFAULT '',
            obrigatorio INTEGER DEFAULT 1,
            enviado_pelo_cliente INTEGER DEFAULT 0,
            dados_confirmados INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT (datetime('now','localtime')),
            updated_at DATETIME DEFAULT (datetime('now','localtime')),
            UNIQUE(venda_id, tipo)
        )
    ");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_venda_documentos_venda ON venda_documentos(venda_id)");
    $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_vendas_documentos_token ON vendas(documentos_token) WHERE documentos_token IS NOT NULL");
    echo "✅ venda_documentos: tabela + índices ok\n";
} catch (Throwable $e) {
    echo "❌ venda_documentos: {$e->getMessage()}\n";
}

// 17/09/2026 — "vamos deixar opcional o laudo e comprovante de pagamento
// opcional para fechar pasta": comprovante_pagamento/laudo_avaliacao
// deixaram de ser obrigatórios pro checklist de fechamento (regra #7),
// mas linhas de oportunidade_documentos JÁ CRIADAS antes dessa mudança
// (garantirLinhasDocumentosObrigatorios() só roda INSERT OR IGNORE, nunca
// atualiza linha existente) continuariam travando o fechamento com
// obrigatorio=1 do jeito antigo se não forem corrigidas aqui — idempotente,
// só afeta linhas que ainda estejam com o valor antigo.
try {
    $afetadas = $db->exec("
        UPDATE oportunidade_documentos SET obrigatorio = 0
        WHERE tipo IN ('comprovante_pagamento', 'laudo_avaliacao') AND obrigatorio = 1
    ");
    echo ($afetadas > 0 ? "✅" : "⏭️ ") . " oportunidade_documentos (comprovante_pagamento/laudo_avaliacao → opcional): {$afetadas} linha(s)\n";
} catch (Throwable $e) {
    echo "❌ oportunidade_documentos (comprovante_pagamento/laudo_avaliacao → opcional): {$e->getMessage()}\n";
}

// 17/09/2026, achado real: "fechamos cliente mais não mostra tipo negocio
// fechado esse mes" — valor_final/data_compra/fechado_por (colunas do
// bloco 8) existiam desde o schema original mas mudarEtapa()
// (includes/oportunidades.php) nunca as preenchia ao fechar uma
// oportunidade; corrigido no código, mas oportunidade que JÁ tinha
// fechado antes desse fix (ex: cliente Bianca) continua com essas 3
// colunas NULL pra sempre sem esse backfill — nunca apareceria em
// "fechadas/valor fechado este mês" nem na frota (admin/veiculos.php)
// só por causa disso, mesmo sendo um negócio genuinamente fechado.
// Idempotente — só toca oportunidade já 'fechado' com alguma das 3
// colunas ainda vazia; data_compra/fechado_por vêm do histórico (data e
// responsável de quando a etapa virou 'fechado' de verdade), fallback pro
// responsavel_id atual da oportunidade se não achar linha de histórico.
try {
    $afetadas = $db->exec("
        UPDATE oportunidades SET
            valor_final = COALESCE(valor_final, valor_ofertado),
            data_compra = COALESCE(data_compra, (
                SELECT date(h.created_at) FROM oportunidade_historico h
                WHERE h.oportunidade_id = oportunidades.id AND h.etapa_nova = 'fechado'
                ORDER BY h.id DESC LIMIT 1
            )),
            fechado_por = COALESCE(fechado_por, (
                SELECT h.responsavel_id FROM oportunidade_historico h
                WHERE h.oportunidade_id = oportunidades.id AND h.etapa_nova = 'fechado'
                ORDER BY h.id DESC LIMIT 1
            ), responsavel_id)
        WHERE etapa = 'fechado' AND (data_compra IS NULL OR fechado_por IS NULL OR valor_final IS NULL)
    ");
    echo ($afetadas > 0 ? "✅" : "⏭️ ") . " oportunidades (backfill valor_final/data_compra/fechado_por de fechamentos antigos): {$afetadas} linha(s)\n";
} catch (Throwable $e) {
    echo "❌ oportunidades (backfill valor_final/data_compra/fechado_por): {$e->getMessage()}\n";
}

// 13/09/2026 — perfis 'consultor' e 'closer' mesclados (pedido do José): a
// mesma pessoa atende (bloco 5) e negocia/fecha (bloco 6). Não dá pra tirar
// 'closer' da CHECK sem reconstruir a tabela no SQLite, mas dá pra garantir
// que nenhuma linha existente continue com esse valor — idempotente, rodar
// de novo só afeta 0 linhas depois da 1ª vez.
try {
    $afetadas = $db->exec("UPDATE usuarios SET perfil = 'consultor' WHERE perfil = 'closer'");
    echo ($afetadas > 0 ? "✅" : "⏭️ ") . " usuarios.perfil (closer→consultor): {$afetadas} linha(s) convertida(s)\n";
} catch (Throwable $e) {
    echo "❌ usuarios.perfil (closer→consultor): {$e->getMessage()}\n";
}

// 13/09/2026 — ZapSign substitui a Assinafy (pedido do José/Jean). Renomeia
// as colunas de identificador em vez de só adicionar novas — preserva o
// dado se algum contrato já tivesse sido enviado pra Assinafy antes da
// troca (nunca aconteceu de verdade neste projeto até aqui, ver CLAUDE.md,
// mas não custa não perder o histórico). RENAME COLUMN existe desde SQLite
// 3.25 (2018), bem antes de qualquer PHP/SQLite que rode este projeto.
foreach ([['assinafy_doc_id', 'zapsign_doc_token'], ['assinafy_signer_id', 'zapsign_signer_token']] as [$antiga, $nova]) {
    if (colunaExiste($db, 'contratos', $nova)) {
        echo "⏭️  contratos.{$nova}: já existia\n";
    } elseif (colunaExiste($db, 'contratos', $antiga)) {
        try {
            $db->exec("ALTER TABLE contratos RENAME COLUMN {$antiga} TO {$nova}");
            echo "✅ contratos.{$nova}: renomeada de {$antiga}\n";
        } catch (Throwable $e) {
            echo "❌ contratos.{$nova}: {$e->getMessage()}\n";
        }
    } else {
        try {
            $db->exec("ALTER TABLE contratos ADD COLUMN {$nova} TEXT DEFAULT ''");
            echo "✅ contratos.{$nova}: adicionada\n";
        } catch (Throwable $e) {
            echo "❌ contratos.{$nova}: {$e->getMessage()}\n";
        }
    }
}

// 15/09/2026 — WhatsApp Box (admin/whatsapp_inbox.php): "não lida" por
// mensagem, pro badge de conversa pendente. Checa ANTES de adicionar a
// coluna pra só rodar o backfill na 1ª vez: sem isso, um SQLite ALTER TABLE
// ADD COLUMN aplica o DEFAULT 0 em toda linha já existente (mensagem
// antiga viraria "não lida" do nada); e se o backfill rodasse de novo em
// deploys futuros, marcaria como lida uma mensagem de verdade ainda não
// vista (teto de "created_at < agora" pegaria qualquer uma com mais de
// alguns segundos). Rodar só 1x, condicionado à coluna não existir ainda,
// resolve os dois problemas.
if (!colunaExiste($db, 'whatsapp_mensagens', 'lida')) {
    try {
        $db->exec("ALTER TABLE whatsapp_mensagens ADD COLUMN lida INTEGER DEFAULT 0");
        $afetadas = $db->exec("UPDATE whatsapp_mensagens SET lida = 1 WHERE direcao = 'in'");
        echo "✅ whatsapp_mensagens.lida: adicionada, {$afetadas} mensagem(ns) antiga(s) marcada(s) como já lida(s)\n";
    } catch (Throwable $e) {
        echo "❌ whatsapp_mensagens.lida: {$e->getMessage()}\n";
    }
} else {
    echo "⏭️  whatsapp_mensagens.lida: já existia\n";
}

// 15/09/2026 — perfil 'supervisor' novo (pedido José/Jean: "preciso ter
// perfil de supervisão que vai acompanhar tudo que consultores está
// fazendo"). A CHECK de usuarios.perfil precisa aceitar esse valor —
// SQLite não tem ALTER TABLE pra mudar CHECK constraint, só reconstruindo
// a tabela (mesmo caso já documentado no CHECK original: "recriar a CHECK
// sem ele exigiria reconstruir a tabela toda"). Idempotente: só reconstrói
// se a CHECK atual ainda não aceitar 'supervisor' — checa o SQL da própria
// tabela em sqlite_master antes de fazer qualquer coisa.
try {
    $sqlAtual = (string)$db->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='usuarios'")->fetchColumn();
    if ($sqlAtual && !str_contains($sqlAtual, "'supervisor'")) {
        $db->exec('BEGIN');
        $db->exec("
            CREATE TABLE usuarios_novo (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                nome TEXT NOT NULL,
                email TEXT UNIQUE,
                whatsapp TEXT DEFAULT '',
                senha_hash TEXT NOT NULL,
                perfil TEXT DEFAULT 'consultor' CHECK (perfil IN ('super_admin','closer','consultor','supervisor')),
                bloqueado INTEGER DEFAULT 0,
                disponivel INTEGER DEFAULT 0,
                plantao_fim_expediente INTEGER DEFAULT 0,
                ultimo_lead_recebido_em DATETIME,
                posicao_fila INTEGER DEFAULT 0,
                created_at DATETIME DEFAULT (datetime('now','localtime'))
            )
        ");
        $db->exec("
            INSERT INTO usuarios_novo (id, nome, email, whatsapp, senha_hash, perfil, bloqueado, disponivel, plantao_fim_expediente, ultimo_lead_recebido_em, posicao_fila, created_at)
            SELECT id, nome, email, whatsapp, senha_hash, perfil, bloqueado, disponivel, plantao_fim_expediente, ultimo_lead_recebido_em, posicao_fila, created_at FROM usuarios
        ");
        $db->exec('DROP TABLE usuarios');
        $db->exec('ALTER TABLE usuarios_novo RENAME TO usuarios');
        $db->exec('COMMIT');
        echo "✅ usuarios.perfil: CHECK reconstruída pra aceitar 'supervisor'\n";
    } else {
        echo "⏭️  usuarios.perfil (CHECK supervisor): já existia\n";
    }
} catch (Throwable $e) {
    try { $db->exec('ROLLBACK'); } catch (Throwable $e2) { /* nada em aberto pra desfazer */ }
    echo "❌ usuarios.perfil (CHECK supervisor): {$e->getMessage()}\n";
}

// 15/09/2026 — a integração de FIPE completa trocou de provedor no meio
// do processo (Parallelum FIPE v2, nunca chegou a ir pro ar → PlacaFIPE,
// depois que o usuário mandou a doc real). Se alguém já tinha salvo um
// token em `fipe_v2_token` (nome antigo) antes da troca, copia pro nome
// novo `placafipe_token` — idempotente, só copia se o destino ainda
// estiver vazio, nunca sobrescreve um token novo já configurado.
try {
    $tokenAntigo = getConfig('fipe_v2_token');
    $tokenNovo = getConfig('placafipe_token');
    if ($tokenAntigo && !$tokenNovo) {
        setConfig('placafipe_token', $tokenAntigo);
        echo "✅ config.placafipe_token: copiado de fipe_v2_token (troca de provedor FIPE)\n";
    } else {
        echo "⏭️  config.placafipe_token: nada a copiar\n";
    }
} catch (Throwable $e) {
    echo "❌ config.placafipe_token: {$e->getMessage()}\n";
}

// 17/09/2026 — perfil 'vendedor' novo (módulo de vendas ganhou funil de
// entrada de lead pelo WhatsApp, "igual de compra" — pedido José/Jean).
// Mesma técnica da migração 'supervisor' acima: SQLite não tem ALTER TABLE
// pra CHECK constraint, só reconstruindo a tabela. Idempotente — só
// reconstrói se a CHECK atual ainda não aceitar 'vendedor'.
try {
    $sqlAtual = (string)$db->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='usuarios'")->fetchColumn();
    if ($sqlAtual && !str_contains($sqlAtual, "'vendedor'")) {
        $db->exec('BEGIN');
        $db->exec("
            CREATE TABLE usuarios_novo (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                nome TEXT NOT NULL,
                email TEXT UNIQUE,
                whatsapp TEXT DEFAULT '',
                senha_hash TEXT NOT NULL,
                perfil TEXT DEFAULT 'consultor' CHECK (perfil IN ('super_admin','closer','consultor','supervisor','vendedor')),
                bloqueado INTEGER DEFAULT 0,
                disponivel INTEGER DEFAULT 0,
                plantao_fim_expediente INTEGER DEFAULT 0,
                ultimo_lead_recebido_em DATETIME,
                posicao_fila INTEGER DEFAULT 0,
                created_at DATETIME DEFAULT (datetime('now','localtime'))
            )
        ");
        $db->exec("
            INSERT INTO usuarios_novo (id, nome, email, whatsapp, senha_hash, perfil, bloqueado, disponivel, plantao_fim_expediente, ultimo_lead_recebido_em, posicao_fila, created_at)
            SELECT id, nome, email, whatsapp, senha_hash, perfil, bloqueado, disponivel, plantao_fim_expediente, ultimo_lead_recebido_em, posicao_fila, created_at FROM usuarios
        ");
        $db->exec('DROP TABLE usuarios');
        $db->exec('ALTER TABLE usuarios_novo RENAME TO usuarios');
        $db->exec('COMMIT');
        echo "✅ usuarios.perfil: CHECK reconstruída pra aceitar 'vendedor'\n";
    } else {
        echo "⏭️  usuarios.perfil (CHECK vendedor): já existia\n";
    }
} catch (Throwable $e) {
    try { $db->exec('ROLLBACK'); } catch (Throwable $e2) { /* nada em aberto pra desfazer */ }
    echo "❌ usuarios.perfil (CHECK vendedor): {$e->getMessage()}\n";
}

// 17/09/2026 — vendas ganhou funil de entrada pelo WhatsApp (lead do
// comprador, "igual de compra"): oportunidade_id precisa virar nullable
// (lead pode chegar antes de saber qual veículo específico quer), etapa
// ganha 'whatsapp'/'qualificacao_ia'/'sem_perfil', e colunas novas de
// qualificação (origem, resumo_ia, veiculo_interesse_texto,
// forma_pagamento_pretendida, urgencia, motivo_perda). SQLite não tem
// ALTER COLUMN pra tirar NOT NULL nem mudar CHECK — reconstrói a tabela,
// mesma técnica de sempre. Idempotente — só reconstrói se a CHECK atual
// ainda não aceitar 'whatsapp' como etapa de venda.
try {
    $sqlAtual = (string)$db->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='vendas'")->fetchColumn();
    if ($sqlAtual && !str_contains($sqlAtual, "'whatsapp', 'qualificacao_ia'")) {
        $db->exec('BEGIN');
        $db->exec("
            CREATE TABLE vendas_novo (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                oportunidade_id INTEGER REFERENCES oportunidades(id),
                etapa TEXT NOT NULL DEFAULT 'negociacao'
                    CHECK (etapa IN ('whatsapp', 'qualificacao_ia', 'negociacao', 'contrato_enviado', 'vendido', 'cancelada', 'sem_perfil')),
                origem TEXT NOT NULL DEFAULT 'manual' CHECK (origem IN ('manual', 'whatsapp')),
                responsavel_id INTEGER REFERENCES usuarios(id),
                proxima_acao TEXT DEFAULT '',
                proxima_acao_em DATETIME,
                motivo_cancelamento TEXT DEFAULT '',
                motivo_perda TEXT DEFAULT '',
                resumo_ia TEXT DEFAULT '',
                veiculo_interesse_texto TEXT DEFAULT '',
                forma_pagamento_pretendida TEXT DEFAULT '',
                urgencia TEXT DEFAULT '',
                comprador_nome TEXT DEFAULT '',
                comprador_nacionalidade TEXT DEFAULT 'brasileiro(a)',
                comprador_estado_civil TEXT DEFAULT '',
                comprador_profissao TEXT DEFAULT '',
                comprador_rg TEXT DEFAULT '',
                comprador_cpf TEXT DEFAULT '',
                comprador_cnh TEXT DEFAULT '',
                comprador_endereco TEXT DEFAULT '',
                comprador_telefone TEXT DEFAULT '',
                comprador_email TEXT DEFAULT '',
                km_entrega INTEGER,
                preco_venda REAL,
                valor_pago_contratacao REAL,
                forma_pagamento TEXT DEFAULT '',
                saldo_preco_devido REAL,
                prazo_quitacao_meses INTEGER DEFAULT 24,
                data_limite_quitacao DATE,
                prestacao_contas_texto TEXT DEFAULT '',
                seguro_texto TEXT DEFAULT '',
                ipva_responsavel_texto TEXT DEFAULT '',
                multas_texto TEXT DEFAULT 'COMPRADOR, na extensão legal aplicável',
                rastreador_texto TEXT DEFAULT '',
                prazo_transferencia_dias INTEGER,
                penalidade_atraso_texto TEXT DEFAULT '',
                data_venda DATE,
                created_at DATETIME DEFAULT (datetime('now','localtime')),
                updated_at DATETIME DEFAULT (datetime('now','localtime'))
            )
        ");
        $db->exec("
            INSERT INTO vendas_novo (
                id, oportunidade_id, etapa, responsavel_id, proxima_acao, proxima_acao_em, motivo_cancelamento,
                comprador_nome, comprador_nacionalidade, comprador_estado_civil, comprador_profissao, comprador_rg,
                comprador_cpf, comprador_cnh, comprador_endereco, comprador_telefone, comprador_email,
                km_entrega, preco_venda, valor_pago_contratacao, forma_pagamento, saldo_preco_devido,
                prazo_quitacao_meses, data_limite_quitacao, prestacao_contas_texto, seguro_texto,
                ipva_responsavel_texto, multas_texto, rastreador_texto, prazo_transferencia_dias,
                penalidade_atraso_texto, data_venda, created_at, updated_at
            )
            SELECT
                id, oportunidade_id, etapa, responsavel_id, proxima_acao, proxima_acao_em, motivo_cancelamento,
                comprador_nome, comprador_nacionalidade, comprador_estado_civil, comprador_profissao, comprador_rg,
                comprador_cpf, comprador_cnh, comprador_endereco, comprador_telefone, comprador_email,
                km_entrega, preco_venda, valor_pago_contratacao, forma_pagamento, saldo_preco_devido,
                prazo_quitacao_meses, data_limite_quitacao, prestacao_contas_texto, seguro_texto,
                ipva_responsavel_texto, multas_texto, rastreador_texto, prazo_transferencia_dias,
                penalidade_atraso_texto, data_venda, created_at, updated_at
            FROM vendas
        ");
        $db->exec('DROP TABLE vendas');
        $db->exec('ALTER TABLE vendas_novo RENAME TO vendas');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_vendas_oportunidade ON vendas(oportunidade_id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_vendas_etapa ON vendas(etapa)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_vendas_proxima_acao ON vendas(proxima_acao_em)');
        $db->exec("
            CREATE UNIQUE INDEX IF NOT EXISTS idx_vendas_ativa_por_veiculo
                ON vendas(oportunidade_id) WHERE etapa IN ('negociacao', 'contrato_enviado')
        ");
        $db->exec('COMMIT');
        echo "✅ vendas: oportunidade_id agora nullable, etapa aceita whatsapp/qualificacao_ia/sem_perfil, colunas novas de qualificação por IA adicionadas\n";
    } else {
        echo "⏭️  vendas (funil de entrada por WhatsApp): já existia\n";
    }
} catch (Throwable $e) {
    try { $db->exec('ROLLBACK'); } catch (Throwable $e2) { /* nada em aberto pra desfazer */ }
    echo "❌ vendas (funil de entrada por WhatsApp): {$e->getMessage()}\n";
}

// 17/09/2026 — catálogo de fotos/vídeos de veículo da frota pra revenda
// ("ela precisa enviar fotos do veículos - vídeo") + coluna de controle
// pra não reenviar a mesma mídia a cada turno da conversa.
try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS veiculo_midias_revenda (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            oportunidade_id INTEGER NOT NULL REFERENCES oportunidades(id),
            tipo TEXT NOT NULL CHECK (tipo IN ('foto', 'video')),
            mime TEXT NOT NULL DEFAULT '',
            drive_file_id TEXT DEFAULT '',
            arquivo_url TEXT DEFAULT '',
            legenda TEXT DEFAULT '',
            created_at DATETIME DEFAULT (datetime('now','localtime'))
        )
    ");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_veiculo_midias_oportunidade ON veiculo_midias_revenda(oportunidade_id)");
    echo "✅ veiculo_midias_revenda: tabela pronta\n";
} catch (Throwable $e) {
    echo "❌ veiculo_midias_revenda: {$e->getMessage()}\n";
}
if (!colunaExiste($db, 'vendas', 'midia_sugerida_enviada_para')) {
    try {
        $db->exec("ALTER TABLE vendas ADD COLUMN midia_sugerida_enviada_para INTEGER REFERENCES oportunidades(id)");
        echo "✅ vendas.midia_sugerida_enviada_para: adicionada\n";
    } catch (Throwable $e) {
        echo "❌ vendas.midia_sugerida_enviada_para: {$e->getMessage()}\n";
    }
} else {
    echo "⏭️  vendas.midia_sugerida_enviada_para: já existia\n";
}

// 17/09/2026 — perfil 'financeiro' novo (pedido José/Jean: "criar perfil
// gestão financeira") — mesma técnica de reconstrução de tabela das
// migrações 'supervisor'/'vendedor' acima (SQLite não tem ALTER TABLE pra
// CHECK constraint). Idempotente — só reconstrói se a CHECK atual ainda
// não aceitar 'financeiro'.
try {
    $sqlAtual = (string)$db->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='usuarios'")->fetchColumn();
    if ($sqlAtual && !str_contains($sqlAtual, "'financeiro'")) {
        $db->exec('BEGIN');
        $db->exec("
            CREATE TABLE usuarios_novo (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                nome TEXT NOT NULL,
                email TEXT UNIQUE,
                whatsapp TEXT DEFAULT '',
                senha_hash TEXT NOT NULL,
                perfil TEXT DEFAULT 'consultor' CHECK (perfil IN ('super_admin','closer','consultor','supervisor','vendedor','financeiro')),
                bloqueado INTEGER DEFAULT 0,
                disponivel INTEGER DEFAULT 0,
                plantao_fim_expediente INTEGER DEFAULT 0,
                ultimo_lead_recebido_em DATETIME,
                posicao_fila INTEGER DEFAULT 0,
                created_at DATETIME DEFAULT (datetime('now','localtime'))
            )
        ");
        $db->exec("
            INSERT INTO usuarios_novo (id, nome, email, whatsapp, senha_hash, perfil, bloqueado, disponivel, plantao_fim_expediente, ultimo_lead_recebido_em, posicao_fila, created_at)
            SELECT id, nome, email, whatsapp, senha_hash, perfil, bloqueado, disponivel, plantao_fim_expediente, ultimo_lead_recebido_em, posicao_fila, created_at FROM usuarios
        ");
        $db->exec('DROP TABLE usuarios');
        $db->exec('ALTER TABLE usuarios_novo RENAME TO usuarios');
        $db->exec('COMMIT');
        echo "✅ usuarios.perfil: CHECK reconstruída pra aceitar 'financeiro'\n";
    } else {
        echo "⏭️  usuarios.perfil (CHECK financeiro): já existia\n";
    }
} catch (Throwable $e) {
    try { $db->exec('ROLLBACK'); } catch (Throwable $e2) { /* nada em aberto pra desfazer */ }
    echo "❌ usuarios.perfil (CHECK financeiro): {$e->getMessage()}\n";
}

// 17/09/2026 — módulo financeiro (portado do JurídicoSaaS, adaptado — ver
// nota grande em install/schema.sql). CREATE TABLE IF NOT EXISTS é
// idempotente por natureza, sem precisar checar antes.
try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS fin_categorias (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            nome TEXT NOT NULL,
            tipo TEXT NOT NULL DEFAULT 'despesa' CHECK (tipo IN ('receita','despesa')),
            natureza_sugerida TEXT DEFAULT '',
            icone TEXT DEFAULT '💰',
            grupo_dre TEXT DEFAULT '',
            ativo INTEGER DEFAULT 1,
            created_at DATETIME DEFAULT (datetime('now','localtime'))
        )
    ");
    $db->exec("
        CREATE TABLE IF NOT EXISTS fin_fornecedores (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            nome TEXT NOT NULL,
            cnpj_cpf TEXT DEFAULT '',
            contato TEXT DEFAULT '',
            observacoes TEXT DEFAULT '',
            status TEXT DEFAULT 'ativo' CHECK (status IN ('ativo','inativo')),
            created_at DATETIME DEFAULT (datetime('now','localtime')),
            updated_at DATETIME DEFAULT (datetime('now','localtime'))
        )
    ");
    $db->exec("
        CREATE TABLE IF NOT EXISTS fin_colaboradores (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            nome TEXT NOT NULL,
            cargo TEXT DEFAULT '',
            tipo_vinculo TEXT DEFAULT 'clt',
            salario_base REAL DEFAULT NULL,
            usuario_id INTEGER DEFAULT NULL REFERENCES usuarios(id),
            status TEXT DEFAULT 'ativo' CHECK (status IN ('ativo','inativo')),
            data_admissao TEXT DEFAULT NULL,
            observacoes TEXT DEFAULT '',
            created_at DATETIME DEFAULT (datetime('now','localtime')),
            updated_at DATETIME DEFAULT (datetime('now','localtime'))
        )
    ");
    $db->exec("
        CREATE TABLE IF NOT EXISTS fin_lancamentos (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tipo TEXT NOT NULL DEFAULT 'despesa' CHECK (tipo IN ('receita','despesa')),
            categoria_id INTEGER DEFAULT NULL REFERENCES fin_categorias(id),
            descricao TEXT NOT NULL,
            valor REAL NOT NULL DEFAULT 0,
            natureza TEXT DEFAULT '',
            data_vencimento TEXT DEFAULT NULL,
            data_pagamento TEXT DEFAULT NULL,
            status TEXT NOT NULL DEFAULT 'pendente' CHECK (status IN ('pendente','pago','atrasado','cancelado')),
            cliente_id INTEGER DEFAULT NULL REFERENCES clientes(id),
            cliente_nome_manual TEXT DEFAULT '',
            oportunidade_id INTEGER DEFAULT NULL REFERENCES oportunidades(id),
            venda_id INTEGER DEFAULT NULL REFERENCES vendas(id),
            parcela_numero INTEGER DEFAULT NULL,
            parcela_total INTEGER DEFAULT NULL,
            funcionario_id INTEGER DEFAULT NULL REFERENCES fin_colaboradores(id),
            fornecedor_id INTEGER DEFAULT NULL REFERENCES fin_fornecedores(id),
            forma_pagamento TEXT DEFAULT '',
            recorrente INTEGER DEFAULT 0,
            recorrencia_intervalo TEXT DEFAULT '',
            recorrencia_origem_id INTEGER DEFAULT NULL,
            drive_file_id TEXT DEFAULT '',
            arquivo_url TEXT DEFAULT '',
            observacoes TEXT DEFAULT '',
            origem TEXT NOT NULL DEFAULT 'manual' CHECK (origem IN ('manual','parcelamento_venda','asaas')),
            asaas_payment_id TEXT DEFAULT NULL,
            asaas_customer_id TEXT DEFAULT NULL,
            created_by INTEGER DEFAULT NULL REFERENCES usuarios(id),
            created_at DATETIME DEFAULT (datetime('now','localtime')),
            updated_at DATETIME DEFAULT (datetime('now','localtime'))
        )
    ");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_fin_lancamentos_venda ON fin_lancamentos(venda_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_fin_lancamentos_oportunidade ON fin_lancamentos(oportunidade_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_fin_lancamentos_status ON fin_lancamentos(status)");
    $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_fin_lancamentos_asaas_payment ON fin_lancamentos(asaas_payment_id) WHERE asaas_payment_id IS NOT NULL");
    $db->exec("
        CREATE TABLE IF NOT EXISTS fin_asaas_clientes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            asaas_id TEXT NOT NULL UNIQUE,
            nome TEXT DEFAULT '',
            cpf_cnpj TEXT DEFAULT '',
            email TEXT DEFAULT '',
            telefone TEXT DEFAULT '',
            cliente_id INTEGER DEFAULT NULL REFERENCES clientes(id),
            venda_id INTEGER DEFAULT NULL REFERENCES vendas(id),
            created_at DATETIME DEFAULT (datetime('now','localtime')),
            updated_at DATETIME DEFAULT (datetime('now','localtime'))
        )
    ");
    echo "✅ módulo financeiro: fin_categorias/fin_fornecedores/fin_colaboradores/fin_lancamentos/fin_asaas_clientes prontas\n";
} catch (Throwable $e) {
    echo "❌ módulo financeiro (tabelas): {$e->getMessage()}\n";
}

// 19/09/2026 — despesa automática ao fechar compra ("sai do caixa quando
// compra veiculo"), pedido direto do usuário pra conciliar negociação com
// financeiro. fin_lancamentos.origem ganhou o valor 'fechamento_compra'
// (finRegistrarDespesaCompraFechada(), includes/financeiro.php, chamada
// de dentro de mudarEtapa() na transição pra 'fechado') — mesma técnica de
// reconstrução de CHECK das migrações de usuarios.perfil acima (SQLite não
// tem ALTER TABLE pra CHECK). Idempotente — só reconstrói se a CHECK atual
// ainda não aceitar 'fechamento_compra'.
try {
    $sqlAtual = (string)$db->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='fin_lancamentos'")->fetchColumn();
    if ($sqlAtual && !str_contains($sqlAtual, "'fechamento_compra'")) {
        $db->exec('BEGIN');
        $db->exec("
            CREATE TABLE fin_lancamentos_novo (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tipo TEXT NOT NULL DEFAULT 'despesa' CHECK (tipo IN ('receita','despesa')),
                categoria_id INTEGER DEFAULT NULL REFERENCES fin_categorias(id),
                descricao TEXT NOT NULL,
                valor REAL NOT NULL DEFAULT 0,
                natureza TEXT DEFAULT '',
                data_vencimento TEXT DEFAULT NULL,
                data_pagamento TEXT DEFAULT NULL,
                status TEXT NOT NULL DEFAULT 'pendente' CHECK (status IN ('pendente','pago','atrasado','cancelado')),
                cliente_id INTEGER DEFAULT NULL REFERENCES clientes(id),
                cliente_nome_manual TEXT DEFAULT '',
                oportunidade_id INTEGER DEFAULT NULL REFERENCES oportunidades(id),
                venda_id INTEGER DEFAULT NULL REFERENCES vendas(id),
                parcela_numero INTEGER DEFAULT NULL,
                parcela_total INTEGER DEFAULT NULL,
                funcionario_id INTEGER DEFAULT NULL REFERENCES fin_colaboradores(id),
                fornecedor_id INTEGER DEFAULT NULL REFERENCES fin_fornecedores(id),
                forma_pagamento TEXT DEFAULT '',
                recorrente INTEGER DEFAULT 0,
                recorrencia_intervalo TEXT DEFAULT '',
                recorrencia_origem_id INTEGER DEFAULT NULL,
                drive_file_id TEXT DEFAULT '',
                arquivo_url TEXT DEFAULT '',
                observacoes TEXT DEFAULT '',
                origem TEXT NOT NULL DEFAULT 'manual' CHECK (origem IN ('manual','parcelamento_venda','asaas','fechamento_compra')),
                asaas_payment_id TEXT DEFAULT NULL,
                asaas_customer_id TEXT DEFAULT NULL,
                created_by INTEGER DEFAULT NULL REFERENCES usuarios(id),
                created_at DATETIME DEFAULT (datetime('now','localtime')),
                updated_at DATETIME DEFAULT (datetime('now','localtime'))
            )
        ");
        $db->exec("
            INSERT INTO fin_lancamentos_novo
            SELECT id, tipo, categoria_id, descricao, valor, natureza, data_vencimento, data_pagamento, status,
                   cliente_id, cliente_nome_manual, oportunidade_id, venda_id, parcela_numero, parcela_total,
                   funcionario_id, fornecedor_id, forma_pagamento, recorrente, recorrencia_intervalo, recorrencia_origem_id,
                   drive_file_id, arquivo_url, observacoes, origem, asaas_payment_id, asaas_customer_id, created_by,
                   created_at, updated_at
            FROM fin_lancamentos
        ");
        $db->exec('DROP TABLE fin_lancamentos');
        $db->exec('ALTER TABLE fin_lancamentos_novo RENAME TO fin_lancamentos');
        $db->exec("CREATE INDEX IF NOT EXISTS idx_fin_lancamentos_venda ON fin_lancamentos(venda_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_fin_lancamentos_oportunidade ON fin_lancamentos(oportunidade_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_fin_lancamentos_status ON fin_lancamentos(status)");
        $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_fin_lancamentos_asaas_payment ON fin_lancamentos(asaas_payment_id) WHERE asaas_payment_id IS NOT NULL");
        $db->exec('COMMIT');
        echo "✅ fin_lancamentos.origem: CHECK reconstruída pra aceitar 'fechamento_compra'\n";
    } else {
        echo "⏭️  fin_lancamentos.origem (CHECK fechamento_compra): já existia\n";
    }
} catch (Throwable $e) {
    try { $db->exec('ROLLBACK'); } catch (Throwable $e2) { /* nada em aberto pra desfazer */ }
    echo "❌ fin_lancamentos.origem (CHECK fechamento_compra): {$e->getMessage()}\n";
}

// Categorias padrão do financeiro Fastcar — só insere as que ainda não
// existem (por nome), mesmo padrão idempotente do JurídicoSaaS original.
try {
    $existentes = $db->query("SELECT nome FROM fin_categorias")->fetchAll(PDO::FETCH_COLUMN);
    $padroesFin = [
        ['Venda de veículo — entrada',        'receita', 'variavel', '🚗', 'receita'],
        ['Venda de veículo — parcela',        'receita', 'variavel', '💳', 'receita'],
        ['Venda de veículo — à vista',        'receita', 'variavel', '💵', 'receita'],
        ['Outras receitas',                   'receita', 'variavel', '💰', 'receita'],
        ['Compra de veículo (pagamento ao vendedor)', 'despesa', 'variavel', '🚙', 'operacional'],
        ['Comissão de consultor/vendedor',    'despesa', 'variavel', '🤝', 'operacional'],
        ['Manutenção/revisão de veículo',     'despesa', 'variavel', '🔧', 'operacional'],
        ['Despachante/documentação',          'despesa', 'variavel', '📄', 'operacional'],
        ['Combustível',                       'despesa', 'variavel', '⛽', 'operacional'],
        ['Salários',                          'despesa', 'fixa',     '👥', 'pessoal'],
        ['Pró-labore',                        'despesa', 'fixa',     '👔', 'pessoal'],
        ['FGTS',                              'despesa', 'fixa',     '📄', 'pessoal'],
        ['INSS',                              'despesa', 'fixa',     '📄', 'pessoal'],
        ['Aluguel',                           'despesa', 'fixa',     '🏢', 'administrativas'],
        ['Assinaturas de Software',           'despesa', 'fixa',     '💻', 'administrativas'],
        ['Material de Escritório',            'despesa', 'variavel', '🖇️', 'administrativas'],
        ['Impostos e Tributos',               'despesa', 'fixa',     '🧾', 'impostos_contabilidade'],
        ['Contabilidade',                     'despesa', 'fixa',     '📊', 'impostos_contabilidade'],
        ['Marketing',                         'despesa', 'variavel', '📣', 'marketing'],
        ['Outras Despesas',                   'despesa', 'variavel', '📦', 'outras'],
    ];
    $ins = $db->prepare("INSERT INTO fin_categorias (nome, tipo, natureza_sugerida, icone, grupo_dre) VALUES (?,?,?,?,?)");
    $novasInseridas = 0;
    foreach ($padroesFin as $p) {
        if (in_array($p[0], $existentes, true)) continue;
        $ins->execute($p);
        $novasInseridas++;
    }
    echo $novasInseridas > 0 ? "✅ {$novasInseridas} categoria(s) financeira(s) padrão nova(s) inserida(s)\n" : "⏭️  categorias financeiras padrão (já cadastradas)\n";
} catch (Throwable $e) {
    echo "❌ categorias financeiras padrão: {$e->getMessage()}\n";
}

// 17/09/2026 — qualificação por IA do comprador (vendas) ganhou parâmetros
// novos de orçamento e uso pretendido, pedido José/Jean: "qual a entrada
// valor da entrada que você tem, valor da parcela em seu orçamento, tipo
// de carro para passeio o aplicativo utilitário".
foreach ([
    ['tipo_uso_veiculo', "ALTER TABLE vendas ADD COLUMN tipo_uso_veiculo TEXT DEFAULT ''"],
    ['valor_entrada_disponivel', 'ALTER TABLE vendas ADD COLUMN valor_entrada_disponivel REAL'],
    ['valor_parcela_orcamento', 'ALTER TABLE vendas ADD COLUMN valor_parcela_orcamento REAL'],
] as [$coluna, $sql]) {
    if (!colunaExiste($db, 'vendas', $coluna)) {
        try {
            $db->exec($sql);
            echo "✅ vendas.{$coluna}: adicionada\n";
        } catch (Throwable $e) {
            echo "❌ vendas.{$coluna}: {$e->getMessage()}\n";
        }
    } else {
        echo "⏭️  vendas.{$coluna}: já existia\n";
    }
}

// 17/09/2026 — "Os consultores eles ganha o fixo cada 15 dias mais
// comição": periodicidade do salário/fixo do colaborador (mensal/
// quinzenal) — comissão em si é sempre lançamento manual avulso
// (confirmado com o usuário), nunca gerada daqui; geração automática do
// lançamento quinzenal via cron fica pra configurar depois (confirmado:
// "podemos configurar depois isso"), este campo só guarda o dado.
if (!colunaExiste($db, 'fin_colaboradores', 'periodicidade_pagamento')) {
    try {
        $db->exec("ALTER TABLE fin_colaboradores ADD COLUMN periodicidade_pagamento TEXT DEFAULT 'mensal'");
        echo "✅ fin_colaboradores.periodicidade_pagamento: adicionada\n";
    } catch (Throwable $e) {
        echo "❌ fin_colaboradores.periodicidade_pagamento: {$e->getMessage()}\n";
    }
} else {
    echo "⏭️  fin_colaboradores.periodicidade_pagamento: já existia\n";
}

// 18/09/2026, "isso que puxamos do assas são receitas de parcela dos
// veiculos temos organizar" — fill-if-empty da categoria padrão do Asaas
// (config.asaas_categoria_padrao_id): se ainda não foi configurada à mão,
// aponta sozinho pra "Venda de veículo — parcela" (já seedada acima, mesmo
// bloco desta migração) — nunca sobrescreve se o super_admin já tiver
// escolhido outra em Configurações → Asaas.
try {
    if (getConfig('asaas_categoria_padrao_id') === null) {
        $stmt = $db->prepare("SELECT id FROM fin_categorias WHERE nome = ?");
        $stmt->execute(['Venda de veículo — parcela']);
        $catId = $stmt->fetchColumn();
        if ($catId) {
            setConfig('asaas_categoria_padrao_id', (string)$catId);
            echo "✅ asaas_categoria_padrao_id: apontada pra 'Venda de veículo — parcela' (id {$catId})\n";
        } else {
            echo "⏭️  asaas_categoria_padrao_id: categoria 'Venda de veículo — parcela' não encontrada, pulando\n";
        }
    } else {
        echo "⏭️  asaas_categoria_padrao_id: já configurada\n";
    }
} catch (Throwable $e) {
    echo "❌ asaas_categoria_padrao_id: {$e->getMessage()}\n";
}

echo "\n🎉 Migração concluída.\n";
