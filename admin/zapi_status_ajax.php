<?php
/**
 * Endpoint JSON de polling — status OPERACIONAL da Z-API (principal, com
 * fallback de leitura da instância fallback quando a principal está fora
 * — zapiStatusOperacionalCache(), 24/09/2026, "reconectou o reserva mas a
 * bolinha continuava vermelha"), pro badge do topbar (admin/_zapi_status.php).
 *
 * Sempre passa por zapiStatusOperacionalCache() (includes/whatsapp_config.php),
 * que já cacheia 60s cada instância separadamente — várias abas/usuários
 * pedindo ao mesmo tempo nunca batem na Z-API mais de 1x por minuto por
 * instância. Qualquer usuário logado pode chamar (o próprio badge aparece
 * pra todo perfil), _bootstrap.php já exige sessão via requireAdmin().
 */

require_once __DIR__ . '/_bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

echo json_encode(zapiStatusOperacionalCache());
