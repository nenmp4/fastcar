<?php
/**
 * Wizard público de documentos do COMPRADOR (módulo de vendas/revenda) —
 * 19/09/2026, "lá no modulo vendas tem espelhar compra - subir os
 * documentos preencher tudo ter link igual de compra". Espelha
 * includes/documentos.php (funil de COMPRA), mas escopo bem mais enxuto:
 * só CNH/RG + comprovante de endereço — comprador de revenda não tem
 * financiamento ativo nem CRLV pra entregar (escopo confirmado com o
 * usuário na sessão anterior).
 *
 * Arquivo PRÓPRIO de propósito (mesmo raciocínio já documentado no
 * CLAUDE.md pro WhatsApp Box/instância de vendas) — nunca ramifica em cima
 * de includes/documentos.php, já validado em produção pro funil de compra:
 * modelo de dado diferente (`vendas.comprador_*` em vez de `clientes`,
 * `venda_documentos` em vez de `oportunidade_documentos`), sem checklist de
 * fechamento (regra #7 é só de compra — venda não tem esse conceito).
 *
 * Reaproveita o que já é 100% genérico sem nenhuma mudança:
 * `extrairDadosDocumentoComIA()`/`extracaoDocumentoPrompt()`
 * (includes/extracao_documentos.php, os tipos 'cnh'/'comprovante_endereco'
 * já existem lá e não dependem de nada específico de compra) e
 * `compararDivergenciasDocumento()` (só compara arrays associativos por
 * nome de campo, nunca toca em tabela nenhuma). Só a APLICAÇÃO dos dados
 * extraídos precisa ser própria (`aplicarDadosExtraidosDocumentoVenda()`
 * abaixo) — o destino são colunas `vendas.comprador_*`, não `clientes`.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/google_drive.php';
// garantirPastaDriveCliente()/UPLOADS_DIR/UPLOAD_MAX_BYTES/
// UPLOAD_MIME_PERMITIDOS/lerConteudoArquivoDocumento()/
// servirArquivoDriveOuLocal() — todos já genéricos, reaproveitados sem
// nenhuma mudança.
require_once __DIR__ . '/documentos.php';

/** Só 2 tipos — comprador de revenda não tem financiamento nem CRLV pra entregar. */
const TIPOS_DOCUMENTOS_COMPRADOR = [
    'cnh'                  => 'CNH ou RG (documento de identidade com foto, frente e verso)',
    'comprovante_endereco' => 'Comprovante de endereço (conta ou boleto de luz, água, internet, condomínio etc. — últimos 3 meses)',
];

/**
 * Contrato de venda em si, igual o lado de compra já mostra "Contrato de
 * compra (Fastcar)" na mesma lista de documentos (TIPOS_DOCUMENTOS_FECHAMENTO,
 * includes/documentos.php) — 21/09/2026, "na lista de documento deveria
 * mostrar contrato de vendas igual do compras". Diferente de
 * TIPOS_DOCUMENTOS_COMPRADOR (o comprador sobe pelo wizard), esse é
 * preenchido sozinho quando a ZapSign confirma a assinatura
 * (includes/contratos.php::zapsignSincronizarContrato()) — nunca sobe pelo
 * wizard público, por isso fica em constante separada.
 */
const TIPOS_DOCUMENTOS_VENDA_CONTRATO = [
    'contrato_venda' => 'Contrato de venda (Fastcar)',
];

/**
 * Token do link público — só faz sentido gerar depois que a venda já tem
 * veículo vinculado (`oportunidade_id` preenchido, `vincularVeiculoVenda()`
 * já rodou), já que o destino Drive da cópia dos documentos depende do
 * cliente ORIGINAL daquele veículo (mesma âncora que
 * `gerarEEnviarContratoVenda()` já usa) — sem veículo vinculado ainda não
 * tem pasta pra salvar nada. Retorna `null` nesse caso (chamador decide
 * como avisar).
 */
function getOuCriarTokenDocumentosVenda(int $vendaId): ?string {
    $db = getDB();
    $stmt = $db->prepare("SELECT documentos_token, oportunidade_id FROM vendas WHERE id = ?");
    $stmt->execute([$vendaId]);
    $row = $stmt->fetch();
    if (!$row || !$row['oportunidade_id']) return null;

    $token = $row['documentos_token'];
    if (!$token) {
        $token = bin2hex(random_bytes(20));
        $db->prepare("UPDATE vendas SET documentos_token = ? WHERE id = ?")->execute([$token, $vendaId]);
    }

    garantirLinhasDocumentosVenda($vendaId);

    return $token;
}

/**
 * Mesmo raciocínio de garantirLinhasDocumentosObrigatorios() (compra) —
 * pré-cria as 2 linhas obrigatórias, nunca sobrescreve upload já feito
 * (INSERT OR IGNORE respeita a UNIQUE venda_id+tipo).
 */
function garantirLinhasDocumentosVenda(int $vendaId): void {
    $db = getDB();
    $stmt = $db->prepare("INSERT OR IGNORE INTO venda_documentos (venda_id, tipo, obrigatorio) VALUES (?, ?, 1)");
    foreach (array_keys(TIPOS_DOCUMENTOS_COMPRADOR) as $tipo) {
        $stmt->execute([$vendaId, $tipo]);
    }
}

/** Busca a venda + veículo (se já vinculado) por token — usado pelo wizard público. */
function buscarVendaPorToken(string $token): ?array {
    if (!$token) return null;
    $db = getDB();
    $stmt = $db->prepare("
        SELECT v.*, o.veiculo_marca, o.veiculo_modelo, o.veiculo_ano, o.veiculo_placa
        FROM vendas v
        LEFT JOIN oportunidades o ON o.id = v.oportunidade_id
        WHERE v.documentos_token = ?
    ");
    $stmt->execute([$token]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Atualiza os dados pessoais do COMPRADOR — mesmo espírito de
 * atualizarDadosPessoaisCliente() (compra), mas grava em `vendas.comprador_*`.
 */
function atualizarDadosPessoaisComprador(
    int $vendaId, string $nome, string $cpf, string $endereco,
    string $rg = '', string $cnh = '', string $nacionalidade = '', string $estadoCivil = '', string $profissao = '',
    string $email = ''
): void {
    $db = getDB();
    $db->prepare("
        UPDATE vendas SET comprador_nome = ?, comprador_cpf = ?, comprador_endereco = ?, comprador_rg = ?,
            comprador_cnh = ?, comprador_nacionalidade = ?, comprador_estado_civil = ?, comprador_profissao = ?, comprador_email = ?
        WHERE id = ?
    ")->execute([
        clean($nome), clean($cpf), clean($endereco), clean($rg), clean($cnh),
        clean($nacionalidade), clean($estadoCivil), clean($profissao), clean($email), $vendaId,
    ]);
}

/** Lista os documentos já registrados de uma venda, indexado por tipo. */
function listarDocumentosVenda(int $vendaId): array {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM venda_documentos WHERE venda_id = ?");
    $stmt->execute([$vendaId]);
    $porTipo = [];
    foreach ($stmt->fetchAll() as $row) {
        $porTipo[$row['tipo']] = $row;
    }
    return $porTipo;
}

/**
 * Mesmo espírito de salvarUploadDocumento() (compra), mas grava em
 * `venda_documentos` e ancora a pasta do Drive no cliente ORIGINAL do
 * veículo — mesmo destino que `gerarEEnviarContratoVenda()` já usa, já que
 * não existe cadastro em `clientes` pro comprador da revenda. Fallback
 * local sob `storage/uploads/vendas/{venda_id}/` — subpasta própria, nunca
 * colide com `storage/uploads/{oportunidade_id}/` do lado de compra.
 */
function salvarUploadDocumentoVenda(int $vendaId, string $tipo, array $arquivo, bool $enviadoPeloCliente): array {
    if (($arquivo['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['ok' => false, 'erro' => null]; // campo vazio, não é erro
    }
    if ($arquivo['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'erro' => 'Falha no envio do arquivo (tente novamente).'];
    }
    if ($arquivo['size'] > UPLOAD_MAX_BYTES) {
        return ['ok' => false, 'erro' => 'Arquivo maior que 10MB.'];
    }

    // Nunca confiar no Content-Type que o navegador manda.
    $mime = mime_content_type($arquivo['tmp_name']);
    if (!isset(UPLOAD_MIME_PERMITIDOS[$mime])) {
        return ['ok' => false, 'erro' => 'Formato não aceito — envie foto (JPG/PNG/WEBP) ou PDF.'];
    }

    $ext = UPLOAD_MIME_PERMITIDOS[$mime];
    $nomeArquivo = $tipo . '_' . time() . '.' . $ext; // nome fixo, nunca o original

    $db = getDB();
    $driveFileId = '';
    $relativoLocal = '';

    $stmtCli = $db->prepare("
        SELECT c.id AS cliente_id, c.nome FROM vendas v
        JOIN oportunidades o ON o.id = v.oportunidade_id
        JOIN clientes c ON c.id = o.cliente_id
        WHERE v.id = ?
    ");
    $stmtCli->execute([$vendaId]);
    $cli = $stmtCli->fetch();

    if ($cli) {
        $drive = new GoogleDrive();
        if ($drive->hasCredentials() && $drive->authenticate()) {
            $pastaId = garantirPastaDriveCliente($drive, (int)$cli['cliente_id'], $cli['nome'] ?: "Cliente #{$cli['cliente_id']}");
            if ($pastaId) {
                $idDrive = $drive->uploadFile($arquivo['tmp_name'], $nomeArquivo, $mime, $pastaId);
                if ($idDrive) $driveFileId = $idDrive;
            }
        }
    }

    if (!$driveFileId) {
        $dir = UPLOADS_DIR . '/vendas/' . $vendaId;
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            return ['ok' => false, 'erro' => 'Não foi possível salvar o arquivo no servidor.'];
        }
        $caminhoFinal = $dir . '/' . $nomeArquivo;
        if (!move_uploaded_file($arquivo['tmp_name'], $caminhoFinal)) {
            return ['ok' => false, 'erro' => 'Falha ao gravar o arquivo no servidor.'];
        }
        $relativoLocal = 'vendas/' . $vendaId . '/' . $nomeArquivo;
    }

    // Reenvio substitui o arquivo — se já tinha sido confirmado antes, volta
    // pra 0 (força revisão de novo), mesma regra do lado de compra.
    $db->prepare("
        INSERT INTO venda_documentos (venda_id, tipo, arquivo_url, drive_file_id, enviado_pelo_cliente, dados_confirmados, updated_at)
        VALUES (?, ?, ?, ?, ?, 0, datetime('now','localtime'))
        ON CONFLICT(venda_id, tipo) DO UPDATE SET
            arquivo_url = excluded.arquivo_url,
            drive_file_id = excluded.drive_file_id,
            enviado_pelo_cliente = excluded.enviado_pelo_cliente,
            dados_confirmados = 0,
            updated_at = datetime('now','localtime')
    ")->execute([$vendaId, $tipo, $relativoLocal, $driveFileId, $enviadoPeloCliente ? 1 : 0]);

    $stmtId = $db->prepare("SELECT id FROM venda_documentos WHERE venda_id = ? AND tipo = ?");
    $stmtId->execute([$vendaId, $tipo]);
    $docId = (int)$stmtId->fetchColumn();

    return ['ok' => true, 'erro' => null, 'documento_id' => $docId];
}

/**
 * Aplica os dados extraídos (fill-if-empty) nas colunas `vendas.comprador_*`
 * — mesmo espírito de aplicarDadosExtraidosDocumento() (compra), mas nunca
 * toca em `clientes` (comprador de revenda não tem cadastro lá). Chaves
 * genéricas ('nome'/'cpf'/'rg'/'cnh'/'endereco', as mesmas que
 * extrairDadosDocumentoComIA() já devolve pros tipos 'cnh'/
 * 'comprovante_endereco') mapeadas pra `comprador_*`.
 */
function aplicarDadosExtraidosDocumentoVenda(int $vendaId, array $dados): void {
    if (!$dados) return;
    $db = getDB();

    $mapa = [
        'nome' => 'comprador_nome', 'cpf' => 'comprador_cpf', 'rg' => 'comprador_rg',
        'cnh' => 'comprador_cnh', 'endereco' => 'comprador_endereco',
    ];

    $sets = []; $params = [];
    foreach ($dados as $campo => $valorBruto) {
        $valor = trim((string)$valorBruto);
        if ($valor === '' || !isset($mapa[$campo])) continue;
        $coluna = $mapa[$campo];
        $sets[] = "{$coluna} = CASE WHEN {$coluna} IS NULL OR {$coluna} = '' THEN ? ELSE {$coluna} END";
        $params[] = $valor;
    }

    if ($sets) {
        $params[] = $vendaId;
        $db->prepare("UPDATE vendas SET " . implode(', ', $sets) . " WHERE id = ?")->execute($params);
    }
}
