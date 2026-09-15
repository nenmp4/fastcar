<?php
/**
 * Integração com a API FIPE — duas partes independentes.
 *
 * 1. BrasilAPI (v1, gratuita, sem token) — decisão com o Jean
 *    (12/09/2026): usada só pra VALIDAR/NORMALIZAR a marca digitada pelo
 *    consultor (fipeValidarMarca()/fipeListarMarcas()); não tem busca de
 *    modelo/ano por marca.
 * 2. PlacaFIPE (api.placafipe.com.br, com token) — busca de valor FIPE
 *    pela PLACA do veículo, adicionada 15/09/2026 (José/Jean: "vamos
 *    colocar em produção" → "vamos integrar", depois de eu confirmar o
 *    provedor certo com a doc real). Funções `placafipe*` abaixo, usadas
 *    em `admin/oportunidade.php` (campo de placa + lista de
 *    correspondências) via `admin/fipe_ajax.php`. Configurada em
 *    Configurações → FIPE (`config.placafipe_token`) — sem token, essas
 *    funções simplesmente não respondem nada, nunca travam a tela.
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
// PlacaFIPE (api.placafipe.com.br, com token) — busca de valor FIPE por
// PLACA do veículo. Adicionado 15/09/2026 (José/Jean: "vamos colocar em
// produção", depois "vamos integrar" confirmando a PlacaFIPE especificamente
// — troca de provedor no meio do processo: a implementação original desta
// seção usava a Parallelum FIPE v2 (auth por header, endpoints
// marca→modelo→ano), nunca chegou a ir pro ar e foi substituída inteira
// por esta, depois do usuário mandar a documentação real da PlacaFIPE.
//
// A PlacaFIPE tem DOIS caminhos de busca:
//   1. Por placa (`getplaca`/`getplacafipe`) — request/response 100%
//      confirmados contra a doc oficial (exemplo real de resposta colado
//      pelo usuário), é o que está implementado aqui.
//   2. Cascata manual (`ConsultarTabelaDeReferencia` →
//      `ConsultarMarcas` → `ConsultarModelos` → `ConsultarAnoModelo` →
//      `ConsultarValorComTodosParametros`, com `codigoTipoVeiculo` pra
//      escolher carro/moto/caminhão) — útil pra quando ainda não tem
//      placa, ou a busca por placa não encontra o veículo. NÃO
//      implementado ainda: a documentação disponível só mostra o formato
//      do REQUEST desses 4 passos intermediários, nunca um exemplo de
//      RESPOSTA — implementar às cegas repetiria o mesmo erro da tentativa
//      anterior com a Parallelum (chutar nome de campo que a API real não
//      usa). Fica pendente até ter um exemplo real de resposta.
//
// Auth: token vai no CORPO de toda requisição (POST), nunca em header —
// diferente de toda outra integração deste projeto (Z-API, Gemini, etc),
// que usam header ou querystring.
//
// Nunca confirmado contra a API real (token real nunca testado por este
// ambiente — sandbox de dev bloqueia acesso externo), só a estrutura da
// doc oficial + exemplo de resposta real colado pelo usuário. Separado de
// fipeValidarMarca()/fipeListarMarcas() (BrasilAPI v1, sem token) de
// propósito — v1 continua funcionando normalmente sem token da PlacaFIPE
// configurado, as duas integrações nunca se misturam.
// ─────────────────────────────────────────────────────────────

if (!defined('PLACAFIPE_BASE_URL')) {
    define('PLACAFIPE_BASE_URL', 'https://api.placafipe.com.br');
}
const PLACAFIPE_CACHE_TTL = 24 * 3600; // evita gastar cota de novo só por recarregar a página no mesmo dia (planos são limitados por requisição — ver doc "Custos")

/** Token da API PlacaFIPE — obrigatório pra qualquer chamada funcionar. */
function placafipeToken(): string {
    return getConfig('placafipe_token') ?: '';
}

/**
 * POST autenticado na PlacaFIPE — token sempre no corpo (nunca header).
 * Retorna null sem token configurado ou em qualquer falha de transporte
 * (nunca lança) — quem chama decide o que fazer com `codigo`/`msg` do
 * corpo da resposta.
 */
function placafipePost(string $endpoint, array $params = []): ?array {
    $token = placafipeToken();
    if (!$token) return null;
    try {
        $ch = curl_init(PLACAFIPE_BASE_URL . '/' . ltrim($endpoint, '/'));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode(array_merge(['token' => $token], $params)),
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $erro = curl_error($ch);
        curl_close($ch);
        if ($body === false || $code >= 400 || $erro) return null;
        $dados = json_decode($body, true);
        return is_array($dados) ? $dados : null;
    } catch (Throwable $e) {
        return null;
    }
}

/** Só letras/números, formato Mercosul ou antigo (ex: ABC1D23, ABC1234) — nunca manda placa mal formatada pra API, economiza cota. */
function placafipeLimparPlaca(string $placa): string {
    $limpa = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $placa) ?? '');
    return preg_match('/^[A-Z]{3}\d[A-Z0-9]\d{2}$/', $limpa) ? $limpa : '';
}

/**
 * Consulta o valor FIPE pela placa (`getplacafipe`) — retorna o corpo
 * decodificado da resposta (`codigo`, `msg`, `fipe` com as correspondências
 * possíveis, `informacoes_veiculo`) ou null se a placa for inválida, sem
 * token, ou a chamada falhar de transporte. Quem chama SEMPRE precisa
 * checar `$resp['codigo'] === 1` antes de usar `fipe`/`informacoes_veiculo`
 * — código de retorno != 1 é erro (placa não encontrada, token inválido,
 * cota estourada etc — a doc de "Códigos de retorno" com a lista completa
 * não foi confirmada ainda, por isso trata qualquer coisa != 1 como falha
 * genérica e mostra o `msg` que a própria API manda, em vez de tentar
 * adivinhar o significado de cada código).
 *
 * Cache de 24h por placa — plano tem cota limitada de requisições (doc
 * "Custos"), não faz sentido gastar cota de novo só porque o consultor
 * recarregou a página da oportunidade no mesmo dia. Só cacheia resposta
 * de SUCESSO (codigo=1); erro nunca fica em cache, pra não travar um
 * "token inválido" temporário (ex: acabou de configurar) pelo resto do dia.
 */
function placafipeConsultarPorPlaca(string $placa): ?array {
    $placaLimpa = placafipeLimparPlaca($placa);
    if ($placaLimpa === '') return null;

    $cacheKey = 'placafipe_cache_' . $placaLimpa;
    $cache = getConfig($cacheKey);
    if ($cache && str_contains($cache, '|')) {
        [$timestamp, $json] = explode('|', $cache, 2);
        $decodificado = json_decode($json, true);
        if (is_array($decodificado) && (time() - (int)$timestamp) < PLACAFIPE_CACHE_TTL) {
            return $decodificado;
        }
    }

    $dados = placafipePost('getplacafipe', ['placa' => $placaLimpa]);
    if ($dados === null) return null;
    if ((int)($dados['codigo'] ?? 0) === 1) {
        setConfig($cacheKey, time() . '|' . json_encode($dados));
    }
    return $dados;
}
