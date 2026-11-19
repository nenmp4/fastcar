<?php
/**
 * Card do cartão de ponto na tela inicial de cada perfil (20/11/2026,
 * "coloca card na tela inicial do pontos"). Cada um vê o próprio ponto de
 * hoje; super admin/supervisor veem também o resumo do dia da equipe.
 */
(static function (): void {
    try {
        $uid = (int)($_SESSION['admin_id'] ?? 0);
        if (!$uid || !function_exists('pontoRegistrosDoDia')) return;
        $gestor = perfilVeTudo();
        $meu = pontoRegistrosDoDia(date('Y-m-d'))[$uid] ?? null;
        $res = $gestor ? pontoResumoDia() : null;
        $h = fn(?string $dt) => $dt ? date('H:i', strtotime($dt)) : '—';
        ?>
        <div class="card ponto-card" style="border-left:4px solid var(--azul);display:flex;flex-wrap:wrap;gap:10px 24px;align-items:center;justify-content:space-between;padding:12px 16px">
            <div>
                <strong>🕒 Seu ponto hoje:</strong>
                <?php if ($meu && (int)$meu['falta'] === 1): ?>
                    <span class="badge badge-atraso">❌ falta marcada</span>
                <?php elseif ($meu && $meu['entrada']): ?>
                    entrada <strong><?= e($h($meu['entrada'])) ?></strong>
                    · <?= $meu['saida'] ? 'saída' : 'último acesso' ?> <strong><?= e($h(pontoFim($meu))) ?></strong>
                    · <?= e(pontoFormatarMinutos(pontoMinutosTrabalhados($meu))) ?>
                <?php else: ?>
                    <span class="badge">sem registro</span>
                <?php endif; ?>
                <a class="btn-texto" href="/admin/ponto.php<?= $gestor ? '?usuario=' . $uid . '#mes' : '' ?>">ver meu mês →</a>
            </div>
            <?php if ($res): ?>
            <div>
                <strong>Equipe hoje:</strong>
                <a href="/admin/ponto.php#dia" title="<?= e(implode(', ', $res['presentes'])) ?>"><span class="badge badge-ok">✅ <?= count($res['presentes']) ?> presente(s)</span></a>
                <a href="/admin/ponto.php#dia" title="<?= e(implode(', ', $res['faltas'])) ?>"><span class="badge badge-atraso">❌ <?= count($res['faltas']) ?> falta(s)</span></a>
                <a href="/admin/ponto.php#dia" title="<?= e(implode(', ', $res['sem_registro'])) ?>"><span class="badge badge-aviso">⏳ <?= count($res['sem_registro']) ?> sem registro</span></a>
                <?php if ($res['sem_registro'] && count($res['sem_registro']) <= 6): ?>
                    <br><small style="color:var(--texto-suave)">Ainda não entraram: <?= e(implode(', ', $res['sem_registro'])) ?></small>
                <?php endif; ?>
                <a class="btn" href="/admin/ponto.php#dia" style="margin-left:6px">🕒 Cartão de ponto</a>
            </div>
            <?php endif; ?>
        </div>
        <?php
    } catch (Throwable $e) {
        // card nunca derruba a tela inicial
    }
})();
