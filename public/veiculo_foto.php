<?php
/**
 * Serve 1 foto/vídeo do catálogo de revenda pro link público do veículo
 * (public/veiculo.php) — só mídia que pertence ao veículo do link, e só
 * enquanto o veículo continua disponível pra venda.
 */
header('X-Robots-Tag: noindex, nofollow, noarchive', true);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/vendas.php';

$o = (int)($_GET['o'] ?? 0);
$v = (int)($_GET['v'] ?? 0);
$m = (int)($_GET['m'] ?? 0);
if (!veiculoLinkPublicoValido($o, $v, (string)($_GET['t'] ?? '')) || !veiculoDisponivelParaVenda($o)) {
    http_response_code(404);
    exit('Não encontrado.');
}
$stmt = getDB()->prepare("SELECT * FROM veiculo_midias_revenda WHERE id = ? AND oportunidade_id = ?");
$stmt->execute([$m, $o]);
$midia = $stmt->fetch();
if (!$midia) {
    http_response_code(404);
    exit('Não encontrado.');
}
servirArquivoDriveOuLocal($midia['drive_file_id'] ?: null, $midia['arquivo_url'] ?: null);
