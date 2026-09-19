<?php
/**
 * includes/zapsign_importar.php — reconciliação de contratos que já
 * existem na conta ZapSign mas nunca passaram por este sistema (19/09/2026,
 * "zapasine tem monte contrato do crm anti será possivel puxar concliar" →
 * "pela api" → "Listar + tentar vincular automaticamente... - esses
 * clientes não está no sistema" → "teria importar cadastrar todas
 * infomaçoes"). Arquivo PRÓPRIO de propósito (mesmo raciocínio de sempre
 * pra fluxo com regras diferentes): não é geração de contrato normal
 * (includes/contratos.php), é IMPORTAÇÃO de um contrato que já existe do
 * lado de fora, sem passar por `zapsignCriarDocumentoEAssinatura()`.
 *
 * Escopo desta 1ª versão, confirmado com o usuário — **só contrato de
 * COMPRA**: reaproveita `criarVeiculoManualFrota()` (já existe, já
 * testada, mesmo caminho de "veículo que a Fastcar já tem mas nunca passou
 * pelo funil normal") — nome/telefone do vendedor vêm pré-preenchidos da
 * ZapSign, mas marca/modelo/ano/placa/chassi/renavam/valor a ZapSign NÃO
 * tem (ela só sabe o que foi digitado no PDF/formulário de assinatura, não
 * dados estruturados de veículo) — o admin digita esses na tela de
 * importação, exatamente como já faz pro cadastro manual normal de veículo.
 * Contrato de VENDA antigo (revenda) fica DE FORA por enquanto — depende
 * de o veículo já estar na frota (regra #3, nunca invento vínculo), e um
 * contrato de venda do CRM antigo provavelmente teria o contrato de COMPRA
 * correspondente também só na ZapSign, não na Fastcar ainda — importar os
 * dois em conjunto é decisão maior, não assumida aqui sem confirmar.
 */
require_once __DIR__ . '/zapsign.php';
require_once __DIR__ . '/oportunidades.php'; // criarVeiculoManualFrota()
require_once __DIR__ . '/documentos.php'; // salvarArquivoGeradoComoDocumento()

/**
 * Importa 1 documento da ZapSign como veículo cadastrado manualmente na
 * frota + contrato de compra já assinado anexado. Nunca importa o mesmo
 * `docToken` 2x (checagem por `contratos.zapsign_doc_token`, único —
 * `idx_contratos_zapsign_doc`) — reimportar por engano nunca duplica
 * cliente/oportunidade.
 *
 * @return array ['ok'=>bool, 'erro'=>?string, 'oportunidade_id'=>?int, 'cliente_id'=>?int]
 */
function zapsignImportarContratoComoVeiculoManual(
    string $docToken,
    string $vendedorNome,
    string $vendedorTelefone,
    string $marca,
    string $modelo,
    string $ano,
    string $placa,
    string $chassi,
    string $renavam,
    ?float $valorPago,
    string $dataAssinatura, // 'Y-m-d H:i:s' ou '' (usa agora)
    int $criadoPor,
    ?int $responsavelId = null
): array {
    $db = getDB();

    $jaImportado = $db->prepare('SELECT id FROM contratos WHERE zapsign_doc_token = ?');
    $jaImportado->execute([$docToken]);
    if ($jaImportado->fetchColumn()) {
        return ['ok' => false, 'erro' => 'Este documento já foi importado antes.', 'oportunidade_id' => null, 'cliente_id' => null];
    }

    try {
        $r = criarVeiculoManualFrota(
            $vendedorNome, $vendedorTelefone, $marca, $modelo, $ano, $placa, $chassi, $renavam,
            $valorPago, $criadoPor, $responsavelId
        );
    } catch (InvalidArgumentException $e) {
        return ['ok' => false, 'erro' => $e->getMessage(), 'oportunidade_id' => null, 'cliente_id' => null];
    }
    $oportunidadeId = $r['oportunidade_id'];
    $clienteId = $r['cliente_id'];

    // Baixa o PDF assinado de verdade da ZapSign — best-effort: se a URL já
    // expirou (temporária, ~60min) ou a chamada falhar, o veículo/contrato
    // ainda é criado (nunca trava a importação por causa disso), só fica
    // sem cópia visível — mesma disciplina de `zapsignSincronizarContrato()`.
    $driveFileId = '';
    $arquivoUrl = '';
    $conteudo = zapsignBaixarAssinado($docToken);
    if ($conteudo) {
        $tmp = tempnam(sys_get_temp_dir(), 'zapsign_import_') . '.pdf';
        file_put_contents($tmp, $conteudo);
        $stmtCli = $db->prepare('SELECT nome FROM clientes WHERE id = ?');
        $stmtCli->execute([$clienteId]);
        $nomeCliente = $stmtCli->fetchColumn() ?: "Cliente #{$clienteId}";
        $copia = salvarArquivoGeradoComoDocumento(
            $clienteId, (string)$nomeCliente, $tmp, "contrato_compra_importado_zapsign_{$oportunidadeId}.pdf",
            'application/pdf', 'contratos/' . $oportunidadeId
        );
        $driveFileId = $copia['drive_file_id'];
        $arquivoUrl = $copia['arquivo_url'];
        @unlink($tmp);
    }

    $assinadoEm = $dataAssinatura ?: date('Y-m-d H:i:s');
    $db->prepare('
        INSERT INTO contratos (oportunidade_id, tipo, nome, zapsign_doc_token, status, assinado_em, drive_file_id, arquivo_url, created_by, campos_json)
        VALUES (?, \'compra\', ?, ?, \'assinado\', ?, ?, ?, ?, ?)
    ')->execute([
        $oportunidadeId, 'Contrato importado da ZapSign (CRM antigo) — ' . $vendedorNome, $docToken,
        $assinadoEm, $driveFileId, $arquivoUrl, $criadoPor,
        json_encode(['origem' => 'importado_zapsign_crm_antigo', 'importado_em' => date('Y-m-d H:i:s')]),
    ]);

    // Mesma consistência que zapsignSincronizarContrato() já mantém pro
    // contrato gerado normalmente — checklist de fechamento (regra #7)
    // conta este documento como presente, mesmo a oportunidade já tendo
    // nascido em 'fechado' direto (criarVeiculoManualFrota() não passa por
    // mudarEtapa(), então não teria essa linha sozinha).
    if ($driveFileId || $arquivoUrl) {
        $db->prepare("
            INSERT INTO oportunidade_documentos (oportunidade_id, tipo, drive_file_id, arquivo_url, obrigatorio, enviado_pelo_cliente, updated_at)
            VALUES (?, 'contrato_compra', ?, ?, 1, 0, datetime('now','localtime'))
            ON CONFLICT(oportunidade_id, tipo) DO UPDATE SET
                drive_file_id = excluded.drive_file_id, arquivo_url = excluded.arquivo_url,
                updated_at = datetime('now','localtime')
        ")->execute([$oportunidadeId, $driveFileId, $arquivoUrl]);
        $db->prepare('UPDATE oportunidades SET contrato_assinado = 1 WHERE id = ?')->execute([$oportunidadeId]);
    }

    return ['ok' => true, 'erro' => null, 'oportunidade_id' => $oportunidadeId, 'cliente_id' => $clienteId];
}
