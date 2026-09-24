<?php
/**
 * Patrimônio da empresa (24/09/2026, "Coloção empresa para cadastrar
 * patrimônio da empresa imobiliária informática e outros") — cadastro de
 * bens fixos da Fastcar (imóveis, equipamentos de informática, mobiliário
 * etc.), DIFERENTE de:
 * - Frota (`admin/veiculos.php`) — veículo comprado como ESTOQUE pra
 *   revenda, não um bem fixo da empresa;
 * - DRE/Financeiro (`includes/financeiro.php`) — receita/despesa do
 *   PERÍODO, não um registro de patrimônio.
 *
 * Confirmado com o usuário via 3 perguntas diretas antes de codar:
 * (1) acesso: só super_admin, mesma trava de Frota/Backup/Configurações;
 * (2) campos: versão "básica" — categoria, nome/descrição, valor de
 *     aquisição, data de compra, local/responsável, status — sem
 *     depreciação, anexo de documento ou número de etiqueta nesta 1ª
 *     versão (ficam como possível próxima iteração se a equipe sentir
 *     falta, mesmo espírito de escopo enxuto já documentado no resto do
 *     projeto);
 * (3) cadastrar um item NUNCA gera lançamento financeiro sozinho —
 *     inventário/patrimônio e o fluxo de caixa (Financeiro → Lançamentos)
 *     ficam propositalmente separados nesta 1ª versão; lançar a despesa da
 *     compra continua manual, como qualquer outra despesa.
 *
 * `valor_aquisicao`/`data_aquisicao` são nullable de propósito (regra #3,
 * "IA/sistema nunca inventa" — aqui vale pro cadastro humano também: item
 * antigo sem nota fiscal/valor conhecido fica pendente, nunca um número
 * chutado).
 */
require_once __DIR__ . '/db.php';

const PATRIMONIO_CATEGORIAS = [
    'imovel' => '🏢 Imóvel',
    'informatica' => '💻 Informática',
    'mobiliario' => '🪑 Mobiliário',
    'outros' => '📦 Outros',
];

const PATRIMONIO_STATUS = [
    'ativo' => 'Ativo',
    'vendido' => 'Vendido',
    'baixado' => 'Baixado/descartado',
];

/** Lista com filtro opcional por categoria/status/busca livre (nome ou local/responsável). */
function listarPatrimonio(string $categoria = '', string $status = '', string $q = ''): array {
    $where = [];
    $params = [];
    if ($categoria !== '') { $where[] = 'categoria = ?'; $params[] = $categoria; }
    if ($status !== '') { $where[] = 'status = ?'; $params[] = $status; }
    if ($q !== '') {
        $where[] = '(nome LIKE ? OR local_responsavel LIKE ? OR observacoes LIKE ?)';
        $like = '%' . $q . '%';
        $params[] = $like; $params[] = $like; $params[] = $like;
    }
    $sql = 'SELECT * FROM patrimonio_itens';
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY (status = \'ativo\') DESC, categoria, nome';
    $stmt = getDB()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function buscarItemPatrimonio(int $id): ?array {
    $stmt = getDB()->prepare('SELECT * FROM patrimonio_itens WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * $dados: categoria, nome, valor_aquisicao (float|null), data_aquisicao
 * (string|null), local_responsavel, status, observacoes, created_by.
 */
function criarItemPatrimonio(array $dados): int {
    $db = getDB();
    $db->prepare("
        INSERT INTO patrimonio_itens (categoria, nome, valor_aquisicao, data_aquisicao, local_responsavel, status, observacoes, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ")->execute([
        $dados['categoria'],
        $dados['nome'],
        $dados['valor_aquisicao'],
        $dados['data_aquisicao'],
        $dados['local_responsavel'] ?? '',
        $dados['status'] ?? 'ativo',
        $dados['observacoes'] ?? '',
        $dados['created_by'] ?? null,
    ]);
    return (int)$db->lastInsertId();
}

function atualizarItemPatrimonio(int $id, array $dados): void {
    getDB()->prepare("
        UPDATE patrimonio_itens SET categoria=?, nome=?, valor_aquisicao=?, data_aquisicao=?, local_responsavel=?, status=?, observacoes=?, updated_at=datetime('now','localtime')
        WHERE id=?
    ")->execute([
        $dados['categoria'],
        $dados['nome'],
        $dados['valor_aquisicao'],
        $dados['data_aquisicao'],
        $dados['local_responsavel'] ?? '',
        $dados['status'] ?? 'ativo',
        $dados['observacoes'] ?? '',
        $id,
    ]);
}

function excluirItemPatrimonio(int $id): void {
    getDB()->prepare('DELETE FROM patrimonio_itens WHERE id = ?')->execute([$id]);
}

/** Soma do valor de aquisição só dos itens ainda ATIVOS — nunca conta item vendido/baixado, e nunca chuta valor de item sem valor cadastrado (NULL fica fora da soma). */
function patrimonioValorTotalAtivo(): float {
    return (float)(getDB()->query("SELECT COALESCE(SUM(valor_aquisicao), 0) FROM patrimonio_itens WHERE status = 'ativo'")->fetchColumn());
}

/** Contagem por categoria, só itens ativos — usada nos cards de resumo da tela. */
function patrimonioContagemPorCategoria(): array {
    $stmt = getDB()->query("SELECT categoria, COUNT(*) AS qtd FROM patrimonio_itens WHERE status='ativo' GROUP BY categoria");
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[$r['categoria']] = (int)$r['qtd'];
    }
    return $out;
}
