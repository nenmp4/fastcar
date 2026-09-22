<?php
/**
 * Serve o PDF/imagem de uma consulta ZapCar CONCLUÍDA (mesmo padrão de
 * admin/ver_documento.php — só admin logado, nunca link direto). 22/09/2026,
 * achado real: o `pdf_url` que a API devolve deu 404 no navegador (a URL
 * provavelmente exige o header Authorization, que um <a href> comum nunca
 * manda) — corrigido buscando o documento pelo SERVIDOR (com a chave, via
 * includes/zapcar.php::zapcarBaixarPdf()) e repassando os bytes prontos
 * pro navegador, nunca o pdf_url cru.
 */

require_once __DIR__ . '/_bootstrap.php';

$idLocal = (int)($_GET['id_local'] ?? 0);
$row = $idLocal ? zapcarBuscarConsultaLocal($idLocal) : null;

if (!$row || !$row['zapcar_id']) {
    http_response_code(404);
    exit('Consulta não encontrada.');
}
if ($row['status'] !== 'concluido') {
    http_response_code(409);
    exit('Essa consulta ainda não concluiu — o documento só fica disponível depois.');
}

$resultado = zapcarBaixarPdf($row['zapcar_id']);
if (!$resultado['ok']) {
    http_response_code($resultado['status'] ?? 502);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Não foi possível baixar o documento na ZapCar: ' . ($resultado['erro'] ?? 'falha desconhecida');
    exit;
}

header('Content-Type: ' . ($resultado['content_type'] ?: 'application/pdf'));
header('Content-Disposition: inline; filename="consulta-zapcar-' . $idLocal . '.pdf"');
echo $resultado['bytes'];
