<?php
/**
 * Feriados nacionais (20/11/2026, "usa API de feriados, tem no JurídicoSaaS" /
 * "pode não contabilizar falta"). Mesmo padrão do repo irmão: BrasilAPI
 * (/api/feriados/v1/{ano}, sem chave) + cache + fallback local. Diferenças:
 * cache em `config` (padrão "timestamp|json" deste projeto) e fallback com a
 * Páscoa calculada (sem tabela de anos fixos), então nunca fica desatualizado.
 * Só nacionais — municipal/estadual (ex.: aniversário de Barueri) não entram.
 */

require_once __DIR__ . '/db.php';

const FERIADOS_CACHE_TTL = 30 * 86400;

function _feriadosPascoa(int $ano): string {
    // Algoritmo de Meeus/Jones/Butcher (calendário gregoriano)
    $a = $ano % 19; $b = intdiv($ano, 100); $c = $ano % 100; $d = intdiv($b, 4); $e = $b % 4;
    $f = intdiv($b + 8, 25); $g = intdiv($b - $f + 1, 3); $h = (19 * $a + $b - $d - $g + 15) % 30;
    $i = intdiv($c, 4); $k = $c % 4; $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7; $m = intdiv($a + 11 * $h + 22 * $l, 451);
    $mes = intdiv($h + $l - 7 * $m + 114, 31); $dia = (($h + $l - 7 * $m + 114) % 31) + 1;
    return sprintf('%04d-%02d-%02d', $ano, $mes, $dia);
}

function _feriadosFallback(int $ano): array {
    $out = [];
    foreach (['01-01' => 'Confraternização Universal', '04-21' => 'Tiradentes', '05-01' => 'Dia do Trabalho',
              '09-07' => 'Independência do Brasil', '10-12' => 'Nossa Senhora Aparecida', '11-02' => 'Finados',
              '11-15' => 'Proclamação da República', '11-20' => 'Dia da Consciência Negra', '12-25' => 'Natal'] as $md => $n) {
        $out["$ano-$md"] = $n;
    }
    $p = strtotime(_feriadosPascoa($ano));
    $out[date('Y-m-d', $p - 47 * 86400)] = 'Carnaval';
    $out[date('Y-m-d', $p - 2 * 86400)] = 'Sexta-feira Santa';
    $out[date('Y-m-d', $p + 60 * 86400)] = 'Corpus Christi';
    ksort($out);
    return $out;
}

function _feriadosFetch(int $ano): array {
    if (!function_exists('curl_init')) return [];
    $ch = curl_init("https://brasilapi.com.br/api/feriados/v1/{$ano}");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 4, CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_FOLLOWLOCATION => true, CURLOPT_USERAGENT => 'FastcarCRM/1.0']);
    $raw = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if (!$raw || $http !== 200) return [];
    $data = json_decode($raw, true);
    if (!is_array($data)) return [];
    $out = [];
    foreach ($data as $f) {
        $d = substr((string)($f['date'] ?? ''), 0, 10);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) $out[$d] = (string)($f['name'] ?? 'Feriado');
    }
    return $out;
}

/** Feriados do ano: ['AAAA-MM-DD' => 'nome']. Nunca lança. */
function feriadosDoAno(int $ano): array {
    static $mem = [];
    if (isset($mem[$ano])) return $mem[$ano];
    $chave = "feriados_cache_{$ano}";
    try {
        $c = (string)(getConfig($chave) ?? '');
        if ($c !== '' && str_contains($c, '|')) {
            [$ts, $json] = explode('|', $c, 2);
            $dados = json_decode($json, true);
            if (is_array($dados) && $dados && time() - (int)$ts < FERIADOS_CACHE_TTL) return $mem[$ano] = $dados;
        }
    } catch (Throwable $e) {}
    $dados = _feriadosFetch($ano);
    $daApi = (bool)$dados;
    if (!$dados) $dados = _feriadosFallback($ano);
    // Só grava cache do que veio da API; fallback é recalculado de graça e tenta a API de novo depois.
    if ($daApi) {
        try { setConfig($chave, time() . '|' . json_encode($dados, JSON_UNESCAPED_UNICODE)); } catch (Throwable $e) {}
    }
    return $mem[$ano] = $dados;
}

const FERIADO_TIPOS = ['nacional' => 'Nacional', 'estadual' => 'Estadual', 'municipal' => 'Municipal', 'empresa' => 'Folga da empresa'];

/**
 * Feriados cadastrados à mão (20/11/2026, "tipo de feriado"): estadual,
 * municipal (ex.: aniversário de Barueri) ou folga da empresa. Ficam em
 * config.feriados_locais (JSON): [{"data":"MM-DD" (todo ano) ou "AAAA-MM-DD", "nome", "tipo"}].
 */
function feriadosLocais(): array {
    $v = json_decode((string)(getConfig('feriados_locais') ?? ''), true);
    return is_array($v) ? array_values(array_filter($v, fn($f) => is_array($f) && !empty($f['data']) && !empty($f['nome']))) : [];
}

function feriadosLocaisSalvar(array $lista): void {
    usort($lista, fn($a, $b) => strcmp(substr($a['data'], -5), substr($b['data'], -5)) ?: strcmp($a['data'], $b['data']));
    setConfig('feriados_locais', json_encode(array_values($lista), JSON_UNESCAPED_UNICODE));
}

/** Feriado nessa data: ['nome','tipo'] ou null. Cadastrado à mão vence o nacional. */
function feriadoInfo(string $data): ?array {
    if (!preg_match('/^(\d{4})-(\d{2}-\d{2})/', $data, $m)) return null;
    $d = substr($data, 0, 10);
    foreach (feriadosLocais() as $f) {
        if ($f['data'] === $d || $f['data'] === $m[2]) {
            return ['nome' => (string)$f['nome'], 'tipo' => isset(FERIADO_TIPOS[$f['tipo'] ?? '']) ? $f['tipo'] : 'municipal'];
        }
    }
    $n = feriadosDoAno((int)$m[1])[$d] ?? null;
    return $n !== null ? ['nome' => $n, 'tipo' => 'nacional'] : null;
}

/** "Finados (nacional)" — nome com o tipo, ou null. */
function feriadoNome(string $data): ?string {
    $f = feriadoInfo($data);
    return $f ? $f['nome'] . ' (' . mb_strtolower(FERIADO_TIPOS[$f['tipo']]) . ')' : null;
}

function isFeriado(string $data): bool {
    return feriadoInfo($data) !== null;
}

/** Todos os feriados do ano (nacionais + cadastrados), ordenados: [['data','nome','tipo','local'=>bool,'idx'=>?int]]. */
function feriadosListaAno(int $ano): array {
    $out = [];
    foreach (feriadosDoAno($ano) as $d => $n) $out[$d] = ['data' => $d, 'nome' => $n, 'tipo' => 'nacional', 'local' => false, 'idx' => null];
    foreach (feriadosLocais() as $i => $f) {
        $d = strlen($f['data']) === 5 ? "$ano-{$f['data']}" : $f['data'];
        if (substr($d, 0, 4) !== (string)$ano) continue;
        $out[$d] = ['data' => $d, 'nome' => $f['nome'], 'tipo' => $f['tipo'] ?? 'municipal', 'local' => true, 'idx' => $i, 'todo_ano' => strlen($f['data']) === 5];
    }
    ksort($out);
    return array_values($out);
}
