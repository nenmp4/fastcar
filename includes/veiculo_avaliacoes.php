<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/vendas.php'; // VEICULO_MIDIA_MIME_PERMITIDOS/MAX_BYTES, veiculo_midias_revenda
require_once __DIR__ . '/documentos.php'; // salvarArquivoGeradoComoDocumento()
require_once __DIR__ . '/zapsign.php'; // só pro histórico de termo já enviado via ZapSign antes de 06/10/2026 — ver sincronizarTermoAvaliacao()
require_once __DIR__ . '/veiculo_avaliacoes_pdf.php';
require_once __DIR__ . '/email_templates.php'; // emailLayout()/emailBotao()/appBaseUrl()
require_once __DIR__ . '/mail.php'; // enviarEmail()
require_once __DIR__ . '/auditoria.php'; // auditoriaClienteIp() — usada em confirmarTermoCientePresencial()

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
 * O "termo de entrega/vistoria" — 06/10/2026, "Termo Ciente": PAROU de
 * ir pra assinatura eletrônica via ZapSign (decisão explícita do
 * usuário, "não enviar checlist de retirada do veiculo pelo zapsiner").
 * Virou documento puramente INTERNO: `gerarEEnviarTermoAvaliacao()` gera
 * o PDF e manda o link do termo por E-MAIL
 * (public/termo_ciente.php?token=...); o comprador só CONFIRMA com 1
 * clique (nunca assina de verdade) via `confirmarTermoCiente()`. Os
 * campos `zapsign_doc_token`/`zapsign_signer_token`/`sign_url` continuam
 * na tabela só pra histórico de termo já enviado assim ANTES dessa
 * mudança (`sincronizarTermoAvaliacao()` segue funcionando pra eles, via
 * webhook/cron) — nenhum código novo escreve neles de novo.
 * `termo_ciente_token`/`_enviado_em`/`_ip`/`_user_agent`/`_ressalva` são
 * os campos do fluxo atual. NUNCA reaproveita
 * `contratos`/zapsignSincronizarContrato() — mesmo raciocínio "arquivo
 * próprio de propósito" já documentado várias vezes neste projeto pra
 * módulos com modelo de dado/regra de negócio diferente demais pra
 * copy-paste direto. Geração/envio é sempre AÇÃO MANUAL (confirmado com o
 * usuário desde a 1ª versão) — nunca dispara sozinho ao concluir a
 * avaliação.
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
// Itens novos de 08/10/2026 ("lista de intens na avaliação" — farol/setas/
// pneus/fechaduras/ar-condicionado-multimídia-som/step/chave reserva/
// macaco-chave de roda, seguido de "vidro manual ou elétrico se está
// funcionando") — checklist de carro era bem mais curto que o de moto,
// sem nenhum item elétrico/conforto/kit de ferramentas; 'pneus' também
// tapou um buraco real (o carro nunca tinha esse item, só a moto).
// Self-heal automático via garantirItensAvaliacao() — avaliação já criada
// antes desta mudança ganha as linhas novas sozinha na próxima vez que
// a tela for aberta, nenhuma migração precisou.
const VEICULO_AVALIACAO_ITENS_CARRO = [
    'avarias'         => 'Avarias (lataria/carenagem/pintura/amassados/riscos)',
    'motor'           => 'Motor',
    'cambio'          => 'Câmbio',
    'suspensao'       => 'Suspensão',
    'vazamentos'      => 'Vazamentos (óleo/água/fluidos)',
    'estofado'        => 'Banco/estofado',
    'pneus'           => 'Pneus',
    'farol'           => 'Farol (alto/baixo)',
    'setas'           => 'Setas e iluminação completa (lanternas, freio, ré)',
    'vidros'          => 'Vidros (manual ou elétrico) — funcionando',
    'fechaduras'      => 'Fechaduras das portas',
    'ar_multimidia'   => 'Ar-condicionado / som / multimídia',
    'step'            => 'Estepe (step)',
    'chave_reserva'   => 'Chave reserva',
    'kit_ferramentas' => 'Macaco e chave de roda',
];

// 22/09/2026, "avaliação tem ter opção de moto carro", confirmado com o
// usuário: checklist DIFERENTE de verdade pro caso de moto (não só o
// rótulo, que já tinha sido generalizado em 21/09) — corrente/freios/
// pneus/elétrica são itens de moto sem equivalente direto no checklist de
// carro; os itens que fazem sentido nos dois mantêm a MESMA chave/rótulo
// do carro (avarias/motor/cambio/suspensao/vazamentos), pra
// veiculoAvaliacaoRotuloItem() nunca precisar saber o tipo pra resolver o
// texto de um item compartilhado.
// Ganhou farol/setas/chave_reserva em 08/10/2026, junto do pacote de itens
// novos do carro acima — fazem sentido pra moto também (as 2 primeiras
// com o MESMO texto do carro, de propósito, mesmo espírito de
// 'motor'/'cambio' compartilhados). Vidros/fechaduras/ar-condicionado-
// multimídia/step/macaco-chave de roda ficam só no carro — moto não tem
// porta/janela/AC/multimídia padrão, e kit de troca de pneu de moto não
// é o mesmo macaco+chave de roda do carro.
const VEICULO_AVALIACAO_ITENS_MOTO = [
    'motor'         => 'Motor',
    'cambio'        => 'Câmbio',
    'corrente'      => 'Corrente/relação (transmissão)',
    'freios'        => 'Freios (dianteiro/traseiro)',
    'pneus'         => 'Pneus',
    'suspensao'     => 'Suspensão (dianteira/traseira)',
    'eletrica'      => 'Elétrica/painel',
    'vazamentos'    => 'Vazamentos (óleo/fluidos)',
    'avarias'       => 'Avarias (carenagem/pintura/amassados/riscos)',
    'farol'         => 'Farol (alto/baixo)',
    'setas'         => 'Setas e iluminação completa (lanternas, freio, ré)',
    'chave_reserva' => 'Chave reserva',
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
 * Resumo + score do checklist — 06/10/2026, "coloca toda lista completa
 * dos intens depois resumo e score do veiculo" (Termo Ciente). Nunca um
 * julgamento de IA nem uma nota inventada — é só contagem determinística
 * dos status que o próprio avaliador já marcou item por item (regra #3,
 * nunca chuta condição do veículo). Usado no PDF do termo e na página
 * pública que o cliente recebe, pra nunca precisar repetir essa conta em
 * 2 lugares.
 *
 * "Não verificado" fica DE FORA do score de propósito — ausência de
 * verificação não é "ok" disfarçado nem conta contra o veículo, só
 * reduz quantos itens entraram na conta; por isso `percentual` sai
 * `null` quando nada ainda foi verificado (nunca um 0%/100% inventado).
 */
function veiculoAvaliacaoScore(array $itens): array {
    $total = count($itens);
    $ok = 0;
    $problema = 0;
    $naoVerificado = 0;
    $problemas = [];

    foreach ($itens as $item) {
        if ($item['status'] === 'ok') {
            $ok++;
        } elseif ($item['status'] === 'problema') {
            $problema++;
            $rotulo = veiculoAvaliacaoRotuloItem($item['item']);
            $obs = (string)($item['observacao'] ?? '');
            $problemas[] = $obs !== '' ? "{$rotulo} — {$obs}" : $rotulo;
        } else {
            $naoVerificado++;
        }
    }

    $verificados = $ok + $problema;
    $percentual = $verificados > 0 ? (int)round(($ok / $verificados) * 100) : null;

    if ($total === 0) {
        $resumo = 'Checklist ainda sem itens.';
    } elseif ($naoVerificado > 0) {
        $resumo = "{$ok} de {$total} item(ns) do checklist conferido(s) sem problema — {$naoVerificado} ainda não verificado(s).";
    } elseif ($problema > 0) {
        $resumo = "{$ok} de {$total} item(ns) sem problema — {$problema} com ressalva (ver detalhe abaixo).";
    } else {
        $resumo = "Todos os {$total} itens do checklist foram conferidos, sem nenhuma ressalva.";
    }

    return [
        'total'          => $total,
        'ok'             => $ok,
        'problema'       => $problema,
        'nao_verificado' => $naoVerificado,
        'percentual'     => $percentual,
        'resumo'         => $resumo,
        'problemas'      => $problemas,
    ];
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

/**
 * Score da vistoria mais recente CONCLUÍDA de um veículo (qualquer tipo —
 * compra ou venda, o que for mais recente) — 08/10/2026, "após conclusão
 * do avaliador não tem score do veiculo e resulmo e condiçõs do veliuculos
 * de 0 100 para revenda": o score já existia (veiculoAvaliacaoScore(),
 * usado no termo/página pública/tela do próprio avaliador), mas nunca
 * aparecia pra quem decide sobre a revenda — a Frota (admin/veiculos.php)
 * e o card de histórico de vistoria em admin/oportunidade.php/venda.php só
 * mostravam Tipo/Status/Avaliador, sem nenhum score/condição visível.
 *
 * NUNCA recalcula a conta em SQL cru — reaproveita veiculoAvaliacaoScore(),
 * única fonte de verdade (regra #3: nunca duplicar lógica de negócio em 2
 * lugares). Retorna `null` quando não existe NENHUMA vistoria concluída
 * pra esse veículo ainda — nunca inventa condição sem avaliação real
 * concluída (mesma disciplina de `percentual=null` dentro do próprio
 * veiculoAvaliacaoScore() quando nada foi verificado).
 */
/**
 * Classe de badge + rótulo de sugestão pra revenda, a partir do score
 * (0-100) — 08/10/2026, "Mostra os score sugestão pronto para revenda,
 * Fazer Manutenção": tradução direta do percentual numa recomendação
 * legível, sem inventar nada além do que o próprio score já representa
 * (mesmas 3 faixas já usadas nos badges de condição — ≥80 verde, 50-79
 * amarelo, <50 vermelho). Únicas fontes de verdade — reaproveitadas nas
 * 4 telas que mostram condição de vistoria (Frota, oportunidade, venda,
 * fila de vistorias), nunca duplicadas uma por uma.
 */
function veiculoAvaliacaoClasseBadgeScore(?int $percentual): string {
    if ($percentual === null) return 'badge-aviso';
    if ($percentual >= 80) return 'badge-ok';
    if ($percentual >= 50) return 'badge-aviso';
    return 'badge-atraso';
}
function veiculoAvaliacaoSugestaoRevenda(?int $percentual): string {
    if ($percentual === null) return '';
    if ($percentual >= 80) return '✅ Pronto pra revenda';
    if ($percentual >= 50) return '⚠️ Revisar antes de revender';
    return '🔧 Fazer manutenção';
}

function buscarScoreVistoriaRecente(int $oportunidadeId): ?array {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT id FROM veiculo_avaliacoes
        WHERE oportunidade_id = ? AND status = 'concluida'
        ORDER BY concluida_em DESC LIMIT 1
    ");
    $stmt->execute([$oportunidadeId]);
    $avaliacaoId = $stmt->fetchColumn();
    if (!$avaliacaoId) {
        return null;
    }
    return veiculoAvaliacaoScore(listarItensAvaliacao((int)$avaliacaoId));
}

// buscarCandidatosVistoria()/listarVistoriasPorPlaca() (busca de veículo e
// histórico por placa pro modal self-atribuído do avaliador) removidas em
// 08/10/2026 junto com o botão "➕ Nova vistoria" de admin/avaliacoes.php
// ("está confudindo" — consultor/vendedor continuam os únicos que criam e
// atribuem a vistoria, ver criarAvaliacao() e os cards de
// admin/oportunidade.php/admin/venda.php, agora com avaliador_id sempre
// obrigatório na criação).

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

/**
 * 05/10/2026, "listagem de veículos vistoriados pra ele conferir todas
 * suas vistorias" — antes tinha um `LIMIT 100` sem paginação nenhuma
 * (mesma classe de bug já documentada em admin/clientes.php: passado o
 * 100º registro, os mais antigos simplesmente sumiam da lista sem
 * aviso). Agora paginado de verdade (includes/paginacao.php) + busca por
 * placa/marca/modelo/cliente, pro avaliador achar uma vistoria antiga
 * específica sem precisar rolar tudo.
 */
function listarAvaliacoesConcluidas(?int $avaliadorId, string $busca = '', int $limite = 100, int $offset = 0): array {
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
    if ($busca !== '') {
        $sql .= " AND (o.veiculo_placa LIKE ? OR o.veiculo_marca LIKE ? OR o.veiculo_modelo LIKE ? OR c.nome LIKE ?)";
        $like = '%' . $busca . '%';
        array_push($params, $like, $like, $like, $like);
    }
    $sql .= " ORDER BY va.concluida_em DESC LIMIT " . max(1, $limite) . " OFFSET " . max(0, $offset);
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/** Total de vistorias concluídas que batem com o mesmo filtro acima — pra paginação real. */
function contarAvaliacoesConcluidas(?int $avaliadorId, string $busca = ''): int {
    $db = getDB();
    $sql = "
        SELECT COUNT(*)
        FROM veiculo_avaliacoes va
        JOIN oportunidades o ON o.id = va.oportunidade_id
        JOIN clientes c ON c.id = o.cliente_id
        WHERE va.status = 'concluida'
    ";
    $params = [];
    if ($avaliadorId !== null) {
        $sql .= " AND va.avaliador_id = ?";
        $params[] = $avaliadorId;
    }
    if ($busca !== '') {
        $sql .= " AND (o.veiculo_placa LIKE ? OR o.veiculo_marca LIKE ? OR o.veiculo_modelo LIKE ? OR c.nome LIKE ?)";
        $like = '%' . $busca . '%';
        array_push($params, $like, $like, $like, $like);
    }
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
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
 * Token do link público do Termo Ciente — mesmo padrão
 * bin2hex(random_bytes(20)) de getOuCriarTokenDocumentos()
 * (includes/documentos.php). Gerado 1x, nunca muda depois — reenviar o
 * termo reaproveita o MESMO link, nunca invalida um já mandado antes.
 */
function getOuCriarTokenTermoCiente(int $avaliacaoId): string {
    $db = getDB();
    $stmt = $db->prepare("SELECT termo_ciente_token FROM veiculo_avaliacoes WHERE id = ?");
    $stmt->execute([$avaliacaoId]);
    $token = (string)$stmt->fetchColumn();
    if ($token !== '') return $token;

    $token = bin2hex(random_bytes(20));
    $db->prepare("UPDATE veiculo_avaliacoes SET termo_ciente_token = ? WHERE id = ?")->execute([$token, $avaliacaoId]);
    return $token;
}

/** Busca a avaliação pelo token do link público — nunca pelo id (o id nunca é confiável vindo de fora, só o token imprevisível). */
function buscarAvaliacaoPorTermoCienteToken(string $token): ?array {
    if ($token === '') return null;
    $db = getDB();
    $stmt = $db->prepare("
        SELECT va.*, o.veiculo_marca, o.veiculo_modelo, o.veiculo_ano, o.veiculo_placa,
               c.nome AS cliente_nome,
               v.comprador_nome, v.comprador_telefone, v.comprador_email
        FROM veiculo_avaliacoes va
        JOIN oportunidades o ON o.id = va.oportunidade_id
        JOIN clientes c ON c.id = o.cliente_id
        LEFT JOIN vendas v ON v.id = va.venda_id
        WHERE va.termo_ciente_token = ?
    ");
    $stmt->execute([$token]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Gera o PDF do termo de entrega/vistoria e manda o link do TERMO CIENTE
 * por E-MAIL — 06/10/2026, "Não enviar checlist de retirada do veiculo
 * pelo zapsiner - vamos fazer documento interno cliente só da uma aceite
 * ao receber no email o link - termo ciente": reverte o fluxo anterior
 * (assinatura eletrônica via ZapSign, WhatsApp) por decisão explícita do
 * usuário. O documento passa a ser puramente interno — o comprador só
 * CONFIRMA com 1 clique num link único (public/termo_ciente.php), nunca
 * mais uma assinatura eletrônica de verdade. SEMPRE ação manual, nunca
 * dispara sozinho ao concluir a avaliação (mesma regra de sempre).
 * Exclusivo de venda (comprador novo recebendo o carro) — decisão
 * original de 21/09/2026, nunca mudou (confirmado de novo no mockup do
 * Termo Ciente: "Só o comprador, na venda — como hoje").
 *
 * O link SEMPRE é gerado, mesmo sem e-mail cadastrado pro comprador — o
 * token funciona por si só; o e-mail é só o canal de ENTREGA automático.
 * Sem e-mail (ou se o envio falhar), devolve o link pronto no `aviso`
 * pra copiar/mandar manualmente — mesmo espírito do botão "📋 Copiar
 * link" já usado nos contratos (26/09/2026), nunca trava a operação por
 * falta de canal.
 */
function gerarEEnviarTermoAvaliacao(int $avaliacaoId): array {
    $av = buscarAvaliacao($avaliacaoId);
    if (!$av) {
        return ['ok' => false, 'erro' => 'Avaliação não encontrada.'];
    }

    // 21/09/2026, "enviar termo mais para venda" — confirmado com o
    // usuário: termo de entrega exclusivo de venda (comprador confirmando
    // recebimento). Na compra, o vendedor já assina o contrato de compra
    // principal — o checklist/fotos da vistoria continuam servindo de
    // registro interno, sem exigir mais nada dele. Nunca confia só em
    // esconder o botão na tela — trava aqui também, pro caso de POST
    // forjado numa avaliação de compra.
    if ($av['tipo'] !== 'venda') {
        return ['ok' => false, 'erro' => 'Termo de entrega é exclusivo das vistorias de venda.'];
    }

    if ($av['termo_status'] === 'confirmado') {
        return ['ok' => false, 'erro' => 'O cliente já confirmou esse termo em ' . date('d/m/Y H:i', strtotime((string)$av['termo_assinado_em'])) . ' — não precisa reenviar.'];
    }

    $nomeDestinatario = trim((string)$av['comprador_nome']);
    $emailDestinatario = trim((string)$av['comprador_email']);
    if ($nomeDestinatario === '') {
        return ['ok' => false, 'erro' => 'Preencha os dados do comprador na venda antes de gerar o termo.'];
    }

    $itens = listarItensAvaliacao($avaliacaoId);
    $score = veiculoAvaliacaoScore($itens);
    $pdfPath = gerarPdfTermoAvaliacao($av, $itens, $score);
    if (!$pdfPath) {
        return ['ok' => false, 'erro' => 'Falha ao gerar o PDF do termo.'];
    }

    $db = getDB();

    // Sempre salva a cópia do PDF — é o registro interno em si, nunca
    // depende de confirmação nenhuma do cliente pra existir.
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

    $token = getOuCriarTokenTermoCiente($avaliacaoId);
    $link = appBaseUrl() . '/public/termo_ciente.php?token=' . $token;

    $db->prepare("
        UPDATE veiculo_avaliacoes
        SET termo_status = 'enviado', termo_ciente_enviado_em = datetime('now','localtime'),
            drive_file_id = ?, arquivo_url = ?, updated_at = datetime('now','localtime')
        WHERE id = ?
    ")->execute([$copia['drive_file_id'], $copia['arquivo_url'], $avaliacaoId]);

    if ($emailDestinatario === '') {
        return ['ok' => true, 'erro' => null, 'aviso' => 'Comprador sem e-mail cadastrado — copie o link abaixo e mande manualmente.', 'link' => $link];
    }

    $veiculo = trim($av['veiculo_marca'] . ' ' . $av['veiculo_modelo']) ?: 'seu veículo';
    $corpo = '<p>Olá, ' . htmlspecialchars($nomeDestinatario, ENT_QUOTES) . '!</p>'
        . "<p>Segue o resumo da vistoria de entrega do seu <strong>{$veiculo}</strong>. Confira os itens abaixo e confirme "
        . 'que recebeu tudo certo — leva menos de 1 minuto, sem precisar assinar nada.</p>'
        . emailBotao('📄 Ver termo e confirmar', $link);
    $resEmail = enviarEmail($emailDestinatario, "Confirme o recebimento do seu {$veiculo}", emailLayout($corpo), $nomeDestinatario);

    if ($resEmail !== true) {
        $motivo = is_array($resEmail) ? ($resEmail['erro'] ?? 'falha ao enviar') : 'falha ao enviar';
        return ['ok' => true, 'erro' => null, 'aviso' => "Link gerado, mas o e-mail não saiu ({$motivo}) — copie o link abaixo e mande manualmente.", 'link' => $link];
    }

    return ['ok' => true, 'erro' => null, 'aviso' => null, 'link' => $link];
}

/**
 * Cliente confirma o Termo Ciente pelo link público — nunca confia em
 * nada vindo do POST pra decidir QUAL avaliação, só o token (mesmo
 * cuidado do wizard de documentos: o id nunca é confiável vindo de fora,
 * só o token imprevisível resolve isso). Idempotente: reabrir o link já
 * confirmado nunca sobrescreve a ressalva/IP/data da 1ª confirmação, só
 * devolve o estado já salvo — evita que um 2º clique (F5, 2 abas)
 * "atualize" a prova do aceite com um IP/horário diferente do real.
 */
function confirmarTermoCiente(string $token, string $ressalva, string $ip, string $userAgent): array {
    $av = buscarAvaliacaoPorTermoCienteToken($token);
    if (!$av) {
        return ['ok' => false, 'erro' => 'Link inválido.', 'avaliacao' => null];
    }
    if ($av['termo_status'] === 'confirmado') {
        return ['ok' => true, 'erro' => null, 'avaliacao' => $av];
    }
    if (!in_array($av['termo_status'], ['enviado', 'gerado'], true)) {
        return ['ok' => false, 'erro' => 'Esse termo ainda não está pronto pra confirmação.', 'avaliacao' => null];
    }

    $db = getDB();
    $db->prepare("
        UPDATE veiculo_avaliacoes
        SET termo_status = 'confirmado', termo_assinado_em = datetime('now','localtime'),
            termo_ciente_ip = ?, termo_ciente_user_agent = ?, termo_ciente_ressalva = ?, termo_ciente_canal = 'link',
            updated_at = datetime('now','localtime')
        WHERE id = ?
    ")->execute([clean($ip), clean(mb_substr($userAgent, 0, 255)), clean($ressalva), $av['id']]);

    return ['ok' => true, 'erro' => null, 'avaliacao' => buscarAvaliacaoPorTermoCienteToken($token)];
}

/**
 * Confirma o Termo Ciente DIRETO NO ADMIN, com assinatura desenhada na
 * tela — 08/10/2026, "possivel cleinte assinar retirada do veiculo no
 * celular mesmo campo assinar tela... o avalista mostra abre campo ele
 * assina". Canal PRESENCIAL, complementar ao link por e-mail
 * (confirmarTermoCiente() acima): o avaliador tem o comprador na frente,
 * mostra o celular/tablet e ele desenha a assinatura com o dedo direto
 * na tela de admin/avaliacao.php — sem precisar do passo de e-mail.
 *
 * Mesmo resultado final que o link (termo_status='confirmado'), só o
 * CANAL (termo_ciente_canal) e a prova mudam: em vez de só IP/navegador
 * de quem clicou um link, grava a IMAGEM da assinatura desenhada de
 * verdade (PNG, mesmo destino Drive/local de toda mídia da vistoria,
 * salvarArquivoGeradoComoDocumento()). Sempre gera o PDF do termo igual
 * ao fluxo por link — nunca existiu geração separada pro canal
 * presencial, é o MESMO documento, só a confirmação em si muda de forma
 * (ver gerarPdfTermoAvaliacao(), parâmetro $assinaturaPngPath).
 *
 * $assinaturaDataUrl é sempre o canvas.toDataURL('image/png') cru, vindo
 * do navegador do avaliador — nunca confia em mais nada do POST pra
 * decidir qual avaliação, só o $avaliacaoId que o próprio admin já
 * validou (diferente do fluxo por link, onde o id nunca é confiável
 * vindo de fora e só o token resolve isso — aqui quem está mandando é
 * sempre uma sessão de admin autenticada, igual qualquer outra ação da
 * tela de vistoria).
 */
function confirmarTermoCientePresencial(int $avaliacaoId, string $assinaturaDataUrl, int $confirmadoPor): array {
    $av = buscarAvaliacao($avaliacaoId);
    if (!$av) {
        return ['ok' => false, 'erro' => 'Avaliação não encontrada.'];
    }
    if ($av['tipo'] !== 'venda') {
        return ['ok' => false, 'erro' => 'Assinatura na retirada é exclusiva das vistorias de venda.'];
    }
    if ($av['termo_status'] === 'confirmado') {
        return ['ok' => false, 'erro' => 'Esse termo já foi confirmado em ' . date('d/m/Y H:i', strtotime((string)$av['termo_assinado_em'])) . '.'];
    }

    $nomeDestinatario = trim((string)$av['comprador_nome']);
    if ($nomeDestinatario === '') {
        return ['ok' => false, 'erro' => 'Preencha os dados do comprador na venda antes de confirmar a retirada.'];
    }

    if (!preg_match('#^data:image/png;base64,(.+)$#', $assinaturaDataUrl, $m)) {
        return ['ok' => false, 'erro' => 'Assinatura inválida — peça pro cliente assinar de novo e confirme.'];
    }
    $bytes = base64_decode($m[1], true);
    if ($bytes === false || strlen($bytes) < 1) {
        return ['ok' => false, 'erro' => 'Assinatura vazia — peça pro cliente desenhar antes de confirmar.'];
    }

    $tmpAssinatura = tempnam(sys_get_temp_dir(), 'assinatura_retirada_') . '.png';
    file_put_contents($tmpAssinatura, $bytes);

    $itens = listarItensAvaliacao($avaliacaoId);
    $score = veiculoAvaliacaoScore($itens);
    $pdfPath = gerarPdfTermoAvaliacao($av, $itens, $score, $tmpAssinatura);
    if (!$pdfPath) {
        @unlink($tmpAssinatura);
        return ['ok' => false, 'erro' => 'Falha ao gerar o PDF do termo.'];
    }

    $db = getDB();
    $stmtCli = $db->prepare("SELECT c.id, c.nome FROM oportunidades o JOIN clientes c ON c.id = o.cliente_id WHERE o.id = ?");
    $stmtCli->execute([$av['oportunidade_id']]);
    $cli = $stmtCli->fetch();

    $copiaPdf = ['drive_file_id' => '', 'arquivo_url' => ''];
    $copiaAssinatura = ['drive_file_id' => '', 'arquivo_url' => ''];
    if ($cli) {
        $copiaPdf = salvarArquivoGeradoComoDocumento(
            (int)$cli['id'], $cli['nome'] ?: "Cliente #{$cli['id']}", $pdfPath,
            'termo_vistoria_' . $avaliacaoId . '.pdf', 'application/pdf', 'vistoria'
        );
        $copiaAssinatura = salvarArquivoGeradoComoDocumento(
            (int)$cli['id'], $cli['nome'] ?: "Cliente #{$cli['id']}", $tmpAssinatura,
            'assinatura_retirada_' . $avaliacaoId . '.png', 'image/png', 'vistoria'
        );
    }
    @unlink($pdfPath);
    @unlink($tmpAssinatura);

    $ip = auditoriaClienteIp();
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');

    $db->prepare("
        UPDATE veiculo_avaliacoes
        SET termo_status = 'confirmado', termo_assinado_em = datetime('now','localtime'),
            termo_ciente_ip = ?, termo_ciente_user_agent = ?, termo_ciente_canal = 'presencial',
            drive_file_id = ?, arquivo_url = ?,
            termo_ciente_assinatura_drive_file_id = ?, termo_ciente_assinatura_arquivo_url = ?,
            updated_at = datetime('now','localtime')
        WHERE id = ?
    ")->execute([
        clean($ip), clean(mb_substr($ua, 0, 255)),
        $copiaPdf['drive_file_id'], $copiaPdf['arquivo_url'],
        $copiaAssinatura['drive_file_id'], $copiaAssinatura['arquivo_url'],
        $avaliacaoId,
    ]);

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
