<?php
/**
 * Serve a mídia (áudio/imagem/vídeo) recebida numa mensagem do WhatsApp
 * Box — mesmo padrão de admin/ver_documento.php
 * (includes/documentos.php::servirArquivoDriveOuLocal()), mas com a
 * mesma trava de quem pode ver a CONVERSA (usuarioPodeVerConversaWhatsapp())
 * que o resto do admin/whatsapp_inbox.php já usa: consultor só vê mídia de
 * cliente onde ele é responsavel_id em alguma oportunidade, super_admin vê
 * tudo. Sem essa checagem em separado, um consultor podia simplesmente
 * trocar o ?id= na URL e ver mídia de conversa de outro cliente.
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

$responsavelFiltro = $_SESSION['admin_perfil'] === 'super_admin' ? null : (int)$_SESSION['admin_id'];
if (!usuarioPodeVerConversaWhatsapp($msg['telefone'], $responsavelFiltro)) {
    http_response_code(403);
    exit('Essa conversa não é de um cliente sob sua responsabilidade.');
}

servirArquivoDriveOuLocal($msg['drive_file_id'] ?: null, $msg['arquivo_url'] ?: null);
