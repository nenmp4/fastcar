<?php
/**
 * Notificações in-app persistentes (sino do topbar) — 30/09/2026, "como
 * sabemos cliente preencheu... temos ter notificação clicável... rola pra
 * cima pra ver status das ações". Diferente do sino ORIGINAL
 * (admin/_notify.php/admin/notificacoes.php), que só detecta "lead novo"
 * computando created_at/updated_at ao vivo sem guardar nada — esta tabela
 * é só pra evento que precisa ficar CLICÁVEL e com HISTÓRICO (cliente
 * confirmou tudo no wizard de documentos, compra ou venda), sem tocar na
 * detecção de lead novo já validada em produção. Os dois convivem, veja
 * admin/notificacoes.php (mescla os dois) e admin/_notify.php (JS).
 */

require_once __DIR__ . '/whatsapp_config.php'; // zapiEnviarTextoInterno() — precisa pra notificarDocumentosConfirmadosZap()

function criarNotificacao(int $usuarioId, string $tipo, string $titulo, string $mensagem, string $url): void {
    getDB()->prepare("
        INSERT INTO notificacoes (usuario_id, tipo, titulo, mensagem, url) VALUES (?, ?, ?, ?, ?)
    ")->execute([$usuarioId, $tipo, $titulo, $mensagem, $url]);
}

/**
 * 22/10/2026, "em vendas só aparecer notificações somente da vendas" —
 * quais `tipo` da tabela `notificacoes` entram no sino quando o viewer
 * está dentro do módulo de vendas (admin/vendas.php e as outras 3 telas
 * que também setam $notifModulo='vendas' antes de incluir _notify.php).
 * `null` (módulo não informado — todo o resto do admin, comportamento
 * padrão) significa "sem filtro", nunca muda o que já era mostrado antes
 * desta feature existir. Lista em array pra crescer sozinha se algum dia
 * outro tipo de evento de venda for criado, sem precisar mexer de novo
 * em quem consome isso (listarNotificacoes()/contarNotificacoesNaoLidas()/
 * marcarNotificacoesLidas()).
 */
const TIPOS_NOTIFICACAO_VENDAS = ['documentos_confirmados_venda', 'visita_confirmada_venda', 'lead_quente_venda'];

/**
 * 24/10/2026, "não misturar notificações vendas com compra" — 'vendas' só
 * os tipos de venda; 'compra' (todo o resto do admin) tudo MENOS os de
 * venda ({'not_in': [...]}, pra tipo novo de compra entrar sozinho). null
 * = sem filtro (mantido só por compatibilidade).
 */
function tiposNotificacaoPorModulo(?string $modulo): ?array {
    if ($modulo === 'vendas') return TIPOS_NOTIFICACAO_VENDAS;
    if ($modulo === 'compra') return ['not_in' => TIPOS_NOTIFICACAO_VENDAS];
    return null;
}

/** Módulo do sino pra este usuário/página: vendedor é sempre vendas; resto é compra, salvo pedido explícito de vendas. */
function moduloNotificacaoAtual(?string $moduloPedido, string $perfil): string {
    if ($perfil === 'vendedor' || $moduloPedido === 'vendas') return 'vendas';
    return 'compra';
}

/**
 * Quem recebe: o responsável da negociação (se já tiver) + todo
 * super_admin/supervisor (perfilVeTudo(), mesmo raciocínio de "acompanha
 * tudo" já usado no resto do projeto) — nunca duplica se a mesma pessoa
 * cair nos dois grupos.
 */
function destinatariosNotificacao(?int $responsavelId): array {
    $db = getDB();
    $ids = [];
    if ($responsavelId) $ids[] = $responsavelId;
    foreach ($db->query("SELECT id FROM usuarios WHERE bloqueado = 0 AND perfil IN ('super_admin','supervisor')")->fetchAll() as $row) {
        $ids[] = (int)$row['id'];
    }
    return array_values(array_unique($ids));
}

/**
 * Manda o aviso de "cliente/comprador confirmou os documentos" pro
 * WhatsApp PESSOAL do responsável (usuarios.whatsapp) — 30/09/2026,
 * "cliente enviou documentação, notificar consultor no zap". Mesmo padrão
 * já usado em notificarAssinaturaContrato() (includes/contratos.php):
 * sem responsável definido ou sem WhatsApp cadastrado pra ele, cai no
 * aviso genérico de config.notificacao_leads_whatsapp como fallback,
 * nunca deixa passar batido. Best-effort (try/catch), nunca pode travar
 * a criação da notificação in-app do sino, que é o gatilho principal e
 * já validado — o zap é um canal extra, não substitui o sino.
 */
/**
 * Dispatch genérico do aviso interno por WhatsApp — pro responsável (se
 * tiver whatsapp cadastrado), senão pra lista de fallback genérica
 * (config.notificacao_leads_whatsapp). Extraído de dentro de
 * notificarDocumentosConfirmadosZap() em 22/10/2026 pra reaproveitar o
 * mesmo "responsável, senão fallback" em notificarVisitaConfirmadaZap()
 * sem duplicar o loop. Best-effort (try/catch) — nunca pode travar quem
 * chama (wizard do cliente/comprador, turno da IA).
 */
function enviarAvisoInternoWhatsapp(?string $whatsappResponsavel, string $msg): void {
    try {
        if (!empty($whatsappResponsavel)) {
            zapiEnviarTextoInterno($whatsappResponsavel, $msg);
            return;
        }
        $lista = getConfig('notificacao_leads_whatsapp') ?: '';
        foreach (array_filter(array_map('trim', explode(',', $lista))) as $numero) {
            zapiEnviarTextoInterno($numero, $msg);
        }
    } catch (Throwable $e) {
        // best-effort — nunca pode travar quem chama
    }
}

/**
 * 24/10/2026, "manda para número Jean sempre... toda pessoa que vier até a
 * loja" — diferente de enviarAvisoInternoWhatsapp() (números de
 * Configurações só como FALLBACK), visita à loja vai SEMPRE pros dois: o
 * vendedor responsável E todo número de config.notificacao_leads_whatsapp.
 * Mesmo número nos dois lugares nunca recebe 2x.
 */
function enviarAvisoVisitaLojaWhatsapp(?string $whatsappResponsavel, string $msg): void {
    try {
        $numeros = array_filter(array_map('trim', explode(',', getConfig('notificacao_leads_whatsapp') ?: '')));
        if (!empty($whatsappResponsavel)) array_unshift($numeros, $whatsappResponsavel);
        $jaEnviados = [];
        foreach ($numeros as $numero) {
            $chave = normalizarTelefone($numero);
            if ($chave === '' || isset($jaEnviados[$chave])) continue;
            $jaEnviados[$chave] = true;
            zapiEnviarTextoInterno($numero, $msg);
        }
    } catch (Throwable $e) {
        // best-effort — nunca pode travar quem chama
    }
}

/** 24/10/2026 — vendedor clicou "✅ Cliente compareceu": avisa responsável + números de Configurações. */
function notificarComparecimentoVenda(int $vendaId, ?string $quemMarcou = null): void {
    try {
        $db = getDB();
        $stmt = $db->prepare("
            SELECT v.comprador_nome, v.comprador_telefone, v.oportunidade_id, u.whatsapp AS responsavel_whatsapp, u.nome AS responsavel_nome
            FROM vendas v LEFT JOIN usuarios u ON u.id = v.responsavel_id
            WHERE v.id = ?
        ");
        $stmt->execute([$vendaId]);
        $v = $stmt->fetch();
        if (!$v) return;
        $veiculo = '';
        if ($v['oportunidade_id']) {
            $stmtOp = $db->prepare("SELECT veiculo_marca, veiculo_modelo FROM oportunidades WHERE id = ?");
            $stmtOp->execute([$v['oportunidade_id']]);
            $op = $stmtOp->fetch();
            if ($op) $veiculo = trim(($op['veiculo_marca'] ?? '') . ' ' . ($op['veiculo_modelo'] ?? ''));
        }
        $baseUrl = getConfig('app_base_url') ?: '';
        $link = $baseUrl ? rtrim($baseUrl, '/') . '/admin/venda.php?id=' . $vendaId : '';
        $msg = "🏁 Cliente chegou na loja!\nComprador: " . ($v['comprador_nome'] ?: '(sem nome)')
             . ($v['comprador_telefone'] ? "\nTelefone: {$v['comprador_telefone']}" : '')
             . ($veiculo !== '' ? "\nVeículo: {$veiculo}" : '')
             . ($v['responsavel_nome'] ? "\nVendedor: {$v['responsavel_nome']}" : '')
             . ($quemMarcou ? "\nRegistrado por: {$quemMarcou}" : '')
             . ($link ? "\n{$link}" : '');
        enviarAvisoVisitaLojaWhatsapp($v['responsavel_whatsapp'] ?? null, $msg);
    } catch (Throwable $e) {
        // best-effort
    }
}

/** 24/10/2026 — negociação de venda virou 🔥 quente pela IA: sino + WhatsApp (vendedor + números de Configurações), 1x por venda. */
function notificarLeadQuenteVenda(int $vendaId): void {
    try {
        $chave = 'venda_quente_avisado_' . $vendaId;
        if (getConfig($chave)) return;
        $db = getDB();
        $stmt = $db->prepare("
            SELECT v.responsavel_id, v.comprador_nome, v.comprador_telefone, v.veiculo_interesse_texto,
                   v.forma_pagamento_pretendida, v.valor_entrada_disponivel, v.valor_parcela_orcamento,
                   u.whatsapp AS responsavel_whatsapp, u.nome AS responsavel_nome
            FROM vendas v LEFT JOIN usuarios u ON u.id = v.responsavel_id
            WHERE v.id = ?
        ");
        $stmt->execute([$vendaId]);
        $v = $stmt->fetch();
        if (!$v) return;
        setConfig($chave, date('Y-m-d H:i:s'));

        $nome = $v['comprador_nome'] ?: '(sem nome)';
        $url = '/admin/venda.php?id=' . $vendaId;
        $responsavelId = $v['responsavel_id'] ? (int)$v['responsavel_id'] : null;
        foreach (destinatariosNotificacao($responsavelId) as $usuarioId) {
            criarNotificacao($usuarioId, 'lead_quente_venda', '🔥 Lead quente em vendas', $nome, $url);
        }

        $fmt = fn($x) => 'R$ ' . number_format((float)$x, 2, ',', '.');
        $baseUrl = getConfig('app_base_url') ?: '';
        $msg = "🔥 Lead QUENTE em vendas!\nComprador: {$nome}"
             . ($v['comprador_telefone'] ? "\nTelefone: {$v['comprador_telefone']}" : '')
             . ($v['veiculo_interesse_texto'] ? "\nProcura: {$v['veiculo_interesse_texto']}" : '')
             . ($v['forma_pagamento_pretendida'] ? "\nPagamento: {$v['forma_pagamento_pretendida']}" : '')
             . ($v['valor_entrada_disponivel'] ? "\nEntrada: " . $fmt($v['valor_entrada_disponivel']) : '')
             . ($v['valor_parcela_orcamento'] ? "\nParcela até: " . $fmt($v['valor_parcela_orcamento']) : '')
             . "\nVendedor: " . ($v['responsavel_nome'] ?: 'sem responsável')
             . ($baseUrl ? "\n" . rtrim($baseUrl, '/') . $url : '');
        enviarAvisoVisitaLojaWhatsapp($v['responsavel_whatsapp'] ?? null, $msg);
    } catch (Throwable $e) {
        // best-effort — nunca pode travar o turno da IA
    }
}

function notificarDocumentosConfirmadosZap(?string $whatsappResponsavel, string $rotuloContato, string $nomeContato, string $veiculo, string $url): void {
    $baseUrl = getConfig('app_base_url') ?: '';
    $link = $baseUrl && $url ? rtrim($baseUrl, '/') . $url : '';
    $msg = "📎 {$rotuloContato} confirmou os documentos!\n{$rotuloContato}: {$nomeContato}"
         . ($veiculo !== '' ? "\nVeículo: {$veiculo}" : '')
         . ($link ? "\n{$link}" : '');
    enviarAvisoInternoWhatsapp($whatsappResponsavel, $msg);
}

/** Cliente confirmou tudo no wizard de documentos do funil de COMPRA. */
function notificarDocumentosConfirmados(int $oportunidadeId): void {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT o.responsavel_id, o.veiculo_marca, o.veiculo_modelo, c.nome AS cliente_nome, u.whatsapp AS responsavel_whatsapp
        FROM oportunidades o
        JOIN clientes c ON c.id = o.cliente_id
        LEFT JOIN usuarios u ON u.id = o.responsavel_id
        WHERE o.id = ?
    ");
    $stmt->execute([$oportunidadeId]);
    $op = $stmt->fetch();
    if (!$op) return;

    $veiculo = trim(($op['veiculo_marca'] ?? '') . ' ' . ($op['veiculo_modelo'] ?? ''));
    $titulo = '✅ Cliente confirmou os documentos';
    $mensagem = ($op['cliente_nome'] ?: '(sem nome)') . ($veiculo !== '' ? ' — ' . $veiculo : '');
    $url = '/admin/oportunidade.php?id=' . $oportunidadeId;

    $responsavelId = $op['responsavel_id'] ? (int)$op['responsavel_id'] : null;
    foreach (destinatariosNotificacao($responsavelId) as $usuarioId) {
        criarNotificacao($usuarioId, 'documentos_confirmados_compra', $titulo, $mensagem, $url);
    }

    notificarDocumentosConfirmadosZap($op['responsavel_whatsapp'] ?? null, 'Cliente', $op['cliente_nome'] ?: '(sem nome)', $veiculo, $url);
}

/** Comprador confirmou tudo no wizard de documentos do funil de VENDA (revenda). */
function notificarDocumentosConfirmadosVenda(int $vendaId): void {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT v.responsavel_id, v.comprador_nome, v.oportunidade_id, u.whatsapp AS responsavel_whatsapp
        FROM vendas v
        LEFT JOIN usuarios u ON u.id = v.responsavel_id
        WHERE v.id = ?
    ");
    $stmt->execute([$vendaId]);
    $v = $stmt->fetch();
    if (!$v) return;

    $veiculo = '';
    if ($v['oportunidade_id']) {
        $stmtOp = $db->prepare("SELECT veiculo_marca, veiculo_modelo FROM oportunidades WHERE id = ?");
        $stmtOp->execute([$v['oportunidade_id']]);
        $op = $stmtOp->fetch();
        if ($op) $veiculo = trim(($op['veiculo_marca'] ?? '') . ' ' . ($op['veiculo_modelo'] ?? ''));
    }
    $titulo = '✅ Comprador confirmou os documentos';
    $mensagem = ($v['comprador_nome'] ?: '(sem nome)') . ($veiculo !== '' ? ' — ' . $veiculo : '');
    $url = '/admin/venda.php?id=' . $vendaId;

    $responsavelId = $v['responsavel_id'] ? (int)$v['responsavel_id'] : null;
    foreach (destinatariosNotificacao($responsavelId) as $usuarioId) {
        criarNotificacao($usuarioId, 'documentos_confirmados_venda', $titulo, $mensagem, $url);
    }

    notificarDocumentosConfirmadosZap($v['responsavel_whatsapp'] ?? null, 'Comprador', $v['comprador_nome'] ?: '(sem nome)', $veiculo, $url);
}

/**
 * Comprador confirmou que vem pessoalmente até a Fastcar (funil de VENDA/
 * revenda, vendas.aceita_visitar_fastcar) — 22/10/2026, "todo crm cliente
 * aceitar vim aqui nas fastcar notificar whatsapp do sistema notificação".
 * Chamada de dentro de iaAplicarDadosExtraidosVenda() (ia_qualificacao_vendas.php)
 * só na 1ª vez que aceita_visitar_fastcar vira 1 de verdade — nunca de
 * novo numa reconfirmação do mesmo "sim" (o campo é fill-if-empty, só
 * entra 1x). Mesmo padrão dos documentos confirmados: sino (clicável,
 * com histórico) + WhatsApp pro responsável (ou fallback genérico).
 * Best-effort — nunca pode travar o turno da IA.
 */
function notificarVisitaConfirmadaVenda(int $vendaId): void {
    try {
        $db = getDB();
        $stmt = $db->prepare("
            SELECT v.responsavel_id, v.comprador_nome, v.visita_dia_horario_texto, v.oportunidade_id,
                   u.whatsapp AS responsavel_whatsapp
            FROM vendas v LEFT JOIN usuarios u ON u.id = v.responsavel_id
            WHERE v.id = ?
        ");
        $stmt->execute([$vendaId]);
        $v = $stmt->fetch();
        if (!$v) return;

        $veiculo = '';
        if ($v['oportunidade_id']) {
            $stmtOp = $db->prepare("SELECT veiculo_marca, veiculo_modelo FROM oportunidades WHERE id = ?");
            $stmtOp->execute([$v['oportunidade_id']]);
            $op = $stmtOp->fetch();
            if ($op) $veiculo = trim(($op['veiculo_marca'] ?? '') . ' ' . ($op['veiculo_modelo'] ?? ''));
        }
        $dia = $v['visita_dia_horario_texto'] ?: '';
        $titulo = '📍 Comprador confirmou visita presencial';
        $mensagem = ($v['comprador_nome'] ?: '(sem nome)') . ($dia !== '' ? ' — ' . $dia : '') . ($veiculo !== '' ? ' — ' . $veiculo : '');
        $url = '/admin/venda.php?id=' . $vendaId;

        $responsavelId = $v['responsavel_id'] ? (int)$v['responsavel_id'] : null;
        foreach (destinatariosNotificacao($responsavelId) as $usuarioId) {
            criarNotificacao($usuarioId, 'visita_confirmada_venda', $titulo, $mensagem, $url);
        }

        $baseUrl = getConfig('app_base_url') ?: '';
        $link = $baseUrl ? rtrim($baseUrl, '/') . $url : '';
        $msg = "📍 Comprador confirmou que vem até a Fastcar!\nComprador: " . ($v['comprador_nome'] ?: '(sem nome)')
             . ($dia !== '' ? "\nDia/horário combinado: {$dia}" : '')
             . ($veiculo !== '' ? "\nVeículo: {$veiculo}" : '')
             . ($link ? "\n{$link}" : '');
        enviarAvisoVisitaLojaWhatsapp($v['responsavel_whatsapp'] ?? null, $msg);
    } catch (Throwable $e) {
        // best-effort — nunca pode travar o turno da IA
    }
}

/**
 * Monta o "AND tipo IN (?,?,...)" opcional — reaproveitado pelas 3 funções
 * abaixo, 22/10/2026 (escopo por módulo, ver tiposNotificacaoPorModulo()).
 * $tipos === null nunca filtra nada (comportamento de sempre, intocado).
 */
function _notificacoesFiltroTipo(?array $tipos): array {
    if ($tipos === null || $tipos === []) return ['', []];
    $operador = 'IN';
    if (isset($tipos['not_in'])) { $operador = 'NOT IN'; $tipos = $tipos['not_in']; }
    $tipos = array_values($tipos);
    $placeholders = implode(',', array_fill(0, count($tipos), '?'));
    return [" AND tipo {$operador} ({$placeholders})", $tipos];
}

/** Últimas N notificações (lidas + não lidas) do usuário, mais recente primeiro — painel do sino. */
function listarNotificacoes(int $usuarioId, int $limite = 30, ?array $tipos = null): array {
    [$filtroSql, $filtroParams] = _notificacoesFiltroTipo($tipos);
    $stmt = getDB()->prepare("
        SELECT id, tipo, titulo, mensagem, url, lida, created_at
        FROM notificacoes WHERE usuario_id = ?{$filtroSql} ORDER BY id DESC LIMIT ?
    ");
    $i = 1;
    $stmt->bindValue($i++, $usuarioId, PDO::PARAM_INT);
    foreach ($filtroParams as $t) $stmt->bindValue($i++, $t, PDO::PARAM_STR);
    $stmt->bindValue($i, $limite, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function contarNotificacoesNaoLidas(int $usuarioId, ?array $tipos = null): int {
    [$filtroSql, $filtroParams] = _notificacoesFiltroTipo($tipos);
    $stmt = getDB()->prepare("SELECT COUNT(*) FROM notificacoes WHERE usuario_id = ? AND lida = 0{$filtroSql}");
    $stmt->execute(array_merge([$usuarioId], $filtroParams));
    return (int)$stmt->fetchColumn();
}

/**
 * $tipos (opcional) marca como lida só os tipos relevantes ao módulo
 * atual — 22/10/2026. Crítico pra "em vendas só aparecer notificações
 * da vendas" não ter efeito colateral: abrir o painel do sino DENTRO do
 * módulo de vendas nunca pode marcar como lida (silenciosamente) um
 * evento de COMPRA que o usuário nem viu ainda, só porque os dois
 * compartilham a mesma linha em `notificacoes`.
 */
function marcarNotificacoesLidas(int $usuarioId, ?array $tipos = null): void {
    [$filtroSql, $filtroParams] = _notificacoesFiltroTipo($tipos);
    getDB()->prepare("UPDATE notificacoes SET lida = 1 WHERE usuario_id = ? AND lida = 0{$filtroSql}")
           ->execute(array_merge([$usuarioId], $filtroParams));
}
