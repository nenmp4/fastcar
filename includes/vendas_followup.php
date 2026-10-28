<?php
/**
 * Follow-up dos leads de VENDAS que ficaram sem resposta — 28/10/2026,
 * "350 leads parados na instância de vendas... tinha caído a conexão" /
 * "mandar o link do formulário" / "sem muito disparo" / "SSH bloqueado".
 *
 * Lógica única, usada por 3 pontos:
 *   - admin/configuracoes.php (card "📣 Follow-up dos leads sem resposta"):
 *     simular, iniciar e pausar — sem precisar de SSH;
 *   - cron/vendas_followup.php (a cada 5 min): manda 1 mensagem por rodada
 *     enquanto estiver ativo;
 *   - install/vendas_followup_formulario.php (CLI, opcional).
 *
 * Fonte dos contatos (sem repetir telefone): conversas da instância
 * Evolution de VENDAS (pega quem escreveu com a conexão caída e nunca
 * chegou no CRM) + leads de vendas no CRM em whatsapp/qualificacao_ia/
 * crm_preenchido cuja última mensagem é do cliente.
 *
 * Nunca manda pra: quem pediu SAIR, negociação que já andou, número de
 * usuário do sistema, conversa cuja última mensagem fomos nós, quem já
 * recebeu (config.vendas_fu_form_{telefone}). Sempre pela instância de
 * VENDAS. Só seg–sáb 10h–18h (Brasília), no máximo VENDAS_FU_MAX_DIA/dia.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/whatsapp_evolution.php';
require_once __DIR__ . '/vendas_formulario.php';
require_once dirname(__DIR__) . '/chatbot-whatsapp/includes/mensagens.php'; // registrarMensagem()

const VENDAS_FU_MAX_DIA = 80;
const VENDAS_FU_DIAS = 15;

function vendasFollowupAtivo(): bool {
    return getConfig('vendas_followup_ativo') === '1';
}

function vendasFollowupDentroHorario(): bool {
    $agora = new DateTime('now', new DateTimeZone('America/Sao_Paulo'));
    $h = (int)$agora->format('G');
    return (int)$agora->format('N') <= 6 && $h >= 10 && $h < 18;
}

function vendasFollowupEnviadosHoje(): int {
    $hoje = (new DateTime('now', new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d');
    return (int)getConfig('vendas_fu_form_total_' . $hoje);
}

function vendasFollowupTotalEnviados(): int {
    return (int)getDB()->query("SELECT COUNT(*) FROM config WHERE chave LIKE 'vendas_fu_form_55%'")->fetchColumn();
}

/**
 * Monta a fila. @return array{fila: array<string,array{nome:string,origem:string}>,
 *   pulados: array<string,int>, conversas: ?int, erro: ?string, exemplo: ?string}
 */
function vendasFollowupMontarFila(int $dias = VENDAS_FU_DIAS): array {
    $db = getDB();
    $limiteTs = time() - $dias * 86400;
    $candidatos = [];
    $erro = null; $exemplo = null; $qtdConversas = null;

    $conversas = evolutionConfiguredVendas() ? evolutionListarConversas(evolutionCredenciaisVendas()) : null;
    if ($conversas === null) {
        $erro = evolutionConfiguredVendas() ? ('Não consegui listar as conversas da instância de vendas: ' . evolutionUltimoErro()) : 'Instância Evolution de vendas não configurada.';
    } else {
        $qtdConversas = count($conversas);
        if ($conversas) $exemplo = substr(json_encode($conversas[0]['raw'], JSON_UNESCAPED_UNICODE), 0, 400);
        foreach ($conversas as $c) {
            if ($c['atualizado_ts'] && $c['atualizado_ts'] < $limiteTs) continue;
            if ($c['ultima_de_mim'] === true) continue;
            $candidatos[$c['telefone']] = ['nome' => $c['nome'], 'origem' => 'instância'];
        }
    }
    $stCrm = $db->query("
        SELECT v.comprador_telefone AS tel, v.comprador_nome AS nome
        FROM vendas v
        WHERE v.etapa IN ('whatsapp', 'qualificacao_ia', 'crm_preenchido') AND COALESCE(v.optout_whatsapp, 0) = 0
          AND v.created_at >= datetime('now', 'localtime', '-" . (int)$dias . " days')
          AND (SELECT m.direcao FROM whatsapp_mensagens m WHERE m.telefone = v.comprador_telefone ORDER BY m.id DESC LIMIT 1) = 'in'
    ");
    foreach ($stCrm->fetchAll() as $r) {
        $tel = normalizarTelefone((string)$r['tel']);
        if (strlen($tel) < 12) continue;
        if (!isset($candidatos[$tel])) $candidatos[$tel] = ['nome' => (string)$r['nome'], 'origem' => 'CRM'];
        elseif ($candidatos[$tel]['nome'] === '') $candidatos[$tel]['nome'] = (string)$r['nome'];
    }

    $staff = [];
    foreach ($db->query("SELECT whatsapp FROM usuarios WHERE COALESCE(whatsapp,'') != ''") as $u) $staff[normalizarTelefone((string)$u['whatsapp'])] = true;
    $stVenda = $db->prepare("SELECT etapa, COALESCE(optout_whatsapp,0) AS optout FROM vendas WHERE comprador_telefone = ? ORDER BY id DESC LIMIT 1");
    $stCli = $db->prepare("SELECT COALESCE(optout_whatsapp,0) FROM clientes WHERE telefone = ?");
    $stUltima = $db->prepare("SELECT direcao FROM whatsapp_mensagens WHERE telefone = ? ORDER BY id DESC LIMIT 1");
    $fila = [];
    $pulados = ['ja_recebeu' => 0, 'pediu_sair' => 0, 'negociacao_andando' => 0, 'ja_respondido' => 0, 'funcionario' => 0];
    foreach ($candidatos as $tel => $c) {
        if (isset($staff[$tel])) { $pulados['funcionario']++; continue; }
        if (getConfig('vendas_fu_form_' . $tel)) { $pulados['ja_recebeu']++; continue; }
        $stVenda->execute([$tel]); $v = $stVenda->fetch();
        if ($v && (int)$v['optout'] === 1) { $pulados['pediu_sair']++; continue; }
        $stCli->execute([$tel]); if ((int)$stCli->fetchColumn() === 1) { $pulados['pediu_sair']++; continue; }
        if ($v && !in_array($v['etapa'], ['whatsapp', 'qualificacao_ia', 'crm_preenchido'], true)) { $pulados['negociacao_andando']++; continue; }
        $stUltima->execute([$tel]);
        if ($stUltima->fetchColumn() === 'out') { $pulados['ja_respondido']++; continue; }
        $fila[$tel] = $c;
    }
    return ['fila' => $fila, 'pulados' => $pulados, 'conversas' => $qtdConversas, 'erro' => $erro, 'exemplo' => $exemplo];
}

function vendasFollowupMensagem(string $nome): string {
    $p = trim(explode(' ', trim($nome))[0] ?? '');
    $oi = $p !== '' ? "Oi, {$p}! " : 'Oi! ';
    $link = vendaFormularioUrlCurta('r');
    return $oi . variarMensagem([
        "Desculpa a demora pra te responder, tivemos uma instabilidade aqui no WhatsApp. Ainda procurando carro? Pra eu te mostrar as opções na promissória, preenche aqui rapidinho (leva 1 minuto): {$link}",
        "Aqui é da Fastcar 🚗 Vi que você chamou a gente e ficou sem resposta, me desculpa! Pra separar os carros que cabem no seu bolso, me conta aqui em 1 minutinho: {$link}",
        "Tudo bem? Você falou com a Fastcar e nosso WhatsApp ficou fora do ar, desculpa! Ainda tem interesse? Preenche esse formulário rapidinho que um vendedor já te chama com as opções: {$link}",
        "Passando pra retomar nosso contato — tivemos um problema no WhatsApp e sua mensagem ficou sem resposta. Se ainda quiser ver os carros na promissória, é só preencher aqui (1 minuto): {$link}",
    ]);
}

/**
 * Manda pro próximo da fila (1 contato). Respeita horário e limite do dia.
 * @return array{status: string, telefone?: string, erro?: string}
 *   status: enviado | falhou | fila_vazia | fora_horario | limite_dia | sem_instancia
 */
function vendasFollowupEnviarProximo(int $maxDia = VENDAS_FU_MAX_DIA): array {
    if (!evolutionConfiguredVendas()) return ['status' => 'sem_instancia'];
    if (!vendasFollowupDentroHorario()) return ['status' => 'fora_horario'];
    if (vendasFollowupEnviadosHoje() >= $maxDia) return ['status' => 'limite_dia'];
    $montada = vendasFollowupMontarFila();
    if (!$montada['fila']) return ['status' => 'fila_vazia', 'erro' => $montada['erro']];
    $tel = (string)array_key_first($montada['fila']); // chave numérica vira int no array
    $c = $montada['fila'][$tel];
    $msg = vendasFollowupMensagem($c['nome']);
    if (!evolutionEnviarTexto($tel, $msg, evolutionCredenciaisVendas())) {
        return ['status' => 'falhou', 'telefone' => $tel, 'erro' => (string)evolutionUltimoErro()];
    }
    $hoje = (new DateTime('now', new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d');
    setConfig('vendas_fu_form_' . $tel, date('Y-m-d H:i:s'));
    setConfig('vendas_fu_form_total_' . $hoje, (string)(vendasFollowupEnviadosHoje() + 1));
    try { registrarMensagem($tel, 'out', $msg, null, true); } catch (Throwable $e) {}
    return ['status' => 'enviado', 'telefone' => $tel];
}

/**
 * 1 rodada completa (envio + log + liga/desliga), compartilhada entre o cron e o
 * botão "📤 Enviar 1 agora" de Configurações — o botão existe pra não depender
 * do crontab da VPS (SSH bloqueado) caso o agendamento não esteja instalado.
 */
function vendasFollowupRodada(): array {
    $r = vendasFollowupEnviarProximo();
    $log = '[' . date('d/m H:i:s') . '] ' . $r['status'] . (isset($r['telefone']) ? ' ' . $r['telefone'] : '') . (!empty($r['erro']) ? ' — ' . $r['erro'] : '');
    setConfig('vendas_followup_ultimo_log', $log);
    if ($r['status'] === 'fila_vazia' && empty($r['erro'])) {
        setConfig('vendas_followup_ativo', '0'); // acabou a lista
    }
    if ($r['status'] === 'falhou') {
        $falhas = (int)getConfig('vendas_followup_falhas_seguidas') + 1;
        setConfig('vendas_followup_falhas_seguidas', (string)$falhas);
        if ($falhas >= 3) setConfig('vendas_followup_ativo', '0'); // pausa: provável instância caída
    } elseif ($r['status'] === 'enviado') {
        setConfig('vendas_followup_falhas_seguidas', '0');
    }
    $r['log'] = $log;
    return $r;
}
