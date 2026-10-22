#!/bin/bash
# Optativo · F5: copia de Forgejo (el volumen es un bind: ./forgejo/data).
# Uso: ./forgejo/backup.sh  (deja forgejo/backups/forgejo-AAAAMMDD-HHMM.tar.gz)
set -euo pipefail
cd "$(dirname "$0")/.."
TS=$(date +%Y%m%d-%H%M)
OUT="forgejo/backups/forgejo-$TS.tar.gz"
mkdir -p forgejo/backups
tar czf "$OUT" -C forgejo/data .
echo "OK $OUT ($(du -h "$OUT" | cut -f1))"
echo "Restore (con el contenedor parado):"
echo "  rm -rf forgejo/data/* && tar xzf $OUT -C forgejo/data"
