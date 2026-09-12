<?php
/**
 * Instâncias Z-API por consultor/closer — canal paralelo ao funil oficial
 * (que roda inteiro na instância principal, config.zapi_*). Serve pra
 * capturar o que cada consultor conversa com o cliente por fora do fluxo
 * automático, dar visibilidade pro Jean e medir volume por pessoa.
 *
 * O telefone do cliente continua sendo a chave que junta tudo numa
 * conversa só (whatsapp_mensagens.telefone) — isso aqui só marca
 * QUEM/QUAL instância trouxe cada mensagem.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/usuarios.php';

/** Lista consultores/closers com (ou sem) instância própria configurada. */
function zapiListarInstanciasConsultores(): array {
    $db = getDB();
    return $db->query("
        SELECT u.id AS usuario_id, u.nome, u.perfil,
               i.instance_id, i.token, i.client_token, i.ativo
        FROM usuarios u
        LEFT JOIN zapi_instancias_consultores i ON i.usuario_id = u.id
        WHERE u.perfil IN ('consultor', 'closer') AND u.bloqueado = 0
        ORDER BY u.nome
    ")->fetchAll();
}

/** Cria ou atualiza a instância de um consultor (upsert por usuario_id). */
function zapiSalvarInstanciaConsultor(int $usuarioId, string $instanceId, string $token, string $clientToken): void {
    $db = getDB();
    $db->prepare("
        INSERT INTO zapi_instancias_consultores (usuario_id, instance_id, token, client_token, ativo)
        VALUES (?, ?, ?, ?, 1)
        ON CONFLICT(usuario_id) DO UPDATE SET
            instance_id = excluded.instance_id,
            token = excluded.token,
            client_token = excluded.client_token,
            ativo = 1
    ")->execute([$usuarioId, trim($instanceId), trim($token), trim($clientToken)]);
}

/** Desliga/remove a instância de um consultor (ex: saiu da empresa, trocou de número). */
function zapiRemoverInstanciaConsultor(int $usuarioId): void {
    $db = getDB();
    $db->prepare("DELETE FROM zapi_instancias_consultores WHERE usuario_id = ?")->execute([$usuarioId]);
}

/**
 * Identifica de onde veio um webhook a partir do instanceId que o próprio
 * Z-API manda no payload. Nunca lança — instância desconhecida só vira
 * 'desconhecida', quem chama decide o que fazer (webhook real rejeita por
 * segurança; sem instanceId nenhum — payload antigo/manual — assume
 * principal, pra não quebrar quem já estava configurado antes disso existir).
 *
 * Retorno: ['tipo' => 'principal'|'consultor'|'desconhecida',
 *           'usuario_id' => ?int, 'client_token' => ?string]
 */
function zapiIdentificarInstancia(string $instanceId): array {
    if ($instanceId === '') {
        return ['tipo' => 'principal', 'usuario_id' => null, 'client_token' => getConfig('zapi_client_token')];
    }

    if ($instanceId === (getConfig('zapi_instance_id') ?? '')) {
        return ['tipo' => 'principal', 'usuario_id' => null, 'client_token' => getConfig('zapi_client_token')];
    }

    $db = getDB();
    $stmt = $db->prepare("SELECT usuario_id, client_token FROM zapi_instancias_consultores WHERE instance_id = ? AND ativo = 1");
    $stmt->execute([$instanceId]);
    $row = $stmt->fetch();
    if ($row) {
        return ['tipo' => 'consultor', 'usuario_id' => (int)$row['usuario_id'], 'client_token' => $row['client_token']];
    }

    return ['tipo' => 'desconhecida', 'usuario_id' => null, 'client_token' => null];
}

/**
 * Volume de mensagens por consultor/closer nos últimos $dias — métrica de
 * produtividade mais simples (contagem), pedida como primeiro corte.
 * NULL de usuario_id (instância principal) fica de fora de propósito —
 * isso é volume do funil oficial, não de conversa paralela de alguém.
 */
function zapiContarMensagensPorConsultor(int $dias = 7): array {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT u.id AS usuario_id, u.nome,
               SUM(CASE WHEN m.direcao = 'out' THEN 1 ELSE 0 END) AS enviadas,
               SUM(CASE WHEN m.direcao = 'in' THEN 1 ELSE 0 END) AS recebidas,
               COUNT(DISTINCT m.telefone) AS clientes_distintos,
               MAX(m.created_at) AS ultima_mensagem
        FROM whatsapp_mensagens m
        JOIN usuarios u ON u.id = m.usuario_id
        WHERE m.created_at >= datetime('now', ?)
        GROUP BY u.id
        ORDER BY (enviadas + recebidas) DESC
    ");
    $stmt->execute(["-{$dias} days"]);
    return $stmt->fetchAll();
}
