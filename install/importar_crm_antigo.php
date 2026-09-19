<?php
/**
 * Importa o CRM antigo (Yaqar/IACAR — mesmo CNPJ da Fastcar, confirmado
 * via distribution_config.csv) do export compartilhado no Google Drive
 * com a service account do Fastcar. 19/09/2026, "zapasine tem monte
 * contrato do crm anti será possivel puxar concliar" → "Analisar e propor
 * um plano de importação" → escopo confirmado com o usuário.
 *
 * **Fase 1 (esta rodada)**: clients.csv → frota (cliente + oportunidade
 * já 'fechado', com documentos reais e dados de veículo/financiamento) —
 * ver includes/importar_crm_antigo.php::crmAntigoImportarCliente(). Fase 2
 * (sales.csv → vendas) e Fase 3 (leads.csv won/lost como histórico) já têm
 * a função pronta em includes/importar_crm_antigo.php mas ficam pra depois
 * — "depois temos ver da venda" (usuário pediu pra validar a compra
 * primeiro).
 *
 * Pré-requisito manual, fora do código (já feito pelo usuário, 19/09/2026):
 * compartilhar a pasta raiz do export (com as subpastas tabelas/ e
 * arquivos/) no Google Drive com o e-mail da service account
 * (Configurações → Google Drive), como Leitor.
 *
 * Uso:
 *   php install/importar_crm_antigo.php <drive_folder_id>                    — dry-run (só lista)
 *   php install/importar_crm_antigo.php <drive_folder_id> --confirmar        — importa de verdade
 *   php install/importar_crm_antigo.php <drive_folder_id> --criado-por=ID    — usuário responsável pelo registro de auditoria (default: 1º super_admin)
 *
 * Nunca reimporta o mesmo zapsign_doc_token 2x (mesma trava de
 * includes/zapsign_importar.php) nem cria cliente duplicado por telefone
 * já cadastrado — rodar o script de novo depois de já ter confirmado uma
 * vez só reprocessa quem falhou/ficou de fora antes.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Este script só roda via linha de comando (CLI).\n");
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/importar_crm_antigo.php';

$args = $argv;
array_shift($args); // remove o nome do script
$confirmar = false;
$criadoPorArg = null;
$driveFolderId = null;
foreach ($args as $arg) {
    if ($arg === '--confirmar') { $confirmar = true; continue; }
    if (str_starts_with($arg, '--criado-por=')) { $criadoPorArg = (int)substr($arg, strlen('--criado-por=')); continue; }
    if (!$driveFolderId && !str_starts_with($arg, '--')) { $driveFolderId = $arg; continue; }
}

if (!$driveFolderId) {
    fwrite(STDERR, "Uso: php install/importar_crm_antigo.php <drive_folder_id> [--confirmar] [--criado-por=ID]\n");
    fwrite(STDERR, "<drive_folder_id> é o ID da pasta raiz do export (URL do Drive: drive.google.com/drive/folders/<ESTE_ID>).\n");
    exit(1);
}

$db = getDB();

$criadoPor = $criadoPorArg;
if (!$criadoPor) {
    $stmt = $db->query("SELECT id FROM usuarios WHERE perfil = 'super_admin' ORDER BY id LIMIT 1");
    $criadoPor = (int)($stmt->fetchColumn() ?: 0);
}
if (!$criadoPor) {
    fwrite(STDERR, "Nenhum super_admin encontrado — passe --criado-por=ID explicitamente.\n");
    exit(1);
}

echo "== Importação CRM antigo (Yaqar/IACAR) — Fase 1: clientes/frota ==\n";
echo $confirmar ? "Modo: CONFIRMAR (grava de verdade)\n" : "Modo: DRY-RUN (só lista, nada é gravado — rode com --confirmar pra aplicar)\n";
echo "Responsável pelo registro de auditoria: usuário #{$criadoPor}\n\n";

try {
    $drive = crmAntigoAutenticarDrive();
} catch (RuntimeException $e) {
    fwrite(STDERR, "Erro de autenticação no Drive: {$e->getMessage()}\n");
    exit(1);
}

$tabelasId = crmAntigoAcharSubpasta($drive, $driveFolderId, 'tabelas');
$arquivosId = crmAntigoAcharSubpasta($drive, $driveFolderId, 'arquivos');
if (!$tabelasId || !$arquivosId) {
    fwrite(STDERR, "Não achei as subpastas \"tabelas\"/\"arquivos\" dentro da pasta informada — confirme o ID e o compartilhamento.\n");
    exit(1);
}

echo "Lendo CSVs...\n";
$clients = crmAntigoLerCsv($drive, $tabelasId, 'clients.csv');
$sellers = crmAntigoLerCsv($drive, $tabelasId, 'sellers.csv');
$documents = crmAntigoLerCsv($drive, $tabelasId, 'documents.csv');
echo "  clients.csv: " . count($clients) . " linha(s)\n";
echo "  sellers.csv: " . count($sellers) . " linha(s)\n";
echo "  documents.csv: " . count($documents) . " linha(s)\n\n";

// UUID do vendedor antigo → telefone antigo (pra depois mapear telefone →
// usuarios.id atual, dentro de crmAntigoImportarCliente()).
$telefonePorVendedorId = [];
foreach ($sellers as $s) {
    if (!empty($s['id'])) $telefonePorVendedorId[$s['id']] = (string)($s['phone'] ?? '');
}

// documents.csv agrupado por client_id — só os documentos de quem vamos
// processar, evita varrer a lista inteira (399 linhas) a cada cliente.
$documentosPorCliente = [];
foreach ($documents as $d) {
    $cid = (string)($d['client_id'] ?? '');
    if ($cid === '') continue;
    $documentosPorCliente[$cid][] = $d;
}

$resultados = ['importado' => 0, 'ja_importado' => 0, 'erro' => 0];
$log = [];

foreach ($clients as $i => $linha) {
    $n = $i + 1;
    $nome = $linha['full_name'] ?? '(sem nome)';
    $telefone = $linha['phone'] ?? '(sem telefone)';

    if (!$confirmar) {
        // Dry-run: só reporta o que faria, sem tocar no banco.
        $zapsignToken = (string)($linha['zapsign_doc_token'] ?? '');
        $idAntigo = (string)($linha['id'] ?? '');
        $telJaExiste = crmAntigoClienteExistentePorTelefone((string)($linha['phone'] ?? ''));
        if ($idAntigo && crmAntigoJaImportadoPorIdAntigo('clients', $idAntigo)) {
            echo "[{$n}/" . count($clients) . "] PULARIA (já importado numa rodada anterior) — {$nome} / {$telefone}\n";
            $resultados['ja_importado']++;
        } elseif ($zapsignToken && crmAntigoContratoJaImportado($zapsignToken)) {
            echo "[{$n}/" . count($clients) . "] PULARIA (zapsign já importado) — {$nome} / {$telefone}\n";
            $resultados['ja_importado']++;
        } elseif ($telJaExiste) {
            echo "[{$n}/" . count($clients) . "] ATENÇÃO — telefone já cadastrado como cliente #{$telJaExiste} — {$nome} / {$telefone} (vai reaproveitar o cliente existente, não duplica)\n";
            $resultados['importado']++;
        } else {
            $qtdDocs = count($documentosPorCliente[$linha['id'] ?? ''] ?? []);
            echo "[{$n}/" . count($clients) . "] importaria — {$nome} / {$telefone} ({$qtdDocs} documento(s))\n";
            $resultados['importado']++;
        }
        continue;
    }

    $telefoneVendedorAntigo = $telefonePorVendedorId[$linha['assigned_seller_id'] ?? ''] ?? null;
    $docsDoCliente = $documentosPorCliente[$linha['id'] ?? ''] ?? [];

    // Retry com backoff específico pra "database is locked" — achado real
    // em produção 19/09/2026: rodando junto com tráfego normal do admin
    // (polling do WhatsApp Box, navegação ao vivo), o banco fica disputado
    // por escritores reais o tempo todo, e o busy_timeout (15s) sozinho não
    // é suficiente quando a disputa é sustentada, não só um pico passageiro.
    // Nunca reexecuta um cliente que já teve sucesso (só chega aqui se
    // lançou exceção) — como a marcação de idempotência agora acontece
    // dentro da mesma transação atômica (includes/importar_crm_antigo.php),
    // uma tentativa que "ok=true" sempre já está 100% commitada, nunca
    // precisa de retry.
    $tentativas = 0;
    $maxTentativas = 5;
    do {
        $tentativas++;
        try {
            $r = crmAntigoImportarCliente($linha, $drive, $arquivosId, $docsDoCliente, $criadoPor, $telefoneVendedorAntigo);
            break;
        } catch (Throwable $e) {
            $ehLock = str_contains($e->getMessage(), 'database is locked') || str_contains($e->getMessage(), 'HY000');
            if ($ehLock && $tentativas < $maxTentativas) {
                $espera = $tentativas * 5; // 5s, 10s, 15s, 20s
                echo "  (tentativa {$tentativas} travou no banco, esperando {$espera}s antes de tentar de novo...)\n";
                sleep($espera);
                continue;
            }
            $r = ['ok' => false, 'motivo' => 'Exceção: ' . $e->getMessage(), 'cliente_id' => null, 'oportunidade_id' => null, 'ja_existia' => false];
            break;
        }
    } while ($tentativas < $maxTentativas);

    if ($r['ok']) {
        echo "[{$n}/" . count($clients) . "] OK — {$nome} / {$telefone} → cliente #{$r['cliente_id']}, oportunidade #{$r['oportunidade_id']}\n";
        $resultados['importado']++;
    } elseif ($r['ja_existia']) {
        echo "[{$n}/" . count($clients) . "] pulado (já importado) — {$nome} / {$telefone} — {$r['motivo']}\n";
        $resultados['ja_importado']++;
    } else {
        echo "[{$n}/" . count($clients) . "] ERRO — {$nome} / {$telefone} — {$r['motivo']}\n";
        $resultados['erro']++;
    }
    $log[] = ['nome' => $nome, 'telefone' => $telefone, 'resultado' => $r];
}

echo "\n== Resumo ==\n";
echo "Importado(s): {$resultados['importado']}\n";
echo "Já importado(s)/pulado(s): {$resultados['ja_importado']}\n";
echo "Erro(s): {$resultados['erro']}\n";

if ($confirmar) {
    $dir = __DIR__ . '/../storage/logs';
    @mkdir($dir, 0755, true);
    $arquivoLog = $dir . '/importar_crm_antigo_' . date('Y-m-d_His') . '.log';
    file_put_contents($arquivoLog, json_encode($log, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    echo "Log detalhado: {$arquivoLog}\n";
} else {
    echo "\nDry-run — nada foi gravado. Rode com --confirmar pra aplicar de verdade.\n";
}
