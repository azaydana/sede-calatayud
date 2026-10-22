#!/bin/bash
# ASGBD · W6: restaura la copia en una BD vacía de prueba y verifica filas (test 20).
# Uso: ./db/restore-test.sh db/backups/sede-XXX.sql.gz
set -euo pipefail
cd "$(dirname "$0")/.."
set -a; source .env; set +a
F="${1:?indica el .sql.gz}"
docker compose exec -T db psql -U "$DB_USUARIO" -d postgres -c "DROP DATABASE IF EXISTS sede_restore;"
docker compose exec -T db psql -U "$DB_USUARIO" -d postgres -c "CREATE DATABASE sede_restore OWNER $DB_USUARIO;"
zcat "$F" | docker compose exec -T db psql -U "$DB_USUARIO" -d sede_restore -q
docker compose exec -T db psql -U "$DB_USUARIO" -d sede_restore -c "SELECT count(*) AS filas_restauradas FROM clientes;"
docker compose exec -T db psql -U "$DB_USUARIO" -d postgres -c "DROP DATABASE sede_restore;"
echo "RESTORE OK"
