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

echo "\n🎉 Migração concluída.\n";
