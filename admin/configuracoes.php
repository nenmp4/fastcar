<?php
/**
 * Configurações de API — restrito ao super_admin (Jean). Guarda as
 * credenciais em `config` (chave/valor), mesmo lugar que
 * includes/whatsapp_config.php já lê (zapi_instance_id, zapi_token,
 * zapi_client_token) — pendência #2 do CLAUDE.md.
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../includes/marca.php';
requireSuperAdmin();

$campos = [
    'zapi_instance_id'  => 'ID da instância Z-API',
    'zapi_token'        => 'Token da instância Z-API',
    'zapi_client_token' => 'Client-Token (segurança da conta Z-API)',
];

// Instância DEDICADA de vendas (17/09/2026, pedido José/Jean: "vamos
// adcionar instancia só para vendas") — número/webhook PRÓPRIO, separado da
// instância principal acima (sempre a de compra). Convivem lado a lado.
$camposZapiVendas = [
    'zapi_instancia_vendas_id'           => 'ID da instância Z-API (vendas)',
    'zapi_instancia_vendas_token'        => 'Token da instância Z-API (vendas)',
    'zapi_instancia_vendas_client_token' => 'Client-Token (vendas)',
];

// Instância DEDICADA do financeiro (18/09/2026, pedido José/Jean: "vamos
// fazer gestão desses clientes que não paga fazer cobrança pelo sistema
// vai ser instancias só do finceir outro numero") — número/webhook
// PRÓPRIO, separado das instâncias de compra/vendas acima. Convivem lado
// a lado, mesmo padrão.
$camposZapiFinanceiro = [
    'zapi_instancia_financeiro_id'           => 'ID da instância Z-API (financeiro)',
    'zapi_instancia_financeiro_token'        => 'Token da instância Z-API (financeiro)',
    'zapi_instancia_financeiro_client_token' => 'Client-Token (financeiro)',
];

// Instância FALLBACK só de ENVIO (20/09/2026, "quero clocar instancia
// fallback" — depois do incidente de bloqueio da principal, 19-20/09/2026).
// Nunca recebe webhook, nunca é roteada — zapiEnviarTexto() tenta ela
// automaticamente quando a instância PRINCIPAL falha ao enviar, ver
// includes/whatsapp_config.php::zapiCredenciaisFallback().
$camposZapiFallback = [
    'zapi_fallback_instance_id'  => 'ID da instância Z-API (fallback)',
    'zapi_fallback_token'        => 'Token da instância Z-API (fallback)',
    'zapi_fallback_client_token' => 'Client-Token (fallback)',
];

$camposIA = [
    'gemini_api_key' => 'Chave da API Gemini (principal)',
    'openai_api_key' => 'Chave da API OpenAI (fallback — só usada se o Gemini falhar)',
];

$camposZapsign = [
    'zapsign_api_token' => 'Token da API ZapSign',
];

$camposFipe = [
    'placafipe_token' => 'Token da API PlacaFIPE (api.placafipe.com.br)',
];

// Consulta veicular ZapCar (22/09/2026, "vamos integrar essa api no
// sistema em oportunidade compras") — proprietário, restrições, gravame,
// leilão e débitos pela placa (ver includes/zapcar.php). Tipo de consulta
// é configurável (mesmo dia, "da para deixar uma chave escolher tipo de
// consulta api mais em configurações") — select abaixo populado ao vivo
// pelo catálogo (GET /v1/servicos), salvo em config.zapcar_servico_slug.
$camposZapcar = [
    'zapcar_api_key' => 'Chave da API ZapCar (Authorization: Bearer — zc_live_... produção, zc_test_... teste)',
];
$zapcarCatalogo = zapcarConfigured() ? zapcarServicos() : null;
$zapcarListaServicos = is_array($zapcarCatalogo) ? ($zapcarCatalogo['servicos'] ?? $zapcarCatalogo) : [];
if (!is_array($zapcarListaServicos)) {
    $zapcarListaServicos = [];
}

// Asaas (17/09/2026, pedido José/Jean: "vamos integrar api do assas pra
// puxar tudo de lá") — cobrança de cliente de venda parcelada (entrada +
// parcelas), já em uso de verdade lá; importa/sincroniza pro financeiro do
// Fastcar. asaas_ambiente decide sandbox x produção (includes/asaas.php::asaasBaseUrl()).
$camposAsaas = [
    'asaas_api_key'       => 'API Key do Asaas (Configurações → Integrações no painel Asaas)',
    'asaas_webhook_token' => 'Token do webhook (opcional — mesmo valor cadastrado no painel Asaas em Configurações → Webhooks, se você optar por autenticar)',
];

// 18/09/2026, "isso que puxamos do assas são receitas de parcela dos
// veiculos temos organizar" — categoria padrão aplicada automaticamente a
// toda cobrança NOVA importada do Asaas (fill-if-empty, nunca sobrescreve
// categoria já escolhida à mão — ver includes/asaas.php::asaasImportarCobrancas()).
$categoriasReceitaAsaas = getDB()->query(
    "SELECT id, nome, icone FROM fin_categorias WHERE tipo='receita' AND ativo=1 ORDER BY nome"
)->fetchAll(PDO::FETCH_ASSOC);

$camposEmail = [
    'email_from'      => 'E-mail remetente — precisa ser uma caixa real do Google Workspace (ex: contato@fastcar.solutions)',
    'email_from_nome' => 'Nome do remetente (ex: Fastcar)',
];

// Testemunhas do contrato de compra são sempre da própria Fastcar (pedido
// do José/Jean, 13/09/2026) — fixas aqui em vez de digitadas de novo em
// cada oportunidade (admin/oportunidade.php). includes/contratos.php lê
// direto daqui na hora de gerar o PDF.
$camposTestemunhas = [
    'testemunha1_nome' => 'Testemunha 1 — nome completo',
    'testemunha1_cpf'  => 'Testemunha 1 — CPF',
    'testemunha2_nome' => 'Testemunha 2 — nome completo',
    'testemunha2_cpf'  => 'Testemunha 2 — CPF',
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
        } elseif ($acao === 'salvar_zapi_vendas') {
            foreach (array_keys($camposZapiVendas) as $chave) {
                setConfig($chave, trim((string)($_POST[$chave] ?? '')));
            }
            $sucesso = 'Configurações da instância de vendas salvas.';
        } elseif ($acao === 'testar_zapi_vendas') {
            $telefoneTeste = (string)($_POST['telefone_teste_vendas'] ?? '');
            if (!$telefoneTeste) {
                $erro = 'Informe um telefone pra receber a mensagem de teste.';
            } else {
                $ok = zapiEnviarTexto($telefoneTeste, '✅ Teste de conexão Z-API (vendas) — Fastcar CRM.', zapiCredenciaisVendas());
                if ($ok) {
                    $sucesso = 'Mensagem de teste (vendas) enviada com sucesso.';
                } else {
                    $erro = 'Falha ao enviar — confira as credenciais da instância de vendas e se ela está conectada.';
                }
            }
        } elseif ($acao === 'salvar_zapi_financeiro') {
            foreach (array_keys($camposZapiFinanceiro) as $chave) {
                setConfig($chave, trim((string)($_POST[$chave] ?? '')));
            }
            $sucesso = 'Configurações da instância do financeiro salvas.';
        } elseif ($acao === 'testar_zapi_financeiro') {
            $telefoneTeste = (string)($_POST['telefone_teste_financeiro'] ?? '');
            if (!$telefoneTeste) {
                $erro = 'Informe um telefone pra receber a mensagem de teste.';
            } else {
                $ok = zapiEnviarTexto($telefoneTeste, '✅ Teste de conexão Z-API (financeiro) — Fastcar CRM.', zapiCredenciaisFinanceiro());
                if ($ok) {
                    $sucesso = 'Mensagem de teste (financeiro) enviada com sucesso.';
                } else {
                    $erro = 'Falha ao enviar — confira as credenciais da instância do financeiro e se ela está conectada.';
                }
            }
        } elseif ($acao === 'salvar_zapi_fallback') {
            foreach (array_keys($camposZapiFallback) as $chave) {
                setConfig($chave, trim((string)($_POST[$chave] ?? '')));
            }
            $sucesso = 'Configurações da instância fallback salvas.';
        } elseif ($acao === 'testar_zapi_fallback') {
            $telefoneTeste = (string)($_POST['telefone_teste_fallback'] ?? '');
            if (!$telefoneTeste) {
                $erro = 'Informe um telefone pra receber a mensagem de teste.';
            } else {
                $ok = zapiEnviarTexto($telefoneTeste, '✅ Teste de conexão Z-API (fallback) — Fastcar CRM.', zapiCredenciaisFallback());
                if ($ok) {
                    $sucesso = 'Mensagem de teste (fallback) enviada com sucesso.';
                } else {
                    $erro = 'Falha ao enviar — confira as credenciais da instância fallback e se ela está conectada.';
                }
            }
        } elseif ($acao === 'salvar_ia') {
            foreach (array_keys($camposIA) as $chave) {
                setConfig($chave, trim((string)($_POST[$chave] ?? '')));
            }
            setConfig('gemini_model', trim((string)($_POST['gemini_model'] ?? '')) ?: 'gemini-3.5-flash-lite');
            setConfig('openai_model', trim((string)($_POST['openai_model'] ?? '')) ?: 'gpt-4o-mini');
            $sucesso = 'Configurações de IA salvas.';
        } elseif ($acao === 'testar_ia') {
            $apiKey = getConfig('gemini_api_key') ?: '';
            if (!$apiKey) {
                $erro = 'Configure e salve a chave Gemini antes de testar.';
            } else {
                $resp = geminiCall('Responda só "ok" pra confirmar que a conexão está funcionando.', $apiKey, getConfig('gemini_model') ?: 'gemini-3.5-flash-lite', 20);
                if (is_array($resp)) {
                    $erro = 'Falha no teste: ' . ($resp['erro'] ?? 'erro desconhecido');
                } else {
                    $sucesso = 'Gemini respondeu: "' . $resp . '" — conexão funcionando.';
                }
            }
        } elseif ($acao === 'salvar_zapsign') {
            foreach (array_keys($camposZapsign) as $chave) {
                setConfig($chave, trim((string)($_POST[$chave] ?? '')));
            }
            $sucesso = 'Configurações da ZapSign salvas.';
        } elseif ($acao === 'salvar_fipe') {
            foreach (array_keys($camposFipe) as $chave) {
                setConfig($chave, trim((string)($_POST[$chave] ?? '')));
            }
            $sucesso = 'Configurações da FIPE salvas.';
        } elseif ($acao === 'testar_fipe') {
            // Diferente de testar_ia/testar_zapi, aqui pedimos uma placa de
            // teste em vez de testar sozinho — a PlacaFIPE cobra por
            // requisição (doc "Custos"), não faz sentido gastar cota numa
            // placa aleatória; o admin escolhe uma placa real (ex: a do
            // próprio carro) pra confirmar que token+conexão funcionam.
            $placaTeste = (string)($_POST['placa_teste'] ?? '');
            if (!placafipeToken()) {
                $erro = 'Configure e salve o token da PlacaFIPE antes de testar.';
            } elseif (!$placaTeste) {
                $erro = 'Informe uma placa real pra testar (o teste consome 1 requisição do seu plano).';
            } else {
                $resp = placafipeConsultarPorPlaca($placaTeste);
                if ($resp === null) {
                    $erro = 'Falha no teste: placa em formato inválido, ou a API não respondeu.';
                } elseif ((int)($resp['codigo'] ?? 0) !== 1) {
                    $erro = 'Falha no teste: ' . ($resp['msg'] ?? 'a PlacaFIPE recusou a consulta — confira o token.');
                } else {
                    $sucesso = 'PlacaFIPE respondeu: "' . ($resp['msg'] ?? 'ok') . '" — conexão funcionando.';
                }
            }
        } elseif ($acao === 'salvar_zapcar') {
            foreach (array_keys($camposZapcar) as $chave) {
                setConfig($chave, trim((string)($_POST[$chave] ?? '')));
            }
            // 22/09/2026, "da para deixar uma chave escolher tipo de
            // consulta api mais em configurações" — slug validado só no
            // formato (letras minúsculas/números/hífen), nunca contra o
            // catálogo travado em memória no momento do POST (pode ter
            // mudado desde o carregamento da tela) — um slug que não
            // existir de verdade só vai dar erro claro na hora de criar a
            // consulta, nunca trava o salvamento da config em si.
            $servicoPost = trim((string)($_POST['zapcar_servico_slug'] ?? ''));
            if ($servicoPost !== '' && preg_match('/^[a-z0-9-]+$/', $servicoPost)) {
                setConfig('zapcar_servico_slug', $servicoPost);
            }
            $sucesso = 'Configurações da ZapCar salvas.';
        } elseif ($acao === 'testar_zapcar') {
            // GET /v1/servicos e /v1/saldo são grátis (não cobram) — testa
            // a chave sem gastar saldo, diferente do teste da PlacaFIPE
            // (que exige placa real porque a busca em si é paga lá).
            if (!zapcarConfigured()) {
                $erro = 'Configure e salve a chave da ZapCar antes de testar.';
            } else {
                $catalogo = zapcarServicos();
                if ($catalogo === null) {
                    $erro = 'Falha no teste: a ZapCar não respondeu, ou a chave é inválida.';
                } else {
                    $saldo = zapcarSaldo();
                    $qtdServicos = count($catalogo['servicos'] ?? (is_array($catalogo) ? $catalogo : []));
                    $sucesso = "ZapCar respondeu: {$qtdServicos} serviço(s) no catálogo"
                        . ($saldo !== null ? ', saldo atual R$ ' . number_format($saldo, 2, ',', '.') : '')
                        . ' — conexão funcionando.';
                }
            }
        } elseif ($acao === 'salvar_asaas') {
            foreach (array_keys($camposAsaas) as $chave) {
                setConfig($chave, trim((string)($_POST[$chave] ?? '')));
            }
            $ambiente = ($_POST['asaas_ambiente'] ?? '') === 'producao' ? 'producao' : 'sandbox';
            setConfig('asaas_ambiente', $ambiente);
            setConfig('asaas_categoria_padrao_id', trim((string)($_POST['asaas_categoria_padrao_id'] ?? '')));
            $sucesso = 'Configurações do Asaas salvas.';
        } elseif ($acao === 'testar_asaas') {
            $r = asaasTestarConexao();
            if ($r['ok']) {
                $sucesso = $r['msg'];
            } else {
                $erro = 'Falha no teste: ' . $r['erro'];
            }
        } elseif ($acao === 'salvar_email') {
            foreach (array_keys($camposEmail) as $chave) {
                // brevo_api_key é opaco (só trim); os outros dois passam por clean()
                $valor = $chave === 'brevo_api_key' ? trim((string)($_POST[$chave] ?? '')) : clean((string)($_POST[$chave] ?? ''));
                setConfig($chave, $valor);
            }
            $sucesso = 'Configurações de e-mail salvas.';
        } elseif ($acao === 'testar_email') {
            $emailTeste = trim((string)($_POST['email_teste'] ?? ''));
            if (!$emailTeste) {
                $erro = 'Informe um e-mail pra receber o teste.';
            } else {
                $resp = enviarEmail($emailTeste, 'Teste de conexão — Fastcar CRM', '<p>✅ Se você recebeu isso, o envio de e-mail está funcionando.</p>');
                if ($resp === true) {
                    $sucesso = 'E-mail de teste enviado com sucesso.';
                } else {
                    $erro = 'Falha no teste: ' . ($resp['erro'] ?? 'erro desconhecido');
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
        } elseif ($acao === 'salvar_notificacao_leads') {
            $numerosDigitados = array_filter(array_map('trim', explode(',', (string)($_POST['notificacao_leads_whatsapp'] ?? ''))));
            setConfig('notificacao_leads_whatsapp', implode(',', $numerosDigitados));
            $sucesso = 'Números de notificação de lead novo salvos.';
        } elseif ($acao === 'definir_plantao') {
            definirPlantaoFimExpediente((int)($_POST['usuario_id'] ?? 0), !empty($_POST['ativo']));
            $sucesso = 'Plantão de fim de expediente atualizado.';
        } elseif ($acao === 'salvar_teto_fila') {
            $novoTeto = (int)($_POST['fila_leads_max_ativas'] ?? 0);
            if ($novoTeto < 1) {
                $erro = 'O teto precisa ser pelo menos 1.';
            } else {
                setConfig('fila_leads_max_ativas', (string)$novoTeto);
                $sucesso = "Teto de leads ativas por consultor atualizado pra {$novoTeto}.";
            }
        } elseif ($acao === 'redistribuir_fila') {
            $movidas = redistribuirFilaLeads((int)($_SESSION['admin_id'] ?? 0));
            if ($movidas) {
                $sucesso = count($movidas) . ' oportunidade(s) redistribuída(s): ';
                $partes = [];
                foreach ($movidas as $m) {
                    $partes[] = "#{$m['oportunidade_id']} ({$m['cliente_nome']}) de {$m['de']} para {$m['para']}";
                }
                $sucesso .= implode('; ', $partes) . '.';
            } else {
                $sucesso = 'Nada pra redistribuir — nenhum consultor está acima do teto de ' . filaLeadsMaxAtivas() . ' leads ativas, ou não há consultor disponível abaixo do teto pra receber.';
            }
        } elseif ($acao === 'equalizar_fila') {
            $movidas = equalizarFilaLeads((int)($_SESSION['admin_id'] ?? 0));
            if ($movidas) {
                $sucesso = count($movidas) . ' oportunidade(s) equalizada(s) entre os disponíveis: ';
                $partes = [];
                foreach ($movidas as $m) {
                    $partes[] = "#{$m['oportunidade_id']} ({$m['cliente_nome']}) de {$m['de']} para {$m['para']}";
                }
                $sucesso .= implode('; ', $partes) . '.';
            } else {
                $sucesso = 'Nada pra equalizar — a carga de leads ainda não tocadas já está parelha entre os consultores disponíveis (ou tem menos de 2 disponível agora).';
            }
        } elseif ($acao === 'salvar_horario_fila') {
            $abertura = trim((string)($_POST['fila_horario_abertura'] ?? ''));
            $fechamento = trim((string)($_POST['fila_horario_fechamento'] ?? ''));
            if (!preg_match('/^\d{2}:\d{2}$/', $abertura) || !preg_match('/^\d{2}:\d{2}$/', $fechamento)) {
                $erro = 'Horário inválido — use o formato HH:MM.';
            } else {
                setConfig('fila_horario_abertura', $abertura);
                setConfig('fila_horario_fechamento', $fechamento);
                $sucesso = "Horário da fila salvo: liga às {$abertura}, desliga às {$fechamento}, todo dia.";
            }
        } elseif ($acao === 'marcar_falta') {
            $resultado = marcarConsultorFaltou((int)($_POST['usuario_id'] ?? 0), (int)($_SESSION['admin_id'] ?? 0));
            if (!$resultado['ok']) {
                $erro = $resultado['motivo'];
            } elseif ($resultado['movidas']) {
                $sucesso = "{$resultado['ausente_nome']} marcado como ausente hoje. " . count($resultado['movidas']) . ' lead(s) redistribuída(s): ';
                $partes = [];
                foreach ($resultado['movidas'] as $m) {
                    $partes[] = "#{$m['oportunidade_id']} ({$m['cliente_nome']}) para {$m['para']}";
                }
                $sucesso .= implode('; ', $partes) . '.';
            } else {
                $sucesso = "{$resultado['ausente_nome']} marcado como ausente hoje. Sem leads não-tocadas pra redistribuir (ou nenhum outro consultor disponível agora).";
            }
        } elseif ($acao === 'desmarcar_falta') {
            desmarcarConsultorFaltou((int)($_POST['usuario_id'] ?? 0));
            $sucesso = 'Falta desmarcada — o consultor volta a ser candidato normal na fila.';
        } elseif ($acao === 'salvar_deploy') {
            $chaveWebhook = trim((string)($_POST['webhook_secret'] ?? ''));
            if ($chaveWebhook !== '') setConfig('webhook_secret', $chaveWebhook);
            $sucesso = 'Configurações de deploy salvas.';
        } elseif ($acao === 'salvar_testemunhas') {
            foreach (array_keys($camposTestemunhas) as $chave) {
                setConfig($chave, clean((string)($_POST[$chave] ?? '')));
            }
            $sucesso = 'Testemunhas do contrato salvas.';
        } elseif ($acao === 'salvar_logo') {
            $resultado = processarUploadLogo($_FILES['logo'] ?? []);
            if ($resultado['ok']) {
                $sucesso = 'Logo enviada! Favicon e ícones do PWA atualizados — pode levar alguns segundos pra aparecer se o navegador tinha em cache.';
            } else {
                $erro = $resultado['erro'];
            }
        } elseif ($acao === 'salvar_credencial_google') {
            $resultado = processarUploadCredencialGoogle($_FILES['credencial_google'] ?? []);
            if ($resultado['ok']) {
                $sucesso = 'Credencial do Google salva! Service account: ' . $resultado['email'];
            } else {
                $erro = $resultado['erro'];
            }
        } elseif ($acao === 'testar_drive') {
            $driveTeste = new GoogleDrive();
            if (!$driveTeste->hasCredentials()) {
                $erro = 'Nenhuma credencial encontrada — envie o JSON da service account antes de testar.';
            } elseif ($driveTeste->testarConexao()) {
                $sucesso = 'Conexão com o Google Drive funcionando! Credencial e API respondendo normalmente.';
            } else {
                $erro = 'Falha no teste: ' . ($driveTeste->lastError ?: 'a API do Drive não respondeu como esperado.');
            }
        } elseif ($acao === 'salvar_backup') {
            setConfig('backup_auto_ativo', isset($_POST['backup_auto_ativo']) ? '1' : '0');
            setConfig('drive_backup_ativo', isset($_POST['drive_backup_ativo']) ? '1' : '0');
            $chaveBackup = trim((string)($_POST['backup_cron_key'] ?? ''));
            if ($chaveBackup !== '') setConfig('backup_cron_key', $chaveBackup);
            $sucesso = 'Configurações de backup salvas.';
        }
    }
}

$valores = [];
foreach (array_keys($campos) as $chave) {
    $valores[$chave] = getConfig($chave) ?? '';
}
$configuradoZapi = $valores['zapi_instance_id'] && $valores['zapi_token'];
$valoresVendas = [];
foreach (array_keys($camposZapiVendas) as $chave) {
    $valoresVendas[$chave] = getConfig($chave) ?? '';
}
$configuradoZapiVendas = $valoresVendas['zapi_instancia_vendas_id'] && $valoresVendas['zapi_instancia_vendas_token'];
$valoresFinanceiro = [];
foreach (array_keys($camposZapiFinanceiro) as $chave) {
    $valoresFinanceiro[$chave] = getConfig($chave) ?? '';
}
$configuradoZapiFinanceiro = $valoresFinanceiro['zapi_instancia_financeiro_id'] && $valoresFinanceiro['zapi_instancia_financeiro_token'];
$valoresFallback = [];
foreach (array_keys($camposZapiFallback) as $chave) {
    $valoresFallback[$chave] = getConfig($chave) ?? '';
}
$configuradoZapiFallback = $valoresFallback['zapi_fallback_instance_id'] && $valoresFallback['zapi_fallback_token'];
$fila = listarFilaConsultores();
foreach ($fila as &$f) {
    $f['leads_ativas'] = contarOportunidadesAtivas((int)$f['id']);
}
unset($f);
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Configurações — Fastcar CRM</title>
<link rel="stylesheet" href="/admin/assets/style.css?v=<?= @filemtime(__DIR__ . '/assets/style.css') ?: 1 ?>">
<?php include __DIR__ . '/_pwa_head.php'; ?>
</head>
<body>
<header class="topbar">
    <a href="/admin/index.php" style="color:#fff">← Voltar</a>
    <strong><img class="topbar-logo" src="/admin/assets/img/icon-192.png" alt="Fastcar" onerror="this.style.display='none'"> Fast<b>Car</b></strong>
    <span>Olá, <?= e($_SESSION['admin_nome']) ?></span>
    <a href="/admin/logout.php">Sair</a>
</header>

<main>
<?php if ($erro): ?><div class="alerta-erro"><?= e($erro) ?></div><?php endif; ?>
<?php if ($sucesso): ?><div class="alerta-sucesso"><?= e($sucesso) ?></div><?php endif; ?>

<div class="card">
    <h2>🎨 Identidade visual</h2>
    <p><small>1 upload só atualiza a logo do wizard de documentos (cabeçalho de
       <code>public/documentos.php</code>), o favicon e os ícones do PWA (192px/512px) — nunca mais precisa mexer em
       arquivo direto no servidor pra trocar a marca. Aceita PNG, JPG ou WEBP; o sistema redimensiona sozinho pra cada
       uso.</small></p>
    <?php if (marcaLogoConfigurada()): ?>
        <p>
            <img src="/public/assets/logo.png?v=<?= (int)strtotime(getConfig('marca_logo_atualizada_em') ?: 'now') ?>"
                 alt="Logo atual" style="max-height:64px;background:#151722;padding:10px;border-radius:8px">
        </p>
        <p><small>Enviada em <?= e(getConfig('marca_logo_atualizada_em') ?: '—') ?>.</small></p>
    <?php else: ?>
        <p><span class="badge badge-atraso">⏳ nenhuma logo enviada ainda — usando placeholder "FC"</span></p>
    <?php endif; ?>
    <form method="post" enctype="multipart/form-data">
        <?= csrfField() ?>
        <input type="hidden" name="acao" value="salvar_logo">
        <label>Arquivo da logo (PNG, JPG ou WEBP)</label>
        <input type="file" name="logo" accept="image/png,image/jpeg,image/webp" required>
        <button type="submit">Enviar e gerar favicon/ícones</button>
    </form>
</div>

<div class="card">
    <h2>⚙️ Instância Z-API (única)</h2>
    <p><small>Restrito ao super_admin. Essas credenciais dão acesso à instância de WhatsApp da Fastcar — não compartilhe.
       Decisão de 15/09/2026 (José/Jean): 1 instância só pra tudo — entrada/IA/follow-up automático (blocos 2-4) E
       o atendimento humano a partir do bloco 5, que agora acontece pelo
       <a href="/admin/whatsapp_inbox.php">WhatsApp Box</a> dentro do CRM, nunca mais por instância própria de
       consultor.</small></p>

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
    <h2>🛒 Instância Z-API — Vendas</h2>
    <p><small>Instância DEDICADA do módulo de vendas (17/09/2026) — número/webhook PRÓPRIO, separado da instância
       principal acima (sempre a de compra): comprador entrando pelo WhatsApp cai direto na qualificação por IA de
       vendas (<a href="/admin/vendas_inbox.php">WhatsApp Vendas</a>), sem se misturar com o funil de compra. Mesmo
       webhook (<code>chatbot-whatsapp/webhook/whatsapp.php</code>) — o Z-API manda <code>instanceId</code> no
       payload, e o sistema descobre sozinho qual instância é qual.</small></p>

    <p>
        Status Z-API (vendas):
        <span class="badge <?= $configuradoZapiVendas ? 'badge-ok' : 'badge-atraso' ?>">
            <?= $configuradoZapiVendas ? '✅ credenciais preenchidas' : '⏳ ainda não configurado' ?>
        </span>
    </p>

    <form method="post" autocomplete="off">
        <?= csrfField() ?>
        <input type="hidden" name="acao" value="salvar_zapi_vendas">
        <?php foreach ($camposZapiVendas as $chave => $label): ?>
            <label for="<?= e($chave) ?>"><?= e($label) ?></label>
            <input type="password" id="<?= e($chave) ?>" name="<?= e($chave) ?>"
                   value="<?= e($valoresVendas[$chave]) ?>" autocomplete="off" placeholder="<?= $valoresVendas[$chave] ? '••••••••' : 'não configurado' ?>">
        <?php endforeach; ?>
        <button type="submit">Salvar configurações</button>
    </form>

    <hr>
    <p><small>Manda uma mensagem de teste pro número informado, usando as credenciais salvas acima.</small></p>
    <form method="post">
        <?= csrfField() ?>
        <input type="hidden" name="acao" value="testar_zapi_vendas">
        <label>Telefone (com DDD)</label>
        <input type="text" name="telefone_teste_vendas" placeholder="Ex: 31999998888">
        <button type="submit" <?= $configuradoZapiVendas ? '' : 'disabled' ?>>Enviar mensagem de teste</button>
        <?php if (!$configuradoZapiVendas): ?>
            <p><small>Preencha e salve o ID da instância e o token acima antes de testar.</small></p>
        <?php endif; ?>
    </form>
</div>

<div class="card">
    <h2>💳 Instância Z-API — Financeiro</h2>
    <p><small>Instância DEDICADA de gestão de cobrança (18/09/2026) — número/webhook PRÓPRIO, separado das instâncias
       de compra e vendas acima: conversa com cliente em atraso acontece pelo
       <a href="/admin/financeiro_inbox.php">WhatsApp Financeiro</a>, sem se misturar com os outros funis. Mesma
       instância nunca dispara mensagem sozinha — só quando alguém do financeiro digita e envia pela caixa. Mesmo
       webhook (<code>chatbot-whatsapp/webhook/whatsapp.php</code>) — o Z-API manda <code>instanceId</code> no
       payload, e o sistema descobre sozinho qual instância é qual.</small></p>

    <p>
        Status Z-API (financeiro):
        <span class="badge <?= $configuradoZapiFinanceiro ? 'badge-ok' : 'badge-atraso' ?>">
            <?= $configuradoZapiFinanceiro ? '✅ credenciais preenchidas' : '⏳ ainda não configurado' ?>
        </span>
    </p>

    <form method="post" autocomplete="off">
        <?= csrfField() ?>
        <input type="hidden" name="acao" value="salvar_zapi_financeiro">
        <?php foreach ($camposZapiFinanceiro as $chave => $label): ?>
            <label for="<?= e($chave) ?>"><?= e($label) ?></label>
            <input type="password" id="<?= e($chave) ?>" name="<?= e($chave) ?>"
                   value="<?= e($valoresFinanceiro[$chave]) ?>" autocomplete="off" placeholder="<?= $valoresFinanceiro[$chave] ? '••••••••' : 'não configurado' ?>">
        <?php endforeach; ?>
        <button type="submit">Salvar configurações</button>
    </form>

    <hr>
    <p><small>Manda uma mensagem de teste pro número informado, usando as credenciais salvas acima.</small></p>
    <form method="post">
        <?= csrfField() ?>
        <input type="hidden" name="acao" value="testar_zapi_financeiro">
        <label>Telefone (com DDD)</label>
        <input type="text" name="telefone_teste_financeiro" placeholder="Ex: 31999998888">
        <button type="submit" <?= $configuradoZapiFinanceiro ? '' : 'disabled' ?>>Enviar mensagem de teste</button>
        <?php if (!$configuradoZapiFinanceiro): ?>
            <p><small>Preencha e salve o ID da instância e o token acima antes de testar.</small></p>
        <?php endif; ?>
    </form>
</div>

<div class="card">
    <h2>🆘 Instância Z-API — Fallback (só envio)</h2>
    <p><small>20/09/2026, depois do incidente de bloqueio da instância principal (19-20/09/2026, "quero clocar
       instancia fallback") — número/instância separada, mas <strong>nunca recebe mensagem nem tem webhook próprio</strong>:
       serve só como reforço automático quando o envio pela instância principal falhar (erro/limite temporário) —
       <code>zapiEnviarTexto()</code> tenta essa instância sozinho antes de desistir, sem precisar de nenhuma ação
       manual. Cobre falha passageira de envio (resposta da IA, notificação, reengajamento).</small></p>
    <p><small>⚠️ <strong>Não substitui trocar de número</strong> se a instância principal for banida de verdade pelo
       WhatsApp — nesse caso a mensagem sai por um número diferente do que o cliente já conhece (o WhatsApp não
       permite "herdar" a conversa de um número banido de jeito nenhum), então é rede de segurança pra instabilidade
       passageira, não solução definitiva pra bloqueio permanente.</small></p>

    <p>
        Status Z-API (fallback):
        <span class="badge <?= $configuradoZapiFallback ? 'badge-ok' : 'badge-atraso' ?>">
            <?= $configuradoZapiFallback ? '✅ credenciais preenchidas' : '⏳ ainda não configurado' ?>
        </span>
    </p>

    <form method="post" autocomplete="off">
        <?= csrfField() ?>
        <input type="hidden" name="acao" value="salvar_zapi_fallback">
        <?php foreach ($camposZapiFallback as $chave => $label): ?>
            <label for="<?= e($chave) ?>"><?= e($label) ?></label>
            <input type="password" id="<?= e($chave) ?>" name="<?= e($chave) ?>"
                   value="<?= e($valoresFallback[$chave]) ?>" autocomplete="off" placeholder="<?= $valoresFallback[$chave] ? '••••••••' : 'não configurado' ?>">
        <?php endforeach; ?>
        <button type="submit">Salvar configurações</button>
    </form>

    <hr>
    <p><small>Manda uma mensagem de teste pro número informado, usando as credenciais salvas acima.</small></p>
    <form method="post">
        <?= csrfField() ?>
        <input type="hidden" name="acao" value="testar_zapi_fallback">
        <label>Telefone (com DDD)</label>
        <input type="text" name="telefone_teste_fallback" placeholder="Ex: 31999998888">
        <button type="submit" <?= $configuradoZapiFallback ? '' : 'disabled' ?>>Enviar mensagem de teste</button>
        <?php if (!$configuradoZapiFallback): ?>
            <p><small>Preencha e salve o ID da instância e o token acima antes de testar.</small></p>
        <?php endif; ?>
    </form>
</div>

<div class="card">
    <h3>🔔 Notificação de lead novo (WhatsApp)</h3>
    <p><small>Assim que um lead entra pelo WhatsApp (bloco 2), a instância principal manda um aviso automático
       pros números abaixo — além do sino sonoro que já aparece no admin pra quem estiver com a tela aberta
       (<code>admin/_notify.php</code>), isso alcança quem não está de olho no painel na hora.</small></p>
    <form method="post">
        <?= csrfField() ?>
        <input type="hidden" name="acao" value="salvar_notificacao_leads">
        <label>Números (com DDD, separados por vírgula)</label>
        <input type="text" name="notificacao_leads_whatsapp" value="<?= e(getConfig('notificacao_leads_whatsapp') ?: '') ?>" placeholder="Ex: 31999998888, 31988887777">
        <button type="submit">Salvar</button>
        <?php if (!getConfig('notificacao_leads_whatsapp')): ?>
            <p><small>Nenhum número configurado ainda — ninguém recebe aviso de lead novo por WhatsApp.</small></p>
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
        <label>Modelo Gemini</label>
        <?php // 16/09/2026, achado real vendo admin/saude.php mostrando um
        // modelo já aposentado ("gemini-2.5-flash-lite") mesmo as chamadas
        // reais usando o certo por baixo (geminiModeloValido() remapeia na
        // hora da chamada, mas nunca reescreve o valor salvo) — mostra aqui
        // o valor JÁ remapeado, pra não exibir um modelo que nem responde
        // mais; salvar sem mexer nesse campo já corrige o valor salvo. ?>
        <input type="text" name="gemini_model" value="<?= e(geminiModeloValido(getConfig('gemini_model') ?: '')) ?>">
        <small>Padrão é o mais barato da família (flash-lite) — volume de leads é baixo, não precisa de um modelo mais caro. Se um dia trocar, o gemini-3.6-flash (mais caro e mais capaz) entra como fallback automático se o configurado falhar.</small>
        <label>Modelo OpenAI (fallback)</label>
        <input type="text" name="openai_model" value="<?= e(getConfig('openai_model') ?: 'gpt-4o-mini') ?>">
        <small>Só entra em cena se o Gemini falhar ou não estiver configurado — gpt-4o-mini já é o nível mais barato da OpenAI pra esse uso.</small>
        <button type="submit">Salvar</button>
    </form>

    <form method="post" style="margin-top:12px">
        <?= csrfField() ?>
        <input type="hidden" name="acao" value="testar_ia">
        <button type="submit" <?= getConfig('gemini_api_key') ? '' : 'disabled' ?>>Testar conexão</button>
    </form>
</div>

<?php $drive = new GoogleDrive(); ?>
<div class="card">
    <h2>📁 Google Drive</h2>
    <p><small>Mesmo padrão do JurídicoSaaS — pasta "Fastcar" com uma subpasta por cliente, onde ficam os documentos
       enviados (CNH, comprovante de endereço, contrato de financiamento, contratos assinados). A credencial é um
       JSON de service account do Google Cloud, salva em <code>config/google_drive_credentials.json</code> (pasta
       protegida por .htaccess, `chmod 600`) — a MESMA credencial usada pro e-mail transacional
       (Configurações → E-mail). Upload direto por aqui desde 15/09/2026 (antes só dava pra dropar manualmente por
       FTP/SSH); continua restrito ao super_admin, como toda essa tela.</small></p>
    <p>
        Status:
        <span class="badge <?= $drive->hasCredentials() ? 'badge-ok' : 'badge-atraso' ?>">
            <?= $drive->hasCredentials() ? '✅ credencial encontrada' : '⏳ arquivo não encontrado' ?>
        </span>
    </p>
    <?php if ($drive->hasCredentials()): ?>
        <p><small>E-mail da service account: <code><?= e($drive->getCredentialEmail()) ?></code></small></p>
        <p><small>Pasta raiz no Drive: <code><?= e(getConfig('drive_folder_id') ?: '(criada automaticamente no 1º upload)') ?></code></small></p>
    <?php endif; ?>
    <form method="post" enctype="multipart/form-data" autocomplete="off">
        <?= csrfField() ?>
        <input type="hidden" name="acao" value="salvar_credencial_google">
        <label>Arquivo JSON da service account (<?= $drive->hasCredentials() ? 'substituir' : 'enviar' ?>)</label>
        <input type="file" name="credencial_google" accept="application/json,.json" required>
        <button type="submit">Enviar credencial</button>
    </form>
    <form method="post" style="margin-top:12px">
        <?= csrfField() ?>
        <input type="hidden" name="acao" value="testar_drive">
        <button type="submit" <?= $drive->hasCredentials() ? '' : 'disabled' ?>>Testar conexão</button>
    </form>
</div>

<div class="card">
    <h2>✍️ ZapSign (assinatura eletrônica)</h2>
    <p><small>Substituiu a Assinafy em 13/09/2026 — o contrato de compra gerado em cada oportunidade é enviado por
       aqui pra assinatura eletrônica do vendedor. Token fica em Configurações → Integrações → ZAPSIGN API dentro da
       própria conta ZapSign.</small></p>
    <p>
        Status:
        <span class="badge <?= getConfig('zapsign_api_token') ? 'badge-ok' : 'badge-atraso' ?>">
            <?= getConfig('zapsign_api_token') ? '✅ configurado' : '⏳ ainda não configurado' ?>
        </span>
    </p>
    <form method="post" autocomplete="off">
        <?= csrfField() ?>
        <input type="hidden" name="acao" value="salvar_zapsign">
        <?php foreach ($camposZapsign as $chave => $label): ?>
            <label for="<?= e($chave) ?>"><?= e($label) ?></label>
            <input type="password" id="<?= e($chave) ?>" name="<?= e($chave) ?>"
                   value="<?= e(getConfig($chave) ?? '') ?>" autocomplete="off"
                   placeholder="<?= getConfig($chave) ? '••••••••' : 'não configurado' ?>">
        <?php endforeach; ?>
        <button type="submit">Salvar</button>
    </form>
    <?php if (getConfig('zapsign_api_token')): ?>
    <p style="margin-top:1rem">
        <a href="/admin/zapsign_importar.php" class="btn" style="width:auto">📥 Importar contratos antigos da ZapSign</a>
        — puxa documentos já existentes na conta (ex: do CRM anterior) e ajuda a cadastrar como veículo da frota.
    </p>
    <?php endif; ?>
</div>

<div class="card">
    <h2>🚗 FIPE — busca por placa (PlacaFIPE)</h2>
    <p><small>Adicionado 15/09/2026 — busca de valor FIPE pela placa do veículo (api.placafipe.com.br), usada em
       <code>admin/oportunidade.php</code> pra preencher o valor FIPE de referência automaticamente. Separada da
       validação de marca "sozinha" (BrasilAPI, sem token) que já funciona mesmo sem nada configurado aqui. Token
       gerado em <code>placafipe.com.br</code>.</small></p>
    <p>
        Status:
        <span class="badge <?= getConfig('placafipe_token') ? 'badge-ok' : 'badge-atraso' ?>">
            <?= getConfig('placafipe_token') ? '✅ configurado' : '⏳ ainda não configurado' ?>
        </span>
    </p>
    <form method="post" autocomplete="off">
        <?= csrfField() ?>
        <input type="hidden" name="acao" value="salvar_fipe">
        <?php foreach ($camposFipe as $chave => $label): ?>
            <label for="<?= e($chave) ?>"><?= e($label) ?></label>
            <input type="password" id="<?= e($chave) ?>" name="<?= e($chave) ?>"
                   value="<?= e(getConfig($chave) ?? '') ?>" autocomplete="off"
                   placeholder="<?= getConfig($chave) ? '••••••••' : 'não configurado' ?>">
        <?php endforeach; ?>
        <button type="submit">Salvar</button>
    </form>
    <form method="post" style="margin-top:12px">
        <?= csrfField() ?>
        <input type="hidden" name="acao" value="testar_fipe">
        <label>Placa real pra testar (consome 1 requisição do plano)</label>
        <input type="text" name="placa_teste" placeholder="ABC1D23" style="text-transform:uppercase;max-width:180px">
        <button type="submit" <?= getConfig('placafipe_token') ? '' : 'disabled' ?>>Testar conexão</button>
    </form>
</div>

<div class="card">
    <h2>🚓 ZapCar — consulta veicular por placa</h2>
    <p><small>Adicionado 22/09/2026 — proprietário, restrições, gravame e leilão pela placa oficial
       (api.zapcarconsulta.com.br), usada no card "🔎 Consulta veicular (ZapCar)" em
       <code>admin/oportunidade.php</code>. Cada consulta é paga (conforme o tipo escolhido abaixo) e desconta
       do saldo da conta ZapCar. Chave gerada no Portal do Cliente ZapCar → API → Chaves.</small></p>
    <p>
        Status:
        <span class="badge <?= getConfig('zapcar_api_key') ? 'badge-ok' : 'badge-atraso' ?>">
            <?= getConfig('zapcar_api_key') ? '✅ configurado' : '⏳ ainda não configurado' ?>
        </span>
    </p>
    <form method="post" autocomplete="off">
        <?= csrfField() ?>
        <input type="hidden" name="acao" value="salvar_zapcar">
        <?php foreach ($camposZapcar as $chave => $label): ?>
            <label for="<?= e($chave) ?>"><?= e($label) ?></label>
            <input type="password" id="<?= e($chave) ?>" name="<?= e($chave) ?>"
                   value="<?= e(getConfig($chave) ?? '') ?>" autocomplete="off"
                   placeholder="<?= getConfig($chave) ? '••••••••' : 'não configurado' ?>">
        <?php endforeach; ?>
        <label for="zapcar_servico_slug">Tipo de consulta</label>
        <?php if ($zapcarListaServicos): ?>
            <select id="zapcar_servico_slug" name="zapcar_servico_slug">
                <?php foreach ($zapcarListaServicos as $s):
                    if (!is_array($s)) continue;
                    $slug = (string)($s['slug'] ?? $s['servico'] ?? '');
                    if ($slug === '') continue;
                    $nome = (string)($s['nome'] ?? $s['descricao'] ?? $slug);
                    $preco = isset($s['preco']) ? number_format((float)$s['preco'], 2, ',', '.') : null;
                ?>
                    <option value="<?= e($slug) ?>" <?= zapcarServicoAtivo() === $slug ? 'selected' : '' ?>>
                        <?= e($nome) ?><?= $preco !== null ? ' — R$ ' . e($preco) : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <small style="color:var(--texto-fraco)">Lido ao vivo do catálogo da ZapCar (GET /v1/servicos) — preço sempre o vigente na conta.</small>
        <?php else: ?>
            <input type="text" id="zapcar_servico_slug" name="zapcar_servico_slug"
                   value="<?= e(zapcarServicoAtivo()) ?>" placeholder="consulta-veicular">
            <small style="color:var(--texto-fraco)">Catálogo ainda não carregado (salve a chave acima e recarregue a página) — digite o slug do serviço manualmente por enquanto, ex: <code>consulta-veicular</code>.</small>
        <?php endif; ?>
        <button type="submit">Salvar</button>
    </form>
    <form method="post" style="margin-top:12px">
        <?= csrfField() ?>
        <input type="hidden" name="acao" value="testar_zapcar">
        <button type="submit" <?= getConfig('zapcar_api_key') ? '' : 'disabled' ?>>Testar conexão</button>
        <small style="color:var(--texto-fraco)">Só lê o catálogo e o saldo — não gasta nada.</small>
    </form>
</div>

<div class="card">
    <h2>🔄 Asaas</h2>
    <p><small>Adicionado 17/09/2026 — cobrança de cliente de venda parcelada (entrada + parcelas), já em uso de
       verdade no Asaas; importa/sincroniza clientes e cobranças pro financeiro do Fastcar
       (<code>admin/financeiro-asaas.php</code>). Nunca confirmado ainda contra uma conta/credencial real — ver
       CLAUDE.md.</small></p>
    <p>
        Status:
        <span class="badge <?= getConfig('asaas_api_key') ? 'badge-ok' : 'badge-atraso' ?>">
            <?= getConfig('asaas_api_key') ? '✅ configurado' : '⏳ ainda não configurado' ?>
        </span>
    </p>
    <form method="post" autocomplete="off">
        <?= csrfField() ?>
        <input type="hidden" name="acao" value="salvar_asaas">
        <label for="asaas_ambiente">Ambiente</label>
        <select id="asaas_ambiente" name="asaas_ambiente">
            <option value="sandbox" <?= getConfig('asaas_ambiente') !== 'producao' ? 'selected' : '' ?>>Sandbox (teste)</option>
            <option value="producao" <?= getConfig('asaas_ambiente') === 'producao' ? 'selected' : '' ?>>Produção</option>
        </select>
        <?php foreach ($camposAsaas as $chave => $label): ?>
            <label for="<?= e($chave) ?>"><?= e($label) ?></label>
            <input type="password" id="<?= e($chave) ?>" name="<?= e($chave) ?>"
                   value="<?= e(getConfig($chave) ?? '') ?>" autocomplete="off"
                   placeholder="<?= getConfig($chave) ? '••••••••' : 'não configurado' ?>">
        <?php endforeach; ?>
        <label for="asaas_categoria_padrao_id">Categoria padrão pra cobrança importada</label>
        <select id="asaas_categoria_padrao_id" name="asaas_categoria_padrao_id">
            <option value="">— nenhuma (fica "—" na tela, como hoje) —</option>
            <?php foreach ($categoriasReceitaAsaas as $cat): ?>
                <option value="<?= (int)$cat['id'] ?>" <?= (string)getConfig('asaas_categoria_padrao_id') === (string)$cat['id'] ? 'selected' : '' ?>>
                    <?= e($cat['icone'] . ' ' . $cat['nome']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <p><small>Aplicada só em cobrança NOVA a partir de agora (fill-if-empty — nunca sobrescreve categoria já
           escolhida à mão). Pra categorizar retroativamente as cobranças já importadas antes disso existir, rodar
           <code>php install/asaas_categorizar_importados.php --confirmar</code> na VPS.</small></p>
        <label>URL do webhook (cadastre no painel Asaas → Integrações → Webhooks)</label>
        <input type="text" value="https://fastcar.solutions/api/asaas_webhook.php" readonly onclick="this.select()">
        <button type="submit">Salvar</button>
    </form>
    <form method="post" style="margin-top:12px">
        <?= csrfField() ?>
        <input type="hidden" name="acao" value="testar_asaas">
        <button type="submit" <?= getConfig('asaas_api_key') ? '' : 'disabled' ?>>Testar conexão</button>
    </form>
</div>

<div class="card">
    <h3>✍️ Testemunhas do contrato de compra</h3>
    <p><small>Sempre as mesmas 2 pessoas, do lado da Fastcar — não muda por oportunidade. Se ficar em branco, o PDF
       sai com a linha vazia pra preencher à mão no presencial, igual antes.</small></p>
    <form method="post">
        <?= csrfField() ?>
        <input type="hidden" name="acao" value="salvar_testemunhas">
        <div class="grid-2">
            <div>
                <label>Testemunha 1 — nome completo</label>
                <input type="text" name="testemunha1_nome" value="<?= e(getConfig('testemunha1_nome') ?? '') ?>">
                <label>Testemunha 1 — CPF</label>
                <input type="text" name="testemunha1_cpf" value="<?= e(getConfig('testemunha1_cpf') ?? '') ?>" placeholder="000.000.000-00">
            </div>
            <div>
                <label>Testemunha 2 — nome completo</label>
                <input type="text" name="testemunha2_nome" value="<?= e(getConfig('testemunha2_nome') ?? '') ?>">
                <label>Testemunha 2 — CPF</label>
                <input type="text" name="testemunha2_cpf" value="<?= e(getConfig('testemunha2_cpf') ?? '') ?>" placeholder="000.000.000-00">
            </div>
        </div>
        <button type="submit">Salvar testemunhas</button>
    </form>
</div>

<div class="card">
    <h2>✉️ E-mail (Gmail API — Google Workspace)</h2>
    <p><small>Trocado da Brevo em 15/09/2026 (pedido do José/Jean) — reaproveita a MESMA credencial de service account
       do Google Drive (<code>config/google_drive_credentials.json</code>, ver card acima pra enviar/trocar), só muda
       o escopo (<code>gmail.send</code>) e a service account passa a "impersonar" a caixa configurada
       abaixo via delegação em todo o domínio (autorizada no Workspace Admin — não dá pra configurar por aqui, é um
       passo manual no painel admin.google.com).</small></p>
    <p>
        Status da credencial:
        <span class="badge <?= $drive->hasCredentials() ? 'badge-ok' : 'badge-atraso' ?>">
            <?= $drive->hasCredentials() ? '✅ credencial encontrada (mesma do Drive)' : '⏳ arquivo não encontrado' ?>
        </span>
    </p>
    <form method="post" autocomplete="off">
        <?= csrfField() ?>
        <input type="hidden" name="acao" value="salvar_email">
        <?php foreach ($camposEmail as $chave => $label): ?>
            <label for="<?= e($chave) ?>"><?= e($label) ?></label>
            <input type="text" id="<?= e($chave) ?>" name="<?= e($chave) ?>" value="<?= e(getConfig($chave) ?? '') ?>">
        <?php endforeach; ?>
        <button type="submit">Salvar</button>
    </form>
    <form method="post" style="margin-top:1rem">
        <?= csrfField() ?>
        <input type="hidden" name="acao" value="testar_email">
        <label>Enviar teste pra</label>
        <input type="email" name="email_teste" placeholder="seu@email.com">
        <button type="submit" <?= $drive->hasCredentials() ? '' : 'disabled' ?>>Enviar e-mail de teste</button>
    </form>
</div>

<div class="card">
    <h3>📥 Fila de distribuição automática de leads</h3>
    <p><small>Lead novo (bloco 2, na entrada) vai automaticamente pra quem estiver com "Disponível" ligado, em rodízio,
       respeitando o teto de <?= filaLeadsMaxAtivas() ?> leads ativas por consultor (quem já está no teto é pulado no
       rodízio). Se ninguém estiver disponível, cai em quem estiver marcado como plantão de fim de expediente abaixo —
       vira responsável da oportunidade normalmente, nenhum lead fica sem dono fora do horário (plantão não respeita o
       teto — nunca fica sem responsável fora do horário).</small></p>

    <form method="post" class="inline" style="margin-bottom:12px">
        <?= csrfField() ?>
        <input type="hidden" name="acao" value="salvar_teto_fila">
        <label>Teto de leads ativas por consultor</label>
        <input type="number" name="fila_leads_max_ativas" value="<?= filaLeadsMaxAtivas() ?>" min="1" style="width:80px;display:inline-block">
        <button type="submit" style="margin-top:0">Salvar teto</button>
        <small style="display:block;color:#666">Ajuste temporário pra dar conta de volume acumulado (ex: mais leads ativos
           que "consultores × teto" atual) é normal — não precisa de deploy, só salvar aqui.</small>
    </form>

    <form method="post" class="inline" style="margin-bottom:12px">
        <?= csrfField() ?>
        <input type="hidden" name="acao" value="salvar_horario_fila">
        <label>Liga às</label>
        <input type="time" name="fila_horario_abertura" value="<?= e(filaHorarioAbertura()) ?>" style="width:110px;display:inline-block">
        <label>Desliga às</label>
        <input type="time" name="fila_horario_fechamento" value="<?= e(filaHorarioFechamento()) ?>" style="width:110px;display:inline-block">
        <button type="submit" style="margin-top:0">Salvar horário</button>
        <small style="display:block;color:#666">Todo dia, automático: liga "Disponível" de todo consultor na abertura
           (exceto quem foi marcado ausente hoje) e desliga todo mundo no fechamento. Roda via cron a cada poucos
           minutos (<code>cron/fila_horario_expediente.php</code>) — não precisa de ninguém clicando nada.</small>
    </form>

    <?php if (!$fila): ?>
        <p><small>Nenhum consultor cadastrado ainda.</small></p>
    <?php endif; ?>

    <table class="tabela-oportunidades">
        <thead>
            <tr><th>Nome</th><th>Status</th><th>Leads ativas</th><th>Último lead recebido</th><th>Plantão fim de expediente</th><th>Falta hoje</th></tr>
        </thead>
        <tbody>
        <?php foreach ($fila as $f): ?>
            <?php $faltouHoje = $f['faltou_em'] === date('Y-m-d'); ?>
            <tr>
                <td><?= e($f['nome']) ?> <span class="badge"><?= e($f['perfil']) ?></span></td>
                <td>
                    <?php if ($faltouHoje): ?>
                        <span class="badge badge-atraso">🤒 faltou hoje</span>
                    <?php elseif ($f['plantao_fim_expediente']): ?>
                        <span class="badge">🌙 só plantão</span>
                    <?php elseif ($f['disponivel']): ?>
                        <span class="badge badge-ok">🟢 disponível</span>
                    <?php else: ?>
                        <span class="badge badge-atraso">⚪ offline</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($f['leads_ativas'] > filaLeadsMaxAtivas()): ?>
                        <span class="badge badge-atraso"><?= (int)$f['leads_ativas'] ?> ⚠️ acima do teto</span>
                    <?php elseif ($f['leads_ativas'] >= filaLeadsMaxAtivas()): ?>
                        <span class="badge"><?= (int)$f['leads_ativas'] ?> (no teto)</span>
                    <?php else: ?>
                        <?= (int)$f['leads_ativas'] ?>
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
                <td>
                    <form method="post" class="inline" onsubmit="<?= $faltouHoje ? '' : "return confirm('Marcar {$f['nome']} como ausente hoje? As leads dele(a) ainda não tocadas vão ser redistribuídas pros consultores disponíveis agora mesmo.')" ?>">
                        <?= csrfField() ?>
                        <input type="hidden" name="acao" value="<?= $faltouHoje ? 'desmarcar_falta' : 'marcar_falta' ?>">
                        <input type="hidden" name="usuario_id" value="<?= (int)$f['id'] ?>">
                        <button type="submit" style="margin-top:0;padding:4px 10px;font-size:12px">
                            <?= $faltouHoje ? '↩️ Desfazer falta' : '❌ Marcar falta' ?>
                        </button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <?php if ($fila): ?>
    <form method="post" class="inline" style="margin-top:12px">
        <?= csrfField() ?>
        <input type="hidden" name="acao" value="redistribuir_fila">
        <button type="submit">🔄 Redistribuir fila agora</button>
    </form>
    <p><small>Move oportunidades ainda não tocadas pelo consultor (WhatsApp, Qualificação IA ou CRM preenchido —
       blocos 2 a 4, antes do consultor começar a atender de verdade) de quem está acima do teto pra quem está
       disponível e abaixo do teto, e também atribui as que estão sem responsável nenhum (ninguém estava disponível
       quando entraram). Nunca mexe em oportunidade que já está em Atendimento ou depois.</small></p>

    <form method="post" class="inline" style="margin-top:8px">
        <?= csrfField() ?>
        <input type="hidden" name="acao" value="equalizar_fila">
        <button type="submit">⚖️ Equalizar entre disponíveis</button>
    </form>
    <p><small>Diferente do botão acima (que só mexe em quem está acima do teto): calcula a média real de leads
       ainda não tocadas entre os consultores <strong>disponíveis agora</strong> e puxa de quem tem mais pra quem
       tem menos até ficar parelho — útil quando entra gente nova no time e ninguém está tecnicamente acima do
       teto pra disparar a redistribuição normal. Só mexe em quem está 🟢 disponível (nunca tira fila de quem
       está offline, nunca dá lead novo pra quem não está disponível), e nunca em oportunidade já em Atendimento
       ou depois.</small></p>
    <?php endif; ?>
</div>

<div class="card">
    <h3>🚀 Deploy automático (git push → servidor atualiza sozinho)</h3>
    <p><small>Configura o webhook do GitHub apontando pra
       <code>https://SEU-DOMINIO/api/webhook_deploy.php</code> (evento "push", só a
       branch <code>main</code>) usando esta mesma chave como secret. Ver
       <code>install/SETUP_VPS.md</code> pra configurar o cron que aplica de verdade.</small></p>
    <p>
        Status:
        <span class="badge <?= getConfig('webhook_secret') ? 'badge-ok' : 'badge-atraso' ?>">
            <?= getConfig('webhook_secret') ? '✅ configurado' : '⏳ ainda não configurado' ?>
        </span>
    </p>
    <form method="post" autocomplete="off">
        <?= csrfField() ?>
        <input type="hidden" name="acao" value="salvar_deploy">
        <label>Secret do webhook</label>
        <input type="password" name="webhook_secret" value="<?= e(getConfig('webhook_secret') ?? '') ?>" autocomplete="off"
               placeholder="<?= getConfig('webhook_secret') ? '••••••••' : 'não configurado' ?>">
        <button type="submit">Salvar</button>
    </form>
</div>

<div class="card">
    <h3>💾 Backup automático</h3>
    <p><small>Liga/desliga o que roda pelo cron (ver <a href="/admin/backup.php">tela de Backup</a> pra disparo
       manual e listagem). Desativado aqui não apaga backup que já existe, só para de gerar novo.</small></p>
    <form method="post">
        <?= csrfField() ?>
        <input type="hidden" name="acao" value="salvar_backup">
        <label><input type="checkbox" name="backup_auto_ativo" <?= getConfig('backup_auto_ativo') !== '0' ? 'checked' : '' ?> style="width:auto;display:inline-block"> Backup completo automático (1x/dia)</label>
        <label><input type="checkbox" name="drive_backup_ativo" <?= getConfig('drive_backup_ativo') !== '0' ? 'checked' : '' ?> style="width:auto;display:inline-block"> Enviar backup pro Google Drive automaticamente</label>
        <label>Chave pra disparar backup via URL (opcional — só necessário se o cron não puder chamar via CLI)</label>
        <input type="password" name="backup_cron_key" value="<?= e(getConfig('backup_cron_key') ?? '') ?>" autocomplete="off"
               placeholder="<?= getConfig('backup_cron_key') ? '••••••••' : 'não configurado' ?>">
        <button type="submit">Salvar</button>
    </form>
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
<?php include __DIR__ . '/_pwa_register.php'; ?>
<?php include __DIR__ . '/_notify.php'; ?>
</body>
</html>
