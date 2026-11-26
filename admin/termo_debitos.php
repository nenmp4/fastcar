<?php
/**
 * PDF do Termo de ciência e confissão de débitos do veículo (26/11/2026) —
 * opcional, abre no navegador pra IMPRIMIR; o comprador assina à mão e a
 * cópia assinada volta pelo card "📎 Documentos" de admin/venda.php (tipo
 * 'termo_debitos_veiculo'). Só leitura, nunca grava nada nem vai pra ZapSign.
 * Ver gerarPdfTermoDebitosVeiculo() (includes/contratos_pdf.php).
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../includes/acesso_documentos.php';
requireAcessoVendas();

$id = (int)($_GET['id'] ?? 0);
$campos = $id ? montarCamposContratoVenda($id) : null;
if (!$campos) {
    http_response_code(404);
    exit('Negociação não encontrada ou sem veículo vinculado.');
}

if (!usuarioPodeAbrirDocumentoNegocio(null, $id)) {
    negarAcessoDocumento('termo_debitos', $id, 'Termo de débitos do veículo — ' . auditoriaContextoNegocio(null, $id));
}

$valor = valorMonetario($_GET['valor'] ?? null);
$prazo = (int)($_GET['prazo'] ?? 30);
$descricao = mb_substr(trim(strip_tags((string)($_GET['descricao'] ?? ''))), 0, 300);
if ($valor === null || $valor <= 0) {
    http_response_code(400);
    exit('Informe o valor dos débitos.');
}
if ($prazo < 1 || $prazo > 365) $prazo = 30;

auditoriaRegistrarDocumentoAberto('termo_debitos', $id, 'Termo de débitos do veículo (impressão) — ' . auditoriaContextoNegocio(null, $id));

pdfAtivarMarcaDagua();
$caminho = gerarPdfTermoDebitosVeiculo($campos, $valor, $descricao, $prazo);
$pdf = (string)file_get_contents($caminho);
@unlink($caminho);

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="termo_debitos_' . $id . '.pdf"');
header('Content-Length: ' . strlen($pdf));
header('Cache-Control: private, no-store');
echo $pdf;
