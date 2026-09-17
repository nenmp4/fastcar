<?php
/**
 * AJAX de apoio pra busca de dados de fornecedor por CNPJ (BrasilAPI →
 * Receita Federal, includes/cnpj.php::cnpjConsultar()) em
 * admin/financeiro-fornecedores.php — mesmo padrão de admin/fipe_ajax.php
 * (GET, sem CSRF — é só leitura, não muda estado nenhum). Restrito a quem
 * tem acesso ao módulo financeiro, mesma trava da tela de fornecedores.
 */

require_once __DIR__ . '/_bootstrap.php';
requireAcessoFinanceiro();

header('Content-Type: application/json');

$cnpj = (string)($_GET['cnpj'] ?? '');
$dados = cnpjConsultar($cnpj);

if ($dados === null) {
    echo json_encode(['ok' => false, 'msg' => 'CNPJ inválido, não encontrado na Receita, ou o serviço está fora do ar no momento.']);
    exit;
}

echo json_encode(['ok' => true, 'dados' => $dados]);
