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
function notificarDocumentosConfirmadosZap(?string $whatsappResponsavel, string $rotuloContato, string $nomeContato, string $veiculo, string $url): void {
    try {
        $baseUrl = getConfig('app_base_url') ?: '';
        $link = $baseUrl && $url ? rtrim($baseUrl, '/') . $url : '';
        $msg = "📎 {$rotuloContato} confirmou os documentos!\n{$rotuloContato}: {$nomeContato}"
             . ($veiculo !== '' ? "\nVeículo: {$veiculo}" : '')
             . ($link ? "\n{$link}" : '');

        if (!empty($whatsappResponsavel)) {
            zapiEnviarTextoInterno($whatsappResponsavel, $msg);
            return;
        }

        $lista = getConfig('notificacao_leads_whatsapp') ?: '';
        foreach (array_filter(array_map('trim', explode(',', $lista))) as $numero) {
            zapiEnviarTextoInterno($numero, $msg);
        }
    } catch (Throwable $e) {
        // best-effort — nunca pode travar o wizard do cliente/comprador
    }
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

/** Últimas N notificações (lidas + não lidas) do usuário, mais recente primeiro — painel do sino. */
function listarNotificacoes(int $usuarioId, int $limite = 30): array {
    $stmt = getDB()->prepare("
        SELECT id, tipo, titulo, mensagem, url, lida, created_at
        FROM notificacoes WHERE usuario_id = ? ORDER BY id DESC LIMIT ?
    ");
    $stmt->bindValue(1, $usuarioId, PDO::PARAM_INT);
    $stmt->bindValue(2, $limite, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function contarNotificacoesNaoLidas(int $usuarioId): int {
    $stmt = getDB()->prepare("SELECT COUNT(*) FROM notificacoes WHERE usuario_id = ? AND lida = 0");
    $stmt->execute([$usuarioId]);
    return (int)$stmt->fetchColumn();
}

function marcarNotificacoesLidas(int $usuarioId): void {
    getDB()->prepare("UPDATE notificacoes SET lida = 1 WHERE usuario_id = ? AND lida = 0")->execute([$usuarioId]);
}
