<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/vendas.php'; // VEICULO_MIDIA_MIME_PERMITIDOS/MAX_BYTES, veiculo_midias_revenda
require_once __DIR__ . '/documentos.php'; // salvarArquivoGeradoComoDocumento()
require_once __DIR__ . '/zapsign.php';
require_once __DIR__ . '/veiculo_avaliacoes_pdf.php';

/**
 * Módulo de checklist de vistoria/avaliação do veículo — 21/09/2026,
 * pedido direto: "temos montar modulo de chelist de verificação do
 * veiculo na venda compra acho ser global - tipo avalição marcar ok
 * adiciona as fotos - essas fotos vai servir para ia qualificação da
 * venda entendeu criar documento de entrega do veiculo enviar para email
 * WhatsApp para assinar". GLOBAL de propósito (`veiculo_avaliacoes.tipo`,
 * mesma tabela pra compra e venda) — cobre os dois momentos em que a
 * Fastcar precisa inspecionar o carro fisicamente: na ENTRADA (compra, o
 * vendedor original entrega o carro) e na SAÍDA (venda, o comprador novo
 * recebe o carro) — mesmo checklist, mesma disciplina de assinatura
 * eletrônica, signatário diferente conforme o `tipo`.
 *
 * Perfil dedicado `avaliador` (confirmado com o usuário — perfil novo, não
 * uma capacidade em cima de perfil existente): só acessa
 * admin/avaliacoes.php/admin/avaliacao.php, nunca funil de compra/vendas/
 * WhatsApp (mesma técnica de allowlist de admin/_bootstrap.php já usada
 * pra vendedor/financeiro). Atribuição de avaliador (quem faz a vistoria)
 * é aberta a super_admin/supervisor E ao responsável do próprio negócio
 * (consultor na compra, vendedor na venda) — confirmado com o usuário,
 * mesmo espírito de responsavel_id no resto do projeto.
 *
 * Fotos/vídeos colhidos na vistoria ficam numa galeria PRÓPRIA
 * (`veiculo_avaliacao_fotos`), separada de propósito de
 * `veiculo_midias_revenda` (o catálogo que a IA de vendas usa sozinha pra
 * mandar mídia pro comprador, includes/ia_qualificacao_vendas.php) —
 * confirmado com o usuário ("Separada, recomendado") e reforçado depois
 * ("as fotos que colher tem que vendedor aprovar para ia usar pois evitar
 * fotos desnecessário"): uma foto de vistoria só entra no catálogo de
 * vendas depois de aprovação EXPLÍCITA do vendedor
 * (aprovarFotoParaCatalogo()), nunca automática — evita a IA usar foto
 * ruim/desnecessária colhida durante a inspeção (ex: foto de um defeito
 * pontual, ângulo estranho, foto de documento).
 *
 * O "termo de entrega/vistoria" (PDF + assinatura eletrônica via ZapSign)
 * NUNCA reaproveita `contratos`/zapsignSincronizarContrato() — essa função
 * já é complexa o bastante amarrada a mudarEtapa()/mudarEtapaVenda(), que
 * não fazem sentido pra um termo de vistoria; os campos de assinatura
 * (zapsign_doc_token etc) moram direto em `veiculo_avaliacoes`, com sync
 * própria (sincronizarTermoAvaliacao()) — mesmo raciocínio "arquivo
 * próprio de propósito" já documentado várias vezes neste projeto pra
 * módulos com modelo de dado/regra de negócio diferente demais pra
 * copy-paste direto. Geração/envio é sempre AÇÃO MANUAL (confirmado com o
 * usuário) — nunca dispara sozinho ao concluir a avaliação, mesmo padrão
 * já usado nos contratos de compra/venda (botão "gerar e enviar", nunca
 * automático).
 */

// Itens fixos do checklist — exatamente os pedidos pelo usuário ("vericar
// km avarias suspenção motor vazamentos estofado" + "tava faltando cambio
// nesse checlist", 21/09/2026). KM fica fora da lista (é campo numérico
// próprio, veiculo_avaliacoes.km_atual, não um item ok/problema/não
// verificado).
// Rótulos ajustados em 21/09/2026 ("tem detalhe quando é moto") — a
// Fastcar compra carro/moto/caminhão/etc (mesma regra já reforçada na IA
// de qualificação), mas "lataria"/"estofado" sozinhos soam só de carro;
// generalizado pra cobrir carenagem/banco de moto também, sem precisar de
// um campo de "tipo de veículo" novo — o checklist continua o mesmo pros
// dois, só o texto ficou neutro o bastante pra fazer sentido nos dois
// casos (a `item` (chave) nunca mudou, então nenhuma avaliação já criada
// precisa de migração).
const VEICULO_AVALIACAO_ITENS_CARRO = [
    'avarias'    => 'Avarias (lataria/carenagem/pintura/amassados/riscos)',
    'motor'      => 'Motor',
    'cambio'     => 'Câmbio',
    'suspensao'  => 'Suspensão',
    'vazamentos' => 'Vazamentos (óleo/água/fluidos)',
    'estofado'   => 'Banco/estofado',
];

// 22/09/2026, "avaliação tem ter opção de moto carro", confirmado com o
// usuário: checklist DIFERENTE de verdade pro caso de moto (não só o
// rótulo, que já tinha sido generalizado em 21/09) — corrente/freios/
// pneus/elétrica são itens de moto sem equivalente direto no checklist de
// carro; os itens que fazem sentido nos dois mantêm a MESMA chave/rótulo
// do carro (avarias/motor/cambio/suspensao/vazamentos), pra
// veiculoAvaliacaoRotuloItem() nunca precisar saber o tipo pra resolver o
// texto de um item compartilhado.
const VEICULO_AVALIACAO_ITENS_MOTO = [
    'motor'      => 'Motor',
    'cambio'     => 'Câmbio',
    'corrente'   => 'Corrente/relação (transmissão)',
    'freios'     => 'Freios (dianteiro/traseiro)',
    'pneus'      => 'Pneus',
    'suspensao'  => 'Suspensão (dianteira/traseira)',
    'eletrica'   => 'Elétrica/painel',
    'vazamentos' => 'Vazamentos (óleo/fluidos)',
    'avarias'    => 'Avarias (carenagem/pintura/amassados/riscos)',
];

/** Lista de itens do checklist certa pro tipo de veículo — 'carro' é o fallback padrão (mesmo default da coluna). */
function veiculoAvaliacaoItens(string $tipoVeiculo): array {
    return $tipoVeiculo === 'moto' ? VEICULO_AVALIACAO_ITENS_MOTO : VEICULO_AVALIACAO_ITENS_CARRO;
}

/**
 * Rótulo de um item, sem precisar saber o tipo de veículo — procura nos 2
 * dicionários (itens compartilhados como "motor" têm o mesmo texto nos
 * dois, então a ordem de busca nunca importa). Usado nos lugares que só
 * têm a `item` (chave) em mãos, tipo o PDF do termo e a tela do
 * avaliador, que já sabem qual avaliação estão mostrando mas não querem
 * uma consulta extra só pra resolver 1 rótulo.
 */
function veiculoAvaliacaoRotuloItem(string $item): string {
    return VEICULO_AVALIACAO_ITENS_CARRO[$item] ?? VEICULO_AVALIACAO_ITENS_MOTO[$item] ?? $item;
}

/**
 * Garante que a avaliação tem uma linha pra CADA item do checklist do seu
 * tipo de veículo — `INSERT OR IGNORE` (índice único `(avaliacao_id,
 * item)`, sempre idempotente) nunca duplica nem mexe num item já
 * preenchido. Chamada tanto na criação quanto toda vez que a lista é lida
 * — self-heal automático (mesmo espírito do wizard de documentos, "etapa
 * sempre derivada do banco"): se um item novo for adicionado à lista
 * padrão no futuro, uma avaliação já criada antes disso ganha a linha
 * faltante sozinha na próxima vez que a tela for aberta, sem precisar de
 * migração/script. $tipoVeiculo opcional — se omitido, busca da própria
 * avaliação (evita 2 idas ao banco quando quem chama já sabe o tipo, ex:
 * logo após criarAvaliacao()).
 */
function garantirItensAvaliacao(int $avaliacaoId, ?string $tipoVeiculo = null): void {
    $db = getDB();
    if ($tipoVeiculo === null) {
        $stmtTipo = $db->prepare("SELECT tipo_veiculo FROM veiculo_avaliacoes WHERE id = ?");
        $stmtTipo->execute([$avaliacaoId]);
        $tipoVeiculo = (string)($stmtTipo->fetchColumn() ?: 'carro');
    }
    $stmt = $db->prepare("INSERT OR IGNORE INTO veiculo_avaliacao_itens (avaliacao_id, item) VALUES (?, ?)");
    foreach (array_keys(veiculoAvaliacaoItens($tipoVeiculo)) as $item) {
        $stmt->execute([$avaliacaoId, $item]);
    }
}

/**
 * Cria uma avaliação nova pro veículo — sempre pré-semeia os itens fixos
 * do checklist (nunca "aparece" um item sem ter sido criado junto, mesmo
 * espírito de garantirLinhasDocumentosObrigatorios()). $vendaId só faz
 * sentido quando $tipo==='venda' (qual negociação/comprador está
 * recebendo o carro agora); nunca setado em avaliação de compra.
 */
function criarAvaliacao(int $oportunidadeId, string $tipo, ?int $vendaId, ?int $avaliadorId, int $criadoPor, string $tipoVeiculo = 'carro'): int {
    if (!in_array($tipo, ['compra', 'venda'], true)) {
        throw new InvalidArgumentException("Tipo de avaliação inválido: {$tipo}");
    }
    if (!in_array($tipoVeiculo, ['carro', 'moto'], true)) {
        throw new InvalidArgumentException("Tipo de veículo inválido: {$tipoVeiculo}");
    }
    $db = getDB();

    // 22/09/2026, achado real: duplo clique/reenvio do form de "Nova
    // vistoria" criava 2 linhas pro mesmo veículo (nenhuma trava contra
    // reenvio existia) — se já existe uma vistoria ATIVA (pendente/em
    // andamento) do mesmo tipo pra esse veículo/negociação, reaproveita
    // ela em vez de criar outra. Nunca bloqueia uma vistoria genuinamente
    // NOVA depois de uma já concluída (ex: 2ª inspeção numa devolução).
    $stmtExistente = $db->prepare("
        SELECT id FROM veiculo_avaliacoes
        WHERE oportunidade_id = ? AND tipo = ? AND status IN ('pendente', 'em_andamento')"
        . ($tipo === 'venda' ? " AND venda_id = ?" : "") . "
        ORDER BY id DESC LIMIT 1
    ");
    $stmtExistente->execute($tipo === 'venda' ? [$oportunidadeId, $tipo, $vendaId] : [$oportunidadeId, $tipo]);
    $existenteId = $stmtExistente->fetchColumn();
    if ($existenteId) {
        return (int)$existenteId;
    }

    $db->beginTransaction();
    try {
        $db->prepare("
            INSERT INTO veiculo_avaliacoes (oportunidade_id, venda_id, tipo, tipo_veiculo, avaliador_id, status, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ")->execute([
            $oportunidadeId,
            $tipo === 'venda' ? $vendaId : null,
            $tipo,
            $tipoVeiculo,
            $avaliadorId,
            $avaliadorId ? 'em_andamento' : 'pendente',
            $criadoPor,
        ]);
        $avaliacaoId = (int)$db->lastInsertId();
        $db->commit();

        garantirItensAvaliacao($avaliacaoId, $tipoVeiculo);
        return $avaliacaoId;
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

function buscarAvaliacao(int $avaliacaoId): ?array {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT va.*, o.veiculo_marca, o.veiculo_modelo, o.veiculo_ano, o.veiculo_placa,
               o.responsavel_id AS oportunidade_responsavel_id,
               c.nome AS cliente_nome, c.telefone AS cliente_telefone, c.email AS cliente_email,
               u.nome AS avaliador_nome,
               v.comprador_nome, v.comprador_telefone, v.comprador_email, v.responsavel_id AS venda_responsavel_id
        FROM veiculo_avaliacoes va
        JOIN oportunidades o ON o.id = va.oportunidade_id
        JOIN clientes c ON c.id = o.cliente_id
        LEFT JOIN usuarios u ON u.id = va.avaliador_id
        LEFT JOIN vendas v ON v.id = va.venda_id
        WHERE va.id = ?
    ");
    $stmt->execute([$avaliacaoId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function listarItensAvaliacao(int $avaliacaoId): array {
    $db = getDB();
    $stmtTipo = $db->prepare("SELECT tipo_veiculo FROM veiculo_avaliacoes WHERE id = ?");
    $stmtTipo->execute([$avaliacaoId]);
    $tipoVeiculo = (string)($stmtTipo->fetchColumn() ?: 'carro');

    garantirItensAvaliacao($avaliacaoId, $tipoVeiculo); // self-heal — ver comentário da função
    // ORDER BY item (nunca por id) — item novo self-healed entra com id
    // maior que os antigos, então "ORDER BY id" jogaria ele pro fim da
    // lista em vez de aparecer na posição certa; ordenar pela ordem
    // declarada na lista do TIPO DE VEÍCULO certo mantém a posição certa
    // sempre, independente de quando o item foi inserido de verdade.
    $ordem = array_flip(array_keys(veiculoAvaliacaoItens($tipoVeiculo)));
    $stmt = $db->prepare("SELECT * FROM veiculo_avaliacao_itens WHERE avaliacao_id = ?");
    $stmt->execute([$avaliacaoId]);
    $itens = $stmt->fetchAll();
    usort($itens, fn($a, $b) => ($ordem[$a['item']] ?? 99) <=> ($ordem[$b['item']] ?? 99));
    return $itens;
}

/**
 * Histórico/timeline de avaliações de um veículo (pedido direto:
 * "histórico de avaliação do veiculo") — todas as avaliações já feitas
 * pra essa oportunidade, mais recente primeiro, independente do tipo
 * (compra ou venda aparecem juntas na mesma lista, é o mesmo carro).
 */
function listarAvaliacoesDoVeiculo(int $oportunidadeId): array {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT va.*, u.nome AS avaliador_nome
        FROM veiculo_avaliacoes va
        LEFT JOIN usuarios u ON u.id = va.avaliador_id
        WHERE va.oportunidade_id = ?
        ORDER BY va.created_at DESC, va.id DESC
    ");
    $stmt->execute([$oportunidadeId]);
    return $stmt->fetchAll();
}

/** Fila de trabalho de um avaliador (ou de todas, pra super_admin/supervisor) — pendente/em_andamento primeiro. */
function listarAvaliacoesPendentes(?int $avaliadorId): array {
    $db = getDB();
    $sql = "
        SELECT va.*, o.veiculo_marca, o.veiculo_modelo, o.veiculo_ano, o.veiculo_placa,
               c.nome AS cliente_nome, u.nome AS avaliador_nome
        FROM veiculo_avaliacoes va
        JOIN oportunidades o ON o.id = va.oportunidade_id
        JOIN clientes c ON c.id = o.cliente_id
        LEFT JOIN usuarios u ON u.id = va.avaliador_id
        WHERE va.status != 'concluida'
    ";
    $params = [];
    if ($avaliadorId !== null) {
        $sql .= " AND va.avaliador_id = ?";
        $params[] = $avaliadorId;
    }
    $sql .= " ORDER BY (va.avaliador_id IS NULL), va.created_at ASC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function listarAvaliacoesConcluidas(?int $avaliadorId): array {
    $db = getDB();
    $sql = "
        SELECT va.*, o.veiculo_marca, o.veiculo_modelo, o.veiculo_ano, o.veiculo_placa,
               c.nome AS cliente_nome, u.nome AS avaliador_nome
        FROM veiculo_avaliacoes va
        JOIN oportunidades o ON o.id = va.oportunidade_id
        JOIN clientes c ON c.id = o.cliente_id
        LEFT JOIN usuarios u ON u.id = va.avaliador_id
        WHERE va.status = 'concluida'
    ";
    $params = [];
    if ($avaliadorId !== null) {
        $sql .= " AND va.avaliador_id = ?";
        $params[] = $avaliadorId;
    }
    $sql .= " ORDER BY va.concluida_em DESC LIMIT 100";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Atribui (ou reatribui/desatribui, $avaliadorId=null) o avaliador —
 * confirmado com o usuário: super_admin/supervisor sempre podem, e também
 * o responsável do próprio negócio (checagem de quem pode chamar fica no
 * caller, aqui é só a escrita). Avaliação 'pendente' sem ninguém atribuído
 * ainda vira 'em_andamento' ao ganhar avaliador; nunca mexe no status se
 * já estava 'concluida' (reatribuir uma já concluída não a reabre sozinho).
 */
function atribuirAvaliador(int $avaliacaoId, ?int $avaliadorId): void {
    $db = getDB();
    $stmt = $db->prepare("SELECT status FROM veiculo_avaliacoes WHERE id = ?");
    $stmt->execute([$avaliacaoId]);
    $statusAtual = $stmt->fetchColumn();
    if ($statusAtual === false) return;

    $novoStatus = $statusAtual;
    if ($statusAtual === 'pendente' && $avaliadorId) {
        $novoStatus = 'em_andamento';
    } elseif ($statusAtual === 'em_andamento' && !$avaliadorId) {
        $novoStatus = 'pendente';
    }

    $db->prepare("
        UPDATE veiculo_avaliacoes SET avaliador_id = ?, status = ?, updated_at = datetime('now','localtime') WHERE id = ?
    ")->execute([$avaliadorId, $novoStatus, $avaliacaoId]);
}

function atualizarKmAvaliacao(int $avaliacaoId, ?int $km): void {
    $db = getDB();
    $db->prepare("UPDATE veiculo_avaliacoes SET km_atual = ?, updated_at = datetime('now','localtime') WHERE id = ?")
        ->execute([$km, $avaliacaoId]);
}

function atualizarObservacoesGeraisAvaliacao(int $avaliacaoId, string $observacoes): void {
    $db = getDB();
    $db->prepare("UPDATE veiculo_avaliacoes SET observacoes_gerais = ?, updated_at = datetime('now','localtime') WHERE id = ?")
        ->execute([clean($observacoes), $avaliacaoId]);
}

function atualizarItemAvaliacao(int $avaliacaoId, string $item, string $status, string $observacao): void {
    // Checa nos 2 dicionários (não precisa saber o tipo_veiculo da
    // avaliação pra validar) — item que não existe em NENHUM dos dois é
    // rejeitado aqui; um item que existe mas não foi semeado pra ESTA
    // avaliação (tipo errado) nunca é alterado de verdade, porque o
    // UPDATE abaixo exige uma linha existente em veiculo_avaliacao_itens.
    if (!array_key_exists($item, VEICULO_AVALIACAO_ITENS_CARRO) && !array_key_exists($item, VEICULO_AVALIACAO_ITENS_MOTO)) return;
    if (!in_array($status, ['ok', 'problema', 'nao_verificado'], true)) return;
    $db = getDB();
    $db->prepare("
        UPDATE veiculo_avaliacao_itens SET status = ?, observacao = ? WHERE avaliacao_id = ? AND item = ?
    ")->execute([$status, clean($observacao), $avaliacaoId, $item]);
}

function concluirAvaliacao(int $avaliacaoId): void {
    $db = getDB();
    $db->prepare("
        UPDATE veiculo_avaliacoes SET status = 'concluida', concluida_em = datetime('now','localtime'), updated_at = datetime('now','localtime')
        WHERE id = ? AND status != 'concluida'
    ")->execute([$avaliacaoId]);
}

/** Volta pra em_andamento — precisar corrigir algo depois de já ter marcado concluída. */
function reabrirAvaliacao(int $avaliacaoId): void {
    $db = getDB();
    $db->prepare("
        UPDATE veiculo_avaliacoes SET status = 'em_andamento', concluida_em = NULL, updated_at = datetime('now','localtime')
        WHERE id = ? AND status = 'concluida'
    ")->execute([$avaliacaoId]);
}

function listarFotosAvaliacao(int $avaliacaoId): array {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM veiculo_avaliacao_fotos WHERE avaliacao_id = ? ORDER BY tipo, id");
    $stmt->execute([$avaliacaoId]);
    return $stmt->fetchAll();
}

/**
 * Sobe uma foto/vídeo pra galeria da vistoria — mesma disciplina de
 * segurança/limites de salvarMidiaRevenda() (includes/vendas.php), mesmo
 * destino Drive/local (pasta do cliente original, subpasta "vistoria"),
 * mas SEMPRE cai em veiculo_avaliacao_fotos (galeria própria, nunca no
 * catálogo de vendas direto — precisa de aprovarFotoParaCatalogo()).
 */
function salvarFotoAvaliacao(int $avaliacaoId, array $arquivo, string $legenda): array {
    if (($arquivo['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['ok' => false, 'erro' => 'Escolha um arquivo.'];
    }
    if ($arquivo['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'erro' => 'Falha no envio do arquivo (tente novamente).'];
    }

    $mime = mime_content_type($arquivo['tmp_name']);
    if (!isset(VEICULO_MIDIA_MIME_PERMITIDOS[$mime])) {
        return ['ok' => false, 'erro' => 'Formato não aceito — envie foto (JPG/PNG/WEBP) ou vídeo (MP4/MOV/WEBM).'];
    }
    [$tipo, $ext] = VEICULO_MIDIA_MIME_PERMITIDOS[$mime];
    $tetoBytes = $tipo === 'video' ? VEICULO_MIDIA_MAX_BYTES_VIDEO : VEICULO_MIDIA_MAX_BYTES_FOTO;
    if ($arquivo['size'] > $tetoBytes) {
        return ['ok' => false, 'erro' => 'Arquivo maior que ' . (int)($tetoBytes / 1024 / 1024) . 'MB.'];
    }

    $av = buscarAvaliacao($avaliacaoId);
    if (!$av) {
        return ['ok' => false, 'erro' => 'Avaliação não encontrada.'];
    }

    $db = getDB();
    $stmtCli = $db->prepare("SELECT c.id AS cliente_id, c.nome FROM oportunidades o JOIN clientes c ON c.id = o.cliente_id WHERE o.id = ?");
    $stmtCli->execute([$av['oportunidade_id']]);
    $cli = $stmtCli->fetch();
    if (!$cli) {
        return ['ok' => false, 'erro' => 'Veículo não encontrado.'];
    }

    $nomeArquivo = 'vistoria_' . $tipo . '_' . $avaliacaoId . '_' . time() . '.' . $ext;
    $copia = salvarArquivoGeradoComoDocumento((int)$cli['cliente_id'], $cli['nome'] ?: "Cliente #{$cli['cliente_id']}", $arquivo['tmp_name'], $nomeArquivo, $mime, 'vistoria');
    if (!$copia['drive_file_id'] && !$copia['arquivo_url']) {
        return ['ok' => false, 'erro' => 'Não foi possível salvar o arquivo agora — tente novamente.'];
    }

    $db->prepare("
        INSERT INTO veiculo_avaliacao_fotos (avaliacao_id, tipo, mime, drive_file_id, arquivo_url, legenda)
        VALUES (?, ?, ?, ?, ?, ?)
    ")->execute([$avaliacaoId, $tipo, $mime, $copia['drive_file_id'], $copia['arquivo_url'], clean($legenda)]);

    return ['ok' => true, 'erro' => null];
}

function excluirFotoAvaliacao(int $fotoId): void {
    $db = getDB();
    $db->prepare("DELETE FROM veiculo_avaliacao_fotos WHERE id = ?")->execute([$fotoId]);
}

/**
 * Exclui uma vistoria inteira (itens + fotos) — 22/09/2026, limpeza de
 * duplicata (duplo clique criando 2 vistorias do mesmo veículo, ver
 * criarAvaliacao()); só super_admin, ação sem volta (mesmo espírito de
 * excluirConversaWhatsapp()). Nunca mexe na cópia já aprovada pro
 * catálogo de vendas — são registros independentes (mesma disciplina de
 * excluirFotoAvaliacao, que já não mexe nisso). Bloqueia se já existe
 * termo de entrega gerado/enviado/assinado — nesse ponto já é documento
 * que saiu do sistema pro cliente, não é mais "limpar rascunho".
 */
function excluirAvaliacao(int $avaliacaoId): array {
    $db = getDB();
    $av = buscarAvaliacao($avaliacaoId);
    if (!$av) {
        return ['ok' => false, 'erro' => 'Vistoria não encontrada.'];
    }
    if (!empty($av['termo_status'])) {
        return ['ok' => false, 'erro' => 'Essa vistoria já tem termo de entrega gerado/enviado — não pode ser excluída.'];
    }
    $db->beginTransaction();
    try {
        $db->prepare("DELETE FROM veiculo_avaliacao_itens WHERE avaliacao_id = ?")->execute([$avaliacaoId]);
        $db->prepare("DELETE FROM veiculo_avaliacao_fotos WHERE avaliacao_id = ?")->execute([$avaliacaoId]);
        $db->prepare("DELETE FROM veiculo_avaliacoes WHERE id = ?")->execute([$avaliacaoId]);
        $db->commit();
        return ['ok' => true, 'erro' => null];
    } catch (Throwable $e) {
        $db->rollBack();
        return ['ok' => false, 'erro' => $e->getMessage()];
    }
}

/**
 * Copia uma foto da vistoria pro catálogo de vendas (`veiculo_midias_revenda`
 * — o que a IA de vendas realmente usa) — 21/09/2026, "as fotos que
 * colher tem que vendedor aprovar para ia usar pois evitar fotos
 * desnecessário": ação EXPLÍCITA e restrita a quem acessa vendas
 * (podeAcessarVendas() — checado pelo caller), nunca automática. Copia a
 * MESMA referência de arquivo (drive_file_id/arquivo_url) — nunca
 * re-upload, é o mesmo byte físico já salvo. Marca a foto de origem como
 * aprovada (idempotente: aprovar de novo só atualiza quem/quando, nunca
 * duplica no catálogo — checa antes se já foi promovida).
 */
function aprovarFotoParaCatalogo(int $fotoId, int $aprovadoPor): array {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM veiculo_avaliacao_fotos WHERE id = ?");
    $stmt->execute([$fotoId]);
    $foto = $stmt->fetch();
    if (!$foto) {
        return ['ok' => false, 'erro' => 'Foto não encontrada.'];
    }
    if ((int)$foto['aprovado_para_catalogo'] === 1) {
        return ['ok' => true, 'erro' => null]; // já aprovada, no-op
    }

    $av = buscarAvaliacao((int)$foto['avaliacao_id']);
    if (!$av) {
        return ['ok' => false, 'erro' => 'Avaliação não encontrada.'];
    }

    $db->prepare("
        INSERT INTO veiculo_midias_revenda (oportunidade_id, tipo, mime, drive_file_id, arquivo_url, legenda)
        VALUES (?, ?, ?, ?, ?, ?)
    ")->execute([$av['oportunidade_id'], $foto['tipo'], $foto['mime'], $foto['drive_file_id'], $foto['arquivo_url'], $foto['legenda']]);

    $db->prepare("
        UPDATE veiculo_avaliacao_fotos SET aprovado_para_catalogo = 1, aprovado_por = ?, aprovado_em = datetime('now','localtime') WHERE id = ?
    ")->execute([$aprovadoPor, $fotoId]);

    return ['ok' => true, 'erro' => null];
}

const AVALIACOES_STATUS_ZAPSIGN = [
    'signed'  => 'assinado',
    'refused' => 'recusado',
    'pending' => 'enviado',
];

/**
 * Gera o PDF do termo de entrega/vistoria e manda pra assinatura via
 * ZapSign — SEMPRE ação manual (confirmado com o usuário), nunca dispara
 * sozinho ao concluir a avaliação. Signatário depende do $tipo: 'compra'
 * = vendedor original (cliente da oportunidade, quem está ENTREGANDO o
 * carro pra Fastcar); 'venda' = comprador novo (vendas.comprador_*, quem
 * está RECEBENDO o carro da Fastcar) — confirmado com o usuário, os dois
 * cenários (multiSelect, ambos selecionados).
 */
function gerarEEnviarTermoAvaliacao(int $avaliacaoId): array {
    $av = buscarAvaliacao($avaliacaoId);
    if (!$av) {
        return ['ok' => false, 'erro' => 'Avaliação não encontrada.'];
    }

    // 21/09/2026, "enviar termo mais para venda" — confirmado com o
    // usuário: termo de entrega/assinatura eletrônica exclusivo de venda
    // (comprador confirmando recebimento). Na compra, o vendedor já
    // assina o contrato de compra principal — o checklist/fotos da
    // vistoria continuam servindo de registro interno, sem exigir uma 2ª
    // assinatura dele. Nunca confia só em esconder o botão na tela —
    // trava aqui também, pro caso de POST forjado numa avaliação de compra.
    if ($av['tipo'] !== 'venda') {
        return ['ok' => false, 'erro' => 'Termo de entrega é exclusivo das vistorias de venda.'];
    }

    $nomeSigner = trim((string)$av['comprador_nome']);
    $telSigner = (string)$av['comprador_telefone'];
    $emailSigner = (string)$av['comprador_email'];
    if ($nomeSigner === '') {
        return ['ok' => false, 'erro' => 'Preencha os dados do comprador na venda antes de gerar o termo.'];
    }

    $itens = listarItensAvaliacao($avaliacaoId);
    $pdfPath = gerarPdfTermoAvaliacao($av, $itens);
    if (!$pdfPath) {
        return ['ok' => false, 'erro' => 'Falha ao gerar o PDF do termo.'];
    }

    $nomeDoc = 'Termo de Entrega e Vistoria — ' . trim($av['veiculo_marca'] . ' ' . $av['veiculo_modelo']) . ' — #' . $avaliacaoId;
    $res = zapsignCriarDocumentoEAssinatura($pdfPath, $nomeDoc, $nomeSigner, $telSigner, $emailSigner);

    $db = getDB();

    // Sempre salva a cópia do PDF (rascunho, ainda sem assinar) — mesmo
    // padrão de contratos: dá pra visualizar mesmo antes de assinado.
    $stmtCli = $db->prepare("SELECT c.id, c.nome FROM oportunidades o JOIN clientes c ON c.id = o.cliente_id WHERE o.id = ?");
    $stmtCli->execute([$av['oportunidade_id']]);
    $cli = $stmtCli->fetch();
    $copia = ['drive_file_id' => '', 'arquivo_url' => ''];
    if ($cli) {
        $copia = salvarArquivoGeradoComoDocumento(
            (int)$cli['id'], $cli['nome'] ?: "Cliente #{$cli['id']}", $pdfPath,
            'termo_vistoria_' . $avaliacaoId . '.pdf', 'application/pdf', 'vistoria'
        );
    }
    @unlink($pdfPath);

    if (isset($res['error'])) {
        // Falha na ZapSign — mesmo assim guarda o PDF gerado como rascunho,
        // pra não perder o trabalho de checklist já feito.
        $db->prepare("
            UPDATE veiculo_avaliacoes SET termo_status = 'gerado', drive_file_id = ?, arquivo_url = ?, updated_at = datetime('now','localtime')
            WHERE id = ?
        ")->execute([$copia['drive_file_id'], $copia['arquivo_url'], $avaliacaoId]);
        return ['ok' => false, 'erro' => $res['error']];
    }

    $db->prepare("
        UPDATE veiculo_avaliacoes
        SET zapsign_doc_token = ?, zapsign_signer_token = ?, sign_url = ?, termo_status = 'enviado',
            drive_file_id = ?, arquivo_url = ?, updated_at = datetime('now','localtime')
        WHERE id = ?
    ")->execute([$res['doc_token'], $res['signer_token'], $res['sign_url'], $copia['drive_file_id'], $copia['arquivo_url'], $avaliacaoId]);

    return ['ok' => true, 'erro' => null];
}

/**
 * Reconsulta a ZapSign e atualiza status/cópia assinada — mesmo padrão de
 * zapsignSincronizarContrato() (includes/contratos.php), mas SEM nenhum
 * efeito colateral de mudança de etapa (termo de vistoria não fecha
 * negócio nenhum, é só registro/formalização da entrega). Best-effort:
 * usada pelo webhook e pelo cron de fallback, nunca lança.
 */
function sincronizarTermoAvaliacao(int $avaliacaoId): void {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM veiculo_avaliacoes WHERE id = ?");
    $stmt->execute([$avaliacaoId]);
    $av = $stmt->fetch();
    if (!$av || !$av['zapsign_doc_token']) return;

    $statusRes = zapsignStatusDocumento($av['zapsign_doc_token']);
    if (isset($statusRes['error'])) return;

    $novoStatus = AVALIACOES_STATUS_ZAPSIGN[$statusRes['status']] ?? $av['termo_status'];

    $jaTemCopiaAssinada = $av['termo_status'] === 'assinado' && ($av['drive_file_id'] || $av['arquivo_url']);
    if ($novoStatus === $av['termo_status'] && $jaTemCopiaAssinada) return;

    $driveFileId = $av['drive_file_id'];
    $arquivoUrl = $av['arquivo_url'];

    if ($novoStatus === 'assinado' && !$jaTemCopiaAssinada) {
        $conteudo = zapsignBaixarAssinado($av['zapsign_doc_token']);
        if ($conteudo) {
            $tmp = tempnam(sys_get_temp_dir(), 'termo_vistoria_assinado_') . '.pdf';
            file_put_contents($tmp, $conteudo);

            $stmtCli = $db->prepare("SELECT c.id, c.nome FROM oportunidades o JOIN clientes c ON c.id = o.cliente_id WHERE o.id = ?");
            $stmtCli->execute([$av['oportunidade_id']]);
            $cli = $stmtCli->fetch();
            if ($cli) {
                $copia = salvarArquivoGeradoComoDocumento(
                    (int)$cli['id'], $cli['nome'] ?: "Cliente #{$cli['id']}", $tmp,
                    'termo_vistoria_assinado_' . $avaliacaoId . '.pdf', 'application/pdf', 'vistoria'
                );
                if ($copia['drive_file_id'] || $copia['arquivo_url']) {
                    $driveFileId = $copia['drive_file_id'];
                    $arquivoUrl = $copia['arquivo_url'];
                }
            }
            @unlink($tmp);
        }
    }

    $assinadoEm = ($novoStatus === 'assinado' && !$av['termo_assinado_em']) ? date('Y-m-d H:i:s') : $av['termo_assinado_em'];

    $db->prepare("
        UPDATE veiculo_avaliacoes SET termo_status = ?, drive_file_id = ?, arquivo_url = ?, termo_assinado_em = ?, updated_at = datetime('now','localtime')
        WHERE id = ?
    ")->execute([$novoStatus, $driveFileId, $arquivoUrl, $assinadoEm, $avaliacaoId]);
}
