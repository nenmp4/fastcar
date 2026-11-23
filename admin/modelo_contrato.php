<?php
/**
 * Baixa um modelo de contrato em branco (Configurações → 📄 Modelos de contrato).
 * Só leitura (GET): gera o PDF na hora com as mesmas funções do contrato de verdade.
 * Ver includes/contratos_modelos.php.
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../includes/contratos_modelos.php';
requireSuperAdmin();
if (!podeAcessarConfiguracoes()) {
    http_response_code(403);
    exit('Acesso restrito.');
}

$tipo = (string)($_GET['tipo'] ?? '');
if (!isset(CONTRATO_MODELOS[$tipo])) {
    http_response_code(404);
    exit('Modelo não encontrado.');
}

ob_start();
try {
    $caminho = contratoModeloPdf($tipo);
} catch (Throwable $e) {
    ob_end_clean();
    http_response_code(500);
    exit('Não deu pra gerar o modelo: ' . e($e->getMessage()));
}
ob_end_clean();

$nome = 'Modelo_FASTCAR_' . $tipo . '.pdf';
header('Content-Type: application/pdf');
header('Content-Disposition: ' . (isset($_GET['baixar']) ? 'attachment' : 'inline') . '; filename="' . $nome . '"');
header('Content-Length: ' . filesize($caminho));
header('Cache-Control: private, no-store');
readfile($caminho);
@unlink($caminho);
