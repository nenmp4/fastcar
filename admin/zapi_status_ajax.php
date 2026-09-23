<?php
/**
 * Endpoint JSON de polling — status da instância Z-API PRINCIPAL, pro
 * badge do topbar (admin/_zapi_status.php). 23/09/2026, "tem como colocar
 * status da instancia topo zpi conectado em destaque".
 *
 * Sempre passa por zapiStatusPrincipalCache() (includes/whatsapp_config.php),
 * que já cacheia 60s — várias abas/usuários pedindo ao mesmo tempo nunca
 * batem na Z-API mais de 1x por minuto. Qualquer usuário logado pode
 * chamar (o próprio badge aparece pra todo perfil), _bootstrap.php já
 * exige sessão via requireAdmin().
 */

require_once __DIR__ . '/_bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

echo json_encode(zapiStatusPrincipalCache());
