#!/bin/bash
#
# install/aplicar_deploy.sh — Fastcar CRM
#
# Aplica um deploy pendente: git pull -> migração de banco -> smoke test.
# Chamado pela linha de crontab quando api/webhook_deploy.php agenda
# storage/.deploy (ver install/setup_crontab.sh) — nunca disparado direto
# pela requisição HTTP do webhook, só pelo cron (decoupling de propósito,
# mesmo padrão do JurídicoSaaS).
#
# Rodar tests/smoke.php como último passo garante que um deploy que quebra
# alguma coisa (erro de sintaxe, guard de regressão, coluna de schema
# faltando) nunca fica em produção sem ninguém perceber — se falhar, manda
# alerta por WhatsApp pros números de notificação já configurados
# (config.notificacao_leads_whatsapp, mesmo usado pra lead novo) em vez de
# só logar silenciosamente. migrar.php roda sempre, mesmo sem migração
# nova nenhuma dessa vez — é idempotente, o custo de rodar à toa é zero.
#
# Uso manual (não precisa esperar o cron):
#   bash install/aplicar_deploy.sh

set -o pipefail # sem isso, "cmd | tee" sempre "sucede" (o status vira o do tee, não o da smoke.php) e a detecção de falha do smoke abaixo nunca dispararia

BASE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$BASE_DIR" || exit 1
PHP_BIN="$(command -v php)"
LOG="$BASE_DIR/storage/logs/deploy_$(date +%Y-%m).log"
mkdir -p "$BASE_DIR/storage/logs"

# tee (não só >>) pra também mostrar na hora quando rodado manualmente via
# SSH — no cron (sem terminal) o tee simplesmente não tem pra onde mostrar
# e só o log conta mesmo.
echo "[$(date '+%d/%m/%Y %H:%M:%S')] Aplicando deploy..." | tee -a "$LOG"
git pull origin main 2>&1 | tee -a "$LOG"
"$PHP_BIN" install/migrar.php 2>&1 | tee -a "$LOG"

if "$PHP_BIN" tests/smoke.php 2>&1 | tee -a "$LOG"; then
    echo "[$(date '+%d/%m/%Y %H:%M:%S')] ✅ Deploy aplicado, smoke OK." | tee -a "$LOG"
else
    echo "[$(date '+%d/%m/%Y %H:%M:%S')] ❌ Deploy aplicado, mas SMOKE FALHOU — verificar $LOG imediatamente." | tee -a "$LOG"
    "$PHP_BIN" -r '
        require "'"$BASE_DIR"'/includes/db.php";
        require "'"$BASE_DIR"'/includes/whatsapp_config.php";
        $numeros = array_filter(array_map("trim", explode(",", getConfig("notificacao_leads_whatsapp") ?: "")));
        foreach ($numeros as $n) {
            zapiEnviarTexto($n, "🚨 Deploy da Fastcar CRM foi aplicado mas o smoke test FALHOU — verificar storage/logs/deploy_*.log na VPS imediatamente. Código em produção pode estar quebrado.");
        }
    ' 2>&1 | tee -a "$LOG"
fi

rm -f "$BASE_DIR/storage/.deploy"
