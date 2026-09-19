<?php
/**
 * includes/importar_crm_antigo.php — 19/09/2026, "zapasine tem monte
 * contrato do crm anti será possivel puxar concliar" → export completo do
 * CRM antigo (Yaqar/IACAR, mesmo CNPJ da Fastcar — confirmado via
 * distribution_config.csv) compartilhado no Google Drive com a service
 * account do Fastcar (pasta com 2 subpastas: tabelas/, 14 CSVs, e
 * arquivos/, os documentos reais organizados por UUID).
 *
 * Arquivo PRÓPRIO de propósito (mesmo raciocínio de includes/zapsign_importar.php):
 * é IMPORTAÇÃO de dado histórico, não operação normal do sistema — nunca
 * chama mudarEtapa()/mudarEtapaVenda() pras transições que criam a
 * oportunidade/venda já num estado terminal (evita disparar automação
 * pensada pra "isso está acontecendo agora", tipo notificação de lead
 * novo ou finGerarReceitaVendaAssinatura() gerando lançamento financeiro
 * com data de hoje pra uma venda que já aconteceu há meses) — reaproveita
 * criarVeiculoManualFrota()/criarVenda()/salvarArquivoGeradoComoDocumento()
 * sem mudar nenhuma delas, só grava histórico manual junto, mesma
 * disciplina de auditoria (regra #6) que o resto do projeto já segue.
 *
 * Escopo confirmado com o usuário: (1) clients.csv → frota (clientes já
 * convertidos, negócio de compra já fechado); (2) sales.csv → vendas
 * (revenda já feita); (3) leads.csv só os "won" (cobertos por clients.csv,
 * pulados aqui pra não duplicar) e "lost"/"no_interest" como oportunidade
 * já encerrada — os outros ~835 (qualifying/new/return_scheduled/qualified)
 * são funil de tráfego pago morto, de propósito NUNCA importados (encheria
 * o funil ativo de lead velho sem responsável nem resposta recente).
 */
require_once __DIR__ . '/google_drive.php';
require_once __DIR__ . '/oportunidades.php'; // criarVeiculoManualFrota()
require_once __DIR__ . '/vendas.php';        // criarVenda()/veiculoDisponivelParaVenda()
require_once __DIR__ . '/documentos.php';    // salvarArquivoGeradoComoDocumento()

// ---------------------------------------------------------------------
// Drive: autenticação + leitura de pasta/CSV (só leitura — a pasta é do
// usuário, compartilhada como Leitor com a service account, nunca escrita
// nem apagada por este script)
// ---------------------------------------------------------------------

function crmAntigoAutenticarDrive(): GoogleDrive {
    $drive = new GoogleDrive();
    if (!$drive->hasCredentials() || !$drive->authenticate()) {
        throw new RuntimeException('Credencial do Google Drive não configurada ou inválida (Configurações → Google Drive).');
    }
    return $drive;
}

/** Acha uma subpasta pelo nome exato (case-insensitive) dentro de $parentId. */
function crmAntigoAcharSubpasta(GoogleDrive $drive, string $parentId, string $nome): ?string {
    foreach ($drive->list($parentId) as $item) {
        if (strcasecmp((string)($item['name'] ?? ''), $nome) === 0) {
            return (string)$item['id'];
        }
    }
    return null;
}

/** Acha um arquivo pelo nome exato dentro de $folderId (não recursivo). */
function crmAntigoAcharArquivo(GoogleDrive $drive, string $folderId, string $nomeArquivo): ?string {
    foreach ($drive->list($folderId) as $item) {
        if (strcasecmp((string)($item['name'] ?? ''), $nomeArquivo) === 0) {
            return (string)$item['id'];
        }
    }
    return null;
}

/**
 * Parser de CSV tolerante — mesmo formato do export Supabase (UTF-8,
 * separador vírgula, aspas duplas pra campo com vírgula/quebra de linha).
 * Retorna array de linhas associativas (chave = cabeçalho, valor = string
 * sempre — conversão de tipo é responsabilidade de quem consome).
 */
function crmAntigoParsearCsv(string $conteudo): array {
    $conteudo = preg_replace('/^\xEF\xBB\xBF/', '', $conteudo); // remove BOM se tiver
    $handle = fopen('php://temp', 'r+b');
    fwrite($handle, $conteudo);
    rewind($handle);
    $header = fgetcsv($handle);
    if (!$header) { fclose($handle); return []; }
    $header = array_map('trim', $header);
    $linhas = [];
    while (($crua = fgetcsv($handle)) !== false) {
        if (count($crua) === 1 && ($crua[0] === null || $crua[0] === '')) continue;
        $linha = [];
        foreach ($header as $i => $coluna) {
            $linha[$coluna] = trim((string)($crua[$i] ?? ''));
        }
        $linhas[] = $linha;
    }
    fclose($handle);
    return $linhas;
}

/** Baixa e faz parse de 1 CSV da pasta tabelas/. Lança se o arquivo não existir. */
function crmAntigoLerCsv(GoogleDrive $drive, string $tabelasFolderId, string $nomeArquivo): array {
    $fileId = crmAntigoAcharArquivo($drive, $tabelasFolderId, $nomeArquivo);
    if (!$fileId) {
        throw new RuntimeException("Arquivo \"{$nomeArquivo}\" não encontrado na pasta tabelas/ compartilhada.");
    }
    $baixado = $drive->download($fileId);
    if (!$baixado) {
        throw new RuntimeException("Falha ao baixar \"{$nomeArquivo}\" do Drive.");
    }
    return crmAntigoParsearCsv($baixado['content']);
}

/**
 * Baixa 1 documento de arquivos/ pelo caminho relativo (mesmo valor da
 * coluna storage_path do CSV antigo, ex: "073dc4be-.../contracts/x.pdf").
 * Anda pasta por pasta (nunca assume 1 nível fixo — o export pode ter
 * profundidade diferente pra client vs sale) e loga no debug quando não
 * acha, em vez de travar a importação inteira por causa de 1 arquivo —
 * mesmo padrão de logDiagnosticoMidiaZapi()/whatsapp_contato_debug.log já
 * usado no resto do projeto pra formato de payload externo nunca 100%
 * confirmado.
 * @return array{content:string,mime:string,name:string}|null
 */
function crmAntigoBaixarArquivoPorCaminho(GoogleDrive $drive, string $arquivosFolderId, string $storagePath): ?array {
    $segmentos = array_values(array_filter(explode('/', trim($storagePath, '/')), fn($s) => $s !== ''));
    if (!$segmentos) return null;

    $atual = $arquivosFolderId;
    $nomeArquivo = array_pop($segmentos);
    foreach ($segmentos as $pasta) {
        $proximo = crmAntigoAcharSubpasta($drive, $atual, $pasta);
        if (!$proximo) {
            crmAntigoLogDiagnostico("Subpasta não encontrada: \"{$pasta}\" (caminho completo: {$storagePath})");
            return null;
        }
        $atual = $proximo;
    }
    $fileId = crmAntigoAcharArquivo($drive, $atual, $nomeArquivo);
    if (!$fileId) {
        crmAntigoLogDiagnostico("Arquivo não encontrado: \"{$nomeArquivo}\" (caminho completo: {$storagePath})");
        return null;
    }
    return $drive->download($fileId) ?: null;
}

function crmAntigoLogDiagnostico(string $mensagem): void {
    $dir = __DIR__ . '/../storage/logs';
    @mkdir($dir, 0755, true);
    @file_put_contents(
        $dir . '/importar_crm_antigo_debug.log',
        '[' . date('Y-m-d H:i:s') . "] {$mensagem}\n",
        FILE_APPEND
    );
}

// ---------------------------------------------------------------------
// Parsing de campo (formatos do export antigo → tipos do Fastcar)
// ---------------------------------------------------------------------

/** "R$ 48.302,00" / "48302.00" / "" → float|null — nunca inventa 0 pra vazio. */
function crmAntigoParseMoeda(?string $valor): ?float {
    $valor = trim((string)$valor);
    if ($valor === '') return null;
    // "R$ 48.302,00" (formato BRL, milhar com ponto, decimal com vírgula)
    if (preg_match('/,\d{2}$/', $valor)) {
        $valor = str_replace('.', '', $valor);
        $valor = str_replace(',', '.', $valor);
    }
    $valor = preg_replace('/[^0-9.\-]/', '', $valor);
    if ($valor === '' || $valor === '-') return null;
    return (float)$valor;
}

function crmAntigoParseInt(?string $valor): ?int {
    $valor = trim((string)$valor);
    if ($valor === '') return null;
    if (!preg_match('/^-?\d+$/', $valor)) return null;
    return (int)$valor;
}

/** "2026-06-22 17:55:07.328551+00" / "2026-06-22" / "" → "Y-m-d"|''. */
function crmAntigoParseData(?string $valor): string {
    $valor = trim((string)$valor);
    if ($valor === '') return '';
    return substr($valor, 0, 10);
}

/** "BRAND/MODEL LONGO" → ['marca'=>'BRAND','modelo'=>'MODEL LONGO'] (best-effort, nunca inventa). */
function crmAntigoSepararMarcaModelo(string $brandModel): array {
    $brandModel = trim($brandModel);
    if ($brandModel === '') return ['marca' => '', 'modelo' => ''];
    if (str_contains($brandModel, '/')) {
        [$marca, $modelo] = explode('/', $brandModel, 2);
        return ['marca' => trim($marca), 'modelo' => trim($modelo)];
    }
    return ['marca' => '', 'modelo' => $brandModel];
}

function crmAntigoMontarEndereco(array $linha, string $prefixo = 'address_'): string {
    $partes = array_filter([
        trim(($linha["{$prefixo}street"] ?? '') . ' ' . ($linha["{$prefixo}number"] ?? '')),
        trim((string)($linha["{$prefixo}complement"] ?? '')),
        trim((string)($linha["{$prefixo}neighborhood"] ?? '')),
    ], fn($p) => $p !== '');
    $cepBruto = (string)($linha["{$prefixo}zip"] ?? '');
    $cep = $cepBruto !== '' ? 'CEP ' . $cepBruto : '';
    if ($cep !== '') $partes[] = $cep;
    return implode(', ', $partes);
}

// ---------------------------------------------------------------------
// Dedup contra o que já existe em produção
// ---------------------------------------------------------------------

/** cliente_id já cadastrado com esse telefone, ou null. */
function crmAntigoClienteExistentePorTelefone(string $telefone): ?int {
    $telNorm = normalizarTelefone($telefone);
    if (!$telNorm) return null;
    $db = getDB();
    $stmt = $db->prepare('SELECT id FROM clientes WHERE telefone = ?');
    $stmt->execute([$telNorm]);
    $id = $stmt->fetchColumn();
    return $id !== false ? (int)$id : null;
}

/** true se esse zapsign_doc_token já virou uma linha em `contratos` — nunca reimporta. */
function crmAntigoContratoJaImportado(string $zapsignDocToken): bool {
    $zapsignDocToken = trim($zapsignDocToken);
    if ($zapsignDocToken === '') return false;
    $db = getDB();
    $stmt = $db->prepare('SELECT 1 FROM contratos WHERE zapsign_doc_token = ?');
    $stmt->execute([$zapsignDocToken]);
    return (bool)$stmt->fetchColumn();
}

/**
 * Trava de idempotência por ID antigo (clients.csv.id/leads.csv.id) — nem
 * todo cliente tem zapsign_doc_token preenchido (comum em cadastros que
 * nunca chegaram a assinar), então o dedup por token sozinho não bastava:
 * rodar o script --confirmar 2x (ex: reiniciar depois de travar no meio)
 * reaproveitaria o CLIENTE (por telefone) mas criaria uma OPORTUNIDADE
 * duplicada pra ele. Mesmo padrão config.chave_id já usado no resto do
 * projeto (config.alerta_atraso_{id}, config.reeng_sent_{telefone}).
 */
function crmAntigoJaImportadoPorIdAntigo(string $tabela, string $idAntigo): bool {
    return (bool)getConfig("crm_antigo_importado_{$tabela}_{$idAntigo}");
}

function crmAntigoMarcarImportadoPorIdAntigo(string $tabela, string $idAntigo, int $oportunidadeId): void {
    setConfig("crm_antigo_importado_{$tabela}_{$idAntigo}", (string)$oportunidadeId);
}

/**
 * Mapeia telefone de vendedor do CRM antigo (sellers.csv) pro
 * usuarios.id atual — por TELEFONE normalizado, nunca por nome (nome
 * digitado à mão diverge fácil: "Rafael Rocha Bueno" vs "Rafael"). Sem
 * match, retorna null — nunca cria usuário novo nem chuta um id, fica
 * pro admin decidir manualmente depois (mesma regra #3 do projeto).
 */
function crmAntigoMapearVendedorPorTelefone(string $telefoneAntigo): ?int {
    $telNorm = normalizarTelefone($telefoneAntigo);
    if (!$telNorm) return null;
    $db = getDB();
    $stmt = $db->prepare("SELECT id, whatsapp FROM usuarios WHERE whatsapp != ''");
    $stmt->execute();
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $u) {
        if (normalizarTelefone((string)$u['whatsapp']) === $telNorm) {
            return (int)$u['id'];
        }
    }
    return null;
}

// ---------------------------------------------------------------------
// Fase 1 — clients.csv → frota (cliente + oportunidade já fechada)
// ---------------------------------------------------------------------

/**
 * Importa 1 linha de clients.csv como cliente+oportunidade já fechada na
 * frota — reaproveita criarVeiculoManualFrota() (já existe/testada) pra
 * criação básica, depois complementa com os campos ricos que só o CRM
 * antigo tinha (CPF/RG/endereço/nacionalidade/estado civil/profissão/
 * e-mail/dados de financiamento) via UPDATE direto — fill-if-empty pros
 * campos do cliente (pode já ter sido preenchido por outra via), sempre
 * sobrescreve os campos da oportunidade recém-criada (a data/valor real
 * do negócio histórico é mais confiável que o "hoje"/valor que
 * criarVeiculoManualFrota() assume por padrão).
 *
 * Dado sem equivalente direto no schema atual (custos de avaliação/
 * despachante, dados de PIX pro pagamento, saldo devedor detalhado) NUNCA
 * é descartado silenciosamente — vai pro observação do
 * oportunidade_historico, texto legível, pra quem revisar depois não
 * perder a informação só porque não tinha coluna própria pra ela.
 *
 * $telefoneVendedorAntigo já vem RESOLVIDO pelo chamador (CLI script) —
 * assigned_seller_id no CSV é um UUID do vendedor antigo, não telefone;
 * quem sabe converter UUID→telefone é sellers.csv, que esta função não
 * recebe (mantém a função só dependente do que ela realmente processa).
 *
 * @return array{ok:bool,motivo:?string,cliente_id:?int,oportunidade_id:?int,ja_existia:bool}
 */
function crmAntigoImportarCliente(array $linha, GoogleDrive $drive, string $arquivosFolderId, array $documentosDoCliente, int $criadoPor, ?string $telefoneVendedorAntigo = null): array {
    $telefone = (string)($linha['phone'] ?? '');
    $nome = (string)($linha['full_name'] ?? '');
    $zapsignToken = (string)($linha['zapsign_doc_token'] ?? '');
    $idAntigo = (string)($linha['id'] ?? '');

    if (!$telefone) {
        return ['ok' => false, 'motivo' => 'Sem telefone no CSV — não dá pra criar cliente sem chave.', 'cliente_id' => null, 'oportunidade_id' => null, 'ja_existia' => false];
    }
    if ($idAntigo && crmAntigoJaImportadoPorIdAntigo('clients', $idAntigo)) {
        return ['ok' => false, 'motivo' => 'Esta linha (id do CRM antigo) já foi importada numa rodada anterior deste script.', 'cliente_id' => null, 'oportunidade_id' => null, 'ja_existia' => true];
    }
    if ($zapsignToken && crmAntigoContratoJaImportado($zapsignToken)) {
        return ['ok' => false, 'motivo' => 'zapsign_doc_token já importado antes (provavelmente pelo import direto da ZapSign).', 'cliente_id' => null, 'oportunidade_id' => null, 'ja_existia' => true];
    }

    $vd = crmAntigoSepararMarcaModelo((string)($linha['vehicle_brand_model'] ?? ''));
    $responsavelId = $telefoneVendedorAntigo ? crmAntigoMapearVendedorPorTelefone($telefoneVendedorAntigo) : null;

    $r = criarVeiculoManualFrota(
        $nome,
        $telefone,
        $vd['marca'],
        $vd['modelo'],
        (string)($linha['vehicle_year_model'] ?? ''),
        (string)($linha['vehicle_plate'] ?? ''),
        (string)($linha['vehicle_chassis'] ?? ''),
        (string)($linha['vehicle_renavam'] ?? ''),
        crmAntigoParseMoeda($linha['payment_to_client'] ?? null),
        $criadoPor,
        $responsavelId
    );
    $clienteId = $r['cliente_id'];
    $oportunidadeId = $r['oportunidade_id'];

    $db = getDB();

    // Fill-if-empty nos campos do cliente — nunca sobrescreve o que já
    // existia (mesma disciplina do resto do projeto).
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
        clean((string)($linha['cpf'] ?? '')),
        clean((string)($linha['rg'] ?? '')),
        clean((string)($linha['email'] ?? '')),
        clean((string)($linha['nationality'] ?? '')),
        clean((string)($linha['marital_status'] ?? '')),
        clean((string)($linha['profession'] ?? '')),
        clean(crmAntigoMontarEndereco($linha)),
        clean((string)($linha['address_city'] ?? '')),
        clean((string)($linha['address_state'] ?? '')),
        $clienteId,
    ]);

    // Sobrescreve os campos da oportunidade que a função genérica só
    // aproxima (data de hoje) com o dado histórico real do CRM antigo.
    $dataCompra = crmAntigoParseData($linha['contract_date'] ?? '') ?: crmAntigoParseData($linha['created_at'] ?? '');
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
        clean((string)($linha['financing_bank'] ?? '')),
        clean((string)($linha['financing_contract_number'] ?? '')),
        crmAntigoParseMoeda($linha['financing_installment_value'] ?? null),
        crmAntigoParseInt($linha['financing_remaining_installments'] ?? null),
        crmAntigoParseInt($linha['financing_overdue_installments'] ?? null) ?? 0,
        $dataCompra,
        $oportunidadeId,
    ]);

    // Dado sem coluna própria no schema atual — nunca descartado
    // silenciosamente, vai pro histórico em texto legível.
    $extras = [];
    foreach ([
        'financing_total_amount' => 'Valor total do financiamento',
        'vehicle_debt_amount' => 'Débito do veículo',
        'pix_bank' => 'Banco do PIX',
        'pix_key' => 'Chave PIX',
        'pix_holder_name' => 'Titular do PIX',
        'cost_evaluation' => 'Custo de avaliação',
        'cost_inspection' => 'Custo de vistoria',
        'cost_despachante' => 'Custo de despachante',
    ] as $campo => $rotulo) {
        $v = trim((string)($linha[$campo] ?? ''));
        if ($v !== '') $extras[] = "{$rotulo}: {$v}";
    }
    $observacao = 'Importado do CRM antigo (Yaqar/IACAR), cliente/negociação já fechada na época.';
    if ($extras) $observacao .= ' Dados extras sem campo correspondente: ' . implode(' | ', $extras) . '.';
    $db->prepare("
        INSERT INTO oportunidade_historico (oportunidade_id, etapa_anterior, etapa_nova, responsavel_id, observacao)
        VALUES (?, 'fechado', 'fechado', ?, ?)
    ")->execute([$oportunidadeId, $responsavelId, $observacao]);

    // Documentos reais — best-effort, nunca trava a importação por causa
    // de 1 arquivo que não baixou (mesma disciplina do resto do projeto:
    // ZapSign/mídia do WhatsApp também nunca bloqueiam o fluxo principal).
    $mapaTipoDoc = [
        'cnh' => 'cnh',
        'proof_of_address' => 'comprovante_endereco',
        'financing_contract' => 'contrato_financiamento',
        'vehicle_document' => 'crlv',
        'contract' => 'contrato_compra',
    ];
    foreach ($documentosDoCliente as $doc) {
        $tipoOriginal = (string)($doc['type'] ?? '');
        $tipoFastcar = $mapaTipoDoc[$tipoOriginal] ?? null;
        if (!$tipoFastcar) continue;
        $baixado = crmAntigoBaixarArquivoPorCaminho($drive, $arquivosFolderId, (string)($doc['storage_path'] ?? ''));
        if (!$baixado) continue;
        $tmp = tempnam(sys_get_temp_dir(), 'crm_antigo_') . '_' . preg_replace('/[^a-zA-Z0-9.]/', '_', $baixado['name']);
        file_put_contents($tmp, $baixado['content']);
        $copia = salvarArquivoGeradoComoDocumento($clienteId, $nome ?: "Cliente #{$clienteId}", $tmp, $baixado['name'], $baixado['mime'], 'documentos/' . $oportunidadeId);
        @unlink($tmp);
        $db->prepare("
            INSERT INTO oportunidade_documentos (oportunidade_id, tipo, arquivo_url, drive_file_id, obrigatorio, enviado_pelo_cliente, dados_confirmados)
            VALUES (?, ?, ?, ?, 1, 0, 1)
            ON CONFLICT(oportunidade_id, tipo) DO UPDATE SET
                arquivo_url = excluded.arquivo_url, drive_file_id = excluded.drive_file_id
        ")->execute([$oportunidadeId, $tipoFastcar, $copia['arquivo_url'], $copia['drive_file_id']]);
    }

    if ($idAntigo) crmAntigoMarcarImportadoPorIdAntigo('clients', $idAntigo, $oportunidadeId);

    return ['ok' => true, 'motivo' => null, 'cliente_id' => $clienteId, 'oportunidade_id' => $oportunidadeId, 'ja_existia' => false];
}

// ---------------------------------------------------------------------
// Fase 2 — sales.csv → vendas (revenda já feita)
// ---------------------------------------------------------------------

/**
 * Importa 1 linha de sales.csv como negociação de venda já `vendido` —
 * exige $oportunidadeId (o veículo já importado na fase 1, achado por
 * vehicle_id → vehicles.csv.source_client_id → cliente/oportunidade já
 * criados) e reaproveita criarVenda() só pra abrir a linha, gravando
 * `etapa='vendido'` direto por fora (nunca mudarEtapaVenda(), mesma
 * disciplina de zapsignImportarContratoVendaComoNegociacaoManual() — evita
 * finGerarReceitaVendaAssinatura() lançar um financeiro FALSO com data de
 * hoje pra uma venda que já aconteceu há meses).
 *
 * @return array{ok:bool,motivo:?string,venda_id:?int}
 */
function crmAntigoImportarVenda(array $linha, int $oportunidadeId, GoogleDrive $drive, string $arquivosFolderId, array $documentosDaVenda, ?int $responsavelId): array {
    $zapsignPlaceholder = (string)($linha['contract_file_url'] ?? '');
    // sales.csv não tem coluna zapsign_doc_token própria — usa a URL do
    // contrato como chave de dedup alternativa (única por negociação),
    // nunca reimporta a mesma venda 2x mesmo rodando o script de novo.
    if ($zapsignPlaceholder) {
        $db = getDB();
        $stmt = $db->prepare("SELECT 1 FROM vendas WHERE oportunidade_id = ? AND etapa = 'vendido'");
        $stmt->execute([$oportunidadeId]);
        if ($stmt->fetchColumn()) {
            return ['ok' => false, 'motivo' => 'Já existe venda concluída pra esse veículo (provavelmente já importada antes).', 'venda_id' => null];
        }
    }

    try {
        $vendaId = criarVenda($oportunidadeId, $responsavelId);
    } catch (RuntimeException $e) {
        return ['ok' => false, 'motivo' => $e->getMessage(), 'venda_id' => null];
    }

    $db = getDB();
    $compradorNome = clean((string)($linha['buyer_name'] ?? ''));
    $compradorTelefone = normalizarTelefone((string)($linha['buyer_whatsapp'] ?? ''));
    $dataVenda = crmAntigoParseData($linha['contract_date'] ?? '') ?: crmAntigoParseData($linha['created_at'] ?? '');

    $db->prepare("
        UPDATE vendas SET
            etapa = 'vendido',
            comprador_nome = ?,
            comprador_telefone = ?,
            comprador_email = ?,
            comprador_cpf = ?,
            comprador_rg = ?,
            comprador_endereco = ?,
            comprador_nacionalidade = ?,
            comprador_estado_civil = ?,
            comprador_profissao = ?,
            preco_venda = ?,
            valor_pago_contratacao = ?,
            forma_pagamento = ?,
            prazo_quitacao_meses = COALESCE(?, prazo_quitacao_meses),
            data_venda = ?,
            updated_at = datetime('now','localtime')
        WHERE id = ?
    ")->execute([
        $compradorNome,
        $compradorTelefone,
        clean((string)($linha['buyer_email'] ?? '')),
        clean((string)($linha['buyer_cpf'] ?? '')),
        clean((string)($linha['buyer_rg'] ?? '')),
        clean(crmAntigoMontarEndereco($linha, 'buyer_address_')),
        clean((string)($linha['buyer_nationality'] ?? '')) ?: 'brasileiro(a)',
        clean((string)($linha['buyer_marital_status'] ?? '')),
        clean((string)($linha['buyer_profession'] ?? '')),
        crmAntigoParseMoeda($linha['sale_value'] ?? null),
        crmAntigoParseMoeda($linha['down_payment_value'] ?? null),
        (string)($linha['type'] ?? '') === 'parcelada' ? 'parcelado' : 'quitacao_futura',
        crmAntigoParseInt($linha['installments_count'] ?? null),
        $dataVenda,
        $vendaId,
    ]);

    $db->prepare("
        INSERT INTO venda_historico (venda_id, etapa_anterior, etapa_nova, responsavel_id, observacao)
        VALUES (?, 'negociacao', 'vendido', ?, 'Importado do CRM antigo (Yaqar/IACAR) — venda já concluída na época; nenhum lançamento financeiro gerado automaticamente (venda histórica, não de hoje)')
    ")->execute([$vendaId, $responsavelId]);

    $mapaTipoDoc = ['cnh' => 'cnh', 'proof_of_address' => 'comprovante_endereco'];
    foreach ($documentosDaVenda as $doc) {
        $tipoOriginal = (string)($doc['type'] ?? '');
        $tipoFastcar = $mapaTipoDoc[$tipoOriginal] ?? null;
        if (!$tipoFastcar) continue;
        $baixado = crmAntigoBaixarArquivoPorCaminho($drive, $arquivosFolderId, (string)($doc['storage_path'] ?? ''));
        if (!$baixado) continue;
        $tmp = tempnam(sys_get_temp_dir(), 'crm_antigo_venda_') . '_' . preg_replace('/[^a-zA-Z0-9.]/', '_', $baixado['name']);
        file_put_contents($tmp, $baixado['content']);
        // Ancorado no cliente ORIGINAL (vendedor que trouxe o veículo pra
        // Fastcar) — mesmo destino que gerarEEnviarContratoVenda() já usa,
        // não existe cadastro em `clientes` pro comprador da revenda.
        $stmtCli = $db->prepare('SELECT id, nome FROM clientes WHERE id = (SELECT cliente_id FROM oportunidades WHERE id = ?)');
        $stmtCli->execute([$oportunidadeId]);
        $cliOriginal = $stmtCli->fetch(PDO::FETCH_ASSOC);
        $copia = salvarArquivoGeradoComoDocumento(
            (int)$cliOriginal['id'], (string)$cliOriginal['nome'], $tmp, $baixado['name'], $baixado['mime'], 'documentos_venda/' . $vendaId
        );
        @unlink($tmp);
        $db->prepare("
            INSERT INTO venda_documentos (venda_id, tipo, arquivo_url, drive_file_id, obrigatorio, enviado_pelo_cliente, dados_confirmados)
            VALUES (?, ?, ?, ?, 1, 0, 1)
            ON CONFLICT(venda_id, tipo) DO UPDATE SET
                arquivo_url = excluded.arquivo_url, drive_file_id = excluded.drive_file_id
        ")->execute([$vendaId, $tipoFastcar, $copia['arquivo_url'], $copia['drive_file_id']]);
    }

    return ['ok' => true, 'motivo' => null, 'venda_id' => $vendaId];
}

// ---------------------------------------------------------------------
// Fase 3 — leads.csv, só "lost"/"no_interest" (histórico, funil ativo
// intocado) — "won" é pulado aqui de propósito, já vem coberto por
// clients.csv na fase 1 (quem "ganhou" o lead virou cliente de verdade).
// ---------------------------------------------------------------------

/**
 * @return array{ok:bool,motivo:?string,cliente_id:?int,oportunidade_id:?int}
 */
function crmAntigoImportarLeadEncerrado(array $linha, int $criadoPor): array {
    $telefone = (string)($linha['phone'] ?? '');
    if (!$telefone) {
        return ['ok' => false, 'motivo' => 'Sem telefone.', 'cliente_id' => null, 'oportunidade_id' => null];
    }
    $status = (string)($linha['status'] ?? '');
    if (!in_array($status, ['lost', 'no_interest'], true)) {
        return ['ok' => false, 'motivo' => "Status \"{$status}\" fora do escopo desta fase (só lost/no_interest).", 'cliente_id' => null, 'oportunidade_id' => null];
    }

    $telNorm = normalizarTelefone($telefone);
    if (!$telNorm || strlen($telNorm) < 12) {
        return ['ok' => false, 'motivo' => 'Telefone inválido.', 'cliente_id' => null, 'oportunidade_id' => null];
    }

    // Já existe cliente com esse telefone (seja de fase 1, seja de lead
    // real já entrado pelo Fastcar novo) — nunca cria duplicado, e nunca
    // mexe na etapa de uma oportunidade que já existe (pode já estar em
    // andamento de verdade no funil atual, bem diferente do lead morto
    // de meses atrás que estamos só arquivando como histórico).
    $clienteExistente = crmAntigoClienteExistentePorTelefone($telefone);
    if ($clienteExistente !== null) {
        return ['ok' => false, 'motivo' => 'Já existe cliente com esse telefone no Fastcar atual — pulado (não mexe em negociação que já existe).', 'cliente_id' => $clienteExistente, 'oportunidade_id' => null];
    }

    $db = getDB();
    $db->prepare('INSERT INTO clientes (nome, telefone, cidade) VALUES (?, ?, ?)')
       ->execute([clean((string)($linha['name'] ?? '')), $telNorm, clean((string)($linha['client_city'] ?? ''))]);
    $clienteId = (int)$db->lastInsertId();

    $etapaFinal = $status === 'no_interest' ? 'sem_perfil' : 'perdido';
    $motivo = trim((string)($linha['lost_reason_notes'] ?? '')) ?: trim((string)($linha['lost_reason'] ?? '')) ?: 'Lead encerrado no CRM antigo (Yaqar/IACAR), motivo não detalhado.';

    $db->prepare("
        INSERT INTO oportunidades (cliente_id, etapa, veiculo_marca, veiculo_modelo, veiculo_ano, veiculo_placa, motivo_perda, responsavel_id)
        VALUES (?, ?, '', ?, ?, ?, ?, NULL)
    ")->execute([
        $clienteId, $etapaFinal,
        clean((string)($linha['vehicle'] ?? '')),
        clean((string)($linha['vehicle_year'] ?? '')),
        clean((string)($linha['vehicle_plate'] ?? '')),
        clean($motivo),
    ]);
    $oportunidadeId = (int)$db->lastInsertId();

    $db->prepare("
        INSERT INTO oportunidade_historico (oportunidade_id, etapa_anterior, etapa_nova, responsavel_id, observacao)
        VALUES (?, '', ?, NULL, 'Importado do CRM antigo (Yaqar/IACAR) como histórico — lead nunca virou negócio, funil ativo do Fastcar não foi afetado')
    ")->execute([$oportunidadeId, $etapaFinal]);

    return ['ok' => true, 'motivo' => null, 'cliente_id' => $clienteId, 'oportunidade_id' => $oportunidadeId];
}
