<?php
/**
 * Paginação simples e compartilhada pras listagens do admin — pedido
 * direto ("quantas negociações ficar na tela, já pensou nisso?"), e a
 * resposta honesta era não: `admin/index.php` (tela principal de
 * negociações) buscava TODAS as oportunidades ativas de uma vez, sem
 * limite nenhum; `admin/clientes.php` tinha um `LIMIT 100` sem paginação
 * NENHUMA — bug real achado no meio do caminho: passado o 100º cliente
 * (por `created_at DESC`), os mais antigos simplesmente sumiam da tela,
 * sem aviso nenhum de que existiam mais.
 */

const ITENS_POR_PAGINA_PADRAO = 25;

/** Lê a página atual da URL (?pagina=N), sempre >= 1. */
function paginaAtual(): int {
    $p = (int)($_GET['pagina'] ?? 1);
    return $p > 0 ? $p : 1;
}

/** OFFSET pra usar na query, a partir da página atual. */
function paginacaoOffset(int $porPagina = ITENS_POR_PAGINA_PADRAO): int {
    return (paginaAtual() - 1) * $porPagina;
}

/**
 * Renderiza os controles de "anterior/próxima" + "página X de Y",
 * preservando os outros parâmetros da URL atual (busca, etapa, etc — tudo
 * que já está em $_GET, exceto 'pagina'). Não desenha nada se só tiver 1
 * página (nada pra navegar).
 */
function renderPaginacao(int $totalRegistros, int $porPagina = ITENS_POR_PAGINA_PADRAO): void {
    $totalPaginas = max(1, (int)ceil($totalRegistros / $porPagina));
    $atual = min(paginaAtual(), $totalPaginas);
    if ($totalPaginas <= 1) return;

    $paramsBase = $_GET;
    unset($paramsBase['pagina']);

    $montarUrl = function (int $pagina) use ($paramsBase): string {
        $params = $paramsBase;
        $params['pagina'] = $pagina;
        return '?' . http_build_query($params);
    };

    echo '<div class="paginacao">';
    echo $atual > 1 ? '<a href="' . e($montarUrl($atual - 1)) . '">← Anterior</a>' : '<span></span>';
    echo '<span>Página ' . $atual . ' de ' . $totalPaginas . ' (' . $totalRegistros . ' no total)</span>';
    echo $atual < $totalPaginas ? '<a href="' . e($montarUrl($atual + 1)) . '">Próxima →</a>' : '<span></span>';
    echo '</div>';
}
