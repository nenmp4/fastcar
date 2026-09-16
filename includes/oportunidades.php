<?php
/**
 * Camada central de manipulação de oportunidades — funil de 8 blocos.
 * Regra #6 do CLAUDE.md: NUNCA fazer UPDATE direto em oportunidades.etapa.
 * Toda mudança de etapa passa por mudarEtapa(), que grava o histórico junto.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/fila_leads.php';
require_once __DIR__ . '/whatsapp_config.php';
require_once __DIR__ . '/mail.php';
require_once __DIR__ . '/email_templates.php';

const ETAPAS_VALIDAS = [
    'whatsapp', 'qualificacao_ia', 'crm_preenchido', 'atendimento',
    'negociacao', 'presencial', 'fechado', 'sem_perfil', 'perdido',
];

const ETAPAS_ATIVAS = [
    'whatsapp', 'qualificacao_ia', 'crm_preenchido', 'atendimento',
    'negociacao', 'presencial',
];

function etapaLabel(string $etapa): string {
    $labels = [
        'whatsapp'        => '💬 WhatsApp',
        'qualificacao_ia' => '🤖 Qualificação IA',
        'crm_preenchido'  => '📋 CRM preenchido',
        'atendimento'     => '📞 Atendimento',
        'negociacao'      => '🤝 Negociação',
        'presencial'      => '🚗 Presencial',
        'fechado'         => '✅ Pasta fechada',
        'sem_perfil'      => '⚪ Sem perfil de compra',
        'perdido'         => '❌ Perdido',
    ];
    return $labels[$etapa] ?? ucfirst($etapa);
}

/**
 * Cria (ou reaproveita) o cliente por telefone e já abre a oportunidade na
 * etapa 'whatsapp' — regra #2: "salvar desde o primeiro contato", mesmo
 * antes de qualquer qualificação.
 */
/**
 * @param array $origem Atribuição de anúncio (bloco 1) — canal_origem,
 *   campanha_origem, anuncio_origem. Só é gravada na CRIAÇÃO do cliente
 *   (first-touch); se o telefone já existe, a origem original é mantida
 *   — inclusive se essa pessoa clicar num anúncio diferente meses depois
 *   pra negociar um 2º veículo (limitação conhecida: origem vive em
 *   `clientes`, não em `oportunidades`, então não temos atribuição por
 *   negócio pra quem repete contato — só first-touch por telefone).
 */
function criarOuAbrirOportunidade(string $telefone, string $nome = '', array $origem = []): array {
    $db = getDB();
    $telNorm = normalizarTelefone($telefone);
    if (!$telNorm || strlen($telNorm) < 12) {
        throw new InvalidArgumentException("Telefone inválido: {$telefone}");
    }

    $stmt = $db->prepare("SELECT id, nome FROM clientes WHERE telefone = ?");
    $stmt->execute([$telNorm]);
    $cliente = $stmt->fetch();

    if (!$cliente) {
        $db->prepare("
            INSERT INTO clientes (nome, telefone, canal_origem, campanha_origem, anuncio_origem)
            VALUES (?, ?, ?, ?, ?)
        ")->execute([
            clean($nome),
            $telNorm,
            clean((string)($origem['canal_origem'] ?? '')),
            clean((string)($origem['campanha_origem'] ?? '')),
            clean((string)($origem['anuncio_origem'] ?? '')),
        ]);
        $clienteId = (int)$db->lastInsertId();
        atualizarNomeFotoWhatsapp($clienteId, $telNorm);
    } else {
        $clienteId = (int)$cliente['id'];
        // Preenche nome se ainda estava vazio (ex: cliente criado só com
        // telefone via webhook, nome veio depois via qualificação)
        if (empty($cliente['nome']) && $nome) {
            $db->prepare("UPDATE clientes SET nome = ? WHERE id = ?")->execute([clean($nome), $clienteId]);
        }
        // foto_perfil_url NULL = nunca tentou buscar ainda; '' = já tentou e
        // não achou nada (não fica tentando de novo a cada mensagem nova
        // desse mesmo cliente, só 1x por cliente).
        $stmtFoto = $db->prepare("SELECT foto_perfil_url FROM clientes WHERE id = ?");
        $stmtFoto->execute([$clienteId]);
        if ($stmtFoto->fetchColumn() === null) {
            atualizarNomeFotoWhatsapp($clienteId, $telNorm);
        }
    }

    // Já existe oportunidade ativa aberta pra esse cliente? Reaproveita —
    // não cria uma segunda enquanto a primeira ainda está em andamento.
    // (Regra do Jean permite MAIS de um veículo por cliente, mas isso é
    // decisão explícita — ex: 2º carro depois do 1º já fechado/perdido —
    // não abertura automática de duplicata pela mesma mensagem de entrada.)
    $etapasAtivasPlaceholder = implode(',', array_fill(0, count(ETAPAS_ATIVAS), '?'));
    $stmtOp = $db->prepare("
        SELECT id FROM oportunidades
        WHERE cliente_id = ? AND etapa IN ({$etapasAtivasPlaceholder})
        ORDER BY id DESC LIMIT 1
    ");
    $stmtOp->execute([$clienteId, ...ETAPAS_ATIVAS]);
    $existente = $stmtOp->fetch();

    if ($existente) {
        return ['cliente_id' => $clienteId, 'oportunidade_id' => (int)$existente['id'], 'nova' => false];
    }

    $db->prepare("INSERT INTO oportunidades (cliente_id, etapa) VALUES (?, 'whatsapp')")
       ->execute([$clienteId]);
    $opId = (int)$db->lastInsertId();

    // Distribuição automática de leads (decisão do Jean): já na entrada
    // (bloco 2), não só quando a IA termina de qualificar — quem estiver
    // disponível no rodízio pega o lead; se ninguém, cai no plantão de fim
    // de expediente; se nem isso, fica sem responsável (igual antes de
    // existir essa fila).
    $responsavelId = atribuirResponsavelAutomatico();
    if ($responsavelId !== null) {
        $db->prepare("UPDATE oportunidades SET responsavel_id = ? WHERE id = ?")->execute([$responsavelId, $opId]);
    }

    mudarEtapa($opId, 'whatsapp', $responsavelId, 'Oportunidade criada — entrada pelo WhatsApp'
        . ($responsavelId ? ' (atribuída automaticamente)' : ''));

    notificarNovoLeadWhatsapp($opId, $nome ?: '(sem nome)', $telNorm);

    return ['cliente_id' => $clienteId, 'oportunidade_id' => $opId, 'nova' => true, 'responsavel_id' => $responsavelId];
}

/**
 * Busca nome/foto de perfil do WhatsApp (zapiBuscarContato()) e preenche
 * no cliente — fill-if-empty pro nome (nunca sobrescreve o que já tinha,
 * mesma regra do resto do projeto), sempre grava foto_perfil_url mesmo
 * que vazio ('' = "já tentei, não achou nada", NULL = "nunca tentei" —
 * evita tentar de novo em toda mensagem nova desse cliente). Best-effort,
 * nunca lança — chamado de dentro do webhook, não pode atrasar/quebrar o
 * fluxo de mensagem por causa disso.
 */
function atualizarNomeFotoWhatsapp(int $clienteId, string $telefone): void {
    try {
        $contato = zapiBuscarContato($telefone);
        $db = getDB();
        if (!$contato) {
            $db->prepare("UPDATE clientes SET foto_perfil_url = '' WHERE id = ? AND foto_perfil_url IS NULL")
               ->execute([$clienteId]);
            return;
        }
        $db->prepare("
            UPDATE clientes
            SET foto_perfil_url = ?,
                nome = CASE WHEN (nome IS NULL OR nome = '') AND ? <> '' THEN ? ELSE nome END
            WHERE id = ?
        ")->execute([$contato['foto_url'], clean($contato['nome']), clean($contato['nome']), $clienteId]);
    } catch (Throwable $e) {
        // melhor esforço — nunca pode travar a criação/atualização do lead.
    }
}

/**
 * Avisa por WhatsApp os números cadastrados em Configurações quando um
 * lead novo entra (bloco 2) — igual ao sino de notificação sonora no
 * admin (admin/_notify.php), mas alcança quem não está de olho no painel
 * na hora. `notificacao_leads_whatsapp` em config: números separados por
 * vírgula, mesmo padrão de lista que o JurídicoSaaS usa pra números
 * bloqueados. Nunca lança exceção nem bloqueia a criação do lead — aviso
 * é sempre melhor esforço (mesmo espírito de enviarEmail()/
 * geminiRegistrarTokens()).
 */
function notificarNovoLeadWhatsapp(int $oportunidadeId, string $nomeCliente, string $telefoneCliente): void {
    try {
        $lista = getConfig('notificacao_leads_whatsapp') ?: '';
        $numeros = array_filter(array_map('trim', explode(',', $lista)));
        if (!$numeros) return;

        $baseUrl = getConfig('app_base_url') ?: '';
        $link = $baseUrl ? rtrim($baseUrl, '/') . "/admin/oportunidade.php?id={$oportunidadeId}" : "oportunidade #{$oportunidadeId}";
        $msg = "🚗 Novo lead no Fastcar CRM!\nCliente: {$nomeCliente}\nTelefone: {$telefoneCliente}\n{$link}";

        foreach ($numeros as $numero) {
            zapiEnviarTexto($numero, $msg);
        }
    } catch (Throwable $e) {
        // notificação nunca pode derrubar a criação do lead
    }
}

/**
 * Avisa o CONSULTOR RESPONSÁVEL, por WhatsApp, quando a IA termina de
 * qualificar um lead (bloco 3→4) — seja qualificação completa normal ou
 * escalada por estagnação (includes/ia_qualificacao.php::iaProcessarTurno()).
 * Sem isso (bug real achado em 13/09/2026 auditando "e depois, tem processo
 * pro consultor ligar?"): a oportunidade só mudava de etapa pra
 * `crm_preenchido` silenciosamente — nenhum aviso saía, o consultor só
 * descobria que tinha lead pronto se checasse o painel por conta própria.
 * `notificarNovoLeadWhatsapp()` (acima) não resolve isso: ela dispara na
 * ENTRADA (bloco 2, antes da IA perguntar até o nome) pra uma lista
 * genérica de números — aqui é dirigido, pro WhatsApp pessoal
 * (`usuarios.whatsapp`) de quem é responsável por essa oportunidade
 * específica, com o resumo pronto pra já saber o que perguntar na ligação.
 * Sem responsável definido (ex: ninguém disponível na fila quando o lead
 * entrou) ou sem `usuarios.whatsapp` cadastrado, cai no aviso genérico de
 * `notificacao_leads_whatsapp` como fallback — nunca deixa passar batido.
 * Nunca lança, nunca bloqueia o fluxo principal (mesmo espírito de
 * notificarNovoLeadWhatsapp()).
 */
function notificarConsultorLeadQualificado(int $oportunidadeId, string $motivo = 'Qualificação concluída'): void {
    try {
        $db = getDB();
        $stmt = $db->prepare("
            SELECT o.resumo_ia, o.responsavel_id, c.nome AS cliente_nome, c.telefone AS cliente_telefone,
                   u.whatsapp AS consultor_whatsapp
            FROM oportunidades o
            JOIN clientes c ON c.id = o.cliente_id
            LEFT JOIN usuarios u ON u.id = o.responsavel_id
            WHERE o.id = ?
        ");
        $stmt->execute([$oportunidadeId]);
        $op = $stmt->fetch();
        if (!$op) return;

        $baseUrl = getConfig('app_base_url') ?: '';
        $link = $baseUrl ? rtrim($baseUrl, '/') . "/admin/oportunidade.php?id={$oportunidadeId}" : "oportunidade #{$oportunidadeId}";
        $msg = "📋 Lead pronto pra ligar! ({$motivo})\n"
             . "Cliente: {$op['cliente_nome']}\nTelefone: {$op['cliente_telefone']}\n\n"
             . ($op['resumo_ia'] ? "{$op['resumo_ia']}\n\n" : '')
             . $link;

        if (!empty($op['consultor_whatsapp'])) {
            zapiEnviarTexto($op['consultor_whatsapp'], $msg);
            return;
        }

        // Sem responsável ou sem WhatsApp cadastrado pra ele — fallback pra
        // não deixar o lead qualificado sem NENHUM aviso saindo.
        $lista = getConfig('notificacao_leads_whatsapp') ?: '';
        foreach (array_filter(array_map('trim', explode(',', $lista))) as $numero) {
            zapiEnviarTexto($numero, $msg);
        }
    } catch (Throwable $e) {
        // notificação nunca pode travar o fluxo da qualificação
    }
}

/**
 * Manda o telefone do consultor responsável direto pro CLIENTE via
 * WhatsApp — regra de negócio de 15/09/2026 (José/Jean: "cliente aceitou
 * que consultor ligar, encaminhar notificação ao consultor E enviar
 * telefone dele pro cliente"): o cliente não precisa ficar só esperando
 * a ligação, já pode chamar direto se quiser. Só dispara quando
 * `aceita_ligacao_consultor` é TRUE de verdade (nunca se recusou ou ainda
 * não respondeu — checagem estrita `=== 1`, não "truthy") E o consultor
 * responsável tem WhatsApp cadastrado; sem os dois, nunca manda mensagem
 * quebrada/sem número nenhum pro cliente. Mensagem fica registrada em
 * `whatsapp_mensagens` como qualquer outra mandada ao cliente, pro
 * consultor que assumir depois ver o que já foi dito.
 */
function enviarTelefoneConsultorAoCliente(int $oportunidadeId): void {
    try {
        $db = getDB();
        $stmt = $db->prepare("
            SELECT c.telefone AS cliente_telefone, o.aceita_ligacao_consultor,
                   u.nome AS consultor_nome, u.whatsapp AS consultor_whatsapp
            FROM oportunidades o
            JOIN clientes c ON c.id = o.cliente_id
            LEFT JOIN usuarios u ON u.id = o.responsavel_id
            WHERE o.id = ?
        ");
        $stmt->execute([$oportunidadeId]);
        $op = $stmt->fetch();
        if (!$op) return;
        if ((int)$op['aceita_ligacao_consultor'] !== 1) return;
        if (empty($op['consultor_whatsapp']) || empty($op['consultor_nome'])) return;

        $msg = "Perfeito! O(a) {$op['consultor_nome']}, da nossa equipe, vai te ligar em breve. "
             . "Se quiser chamar antes, o WhatsApp dele(a) é: {$op['consultor_whatsapp']}";
        if (!zapiEnviarTexto($op['cliente_telefone'], $msg)) return;

        $telNorm = normalizarTelefone($op['cliente_telefone']);
        $stmtC = $db->prepare("SELECT id FROM clientes WHERE telefone = ?");
        $stmtC->execute([$telNorm]);
        $clienteId = $stmtC->fetchColumn();
        $db->prepare("
            INSERT INTO whatsapp_mensagens (telefone, cliente_id, direcao, mensagem, tipo, enviado_por_ia, created_at)
            VALUES (?, ?, 'out', ?, 'text', 1, datetime('now','localtime'))
        ")->execute([$telNorm, $clienteId !== false ? (int)$clienteId : null, $msg]);
    } catch (Throwable $e) {
        // melhor esforço — nunca pode travar a conclusão da qualificação.
    }
}

/**
 * ÚNICO ponto do sistema que deve alterar oportunidades.etapa.
 * Grava o histórico (data + responsável) junto, sempre.
 */
function mudarEtapa(int $oportunidadeId, string $etapaNova, ?int $responsavelId = null, string $observacao = ''): bool {
    if (!in_array($etapaNova, ETAPAS_VALIDAS, true)) {
        throw new InvalidArgumentException("Etapa inválida: {$etapaNova}");
    }

    $db = getDB();
    $stmt = $db->prepare("SELECT etapa FROM oportunidades WHERE id = ?");
    $stmt->execute([$oportunidadeId]);
    $atual = $stmt->fetchColumn();
    if ($atual === false) {
        throw new RuntimeException("Oportunidade #{$oportunidadeId} não existe.");
    }

    // "Compra concluída exige checklist" (regra #7) — trava aqui, não só
    // na tela, pra nenhuma rota conseguir pular o checklist.
    if ($etapaNova === 'fechado' && !checklistFechamentoCompleto($oportunidadeId)) {
        throw new RuntimeException(
            "Oportunidade #{$oportunidadeId} não pode ser fechada: documentos obrigatórios pendentes."
        );
    }

    $db->beginTransaction();
    try {
        $db->prepare("UPDATE oportunidades SET etapa = ?, updated_at = datetime('now','localtime') WHERE id = ?")
           ->execute([$etapaNova, $oportunidadeId]);

        $db->prepare("
            INSERT INTO oportunidade_historico (oportunidade_id, etapa_anterior, etapa_nova, responsavel_id, observacao)
            VALUES (?, ?, ?, ?, ?)
        ")->execute([$oportunidadeId, $atual, $etapaNova, $responsavelId, clean($observacao)]);

        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    // Fora da transação de propósito: nunca queremos um rollBack() numa
    // transação já commitada só porque o envio de e-mail deu problema.
    if ($etapaNova === 'fechado') {
        enviarEmailCompraConcluida($oportunidadeId);
    }

    return true;
}

/**
 * E-mail de confirmação pro cliente quando a compra é concluída (bloco 8)
 * — 16/09/2026, pedido José/Jean ("cria todos os templates" dos e-mails
 * transacionais propostos). Best-effort, igual todo outro aviso automático
 * daqui: nunca pode travar o fechamento da oportunidade por causa disso.
 * Sem e-mail cadastrado, não manda nada (nunca quebra por falta de dado).
 */
function enviarEmailCompraConcluida(int $oportunidadeId): void {
    try {
        $db = getDB();
        $stmt = $db->prepare("
            SELECT o.veiculo_marca, o.veiculo_modelo, o.valor_final, c.nome AS cliente_nome, c.email AS cliente_email
            FROM oportunidades o JOIN clientes c ON c.id = o.cliente_id
            WHERE o.id = ?
        ");
        $stmt->execute([$oportunidadeId]);
        $op = $stmt->fetch();
        if (!$op || empty($op['cliente_email'])) return;

        $veiculo = trim(($op['veiculo_marca'] ?? '') . ' ' . ($op['veiculo_modelo'] ?? '')) ?: 'seu veículo';
        $valor = $op['valor_final'] ? 'R$ ' . number_format((float)$op['valor_final'], 2, ',', '.') : null;

        $corpo = "<p>Olá, " . htmlspecialchars($op['cliente_nome'] ?: '', ENT_QUOTES) . "!</p>"
            . "<p>A compra do seu <strong>{$veiculo}</strong> foi concluída com sucesso pela Fastcar. 🎉</p>"
            . ($valor ? "<p>Valor final: <strong>{$valor}</strong></p>" : '')
            . "<p>Toda a documentação e o contrato assinado ficam guardados com a gente. Qualquer dúvida sobre o processo, "
            . "é só chamar por aqui ou pelo WhatsApp.</p>"
            . "<p>Obrigado pela confiança!</p>";

        enviarEmail($op['cliente_email'], 'Compra concluída — Fastcar', emailLayout($corpo), $op['cliente_nome'] ?: '');
    } catch (Throwable $e) {
        // best-effort — nunca pode travar o fechamento da oportunidade.
    }
}

/**
 * Checklist do bloco 8 — regra #7: só libera 'fechado' se todo documento
 * marcado como obrigatório pra essa oportunidade estiver presente.
 */
function checklistFechamentoCompleto(int $oportunidadeId): bool {
    $db = getDB();
    // ⚠️ Bug real #1 (achado em teste): contar só "pendentes" (obrigatorio=1
    // com arquivo vazio) dá 0 tanto faz se está tudo preenchido quanto se
    // NENHUM documento foi cadastrado ainda — falso-positivo de COUNT em
    // query vazia. Por isso exige explicitamente total>0: sem nenhum
    // documento obrigatório cadastrado, o checklist NUNCA está completo.
    //
    // ⚠️ Bug real #2 (achado em teste E2E do funil completo, depois que o
    // Google Drive foi integrado): um documento "presente" pode estar em
    // arquivo_url (fallback local) OU em drive_file_id (Drive, preferido) —
    // checar só arquivo_url fazia o checklist NUNCA fechar depois que o
    // Drive foi configurado, mesmo com os 6 documentos enviados de verdade.
    $stmt = $db->prepare("
        SELECT
            COUNT(*) as total,
            SUM(CASE WHEN (arquivo_url IS NULL OR arquivo_url = '') AND (drive_file_id IS NULL OR drive_file_id = '') THEN 1 ELSE 0 END) as pendentes
        FROM oportunidade_documentos
        WHERE oportunidade_id = ? AND obrigatorio = 1
    ");
    $stmt->execute([$oportunidadeId]);
    $r = $stmt->fetch();
    return (int)$r['total'] > 0 && (int)$r['pendentes'] === 0;
}

/**
 * Marca perda em qualquer etapa — sempre exige motivo (regra do Jean:
 * "Sem perfil de compra" → registra motivo e encerra; "Perdido" também).
 */
function marcarPerdida(int $oportunidadeId, string $motivo, ?int $responsavelId = null, bool $semPerfil = false): bool {
    if (!$motivo) {
        throw new InvalidArgumentException('Motivo de perda é obrigatório.');
    }
    $db = getDB();
    $db->prepare("UPDATE oportunidades SET motivo_perda = ? WHERE id = ?")->execute([clean($motivo), $oportunidadeId]);
    return mudarEtapa($oportunidadeId, $semPerfil ? 'sem_perfil' : 'perdido', $responsavelId, $motivo);
}
