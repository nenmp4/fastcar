<?php
/**
 * Wizard público (sem login) de documentos — pedido do José/Jean em
 * 13/09/2026: em vez de 1 formulário só (digita tudo + sobe tudo de uma
 * vez), o cliente sobe 1 documento por vez (CNH → comprovante de endereço
 * → contrato de financiamento); a cada upload a IA lê o documento e
 * pré-preenche os campos (includes/extracao_documentos.php) — o cliente só
 * revisa/corrige e confirma antes de avançar. No final, uma tela de resumo
 * com "Confirmar e enviar". Reduz o risco de nome/CPF digitado errado indo
 * reto pro contrato (ninguém mais tem que digitar do zero — só corrigir se
 * a IA leu algo errado). Acesso só pelo token na URL (link mandado via
 * WhatsApp) — ver includes/documentos.php::getOuCriarTokenDocumentos().
 *
 * A etapa atual é sempre derivada do banco (nunca de sessão/cookie): o
 * cliente pode fechar a aba e voltar pelo mesmo link dias depois, de outro
 * aparelho, que continua exatamente de onde parou.
 */

// Link com token na URL, sem login — nunca pode ser indexado nem cacheado
// por robô (um crawler que seguisse o link exporia o token de um cliente).
header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet', true);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/documentos.php';
require_once __DIR__ . '/../includes/extracao_documentos.php';

startSecureSession();

const ORDEM_ETAPAS = ['cnh', 'comprovante_endereco', 'contrato_financiamento'];
$labelEtapa = [
    'cnh' => 'CNH (frente e verso, ou documento com foto)',
    'comprovante_endereco' => 'Comprovante de endereço (últimos 3 meses)',
    'contrato_financiamento' => 'Contrato de financiamento do veículo (com o banco)',
];

$token = (string)($_GET['token'] ?? $_POST['token'] ?? '');
$op = buscarOportunidadePorToken($token);

if (!$op) {
    http_response_code(404);
    ?>
    <!doctype html><html lang="pt-br"><head><meta charset="utf-8"><title>Link inválido — Fastcar</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/png" href="/public/assets/favicon.png"></head>
    <body style="font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif;text-align:center;padding:60px 20px;background:#151722;color:#fff;min-height:100vh;margin:0">
        <img src="/public/assets/logo.png" alt="Fastcar" style="max-height:56px;margin-bottom:24px" onerror="this.style.display='none'">
        <h2>⚠️ Link inválido ou expirado</h2>
        <p style="color:#aab4d4">Fale com seu consultor da Fastcar pra receber um novo link.</p>
        <p style="color:#6b7aa0;font-size:11.5px;margin-top:40px;padding-top:16px;border-top:1px solid #2a3352">
        <strong style="color:#5b91ff">FASTCAR SOLUTIONS</strong> — CNPJ 66.934.500/0001-09<br>
        Av. Sagitário, 138 — Alphaville Conde II, Barueri/SP</p>
    </body></html>
    <?php
    exit;
}

$erro = '';
$avisoIA = ''; // "li seu documento, confira se bateu certo" — só informativo, nunca bloqueia

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        $erro = 'Sessão expirada — atualize a página e tente de novo.';
    } else {
        $acao = (string)($_POST['acao'] ?? '');
        $tipoForm = (string)($_POST['tipo'] ?? '');

        if ($acao === 'enviar_documento' && in_array($tipoForm, ORDEM_ETAPAS, true)) {
            if (empty($_FILES['arquivo']) || ($_FILES['arquivo']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                $erro = 'Selecione o arquivo antes de enviar.';
            } else {
                $resultado = salvarUploadDocumento($op['oportunidade_id'], $tipoForm, $_FILES['arquivo'], true);
                if (!$resultado['ok']) {
                    $erro = $resultado['erro'] ?? 'Falha ao enviar o arquivo.';
                } else {
                    // Lê o arquivo que acabou de ser salvo e manda pra IA extrair
                    // os dados — nunca trava o wizard se a IA falhar/não tiver
                    // chave configurada, o cliente só revisa/digita manualmente.
                    $docSalvo = listarDocumentos($op['oportunidade_id'])[$tipoForm] ?? null;
                    if ($docSalvo) {
                        $arquivoLido = lerConteudoArquivoDocumento($docSalvo['drive_file_id'] ?: null, $docSalvo['arquivo_url'] ?: null);
                        if ($arquivoLido) {
                            $dadosExtraidos = extrairDadosDocumentoComIA($tipoForm, $arquivoLido);
                            if ($dadosExtraidos) {
                                // Compara ANTES de aplicar (aplicar só preenche vazio,
                                // nunca sobrescreve — a comparação precisa do valor que
                                // já existia pra detectar documento de outra
                                // pessoa/veículo, pedido explícito do José/Jean).
                                $divergencias = compararDivergenciasDocumento($op, $dadosExtraidos);
                                aplicarDadosExtraidosDocumento((int)$op['cliente_id'], (int)$op['oportunidade_id'], $tipoForm, $dadosExtraidos);
                                if ($divergencias) {
                                    $avisoIA = '⚠️ Reparei uma diferença entre o que já tínhamos e o que esse documento mostra — confira com atenção: '
                                        . implode(' ', $divergencias);
                                    getDB()->prepare("
                                        INSERT INTO oportunidade_historico (oportunidade_id, etapa_anterior, etapa_nova, observacao)
                                        VALUES (?, ?, ?, ?)
                                    ")->execute([
                                        $op['oportunidade_id'], $op['etapa'], $op['etapa'],
                                        "IA detectou divergência no documento '" . ($labelEtapa[$tipoForm] ?? $tipoForm) . "': " . implode(' ', $divergencias),
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
        } elseif ($acao === 'confirmar_etapa' && in_array($tipoForm, ORDEM_ETAPAS, true)) {
            if ($tipoForm === 'cnh') {
                atualizarDadosPessoaisCliente(
                    (int)$op['cliente_id'],
                    (string)($_POST['nome'] ?? ''),
                    (string)($_POST['cpf'] ?? ''),
                    (string)($op['endereco'] ?? ''), // endereço é da etapa seguinte, não toca aqui
                    (string)($_POST['rg'] ?? ''),
                    (string)($_POST['cnh'] ?? ''),
                    (string)($_POST['nacionalidade'] ?? ''),
                    (string)($_POST['estado_civil'] ?? ''),
                    (string)($_POST['profissao'] ?? '')
                );
            } elseif ($tipoForm === 'comprovante_endereco') {
                atualizarDadosPessoaisCliente(
                    (int)$op['cliente_id'],
                    (string)($op['nome'] ?? ''), (string)($op['cpf'] ?? ''),
                    (string)($_POST['endereco'] ?? ''),
                    (string)($op['rg'] ?? ''), (string)($op['cnh'] ?? ''),
                    (string)($op['nacionalidade'] ?? ''), (string)($op['estado_civil'] ?? ''), (string)($op['profissao'] ?? '')
                );
            } elseif ($tipoForm === 'contrato_financiamento') {
                $db = getDB();
                $db->prepare("
                    UPDATE oportunidades
                    SET banco_financiamento = ?, veiculo_marca = ?, veiculo_modelo = ?, veiculo_ano = ?,
                        veiculo_placa = ?, veiculo_renavam = ?, veiculo_chassi = ?,
                        valor_parcela = ?, parcelas_restantes = ?, contrato_financiamento_numero = ?,
                        updated_at = datetime('now','localtime')
                    WHERE id = ?
                ")->execute([
                    clean((string)($_POST['banco_financiamento'] ?? '')),
                    clean((string)($_POST['veiculo_marca'] ?? '')),
                    clean((string)($_POST['veiculo_modelo'] ?? '')),
                    clean((string)($_POST['veiculo_ano'] ?? '')),
                    clean((string)($_POST['veiculo_placa'] ?? '')),
                    clean((string)($_POST['veiculo_renavam'] ?? '')),
                    clean((string)($_POST['veiculo_chassi'] ?? '')),
                    $_POST['valor_parcela'] !== '' ? (float)str_replace(',', '.', (string)$_POST['valor_parcela']) : null,
                    $_POST['parcelas_restantes'] !== '' ? (int)$_POST['parcelas_restantes'] : null,
                    clean((string)($_POST['contrato_financiamento_numero'] ?? '')),
                    (int)$op['oportunidade_id'],
                ]);
            }

            $db = getDB();
            $db->prepare("UPDATE oportunidade_documentos SET dados_confirmados = 1, updated_at = datetime('now','localtime') WHERE oportunidade_id = ? AND tipo = ?")
                ->execute([$op['oportunidade_id'], $tipoForm]);
            // Editar uma etapa depois de já ter confirmado tudo força o
            // consultor a olhar de novo antes do contrato.
            $db->prepare("UPDATE oportunidades SET documentos_confirmados_em = NULL WHERE id = ?")->execute([$op['oportunidade_id']]);
        } elseif ($acao === 'confirmar_final') {
            $db = getDB();
            $db->prepare("UPDATE oportunidades SET documentos_confirmados_em = datetime('now','localtime') WHERE id = ?")
                ->execute([$op['oportunidade_id']]);
        }

        // Recarrega pra refletir o que já foi salvo.
        $op = buscarOportunidadePorToken($token);
    }
}

$documentos = listarDocumentos($op['oportunidade_id']);
$stmtOpFull = getDB()->prepare("SELECT documentos_confirmados_em FROM oportunidades WHERE id = ?");
$stmtOpFull->execute([$op['oportunidade_id']]);
$confirmadoEm = $stmtOpFull->fetchColumn();

$revisar = (string)($_GET['revisar'] ?? '');

// Deriva a etapa atual: 1º tipo (na ordem) sem arquivo → upload; 1º tipo com
// arquivo mas ainda não confirmado → revisão; todos confirmados e nunca
// finalizado (ou ?revisar=tipo pedindo pra voltar) → mostra o que foi pedido.
$fase = 'concluido';
$tipoAtual = '';
foreach (ORDEM_ETAPAS as $tipo) {
    $d = $documentos[$tipo] ?? null;
    $temArquivo = $d && ($d['arquivo_url'] || $d['drive_file_id']);
    if (!$temArquivo) { $fase = 'upload'; $tipoAtual = $tipo; break; }
    if (!$d['dados_confirmados']) { $fase = 'revisao'; $tipoAtual = $tipo; break; }
}
if ($fase === 'concluido' && !$confirmadoEm) {
    $fase = 'revisao_final';
}
if ($revisar && in_array($revisar, ORDEM_ETAPAS, true) && ($documentos[$revisar]['arquivo_url'] ?? $documentos[$revisar]['drive_file_id'] ?? '')) {
    $fase = 'revisao';
    $tipoAtual = $revisar;
}

$numeroEtapa = array_search($tipoAtual, ORDEM_ETAPAS, true);
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Envio de documentos — Fastcar</title>
<link rel="icon" type="image/png" href="/public/assets/favicon.png">
<style>
/* Paleta da marca Fast Car — azul-marinho escuro + azul de destaque + branco
   (mesma logo enviada pelo Jean/José, 13/09/2026: fundo #151722, "Fast"
   branco, "Car" azul). Cartões ficam claros por cima do fundo escuro —
   formulário precisa de contraste alto pra input ser legível, não dá pra
   deixar tudo escuro só por estética. */
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
/* Faixa escura isolada só no cabeçalho da marca — nunca embaixo de texto de
   conteúdo (etapa, alertas), senão contraste quebra dependendo de quanto
   conteúdo tem acima na página (bug real: "Etapa 1 de 3" ficava ilegível,
   texto escuro sobre fundo escuro, quando esse texto caía dentro da faixa). */
.marca { text-align: center; padding: 32px 16px 26px; background: linear-gradient(135deg, var(--navy) 0%, var(--navy-2) 100%); }
.marca img { max-height: 64px; margin-bottom: 6px; }
.marca .logotipo { font-size: 26px; font-weight: 700; color: #fff; letter-spacing: .2px; }
.marca .logotipo b { color: var(--blue); }
.marca .subtitulo { font-size: 12px; color: #aab4d4; letter-spacing: .5px; text-transform: uppercase; margin-top: 2px; }
.wrap { max-width: 480px; margin: 0 auto; padding: 20px 16px 60px; }
h1 { font-size: 20px; }
.card { background: #fff; border-radius: 14px; padding: 20px 22px; margin-bottom: 16px; box-shadow: 0 8px 24px rgba(10,18,41,.14); }
label { display: block; font-size: 13px; color: #555; margin: 14px 0 4px; }
input[type=text] { width: 100%; padding: 11px 12px; border: 1px solid #d8dce4; border-radius: 8px; font-size: 15px; }
input[type=text]:focus { outline: none; border-color: var(--blue); box-shadow: 0 0 0 3px rgba(47,111,237,.15); }
input[type=file] { width: 100%; margin-top: 4px; }
button { width: 100%; margin-top: 20px; padding: 13px; border: none; border-radius: 10px;
    background: linear-gradient(135deg, var(--blue) 0%, var(--blue-dark) 100%); color: #fff;
    font-size: 16px; font-weight: 600; cursor: pointer; }
button.secundario { background: #e5e8ef; color: var(--texto); margin-top: 8px; }
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
/* Endereço real da empresa no rodapé — ajuda a passar confiança de que o
   link não é golpe (pedido explícito do José/Jean, mesma preocupação já
   coberta no prompt da IA de qualificação: pedir dado financeiro por
   WhatsApp de um número desconhecido levanta desconfiança legítima hoje
   em dia). */
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
    <?php if ($fase !== 'concluido'): ?>
    <div class="passos">
        <?php foreach (ORDEM_ETAPAS as $i => $t):
            $d = $documentos[$t] ?? null;
            $classe = ($d && $d['dados_confirmados']) ? 'feito' : ($t === $tipoAtual ? 'atual' : '');
        ?>
            <span class="<?= $classe ?>"></span>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($erro): ?><div class="alerta-erro"><?= e($erro) ?></div><?php endif; ?>
    <?php if ($avisoIA): ?><div class="alerta-info">🤖 <?= e($avisoIA) ?></div><?php endif; ?>

    <?php if ($fase === 'upload'): ?>
        <p>Etapa <?= $numeroEtapa + 1 ?> de <?= count(ORDEM_ETAPAS) ?> — envie: <strong><?= e($labelEtapa[$tipoAtual]) ?></strong></p>
        <div class="card">
            <form method="post" enctype="multipart/form-data">
                <?= csrfField() ?>
                <input type="hidden" name="token" value="<?= e($token) ?>">
                <input type="hidden" name="acao" value="enviar_documento">
                <input type="hidden" name="tipo" value="<?= e($tipoAtual) ?>">
                <label><?= e($labelEtapa[$tipoAtual]) ?></label>
                <input type="file" name="arquivo" accept="image/jpeg,image/png,image/webp,application/pdf" required>
                <button type="submit">Enviar</button>
            </form>
        </div>

    <?php elseif ($fase === 'revisao'): ?>
        <p>Confira os dados abaixo — corrija o que estiver errado antes de avançar.</p>
        <div class="card">
            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="token" value="<?= e($token) ?>">
                <input type="hidden" name="acao" value="confirmar_etapa">
                <input type="hidden" name="tipo" value="<?= e($tipoAtual) ?>">

                <?php if ($tipoAtual === 'cnh'): ?>
                    <label>Nome completo</label>
                    <input type="text" name="nome" value="<?= e($op['nome'] ?? '') ?>" required>
                    <label>CPF</label>
                    <input type="text" name="cpf" value="<?= e($op['cpf'] ?? '') ?>" placeholder="000.000.000-00">
                    <label>RG</label>
                    <input type="text" name="rg" value="<?= e($op['rg'] ?? '') ?>">
                    <label>Nº da CNH (se tiver)</label>
                    <input type="text" name="cnh" value="<?= e($op['cnh'] ?? '') ?>">
                    <label>Nacionalidade</label>
                    <input type="text" name="nacionalidade" value="<?= e($op['nacionalidade'] ?: 'Brasileiro(a)') ?>">
                    <label>Estado civil</label>
                    <input type="text" name="estado_civil" value="<?= e($op['estado_civil'] ?? '') ?>" placeholder="Solteiro(a), casado(a)...">
                    <label>Profissão</label>
                    <input type="text" name="profissao" value="<?= e($op['profissao'] ?? '') ?>">
                <?php elseif ($tipoAtual === 'comprovante_endereco'): ?>
                    <label>Endereço completo</label>
                    <input type="text" name="endereco" value="<?= e($op['endereco'] ?? '') ?>" placeholder="Rua, número, bairro, cidade" required>
                <?php elseif ($tipoAtual === 'contrato_financiamento'): ?>
                    <label>Banco/financeira</label>
                    <input type="text" name="banco_financiamento" value="<?= e($op['banco_financiamento'] ?? '') ?>">
                    <label>Marca do veículo</label>
                    <input type="text" name="veiculo_marca" value="<?= e($op['veiculo_marca'] ?? '') ?>">
                    <label>Modelo</label>
                    <input type="text" name="veiculo_modelo" value="<?= e($op['veiculo_modelo'] ?? '') ?>">
                    <label>Ano</label>
                    <input type="text" name="veiculo_ano" value="<?= e($op['veiculo_ano'] ?? '') ?>">
                    <label>Placa</label>
                    <input type="text" name="veiculo_placa" value="<?= e($op['veiculo_placa'] ?? '') ?>">
                    <label>Renavam</label>
                    <input type="text" name="veiculo_renavam" value="<?= e($op['veiculo_renavam'] ?? '') ?>">
                    <label>Chassi</label>
                    <input type="text" name="veiculo_chassi" value="<?= e($op['veiculo_chassi'] ?? '') ?>">
                    <label>Valor da parcela</label>
                    <input type="text" name="valor_parcela" value="<?= e($op['valor_parcela'] !== null ? number_format((float)$op['valor_parcela'], 2, ',', '') : '') ?>" placeholder="850,00">
                    <label>Parcelas restantes</label>
                    <input type="text" name="parcelas_restantes" value="<?= e((string)($op['parcelas_restantes'] ?? '')) ?>">
                    <label>Nº do contrato de financiamento</label>
                    <input type="text" name="contrato_financiamento_numero" value="<?= e($op['contrato_financiamento_numero'] ?? '') ?>">
                <?php endif; ?>

                <button type="submit">Confirmar e avançar</button>
            </form>
        </div>

    <?php elseif ($fase === 'revisao_final'): ?>
        <p>Confira o resumo antes de enviar pro seu consultor:</p>
        <div class="card resumo">
            <dl>
                <dt>Nome</dt><dd><?= e($op['nome'] ?: '—') ?></dd>
                <dt>CPF</dt><dd><?= e($op['cpf'] ?: '—') ?></dd>
                <dt>RG</dt><dd><?= e($op['rg'] ?: '—') ?></dd>
                <dt>Endereço</dt><dd><?= e($op['endereco'] ?: '—') ?></dd>
                <dt>Banco/financeira</dt><dd><?= e($op['banco_financiamento'] ?: '—') ?></dd>
                <dt>Veículo</dt><dd><?= e(trim(($op['veiculo_marca'] ?? '') . ' ' . ($op['veiculo_modelo'] ?? '') . ' ' . ($op['veiculo_ano'] ?? '')) ?: '—') ?></dd>
            </dl>
            <p><small>Alguma coisa errada? Volte na
                <a href="?token=<?= e($token) ?>&revisar=cnh">CNH</a>,
                <a href="?token=<?= e($token) ?>&revisar=comprovante_endereco">comprovante de endereço</a> ou
                <a href="?token=<?= e($token) ?>&revisar=contrato_financiamento">contrato de financiamento</a>.</small></p>
            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="token" value="<?= e($token) ?>">
                <input type="hidden" name="acao" value="confirmar_final">
                <button type="submit">✅ Confirmar e enviar</button>
            </form>
        </div>

    <?php else: ?>
        <div class="alerta-sucesso">✅ Tudo certo! Recebemos seus documentos e dados — seu consultor vai analisar e entrar em contato.</div>
        <p><small>Precisa corrigir algo? Volte na
            <a href="?token=<?= e($token) ?>&revisar=cnh">CNH</a>,
            <a href="?token=<?= e($token) ?>&revisar=comprovante_endereco">comprovante de endereço</a> ou
            <a href="?token=<?= e($token) ?>&revisar=contrato_financiamento">contrato de financiamento</a>.</small></p>
    <?php endif; ?>

    <footer class="rodape-empresa">
        <strong>FASTCAR SOLUTIONS</strong> — CNPJ 66.934.500/0001-09<br>
        Av. Sagitário, 138 — Sala 1003, 10º andar, Torre City (Torre 2), Complexo Alpha Square Offices<br>
        Alphaville Conde II, Barueri/SP — CEP 06473-073
    </footer>
</div>
</body>
</html>
