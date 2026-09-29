<?php
/**
 * Endpoint JSON de polling — status do CANAL PRINCIPAL (Z-API ou Meta
 * oficial, conforme o toggle em Configurações), pro badge do topbar
 * (admin/_zapi_status.php).
 *
 * 29/09/2026 — trocado de zapiStatusPrincipalCache() direto pra
 * canalPrincipalStatusCache() (includes/whatsapp_config.php): o badge
 * antigo sempre mostrava Z-API mesmo depois do canal principal já ter
 * virado a API oficial. Cada provedor mantém seu próprio cache de 60s por
 * baixo, várias abas/usuários nunca batem na API mais de 1x por minuto.
 * Qualquer usuário logado pode chamar (o próprio badge aparece pra todo
 * perfil), _bootstrap.php já exige sessão via requireAdmin().
 */

require_once __DIR__ . '/_bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

echo json_encode(canalPrincipalStatusCache());
