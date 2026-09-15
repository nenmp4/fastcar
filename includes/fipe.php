<?php
/**
 * Integração com a API FIPE — duas partes independentes.
 *
 * 1. BrasilAPI (v1, gratuita, sem token) — decisão com o Jean
 *    (12/09/2026): usada só pra VALIDAR/NORMALIZAR a marca digitada pelo
 *    consultor (fipeValidarMarca()/fipeListarMarcas()); não tem busca de
 *    modelo/ano por marca.
 * 2. Parallelum FIPE v2 (com token) — busca completa
 *    marca→modelo→ano→valor, adicionada 15/09/2026 (José/Jean: "vamos
 *    colocar em produção"). Funções `fipeV2*` abaixo, usadas em
 *    `admin/oportunidade.php` (selects em cascata) via
 *    `admin/fipe_ajax.php`. Configurada em Configurações → FIPE
 *    (`config.fipe_v2_token`) — sem token, essas funções simplesmente não
 *    respondem nada, nunca travam a tela.
 *
 * Cache no padrão já documentado no CLAUDE.md: `config` chave/valor,
 * valor = "timestamp|json" — evita bater na API toda hora.
 *
 * Nunca lança exceção nem bloqueia o fluxo principal: se a FIPE estiver
 * fora do ar, a validação/busca simplesmente fica indisponível — o
 * cadastro do cliente (regra do Jean) nunca pode depender de um serviço
 * externo.
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

// ─────────────────────────────────────────────────────────────
// FIPE v2 (Parallelum, com token) — busca completa
// marca→modelo→ano→valor. Adicionado 15/09/2026 (José/Jean: "vamos
// colocar em produção" — confirmado que era pra implementar a busca
// completa oferecida). Construído a partir da documentação pública
// (fipe.parallelum.com.br/doc — ambiente de dev bloqueia fetch direto do
// domínio) e testado só contra servidor fake local; nunca confirmado
// contra a API real (mesma ressalva de todo provedor externo deste
// projeto, ver CLAUDE.md "a validar em produção"). Separado de
// fipeValidarMarca()/fipeListarMarcas() (BrasilAPI v1, sem token) de
// propósito — v1 continua funcionando normalmente mesmo sem token v2
// configurado, as duas integrações nunca se misturam.
// ─────────────────────────────────────────────────────────────

if (!defined('FIPE_V2_BASE_URL')) {
    define('FIPE_V2_BASE_URL', 'https://fipe.parallelum.com.br/api/v2');
}

/** Token da API FIPE v2 (Parallelum) — obrigatório pra qualquer chamada v2 funcionar. */
function fipeV2Token(): string {
    return getConfig('fipe_v2_token') ?: '';
}

/** GET autenticado na FIPE v2 — retorna null sem token ou em qualquer falha (nunca lança). */
function fipeV2Requisitar(string $caminho): ?array {
    $token = fipeV2Token();
    if (!$token) return null;
    try {
        $ch = curl_init(FIPE_V2_BASE_URL . $caminho);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'X-Subscription-Token: ' . $token],
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $erro = curl_error($ch);
        curl_close($ch);
        if ($body === false || $code !== 200 || $erro) return null;
        $dados = json_decode($body, true);
        return is_array($dados) ? $dados : null;
    } catch (Throwable $e) {
        return null;
    }
}

/** Só dígitos/letras/hífen — mesmo formato de código que a FIPE v2 usa (ex: "59", "2024-1"). */
function fipeV2LimparCodigo(string $codigo): string {
    return preg_replace('/[^a-zA-Z0-9\-]/', '', $codigo) ?? '';
}

/** Cache genérico de leitura pra endpoints v2 (marca/modelo/ano mudam raro) — mesmo padrão "timestamp|json" já documentado no CLAUDE.md. */
function fipeV2Cache(string $cacheKey, string $caminho): array {
    $cache = getConfig($cacheKey);
    if ($cache && str_contains($cache, '|')) {
        [$timestamp, $json] = explode('|', $cache, 2);
        $decodificado = json_decode($json, true);
        if (is_array($decodificado) && (time() - (int)$timestamp) < FIPE_CACHE_TTL) {
            return $decodificado;
        }
    }
    $dados = fipeV2Requisitar($caminho);
    if ($dados === null) {
        // Sem resposta nova (API fora do ar ou sem token) — cai pro cache
        // vencido se existir, senão devolve vazio (nunca quebra a tela).
        if ($cache && str_contains($cache, '|')) {
            [, $jsonVelho] = explode('|', $cache, 2);
            $decodificado = json_decode($jsonVelho, true);
            return is_array($decodificado) ? $decodificado : [];
        }
        return [];
    }
    setConfig($cacheKey, time() . '|' . json_encode($dados));
    return $dados;
}

/** Lista marcas de carro (FIPE v2) — [{code, name}, ...]. */
function fipeV2ListarMarcas(): array {
    return fipeV2Cache('fipe_v2_marcas_cache', '/cars/brands');
}

/** Lista modelos de uma marca — [{code, name}, ...]. */
function fipeV2ListarModelos(string $marcaCode): array {
    $marcaCode = fipeV2LimparCodigo($marcaCode);
    if ($marcaCode === '') return [];
    return fipeV2Cache("fipe_v2_modelos_cache_{$marcaCode}", "/cars/brands/{$marcaCode}/models");
}

/** Lista anos/combustível de um modelo — [{code, name}, ...] (ex: name="2024 Gasolina"). */
function fipeV2ListarAnos(string $marcaCode, string $modeloCode): array {
    $marcaCode = fipeV2LimparCodigo($marcaCode);
    $modeloCode = fipeV2LimparCodigo($modeloCode);
    if ($marcaCode === '' || $modeloCode === '') return [];
    return fipeV2Cache("fipe_v2_anos_cache_{$marcaCode}_{$modeloCode}", "/cars/brands/{$marcaCode}/models/{$modeloCode}/years");
}

/**
 * Valor FIPE final pra marca+modelo+ano escolhidos — retorna null se
 * faltar código ou a chamada falhar. Sem cache de propósito: a FIPE
 * atualiza a tabela todo mês (`referenceMonth` na resposta), não faz
 * sentido guardar um valor velho por 7 dias como marca/modelo/ano fazem.
 */
function fipeV2BuscarValor(string $marcaCode, string $modeloCode, string $anoCode): ?array {
    $marcaCode = fipeV2LimparCodigo($marcaCode);
    $modeloCode = fipeV2LimparCodigo($modeloCode);
    $anoCode = fipeV2LimparCodigo($anoCode);
    if ($marcaCode === '' || $modeloCode === '' || $anoCode === '') return null;
    return fipeV2Requisitar("/cars/brands/{$marcaCode}/models/{$modeloCode}/years/{$anoCode}");
}

/** Converte o preço no formato da resposta FIPE ("R$ 45.000,00") pra float (45000.0). */
function fipeV2ParsearPreco(string $preco): ?float {
    $limpo = preg_replace('/[^0-9,]/', '', $preco) ?? '';
    $limpo = str_replace(',', '.', $limpo);
    return is_numeric($limpo) ? (float)$limpo : null;
}
