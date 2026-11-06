<?php
/**
 * Serve a foto/vídeo de uma mensagem do WhatsApp Vendas (06/11/2026 — 📷 e
 * 🚘 Estoque no inbox do vendedor). Mesma trava das outras rotas do inbox:
 * só quem pode ver ESSA conversa (vendedor responsável, ou quem vê tudo).
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../includes/vendas_inbox.php';
requireAcessoVendas();
liberarSessao();

$id = (int)($_GET['id'] ?? 0);
$st = getDB()->prepare("SELECT telefone, drive_file_id, arquivo_url FROM whatsapp_mensagens WHERE id = ? AND canal = 'vendas'");
$st->execute([$id]);
$m = $st->fetch();
if (!$m) { http_response_code(404); exit('Mídia não encontrada.'); }
$responsavelFiltro = perfilVeTudo() ? null : (int)$_SESSION['admin_id'];
if (!usuarioPodeVerConversaVendas((string)$m['telefone'], $responsavelFiltro)) {
    http_response_code(403);
    exit('Essa conversa não é de um comprador sob sua responsabilidade.');
}
servirArquivoDriveOuLocal($m['drive_file_id'] ?: null, $m['arquivo_url'] ?: null);
