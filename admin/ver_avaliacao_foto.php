<?php
/**
 * Serve uma foto/vídeo da galeria de vistoria (veiculo_avaliacao_fotos) —
 * mesmo padrão de admin/ver_midia_revenda.php. Trava é
 * requireAcessoAvaliacoes() (não responsavel_id específico): a foto é da
 * VISTORIA de um veículo, qualquer perfil com acesso ao módulo pode ver.
 */

require_once __DIR__ . '/_bootstrap.php';
requireAcessoAvaliacoes();

$id = (int)($_GET['id'] ?? 0);
$db = getDB();
$stmt = $db->prepare("SELECT * FROM veiculo_avaliacao_fotos WHERE id = ?");
$stmt->execute([$id]);
$foto = $stmt->fetch();

if (!$foto) {
    http_response_code(404);
    exit('Mídia não encontrada.');
}

servirArquivoDriveOuLocal($foto['drive_file_id'] ?: null, $foto['arquivo_url'] ?: null);
