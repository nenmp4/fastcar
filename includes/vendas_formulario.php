<?php
/**
 * Formulário de entrada do lead de VENDAS (26/10/2026, "vamos criar
 * formulários registrar no CRM do mesmo jeito que a IA" / "só vem pro
 * WhatsApp se ele passar pelo formulário").
 *
 * Anúncio → fastcar.solutions/quero-carro.php → o cliente preenche → o lead entra em
 * `vendas` igual ao lead que a IA qualifica (crm_preenchido + resumo em
 * checklist + temperatura + vendedor avisado). Quem não tem a entrada
 * mínima pro caso dele vira 'sem_perfil' e nunca recebe o botão do
 * WhatsApp nem chega no vendedor.
 *
 * Entrada mínima = a mesma regra que a IA de vendas já usa
 * (includes/ia_qualificacao_vendas.php, regra da Fastcar 24/10/2026).
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/vendas.php';
require_once __DIR__ . '/notificacoes.php';
require_once dirname(__DIR__) . '/chatbot-whatsapp/includes/mensagens.php'; // pausarIA()

const VENDA_FORM_TIPOS = ['carro' => 'Carro', 'utilitario' => 'Utilitário (picape/van/furgão)', 'moto' => 'Moto'];
const VENDA_FORM_USOS = ['passeio' => 'Passeio / família', 'aplicativo' => 'Aplicativo (Uber/99/entrega)', 'utilitario' => 'Trabalho / carga'];

/**
 * Faixas de entrada (30/10/2026, "as pessoas não gostam de preencher") — no
 * formulário a entrada virou botão de faixa em vez de digitar valor.
 * [mínimo, máximo (null = sem teto), rótulo]. Passa na entrada mínima quando
 * o mínimo do caso cabe na faixa; 🔥 quente quem tem o PISO ≥ R$ 15 mil.
 */
const VENDA_FORM_FAIXAS = [
    'nao'  => [0, 0, 'Não tenho entrada'],
    'ate5' => [1, 5000, 'Até R$ 5 mil'],
    '5a10' => [5000, 10000, 'R$ 5 a 10 mil'],
    '10a15' => [10000, 15000, 'R$ 10 a 15 mil'],
    '15a25' => [15000, 25000, 'R$ 15 a 25 mil'],
    '25mais' => [25000, null, 'Mais de R$ 25 mil'],
];

/** Entrada mínima (R$) pro caso — moto R$ 5 mil, aplicativo R$ 14 mil, passeio/trabalho R$ 10 mil, utilitário R$ 20 mil (Jean, 26/10/2026). */
function vendaFormularioMinimo(string $tipo, string $uso): float {
    if ($tipo === 'moto') return 5000.0;
    if ($tipo === 'utilitario') return 20000.0;
    if ($uso === 'aplicativo') return 14000.0;
    return 10000.0;
}

/** Mesma faixa da IA: abaixo do mínimo = frio; atingiu e ≥ R$ 15 mil = quente; atingiu e < 15 mil = morno. */
function vendaFormularioTemperatura(float $entrada, float $minimo): string {
    if ($entrada < $minimo) return 'frio';
    return $entrada >= 15000 ? 'quente' : 'morno';
}

/** Código curto que vai no texto do WhatsApp pra ligar a conversa ao formulário. */
function vendaFormularioCodigo(int $vendaId): string {
    return 'FC' . $vendaId;
}

/** Acha o id da venda num texto tipo "...(cód. FC1234)". null se não tiver. */
function vendaFormularioIdDoTexto(string $texto): ?int {
    return preg_match('/\bFC(\d{1,9})\b/i', $texto, $m) ? (int)$m[1] : null;
}

/** Link curto pras mensagens de WhatsApp (ver carro/index.php): 'r' follow-up, 'w' chamou direto. */
function vendaFormularioUrlCurta(string $codigo): string {
    return 'https://fastcar.solutions/carro?' . $codigo;
}

function vendaFormularioUrl(array $utm = []): string {
    // Sempre no domínio público (fastcar.solutions), nunca no do sistema.
    return 'https://fastcar.solutions/quero-carro.php' . ($utm ? '?' . http_build_query($utm) : '');
}

/** Liga/desliga em Configurações: lead que chama direto no WhatsApp de vendas recebe o link do formulário em vez da IA. */
function vendaFormularioObrigatorio(): bool {
    return getConfig('vendas_formulario_obrigatorio') === '1';
}

/**
 * Registra o formulário no CRM. $d: nome, telefone, tipo, uso, entrada
 * (float|null — null = "não tenho entrada"), parcela (float|null),
 * visita (texto livre), utm_source/utm_campaign/utm_content.
 * Retorna ['ok', 'erro', 'venda_id', 'qualificado', 'minimo', 'temperatura'].
 */
function registrarFormularioVenda(array $d): array {
    $nome = trim((string)($d['nome'] ?? ''));
    $tel = normalizarTelefone((string)($d['telefone'] ?? ''));
    $tipo = array_key_exists($d['tipo'] ?? '', VENDA_FORM_TIPOS) ? $d['tipo'] : '';
    $uso = array_key_exists($d['uso'] ?? '', VENDA_FORM_USOS) ? $d['uso'] : '';
    if ($tipo === 'moto') $uso = $uso ?: 'passeio';
    if ($tipo === 'utilitario') $uso = $uso ?: 'utilitario';
    if ($nome === '') return ['ok' => false, 'erro' => 'Informe seu nome.'];
    if (!$tel || strlen($tel) < 12) return ['ok' => false, 'erro' => 'Informe um WhatsApp válido com DDD.'];
    if ($tipo === '' || $uso === '') return ['ok' => false, 'erro' => 'Escolha o tipo de veículo e o uso.'];

    $entrada = isset($d['entrada']) && $d['entrada'] !== null ? max(0.0, (float)$d['entrada']) : 0.0;
    $parcela = isset($d['parcela']) && $d['parcela'] !== null ? max(0.0, (float)$d['parcela']) : null;
    $visita = trim((string)($d['visita'] ?? ''));
    $minimo = vendaFormularioMinimo($tipo, $uso);
    $qualificado = $entrada >= $minimo;
    $temperatura = vendaFormularioTemperatura($entrada, $minimo);
    $faixaRotulo = '';
    if (isset($d['entrada_faixa'])) {
        if (!isset(VENDA_FORM_FAIXAS[$d['entrada_faixa']])) return ['ok' => false, 'erro' => 'Escolha quanto você tem de entrada.'];
        [$fMin, $fMax, $faixaRotulo] = VENDA_FORM_FAIXAS[$d['entrada_faixa']];
        $entrada = (float)$fMin;
        // Passa se o piso da faixa já cobre o mínimo, ou se o mínimo cai DENTRO da
        // faixa (ex: aplicativo R$ 14 mil na faixa 10–15). Teto igual ao mínimo não
        // passa: quem tem exatamente R$ 5 mil marca "5 a 10", não "até 5".
        $qualificado = $d['entrada_faixa'] !== 'nao' && ($fMin >= $minimo || $fMax === null || $fMax > $minimo);
        $temperatura = !$qualificado ? 'frio' : ($fMin >= 15000 ? 'quente' : 'morno');
    }

    $origem = [
        'canal_origem' => 'formulario_site',
        'campanha_origem' => mb_substr(trim((string)($d['utm_campaign'] ?? '')), 0, 200),
        'anuncio_origem' => mb_substr(trim((string)($d['utm_content'] ?? '')), 0, 200),
    ];
    $lead = criarOuAbrirVendaLead($tel, $nome, $origem);
    $vendaId = (int)$lead['venda_id'];

    $interesse = VENDA_FORM_TIPOS[$tipo] . ' — ' . VENDA_FORM_USOS[$uso];
    $fmt = fn($v) => 'R$ ' . number_format((float)$v, 2, ',', '.');
    $resumo = "📝 Preenchido pelo formulário do site\n"
        . "✅ Nome: {$nome}\n"
        . "✅ Procura: {$interesse}\n"
        . "✅ Forma de pagamento: promissória (formulário)\n"
        . ($faixaRotulo !== '' ? ($entrada > 0 || $qualificado ? "✅ Entrada disponível: {$faixaRotulo}\n" : "⚠️ Não tem entrada\n")
            : ($entrada > 0 ? "✅ Entrada disponível: {$fmt($entrada)}\n" : "⚠️ Não tem entrada\n"))
        . ($parcela ? "✅ Parcela que cabe no bolso: até {$fmt($parcela)}\n" : "⚠️ Parcela: não informou\n")
        . ($visita !== '' ? "✅ Melhor dia/horário pra visita: {$visita}\n" : "⚠️ Visita: ainda não combinou dia\n")
        . "Entrada mínima pro caso: {$fmt($minimo)}\n"
        . ($qualificado
            ? ($temperatura === 'quente' ? '🔥 PRONTO PRA FECHAR — ligar e confirmar a visita' : '🌱 Qualificado — ligar e combinar a visita')
            : '❌ Abaixo da entrada mínima — não encaminhado');

    $db = getDB();
    $db->prepare("
        UPDATE vendas SET
            comprador_nome = CASE WHEN COALESCE(comprador_nome,'') = '' OR comprador_nome = '(sem nome)' THEN ? ELSE comprador_nome END,
            veiculo_interesse_texto = ?, tipo_uso_veiculo = ?, forma_pagamento_pretendida = 'promissória',
            valor_entrada_disponivel = ?, valor_parcela_orcamento = ?,
            visita_dia_horario_texto = CASE WHEN ? != '' THEN ? ELSE visita_dia_horario_texto END,
            temperatura_lead = ?, resumo_ia = ?,
            canal_origem = CASE WHEN COALESCE(canal_origem,'') = '' THEN 'formulario_site' ELSE canal_origem END,
            updated_at = datetime('now','localtime')
        WHERE id = ?
    ")->execute([clean($nome), $interesse, $uso, $entrada, $parcela, $visita, clean($visita), $temperatura, $resumo, $vendaId]);

    $etapa = (string)$db->query("SELECT etapa FROM vendas WHERE id = " . $vendaId)->fetchColumn();
    if ($qualificado) {
        if (in_array($etapa, ['whatsapp', 'qualificacao_ia'], true)) {
            mudarEtapaVenda($vendaId, 'crm_preenchido', null, 'Qualificado pelo formulário do site — encaminhado pro vendedor');
        }
        // Vendedor assume a conversa: a IA nunca qualifica de novo quem já preencheu.
        pausarIA($tel);
        // 29/10/2026, "já começou chegar lead pelo formulário — só o quente
        // que o Jean é notificado": 🔥 quente avisa vendedor + números de
        // Configurações (Jean), 1x por negociação; morno só o vendedor.
        if ($temperatura === 'quente') {
            notificarLeadQuenteVenda($vendaId);
        } else {
            notificarVendedorLeadQualificado($vendaId, 'Formulário do site');
        }
    } elseif (in_array($etapa, ['whatsapp', 'qualificacao_ia'], true)) {
        mudarEtapaVenda($vendaId, 'sem_perfil', null,
            'Formulário: entrada ' . ($faixaRotulo !== '' ? $faixaRotulo : $fmt($entrada)) . ' abaixo do mínimo de ' . $fmt($minimo) . ' pra ' . $interesse);
    }
    return ['ok' => true, 'venda_id' => $vendaId, 'qualificado' => $qualificado, 'minimo' => $minimo, 'temperatura' => $temperatura];
}

/**
 * Agendamento da visita pelo formulário (16/11/2026, "agendamento para ver
 * veículo disponível — formulário de venda, horário e dia para cliente").
 * Depois de aprovado no formulário, o cliente escolhe dia e horário pra vir
 * ver os veículos. Sem controle de vaga (pátio físico, vários ao mesmo
 * tempo — mesma decisão da IA de vendas). Dias: próximos 7 dias, seg–sáb
 * (domingo fechado). Horários: de hora em hora, da abertura da fila até 1h
 * antes do fechamento (Configurações → Fila); hoje só a partir de 1h depois
 * de agora.
 */
const VENDA_VISITA_DIAS_SEMANA = [1 => 'segunda', 2 => 'terça', 3 => 'quarta', 4 => 'quinta', 5 => 'sexta', 6 => 'sábado', 7 => 'domingo'];

/** Horários (HH:MM) de visita de um dia (Y-m-d). Vazio = dia sem horário. */
function vendaVisitaHorarios(string $data, ?int $agora = null): array {
    $agora = $agora ?? time();
    $ts = strtotime($data . ' 00:00');
    if ($ts === false || (int)date('N', $ts) === 7) return [];
    $abre = (string)(getConfig('fila_horario_abertura') ?: '10:00');
    $fecha = (string)(getConfig('fila_horario_fechamento') ?: '19:20');
    if (!preg_match('/^\d{2}:\d{2}$/', $abre)) $abre = '10:00';
    if (!preg_match('/^\d{2}:\d{2}$/', $fecha)) $fecha = '19:20';
    $ini = strtotime($data . ' ' . $abre);
    $fim = strtotime($data . ' ' . $fecha) - 3600; // última visita 1h antes de fechar
    $ini = (int)(ceil($ini / 3600) * 3600);         // começa em hora cheia
    $horas = [];
    for ($t = $ini; $t <= $fim; $t += 3600) {
        if ($t < $agora + 3600) continue;
        $horas[] = date('H:i', $t);
    }
    return $horas;
}

/** Próximos dias com horário livre: [['data'=>'Y-m-d','rotulo'=>'sábado 22/11','horas'=>[...]], ...] */
function vendaVisitaDias(?int $agora = null, int $quantos = 7): array {
    $agora = $agora ?? time();
    $dias = [];
    for ($i = 0; $i < 14 && count($dias) < $quantos; $i++) {
        $data = date('Y-m-d', strtotime("+{$i} day", $agora));
        $horas = vendaVisitaHorarios($data, $agora);
        if (!$horas) continue;
        $ts = strtotime($data);
        $rotulo = ($i === 0 ? 'hoje' : ($i === 1 ? 'amanhã' : VENDA_VISITA_DIAS_SEMANA[(int)date('N', $ts)])) . ' ' . date('d/m', $ts);
        $dias[] = ['data' => $data, 'rotulo' => $rotulo, 'horas' => $horas];
    }
    return $dias;
}

/**
 * Grava a visita escolhida no formulário: aceita_visitar_fastcar=1, texto
 * "sábado 22/11 às 10:00", data resolvida, histórico e aviso pro vendedor
 * (sino + WhatsApp, mesmo aviso da visita confirmada pela IA). Reagendar
 * sobrescreve o dia/horário e avisa de novo. Só aceita dia/horário da lista.
 */
function agendarVisitaFormularioVenda(int $vendaId, string $data, string $hora): array {
    $dia = null;
    foreach (vendaVisitaDias() as $d) if ($d['data'] === $data) $dia = $d;
    if (!$dia || !in_array($hora, $dia['horas'], true)) return ['ok' => false, 'erro' => 'Escolha um dia e um horário da lista.'];
    $db = getDB();
    $st = $db->prepare("SELECT etapa, visita_dia_horario_texto FROM vendas WHERE id = ?");
    $st->execute([$vendaId]);
    $v = $st->fetch();
    if (!$v || in_array($v['etapa'], ['vendido', 'cancelada', 'sem_perfil'], true)) return ['ok' => false, 'erro' => 'Não foi possível agendar. Fale com a gente pelo WhatsApp.'];
    $ts = strtotime($data);
    $texto = VENDA_VISITA_DIAS_SEMANA[(int)date('N', $ts)] . ' ' . date('d/m', $ts) . ' às ' . $hora;
    $reagendou = (string)$v['visita_dia_horario_texto'] !== '' && $v['visita_dia_horario_texto'] !== $texto;
    $db->prepare("UPDATE vendas SET aceita_visitar_fastcar = 1, visita_dia_horario_texto = ?, visita_data_resolvida = ?,
                    visita_confirmada_em = COALESCE(visita_confirmada_em, datetime('now','localtime')),
                    lembrete_visita_enviado_para = '', lembrete_visita_resposta = '',
                    resumo_ia = COALESCE(resumo_ia,'') || ?, updated_at = datetime('now','localtime') WHERE id = ?")
       ->execute([$texto, $data, "\n📅 Visita agendada pelo formulário: {$texto}", $vendaId]);
    $db->prepare("INSERT INTO venda_historico (venda_id, etapa_anterior, etapa_nova, observacao) VALUES (?, ?, ?, ?)")
       ->execute([$vendaId, $v['etapa'], $v['etapa'], ($reagendou ? 'Visita reagendada' : 'Visita agendada') . " pelo cliente no formulário: {$texto}"]);
    notificarVisitaConfirmadaVenda($vendaId);
    return ['ok' => true, 'texto' => $texto];
}

/** Variações da mensagem com o link — lead que chamou direto sem passar pelo formulário. */
function vendaFormularioMsgLink(string $nome = ''): string {
    $primeiro = trim(explode(' ', $nome)[0] ?? '');
    $oi = $primeiro !== '' ? "Oi, {$primeiro}! " : 'Oi! ';
    $link = vendaFormularioUrlCurta('w');
    return $oi . variarMensagem([
        "Pra eu te mostrar as opções que cabem no seu bolso, preenche aqui rapidinho (leva 1 minuto): {$link}",
        "Tudo bem? Pra separar os carros certos pra você, me conta aqui em 1 minutinho: {$link}",
        "Que bom que chamou! Preenche esse formulário rapidinho que um vendedor já te atende com as opções: {$link}",
    ]);
}

/**
 * 05/11/2026, "manda única resposta: já preencheu, nossos vendedores entrarão
 * em contato, se ficar mandando mensagem" — cliente que já preencheu o
 * formulário (qualificado, IA pausada) e continua escrevendo recebe UMA vez
 * só a confirmação de que o vendedor vai chamar. Sem perfil (entrada abaixo do
 * mínimo) recebe 1x o mínimo + link pra preencher de novo, nunca promessa de
 * contato. Nunca manda se: não veio do formulário;
 * negociação já encerrada; ou um vendedor já respondeu essa conversa.
 * Devolve true se mandou agora.
 */
function vendaFormularioResponderJaPreencheu(int $vendaId, string $phone, ?array $credenciais): bool {
    $chave = 'formvenda_ack_' . $vendaId;
    if (getConfig($chave) === '1') return false;
    $db = getDB();
    $st = $db->prepare("SELECT etapa, comprador_nome, resumo_ia, comprador_telefone FROM vendas WHERE id = ?");
    $st->execute([$vendaId]);
    $v = $st->fetch();
    if (!$v || !str_starts_with((string)$v['resumo_ia'], '📝 Preenchido pelo formulário')) return false;
    $semPerfil = $v['etapa'] === 'sem_perfil';
    if (!$semPerfil && !in_array($v['etapa'], ['crm_preenchido', 'negociacao', 'contrato_enviado'], true)) return false;
    $tels = array_unique(array_filter([normalizarTelefone($phone), normalizarTelefone((string)$v['comprador_telefone'])]));
    $ph = implode(',', array_fill(0, count($tels), '?'));
    $stH = $db->prepare("SELECT 1 FROM whatsapp_mensagens WHERE telefone IN ($ph) AND direcao = 'out' AND usuario_id IS NOT NULL LIMIT 1");
    $stH->execute(array_values($tels));
    if ($stH->fetchColumn()) { setConfig($chave, '1'); return false; } // vendedor já está na conversa
    $primeiro = trim(explode(' ', trim((string)$v['comprador_nome']))[0] ?? '');
    $oi = ($primeiro !== '' && $primeiro !== '(sem') ? "Oi, {$primeiro}! " : 'Oi! ';
    if ($semPerfil) {
        // 05/11/2026 ("sim") — sem perfil que insiste: resposta honesta, 1x,
        // nunca promete contato. Mínimo vem do próprio resumo do formulário.
        $minTxt = preg_match('/Entrada mínima pro caso: (R\$ [\d.,]+)/u', (string)$v['resumo_ia'], $mm) ? $mm[1] : '';
        $msg = $oi . 'Obrigado pelo interesse! No momento as nossas opções na promissória pedem uma entrada '
            . ($minTxt !== '' ? "a partir de {$minTxt} " : 'maior ')
            . 'pra esse tipo de veículo. Guardamos o seu contato 🙂 Quando você tiver esse valor de entrada, é só preencher de novo: '
            . vendaFormularioUrlCurta('w');
    } else $msg = $oi . variarMensagem([
        'Já recebemos o seu cadastro ✅ Um dos nossos vendedores vai entrar em contato com você em breve por aqui.',
        'Seu formulário já chegou aqui ✅ Nosso vendedor vai te chamar em breve com as opções.',
        'Tudo certo, seu cadastro já está com a gente ✅ Em breve um vendedor da Fastcar entra em contato.',
    ]);
    if (!zapiEnviarTexto($phone, $msg, $credenciais)) return false;
    registrarMensagem($phone, 'out', $msg, null, true);
    setConfig($chave, '1');
    $db->prepare("INSERT INTO venda_historico (venda_id, etapa_anterior, etapa_nova, observacao) VALUES (?, ?, ?, ?)")
        ->execute([$vendaId, $v['etapa'], $v['etapa'], $semPerfil
            ? 'Resposta automática: sem perfil pela entrada — avisado do mínimo e do link pra preencher de novo'
            : 'Resposta automática: cliente já preencheu o formulário, vendedor vai entrar em contato']);
    return true;
}

/**
 * 🔄 Requalificar (30/10/2026, "será que ele é lead quente" — cliente
 * digitou 28 no formulário querendo dizer 28 mil e caiu como sem perfil).
 * O vendedor corrige entrada/parcela na venda e o sistema refaz a mesma
 * conta do formulário: entrada mínima pelo tipo/uso, temperatura e etapa.
 * Lead que já passou da negociação nunca é rebaixado — só atualiza valores.
 */
function requalificarVendaPorValores(int $vendaId, ?float $entrada, ?float $parcela, int $usuarioId): array {
    $db = getDB();
    $st = $db->prepare("SELECT * FROM vendas WHERE id = ?");
    $st->execute([$vendaId]);
    $v = $st->fetch();
    if (!$v) return ['ok' => false, 'erro' => 'Negociação não encontrada.'];
    if (in_array($v['etapa'], ['vendido', 'cancelada'], true)) return ['ok' => false, 'erro' => 'Negociação já encerrada — não dá pra requalificar.'];
    if ($entrada === null || $entrada < 0) return ['ok' => false, 'erro' => 'Informe a entrada.'];

    $interesse = mb_strtolower((string)$v['veiculo_interesse_texto']);
    $tipo = str_starts_with($interesse, 'moto') ? 'moto' : (str_starts_with($interesse, 'utilit') ? 'utilitario' : 'carro');
    $uso = array_key_exists((string)$v['tipo_uso_veiculo'], VENDA_FORM_USOS) ? (string)$v['tipo_uso_veiculo'] : 'passeio';
    $minimo = vendaFormularioMinimo($tipo, $uso);
    $temperatura = vendaFormularioTemperatura($entrada, $minimo);
    $qualificado = $entrada >= $minimo;
    $fmt = fn($x) => 'R$ ' . number_format((float)$x, 2, ',', '.');
    $quem = (string)($db->query("SELECT nome FROM usuarios WHERE id = " . $usuarioId)->fetchColumn() ?: '');
    $linha = "\n🔄 Requalificado" . ($quem ? " por {$quem}" : '') . ' em ' . date('d/m/Y H:i') . ': entrada ' . $fmt($entrada)
        . ($parcela ? ', parcela até ' . $fmt($parcela) : '') . ' (mínimo ' . $fmt($minimo) . ') → '
        . ($qualificado ? ($temperatura === 'quente' ? '🔥 quente' : '🌱 qualificado') : '❌ abaixo da entrada mínima');

    $db->prepare("UPDATE vendas SET valor_entrada_disponivel = ?, valor_parcela_orcamento = ?, temperatura_lead = ?,
                    resumo_ia = COALESCE(resumo_ia,'') || ?, updated_at = datetime('now','localtime') WHERE id = ?")
       ->execute([$entrada, $parcela, $temperatura, $linha, $vendaId]);

    $etapaNova = null;
    if ($qualificado && in_array($v['etapa'], ['sem_perfil', 'whatsapp', 'qualificacao_ia'], true)) {
        $etapaNova = 'crm_preenchido';
        mudarEtapaVenda($vendaId, $etapaNova, $usuarioId, 'Requalificado: entrada ' . $fmt($entrada) . ' (mínimo ' . $fmt($minimo) . ')');
        $db->prepare("UPDATE vendas SET motivo_perda = '' WHERE id = ?")->execute([$vendaId]);
        if ($temperatura === 'quente') notificarLeadQuenteVenda($vendaId);
        else notificarVendedorLeadQualificado($vendaId, 'Requalificado na tela');
    } elseif ($qualificado && $temperatura === 'quente') {
        notificarLeadQuenteVenda($vendaId); // 1x por negociação (a própria função deduplica)
    } elseif (!$qualificado && in_array($v['etapa'], ['whatsapp', 'qualificacao_ia', 'crm_preenchido'], true)) {
        $etapaNova = 'sem_perfil';
        mudarEtapaVenda($vendaId, $etapaNova, $usuarioId, 'Requalificado: entrada ' . $fmt($entrada) . ' abaixo do mínimo de ' . $fmt($minimo));
    }
    return ['ok' => true, 'temperatura' => $temperatura, 'qualificado' => $qualificado, 'minimo' => $minimo, 'etapa_nova' => $etapaNova];
}
