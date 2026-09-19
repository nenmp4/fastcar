#!/bin/bash
#
# install/setup_crontab.sh — Fastcar CRM
#
# Cadastra TODOS os cron jobs do projeto de uma vez via `crontab`, em vez de
# configurar um por um na mão. Mesmo padrão do JurídicoSaaS. Útil também
# numa migração de hospedagem: clona o repo no host novo, roda este script,
# e os crons já ficam configurados.
#
# USO (no terminal da VPS, com o PHP do sistema no PATH):
#   bash install/setup_crontab.sh
#
# O script é IDEMPOTENTE: se rodar de novo, não duplica as linhas — remove
# as antigas marcadas com "# fastcar-cron" e recoloca as atuais. Qualquer
# cron job que já exista e NÃO seja deste sistema é preservado.
#
# ⚠️ ESTE ARQUIVO É A FONTE DE VERDADE DOS HORÁRIOS DE CRON DO PROJETO. Toda
# vez que um cron for criado ou o horário mudar, atualize a linha aqui E a
# tabela em CLAUDE.md — os dois devem ficar sempre sincronizados.

set -e

BASE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PHP_BIN="$(command -v php || true)"
if [ -z "$PHP_BIN" ]; then
  echo "❌ php não encontrado no PATH. Instale o PHP (ver install/SETUP_VPS.md) antes de rodar este script."
  exit 1
fi

MARK="# fastcar-cron"

CRON_LINES=$(cat <<EOF
*/30 * * * * $PHP_BIN $BASE_DIR/cron/followup.php >> $BASE_DIR/storage/logs/followup.log 2>&1 $MARK
*/30 * * * * $PHP_BIN $BASE_DIR/cron/zapsign_sync.php >> $BASE_DIR/storage/logs/zapsign_sync.log 2>&1 $MARK
*/30 * * * * $PHP_BIN $BASE_DIR/cron/asaas_sync.php >> $BASE_DIR/storage/logs/asaas_sync.log 2>&1 $MARK
30 19 * * * $PHP_BIN $BASE_DIR/cron/resumo_produtividade.php >> $BASE_DIR/storage/logs/resumo_produtividade.log 2>&1 $MARK
0 5 * * * $PHP_BIN $BASE_DIR/cron/lancamentos_fixos.php >> $BASE_DIR/storage/logs/lancamentos_fixos.log 2>&1 $MARK
0 2,8,13,18 * * * $PHP_BIN $BASE_DIR/cron/backup_db.php >> $BASE_DIR/storage/logs/backup_db.log 2>&1 $MARK
0 3 * * * $PHP_BIN $BASE_DIR/cron/backup.php >> $BASE_DIR/storage/logs/backup.log 2>&1 $MARK
0 4 * * * $PHP_BIN $BASE_DIR/cron/backup_drive.php >> $BASE_DIR/storage/logs/backup_drive.log 2>&1 $MARK
* * * * * [ -f $BASE_DIR/storage/.deploy ] && bash $BASE_DIR/install/aplicar_deploy.sh $MARK
EOF
)

mkdir -p "$BASE_DIR/storage/logs"

CURRENT=$(crontab -l 2>/dev/null | grep -v "$MARK" || true)

{
  echo "$CURRENT"
  echo "$CRON_LINES"
} | grep -v '^\s*$' | crontab -

echo "✅ 9 cron jobs do Fastcar CRM instalados/atualizados (8 jobs + o puxador de deploy do webhook)."
echo "Conferir com: crontab -l"
