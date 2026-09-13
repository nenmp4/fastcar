<?php
/**
 * includes/marca.php — upload da identidade visual (logo) da Fastcar,
 * pedido do José/Jean em 13/09/2026 ("mais fácil personalizar com a logo
 * original, coloca em Configurações pra subir logo, favicon e ícone PWA")
 * em vez de precisar mandar o arquivo por fora e um dev trocar na mão a
 * cada deploy. 1 upload só gera as variações que o sistema usa: logo do
 * cabeçalho do wizard público (proporção original, fundo transparente),
 * ícones do PWA (192/512, quadrados, fundo sólido da marca) e favicon
 * (mesmo princípio, tamanho menor). Usa só GD (extensão padrão do PHP,
 * sem Imagick) — mesmo espírito de "sem dependência exótica que shared
 * hosting não teria" do resto do projeto.
 */

require_once __DIR__ . '/db.php';

const MARCA_LOGO_PUBLICO     = __DIR__ . '/../public/assets/logo.png';
const MARCA_FAVICON_PUBLICO  = __DIR__ . '/../public/assets/favicon.png';
const MARCA_ICON_192         = __DIR__ . '/../admin/assets/img/icon-192.png';
const MARCA_ICON_512         = __DIR__ . '/../admin/assets/img/icon-512.png';
const MARCA_FAVICON_ADMIN    = __DIR__ . '/../admin/assets/img/favicon.png';
// Mesma cor do cabeçalho do wizard (public/documentos.php) — ícone/favicon
// com fundo transparente às vezes fica ilegível dependendo do tema do
// sistema/navegador de quem instalar o PWA, por isso preenche com a marca.
const MARCA_COR_FUNDO = '#151722';

/**
 * Recebe o upload ($_FILES['logo']) e gera as variações. Retorna
 * ['ok' => bool, 'erro' => ?string]. Nunca lança — falha de imagem
 * corrompida ou GD indisponível vira erro tratado, nunca fatal.
 */
function processarUploadLogo(array $arquivo): array {
    if (($arquivo['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['ok' => false, 'erro' => 'Selecione um arquivo antes de enviar.'];
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

    // Nunca confia no Content-Type do navegador — detecta pelo conteúdo real
    // (mesmo padrão de includes/documentos.php::salvarUploadDocumento()).
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
    imagesavealpha($origem, true);

    @mkdir(dirname(MARCA_LOGO_PUBLICO), 0755, true);
    @mkdir(dirname(MARCA_ICON_192), 0755, true);

    // Logo do cabeçalho — proporção original preservada, fundo transparente,
    // só reduz se for maior que o necessário (cabeçalho de celular nunca
    // precisa de mais que ~200px de altura).
    $logo = marcaRedimensionarProporcional($origem, 200);
    imagepng($logo, MARCA_LOGO_PUBLICO);
    if ($logo !== $origem) imagedestroy($logo);

    // Ícones do PWA e favicon — quadrados, fundo sólido da marca.
    foreach ([512 => MARCA_ICON_512, 192 => MARCA_ICON_192, 64 => MARCA_FAVICON_ADMIN] as $tamanho => $destino) {
        $quadrado = marcaGerarQuadrado($origem, $tamanho, MARCA_COR_FUNDO);
        imagepng($quadrado, $destino);
        imagedestroy($quadrado);
    }
    copy(MARCA_FAVICON_ADMIN, MARCA_FAVICON_PUBLICO);

    imagedestroy($origem);
    setConfig('marca_logo_atualizada_em', date('Y-m-d H:i:s'));

    return ['ok' => true, 'erro' => null];
}

/** Reduz a imagem se a altura passar de $alturaMax, preservando proporção e transparência. */
function marcaRedimensionarProporcional($origem, int $alturaMax) {
    $larguraOrig = imagesx($origem);
    $alturaOrig = imagesy($origem);
    if ($alturaOrig <= $alturaMax) return $origem;

    $escala = $alturaMax / $alturaOrig;
    $novaLargura = max(1, (int)round($larguraOrig * $escala));
    $canvas = imagecreatetruecolor($novaLargura, $alturaMax);
    imagealphablending($canvas, false);
    imagesavealpha($canvas, true);
    $transparente = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
    imagefill($canvas, 0, 0, $transparente);
    imagecopyresampled($canvas, $origem, 0, 0, 0, 0, $novaLargura, $alturaMax, $larguraOrig, $alturaOrig);
    return $canvas;
}

/** Encaixa a imagem inteira (sem cortar) num quadrado $tamanho×$tamanho, preenchendo a sobra com $corFundoHex. */
function marcaGerarQuadrado($origem, int $tamanho, string $corFundoHex) {
    $larguraOrig = imagesx($origem);
    $alturaOrig = imagesy($origem);
    $escala = min($tamanho / $larguraOrig, $tamanho / $alturaOrig);
    $novaLargura = max(1, (int)round($larguraOrig * $escala));
    $novaAltura = max(1, (int)round($alturaOrig * $escala));

    $canvas = imagecreatetruecolor($tamanho, $tamanho);
    [$r, $g, $b] = marcaHexParaRgb($corFundoHex);
    $fundo = imagecolorallocate($canvas, $r, $g, $b);
    imagefill($canvas, 0, 0, $fundo);

    $destX = (int)(($tamanho - $novaLargura) / 2);
    $destY = (int)(($tamanho - $novaAltura) / 2);
    imagecopyresampled($canvas, $origem, $destX, $destY, 0, 0, $novaLargura, $novaAltura, $larguraOrig, $alturaOrig);
    return $canvas;
}

function marcaHexParaRgb(string $hex): array {
    $hex = ltrim($hex, '#');
    return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
}

/** true se já existe alguma logo enviada (pra UI decidir mostrar preview vs "nunca enviada"). */
function marcaLogoConfigurada(): bool {
    return file_exists(MARCA_LOGO_PUBLICO);
}
