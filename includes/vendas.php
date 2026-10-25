<?php
/**
 * Camada central do módulo de VENDAS (Fastcar revende um veículo já
 * comprado — frota = oportunidades com etapa='fechado'). Mesma disciplina
 * do funil de compra (includes/oportunidades.php, regra #6 do CLAUDE.md):
 * NUNCA fazer UPDATE direto em vendas.etapa — toda mudança passa por
 * mudarEtapaVenda(), que grava o histórico (venda_historico) junto.
 *
 * 15/09/2026 — pedido direto do José/Jean ("você colocar galera para fazer
 * o fluxo e fechar pontas soltas"), seguindo o mesmo padrão já combinado
 * antes ("modulos de vendas seguir mesmo padrão de compra ter a telas de
 * negociação").
 *
 * 17/09/2026 — 2ª etapa do módulo (pedido José/Jean: "perfil vendedor,
 * ibox do vendedor igual compras, dasbord de vendas, vamos usar
 * qualificação do lead para vendas, vamos adcionar instancia só para
 * vendas"): ganhou funil de ENTRADA pelo WhatsApp, espelhando o funil de
 * compra — instância Z-API dedicada (includes/zapi_instancias.php,
 * config.zapi_instancia_vendas_*), qualificação por IA
 * (includes/ia_qualificacao_vendas.php) e perfil `vendedor` próprio
 * (fila/round-robin em includes/fila_vendas.php). A negociação CRIADA
 * MANUALMENTE a partir de um veículo já na frota (admin/veiculos.php,
 * botão "Vender") continua existindo como caminho alternativo — nem todo
 * comprador de revenda vem pelo WhatsApp (indicação, anúncio, presencial
 * continuam válidos); as duas origens (`vendas.origem`) convivem na mesma
 * tabela e no mesmo pipeline (admin/vendas.php).
 *
 * Diferente do funil de compra, o lead de venda pode chegar SEM saber
 * ainda qual veículo específico da frota quer (`oportunidade_id` nullable
 * enquanto `etapa` está em 'whatsapp'/'qualificacao_ia') — a IA capta o
 * que o comprador descreve (`veiculo_interesse_texto`) grounded na frota
 * REAL disponível (nunca inventa um veículo que não existe, regra #3), mas
 * o vínculo final com uma `oportunidade_id` específica é sempre confirmado
 * por um humano (vendedor, vincularVeiculoVenda()) — mesmo espírito de
 * "sistema nunca decide sozinho informação crítica" já usado no widget de
 * FIPE por placa.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/fila_vendas.php';
require_once __DIR__ . '/whatsapp_config.php';
require_once __DIR__ . '/documentos.php'; // salvarArquivoGeradoComoDocumento()/lerConteudoArquivoDocumento() — catálogo de mídia de revenda
require_once __DIR__ . '/financeiro.php'; // finGerarReceitaVendaAssinatura() — receita automática ao vender
require_once __DIR__ . '/contratos.php'; // cancelarContratosAtivosVenda() — invalida contrato pendente ao trocar o veículo vinculado (16/10/2026). Circular (contratos.php também requer vendas.php) — seguro, nenhum dos dois chama função do outro fora de corpo de função, só na definição

// 'whatsapp'/'qualificacao_ia'/'sem_perfil' são exclusivas de leads que
// entraram por origem='whatsapp' — negociação manual nunca passa por elas.
const ETAPAS_VENDA_VALIDAS = ['whatsapp', 'qualificacao_ia', 'crm_preenchido', 'negociacao', 'contrato_enviado', 'vendido', 'cancelada', 'sem_perfil'];
// 24/10/2026, "ta faltando o crm preenchido" — mesma etapa do funil de
// compra: IA terminou de qualificar, esperando o vendedor assumir.
const ETAPAS_VENDA_ATIVAS  = ['whatsapp', 'qualificacao_ia', 'crm_preenchido', 'negociacao', 'contrato_enviado'];
// Etapas do funil de PIPELINE (o que admin/vendas.php mostra na nav por
// padrão) — deixa 'whatsapp'/'qualificacao_ia'/'sem_perfil' fora da lista
// principal (aparecem juntas em "Leads (IA)"), igual o pipeline de compra
// separa etapa de entrada/qualificação do funil de negociação em si.
const ETAPAS_VENDA_PIPELINE = ['negociacao', 'contrato_enviado', 'vendido', 'cancelada'];
const ETAPAS_VENDA_LEAD_IA  = ['whatsapp', 'qualificacao_ia', 'crm_preenchido'];

function etapaVendaLabel(string $etapa): string {
    $labels = [
        'whatsapp'         => '💬 WhatsApp',
        'qualificacao_ia'  => '🤖 Qualificação IA',
        'crm_preenchido'   => '📋 CRM preenchido',
        'negociacao'       => '🤝 Negociação',
        'contrato_enviado' => '📤 Contrato enviado',
        'vendido'          => '✅ Vendido',
        'cancelada'        => '❌ Cancelada',
        'sem_perfil'       => '⚪ Sem perfil de compra',
    ];
    return $labels[$etapa] ?? ucfirst($etapa);
}

/**
 * Um veículo (oportunidade com etapa='fechado') só pode ter 1 negociação
 * de venda ATIVA por vez (negociacao/contrato_enviado) — mesma regra do
 * índice único parcial idx_vendas_ativa_por_veiculo no schema (defesa em
 * dupla camada: aplicação + banco). "Disponível pra vender" = está na
 * frota E não tem negociação ativa nem já vendido.
 *
 * 22/10/2026, "no seletor de veiculo não aparecer veiculos marcado como
 * vendido" — achado real: o MESMO carro físico pode ter 2 cadastros na
 * frota (mesma placa, 2 oportunidade_id distintos — já visto antes com
 * importação duplicada do CRM antigo, ver excluirVeiculoFrota()); se um
 * deles já tem venda ativa/vendida, o OUTRO (duplicado, sem venda
 * própria) continua passando a checagem acima sozinho — nunca deveria,
 * é o mesmo carro. Checagem extra: nenhuma OUTRA oportunidade com a
 * MESMA placa normalizada pode ter venda ativa/vendida. Placa vazia
 * nunca dispara essa checagem (cadastro legado sem placa registrada
 * nunca é "duplicado" por coincidência de string vazia).
 */
/**
 * Link público do veículo pra vendedor mandar pro cliente (24/10/2026,
 * "link dos veículos pro vendedor compartilhar"). Sem login: autenticado
 * por um HMAC de (veículo, vendedor) — ninguém consegue trocar o número
 * na URL pra ver outro carro nem "virar" outro vendedor. O vendedor entra
 * no link pra o botão de WhatsApp da página cair direto nele.
 */
/**
 * Fotos/vídeos das vistorias de um veículo (24/10/2026) — usadas como
 * capa/galeria quando o carro ainda não tem mídia de revenda própria.
 * Marca cada item com _vistoria=1 pra quem monta a URL saber a origem.
 */
function listarMidiasVistoriaVeiculo(int $oportunidadeId): array {
    $stmt = getDB()->prepare("
        SELECT f.id, f.tipo, f.mime, f.drive_file_id, f.arquivo_url, f.legenda, 1 AS _vistoria
        FROM veiculo_avaliacao_fotos f
        JOIN veiculo_avaliacoes a ON a.id = f.avaliacao_id
        WHERE a.oportunidade_id = ?
        ORDER BY f.id
    ");
    $stmt->execute([$oportunidadeId]);
    return $stmt->fetchAll();
}

/**
 * Carros "migrados da ZapSign" (24/10/2026): compra importada do CRM
 * antigo via admin/zapsign_importar.php ou install/zapsign_conciliar_vendas.php
 * — contrato de compra marcado com origem 'importado_zapsign_crm_antigo'.
 * Só os que ainda aparecem no estoque (fechado, não ocultos).
 */
function idsVeiculosMigradosZapsignNoEstoque(): array {
    $ids = array_map(fn($r) => (int)$r['oportunidade_id'], listarFrotaDisponivelParaVenda(100000));
    if (!$ids) return [];
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $stmt = getDB()->prepare("SELECT DISTINCT oportunidade_id FROM contratos WHERE tipo = 'compra' AND oportunidade_id IN ({$ph})
                              AND (campos_json LIKE '%importado_zapsign%' OR nome LIKE 'Contrato importado da ZapSign%')");
    $stmt->execute($ids);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function ocultarVeiculosEstoque(array $ids, string $motivo, bool $ocultar = true): int {
    $n = 0;
    $stmt = getDB()->prepare("UPDATE oportunidades SET oculto_estoque = ?, oculto_estoque_motivo = ?, updated_at = datetime('now','localtime') WHERE id = ? AND etapa = 'fechado'");
    foreach (array_unique(array_map('intval', $ids)) as $id) {
        $stmt->execute([$ocultar ? 1 : 0, $ocultar ? clean($motivo) : '', $id]);
        $n += $stmt->rowCount();
    }
    return $n;
}

function veiculoLinkPublicoSegredo(): string {
    $s = (string)getConfig('veiculo_link_publico_segredo');
    if ($s === '') {
        $s = bin2hex(random_bytes(32));
        setConfig('veiculo_link_publico_segredo', $s);
    }
    return $s;
}

function veiculoLinkPublicoToken(int $oportunidadeId, int $vendedorId): string {
    return substr(hash_hmac('sha256', $oportunidadeId . ':' . $vendedorId, veiculoLinkPublicoSegredo()), 0, 20);
}

function veiculoLinkPublicoValido(int $oportunidadeId, int $vendedorId, string $token): bool {
    return $oportunidadeId > 0 && $token !== '' && hash_equals(veiculoLinkPublicoToken($oportunidadeId, $vendedorId), $token);
}

function veiculoLinkPublicoUrl(int $oportunidadeId, int $vendedorId): string {
    $base = rtrim((string)(getConfig('app_base_url') ?: 'https://sistema.fastcar.solutions'), '/');
    return $base . '/public/veiculo.php?o=' . $oportunidadeId . '&v=' . $vendedorId . '&t=' . veiculoLinkPublicoToken($oportunidadeId, $vendedorId);
}

// 24/10/2026, "link pra mandar o catálogo inteiro do estoque" — 1 link por
// vendedor (o botão "Falar no WhatsApp" de cada carro vai pra ele).
function catalogoLinkPublicoToken(int $vendedorId): string {
    return substr(hash_hmac('sha256', 'catalogo:' . $vendedorId, veiculoLinkPublicoSegredo()), 0, 20);
}

function catalogoLinkPublicoValido(int $vendedorId, string $token): bool {
    return $vendedorId > 0 && $token !== '' && hash_equals(catalogoLinkPublicoToken($vendedorId), $token);
}

function catalogoLinkPublicoUrl(int $vendedorId): string {
    $base = rtrim((string)(getConfig('app_base_url') ?: 'https://sistema.fastcar.solutions'), '/');
    return $base . '/public/catalogo.php?v=' . $vendedorId . '&t=' . catalogoLinkPublicoToken($vendedorId);
}

/** 1ª foto de capa de um veículo pro link público: revenda, senão vistoria. */
function veiculoCapaPublica(int $oportunidadeId): ?array {
    foreach (listarMidiasRevenda($oportunidadeId) as $m) if ($m['tipo'] === 'foto') return $m;
    foreach (listarMidiasVistoriaVeiculo($oportunidadeId) as $m) if ($m['tipo'] === 'foto') return $m;
    return null;
}

function veiculoDisponivelParaVenda(int $oportunidadeId): bool {
    $db = getDB();
    $stmtOp = $db->prepare("SELECT etapa, veiculo_placa FROM oportunidades WHERE id = ?");
    $stmtOp->execute([$oportunidadeId]);
    $op = $stmtOp->fetch();
    if (!$op || $op['etapa'] !== 'fechado') return false;
    $stmtOc = $db->prepare("SELECT COALESCE(oculto_estoque, 0) FROM oportunidades WHERE id = ?");
    $stmtOc->execute([$oportunidadeId]);
    if ((int)$stmtOc->fetchColumn() === 1) return false;
    // Mesmo carro cadastrado 2x (mesma placa): só o "principal" vende —
    // nunca abre 2 negociações pro mesmo veículo físico.
    $principal = veiculoPrincipalPorPlaca((string)$op['veiculo_placa']);
    if ($principal !== null && $principal !== $oportunidadeId) return false;

    $stmt = $db->prepare("
        SELECT COUNT(*) FROM vendas WHERE oportunidade_id = ? AND etapa IN ('negociacao', 'contrato_enviado', 'vendido')
    ");
    $stmt->execute([$oportunidadeId]);
    if ((int)$stmt->fetchColumn() > 0) return false;

    $placaNorm = strtoupper(preg_replace('/[^A-Z0-9]/i', '', (string)$op['veiculo_placa']));
    if ($placaNorm !== '') {
        $stmtDup = $db->prepare("
            SELECT COUNT(*) FROM oportunidades o2
            JOIN vendas v2 ON v2.oportunidade_id = o2.id
            WHERE o2.id != ? AND o2.etapa = 'fechado'
              AND UPPER(REPLACE(REPLACE(o2.veiculo_placa, '-', ''), ' ', '')) = ?
              AND v2.etapa IN ('negociacao', 'contrato_enviado', 'vendido')
        ");
        $stmtDup->execute([$oportunidadeId, $placaNorm]);
        if ((int)$stmtDup->fetchColumn() > 0) return false;
    }

    return true;
}

/**
 * Abre uma nova negociação de venda pra um veículo da frota. Lança se o
 * veículo não existir/não estiver na frota, ou já tiver negociação ativa/
 * concluída — checagem em dobro com o índice único parcial do schema (essa
 * aqui dá mensagem de erro legível pro admin; o índice é a rede de
 * segurança final contra corrida de 2 requests simultâneas).
 */
function criarVenda(int $oportunidadeId, ?int $responsavelId = null): int {
    if (!veiculoDisponivelParaVenda($oportunidadeId)) {
        throw new RuntimeException("Veículo #{$oportunidadeId} não está disponível pra venda (não é da frota, ou já tem negociação ativa/concluída).");
    }

    $db = getDB();
    $db->prepare("
        INSERT INTO vendas (oportunidade_id, responsavel_id, etapa)
        VALUES (?, ?, 'negociacao')
    ")->execute([$oportunidadeId, $responsavelId]);
    $vendaId = (int)$db->lastInsertId();

    $db->prepare("
        INSERT INTO venda_historico (venda_id, etapa_anterior, etapa_nova, responsavel_id, observacao)
        VALUES (?, '', 'negociacao', ?, 'Negociação aberta')
    ")->execute([$vendaId, $responsavelId]);

    return $vendaId;
}

/**
 * Muda a etapa de uma negociação de venda, gravando o histórico junto —
 * nunca UPDATE direto (mesma regra #6 do funil de compra). Etapa
 * 'cancelada' exige $observacao (motivo) — mesmo espírito de
 * marcarPerdida() no funil de compra, nunca encerra sem dizer por quê.
 */
function mudarEtapaVenda(int $vendaId, string $etapaNova, ?int $responsavelId = null, string $observacao = ''): bool {
    if (!in_array($etapaNova, ETAPAS_VENDA_VALIDAS, true)) {
        throw new InvalidArgumentException("Etapa de venda inválida: {$etapaNova}");
    }

    $db = getDB();
    $stmt = $db->prepare("SELECT etapa FROM vendas WHERE id = ?");
    $stmt->execute([$vendaId]);
    $atual = $stmt->fetchColumn();
    if ($atual === false) {
        throw new RuntimeException("Venda #{$vendaId} não existe.");
    }

    if ($etapaNova === 'cancelada' && trim($observacao) === '') {
        throw new RuntimeException('Motivo do cancelamento é obrigatório.');
    }
    if ($etapaNova === 'sem_perfil' && trim($observacao) === '') {
        throw new RuntimeException('Motivo é obrigatório.');
    }

    $db->beginTransaction();
    try {
        $camposExtra = '';
        $valoresExtra = [];
        if ($etapaNova === 'vendido') {
            $camposExtra = ", data_venda = date('now','localtime')";
        } elseif ($etapaNova === 'cancelada') {
            $camposExtra = ', motivo_cancelamento = ?';
            $valoresExtra[] = clean($observacao);
        } elseif ($etapaNova === 'sem_perfil') {
            $camposExtra = ', motivo_perda = ?';
            $valoresExtra[] = clean($observacao);
        }

        $db->prepare("
            UPDATE vendas SET etapa = ?, updated_at = datetime('now','localtime') {$camposExtra} WHERE id = ?
        ")->execute([$etapaNova, ...$valoresExtra, $vendaId]);

        $db->prepare("
            INSERT INTO venda_historico (venda_id, etapa_anterior, etapa_nova, responsavel_id, observacao)
            VALUES (?, ?, ?, ?, ?)
        ")->execute([$vendaId, $atual, $etapaNova, $responsavelId, clean($observacao)]);

        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    // Fora da transação de propósito: nunca queremos um rollBack() numa
    // transação já commitada só porque o lançamento financeiro deu
    // problema. 19/09/2026, pedido direto: "você faz mesma coinsa com
    // venda assinou contrato gera receita" — ver finGerarReceitaVendaAssinatura().
    if ($etapaNova === 'vendido') {
        finGerarReceitaVendaAssinatura($vendaId, $responsavelId);
        // 24/09/2026, pedido direto: "na venda pagamos 5 por cento do valor
        // da entrada" — ver finRegistrarComissaoVendaFechada().
        finRegistrarComissaoVendaFechada($vendaId);
    } elseif ($etapaNova === 'cancelada') {
        // 19/09/2026, "cliente devolver veiculo... aquelas cobrança é
        // cancelada" — cobre tanto cancelamento pré-venda (normalmente sem
        // lançamento nenhum ainda) quanto devolução pós-venda (parcelas
        // futuras já rodando) com o mesmo gatilho — ver
        // finCancelarLancamentosPendentesVenda().
        finCancelarLancamentosPendentesVenda($vendaId);
    }

    return true;
}

/**
 * Seleção em massa (29/09/2026, mesmo pedido do lado de compra — ver
 * marcarPerdidaEmMassa() em includes/oportunidades.php) — cancela várias
 * negociações de venda numa passada só, mesmo motivo pra todas. Reaproveita
 * mudarEtapaVenda() pra cada id (regra #6), nunca UPDATE em lote direto.
 * Cada id tratado independente: id inexistente, fora da carteira do
 * vendedor (quando $restringirDono é passado) ou já fora de
 * ETAPAS_VENDA_ATIVAS conta como "ignorado", nunca derruba o lote.
 *
 * @param int[] $ids
 * @return array{total:int, sucesso:int, ignorados:int}
 */
/**
 * 24/10/2026, "opção pra eu selecionar múltiplo e atribuir responsável" —
 * troca o vendedor de várias negociações de uma vez (só etapas ativas),
 * gravando histórico em cada uma. Destino: vendedor, avaliador ou
 * super_admin ativo.
 */
function reatribuirVendasEmMassa(array $ids, int $novoResponsavelId, ?int $executadoPor = null): array {
    $db = getDB();
    $stmt = $db->prepare("SELECT id, nome FROM usuarios WHERE id = ? AND bloqueado = 0 AND perfil IN ('vendedor','avaliador','super_admin')");
    $stmt->execute([$novoResponsavelId]);
    $destino = $stmt->fetch();
    if (!$destino) throw new RuntimeException('Responsável de destino inválido.');
    $sucesso = 0; $ignorados = 0;
    foreach (array_unique(array_map('intval', $ids)) as $id) {
        $stmt = $db->prepare("SELECT v.etapa, v.responsavel_id, u.nome AS origem FROM vendas v LEFT JOIN usuarios u ON u.id = v.responsavel_id WHERE v.id = ?");
        $stmt->execute([$id]);
        $v = $stmt->fetch();
        if (!$v || !in_array($v['etapa'], ETAPAS_VENDA_ATIVAS, true) || (int)$v['responsavel_id'] === $novoResponsavelId) { $ignorados++; continue; }
        $db->beginTransaction();
        $db->prepare("UPDATE vendas SET responsavel_id = ?, updated_at = datetime('now','localtime') WHERE id = ?")->execute([$novoResponsavelId, $id]);
        $db->prepare("INSERT INTO venda_historico (venda_id, etapa_anterior, etapa_nova, observacao, responsavel_id) VALUES (?, ?, ?, ?, ?)")
           ->execute([$id, $v['etapa'], $v['etapa'], 'Reatribuído manualmente pelo super admin' . ($v['origem'] ? " (de {$v['origem']}" : ' (sem responsável anterior') . " pra {$destino['nome']})", $executadoPor]);
        $db->commit();
        $sucesso++;
    }
    return ['sucesso' => $sucesso, 'ignorados' => $ignorados, 'destino_nome' => $destino['nome']];
}

function cancelarVendaEmMassa(array $ids, string $motivo, ?int $responsavelId = null, ?int $restringirDono = null): array {
    if (trim($motivo) === '') {
        throw new InvalidArgumentException('Motivo do cancelamento é obrigatório.');
    }
    $ids = array_values(array_unique(array_map('intval', $ids)));
    $db = getDB();
    $sucesso = 0;
    $ignorados = 0;
    foreach ($ids as $vendaId) {
        if ($vendaId <= 0) {
            $ignorados++;
            continue;
        }
        $stmt = $db->prepare('SELECT etapa, responsavel_id FROM vendas WHERE id = ?');
        $stmt->execute([$vendaId]);
        $v = $stmt->fetch();
        if (!$v || !in_array($v['etapa'], ETAPAS_VENDA_ATIVAS, true)) {
            $ignorados++;
            continue;
        }
        if ($restringirDono !== null && (int)$v['responsavel_id'] !== $restringirDono) {
            $ignorados++;
            continue;
        }
        try {
            mudarEtapaVenda($vendaId, 'cancelada', $responsavelId, $motivo);
            $sucesso++;
        } catch (Throwable $e) {
            $ignorados++;
        }
    }
    return ['total' => count($ids), 'sucesso' => $sucesso, 'ignorados' => $ignorados];
}

/**
 * Anotação livre editável direto na linha da tabela de admin/vendas.php —
 * 01/10/2026, "adicionar campo para recado tanto na compra e revenda",
 * espelhando atualizarObservacaoManual() (includes/oportunidades.php, já
 * existia só pro funil de compra desde 29/09/2026) — mesma disciplina:
 * nunca é mudança de etapa (UPDATE direto, sem mudarEtapaVenda()/
 * histórico), e enquanto a negociação já está encerrada
 * (cancelada/sem_perfil) a tela reaproveita motivo_cancelamento/
 * motivo_perda em vez desse campo, mesmo espírito do lado de compra.
 */
function atualizarObservacaoManualVenda(int $vendaId, string $observacao): bool {
    $db = getDB();
    $stmt = $db->prepare("UPDATE vendas SET observacao_manual = ?, updated_at = datetime('now','localtime') WHERE id = ?");
    $stmt->execute([clean($observacao), $vendaId]);
    return $stmt->rowCount() > 0;
}

/**
 * Cria (ou reaproveita) o lead de VENDA por telefone e já abre a
 * negociação na etapa 'whatsapp' — espelha
 * includes/oportunidades.php::criarOuAbrirOportunidade() (regra #2:
 * "salvar desde o primeiro contato"), mas sem tabela `clientes`: o
 * comprador de revenda é identificado direto pelas colunas
 * `vendas.comprador_*` (mesma decisão de design já tomada na 1ª versão do
 * módulo — ver comentário no topo do arquivo/schema.sql).
 * `oportunidade_id` fica NULL até o vendedor confirmar o match com um
 * veículo real da frota (vincularVeiculoVenda()).
 *
 * $origem (opcional, 19/09/2026): ['canal_origem', 'campanha_origem',
 * 'anuncio_origem'] vindos de extrairOrigemAnuncio() — só gravados na
 * criação do lead NOVO, nunca reescritos numa reabertura (mesma regra do
 * lado de compra, criarOuAbrirOportunidade()).
 */
function criarOuAbrirVendaLead(string $telefone, string $nome = '', array $origem = []): array {
    $db = getDB();
    $telNorm = normalizarTelefone($telefone);
    if (!$telNorm || strlen($telNorm) < 12) {
        throw new InvalidArgumentException("Telefone inválido: {$telefone}");
    }

    // Já existe negociação ativa (ainda não tocada pelo vendedor — mesma
    // ideia de "não duplicar por causa da mesma mensagem de entrada" do
    // funil de compra) pra esse telefone? Reaproveita.
    $etapasAtivasPlaceholder = implode(',', array_fill(0, count(ETAPAS_VENDA_ATIVAS), '?'));
    $stmt = $db->prepare("
        SELECT id, responsavel_id FROM vendas
        WHERE comprador_telefone = ? AND etapa IN ({$etapasAtivasPlaceholder})
        ORDER BY id DESC LIMIT 1
    ");
    $stmt->execute([$telNorm, ...ETAPAS_VENDA_ATIVAS]);
    $existente = $stmt->fetch();

    if ($existente) {
        // fill-if-empty pro nome — mesma regra de sempre.
        if ($nome) {
            $db->prepare("UPDATE vendas SET comprador_nome = CASE WHEN comprador_nome = '' THEN ? ELSE comprador_nome END WHERE id = ?")
               ->execute([clean($nome), $existente['id']]);
        }
        return ['venda_id' => (int)$existente['id'], 'nova' => false, 'responsavel_id' => $existente['responsavel_id'] !== null ? (int)$existente['responsavel_id'] : null];
    }

    $db->prepare("
        INSERT INTO vendas (etapa, origem, comprador_nome, comprador_telefone, canal_origem, campanha_origem, anuncio_origem)
        VALUES ('whatsapp', 'whatsapp', ?, ?, ?, ?, ?)
    ")->execute([
        clean($nome),
        $telNorm,
        $origem['canal_origem'] ?? '',
        $origem['campanha_origem'] ?? '',
        $origem['anuncio_origem'] ?? '',
    ]);
    $vendaId = (int)$db->lastInsertId();

    // Distribuição automática (mesmo padrão do rodízio de compra) já na
    // entrada, não só quando a IA termina de qualificar.
    $responsavelId = atribuirVendedorAutomatico();
    if ($responsavelId !== null) {
        $db->prepare("UPDATE vendas SET responsavel_id = ? WHERE id = ?")->execute([$responsavelId, $vendaId]);
    }

    $db->prepare("
        INSERT INTO venda_historico (venda_id, etapa_anterior, etapa_nova, responsavel_id, observacao)
        VALUES (?, '', 'whatsapp', ?, ?)
    ")->execute([$vendaId, $responsavelId, 'Lead de venda criado — entrada pelo WhatsApp' . ($responsavelId ? ' (atribuído automaticamente)' : '')]);

    notificarNovoLeadVendas($vendaId, $nome ?: '(sem nome)', $telNorm);

    return ['venda_id' => $vendaId, 'nova' => true, 'responsavel_id' => $responsavelId];
}

/**
 * Frota disponível pra vender AGORA (mesmo critério de
 * veiculoDisponivelParaVenda(), em lote) — usada pela qualificação por IA
 * (includes/ia_qualificacao_vendas.php) pra sempre responder o comprador
 * com base no que EXISTE de verdade, nunca inventar um veículo (regra #3
 * do CLAUDE.md). Resumo enxuto (id, marca/modelo/ano, valor de referência)
 * — o suficiente pra IA conversar sobre o que tem disponível sem expor
 * dado sensível (placa/chassi/financiamento) pro comprador.
 *
 * 22/10/2026, mesma exclusão por placa duplicada de
 * veiculoDisponivelParaVenda() (ver docblock lá) — direto em SQL aqui,
 * pra não rodar 1 query extra por linha num bulk listing.
 */
function listarFrotaDisponivelParaVenda(int $limite = 30): array {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT o.id AS oportunidade_id, o.veiculo_marca, o.veiculo_modelo, o.veiculo_ano,
               COALESCE(o.valor_fipe_referencia, o.valor_final) AS valor_referencia,
               o.valor_fipe_referencia, o.veiculo_placa, o.tipo_veiculo, o.data_compra
        FROM oportunidades o
        WHERE o.etapa = 'fechado'
          AND COALESCE(o.oculto_estoque, 0) = 0
          AND NOT EXISTS (
              SELECT 1 FROM vendas v
              WHERE v.oportunidade_id = o.id AND v.etapa IN ('negociacao', 'contrato_enviado', 'vendido')
          )
          AND (
              TRIM(COALESCE(o.veiculo_placa, '')) = ''
              OR NOT EXISTS (
                  SELECT 1 FROM oportunidades o2
                  JOIN vendas v2 ON v2.oportunidade_id = o2.id
                  WHERE o2.id != o.id AND o2.etapa = 'fechado'
                    AND UPPER(REPLACE(REPLACE(o2.veiculo_placa, '-', ''), ' ', ''))
                      = UPPER(REPLACE(REPLACE(o.veiculo_placa, '-', ''), ' ', ''))
                    AND v2.etapa IN ('negociacao', 'contrato_enviado', 'vendido')
              )
          )
        ORDER BY o.data_compra DESC
        LIMIT ?
    ");
    $stmt->bindValue(1, $limite + 200, PDO::PARAM_INT);
    $stmt->execute();
    // 24/10/2026, "Fiesta está duplicando — acaba gerando dois lugares de
    // negociação": mesmo veículo físico (mesma placa) cadastrado em 2
    // oportunidades aparece 1 vez só — sempre o "principal".
    $saida = [];
    foreach ($stmt->fetchAll() as $r) {
        $placaNorm = veiculoPlacaNormalizada((string)$r['veiculo_placa']);
        if ($placaNorm !== '' && veiculoPrincipalPorPlaca($placaNorm) !== (int)$r['oportunidade_id']) continue;
        $saida[] = $r;
        if (count($saida) >= $limite) break;
    }
    return $saida;
}

/**
 * 24/10/2026, "evitar duplicar placas" — junta os cadastros repetidos que
 * JÁ existem: pra cada placa com mais de 1 veículo na frota, move vistorias
 * e fotos de revenda pro cadastro principal e oculta os outros (nunca
 * apaga nada; negociações antigas ficam onde estão).
 * @return array lista de ['placa','principal','duplicados'=>[ids]]
 */
function mesclarVeiculosDuplicadosPorPlaca(bool $aplicar = true): array {
    // 25/10/2026 — vale pra TODO veículo fechado (inclusive os ocultos,
    // ex: migrados do ZapSign), não só os do estoque: a mesma compra
    // aparecia 2x na aba ✅ Fechadas e somava 2x no total.
    $db = getDB();
    $grupos = $db->query("
        SELECT UPPER(REPLACE(REPLACE(veiculo_placa, '-', ''), ' ', '')) AS placa
        FROM oportunidades
        WHERE etapa = 'fechado' AND COALESCE(oculto_estoque_motivo, '') NOT LIKE 'Cadastro duplicado%'
          AND LENGTH(REPLACE(REPLACE(COALESCE(veiculo_placa,''), '-', ''), ' ', '')) >= 6
        GROUP BY placa HAVING COUNT(*) > 1
    ")->fetchAll(PDO::FETCH_COLUMN);
    $stmtGrupo = $db->prepare("
        SELECT o.id,
               EXISTS (SELECT 1 FROM vendas v WHERE v.oportunidade_id = o.id AND v.etapa != 'cancelada') AS tem_venda,
               (COALESCE(o.oculto_estoque, 0) = 0) AS visivel,
               EXISTS (SELECT 1 FROM veiculo_avaliacoes a WHERE a.oportunidade_id = o.id AND a.status = 'concluida') AS tem_vistoria,
               EXISTS (SELECT 1 FROM veiculo_midias_revenda m WHERE m.oportunidade_id = o.id) AS tem_midia,
               EXISTS (SELECT 1 FROM contratos ct WHERE ct.oportunidade_id = o.id AND ct.tipo = 'compra' AND ct.status = 'assinado') AS tem_contrato,
               (COALESCE(o.valor_final, 0) > 0) AS tem_valor
        FROM oportunidades o
        WHERE o.etapa = 'fechado' AND COALESCE(o.oculto_estoque_motivo, '') NOT LIKE 'Cadastro duplicado%'
          AND UPPER(REPLACE(REPLACE(o.veiculo_placa, '-', ''), ' ', '')) = ?
        ORDER BY tem_venda DESC, tem_contrato DESC, tem_valor DESC, tem_vistoria DESC, tem_midia DESC, visivel DESC, o.id ASC
    ");
    $saida = [];
    foreach ($grupos as $placa) {
        $stmtGrupo->execute([$placa]);
        $linhas = $stmtGrupo->fetchAll();
        if (count($linhas) < 2) continue;
        $comVenda = array_filter($linhas, fn($l) => (int)$l['tem_venda'] === 1);
        $principal = (int)$linhas[0]['id'];
        $dups = array_map(fn($l) => (int)$l['id'], array_slice($linhas, 1));
        // 2 cadastros com venda própria = provável recompra real — nunca mescla sozinho.
        $conflito = count($comVenda) > 1;
        $saida[] = ['placa' => $placa, 'principal' => $principal, 'duplicados' => $dups, 'conflito' => $conflito];
        if (!$aplicar || $conflito) continue;
        $algumVisivel = (bool)array_filter($linhas, fn($l) => (int)$l['visivel'] === 1);
        $db->beginTransaction();
        try {
            // Se o carro estava visível no estoque por um dos cadastros, o
            // principal herda essa visibilidade (senão o carro some do estoque).
            if ($algumVisivel && (int)$linhas[0]['visivel'] === 0) {
                $antesP = $db->query("SELECT COALESCE(oculto_estoque,0) || '|' || COALESCE(oculto_estoque_motivo,'') FROM oportunidades WHERE id = " . $principal)->fetchColumn();
                setConfig('mesclagem_principal_' . $principal, (string)$antesP);
                $db->prepare("UPDATE oportunidades SET oculto_estoque = 0, oculto_estoque_motivo = '' WHERE id = ?")->execute([$principal]);
            }
            foreach ($dups as $d) {
                // Guarda o que foi movido, pra desfazerMesclagemVeiculos() devolver certinho.
                $movAv = $db->query("SELECT id FROM veiculo_avaliacoes WHERE oportunidade_id = " . (int)$d)->fetchAll(PDO::FETCH_COLUMN);
                $movMi = $db->query("SELECT id FROM veiculo_midias_revenda WHERE oportunidade_id = " . (int)$d)->fetchAll(PDO::FETCH_COLUMN);
                $ocultoAntes = $db->query("SELECT COALESCE(oculto_estoque,0) || '|' || COALESCE(oculto_estoque_motivo,'') FROM oportunidades WHERE id = " . (int)$d)->fetchColumn();
                setConfig('mesclagem_veiculo_' . $d, json_encode(['principal' => $principal, 'av' => $movAv, 'mi' => $movMi, 'oculto_antes' => $ocultoAntes], JSON_UNESCAPED_UNICODE));
                $db->prepare("UPDATE veiculo_avaliacoes SET oportunidade_id = ? WHERE oportunidade_id = ?")->execute([$principal, $d]);
                $db->prepare("UPDATE veiculo_midias_revenda SET oportunidade_id = ? WHERE oportunidade_id = ?")->execute([$principal, $d]);
                $db->prepare("UPDATE oportunidades SET oculto_estoque = 1, oculto_estoque_motivo = ?, updated_at = datetime('now','localtime') WHERE id = ?")
                   ->execute(["Cadastro duplicado da placa {$placa} — mesclado no #{$principal}", $d]);
                $db->prepare("INSERT INTO oportunidade_historico (oportunidade_id, etapa_anterior, etapa_nova, observacao) VALUES (?, 'fechado', 'fechado', ?)")
                   ->execute([$d, "Duplicado da placa {$placa}: vistorias/fotos movidas pro #{$principal}; cadastro ocultado do estoque e da lista de compras fechadas"]);
            }
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }
    return $saida;
}

/**
 * Desfaz TODA mesclagem de veículo duplicado (24/10/2026, Jean: "acho que
 * você tirou a verdadeira compra e deixou apenas a avaliação do Levy — tem
 * como voltar as 24?"). Cada cadastro marcado 'Cadastro duplicado...' volta
 * a aparecer; o estado de oculto anterior é restaurado (gravado na hora da
 * mesclagem; pra mesclagem antiga sem esse registro, volta oculto só se for
 * compra importada do ZapSign — mesmo critério da migração). Vistorias/fotos
 * movidas voltam pro cadastro de origem quando o registro existe.
 */
function desfazerMesclagemVeiculos(): int {
    $db = getDB();
    $ids = $db->query("SELECT id FROM oportunidades WHERE COALESCE(oculto_estoque_motivo, '') LIKE 'Cadastro duplicado%'")->fetchAll(PDO::FETCH_COLUMN);
    $stmtZap = $db->prepare("SELECT 1 FROM contratos WHERE tipo = 'compra' AND oportunidade_id = ?
                             AND (campos_json LIKE '%importado_zapsign%' OR nome LIKE 'Contrato importado da ZapSign%') LIMIT 1");
    $n = 0;
    foreach ($ids as $id) {
        $id = (int)$id;
        $reg = json_decode((string)getConfig('mesclagem_veiculo_' . $id), true);
        $db->beginTransaction();
        try {
            if (is_array($reg)) {
                [$oc, $mot] = array_pad(explode('|', (string)($reg['oculto_antes'] ?? '0|'), 2), 2, '');
                foreach (($reg['av'] ?? []) as $a) $db->prepare("UPDATE veiculo_avaliacoes SET oportunidade_id = ? WHERE id = ?")->execute([$id, (int)$a]);
                foreach (($reg['mi'] ?? []) as $m) $db->prepare("UPDATE veiculo_midias_revenda SET oportunidade_id = ? WHERE id = ?")->execute([$id, (int)$m]);
            } else {
                $stmtZap->execute([$id]);
                $oc = $stmtZap->fetchColumn() ? '1' : '0';
                $mot = $oc === '1' ? 'Migrado do ZapSign (CRM antigo) — já vendido' : '';
            }
            $db->prepare("UPDATE oportunidades SET oculto_estoque = ?, oculto_estoque_motivo = ?, updated_at = datetime('now','localtime') WHERE id = ?")
               ->execute([(int)$oc, $mot, $id]);
            $db->prepare("INSERT INTO oportunidade_historico (oportunidade_id, etapa_anterior, etapa_nova, observacao) VALUES (?, 'fechado', 'fechado', ?)")
               ->execute([$id, 'Mesclagem de duplicado desfeita — cadastro voltou pra lista de compras fechadas']);
            $db->commit();
            setConfig('mesclagem_veiculo_' . $id, '');
            $pr = (int)($reg['principal'] ?? 0);
            $antesP = $pr ? (string)getConfig('mesclagem_principal_' . $pr) : '';
            if ($antesP !== '') {
                [$ocP, $motP] = array_pad(explode('|', $antesP, 2), 2, '');
                $db->prepare("UPDATE oportunidades SET oculto_estoque = ?, oculto_estoque_motivo = ? WHERE id = ?")->execute([(int)$ocP, $motP, $pr]);
                setConfig('mesclagem_principal_' . $pr, '');
            }
            $n++;
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }
    return $n;
}

function veiculoPlacaNormalizada(string $placa): string {
    return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $placa));
}

/**
 * Entre as oportunidades 'fechado' (não ocultas) com a mesma placa, qual é a
 * "principal" — a que tem vistoria concluída, depois a que tem foto de
 * revenda, depois a mais nova. null = sem placa / nenhuma.
 */
function veiculoPrincipalPorPlaca(string $placa): ?int {
    $norm = veiculoPlacaNormalizada($placa);
    if (strlen($norm) < 6) return null;
    $stmt = getDB()->prepare("
        SELECT o.id,
               EXISTS (SELECT 1 FROM vendas v WHERE v.oportunidade_id = o.id AND v.etapa IN ('negociacao','contrato_enviado','vendido')) AS tem_venda,
               EXISTS (SELECT 1 FROM veiculo_avaliacoes a WHERE a.oportunidade_id = o.id AND a.status = 'concluida') AS tem_vistoria,
               EXISTS (SELECT 1 FROM veiculo_midias_revenda m WHERE m.oportunidade_id = o.id) AS tem_midia
        FROM oportunidades o
        WHERE o.etapa = 'fechado' AND COALESCE(o.oculto_estoque, 0) = 0
          AND UPPER(REPLACE(REPLACE(o.veiculo_placa, '-', ''), ' ', '')) = ?
        ORDER BY tem_venda DESC, tem_vistoria DESC, tem_midia DESC, o.id DESC
        LIMIT 1
    ");
    $stmt->execute([$norm]);
    $id = $stmt->fetchColumn();
    return $id === false ? null : (int)$id;
}

/**
 * Confirma o vínculo entre uma negociação de venda (lead qualificado pela
 * IA, `oportunidade_id` ainda NULL) e um veículo real da frota — SEMPRE
 * ação humana (vendedor escolhendo na tela, admin/venda.php), nunca a IA
 * decide sozinha: mesmo espírito do widget de FIPE por placa (o sistema
 * nunca aplica o candidato "óbvio" sozinho). Lança se o veículo não
 * estiver disponível (mesma checagem de criarVenda()).
 *
 * 16/10/2026, "em vendas permita alterar veículo para gerar novo
 * contrato" — a mesma função também cobre TROCAR um veículo já
 * vinculado (não só a 1ª vinculação): quando a `oportunidade_id` de
 * fato muda, qualquer contrato ainda não assinado pra essa venda foi
 * gerado pro veículo ERRADO/anterior e nunca pode continuar vivo/
 * assinável (ver cancelarContratosAtivosVenda(), includes/contratos.php)
 * — nunca mexe em contrato já assinado. Reselecionar o MESMO veículo
 * (sem mudança real) nunca cancela nada.
 */
function vincularVeiculoVenda(int $vendaId, int $oportunidadeId): bool {
    if (!veiculoDisponivelParaVenda($oportunidadeId)) {
        throw new RuntimeException("Veículo #{$oportunidadeId} não está disponível pra venda (não é da frota, ou já tem negociação ativa/concluída).");
    }
    $db = getDB();
    $stmtAtual = $db->prepare("SELECT oportunidade_id FROM vendas WHERE id = ?");
    $stmtAtual->execute([$vendaId]);
    $oportunidadeAnterior = $stmtAtual->fetchColumn();

    $db->prepare("UPDATE vendas SET oportunidade_id = ?, updated_at = datetime('now','localtime') WHERE id = ?")
       ->execute([$oportunidadeId, $vendaId]);

    if ($oportunidadeAnterior !== false && $oportunidadeAnterior !== null && (int)$oportunidadeAnterior !== $oportunidadeId) {
        cancelarContratosAtivosVenda($vendaId);
    }
    return true;
}

/**
 * Partes da entrada pagas via PIX, cada uma com valor e data próprios
 * (26/09/2026, réplica do sistema antigo — "Valor 1ª parte no PIX"/"Data
 * 1ª parte no PIX", quantas partes o vendedor precisar). Substitui a
 * lista inteira a cada salvamento (mesmo padrão de "recalcula tudo junto"
 * já usado em outras telas de formulário do projeto — não é histórico
 * incremental, é o estado atual da negociação enquanto ela não foi
 * fechada) e sempre recalcula vendas.valor_pago_contratacao como a SOMA
 * das partes — esse campo é o que finGerarReceitaVendaAssinatura() usa
 * pra gerar a receita real, então precisa refletir só dinheiro de
 * verdade (nunca o valor do bem de troca, ver salvarBemTrocaVenda()).
 * $partes: lista de ['valor' => float, 'data_prevista' => 'AAAA-MM-DD'|null].
 */
function salvarEntradaPartesVenda(int $vendaId, array $partes): float {
    $db = getDB();
    $db->beginTransaction();
    try {
        $db->prepare("DELETE FROM venda_entrada_partes WHERE venda_id = ?")->execute([$vendaId]);
        $numero = 0;
        $total = 0.0;
        $stmt = $db->prepare("INSERT INTO venda_entrada_partes (venda_id, parte_numero, valor, data_prevista) VALUES (?, ?, ?, ?)");
        foreach ($partes as $p) {
            $valor = (float)($p['valor'] ?? 0);
            if ($valor <= 0) continue;
            $numero++;
            $stmt->execute([$vendaId, $numero, $valor, $p['data_prevista'] ?: null]);
            $total += $valor;
        }
        $db->prepare("UPDATE vendas SET valor_pago_contratacao = ?, updated_at = datetime('now','localtime') WHERE id = ?")
           ->execute([$total, $vendaId]);
        $db->commit();
        return $total;
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

function listarEntradaPartesVenda(int $vendaId): array {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM venda_entrada_partes WHERE venda_id = ? ORDER BY parte_numero");
    $stmt->execute([$vendaId]);
    return $stmt->fetchAll();
}

/**
 * Bem recebido como parte do pagamento da entrada — veículo ou outro bem
 * (26/09/2026, réplica do sistema antigo: "Receber bem como parte do
 * pagamento da entrada"). bem_troca_valor entra no Quadro-Resumo do
 * contrato (includes/contratos_pdf.php), mas NUNCA em
 * vendas.valor_pago_contratacao — não é dinheiro recebido, é um ativo;
 * contá-lo como receita em finGerarReceitaVendaAssinatura() geraria
 * lançamento financeiro falso. Registrar o bem no patrimônio da empresa
 * (admin/patrimonio.php) continua sempre manual/separado, nunca automático.
 */
function salvarBemTrocaVenda(int $vendaId, array $dados): void {
    $db = getDB();
    $recebido = !empty($dados['recebido']);
    if (!$recebido) {
        $db->prepare("
            UPDATE vendas SET bem_troca_recebido = 0, bem_troca_tipo = '', bem_troca_nome = '',
                bem_troca_valor = NULL, bem_troca_modelo_ano = '', bem_troca_ano_fabricacao = '',
                bem_troca_cor = '', bem_troca_placa = '', bem_troca_chassi = '', bem_troca_renavam = '',
                updated_at = datetime('now','localtime')
            WHERE id = ?
        ")->execute([$vendaId]);
        return;
    }
    $tipo = ($dados['tipo'] ?? '') === 'veiculo' ? 'veiculo' : 'outro';
    $db->prepare("
        UPDATE vendas SET bem_troca_recebido = 1, bem_troca_tipo = ?, bem_troca_nome = ?, bem_troca_valor = ?,
            bem_troca_modelo_ano = ?, bem_troca_ano_fabricacao = ?, bem_troca_cor = ?,
            bem_troca_placa = ?, bem_troca_chassi = ?, bem_troca_renavam = ?,
            updated_at = datetime('now','localtime')
        WHERE id = ?
    ")->execute([
        $tipo,
        trim((string)($dados['nome'] ?? '')),
        isset($dados['valor']) && $dados['valor'] !== '' ? (float)$dados['valor'] : null,
        $tipo === 'veiculo' ? trim((string)($dados['modelo_ano'] ?? '')) : '',
        $tipo === 'veiculo' ? trim((string)($dados['ano_fabricacao'] ?? '')) : '',
        $tipo === 'veiculo' ? trim((string)($dados['cor'] ?? '')) : '',
        $tipo === 'veiculo' ? trim((string)($dados['placa'] ?? '')) : '',
        $tipo === 'veiculo' ? trim((string)($dados['chassi'] ?? '')) : '',
        $tipo === 'veiculo' ? trim((string)($dados['renavam'] ?? '')) : '',
        $vendaId,
    ]);
}

/**
 * Persiste os termos do parcelamento do saldo remanescente na própria
 * venda (26/09/2026) — antes só existiam de passagem no POST de
 * "gerar_parcelamento" (admin/venda.php), nunca gravados; sem isso não
 * dava pra citar "financiado em Nx de R$Y, vencendo dia Z" no Quadro-
 * Resumo do contrato depois de gerado.
 */
function salvarParcelamentoTermosVenda(int $vendaId, float $valorParcela, int $qtdParcelas, string $primeiraParcelaData, string $periodicidade = 'mensal'): void {
    $db = getDB();
    $db->prepare("
        UPDATE vendas SET parcelamento_valor_parcela = ?, parcelamento_qtd_parcelas = ?,
            parcelamento_primeira_parcela_data = ?, parcelamento_periodicidade = ?, updated_at = datetime('now','localtime')
        WHERE id = ?
    ")->execute([$valorParcela, $qtdParcelas, $primeiraParcelaData, $periodicidade, $vendaId]);
}

/**
 * "Vender na Promissória" (26/09/2026, pedido direto: "esse módulo dentro
 * das vendas... coloca dados básicos todo fluxo condições parcelas gera
 * resumo registra venda ja vem link pro cliente conferir dados mesma
 * coisa do antigo coloca estilo modal") — consolida num único
 * formulário/modal o que hoje vive espalhado em vários cards separados de
 * admin/venda.php (comprador, condições, entrada em partes, bem de troca):
 * registra a negociação inteira numa passada só, igual o sistema antigo
 * fazia. Nunca duplica lógica — só orquestra, na ordem certa, as mesmas
 * funções já testadas (criarVenda/vincularVeiculoVenda/
 * salvarEntradaPartesVenda/salvarBemTrocaVenda/salvarParcelamentoTermosVenda).
 *
 * De propósito NÃO dispara o link de documentos automaticamente pro
 * comprador (confirmado com o usuário) — isso continua um clique
 * separado, feito depois em admin/venda.php (o link já sai pronto pra
 * copiar assim que a negociação existe, só não é enviado sozinho).
 *
 * NÃO gera plano de parcelamento do saldo financiado (Asaas/local) — esse
 * passo tem lógica própria de decisão (Asaas configurado? cliente já
 * existe lá?) e continua no card dedicado de admin/venda.php, pra não
 * duplicar essa complexidade aqui; só persiste os TERMOS quando já vierem
 * preenchidos (mesma função de sempre), pra já aparecer no Quadro-Resumo
 * do contrato se o vendedor already souber os números na hora do cadastro.
 *
 * $dados esperado (índices ausentes/vazios são tratados como "não
 * informado", nunca um valor chutado — regra #3):
 *   oportunidade_id (int, obrigatório)
 *   comprador_nome, comprador_telefone (obrigatórios)
 *   comprador_cpf, comprador_rg, comprador_email (opcionais)
 *   preco_venda, forma_pagamento, saldo_preco_devido, prazo_quitacao_meses
 *   entrada_partes (array de ['valor'=>float, 'data_prevista'=>?string])
 *   bem_troca (array no formato de salvarBemTrocaVenda(), com 'recebido')
 *   parcelamento (['valor_parcela'=>float,'qtd_parcelas'=>int,'primeira_parcela_data'=>string], opcional)
 *
 * Lança InvalidArgumentException (dado obrigatório faltando) ou
 * RuntimeException (veículo indisponível, mesma checagem de criarVenda()).
 */
function registrarVendaPromissoria(array $dados, int $responsavelId): array {
    $oportunidadeId = (int)($dados['oportunidade_id'] ?? 0);
    $compradorNome = trim((string)($dados['comprador_nome'] ?? ''));
    $compradorTelefoneNorm = normalizarTelefone((string)($dados['comprador_telefone'] ?? ''));

    if (!$oportunidadeId) {
        throw new InvalidArgumentException('Escolha um veículo da frota.');
    }
    if ($compradorNome === '') {
        throw new InvalidArgumentException('Nome do comprador é obrigatório.');
    }
    if (!$compradorTelefoneNorm || strlen($compradorTelefoneNorm) < 12) {
        throw new InvalidArgumentException('Telefone do comprador é obrigatório e precisa ser válido.');
    }

    // criarVenda() já cria a negociação COM o veículo vinculado (mesmo
    // caminho de admin/veiculos.php, botão "Vender") — nunca chamar
    // vincularVeiculoVenda() depois: essa função é só pro OUTRO fluxo
    // (lead entrado pelo WhatsApp, oportunidade_id ainda NULL); chamá-la
    // aqui checaria disponibilidade de novo contra uma venda que a
    // própria criarVenda() já acabou de criar, sempre dando falso negativo.
    // criarVenda() já valida disponibilidade e lança se não estiver
    // (índice único parcial do schema é a rede de segurança final contra
    // corrida, mesma disciplina do resto do módulo).
    $vendaId = criarVenda($oportunidadeId, $responsavelId);

    $db = getDB();
    $prazoMeses = isset($dados['prazo_quitacao_meses']) && $dados['prazo_quitacao_meses'] !== null && $dados['prazo_quitacao_meses'] !== ''
        ? max(1, (int)$dados['prazo_quitacao_meses']) : 24;
    $db->prepare("
        UPDATE vendas
        SET comprador_nome = ?, comprador_telefone = ?, comprador_cpf = ?, comprador_rg = ?, comprador_email = ?,
            preco_venda = ?, forma_pagamento = ?, saldo_preco_devido = ?, prazo_quitacao_meses = ?,
            updated_at = datetime('now','localtime')
        WHERE id = ?
    ")->execute([
        clean($compradorNome),
        $compradorTelefoneNorm,
        clean((string)($dados['comprador_cpf'] ?? '')),
        clean((string)($dados['comprador_rg'] ?? '')),
        clean((string)($dados['comprador_email'] ?? '')),
        isset($dados['preco_venda']) && $dados['preco_venda'] !== null ? (float)$dados['preco_venda'] : null,
        clean((string)($dados['forma_pagamento'] ?? '')),
        isset($dados['saldo_preco_devido']) && $dados['saldo_preco_devido'] !== null ? (float)$dados['saldo_preco_devido'] : null,
        $prazoMeses,
        $vendaId,
    ]);

    $totalEntrada = 0.0;
    if (!empty($dados['entrada_partes'])) {
        $totalEntrada = salvarEntradaPartesVenda($vendaId, $dados['entrada_partes']);
    }

    $bemTroca = null;
    if (!empty($dados['bem_troca']) && !empty($dados['bem_troca']['recebido'])) {
        salvarBemTrocaVenda($vendaId, $dados['bem_troca']);
        $bemTroca = $dados['bem_troca'];
    }

    $parcelamento = null;
    $p = $dados['parcelamento'] ?? null;
    if ($p && (float)($p['valor_parcela'] ?? 0) > 0 && (int)($p['qtd_parcelas'] ?? 0) > 0 && !empty($p['primeira_parcela_data'])) {
        // 22/10/2026, "cliente quer pagar valor semanal mais opção e
        // condição de venda" — mesma whitelist de admin/venda.php, nunca
        // confia em valor cru vindo de fora.
        $periodicidadeP = in_array($p['periodicidade'] ?? '', ['mensal', 'semanal'], true) ? $p['periodicidade'] : 'mensal';
        salvarParcelamentoTermosVenda($vendaId, (float)$p['valor_parcela'], (int)$p['qtd_parcelas'], (string)$p['primeira_parcela_data'], $periodicidadeP);
        $parcelamento = $p;
    }

    return [
        'venda_id' => $vendaId,
        'total_entrada' => $totalEntrada,
        'bem_troca' => $bemTroca,
        'parcelamento' => $parcelamento,
    ];
}

// Fotos/vídeos são upload deliberado do vendedor (nunca reaproveita foto
// antiga da conversa de COMPRA — pode estar desatualizada, carro pode ter
// sido reformado/lavado/rodado km desde então; regra #3 do CLAUDE.md
// aplicada aqui também: nunca supor que dado velho ainda reflete a
// realidade). Vídeo pode ser bem maior que foto — teto próprio, mais
// generoso que UPLOAD_MAX_BYTES (10MB, pensado pra documento/PDF).
const VEICULO_MIDIA_MAX_BYTES_FOTO  = 10 * 1024 * 1024;  // 10MB
const VEICULO_MIDIA_MAX_BYTES_VIDEO = 50 * 1024 * 1024;  // 50MB
// 28/09/2026 — teto do LOTE inteiro (soma de todos os arquivos de 1 envio
// com "múltiplas fotos"), achado real: "413 erro reques" depois de
// liberar seleção múltipla sem nenhum limite de total. Fica abaixo do
// post_max_size/client_max_body_size configurados (95M, ver admin/.user.ini
// e install/SETUP_VPS.md) com margem — se o lote já vier maior que isso,
// devolve erro amigável ANTES de gastar tempo salvando arquivo por
// arquivo, em vez de deixar o nginx/PHP cortar a requisição crua.
const VEICULO_MIDIA_MAX_BYTES_LOTE = 80 * 1024 * 1024;  // 80MB por envio
const VEICULO_MIDIA_MIME_PERMITIDOS = [
    'image/jpeg' => ['foto', 'jpg'],
    'image/png'  => ['foto', 'png'],
    'image/webp' => ['foto', 'webp'],
    'video/mp4'       => ['video', 'mp4'],
    'video/quicktime' => ['video', 'mov'],
    'video/webm'      => ['video', 'webm'],
];

/**
 * Lista o catálogo de fotos/vídeos de um veículo da frota (17/09/2026,
 * "ela precisa enviar fotos do veículos - vídeo") — fica ligado à
 * OPORTUNIDADE (o veículo), não a uma negociação específica, então
 * persiste entre tentativas de venda diferentes do mesmo carro.
 */
function listarMidiasRevenda(int $oportunidadeId): array {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM veiculo_midias_revenda WHERE oportunidade_id = ? ORDER BY tipo, id");
    $stmt->execute([$oportunidadeId]);
    return $stmt->fetchAll();
}

/**
 * Sobe uma foto/vídeo pro catálogo de revenda de um veículo — mesma
 * disciplina de segurança de salvarUploadDocumento() (nunca confia no
 * Content-Type do navegador, detecta de verdade pelo conteúdo do
 * arquivo), mas guarda em `veiculo_midias_revenda` (não
 * `oportunidade_documentos`, que é semântica de documento de COMPRA) e no
 * MESMO destino Drive/local já usado pros documentos daquele veículo
 * (pasta do cliente original — subpasta "revenda" no fallback local).
 */
function salvarMidiaRevenda(int $oportunidadeId, array $arquivo, string $legenda): array {
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

    $db = getDB();
    $stmtCli = $db->prepare("SELECT c.id AS cliente_id, c.nome FROM oportunidades o JOIN clientes c ON c.id = o.cliente_id WHERE o.id = ?");
    $stmtCli->execute([$oportunidadeId]);
    $cli = $stmtCli->fetch();
    if (!$cli) {
        return ['ok' => false, 'erro' => 'Veículo não encontrado.'];
    }

    $nomeArquivo = $tipo . '_' . $oportunidadeId . '_' . time() . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
    $copia = salvarArquivoGeradoComoDocumento((int)$cli['cliente_id'], $cli['nome'] ?: "Cliente #{$cli['cliente_id']}", $arquivo['tmp_name'], $nomeArquivo, $mime, 'revenda');
    if (!$copia['drive_file_id'] && !$copia['arquivo_url']) {
        return ['ok' => false, 'erro' => 'Não foi possível salvar o arquivo agora — tente novamente.'];
    }

    try {
        dbComRetry(fn() => $db->prepare("
            INSERT INTO veiculo_midias_revenda (oportunidade_id, tipo, mime, drive_file_id, arquivo_url, legenda)
            VALUES (?, ?, ?, ?, ?, ?)
        ")->execute([$oportunidadeId, $tipo, $mime, $copia['drive_file_id'], $copia['arquivo_url'], clean($legenda)]));
    } catch (Throwable $e) {
        return ['ok' => false, 'erro' => 'Sistema ocupado agora — tente enviar essa foto de novo.'];
    }

    return ['ok' => true, 'erro' => null];
}

/**
 * Sobe VÁRIAS fotos/vídeos de uma vez, todos com a MESMA legenda —
 * 28/09/2026, "permita selecionar múltipla fotos depois cara coloca
 * legenda para ser mais rápido": antes só dava pra subir 1 arquivo por
 * envio (voltar pra tela, escolher o próximo, digitar legenda de novo...),
 * lento pra quem tira várias fotos do carro em sequência. Reaproveita
 * salvarMidiaRevenda() sem duplicar NENHUMA validação — só reindexa o
 * formato de array que `<input type="file" name="midias[]" multiple>`
 * produz (`$_FILES['midias']['name'][0..N]`) pro formato de arquivo único
 * que a função de baixo nível já espera, e chama 1x por arquivo. Nunca
 * aborta no meio — 1 arquivo com formato/tamanho inválido não impede os
 * outros de serem salvos, erro de cada um fica listado pro usuário
 * corrigir só o que falhou.
 */
function salvarMidiasRevendaEmLote(int $oportunidadeId, array $arquivosMultiplos, string $legenda): array {
    $nomes = $arquivosMultiplos['name'] ?? null;
    if (!is_array($nomes) || !count($nomes)) {
        return ['ok_count' => 0, 'total' => 0, 'erros' => ['Escolha pelo menos um arquivo.']];
    }

    // 28/09/2026 — soma o lote inteiro ANTES de processar qualquer
    // arquivo: um lote grande demais já teria sido cortado cru pelo
    // nginx/PHP (413) antes de chegar aqui, mas isso protege o caso do
    // meio-termo — passou pela infra, mas estoura o teto de negócio do
    // projeto (VEICULO_MIDIA_MAX_BYTES_LOTE) — com um erro amigável em
    // vez de deixar salvar um lote gigante sem controle nenhum.
    $somaBytes = array_sum(array_map('intval', $arquivosMultiplos['size'] ?? []));
    if ($somaBytes > VEICULO_MIDIA_MAX_BYTES_LOTE) {
        return [
            'ok_count' => 0,
            'total' => count($nomes),
            'erros' => ['Lote muito grande (' . round($somaBytes / 1024 / 1024) . 'MB) — envie até ' . (int)(VEICULO_MIDIA_MAX_BYTES_LOTE / 1024 / 1024) . 'MB por vez, em vários envios se precisar.'],
        ];
    }

    $total = count($nomes);
    $okCount = 0;
    $erros = [];
    for ($i = 0; $i < $total; $i++) {
        $nomeArquivo = (string)($nomes[$i] ?? '');
        if ($nomeArquivo === '' && ($arquivosMultiplos['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            continue; // slot vazio (navegador manda menos arquivos do que o esperado)
        }
        $arquivoUnico = [
            'name' => $nomeArquivo,
            'type' => $arquivosMultiplos['type'][$i] ?? '',
            'tmp_name' => $arquivosMultiplos['tmp_name'][$i] ?? '',
            'error' => $arquivosMultiplos['error'][$i] ?? UPLOAD_ERR_NO_FILE,
            'size' => $arquivosMultiplos['size'][$i] ?? 0,
        ];
        $r = salvarMidiaRevenda($oportunidadeId, $arquivoUnico, $legenda);
        if ($r['ok']) {
            $okCount++;
        } else {
            $erros[] = ($nomeArquivo !== '' ? "{$nomeArquivo}: " : '') . $r['erro'];
        }
    }

    return ['ok_count' => $okCount, 'total' => $total, 'erros' => $erros];
}

/** Apaga 1 mídia do catálogo (mesmo padrão de excluirConversaWhatsapp() — só a linha do banco, nunca tenta limpar o Drive). */
function excluirMidiaRevenda(int $midiaId): void {
    $db = getDB();
    $db->prepare("DELETE FROM veiculo_midias_revenda WHERE id = ?")->execute([$midiaId]);
}

/** Quantas fotos manda de uma vez (nunca o catálogo inteiro — evita floodar o comprador). */
const VEICULO_MIDIA_MAX_FOTOS_POR_ENVIO = 3;

/**
 * Manda o catálogo de fotos/vídeo de um veículo pro COMPRADOR via WhatsApp
 * (17/09/2026, "ela precisa enviar fotos do veículos - vídeo") — chamado
 * pela IA de vendas (includes/ia_qualificacao_vendas.php) quando identifica
 * interesse forte num veículo específico. Manda pela instância DEDICADA de
 * vendas (nunca a de compra). Lê os bytes reais (Drive ou local,
 * lerConteudoArquivoDocumento()) e envia como data URI base64 — evita
 * precisar de uma URL pública pra pasta do Drive/uploads, que não é
 * pública. Até `VEICULO_MIDIA_MAX_FOTOS_POR_ENVIO` fotos + o 1º vídeo do
 * catálogo (se tiver); nunca lança — mídia é sempre melhor esforço, mesmo
 * espírito do resto do projeto. Retorna quantas fotos/vídeos saíram de
 * verdade, pra quem chama decidir se registra "mídia enviada" ou tenta de
 * novo no próximo turno.
 */
function enviarMidiaCatalogoParaComprador(int $oportunidadeId, string $telefone, string $descricaoVeiculo): array {
    $enviouFoto = 0;
    $enviouVideo = 0;
    try {
        $credenciais = zapiCredenciaisVendas();
        $midias = listarMidiasRevenda($oportunidadeId);
        $fotos = array_slice(array_filter($midias, fn($m) => $m['tipo'] === 'foto'), 0, VEICULO_MIDIA_MAX_FOTOS_POR_ENVIO);
        $videos = array_slice(array_filter($midias, fn($m) => $m['tipo'] === 'video'), 0, 1);

        foreach ($fotos as $m) {
            $arquivo = lerConteudoArquivoDocumento($m['drive_file_id'] ?: null, $m['arquivo_url'] ?: null);
            if (!$arquivo) continue;
            $legenda = $m['legenda'] ?: $descricaoVeiculo;
            $dataUri = 'data:' . $arquivo['mime'] . ';base64,' . base64_encode($arquivo['content']);
            if (zapiEnviarImagem($telefone, $dataUri, $legenda, $credenciais)) $enviouFoto++;
        }
        foreach ($videos as $m) {
            $arquivo = lerConteudoArquivoDocumento($m['drive_file_id'] ?: null, $m['arquivo_url'] ?: null);
            if (!$arquivo) continue;
            $legenda = $m['legenda'] ?: $descricaoVeiculo;
            $dataUri = 'data:' . $arquivo['mime'] . ';base64,' . base64_encode($arquivo['content']);
            if (zapiEnviarVideo($telefone, $dataUri, $legenda, $credenciais)) $enviouVideo++;
        }
    } catch (Throwable $e) {
        // melhor esforço — nunca pode travar o turno de qualificação.
    }
    return ['fotos' => $enviouFoto, 'video' => $enviouVideo];
}

/**
 * Avisa por WhatsApp os números cadastrados em Configurações quando um
 * lead de VENDA novo entra — mesmo mecanismo de notificarNovoLeadWhatsapp()
 * (includes/oportunidades.php), reaproveitando a mesma lista de números
 * (notificacao_leads_whatsapp) e a instância padrão de envio: aviso interno
 * pra equipe, não é conversa com o comprador, não precisa da instância
 * dedicada de vendas pra isso. Nunca lança, nunca bloqueia a criação do lead.
 */
function notificarNovoLeadVendas(int $vendaId, string $nomeComprador, string $telefoneComprador): void {
    // 24/10/2026, "IA de venda notifica todos os leads pro Jean — só os
    // leads quentes": lead novo de venda NÃO vai mais pros números de
    // Configurações (só o 🔥 quente vai, via notificarLeadQuenteVenda()).
    return;
    try {
        $lista = getConfig('notificacao_leads_whatsapp') ?: '';
        $numeros = array_filter(array_map('trim', explode(',', $lista)));
        if (!$numeros) return;

        $baseUrl = getConfig('app_base_url') ?: '';
        $link = $baseUrl ? rtrim($baseUrl, '/') . "/admin/venda.php?id={$vendaId}" : "venda #{$vendaId}";
        $msg = "🛒 Novo lead de VENDA no Fastcar CRM!\nComprador: {$nomeComprador}\nTelefone: {$telefoneComprador}\n{$link}";

        foreach ($numeros as $numero) {
            zapiEnviarTextoInterno($numero, $msg);
        }
    } catch (Throwable $e) {
        // notificação nunca pode derrubar a criação do lead
    }
}

/**
 * Avisa o VENDEDOR RESPONSÁVEL, por WhatsApp, quando a IA termina de
 * qualificar um lead de venda — mesmo padrão de
 * notificarConsultorLeadQualificado() (includes/oportunidades.php). Sem
 * responsável definido ou sem WhatsApp cadastrado, cai no aviso genérico
 * de notificacao_leads_whatsapp como fallback.
 */
function notificarVendedorLeadQualificado(int $vendaId, string $motivo = 'Qualificação concluída'): void {
    try {
        $db = getDB();
        $stmt = $db->prepare("
            SELECT v.resumo_ia, v.responsavel_id, v.comprador_nome, v.comprador_telefone,
                   u.whatsapp AS vendedor_whatsapp
            FROM vendas v
            LEFT JOIN usuarios u ON u.id = v.responsavel_id
            WHERE v.id = ?
        ");
        $stmt->execute([$vendaId]);
        $v = $stmt->fetch();
        if (!$v) return;

        $baseUrl = getConfig('app_base_url') ?: '';
        $link = $baseUrl ? rtrim($baseUrl, '/') . "/admin/venda.php?id={$vendaId}" : "venda #{$vendaId}";
        $msg = "📋 Lead de venda pronto! ({$motivo})\n"
             . "Comprador: {$v['comprador_nome']}\nTelefone: {$v['comprador_telefone']}\n\n"
             . ($v['resumo_ia'] ? "{$v['resumo_ia']}\n\n" : '')
             . $link;

        if (!empty($v['vendedor_whatsapp'])) {
            zapiEnviarTextoInterno($v['vendedor_whatsapp'], $msg);
            return;
        }

        // Sem vendedor com WhatsApp: só cai pros números de Configurações se
        // o lead for 🔥 quente (24/10/2026 — "só os leads quentes" pro Jean).
        $stmtT = $db->prepare("SELECT temperatura_lead FROM vendas WHERE id = ?");
        $stmtT->execute([$vendaId]);
        if ($stmtT->fetchColumn() !== 'quente') return;

        $lista = getConfig('notificacao_leads_whatsapp') ?: '';
        foreach (array_filter(array_map('trim', explode(',', $lista))) as $numero) {
            zapiEnviarTextoInterno($numero, $msg);
        }
    } catch (Throwable $e) {
        // notificação nunca pode travar o fluxo da qualificação
    }
}

/**
 * Fim de turno pro lado de VENDAS — 22/10/2026, "em vendas crm preenchido
 * notificar mesmo do sistema": espelha
 * notificarLeadsCrmPreenchidoFimTurno() (includes/oportunidades.php),
 * mesmo mecanismo ("mesmo do sistema" — mesmo horário configurável via
 * leadsCrmPreenchidoHorarioNotificar(), mesma lista de números em
 * config.notificacao_leads_whatsapp), só trocando `oportunidades`
 * (etapa='crm_preenchido') por `vendas` (etapa='negociacao' — o
 * equivalente de vendas pra "IA já qualificou, esperando o vendedor
 * assumir de verdade": vendas não tem uma etapa própria tipo
 * 'crm_preenchido', a transição qualificacao_ia→negociacao já É esse
 * momento). Complementa, nunca substitui, notificarVendedorLeadQualificado()
 * (que já avisa o vendedor individualmente NO MOMENTO da qualificação) —
 * este aqui é o resumo de fim de dia, pra pegar quem passou batido (lead
 * que chegou com o vendedor sem WhatsApp cadastrado, notificação que
 * falhou, etc), igual o mecanismo de compra já faz.
 */
function notificarVendasEmNegociacaoFimTurno(): array {
    try {
        $hoje = date('Y-m-d');
        $db = getDB();
        $stmt = $db->prepare("
            SELECT v.id, v.comprador_nome, v.comprador_telefone, v.veiculo_interesse_texto,
                   o.veiculo_marca, o.veiculo_modelo, u.nome AS responsavel_nome
            FROM vendas v
            LEFT JOIN oportunidades o ON o.id = v.oportunidade_id
            LEFT JOIN usuarios u ON u.id = v.responsavel_id
            WHERE v.etapa IN ('crm_preenchido', 'negociacao') AND date(v.created_at) = ?
            ORDER BY v.created_at
        ");
        $stmt->execute([$hoje]);
        $leads = $stmt->fetchAll();

        $lista = getConfig('notificacao_leads_whatsapp') ?: '';
        $numeros = array_filter(array_map('trim', explode(',', $lista)));
        if (!$numeros) {
            return ['ok' => false, 'motivo' => 'nenhum número de notificação configurado', 'total' => count($leads)];
        }

        $baseUrl = getConfig('app_base_url') ?: '';
        if (!$leads) {
            $msg = "🛒 Fim de turno (vendas) — nenhum lead qualificado hoje ficou parado em negociação. ✅";
        } else {
            $linhas = [];
            foreach ($leads as $l) {
                $veiculo = trim(($l['veiculo_marca'] ?? '') . ' ' . ($l['veiculo_modelo'] ?? '')) ?: trim((string)($l['veiculo_interesse_texto'] ?? ''));
                $link = $baseUrl ? rtrim($baseUrl, '/') . "/admin/venda.php?id={$l['id']}" : "#{$l['id']}";
                $resp = $l['responsavel_nome'] ? $l['responsavel_nome'] : 'sem responsável';
                $linhas[] = "• {$l['comprador_nome']} ({$l['comprador_telefone']})" . ($veiculo !== '' ? " — {$veiculo}" : '')
                          . " — {$resp}\n  {$link}";
            }
            $msg = "🛒 Fim de turno (vendas) — " . count($leads) . ' lead(s) qualificado(s) hoje ainda em negociação:' . "\n\n"
                 . implode("\n\n", $linhas);
        }

        $enviouAlgum = false;
        foreach ($numeros as $numero) {
            if (zapiEnviarTextoInterno($numero, $msg)) $enviouAlgum = true;
        }
        return ['ok' => $enviouAlgum, 'total' => count($leads)];
    } catch (Throwable $e) {
        return ['ok' => false, 'motivo' => 'erro interno', 'total' => 0];
    }
}
