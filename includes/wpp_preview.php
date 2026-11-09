<?php
/**
 * Prévia da última mensagem na lista de conversas dos WhatsApp Box
 * (compra/vendas/financeiro) — 09/11/2026, "ícones na lista, bem destacado":
 * mídia ganha um selo colorido (🎤 Áudio, 📷 Foto, 🎥 Vídeo, 📄 Documento)
 * em vez do "📎 [document]" cru. Espelhada em JS (wppPreviewHtml) em cada
 * inbox, pro polling desenhar igual.
 */
function wppTipoPreview(string $tipo, string $msg): ?string {
    $m = ltrim($msg);
    if ($tipo === 'audio' || str_starts_with($m, '🎤')) return 'audio';
    if ($tipo === 'image' || str_starts_with($m, '📷')) return 'image';
    if ($tipo === 'video' || str_starts_with($m, '🎥')) return 'video';
    if ($tipo === 'document' || str_starts_with($m, '📎')) return 'document';
    if ($tipo === 'sticker') return 'sticker';
    if ($tipo !== 'text' && $tipo !== '') return 'outro';
    return null;
}

function wppPreviewHtml(string $tipo, string $msg): string {
    $t = wppTipoPreview($tipo, $msg);
    if ($t === null) return e(mb_strimwidth($msg, 0, 60, '…'));
    $rotulo = ['audio' => '🎤 Áudio', 'image' => '📷 Foto', 'video' => '🎥 Vídeo', 'document' => '📄 Documento',
               'sticker' => '🙂 Figurinha', 'outro' => '📎 Mídia'][$t];
    $resto = trim(preg_replace('/^(🎤|📷|🎥|📎)\s*/u', '', trim($msg)));
    if (preg_match('/^\[[a-z_]+\]$/i', $resto) || in_array(mb_strtolower($resto), ['áudio', 'foto', 'vídeo', 'documento', 'audio', 'video'], true)) $resto = '';
    return '<span class="wpp-tipo-midia wpp-tipo-' . $t . '">' . $rotulo . '</span>'
         . ($resto !== '' ? ' ' . e(mb_strimwidth($resto, 0, 48, '…')) : '');
}

/** Mesma lógica em JS — colar dentro do <script> de cada inbox. */
function wppPreviewJs(): string {
    return <<<'JS'
function wppPreviewHtml(tipo, msg) {
    msg = (msg || '').trim(); tipo = tipo || '';
    var t = null;
    if (tipo === 'audio' || msg.indexOf('🎤') === 0) t = 'audio';
    else if (tipo === 'image' || msg.indexOf('📷') === 0) t = 'image';
    else if (tipo === 'video' || msg.indexOf('🎥') === 0) t = 'video';
    else if (tipo === 'document' || msg.indexOf('📎') === 0) t = 'document';
    else if (tipo === 'sticker') t = 'sticker';
    else if (tipo !== 'text' && tipo !== '') t = 'outro';
    var esc = function (s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; };
    if (!t) return esc(msg.length > 60 ? msg.slice(0, 60) + '…' : msg);
    var rot = {audio: '🎤 Áudio', image: '📷 Foto', video: '🎥 Vídeo', document: '📄 Documento', sticker: '🙂 Figurinha', outro: '📎 Mídia'}[t];
    var resto = msg.replace(/^(🎤|📷|🎥|📎)\s*/u, '').trim();
    if (/^\[[a-z_]+\]$/i.test(resto) || ['áudio', 'foto', 'vídeo', 'documento', 'audio', 'video'].indexOf(resto.toLowerCase()) >= 0) resto = '';
    return '<span class="wpp-tipo-midia wpp-tipo-' + t + '">' + rot + '</span>' + (resto ? ' ' + esc(resto.length > 48 ? resto.slice(0, 48) + '…' : resto) : '');
}
JS;
}
