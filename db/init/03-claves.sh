#!/bin/sh
# Reto 1 · Contraseñas de los roles y rol propio de la web (C4, C11)
#
# Las claves no van en Git: llegan al contenedor desde .env (compose.yaml, servicio
# db, environment). Este guion las pone con ALTER ROLE, que las guarda en
# scram-sha-256 (password_encryption de db/conf/postgresql.conf).
#
# Se ejecuta solo al crear el volumen, después de 01 y 02. instalar.sh lo vuelve
# a lanzar en cada ejecución: si se cambia una clave en .env, se aplica.
#   docker compose exec -T db sh /docker-entrypoint-initdb.d/03-claves.sh
#
# El entrypoint de la imagen hace «source» de los .sh no ejecutables: nada de exit.

set -eu

for V in DB_NOMBRE WEB_USUARIO WEB_CLAVE PANEL_CLAVE RESP_DEMO_CLAVE; do
    eval "VALOR=\${$V:-}"
    if [ -z "$VALOR" ]; then
        echo "03-claves.sh: falta $V en el entorno del contenedor (.env y compose.yaml)" >&2
        false
    fi
done

# Las variables viajan como variables de psql (:'x' las pone entre comillas
# y escapa lo que haga falta): nada de pegar la clave dentro del SQL.
psql -v ON_ERROR_STOP=1 --no-psqlrc -q \
     --username "$POSTGRES_USER" --dbname postgres \
     -v web="$WEB_USUARIO" -v web_clave="$WEB_CLAVE" \
     -v panel_clave="$PANEL_CLAVE" -v resp_clave="$RESP_DEMO_CLAVE" \
     -v base="$DB_NOMBRE" <<'SQL'
-- Rol de la web: entra solo en su base y solo lee clientes (D3)
SELECT format('CREATE ROLE %I LOGIN CONNECTION LIMIT 20', :'web')
WHERE NOT EXISTS (SELECT FROM pg_roles WHERE rolname = :'web')
\gexec

ALTER ROLE :"web"     PASSWORD :'web_clave';
ALTER ROLE panel      PASSWORD :'panel_clave';
ALTER ROLE resp_demo  PASSWORD :'resp_clave';

REVOKE CONNECT, TEMPORARY ON DATABASE :"base" FROM PUBLIC;
GRANT CONNECT ON DATABASE :"base" TO :"web";

\connect :"base"
GRANT USAGE ON SCHEMA public TO :"web";
GRANT SELECT ON clientes TO :"web";
SQL

echo "03-claves.sh: claves de $WEB_USUARIO, panel y resp_demo puestas"
