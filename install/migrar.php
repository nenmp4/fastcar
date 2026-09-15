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

echo "\n🎉 Migração concluída.\n";
