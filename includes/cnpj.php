<?php
/**
 * Consulta de CNPJ via BrasilAPI (https://brasilapi.com.br/api/cnpj/v1/{cnpj})
 * — mesmo provedor já usado pro FIPE (includes/fipe.php::fipeListarMarcas()),
 * sem token/chave, dados oficiais espelhados da Receita Federal. Pedido
 * José/Jean: "no modulo financeiro em foncedores coloca api do cnpj para
 * puxa da receita" — usado em admin/financeiro-fornecedores.php pra
 * preencher o cadastro de fornecedor a partir do CNPJ, em vez de digitar
 * razão social/telefone/endereço na mão.
 */

if (!defined('CNPJ_BASE_URL')) {
    define('CNPJ_BASE_URL', 'https://brasilapi.com.br/api/cnpj/v1');
}
const CNPJ_CACHE_TTL = 24 * 3600; // cadastro na Receita muda raramente — 24h de sobra

function cnpjSomenteDigitos(string $cnpj): string {
    return preg_replace('/\D/', '', $cnpj) ?? '';
}

/**
 * "Último erro" da consulta mais recente — mesmo espírito de
 * GoogleDrive::lastError e logDiagnosticoMidiaZapi(): a 1ª versão desta
 * função só devolvia null em qualquer falha, sem nenhum jeito de saber
 * o motivo (CNPJ mal formatado? não encontrado de verdade? erro de rede?
 * resposta num formato inesperado?) — achado real de produção (17/09/2026,
 * "cnpj não tá buscando api"), sem acesso a este sandbox pra reproduzir
 * (BrasilAPI bloqueada aqui, mesma limitação já documentada no CLAUDE.md).
 * `admin/fornecedor_cnpj_ajax.php` usa isso pra mostrar um aviso
 * específico em vez do genérico de sempre.
 */
function cnpjSetUltimoErro(string $motivo): void {
    $GLOBALS['__cnpj_ultimo_erro'] = $motivo;
}
function cnpjUltimoErro(): string {
    return $GLOBALS['__cnpj_ultimo_erro'] ?? '';
}

function cnpjLogDiagnostico(string $motivo, string $corpo): void {
    $dir = dirname(__DIR__) . '/storage/logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $linha = '[' . date('Y-m-d H:i:s') . "] {$motivo}\n" . substr($corpo, 0, 2000) . "\n---\n";
    @file_put_contents($dir . '/cnpj_debug.log', $linha, FILE_APPEND);
}

/**
 * Consulta um CNPJ na Receita (via BrasilAPI). Retorna null se o CNPJ não
 * tem 14 dígitos, não foi encontrado, ou a API está fora do ar — nunca
 * inventa dado (regra #3 do projeto), quem chama decide o que fazer com
 * o null (avisar o usuário, nunca preencher nada). `cnpjUltimoErro()` logo
 * depois de um null explica o motivo específico.
 */
function cnpjConsultar(string $cnpjDigitado): ?array {
    cnpjSetUltimoErro('');
    $cnpj = cnpjSomenteDigitos($cnpjDigitado);
    if (strlen($cnpj) !== 14) {
        cnpjSetUltimoErro('CNPJ precisa ter 14 dígitos (pode digitar com ou sem pontuação — só os números importam).');
        return null;
    }

    $cacheKey = "cnpj_consulta_{$cnpj}";
    $cache = getConfig($cacheKey);
    if ($cache && str_contains($cache, '|')) {
        [$timestamp, $json] = explode('|', $cache, 2);
        $decodificado = json_decode($json, true);
        if (is_array($decodificado) && (time() - (int)$timestamp) < CNPJ_CACHE_TTL) {
            return $decodificado;
        }
    }

    $ch = curl_init(CNPJ_BASE_URL . '/' . $cnpj);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        // Sem User-Agent, alguns provedores atrás de CDN (Cloudflare/Vercel,
        // é o caso da BrasilAPI) rejeitam a request como bot — curl sem essa
        // opção manda vazio por padrão.
        CURLOPT_USERAGENT => 'FastcarCRM/1.0 (+https://fastcar.solutions)',
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $erroCurl = curl_error($ch);
    curl_close($ch);

    if ($body === false || $erroCurl) {
        cnpjSetUltimoErro('Falha de conexão com a Receita/BrasilAPI: ' . ($erroCurl ?: 'sem resposta'));
        cnpjLogDiagnostico("erro_curl cnpj={$cnpj} http_code={$code}", $erroCurl);
        return null;
    }
    if ($code === 404) {
        cnpjSetUltimoErro('CNPJ não encontrado na Receita.');
        return null;
    }
    if ($code !== 200) {
        cnpjSetUltimoErro("A Receita/BrasilAPI respondeu com erro (HTTP {$code}). Tente de novo em alguns minutos.");
        cnpjLogDiagnostico("http_nao_200 cnpj={$cnpj} http_code={$code}", (string)$body);
        return null;
    }
    $dados = json_decode($body, true);
    if (!is_array($dados) || empty($dados['cnpj'])) {
        cnpjSetUltimoErro('A Receita/BrasilAPI respondeu num formato inesperado.');
        cnpjLogDiagnostico("formato_inesperado cnpj={$cnpj}", (string)$body);
        return null;
    }

    $resultado = [
        'cnpj' => (string)$dados['cnpj'],
        'razao_social' => trim((string)($dados['razao_social'] ?? '')),
        'nome_fantasia' => trim((string)($dados['nome_fantasia'] ?? '')),
        'situacao' => trim((string)($dados['descricao_situacao_cadastral'] ?? '')),
        'telefone' => trim((string)($dados['ddd_telefone_1'] ?? '')),
        'email' => trim((string)($dados['email'] ?? '')),
        'endereco' => cnpjMontarEndereco($dados),
    ];

    setConfig($cacheKey, time() . '|' . json_encode($resultado));
    return $resultado;
}

function cnpjMontarEndereco(array $dados): string {
    $logradouro = trim((string)($dados['logradouro'] ?? ''));
    $numero = trim((string)($dados['numero'] ?? ''));
    $bairro = trim((string)($dados['bairro'] ?? ''));
    $municipio = trim((string)($dados['municipio'] ?? ''));
    $uf = trim((string)($dados['uf'] ?? ''));
    $cep = trim((string)($dados['cep'] ?? ''));

    $partes = [];
    if ($logradouro) {
        $partes[] = $logradouro . ($numero ? ", {$numero}" : '');
    }
    if ($bairro) {
        $partes[] = $bairro;
    }
    if ($municipio || $uf) {
        $partes[] = trim("{$municipio}/{$uf}", '/');
    }
    if ($cep) {
        $partes[] = "CEP {$cep}";
    }
    return implode(' — ', $partes);
}
