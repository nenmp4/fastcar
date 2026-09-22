<?php
/**
 * AJAX de apoio pra Consulta Veicular ZapCar (includes/zapcar.php) em
 * admin/oportunidade.php — cria a consulta (PAGA, POST, com CSRF) e
 * deixa o navegador repollar o status (grátis, GET) até concluir/errar.
 * Restrito a admin logado (mesma trava de toda tela do painel); criar
 * consulta é bloqueado pro perfil supervisor (mesmo guard de toda ação de
 * escrita em admin/oportunidade.php — supervisor só acompanha, nunca
 * gasta saldo da conta ZapCar).
 */

require_once __DIR__ . '/_bootstrap.php';

header('Content-Type: application/json');

$acao = (string)($_REQUEST['acao'] ?? '');

if ($acao === 'consultar') {
    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        echo json_encode(['ok' => false, 'erro' => 'Sessão expirada, recarregue a página e tente de novo.']);
        exit;
    }
    if ($_SESSION['admin_perfil'] === 'supervisor') {
        http_response_code(403);
        echo json_encode(['ok' => false, 'erro' => 'Perfil de supervisão só acompanha — não pode gastar saldo da conta ZapCar.']);
        exit;
    }
    $oportunidadeId = (int)($_POST['oportunidade_id'] ?? 0);
    $placa = (string)($_POST['placa'] ?? '');
    if (!$oportunidadeId || $placa === '') {
        echo json_encode(['ok' => false, 'erro' => 'Dados incompletos.']);
        exit;
    }
    $resultado = zapcarIniciarConsultaVeicular($oportunidadeId, $placa, (int)$_SESSION['admin_id']);
    echo json_encode($resultado);
    exit;
}

if ($acao === 'status') {
    $idLocal = (int)($_GET['id_local'] ?? 0);
    if (!$idLocal) {
        echo json_encode(['ok' => false, 'erro' => 'id inválido']);
        exit;
    }
    $row = zapcarAtualizarStatusLocal($idLocal);
    echo json_encode(zapcarFormatarRespostaAjax($row));
    exit;
}

if ($acao === 'ultima') {
    $oportunidadeId = (int)($_GET['oportunidade_id'] ?? 0);
    $row = $oportunidadeId ? zapcarUltimaConsultaDaOportunidade($oportunidadeId) : null;
    echo json_encode($row ? zapcarFormatarRespostaAjax($row) : ['ok' => true, 'vazio' => true]);
    exit;
}

echo json_encode(['erro' => 'ação inválida']);
