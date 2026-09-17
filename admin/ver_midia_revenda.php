<?php
/**
 * Serve uma foto/vídeo do catálogo de revenda de um veículo — mesmo
 * padrão de admin/ver_midia_whatsapp.php, mas a trava aqui é
 * requireAcessoVendas() (não por responsavel_id de uma negociação
 * específica): a mídia pertence ao VEÍCULO (compartilhada entre qualquer
 * tentativa de venda dele), não a um comprador/vendedor específico —
 * qualquer vendedor pode ver o catálogo de qualquer veículo disponível.
 */

require_once __DIR__ . '/_bootstrap.php';
requireAcessoVendas();

$id = (int)($_GET['id'] ?? 0);
$db = getDB();
$stmt = $db->prepare("SELECT * FROM veiculo_midias_revenda WHERE id = ?");
$stmt->execute([$id]);
$midia = $stmt->fetch();

if (!$midia) {
    http_response_code(404);
    exit('Mídia não encontrada.');
}

servirArquivoDriveOuLocal($midia['drive_file_id'] ?: null, $midia['arquivo_url'] ?: null);
