<?php
/**
 * Endpoint JSON de polling — status da instância Z-API PRINCIPAL, pro
 * badge do topbar (admin/_zapi_status.php).
 *
 * 28/09/2026, "vamos remover fallback" — voltou a chamar só
 * zapiStatusPrincipalCache() (includes/whatsapp_config.php, cache de 60s)
 * direto, sem o combinado com a instância fallback que existia antes.
 * Várias abas/usuários pedindo ao mesmo tempo nunca batem na Z-API mais
 * de 1x por minuto. Qualquer usuário logado pode chamar (o próprio badge
 * aparece pra todo perfil), _bootstrap.php já exige sessão via
 * requireAdmin().
 */

require_once __DIR__ . '/_bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

echo json_encode(zapiStatusPrincipalCache());
