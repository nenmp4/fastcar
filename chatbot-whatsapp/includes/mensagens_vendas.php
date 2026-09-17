<?php
/**
 * Processamento de mensagem recebida na instância DEDICADA de vendas
 * (17/09/2026, pedido José/Jean: "vamos adcionar instancia só para
 * vendas" + "vamos usar qualificação do lead para vendas") — espelha
 * processarMensagemZapi() (mensagens.php) pro lado do COMPRADOR, mas
 * arquivo PRÓPRIO de propósito (mesmo raciocínio já documentado no
 * CLAUDE.md pro WhatsApp Box: "portar o conceito, não o arquivo direto...
 * modelo de dado e regras de negócio diferentes demais pra copy-paste
 * direto") — mexer na função de compra, já validada em produção, pra
 * ramificar em cima de uma tabela/fluxo diferente é risco desnecessário.
 *
 * Reaproveita de mensagens.php (já exigido antes deste arquivo) tudo que é
 * genérico o bastante pra servir os dois lados — registrarMensagem(),
 * extrairTexto(), tipoMidia(), baixarMidiaZapi(), descreverMidiaComGemini(),
 * jaProcessado(), aguardarSilencioOuAbortar(), iaPausada() — nenhuma dessas
 * depende de `oportunidades`/`clientes`, só de telefone/whatsapp_mensagens/
 * whatsapp_sessoes (tabelas compartilhadas pelos dois funis).
 */

require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/security.php';
require_once dirname(__DIR__, 2) . '/includes/vendas.php';
require_once dirname(__DIR__, 2) . '/includes/zapi_instancias.php';
require_once dirname(__DIR__, 2) . '/includes/whatsapp_config.php';
require_once dirname(__DIR__, 2) . '/includes/ia_qualificacao_vendas.php';
require_once __DIR__ . '/mensagens.php'; // helpers genéricos de mídia/dedup/debounce

/**
 * Núcleo do processamento de uma mensagem recebida via Z-API na instância
 * de VENDAS. Mesmo contrato de retorno (formato) de processarMensagemZapi(),
 * trocando 'oportunidade' por 'venda_lead'.
 */
function processarMensagemVendasZapi(array $payload, ?array $instancia = null): array {
    $instancia ??= zapiIdentificarInstancia((string)($payload['instanceId'] ?? ''));
    $credenciaisVendas = zapiCredenciaisVendas();

    $vazio = ['ignored' => null, 'telefone' => '', 'texto' => null, 'tipo' => null,
              'ia_pausada' => false, 'venda_lead' => null, 'erro_venda' => null,
              'instancia' => $instancia, 'ia_resultado' => null];

    $messageId = (string)($payload['messageId'] ?? $payload['id'] ?? '');
    $phone     = (string)($payload['phone'] ?? '');
    $fromMe    = !empty($payload['fromMe']);
    $isGroup   = !empty($payload['isGroup']) || str_contains($phone, '-group');

    if (!$phone) {
        return ['ignored' => 'no_phone'] + $vazio;
    }

    if ($fromMe) {
        registrarMensagem($phone, 'out', extrairTexto($payload) ?? '[' . tipoMidia($payload) . ']', $messageId ?: null, false, 'text', null);
        return ['ignored' => 'from_me', 'telefone' => $phone, 'ia_pausada' => iaPausada($phone)] + $vazio;
    }

    if ($isGroup) {
        return ['ignored' => 'group', 'telefone' => $phone] + $vazio;
    }

    if ($messageId && jaProcessado($messageId)) {
        return ['ignored' => 'duplicate', 'telefone' => $phone, 'ia_pausada' => iaPausada($phone)] + $vazio;
    }

    $texto = extrairTexto($payload);
    $tipoRegistro = 'text';
    $bytesMidia = null;
    $mime = '';
    if ($texto === null) {
        $tipoBruto = tipoMidia($payload);

        // Mesmo guard do lado de compra — evento de presença/status/conexão
        // da Z-API caindo por engano no webhook "Ao receber" (ver
        // CLAUDE.md, incidente de flood de 15/09/2026) nunca deve virar
        // "mídia não suportada" — ignora silenciosamente.
        if ($tipoBruto === 'desconhecido') {
            return ['ignored' => 'not_a_message', 'telefone' => $phone] + $vazio;
        }

        $textoMidia = '';
        if (in_array($tipoBruto, ['audio', 'image', 'video'], true)) {
            $url = extrairUrlMidia($payload, $tipoBruto);
            if ($url) {
                $mimeDefault = ['audio' => 'audio/ogg', 'image' => 'image/jpeg', 'video' => 'video/mp4'][$tipoBruto];
                $mime = mimeMidia($payload, $tipoBruto, $mimeDefault);
                $bytesMidia = baixarMidiaZapi($url, $tipoBruto);
                if ($bytesMidia !== null) {
                    $textoMidia = descreverMidiaComGemini($bytesMidia, $mime, $tipoBruto);
                }
            }
        }
        if ($textoMidia !== '') {
            $prefixo = ['audio' => '🎤 ', 'video' => '🎥 '][$tipoBruto] ?? '📷 ';
            $texto = $prefixo . $textoMidia;
        } else {
            $tipoRegistro = $tipoBruto;
            $texto = '[' . $tipoRegistro . ']';
        }
    }

    $idMensagemRecebida = registrarMensagem($phone, 'in', $texto, $messageId ?: null, false, $tipoRegistro, null);

    $nomeContatoBruto = (string)($payload['senderName'] ?? $payload['chatName'] ?? '');
    $nomeContato = nomeWhatsappPareceValido($nomeContatoBruto) ? $nomeContatoBruto : '';
    $vendaLead = null;
    $erroVenda = null;
    try {
        $vendaLead = criarOuAbrirVendaLead($phone, $nomeContato);
    } catch (Throwable $e) {
        $erroVenda = $e->getMessage();
    }

    // Mídia recebida (foto do veículo que o comprador já tem em mente,
    // documento etc) fica só como descrição em texto por enquanto — sem
    // `clientes.id` (não existe do lado de venda), salvarMidiaWhatsappRecebida()
    // não tem onde vincular a cópia visível no Drive; escopo intencional
    // desta 1ª versão (evita over-engineering sem pedido real), sinalizar
    // se a equipe sentir falta de ver a mídia em si, não só a descrição.

    $ia_pausada = iaPausada($phone);
    $iaResultado = null;

    if ($vendaLead && !$ia_pausada && $instancia['tipo'] === 'vendas') {
        $db = getDB();
        $stmtEtapa = $db->prepare("SELECT etapa FROM vendas WHERE id = ?");
        $stmtEtapa->execute([$vendaLead['venda_id']]);
        $etapaAtual = $stmtEtapa->fetchColumn();

        if (in_array($etapaAtual, ['whatsapp', 'qualificacao_ia'], true)) {
            if ($tipoRegistro === 'text') {
                if ($etapaAtual === 'whatsapp') {
                    mudarEtapaVenda($vendaLead['venda_id'], 'qualificacao_ia', null, 'IA iniciou qualificação');
                }
                if (aguardarSilencioOuAbortar($phone, $idMensagemRecebida)) {
                    try {
                        $iaResultado = iaProcessarTurnoVenda($vendaLead['venda_id'], $phone);
                    } catch (Throwable $e) {
                        // Nunca deixa falha da IA quebrar o resto do webhook.
                    }
                }
            } else {
                $textoAck = 'Recebi por aqui! 😊 Consegue me contar em texto ou áudio?';
                if (zapiEnviarTexto($phone, $textoAck, $credenciaisVendas)) {
                    registrarMensagem($phone, 'out', $textoAck, null, true);
                }
            }
        }
    }

    return [
        'ignored' => null,
        'telefone' => $phone,
        'texto' => $texto,
        'tipo' => $tipoRegistro,
        'ia_pausada' => $ia_pausada,
        'venda_lead' => $vendaLead,
        'erro_venda' => $erroVenda,
        'instancia' => $instancia,
        'ia_resultado' => $iaResultado,
    ];
}
