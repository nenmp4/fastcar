<?php
/**
 * AJAX — lê um CRLV (foto/PDF) com IA e devolve os dados do veículo pra
 * pré-preencher o cadastro manual em admin/veiculos.php — 17/09/2026,
 * pedido José/Jean: "vamos subir carro pelo documento veiculo para ir
 * mais rapido". Reaproveita exatamente a mesma extração já usada no
 * wizard de documentos do cliente (`includes/extracao_documentos.php`,
 * `EXTRACAO_DOCUMENTO_CAMPOS['crlv']`) — nunca uma cópia nova do prompt,
 * mesmo mecanismo de autochecagem de tipo (`_documento_correto`) que já
 * bloqueia usar dado de um documento errado (ex: CNH no lugar do CRLV).
 * Só lê e devolve os campos — nunca salva o arquivo como documento
 * oficial nem cria nada no banco, quem decide se os dados batem e
 * confirma é o super_admin cadastrando o veículo.
 */

require_once __DIR__ . '/_bootstrap.php';
requireSuperAdmin();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validateCSRF($_POST['csrf_token'] ?? '')) {
    echo json_encode(['ok' => false, 'erro' => 'Sessão expirada, recarregue a página e tente de novo.']);
    exit;
}

if (!getConfig('gemini_api_key')) {
    echo json_encode(['ok' => false, 'erro' => 'Chave da API Gemini não configurada — configure em Configurações → IA pra usar essa leitura automática.']);
    exit;
}

$arquivo = $_FILES['crlv'] ?? null;
if (!$arquivo || ($arquivo['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
    echo json_encode(['ok' => false, 'erro' => 'Escolha o arquivo do CRLV primeiro.']);
    exit;
}
if ($arquivo['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['ok' => false, 'erro' => 'Falha no envio do arquivo (tente novamente).']);
    exit;
}
if ($arquivo['size'] > UPLOAD_MAX_BYTES) {
    echo json_encode(['ok' => false, 'erro' => 'Arquivo maior que ' . (int)(UPLOAD_MAX_BYTES / 1024 / 1024) . 'MB.']);
    exit;
}
$mime = mime_content_type($arquivo['tmp_name']);
if (!isset(UPLOAD_MIME_PERMITIDOS[$mime])) {
    echo json_encode(['ok' => false, 'erro' => 'Formato não aceito — envie foto (JPG/PNG/WEBP) ou PDF do CRLV.']);
    exit;
}

$dados = extrairDadosDocumentoComIA('crlv', [
    'content' => file_get_contents($arquivo['tmp_name']),
    'mime' => $mime,
]);

if (!$dados) {
    echo json_encode(['ok' => false, 'erro' => 'Não deu pra ler o documento agora (IA fora do ar ou não conseguiu extrair nada) — preencha manualmente.']);
    exit;
}

if (!$dados['_documento_correto']) {
    echo json_encode([
        'ok' => false,
        'erro' => 'Esse arquivo não parece ser um CRLV' . ($dados['_tipo_real_se_diferente'] ? " — parece ser {$dados['_tipo_real_se_diferente']}" : '') . '. Envie o CRLV do veículo ou preencha manualmente.',
    ]);
    exit;
}

echo json_encode([
    'ok' => true,
    'veiculo_marca' => $dados['veiculo_marca'] ?? '',
    'veiculo_modelo' => $dados['veiculo_modelo'] ?? '',
    'veiculo_ano' => $dados['veiculo_ano'] ?? '',
    'veiculo_placa' => $dados['veiculo_placa'] ?? '',
    'veiculo_renavam' => $dados['veiculo_renavam'] ?? '',
    'veiculo_chassi' => $dados['veiculo_chassi'] ?? '',
]);
