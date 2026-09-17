<?php
/**
 * Bootstrap comum das páginas do admin. Toda página protegida faz
 * `require __DIR__ . '/_bootstrap.php';` como primeira linha — garante
 * libs carregadas e sessão autenticada antes de qualquer lógica de página.
 * admin/login.php NÃO usa este arquivo (senão vira loop de redirect).
 */

// Sistema interno com dados pessoais/financeiros de cliente — nunca pode ser
// indexado. Header (funciona em qualquer servidor) + robots.txt/.htaccess na
// raiz cobrem o resto, mas login.php não passa por este bootstrap (viraria
// loop de redirect), então repete a mesma linha lá.
header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet', true);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/usuarios.php';
require_once __DIR__ . '/../includes/oportunidades.php';
require_once __DIR__ . '/../includes/vendas.php';
require_once __DIR__ . '/../includes/whatsapp_inbox.php';
require_once __DIR__ . '/../includes/fipe.php';
require_once __DIR__ . '/../includes/whatsapp_config.php';
require_once __DIR__ . '/../includes/zapi_instancias.php';
require_once __DIR__ . '/../includes/fila_leads.php';
require_once __DIR__ . '/../includes/documentos.php';
require_once __DIR__ . '/../includes/extracao_documentos.php';
require_once __DIR__ . '/../includes/marca.php';
require_once __DIR__ . '/../includes/paginacao.php';
require_once __DIR__ . '/../includes/gemini.php';
require_once __DIR__ . '/../includes/openai.php';
require_once __DIR__ . '/../includes/contratos.php';
require_once __DIR__ . '/../includes/pendencias_pos_venda.php';
require_once __DIR__ . '/../includes/mail.php';
require_once __DIR__ . '/../includes/email_templates.php';

requireAdmin();

// 17/09/2026 — perfil `vendedor` (módulo de vendas) nunca acessa o funil
// de COMPRA (dados de outro negócio/cliente) — checagem central aqui, que
// toda página admin passa por _bootstrap.php, evita repetir essa trava
// arquivo por arquivo em cada tela de compra já existente.
if (($_SESSION['admin_perfil'] ?? '') === 'vendedor') {
    $paginaAtualVendedor = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $permitidasVendedor = ['vendas.php', 'venda.php', 'vendas_inbox.php', 'ver_midia_revenda.php', 'logout.php'];
    if (!in_array($paginaAtualVendedor, $permitidasVendedor, true)) {
        header('Location: /admin/vendas.php');
        exit;
    }
}
