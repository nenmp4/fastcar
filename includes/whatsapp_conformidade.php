<?php
/**
 * Conformidade WhatsApp — 30/09/2026, depois da conta Meta Business da
 * Fastcar ter sido desativada PERMANENTEMENTE ("violação dos Termos de
 * Uso Aceitável" — causa apontada: "disparo em massa, sem consentimento,
 * pra qualificar leads"). Gate central pro único tipo de envio que
 * causou isso: mensagem ATIVA/PROATIVA disparada por AUTOMAÇÃO (cron),
 * sem o cliente ter escrito primeiro — cobre exatamente
 * `cron/recuperacao_leads.php` (contato que nunca virou cliente de
 * verdade) e o bloco 3 de `cron/followup.php` (reengajamento de lead
 * esfriando).
 *
 * Escopo confirmado com o usuário (AskUserQuestion, 30/09/2026) — NÃO
 * cobre:
 * - resposta reativa da IA no mesmo turno de uma mensagem recebida
 *   (sempre dentro da janela por construção, nunca precisou de gate);
 * - notificação interna pro WhatsApp pessoal de staff
 *   (`zapiEnviarTextoInterno()`, já uma função separada);
 * - envio MANUAL deliberado por um humano (WhatsApp Box, botão "enviar
 *   link de documentos/contrato") — é continuidade de conversa já em
 *   andamento, não disparo em massa; só precisa respeitar opt-out, que é
 *   verificado no ponto de entrada do webhook (ver
 *   `chatbot-whatsapp/includes/mensagens.php`), não aqui.
 *
 * Cliente/lead que já tem QUALQUER mensagem recebida da empresa antes de
 * hoje é "grandfathered": nunca precisa de opt-in formal retroativo pra
 * continuar sendo atendido (decisão 2 do usuário) — `podeEnviarAtivo()`
 * trata "já teve troca de mensagem de verdade" como consentimento
 * implícito, só exige `optin_whatsapp=1` explícito de quem NUNCA
 * conversou (o caso exato de `recuperacao_leads.php`).
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/whatsapp_config.php';

/**
 * Kill switch global — `config.whatsapp_envio_ativo`, mesmo padrão
 * `'1'`/`'0'` já usado por `backup_auto_ativo` em `admin/configuracoes.php`.
 * Desligado por padrão (ausente = '0' = false) — a spec pede
 * explicitamente "desligada por padrão", nunca ligar sozinho.
 */
function envioAtivoHabilitado(): bool {
    return getConfig('whatsapp_envio_ativo') === '1';
}

/**
 * Instância Z-API DEDICADA só pra envio ativo automático — NUNCA a
 * principal (ad-facing, onde o lead de anúncio chega) nem as dedicadas
 * de vendas/financeiro. Separação estrutural do número (regra 6 da
 * spec): sem essa instância configurada, todo envio ativo automático
 * fica bloqueado, nunca cai pra principal como "fallback de
 * conveniência" — diferente do fallback de PROVEDOR que já existe pra
 * vendas/financeiro em `zapiEnviarTexto()` (aquilo é sobre disponibilidade,
 * isto aqui é sobre risco: nunca queremos o número que recebe lead de
 * anúncio pago sendo usado pra reengajamento em massa de novo).
 */
function credenciaisNotificacaoOptin(): array {
    return [
        getConfig('zapi_notificacao_optin_instance_id') ?: '',
        getConfig('zapi_notificacao_optin_token') ?: '',
        getConfig('zapi_notificacao_optin_client_token') ?: '',
    ];
}

function credenciaisNotificacaoOptinConfigured(): bool {
    [$inst, $tok] = credenciaisNotificacaoOptin();
    return $inst !== '' && $tok !== '';
}

/** Acha o cliente_id pelo telefone SEM criar nada — null se ainda não existe cadastro. */
function clienteIdPorTelefone(string $telefone): ?int {
    $stmt = getDB()->prepare("SELECT id FROM clientes WHERE telefone = ?");
    $stmt->execute([normalizarTelefone($telefone)]);
    $id = $stmt->fetchColumn();
    return $id !== false ? (int)$id : null;
}

/** Existe pelo menos 1 mensagem RECEBIDA (direcao='in') desse telefone? Base do "grandfathered". */
function clienteTemHistoricoBidirecional(string $telefone): bool {
    $stmt = getDB()->prepare("SELECT 1 FROM whatsapp_mensagens WHERE telefone = ? AND direcao = 'in' LIMIT 1");
    $stmt->execute([normalizarTelefone($telefone)]);
    return (bool)$stmt->fetchColumn();
}

/**
 * Grava o consentimento — nunca sobrescreve se já setado (mesmo
 * `if ($campo === null)` de `oportunidades.aceita_ligacao_consultor`,
 * `includes/ia_qualificacao.php`). `$textoAceito` é a resposta LITERAL
 * do cliente, nunca uma paráfrase (regra #3 do projeto — nunca inventar).
 */
function registrarOptIn(int $clienteId, string $textoAceito, string $origem = 'conversa'): void {
    $stmt = getDB()->prepare("SELECT optin_whatsapp FROM clientes WHERE id = ?");
    $stmt->execute([$clienteId]);
    if ($stmt->fetchColumn() !== null) return; // já registrado, nunca sobrescreve

    getDB()->prepare("
        UPDATE clientes SET optin_whatsapp = 1, optin_em = datetime('now','localtime'),
            optin_origem = ?, optin_texto = ? WHERE id = ?
    ")->execute([clean($origem), clean($textoAceito), $clienteId]);
}

/** Cliente recusou explicitamente (resposta negativa à pergunta de opt-in da IA). */
function registrarOptInRecusado(int $clienteId, string $textoRecusa, string $origem = 'conversa'): void {
    $stmt = getDB()->prepare("SELECT optin_whatsapp FROM clientes WHERE id = ?");
    $stmt->execute([$clienteId]);
    if ($stmt->fetchColumn() !== null) return;

    getDB()->prepare("
        UPDATE clientes SET optin_whatsapp = 0, optin_em = datetime('now','localtime'),
            optin_origem = ?, optin_texto = ? WHERE id = ?
    ")->execute([clean($origem), clean($textoRecusa), $clienteId]);
}

/**
 * "SAIR"/"PARAR"/etc — grava o opt-out. Idempotente (rodar 2x não tem
 * efeito extra). Nunca desfaz sozinho — só um humano reabriria manual,
 * nunca implementado (não pedido).
 */
function registrarOptOut(int $clienteId): void {
    getDB()->prepare("
        UPDATE clientes SET optout_whatsapp = 1, optout_em = datetime('now','localtime') WHERE id = ?
    ")->execute([$clienteId]);
}

/** Mesmo que registrarOptOut(), lado de VENDAS (comprador não vira `clientes`). */
function registrarOptOutVenda(int $vendaId): void {
    getDB()->prepare("
        UPDATE vendas SET optout_whatsapp = 1, optout_em = datetime('now','localtime') WHERE id = ?
    ")->execute([$vendaId]);
}

/** Best-effort, nunca lança — mesmo padrão de `auditoriaRegistrar()`. */
function registrarTentativaEnvioAtivo(
    ?int $clienteId,
    string $telefone,
    string $canal,
    string $numeroOrigem,
    string $funcaoOrigem,
    string $status,
    string $motivo = ''
): void {
    try {
        getDB()->prepare("
            INSERT INTO whatsapp_envios_log (cliente_id, telefone, canal, numero_origem, funcao_origem, status, motivo_bloqueio)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ")->execute([$clienteId, normalizarTelefone($telefone), $canal, $numeroOrigem, $funcaoOrigem, $status, $motivo]);
    } catch (Throwable $e) {
        // log de conformidade nunca pode travar o envio/bloqueio em si
    }
}

/** Limite diário global de envio ativo — `config.whatsapp_limite_diario`, padrão 50. */
function whatsappLimiteDiario(): int {
    $v = getConfig('whatsapp_limite_diario');
    $n = $v !== null ? (int)$v : 50;
    return $n > 0 ? $n : 50;
}

/**
 * O gate central. Retorna ['ok'=>bool, 'motivo'=>?string]. Primeira
 * checagem que falhar vence — ordem importa (do mais barato/estrutural
 * pro mais caro/dependente de dado).
 */
function podeEnviarAtivo(?int $clienteId, string $telefone, string $funcaoOrigem): array {
    if (!envioAtivoHabilitado()) {
        return ['ok' => false, 'motivo' => 'flag_desligada'];
    }
    if (!credenciaisNotificacaoOptinConfigured()) {
        return ['ok' => false, 'motivo' => 'numero_dedicado_nao_configurado'];
    }

    $db = getDB();
    // $clienteId=null cobre o caso recuperacao_leads.php: contato que NUNCA
    // virou cliente de verdade aqui — nunca cria a linha em `clientes` só
    // pra poder checar opt-in (criar já seria "engajar" quem não consentiu),
    // trata como sem opt-in/sem opt-out direto, sempre cai no bloqueio
    // 'sem_optin' mais abaixo (não tem como ter histórico bidirecional nem
    // optin_whatsapp=1 sem nunca ter tido cadastro).
    $cliente = ['optin_whatsapp' => null, 'optout_whatsapp' => 0];
    if ($clienteId !== null) {
        $stmt = $db->prepare("SELECT optin_whatsapp, optout_whatsapp FROM clientes WHERE id = ?");
        $stmt->execute([$clienteId]);
        $encontrado = $stmt->fetch();
        if (!$encontrado) {
            return ['ok' => false, 'motivo' => 'cliente_nao_encontrado'];
        }
        $cliente = $encontrado;
    }
    if ((int)$cliente['optout_whatsapp'] === 1) {
        return ['ok' => false, 'motivo' => 'optout'];
    }

    // Grandfathered: já teve troca de mensagem real conta como consentimento
    // implícito (decisão 2) — só exige optin_whatsapp=1 explícito de quem
    // NUNCA conversou de verdade com a empresa.
    if (!clienteTemHistoricoBidirecional($telefone) && (int)($cliente['optin_whatsapp'] ?? 0) !== 1) {
        return ['ok' => false, 'motivo' => 'sem_optin'];
    }

    if (!automacaoDentroHorarioComercial()) {
        return ['ok' => false, 'motivo' => 'fora_horario'];
    }

    $telNorm = normalizarTelefone($telefone);

    // Rate limit por contato — 1 envio ativo (status='enviado') a cada 7 dias.
    $stmtUltimo = $db->prepare("
        SELECT MAX(created_at) FROM whatsapp_envios_log
        WHERE telefone = ? AND status = 'enviado'
    ");
    $stmtUltimo->execute([$telNorm]);
    $ultimoEnviado = $stmtUltimo->fetchColumn();
    if ($ultimoEnviado && (time() - strtotime($ultimoEnviado)) < 7 * 86400) {
        return ['ok' => false, 'motivo' => 'rate_limit_contato'];
    }

    // Máximo de 2 tentativas no total por contato (enviado OU falhou —
    // bloqueado nunca conta como "tentativa", nunca chegou a tentar de verdade).
    $stmtTentativas = $db->prepare("
        SELECT COUNT(*) FROM whatsapp_envios_log
        WHERE telefone = ? AND status IN ('enviado', 'falhou')
    ");
    $stmtTentativas->execute([$telNorm]);
    if ((int)$stmtTentativas->fetchColumn() >= 2) {
        return ['ok' => false, 'motivo' => 'max_tentativas'];
    }

    // Limite diário global.
    $stmtHoje = $db->query("
        SELECT COUNT(*) FROM whatsapp_envios_log
        WHERE status = 'enviado' AND date(created_at) = date('now','localtime')
    ");
    if ((int)$stmtHoje->fetchColumn() >= whatsappLimiteDiario()) {
        return ['ok' => false, 'motivo' => 'rate_limit_diario'];
    }

    // Circuit breaker — taxa de falha/bloqueio do dia > 2%, só avalia com
    // volume mínimo (senão 1 falha em 3 tentativas já dispararia à toa).
    $stmtTaxa = $db->query("
        SELECT
            SUM(CASE WHEN status IN ('falhou','bloqueado') THEN 1 ELSE 0 END) AS problemas,
            COUNT(*) AS total
        FROM whatsapp_envios_log
        WHERE date(created_at) = date('now','localtime')
    ");
    $taxa = $stmtTaxa->fetch();
    $totalHoje = (int)($taxa['total'] ?? 0);
    if ($totalHoje >= 20) {
        $pctProblema = ((int)$taxa['problemas'] / $totalHoje) * 100;
        if ($pctProblema > 2) {
            return ['ok' => false, 'motivo' => 'circuit_breaker'];
        }
    }

    return ['ok' => true, 'motivo' => null];
}

/**
 * Função de conveniência pros 2 call sites de categoria A — roda o gate,
 * loga bloqueio/sucesso/falha, espera 20-60s antes de mandar (nunca em
 * resposta reativa, só aqui) e manda pela instância DEDICADA. Nunca cai
 * pra Meta oficial nem pra instância principal em nenhum cenário — a
 * ausência da instância dedicada já é bloqueada dentro de
 * `podeEnviarAtivo()`.
 */
function enviarAtivoComGate(?int $clienteId, string $telefone, string $msg, string $funcaoOrigem): bool {
    $resultado = podeEnviarAtivo($clienteId, $telefone, $funcaoOrigem);
    if (!$resultado['ok']) {
        registrarTentativaEnvioAtivo($clienteId, $telefone, 'zapi', 'notificacao_optin', $funcaoOrigem, 'bloqueado', (string)$resultado['motivo']);
        return false;
    }

    // Espaçamento aleatório (item 5 da spec) — só pra envio ATIVO automático,
    // nunca em resposta reativa (isso atrasaria a IA respondendo o cliente
    // que acabou de escrever, categoria B, fora do escopo deste gate).
    sleep(random_int(20, 60));

    [$inst, $tok, $ctok] = credenciaisNotificacaoOptin();
    $telNorm = normalizarTelefone($telefone);
    $ok = strlen($telNorm) >= 12 && _zapiEnviarTextoBruto($telNorm, $msg, $inst, $tok, $ctok);

    registrarTentativaEnvioAtivo($clienteId, $telefone, 'zapi', 'notificacao_optin', $funcaoOrigem, $ok ? 'enviado' : 'falhou');
    return $ok;
}

/**
 * Agregação pro painel `admin/whatsapp_conformidade.php` — mesmo padrão
 * `dashboardX()` de `includes/dashboard.php` (função pura, array
 * associativo, a view só renderiza).
 */
function whatsappConformidadeResumo(): array {
    $db = getDB();

    $hoje = $db->query("
        SELECT
            SUM(CASE WHEN status = 'enviado' THEN 1 ELSE 0 END) AS enviados,
            SUM(CASE WHEN status = 'bloqueado' THEN 1 ELSE 0 END) AS bloqueados,
            SUM(CASE WHEN status = 'falhou' THEN 1 ELSE 0 END) AS falhas,
            COUNT(*) AS total
        FROM whatsapp_envios_log
        WHERE date(created_at) = date('now','localtime')
    ")->fetch();

    $porMotivo = $db->query("
        SELECT motivo_bloqueio, COUNT(*) AS n
        FROM whatsapp_envios_log
        WHERE status = 'bloqueado' AND date(created_at) = date('now','localtime')
          AND motivo_bloqueio != ''
        GROUP BY motivo_bloqueio
        ORDER BY n DESC
    ")->fetchAll();

    $totalOptOuts = (int)$db->query("SELECT COUNT(*) FROM clientes WHERE optout_whatsapp = 1")->fetchColumn();

    $total = (int)($hoje['total'] ?? 0);
    $falhas = (int)($hoje['falhas'] ?? 0);
    $bloqueados = (int)($hoje['bloqueados'] ?? 0);
    $taxaFalha = $total > 0 ? round((($falhas + $bloqueados) / $total) * 100, 1) : 0.0;

    return [
        'flag_ligada' => envioAtivoHabilitado(),
        'instancia_configurada' => credenciaisNotificacaoOptinConfigured(),
        'enviados_hoje' => (int)($hoje['enviados'] ?? 0),
        'bloqueados_hoje' => $bloqueados,
        'falhas_hoje' => $falhas,
        'total_hoje' => $total,
        'taxa_falha_hoje' => $taxaFalha,
        'circuit_breaker_ativo' => $total >= 20 && $taxaFalha > 2,
        'bloqueados_por_motivo' => $porMotivo,
        'total_optouts' => $totalOptOuts,
        'limite_diario' => whatsappLimiteDiario(),
    ];
}

/**
 * Contatos bloqueados por falta de opt-in nos últimos 7 dias — a "fila de
 * recontato manual" da regra 7 da spec (ligação/SMS/e-mail, nunca
 * WhatsApp automático pra quem nunca deu consentimento). Só lista, nunca
 * dispara nada — ação sempre manual, fora do sistema.
 */
function whatsappFilaRecontatoManual(): array {
    return getDB()->query("
        SELECT l.telefone, l.created_at, c.nome, c.id AS cliente_id
        FROM whatsapp_envios_log l
        LEFT JOIN clientes c ON c.telefone = l.telefone
        WHERE l.status = 'bloqueado' AND l.motivo_bloqueio = 'sem_optin'
          AND l.created_at >= datetime('now','localtime','-7 days')
        GROUP BY l.telefone
        ORDER BY l.created_at DESC
        LIMIT 100
    ")->fetchAll();
}

/**
 * Detecta pedido de opt-out (rule 4) — "SAIR", "PARAR", "STOP", "não
 * quero", "cancelar", "remover". Comparação EXATA (mensagem inteira,
 * normalizada — sem acento/pontuação/espaço), nunca substring — mesmo
 * cuidado de `nomeWhatsappPareceValido()` (`includes/whatsapp_config.php`):
 * "cancelar meu pedido de vaga" ou "quero remover a foto errada" nunca
 * disparam isso à toa, só a palavra/frase sozinha.
 */
function whatsappTextoEhOptOut(string $texto): bool {
    $norm = strtolower(preg_replace('/[^a-z0-9]/i', '', iconv('UTF-8', 'ASCII//TRANSLIT', $texto) ?: $texto));
    static $palavras = ['sair', 'parar', 'stop', 'naoquero', 'cancelar', 'remover'];
    return in_array($norm, $palavras, true);
}

/** Rótulo legível pro motivo de bloqueio — usado no painel. */
function whatsappConformidadeRotuloMotivo(string $motivo): string {
    return match ($motivo) {
        'flag_desligada' => 'Envio ativo desligado (Configurações)',
        'numero_dedicado_nao_configurado' => 'Instância dedicada não configurada',
        'optout' => 'Contato pediu SAIR',
        'sem_optin' => 'Sem opt-in (nunca conversou antes)',
        'fora_horario' => 'Fora do horário comercial',
        'rate_limit_contato' => 'Já recebeu envio ativo nos últimos 7 dias',
        'max_tentativas' => 'Já teve 2 tentativas de recontato',
        'rate_limit_diario' => 'Limite diário global atingido',
        'circuit_breaker' => 'Taxa de falha/bloqueio do dia acima de 2%',
        'cliente_nao_encontrado' => 'Cliente não encontrado',
        default => $motivo,
    };
}
