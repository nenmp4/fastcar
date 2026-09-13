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

$migracoes = [
    // 09/2026 — testemunhas do contrato-mestre de compra
    'oportunidades.testemunha1_nome' => "ALTER TABLE oportunidades ADD COLUMN testemunha1_nome TEXT DEFAULT ''",
    'oportunidades.testemunha1_cpf'  => "ALTER TABLE oportunidades ADD COLUMN testemunha1_cpf TEXT DEFAULT ''",
    'oportunidades.testemunha2_nome' => "ALTER TABLE oportunidades ADD COLUMN testemunha2_nome TEXT DEFAULT ''",
    'oportunidades.testemunha2_cpf'  => "ALTER TABLE oportunidades ADD COLUMN testemunha2_cpf TEXT DEFAULT ''",
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

echo "\n🎉 Migração concluída.\n";
