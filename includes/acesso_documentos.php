<?php
/**
 * Trava por carteira nos documentos de cliente (18/11/2026, "ajuda evitar
 * vazamento?" → "aplicar 1 2 3"). Antes, qualquer consultor logado abria
 * CNH/comprovante/contrato de QUALQUER cliente só trocando o id no fim do
 * link (admin/ver_documento.php etc só exigiam login). Agora:
 *
 *  - super_admin, supervisor e financeiro: tudo (mesma visão de sempre);
 *  - consultor: oportunidade em que é responsável ou quem fechou;
 *  - vendedor: venda em que é responsável;
 *  - qualquer um atribuído como avaliador numa vistoria daquele negócio
 *    (avaliador, ou consultor que também faz vistoria).
 *
 * A tela do negócio continua abrindo normal — só o ARQUIVO é barrado. Toda
 * tentativa barrada fica na Auditoria (`documento_acesso_negado`) e conta
 * pro alerta de volume (includes/auditoria.php).
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/auditoria.php';

function usuarioPodeAbrirDocumentoNegocio(?int $oportunidadeId, ?int $vendaId = null): bool {
    $perfil = (string)($_SESSION['admin_perfil'] ?? '');
    $me = (int)($_SESSION['admin_id'] ?? 0);
    if (!$me) return false;
    if (perfilVeTudo() || $perfil === 'financeiro') return true;

    $db = getDB();
    if ($oportunidadeId) {
        $st = $db->prepare("SELECT responsavel_id, fechado_por FROM oportunidades WHERE id = ?");
        $st->execute([$oportunidadeId]);
        $o = $st->fetch();
        if ($o && ((int)$o['responsavel_id'] === $me || (int)$o['fechado_por'] === $me)) return true;
    }
    if ($vendaId) {
        $st = $db->prepare("SELECT responsavel_id FROM vendas WHERE id = ?");
        $st->execute([$vendaId]);
        if ((int)$st->fetchColumn() === $me) return true;
    }
    if ($oportunidadeId || $vendaId) {
        $st = $db->prepare("
            SELECT 1 FROM veiculo_avaliacoes
            WHERE avaliador_id = ? AND ((? > 0 AND oportunidade_id = ?) OR (? > 0 AND venda_id = ?))
            LIMIT 1
        ");
        $st->execute([$me, (int)$oportunidadeId, (int)$oportunidadeId, (int)$vendaId, (int)$vendaId]);
        if ($st->fetchColumn()) return true;
    }
    return false;
}

/** Barra o arquivo: registra na Auditoria e responde 403 com mensagem clara. */
function negarAcessoDocumento(string $alvoTipo, int $alvoId, string $detalhe): never {
    auditoriaRegistrarDocumentoNegado($alvoTipo, $alvoId, $detalhe);
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: private, no-store');
    exit("Esse documento é de um cliente fora da sua carteira, por isso não abre pra você.\n"
       . "A tentativa ficou registrada. Se precisar dele, peça ao responsável pelo cliente ou a um supervisor.");
}
