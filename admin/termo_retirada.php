<?php
/**
 * PDF do Termo de devolução amigável e autorização de retirada do veículo
 * (27/10/2026) — abre no navegador pra IMPRIMIR; o cliente assina à mão e a
 * cópia assinada volta pelo card "📎 Documentos" de admin/venda.php (tipo
 * 'termo_retirada_amigavel'). Só leitura, nunca grava nada nem vai pra
 * ZapSign. Ver gerarPdfTermoRetiradaAmigavel() (includes/contratos_pdf.php).
 */

require_once __DIR__ . '/_bootstrap.php';
requireAcessoVendas();

$id = (int)($_GET['id'] ?? 0);
$campos = $id ? montarCamposContratoVenda($id) : null;
if (!$campos) {
    http_response_code(404);
    exit('Negociação não encontrada ou sem veículo vinculado.');
}

$caminho = gerarPdfTermoRetiradaAmigavel($campos);
$pdf = (string)file_get_contents($caminho);
@unlink($caminho);

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="termo_retirada_' . $id . '.pdf"');
header('Content-Length: ' . strlen($pdf));
header('Cache-Control: private, no-store');
echo $pdf;
