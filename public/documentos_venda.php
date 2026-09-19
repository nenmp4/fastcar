<?php
/**
 * Wizard público (sem login) de documentos do COMPRADOR — módulo de
 * vendas/revenda, 19/09/2026: "lá no modulo vendas tem espelhar compra -
 * subir os documentos preencher tudo ter link igual de compra... mesma
 * dinâmica pra ficar padrão sistema". Espelha public/documentos.php
 * (funil de compra) rigorosamente no MESMO rito — 1 documento por vez, IA
 * lê e pré-preenche, cliente revisa/confirma, resumo final antes de
 * enviar — mas com só 2 etapas (CNH/RG → comprovante de endereço), já que
 * o comprador de revenda não tem financiamento ativo nem CRLV pra
 * entregar. Acesso só pelo token na URL (link mandado via WhatsApp) — ver
 * includes/venda_documentos.php::getOuCriarTokenDocumentosVenda().
 *
 * A etapa atual é sempre derivada do banco (nunca sessão/cookie) — mesmo
 * comportamento do wizard de compra.
 */

header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet', true);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/venda_documentos.php';
require_once __DIR__ . '/../includes/extracao_documentos.php';

startSecureSession();

const ORDEM_ETAPAS_VENDA = ['cnh', 'comprovante_endereco'];
$labelEtapaVenda = TIPOS_DOCUMENTOS_COMPRADOR;

$token = (string)($_GET['token'] ?? $_POST['token'] ?? '');
$v = buscarVendaPorToken($token);

if (!$v) {
    http_response_code(404);
    ?>
    <!doctype html><html lang="pt-br"><head><meta charset="utf-8"><title>Link inválido — Fastcar</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/png" href="/public/assets/favicon.png"></head>
    <body style="font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif;text-align:center;padding:60px 20px;background:#151722;color:#fff;min-height:100vh;margin:0">
        <img src="/public/assets/logo.png" alt="Fastcar" style="max-height:56px;margin-bottom:24px" onerror="this.style.display='none'">
        <h2>⚠️ Link inválido ou expirado</h2>
        <p style="color:#aab4d4">Fale com o vendedor da Fastcar pra receber um novo link.</p>
        <p style="color:#6b7aa0;font-size:11.5px;margin-top:40px;padding-top:16px;border-top:1px solid #2a3352">
        <strong style="color:#5b91ff">FASTCAR SOLUTIONS</strong> — CNPJ 66.934.500/0001-09<br>
        Av. Sagitário, 138 — Alphaville Conde II, Barueri/SP</p>
    </body></html>
    <?php
    exit;
}

$erro = '';
$avisoIA = '';
$avisoErroTipo = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        $erro = 'Sessão expirada — atualize a página e tente de novo.';
    } else {
        $acao = (string)($_POST['acao'] ?? '');
        $tipoForm = (string)($_POST['tipo'] ?? '');

        if ($acao === 'enviar_documento' && in_array($tipoForm, ORDEM_ETAPAS_VENDA, true)) {
            if (empty($_FILES['arquivo']) || ($_FILES['arquivo']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                $erro = 'Selecione o arquivo antes de enviar.';
            } else {
                $resultado = salvarUploadDocumentoVenda((int)$v['id'], $tipoForm, $_FILES['arquivo'], true);
                if (!$resultado['ok']) {
                    $erro = $resultado['erro'] ?? 'Falha ao enviar o arquivo.';
                } else {
                    $docSalvo = listarDocumentosVenda((int)$v['id'])[$tipoForm] ?? null;
                    if ($docSalvo) {
                        $arquivoLido = lerConteudoArquivoDocumento($docSalvo['drive_file_id'] ?: null, $docSalvo['arquivo_url'] ?: null);
                        if ($arquivoLido) {
                            $dadosExtraidos = extrairDadosDocumentoComIA($tipoForm, $arquivoLido);
                            if ($dadosExtraidos && !$dadosExtraidos['_documento_correto']) {
                                // Mesma proteção do lado de compra (17/09/2026,
                                // "consultor subiu documento do carro no lugar
                                // da cnh") — nunca aplica dado extraído quando
                                // a IA identifica que o arquivo não é o tipo
                                // esperado pra esse slot.
                                $tipoPercebido = $dadosExtraidos['_tipo_real_se_diferente'] ?: 'outro tipo de documento';
                                $avisoErroTipo = "Esse arquivo não parece ser " . ($labelEtapaVenda[$tipoForm] ?? $tipoForm)
                                    . " — parece ser {$tipoPercebido}. Envie o arquivo certo pra continuar (os dados não foram preenchidos automaticamente).";
                                getDB()->prepare("
                                    INSERT INTO venda_historico (venda_id, etapa_anterior, etapa_nova, observacao)
                                    VALUES (?, ?, ?, ?)
                                ")->execute([
                                    $v['id'], $v['etapa'], $v['etapa'],
                                    "IA identificou documento no slot errado — enviado como '" . ($labelEtapaVenda[$tipoForm] ?? $tipoForm) . "', parece ser: {$tipoPercebido}.",
                                ]);
                            } elseif ($dadosExtraidos) {
                                // Mesma comparação genérica do lado de compra
                                // (compararDivergenciasDocumento() não toca em
                                // tabela, só compara arrays por nome de campo)
                                // — dados atuais mapeados das colunas
                                // comprador_* pras mesmas chaves genéricas.
                                $dadosAtuais = [
                                    'nome' => $v['comprador_nome'] ?? '', 'cpf' => $v['comprador_cpf'] ?? '',
                                    'rg' => $v['comprador_rg'] ?? '', 'cnh' => $v['comprador_cnh'] ?? '',
                                    'endereco' => $v['comprador_endereco'] ?? '',
                                ];
                                $divergencias = compararDivergenciasDocumento($dadosAtuais, $dadosExtraidos);
                                aplicarDadosExtraidosDocumentoVenda((int)$v['id'], $dadosExtraidos);
                                if ($divergencias) {
                                    $avisoIA = '⚠️ Reparei uma diferença entre o que já tínhamos e o que esse documento mostra — confira com atenção: '
                                        . implode(' ', $divergencias);
                                    getDB()->prepare("
                                        INSERT INTO venda_historico (venda_id, etapa_anterior, etapa_nova, observacao)
                                        VALUES (?, ?, ?, ?)
                                    ")->execute([
                                        $v['id'], $v['etapa'], $v['etapa'],
                                        "IA detectou divergência no documento '" . ($labelEtapaVenda[$tipoForm] ?? $tipoForm) . "': " . implode(' ', $divergencias),
                                    ]);
                                } else {
                                    $avisoIA = 'Li seu documento e já preenchi o que consegui identificar — confira se está tudo certo abaixo.';
                                }
                            } else {
                                $avisoIA = 'Não consegui ler os dados automaticamente — confira/preencha manualmente abaixo.';
                            }
                        }
                    }
                }
            }
        } elseif ($acao === 'confirmar_etapa' && in_array($tipoForm, ORDEM_ETAPAS_VENDA, true)) {
            if ($tipoForm === 'cnh') {
                atualizarDadosPessoaisComprador(
                    (int)$v['id'],
                    (string)($_POST['nome'] ?? ''),
                    (string)($_POST['cpf'] ?? ''),
                    (string)($v['comprador_endereco'] ?? ''), // endereço é da etapa seguinte, não toca aqui
                    (string)($_POST['rg'] ?? ''),
                    (string)($_POST['cnh'] ?? ''),
                    (string)($_POST['nacionalidade'] ?? ''),
                    (string)($_POST['estado_civil'] ?? ''),
                    (string)($_POST['profissao'] ?? ''),
                    (string)($_POST['email'] ?? '')
                );
            } elseif ($tipoForm === 'comprovante_endereco') {
                atualizarDadosPessoaisComprador(
                    (int)$v['id'],
                    (string)($v['comprador_nome'] ?? ''), (string)($v['comprador_cpf'] ?? ''),
                    (string)($_POST['endereco'] ?? ''),
                    (string)($v['comprador_rg'] ?? ''), (string)($v['comprador_cnh'] ?? ''),
                    (string)($v['comprador_nacionalidade'] ?? ''), (string)($v['comprador_estado_civil'] ?? ''), (string)($v['comprador_profissao'] ?? ''),
                    (string)($v['comprador_email'] ?? '')
                );
            }

            $db = getDB();
            $db->prepare("UPDATE venda_documentos SET dados_confirmados = 1, updated_at = datetime('now','localtime') WHERE venda_id = ? AND tipo = ?")
                ->execute([$v['id'], $tipoForm]);
            // Editar uma etapa depois de já ter confirmado tudo força o
            // vendedor a olhar de novo antes do contrato — mesmo rito de compra.
            $db->prepare("UPDATE vendas SET documentos_confirmados_em = NULL WHERE id = ?")->execute([$v['id']]);
        } elseif ($acao === 'confirmar_final') {
            $db = getDB();
            $db->prepare("UPDATE vendas SET documentos_confirmados_em = datetime('now','localtime') WHERE id = ?")
                ->execute([$v['id']]);
        }

        $v = buscarVendaPorToken($token);
    }
}

$documentos = listarDocumentosVenda((int)$v['id']);
$stmtVFull = getDB()->prepare("SELECT documentos_confirmados_em FROM vendas WHERE id = ?");
$stmtVFull->execute([$v['id']]);
$confirmadoEm = $stmtVFull->fetchColumn();

$revisar = (string)($_GET['revisar'] ?? '');

$fase = 'concluido';
$tipoAtual = '';
foreach (ORDEM_ETAPAS_VENDA as $tipo) {
    $d = $documentos[$tipo] ?? null;
    $temArquivo = $d && ($d['arquivo_url'] || $d['drive_file_id']);
    if (!$temArquivo) { $fase = 'upload'; $tipoAtual = $tipo; break; }
    if (!$d['dados_confirmados']) { $fase = 'revisao'; $tipoAtual = $tipo; break; }
}
if ($fase === 'concluido' && !$confirmadoEm) {
    $fase = 'revisao_final';
}
if ($revisar && in_array($revisar, ORDEM_ETAPAS_VENDA, true) && ($documentos[$revisar]['arquivo_url'] ?? $documentos[$revisar]['drive_file_id'] ?? '')) {
    $fase = 'revisao';
    $tipoAtual = $revisar;
}

$numeroEtapa = array_search($tipoAtual, ORDEM_ETAPAS_VENDA, true);
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Envio de documentos — Fastcar</title>
<link rel="icon" type="image/png" href="/public/assets/favicon.png">
<style>
/* Mesma paleta/CSS do wizard de compra (public/documentos.php), pra manter
   a identidade visual idêntica nos dois — comprador não deveria perceber
   diferença entre os dois wizards além do conteúdo dos passos. */
:root {
    --navy: #151722;
    --navy-2: #101d40;
    --blue: #2f6fed;
    --blue-dark: #1a4fc4;
    --texto: #1c1e29;
}
* { box-sizing: border-box; }
body {
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
    background: #f4f5f9;
    color: var(--texto);
    margin: 0;
}
.marca { text-align: center; padding: 32px 16px 26px; background: linear-gradient(135deg, var(--navy) 0%, var(--navy-2) 100%); }
.marca img { max-height: 64px; margin-bottom: 6px; }
.marca .logotipo { font-size: 26px; font-weight: 700; color: #fff; letter-spacing: .2px; }
.marca .logotipo b { color: var(--blue); }
.marca .subtitulo { font-size: 12px; color: #aab4d4; letter-spacing: .5px; text-transform: uppercase; margin-top: 2px; }
.wrap { max-width: 480px; margin: 0 auto; padding: 20px 16px 60px; }
h1 { font-size: 20px; }
.card { background: #fff; border-radius: 14px; padding: 20px 22px; margin-bottom: 16px; box-shadow: 0 8px 24px rgba(10,18,41,.14); }
label { display: block; font-size: 13px; color: #555; margin: 14px 0 4px; }
input[type=text], input[type=email] { width: 100%; padding: 11px 12px; border: 1px solid #d8dce4; border-radius: 8px; font-size: 15px; }
input[type=text]:focus, input[type=email]:focus { outline: none; border-color: var(--blue); box-shadow: 0 0 0 3px rgba(47,111,237,.15); }
input[type=file] { width: 100%; margin-top: 4px; }
button { width: 100%; margin-top: 20px; padding: 13px; border: none; border-radius: 10px;
    background: linear-gradient(135deg, var(--blue) 0%, var(--blue-dark) 100%); color: #fff;
    font-size: 16px; font-weight: 600; cursor: pointer; }
.status-ok { color: #2a7a3b; font-size: 13px; }
.alerta-erro { background: #fbe4e1; color: #a33; padding: 10px 14px; border-radius: 8px; margin-bottom: 14px; }
.alerta-info { background: #e8f0fc; color: var(--blue-dark); padding: 10px 14px; border-radius: 8px; margin-bottom: 14px; }
.alerta-sucesso { background: #e3f3e6; color: #2a7a3b; padding: 10px 14px; border-radius: 8px; margin-bottom: 14px; }
.passos { display: flex; gap: 6px; margin-bottom: 18px; }
.passos span { flex: 1; height: 4px; border-radius: 2px; background: #dde3f0; }
.passos span.feito { background: #22a559; }
.passos span.atual { background: var(--blue); }
.resumo dt { font-size: 12px; color: #888; margin-top: 10px; }
.resumo dd { margin: 2px 0 0; font-size: 15px; }
.rodape-empresa {
    text-align: center;
    font-size: 11.5px;
    color: #6b7aa0;
    line-height: 1.7;
    margin-top: 28px;
    padding: 18px 16px 4px;
    border-top: 2px solid transparent;
    border-image: linear-gradient(90deg, transparent, var(--blue) 50%, transparent) 1;
}
.rodape-empresa strong { color: var(--blue-dark); font-size: 12.5px; letter-spacing: .02em; }
</style>
</head>
<body>
<div class="marca">
    <img src="/public/assets/logo.png" alt="Fastcar" onerror="this.style.display='none'">
    <div class="logotipo">Fast<b>Car</b></div>
    <div class="subtitulo">Soluções Financeiras</div>
</div>
<div class="wrap">
    <?php if ($v['veiculo_marca']): ?>
        <p style="text-align:center;color:#666;font-size:13.5px;margin-top:0">
            Veículo: <strong><?= e(trim($v['veiculo_marca'] . ' ' . $v['veiculo_modelo'] . ' ' . $v['veiculo_ano'])) ?></strong>
        </p>
    <?php endif; ?>

    <?php if ($fase !== 'concluido'): ?>
    <div class="passos">
        <?php foreach (ORDEM_ETAPAS_VENDA as $i => $t):
            $d = $documentos[$t] ?? null;
            $classe = ($d && $d['dados_confirmados']) ? 'feito' : ($t === $tipoAtual ? 'atual' : '');
        ?>
            <span class="<?= $classe ?>"></span>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($erro): ?><div class="alerta-erro"><?= e($erro) ?></div><?php endif; ?>
    <?php if ($avisoErroTipo): ?><div class="alerta-erro">🤖 <?= e($avisoErroTipo) ?></div><?php endif; ?>
    <?php if ($avisoIA): ?><div class="alerta-info">🤖 <?= e($avisoIA) ?></div><?php endif; ?>

    <?php if ($fase === 'upload'): ?>
        <p>Etapa <?= $numeroEtapa + 1 ?> de <?= count(ORDEM_ETAPAS_VENDA) ?> — envie: <strong><?= e($labelEtapaVenda[$tipoAtual]) ?></strong></p>
        <div class="card">
            <form method="post" enctype="multipart/form-data">
                <?= csrfField() ?>
                <input type="hidden" name="token" value="<?= e($token) ?>">
                <input type="hidden" name="acao" value="enviar_documento">
                <input type="hidden" name="tipo" value="<?= e($tipoAtual) ?>">
                <label><?= e($labelEtapaVenda[$tipoAtual]) ?></label>
                <input type="file" name="arquivo" accept="image/jpeg,image/png,image/webp,application/pdf" required>
                <button type="submit">Enviar</button>
            </form>
        </div>

    <?php elseif ($fase === 'revisao'): ?>
        <p>Confira os dados abaixo — corrija o que estiver errado antes de avançar.</p>
        <details style="margin-bottom:14px">
            <summary style="cursor:pointer;color:var(--blue-dark);font-size:13.5px">Enviou o arquivo errado? Envie outro no lugar</summary>
            <form method="post" enctype="multipart/form-data" style="margin-top:10px">
                <?= csrfField() ?>
                <input type="hidden" name="token" value="<?= e($token) ?>">
                <input type="hidden" name="acao" value="enviar_documento">
                <input type="hidden" name="tipo" value="<?= e($tipoAtual) ?>">
                <input type="file" name="arquivo" accept="image/jpeg,image/png,image/webp,application/pdf" required>
                <button type="submit">Substituir arquivo</button>
            </form>
        </details>
        <div class="card">
            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="token" value="<?= e($token) ?>">
                <input type="hidden" name="acao" value="confirmar_etapa">
                <input type="hidden" name="tipo" value="<?= e($tipoAtual) ?>">

                <?php if ($tipoAtual === 'cnh'): ?>
                    <label>Nome completo</label>
                    <input type="text" name="nome" value="<?= e($v['comprador_nome'] ?? '') ?>" required>
                    <label>CPF</label>
                    <input type="text" name="cpf" value="<?= e($v['comprador_cpf'] ?? '') ?>" placeholder="000.000.000-00">
                    <label>E-mail</label>
                    <input type="email" name="email" value="<?= e($v['comprador_email'] ?? '') ?>" placeholder="voce@email.com" required>
                    <label>RG</label>
                    <input type="text" name="rg" value="<?= e($v['comprador_rg'] ?? '') ?>">
                    <label>Nº da CNH (se tiver)</label>
                    <input type="text" name="cnh" value="<?= e($v['comprador_cnh'] ?? '') ?>">
                    <label>Nacionalidade</label>
                    <input type="text" name="nacionalidade" value="<?= e($v['comprador_nacionalidade'] ?: 'Brasileiro(a)') ?>">
                    <label>Estado civil</label>
                    <input type="text" name="estado_civil" value="<?= e($v['comprador_estado_civil'] ?? '') ?>" placeholder="Solteiro(a), casado(a)...">
                    <label>Profissão</label>
                    <input type="text" name="profissao" value="<?= e($v['comprador_profissao'] ?? '') ?>">
                <?php elseif ($tipoAtual === 'comprovante_endereco'): ?>
                    <label>Endereço completo</label>
                    <input type="text" name="endereco" value="<?= e($v['comprador_endereco'] ?? '') ?>" placeholder="Rua, número, bairro, cidade" required>
                <?php endif; ?>

                <button type="submit">Confirmar e avançar</button>
            </form>
        </div>

    <?php elseif ($fase === 'revisao_final'): ?>
        <p>Confira o resumo antes de enviar pro vendedor:</p>
        <div class="card resumo">
            <dl>
                <dt>Nome</dt><dd><?= e($v['comprador_nome'] ?: '—') ?></dd>
                <dt>CPF</dt><dd><?= e($v['comprador_cpf'] ?: '—') ?></dd>
                <dt>E-mail</dt><dd><?= e($v['comprador_email'] ?: '—') ?></dd>
                <dt>RG</dt><dd><?= e($v['comprador_rg'] ?: '—') ?></dd>
                <dt>Endereço</dt><dd><?= e($v['comprador_endereco'] ?: '—') ?></dd>
            </dl>
            <p><small>Alguma coisa errada? Volte na
                <a href="?token=<?= e($token) ?>&revisar=cnh">CNH/RG</a> ou
                <a href="?token=<?= e($token) ?>&revisar=comprovante_endereco">comprovante de endereço</a>.</small></p>
            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="token" value="<?= e($token) ?>">
                <input type="hidden" name="acao" value="confirmar_final">
                <button type="submit">✅ Confirmar e enviar</button>
            </form>
        </div>

    <?php else: ?>
        <div class="alerta-sucesso">✅ Tudo certo! Recebemos seus documentos e dados — o vendedor vai analisar e entrar em contato.</div>
        <p><small>Precisa corrigir algo? Volte na
            <a href="?token=<?= e($token) ?>&revisar=cnh">CNH/RG</a> ou
            <a href="?token=<?= e($token) ?>&revisar=comprovante_endereco">comprovante de endereço</a>.</small></p>
    <?php endif; ?>

    <footer class="rodape-empresa">
        <strong>FASTCAR SOLUTIONS</strong> — CNPJ 66.934.500/0001-09<br>
        Av. Sagitário, 138 — Sala 1003, 10º andar, Torre City (Torre 2), Complexo Alpha Square Offices<br>
        Alphaville Conde II, Barueri/SP — CEP 06473-073
    </footer>
</div>
</body>
</html>
