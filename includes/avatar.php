<?php
/**
 * includes/avatar.php — upload de foto de perfil (avatar) pelo próprio
 * usuário do sistema, 21/09/2026, "Permita os usuários do sistema editar
 * perfis deles trocar número e-mail nome fazer upload de avatar". Mesmo
 * espírito/técnica de `includes/marca.php` (GD puro, sem Imagick — "sem
 * dependência exótica que shared hosting não teria"), mas corta a imagem
 * pra preencher o quadrado (`cover`, sem barra/letterbox) em vez de
 * encaixar a imagem inteira com fundo sólido — foto de rosto fica melhor
 * cortada do que com sobra de fundo dos dois lados, diferente do logo da
 * marca (que precisa preservar a arte inteira).
 *
 * Sem coluna nova no banco de propósito: o caminho do arquivo é sempre
 * previsível por `usuario_id` (`admin/assets/avatars/{id}.png`), então
 * `avatarUrl()` só confere se o arquivo existe — nunca precisa gravar/ler
 * nada em `usuarios`. Cache-busting via `?v=filemtime()`, mesmo padrão já
 * usado pro CSS (`admin/assets/style.css?v=...`).
 */

require_once __DIR__ . '/db.php';

const AVATAR_DIR_DISCO = __DIR__ . '/../admin/assets/avatars';
const AVATAR_TAMANHO_PX = 256;

function avatarCaminhoDisco(int $usuarioId): string {
    return AVATAR_DIR_DISCO . '/' . $usuarioId . '.png';
}

/** URL pública já com cache-busting, ou null se o usuário nunca enviou avatar. */
function avatarUrl(int $usuarioId): ?string {
    $caminho = avatarCaminhoDisco($usuarioId);
    if (!is_file($caminho)) return null;
    return '/admin/assets/avatars/' . $usuarioId . '.png?v=' . filemtime($caminho);
}

/**
 * Recebe o upload ($_FILES['avatar']) e salva a versão cortada/quadrada.
 * Retorna ['ok' => bool, 'erro' => ?string]. Nunca lança — mesma
 * disciplina de processarUploadLogo() (includes/marca.php).
 */
function processarUploadAvatar(int $usuarioId, array $arquivo): array {
    if (($arquivo['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['ok' => false, 'erro' => 'Selecione uma foto antes de enviar.'];
    }
    if ($arquivo['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'erro' => 'Falha no envio do arquivo (tente de novo).'];
    }
    if ($arquivo['size'] > 5 * 1024 * 1024) {
        return ['ok' => false, 'erro' => 'Arquivo maior que 5MB.'];
    }
    if (!extension_loaded('gd')) {
        return ['ok' => false, 'erro' => 'Extensão GD do PHP não está disponível neste servidor.'];
    }

    // Nunca confia no Content-Type do navegador — mesmo cuidado de
    // includes/documentos.php::salvarUploadDocumento() e includes/marca.php.
    $mime = mime_content_type($arquivo['tmp_name']);
    $origem = match ($mime) {
        'image/png'  => @imagecreatefrompng($arquivo['tmp_name']),
        'image/jpeg' => @imagecreatefromjpeg($arquivo['tmp_name']),
        'image/webp' => @imagecreatefromwebp($arquivo['tmp_name']),
        default      => null,
    };
    if (!$origem) {
        return ['ok' => false, 'erro' => 'Formato não aceito ou arquivo corrompido — envie PNG, JPG ou WEBP.'];
    }

    @mkdir(AVATAR_DIR_DISCO, 0755, true);
    $quadrado = avatarGerarQuadradoCover($origem, AVATAR_TAMANHO_PX);
    imagepng($quadrado, avatarCaminhoDisco($usuarioId));
    imagedestroy($quadrado);
    imagedestroy($origem);

    return ['ok' => true, 'erro' => null];
}

function removerAvatar(int $usuarioId): void {
    $caminho = avatarCaminhoDisco($usuarioId);
    if (is_file($caminho)) @unlink($caminho);
}

/**
 * Corta (nunca deixa barra/letterbox) e redimensiona a imagem pra cobrir
 * um quadrado $tamanho×$tamanho — escala pelo MAIOR fator (cobre os dois
 * lados) e centraliza, cortando a sobra. Diferente de
 * marcaGerarQuadrado() (includes/marca.php), que encaixa a imagem
 * INTEIRA sem cortar (bom pra logo, ruim pra foto de rosto — sobraria
 * fundo sólido nas laterais).
 */
function avatarGerarQuadradoCover($origem, int $tamanho) {
    $larguraOrig = imagesx($origem);
    $alturaOrig = imagesy($origem);
    $escala = max($tamanho / $larguraOrig, $tamanho / $alturaOrig);
    $novaLargura = max(1, (int)round($larguraOrig * $escala));
    $novaAltura = max(1, (int)round($alturaOrig * $escala));

    $canvas = imagecreatetruecolor($tamanho, $tamanho);
    $offsetX = (int)(($novaLargura - $tamanho) / 2);
    $offsetY = (int)(($novaAltura - $tamanho) / 2);
    imagecopyresampled($canvas, $origem, -$offsetX, -$offsetY, 0, 0, $novaLargura, $novaAltura, $larguraOrig, $alturaOrig);
    return $canvas;
}
