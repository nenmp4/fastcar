<?php
/**
 * Configurações de API — restrito ao super_admin (Jean). Guarda as
 * credenciais em `config` (chave/valor), mesmo lugar que
 * includes/whatsapp_config.php já lê (zapi_instance_id, zapi_token,
 * zapi_client_token) — pendência #2 do CLAUDE.md.
 */

require_once __DIR__ . '/_bootstrap.php';
requireSuperAdmin();

$campos = [
    'zapi_instance_id'  => 'ID da instância Z-API',
    'zapi_token'        => 'Token da instância Z-API',
    'zapi_client_token' => 'Client-Token (segurança da conta Z-API)',
];

$camposIA = [
    'gemini_api_key' => 'Chave da API Gemini',
];

$erro = '';
$sucesso = '';
$testeResultado = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        $erro = 'Sessão expirada, recarregue a página e tente de novo.';
    } else {
        $acao = (string)($_POST['acao'] ?? '');

        if ($acao === 'salvar') {
            foreach (array_keys($campos) as $chave) {
                // Credencial opaca — só trim, sem clean()/strip_tags que
                // poderia mexer em caractere especial do token.
                setConfig($chave, trim((string)($_POST[$chave] ?? '')));
            }
            $sucesso = 'Configurações salvas.';
        } elseif ($acao === 'salvar_ia') {
            foreach (array_keys($camposIA) as $chave) {
                setConfig($chave, trim((string)($_POST[$chave] ?? '')));
            }
            setConfig('gemini_model', trim((string)($_POST['gemini_model'] ?? '')) ?: 'gemini-2.5-flash');
            $sucesso = 'Configurações de IA salvas.';
        } elseif ($acao === 'testar_ia') {
            $apiKey = getConfig('gemini_api_key') ?: '';
            if (!$apiKey) {
                $erro = 'Configure e salve a chave Gemini antes de testar.';
            } else {
                $resp = geminiCall('Responda só "ok" pra confirmar que a conexão está funcionando.', $apiKey, getConfig('gemini_model') ?: 'gemini-2.5-flash', 20);
                if (is_array($resp)) {
                    $erro = 'Falha no teste: ' . ($resp['erro'] ?? 'erro desconhecido');
                } else {
                    $sucesso = 'Gemini respondeu: "' . $resp . '" — conexão funcionando.';
                }
            }
        } elseif ($acao === 'testar_zapi') {
            $telefoneTeste = (string)($_POST['telefone_teste'] ?? '');
            if (!$telefoneTeste) {
                $erro = 'Informe um telefone pra receber a mensagem de teste.';
            } else {
                $ok = zapiEnviarTexto($telefoneTeste, '✅ Teste de conexão Z-API — Fastcar CRM.');
                $testeResultado = $ok;
                if ($ok) {
                    $sucesso = 'Mensagem de teste enviada com sucesso.';
                } else {
                    $erro = 'Falha ao enviar — confira as credenciais e se a instância está conectada.';
                }
            }
        } elseif ($acao === 'salvar_instancia_consultor') {
            $usuarioId = (int)($_POST['usuario_id'] ?? 0);
            $instanceId = trim((string)($_POST['instance_id'] ?? ''));
            $token = trim((string)($_POST['token'] ?? ''));
            if (!$usuarioId || !$instanceId || !$token) {
                $erro = 'Preencha ID da instância e token pra salvar.';
            } else {
                zapiSalvarInstanciaConsultor($usuarioId, $instanceId, $token, (string)($_POST['client_token'] ?? ''));
                $sucesso = 'Instância do consultor salva.';
            }
        } elseif ($acao === 'remover_instancia_consultor') {
            zapiRemoverInstanciaConsultor((int)($_POST['usuario_id'] ?? 0));
            $sucesso = 'Instância do consultor removida.';
        } elseif ($acao === 'definir_plantao') {
            definirPlantaoFimExpediente((int)($_POST['usuario_id'] ?? 0), !empty($_POST['ativo']));
            $sucesso = 'Plantão de fim de expediente atualizado.';
        }
    }
}

$valores = [];
foreach (array_keys($campos) as $chave) {
    $valores[$chave] = getConfig($chave) ?? '';
}
$configuradoZapi = $valores['zapi_instance_id'] && $valores['zapi_token'];
$instancias = zapiListarInstanciasConsultores();
$fila = listarFilaConsultores();
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Configurações — Fastcar CRM</title>
<link rel="stylesheet" href="/admin/assets/style.css">
</head>
<body>
<header class="topbar">
    <a href="/admin/index.php" style="color:#fff">← Voltar</a>
    <strong>🚗 Fastcar CRM</strong>
    <span>Olá, <?= e($_SESSION['admin_nome']) ?></span>
    <a href="/admin/logout.php">Sair</a>
</header>

<main>
<?php if ($erro): ?><div class="alerta-erro"><?= e($erro) ?></div><?php endif; ?>
<?php if ($sucesso): ?><div class="alerta-sucesso"><?= e($sucesso) ?></div><?php endif; ?>

<div class="card">
    <h2>⚙️ Instância principal (entrada, qualificação IA e follow-up)</h2>
    <p><small>Restrito ao super_admin. Essas credenciais dão acesso à instância de WhatsApp da Fastcar — não compartilhe.
       Cuida só dos blocos 2-4 do funil (entrada, IA, follow-up do cron); a partir do bloco 5 o atendimento passa a ser
       sempre pela instância própria do consultor, configurada abaixo.</small></p>

    <p>
        Status Z-API:
        <span class="badge <?= $configuradoZapi ? 'badge-ok' : 'badge-atraso' ?>">
            <?= $configuradoZapi ? '✅ credenciais preenchidas' : '⏳ ainda não configurado' ?>
        </span>
    </p>

    <form method="post" autocomplete="off">
        <?= csrfField() ?>
        <input type="hidden" name="acao" value="salvar">
        <?php foreach ($campos as $chave => $label): ?>
            <label for="<?= e($chave) ?>"><?= e($label) ?></label>
            <input type="password" id="<?= e($chave) ?>" name="<?= e($chave) ?>"
                   value="<?= e($valores[$chave]) ?>" autocomplete="off" placeholder="<?= $valores[$chave] ? '••••••••' : 'não configurado' ?>">
        <?php endforeach; ?>
        <label><input type="checkbox" id="mostrarSenhas" style="width:auto;display:inline-block"> mostrar valores</label>
        <button type="submit">Salvar configurações</button>
    </form>
</div>

<div class="card">
    <h3>Testar conexão Z-API</h3>
    <p><small>Manda uma mensagem de teste pro número informado, usando as credenciais salvas acima.</small></p>
    <form method="post">
        <?= csrfField() ?>
        <input type="hidden" name="acao" value="testar_zapi">
        <label>Telefone (com DDD)</label>
        <input type="text" name="telefone_teste" placeholder="Ex: 31999998888">
        <button type="submit" <?= $configuradoZapi ? '' : 'disabled' ?>>Enviar mensagem de teste</button>
        <?php if (!$configuradoZapi): ?>
            <p><small>Preencha e salve o ID da instância e o token acima antes de testar.</small></p>
        <?php endif; ?>
    </form>
</div>

<div class="card">
    <h2>🤖 IA de Qualificação (Gemini)</h2>
    <p><small>Resolve a pendência #3 do CLAUDE.md — mesmo provedor do JurídicoSaaS. Sem chave configurada, a IA
       simplesmente não responde (mensagem e oportunidade continuam sendo salvas normalmente).</small></p>

    <p>
        Status:
        <span class="badge <?= getConfig('gemini_api_key') ? 'badge-ok' : 'badge-atraso' ?>">
            <?= getConfig('gemini_api_key') ? '✅ chave configurada' : '⏳ ainda não configurado' ?>
        </span>
    </p>

    <form method="post" autocomplete="off">
        <?= csrfField() ?>
        <input type="hidden" name="acao" value="salvar_ia">
        <?php foreach ($camposIA as $chave => $label): ?>
            <label for="<?= e($chave) ?>"><?= e($label) ?></label>
            <input type="password" id="<?= e($chave) ?>" name="<?= e($chave) ?>"
                   value="<?= e(getConfig($chave) ?? '') ?>" autocomplete="off"
                   placeholder="<?= getConfig($chave) ? '••••••••' : 'não configurado' ?>">
        <?php endforeach; ?>
        <label>Modelo</label>
        <input type="text" name="gemini_model" value="<?= e(getConfig('gemini_model') ?: 'gemini-2.5-flash') ?>">
        <button type="submit">Salvar</button>
    </form>

    <form method="post" style="margin-top:12px">
        <?= csrfField() ?>
        <input type="hidden" name="acao" value="testar_ia">
        <button type="submit" <?= getConfig('gemini_api_key') ? '' : 'disabled' ?>>Testar conexão</button>
    </form>
</div>

<div class="card">
    <h3>👤 Instâncias dos consultores/closers</h3>
    <p><small>A partir do bloco 5 (atendimento), a conversa com o cliente passa a rodar SEMPRE pelo número/instância
       própria de quem estiver com a oportunidade — não pela instância principal. É registrado no mesmo histórico do
       cliente (mesmo telefone), só marcando quem falou por qual canal — dá visibilidade do que cada um conversa e o
       volume de mensagens (ver <a href="/admin/produtividade.php">produtividade</a>).</small></p>

    <?php if (!$instancias): ?>
        <p><small>Nenhum consultor/closer cadastrado ainda em <code>usuarios</code>.</small></p>
    <?php endif; ?>

    <?php foreach ($instancias as $inst): ?>
        <div class="historico-item" style="border-left-color:#1a5fb4">
            <strong><?= e($inst['nome']) ?></strong> <span class="badge"><?= e($inst['perfil']) ?></span>
            <?php if ($inst['instance_id']): ?>
                <span class="badge badge-ok">✅ instância configurada</span>
            <?php else: ?>
                <span class="badge badge-atraso">⏳ sem instância</span>
            <?php endif; ?>

            <form method="post" style="margin-top:8px">
                <?= csrfField() ?>
                <input type="hidden" name="acao" value="salvar_instancia_consultor">
                <input type="hidden" name="usuario_id" value="<?= (int)$inst['usuario_id'] ?>">
                <div class="grid-2">
                    <input type="text" name="instance_id" placeholder="ID da instância" value="<?= e($inst['instance_id'] ?? '') ?>">
                    <input type="text" name="token" placeholder="Token" value="<?= e($inst['token'] ?? '') ?>">
                </div>
                <input type="text" name="client_token" placeholder="Client-Token (opcional)" value="<?= e($inst['client_token'] ?? '') ?>">
                <button type="submit">Salvar</button>
                <?php if ($inst['instance_id']): ?>
                    <button type="button" class="perigo" onclick="
                        if (confirm('Remover a instância de <?= e($inst['nome']) ?>?')) {
                            var f = document.createElement('form');
                            f.method = 'post';
                            f.innerHTML = <?= json_encode(csrfField()) ?> +
                                '<input type=\'hidden\' name=\'acao\' value=\'remover_instancia_consultor\'>' +
                                '<input type=\'hidden\' name=\'usuario_id\' value=\'<?= (int)$inst['usuario_id'] ?>\'>';
                            document.body.appendChild(f);
                            f.submit();
                        }">Remover</button>
                <?php endif; ?>
            </form>
        </div>
    <?php endforeach; ?>
</div>

<div class="card">
    <h3>📥 Fila de distribuição automática de leads</h3>
    <p><small>Lead novo (bloco 2, na entrada) vai automaticamente pra quem estiver com "Disponível" ligado, em rodízio.
       Se ninguém estiver disponível, cai em quem estiver marcado como plantão de fim de expediente abaixo — vira
       responsável da oportunidade normalmente, nenhum lead fica sem dono fora do horário.</small></p>

    <?php if (!$fila): ?>
        <p><small>Nenhum consultor/closer cadastrado ainda.</small></p>
    <?php endif; ?>

    <table class="tabela-oportunidades">
        <thead>
            <tr><th>Nome</th><th>Status</th><th>Último lead recebido</th><th>Plantão fim de expediente</th></tr>
        </thead>
        <tbody>
        <?php foreach ($fila as $f): ?>
            <tr>
                <td><?= e($f['nome']) ?> <span class="badge"><?= e($f['perfil']) ?></span></td>
                <td>
                    <?php if ($f['plantao_fim_expediente']): ?>
                        <span class="badge">🌙 só plantão</span>
                    <?php elseif ($f['disponivel']): ?>
                        <span class="badge badge-ok">🟢 disponível</span>
                    <?php else: ?>
                        <span class="badge badge-atraso">⚪ offline</span>
                    <?php endif; ?>
                </td>
                <td><?= $f['ultimo_lead_recebido_em'] ? date('d/m H:i', strtotime($f['ultimo_lead_recebido_em'])) : '— nunca —' ?></td>
                <td>
                    <form method="post" class="inline">
                        <?= csrfField() ?>
                        <input type="hidden" name="acao" value="definir_plantao">
                        <input type="hidden" name="usuario_id" value="<?= (int)$f['id'] ?>">
                        <input type="hidden" name="ativo" value="<?= $f['plantao_fim_expediente'] ? '0' : '1' ?>">
                        <button type="submit" style="margin-top:0;padding:4px 10px;font-size:12px">
                            <?= $f['plantao_fim_expediente'] ? 'Remover plantão' : 'Marcar como plantão' ?>
                        </button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
</main>

<script>
document.getElementById('mostrarSenhas').addEventListener('change', function () {
    var tipo = this.checked ? 'text' : 'password';
    ['zapi_instance_id', 'zapi_token', 'zapi_client_token'].forEach(function (id) {
        document.getElementById(id).type = tipo;
    });
});
</script>
</body>
</html>
