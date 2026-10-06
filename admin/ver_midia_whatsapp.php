<?php
/**
 * Serve a mídia (áudio/imagem/vídeo) recebida numa mensagem do WhatsApp
 * Box — mesmo padrão de admin/ver_documento.php
 * (includes/documentos.php::servirArquivoDriveOuLocal()), mas com a
 * mesma trava de quem pode ver a CONVERSA (usuarioPodeVerConversaWhatsapp())
 * que o resto do admin/whatsapp_inbox.php já usa: consultor só vê mídia de
 * cliente onde ele é responsavel_id em alguma oportunidade, quem tem
 * perfilVeTudo() (super_admin/supervisor) vê tudo. Sem essa checagem em
 * separado, um consultor podia simplesmente trocar o ?id= na URL e ver
 * mídia de conversa de outro cliente.
 *
 * 16/09/2026, bug real achado em produção ("no ibox não consigo
 * visualizar as fotos", investigado até aqui): esse arquivo tinha ficado
 * pra trás usando `$_SESSION['admin_perfil'] === 'super_admin'` direto,
 * em vez de perfilVeTudo() como o resto do admin/whatsapp_inbox.php já
 * usa desde 15/09/2026 — supervisor via a conversa inteira na caixa (a
 * listagem já libera certo) mas clicar numa foto/áudio caía nessa
 * checagem desatualizada e dava 403, porque supervisor não é
 * literalmente 'super_admin'.
 *
 * 06/10/2026, achado real ("inbox - fotos está demorando abrir da geral
 * na velocidade"): nunca mais reaproveita
 * includes/documentos.php::servirArquivoDriveOuLocal() (usada por
 * ver_documento.php/ver_contrato.php, onde o arquivo de um mesmo id PODE
 * ser substituído depois — contrato assinado por cima do rascunho, por
 * exemplo, então aquele header sempre foi `no-store` de propósito).
 * Mídia de WhatsApp é diferente: o arquivo de uma mensagem NUNCA troca
 * depois de salvo (ver includes/whatsapp_inbox.php::lerMidiaWhatsappComCache()
 * pro porquê) — serve com cache local (poupa bater na Drive API de novo
 * pra reabrir a MESMA foto) e cache de navegador de verdade (poupa até a
 * própria request).
 */

require_once __DIR__ . '/_bootstrap.php';

$id = (int)($_GET['id'] ?? 0);
$db = getDB();
$stmt = $db->prepare("SELECT * FROM whatsapp_mensagens WHERE id = ?");
$stmt->execute([$id]);
$msg = $stmt->fetch();

if (!$msg) {
    http_response_code(404);
    exit('Mídia não encontrada.');
}

$responsavelFiltro = perfilVeTudo() ? null : (int)$_SESSION['admin_id'];
if (!usuarioPodeVerConversaWhatsapp($msg['telefone'], $responsavelFiltro)) {
    http_response_code(403);
    exit('Essa conversa não é de um cliente sob sua responsabilidade.');
}

$driveFileId = $msg['drive_file_id'] ?: null;
$arquivoUrl = $msg['arquivo_url'] ?: null;
if (!$driveFileId && !$arquivoUrl) {
    http_response_code(404);
    exit('Arquivo não encontrado.');
}

$arquivo = lerMidiaWhatsappComCache($driveFileId, $arquivoUrl);
if (!$arquivo) {
    http_response_code($driveFileId ? 502 : 404);
    exit($driveFileId ? 'Não foi possível baixar o documento agora. Tente novamente.' : 'Arquivo não encontrado.');
}

header('Content-Type: ' . $arquivo['mime']);
header('Content-Disposition: inline; filename="' . rawurlencode($arquivo['name']) . '"');
header('Content-Length: ' . strlen($arquivo['content']));
header('X-Content-Type-Options: nosniff');
// `private` (nunca cache compartilhado/CDN, é autenticado por conversa) +
// 30 dias + `immutable` — conteúdo nunca muda sob o mesmo id de mensagem
// (ver docblock acima), seguro o navegador nunca mais pedir de novo.
header('Cache-Control: private, max-age=2592000, immutable');
// startSecureSession() (chamada por requireAdmin() no _bootstrap.php) já
// mandou Pragma/Expires de "nunca cachear" por conta do session_start()
// (session.cache_limiter padrão do PHP) — nomes de header DIFERENTES de
// Cache-Control, então o header() acima não os substitui sozinho.
// Confirmado em teste real que todo navegador moderno já ignora os dois
// quando Cache-Control está presente (RFC 7234 §5.3), mas limpa explícito
// pra não depender disso.
header_remove('Pragma');
header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 2592000) . ' GMT');
echo $arquivo['content'];
