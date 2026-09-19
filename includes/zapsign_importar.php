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
 * **Compra**: reaproveita `criarVeiculoManualFrota()` (já existe, já
 * testada, mesmo caminho de "veículo que a Fastcar já tem mas nunca passou
 * pelo funil normal") — nome/telefone do vendedor vêm pré-preenchidos da
 * ZapSign, mas marca/modelo/ano/placa/chassi/renavam/valor a ZapSign NÃO
 * tem (ela só sabe o que foi digitado no PDF/formulário de assinatura, não
 * dados estruturados de veículo) — o admin digita esses na tela de
 * importação, exatamente como já faz pro cadastro manual normal de veículo.
 *
 * **Venda** (19/09/2026, "como vai saber se contrato venda ou compra" →
 * confirmado que a conta ZapSign tem os dois tipos misturados): a ZapSign
 * NUNCA diz sozinha se um documento é compra ou venda — não existe campo
 * estruturado pra isso, só o nome do documento (texto livre, não confiável
 * — regra #3, nunca inferir) — então quem decide é sempre o admin, olhando
 * o nome/signatário na tela e escolhendo o botão certo por documento.
 * Contrato de venda exige vincular a um veículo JÁ na frota
 * (`listarFrotaDisponivelParaVenda()`, mesma função que `admin/venda.php`
 * usa) — se o veículo ainda não foi importado (a compra dele também só
 * existe na ZapSign), precisa importar a COMPRA primeiro, depois voltar
 * aqui pra importar a venda vinculando a ele. Diferente da compra, a
 * importação de venda **nunca chama `mudarEtapaVenda()`** — grava
 * `etapa='vendido'` direto + histórico manual (mesma disciplina de
 * `criarVeiculoManualFrota()` pulando `mudarEtapa()`), de propósito pra
 * NUNCA disparar `finGerarReceitaVendaAssinatura()` — essa função assume
 * que a venda está acontecendo AGORA (lança entrada com
 * `data_vencimento=hoje` e parcelas começando no mês que vem), o que
 * geraria lançamentos financeiros FALSOS pra uma venda que na verdade já
 * aconteceu no passado (é exatamente o dado que estamos importando do
 * CRM antigo). Se quiser registrar o financeiro dessa venda histórica,
 * é lançamento manual à parte em Financeiro → Lançamentos.
 */
require_once __DIR__ . '/zapsign.php';
require_once __DIR__ . '/oportunidades.php'; // criarVeiculoManualFrota()
require_once __DIR__ . '/vendas.php'; // criarVenda()/veiculoDisponivelParaVenda()
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

/**
 * Importa 1 documento da ZapSign como negociação de VENDA (revenda) já
 * concluída, vinculada a um veículo que JÁ está na frota (só oferece os
 * mesmos veículos de `listarFrotaDisponivelParaVenda()`). Nunca importa o
 * mesmo `docToken` 2x, mesma checagem de `zapsignImportarContratoComoVeiculoManual()`.
 *
 * De propósito NÃO passa por `mudarEtapaVenda()` — grava `etapa='vendido'`
 * direto + histórico manual, pra nunca disparar `finGerarReceitaVendaAssinatura()`
 * com dados de "hoje" pra uma venda que já aconteceu no passado (ver nota
 * grande no topo do arquivo).
 *
 * @return array ['ok'=>bool, 'erro'=>?string, 'venda_id'=>?int]
 */
function zapsignImportarContratoVendaComoNegociacaoManual(
    string $docToken,
    int $oportunidadeId,
    string $compradorNome,
    string $compradorTelefone,
    ?float $precoVenda,
    string $dataAssinatura, // 'Y-m-d H:i:s' ou '' (usa hoje)
    int $criadoPor
): array {
    $db = getDB();

    $jaImportado = $db->prepare('SELECT id FROM contratos WHERE zapsign_doc_token = ?');
    $jaImportado->execute([$docToken]);
    if ($jaImportado->fetchColumn()) {
        return ['ok' => false, 'erro' => 'Este documento já foi importado antes.', 'venda_id' => null];
    }

    try {
        $vendaId = criarVenda($oportunidadeId, $criadoPor);
    } catch (RuntimeException $e) {
        return ['ok' => false, 'erro' => $e->getMessage(), 'venda_id' => null];
    }

    $telNorm = $compradorTelefone ? (normalizarTelefone($compradorTelefone) ?: $compradorTelefone) : '';
    $dataVenda = $dataAssinatura ? substr($dataAssinatura, 0, 10) : date('Y-m-d');
    $db->prepare("
        UPDATE vendas SET comprador_nome = ?, comprador_telefone = ?, preco_venda = ?,
               etapa = 'vendido', data_venda = ?, updated_at = datetime('now','localtime')
        WHERE id = ?
    ")->execute([clean($compradorNome), $telNorm, $precoVenda, $dataVenda, $vendaId]);

    $db->prepare("
        INSERT INTO venda_historico (venda_id, etapa_anterior, etapa_nova, responsavel_id, observacao)
        VALUES (?, 'negociacao', 'vendido', ?, 'Importado da ZapSign (CRM antigo) — contrato já assinado; nenhum lançamento financeiro gerado automaticamente (venda histórica, não de hoje)')
    ")->execute([$vendaId, $criadoPor]);

    // Baixa o PDF assinado — mesma âncora do Drive já usada pra contrato de
    // venda gerado normalmente (`zapsignSincronizarContrato()`): o cliente
    // ORIGINAL (vendedor que trouxe o veículo), nunca o comprador (que não
    // tem cadastro em `clientes`).
    $driveFileId = '';
    $arquivoUrl = '';
    $conteudo = zapsignBaixarAssinado($docToken);
    if ($conteudo) {
        $tmp = tempnam(sys_get_temp_dir(), 'zapsign_import_venda_') . '.pdf';
        file_put_contents($tmp, $conteudo);
        $stmtCli = $db->prepare('SELECT cl.id, cl.nome FROM oportunidades o JOIN clientes cl ON cl.id = o.cliente_id WHERE o.id = ?');
        $stmtCli->execute([$oportunidadeId]);
        $cli = $stmtCli->fetch();
        if ($cli) {
            $copia = salvarArquivoGeradoComoDocumento(
                (int)$cli['id'], (string)($cli['nome'] ?: "Cliente #{$cli['id']}"), $tmp,
                "contrato_venda_assinado_importado_zapsign_{$vendaId}.pdf",
                'application/pdf', 'contratos/' . $oportunidadeId
            );
            $driveFileId = $copia['drive_file_id'];
            $arquivoUrl = $copia['arquivo_url'];
        }
        @unlink($tmp);
    }

    $assinadoEm = $dataAssinatura ?: date('Y-m-d H:i:s');
    $db->prepare('
        INSERT INTO contratos (oportunidade_id, venda_id, tipo, nome, zapsign_doc_token, status, assinado_em, drive_file_id, arquivo_url, created_by, campos_json)
        VALUES (?, ?, \'venda\', ?, ?, \'assinado\', ?, ?, ?, ?, ?)
    ')->execute([
        $oportunidadeId, $vendaId, 'Contrato de venda importado da ZapSign (CRM antigo) — ' . $compradorNome,
        $docToken, $assinadoEm, $driveFileId, $arquivoUrl, $criadoPor,
        json_encode(['origem' => 'importado_zapsign_crm_antigo', 'importado_em' => date('Y-m-d H:i:s')]),
    ]);

    return ['ok' => true, 'erro' => null, 'venda_id' => $vendaId];
}
