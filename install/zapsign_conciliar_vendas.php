<?php
/**
 * Concilia contratos de VENDA (revenda) que já existem na conta ZapSign
 * mas nunca passaram por este sistema — 24/09/2026, pedido direto:
 * "mais facil agente rodar script puxando pelo zapsign puxa placa vicula
 * o client puxa os dados do cliente contrato fa[z] viculo depois agente
 * faz o financeiro". Mesma ideia de `admin/zapsign_importar.php`, mas
 * em LOTE via linha de comando em vez de clicar documento por documento —
 * a diferença real que este script adiciona é a leitura automática da
 * PLACA no PDF do contrato (via IA, `zapsignExtrairVeiculoContrato()`)
 * pra já sugerir qual veículo da frota é o certo, em vez do admin ter que
 * abrir o PDF e procurar na lista manualmente.
 *
 * Escopo: só documentos "sem match" (telefone do signatário NÃO bate com
 * nenhum `clientes` já cadastrado) — mesmo critério de
 * `admin/zapsign_importar.php`. Documento cujo telefone bate com cliente
 * existente fica de fora (mesmo motivo de lá: um cliente pode ter mais de
 * 1 veículo, regra #1, não dá pra saber qual sem revisão humana) — segue
 * aparecendo só na tela pra resolver manualmente lá.
 *
 * Placa é a única coisa que decide o vínculo — nunca nome/telefone do
 * comprador (esses só preenchem o CADASTRO da venda depois de já saber
 * qual veículo é). Só considera candidata segura quando a placa lida bate
 * com EXATAMENTE 1 veículo disponível na frota
 * (`zapsignEncontrarVeiculoPorPlaca()`) — sem placa legível, placa que não
 * bate com nada no estoque, ou (não deveria acontecer, mas defensivo) mais
 * de 1 candidato, fica listado à parte, nunca decidido sozinho (regra #3).
 * Veículo com MAIS de 1 contrato de venda na ZapSign (ex: devolução +
 * revenda) importa só o de assinatura MAIS RECENTE automaticamente — os
 * mais antigos pro mesmo veículo ficam de fora, listados à parte pra
 * revisão manual (pode ser rascunho/duplicata, ou venda anterior genuína
 * que merece registro próprio — decisão sempre humana).
 *
 * Reaproveita `zapsignImportarContratoVendaComoNegociacaoManual()`
 * (`includes/zapsign_importar.php`, já existia, estendida nesta mesma
 * mudança) — mesma disciplina de sempre (nunca chama `mudarEtapaVenda()`,
 * pra nunca gerar lançamento financeiro FALSO com data de hoje pra uma
 * venda histórica). Ela já faz sozinha as 2 outras pontas pedidas junto
 * ("tem preencher ficha completa do cliente salvar contrato no drive"):
 * (1) salva o PDF assinado no MESMO destino Drive/local já usado pro resto
 * dos documentos do cliente original (`salvarArquivoGeradoComoDocumento()`,
 * nenhuma mudança nela); (2) grava a ficha completa do comprador
 * (CPF/RG/endereço/e-mail/nacionalidade/estado civil/profissão) lida do
 * corpo do contrato via `zapsignExtrairVeiculoContrato()` — CPF prioriza o
 * metadado de assinatura da própria ZapSign (mais confiável, confirmado
 * pelo signatário na hora de assinar) quando disponível, só cai pro lido
 * no texto se a ZapSign não trouxer nenhum.
 *
 * "depois agente faz o financeiro" — depois de importar aqui, roda
 * `install/gerar_lancamentos_vendas_retroativos.php` (já existe) pra
 * gerar a receita/comissão dessas vendas recém-importadas, usando a data
 * REAL de cada uma — é um passo SEPARADO de propósito, nunca disparado
 * automaticamente por este script (mesma razão de sempre: nunca misturar
 * "importar o negócio" com "decidir o financeiro dele" na mesma operação
 * sem revisão no meio).
 *
 * Uso:
 *   php install/zapsign_conciliar_vendas.php              — só lista (dry-run)
 *   php install/zapsign_conciliar_vendas.php --confirmar  — aplica de verdade
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Este script só roda via linha de comando (CLI).\n");
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/zapsign_importar.php';

$confirmar = in_array('--confirmar', $argv, true);
$db = getDB();

// Sem isso, PHP-CLI bufferiza a saída e o terminal fica em silêncio total
// até o script inteiro terminar — pra uma conta com muitos contratos
// (cada um custa 1 download de PDF + 1 chamada de IA, minutos ao todo),
// parecia travado sem nenhum sinal de vida. Progresso ao vivo abaixo.
if (function_exists('ob_implicit_flush')) ob_implicit_flush(true);
@ob_end_flush();

if (!getConfig('zapsign_api_token')) {
    die("❌ Token da API ZapSign não configurado (Configurações → ZapSign) — nada a fazer.\n");
}
if (!getConfig('gemini_api_key')) {
    echo "⚠️  Sem chave Gemini configurada — a leitura automática de placa no PDF não vai funcionar,\n";
    echo "    todo documento vai cair em \"placa não identificada\". Configure em Configurações → IA.\n\n";
}

echo "🔎 Listando documentos na ZapSign...\n";
$resLista = zapsignListarTodosDocumentos();
$documentos = $resLista['itens'];
if (!$resLista['ok']) {
    echo "⚠️  Falha listando documentos da ZapSign: {$resLista['erro']}";
    echo $documentos ? " (mostrando os " . count($documentos) . " já obtidos antes da falha)\n" : "\n";
}
if (!$documentos) {
    echo "✅ Nenhum documento na conta ZapSign — nada a fazer.\n";
    exit(0);
}
echo count($documentos) . " documento(s) encontrado(s) — processando um por um (baixa PDF + lê com IA, pode demorar alguns segundos por documento):\n\n";

$candidatas = [];
$semPlaca = [];
$comCliente = 0;
$jaImportados = 0;

$totalDocs = count($documentos);
$i = 0;
$errosLeitura = 0;
foreach ($documentos as $doc) {
    $i++;
    $nomeDoc = (string)($doc['name'] ?? '(sem nome)');
    // Mesmo raciocínio do loop de --confirmar abaixo: 1 exceção não tratada
    // (ex: "database is locked" numa contenção real de escrita concorrente
    // no banco de produção) nunca pode derrubar o script inteiro no meio da
    // LEITURA — perderia a visibilidade de TODOS os documentos seguintes,
    // não só do que falhou. Idempotente: rodar de novo resolve sozinho.
    try {
        $token = (string)($doc['token'] ?? '');
        if ($token === '') continue;

        $existe = $db->prepare('SELECT id FROM contratos WHERE zapsign_doc_token = ?');
        $existe->execute([$token]);
        if ($existe->fetchColumn()) {
            $jaImportados++;
            echo "  [{$i}/{$totalDocs}] \"{$nomeDoc}\" — já importado, pulando\n";
            continue;
        }

        $sig = zapsignExtrairSignatario($doc);
        $telNorm = $sig['telefone'] ? normalizarTelefone($sig['telefone']) : '';
        if ($telNorm) {
            $stmtCli = $db->prepare('SELECT 1 FROM clientes WHERE telefone = ?');
            $stmtCli->execute([$telNorm]);
            if ($stmtCli->fetchColumn()) {
                // Telefone bate com cliente já cadastrado — mesmo critério de
                // admin/zapsign_importar.php, fica de fora daqui de propósito
                // (pode ter mais de 1 veículo, precisa revisão humana lá).
                $comCliente++;
                echo "  [{$i}/{$totalDocs}] \"{$nomeDoc}\" — telefone já é cliente cadastrado, fora do escopo\n";
                continue;
            }
        }

        echo "  [{$i}/{$totalDocs}] \"{$nomeDoc}\" — baixando PDF e lendo com IA...";
        $veiculo = zapsignExtrairVeiculoContrato($token);
        $oportunidadeId = $veiculo['placa'] ? zapsignEncontrarVeiculoPorPlaca($veiculo['placa']) : null;
        echo $oportunidadeId ? " placa {$veiculo['placa']} encontrada\n" : " sem placa identificada no estoque\n";

        if (!$oportunidadeId) {
            $semPlaca[] = ['doc' => $doc, 'sig' => $sig, 'veiculo' => $veiculo];
            continue;
        }

        // Data REAL da assinatura — nunca "hoje" (pedido explícito: "salvar
        // sempre na data do contrato real não como data de hoje"). Prioriza o
        // que a ZapSign marcar como data de assinatura do signatário (mais
        // preciso — nunca confirmado contra a API real, ver "A validar" no
        // CLAUDE.md), cai pra `created_at`/`last_update_at` do documento em
        // si (a data em que o CONTRATO existiu na ZapSign, quase sempre bem
        // próxima da assinatura de verdade num fluxo de "gera e já assina").
        // Sem NENHUMA data confiável, nunca chuta "hoje" sozinho — fica de
        // fora, pro admin resolver manual em admin/zapsign_importar.php
        // (regra #3: sem dado confiável, nunca inventa).
        $dataAssinatura = (string)(
            $doc['signers'][0]['signed_at'] ?? $doc['created_at'] ?? $doc['last_update_at'] ?? ''
        );
        if ($dataAssinatura === '') {
            $semPlaca[] = ['doc' => $doc, 'sig' => $sig, 'veiculo' => $veiculo, 'motivo' => 'sem_data'];
            continue;
        }

        $stmtOp = $db->prepare("SELECT veiculo_marca, veiculo_modelo, veiculo_placa FROM oportunidades WHERE id = ?");
        $stmtOp->execute([$oportunidadeId]);
        $op = $stmtOp->fetch(PDO::FETCH_ASSOC);

        $candidatas[] = [
            'doc' => $doc, 'sig' => $sig, 'veiculo' => $veiculo, 'data_assinatura' => $dataAssinatura,
            'oportunidade_id' => $oportunidadeId, 'oportunidade' => $op,
        ];
    } catch (Throwable $e) {
        $errosLeitura++;
        echo "  [{$i}/{$totalDocs}] \"{$nomeDoc}\" — ⚠️  erro inesperado, pulando: {$e->getMessage()}\n";
    }
}

// Um mesmo veículo pode ter MAIS de 1 contrato de venda na ZapSign (ex:
// devolução + revenda pra outro comprador — ver bullet "Devolução de
// veículo já vendido" no CLAUDE.md) — 24/09/2026, pergunta direta do
// usuário: "se veiculo tiver mais 2 contratos?" → "buscar sempre mais
// atual". A ordem que a ZapSign devolve os documentos não é garantida
// ser cronológica, então NUNCA importa só o primeiro que aparecer na
// lista — agrupa por veículo e mantém só o de `data_assinatura` mais
// recente como candidata automática; o(s) outro(s) (contrato mais antigo
// pra esse mesmo veículo) fica de fora do automático, listado à parte —
// pode ser rascunho/duplicata (só esse mais recente importa) ou pode ser
// uma venda anterior genuína (devolução+revenda) que merece registro
// próprio, mas isso é decisão humana, o script nunca assume sozinho
// (regra #3) — resolve manual em admin/zapsign_importar.php se for o
// caso de registrar as duas.
$porVeiculo = [];
foreach ($candidatas as $idx => $c) $porVeiculo[$c['oportunidade_id']][] = $idx;

$superados = [];
foreach ($porVeiculo as $idxs) {
    if (count($idxs) < 2) continue;
    // strtotime() em vez de comparar string cru — o formato de signed_at/
    // created_at nunca foi confirmado contra a API real, pode variar
    // (com ou sem hora, fuso etc); strtotime() lida com isso, comparação
    // de string cru não seria confiável nesse cenário.
    usort($idxs, fn($a, $b) => strtotime($candidatas[$b]['data_assinatura']) <=> strtotime($candidatas[$a]['data_assinatura']));
    foreach (array_slice($idxs, 1) as $idxAntigo) {
        $superados[] = $candidatas[$idxAntigo] + ['mais_recente' => $candidatas[$idxs[0]]];
    }
}
if ($superados) {
    $tokensSuperados = array_map(fn($s) => $s['doc']['token'], $superados);
    $candidatas = array_values(array_filter(
        $candidatas, fn($c) => !in_array($c['doc']['token'], $tokensSuperados, true)
    ));
}

echo "Total na ZapSign: " . count($documentos) . " | já importados: {$jaImportados} | telefone bate com cliente (fora do escopo, resolve em admin/zapsign_importar.php): {$comCliente}"
    . ($errosLeitura ? " | erro inesperado (nunca chegou a ser lido, rode de novo): {$errosLeitura}" : '') . "\n\n";

if ($superados) {
    echo "🔁 " . count($superados) . " documento(s) superado(s) por um contrato MAIS RECENTE pro mesmo veículo — nunca importado automaticamente, nunca decide sozinho se é duplicata ou devolução+revenda genuína:\n\n";
    foreach ($superados as $s) {
        printf(
            "  \"%s\" (assinado %s) — veículo #%d, placa %s → existe contrato mais recente: \"%s\" (assinado %s)\n",
            $s['doc']['name'] ?? '(sem nome)', substr($s['data_assinatura'], 0, 10),
            $s['oportunidade_id'], $s['oportunidade']['veiculo_placa'] ?? '—',
            $s['mais_recente']['doc']['name'] ?? '(sem nome)', substr($s['mais_recente']['data_assinatura'], 0, 10)
        );
    }
    echo "\n";
}

if ($semPlaca) {
    echo "❓ " . count($semPlaca) . " documento(s) sem placa identificável no estoque OU sem data de assinatura confiável — precisam resolver manual em admin/zapsign_importar.php:\n\n";
    foreach ($semPlaca as $item) {
        if (($item['motivo'] ?? '') === 'sem_data') {
            $motivo = 'nenhuma data de assinatura/criação encontrada no documento — nunca importa sozinho com a data de hoje';
        } else {
            $motivo = $item['veiculo']['placa'] ? "placa lida \"{$item['veiculo']['placa']}\" não bate com nenhum veículo disponível na frota" : 'nenhuma placa identificada no PDF';
        }
        printf(
            "  \"%s\" — %s (%s)\n    → %s\n",
            $item['doc']['name'] ?? '(sem nome)', $item['sig']['nome'] ?: 'sem nome identificado',
            $item['sig']['telefone'] ?: 'sem telefone', $motivo
        );
    }
    echo "\n";
}

if (!$candidatas) {
    echo "✅ Nenhuma venda com placa identificada e disponível no estoque — nada a importar agora.\n";
    exit(0);
}

echo count($candidatas) . " venda(s) com placa identificada e casando com veículo disponível na frota:\n\n";
foreach ($candidatas as $c) {
    $veic = trim(($c['oportunidade']['veiculo_marca'] ?? '') . ' ' . ($c['oportunidade']['veiculo_modelo'] ?? '')) ?: 'veículo';
    $cpf = $c['sig']['cpf'] ?: $c['veiculo']['comprador_cpf'];
    printf(
        "  \"%s\" | comprador %s (%s)%s | veículo #%d: %s — placa %s | valor lido: %s | data real do contrato: %s\n",
        $c['doc']['name'] ?? '(sem nome)', $c['sig']['nome'] ?: 'sem nome identificado',
        $c['sig']['telefone'] ?: 'sem telefone', $cpf ? ", CPF {$cpf}" : '',
        $c['oportunidade_id'], $veic,
        $c['oportunidade']['veiculo_placa'] ?? '—', $c['veiculo']['valor_venda'] ?: '(não lido)',
        substr($c['data_assinatura'], 0, 10)
    );
    $ficha = array_filter([
        'RG' => $c['veiculo']['comprador_rg'], 'endereço' => $c['veiculo']['comprador_endereco'],
        'e-mail' => $c['veiculo']['comprador_email'], 'nacionalidade' => $c['veiculo']['comprador_nacionalidade'],
        'estado civil' => $c['veiculo']['comprador_estado_civil'], 'profissão' => $c['veiculo']['comprador_profissao'],
    ]);
    if ($ficha) {
        $partes = [];
        foreach ($ficha as $rotulo => $valor) $partes[] = "{$rotulo}: {$valor}";
        echo "    ficha lida do contrato → " . implode(' | ', $partes) . "\n";
    } else {
        echo "    ficha do comprador → nenhum campo extra lido do contrato (sem chave Gemini, ou contrato não detalha)\n";
    }
}

if (!$confirmar) {
    echo "\n(dry-run — rode com --confirmar pra importar de verdade)\n";
    exit(0);
}

$importadas = 0;
$falhas = 0;
foreach ($candidatas as $c) {
    $doc = $c['doc'];
    // Mesma data já mostrada e validada no relatório acima — nunca recalcula
    // aqui (e nunca cai pra "hoje": `$c['data_assinatura']` já garantido
    // não-vazio por construção, candidata sem data nunca chega em $candidatas).
    $dataAssinatura = $c['data_assinatura'];
    $precoVenda = $c['veiculo']['valor_venda'] !== '' ? (float)$c['veiculo']['valor_venda'] : null;

    // CPF: prioriza o do metadado de assinatura da ZapSign (signatário
    // confirmou os dados na hora de assinar, mais confiável), cai pro lido
    // no corpo do contrato só se a ZapSign não trouxe nenhum.
    $dadosComprador = $c['veiculo'];
    if ($c['sig']['cpf']) $dadosComprador['comprador_cpf'] = $c['sig']['cpf'];

    // Achado real em produção (24/09/2026): "database is locked" (contenção
    // real de escrita — webhook/admin/cron gravando ao mesmo tempo, mesmo
    // incidente já documentado várias vezes no CLAUDE.md) derrubou o
    // processo INTEIRO no meio de um --confirmar longo, porque só a chamada
    // interna de criarVenda() (dentro de zapsignImportarContratoVendaComoNegociacaoManual())
    // tinha try/catch — as escritas seguintes (UPDATE vendas, INSERT
    // venda_historico/contratos) não tinham, e uma exceção não capturada aí
    // matava o script, perdendo o processamento de TODOS os documentos
    // seguintes na lista, não só o que falhou. Nunca mais: qualquer
    // Throwable na importação de 1 documento vira falha registrada, nunca
    // derruba o lote — o script é idempotente (checa zapsign_doc_token já
    // importado antes de qualquer coisa), então rodar --confirmar de novo
    // depois resolve sozinho o que falhou por contenção passageira.
    try {
        $r = zapsignImportarContratoVendaComoNegociacaoManual(
            (string)$doc['token'], $c['oportunidade_id'],
            $c['sig']['nome'] ?: 'Comprador (ZapSign)', $c['sig']['telefone'],
            $precoVenda, $dataAssinatura, /* $criadoPor */ 0, $dadosComprador
        );
    } catch (Throwable $e) {
        $r = ['ok' => false, 'erro' => 'exceção não tratada: ' . $e->getMessage()];
    }
    if ($r['ok']) {
        $importadas++;
        echo "  ✅ venda #{$r['venda_id']} importada — \"{$doc['name']}\"\n";
    } else {
        $falhas++;
        echo "  ❌ falhou \"{$doc['name']}\": {$r['erro']}\n";
    }
}

echo "\n✅ {$importadas} venda(s) importada(s)" . ($falhas ? ", {$falhas} falharam" : '') . ".\n";
if ($falhas) {
    echo "Rode o script de novo (--confirmar) daqui a pouco — é idempotente, resolve\n";
    echo "sozinho quem já foi importado e tenta de novo só quem falhou (útil pra falha\n";
    echo "passageira de contenção do banco, \"database is locked\").\n";
}
if ($importadas) {
    echo "\nAgora roda o backfill financeiro pra gerar a receita/comissão dessas vendas:\n";
    echo "  php install/gerar_lancamentos_vendas_retroativos.php\n";
}
