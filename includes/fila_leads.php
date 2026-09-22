<?php
/**
 * Distribuição automática de leads — decisão do Jean (12/09/2026):
 *   - Consultor liga/desliga disponibilidade manualmente no admin
 *   - Lead novo (bloco 2, na entrada) vai automaticamente pra quem estiver
 *     disponível, em rodízio (quem está há mais tempo sem receber é o
 *     próximo) — evita sobrecarregar sempre a mesma pessoa
 *   - Se TODO MUNDO estiver offline, cai no plantão de fim de expediente
 *     (usuarios.plantao_fim_expediente=1), que vira responsável normal da
 *     oportunidade — nenhum lead fica largado fora do horário
 *   - Sem ninguém disponível NEM plantão configurado: fica sem responsável
 *     mesmo (comportamento de hoje), alguém pega manualmente depois
 */

require_once __DIR__ . '/db.php';

/**
 * Teto de oportunidades ATIVAS por consultor no rodízio automático
 * (16/09/2026, achado real — José/Jean: "tinha vários lead na fila,
 * usuário Dayane logou veio tudo para ela, usuário Anderson logou e
 * Rafael ficaram sem lead"). Causa raiz: o rodízio só olha quem está
 * disponível NO MOMENTO que cada lead chega — se só a Dayane estava
 * online quando vários leads entraram em sequência, todos iam pra ela;
 * quando Anderson/Rafael ficaram disponíveis depois, não tinha lead novo
 * chegando naquele momento pra "compensar" o desequilíbrio já feito. O
 * teto não resolve o desequilíbrio já existente (ver
 * redistribuirFilaLeads() pra isso) — só evita que a MESMA pessoa
 * acumule mais de N daqui pra frente.
 *
 * Configurável (`config.fila_leads_max_ativas`), não mais fixo em 5 —
 * achado real no mesmo dia: com poucos consultores disponíveis e volume
 * acumulado de leads (ex: 33 ativos pra 3 consultores), um teto de 5 trava
 * a maioria sem responsável (3×5=15 < 33) até alguém aumentar. Editável
 * direto em Configurações, sem precisar de deploy toda vez que o volume
 * mudar. `5` continua sendo o padrão de fábrica se nunca foi configurado.
 */
function filaLeadsMaxAtivas(): int {
    $valor = (int)(getConfig('fila_leads_max_ativas') ?: 5);
    return $valor > 0 ? $valor : 5;
}

/** Conta oportunidades "ativas" (ainda em qualquer etapa do funil de compra
 *  em andamento) de um consultor — mesmo critério usado pro teto e pro
 *  card de fila em Configurações. */
function contarOportunidadesAtivas(int $usuarioId): int {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT COUNT(*) FROM oportunidades
        WHERE responsavel_id = ? AND etapa NOT IN ('fechado','sem_perfil','perdido')
    ");
    $stmt->execute([$usuarioId]);
    return (int)$stmt->fetchColumn();
}

/**
 * Escolhe o próximo usuário da fila e marca no rodízio (atualiza
 * ultimo_lead_recebido_em). Retorna null se não tiver ninguém disponível
 * nem plantão configurado — quem chama decide o que fazer (deixar sem
 * responsável, igual hoje).
 */
function atribuirResponsavelAutomatico(): ?int {
    $usuarioId = proximoDaFila(disponivelOnly: true);
    if ($usuarioId === null) {
        $usuarioId = proximoDaFila(disponivelOnly: false, apenasPlantao: true);
    }
    if ($usuarioId === null) {
        return null;
    }

    $db = getDB();
    $db->beginTransaction();
    try {
        // Contador monotônico, não timestamp: 2 leads no mesmo segundo não
        // podem empatar e cair sempre na mesma pessoa (bug real pego em
        // teste — SQLite datetime('now') só tem granularidade de segundo).
        $proximaPosicao = 1 + (int)$db->query("
            SELECT COALESCE(MAX(posicao_fila), 0) FROM usuarios WHERE perfil = 'consultor'
        ")->fetchColumn();
        $db->prepare("
            UPDATE usuarios SET posicao_fila = ?, ultimo_lead_recebido_em = datetime('now','localtime') WHERE id = ?
        ")->execute([$proximaPosicao, $usuarioId]);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    return $usuarioId;
}

function proximoDaFila(bool $disponivelOnly, bool $apenasPlantao = false): ?int {
    $db = getDB();
    $condicoes = ["bloqueado = 0", "perfil = 'consultor'"];
    if ($apenasPlantao) {
        $condicoes[] = 'plantao_fim_expediente = 1';
    } else {
        $condicoes[] = 'plantao_fim_expediente = 0';
        if ($disponivelOnly) $condicoes[] = 'disponivel = 1';
    }
    $where = implode(' AND ', $condicoes);

    // posicao_fila menor = há mais tempo (ou nunca) sem receber — próximo
    // da fila. id ASC só desempata o caso raro de dois nunca terem
    // recebido nada ainda (posicao_fila=0 nos dois).
    $stmt = $db->query("
        SELECT id FROM usuarios
        WHERE {$where}
        ORDER BY posicao_fila ASC, id ASC
    ");
    $candidatos = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Teto só se aplica ao rodízio normal (disponivelOnly) — plantão de
    // fim de expediente sempre recebe, senão o lead ficaria largado fora
    // do horário só porque o plantonista já está com 5+ ativas.
    if (!$disponivelOnly || $apenasPlantao) {
        return $candidatos ? (int)$candidatos[0] : null;
    }
    foreach ($candidatos as $id) {
        if (contarOportunidadesAtivas((int)$id) < filaLeadsMaxAtivas()) {
            return (int)$id;
        }
    }
    // Todo mundo disponível já está no teto — fica sem responsável (fila),
    // igual ao comportamento de "ninguém disponível" de hoje.
    return null;
}

/**
 * Etapas "ainda não tocadas de verdade" pelo consultor — atribuirResponsavelAutomatico()
 * roda já na ENTRADA do lead (bloco 2, etapa='whatsapp', antes da IA nem
 * qualificar — includes/oportunidades.php::criarOuAbrirOportunidade()), não
 * só quando chega em crm_preenchido. Então um consultor sobrecarregado pode
 * estar empilhado em QUALQUER uma dessas 3 etapas (conversa com a IA ainda
 * rolando, ou já qualificado esperando o consultor começar) — redistribuir
 * só 'crm_preenchido' deixava passar a maioria batido (achado real,
 * 16/09/2026: "apertei distribuir dayane está com 42 leads"). 'atendimento'
 * em diante já é contato humano de verdade — nunca mexido aqui.
 */
const FILA_LEADS_ETAPAS_NAO_TOCADAS = ['whatsapp', 'qualificacao_ia', 'crm_preenchido'];

/**
 * Corrige um desequilíbrio JÁ EXISTENTE na fila (o teto acima só evita que
 * aconteça de novo daqui pra frente), em 2 fases:
 *
 * Fase 1 — rebalanceia: move oportunidades ainda em etapa "não tocada"
 * (FILA_LEADS_ETAPAS_NAO_TOCADAS — entrada, qualificação IA ou CRM
 * preenchido, bloco 5 ainda não começou) de consultores acima do teto pra
 * quem está disponível e abaixo do teto, em rodízio.
 *
 * Fase 2 — adota órfãs: `atribuirResponsavelAutomatico()` só roda UMA VEZ,
 * na entrada do lead (bloco 2) — se ninguém estava disponível NEM em
 * plantão naquele instante, a oportunidade fica com `responsavel_id NULL`
 * pra sempre, porque nada revisita depois (achado real em produção,
 * 16/09/2026: funil cheio de leads reais, telefone válido, "Responsável: —",
 * provavelmente do período antes de qualquer consultor logar disponível).
 * Essa fase varre essas órfãs (mais antigas primeiro) e atribui pelo mesmo
 * rodízio, mesmo teto.
 *
 * Nunca mexe em oportunidade que o consultor já começou a trabalhar
 * (etapa='atendimento' em diante) — só adota/redistribui o que ainda está
 * "na fila" de verdade. Retorna um resumo (quantas movidas/atribuídas, de
 * quem pra quem) pro admin ver o que aconteceu.
 */
function redistribuirFilaLeads(int $executadoPor): array {
    $db = getDB();

    $consultores = $db->query("
        SELECT id, nome, disponivel FROM usuarios
        WHERE perfil = 'consultor' AND bloqueado = 0
        ORDER BY posicao_fila ASC, id ASC
    ")->fetchAll();

    $cargas = [];
    foreach ($consultores as $c) {
        $cargas[(int)$c['id']] = contarOportunidadesAtivas((int)$c['id']);
    }

    // Receptores: disponíveis e abaixo do teto — fila de destino em rodízio,
    // sempre pro que está com menos carga primeiro.
    $receptores = array_values(array_filter($consultores, function ($c) use ($cargas) {
        return (int)$c['disponivel'] === 1 && $cargas[(int)$c['id']] < filaLeadsMaxAtivas();
    }));

    $movidas = [];
    if ($receptores) {
        foreach ($consultores as $doador) {
            $doadorId = (int)$doador['id'];
            if ($cargas[$doadorId] <= filaLeadsMaxAtivas()) continue;

            $excedente = $cargas[$doadorId] - filaLeadsMaxAtivas();
            $etapasPh = implode(',', array_fill(0, count(FILA_LEADS_ETAPAS_NAO_TOCADAS), '?'));
            $stmt = $db->prepare("
                SELECT o.id, o.etapa, c.nome AS cliente_nome
                FROM oportunidades o
                JOIN clientes c ON c.id = o.cliente_id
                WHERE o.responsavel_id = ? AND o.etapa IN ({$etapasPh})
                ORDER BY o.created_at DESC
                LIMIT ?
            ");
            $params = array_merge([$doadorId], FILA_LEADS_ETAPAS_NAO_TOCADAS, [$excedente]);
            foreach ($params as $i => $val) {
                $tipo = is_int($val) ? PDO::PARAM_INT : PDO::PARAM_STR;
                $stmt->bindValue($i + 1, $val, $tipo);
            }
            $stmt->execute();
            $candidatas = $stmt->fetchAll();

            foreach ($candidatas as $oportunidade) {
                // Reordena receptores pelo mais vazio a cada movimentação,
                // pra espalhar em vez de encher só o primeiro da lista.
                usort($receptores, function ($a, $b) use ($cargas) {
                    return $cargas[(int)$a['id']] <=> $cargas[(int)$b['id']];
                });
                $receptores = array_values(array_filter($receptores, function ($r) use ($cargas) {
                    return $cargas[(int)$r['id']] < filaLeadsMaxAtivas();
                }));
                if (!$receptores) break 2;

                $receptor = $receptores[0];
                $receptorId = (int)$receptor['id'];

                $db->beginTransaction();
                try {
                    $db->prepare("UPDATE oportunidades SET responsavel_id = ? WHERE id = ?")
                       ->execute([$receptorId, $oportunidade['id']]);
                    $db->prepare("
                        INSERT INTO oportunidade_historico (oportunidade_id, etapa_anterior, etapa_nova, observacao, responsavel_id)
                        VALUES (?, ?, ?, ?, ?)
                    ")->execute([
                        $oportunidade['id'],
                        $oportunidade['etapa'],
                        $oportunidade['etapa'],
                        "Redistribuição automática da fila: de {$doador['nome']} para {$receptor['nome']} (equilíbrio de carga)",
                        $executadoPor,
                    ]);
                    $db->commit();
                } catch (Throwable $e) {
                    $db->rollBack();
                    throw $e;
                }

                $cargas[$doadorId]--;
                $cargas[$receptorId]++;
                $movidas[] = [
                    'oportunidade_id' => $oportunidade['id'],
                    'cliente_nome' => $oportunidade['cliente_nome'],
                    'de' => $doador['nome'],
                    'para' => $receptor['nome'],
                ];
            }
        }
    }

    // Fase 2 — adota órfãs (responsavel_id NULL): reaproveita o mesmo
    // $receptores (já refletindo a carga atualizada depois da fase 1).
    // Mais antigas primeiro — quem está esperando há mais tempo tem
    // prioridade de finalmente ganhar um responsável.
    $etapasPhOrfas = implode(',', array_fill(0, count(FILA_LEADS_ETAPAS_NAO_TOCADAS), '?'));
    $stmtOrfas = $db->prepare("
        SELECT o.id, o.etapa, c.nome AS cliente_nome
        FROM oportunidades o
        JOIN clientes c ON c.id = o.cliente_id
        WHERE o.responsavel_id IS NULL AND o.etapa IN ({$etapasPhOrfas})
        ORDER BY o.created_at ASC
    ");
    $stmtOrfas->execute(FILA_LEADS_ETAPAS_NAO_TOCADAS);
    $orfas = $stmtOrfas->fetchAll();

    foreach ($orfas as $oportunidade) {
        usort($receptores, function ($a, $b) use ($cargas) {
            return $cargas[(int)$a['id']] <=> $cargas[(int)$b['id']];
        });
        $receptores = array_values(array_filter($receptores, function ($r) use ($cargas) {
            return $cargas[(int)$r['id']] < filaLeadsMaxAtivas();
        }));
        if (!$receptores) break;

        $receptor = $receptores[0];
        $receptorId = (int)$receptor['id'];

        $db->beginTransaction();
        try {
            $db->prepare("UPDATE oportunidades SET responsavel_id = ? WHERE id = ?")
               ->execute([$receptorId, $oportunidade['id']]);
            $db->prepare("
                INSERT INTO oportunidade_historico (oportunidade_id, etapa_anterior, etapa_nova, observacao, responsavel_id)
                VALUES (?, ?, ?, ?, ?)
            ")->execute([
                $oportunidade['id'],
                $oportunidade['etapa'],
                $oportunidade['etapa'],
                "Redistribuição automática da fila: atribuído a {$receptor['nome']} (estava sem responsável)",
                $executadoPor,
            ]);
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        $cargas[$receptorId]++;
        $movidas[] = [
            'oportunidade_id' => $oportunidade['id'],
            'cliente_nome' => $oportunidade['cliente_nome'],
            'de' => '(sem responsável)',
            'para' => $receptor['nome'],
        ];
    }

    return $movidas;
}

/**
 * Equaliza leads AINDA NÃO TOCADAS (FILA_LEADS_ETAPAS_NAO_TOCADAS) entre os
 * consultores DISPONÍVEIS agora — diferente de redistribuirFilaLeads(), que
 * só move quem está acima do teto configurável; esta função nunca olha pro
 * teto, sempre calcula a média real de carga entre quem está disponível e
 * puxa de quem tem mais pra quem tem menos até ficar parelho (mesmo que
 * ninguém esteja "acima do limite"). 22/09/2026, achado real: consultor novo
 * entrando no time com o teto alto (100) não recebia nada da redistribuição
 * normal, porque ninguém estava de fato acima desse teto — "redistribuir"
 * nesse caso significa dividir o que já existe igualmente, não só socorrer
 * quem estourou um limite.
 *
 * Só considera consultor `disponivel=1` (doador E receptor) — nunca tira
 * fila de quem está offline sem ele saber, e nunca dá lead novo pra quem não
 * está disponível pra atender agora. Nunca mexe em oportunidade que já saiu
 * de FILA_LEADS_ETAPAS_NAO_TOCADAS (já em atendimento ou além) — mesma regra
 * de redistribuirFilaLeads().
 */
function equalizarFilaLeads(int $executadoPor): array {
    $db = getDB();

    $consultores = $db->query("
        SELECT id, nome FROM usuarios
        WHERE perfil = 'consultor' AND bloqueado = 0 AND disponivel = 1
        ORDER BY posicao_fila ASC, id ASC
    ")->fetchAll();

    if (count($consultores) < 2) return [];

    $etapasPh = implode(',', array_fill(0, count(FILA_LEADS_ETAPAS_NAO_TOCADAS), '?'));
    $stmtCarga = $db->prepare("
        SELECT COUNT(*) FROM oportunidades WHERE responsavel_id = ? AND etapa IN ({$etapasPh})
    ");

    $nomes = [];
    $cargas = [];
    foreach ($consultores as $c) {
        $id = (int)$c['id'];
        $nomes[$id] = $c['nome'];
        $stmtCarga->execute(array_merge([$id], FILA_LEADS_ETAPAS_NAO_TOCADAS));
        $cargas[$id] = (int)$stmtCarga->fetchColumn();
    }

    $stmtCandidata = $db->prepare("
        SELECT o.id, o.etapa, c.nome AS cliente_nome
        FROM oportunidades o
        JOIN clientes c ON c.id = o.cliente_id
        WHERE o.responsavel_id = ? AND o.etapa IN ({$etapasPh})
        ORDER BY o.created_at DESC
        LIMIT 1
    ");

    // Algoritmo guloso: a cada passo, tira 1 lead de quem tem MAIS agora e dá
    // pra quem tem MENOS agora, até a diferença ficar <= 1 (o mais parelho
    // que dá, já que lead não divide ao meio) — bem diferente de um corte
    // fixo tipo "só quem tá acima da média dá, só quem tá abaixo recebe",
    // que deixava sobra por não continuar puxando de quem ainda tinha mais
    // depois da 1ª rodada (achado no próprio teste isolado, antes do
    // commit: 6/4/0 virava 4/4/2 com o corte fixo, em vez do 3/4/3 real).
    $movidas = [];
    $limiteIteracoes = array_sum($cargas) + 1; // trava de segurança, nunca deveria bater nisso
    for ($i = 0; $i < $limiteIteracoes; $i++) {
        $maiorCarga = max($cargas);
        $menorCarga = min($cargas);
        if ($maiorCarga - $menorCarga <= 1) break; // já parelho dentro do possível

        $doadorId = array_search($maiorCarga, $cargas, true);
        $receptorId = array_search($menorCarga, $cargas, true);
        if ($doadorId === $receptorId) break; // nunca deveria acontecer aqui, defesa extra

        $stmtCandidata->execute(array_merge([$doadorId], FILA_LEADS_ETAPAS_NAO_TOCADAS));
        $oportunidade = $stmtCandidata->fetch();
        if (!$oportunidade) break; // carga contada não bate com lead real disponível — para, nunca chuta

        $db->beginTransaction();
        try {
            $db->prepare("UPDATE oportunidades SET responsavel_id = ? WHERE id = ?")
               ->execute([$receptorId, $oportunidade['id']]);
            $db->prepare("
                INSERT INTO oportunidade_historico (oportunidade_id, etapa_anterior, etapa_nova, observacao, responsavel_id)
                VALUES (?, ?, ?, ?, ?)
            ")->execute([
                $oportunidade['id'],
                $oportunidade['etapa'],
                $oportunidade['etapa'],
                "Equalização automática da fila: de {$nomes[$doadorId]} para {$nomes[$receptorId]} (dividir carga entre disponíveis)",
                $executadoPor,
            ]);
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        $cargas[$doadorId]--;
        $cargas[$receptorId]++;
        $movidas[] = [
            'oportunidade_id' => $oportunidade['id'],
            'cliente_nome' => $oportunidade['cliente_nome'],
            'de' => $nomes[$doadorId],
            'para' => $nomes[$receptorId],
        ];
    }

    return $movidas;
}

/** Toggle de disponibilidade — o próprio usuário liga/desliga no admin. */
function alternarDisponibilidade(int $usuarioId): bool {
    $db = getDB();
    $stmt = $db->prepare("SELECT disponivel FROM usuarios WHERE id = ?");
    $stmt->execute([$usuarioId]);
    $atual = (int)$stmt->fetchColumn();
    $novo = $atual ? 0 : 1;
    $db->prepare("UPDATE usuarios SET disponivel = ? WHERE id = ?")->execute([$novo, $usuarioId]);
    return (bool)$novo;
}

/** Marca/desmarca um usuário como plantão de fim de expediente (super_admin). */
function definirPlantaoFimExpediente(int $usuarioId, bool $ativo): void {
    $db = getDB();
    $db->prepare("UPDATE usuarios SET plantao_fim_expediente = ? WHERE id = ?")
       ->execute([$ativo ? 1 : 0, $usuarioId]);
}

/** Lista consultores com status de disponibilidade/plantão, pro admin. */
function listarFilaConsultores(): array {
    $db = getDB();
    return $db->query("
        SELECT id, nome, perfil, disponivel, plantao_fim_expediente, ultimo_lead_recebido_em, faltou_em
        FROM usuarios
        WHERE perfil = 'consultor' AND bloqueado = 0
        ORDER BY nome
    ")->fetchAll();
}

/**
 * Horário de expediente da fila (22/09/2026, "colocar usuarios para ficar
 * off line as 19:20... online 10 horas da manha... quero que vire padrão
 * todo dia") — padrão 10:00/19:20 se nunca configurado, formato "HH:MM".
 */
function filaHorarioAbertura(): string {
    $v = getConfig('fila_horario_abertura');
    return ($v && preg_match('/^\d{2}:\d{2}$/', $v)) ? $v : '10:00';
}
function filaHorarioFechamento(): string {
    $v = getConfig('fila_horario_fechamento');
    return ($v && preg_match('/^\d{2}:\d{2}$/', $v)) ? $v : '19:20';
}

/**
 * Roda no cron (cron/fila_horario_expediente.php), várias vezes ao dia.
 * Às 10:00 (configurável), liga `disponivel=1` de todo consultor não
 * bloqueado — EXCETO quem está marcado `faltou_em` = hoje (regra #22/09,
 * "coloca opção para marca faltou... redistribuir leads que fatou": quem
 * faltou não deve ser ligado de volta sozinho no meio do dia só porque
 * bateu o horário de abertura). Às 19:20 (configurável), desliga todo
 * mundo — mesmo quem faltou (já estava desligado, fica desligado, no-op).
 * Dedup por dia via `config.fila_expediente_abriu_{data}`/`_fechou_{data}`
 * — cada evento dispara só 1x por dia, mesmo rodando o cron a cada poucos
 * minutos; roda de novo no mesmo dia depois de já ter disparado não faz
 * nada (idempotente). Nunca mexe em `plantao_fim_expediente` (conceito
 * independente) — desligar todo mundo às 19:20 naturalmente deixa o
 * fallback de plantão assumir os leads que chegarem depois, já que
 * `proximoDaFila()` só cai pro plantão quando ninguém normal está
 * disponível.
 */
function aplicarHorarioExpedienteFila(): array {
    $db = getDB();
    $hoje = date('Y-m-d');
    $agora = date('H:i');
    $resultado = ['abriu' => false, 'fechou' => false, 'afetados' => 0];

    if ($agora >= filaHorarioAbertura() && !getConfig("fila_expediente_abriu_{$hoje}")) {
        $stmt = $db->prepare("
            UPDATE usuarios SET disponivel = 1
            WHERE perfil = 'consultor' AND bloqueado = 0
              AND (faltou_em IS NULL OR faltou_em != ?)
        ");
        $stmt->execute([$hoje]);
        setConfig("fila_expediente_abriu_{$hoje}", '1');
        $resultado['abriu'] = true;
        $resultado['afetados'] = $stmt->rowCount();
    }

    if ($agora >= filaHorarioFechamento() && !getConfig("fila_expediente_fechou_{$hoje}")) {
        $db->exec("UPDATE usuarios SET disponivel = 0 WHERE perfil = 'consultor' AND bloqueado = 0");
        setConfig("fila_expediente_fechou_{$hoje}", '1');
        $resultado['fechou'] = true;
    }

    return $resultado;
}

/**
 * Marca o consultor como ausente HOJE e redistribui na hora as
 * oportunidades ainda não tocadas dele (FILA_LEADS_ETAPAS_NAO_TOCADAS)
 * pros outros consultores disponíveis — sempre pro que está com menos
 * carga no momento, um por um (mesmo espírito de equalizarFilaLeads()).
 * Força `disponivel=0` na hora (nunca espera o horário de fechamento) —
 * quem faltou não deve continuar candidato a receber lead novo enquanto
 * o dia corre. Nunca mexe em oportunidade já em atendimento ou além.
 */
function marcarConsultorFaltou(int $usuarioId, int $executadoPor): array {
    $db = getDB();
    $hoje = date('Y-m-d');

    $stmt = $db->prepare("SELECT nome FROM usuarios WHERE id = ? AND perfil = 'consultor'");
    $stmt->execute([$usuarioId]);
    $ausente = $stmt->fetch();
    if (!$ausente) {
        return ['ok' => false, 'motivo' => 'Usuário não encontrado ou não é consultor.', 'movidas' => []];
    }

    $db->prepare("UPDATE usuarios SET faltou_em = ?, disponivel = 0 WHERE id = ?")
       ->execute([$hoje, $usuarioId]);

    $receptores = $db->query("
        SELECT id, nome FROM usuarios
        WHERE perfil = 'consultor' AND bloqueado = 0 AND disponivel = 1 AND id != {$usuarioId}
        ORDER BY posicao_fila ASC, id ASC
    ")->fetchAll();

    $etapasPh = implode(',', array_fill(0, count(FILA_LEADS_ETAPAS_NAO_TOCADAS), '?'));
    $movidas = [];

    if ($receptores) {
        $cargas = [];
        $stmtCarga = $db->prepare("SELECT COUNT(*) FROM oportunidades WHERE responsavel_id = ? AND etapa IN ({$etapasPh})");
        foreach ($receptores as $r) {
            $stmtCarga->execute(array_merge([(int)$r['id']], FILA_LEADS_ETAPAS_NAO_TOCADAS));
            $cargas[(int)$r['id']] = (int)$stmtCarga->fetchColumn();
        }

        $stmt = $db->prepare("
            SELECT o.id, o.etapa, c.nome AS cliente_nome
            FROM oportunidades o
            JOIN clientes c ON c.id = o.cliente_id
            WHERE o.responsavel_id = ? AND o.etapa IN ({$etapasPh})
            ORDER BY o.created_at DESC
        ");
        $stmt->execute(array_merge([$usuarioId], FILA_LEADS_ETAPAS_NAO_TOCADAS));
        $candidatas = $stmt->fetchAll();

        foreach ($candidatas as $oportunidade) {
            usort($receptores, fn($a, $b) => $cargas[(int)$a['id']] <=> $cargas[(int)$b['id']]);
            $receptor = $receptores[0];
            $receptorId = (int)$receptor['id'];

            $db->beginTransaction();
            try {
                $db->prepare("UPDATE oportunidades SET responsavel_id = ? WHERE id = ?")
                   ->execute([$receptorId, $oportunidade['id']]);
                $db->prepare("
                    INSERT INTO oportunidade_historico (oportunidade_id, etapa_anterior, etapa_nova, observacao, responsavel_id)
                    VALUES (?, ?, ?, ?, ?)
                ")->execute([
                    $oportunidade['id'],
                    $oportunidade['etapa'],
                    $oportunidade['etapa'],
                    "Redistribuído automaticamente: {$ausente['nome']} marcado como ausente hoje, lead passou pra {$receptor['nome']}",
                    $executadoPor,
                ]);
                $db->commit();
            } catch (Throwable $e) {
                $db->rollBack();
                throw $e;
            }

            $cargas[$receptorId]++;
            $movidas[] = [
                'oportunidade_id' => $oportunidade['id'],
                'cliente_nome' => $oportunidade['cliente_nome'],
                'para' => $receptor['nome'],
            ];
        }
    }

    return ['ok' => true, 'motivo' => '', 'ausente_nome' => $ausente['nome'], 'movidas' => $movidas];
}

/** Desfaz a marcação de falta (engano, ou consultor voltou no mesmo dia) — nunca desfaz a redistribuição já feita. */
function desmarcarConsultorFaltou(int $usuarioId): void {
    $db = getDB();
    $db->prepare("UPDATE usuarios SET faltou_em = NULL WHERE id = ?")->execute([$usuarioId]);
}
