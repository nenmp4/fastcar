<?php
/**
 * Desfaz uma redistribuição de leads feita sem querer (24/10/2026, "tirei os
 * leads do Endriks sem querer eu redistribui").
 *
 * Lê o histórico (oportunidade_historico e venda_historico) das últimas N
 * horas e acha toda movimentação QUE TIROU lead do usuário informado —
 * redistribuição/equalização da fila, "marcar falta" e "puxar leads"
 * (reatribuição manual). Devolve o lead pra ele SÓ se o lead ainda está com
 * quem recebeu (ninguém mexeu depois) e ainda está numa etapa ativa.
 *
 * Uso (na VPS, via SSH):
 *   php install/desfazer_redistribuicao.php Endriks             (só mostra, não altera nada)
 *   php install/desfazer_redistribuicao.php Endriks --horas=48  (janela maior; padrão 24h)
 *   php install/desfazer_redistribuicao.php Endriks --confirmar (devolve de verdade)
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Só via linha de comando.'); }

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/oportunidades.php';
require_once __DIR__ . '/../includes/vendas.php';

$nomeBusca = '';
$horas = 24;
$confirmar = false;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--confirmar') $confirmar = true;
    elseif (str_starts_with($arg, '--horas=')) $horas = max(1, (int)substr($arg, 8));
    else $nomeBusca = trim($arg);
}
if ($nomeBusca === '') {
    exit("Informe o nome (ou parte do nome) do usuário que perdeu os leads. Ex: php install/desfazer_redistribuicao.php Endriks\n");
}

$db = getDB();
$stmt = $db->prepare("SELECT id, nome, perfil, disponivel, faltou_em FROM usuarios WHERE nome LIKE ?");
$stmt->execute(['%' . $nomeBusca . '%']);
$alvos = $stmt->fetchAll();
if (count($alvos) !== 1) {
    echo count($alvos) === 0 ? "Nenhum usuário com nome parecido com \"{$nomeBusca}\".\n" : "Mais de um usuário bate com \"{$nomeBusca}\" — seja mais específico:\n";
    foreach ($alvos as $a) echo "  - {$a['nome']} ({$a['perfil']})\n";
    exit(1);
}
$alvo = $alvos[0];
$usuariosPorNome = [];
foreach ($db->query("SELECT id, nome FROM usuarios")->fetchAll() as $u) $usuariosPorNome[$u['nome']] = (int)$u['id'];

echo "Usuário: {$alvo['nome']} (#{$alvo['id']}, {$alvo['perfil']}) — olhando as últimas {$horas}h"
   . ($confirmar ? " — MODO CONFIRMAR (vai alterar)" : " — só simulação, use --confirmar pra aplicar") . "\n\n";

/** Extrai [origem, destino] das observações gravadas pela fila/reatribuição. */
function _desfazerParse(string $obs): ?array {
    $padroes = [
        '/: de (.+?) para (.+?) \(/u',                          // redistribuição / equalização
        '/\(de (.+?) pra (.+?)\)$/u',                            // reatribuição manual ("puxar leads")
        '/: (.+?) marcado como ausente hoje, lead passou pra (.+)$/u', // marcar falta
    ];
    foreach ($padroes as $p) {
        if (preg_match($p, $obs, $m)) return [trim($m[1]), trim($m[2])];
    }
    return null;
}

$tabelas = [
    ['rotulo' => 'Compra', 'hist' => 'oportunidade_historico', 'fk' => 'oportunidade_id', 'tab' => 'oportunidades', 'ativas' => ETAPAS_ATIVAS],
    ['rotulo' => 'Vendas', 'hist' => 'venda_historico',        'fk' => 'venda_id',        'tab' => 'vendas',        'ativas' => ETAPAS_VENDA_ATIVAS],
];

$totalDevolver = 0;
foreach ($tabelas as $t) {
    $stmt = $db->prepare("
        SELECT h.{$t['fk']} AS rid, h.observacao, h.created_at
        FROM {$t['hist']} h
        WHERE h.created_at >= datetime('now','localtime', ?)
        ORDER BY h.id DESC
    ");
    $stmt->execute(["-{$horas} hours"]);
    $vistos = [];
    $candidatos = [];
    foreach ($stmt->fetchAll() as $h) {
        $rid = (int)$h['rid'];
        if (isset($vistos[$rid])) continue; // só a movimentação mais recente de cada lead
        $par = _desfazerParse((string)$h['observacao']);
        if (!$par) continue;
        $vistos[$rid] = true;
        [$origem, $destino] = $par;
        if ($origem !== $alvo['nome']) continue;
        $candidatos[] = ['rid' => $rid, 'destino' => $destino, 'quando' => $h['created_at']];
    }

    echo "== {$t['rotulo']}: " . count($candidatos) . " lead(s) tirados de {$alvo['nome']} ==\n";
    foreach ($candidatos as $c) {
        $stmtR = $db->prepare("SELECT responsavel_id, etapa FROM {$t['tab']} WHERE id = ?");
        $stmtR->execute([$c['rid']]);
        $r = $stmtR->fetch();
        $destinoId = $usuariosPorNome[$c['destino']] ?? null;
        $prefixo = "  #{$c['rid']} (movido em {$c['quando']} pra {$c['destino']})";
        if (!$r) { echo "{$prefixo} — não existe mais, pulando\n"; continue; }
        if (!in_array($r['etapa'], $t['ativas'], true)) { echo "{$prefixo} — já está em '{$r['etapa']}', pulando\n"; continue; }
        if ($destinoId === null || (int)$r['responsavel_id'] !== $destinoId) { echo "{$prefixo} — já mudou de dono depois, pulando\n"; continue; }

        $totalDevolver++;
        echo "{$prefixo} — volta pra {$alvo['nome']}\n";
        if ($confirmar) {
            $db->beginTransaction();
            $db->prepare("UPDATE {$t['tab']} SET responsavel_id = ?, updated_at = datetime('now','localtime') WHERE id = ?")
               ->execute([$alvo['id'], $c['rid']]);
            $db->prepare("INSERT INTO {$t['hist']} ({$t['fk']}, etapa_anterior, etapa_nova, observacao, responsavel_id) VALUES (?, ?, ?, ?, NULL)")
               ->execute([$c['rid'], $r['etapa'], $r['etapa'], "Redistribuição desfeita: devolvido de {$c['destino']} pra {$alvo['nome']}"]);
            $db->commit();
        }
    }
    echo "\n";
}

echo $confirmar
    ? "✅ {$totalDevolver} lead(s) devolvido(s) pra {$alvo['nome']}.\n"
    : "{$totalDevolver} lead(s) seriam devolvidos. Rode de novo com --confirmar pra aplicar.\n";
if (($alvo['faltou_em'] ?? '') === date('Y-m-d')) {
    echo "⚠️ {$alvo['nome']} está marcado com FALTA hoje — desfaça em Configurações → Fila (↩️ Desfazer falta) pra ele voltar a receber lead novo.\n";
} elseif (!(int)$alvo['disponivel']) {
    echo "ℹ️ {$alvo['nome']} está OFFLINE na fila — se ele está trabalhando, ligue em Configurações → Fila.\n";
}
