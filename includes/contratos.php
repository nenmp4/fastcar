<?php
/**
 * includes/contratos.php — Orquestra o contrato-mestre de COMPRA: monta os
 * dados da oportunidade, gera o PDF (includes/contratos_pdf.php), manda
 * pra assinatura eletrônica (includes/zapsign.php) e, quando assinado,
 * sobe o PDF final pra pasta do cliente no Drive (includes/google_drive.php)
 * e marca como documento da pasta fechada (bloco 8, regra #7).
 *
 * ZapSign substituiu a Assinafy em 13/09/2026 — colunas `contratos.
 * assinafy_doc_id`/`assinafy_signer_id` foram renomeadas via
 * install/migrar.php pra `zapsign_doc_token`/`zapsign_signer_token`;
 * `assinafy_assignment_id` ficou sem uso (a ZapSign não tem esse conceito
 * separado, cria documento+signatário numa chamada só).
 *
 * Módulo de VENDA fica pra outra etapa (decisão do Jean) — não implementado.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/contratos_pdf.php';
require_once __DIR__ . '/zapsign.php';
require_once __DIR__ . '/google_drive.php';
require_once __DIR__ . '/documentos.php'; // garantirPastaDriveCliente()
require_once __DIR__ . '/vendas.php'; // mudarEtapaVenda() — auto-transição ao gerar/assinar contrato de venda
require_once __DIR__ . '/mail.php';
require_once __DIR__ . '/email_templates.php';

const CONTRATOS_STATUS_ZAPSIGN = [
    'signed'  => 'assinado',
    'refused' => 'recusado',
    'pending' => 'enviado',
];

function formatarDataExtensoPtBr(string $dataYmd): string {
    $meses = [1 => 'janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho',
              'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
    $ts = strtotime($dataYmd) ?: time();
    return date('d', $ts) . ' de ' . $meses[(int)date('n', $ts)] . ' de ' . date('Y', $ts);
}

/** Monta os campos do merge do contrato de compra a partir da oportunidade+cliente. */
function montarCamposContratoCompra(int $oportunidadeId): ?array {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT o.*, c.id AS cliente_id, c.nome AS cliente_nome, c.telefone, c.email, c.cpf, c.rg, c.cnh,
               c.nacionalidade, c.estado_civil, c.profissao, c.endereco
        FROM oportunidades o JOIN clientes c ON c.id = o.cliente_id
        WHERE o.id = ?
    ");
    $stmt->execute([$oportunidadeId]);
    $op = $stmt->fetch();
    if (!$op) return null;

    $percentual = ($op['valor_fipe_referencia'] && $op['valor_ofertado'])
        ? round((float)$op['valor_ofertado'] / (float)$op['valor_fipe_referencia'] * 100, 2)
        : 0.0;

    return [
        'vendedor_nome'                 => $op['cliente_nome'] ?: '',
        'vendedor_nacionalidade'        => $op['nacionalidade'] ?: 'brasileiro(a)',
        'vendedor_estado_civil'         => $op['estado_civil'] ?: '',
        'vendedor_profissao'            => $op['profissao'] ?: '',
        'vendedor_rg'                   => $op['rg'] ?: '',
        'vendedor_cpf'                  => $op['cpf'] ?: '',
        'vendedor_cnh'                  => $op['cnh'] ?: '',
        'vendedor_endereco'             => $op['endereco'] ?: '',
        'veiculo_marca'                 => $op['veiculo_marca'] ?: '',
        'veiculo_modelo'                => $op['veiculo_modelo'] ?: '',
        'veiculo_ano'                   => $op['veiculo_ano'] ?: '',
        'veiculo_placa'                 => $op['veiculo_placa'] ?: '',
        'veiculo_renavam'               => $op['veiculo_renavam'] ?: '',
        'veiculo_chassi'                => $op['veiculo_chassi'] ?: '',
        'valor_fipe_referencia'         => $op['valor_fipe_referencia'] !== null ? (float)$op['valor_fipe_referencia'] : null,
        'percentual_fipe'               => $percentual,
        'valor_pago_vendedor'           => $op['valor_ofertado'] !== null ? (float)$op['valor_ofertado'] : null,
        'banco_financiamento'           => $op['banco_financiamento'] ?: '',
        'contrato_financiamento_numero' => $op['contrato_financiamento_numero'] ?: '',
        'saldo_financiamento_atual'     => $op['saldo_financiamento_atual'] !== null ? (float)$op['saldo_financiamento_atual'] : null,
        'responsavel_registral'         => $op['cliente_nome'] ?: '',
        'terceiro_quitacao'             => $op['terceiro_quitacao'] ?: '',
        'data_entrega_posse'            => $op['data_entrega_posse'] ? date('d/m/Y', strtotime($op['data_entrega_posse'])) : date('d/m/Y'),
        'seguro_texto'                  => $op['seguro_texto'] ?: '',
        'encargos_texto'                => $op['encargos_texto'] ?: '',
        // Testemunhas são sempre da própria Fastcar (pedido do José/Jean,
        // 13/09/2026) — fixas em Configurações (admin/configuracoes.php),
        // não mais digitadas por oportunidade. oportunidades.testemunha1_*/
        // testemunha2_* ficam sem uso a partir daqui (não removidas do
        // schema — sem ganho real em reconstruir a tabela no SQLite só
        // pra isso, e nenhum contrato real chegou a usar esses campos).
        'testemunha1_nome'              => getConfig('testemunha1_nome') ?: '',
        'testemunha1_cpf'               => getConfig('testemunha1_cpf') ?: '',
        'testemunha2_nome'              => getConfig('testemunha2_nome') ?: '',
        'testemunha2_cpf'               => getConfig('testemunha2_cpf') ?: '',
        'data_extenso'                  => formatarDataExtensoPtBr(date('Y-m-d')),
        '_telefone'                     => $op['telefone'],
        '_email'                        => $op['email'] ?: '',
        '_cliente_id'                   => (int)$op['cliente_id'],
    ];
}

/**
 * Campos sem os quais o contrato não deveria nem ser gerado — "campos
 * essenciais... não poderão ficar em branco" (cláusula 27.2 do próprio
 * modelo). Retorna a lista de rótulos faltando (vazio = tudo ok).
 */
function verificarCamposObrigatoriosContrato(array $campos): array {
    $obrigatorios = [
        'vendedor_nome' => 'Nome do vendedor', 'vendedor_cpf' => 'CPF do vendedor',
        'vendedor_rg' => 'RG do vendedor', 'veiculo_marca' => 'Marca do veículo',
        'veiculo_modelo' => 'Modelo do veículo', 'valor_pago_vendedor' => 'Valor ofertado ao vendedor (bloco 6)',
    ];
    $faltando = [];
    foreach ($obrigatorios as $campo => $label) {
        if (empty($campos[$campo]) && $campos[$campo] !== 0.0) $faltando[] = $label;
    }
    return $faltando;
}

/**
 * Gera o PDF, sobe pra assinatura eletrônica e registra em `contratos`.
 * Retorna ['ok'=>bool, 'erro'=>?string, 'contrato_id'=>?int, 'sign_url'=>?string, 'aviso'=>?string].
 */
function gerarEEnviarContratoCompra(int $oportunidadeId, ?int $usuarioId): array {
    $campos = montarCamposContratoCompra($oportunidadeId);
    if (!$campos) return ['ok' => false, 'erro' => 'Oportunidade não encontrada.'];

    $faltando = verificarCamposObrigatoriosContrato($campos);
    if ($faltando) {
        return ['ok' => false, 'erro' => 'Faltam dados obrigatórios pra gerar o contrato: ' . implode(', ', $faltando) . '.'];
    }

    // Limite contratual de 25% da FIPE (cláusula 1.2) — nunca decide sozinho
    // se segue ou não, só avisa; a decisão de negociação é sempre do consultor.
    $avisos = [];
    if ($campos['percentual_fipe'] > 25) {
        $avisos[] = "Percentual pago ({$campos['percentual_fipe']}%) excede o limite contratual de 25% da FIPE — confira antes de enviar pra assinatura.";
    }
    // Cliente sem e-mail cadastrado (wizard antigo, ou cliente ainda não
    // confirmou a 1ª etapa) — a ZapSign segue mandando só por
    // telefone/WhatsApp, nunca trava o envio do contrato por causa disso,
    // só avisa pro consultor completar em admin/cliente_detalhe.php.
    if (!$campos['_email']) {
        $avisos[] = 'Cliente sem e-mail cadastrado — o contrato vai só pelo WhatsApp/telefone pra assinatura. Complete o e-mail em Clientes pra também mandar por e-mail.';
    }
    $aviso = $avisos ? implode(' ', $avisos) : null;

    $pdfPath = gerarPdfContratoCompra($campos);
    $nomeDoc = 'Contrato de Compra - ' . ($campos['vendedor_nome'] ?: "Oportunidade #{$oportunidadeId}");

    // Guarda uma cópia própria (Drive preferido, storage/uploads/ como
    // fallback — mesmo padrão de includes/documentos.php) ANTES de mandar
    // pra assinatura, pra dar pra visualizar o contrato no sistema
    // (admin/ver_contrato.php) mesmo enquanto ainda está esperando
    // assinatura — nunca dependeu disso pra decidir se segue com o envio.
    $nomeArquivoCopia = 'contrato_compra_' . $oportunidadeId . '_' . time() . '.pdf';
    $copia = salvarArquivoGeradoComoDocumento(
        $campos['_cliente_id'], $campos['vendedor_nome'], $pdfPath, $nomeArquivoCopia,
        'application/pdf', 'contratos/' . $oportunidadeId
    );

    // ZapSign cria documento + signatário numa chamada só (diferente da
    // Assinafy, que precisava de 3 chamadas separadas) — telefone e e-mail
    // são os canais de verificação/notificação quando existem (pedido do
    // José/Jean, 14/09/2026: "vamos enviar no email dele o contrato").
    $docRes = zapsignCriarDocumentoEAssinatura($pdfPath, $nomeDoc, $campos['vendedor_nome'], $campos['_telefone'], $campos['_email']);
    @unlink($pdfPath);
    if (isset($docRes['error'])) {
        return ['ok' => false, 'erro' => 'Falha ao enviar pra assinatura: ' . $docRes['error']];
    }

    $db = getDB();
    $db->prepare("
        INSERT INTO contratos
            (oportunidade_id, tipo, nome, campos_json, zapsign_doc_token, zapsign_signer_token, sign_url, status, drive_file_id, arquivo_url, created_by)
        VALUES (?, 'compra', ?, ?, ?, ?, ?, 'enviado', ?, ?, ?)
    ")->execute([
        $oportunidadeId, $nomeDoc, json_encode($campos), $docRes['doc_token'], $docRes['signer_token'],
        $docRes['sign_url'], $copia['drive_file_id'], $copia['arquivo_url'], $usuarioId,
    ]);

    // Aviso complementar por e-mail (16/09/2026, "cria todos os templates")
    // — a ZapSign já manda o link de assinatura de verdade por conta
    // própria (telefone/e-mail, includes/zapsign.php); este é só um
    // "está a caminho" com a cara da Fastcar, nunca compete com o link
    // oficial de assinatura. Best-effort, nunca pode travar a geração do
    // contrato (já foi criado/salvo acima, independente disso).
    if ($campos['_email']) {
        $corpoEmail = "<p>Olá, " . htmlspecialchars($campos['vendedor_nome'] ?: '', ENT_QUOTES) . "!</p>"
            . "<p>O contrato de compra do seu veículo (<strong>" . htmlspecialchars(trim($campos['veiculo_marca'] . ' ' . $campos['veiculo_modelo']), ENT_QUOTES) . "</strong>) "
            . "acaba de ser enviado pra assinatura eletrônica.</p>"
            . "<p>Você vai receber um link de assinatura da ZapSign, nossa plataforma parceira, por WhatsApp"
            . ($campos['_email'] ? ' e/ou e-mail' : '') . ". Basta seguir as instruções por lá pra assinar.</p>"
            . "<p>Qualquer dúvida, é só chamar a gente.</p>";
        enviarEmail($campos['_email'], 'Contrato enviado pra assinatura — Fastcar', emailLayout($corpoEmail), $campos['vendedor_nome'] ?: '');
    }

    return [
        'ok' => true,
        'contrato_id' => (int)$db->lastInsertId(),
        'sign_url' => $docRes['sign_url'],
        'aviso' => $aviso,
    ];
}

/**
 * Monta os campos do merge do contrato de VENDA a partir da venda +
 * oportunidade (dados do veículo/financiamento — mesma fonte de verdade do
 * contrato de compra, nunca duplicados em `vendas`). Ver includes/vendas.php
 * pro resto da lógica de negócio do módulo de vendas.
 */
function montarCamposContratoVenda(int $vendaId): ?array {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT v.*, o.id AS oportunidade_id, o.cliente_id, o.veiculo_marca, o.veiculo_modelo, o.veiculo_ano,
               o.veiculo_placa, o.veiculo_renavam, o.veiculo_chassi, o.banco_financiamento,
               o.contrato_financiamento_numero, o.saldo_financiamento_atual, o.valor_fipe_referencia
        FROM vendas v JOIN oportunidades o ON o.id = v.oportunidade_id
        WHERE v.id = ?
    ");
    $stmt->execute([$vendaId]);
    $v = $stmt->fetch();
    if (!$v) return null;

    return [
        'comprador_nome'                => $v['comprador_nome'] ?: '',
        'comprador_nacionalidade'       => $v['comprador_nacionalidade'] ?: 'brasileiro(a)',
        'comprador_estado_civil'        => $v['comprador_estado_civil'] ?: '',
        'comprador_profissao'           => $v['comprador_profissao'] ?: '',
        'comprador_rg'                  => $v['comprador_rg'] ?: '',
        'comprador_cpf'                 => $v['comprador_cpf'] ?: '',
        'comprador_cnh'                 => $v['comprador_cnh'] ?: '',
        'comprador_endereco'            => $v['comprador_endereco'] ?: '',
        'comprador_telefone'            => $v['comprador_telefone'] ?: '',
        'comprador_email'               => $v['comprador_email'] ?: '',
        'veiculo_marca'                 => $v['veiculo_marca'] ?: '',
        'veiculo_modelo'                => $v['veiculo_modelo'] ?: '',
        'veiculo_ano'                   => $v['veiculo_ano'] ?: '',
        'veiculo_placa'                 => $v['veiculo_placa'] ?: '',
        'veiculo_renavam'               => $v['veiculo_renavam'] ?: '',
        'veiculo_chassi'                => $v['veiculo_chassi'] ?: '',
        'km_entrega'                    => $v['km_entrega'] !== null ? (int)$v['km_entrega'] : null,
        'valor_fipe_referencia'         => $v['valor_fipe_referencia'] !== null ? (float)$v['valor_fipe_referencia'] : null,
        'preco_venda'                   => $v['preco_venda'] !== null ? (float)$v['preco_venda'] : null,
        'valor_pago_contratacao'        => $v['valor_pago_contratacao'] !== null ? (float)$v['valor_pago_contratacao'] : null,
        'forma_pagamento'               => $v['forma_pagamento'] ?: '',
        'saldo_preco_devido'            => $v['saldo_preco_devido'] !== null ? (float)$v['saldo_preco_devido'] : null,
        'banco_financiamento'           => $v['banco_financiamento'] ?: '',
        'contrato_financiamento_numero' => $v['contrato_financiamento_numero'] ?: '',
        'saldo_financiamento_atual'     => $v['saldo_financiamento_atual'] !== null ? (float)$v['saldo_financiamento_atual'] : null,
        'prazo_quitacao_meses'          => (int)($v['prazo_quitacao_meses'] ?: 24),
        'data_limite_quitacao'          => $v['data_limite_quitacao'] ? date('d/m/Y', strtotime($v['data_limite_quitacao'])) : '',
        'prestacao_contas_texto'        => $v['prestacao_contas_texto'] ?: '',
        'seguro_texto'                  => $v['seguro_texto'] ?: '',
        'ipva_responsavel_texto'        => $v['ipva_responsavel_texto'] ?: '',
        'multas_texto'                  => $v['multas_texto'] ?: '',
        'rastreador_texto'              => $v['rastreador_texto'] ?: '',
        'prazo_transferencia_dias'      => $v['prazo_transferencia_dias'] !== null ? (int)$v['prazo_transferencia_dias'] : null,
        'penalidade_atraso_texto'       => $v['penalidade_atraso_texto'] ?: '',
        'testemunha1_nome'              => getConfig('testemunha1_nome') ?: '',
        'testemunha1_cpf'               => getConfig('testemunha1_cpf') ?: '',
        'testemunha2_nome'              => getConfig('testemunha2_nome') ?: '',
        'testemunha2_cpf'               => getConfig('testemunha2_cpf') ?: '',
        'data_extenso'                  => formatarDataExtensoPtBr(date('Y-m-d')),
        '_venda_id'                     => (int)$v['id'],
        '_oportunidade_id'              => (int)$v['oportunidade_id'],
        '_cliente_id'                   => (int)$v['cliente_id'],
        '_telefone'                     => $v['comprador_telefone'] ?: '',
        '_email'                        => $v['comprador_email'] ?: '',
    ];
}

/**
 * Campos sem os quais o contrato de venda não deveria ser gerado — mesmo
 * espírito de verificarCamposObrigatoriosContrato() (compra), adaptado pro
 * lado do COMPRADOR.
 */
function verificarCamposObrigatoriosContratoVenda(array $campos): array {
    $obrigatorios = [
        'comprador_nome' => 'Nome do comprador', 'comprador_cpf' => 'CPF do comprador',
        'comprador_rg' => 'RG do comprador', 'preco_venda' => 'Preço ajustado (bloco de negociação)',
    ];
    $faltando = [];
    foreach ($obrigatorios as $campo => $label) {
        if (empty($campos[$campo]) && $campos[$campo] !== 0.0) $faltando[] = $label;
    }
    return $faltando;
}

/**
 * Gera o PDF do contrato de venda, manda pra assinatura eletrônica e
 * registra em `contratos` (tipo='venda', venda_id preenchido — desambigua
 * qual negociação de revenda gerou esse contrato específico). Mesmo padrão
 * de gerarEEnviarContratoCompra(): nunca decide sozinho se segue, só avisa.
 */
function gerarEEnviarContratoVenda(int $vendaId, ?int $usuarioId): array {
    $campos = montarCamposContratoVenda($vendaId);
    if (!$campos) return ['ok' => false, 'erro' => 'Venda não encontrada.'];

    $faltando = verificarCamposObrigatoriosContratoVenda($campos);
    if ($faltando) {
        return ['ok' => false, 'erro' => 'Faltam dados obrigatórios pra gerar o contrato: ' . implode(', ', $faltando) . '.'];
    }

    $aviso = !$campos['_email']
        ? 'Comprador sem e-mail cadastrado — o contrato vai só pelo WhatsApp/telefone pra assinatura.'
        : null;

    $pdfPath = gerarPdfContratoVenda($campos);
    $nomeDoc = 'Contrato de Venda - ' . ($campos['comprador_nome'] ?: "Venda #{$vendaId}");

    // Mesmo padrão do contrato de compra: cópia própria salva ANTES de
    // mandar pra assinatura, pra dar pra visualizar no sistema mesmo
    // enquanto espera assinatura (admin/ver_contrato.php, já genérico —
    // funciona pra compra e venda sem mudar nada). Âncora do Drive segue
    // sendo o cliente original (VENDEDOR que trouxe o veículo pra Fastcar)
    // — não existe cadastro em `clientes` pro COMPRADOR da revenda (ver
    // includes/vendas.php), então a pasta do veículo/processo continua
    // sendo a mesma dos documentos de compra desse mesmo carro.
    $nomeArquivoCopia = 'contrato_venda_' . $vendaId . '_' . time() . '.pdf';
    $copia = salvarArquivoGeradoComoDocumento(
        $campos['_cliente_id'], $campos['comprador_nome'], $pdfPath, $nomeArquivoCopia,
        'application/pdf', 'contratos/' . $campos['_oportunidade_id']
    );

    $docRes = zapsignCriarDocumentoEAssinatura($pdfPath, $nomeDoc, $campos['comprador_nome'], $campos['_telefone'], $campos['_email']);
    @unlink($pdfPath);
    if (isset($docRes['error'])) {
        return ['ok' => false, 'erro' => 'Falha ao enviar pra assinatura: ' . $docRes['error']];
    }

    $db = getDB();
    $db->prepare("
        INSERT INTO contratos
            (oportunidade_id, venda_id, tipo, nome, campos_json, zapsign_doc_token, zapsign_signer_token, sign_url, status, drive_file_id, arquivo_url, created_by)
        VALUES (?, ?, 'venda', ?, ?, ?, ?, ?, 'enviado', ?, ?, ?)
    ")->execute([
        $campos['_oportunidade_id'], $vendaId, $nomeDoc, json_encode($campos), $docRes['doc_token'], $docRes['signer_token'],
        $docRes['sign_url'], $copia['drive_file_id'], $copia['arquivo_url'], $usuarioId,
    ]);
    $contratoId = (int)$db->lastInsertId();

    // Diferente da compra, enviar o contrato pra assinatura já move a
    // negociação pra 'contrato_enviado' (só na 1ª vez — reenvio/correção
    // gerando o contrato de novo não regride nem duplica a transição) —
    // não existe um checklist de documentos bloqueando isso (regra #7 é
    // só do funil de compra); fica tudo registrado no histórico de
    // `contratos` de qualquer forma, igual compra.
    $etapaAtualVenda = $db->prepare("SELECT etapa FROM vendas WHERE id = ?");
    $etapaAtualVenda->execute([$vendaId]);
    if ($etapaAtualVenda->fetchColumn() === 'negociacao') {
        mudarEtapaVenda($vendaId, 'contrato_enviado', $usuarioId, 'Contrato gerado e enviado pra assinatura');
    }

    return [
        'ok' => true,
        'contrato_id' => $contratoId,
        'sign_url' => $docRes['sign_url'],
        'aviso' => $aviso,
    ];
}

/**
 * Consulta o status do contrato na ZapSign e sincroniza — usado pelo
 * webhook (api/zapsign_webhook.php) e pelo polling de fallback
 * (cron/zapsign_sync.php). Quando assinado, baixa o PDF final e sobe pra
 * pasta do cliente no Drive, marcando como documento da pasta fechada
 * (bloco 8) — o checklist de fechamento (regra #7) passa a contar com ele.
 */
function zapsignSincronizarContrato(int $contratoId): void {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM contratos WHERE id = ?");
    $stmt->execute([$contratoId]);
    $c = $stmt->fetch();
    if (!$c || !$c['zapsign_doc_token']) return;

    $statusRes = zapsignStatusDocumento($c['zapsign_doc_token']);
    if (isset($statusRes['error'])) return;

    $novoStatus = CONTRATOS_STATUS_ZAPSIGN[$statusRes['status']] ?? $c['status'];

    // Só sai cedo se REALMENTE não tem nada pra fazer: status igual E (se já
    // assinado) já garantiu uma cópia de verdade. Sem o segundo checar, um
    // download que falhasse uma vez (link temporário expirado, rede) nunca
    // mais tentaria de novo — status já teria virado 'assinado' e o
    // early-return bloquearia toda tentativa futura, ficando pra sempre sem
    // cópia nenhuma do contrato assinado (bug real, achado testando).
    $jaTemCopiaAssinada = $c['status'] === 'assinado' && ($c['drive_file_id'] || $c['arquivo_url']);
    if ($novoStatus === $c['status'] && $jaTemCopiaAssinada) return;

    $driveFileId = $c['drive_file_id'];
    $arquivoUrl = $c['arquivo_url'];

    // Tenta baixar/guardar a cópia assinada sempre que o status for
    // 'assinado' e ainda não tiver copia — cobre tanto a transição normal
    // quanto o retry de uma tentativa anterior que falhou.
    if ($novoStatus === 'assinado' && !$jaTemCopiaAssinada) {
        $conteudo = zapsignBaixarAssinado($c['zapsign_doc_token']);
        if ($conteudo) {
            $tmp = tempnam(sys_get_temp_dir(), 'contrato_assinado_') . '.pdf';
            file_put_contents($tmp, $conteudo);

            // Âncora do Drive é sempre o cliente original (VENDEDOR que
            // trouxe o veículo) — compra E venda, mesmo veículo/processo,
            // mesma pasta (ver gerarEEnviarContratoVenda() pro motivo: não
            // existe cadastro em `clientes` pro COMPRADOR da revenda).
            $stmtCli = $db->prepare("
                SELECT cl.id, cl.nome FROM oportunidades o JOIN clientes cl ON cl.id = o.cliente_id WHERE o.id = ?
            ");
            $stmtCli->execute([$c['oportunidade_id']]);
            $cli = $stmtCli->fetch();

            $ehVenda = $c['tipo'] === 'venda';
            $nomeArquivo = ($ehVenda ? 'contrato_venda_assinado_' . $c['venda_id'] : 'contrato_compra_assinado_' . $c['oportunidade_id']) . '.pdf';

            if ($cli) {
                $copia = salvarArquivoGeradoComoDocumento(
                    (int)$cli['id'], $cli['nome'] ?: "Cliente #{$cli['id']}", $tmp, $nomeArquivo,
                    'application/pdf', 'contratos/' . $c['oportunidade_id']
                );
                if ($copia['drive_file_id'] || $copia['arquivo_url']) {
                    $driveFileId = $copia['drive_file_id'];
                    $arquivoUrl  = $copia['arquivo_url'];
                }
            }
            @unlink($tmp);

            if ($ehVenda) {
                // Módulo de venda não tem checklist de fechamento (regra #7
                // é só do funil de compra) — assinatura do comprador marca
                // a negociação como concluída direto, só se ainda estava
                // 'contrato_enviado' (nunca força de volta se alguém já
                // cancelou a negociação manualmente nesse meio-tempo).
                if ($driveFileId || $arquivoUrl) {
                    $etapaVendaAtual = $db->prepare("SELECT etapa FROM vendas WHERE id = ?");
                    $etapaVendaAtual->execute([$c['venda_id']]);
                    if ($etapaVendaAtual->fetchColumn() === 'contrato_enviado') {
                        mudarEtapaVenda((int)$c['venda_id'], 'vendido', null, 'Contrato assinado pelo comprador (ZapSign)');
                    }
                }
            } elseif ($driveFileId || $arquivoUrl) {
                // A pasta fechada (bloco 8, regra #7) só conta esse
                // documento como presente aqui — na geração (ainda sem
                // assinar) de propósito NÃO grava em oportunidade_documentos,
                // senão o checklist de fechamento passaria mesmo sem
                // assinatura.
                $db->prepare("
                    INSERT INTO oportunidade_documentos (oportunidade_id, tipo, drive_file_id, arquivo_url, obrigatorio, enviado_pelo_cliente, updated_at)
                    VALUES (?, 'contrato_compra', ?, ?, 1, 0, datetime('now','localtime'))
                    ON CONFLICT(oportunidade_id, tipo) DO UPDATE SET
                        drive_file_id = excluded.drive_file_id, arquivo_url = excluded.arquivo_url,
                        updated_at = datetime('now','localtime')
                ")->execute([$c['oportunidade_id'], $driveFileId, $arquivoUrl]);

                $db->prepare("UPDATE oportunidades SET contrato_assinado = 1 WHERE id = ?")->execute([$c['oportunidade_id']]);
            }
        }
    }

    // assinado_em só grava na PRIMEIRA vez que o status vira 'assinado' de
    // verdade (nunca sobrescreve numa sincronização seguinte, ex: retry
    // baixando a cópia) — é "quando o cliente assinou", não "última vez
    // que sincronizamos". Pedido: mostrar "que dia ele assina contrato"
    // no detalhe do cliente (admin/cliente_detalhe.php).
    $assinadoEm = ($novoStatus === 'assinado' && !$c['assinado_em']) ? date('Y-m-d H:i:s') : $c['assinado_em'];

    $db->prepare("
        UPDATE contratos SET status = ?, drive_file_id = ?, arquivo_url = ?, assinado_em = ?, updated_at = datetime('now','localtime') WHERE id = ?
    ")->execute([$novoStatus, $driveFileId, $arquivoUrl, $assinadoEm, $contratoId]);
}
