<?php
/**
 * Formulário público de upload de documentos — cliente sobe CNH,
 * comprovante de endereço e contrato de financiamento sozinho, sem login,
 * por um link com token enviado via WhatsApp. Preenche o mesmo
 * `oportunidade_documentos` que o checklist de fechamento (regra #7,
 * includes/oportunidades.php::checklistFechamentoCompleto()) já usa.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';

/** Tipos que o CLIENTE sobe sozinho no formulário público. */
const TIPOS_DOCUMENTOS_CLIENTE = [
    'cnh'                    => 'CNH (frente e verso, ou documento com foto)',
    'comprovante_endereco'   => 'Comprovante de endereço (últimos 3 meses)',
    'contrato_financiamento' => 'Contrato de financiamento do veículo (com o banco)',
];

/** Tipos da pasta fechada (bloco 8) — quem sobe é o consultor/Jean, não o cliente. */
const TIPOS_DOCUMENTOS_FECHAMENTO = [
    'contrato_compra'        => 'Contrato de compra (Fastcar)',
    'comprovante_pagamento'  => 'Comprovante de pagamento',
    'laudo_avaliacao'        => 'Laudo de avaliação do veículo',
];

define('UPLOADS_DIR', dirname(__DIR__) . '/storage/uploads');
const UPLOAD_MAX_BYTES = 10 * 1024 * 1024; // 10MB
const UPLOAD_MIME_PERMITIDOS = [
    'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf',
];

/**
 * Token é a "senha" do link público — gerado sob demanda (lazy) na 1ª vez
 * que alguém pede o link (ex: consultor clica "gerar link" no admin).
 * Reaproveita se já existir, nunca troca token de uma oportunidade em uso
 * (link já mandado pro cliente pararia de funcionar).
 */
function getOuCriarTokenDocumentos(int $oportunidadeId): string {
    $db = getDB();
    $stmt = $db->prepare("SELECT documentos_token FROM oportunidades WHERE id = ?");
    $stmt->execute([$oportunidadeId]);
    $token = $stmt->fetchColumn();

    if (!$token) {
        $token = bin2hex(random_bytes(20));
        $db->prepare("UPDATE oportunidades SET documentos_token = ? WHERE id = ?")->execute([$token, $oportunidadeId]);
    }

    garantirLinhasDocumentosObrigatorios($oportunidadeId);

    return $token;
}

/**
 * checklistFechamentoCompleto() (includes/oportunidades.php) só conta
 * linhas que EXISTEM em oportunidade_documentos — se só 1 dos 6 tipos
 * obrigatórios virar linha (só ele foi enviado), total=1/pendentes=0 e o
 * checklist erradamente dá como completo, mesmo faltando os outros 5 que
 * nunca chegaram a existir como linha. Por isso pré-cria TODOS os tipos
 * obrigatórios (com arquivo_url vazio) assim que o link é gerado — nunca
 * sobrescreve upload já feito (INSERT OR IGNORE respeita a UNIQUE
 * oportunidade_id+tipo).
 */
function garantirLinhasDocumentosObrigatorios(int $oportunidadeId): void {
    $db = getDB();
    $stmt = $db->prepare("
        INSERT OR IGNORE INTO oportunidade_documentos (oportunidade_id, tipo, obrigatorio) VALUES (?, ?, 1)
    ");
    foreach (array_keys(TIPOS_DOCUMENTOS_CLIENTE + TIPOS_DOCUMENTOS_FECHAMENTO) as $tipo) {
        $stmt->execute([$oportunidadeId, $tipo]);
    }
}

/** Busca a oportunidade + cliente por token — usado pelo formulário público. */
function buscarOportunidadePorToken(string $token): ?array {
    if (!$token) return null;
    $db = getDB();
    $stmt = $db->prepare("
        SELECT o.id AS oportunidade_id, o.etapa, o.veiculo_marca, o.veiculo_modelo, o.veiculo_ano,
               c.id AS cliente_id, c.nome, c.telefone, c.cpf, c.endereco
        FROM oportunidades o
        JOIN clientes c ON c.id = o.cliente_id
        WHERE o.documentos_token = ?
    ");
    $stmt->execute([$token]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** Atualiza os dados pessoais do cliente a partir do formulário público. */
function atualizarDadosPessoaisCliente(int $clienteId, string $nome, string $cpf, string $endereco): void {
    $db = getDB();
    $db->prepare("UPDATE clientes SET nome = ?, cpf = ?, endereco = ? WHERE id = ?")
       ->execute([clean($nome), clean($cpf), clean($endereco), $clienteId]);
}

/** Lista os documentos já registrados de uma oportunidade, indexado por tipo. */
function listarDocumentos(int $oportunidadeId): array {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM oportunidade_documentos WHERE oportunidade_id = ?");
    $stmt->execute([$oportunidadeId]);
    $porTipo = [];
    foreach ($stmt->fetchAll() as $row) {
        $porTipo[$row['tipo']] = $row;
    }
    return $porTipo;
}

/**
 * Valida e salva um arquivo enviado (formato $_FILES['campo']), grava em
 * storage/uploads/{oportunidade_id}/ com nome fixo (nunca o nome original —
 * evita path traversal e nome/extensão forjados) e faz upsert em
 * oportunidade_documentos (UNIQUE oportunidade_id+tipo — reenvio substitui).
 *
 * Retorna ['ok' => bool, 'erro' => ?string].
 */
function salvarUploadDocumento(int $oportunidadeId, string $tipo, array $arquivo, bool $enviadoPeloCliente): array {
    if (($arquivo['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['ok' => false, 'erro' => null]; // campo vazio, não é erro — só não tinha arquivo nesse envio
    }
    if ($arquivo['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'erro' => 'Falha no envio do arquivo (tente novamente).'];
    }
    if ($arquivo['size'] > UPLOAD_MAX_BYTES) {
        return ['ok' => false, 'erro' => 'Arquivo maior que 10MB.'];
    }

    // Nunca confiar no Content-Type que o navegador manda — detecta de
    // verdade pelo conteúdo do arquivo.
    $mime = mime_content_type($arquivo['tmp_name']);
    if (!isset(UPLOAD_MIME_PERMITIDOS[$mime])) {
        return ['ok' => false, 'erro' => 'Formato não aceito — envie foto (JPG/PNG/WEBP) ou PDF.'];
    }

    $dir = UPLOADS_DIR . '/' . $oportunidadeId;
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        return ['ok' => false, 'erro' => 'Não foi possível salvar o arquivo no servidor.'];
    }

    $ext = UPLOAD_MIME_PERMITIDOS[$mime];
    $nomeArquivo = $tipo . '_' . time() . '.' . $ext; // nome fixo, nunca o original
    $caminhoFinal = $dir . '/' . $nomeArquivo;

    if (!move_uploaded_file($arquivo['tmp_name'], $caminhoFinal)) {
        return ['ok' => false, 'erro' => 'Falha ao gravar o arquivo no servidor.'];
    }

    // arquivo_url guarda o caminho RELATIVO a storage/uploads/ — nunca a URL
    // pública direta, porque essa pasta não é servida diretamente (só via
    // admin/ver_documento.php, que exige login).
    $relativo = $oportunidadeId . '/' . $nomeArquivo;

    $db = getDB();
    $db->prepare("
        INSERT INTO oportunidade_documentos (oportunidade_id, tipo, arquivo_url, enviado_pelo_cliente, updated_at)
        VALUES (?, ?, ?, ?, datetime('now','localtime'))
        ON CONFLICT(oportunidade_id, tipo) DO UPDATE SET
            arquivo_url = excluded.arquivo_url,
            enviado_pelo_cliente = excluded.enviado_pelo_cliente,
            updated_at = datetime('now','localtime')
    ")->execute([$oportunidadeId, $tipo, $relativo, $enviadoPeloCliente ? 1 : 0]);

    return ['ok' => true, 'erro' => null];
}
