# db/ · ASGBD

La base de datos de la sede: PostgreSQL 16 en el servicio `db` de `compose.yaml`. Dentro hay dos
bases:

| Base | Para quién | Qué tiene |
|---|---|---|
| `sede` (`DB_NOMBRE`) | La web, con el rol `web` | La tabla `clientes` (25 filas) |
| `bifi` | El panel (rol `panel`) y los responsables con `psql` (grupo `responsables`) | Proyectos, miembros, consumo y cuentas del BIFI (Reto 1) |

Ni copias con datos de personas ni contraseñas en Git: las claves llegan desde `.env`.

```text
db/
  conf/postgresql.conf          parámetros del servidor (RA2)
  conf/pg_hba.conf              quién entra, a qué base y desde dónde (RA2). Plantilla: ver abajo
  init/01-esquema.sql           base sede: tabla clientes y sus filas
  init/02-bifi.sql              roles, base bifi, tablas, reglas, vistas y datos del BIFI
  init/03-claves.sh             contraseñas desde .env y rol propio de la web
  clientes/pg_service.conf.ejemplo   conexión de un responsable con psql service=bifi
  backup.sh, restore-test.sh    copias (W6)
```

## Cómo nace

`compose.yaml` monta `db/init/` en `/docker-entrypoint-initdb.d/`. Con el volumen **vacío**, la
imagen ejecuta los tres ficheros en orden y la base nace con todo (DS6). Con un volumen que ya
existe no se ejecutan: por eso `instalar.sh` lanza `02-bifi.sql` y `03-claves.sh` en cada
ejecución. Los dos se pueden repetir sin romper nada (`IF NOT EXISTS`, `ON CONFLICT DO NOTHING`,
`CREATE OR REPLACE`).

```bash
# Lo mismo a mano, con la composición en marcha
source .env
docker compose exec -T db psql -v ON_ERROR_STOP=1 -U "$DB_USUARIO" -d postgres < db/init/02-bifi.sql
docker compose exec -T db sh /docker-entrypoint-initdb.d/03-claves.sh
```

Lo único que no se cambia así es la codificación y la colación de `bifi`: solo cuentan al crearla.

## Roles

| Rol | Qué es | Entra | Límite |
|---|---|---|---|
| `$DB_USUARIO` (`sede`) | Administrador (`POSTGRES_USER`, `admin_sede` en la teoría). Superusuario | Solo por el *socket*: `docker compose exec db psql -U sede` | Las 3 reservadas |
| `web` | La web. Solo `SELECT` en `clientes` | A `sede`, desde la red de Compose | 20 conexiones |
| `panel` | El panel del BIFI (ver `panel/README.md`) | A `bifi`, desde la red de Compose | 10 conexiones, sentencias de 5 s |
| `responsables` | Grupo, sin entrada (`NOLOGIN`). Los permisos van aquí | — | — |
| `resp_demo` | Un responsable (Marta), miembro de `responsables` | A `bifi`, desde otro equipo del aula | 2 conexiones, sentencias de 30 s |

La web ya no usa al administrador: con la regla «el administrador no entra por red», dejaría de
funcionar. Para dar de alta a otro responsable basta con meterlo en el grupo y en `cuenta_bd`, sin
tocar `pg_hba.conf`:

```sql
CREATE ROLE resp_jorge LOGIN CONNECTION LIMIT 2 IN ROLE responsables PASSWORD '…';
\c bifi
INSERT INTO cuenta_bd VALUES ('resp_jorge', 'jorge');
```

## `conf/postgresql.conf`

Solo lleva lo que cambiamos. Lo carga el `command:` del servicio (`-c config_file=…`), así que el
`postgresql.conf` que la imagen dejó en el volumen no se usa (C1). **Reiniciar** =
`docker compose restart db`. **Recargar** = `SELECT pg_reload_conf();`, sin cortar a nadie.

| Parámetro | Valor | Por qué | Se aplica al |
|---|---|---|---|
| `listen_addresses` | `'*'` | Escuchar en todas las interfaces **del contenedor**: la `trasera` (web y panel) y la `bd-aula` (puerto publicado). El valor por defecto, `localhost`, deja a todos fuera. Qué llega hasta el contenedor ya lo limita el `ports:` (solo la IP del aula) | reiniciar |
| `port` | `5432` | El de siempre: el que publica Compose | reiniciar |
| `hba_file` | `/etc/postgresql/pg_hba.conf` | `pg_hba.conf` fuera de `PGDATA`, montado desde `generado/db/` | reiniciar |
| `max_connections` | `50` | web 20 + panel 10 + responsables 10 (2 cada uno, hasta 5) + 3 reservadas + 7 de margen. Cada conexión es un proceso con su memoria: no se sube «por si acaso» | reiniciar |
| `superuser_reserved_connections` | `3` | Aunque esté lleno, el administrador puede entrar a arreglarlo | reiniciar |
| `password_encryption` | `scram-sha-256` | Las claves se guardan con un *hash* fuerte (C4). `md5` se puede reutilizar si alguien lo roba | recargar |
| `authentication_timeout` | `30s` | Quien no termina de autenticarse no ocupa plaza | recargar |
| `idle_in_transaction_session_timeout` | `5min` | Para todos: una transacción abierta sin hacer nada retiene bloqueos y frena el *autovacuum*. El panel tiene `10s` (por rol) | recargar |
| `tcp_keepalives_idle` | `300` | Detecta las conexiones muertas por un corte de red | recargar |
| `log_connections`, `log_disconnections` | `on` | Cada conexión deja su línea con usuario, base y origen: con eso se ven P1 a P6 | recargar |
| `log_line_prefix` | `'%m [%p] %q%u@%d %h '` | Hora, proceso, `usuario@base` y origen en cada línea del registro | recargar |
| `log_timezone`, `timezone` | `Europe/Madrid` | Horas del registro y de las sesiones en hora de Madrid | recargar |
| `datestyle` | `'ISO, DMY'` | Fechas `2026-11-25` y, si se escriben a mano, día/mes/año | recargar |
| `lc_messages` | `'C'` | Mensajes en inglés: los de la teoría y los que se encuentran al buscar | recargar |

Por rol y por base (en `02-bifi.sql`, se aplican en la siguiente conexión):

| Dónde | Parámetro | Por qué |
|---|---|---|
| `ALTER ROLE panel` | `statement_timeout = 5s` | Una consulta mal hecha del panel no bloquea el servidor (C6, P6) |
| `ALTER ROLE panel` | `idle_in_transaction_session_timeout = 10s` | El panel no deja transacciones abiertas (C6) |
| `ALTER ROLE resp_demo` | `statement_timeout = 30s` | Un responsable puede lanzar consultas más largas, pero no eternas |
| `ALTER ROLE responsables` | `idle_session_timeout = 1h` | Un `psql` olvidado abierto no ocupa una de sus dos plazas para siempre |
| `ALTER DATABASE bifi` | `timezone = Europe/Madrid` | C7 |
| `CREATE ROLE … CONNECTION LIMIT` | `panel` 10, `resp_demo` 2, `web` 20 | C5, P5 |

Para ver de dónde sale cada valor: `SELECT name, setting, source FROM pg_settings WHERE name = '…';`

## `conf/pg_hba.conf`

Es una **plantilla**: `instalar.sh` (paso 5) cambia las marcas y deja el resultado en
`generado/db/pg_hba.conf`, que es el que se monta. Así la red del aula sale de `sede.env` (y de
`sede.local.env` en otra red) y los nombres, de `.env`.

| Marca | Sale de | En la sede |
|---|---|---|
| `__ADMIN__` | `DB_USUARIO` (`.env`) | `sede` |
| `__DB_NOMBRE__` | `DB_NOMBRE` (`.env`) | `sede` |
| `__WEB_USUARIO__` | `WEB_USUARIO` (`.env`) | `web` |
| `__IP_AULA__` | `IP_AULA` (`sede.env`) | `192.168.6.118` |
| `__RED_AULA__` | `RED_AULA` (`sede.env`) | `192.168.6.0/24` |

Se lee de arriba abajo y **gana la primera línea que coincide**. Se recarga sin reiniciar.

| # | Línea | Por qué |
|---|---|---|
| 1 | `local all all trust` | Por el *socket* solo llega quien ya es administrador del servidor (`docker compose exec`). Lo usan el *healthcheck*, `backup.sh` y los guiones de `init/` |
| 2 | `host all sede all reject` | El administrador no entra por red, desde ningún sitio (C4, P2). Va **antes** que las demás: si estuviera después de una que le dejara entrar, no serviría |
| 3 | `host sede web samenet scram-sha-256` | La web, solo a su base y solo desde una red a la que esté conectado el contenedor (la `trasera`) |
| 4 | `host bifi panel samenet scram-sha-256` | El panel, solo a `bifi` y solo desde la red de Compose (P1) |
| 5 | `host bifi +responsables 192.168.6.118/32 reject` | Desde el propio servidor, PostgreSQL ve la IP del aula del servidor, que está dentro de la red de la línea 6. Sin esta línea, `resp_demo` entraría desde el servidor. Un responsable se conecta desde **su** equipo (P3) |
| 6 | `host bifi +responsables 192.168.6.0/24 scram-sha-256` | Cualquier miembro del grupo `responsables`, desde la red del aula, solo a `bifi`. Con `+` no hay que tocar el fichero al dar de alta a otro (C3) |
| 7 | `host all all all reject` | Todo lo demás, fuera: otro rol, otra base u otra red (P4). No es la línea de la imagen, `host all all all scram-sha-256`, que deja entrar a cualquiera que sepa una contraseña |

### Qué IP ve el gestor

| Desde | IP de origen | Qué línea le toca |
|---|---|---|
| La web o el panel (otro contenedor de la composición) | Su IP en la `trasera` (`172.x`) | 3 o 4, por `samenet` |
| Un equipo del aula, por el puerto publicado | La de ese equipo: Docker hace DNAT y no cambia el origen | 6 (o 7 si no es responsable o pide otra base) |
| El propio servidor, a `$IP_AULA:5432` | `192.168.6.118`: la regla DNAT de Docker cambia el destino, no el origen | 5: rechazado |
| El propio servidor, a `localhost:5432` (si se publicara también ahí, como en la teoría) | La puerta de enlace de la red de Docker (`172.x.0.1`): esa conexión la reenvía `docker-proxy`, que se conecta él al contenedor | 3 o 4 por `samenet`. Por eso el 5432 se publica **solo** en la IP del aula y P3 se hace desde otro equipo |
| Un contenedor de **otra** composición, al puerto publicado | La puerta de enlace de la red `bd-aula` (`172.x.0.1`), que está en `samenet` | 7 (solo `web` y `panel` entrarían, y con su contraseña) |

Para comprobar cómo ha entendido el servidor el fichero:
`SELECT line_number, database, user_name, address, auth_method, error FROM pg_hba_file_rules;`

## Red y puerto (C2)

- `ports: "${IP_AULA}:5432:5432"`: el 5432 solo en la IP del aula, no en la interna ni en las
  demás. Compose solo lee `.env`, así que `instalar.sh` copia `IP_AULA` de `sede.env` al final de
  `.env`, debajo de una marca.
- `networks: [trasera, bd-aula]`. La `trasera` es `internal` y una red `internal` no publica
  puertos: `bd-aula` existe solo para eso. Quién entra lo decide `pg_hba.conf`.

## La base `bifi` (C7 y RA3)

```sql
CREATE DATABASE bifi TEMPLATE template0 ENCODING 'UTF8'
  LOCALE_PROVIDER icu ICU_LOCALE 'es-ES' LOCALE 'C.UTF-8';
ALTER DATABASE bifi SET timezone = 'Europe/Madrid';
REVOKE CONNECT, TEMPORARY ON DATABASE bifi FROM PUBLIC;
GRANT CONNECT ON DATABASE bifi TO panel, responsables;
```

- **UTF-8 e ICU `es-ES`**: tildes y eñes, y el orden español (`Nueva < Núñez < Nuño < Nuria <
  Ñandú < Oca`). La imagen Alpine no trae las *locales* del sistema, pero sí ICU.
- **Sin `CONNECT` para `PUBLIC`**: segunda capa detrás de `pg_hba.conf`. Aunque una línea se abriera
  de más, un rol sin `CONNECT` no entra. Lo mismo en la base `sede`, que solo deja entrar a `web`.
- **Tablas**: las seis del modelo (`reto-1/modelo-datos.md`), más `solicitud` y `recuperacion` para
  recuperar la contraseña desde el panel y `cuenta_bd`, que dice qué usuario del BIFI es cada rol
  de `psql` (`resp_demo` → `marta`).
- **Reglas que un `CHECK` no puede mirar** (otra tabla), con disparadores: el responsable de un
  proyecto tiene perfil de responsable, en `participa` solo hay investigadores y solo se cobra un
  `uso` a un proyecto en el que se participa. `uso` no tiene clave ajena a `participa`: si se saca a
  alguien de un proyecto, lo que ya gastó se sigue cobrando.
- **Privilegio mínimo**: `REVOKE ALL` a `PUBLIC` en todas las tablas. El panel tiene lo justo
  (tabla en `panel/README.md`). Los responsables **no tienen ninguna tabla**, solo cuatro vistas
  que filtran por `current_user` a través de `cuenta_bd`:

| Vista | Qué enseña |
|---|---|
| `mis_proyectos` | Mis proyectos, con su tarifa y precio |
| `mis_miembros` | Quién está en cada uno |
| `mi_consumo` | Cada trabajo de mis proyectos, con sus núcleo-horas |
| `mi_factura` | Por proyecto: trabajos, núcleo-horas y euros |

Las vistas son `security_barrier`: una función del responsable en el `WHERE` no puede ver filas
antes de que se aplique el filtro.

## Arranque y parada (C9)

- `docker compose stop` manda **`SIGINT`** (la imagen declara `STOPSIGNAL SIGINT`): parada
  **fast**. Corta las conexiones, deshace las transacciones abiertas, hace *checkpoint* y para. En
  el registro: `received fast shutdown request` … `database system is shut down`. Al arrancar:
  `database system was shut down at …`, sin recuperación.
- Si a los **10 segundos** no ha terminado, Docker manda **`SIGKILL`**. Para el gestor es una
  caída: no hay *checkpoint* y al arrancar sale `database system was not properly shut down;
  automatic recovery in progress` (probado con `docker kill -s KILL`).
- `stop_grace_period: 30s`: con escrituras pendientes, el *checkpoint* final puede pasar de 10 s.
  Con 30 s le da tiempo y la parada sigue siendo limpia. En la prueba, la parada *fast* tardó
  menos de un segundo.

## Clientes: un responsable con `psql` (C8)

En **su** equipo, no en el servidor:

```bash
cp db/clientes/pg_service.conf.ejemplo ~/.pg_service.conf      # host = IP_AULA
echo '192.168.6.118:5432:bifi:resp_demo:LA-CLAVE' > ~/.pgpass   # la de RESP_DEMO_CLAVE
chmod 600 ~/.pgpass
psql service=bifi -c 'SELECT * FROM mi_factura'
```

Con `.pgpass` en `644`, `psql` no lo usa: avisa con `WARNING: password file … has group or world
access` y pide la clave (o, con `-w`, `fe_sendauth: no password supplied`). Ninguno de los dos
ficheros va al repositorio.

## Copias

- `backup.sh`: copia de las dos bases (W6), deja `db/backups/sede-*.sql.gz` y `bifi-*.sql.gz`.
- `restore-test.sh`: restaura la copia de `sede` en una BD de prueba y verifica el número de filas
  (test 20).

Las copias están fuera de Git (`db/backups/` en `.gitignore`).

## Llevar los datos a otro servidor

Git no lleva los datos: un servidor nuevo nace con las filas de `init/`. Para llevar los de otro:

```bash
# En el servidor de origen
./db/backup.sh                     # db/backups/sede-… y bifi-AAAAMMDD-HHMM.sql.gz
./forgejo/backup.sh                # forgejo/backups/forgejo-AAAAMMDD-HHMM.tar.gz
# Copiarlos al nuevo (scp) en las mismas carpetas del repositorio

# En el servidor nuevo, tras sudo ./instalar.sh
source .env
docker compose exec -T db psql -U "$DB_USUARIO" -d "$DB_NOMBRE" -c 'DROP TABLE IF EXISTS clientes;'
zcat db/backups/sede-*.sql.gz | docker compose exec -T db psql -U "$DB_USUARIO" -d "$DB_NOMBRE" -q
# bifi: se borra la de ejemplo, se crea vacía con las mismas propiedades, se restaura la copia
# y 02-bifi.sql vuelve a poner lo que es de la base y no de su contenido (CONNECT, timezone)
docker compose exec -T db psql -U "$DB_USUARIO" -d postgres -c 'DROP DATABASE bifi WITH (FORCE);' \
  -c "CREATE DATABASE bifi TEMPLATE template0 ENCODING 'UTF8' LOCALE_PROVIDER icu ICU_LOCALE 'es-ES' LOCALE 'C.UTF-8';"
zcat db/backups/bifi-*.sql.gz | docker compose exec -T db psql -U "$DB_USUARIO" -d bifi -q
docker compose exec -T db psql -U "$DB_USUARIO" -d postgres < db/init/02-bifi.sql
docker compose stop git
sudo rm -rf forgejo/data/* && sudo tar xzf forgejo/backups/forgejo-*.tar.gz -C forgejo/data
docker compose start git
```
