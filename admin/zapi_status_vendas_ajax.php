<?php
/**
 * Endpoint JSON de polling — status do CANAL DE VENDAS (Evolution/Meta/
 * Z-API dedicados, conforme o que estiver configurado), pro badge do
 * módulo de vendas (admin/_zapi_status_vendas.php). Mesmo padrão exato de
 * admin/zapi_status_ajax.php (canal principal), 22/10/2026.
 *
 * Qualquer usuário logado com acesso ao módulo de vendas pode chamar —
 * _bootstrap.php já exige sessão via requireAdmin() e, pro perfil
 * `vendedor` (siloed), este arquivo precisa estar no allowlist central
 * (ver admin/_bootstrap.php) senão o guard redireciona (302) e o fetch()
 * tenta fazer JSON.parse() do HTML da página de redirecionamento — mesma
 * classe de bug já documentada pro badge/sino principal.
 */

require_once __DIR__ . '/_bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

echo json_encode(canalVendasStatusCache());
