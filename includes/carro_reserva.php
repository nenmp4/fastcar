<?php
/**
 * Carro reserva (19/11/2026, "cliente pediu carro pela busca, temos liberar
 * outro carro, precisa assinar termo de carro reserva").
 *
 * O banco tomou (busca e apreensão) o carro que a Fastcar vendeu/locou pro
 * cliente — a Fastcar libera OUTRO carro da frota. Duas modalidades,
 * escolhidas na hora de liberar:
 *  - 'temporario': posse provisória. O contrato continua com o carro
 *    original; o reserva sai do estoque enquanto estiver com o cliente e
 *    volta quando ele devolve (carroReservaDevolver()).
 *  - 'definitivo': o reserva passa a ser o carro da negociação
 *    (vendas.oportunidade_id troca) e o original sai do estoque.
 *
 * Fluxo: liberar (status 'pendente', reserva já fica bloqueado pra venda)
 * → cliente assina o termo NA TELA (canvas, igual à retirada da vistoria)
 * → 'ativa' (temporário) ou 'concluida' (definitivo) → 'devolvida'
 * (temporário). 'cancelada' = liberação desfeita antes de assinar.
 * Nada muda na venda/estoque até a assinatura, exceto o bloqueio do reserva.
 *
 * Termo: gerarPdfTermoCarroReserva() — RASCUNHO escrito pelo sistema
 * ("crie modelo"), pro time/Jean revisar.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/auditoria.php';
require_once __DIR__ . '/vendas.php';
require_once __DIR__ . '/documentos.php';
require_once __DIR__ . '/contratos_pdf.php';

const CARRO_RESERVA_TIPOS = ['temporario' => 'Temporário (empréstimo)', 'definitivo' => 'Troca definitiva'];
const CARRO_RESERVA_STATUS = [
    'pendente'  => '✍️ Aguardando assinatura',
    'ativa'     => '🔑 Com o cliente',
    'concluida' => '✅ Troca concluída',
    'devolvida' => '↩️ Devolvido',
    'cancelada' => '🚫 Cancelada',
];
const CARRO_RESERVA_COMBUSTIVEL = ['' => '—', 'reserva' => 'Reserva', '1/4' => '1/4', '1/2' => '1/2', '3/4' => '3/4', 'cheio' => 'Cheio'];

/** Status em que o carro reserva NÃO pode ser vendido nem liberado de novo. */
function carroReservaSqlBloqueia(string $aliasOp = 'o'): string {
    return "EXISTS (SELECT 1 FROM veiculo_reservas vr WHERE vr.oportunidade_reserva_id = {$aliasOp}.id AND vr.status IN ('pendente','ativa'))";
}

function carroReservaVeiculoBloqueado(int $oportunidadeId): bool {
    try {
        $st = getDB()->prepare("SELECT 1 FROM veiculo_reservas WHERE oportunidade_reserva_id = ? AND status IN ('pendente','ativa') LIMIT 1");
        $st->execute([$oportunidadeId]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return false; // tabela ainda não migrada — nunca derruba a venda
    }
}

function carroReservaBuscar(int $id): ?array {
    $st = getDB()->prepare("
        SELECT r.*, v.comprador_nome, v.comprador_cpf, v.comprador_telefone, v.comprador_endereco, v.comprador_rg,
               v.etapa AS venda_etapa, v.tipo_contrato,
               oo.veiculo_marca AS orig_marca, oo.veiculo_modelo AS orig_modelo, oo.veiculo_ano AS orig_ano,
               oo.veiculo_placa AS orig_placa, oo.veiculo_renavam AS orig_renavam, oo.veiculo_chassi AS orig_chassi,
               oo.busca_apreensao_em, oo.cliente_id AS orig_cliente_id,
               orr.veiculo_marca AS res_marca, orr.veiculo_modelo AS res_modelo, orr.veiculo_ano AS res_ano,
               orr.veiculo_placa AS res_placa, orr.veiculo_renavam AS res_renavam, orr.veiculo_chassi AS res_chassi,
               u.nome AS criado_por_nome
        FROM veiculo_reservas r
        JOIN vendas v ON v.id = r.venda_id
        JOIN oportunidades oo ON oo.id = r.oportunidade_original_id
        JOIN oportunidades orr ON orr.id = r.oportunidade_reserva_id
        LEFT JOIN usuarios u ON u.id = r.criado_por
        WHERE r.id = ?
    ");
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function carroReservaListarDaVenda(int $vendaId): array {
    $st = getDB()->prepare("SELECT id FROM veiculo_reservas WHERE venda_id = ? ORDER BY id DESC");
    $st->execute([$vendaId]);
    return array_values(array_filter(array_map(fn($r) => carroReservaBuscar((int)$r['id']), $st->fetchAll())));
}

/** Reserva em andamento (pendente/ativa) em que o veículo é o carro reserva — tag na Frota. */
function carroReservaAtualDoVeiculo(int $oportunidadeId): ?array {
    try {
        $st = getDB()->prepare("SELECT r.id, r.venda_id, r.status, r.tipo, v.comprador_nome FROM veiculo_reservas r
                                JOIN vendas v ON v.id = r.venda_id
                                WHERE r.oportunidade_reserva_id = ? AND r.status IN ('pendente','ativa') ORDER BY r.id DESC LIMIT 1");
        $st->execute([$oportunidadeId]);
        return $st->fetch() ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/** Veículos que podem ser liberados como reserva (mesmo critério do estoque de venda). */
function carroReservaVeiculosDisponiveis(int $excluirOportunidadeId = 0): array {
    return array_values(array_filter(listarFrotaDisponivelParaVenda(500),
        fn($v) => (int)$v['oportunidade_id'] !== $excluirOportunidadeId));
}

function _carroReservaHistorico(int $reservaId, int $vendaId, array $opIds, ?int $usuarioId, string $texto, string $etapaVenda): void {
    $db = getDB();
    $db->prepare("INSERT INTO venda_historico (venda_id, etapa_anterior, etapa_nova, responsavel_id, observacao) VALUES (?, ?, ?, ?, ?)")
       ->execute([$vendaId, $etapaVenda, $etapaVenda, $usuarioId, $texto]);
    foreach (array_unique($opIds) as $opId) {
        $db->prepare("INSERT INTO oportunidade_historico (oportunidade_id, etapa_anterior, etapa_nova, responsavel_id, observacao) VALUES (?, 'fechado', 'fechado', ?, ?)")
           ->execute([$opId, $usuarioId, $texto]);
    }
}

function _carroReservaVeiculoTexto(array $r, string $p): string {
    $t = trim(($r["{$p}_marca"] ?? '') . ' ' . ($r["{$p}_modelo"] ?? '') . ' ' . ($r["{$p}_ano"] ?? ''));
    $placa = strtoupper(trim((string)($r["{$p}_placa"] ?? '')));
    return ($t !== '' ? $t : 'veículo') . ($placa !== '' ? " (placa {$placa})" : '');
}

/**
 * Libera o carro reserva (status 'pendente' até o cliente assinar).
 * $dados: tipo, oportunidade_reserva_id, motivo, prazo_devolucao (Y-m-d, só
 * temporário, opcional), km_entrega, combustivel_entrega, obs_entrega.
 */
function carroReservaLiberar(int $vendaId, array $dados, int $usuarioId): array {
    $db = getDB();
    $st = $db->prepare("SELECT id, etapa, oportunidade_id, comprador_nome FROM vendas WHERE id = ?");
    $st->execute([$vendaId]);
    $v = $st->fetch();
    if (!$v || !$v['oportunidade_id']) return ['ok' => false, 'erro' => 'Negociação não encontrada.'];
    if ($v['etapa'] !== 'vendido') return ['ok' => false, 'erro' => 'Carro reserva só pra contrato já assinado (vendido/locado).'];
    if (trim((string)$v['comprador_nome']) === '') return ['ok' => false, 'erro' => 'Preencha os dados do cliente na venda antes.'];

    $tipo = (string)($dados['tipo'] ?? '');
    if (!isset(CARRO_RESERVA_TIPOS[$tipo])) return ['ok' => false, 'erro' => 'Escolha se é temporário ou troca definitiva.'];
    $reservaOp = (int)($dados['oportunidade_reserva_id'] ?? 0);
    $origOp = (int)$v['oportunidade_id'];
    if ($reservaOp <= 0 || $reservaOp === $origOp) return ['ok' => false, 'erro' => 'Escolha o carro reserva.'];
    if (!veiculoDisponivelParaVenda($reservaOp)) return ['ok' => false, 'erro' => 'Esse carro não está disponível no estoque (vendido, em negociação ou já reservado).'];

    $st = $db->prepare("SELECT COUNT(*) FROM veiculo_reservas WHERE venda_id = ? AND status IN ('pendente','ativa')");
    $st->execute([$vendaId]);
    if ((int)$st->fetchColumn() > 0) return ['ok' => false, 'erro' => 'Já existe um carro reserva em andamento pra esse contrato — devolva ou cancele antes.'];

    $motivo = trim(clean((string)($dados['motivo'] ?? '')));
    if ($motivo === '') return ['ok' => false, 'erro' => 'Informe o motivo.'];
    $prazo = null;
    if ($tipo === 'temporario' && trim((string)($dados['prazo_devolucao'] ?? '')) !== '') {
        $dt = DateTime::createFromFormat('Y-m-d', (string)$dados['prazo_devolucao']);
        if (!$dt || $dt->format('Y-m-d') !== $dados['prazo_devolucao'] || $dados['prazo_devolucao'] < date('Y-m-d')) {
            return ['ok' => false, 'erro' => 'Prazo de devolução inválido (não pode ser no passado).'];
        }
        $prazo = $dados['prazo_devolucao'];
    }
    $km = trim((string)($dados['km_entrega'] ?? '')) !== '' ? max(0, (int)preg_replace('/\D/', '', (string)$dados['km_entrega'])) : null;
    $comb = (string)($dados['combustivel_entrega'] ?? '');
    if (!isset(CARRO_RESERVA_COMBUSTIVEL[$comb])) $comb = '';
    $obs = trim(clean(mb_substr((string)($dados['obs_entrega'] ?? ''), 0, 500)));

    $db->prepare("INSERT INTO veiculo_reservas (venda_id, oportunidade_original_id, oportunidade_reserva_id, tipo, motivo,
                    prazo_devolucao, km_entrega, combustivel_entrega, obs_entrega, status, criado_por)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pendente', ?)")
       ->execute([$vendaId, $origOp, $reservaOp, $tipo, $motivo, $prazo, $km, $comb, $obs, $usuarioId]);
    $id = (int)$db->lastInsertId();
    $r = carroReservaBuscar($id);
    $txt = "🔑 Carro reserva liberado (" . CARRO_RESERVA_TIPOS[$tipo] . "): " . _carroReservaVeiculoTexto($r, 'res')
         . " no lugar de " . _carroReservaVeiculoTexto($r, 'orig') . " — motivo: {$motivo}. Aguardando assinatura do termo.";
    _carroReservaHistorico($id, $vendaId, [$origOp, $reservaOp], $usuarioId, $txt, 'vendido');
    auditoriaRegistrar('carro_reserva_liberado', $usuarioId, (string)($_SESSION['admin_nome'] ?? ''), 'venda', $vendaId, $txt);
    return ['ok' => true, 'id' => $id];
}

function carroReservaCancelar(int $id, int $usuarioId, string $motivo = ''): array {
    $r = carroReservaBuscar($id);
    if (!$r) return ['ok' => false, 'erro' => 'Carro reserva não encontrado.'];
    if ($r['status'] !== 'pendente') return ['ok' => false, 'erro' => 'Só dá pra cancelar antes da assinatura.'];
    getDB()->prepare("UPDATE veiculo_reservas SET status = 'cancelada', updated_at = datetime('now','localtime') WHERE id = ?")->execute([$id]);
    $txt = '🚫 Liberação do carro reserva cancelada antes da assinatura: ' . _carroReservaVeiculoTexto($r, 'res') . ($motivo !== '' ? " — {$motivo}" : '');
    _carroReservaHistorico($id, (int)$r['venda_id'], [(int)$r['oportunidade_original_id'], (int)$r['oportunidade_reserva_id']], $usuarioId, $txt, (string)$r['venda_etapa']);
    auditoriaRegistrar('carro_reserva_cancelado', $usuarioId, (string)($_SESSION['admin_nome'] ?? ''), 'venda', (int)$r['venda_id'], $txt);
    return ['ok' => true];
}

/** Cliente assinou na tela: gera o termo com a assinatura, guarda e aplica a modalidade. */
function carroReservaAssinar(int $id, string $assinaturaDataUrl, int $usuarioId): array {
    $r = carroReservaBuscar($id);
    if (!$r) return ['ok' => false, 'erro' => 'Carro reserva não encontrado.'];
    if ($r['status'] !== 'pendente') return ['ok' => false, 'erro' => 'Esse termo já foi assinado ou cancelado.'];
    if (!preg_match('#^data:image/png;base64,(.+)$#', $assinaturaDataUrl, $m)) {
        return ['ok' => false, 'erro' => 'Assinatura inválida — peça pro cliente assinar de novo.'];
    }
    $bytes = base64_decode($m[1], true);
    if ($bytes === false || $bytes === '') return ['ok' => false, 'erro' => 'Assinatura vazia — peça pro cliente desenhar antes de confirmar.'];
    // Se a troca definitiva não puder acontecer (ex.: venda mudou), recusa antes de gerar nada.
    if ($r['tipo'] === 'definitivo' && $r['venda_etapa'] !== 'vendido') {
        return ['ok' => false, 'erro' => 'A negociação não está mais como vendida — a troca definitiva não pode ser aplicada.'];
    }

    $tmpAss = tempnam(sys_get_temp_dir(), 'assin_reserva_') . '.png';
    file_put_contents($tmpAss, $bytes);
    $r['_assinado_em'] = date('Y-m-d H:i:s');
    $r['_ip'] = auditoriaClienteIp();
    $pdfPath = gerarPdfTermoCarroReserva($r, $tmpAss);

    $db = getDB();
    $cliId = (int)$r['orig_cliente_id'];
    $stCli = $db->prepare("SELECT nome FROM clientes WHERE id = ?");
    $stCli->execute([$cliId]);
    $cliNome = (string)($stCli->fetchColumn() ?: "Cliente #{$cliId}");
    $copiaPdf = salvarArquivoGeradoComoDocumento($cliId, $cliNome, $pdfPath, "termo_carro_reserva_{$id}.pdf", 'application/pdf', 'revenda');
    $copiaAss = salvarArquivoGeradoComoDocumento($cliId, $cliNome, $tmpAss, "assinatura_carro_reserva_{$id}.png", 'image/png', 'revenda');
    @unlink($pdfPath);
    @unlink($tmpAss);

    $novoStatus = $r['tipo'] === 'definitivo' ? 'concluida' : 'ativa';
    $orig = (int)$r['oportunidade_original_id'];
    $res = (int)$r['oportunidade_reserva_id'];
    $db->beginTransaction();
    try {
        $db->prepare("UPDATE veiculo_reservas SET status = ?, assinado_em = ?, assinatura_ip = ?, assinatura_user_agent = ?,
                        termo_drive_file_id = ?, termo_arquivo_url = ?, assinatura_drive_file_id = ?, assinatura_arquivo_url = ?,
                        assinado_registrado_por = ?, updated_at = datetime('now','localtime')
                      WHERE id = ? AND status = 'pendente'")
           ->execute([$novoStatus, $r['_assinado_em'], clean($r['_ip']), clean(mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255)),
                      $copiaPdf['drive_file_id'], $copiaPdf['arquivo_url'], $copiaAss['drive_file_id'], $copiaAss['arquivo_url'],
                      $usuarioId, $id]);
        if ($r['tipo'] === 'definitivo') {
            $db->prepare("UPDATE vendas SET oportunidade_id = ?, updated_at = datetime('now','localtime') WHERE id = ?")->execute([$res, (int)$r['venda_id']]);
            $db->prepare("UPDATE oportunidades SET oculto_estoque = 1, oculto_estoque_motivo = ?, updated_at = datetime('now','localtime') WHERE id = ?")
               ->execute(['Apreendido — substituído por carro reserva na venda #' . (int)$r['venda_id'], $orig]);
            $txt = '✅ Termo de carro reserva assinado — TROCA DEFINITIVA: o contrato passa a ser do ' . _carroReservaVeiculoTexto($r, 'res')
                 . ' no lugar do ' . _carroReservaVeiculoTexto($r, 'orig') . '.';
        } else {
            $txt = '✅ Termo de carro reserva assinado — ' . _carroReservaVeiculoTexto($r, 'res') . ' entregue (temporário)'
                 . ($r['prazo_devolucao'] ? ', devolução prevista até ' . date('d/m/Y', strtotime($r['prazo_devolucao'])) : '') . '.';
        }
        _carroReservaHistorico($id, (int)$r['venda_id'], [$orig, $res], $usuarioId, $txt, 'vendido');
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        return ['ok' => false, 'erro' => 'Falha ao gravar a assinatura, tente de novo.'];
    }
    auditoriaRegistrar('carro_reserva_assinado', $usuarioId, (string)($_SESSION['admin_nome'] ?? ''), 'venda', (int)$r['venda_id'], $txt);
    return ['ok' => true, 'status' => $novoStatus];
}

/** Cliente devolveu o carro reserva (só temporário). */
function carroReservaDevolver(int $id, array $dados, int $usuarioId): array {
    $r = carroReservaBuscar($id);
    if (!$r) return ['ok' => false, 'erro' => 'Carro reserva não encontrado.'];
    if ($r['status'] !== 'ativa') return ['ok' => false, 'erro' => 'Esse carro reserva não está com o cliente.'];
    $data = (string)($dados['data'] ?? date('Y-m-d'));
    $dt = DateTime::createFromFormat('Y-m-d', $data);
    if (!$dt || $dt->format('Y-m-d') !== $data || $data > date('Y-m-d')) return ['ok' => false, 'erro' => 'Data de devolução inválida.'];
    $km = trim((string)($dados['km'] ?? '')) !== '' ? max(0, (int)preg_replace('/\D/', '', (string)$dados['km'])) : null;
    $comb = (string)($dados['combustivel'] ?? '');
    if (!isset(CARRO_RESERVA_COMBUSTIVEL[$comb])) $comb = '';
    $obs = trim(clean(mb_substr((string)($dados['obs'] ?? ''), 0, 500)));
    getDB()->prepare("UPDATE veiculo_reservas SET status = 'devolvida', devolvido_em = ?, km_devolucao = ?, combustivel_devolucao = ?,
                        obs_devolucao = ?, devolvido_registrado_por = ?, updated_at = datetime('now','localtime') WHERE id = ?")
           ->execute([$data, $km, $comb, $obs, $usuarioId, $id]);
    $txt = '↩️ Carro reserva devolvido em ' . $dt->format('d/m/Y') . ': ' . _carroReservaVeiculoTexto($r, 'res')
         . ($km !== null ? " — KM {$km}" : '') . ($obs !== '' ? " — {$obs}" : '') . '. Voltou pro estoque.';
    _carroReservaHistorico($id, (int)$r['venda_id'], [(int)$r['oportunidade_original_id'], (int)$r['oportunidade_reserva_id']], $usuarioId, $txt, (string)$r['venda_etapa']);
    auditoriaRegistrar('carro_reserva_devolvido', $usuarioId, (string)($_SESSION['admin_nome'] ?? ''), 'venda', (int)$r['venda_id'], $txt);
    return ['ok' => true];
}

/**
 * PDF do Termo de entrega de veículo reserva. RASCUNHO (19/11/2026, "crie
 * modelo") — texto escrito pelo sistema pro time revisar. Sem $assinaturaPng
 * sai como prévia ("assine na tela").
 */
function gerarPdfTermoCarroReserva(array $r, ?string $assinaturaPng = null): string {
    $linha = '______________________________';
    $ou = fn($x) => trim((string)$x) !== '' ? (string)$x : $linha;
    $tr = fn($x) => trim((string)$x) !== '' ? (string)$x : '—'; // campo curto dentro de linha combinada
    $contrato = (($r['tipo_contrato'] ?? 'venda') === 'locacao' ? 'LOC-' : 'VENDA-') . (int)$r['venda_id'];
    $definitivo = $r['tipo'] === 'definitivo';

    $pdf = _pdfNovo();
    _pdfCabecalho($pdf, 'TERMO DE ENTREGA DE VEÍCULO RESERVA');
    $pdf->SetFont('Helvetica', 'B', 10);
    $pdf->Cell(0, 6, _pdfTexto("FASTCAR SOLUTIONS  |  CONTRATO Nº {$contrato}  |  TERMO Nº " . (int)$r['id']), 0, 1, 'C');
    $pdf->Ln(2);

    _pdfCorpo($pdf,
        'Pelo presente termo, FASTCAR SOLUTIONS LTDA., CNPJ nº 66.934.500/0001-09, doravante FASTCAR, e o(a) CLIENTE ' .
        'identificado(a) abaixo, parte do contrato indicado, ajustam a entrega de VEÍCULO RESERVA nas condições a seguir.'
    );
    $pdf->Ln(2);
    _pdfLinhaResumo($pdf, 'Cliente', $ou($r['comprador_nome']));
    _pdfLinhaResumo($pdf, 'CPF / RG', $tr($r['comprador_cpf']) . ' / ' . $tr($r['comprador_rg']));
    _pdfLinhaResumo($pdf, 'Telefone', $ou($r['comprador_telefone']));
    _pdfLinhaResumo($pdf, 'Endereço', $ou($r['comprador_endereco']));

    $veic = function (string $titulo, string $p) use ($pdf, $r, $ou, $tr) {
        $pdf->Ln(2);
        $pdf->SetFont('Helvetica', 'B', 10);
        $pdf->Cell(0, 6, _pdfTexto($titulo), 0, 1, 'L');
        _pdfLinhaResumo($pdf, 'Marca / modelo / ano', $ou(trim($r["{$p}_marca"] . ' ' . $r["{$p}_modelo"] . ' ' . $r["{$p}_ano"])));
        _pdfLinhaResumo($pdf, 'Placa / RENAVAM / chassi', $tr(strtoupper((string)$r["{$p}_placa"])) . ' / ' . $tr($r["{$p}_renavam"]) . ' / ' . $tr($r["{$p}_chassi"]));
    };
    $veic('VEÍCULO ORIGINAL DO CONTRATO', 'orig');
    _pdfLinhaResumo($pdf, 'Motivo da substituição', $ou($r['motivo']));
    $veic('VEÍCULO RESERVA ENTREGUE', 'res');
    _pdfLinhaResumo($pdf, 'Modalidade', $definitivo ? 'TROCA DEFINITIVA — substitui o veículo do contrato' : 'TEMPORÁRIA — empréstimo até a regularização');
    if (!$definitivo) {
        _pdfLinhaResumo($pdf, 'Devolução prevista', $r['prazo_devolucao'] ? date('d/m/Y', strtotime($r['prazo_devolucao'])) : 'quando solicitado pela FASTCAR (cláusula 2ª)');
    }
    _pdfLinhaResumo($pdf, 'KM / combustível na entrega', ($r['km_entrega'] !== null ? number_format((int)$r['km_entrega'], 0, ',', '.') . ' km' : $linha)
        . ' / ' . (CARRO_RESERVA_COMBUSTIVEL[$r['combustivel_entrega'] ?? ''] ?? '—'));
    _pdfLinhaResumo($pdf, 'Estado / observações', trim((string)$r['obs_entrega']) !== '' ? (string)$r['obs_entrega'] : 'Bom estado de conservação e funcionamento, salvo o anotado em vistoria.');

    $pdf->Ln(3);
    _pdfTituloClausula($pdf, 'CLÁUSULA 1ª – OBJETO');
    _pdfCorpo($pdf, 'A FASTCAR entrega ao CLIENTE, nesta data, o VEÍCULO RESERVA acima identificado, com documento de porte ' .
        'obrigatório, chave(s) e equipamentos obrigatórios, em razão do motivo informado, vinculado ao contrato indicado.');
    if ($definitivo) {
        _pdfTituloClausula($pdf, 'CLÁUSULA 2ª – SUBSTITUIÇÃO DEFINITIVA');
        _pdfCorpo($pdf, 'O VEÍCULO RESERVA passa a ser o objeto do contrato indicado, em substituição ao VEÍCULO ORIGINAL, ' .
            'mantidas sem alteração todas as demais cláusulas, valores, parcelas, prazos, encargos e condições do contrato. ' .
            'O VEÍCULO ORIGINAL deixa de integrar o contrato a partir desta data.');
    } else {
        _pdfTituloClausula($pdf, 'CLÁUSULA 2ª – CARÁTER TEMPORÁRIO');
        _pdfCorpo($pdf, 'A entrega é provisória e não transfere a propriedade nem altera o objeto do contrato. O VEÍCULO RESERVA ' .
            'deverá ser devolvido na data prevista acima ou em até 48 (quarenta e oito) horas após comunicação da FASTCAR pelos ' .
            'canais do contrato, no local por ela indicado, nas mesmas condições em que foi recebido, ressalvado o desgaste ' .
            'natural pelo uso normal. As obrigações do CLIENTE previstas no contrato, inclusive o pagamento das parcelas, ' .
            'permanecem inalteradas durante o período.');
    }
    _pdfTituloClausula($pdf, 'CLÁUSULA 3ª – RESPONSABILIDADES DO CLIENTE');
    _pdfCorpo($pdf, 'Durante a posse, o CLIENTE se obriga a: (a) usar o veículo com zelo, apenas para fins lícitos, conduzido ' .
        'por pessoa habilitada; (b) responder por multas, infrações, pedágios, estacionamentos, combustível e danos causados ' .
        'ao veículo ou a terceiros; (c) não emprestar, sublocar, ceder, vender, dar em garantia nem alterar características ' .
        'do veículo; (d) comunicar à FASTCAR, em até 24 (vinte e quatro) horas, qualquer acidente, furto, roubo, apreensão ' .
        'ou defeito; (e) manter o veículo e seus documentos em seu poder e permitir vistoria quando solicitada.');
    _pdfTituloClausula($pdf, 'CLÁUSULA 4ª – MULTAS E INDICAÇÃO DE CONDUTOR');
    _pdfCorpo($pdf, 'O CLIENTE autoriza a FASTCAR a indicá-lo como condutor responsável pelas infrações de trânsito cometidas ' .
        'com o VEÍCULO RESERVA a partir desta data' . ($definitivo ? '' : ' e até a efetiva devolução') . ', e a cobrar ' .
        'valores que a FASTCAR venha a pagar em razão delas.');
    if (!$definitivo) {
        _pdfTituloClausula($pdf, 'CLÁUSULA 5ª – NÃO DEVOLUÇÃO');
        _pdfCorpo($pdf, 'A não devolução no prazo configura descumprimento contratual e autoriza a FASTCAR a retomar o VEÍCULO ' .
            'RESERVA nos termos do contrato e da lei, sem prejuízo da cobrança de despesas, danos e encargos.');
    }
    _pdfTituloClausula($pdf, 'CLÁUSULA ' . ($definitivo ? '5ª' : '6ª') . ' – DISPOSIÇÕES GERAIS');
    _pdfCorpo($pdf, 'Este termo integra o contrato indicado. A assinatura do CLIENTE colhida na tela, com data, hora e endereço ' .
        'IP registrados no sistema, vale como manifestação de concordância com estas condições. Fica eleito o foro da ' .
        'Comarca de Barueri/SP.');

    $pdf->Ln(4);
    if ($pdf->GetY() > 220) $pdf->AddPage();
    $pdf->SetFont('Helvetica', '', 9);
    $quando = !empty($r['_assinado_em']) ? date('d/m/Y \à\s H:i', strtotime($r['_assinado_em'])) : '____/____/________';
    $pdf->Cell(0, 6, _pdfTexto('Barueri/SP, ' . $quando . '.'), 0, 1, 'L');
    $pdf->Ln(4);

    $yAssin = $pdf->GetY();
    if ($assinaturaPng && is_file($assinaturaPng)) {
        $dim = @getimagesize($assinaturaPng);
        if ($dim && $dim[0] > 0) {
            $w = 60;
            $h = min(25, $w * $dim[1] / $dim[0]);
            $w = $h * $dim[0] / $dim[1];
            $pdf->Image($assinaturaPng, 20 + (80 - $w) / 2, $yAssin, $w, $h);
        }
    } else {
        $pdf->SetXY(20, $yAssin + 8);
        $pdf->SetFont('Helvetica', 'I', 8);
        $pdf->Cell(80, 5, _pdfTexto('(assinatura colhida na tela)'), 0, 0, 'C');
    }
    $pdf->SetXY(20, $yAssin + 26);
    $pdf->SetFont('Helvetica', '', 8.5);
    $pdf->Cell(80, 5, '______________________________________', 0, 0, 'C');
    $pdf->SetXY(110, $yAssin + 26);
    $pdf->Cell(80, 5, '______________________________________', 0, 1, 'C');
    $pdf->SetFont('Helvetica', 'B', 8.5);
    $pdf->SetX(20);
    $pdf->Cell(80, 5, _pdfTexto('CLIENTE: ' . mb_strtoupper((string)$r['comprador_nome'])), 0, 0, 'C');
    $pdf->SetX(110);
    $pdf->Cell(80, 5, _pdfTexto('FASTCAR SOLUTIONS LTDA.'), 0, 1, 'C');
    if (!empty($r['_assinado_em'])) {
        $pdf->Ln(3);
        $pdf->SetFont('Helvetica', '', 7.5);
        $pdf->MultiCell(0, 4, _pdfTexto('Assinado eletronicamente na tela pelo CLIENTE em ' . $quando . ', IP ' . ($r['_ip'] ?? '—') .
            '. Termo nº ' . (int)$r['id'] . ' registrado no sistema da FASTCAR.'));
    }

    _pdfRodapeEndereco($pdf);
    $caminho = tempnam(sys_get_temp_dir(), 'termo_reserva_') . '.pdf';
    $pdf->Output('F', $caminho);
    return $caminho;
}
