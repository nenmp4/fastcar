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
require_once __DIR__ . '/google_drive.php';

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
               o.veiculo_placa, o.veiculo_renavam, o.veiculo_chassi, o.banco_financiamento,
               o.valor_parcela, o.parcelas_restantes, o.contrato_financiamento_numero,
               c.id AS cliente_id, c.nome, c.telefone, c.cpf, c.email, c.endereco,
               c.rg, c.cnh, c.nacionalidade, c.estado_civil, c.profissao
        FROM oportunidades o
        JOIN clientes c ON c.id = o.cliente_id
        WHERE o.documentos_token = ?
    ");
    $stmt->execute([$token]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Atualiza os dados pessoais do cliente a partir do formulário público —
 * inclui a qualificação civil (RG, CNH, nacionalidade, estado civil,
 * profissão) exigida pelo contrato-mestre de compra (includes/contratos.php)
 * e o e-mail (14/09/2026, pedido do José/Jean — faltava, usado também como
 * canal alternativo de notificação da ZapSign na hora de assinar).
 */
function atualizarDadosPessoaisCliente(
    int $clienteId, string $nome, string $cpf, string $endereco,
    string $rg = '', string $cnh = '', string $nacionalidade = '', string $estadoCivil = '', string $profissao = '',
    string $email = ''
): void {
    $db = getDB();
    $db->prepare("
        UPDATE clientes SET nome = ?, cpf = ?, endereco = ?, rg = ?, cnh = ?, nacionalidade = ?, estado_civil = ?, profissao = ?, email = ?
        WHERE id = ?
    ")->execute([
        clean($nome), clean($cpf), clean($endereco), clean($rg), clean($cnh),
        clean($nacionalidade), clean($estadoCivil), clean($profissao), clean($email), $clienteId,
    ]);
}

/**
 * Pasta raiz "Fastcar" no Drive — criada uma única vez, guardada em
 * config.drive_folder_id. Estrutura pedida: Fastcar > Cliente - Fulano > arquivos.
 */
function garantirPastaRaizFastcar(GoogleDrive $drive): ?string {
    $raizId = getConfig('drive_folder_id');
    if ($raizId) return $raizId;
    $novaId = $drive->createFolder('Fastcar');
    if ($novaId) setConfig('drive_folder_id', $novaId);
    return $novaId ?: null;
}

/**
 * Pasta do cliente dentro da raiz Fastcar — criada sob demanda, guardada em
 * clientes.drive_folder_id (nunca recriada depois de existir). Recebe a
 * instância GoogleDrive já autenticada de quem chamou, pra não autenticar
 * 2x à toa.
 */
function garantirPastaDriveCliente(GoogleDrive $drive, int $clienteId, string $nomeCliente): ?string {
    $db = getDB();
    $stmt = $db->prepare("SELECT drive_folder_id FROM clientes WHERE id = ?");
    $stmt->execute([$clienteId]);
    $pastaId = $stmt->fetchColumn();
    if ($pastaId) return $pastaId;

    $raizId = garantirPastaRaizFastcar($drive);
    if (!$raizId) return null;

    $novaId = $drive->createFolder('Cliente - ' . $nomeCliente, $raizId);
    if ($novaId) {
        $db->prepare("UPDATE clientes SET drive_folder_id = ? WHERE id = ?")->execute([$novaId, $clienteId]);
    }
    return $novaId ?: null;
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

    $ext = UPLOAD_MIME_PERMITIDOS[$mime];
    $nomeArquivo = $tipo . '_' . time() . '.' . $ext; // nome fixo, nunca o original

    $db = getDB();
    $driveFileId = '';
    $relativoLocal = '';

    // Google Drive é o destino preferido (pasta do cliente, pedido do
    // Jean) — local (storage/uploads/) é só fallback enquanto a
    // credencial não existir ou se a chamada falhar; nunca pode travar o
    // envio do documento por causa de um provedor externo fora do ar.
    $stmtCli = $db->prepare("
        SELECT c.id AS cliente_id, c.nome FROM oportunidades o
        JOIN clientes c ON c.id = o.cliente_id WHERE o.id = ?
    ");
    $stmtCli->execute([$oportunidadeId]);
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
        $dir = UPLOADS_DIR . '/' . $oportunidadeId;
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            return ['ok' => false, 'erro' => 'Não foi possível salvar o arquivo no servidor.'];
        }
        $caminhoFinal = $dir . '/' . $nomeArquivo;
        if (!move_uploaded_file($arquivo['tmp_name'], $caminhoFinal)) {
            return ['ok' => false, 'erro' => 'Falha ao gravar o arquivo no servidor.'];
        }
        // Caminho RELATIVO a storage/uploads/ — nunca a URL pública direta,
        // essa pasta não é servida diretamente (só via admin/ver_documento.php).
        $relativoLocal = $oportunidadeId . '/' . $nomeArquivo;
    }

    // Reenvio substitui o arquivo — mas se já tinha sido confirmado no
    // wizard antes (dados_confirmados=1), o arquivo novo pode ter dados
    // diferentes do que foi confirmado, então volta pra 0 (força revisão
    // de novo). Nunca reseta o que já tinha sido extraído/gravado nos
    // campos de clientes/oportunidades — só o "confirmei que tá certo".
    $db->prepare("
        INSERT INTO oportunidade_documentos (oportunidade_id, tipo, arquivo_url, drive_file_id, enviado_pelo_cliente, dados_confirmados, updated_at)
        VALUES (?, ?, ?, ?, ?, 0, datetime('now','localtime'))
        ON CONFLICT(oportunidade_id, tipo) DO UPDATE SET
            arquivo_url = excluded.arquivo_url,
            drive_file_id = excluded.drive_file_id,
            enviado_pelo_cliente = excluded.enviado_pelo_cliente,
            dados_confirmados = 0,
            updated_at = datetime('now','localtime')
    ")->execute([$oportunidadeId, $tipo, $relativoLocal, $driveFileId, $enviadoPeloCliente ? 1 : 0]);

    $stmtId = $db->prepare("SELECT id FROM oportunidade_documentos WHERE oportunidade_id = ? AND tipo = ?");
    $stmtId->execute([$oportunidadeId, $tipo]);
    $docId = (int)$stmtId->fetchColumn();

    // documento_id vai pro chamador poder disparar a extração por IA
    // (includes/extracao_documentos.php) — que relê os bytes de volta via
    // lerConteudoArquivoDocumento(), nunca reaproveita $arquivo['tmp_name']
    // aqui (já foi movido/consumido acima, dependendo do destino).
    return ['ok' => true, 'erro' => null, 'documento_id' => $docId];
}

/**
 * Sobe um arquivo já em disco (gerado pelo próprio sistema — ex: PDF de
 * contrato, includes/contratos_pdf.php — não um upload de $_FILES) pra
 * pasta do cliente no Drive, com o mesmo fallback local de
 * salvarUploadDocumento() (storage/uploads/{subpasta}/{nome}). Nunca move
 * nem apaga o arquivo de origem — quem chama decide o que fazer com ele
 * depois. Retorna ['drive_file_id' => string, 'arquivo_url' => string] —
 * sempre um dos dois preenchido e o outro vazio, ou ambos vazios se os
 * dois caminhos falharem (Drive indisponível/falhou E não deu pra gravar
 * local — quem chama decide se isso é erro fatal ou só "sem cópia visível
 * dessa vez", nunca deve travar o fluxo principal por causa disso).
 */
function salvarArquivoGeradoComoDocumento(int $clienteId, string $nomeCliente, string $caminhoOrigem, string $nomeArquivo, string $mime, string $subpasta): array {
    $driveFileId = '';
    $relativoLocal = '';

    $drive = new GoogleDrive();
    if ($drive->hasCredentials() && $drive->authenticate()) {
        $pastaId = garantirPastaDriveCliente($drive, $clienteId, $nomeCliente ?: "Cliente #{$clienteId}");
        if ($pastaId) {
            $idDrive = $drive->uploadFile($caminhoOrigem, $nomeArquivo, $mime, $pastaId);
            if ($idDrive) $driveFileId = $idDrive;
        }
    }

    if (!$driveFileId) {
        $dir = UPLOADS_DIR . '/' . $subpasta;
        if ((is_dir($dir) || mkdir($dir, 0755, true)) && copy($caminhoOrigem, $dir . '/' . $nomeArquivo)) {
            $relativoLocal = $subpasta . '/' . $nomeArquivo;
        }
    }

    return ['drive_file_id' => $driveFileId, 'arquivo_url' => $relativoLocal];
}

/**
 * Lê os bytes de um arquivo salvo via Drive (drive_file_id) ou fallback
 * local (arquivo_url, relativo a UPLOADS_DIR) — mesma defesa contra path
 * traversal e mesma prioridade Drive-primeiro de salvarUploadDocumento().
 * Retorna null se não achar nenhum dos dois ou se a leitura falhar (nunca
 * lança). Compartilhado entre servirArquivoDriveOuLocal() (serve pro
 * navegador) e extrairDadosDocumentoComIA() (manda os bytes pro Gemini).
 */
function lerConteudoArquivoDocumento(?string $driveFileId, ?string $arquivoUrl): ?array {
    if ($driveFileId) {
        $drive = new GoogleDrive();
        if (!$drive->hasCredentials() || !$drive->authenticate()) return null;
        $arquivo = $drive->download($driveFileId);
        if (!$arquivo) return null;
        return ['content' => $arquivo['content'], 'mime' => $arquivo['mime'], 'name' => $arquivo['name']];
    }

    if (!$arquivoUrl) return null;
    $caminho = realpath(UPLOADS_DIR . '/' . $arquivoUrl);
    if (!$caminho || !str_starts_with($caminho, realpath(UPLOADS_DIR) . DIRECTORY_SEPARATOR)) return null;

    $conteudo = @file_get_contents($caminho);
    if ($conteudo === false) return null;
    return ['content' => $conteudo, 'mime' => mime_content_type($caminho) ?: 'application/octet-stream', 'name' => basename($caminho)];
}

/**
 * Serve (inline, nunca força download) um arquivo salvo via Drive ou
 * fallback local — compartilhado entre admin/ver_documento.php e
 * admin/ver_contrato.php pra não duplicar a lógica de download/defesa
 * contra path traversal em dois arquivos. Sempre termina a request (exit).
 */
function servirArquivoDriveOuLocal(?string $driveFileId, ?string $arquivoUrl): void {
    if (!$driveFileId && !$arquivoUrl) {
        http_response_code(404);
        exit('Arquivo não encontrado.');
    }

    $arquivo = lerConteudoArquivoDocumento($driveFileId, $arquivoUrl);
    if (!$arquivo) {
        http_response_code($driveFileId ? 502 : 404);
        exit($driveFileId ? 'Não foi possível baixar o documento agora. Tente novamente.' : 'Arquivo não encontrado.');
    }

    header('Content-Type: ' . $arquivo['mime']);
    header('Content-Disposition: inline; filename="' . rawurlencode($arquivo['name']) . '"');
    header('Content-Length: ' . strlen($arquivo['content']));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    echo $arquivo['content'];
    exit;
}
