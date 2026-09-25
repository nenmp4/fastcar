<?php
/**
 * Cadastro manual de 1 cliente/veículo que fechou no CRM antigo mas não
 * apareceu no export CSV (`install/importar_crm_antigo.php`) — 25/09/2026,
 * caso real: Élida Souza e Silva Gonçalves, achada direto na tela do CRM
 * antigo (fastcar.site) em vez de no export. Mesma lógica de
 * `crmAntigoImportarCliente()`/`crmAntigoGravarNucleoCliente()`
 * (includes/importar_crm_antigo.php) — reaproveita `criarVeiculoManualFrota()`
 * pra criação básica, depois complementa CPF/RG/endereço/dados de
 * financiamento via UPDATE fill-if-empty — só que os dados vêm de um
 * array editado à mão aqui em cima, não de uma linha de CSV.
 *
 * Dado sem coluna própria no schema (telefone secundário, débito do
 * veículo, valor da entrada, dia de vencimento, dados de PIX, custos
 * operacionais, órgão emissor do RG) nunca é descartado — vai pro
 * observação de oportunidade_historico, texto legível.
 *
 * Reusável: edite o array $DADOS abaixo pro próximo cliente que precisar
 * desse mesmo tratamento manual, e rode de novo.
 *
 * Uso:
 *   php install/cadastrar_cliente_manual.php              — só mostra o que seria gravado (dry-run)
 *   php install/cadastrar_cliente_manual.php --confirmar   — grava de verdade
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Este script só roda via linha de comando (CLI).\n");
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/oportunidades.php';

// ---------------------------------------------------------------------
// Edite aqui pro cliente da vez.
// ---------------------------------------------------------------------
$DADOS = [
    'nome' => 'Élida Souza e Silva Gonçalves',
    'telefone' => '21979293902',
    'telefone_secundario' => '(21) 98125-2123',
    'cpf' => '167.964.507-28',
    'rg' => '16796450728',
    'rg_orgao_emissor' => 'DETRAN-RJ',
    'nascimento' => '1995-09-19',
    'nacionalidade' => 'Brasileira',
    'estado_civil' => 'Casada',
    'profissao' => 'Autônoma',
    'email' => 'Elida2502@gmail.com',
    'endereco_rua' => 'Rua Fernandinho',
    'endereco_numero' => '0',
    'endereco_complemento' => 'QD C BLC 6 AP 402',
    'endereco_bairro' => 'Valdariosa',
    'endereco_cidade' => 'Queimados',
    'endereco_estado' => 'RJ',
    'endereco_cep' => '26311-210',

    'veiculo_marca' => 'VW',
    'veiculo_modelo' => 'GOL 1.6L MB5',
    'veiculo_ano' => '2019', // ano modelo (fabricação 2018, ver extras)
    'veiculo_placa' => 'PRW-3B36',
    'veiculo_chassi' => '9BWAB45U1KT054223',
    'veiculo_renavam' => '01168082371',
    'veiculo_cor' => 'Branca',
    'veiculo_ano_fabricacao' => '2018',

    'valor_pago_pela_fastcar' => 3500.00, // "Pagamento ao Cedente" no CRM antigo
    'banco_financiamento' => 'BV',
    'contrato_financiamento_numero' => '286034623',
    'valor_parcela' => 1856.00,
    'parcelas_totais' => 60,
    'parcelas_restantes' => 57,
    'parcelas_atraso' => 48,
    'valor_atrasado' => 89088.00,
    'valor_entrada' => 10000.00,
    'valor_total_financiamento' => 111360.00,
    'dia_vencimento_parcela' => 20,
    'debito_veiculo' => 11522.66,
    'pix_banco' => 'Inter',
    'pix_chave' => '167.964.507-28',
    'custo_avaliacao' => 248.00,
    'custo_vistoria' => 300.00,
    'custo_despachante' => 260.00,
    'custo_procuracao' => 310.00,
    'custo_autenticacao' => 55.00,

    // Data real do fechamento no CRM antigo, se souber (formato AAAA-MM-DD)
    // — deixe null pra usar a data de hoje (não temos certeza da data real
    // a partir só das telas de Dados Pessoais/Veículo).
    'data_compra' => null,

    'responsavel_id' => null, // id do usuário consultor responsável, se souber
];
// ---------------------------------------------------------------------

$confirmar = in_array('--confirmar', $argv, true);

echo "=== Cadastro manual: {$DADOS['nome']} ({$DADOS['telefone']}) ===\n";
echo $confirmar ? "Modo: CONFIRMAR (grava de verdade)\n\n" : "Modo: DRY-RUN (só mostra, nada é gravado)\n\n";

$db = getDB();
$stmt = $db->prepare('SELECT id FROM clientes WHERE telefone = ?');
$telNorm = normalizarTelefone($DADOS['telefone']);
$stmt->execute([$telNorm]);
if ($stmt->fetch()) {
    echo "⚠️  Já existe um cliente com o telefone {$telNorm} — provavelmente já foi cadastrado antes. Confere em admin/clientes.php antes de rodar de novo.\n";
    exit(0);
}

echo "Veículo: {$DADOS['veiculo_marca']}/{$DADOS['veiculo_modelo']} {$DADOS['veiculo_ano']} — placa {$DADOS['veiculo_placa']}\n";
echo "Valor pago pela Fastcar: R$ " . number_format($DADOS['valor_pago_pela_fastcar'], 2, ',', '.') . "\n";

if (!$confirmar) {
    echo "\nDry-run — nada foi gravado. Rode com --confirmar pra cadastrar de verdade.\n";
    exit(0);
}

$db->beginTransaction();
try {
    $r = criarVeiculoManualFrota(
        $DADOS['nome'],
        $DADOS['telefone'],
        $DADOS['veiculo_marca'],
        $DADOS['veiculo_modelo'],
        $DADOS['veiculo_ano'],
        preg_replace('/[^A-Za-z0-9]/', '', $DADOS['veiculo_placa']),
        $DADOS['veiculo_chassi'],
        $DADOS['veiculo_renavam'],
        $DADOS['valor_pago_pela_fastcar'],
        (int)($db->query("SELECT id FROM usuarios WHERE perfil='super_admin' ORDER BY id LIMIT 1")->fetchColumn()),
        $DADOS['responsavel_id']
    );
    $clienteId = $r['cliente_id'];
    $oportunidadeId = $r['oportunidade_id'];

    $enderecoPartes = array_filter([
        trim($DADOS['endereco_rua'] . ' ' . $DADOS['endereco_numero']),
        $DADOS['endereco_complemento'],
        $DADOS['endereco_bairro'],
    ], fn($p) => trim((string)$p) !== '');
    if ($DADOS['endereco_cep']) $enderecoPartes[] = 'CEP ' . $DADOS['endereco_cep'];
    $enderecoTexto = implode(', ', $enderecoPartes);

    $db->prepare("
        UPDATE clientes SET
            cpf = CASE WHEN cpf = '' THEN ? ELSE cpf END,
            rg = CASE WHEN rg = '' THEN ? ELSE rg END,
            email = CASE WHEN email = '' THEN ? ELSE email END,
            nacionalidade = CASE WHEN nacionalidade = '' THEN ? ELSE nacionalidade END,
            estado_civil = CASE WHEN estado_civil = '' THEN ? ELSE estado_civil END,
            profissao = CASE WHEN profissao = '' THEN ? ELSE profissao END,
            endereco = CASE WHEN endereco = '' THEN ? ELSE endereco END,
            cidade = CASE WHEN cidade = '' THEN ? ELSE cidade END,
            estado = CASE WHEN estado = '' THEN ? ELSE estado END
        WHERE id = ?
    ")->execute([
        clean($DADOS['cpf']), clean($DADOS['rg']), clean($DADOS['email']),
        clean($DADOS['nacionalidade']), clean($DADOS['estado_civil']), clean($DADOS['profissao']),
        clean($enderecoTexto), clean($DADOS['endereco_cidade']), clean($DADOS['endereco_estado']),
        $clienteId,
    ]);

    $db->prepare("
        UPDATE oportunidades SET
            banco_financiamento = ?,
            contrato_financiamento_numero = ?,
            valor_parcela = ?,
            parcelas_restantes = ?,
            parcelas_atraso = ?,
            data_compra = COALESCE(NULLIF(?, ''), data_compra)
        WHERE id = ?
    ")->execute([
        clean($DADOS['banco_financiamento']),
        clean($DADOS['contrato_financiamento_numero']),
        $DADOS['valor_parcela'],
        $DADOS['parcelas_restantes'],
        $DADOS['parcelas_atraso'],
        $DADOS['data_compra'] ?? '',
        $oportunidadeId,
    ]);

    $extras = [];
    $mapaExtras = [
        'telefone_secundario' => 'Telefone secundário',
        'rg_orgao_emissor' => 'Órgão emissor do RG',
        'nascimento' => 'Data de nascimento',
        'veiculo_cor' => 'Cor do veículo',
        'veiculo_ano_fabricacao' => 'Ano de fabricação',
        'parcelas_totais' => 'Parcelas totais',
        'valor_atrasado' => 'Valor em atraso',
        'valor_entrada' => 'Valor da entrada',
        'valor_total_financiamento' => 'Valor total do financiamento',
        'dia_vencimento_parcela' => 'Dia de vencimento da parcela',
        'debito_veiculo' => 'Débito do veículo',
        'pix_banco' => 'Banco do PIX',
        'pix_chave' => 'Chave PIX',
        'custo_avaliacao' => 'Custo de avaliação',
        'custo_vistoria' => 'Custo de laudo/vistoria',
        'custo_despachante' => 'Custo de despachante',
        'custo_procuracao' => 'Custo de procuração',
        'custo_autenticacao' => 'Custo de autenticação',
    ];
    foreach ($mapaExtras as $campo => $rotulo) {
        $v = $DADOS[$campo] ?? null;
        if ($v === null || $v === '') continue;
        $extras[] = "{$rotulo}: " . (is_float($v) ? 'R$ ' . number_format($v, 2, ',', '.') : $v);
    }
    $observacao = 'Cadastrado manualmente — cliente fechou negócio no CRM antigo, não estava no export CSV.';
    if ($extras) $observacao .= ' Dados extras sem campo correspondente: ' . implode(' | ', $extras) . '.';
    $db->prepare("
        INSERT INTO oportunidade_historico (oportunidade_id, etapa_anterior, etapa_nova, responsavel_id, observacao)
        VALUES (?, 'fechado', 'fechado', ?, ?)
    ")->execute([$oportunidadeId, $DADOS['responsavel_id'], $observacao]);

    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    throw $e;
}

echo "\n✅ Cliente #{$clienteId} / Oportunidade #{$oportunidadeId} cadastrados com sucesso.\n";
echo "Veja em: /admin/oportunidade.php?id={$oportunidadeId}\n";
