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
