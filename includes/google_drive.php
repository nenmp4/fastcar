<?php
/**
 * includes/google_drive.php — mesmo padrão do JurídicoSaaS: PHP puro com
 * cURL + JWT de service account, sem biblioteca externa (funciona em
 * cPanel/hospedagem compartilhada sem Composer).
 *
 * Credencial fica em config/google_drive_credentials.json (protegida por
 * config/.htaccess — Deny from all), NUNCA no banco nem versionada — é um
 * arquivo JSON de service account do Google Cloud, dropado manualmente
 * (FTP/SSH) quando a conta existir. Reaproveita a MESMA lógica de auth do
 * JurídicoSaaS; a credencial em si é própria da Fastcar, não a mesma
 * conta/chave do escritório de advocacia.
 */

defined('ROOT') or define('ROOT', dirname(__DIR__));

class GoogleDrive {
    private string $token = '';
    private string $credentials_path;
    public string $lastError = '';

    public function __construct(?string $credentials_path = null) {
        $this->credentials_path = $credentials_path ?? ROOT . '/config/google_drive_credentials.json';
    }

    public function getToken(): string { return $this->token; }

    /** URLs override via define() só em teste (fake server local). */
    private function oauthUrl(): string { return defined('GOOGLE_OAUTH_URL') ? GOOGLE_OAUTH_URL : 'https://oauth2.googleapis.com/token'; }
    private function apiUrl(): string { return defined('GOOGLE_DRIVE_API_URL') ? GOOGLE_DRIVE_API_URL : 'https://www.googleapis.com/drive/v3'; }
    private function uploadUrl(): string { return defined('GOOGLE_DRIVE_UPLOAD_URL') ? GOOGLE_DRIVE_UPLOAD_URL : 'https://www.googleapis.com/upload/drive/v3'; }

    public function hasCredentials(): bool {
        if (!file_exists($this->credentials_path)) return false;
        $j = json_decode(file_get_contents($this->credentials_path), true);
        return isset($j['client_email'], $j['private_key']);
    }

    public function getCredentialEmail(): string {
        if (!$this->hasCredentials()) return '';
        $j = json_decode(file_get_contents($this->credentials_path), true);
        return $j['client_email'] ?? '';
    }

    public function authenticate(): bool {
        if (!$this->hasCredentials()) return false;
        $creds = json_decode(file_get_contents($this->credentials_path), true);
        $now = time();
        $header  = $this->b64u(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $payload = $this->b64u(json_encode([
            'iss'   => $creds['client_email'],
            'scope' => 'https://www.googleapis.com/auth/drive',
            'aud'   => 'https://oauth2.googleapis.com/token',
            'exp'   => $now + 3600,
            'iat'   => $now,
        ]));
        $key = openssl_pkey_get_private($creds['private_key']);
        if (!$key) return false;
        $sig = '';
        openssl_sign("{$header}.{$payload}", $sig, $key, 'SHA256');
        $jwt = "{$header}.{$payload}." . $this->b64u($sig);

        $resp = $this->curlPost(
            $this->oauthUrl(),
            http_build_query(['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $jwt]),
            ['Content-Type: application/x-www-form-urlencoded']
        );
        $data = json_decode($resp, true);
        if (empty($data['access_token'])) return false;
        $this->token = $data['access_token'];
        return true;
    }

    public function createFolder(string $nome, ?string $parent_id = null): string|bool {
        if (!$this->token) return false;
        $meta = ['name' => $nome, 'mimeType' => 'application/vnd.google-apps.folder'];
        if ($parent_id) $meta['parents'] = [$parent_id];
        $ch = curl_init($this->apiUrl() . '/files?supportsAllDrives=true');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($meta),
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $this->token, 'Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 15,
        ]);
        $resp   = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($status !== 200 && $status !== 201) return false;
        $data = json_decode($resp, true);
        return $data['id'] ?? false;
    }

    /**
     * Lista arquivos dentro de uma pasta (opcionalmente filtrando pelo nome
     * conter $prefix) — usado pela rotação de backups no Drive
     * (cron/backup_drive.php): precisa saber quais já existem lá pra decidir
     * o que apagar e não subir duplicado no mesmo dia.
     * @return array<int, array{id:string,name:string,createdTime:string,size?:string}>
     */
    public function list(string $folder_id, string $prefix = ''): array {
        if (!$this->token) return [];
        $q = "'{$folder_id}' in parents and trashed=false";
        if ($prefix) $q .= " and name contains '{$prefix}'";

        $params = http_build_query([
            'q'                         => $q,
            'orderBy'                   => 'createdTime',
            'supportsAllDrives'         => 'true',
            'includeItemsFromAllDrives' => 'true',
            'fields'                    => 'files(id,name,createdTime,size)',
        ]);

        $ch = curl_init($this->apiUrl() . '/files?' . $params);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $this->token],
            CURLOPT_TIMEOUT        => 15,
        ]);
        $resp   = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($status !== 200) return [];
        $data = json_decode($resp, true);
        return $data['files'] ?? [];
    }

    public function uploadFile(string $tmp_path, string $nome, string $mime, string $folder_id): string|bool {
        if (!$this->token) return false;
        $conteudo = file_get_contents($tmp_path);
        if ($conteudo === false) return false;
        $meta     = json_encode(['name' => $nome, 'parents' => [$folder_id]]);
        $boundary = 'fastcar_' . md5(uniqid());
        $body     = "--{$boundary}\r\nContent-Type: application/json; charset=UTF-8\r\n\r\n{$meta}\r\n";
        $body    .= "--{$boundary}\r\nContent-Type: {$mime}\r\n\r\n{$conteudo}\r\n";
        $body    .= "--{$boundary}--";
        $ch = curl_init($this->uploadUrl() . '/files?uploadType=multipart&supportsAllDrives=true');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->token,
                'Content-Type: multipart/related; boundary="' . $boundary . '"',
                'Content-Length: ' . strlen($body),
            ],
            CURLOPT_TIMEOUT => 120,
        ]);
        $resp   = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($status !== 200 && $status !== 201) {
            $err = json_decode($resp ?: '{}', true);
            $this->lastError = ($err['error']['message'] ?? '') ?: "HTTP {$status}";
            return false;
        }
        $data = json_decode($resp, true);
        return $data['id'] ?? false;
    }

    /**
     * Baixa o conteúdo binário autenticado como a service account — usado
     * pelo proxy de download (admin/ver_documento.php) pra nunca precisar
     * tornar o arquivo público no Drive só pra exibir pro admin logado.
     * Retorna ['content'=>string,'mime'=>string,'name'=>string] ou false.
     */
    public function download(string $file_id): array|bool {
        if (!$this->token) return false;

        $chMeta = curl_init($this->apiUrl() . "/files/{$file_id}?supportsAllDrives=true&fields=mimeType,name");
        curl_setopt_array($chMeta, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $this->token],
            CURLOPT_TIMEOUT        => 15,
        ]);
        $metaResp = curl_exec($chMeta);
        $metaStatus = curl_getinfo($chMeta, CURLINFO_HTTP_CODE);
        curl_close($chMeta);
        if ($metaStatus !== 200) return false;
        $meta = json_decode($metaResp, true);
        $mime = $meta['mimeType'] ?? 'application/octet-stream';
        $name = $meta['name'] ?? 'arquivo';

        $ch = curl_init($this->apiUrl() . "/files/{$file_id}?alt=media&supportsAllDrives=true");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $this->token],
            CURLOPT_TIMEOUT        => 60,
        ]);
        $content = curl_exec($ch);
        $status  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($status !== 200 || $content === false) return false;

        return ['content' => $content, 'mime' => $mime, 'name' => $name];
    }

    public function delete(string $file_id): bool {
        if (!$this->token) return false;
        $ch = curl_init($this->apiUrl() . "/files/{$file_id}?supportsAllDrives=true");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => 'PATCH',
            CURLOPT_POSTFIELDS     => json_encode(['trashed' => true]), // "Administrador de conteúdo" não pode excluir de vez
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $this->token, 'Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 15,
        ]);
        curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $status === 200;
    }

    private function b64u(string $data): string {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function curlPost(string $url, mixed $body, array $headers = []): string {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 30,
        ]);
        $r = curl_exec($ch);
        curl_close($ch);
        return $r ?: '';
    }
}

/**
 * Upload da credencial de service account pela tela de Configurações
 * (15/09/2026, pedido José/Jean — antes só dava pra dropar manualmente por
 * FTP/SSH, decisão original de segurança; agora com upload autenticado
 * também, restrito ao super_admin como o resto de admin/configuracoes.php).
 * Reaproveitada tanto pro Google Drive quanto pro e-mail transacional
 * (includes/mail.php), que leem o MESMO arquivo.
 */
function processarUploadCredencialGoogle(array $arquivo): array {
    if (($arquivo['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['ok' => false, 'erro' => 'Selecione o arquivo JSON antes de enviar.'];
    }
    if ($arquivo['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'erro' => 'Falha no envio do arquivo (tente de novo).'];
    }
    if ($arquivo['size'] > 20 * 1024) {
        return ['ok' => false, 'erro' => 'Arquivo grande demais pra ser uma credencial de service account (esperado poucos KB).'];
    }

    $conteudo = file_get_contents($arquivo['tmp_name']);
    $dados = json_decode($conteudo ?: '', true);
    if (!is_array($dados) || ($dados['type'] ?? '') !== 'service_account'
        || empty($dados['client_email']) || empty($dados['private_key'])) {
        return ['ok' => false, 'erro' => 'Não parece uma credencial de service account válida (esperado um JSON com "type":"service_account", client_email e private_key).'];
    }

    $destino = ROOT . '/config/google_drive_credentials.json';
    @mkdir(dirname($destino), 0755, true);
    if (!copy($arquivo['tmp_name'], $destino)) {
        return ['ok' => false, 'erro' => 'Não deu pra salvar o arquivo no servidor.'];
    }
    @chmod($destino, 0600); // defesa extra além do config/.htaccess (Deny from all)

    return ['ok' => true, 'email' => $dados['client_email']];
}
