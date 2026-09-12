<?php
/**
 * Integração com a API FIPE. Decisão com o Jean (12/09/2026): usar a
 * BrasilAPI (gratuita, sem token) só pra VALIDAR/NORMALIZAR a marca do
 * veículo — ela não tem busca de modelo/ano por marca, só lista de
 * marcas e preço por código FIPE já conhecido. Se um dia precisar de
 * busca completa marca→modelo→ano→valor, a Parallelum FIPE v2 (com
 * token) é o próximo passo, sem afetar quem já usa fipeValidarMarca().
 *
 * Cache no padrão já documentado no CLAUDE.md: `config` chave/valor,
 * valor = "timestamp|json" — evita bater na API toda hora.
 *
 * Nunca lança exceção nem bloqueia o fluxo principal: se a FIPE estiver
 * fora do ar, a validação simplesmente fica indisponível — o cadastro do
 * cliente (regra do Jean) nunca pode depender de um serviço externo.
 */

require_once __DIR__ . '/db.php';

if (!defined('FIPE_BASE_URL')) {
    define('FIPE_BASE_URL', 'https://brasilapi.com.br/api/fipe');
}
const FIPE_CACHE_TTL = 7 * 24 * 3600; // marcas mudam raramente — 7 dias de sobra

/**
 * Lista de marcas de carro da FIPE, com cache. Retorna [] se a API estiver
 * fora do ar e não houver nem cache velho pra recorrer.
 */
function fipeListarMarcas(): array {
    $cacheKey = 'fipe_marcas_cache';
    $cache = getConfig($cacheKey);
    $cacheAntigo = null;
    if ($cache && str_contains($cache, '|')) {
        [$timestamp, $json] = explode('|', $cache, 2);
        $decodificado = json_decode($json, true);
        if (is_array($decodificado)) {
            if ((time() - (int)$timestamp) < FIPE_CACHE_TTL) {
                return $decodificado;
            }
            $cacheAntigo = $decodificado; // vencido, mas serve de fallback se a API falhar agora
        }
    }

    $marcas = fipeRequisitar('/marcas/v1/carros');
    if ($marcas === null) {
        return $cacheAntigo ?? [];
    }

    $nomes = array_values(array_filter(array_map(
        fn($m) => is_array($m) && isset($m['nome']) ? (string)$m['nome'] : null,
        $marcas
    )));
    setConfig($cacheKey, time() . '|' . json_encode($nomes));
    return $nomes;
}

/**
 * Confere se $marcaDigitada bate com alguma marca oficial da FIPE.
 * NUNCA sobrescreve o que foi digitado (regra do Jean: nunca inventar/
 * corrigir informação por conta própria) — só devolve um sinal pra quem
 * chama decidir o que mostrar. "corrigida" é só uma sugestão de typo
 * pequeno, quem usa decide se aplica ou não.
 *
 * Retorno:
 *   ['status' => 'ok', 'sugestao' => 'Toyota']          — bate exato (ignorando caixa/acento)
 *   ['status' => 'corrigida', 'sugestao' => 'Toyota']   — nome parecido, possível erro de digitação
 *   ['status' => 'nao_encontrada', 'sugestao' => null]  — nenhuma marca parecida na FIPE
 *   ['status' => 'indisponivel', 'sugestao' => null]    — FIPE fora do ar, sem cache pra checar
 */
function fipeValidarMarca(string $marcaDigitada): array {
    $marcaDigitada = trim($marcaDigitada);
    if ($marcaDigitada === '') {
        return ['status' => 'nao_encontrada', 'sugestao' => null];
    }

    $marcas = fipeListarMarcas();
    if (!$marcas) {
        return ['status' => 'indisponivel', 'sugestao' => null];
    }

    $norm = fn(string $s) => strtolower(preg_replace('/[^a-z0-9]/i', '', iconv('UTF-8', 'ASCII//TRANSLIT', $s) ?: $s));
    $alvo = $norm($marcaDigitada);

    foreach ($marcas as $m) {
        if ($norm($m) === $alvo) {
            return ['status' => 'ok', 'sugestao' => $m];
        }
    }

    $melhor = null;
    $melhorDist = null;
    foreach ($marcas as $m) {
        $dist = levenshtein($norm($m), $alvo);
        if ($melhorDist === null || $dist < $melhorDist) {
            $melhorDist = $dist;
            $melhor = $m;
        }
    }

    // Diferença pequena (proporcional ao tamanho do nome) = provável erro
    // de digitação ("Toyta" → "Toyota"). Limite baixo de propósito pra não
    // "sugerir" uma marca totalmente diferente da digitada.
    if ($melhor !== null && $melhorDist <= max(1, (int)floor(strlen($alvo) * 0.25))) {
        return ['status' => 'corrigida', 'sugestao' => $melhor];
    }

    return ['status' => 'nao_encontrada', 'sugestao' => null];
}

/** GET simples na base da FIPE configurada — retorna null se der qualquer problema (nunca lança). */
function fipeRequisitar(string $caminho): ?array {
    $ch = curl_init(FIPE_BASE_URL . $caminho);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $erro = curl_error($ch);
    curl_close($ch);

    if ($body === false || $code !== 200 || $erro) {
        return null;
    }
    $dados = json_decode($body, true);
    return is_array($dados) ? $dados : null;
}
