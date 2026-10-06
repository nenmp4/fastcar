<?php
/**
 * Serve 1 foto/vídeo da galeria da vistoria pro link público do Termo
 * Ciente (06/10/2026) — só a foto que de fato pertence à avaliação
 * resolvida pelo TOKEN (nunca confia no `foto_id` isolado vindo da URL:
 * sem checar o vínculo, qualquer um podia trocar o número e ver mídia de
 * outra vistoria/cliente). Mesmo padrão de servirArquivoDriveOuLocal()
 * (includes/documentos.php), só que sem exigir sessão/login — o token
 * já é a autenticação aqui.
 */

header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet', true);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/veiculo_avaliacoes.php';

$token = (string)($_GET['token'] ?? '');
$fotoId = (int)($_GET['foto_id'] ?? 0);

$av = buscarAvaliacaoPorTermoCienteToken($token);
if (!$av || !$fotoId) {
    http_response_code(404);
    exit('Não encontrado.');
}

$foto = null;
foreach (listarFotosAvaliacao((int)$av['id']) as $f) {
    if ((int)$f['id'] === $fotoId) {
        $foto = $f;
        break;
    }
}
if (!$foto) {
    http_response_code(404);
    exit('Não encontrado.');
}

servirArquivoDriveOuLocal($foto['drive_file_id'] ?: null, $foto['arquivo_url'] ?: null);
