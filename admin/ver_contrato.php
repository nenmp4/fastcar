<?php
/**
 * Serve o PDF de um contrato (tabela `contratos` — Google Drive ou
 * storage/uploads/ local, fallback) pro admin logado. Funciona em
 * qualquer status: logo depois de gerado (ainda `enviado`, esperando
 * assinatura) até depois de `assinado` — o registro é sobrescrito com a
 * versão mais recente do PDF a cada passo (includes/contratos.php).
 * Mesma lógica de ver/baixar de admin/ver_documento.php, compartilhada via
 * includes/documentos.php::servirArquivoDriveOuLocal().
 */

require_once __DIR__ . '/_bootstrap.php';

$id = (int)($_GET['id'] ?? 0);
$db = getDB();
$stmt = $db->prepare("SELECT * FROM contratos WHERE id = ?");
$stmt->execute([$id]);
$contrato = $stmt->fetch();

if (!$contrato) {
    http_response_code(404);
    exit('Contrato não encontrado.');
}

servirArquivoDriveOuLocal($contrato['drive_file_id'] ?: null, $contrato['arquivo_url'] ?: null);
