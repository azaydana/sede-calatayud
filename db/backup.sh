#!/bin/bash
# ASGBD · W6: copia de PostgreSQL + prueba de restauración (test 20).
# Uso: ./db/backup.sh  (deja db/backups/sede-AAAAMMDD-HHMM.sql.gz)
set -euo pipefail
cd "$(dirname "$0")/.."
set -a; source .env; set +a
TS=$(date +%Y%m%d-%H%M)
OUT="db/backups/sede-$TS.sql.gz"
mkdir -p db/backups
docker compose exec -T db pg_dump -U "$DB_USUARIO" -d "$DB_NOMBRE" | gzip > "$OUT"
echo "OK $OUT ($(du -h "$OUT" | cut -f1))"
echo "Probar restore: ./db/restore-test.sh $OUT"
