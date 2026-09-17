<?php
/**
 * Helpers de segurança — mesmo padrão do JurídicoSaaS (includes/security.php).
 */

function startSecureSession(): void {
    if (session_status() === PHP_SESSION_NONE) {
        $lifetime = 60 * 60 * 24 * 14; // 14 dias
        ini_set('session.cookie_httponly', 1);
        ini_set('session.cookie_samesite', 'Lax');
        ini_set('session.use_strict_mode', 1);
        ini_set('session.gc_maxlifetime', (string)$lifetime);
        session_set_cookie_params(['lifetime' => $lifetime, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
        session_start();
    }
}

function generateCSRF(): string {
    startSecureSession();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validateCSRF(string $token): bool {
    startSecureSession();
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function csrfField(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(generateCSRF()) . '">';
}

function clean(string $input): string {
    return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES, 'UTF-8');
}

function e(string $str): string {
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}

function requireAdmin(): void {
    startSecureSession();
    if (empty($_SESSION['admin_id'])) {
        header('Location: /admin/login.php');
        exit;
    }
}

/**
 * Trava de permissão pra área restrita ao super_admin (Jean) — ex:
 * configurações de API, que dão acesso a credenciais sensíveis (Z-API) e
 * não devem ficar visíveis pro consultor. Chamar DEPOIS de
 * requireAdmin() (assume que já tem sessão logada).
 */
function requireSuperAdmin(): void {
    if (($_SESSION['admin_perfil'] ?? '') !== 'super_admin') {
        http_response_code(403);
        exit('Acesso restrito ao super_admin.');
    }
}

/**
 * super_admin e supervisor têm a mesma VISÃO (empresa inteira: todas as
 * oportunidades, conversas do WhatsApp, produtividade, qualidade da IA) —
 * mas só super_admin pode AGIR fora da própria conta (mudar etapa, enviar
 * mensagem, editar cadastro etc). 15/09/2026, pedido José/Jean: "preciso
 * ter perfil de supervisão que vai acompanhar tudo que consultores está
 * fazendo" — supervisor só acompanha, nunca substitui o consultor.
 */
function perfilVeTudo(): bool {
    return in_array($_SESSION['admin_perfil'] ?? '', ['super_admin', 'supervisor'], true);
}

/**
 * Trava de permissão pras telas de acompanhamento geral (produtividade,
 * qualidade da IA, origem de leads) — super_admin E supervisor, nunca
 * consultor. Diferente de requireSuperAdmin(): essas telas são só
 * LEITURA/relatório, não dão acesso a credencial nem ação destrutiva, por
 * isso o supervisor entra aqui mas não em Configurações/Usuários/Backup.
 */
function requireVisaoGeral(): void {
    if (!perfilVeTudo()) {
        http_response_code(403);
        exit('Acesso restrito.');
    }
}

/**
 * Quem pode acessar o módulo de VENDAS (17/09/2026, perfil `vendedor`
 * novo) — super_admin e supervisor continuam vendo tudo (mesmo espírito de
 * perfilVeTudo(), só que essa aqui trava a ÁREA, não decide filtro por
 * responsavel_id dentro dela — isso cada tela de vendas decide sozinha,
 * mesmo padrão "Minhas/Todas" do funil de compra), e o `vendedor` propriamente
 * dito. `consultor` nunca entra aqui — os dois módulos são times separados.
 */
function podeAcessarVendas(): bool {
    return in_array($_SESSION['admin_perfil'] ?? '', ['super_admin', 'supervisor', 'vendedor'], true);
}

function requireAcessoVendas(): void {
    if (!podeAcessarVendas()) {
        http_response_code(403);
        exit('Acesso restrito ao módulo de vendas.');
    }
}

/**
 * Normaliza telefone pro padrão BR com DDI 55 — mesmo helper do
 * JurídicoSaaS (includes/leads.php::normalizarTelefone). O telefone é a
 * chave de identificação da oportunidade (1 cadastro por telefone, regra
 * do Jean), então essa normalização precisa ser idêntica em toda entrada
 * de dado (WhatsApp, CRM manual, importação).
 */
function normalizarTelefone(string $phone): string {
    $phone = preg_replace('/\D/', '', $phone);
    if (!$phone || $phone === '0') return $phone;
    if (strlen($phone) <= 11) {
        $phone = '55' . $phone;
    }
    return $phone;
}
