# db/ · ASGBD

Lo que hace que la base de datos nazca con su tabla, sus filas y su usuario sin
intervención (DS6). Ni copias con datos de personas ni contraseñas: las claves
llegan desde `.env`.

- `init/01-esquema.sql`: tabla `clientes` (≥20 filas, idempotente con
  `CREATE TABLE IF NOT EXISTS` y `ON CONFLICT DO NOTHING`). `compose.yaml` monta
  `db/init/` en `/docker-entrypoint-initdb.d/`: al crear el volumen la primera
  vez, la BD nace con todo.
- `backup.sh`: copia de PostgreSQL (W6), deja `db/backups/sede-*.sql.gz`.
- `restore-test.sh`: restaura la copia en una BD de prueba y
  verifica el número de filas (test 20).

En `compose.yaml` (servicio `db`): `postgres:16-alpine` (versión mayor fijada, D1),
solo en la red `trasera` (sin salida, D6), healthcheck con `pg_isready` para que
la web espere a que esté lista, y el usuario de la web solo ve su BD (D3).
Las copias están fuera de Git (`db/backups/` en `.gitignore`).

## Llevar los datos a otro servidor

Git no lleva los datos: un servidor nuevo nace con las filas de `init/`. Para
llevar los de otro:

```bash
# En el servidor de origen
./db/backup.sh                     # db/backups/sede-AAAAMMDD-HHMM.sql.gz
./forgejo/backup.sh                # forgejo/backups/forgejo-AAAAMMDD-HHMM.tar.gz
# Copiarlos al nuevo (scp) en las mismas carpetas del repositorio

# En el servidor nuevo, tras sudo ./instalar.sh
source .env
docker compose exec -T db psql -U "$DB_USUARIO" -d "$DB_NOMBRE" -c 'DROP TABLE IF EXISTS clientes;'
zcat db/backups/sede-*.sql.gz | docker compose exec -T db psql -U "$DB_USUARIO" -d "$DB_NOMBRE" -q
docker compose stop git
sudo rm -rf forgejo/data/* && sudo tar xzf forgejo/backups/forgejo-*.tar.gz -C forgejo/data
docker compose start git
```
