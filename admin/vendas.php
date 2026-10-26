<?php
/**
 * Vendas — pipeline de revenda de veículos já comprados (frota). Cada
 * linha é uma negociação com um comprador (includes/vendas.php); nasce a
 * partir de admin/veiculos.php (botão "Vender" num veículo disponível) OU,
 * desde 17/09/2026, sozinha pela instância Z-API dedicada de vendas
 * (comprador entra pelo WhatsApp, qualificado por IA — ver
 * includes/ia_qualificacao_vendas.php) — as duas origens convivem no mesmo
 * pipeline. Também serve de DASHBOARD do vendedor (mesmo espírito de
 * admin/index.php pro funil de compra): cards de resultado + funil filtrado
 * por responsável.
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../includes/dashboard.php';
requireAcessoVendas();

$db = getDB();
$perfil = $_SESSION['admin_perfil'];
$meuId = (int)$_SESSION['admin_id'];
// vendedor só vê a própria carteira; super_admin/supervisor veem tudo.
$souDono = in_array($perfil, ['vendedor', 'avaliador'], true); // 24/10/2026: avaliador atende vendas igual vendedor

/*
 * "Vender na Promissória" (26/09/2026) — modal único que registra a
 * negociação inteira numa passada só, consolidando o que hoje vive
 * espalhado em vários cards separados de admin/venda.php (comprador,
 * condições, entrada em partes, bem de troca) — "mesma coisa do antigo".
 * Reaproveita registrarVendaPromissoria() (includes/vendas.php), que só
 * orquestra as mesmas funções já testadas, na ordem certa. Nunca dispara
 * o link de documentos automaticamente (confirmado com o usuário) — isso
 * continua um clique separado, feito depois em admin/venda.php.
 *
 * Se o veículo não estiver na frota disponível (checkbox
 * "cadastrar_veiculo_novo"), cadastra primeiro via criarVeiculoManualFrota()
 * (mesma função já usada em admin/veiculos.php, inclusive a leitura de
 * CRLV por IA — admin/veiculo_crlv_ajax.php, cujo guard foi relaxado de
 * requireSuperAdmin() pra requireAcessoVendas() pra funcionar aqui também)
 * e usa o id recém-criado como veículo da venda.
 */
$erroPromissoria = '';
$modalPromissoriaAbrir = false;
// 24/10/2026 — vindo do 🚘 Estoque ("Vender → Promissória"): abre o modal
// já com o veículo escolhido.
$venderOpEstoque = (int)($_GET['vender_op'] ?? 0);
if ($venderOpEstoque > 0 && $_SERVER['REQUEST_METHOD'] !== 'POST' && $perfil !== 'supervisor') $modalPromissoriaAbrir = true;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'vender_promissoria') {
    $modalPromissoriaAbrir = true;
    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        $erroPromissoria = 'Sessão expirada, recarregue a página e tente de novo.';
    } elseif ($perfil === 'supervisor') {
        http_response_code(403);
        exit('Ação não permitida — perfil de acompanhamento só consulta.');
    } else {
        try {
            $oportunidadeIdEscolhida = (int)($_POST['oportunidade_id'] ?? 0);

            if (!empty($_POST['cadastrar_veiculo_novo'])) {
                // 06/10/2026, "como evitar duplicar veiculo" — mesmo
                // racional já aplicado em admin/veiculos.php: cadastrar
                // "veículo novo" com uma placa que JÁ está na frota quase
                // sempre é o mesmo carro duplicado (não é passível de
                // "confirmar mesmo assim" nesse modal — o próprio fluxo já
                // tem a saída certa: selecionar o veículo EXISTENTE no
                // dropdown da frota, nunca cadastrar de novo) — recusa com
                // mensagem clara, nunca cria a 2ª linha.
                $duplicidadePlaca = buscarVeiculoAtivoPorPlaca((string)($_POST['veiculo_placa'] ?? ''));
                if ($duplicidadePlaca) {
                    throw new RuntimeException(
                        "Essa placa já está cadastrada na frota — oportunidade #{$duplicidadePlaca['id']} " .
                        "({$duplicidadePlaca['veiculo_marca']} {$duplicidadePlaca['veiculo_modelo']}). " .
                        'Desmarque "cadastrar veículo novo" e selecione esse veículo direto no dropdown da frota.'
                    );
                }
                $novoVeiculo = criarVeiculoManualFrota(
                    (string)($_POST['vendedor_nome'] ?? ''),
                    (string)($_POST['vendedor_telefone'] ?? ''),
                    (string)($_POST['veiculo_marca'] ?? ''),
                    (string)($_POST['veiculo_modelo'] ?? ''),
                    (string)($_POST['veiculo_ano'] ?? ''),
                    (string)($_POST['veiculo_placa'] ?? ''),
                    (string)($_POST['veiculo_chassi'] ?? ''),
                    (string)($_POST['veiculo_renavam'] ?? ''),
                    valorMonetario((string)($_POST['veiculo_valor_final'] ?? '')),
                    $meuId
                );
                $oportunidadeIdEscolhida = (int)$novoVeiculo['oportunidade_id'];
            }

            $entradaPartesPost = [];
            $valoresPartesPost = $_POST['parte_valor'] ?? [];
            $datasPartesPost = $_POST['parte_data'] ?? [];
            foreach ((array)$valoresPartesPost as $i => $valorBruto) {
                $valorParte = valorMonetario((string)$valorBruto);
                if ($valorParte === null || $valorParte <= 0) continue;
                $entradaPartesPost[] = ['valor' => $valorParte, 'data_prevista' => (string)($datasPartesPost[$i] ?? '') ?: null];
            }

            $bemTrocaPost = [
                'recebido' => !empty($_POST['bem_troca_recebido']),
                'tipo' => (string)($_POST['bem_troca_tipo'] ?? ''),
                'nome' => (string)($_POST['bem_troca_nome'] ?? ''),
                'valor' => valorMonetario((string)($_POST['bem_troca_valor'] ?? '')),
                'modelo_ano' => (string)($_POST['bem_troca_modelo_ano'] ?? ''),
                'ano_fabricacao' => (string)($_POST['bem_troca_ano_fabricacao'] ?? ''),
                'cor' => (string)($_POST['bem_troca_cor'] ?? ''),
                'placa' => (string)($_POST['bem_troca_placa'] ?? ''),
                'chassi' => (string)($_POST['bem_troca_chassi'] ?? ''),
                'renavam' => (string)($_POST['bem_troca_renavam'] ?? ''),
            ];

            $parcelamentoPost = null;
            $vpParcela = valorMonetario((string)($_POST['parcelamento_valor_parcela'] ?? ''));
            $qtdParcelasPost = (int)($_POST['parcelamento_qtd_parcelas'] ?? 0);
            $primeiraParcelaPost = (string)($_POST['parcelamento_primeira_parcela_data'] ?? '');
            // 22/10/2026, "cliente quer pagar valor semanal mais opção e
            // condição de venda" — mesma whitelist do card de condições em
            // admin/venda.php; só persiste o termo aqui (vira a 1ª resposta
            // mostrada no resumo pós-cadastro), a geração de verdade do
            // plano financeiro continua sendo o botão "Gerar plano de
            // parcelamento" em admin/venda.php.
            $periodicidadePromissoriaPost = in_array($_POST['parcelamento_periodicidade'] ?? '', ['mensal', 'semanal'], true) ? $_POST['parcelamento_periodicidade'] : 'mensal';
            if ($vpParcela && $vpParcela > 0 && $qtdParcelasPost > 0 && $primeiraParcelaPost !== '') {
                $parcelamentoPost = ['valor_parcela' => $vpParcela, 'qtd_parcelas' => $qtdParcelasPost, 'primeira_parcela_data' => $primeiraParcelaPost, 'periodicidade' => $periodicidadePromissoriaPost];
            }

            $resultadoPromissoria = registrarVendaPromissoria([
                'oportunidade_id' => $oportunidadeIdEscolhida,
                'comprador_nome' => (string)($_POST['comprador_nome'] ?? ''),
                'comprador_telefone' => (string)($_POST['comprador_telefone'] ?? ''),
                'comprador_cpf' => (string)($_POST['comprador_cpf'] ?? ''),
                'comprador_rg' => (string)($_POST['comprador_rg'] ?? ''),
                'comprador_email' => (string)($_POST['comprador_email'] ?? ''),
                'preco_venda' => valorMonetario((string)($_POST['preco_venda'] ?? '')),
                'forma_pagamento' => (string)($_POST['forma_pagamento'] ?? ''),
                'saldo_preco_devido' => valorMonetario((string)($_POST['saldo_preco_devido'] ?? '')),
                // 06/10/2026, "pode remover campo prazo máximo para quitação na
                // promissória não precisa, pois já tem as condições do
                // financiamento" — o campo saiu do formulário (era redundante
                // com a seção "Parcelamento do saldo" logo abaixo, que já
                // captura os termos reais de forma mais precisa: qtd/valor/data
                // de cada parcela). registrarVendaPromissoria() já cai pro
                // padrão de 24 meses sozinha quando esse dado não vem — nunca
                // precisou de valor aqui.
                'entrada_partes' => $entradaPartesPost,
                'bem_troca' => $bemTrocaPost,
                'parcelamento' => $parcelamentoPost,
            ], $meuId);

            header('Location: /admin/venda.php?id=' . $resultadoPromissoria['venda_id'] . '&criado=1');
            exit;
        } catch (Throwable $e) {
            $erroPromissoria = $e->getMessage();
        }
    }
}

// 29/09/2026, mesmo pedido do lado de compra (ver admin/index.php) —
// seleção em massa na tabela, pra cancelar várias negociações de uma vez.
// Reaproveita cancelarVendaEmMassa() (includes/vendas.php) — nunca apaga
// nada, só encerra com motivo/histórico via mudarEtapaVenda(), mesma
// disciplina de "cancelar" de sempre.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'cancelar_venda_massa') {
    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        exit('Sessão expirada, recarregue a página.');
    }
    if ($perfil === 'supervisor') {
        http_response_code(403);
        exit('Ação não permitida — perfil de acompanhamento só consulta.');
    }
    $idsPost = array_map('intval', (array)($_POST['ids'] ?? []));
    $motivoPost = clean((string)($_POST['motivo'] ?? ''));
    $bulkQs = [];
    if (!$idsPost) {
        $bulkQs = ['bulk_erro' => 'Nenhuma negociação selecionada.'];
    } elseif ($motivoPost === '') {
        $bulkQs = ['bulk_erro' => 'Motivo é obrigatório.'];
    } else {
        try {
            $r = cancelarVendaEmMassa($idsPost, $motivoPost, $meuId, $souDono ? $meuId : null);
            $bulkQs = ['bulk_sucesso' => $r['sucesso'], 'bulk_ignorados' => $r['ignorados']];
        } catch (Throwable $e) {
            $bulkQs = ['bulk_erro' => $e->getMessage()];
        }
    }
    $qs = $_GET;
    unset($qs['bulk_sucesso'], $qs['bulk_ignorados'], $qs['bulk_erro']);
    header('Location: /admin/vendas.php?' . http_build_query(array_merge($qs, $bulkQs)));
    exit;
}

// 24/10/2026 — atribuir responsável em massa (só super_admin).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'reatribuir_venda_massa') {
    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        exit('Sessão expirada, recarregue a página.');
    }
    if ($perfil !== 'super_admin') {
        http_response_code(403);
        exit('Só o super admin atribui responsável em massa.');
    }
    $idsPost = array_map('intval', (array)($_POST['ids'] ?? []));
    $destinoPost = (int)($_POST['novo_responsavel_id'] ?? 0);
    try {
        if (!$idsPost) throw new RuntimeException('Nenhuma negociação selecionada.');
        $r = reatribuirVendasEmMassa($idsPost, $destinoPost, $meuId);
        $bulkQs = ['bulk_reatrib' => $r['sucesso'], 'bulk_ignorados' => $r['ignorados'], 'bulk_destino' => $r['destino_nome']];
    } catch (Throwable $e) {
        $bulkQs = ['bulk_erro' => $e->getMessage()];
    }
    $qs = $_GET;
    unset($qs['bulk_sucesso'], $qs['bulk_ignorados'], $qs['bulk_erro'], $qs['bulk_reatrib'], $qs['bulk_destino']);
    header('Location: /admin/vendas.php?' . http_build_query(array_merge($qs, $bulkQs)));
    exit;
}

// 01/10/2026, "adicionar campo para recado tanto na compra e revenda" —
// mesma mecânica de admin/index.php (atualizarObservacaoManual()): texto
// livre editável direto na linha, só enquanto a negociação ainda está
// ativa; nunca é mudança de etapa, nunca grava histórico.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'atualizar_observacao_manual') {
    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        exit('Sessão expirada, recarregue a página.');
    }
    if ($perfil === 'supervisor') {
        http_response_code(403);
        exit('Perfil de supervisão só acompanha, não altera negociações.');
    }
    $idObsPost = (int)($_POST['id'] ?? 0);
    $obsPost = (string)($_POST['observacao'] ?? '');
    try {
        if ($souDono) {
            $stmtDonoObs = $db->prepare('SELECT responsavel_id FROM vendas WHERE id = ?');
            $stmtDonoObs->execute([$idObsPost]);
            $vDonoObs = $stmtDonoObs->fetch();
            if (!$vDonoObs || (int)$vDonoObs['responsavel_id'] !== $meuId) {
                throw new RuntimeException('Essa negociação não é da sua carteira.');
            }
        }
        atualizarObservacaoManualVenda($idObsPost, $obsPost);
    } catch (Throwable $e) {
        // best-effort — se falhar (id inválido, não é dono), só não salva.
    }
    header('Location: /admin/vendas.php?' . http_build_query($_GET));
    exit;
}

// 24/10/2026, "botão no lead marcar cliente não qualificado / mudar etapa
// rápido" — troca de etapa direto da linha da tabela, sem abrir a negociação.
// Sempre via mudarEtapaVenda() (histórico); "não qualificado" = 'sem_perfil'
// com motivo obrigatório. Contrato/vendido continuam só pela tela da venda.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'etapa_rapida') {
    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        exit('Sessão expirada, recarregue a página.');
    }
    if ($perfil === 'supervisor') {
        http_response_code(403);
        exit('Perfil de supervisão só acompanha, não altera negociações.');
    }
    $idRap = (int)($_POST['id'] ?? 0);
    $etapaRap = (string)($_POST['etapa_nova'] ?? '');
    $motivoRap = trim((string)($_POST['motivo'] ?? ''));
    $qs = $_GET;
    unset($qs['rap_ok'], $qs['rap_erro']);
    try {
        if (!in_array($etapaRap, ['qualificacao_ia', 'crm_preenchido', 'negociacao', 'sem_perfil'], true)) throw new RuntimeException('Etapa inválida.');
        $stmtR = $db->prepare('SELECT etapa, responsavel_id FROM vendas WHERE id = ?');
        $stmtR->execute([$idRap]);
        $vR = $stmtR->fetch();
        if (!$vR || !in_array($vR['etapa'], ETAPAS_VENDA_ATIVAS, true)) throw new RuntimeException('Negociação não encontrada ou já encerrada.');
        if ($souDono && (int)$vR['responsavel_id'] !== $meuId) throw new RuntimeException('Essa negociação não é da sua carteira.');
        if ($etapaRap === 'sem_perfil' && $motivoRap === '') throw new RuntimeException('Informe o motivo de não qualificado.');
        if ($vR['etapa'] !== $etapaRap) {
            mudarEtapaVenda($idRap, $etapaRap, $meuId, $etapaRap === 'sem_perfil' ? 'Não qualificado: ' . $motivoRap : 'Etapa alterada direto pela lista');
        }
        $qs['rap_ok'] = $etapaRap === 'sem_perfil' ? "Negociação #{$idRap} marcada como não qualificada." : "Negociação #{$idRap} movida pra " . etapaVendaLabel($etapaRap) . '.';
    } catch (Throwable $e) {
        $qs['rap_erro'] = $e->getMessage();
    }
    header('Location: /admin/vendas.php?' . http_build_query($qs));
    exit;
}

$frotaDisponivelPromissoria = listarFrotaDisponivelParaVenda();

$etapaFiltro = (string)($_GET['etapa'] ?? '');
$busca = trim((string)($_GET['q'] ?? ''));

// 06/10/2026, "no finaceiro da exibir cads... carros vendidos esses mes"
// — vem do card "🚙 Carros vendidos" em admin/financeiro.php. Mesma lógica
// de ref_posse já usada em admin/veiculos.php pro lado de compra: "valor
// baseado no contrato assinado" — prefere a data de assinatura do
// contrato de VENDA (contratos.tipo='venda', amarrado por venda_id — um
// mesmo veículo pode ter mais de 1 linha em `vendas` ao longo do tempo),
// cai pra data_venda só quando não existe contrato assinado ainda.
$joinContratoVendaAssinado = "
    LEFT JOIN (
        SELECT venda_id, MAX(assinado_em) AS assinado_em
        FROM contratos WHERE tipo = 'venda' AND venda_id IS NOT NULL AND assinado_em IS NOT NULL
        GROUP BY venda_id
    ) ctrv ON ctrv.venda_id = v.id
";
$refVendaExpr = "COALESCE(ctrv.assinado_em, v.data_venda)";
$fDe = (string)($_GET['de'] ?? '');
$fAte = (string)($_GET['ate'] ?? '');
$filtroDataVendaAtivo = (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $fDe) && (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $fAte);

// 19/09/2026, "aproveita adciona dasbord também em vendas igual de compras
// etapas igual de compras" — mesmo padrão de admin/index.php: nav sempre
// limitada a ETAPAS_VENDA_ATIVAS não deixava achar/buscar negociação já
// 'vendido' nem 'cancelada'/'sem_perfil' (nenhum lugar listava). `?etapa=`
// reaproveita o mesmo valor real da etapa terminal ('vendido', igual
// compra usa 'fechado') como aba especial, e 'encerradas' cobre
// cancelada+sem_perfil juntos — cada uma monta seu próprio escopo de
// etapa pro WHERE em vez de sempre ETAPAS_VENDA_ATIVAS.
//
// 22/10/2026, "em vendas não tem dasbord igual de consultores de compras"
// — os cards de estatística do vendedor (mais abaixo) sempre foram só
// <div>, nunca clicáveis; admin/index.php já tinha ?filtro=hoje/ontem/
// atrasadas/semana pro consultor desde 08/09/2026-21/09/2026. `?filtro=`
// aqui é o mesmo mecanismo, trimmado pros 4 casos que os cards de vendas
// de fato mostram — sempre vence sobre `?etapa=` (mesma disciplina de
// compra, "evita o usuário cair numa combinação impossível").
// 26/10/2026, "filtro no dashboard dos vendedores — por data, tipo de
// lead quente etc" — mesmo filtro de gestão de admin/index.php.
[$gestaoSql, $gestaoParams, $gestaoQsPartes, $tempFiltro, $periodoEntrada, $entradaDe, $entradaAte, $ordemLista] = filtroGestaoLeads($_GET, 'v.temperatura_lead', 'v.created_at');
$qsGestao = $gestaoQsPartes ? '&' . implode('&', $gestaoQsPartes) : '';
$filtroEspecialVendas = (string)($_GET['filtro'] ?? '');
$extraWhereVendas = '';
switch ($filtroEspecialVendas) {
    case 'hoje':
        $etapasEscopo = ETAPAS_VENDA_ATIVAS;
        $extraWhereVendas = " AND date(v.created_at) = date('now','localtime')";
        break;
    case 'ontem':
        $etapasEscopo = ETAPAS_VENDA_ATIVAS;
        $extraWhereVendas = " AND date(v.created_at) = date('now','localtime','-1 day')";
        break;
    case 'semana':
        $etapasEscopo = ETAPAS_VENDA_ATIVAS;
        $extraWhereVendas = " AND v.created_at >= datetime('now','localtime','-7 days')";
        break;
    case 'agendados':
        // 24/10/2026, "colocar card de todos os agendamentos" — confirmou
        // vir até a loja e ainda não compareceu.
        $etapasEscopo = ETAPAS_VENDA_ATIVAS;
        $extraWhereVendas = " AND v.aceita_visitar_fastcar = 1 AND v.visita_compareceu_em IS NULL";
        break;
    case 'atrasadas':
        $etapasEscopo = ETAPAS_VENDA_ATIVAS;
        $extraWhereVendas = " AND v.proxima_acao_em IS NOT NULL AND v.proxima_acao_em < datetime('now','localtime')";
        break;
    default:
        $etapasEscopo = match ($etapaFiltro) {
            'vendido'    => ['vendido'],
            'encerradas' => ['cancelada', 'sem_perfil'],
            default      => ETAPAS_VENDA_ATIVAS,
        };
}
$placeholders = implode(',', array_fill(0, count($etapasEscopo), '?'));

// Vendas nunca teve um "fechado_por" próprio (diferente de compra) — só
// responsavel_id existe, então as 3 abas (ativas/vendido/encerradas) usam
// o mesmo campo de dono, sem distinção.
$where = "WHERE v.etapa IN ({$placeholders}){$extraWhereVendas}";
$params = $etapasEscopo;
if ($souDono) {
    $where .= " AND v.responsavel_id = ?";
    $params[] = $meuId;
}
if ($filtroEspecialVendas === '' && in_array($etapaFiltro, ETAPAS_VENDA_ATIVAS, true)) {
    $where .= " AND v.etapa = ?";
    $params[] = $etapaFiltro;
}
if ($busca !== '') {
    $where .= " AND (v.comprador_nome LIKE ? OR v.comprador_telefone LIKE ? OR o.veiculo_marca LIKE ? OR o.veiculo_modelo LIKE ? OR v.veiculo_interesse_texto LIKE ? OR o.veiculo_placa LIKE ?)";
    $like = '%' . $busca . '%';
    array_push($params, $like, $like, $like, $like, $like, $like);
}
$where .= $gestaoSql;
array_push($params, ...$gestaoParams);
// 24/10/2026, "separar leads de carros vendidos" — aba ✅ Vendidas virou
// visão própria (carro, comprador, valor, data), com filtro por mês.
$abaVendidas = $filtroEspecialVendas === '' && $etapaFiltro === 'vendido';
$mesVendidas = ($abaVendidas && preg_match('/^\d{4}-\d{2}$/', (string)($_GET['mes'] ?? ''))) ? (string)$_GET['mes'] : '';
if ($mesVendidas !== '') {
    $where .= " AND strftime('%Y-%m', COALESCE(v.data_venda, v.updated_at)) = ?";
    $params[] = $mesVendidas;
}
if ($filtroDataVendaAtivo) {
    $where .= " AND date({$refVendaExpr}) BETWEEN ? AND ?";
    $params[] = $fDe;
    $params[] = $fAte;
}

// LEFT JOIN — desde 17/09/2026 uma negociação pode não ter veículo
// vinculado ainda (lead recém-entrado pelo WhatsApp, ver
// includes/vendas.php::criarOuAbrirVendaLead()), diferente do JOIN
// original (que assumia oportunidade_id sempre preenchido).
$stmtTotal = $db->prepare("SELECT COUNT(*) FROM vendas v LEFT JOIN oportunidades o ON o.id = v.oportunidade_id {$joinContratoVendaAssinado} {$where}");
$stmtTotal->execute($params);
$totalFiltrado = (int)$stmtTotal->fetchColumn();

// 24/10/2026, "ta misturando" — nos cards de chegada (hoje/ontem/7 dias) a
// lista é sobre QUANDO o lead entrou, então ordena só por data de entrada,
// mais recente primeiro; a ordenação por temperatura/próxima ação continua
// valendo pras abas normais.
$ordemVendas = filtroGestaoOrdemSql($ordemLista, 'v') ?? match (true) {
    $abaVendidas => "COALESCE(v.data_venda, v.updated_at) DESC, v.id DESC",
    $filtroEspecialVendas === 'agendados' => "(v.visita_data_resolvida IS NULL), v.visita_data_resolvida ASC, v.visita_confirmada_em ASC",
    in_array($filtroEspecialVendas, ['hoje', 'ontem', 'semana'], true) => "v.created_at DESC, v.id DESC",
    default => "CASE v.temperatura_lead WHEN 'quente' THEN 0 WHEN 'morno' THEN 1 WHEN 'frio' THEN 2 ELSE 3 END,
             (v.proxima_acao_em IS NULL), v.proxima_acao_em ASC, v.created_at DESC",
};
$sql = "
    SELECT v.*, o.veiculo_marca, o.veiculo_modelo, o.veiculo_ano, o.veiculo_placa, o.valor_fipe_referencia,
           u.nome AS responsavel_nome
    FROM vendas v
    LEFT JOIN oportunidades o ON o.id = v.oportunidade_id
    LEFT JOIN usuarios u ON u.id = v.responsavel_id
    {$joinContratoVendaAssinado}
    {$where}
    ORDER BY {$ordemVendas}
    LIMIT " . ITENS_POR_PAGINA_PADRAO . " OFFSET " . paginacaoOffset();
$stmt = $db->prepare($sql);
$stmt->execute($params);
$vendas = $stmt->fetchAll();
$resumoVendidas = null;
if ($abaVendidas) {
    $stmtRes = $db->prepare("SELECT COUNT(*) AS qtd, COALESCE(SUM(v.preco_venda),0) AS total, COALESCE(SUM(v.valor_pago_contratacao),0) AS entradas FROM vendas v LEFT JOIN oportunidades o ON o.id = v.oportunidade_id {$joinContratoVendaAssinado} {$where}");
    $stmtRes->execute($params);
    $resumoVendidas = $stmtRes->fetch();
    // Situação das parcelas de cada venda da página (1 query só).
    $parcVendidas = [];
    $comissaoVendidas = [];
    if ($vendas) {
        $idsV = array_map(fn($x) => (int)$x['id'], $vendas);
        $phV = implode(',', array_fill(0, count($idsV), '?'));
        $stmtP = $db->prepare("SELECT venda_id,
                SUM(CASE WHEN parcela_numero > 0 THEN 1 ELSE 0 END) AS qtd,
                SUM(CASE WHEN parcela_numero > 0 AND status = 'pago' THEN 1 ELSE 0 END) AS pagas,
                SUM(CASE WHEN parcela_numero > 0 AND status = 'atrasado' THEN 1 ELSE 0 END) AS atrasadas,
                MAX(CASE WHEN parcela_numero = 0 THEN status END) AS entrada_status
            FROM fin_lancamentos WHERE venda_id IN ({$phV}) AND status != 'cancelado' AND parcela_numero IS NOT NULL
            GROUP BY venda_id");
        $stmtP->execute($idsV);
        foreach ($stmtP->fetchAll() as $r) $parcVendidas[(int)$r['venda_id']] = $r;
        // Comissão do vendedor lançada no financeiro (5% da entrada, automática).
        $stmtC = $db->prepare("SELECT venda_id, SUM(valor) AS valor, MAX(status) AS status, COUNT(*) AS qtd FROM fin_lancamentos
                               WHERE venda_id IN ({$phV}) AND origem = 'comissao_venda' AND status != 'cancelado' GROUP BY venda_id");
        $stmtC->execute($idsV);
        foreach ($stmtC->fetchAll() as $r) $comissaoVendidas[(int)$r['venda_id']] = $r;
    }
}

// Contadores da nav das etapas ATIVAS — sempre contra ETAPAS_VENDA_ATIVAS
// (nunca $placeholders/$etapasEscopo, que podem estar reduzidos a só
// 'vendido'/'encerradas' quando uma dessas abas está selecionada — bug
// real já corrigido uma vez no mesmo padrão em admin/index.php: usar a
// mesma variável pro `IN(...)` da nav quebraria o número de binds).
$placeholdersAtivas = implode(',', array_fill(0, count(ETAPAS_VENDA_ATIVAS), '?'));
$sqlContagem = "SELECT v.etapa, COUNT(*) AS total FROM vendas v LEFT JOIN oportunidades o ON o.id = v.oportunidade_id
                WHERE v.etapa IN ({$placeholdersAtivas})";
$paramsContagem = ETAPAS_VENDA_ATIVAS;
if ($souDono) {
    $sqlContagem .= " AND v.responsavel_id = ?";
    $paramsContagem[] = $meuId;
}
if ($busca !== '') {
    $sqlContagem .= " AND (v.comprador_nome LIKE ? OR v.comprador_telefone LIKE ? OR o.veiculo_marca LIKE ? OR o.veiculo_modelo LIKE ? OR v.veiculo_interesse_texto LIKE ?)";
    array_push($paramsContagem, $like, $like, $like, $like, $like);
}
$sqlContagem .= $gestaoSql;
array_push($paramsContagem, ...$gestaoParams);
$sqlContagem .= " GROUP BY v.etapa";
$stmtContagem = $db->prepare($sqlContagem);
$stmtContagem->execute($paramsContagem);
$contagemPorEtapa = array_column($stmtContagem->fetchAll(), 'total', 'etapa');
$totalAtivas = array_sum($contagemPorEtapa);

// Contador do badge "✅ Vendidas" — mesmo filtro de dono/busca, independente
// do filtro atual (mesma disciplina do "✅ Fechadas" de admin/index.php).
$sqlVendidas = "SELECT COUNT(*) FROM vendas v LEFT JOIN oportunidades o ON o.id = v.oportunidade_id WHERE v.etapa = 'vendido'";
$paramsVendidas = [];
if ($souDono) { $sqlVendidas .= " AND v.responsavel_id = ?"; $paramsVendidas[] = $meuId; }
if ($busca !== '') {
    $sqlVendidas .= " AND (v.comprador_nome LIKE ? OR v.comprador_telefone LIKE ? OR o.veiculo_marca LIKE ? OR o.veiculo_modelo LIKE ? OR v.veiculo_interesse_texto LIKE ?)";
    array_push($paramsVendidas, $like, $like, $like, $like, $like);
}
$sqlVendidas .= $gestaoSql;
array_push($paramsVendidas, ...$gestaoParams);
$stmtVendidas = $db->prepare($sqlVendidas);
$stmtVendidas->execute($paramsVendidas);
$totalVendidas = (int)$stmtVendidas->fetchColumn();

// Contador do badge "❌ Encerradas" (cancelada + sem_perfil) — mesmo padrão.
$sqlEncerradasVenda = "SELECT COUNT(*) FROM vendas v LEFT JOIN oportunidades o ON o.id = v.oportunidade_id WHERE v.etapa IN ('cancelada', 'sem_perfil')";
$paramsEncerradasVenda = [];
if ($souDono) { $sqlEncerradasVenda .= " AND v.responsavel_id = ?"; $paramsEncerradasVenda[] = $meuId; }
if ($busca !== '') {
    $sqlEncerradasVenda .= " AND (v.comprador_nome LIKE ? OR v.comprador_telefone LIKE ? OR o.veiculo_marca LIKE ? OR o.veiculo_modelo LIKE ? OR v.veiculo_interesse_texto LIKE ?)";
    array_push($paramsEncerradasVenda, $like, $like, $like, $like, $like);
}
$sqlEncerradasVenda .= $gestaoSql;
array_push($paramsEncerradasVenda, ...$gestaoParams);
$stmtEncerradasVenda = $db->prepare($sqlEncerradasVenda);
$stmtEncerradasVenda->execute($paramsEncerradasVenda);
$totalEncerradasVenda = (int)$stmtEncerradasVenda->fetchColumn();

$stats = match (true) {
    $souDono => dashboardVendedor($meuId),
    default  => dashboardVendasGeral(),
};

$phAgend = implode(',', array_fill(0, count(ETAPAS_VENDA_ATIVAS), '?'));
$sqlAgend = "SELECT COUNT(*) FROM vendas WHERE etapa IN ({$phAgend}) AND aceita_visitar_fastcar = 1 AND visita_compareceu_em IS NULL";
$paramsAgend = ETAPAS_VENDA_ATIVAS;
if ($souDono) { $sqlAgend .= " AND responsavel_id = ?"; $paramsAgend[] = $meuId; }
$stmtAgend = $db->prepare($sqlAgend);
$stmtAgend->execute($paramsAgend);
$totalAgendados = (int)$stmtAgend->fetchColumn();

function moedaVenda(float $v): string { return 'R$ ' . number_format($v, 2, ',', '.'); }
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Vendas — Fastcar CRM</title>
<link rel="stylesheet" href="/admin/assets/style.css?v=<?= @filemtime(__DIR__ . '/assets/style.css') ?: 1 ?>">
<link rel="stylesheet" href="/admin/assets/mobile.css?v=<?= @filemtime(__DIR__ . '/assets/mobile.css') ?: 1 ?>">
<script src="/admin/assets/mobile.js?v=<?= @filemtime(__DIR__ . '/assets/mobile.js') ?: 1 ?>" defer></script>
<?php include __DIR__ . '/_pwa_head.php'; ?>
</head>
<body>
<?php include __DIR__ . '/_impersonando_banner.php'; ?>
<header class="topbar">
    <a href="/admin/index.php" style="color:#fff">← Voltar</a>
    <a class="topbar-brand" href="<?= e(paginaInicialPorPerfil($_SESSION['admin_perfil'] ?? '')) ?>"><img class="topbar-logo" src="/admin/assets/img/icon-192.png" alt="Fastcar" onerror="this.style.display='none'"><span class="topbar-wordmark">Fast<b>Car</b></span></a>
    <span>Olá, <?= e($_SESSION['admin_nome']) ?></span>
    <a href="/admin/vendas_inbox.php">💬 WhatsApp Vendas</a>
    <a href="/admin/promissorias.php">💳 Promissórias</a>
    <a href="/admin/estoque.php">🚘 Estoque</a>
    <?php if (perfilVeTudo()): ?><a href="/admin/veiculos.php">🚗 Veículos</a><?php endif; ?>
    <a href="/admin/avaliacoes.php">🔍 Vistorias</a>
    <a href="/admin/meu_perfil.php">🙋 Meu perfil</a>
    <a href="/admin/logout.php">Sair</a>
</header>

<nav class="etapas-nav">
    <?php $qsNavV = ltrim(($busca !== '' ? '&q=' . urlencode($busca) : '') . $qsGestao, '&'); ?>
    <a href="/admin/vendas.php<?= $qsNavV !== '' ? '?' . $qsNavV : '' ?>" class="<?= $etapaFiltro === '' && $filtroEspecialVendas === '' ? 'ativo' : '' ?>"><?= $souDono ? 'Minhas' : 'Todas' ?> (<?= (int)$totalAtivas ?>)</a>
    <?php foreach (ETAPAS_VENDA_ATIVAS as $et): ?>
        <a href="/admin/vendas.php?etapa=<?= urlencode($et) ?><?= $busca !== '' ? '&q=' . urlencode($busca) : '' ?><?= e($qsGestao) ?>" class="<?= $etapaFiltro === $et && $filtroEspecialVendas === '' ? 'ativo' : '' ?>">
            <?= e(etapaVendaLabel($et)) ?> (<?= (int)($contagemPorEtapa[$et] ?? 0) ?>)
        </a>
    <?php endforeach; ?>
    <a href="/admin/vendas.php?etapa=vendido<?= $busca !== '' ? '&q=' . urlencode($busca) : '' ?><?= e($qsGestao) ?>" class="<?= $etapaFiltro === 'vendido' && $filtroEspecialVendas === '' ? 'ativo' : '' ?>">✅ Vendidas (<?= $totalVendidas ?>)</a>
    <a href="/admin/vendas.php?etapa=encerradas<?= $busca !== '' ? '&q=' . urlencode($busca) : '' ?><?= e($qsGestao) ?>" class="<?= $etapaFiltro === 'encerradas' && $filtroEspecialVendas === '' ? 'ativo' : '' ?>">❌ Encerradas (<?= $totalEncerradasVenda ?>)</a>
</nav>

<main>
<?php if ($erroPromissoria): ?><div class="alerta-erro"><?= e($erroPromissoria) ?></div><?php endif; ?>
<?php if (isset($_GET['rap_ok'])): ?>
    <div class="alerta-sucesso">✅ <?= e((string)$_GET['rap_ok']) ?></div>
<?php elseif (isset($_GET['rap_erro'])): ?>
    <div class="alerta-erro"><?= e((string)$_GET['rap_erro']) ?></div>
<?php endif; ?>
<?php if (isset($_GET['bulk_reatrib'])): ?>
    <div class="alerta-sucesso">✅ <?= (int)$_GET['bulk_reatrib'] ?> negociação(ões) atribuída(s) a <?= e((string)($_GET['bulk_destino'] ?? '')) ?><?= (int)($_GET['bulk_ignorados'] ?? 0) > 0 ? ' — ' . (int)$_GET['bulk_ignorados'] . ' ignorada(s) (já encerrada ou já era dele)' : '' ?>.</div>
<?php elseif (isset($_GET['bulk_sucesso'])): ?>
    <div class="alerta-sucesso">✅ <?= (int)$_GET['bulk_sucesso'] ?> negociação(ões) cancelada(s)<?= isset($_GET['bulk_ignorados']) && (int)$_GET['bulk_ignorados'] > 0 ? ' — ' . (int)$_GET['bulk_ignorados'] . ' ignorada(s) (fora da carteira ou já encerrada)' : '' ?>.</div>
<?php elseif (isset($_GET['bulk_erro'])): ?>
    <div class="alerta-erro">⚠️ <?= e((string)$_GET['bulk_erro']) ?></div>
<?php endif; ?>
<?php if ($filtroDataVendaAtivo): ?>
<div class="alerta-info">
    📅 Mostrando vendas entre <strong><?= date('d/m/Y', strtotime($fDe)) ?></strong> e
    <strong><?= date('d/m/Y', strtotime($fAte)) ?></strong> (pela data de assinatura do contrato, quando
    assinado; senão a data de venda) — <a href="/admin/vendas.php?etapa=<?= e($etapaFiltro) ?>">ver todas</a>
</div>
<?php endif; ?>

<div style="display:flex;justify-content:flex-end;margin-bottom:1rem">
    <button type="button" class="btn-primary" style="width:auto" onclick="document.getElementById('modal-promissoria').showModal()">💳 Vender na Promissória</button>
</div>

<?php
    $postVp = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : ($venderOpEstoque > 0 ? ['oportunidade_id' => $venderOpEstoque] : []);
    $vpSemFrota = !$frotaDisponivelPromissoria;
    $vpCadastrarNovo = !empty($postVp['cadastrar_veiculo_novo']) || $vpSemFrota;

    $vpPartes = [];
    if (!empty($postVp['parte_valor'])) {
        foreach ((array)$postVp['parte_valor'] as $i => $val) {
            if (trim((string)$val) === '') continue;
            $vpPartes[] = ['valor' => $val, 'data_prevista' => $postVp['parte_data'][$i] ?? ''];
        }
    }
    if (!$vpPartes) $vpPartes = [['valor' => '', 'data_prevista' => '']];
?>
<dialog id="modal-promissoria" class="modal-lancamento">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.5rem">
        <h2 style="margin:0">💳 Vender na Promissória</h2>
        <button type="button" onclick="document.getElementById('modal-promissoria').close()" style="background:none;border:none;font-size:1.6rem;font-weight:700;cursor:pointer;line-height:1;padding:0 .25rem;color:var(--texto-suave)" aria-label="Fechar">&times;</button>
    </div>
    <p><small>Registra a negociação inteira numa passada só — comprador, condições, entrada em partes (PIX) e bem de
       troca, tudo junto — igual o sistema antigo fazia. Depois de registrar, o link do wizard de documentos já vem
       pronto pra copiar (enviar continua sendo um passo separado). Gerar o plano de parcelamento do saldo financiado
       (Asaas ou local) continua no card próprio da tela da negociação, depois de registrar aqui.</small></p>

    <form method="post" id="form-vender-promissoria">
        <?= csrfField() ?>
        <input type="hidden" name="acao" value="vender_promissoria">

        <div id="vp-step-1">

        <h3 style="margin-top:1.2rem">🚗 Veículo</h3>
        <label>
            <input type="checkbox" name="cadastrar_veiculo_novo" id="vp-check-novo" value="1"
                   <?= $vpCadastrarNovo ? 'checked' : '' ?> <?= $vpSemFrota ? 'disabled' : '' ?>
                   onchange="vpAlternarVeiculoNovo(this.checked)">
            Veículo não está na lista? Cadastrar um novo agora (lendo o CRLV)
        </label>
        <?php if ($vpSemFrota): ?>
            <p><small>⚠️ Nenhum veículo disponível na frota pra vender agora — cadastre um novo abaixo pra continuar.</small></p>
        <?php endif; ?>

        <div id="vp-bloco-select" style="<?= $vpCadastrarNovo ? 'display:none' : '' ?>;margin-top:8px">
            <label>Veículo da frota</label>
            <select name="oportunidade_id" id="vp-select-veiculo" <?= $vpCadastrarNovo ? '' : 'required' ?>>
                <option value="">— Escolha —</option>
                <?php foreach ($frotaDisponivelPromissoria as $fv): ?>
                    <option value="<?= (int)$fv['oportunidade_id'] ?>" <?= (int)($postVp['oportunidade_id'] ?? 0) === (int)$fv['oportunidade_id'] ? 'selected' : '' ?>>
                        #<?= (int)$fv['oportunidade_id'] ?> — <?= e(trim($fv['veiculo_marca'] . ' ' . $fv['veiculo_modelo'] . ' ' . $fv['veiculo_ano'])) ?>
                        <?= $fv['valor_referencia'] ? ' (ref. R$ ' . number_format((float)$fv['valor_referencia'], 2, ',', '.') . ')' : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div id="vp-bloco-novo" style="<?= $vpCadastrarNovo ? '' : 'display:none' ?>;margin-top:8px">
            <div class="form-group" style="margin-bottom:12px">
                <label>📄 Subir pelo CRLV (opcional) — a IA lê o documento e preenche os campos abaixo</label>
                <input type="file" id="vp-crlv-arquivo" accept="image/jpeg,image/png,image/webp,application/pdf">
                <button type="button" onclick="vpLerCrlv()" style="margin-top:.4rem" id="vp-crlv-btn">📄 Ler CRLV com IA</button>
                <span id="vp-crlv-status" style="font-size:.8rem;color:var(--texto-suave);margin-left:.5rem"></span>
            </div>
            <div class="grid-2">
                <div>
                    <label>Nome do vendedor/origem <?= $vpCadastrarNovo ? '*' : '' ?></label>
                    <input type="text" name="vendedor_nome" value="<?= e($postVp['vendedor_nome'] ?? '') ?>" <?= $vpCadastrarNovo ? 'required' : '' ?>>
                    <label>Telefone do vendedor/origem <?= $vpCadastrarNovo ? '*' : '' ?></label>
                    <input type="text" name="vendedor_telefone" value="<?= e($postVp['vendedor_telefone'] ?? '') ?>" <?= $vpCadastrarNovo ? 'required' : '' ?> placeholder="Ex: 31999998888">
                    <label>Valor pago pelo veículo (R$)</label>
                    <input type="text" name="veiculo_valor_final" value="<?= e($postVp['veiculo_valor_final'] ?? '') ?>" placeholder="0,00">
                </div>
                <div>
                    <label>Marca</label>
                    <input type="text" name="veiculo_marca" id="vp-marca" value="<?= e($postVp['veiculo_marca'] ?? '') ?>">
                    <label>Modelo</label>
                    <input type="text" name="veiculo_modelo" id="vp-modelo" value="<?= e($postVp['veiculo_modelo'] ?? '') ?>">
                    <label>Ano</label>
                    <input type="text" name="veiculo_ano" id="vp-ano" value="<?= e($postVp['veiculo_ano'] ?? '') ?>" style="max-width:120px">
                    <label>Placa</label>
                    <input type="text" name="veiculo_placa" id="vp-placa" value="<?= e($postVp['veiculo_placa'] ?? '') ?>" style="max-width:160px">
                    <label>Chassi</label>
                    <input type="text" name="veiculo_chassi" id="vp-chassi" value="<?= e($postVp['veiculo_chassi'] ?? '') ?>">
                    <label>RENAVAM</label>
                    <input type="text" name="veiculo_renavam" id="vp-renavam" value="<?= e($postVp['veiculo_renavam'] ?? '') ?>">
                </div>
            </div>
        </div>

        <h3 style="margin-top:1.4rem">🙋 Dados básicos do comprador</h3>
        <div class="grid-2">
            <div>
                <label>Nome do comprador *</label>
                <input type="text" name="comprador_nome" value="<?= e($postVp['comprador_nome'] ?? '') ?>" required>
                <label>Telefone do comprador *</label>
                <input type="text" name="comprador_telefone" value="<?= e($postVp['comprador_telefone'] ?? '') ?>" required placeholder="Ex: 31999998888">
            </div>
            <div>
                <label>CPF</label>
                <input type="text" name="comprador_cpf" value="<?= e($postVp['comprador_cpf'] ?? '') ?>" placeholder="000.000.000-00">
                <label>RG</label>
                <input type="text" name="comprador_rg" value="<?= e($postVp['comprador_rg'] ?? '') ?>">
                <label>E-mail</label>
                <input type="email" name="comprador_email" value="<?= e($postVp['comprador_email'] ?? '') ?>">
            </div>
        </div>

        <h3 style="margin-top:1.4rem">📋 Condições da venda</h3>
        <div class="grid-2">
            <div>
                <label>Preço de venda (R$)</label>
                <input type="number" step="0.01" inputmode="decimal" name="preco_venda" value="<?= e((string)($postVp['preco_venda'] ?? '')) ?>">
                <label>Forma de pagamento</label>
                <input type="text" name="forma_pagamento" value="<?= e($postVp['forma_pagamento'] ?? '') ?>" placeholder="À vista, financiado, entrada + parcelas, promissória...">
            </div>
            <div>
                <label>Saldo de preço devido depois da entrada (R$)</label>
                <input type="number" step="0.01" inputmode="decimal" name="saldo_preco_devido" value="<?= e((string)($postVp['saldo_preco_devido'] ?? '')) ?>">
            </div>
        </div>

        <h3 style="margin-top:1.4rem">💰 Entrada — pago via PIX (em partes)</h3>
        <p><small>A entrada pode ser paga em mais de uma parte, cada uma com valor e data próprios — o total das
           partes abaixo é sempre o "valor pago pelo comprador na contratação".</small></p>
        <div id="vp-partes-linhas">
            <?php foreach ($vpPartes as $i => $parte): ?>
                <div class="grid-2 vp-parte-linha" style="align-items:end">
                    <div>
                        <label>Valor da <?= $i + 1 ?>ª parte no PIX (R$)</label>
                        <input type="number" step="0.01" inputmode="decimal" name="parte_valor[]" value="<?= e((string)($parte['valor'] ?? '')) ?>">
                    </div>
                    <div style="display:flex;gap:8px;align-items:end">
                        <div style="flex:1">
                            <label>Data da <?= $i + 1 ?>ª parte no PIX</label>
                            <input type="date" name="parte_data[]" value="<?= e($parte['data_prevista'] ?? '') ?>">
                        </div>
                        <button type="button" class="btn-texto perigo" onclick="this.closest('.vp-parte-linha').remove()" style="margin-bottom:2px">🗑️ remover</button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <button type="button" class="secundario" onclick="vpAdicionarParteEntrada()">➕ Adicionar outra parte no PIX</button>

        <h3 style="margin-top:1.4rem">🔁 Bem recebido como parte da entrada</h3>
        <label>
            <input type="checkbox" name="bem_troca_recebido" value="1" id="vp-bem-troca-check"
                   <?= !empty($postVp['bem_troca_recebido']) ? 'checked' : '' ?>
                   onchange="document.getElementById('vp-bem-troca-campos').style.display = this.checked ? '' : 'none'">
            Recebemos um bem como parte da entrada
        </label>
        <div id="vp-bem-troca-campos" style="<?= !empty($postVp['bem_troca_recebido']) ? '' : 'display:none' ?>;margin-top:10px">
            <label>Tipo</label>
            <select name="bem_troca_tipo" id="vp-bem-troca-tipo" onchange="document.getElementById('vp-bem-troca-veiculo').style.display = this.value === 'veiculo' ? '' : 'none'">
                <option value="outro" <?= ($postVp['bem_troca_tipo'] ?? '') === 'outro' ? 'selected' : '' ?>>Outro bem</option>
                <option value="veiculo" <?= ($postVp['bem_troca_tipo'] ?? '') === 'veiculo' ? 'selected' : '' ?>>Veículo</option>
            </select>
            <label>Descrição do bem</label>
            <input type="text" name="bem_troca_nome" value="<?= e($postVp['bem_troca_nome'] ?? '') ?>" placeholder="Ex: Moto Honda CG 160, ou nome do bem">
            <label>Valor atribuído ao bem (R$)</label>
            <input type="number" step="0.01" inputmode="decimal" name="bem_troca_valor" value="<?= e((string)($postVp['bem_troca_valor'] ?? '')) ?>">
            <div id="vp-bem-troca-veiculo" class="grid-2" style="<?= ($postVp['bem_troca_tipo'] ?? '') === 'veiculo' ? '' : 'display:none' ?>">
                <div>
                    <label>Modelo/ano</label>
                    <input type="text" name="bem_troca_modelo_ano" value="<?= e($postVp['bem_troca_modelo_ano'] ?? '') ?>">
                    <label>Ano de fabricação</label>
                    <input type="text" name="bem_troca_ano_fabricacao" value="<?= e($postVp['bem_troca_ano_fabricacao'] ?? '') ?>">
                    <label>Cor</label>
                    <input type="text" name="bem_troca_cor" value="<?= e($postVp['bem_troca_cor'] ?? '') ?>">
                </div>
                <div>
                    <label>Placa</label>
                    <input type="text" name="bem_troca_placa" value="<?= e($postVp['bem_troca_placa'] ?? '') ?>">
                    <label>Chassi</label>
                    <input type="text" name="bem_troca_chassi" value="<?= e($postVp['bem_troca_chassi'] ?? '') ?>">
                    <label>Renavam</label>
                    <input type="text" name="bem_troca_renavam" value="<?= e($postVp['bem_troca_renavam'] ?? '') ?>">
                </div>
            </div>
        </div>

        <h3 style="margin-top:1.4rem">📆 Parcelamento do saldo (opcional)</h3>
        <p><small>Só se você já souber os números agora — senão deixe em branco e gere o plano depois, no card de
           Financeiro da negociação (lá dá pra escolher entre cobrança real via Asaas ou registro local).</small></p>
        <div class="grid-2">
            <div>
                <label>Valor de cada parcela (R$)</label>
                <input type="number" step="0.01" inputmode="decimal" name="parcelamento_valor_parcela" value="<?= e((string)($postVp['parcelamento_valor_parcela'] ?? '')) ?>">
            </div>
            <div>
                <label>Quantidade de parcelas</label>
                <input type="number" name="parcelamento_qtd_parcelas" value="<?= e((string)($postVp['parcelamento_qtd_parcelas'] ?? '')) ?>">
                <label>Vencimento da 1ª parcela</label>
                <input type="date" name="parcelamento_primeira_parcela_data" value="<?= e($postVp['parcelamento_primeira_parcela_data'] ?? '') ?>">
            </div>
        </div>
        <label>Periodicidade das parcelas</label>
        <label style="font-weight:normal;display:inline-flex;align-items:center;gap:4px;margin-right:16px">
            <input type="radio" name="parcelamento_periodicidade" value="mensal" <?= ($postVp['parcelamento_periodicidade'] ?? 'mensal') === 'mensal' ? 'checked' : '' ?>> Mensal
        </label>
        <label style="font-weight:normal;display:inline-flex;align-items:center;gap:4px">
            <input type="radio" name="parcelamento_periodicidade" value="semanal" <?= ($postVp['parcelamento_periodicidade'] ?? '') === 'semanal' ? 'checked' : '' ?>> Semanal
        </label>

        <div style="display:flex;gap:10px;margin-top:1.4rem">
            <button type="button" class="btn-primary" onclick="vpRevisar()">👁️ Revisar antes de registrar</button>
            <button type="button" onclick="document.getElementById('modal-promissoria').close()">Cancelar</button>
        </div>

        </div>

        <div id="vp-step-2" style="display:none">
            <h3 style="margin-top:1.2rem">📋 Confira antes de registrar</h3>
            <p><small>Nada foi salvo ainda — só grava no banco depois de clicar em "Confirmar e registrar" abaixo.
               Pra corrigir algo, volte e edite.</small></p>
            <div id="vp-resumo-conteudo" style="background:var(--fundo-suave, #f4f6fb);border-radius:10px;padding:14px 16px;font-size:.92rem"></div>
            <div style="display:flex;gap:10px;margin-top:1.2rem">
                <button type="submit" class="btn-primary">✅ Confirmar e registrar</button>
                <button type="button" onclick="vpVoltarEditar()">← Voltar e editar</button>
                <button type="button" onclick="document.getElementById('modal-promissoria').close()">Cancelar</button>
            </div>
        </div>
    </form>
</dialog>
<script>
var csrfTokenVendaPromissoria = <?= json_encode(generateCSRF()) ?>;

function vpAlternarVeiculoNovo(novo) {
    document.getElementById('vp-bloco-select').style.display = novo ? 'none' : '';
    document.getElementById('vp-bloco-novo').style.display = novo ? '' : 'none';
    document.getElementById('vp-select-veiculo').required = !novo;
    document.querySelector('input[name="vendedor_nome"]').required = novo;
    document.querySelector('input[name="vendedor_telefone"]').required = novo;
}

function vpLerCrlv() {
    var input = document.getElementById('vp-crlv-arquivo');
    if (!input.files.length) { alert('Escolha o arquivo do CRLV primeiro.'); return; }
    var status = document.getElementById('vp-crlv-status');
    var btn = document.getElementById('vp-crlv-btn');
    status.textContent = '🔄 Lendo CRLV...';
    btn.disabled = true;

    var fd = new FormData();
    fd.append('crlv', input.files[0]);
    fd.append('csrf_token', csrfTokenVendaPromissoria);
    fetch('/admin/veiculo_crlv_ajax.php', { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            btn.disabled = false;
            if (!d.ok) { status.textContent = '⚠️ ' + d.erro; return; }
            // fill-if-empty — nunca sobrescreve o que já foi digitado/corrigido na mão.
            var campos = { marca: d.veiculo_marca, modelo: d.veiculo_modelo, ano: d.veiculo_ano, placa: d.veiculo_placa, chassi: d.veiculo_chassi, renavam: d.veiculo_renavam };
            Object.keys(campos).forEach(function (k) {
                var el = document.getElementById('vp-' + k);
                if (el && !el.value && campos[k]) el.value = campos[k];
            });
            status.textContent = '✅ Preenchido! Confira antes de registrar.';
        })
        .catch(function (err) { btn.disabled = false; status.textContent = '⚠️ Erro ao ler o CRLV.'; console.error(err); });
}

function vpFmtMoeda(v) {
    var n = parseFloat(String(v || '').replace(',', '.'));
    if (isNaN(n) || n === 0) return null;
    return 'R$ ' + n.toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d)(?=,))/g, '.');
}
function vpEsc(s) {
    return String(s || '').replace(/[&<>"']/g, function (c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
}
function vpFmtData(v) {
    if (!v) return null;
    var p = v.split('-');
    return p.length === 3 ? (p[2] + '/' + p[1] + '/' + p[0]) : v;
}
function vpVal(name) {
    var el = document.querySelector('#form-vender-promissoria [name="' + name + '"]');
    return el ? el.value.trim() : '';
}

function vpRevisar() {
    var form = document.getElementById('form-vender-promissoria');
    if (!form.reportValidity()) return; // validação nativa (campos *) já avisa o que falta

    var linhas = [];

    // Veículo
    if (document.getElementById('vp-check-novo').checked) {
        var partesVeiculo = [vpVal('veiculo_marca'), vpVal('veiculo_modelo'), vpVal('veiculo_ano')].filter(Boolean).join(' ');
        linhas.push('<p><strong>🚗 Veículo (novo cadastro):</strong> ' + vpEsc(partesVeiculo || '(sem marca/modelo informados)') +
            (vpVal('veiculo_placa') ? ' — placa ' + vpEsc(vpVal('veiculo_placa')) : '') + '</p>');
        linhas.push('<p><strong>Vendedor/origem:</strong> ' + vpEsc(vpVal('vendedor_nome')) + ' — ' + vpEsc(vpVal('vendedor_telefone')) + '</p>');
    } else {
        var sel = document.getElementById('vp-select-veiculo');
        var texto = sel.selectedIndex >= 0 ? sel.options[sel.selectedIndex].text : '';
        linhas.push('<p><strong>🚗 Veículo (da frota):</strong> ' + vpEsc(texto || '(nenhum selecionado)') + '</p>');
    }

    // Comprador
    linhas.push('<p><strong>🙋 Comprador:</strong> ' + vpEsc(vpVal('comprador_nome')) + ' — ' + vpEsc(vpVal('comprador_telefone')) +
        (vpVal('comprador_cpf') ? ' — CPF ' + vpEsc(vpVal('comprador_cpf')) : '') + '</p>');

    // Condições
    var condicoes = [];
    if (vpFmtMoeda(vpVal('preco_venda'))) condicoes.push('Preço: ' + vpFmtMoeda(vpVal('preco_venda')));
    if (vpVal('forma_pagamento')) condicoes.push('Forma: ' + vpEsc(vpVal('forma_pagamento')));
    if (vpFmtMoeda(vpVal('saldo_preco_devido'))) condicoes.push('Saldo devido: ' + vpFmtMoeda(vpVal('saldo_preco_devido')));
    if (condicoes.length) linhas.push('<p><strong>📋 Condições:</strong> ' + condicoes.join(' · ') + '</p>');

    // Entrada em partes
    var totalEntrada = 0, partesTexto = [];
    document.querySelectorAll('.vp-parte-linha').forEach(function (linha) {
        var valorEl = linha.querySelector('[name="parte_valor[]"]');
        var dataEl = linha.querySelector('[name="parte_data[]"]');
        var v = parseFloat(String(valorEl.value || '').replace(',', '.'));
        if (!v) return;
        totalEntrada += v;
        var d = vpFmtData(dataEl.value);
        partesTexto.push(vpFmtMoeda(v) + (d ? ' em ' + d : ''));
    });
    if (partesTexto.length) {
        linhas.push('<p><strong>💰 Entrada via PIX (' + partesTexto.length + ' parte' + (partesTexto.length > 1 ? 's' : '') + '):</strong> ' +
            partesTexto.join(', ') + ' — total ' + vpFmtMoeda(totalEntrada) + '</p>');
    } else {
        linhas.push('<p><strong>💰 Entrada via PIX:</strong> nenhuma informada</p>');
    }

    // Bem de troca
    if (document.getElementById('vp-bem-troca-check').checked) {
        var tipoBem = document.getElementById('vp-bem-troca-tipo').value === 'veiculo' ? 'Veículo' : 'Outro bem';
        var descBem = [tipoBem + ':', vpVal('bem_troca_nome')].filter(Boolean).join(' ');
        var valorBem = vpFmtMoeda(vpVal('bem_troca_valor'));
        linhas.push('<p><strong>🔁 Bem recebido na entrada:</strong> ' + vpEsc(descBem) + (valorBem ? ' — ' + valorBem : '') + '</p>');
    }

    // Parcelamento do saldo
    var qtdParc = vpVal('parcelamento_qtd_parcelas');
    var valorParc = vpFmtMoeda(vpVal('parcelamento_valor_parcela'));
    if (qtdParc && valorParc) {
        var primeiraData = vpFmtData(vpVal('parcelamento_primeira_parcela_data'));
        var periodicidadeEl = document.querySelector('input[name="parcelamento_periodicidade"]:checked');
        var periodicidadeTxt = periodicidadeEl && periodicidadeEl.value === 'semanal' ? ' (semanal)' : '';
        linhas.push('<p><strong>📆 Parcelamento do saldo:</strong> ' + vpEsc(qtdParc) + 'x de ' + valorParc + periodicidadeTxt +
            (primeiraData ? ', 1ª parcela em ' + primeiraData : '') + '</p>');
    }

    document.getElementById('vp-resumo-conteudo').innerHTML = linhas.join('');
    document.getElementById('vp-step-1').style.display = 'none';
    document.getElementById('vp-step-2').style.display = '';
}

function vpVoltarEditar() {
    document.getElementById('vp-step-2').style.display = 'none';
    document.getElementById('vp-step-1').style.display = '';
}

function vpAdicionarParteEntrada() {
    var wrap = document.getElementById('vp-partes-linhas');
    var n = wrap.querySelectorAll('.vp-parte-linha').length + 1;
    var div = document.createElement('div');
    div.className = 'grid-2 vp-parte-linha';
    div.style.alignItems = 'end';
    div.innerHTML = ''
        + '<div>'
        + '<label>Valor da ' + n + 'ª parte no PIX (R$)</label>'
        + '<input type="number" step="0.01" inputmode="decimal" name="parte_valor[]" value="">'
        + '</div>'
        + '<div style="display:flex;gap:8px;align-items:end">'
        + '<div style="flex:1">'
        + '<label>Data da ' + n + 'ª parte no PIX</label>'
        + '<input type="date" name="parte_data[]" value="">'
        + '</div>'
        + '<button type="button" class="btn-texto perigo" onclick="this.closest(\'.vp-parte-linha\').remove()" style="margin-bottom:2px">🗑️ remover</button>'
        + '</div>';
    wrap.appendChild(div);
}

<?php if ($modalPromissoriaAbrir): ?>
document.getElementById('modal-promissoria').showModal();
<?php endif; ?>
</script>

<?php
// 22/10/2026 — mesmo banner "🔎 Mostrando" de admin/index.php, só pro
// filtro especial de vendas; nunca aparece junto da aba normal (?etapa=).
$filtroEspecialVendasLabel = [
    'hoje'      => $souDono ? 'Meus leads de hoje' : 'Leads novos hoje',
    'ontem'     => $souDono ? 'Meus leads de ontem' : 'Leads novos ontem',
    'semana'    => $souDono ? 'Recebidas nos últimos 7 dias' : 'Leads novos (7 dias)',
    'atrasadas' => 'Atrasadas',
    'agendados' => 'Visitas agendadas na loja (ainda não vieram)',
][$filtroEspecialVendas] ?? '';
?>
<?php if ($filtroEspecialVendasLabel !== ''): ?>
<div class="card" style="display:flex;align-items:center;justify-content:space-between;padding:12px 20px;margin-bottom:14px">
    <span>🔎 Mostrando: <strong><?= e($filtroEspecialVendasLabel) ?></strong></span>
    <a href="/admin/vendas.php<?= $busca !== '' ? '?q=' . urlencode($busca) : '' ?>">Limpar filtro</a>
</div>
<?php endif; ?>

<div class="stat-grid">
    <a class="stat-card" href="/admin/vendas.php">
        <div class="valor"><?= (int)$stats['ativas'] ?></div>
        <div class="rotulo"><?= $souDono ? 'Minhas' : '' ?> negociações ativas</div>
    </a>
    <a class="stat-card <?= $stats['atrasadas'] > 0 ? 'alerta' : '' ?>" href="/admin/vendas.php?filtro=atrasadas">
        <div class="valor"><?= (int)$stats['atrasadas'] ?></div>
        <div class="rotulo">Atrasadas</div>
    </a>
    <a class="stat-card sucesso" href="/admin/vendas.php?filtro=agendados">
        <div class="valor"><?= $totalAgendados ?></div>
        <div class="rotulo">📍 Visitas agendadas</div>
    </a>
    <?php if ($souDono): ?>
        <a class="stat-card alerta" href="/admin/vendas.php?filtro=hoje">
            <div class="valor"><?= (int)$stats['novas_hoje'] ?></div>
            <div class="rotulo">Meus leads de hoje</div>
        </a>
        <a class="stat-card azul-forte" href="/admin/vendas.php?filtro=ontem">
            <div class="valor"><?= (int)$stats['novas_ontem'] ?></div>
            <div class="rotulo">Meus leads de ontem</div>
        </a>
        <a class="stat-card neutro" href="/admin/vendas.php?filtro=semana">
            <div class="valor"><?= (int)$stats['recebidas_semana'] ?></div>
            <div class="rotulo">Recebidas nos últimos 7 dias</div>
        </a>
        <div class="stat-card <?= $stats['disponivel'] ? 'sucesso' : 'neutro' ?>">
            <div class="valor"><?= $stats['disponivel'] ? '🟢' : '⚪' ?></div>
            <div class="rotulo"><?= $stats['disponivel'] ? 'Disponível pra fila' : ($stats['plantao'] ? 'Offline (plantão)' : 'Offline') ?></div>
        </div>
    <?php else: ?>
        <a class="stat-card alerta" href="/admin/vendas.php?filtro=hoje">
            <div class="valor"><?= (int)$stats['novas_hoje'] ?></div>
            <div class="rotulo">Leads novos hoje</div>
        </a>
        <a class="stat-card azul-forte" href="/admin/vendas.php?filtro=ontem">
            <div class="valor"><?= (int)$stats['novas_ontem'] ?></div>
            <div class="rotulo">Leads novos ontem</div>
        </a>
        <a class="stat-card neutro" href="/admin/vendas.php?filtro=semana">
            <div class="valor"><?= (int)$stats['novas_semana'] ?></div>
            <div class="rotulo">Leads novos (7 dias)</div>
        </a>
    <?php endif; ?>
    <a class="stat-card sucesso" href="/admin/vendas.php?etapa=vendido&mes=<?= date('Y-m') ?>">
        <div class="valor"><?= (int)$stats['vendidas_mes'] ?></div>
        <div class="rotulo">Vendidas este mês</div>
    </a>
    <a class="stat-card sucesso" href="/admin/vendas.php?etapa=vendido&mes=<?= date('Y-m') ?>">
        <div class="valor"><?= moedaVenda($stats['valor_vendido_mes']) ?></div>
        <div class="rotulo">Valor vendido este mês</div>
    </a>
    <?php if ($souDono && $stats['taxa_conversao'] !== null): ?>
        <div class="stat-card neutro">
            <div class="valor"><?= $stats['taxa_conversao'] ?>%</div>
            <div class="rotulo">Taxa de conversão</div>
        </div>
    <?php elseif (!$souDono): ?>
        <div class="stat-card neutro">
            <div class="valor"><?= $stats['taxa_conversao'] === null ? '—' : $stats['taxa_conversao'] . '%' ?></div>
            <div class="rotulo">Taxa de conversão geral</div>
        </div>
    <?php endif; ?>
</div>

<?php
// 24/10/2026, "coloca cards com os carros disponíveis" — vitrine rápida do
// estoque direto no dashboard de vendas (mesma fonte do 🚘 Estoque e da IA).
$frotaDash = listarFrotaDisponivelParaVenda(1000);
$capaDash = [];
if ($frotaDash) {
    $idsDash = array_column($frotaDash, 'oportunidade_id');
    $phDash = implode(',', array_fill(0, count($idsDash), '?'));
    $stmtCapa = $db->prepare("SELECT oportunidade_id, MIN(id) AS foto_id FROM veiculo_midias_revenda WHERE tipo = 'foto' AND oportunidade_id IN ({$phDash}) GROUP BY oportunidade_id");
    $stmtCapa->execute($idsDash);
    foreach ($stmtCapa->fetchAll() as $r) $capaDash[(int)$r['oportunidade_id']] = '/admin/ver_midia_revenda.php?id=' . (int)$r['foto_id'];
    // Sem foto de revenda ainda? Usa a 1ª foto da vistoria como capa.
    $stmtCapaV = $db->prepare("SELECT a.oportunidade_id, MIN(f.id) AS foto_id FROM veiculo_avaliacao_fotos f JOIN veiculo_avaliacoes a ON a.id = f.avaliacao_id WHERE f.tipo = 'foto' AND a.oportunidade_id IN ({$phDash}) GROUP BY a.oportunidade_id");
    $stmtCapaV->execute($idsDash);
    foreach ($stmtCapaV->fetchAll() as $r) $capaDash[(int)$r['oportunidade_id']] ??= '/admin/ver_avaliacao_foto.php?id=' . (int)$r['foto_id'];
}
$iconeDash = ['carro' => '🚗', 'moto' => '🏍️', 'caminhao' => '🚚', 'outro' => '🚙'];
?>
<div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
        <h3 style="margin:0">🚘 Carros disponíveis (<?= count($frotaDash) ?>)</h3>
        <a class="btn" href="/admin/estoque.php">Ver estoque completo →</a>
    </div>
    <?php if ($frotaDash): ?>
    <input type="search" id="vitrine-busca" placeholder="🔎 Digite a placa (ou modelo) pra achar rápido" autocomplete="off"
           style="margin-top:10px;text-transform:uppercase" oninput="vitrineFiltrar(this.value)">
    <?php endif; ?>
    <?php if (!$frotaDash): ?>
        <p><small>Nenhum veículo disponível no estoque agora.</small></p>
    <?php else: ?>
    <div class="vitrine-dash">
        <?php foreach ($frotaDash as $iDash => $fv): $fid = (int)$fv['oportunidade_id']; $tit = trim(($fv['veiculo_marca'] ?? '') . ' ' . ($fv['veiculo_modelo'] ?? ''));
            $buscaDash = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)($fv['veiculo_placa'] ?? '')) . ' ' . $tit . ' ' . ($fv['veiculo_ano'] ?? '')); ?>
        <a class="vitrine-card<?= $iDash >= 12 ? ' vitrine-extra' : '' ?>" data-busca="<?= e($buscaDash) ?>"<?= $iDash >= 12 ? ' style="display:none"' : '' ?> href="/admin/estoque.php?q=<?= urlencode((string)($fv['veiculo_placa'] ?: $tit)) ?>">
            <div class="vitrine-foto">
                <?php if (isset($capaDash[$fid])): ?>
                    <img src="<?= e($capaDash[$fid]) ?>" alt="" loading="lazy">
                <?php else: ?>
                    <?= $iconeDash[$fv['tipo_veiculo'] ?? ''] ?? '🚗' ?>
                <?php endif; ?>
            </div>
            <div class="vitrine-info">
                <strong><?= e($tit ?: '(sem modelo)') ?></strong>
                <?php if (!empty($fv['veiculo_placa'])): ?><span class="vitrine-placa"><?= e(strtoupper($fv['veiculo_placa'])) ?></span><?php endif; ?>
                <small><?= e((string)($fv['veiculo_ano'] ?? '')) ?></small>
                <?php if ($fv['valor_fipe_referencia']): ?><small>FIPE R$ <?= number_format((float)$fv['valor_fipe_referencia'], 0, ',', '.') ?></small><?php endif; ?>
            </div>
        </a>
        <?php endforeach; ?>
        <?php if (count($frotaDash) > 12): ?>
            <a class="vitrine-card vitrine-mais" id="vitrine-mais" href="/admin/estoque.php">+<?= count($frotaDash) - 12 ?><br><small>ver todos</small></a>
        <?php endif; ?>
        <p id="vitrine-vazio" style="display:none;margin:8px 0"><small>Nenhum carro disponível com essa placa/modelo.</small></p>
    </div>
    <?php endif; ?>
</div>
<script>
function vitrineFiltrar(termo) {
    var t = termo.toUpperCase().replace(/[^A-Z0-9 ]/g, '').trim();
    var semEspaco = t.replace(/ /g, '');
    var achou = 0;
    document.querySelectorAll('.vitrine-dash .vitrine-card[data-busca]').forEach(function (c) {
        var b = c.getAttribute('data-busca');
        var mostra = t === '' ? !c.classList.contains('vitrine-extra') : (b.indexOf(t) !== -1 || b.replace(/ /g, '').indexOf(semEspaco) !== -1);
        c.style.display = mostra ? '' : 'none';
        if (mostra) achou++;
    });
    var mais = document.getElementById('vitrine-mais');
    if (mais) mais.style.display = t === '' ? '' : 'none';
    document.getElementById('vitrine-vazio').style.display = (t !== '' && achou === 0) ? '' : 'none';
}
</script>
<style>
.vitrine-placa { display:inline-block; align-self:flex-start; font-family:monospace; font-weight:700; font-size:14px; letter-spacing:1px; background:#fff; border:2px solid var(--navy); border-top-width:5px; border-top-color:var(--azul); border-radius:4px; padding:1px 6px; }
.vitrine-dash { display:flex; gap:12px; overflow-x:auto; padding:12px 2px 6px; scroll-snap-type:x mandatory; }
.vitrine-card { flex:0 0 170px; scroll-snap-align:start; background:#fff; border:1px solid var(--borda); border-radius:var(--raio); box-shadow:var(--sombra); overflow:hidden; color:inherit; text-decoration:none; display:flex; flex-direction:column; }
.vitrine-card:hover { border-color:var(--azul); }
.vitrine-foto { aspect-ratio:4/3; background:var(--navy); display:flex; align-items:center; justify-content:center; font-size:40px; overflow:hidden; }
.vitrine-foto img { width:100%; height:100%; object-fit:cover; }
.vitrine-info { padding:8px 10px; display:flex; flex-direction:column; gap:2px; font-size:14px; }
.vitrine-mais { align-items:center; justify-content:center; font-size:24px; font-weight:700; color:var(--azul); }
</style>

<?php if (!$souDono): ?>
<div class="card">
    <h3>Funil (negociações ativas)</h3>
    <div class="funil-barra">
        <?php foreach (ETAPAS_VENDA_ATIVAS as $et): ?>
            <?php $qtd = (int)($contagemPorEtapa[$et] ?? 0); $pct = $totalAtivas > 0 ? round($qtd / $totalAtivas * 100) : 0; ?>
            <div class="funil-linha">
                <span class="etapa-nome"><?= e(etapaVendaLabel($et)) ?></span>
                <span class="barra-fundo"><span class="barra-preenchida" style="width:<?= $pct ?>%"></span></span>
                <span class="qtd"><?= $qtd ?></span>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <form method="get" style="display:flex;gap:8px;align-items:center">
        <?php if ($filtroEspecialVendas !== ''): ?><input type="hidden" name="filtro" value="<?= e($filtroEspecialVendas) ?>">
        <?php elseif ($etapaFiltro): ?><input type="hidden" name="etapa" value="<?= e($etapaFiltro) ?>"><?php endif; ?>
        <?= filtroGestaoHiddens($gestaoQsPartes) ?>
        <input type="text" name="q" value="<?= e($busca) ?>" placeholder="Buscar por comprador, telefone ou veículo..." style="flex:1;margin:0">
        <button type="submit" style="margin:0">Buscar</button>
        <?php if ($busca):
            // 22/10/2026 — mesma disciplina de admin/index.php: "Limpar"
            // precisa preservar o ?filtro= especial também, nunca só a
            // ?etapa= normal, senão limpar a busca dentro de "Meus leads
            // de hoje" jogava de volta pra aba padrão sem querer.
            $voltarQsVendas = $filtroEspecialVendas !== '' ? '?filtro=' . urlencode($filtroEspecialVendas)
                             : ($etapaFiltro !== '' ? '?etapa=' . urlencode($etapaFiltro) : '');
            if ($qsGestao !== '') $voltarQsVendas .= ($voltarQsVendas === '' ? '?' : '&') . ltrim($qsGestao, '&');
        ?><a href="/admin/vendas.php<?= e($voltarQsVendas) ?>">Limpar</a><?php endif; ?>
    </form>
    <?php
        $gestaoManterV = [];
        if ($filtroEspecialVendas !== '') $gestaoManterV['filtro'] = $filtroEspecialVendas;
        elseif ($etapaFiltro !== '') $gestaoManterV['etapa'] = $etapaFiltro;
        if ($busca !== '') $gestaoManterV['q'] = $busca;
        if ($mesVendidas !== '') $gestaoManterV['mes'] = $mesVendidas;
        echo filtroGestaoForm('/admin/vendas.php', $gestaoManterV, $tempFiltro, $periodoEntrada, $entradaDe, $entradaAte, $ordemLista);
    ?>
</div>

<?php
// 29/09/2026, mesmo pedido do lado de compra — seleção em massa pra
// cancelar várias negociações de uma vez. Nunca aparece pro supervisor
// (só acompanha).
$podeSelecionarEmMassa = $perfil !== 'supervisor';
if ($podeSelecionarEmMassa):
?>
<form id="form-bulk-cancelar" method="post" onsubmit="return confirmarAcao(this, 'Cancelar as negociações selecionadas? Motivo fica gravado no histórico de cada uma; o veículo volta a ficar disponível pra uma nova venda.');">
    <?= csrfField() ?>
    <input type="hidden" name="acao" value="cancelar_venda_massa">
</form>
<div id="barra-bulk-cancelar" class="card" style="display:none;margin-bottom:14px;background:var(--laranja-bg,#fff4ec);border:1px solid var(--laranja,#ea580c);display:flex;flex-wrap:wrap;align-items:center;gap:10px;padding:12px 16px">
    <strong><span id="bulk-contagem">0</span> selecionada(s)</strong>
    <input type="text" name="motivo" form="form-bulk-cancelar" id="bulk-motivo"
           placeholder="Motivo (obrigatório) — ex: desistiu, achou proposta baixa..."
           style="flex:1;min-width:220px;margin:0">
    <button type="submit" form="form-bulk-cancelar" class="perigo" style="margin:0"
            onclick="if (document.getElementById('bulk-motivo').value.trim() === '') { alert('Escreva o motivo do cancelamento.'); return false; }">🚫 Cancelar selecionadas</button>
    <?php if ($perfil === 'super_admin'): ?>
        <?php $destinosVenda = $db->query("SELECT id, nome, perfil FROM usuarios WHERE bloqueado = 0 AND perfil IN ('vendedor','avaliador','super_admin') ORDER BY nome")->fetchAll(); ?>
        <span style="flex-basis:100%;height:0"></span>
        <select id="bulk-novo-responsavel" style="flex:1;min-width:200px;margin:0">
            <option value="">— atribuir a… —</option>
            <?php foreach ($destinosVenda as $d): ?>
                <option value="<?= (int)$d['id'] ?>"><?= e($d['nome']) ?> (<?= e($d['perfil']) ?>)</option>
            <?php endforeach; ?>
        </select>
        <button type="button" style="margin:0" onclick="bulkReatribuir()">👤 Atribuir responsável</button>
    <?php endif; ?>
    <button type="button" class="btn-texto" onclick="bulkLimparSelecao()">Limpar seleção</button>
</div>
<?php if ($perfil === 'super_admin'): ?>
<form id="form-bulk-reatribuir" method="post" style="display:none">
    <?= csrfField() ?>
    <input type="hidden" name="acao" value="reatribuir_venda_massa">
    <input type="hidden" name="novo_responsavel_id" id="bulk-reatrib-destino">
</form>
<?php endif; ?>
<?php endif; ?>

<?php if ($abaVendidas): ?>
<div class="card">
    <h3 style="margin-top:0">✅ Vendas fechadas<?= $mesVendidas ? ' — ' . e(date('m/Y', strtotime($mesVendidas . '-01'))) : '' ?></h3>
    <div class="stat-grid" style="margin-bottom:12px">
        <div class="stat-card sucesso"><div class="valor"><?= (int)$resumoVendidas['qtd'] ?></div><div class="rotulo">Carros vendidos</div></div>
        <div class="stat-card sucesso"><div class="valor"><?= moedaVenda((float)$resumoVendidas['total']) ?></div><div class="rotulo">Valor total vendido</div></div>
        <div class="stat-card neutro"><div class="valor"><?= moedaVenda((float)$resumoVendidas['entradas']) ?></div><div class="rotulo">Entradas</div></div>
    </div>
    <form method="get" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end">
        <input type="hidden" name="etapa" value="vendido">
<?= filtroGestaoHiddens($gestaoQsPartes) ?>
        <div style="flex:1;min-width:180px"><label>Buscar</label><input type="text" name="q" value="<?= e($busca) ?>" placeholder="Comprador, telefone, modelo ou placa"></div>
        <div><label>Mês da venda</label><input type="month" name="mes" value="<?= e($mesVendidas) ?>"></div>
        <button type="submit" style="width:auto;margin:0">Filtrar</button>
        <?php if ($mesVendidas || $busca): ?><a class="btn" href="/admin/vendas.php?etapa=vendido">Limpar</a><?php endif; ?>
    </form>
    <table class="tabela-oportunidades" style="margin-top:12px">
        <thead>
            <tr><th>Veículo</th><th>Comprador</th><th>FIPE</th><th>Valor</th><th>Entrada</th><th>Parcelas</th><th>Comissão</th><th>Data da venda</th><th>Vendedor</th><th></th></tr>
        </thead>
        <tbody>
        <?php if (!$vendas): ?>
            <tr><td colspan="10">Nenhuma venda fechada<?= ($mesVendidas || $busca) ? ' com esse filtro' : ' ainda' ?>.</td></tr>
        <?php endif; ?>
        <?php foreach ($vendas as $v): $pv = $parcVendidas[(int)$v['id']] ?? null; ?>
            <tr class="linha-clicavel" onclick="if (!event.target.closest('a')) location.href='/admin/venda.php?id=<?= (int)$v['id'] ?>'" style="cursor:pointer">
                <td>
                    <?= e(trim(($v['veiculo_marca'] ?? '') . ' ' . ($v['veiculo_modelo'] ?? '') . ' ' . ($v['veiculo_ano'] ?? ''))) ?: '—' ?>
                    <?php if (!empty($v['veiculo_placa'])): ?><br><small><?= e(strtoupper($v['veiculo_placa'])) ?></small><?php endif; ?>
                </td>
                <td><?= e($v['comprador_nome'] ?: '—') ?><br><small><?= e($v['comprador_telefone'] ?: '') ?></small></td>
                <td><?= $v['valor_fipe_referencia'] ? moedaVenda((float)$v['valor_fipe_referencia']) : '<small>—</small>' ?></td>
                <td>
                    <?= $v['preco_venda'] !== null ? moedaVenda((float)$v['preco_venda']) : '—' ?>
                    <?php if ($v['preco_venda'] && $v['valor_fipe_referencia'] > 0): $pct = round((float)$v['preco_venda'] / (float)$v['valor_fipe_referencia'] * 100); ?>
                        <br><small><?= $pct ?>% da FIPE</small>
                    <?php endif; ?>
                </td>
                <td>
                    <?= $v['valor_pago_contratacao'] ? moedaVenda((float)$v['valor_pago_contratacao']) : '—' ?>
                    <?php if ($pv && $pv['entrada_status']): ?><br><span class="badge <?= $pv['entrada_status'] === 'pago' ? 'badge-ok' : ($pv['entrada_status'] === 'atrasado' ? 'badge-atraso' : 'badge-aviso') ?>"><?= $pv['entrada_status'] === 'pago' ? '✅ paga' : ($pv['entrada_status'] === 'atrasado' ? '⚠️ atrasada' : '⏳ a conferir') ?></span><?php endif; ?>
                </td>
                <td>
                    <?php if ($v['parcelamento_qtd_parcelas']): ?>
                        <?= (int)$v['parcelamento_qtd_parcelas'] ?>x <?= $v['parcelamento_valor_parcela'] ? moedaVenda((float)$v['parcelamento_valor_parcela']) : '' ?>
                        <?= ($v['parcelamento_periodicidade'] ?? 'mensal') === 'semanal' ? '<small>(semanal)</small>' : '' ?>
                        <?php if ($v['parcelamento_primeira_parcela_data']): ?><br><small>1ª em <?= date('d/m/Y', strtotime($v['parcelamento_primeira_parcela_data'])) ?></small><?php endif; ?>
                    <?php else: ?>
                        <small>à vista / sem parcelamento</small>
                    <?php endif; ?>
                    <?php if ($pv && (int)$pv['qtd'] > 0): ?>
                        <br><span class="badge <?= (int)$pv['atrasadas'] > 0 ? 'badge-atraso' : 'badge-ok' ?>"><?= (int)$pv['pagas'] ?>/<?= (int)$pv['qtd'] ?> pagas<?= (int)$pv['atrasadas'] > 0 ? ' · ' . (int)$pv['atrasadas'] . ' atrasada(s)' : '' ?></span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php $cv = $comissaoVendidas[(int)$v['id']] ?? null; ?>
                    <?php if ($cv): ?>
                        <?= moedaVenda((float)$cv['valor']) ?>
                        <br><span class="badge <?= $cv['status'] === 'pago' ? 'badge-ok' : 'badge-aviso' ?>"><?= $cv['status'] === 'pago' ? '✅ lançada/paga' : '⏳ ' . e($cv['status']) ?></span>
                    <?php elseif ($v['valor_pago_contratacao'] > 0): ?>
                        <small>não lançada</small><br><small>(5% = <?= moedaVenda((float)$v['valor_pago_contratacao'] * 0.05) ?>)</small>
                    <?php else: ?>
                        <small>— sem entrada</small>
                    <?php endif; ?>
                </td>
                <td><?= $v['data_venda'] ? date('d/m/Y', strtotime($v['data_venda'])) : '—' ?></td>
                <td><?= e($v['responsavel_nome'] ?? '—') ?></td>
                <td><a href="/admin/venda.php?id=<?= (int)$v['id'] ?>">Abrir →</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php renderPaginacao($totalFiltrado); ?>
</div>
<?php else: ?>
<div class="card">
    <table class="tabela-oportunidades">
        <thead>
            <tr>
                <?php if ($podeSelecionarEmMassa): ?>
                <th><input type="checkbox" id="bulk-chk-todos" onchange="bulkToggleTodos(this)" title="Selecionar todas as visíveis"></th>
                <?php endif; ?>
                <th>Veículo</th><th>Comprador</th><th>Preço/interesse</th>
                <th>Etapa</th><th>Responsável</th><th>Recebido em</th><th>Observação</th><th></th>
            </tr>
        </thead>
        <tbody>
        <?php if (!$vendas): ?>
            <tr><td colspan="<?= $podeSelecionarEmMassa ? 9 : 8 ?>">Nenhuma <?= $souDono ? 'venda sua' : 'venda' ?> <?= $busca ? 'encontrada' : ($etapaFiltro ? 'nessa etapa' : 'iniciada ainda') ?>.</td></tr>
        <?php endif; ?>
        <?php foreach ($vendas as $v): ?>
            <?php
            // Mesma classe de bug já corrigida em admin/index.php: sem
            // restringir a ETAPAS_VENDA_ATIVAS, uma venda já 'vendido'/
            // 'cancelada'/'sem_perfil' com proxima_acao_em velho (resto de
            // quando ainda estava ativa) apareceria com destaque de atraso
            // como se ainda precisasse de ação — nunca mais faz sentido
            // numa negociação já encerrada.
            $atrasada = in_array($v['etapa'], ETAPAS_VENDA_ATIVAS, true) && $v['proxima_acao_em'] && $v['proxima_acao_em'] < date('Y-m-d H:i:s');
            $quente = $v['temperatura_lead'] === 'quente';
            $ativaParaSelecao = in_array($v['etapa'], ETAPAS_VENDA_ATIVAS, true);
            ?>
            <tr class="<?= trim(($atrasada ? 'linha-atrasada ' : '') . ($quente ? 'linha-quente' : '')) ?>">
                <?php if ($podeSelecionarEmMassa): ?>
                <td data-label="">
                    <?php if ($ativaParaSelecao): ?>
                    <input type="checkbox" class="bulk-chk-lead" name="ids[]" value="<?= (int)$v['id'] ?>" form="form-bulk-cancelar" onchange="bulkAtualizar()">
                    <?php endif; ?>
                </td>
                <?php endif; ?>
                <td>
                    <?php if ($v['veiculo_marca'] || $v['veiculo_modelo']): ?>
                        <?= e(trim($v['veiculo_marca'] . ' ' . $v['veiculo_modelo'])) ?> <?= e((string)($v['veiculo_ano'] ?? '')) ?>
                        <br><small><?= e($v['veiculo_placa'] ?: '—') ?></small>
                    <?php else: ?>
                        <small style="color:var(--texto-fraco)">🔍 ainda não vinculado</small>
                        <?php if ($v['veiculo_interesse_texto']): ?><br><small><?= e(mb_strimwidth($v['veiculo_interesse_texto'], 0, 40, '…')) ?></small><?php endif; ?>
                    <?php endif; ?>
                </td>
                <td>
                    <?= e($v['comprador_nome'] ?: '(sem nome ainda)') ?><br><small><?= e($v['comprador_telefone'] ?: '') ?></small>
                    <?php if ($v['temperatura_lead'] === 'quente'): ?>
                        <br><span class="badge badge-quente">🔥 Quente</span>
                    <?php elseif ($v['temperatura_lead'] === 'morno'): ?>
                        <br><span class="badge">🌤️ Morno</span>
                    <?php elseif ($v['temperatura_lead'] === 'frio'): ?>
                        <br><span class="badge">❄️ Frio</span>
                    <?php endif; ?>
                </td>
                <td><?= $v['preco_venda'] !== null ? moedaVenda((float)$v['preco_venda']) : '—' ?></td>
                <td>
                    <span class="badge"><?= e(etapaVendaLabel($v['etapa'])) ?></span>
                    <?php if ($atrasada): ?><br><span class="badge badge-atraso">⚠️ atrasada</span><?php endif; ?>
                    <?php if (!empty($v['visita_compareceu_em'])): ?>
                        <br><span class="badge badge-ok">🏁 veio na loja</span>
                    <?php elseif ((int)($v['aceita_visitar_fastcar'] ?? 0) === 1): ?>
                        <br><span class="badge badge-info">📍 visita: <?= e(mb_strimwidth((string)$v['visita_dia_horario_texto'], 0, 30, '…') ?: 'confirmada') ?><?= !empty($v['visita_data_resolvida']) ? ' (' . date('d/m', strtotime($v['visita_data_resolvida'])) . ')' : '' ?></span>
                    <?php endif; ?>
                    <?php if ($v['etapa'] === 'vendido' && $v['data_venda']): ?>
                        <br><small>vendido <?= date('d/m/Y', strtotime($v['data_venda'])) ?></small>
                    <?php elseif (in_array($v['etapa'], ['cancelada', 'sem_perfil'], true)): ?>
                        <?php $motivo = $v['motivo_cancelamento'] ?: $v['motivo_perda']; ?>
                        <?php if ($motivo): ?><br><small><?= e(mb_strimwidth($motivo, 0, 50, '…')) ?></small><?php endif; ?>
                    <?php endif; ?>
                </td>
                <td><?= e($v['responsavel_nome'] ?? '—') ?></td>
                <td><?= date('d/m/Y H:i', strtotime($v['created_at'])) ?></td>
                <td data-label="Observação">
                    <?php if ($ativaParaSelecao && $perfil !== 'supervisor'): ?>
                        <?php // 01/10/2026, "adicionar campo para recado tanto na
                              // compra e revenda" — mesma mecânica de admin/index.php,
                              // texto livre editável direto na linha, só enquanto a
                              // negociação ainda está ativa. ?>
                        <form method="post" style="display:flex;gap:4px;min-width:160px">
                            <?= csrfField() ?>
                            <input type="hidden" name="acao" value="atualizar_observacao_manual">
                            <input type="hidden" name="id" value="<?= (int)$v['id'] ?>">
                            <input type="text" name="observacao" value="<?= e($v['observacao_manual'] ?? '') ?>" placeholder="anotação..." style="flex:1;min-width:0;font-size:13px;padding:4px 6px">
                            <button type="submit" class="btn-texto" style="padding:4px 8px" title="Salvar observação">💾</button>
                        </form>
                    <?php else: ?>
                        <?php // Encerrada (cancelada/sem_perfil) — reaproveita o
                              // motivo já mostrado no card de etapa em vez de
                              // aceitar edição aqui, mesma disciplina de compra. ?>
                        <small><?= e(($v['motivo_cancelamento'] ?: $v['motivo_perda']) ?: ($v['observacao_manual'] ?? '') ?: '—') ?></small>
                    <?php endif; ?>
                </td>
                <td>
                    <a href="/admin/venda.php?id=<?= (int)$v['id'] ?>">Abrir →</a>
                    <?php if ($ativaParaSelecao && $perfil !== 'supervisor'): ?>
                    <form method="post" class="etapa-rapida-form" style="margin-top:6px">
                        <?= csrfField() ?>
                        <input type="hidden" name="acao" value="etapa_rapida">
                        <input type="hidden" name="id" value="<?= (int)$v['id'] ?>">
                        <input type="hidden" name="motivo" value="">
                        <select name="etapa_nova" onchange="etapaRapida(this)" style="font-size:13px;padding:4px 6px;margin:0;min-width:150px">
                            <option value="">⚡ Mudar etapa…</option>
                            <?php foreach (['qualificacao_ia', 'crm_preenchido', 'negociacao'] as $etR): if ($etR === $v['etapa']) continue; ?>
                                <option value="<?= $etR ?>"><?= e(etapaVendaLabel($etR)) ?></option>
                            <?php endforeach; ?>
                            <option value="sem_perfil">⚪ Não qualificado</option>
                        </select>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php renderPaginacao($totalFiltrado); ?>
</div>
<?php endif; ?>
</main>
<?php if ($podeSelecionarEmMassa): ?>
<script>
function bulkAtualizar() {
    var checks = document.querySelectorAll('.bulk-chk-lead:checked');
    document.getElementById('bulk-contagem').textContent = checks.length;
    document.getElementById('barra-bulk-cancelar').style.display = checks.length > 0 ? 'flex' : 'none';
}
function bulkToggleTodos(master) {
    document.querySelectorAll('.bulk-chk-lead').forEach(function (c) { c.checked = master.checked; });
    bulkAtualizar();
}
function etapaRapida(sel) {
    var form = sel.form;
    if (!sel.value) return;
    if (sel.value === 'sem_perfil') {
        var motivo = prompt('Motivo de não qualificado (ex: sem entrada, só pesquisando, fora da região):');
        if (motivo === null || motivo.trim() === '') { sel.value = ''; return; }
        form.querySelector('input[name="motivo"]').value = motivo.trim();
    }
    form.submit();
}
function bulkReatribuir() {
    var destino = document.getElementById('bulk-novo-responsavel').value;
    if (!destino) { alert('Escolha pra quem atribuir.'); return; }
    var form = document.getElementById('form-bulk-reatribuir');
    form.querySelectorAll('input[name="ids[]"]').forEach(function (i) { i.remove(); });
    document.querySelectorAll('.bulk-chk-lead:checked').forEach(function (c) {
        var h = document.createElement('input'); h.type = 'hidden'; h.name = 'ids[]'; h.value = c.value; form.appendChild(h);
    });
    document.getElementById('bulk-reatrib-destino').value = destino;
    form.submit();
}
function bulkLimparSelecao() {
    document.querySelectorAll('.bulk-chk-lead').forEach(function (c) { c.checked = false; });
    document.getElementById('bulk-chk-todos').checked = false;
    document.getElementById('bulk-motivo').value = '';
    bulkAtualizar();
}
</script>
<?php endif; ?>
<?php include __DIR__ . '/_pwa_register.php'; ?>
<?php $notifModulo = 'vendas'; include __DIR__ . '/_notify.php'; ?>
<?php include __DIR__ . '/_scroll_restore.php'; ?>
<?php include __DIR__ . '/_acao_popup.php'; ?>
<?php include __DIR__ . '/_confirm_dialog.php'; ?>
<?php include __DIR__ . '/_zapi_status_vendas.php'; ?>
</body>
</html>
