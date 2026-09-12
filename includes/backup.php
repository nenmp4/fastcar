<?php
/**
 * includes/backup.php — lógica de backup compartilhada entre cron/ (rodado
 * automático) e admin/backup.php (botão manual), pra nunca duplicar a
 * mesma lógica em dois lugares e ela divergir com o tempo (mesmo motivo do
 * mudarEtapa() central em includes/oportunidades.php).
 *
 * Dois tipos de backup, mesmo padrão do JurídicoSaaS:
 *  - "banco" — só o .db, cópia rápida e frequente (várias vezes ao dia),
 *    pra recuperação rápida de um "oops" recente.
 *  - "completo" — .db + storage/uploads/ (fallback local de documento, só
 *    existe quando o Drive não pegou o upload) + a credencial do Drive (se
 *    existir) num ZIP, 1x/dia — o código em si NÃO entra no zip porque já
 *    está versionado no git, backup de código duplicado seria desperdício.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/google_drive.php';

defined('ROOT') or define('ROOT', dirname(__DIR__));
defined('BACKUP_DIR') or define('BACKUP_DIR', ROOT . '/storage/backups');
defined('MAX_DB_BACKUPS') or define('MAX_DB_BACKUPS', 7);
defined('MAX_ZIP_BACKUPS') or define('MAX_ZIP_BACKUPS', 5);
defined('MAX_DRIVE_BACKUPS') or define('MAX_DRIVE_BACKUPS', 5);

/** Garante storage/backups/ com .htaccess Deny from all (nunca serve estático). */
function backupGarantirDiretorio(): bool {
    if (is_dir(BACKUP_DIR)) return true;
    if (!@mkdir(BACKUP_DIR, 0755, true)) return false;
    @file_put_contents(BACKUP_DIR . '/.htaccess', "Deny from all\n");
    return true;
}

/**
 * Cópia rápida do .db — pra rodar várias vezes ao dia. Sobrescreve o backup
 * do dia se já existir (última execução do dia vale), mantém só os últimos
 * MAX_DB_BACKUPS dias.
 * @return array{ok:bool, mensagem:string, arquivo?:string}
 */
function backupDbCopiar(): array {
    $origem = ROOT . '/database/fastcar.db';
    if (!file_exists($origem)) return ['ok' => false, 'mensagem' => 'Banco não encontrado: ' . $origem];
    if (!backupGarantirDiretorio()) return ['ok' => false, 'mensagem' => 'Falha ao criar storage/backups/'];

    $destino = BACKUP_DIR . '/backup_db_' . date('Y-m-d') . '.db';
    if (!@copy($origem, $destino)) return ['ok' => false, 'mensagem' => 'Falha ao copiar banco'];

    $antigos = glob(BACKUP_DIR . '/backup_db_*.db') ?: [];
    if (count($antigos) > MAX_DB_BACKUPS) {
        usort($antigos, fn($a, $b) => filemtime($b) - filemtime($a));
        foreach (array_slice($antigos, MAX_DB_BACKUPS) as $velho) @unlink($velho);
    }

    return ['ok' => true, 'mensagem' => 'Banco copiado', 'arquivo' => basename($destino)];
}

/**
 * ZIP completo: .db + storage/uploads/ + credencial do Drive (se existir).
 * 1x/dia, sobrescreve o zip do dia se já existir, mantém os últimos
 * MAX_ZIP_BACKUPS dias.
 * @return array{ok:bool, mensagem:string, arquivo?:string}
 */
function backupCompletoZip(): array {
    if (!class_exists('ZipArchive')) return ['ok' => false, 'mensagem' => 'Extensão ZipArchive não disponível no PHP'];
    if (!backupGarantirDiretorio()) return ['ok' => false, 'mensagem' => 'Falha ao criar storage/backups/'];

    $destino = BACKUP_DIR . '/backup_completo_' . date('Y-m-d') . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($destino, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        return ['ok' => false, 'mensagem' => 'Falha ao criar ' . basename($destino)];
    }

    $dbPath = ROOT . '/database/fastcar.db';
    if (file_exists($dbPath)) $zip->addFile($dbPath, 'database/fastcar.db');

    $uploadsDir = ROOT . '/storage/uploads';
    if (is_dir($uploadsDir)) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($uploadsDir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $arquivo) {
            if ($arquivo->getFilename() === '.gitkeep') continue;
            $relativo = 'storage/uploads/' . substr($arquivo->getPathname(), strlen($uploadsDir) + 1);
            $zip->addFile($arquivo->getPathname(), $relativo);
        }
    }

    $credenciais = ROOT . '/config/google_drive_credentials.json';
    if (file_exists($credenciais)) $zip->addFile($credenciais, 'config/google_drive_credentials.json');

    $zip->close();

    $antigos = glob(BACKUP_DIR . '/backup_completo_*.zip') ?: [];
    if (count($antigos) > MAX_ZIP_BACKUPS) {
        usort($antigos, fn($a, $b) => filemtime($b) - filemtime($a));
        foreach (array_slice($antigos, MAX_ZIP_BACKUPS) as $velho) @unlink($velho);
    }

    return ['ok' => true, 'mensagem' => 'Backup completo criado', 'arquivo' => basename($destino)];
}

/** Lista os arquivos de backup locais (banco + zip), mais novo primeiro. */
function backupListarLocais(): array {
    if (!is_dir(BACKUP_DIR)) return [];
    $arquivos = array_merge(glob(BACKUP_DIR . '/backup_db_*.db') ?: [], glob(BACKUP_DIR . '/backup_completo_*.zip') ?: []);
    usort($arquivos, fn($a, $b) => filemtime($b) - filemtime($a));
    return array_map(fn($f) => [
        'nome'      => basename($f),
        'tamanho'   => filesize($f),
        'modificado' => filemtime($f),
        'tipo'      => str_ends_with($f, '.zip') ? 'completo' : 'banco',
    ], $arquivos);
}

/** Pasta "Backups" dentro da raiz Fastcar no Drive — criada sob demanda. */
function garantirPastaDriveBackups(GoogleDrive $drive): ?string {
    $id = getConfig('drive_backups_folder_id');
    if ($id) return $id;
    $raizId = getConfig('drive_folder_id') ?: $drive->createFolder('Fastcar');
    if ($raizId && !getConfig('drive_folder_id')) setConfig('drive_folder_id', $raizId);
    if (!$raizId) return null;
    $novaId = $drive->createFolder('Backups', $raizId);
    if ($novaId) setConfig('drive_backups_folder_id', $novaId);
    return $novaId ?: null;
}

/**
 * Envia o ZIP completo mais recente pro Drive (pasta Backups) e roda a
 * rotação lá (mantém só os últimos MAX_DRIVE_BACKUPS). Pula o upload se já
 * existir um backup de hoje no Drive (evita duplicar se o cron rodar 2x).
 * @return array{ok:bool, mensagem:string}
 */
function backupEnviarDrive(): array {
    if (getConfig('drive_backup_ativo') === '0') return ['ok' => true, 'mensagem' => 'Backup no Drive desativado nas configurações — nada a fazer'];

    $drive = new GoogleDrive();
    if (!$drive->hasCredentials()) return ['ok' => false, 'mensagem' => 'Credenciais do Drive não configuradas'];
    if (!$drive->authenticate()) return ['ok' => false, 'mensagem' => 'Falha ao autenticar no Drive'];

    $zips = glob(BACKUP_DIR . '/backup_completo_*.zip') ?: [];
    if (!$zips) return ['ok' => false, 'mensagem' => 'Nenhum backup completo local encontrado — rode o backup completo antes'];
    usort($zips, fn($a, $b) => filemtime($b) - filemtime($a));
    $zipMaisRecente = $zips[0];
    $nomeZip = basename($zipMaisRecente);

    $pastaId = garantirPastaDriveBackups($drive);
    if (!$pastaId) return ['ok' => false, 'mensagem' => 'Falha ao criar/obter pasta Backups no Drive'];

    $existentes = $drive->list($pastaId, 'backup_completo_');
    $hoje = date('Y-m-d');
    $jaTemHoje = false;
    foreach ($existentes as $f) {
        if (str_contains($f['name'], $hoje)) { $jaTemHoje = true; break; }
    }

    if (!$jaTemHoje) {
        $fileId = $drive->uploadFile($zipMaisRecente, $nomeZip, 'application/zip', $pastaId);
        if (!$fileId) return ['ok' => false, 'mensagem' => 'Falha no upload: ' . ($drive->lastError ?: 'erro desconhecido')];
        setConfig('drive_backup_ultimo', date('Y-m-d H:i:s'));
        $existentes[] = ['name' => $nomeZip, 'id' => $fileId, 'createdTime' => date('c')];
    }

    // Rotação: roda sempre, mesmo se não subiu nada agora
    if (count($existentes) > MAX_DRIVE_BACKUPS) {
        usort($existentes, fn($a, $b) => strcmp($a['createdTime'] ?? '', $b['createdTime'] ?? ''));
        foreach (array_slice($existentes, 0, count($existentes) - MAX_DRIVE_BACKUPS) as $velho) {
            $drive->delete($velho['id']);
        }
    }

    return ['ok' => true, 'mensagem' => $jaTemHoje ? 'Já existia backup de hoje no Drive — só rotação' : "Enviado: {$nomeZip}"];
}

/** Lista backups na pasta Backups do Drive, mais novo primeiro — vazio se Drive não configurado. */
function backupListarDrive(): array {
    $drive = new GoogleDrive();
    if (!$drive->hasCredentials()) return [];
    if (!$drive->authenticate()) return [];
    $pastaId = getConfig('drive_backups_folder_id');
    if (!$pastaId) return [];
    $arquivos = $drive->list($pastaId, 'backup_');
    usort($arquivos, fn($a, $b) => strcmp($b['createdTime'] ?? '', $a['createdTime'] ?? ''));
    return $arquivos;
}
