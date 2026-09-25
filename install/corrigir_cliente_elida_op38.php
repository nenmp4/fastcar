<?php
/**
 * Correção pontual — 25/09/2026: cliente #31/oportunidade #38 (telefone
 * 5521979293902) entrou pelo WhatsApp normal, qualificou, foi pro
 * consultor Anderson Souza, e em 18/09/2026 foi marcada 'perdido' (só
 * "ok" como observação) — só que o negócio JÁ TINHA FECHADO de verdade
 * no CRM antigo (Yaqar/IACAR), confirmado pelo CPF batendo exatamente
 * (167.964.507-28) com a Élida Souza e Silva Gonçalves. O nome no cadastro
 * também estava errado ("Thiago Luiz" — provável quem mandou a 1ª
 * mensagem, não a titular do contrato) — confirmado com o usuário: "corrige
 * pra Élida, ela é a titular".
 *
 * Nunca passa por mudarEtapa() (regra #7 bloquearia por falta de checklist
 * de documentos — este negócio não passou pelo funil normal de fechamento,
 * é dado histórico real) — mesma disciplina de criarVeiculoManualFrota()/
 * crmAntigoImportarCliente(): UPDATE direto + histórico manual.
 *
 * Uso:
 *   php install/corrigir_cliente_elida_op38.php              — só mostra (dry-run)
 *   php install/corrigir_cliente_elida_op38.php --confirmar  — corrige de verdade
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Este script só roda via linha de comando (CLI).\n");
}

require_once __DIR__ . '/../includes/db.php';

$CLIENTE_ID = 31;
$OPORTUNIDADE_ID = 38;

$confirmar = in_array('--confirmar', $argv, true);
$db = getDB();

$cliente = $db->query("SELECT * FROM clientes WHERE id = {$CLIENTE_ID}")->fetch(PDO::FETCH_ASSOC);
$op = $db->query("SELECT * FROM oportunidades WHERE id = {$OPORTUNIDADE_ID}")->fetch(PDO::FETCH_ASSOC);

if (!$cliente || !$op || (int)$op['cliente_id'] !== $CLIENTE_ID) {
    fwrite(STDERR, "Cliente #{$CLIENTE_ID} / oportunidade #{$OPORTUNIDADE_ID} não bateram como esperado — aborta, confere manualmente antes de rodar.\n");
    exit(1);
}
if ($cliente['cpf'] !== '167.964.507-28') {
    fwrite(STDERR, "CPF do cliente #{$CLIENTE_ID} não é mais o esperado (167.964.507-28, veio '{$cliente['cpf']}') — aborta, alguém já deve ter mexido nesse registro.\n");
    exit(1);
}

echo "=== Correção: cliente #{$CLIENTE_ID} / oportunidade #{$OPORTUNIDADE_ID} ===\n";
echo $confirmar ? "Modo: CONFIRMAR (grava de verdade)\n\n" : "Modo: DRY-RUN (só mostra, nada é gravado)\n\n";
echo "Nome atual: {$cliente['nome']} -> Élida Souza e Silva Gonçalves\n";
echo "Etapa atual: {$op['etapa']} -> fechado\n";

if (!$confirmar) {
    echo "\nDry-run — nada foi gravado. Rode com --confirmar pra corrigir de verdade.\n";
    exit(0);
}

$db->beginTransaction();
try {
    $db->prepare("
        UPDATE clientes SET
            nome = 'Élida Souza e Silva Gonçalves',
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
        '16796450728', 'Elida2502@gmail.com', 'Brasileira', 'Casada', 'Autônoma',
        'Rua Fernandinho 0, QD C BLC 6 AP 402, Valdariosa, CEP 26311-210', 'Queimados', 'RJ',
        $CLIENTE_ID,
    ]);

    $db->prepare("
        UPDATE oportunidades SET
            etapa = 'fechado',
            motivo_perda = NULL,
            veiculo_placa = ?,
            veiculo_chassi = ?,
            veiculo_renavam = ?,
            valor_final = ?,
            data_compra = date('now','localtime'),
            fechado_por = responsavel_id,
            banco_financiamento = ?,
            contrato_financiamento_numero = ?,
            valor_parcela = ?,
            parcelas_restantes = ?,
            parcelas_atraso = ?
        WHERE id = ?
    ")->execute([
        'PRW3B36', '9BWAB45U1KT054223', '01168082371',
        3500.00,
        'BV', '286034623', 1856.00, 57, 48,
        $OPORTUNIDADE_ID,
    ]);

    $observacao = 'Corrigido manualmente (25/09/2026): negócio já tinha fechado de verdade no CRM antigo '
        . '(Yaqar/IACAR), confirmado pelo CPF 167.964.507-28 — a marcação como "perdido" em 18/09 foi engano. '
        . 'Nome corrigido de "Thiago Luiz" pra Élida Souza e Silva Gonçalves (titular do CPF/contrato). '
        . 'Dados extras sem campo correspondente: Telefone secundário: (21) 98125-2123 | '
        . 'Órgão emissor do RG: DETRAN-RJ | Data de nascimento: 1995-09-19 | Cor do veículo: Branca | '
        . 'Ano modelo: 2019, fabricação: 2018 | Parcelas totais: 60 | Valor em atraso: R$ 89.088,00 | '
        . 'Valor da entrada: R$ 10.000,00 | Valor total do financiamento: R$ 111.360,00 | '
        . 'Dia de vencimento da parcela: 20 | Débito do veículo: R$ 11.522,66 | Banco do PIX: Inter | '
        . 'Chave PIX: 167.964.507-28 | Custo de avaliação: R$ 248,00 | Custo de laudo/vistoria: R$ 300,00 | '
        . 'Custo de despachante: R$ 260,00 | Custo de procuração: R$ 310,00 | Custo de autenticação: R$ 55,00.';
    $db->prepare("
        INSERT INTO oportunidade_historico (oportunidade_id, etapa_anterior, etapa_nova, responsavel_id, observacao)
        VALUES (?, 'perdido', 'fechado', ?, ?)
    ")->execute([$OPORTUNIDADE_ID, $op['responsavel_id'], $observacao]);

    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    throw $e;
}

echo "\n✅ Cliente #{$CLIENTE_ID} corrigido, oportunidade #{$OPORTUNIDADE_ID} agora 'fechado'.\n";
echo "Veja em: /admin/oportunidade.php?id={$OPORTUNIDADE_ID}\n";
