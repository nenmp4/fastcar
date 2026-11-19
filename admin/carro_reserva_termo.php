<?php
/**
 * PDF do termo de carro reserva (19/11/2026): assinado → serve a cópia
 * guardada; ainda pendente → gera a prévia (sem assinatura) pra mostrar ao
 * cliente antes de ele assinar na tela. Só leitura.
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../includes/carro_reserva.php';
require_once __DIR__ . '/../includes/acesso_documentos.php';
requireAcessoVendas();

$id = (int)($_GET['id'] ?? 0);
$r = $id ? carroReservaBuscar($id) : null;
if (!$r) {
    http_response_code(404);
    exit('Termo não encontrado.');
}
if (!usuarioPodeAbrirDocumentoNegocio(null, (int)$r['venda_id'])) {
    negarAcessoDocumento('termo_carro_reserva', (int)$r['venda_id'], 'Termo de carro reserva #' . $id);
}
auditoriaRegistrarDocumentoAberto('termo_carro_reserva', (int)$r['venda_id'], 'Termo de carro reserva #' . $id);

if ($r['assinado_em'] && ($r['termo_drive_file_id'] || $r['termo_arquivo_url'])) {
    servirArquivoDriveOuLocal($r['termo_drive_file_id'] ?: null, $r['termo_arquivo_url'] ?: null);
    exit;
}

$caminho = gerarPdfTermoCarroReserva($r);
$pdf = (string)file_get_contents($caminho);
@unlink($caminho);
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="termo_carro_reserva_' . $id . '.pdf"');
header('Content-Length: ' . strlen($pdf));
header('Cache-Control: private, no-store');
echo $pdf;
