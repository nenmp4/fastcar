<?php
/**
 * Correção pontual (dry-run por padrão, `--confirmar` pra aplicar) — 12/10/2026,
 * achado real via screenshot: conversa com "Clebeson" mostrando 3 fotos +
 * 1 áudio como texto cru "[image]"/"[audio]" no WhatsApp Box, datados de
 * 11/10 13:56-57 — um dia ANTES do fix de mídia recebida da Evolution
 * (commit 2aea7aa, 12/10 10:01 UTC) existir. Essas mensagens chegaram pelo
 * webhook antigo (evolutionAdaptarPayloadParaZapi() nunca populava
 * mediaId/url, então o download nunca era tentado) e ficaram salvas sem
 * drive_file_id/arquivo_url — nunca vão se corrigir sozinhas, não existe
 * backfill automático.
 *
 * Este script tenta recuperar RETROATIVAMENTE: reaproveita
 * evolutionBaixarMidiaRecebida() (o mesmo endpoint que já corrige mensagem
 * NOVA desde o fix) usando zapi_message_id, que já estava sendo salvo
 * desde sempre (é só o download que nunca rodava). ⚠️ Sem garantia — o
 * WhatsApp/Baileys normalmente só mantém a mídia disponível no servidor
 * por um tempo limitado; passado quase 1 dia é bem possível que a
 * Evolution já não tenha mais os bytes pra devolver (erro reportado por
 * mensagem, nunca assumido como sucesso).
 *
 * Nunca assume que a mensagem veio da Evolution — tenta o download pra
 * QUALQUER mensagem 'in' de mídia sem bytes salvos (não existe coluna de
 * "canal" por mensagem); uma mensagem que veio de outro provedor (Z-API/
 * Meta) só vai falhar graciosamente aqui (messageId não reconhecido pela
 * Evolution), sem risco de corromper nada.
 *
 * Em dry-run (padrão) JÁ tenta o download de verdade (é leitura, sem
 * nenhuma escrita no nosso banco) — mostra por mensagem se recuperou ou
 * não, com o erro real, ANTES de decidir se vale rodar --confirmar.
 * `--confirmar` repete o download e PERSISTE via salvarMidiaWhatsappRecebida()
 * (mesma função que o fluxo em tempo real usa).
 *
 * Uso:
 *   php install/corrigir_midia_evolution_antiga.php              — só testa e lista (dry-run)
 *   php install/corrigir_midia_evolution_antiga.php --confirmar  — aplica de verdade
 *   php install/corrigir_midia_evolution_antiga.php --dias=3     — janela maior que o padrão (2 dias)
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Este script só roda via linha de comando (CLI).\n");
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/whatsapp_evolution.php';
require_once __DIR__ . '/../chatbot-whatsapp/includes/mensagens.php';

ob_implicit_flush(true);
if (function_exists('ob_end_flush')) { while (ob_get_level() > 0) ob_end_flush(); }

$confirmar = in_array('--confirmar', $argv, true);
$dias = 2;
foreach ($argv as $arg) {
    if (preg_match('/^--dias=(\d+)$/', $arg, $m)) $dias = max(1, (int)$m[1]);
}

$db = getDB();

echo "=== Recuperação de mídia antiga (Evolution) — últimos {$dias} dia(s), janela a partir de 'agora') ===\n\n";

$candidatas = $db->prepare("
    SELECT m.id, m.telefone, m.cliente_id, m.tipo, m.zapi_message_id, m.created_at,
           c.nome AS cliente_nome
    FROM whatsapp_mensagens m
    LEFT JOIN clientes c ON c.id = m.cliente_id
    WHERE m.direcao = 'in'
      AND m.tipo IN ('image','audio','video','document')
      AND (m.drive_file_id IS NULL OR m.drive_file_id = '')
      AND (m.arquivo_url IS NULL OR m.arquivo_url = '')
      AND m.zapi_message_id IS NOT NULL AND m.zapi_message_id != ''
      AND m.created_at >= datetime('now', 'localtime', '-' || :dias || ' days')
    ORDER BY m.created_at ASC
");
$candidatas->execute([':dias' => $dias]);
$linhas = $candidatas->fetchAll(PDO::FETCH_ASSOC);

if (!$linhas) {
    echo "Nenhuma mensagem de mídia quebrada encontrada nessa janela.\n";
    echo "(dica: aumente com --dias=N se o problema for mais antigo que {$dias} dia(s))\n";
    exit(0);
}

echo count($linhas) . " mensagem(ns) candidata(s):\n\n";

$recuperadas = 0;
$falhas = 0;
$i = 0;
foreach ($linhas as $l) {
    $i++;
    $nome = $l['cliente_nome'] ?: '(sem nome)';
    echo "[{$i}/" . count($linhas) . "] #{$l['id']} {$nome} {$l['telefone']} — {$l['tipo']} — {$l['created_at']} ... ";

    $resultado = evolutionBaixarMidiaRecebida((string)$l['zapi_message_id'], (string)$l['tipo']);

    if ($resultado === null) {
        $falhas++;
        $erro = evolutionUltimoErro() ?: '(erro desconhecido)';
        echo "❌ falhou — " . substr($erro, 0, 180) . "\n";
        continue;
    }

    if (!$confirmar) {
        $recuperadas++;
        echo "✅ recuperável (" . strlen($resultado['bytes']) . " bytes, " . ($resultado['mime'] ?: '?') . ") — dry-run, nada salvo ainda\n";
        continue;
    }

    if ((int)$l['cliente_id'] <= 0) {
        $falhas++;
        echo "⚠️ baixou os bytes mas a mensagem não tem cliente_id — não dá pra salvar\n";
        continue;
    }

    salvarMidiaWhatsappRecebida(
        (int)$l['id'],
        $resultado['bytes'],
        $resultado['mime'] ?: 'application/octet-stream',
        (string)$l['tipo'],
        (int)$l['cliente_id'],
        $nome
    );

    // Confirma que salvou de verdade (salvarMidiaWhatsappRecebida() é
    // best-effort, nunca lança, nunca retorna nada — reconsulta o banco).
    $check = $db->prepare("SELECT drive_file_id, arquivo_url FROM whatsapp_mensagens WHERE id = ?");
    $check->execute([$l['id']]);
    $salvo = $check->fetch(PDO::FETCH_ASSOC);
    if ($salvo && ($salvo['drive_file_id'] || $salvo['arquivo_url'])) {
        $recuperadas++;
        echo "✅ recuperado e salvo\n";
    } else {
        $falhas++;
        echo "⚠️ baixou os bytes mas salvarMidiaWhatsappRecebida() não persistiu (Drive/disco falhou)\n";
    }
}

echo "\n--- Resumo ---\n";
echo "Recuperadas: {$recuperadas}\n";
echo "Falharam: {$falhas}\n";

if (!$confirmar) {
    echo "\n(dry-run — nada foi salvo. Rode com --confirmar pra persistir as recuperáveis.)\n";
}
