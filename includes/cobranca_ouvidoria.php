<?php
/**
 * Cobrança pelo número da Ouvidoria (20/11/2026, "Ele ja usam esse número para
 * cobrar — colocar no financeiro botão gerar cobrança"). Botão "💬 Cobrar" em
 * Financeiro → Lançamentos: monta a mensagem com as parcelas em aberto do
 * cliente + link do boleto/Pix do Asaas; o financeiro revisa e envia UMA por
 * vez. Nunca dispara sozinho nem em massa (risco de bloqueio do número).
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/usuarios.php';
require_once __DIR__ . '/auditoria.php';
require_once __DIR__ . '/asaas.php';
require_once dirname(__DIR__) . '/chatbot-whatsapp/includes/mensagens_ouvidoria.php';

/** Não manda 2 cobranças pro mesmo número em menos que isso (sem confirmar). */
const COBRANCA_OUV_INTERVALO_HORAS = 20;

/** Telefone/nome de quem paga o lançamento (venda → cliente → cliente Asaas). */
function cobrancaContatoDoLancamento(array $l): array {
    $db = getDB();
    $tel = '';
    $nome = '';
    if (!empty($l['venda_id'])) {
        $st = $db->prepare('SELECT comprador_telefone, comprador_nome FROM vendas WHERE id = ?');
        $st->execute([(int)$l['venda_id']]);
        if ($r = $st->fetch(PDO::FETCH_ASSOC)) { $tel = (string)$r['comprador_telefone']; $nome = (string)$r['comprador_nome']; }
    }
    if ($tel === '' && !empty($l['cliente_id'])) {
        $st = $db->prepare('SELECT telefone, nome FROM clientes WHERE id = ?');
        $st->execute([(int)$l['cliente_id']]);
        if ($r = $st->fetch(PDO::FETCH_ASSOC)) { $tel = (string)$r['telefone']; $nome = $nome ?: (string)$r['nome']; }
    }
    if ($tel === '' && !empty($l['asaas_customer_id'])) {
        $st = $db->prepare('SELECT telefone, nome FROM fin_asaas_clientes WHERE asaas_id = ?');
        $st->execute([(string)$l['asaas_customer_id']]);
        if ($r = $st->fetch(PDO::FETCH_ASSOC)) { $tel = (string)$r['telefone']; $nome = $nome ?: (string)$r['nome']; }
    }
    if ($nome === '') $nome = (string)($l['cliente_nome_manual'] ?? '');
    return ['telefone' => $tel !== '' ? normalizarTelefone($tel) : '', 'nome' => trim($nome)];
}

/** Receitas em aberto do mesmo pagador (mesma venda / cliente Asaas / cliente). */
function cobrancaItensEmAberto(array $l): array {
    if (!empty($l['venda_id'])) { $campo = 'venda_id'; $valor = (int)$l['venda_id']; }
    elseif (!empty($l['asaas_customer_id'])) { $campo = 'asaas_customer_id'; $valor = (string)$l['asaas_customer_id']; }
    elseif (!empty($l['cliente_id'])) { $campo = 'cliente_id'; $valor = (int)$l['cliente_id']; }
    else return [$l];
    $st = getDB()->prepare("
        SELECT * FROM fin_lancamentos
        WHERE {$campo} = ? AND tipo = 'receita'
          AND (status = 'atrasado' OR id = ?)
          AND status NOT IN ('pago','cancelado')
        ORDER BY data_vencimento, id
    ");
    $st->execute([$valor, (int)$l['id']]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [$l];
}

/** Link do boleto/Pix da cobrança no Asaas (null se não tiver). */
function cobrancaLinkAsaas(array $l): ?string {
    if (empty($l['asaas_payment_id']) || !asaasConfigured()) return null;
    $r = asaasRequest('GET', '/payments/' . rawurlencode((string)$l['asaas_payment_id']));
    if (!$r['ok']) return null;
    $url = (string)($r['dados']['invoiceUrl'] ?? $r['dados']['bankSlipUrl'] ?? '');
    return preg_match('#^https://#', $url) ? $url : null;
}

function _cobrancaChaveUltima(string $tel): string {
    return 'cobranca_ouv_enviada_' . md5($tel);
}

/** Dados pro modal: contato, itens, total, mensagem pronta, última cobrança. */
function cobrancaPreparar(int $lancamentoId): array {
    $st = getDB()->prepare('SELECT * FROM fin_lancamentos WHERE id = ?');
    $st->execute([$lancamentoId]);
    $l = $st->fetch(PDO::FETCH_ASSOC);
    if (!$l) return ['ok' => false, 'erro' => 'Lançamento não encontrado.'];
    if ($l['tipo'] !== 'receita' || in_array($l['status'], ['pago', 'cancelado'], true)) {
        return ['ok' => false, 'erro' => 'Só dá pra cobrar receita em aberto.'];
    }
    $c = cobrancaContatoDoLancamento($l);
    if ($c['telefone'] === '') return ['ok' => false, 'erro' => 'Esse lançamento não tem telefone do cliente (vincule a venda/cliente ou o cliente Asaas).'];

    $itens = cobrancaItensEmAberto($l);
    $total = 0.0;
    $linhas = [];
    $hoje = strtotime(date('Y-m-d'));
    foreach ($itens as $i) {
        $total += (float)$i['valor'];
        $venc = $i['data_vencimento'] ? date('d/m/Y', strtotime($i['data_vencimento'])) : 'sem vencimento';
        $dias = $i['data_vencimento'] ? (int)floor(($hoje - strtotime($i['data_vencimento'])) / 86400) : 0;
        $desc = $i['parcela_numero'] !== null
            ? ((int)$i['parcela_numero'] === 0 ? 'Entrada' : 'Parcela ' . (int)$i['parcela_numero'] . ($i['parcela_total'] ? '/' . (int)$i['parcela_total'] : ''))
            : mb_substr((string)$i['descricao'], 0, 50);
        $linhas[] = "• {$desc} — venc. {$venc} — R$ " . number_format((float)$i['valor'], 2, ',', '.') . ($dias > 0 ? " ({$dias} dia(s) em atraso)" : '');
    }
    $link = cobrancaLinkAsaas($l);
    $primeiro = $c['nome'] !== '' ? explode(' ', ucwords(mb_strtolower($c['nome'])))[0] : '';
    $msg = 'Olá' . ($primeiro !== '' ? ", {$primeiro}" : '') . "! Tudo bem? Aqui é do financeiro da Fastcar Solutions.\n\n"
         . (count($itens) > 1 ? "Constam em aberto no seu contrato:\n" : "Consta em aberto no seu contrato:\n")
         . implode("\n", $linhas)
         . (count($itens) > 1 ? "\n\n*Total: R$ " . number_format($total, 2, ',', '.') . '*' : '')
         . ($link ? "\n\nPra pagar (boleto ou Pix): {$link}" : '')
         . "\n\nSe já pagou, é só mandar o comprovante aqui que a gente confere. Qualquer dúvida, estamos à disposição. 🙏";

    $ultima = getConfig(_cobrancaChaveUltima($c['telefone']));
    return [
        'ok' => true,
        'nome' => $c['nome'],
        'telefone' => $c['telefone'],
        'itens' => count($itens),
        'total' => $total,
        'mensagem' => $msg,
        'tem_link' => (bool)$link,
        'ultima_cobranca' => $ultima ? date('d/m/Y H:i', (int)$ultima) : null,
        'recente' => $ultima && (time() - (int)$ultima) < COBRANCA_OUV_INTERVALO_HORAS * 3600,
        'ouvidoria_ok' => evolutionConfiguredOuvidoria(),
    ];
}

/** Envia a cobrança (texto revisado pelo financeiro) pelo número da Ouvidoria. */
function cobrancaEnviarPelaOuvidoria(int $lancamentoId, string $texto, int $usuarioId, bool $forcar = false): array {
    $p = cobrancaPreparar($lancamentoId);
    if (!$p['ok']) return $p;
    $texto = trim($texto);
    if ($texto === '') return ['ok' => false, 'erro' => 'Mensagem vazia.'];
    if (!$p['ouvidoria_ok']) return ['ok' => false, 'erro' => 'Instância da ouvidoria não configurada (Configurações → Evolution API — Ouvidoria).'];
    if ($p['recente'] && !$forcar) {
        return ['ok' => false, 'erro' => "Esse cliente já recebeu cobrança em {$p['ultima_cobranca']}. Marque \"enviar mesmo assim\" se for de propósito."];
    }
    $tel = $p['telefone'];
    $u = buscarUsuario($usuarioId);
    $nomeUsuario = trim((string)($u['nome'] ?? ''));
    $assinado = ($nomeUsuario !== '' ? "*{$nomeUsuario} — Financeiro Fastcar:*\n" : '') . $texto;

    whatsappCanalRegistro('ouvidoria');
    if (!evolutionEnviarTexto($tel, $assinado, evolutionCredenciaisOuvidoria())) {
        return ['ok' => false, 'erro' => 'Falha ao enviar pela instância da ouvidoria.' . whatsappDetalheUltimoErro()];
    }
    ouvidoriaRegistrarEnviada($tel, $texto, false, $usuarioId);
    setConfig(_cobrancaChaveUltima($tel), (string)time());

    // Liga a conversa da ouvidoria à venda, pra resposta do cliente já mostrar o contrato.
    try {
        $st = getDB()->prepare('SELECT venda_id FROM fin_lancamentos WHERE id = ?');
        $st->execute([$lancamentoId]);
        $vendaId = (int)$st->fetchColumn();
        $conv = ouvidoriaConversa($tel);
        $campos = [];
        if ($vendaId && empty($conv['venda_id']) && empty($conv['oportunidade_id'])) $campos['venda_id'] = $vendaId;
        if ($p['nome'] !== '' && empty($conv['nome'])) $campos['nome'] = $p['nome'];
        if ($campos) ouvidoriaSalvarConversa($tel, $campos);
    } catch (Throwable $e) {}

    auditoriaRegistrar('cobranca_enviada', $usuarioId, $nomeUsuario, 'fin_lancamento', $lancamentoId,
        "Cobrança pelo número da ouvidoria: {$p['itens']} lançamento(s), R$ " . number_format($p['total'], 2, ',', '.'));
    return ['ok' => true, 'nome' => $p['nome']];
}
