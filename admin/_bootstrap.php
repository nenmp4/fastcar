<?php
/**
 * Bootstrap comum das páginas do admin. Toda página protegida faz
 * `require __DIR__ . '/_bootstrap.php';` como primeira linha — garante
 * libs carregadas e sessão autenticada antes de qualquer lógica de página.
 * admin/login.php NÃO usa este arquivo (senão vira loop de redirect).
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/usuarios.php';
require_once __DIR__ . '/../includes/oportunidades.php';
require_once __DIR__ . '/../includes/fipe.php';
require_once __DIR__ . '/../includes/whatsapp_config.php';
require_once __DIR__ . '/../includes/zapi_instancias.php';
require_once __DIR__ . '/../includes/fila_leads.php';
require_once __DIR__ . '/../includes/documentos.php';
require_once __DIR__ . '/../includes/gemini.php';

requireAdmin();
