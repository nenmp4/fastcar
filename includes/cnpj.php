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
 * Consulta um CNPJ na Receita (via BrasilAPI). Retorna null se o CNPJ não
 * tem 14 dígitos, não foi encontrado, ou a API está fora do ar — nunca
 * inventa dado (regra #3 do projeto), quem chama decide o que fazer com
 * o null (avisar o usuário, nunca preencher nada).
 */
function cnpjConsultar(string $cnpjDigitado): ?array {
    $cnpj = cnpjSomenteDigitos($cnpjDigitado);
    if (strlen($cnpj) !== 14) {
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
        CURLOPT_TIMEOUT => 8,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false || $code !== 200) {
        return null;
    }
    $dados = json_decode($body, true);
    if (!is_array($dados) || empty($dados['cnpj'])) {
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
