<?php
/**
 * Processamento de mensagem recebida na instância DEDICADA do financeiro
 * (18/09/2026, pedido José/Jean: "vamos fazer gestão desses clientes que
 * não paga fazer cobrança pelo sistema vai ser instancias só do finceir
 * outro numero") — mesmo raciocínio já documentado no CLAUDE.md pro
 * WhatsApp Box e pra instância de vendas: arquivo PRÓPRIO, nunca ramifica
 * em cima da função de compra/vendas já validadas em produção.
 *
 * Bem mais simples que compra/vendas de propósito: cobrança é gestão
 * ATIVA de dívida já existente (cliente já convertido, não lead), não faz
 * sentido qualificação por IA nem criar oportunidade/venda nenhuma — só
 * registra a mensagem no histórico compartilhado (whatsapp_mensagens) pra
 * aparecer no WhatsApp Box do financeiro (admin/financeiro_inbox.php);
 * quem decide o que responder é sempre um humano do financeiro, nunca bot
 * automático — regra de segurança extra dado o histórico real deste
 * projeto de bloqueio de número por mensagem repetida em rajada (ver
 * CLAUDE.md, "Bug real achado investigando bloqueio do número no
 * WhatsApp") — cobrança automatizada em massa é exatamente o tipo de
 * padrão que mais arrisca isso, então esta 1ª versão NUNCA manda nada
 * sozinha, só recebe e guarda.
 */

require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/security.php';
require_once dirname(__DIR__, 2) . '/includes/zapi_instancias.php';
require_once dirname(__DIR__, 2) . '/includes/whatsapp_config.php';
require_once __DIR__ . '/mensagens.php'; // helpers genéricos de mídia/dedup/texto

/**
 * Núcleo do processamento de uma mensagem recebida via Z-API na instância
 * de FINANCEIRO. Mesmo contrato de retorno (formato) de
 * processarMensagemZapi()/processarMensagemVendasZapi(), sem os campos que
 * não fazem sentido aqui (sem oportunidade/venda, sem IA).
 */
function processarMensagemFinanceiroZapi(array $payload, ?array $instancia = null): array {
    $instancia ??= zapiIdentificarInstancia((string)($payload['instanceId'] ?? ''));

    // ia_pausada sempre false — não existe IA nesta instância pra pausar,
    // mas o campo precisa existir no retorno: o webhook (chatbot-whatsapp/
    // webhook/whatsapp.php) lê $resultado['ia_pausada'] incondicionalmente
    // (sem empty()) no fluxo compartilhado com compra/vendas.
    $vazio = ['ignored' => null, 'telefone' => '', 'texto' => null, 'tipo' => null, 'ia_pausada' => false, 'instancia' => $instancia];

    $messageId = (string)($payload['messageId'] ?? $payload['id'] ?? '');
    $phone     = (string)($payload['phone'] ?? '');
    $fromMe    = !empty($payload['fromMe']);
    $isGroup   = !empty($payload['isGroup']) || str_contains($phone, '-group');

    if (!$phone) {
        return ['ignored' => 'no_phone'] + $vazio;
    }

    if ($fromMe) {
        registrarMensagem($phone, 'out', extrairTexto($payload) ?? '[' . tipoMidia($payload) . ']', $messageId ?: null, false, 'text', null);
        return ['ignored' => 'from_me', 'telefone' => $phone] + $vazio;
    }

    if ($isGroup) {
        return ['ignored' => 'group', 'telefone' => $phone] + $vazio;
    }

    if ($messageId && jaProcessado($messageId)) {
        return ['ignored' => 'duplicate', 'telefone' => $phone] + $vazio;
    }

    $texto = extrairTexto($payload);
    $tipoRegistro = 'text';
    if ($texto === null) {
        $tipoBruto = tipoMidia($payload);

        // Mesmo guard das outras 2 instâncias — evento de presença/status/
        // conexão da Z-API caindo no webhook "Ao receber" por engano nunca
        // deve virar mensagem (ver CLAUDE.md, incidente de flood 15/09/2026).
        if ($tipoBruto === 'desconhecido') {
            return ['ignored' => 'not_a_message', 'telefone' => $phone] + $vazio;
        }

        // Sem IA nesta instância — mídia (ex: comprovante de pagamento)
        // fica só com o rótulo do tipo, sem descrição nem cópia salva ainda;
        // escopo cortado de propósito (evitar over-engineering sem pedido
        // real), mesmo espírito do inbox de vendas na 1ª versão dele.
        $tipoRegistro = $tipoBruto;
        $texto = '[' . $tipoRegistro . ']';
    }

    registrarMensagem($phone, 'in', $texto, $messageId ?: null, false, $tipoRegistro, null);

    return [
        'ignored' => null,
        'telefone' => $phone,
        'texto' => $texto,
        'tipo' => $tipoRegistro,
        'ia_pausada' => false,
        'instancia' => $instancia,
    ];
}
