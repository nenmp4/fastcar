<?php
/**
 * Serve a imagem da assinatura presencial (08/10/2026, assinatura na
 * retirada do veículo) — veiculo_avaliacoes.termo_ciente_assinatura_*.
 * Mesma trava de admin/ver_avaliacao_foto.php: só requireAcessoAvaliacoes(),
 * qualquer perfil com acesso ao módulo pode ver — não é dado sensível por
 * responsável específico, é a prova da própria vistoria.
 */

require_once __DIR__ . '/_bootstrap.php';
requireAcessoAvaliacoes();

$id = (int)($_GET['id'] ?? 0);
$av = buscarAvaliacao($id);

if (!$av) {
    http_response_code(404);
    exit('Vistoria não encontrada.');
}

servirArquivoDriveOuLocal($av['termo_ciente_assinatura_drive_file_id'] ?: null, $av['termo_ciente_assinatura_arquivo_url'] ?: null);
