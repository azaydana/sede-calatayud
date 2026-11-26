# Comprobaciones de la sede calatayud

DS10 del encargo: las pruebas que cualquiera del equipo hace en el servidor para saber si la sede
está bien, cada una con su orden y la salida esperada. Se hacen después de `sudo ./instalar.sh`.
Todas se lanzan desde la carpeta del repositorio. Donde pone `$VAR`, es una variable de `sede.env`
o de `.env` (`source sede.env` antes de empezar). Las IPs `192.168.6.x` son las de la sede
real: en otra red (`sede.local.env`), usad las vuestras.

## Sistema y SSH (ASO)

| # | Qué | Orden | Salida esperada | Si falla |
|---|---|---|---|---|
| S1 | Los usuarios existen | `id ayoub` | `uid=…(ayoub) gid=…` | paso 1 de `instalar.sh`; claves en `sistema/usuarios/` |
| S2 | Solo se entra por SSH con claves | `ssh -o PreferredAuthentications=password ayoub@$IP_AULA` | `Permission denied (publickey)` | `sistema/ssh/` aplicado en el paso 1 |
| S3 | sshd con el endurecimiento activo | `sudo sshd -T \| grep -i passwordauth` | `passwordauthentication no` | `00-sede-hardening.conf` en `sshd_config.d/` (paso 1); si sale `yes`, otro fichero se lee antes |

## Red (SRI · ASO)

| # | Qué | Orden | Salida esperada | Si falla |
|---|---|---|---|---|
| R1 | IP del aula | `ip -4 addr show $INTERFAZ_AULA` | `192.168.6.118/24` | netplan (`red/netplan/`, paso 2) |
| R2 | IP de la interna | `ip -4 addr show $INTERFAZ_INTERNA` | `10.4.0.1/24` (en la misma tarjeta que R1) | netplan (paso 2) |
| R3 | Reenvío activo | `sysctl net.ipv4.ip_forward` | `net.ipv4.ip_forward = 1` | `red/kernel/` (paso 2) |
| R4 | NAT activo | `sudo nft list table ip sede_nat` | la regla `masquerade` | `red/nftables/` + `sede-nat.service` (paso 2) |
| R5 | Un PC de la interna navega | desde un VPCS de GNS3: `ping 8.8.8.8` | contesta | NAT (paso 2) o puerta de enlace del DHCP |
| R6 | DHCP de la interna | desde un VPCS: `ip dhcp` | `10.4.0.x`, gw y DNS `10.4.0.1` | Kea (pasos 3 y 5); logs: `docker compose logs kea` |

## DNS (SRI)

| # | Qué | Orden | Salida esperada | Si falla |
|---|---|---|---|---|
| D1 | Resolución directa | `dig @192.168.6.118 www.calatayud.empresa.lan +short` | `srv.calatayud.empresa.lan.` y `192.168.6.118` | zona directa en `dns/zones/` |
| D2 | Resolución inversa | `dig @192.168.6.118 -x 10.4.0.2 +short` | `ns2.calatayud.empresa.lan.` | zona `0.4.10.in-addr.arpa` |
| D3 | La interna recurre a internet | desde un PC de la interna: `dig @10.4.0.1 www.debian.org +short` | IPs del nombre externo | `allow-recursion` + forwarders en `named.conf.options` |
| D4 | El aula NO recurre a internet | desde un PC del aula: `dig @192.168.6.118 www.debian.org +short` | `REFUSED` | ACLs en `named.conf.options` |
| D5 | El secundario sirve la zona | desde ns2 (GNS3): `dig @10.4.0.2 www.calatayud.empresa.lan +short` | igual que D1 | `allow-transfer`/`also-notify` en `named.conf.local`; ficheros de `dns/ns2/` |
| D0 | El 53 es del contenedor | `sudo ss -lunp \| grep ':53 '` | `docker-proxy`, no `systemd-resolve` | `red/resolved/` (paso 2) |
| D6 | El contenedor de BIND está sano | `docker compose logs dns \| grep -iE 'error\|warn'` | sin errores de zona | volúmenes de `compose.yaml` |

## Web (IAW)

| # | Qué | Orden | Salida esperada | Si falla |
|---|---|---|---|---|
| W1 | La web pública responde | `curl -H 'Host: www.calatayud.empresa.lan' http://localhost/` | el HTML de `web/publica/` | vhost en `web/conf/site.conf` |
| W2 | La intranet pide usuario | `curl -I -H 'Host: intranet.calatayud.empresa.lan' http://localhost/` | `HTTP/1.1 401 Unauthorized` | auth basic + `secretos/intranet.htpasswd` |
| W3 | La intranet acepta al usuario de `.env` | `curl -u "$INTRANET_USUARIO:$INTRANET_CLAVE" -H 'Host: intranet.calatayud.empresa.lan' http://localhost/` | el HTML del panel | paso 4 de `instalar.sh` |
| W4 | El estado lee la base de datos | `curl -H 'Host: www.calatayud.empresa.lan' http://localhost/estado.php` | versión de PostgreSQL y «Filas en clientes: 25» | `depends_on` + healthcheck en `compose.yaml` |

## Base de datos (ASGBD)

| # | Qué | Orden | Salida esperada | Si falla |
|---|---|---|---|---|
| B1 | La BD nació poblada | `docker compose exec db psql -U "$DB_USUARIO" -d "$DB_NOMBRE" -c 'SELECT count(*) FROM clientes;'` | `25` | `db/init/01-esquema.sql` montado en `compose.yaml` |
| B2 | La web tiene rol propio, no superusuario | `docker compose exec db psql -U "$DB_USUARIO" -c '\du'` | `Superuser` solo en `$DB_USUARIO`; `web`, `panel` y `resp_demo` sin atributos; `responsables` `Cannot login` | `db/init/03-claves.sh`; `WEB_*` en `.env` |
| B3 | Copia de seguridad | `./db/backup.sh` | `OK db/backups/sede-…sql.gz` y `OK db/backups/bifi-…sql.gz` | permisos de `db/backups/` |
| B4 | La copia devuelve las filas | `./db/restore-test.sh db/backups/sede-*.sql.gz` | `filas_restauradas 25` + `RESTORE OK` | integridad del `.sql.gz` (B3) |

## Base del BIFI abierta a clientes de fuera (ASGBD RA2 · Reto 1)

Pruebas P1 a P8 del encargo, en este orden. `CLIENTE` es un contenedor de usar y tirar en la red de
la composición donde está `db` (la `trasera`):

```bash
source .env
CLIENTE="docker run --rm --network sede-calatayud_trasera -e PGCONNECT_TIMEOUT=5 postgres:16-alpine"
```

Las salidas son las de la prueba del 25/11/2026 en una composición aislada. Las IPs de Docker
(`172.x`) cambian de un servidor a otro.

| # | Qué | Orden | Salida esperada | Si falla |
|---|---|---|---|---|
| P1 | El panel entra desde la red de Compose | `$CLIENTE env PGPASSWORD=$PANEL_CLAVE psql -h db -U panel -d bifi -Atc "SELECT current_user, inet_client_addr()"` | `panel\|172.20.0.5` (una IP de la `trasera`) | línea 4 de `pg_hba.conf`; `PANEL_CLAVE` en `.env` y `03-claves.sh` |
| P2 | El administrador no entra por red | `$CLIENTE env PGPASSWORD=$DB_CLAVE psql -h db -U $DB_USUARIO -d postgres -c 'SELECT 1'` | `FATAL:  pg_hba.conf rejects connection for host "172.20.0.5", user "sede", database "postgres"` | línea 2 de `pg_hba.conf` |
| P3 | Un responsable entra desde **otro equipo** del aula | en ese equipo, con `~/.pg_service.conf` (de `db/clientes/`) y `~/.pgpass` en `600`: `psql service=bifi -c "SELECT current_user, inet_client_addr()"` | `resp_demo` y la IP de ese equipo (`192.168.6.x`) | puerto publicado (`ss -ltn \| grep 5432` en el servidor), línea 6 de `pg_hba.conf` |
| P3b | …y no desde el propio servidor | en el servidor: `psql "host=$IP_AULA user=resp_demo dbname=bifi"` | `pg_hba.conf rejects connection for host "192.168.6.118"` | línea 5 de `pg_hba.conf` |
| P4 | Un responsable no entra en otra base | `psql "host=$IP_AULA user=resp_demo dbname=postgres"` (desde el equipo del aula) | `pg_hba.conf rejects connection … database "postgres"` | líneas 6 y 7 de `pg_hba.conf` |
| P5 | Límite de conexiones | dos sesiones de `resp_demo` con `SELECT pg_sleep(30)` y una tercera | `FATAL:  too many connections for role "resp_demo"` | `CONNECTION LIMIT 2` en `02-bifi.sql` |
| P6 | Tiempo máximo de una sentencia | `$CLIENTE env PGPASSWORD=$PANEL_CLAVE psql -h db -U panel -d bifi -c "SELECT pg_sleep(6)"` | `ERROR:  canceling statement due to statement timeout` | `ALTER ROLE panel SET statement_timeout` en `02-bifi.sql` |
| P7 | La base y sus valores | `docker compose exec db psql -U $DB_USUARIO -c '\l bifi'` y `docker compose exec db psql -U $DB_USUARIO -d bifi -c 'SHOW timezone'` | `UTF8`, `icu`, `es-ES`; privilegios `sede=CTc/sede`, `panel=c/sede`, `responsables=c/sede` (sin `=Tc/sede` de `PUBLIC`); `Europe/Madrid` | `CREATE DATABASE` de `02-bifi.sql`: si la base ya existía, no cambia |
| P8 | Contraseñas y reglas | `docker compose exec db psql -U $DB_USUARIO -c "SELECT rolname, left(rolpassword,13) FROM pg_authid WHERE rolcanlogin"` y `… -c "SELECT line_number, database, user_name, address, auth_method, error FROM pg_hba_file_rules"` | los 4 roles `SCRAM-SHA-256`; 7 reglas en este orden, sin `error` | `password_encryption`; volver a poner la clave (`03-claves.sh`) |
| P9 | `.pgpass` con permisos `644` | en el equipo del aula: `chmod 644 ~/.pgpass; psql service=bifi -w -c 'SELECT 1'` | `WARNING: password file "…/.pgpass" has group or world access; permissions should be u=rw (0600) or less` y `fe_sendauth: no password supplied` | es lo esperado: `chmod 600 ~/.pgpass` |

Resultado de P8 (reglas, con el `pg_hba.conf` generado por `instalar.sh`):

```text
 line_number | database |    user_name     |    address     | auth_method   | error
-------------+----------+------------------+----------------+---------------+-------
          16 | {all}    | {all}            |                | trust         |
          19 | {all}    | {sede}           | all            | reject        |
          22 | {sede}   | {web}            | samenet        | scram-sha-256 |
          25 | {bifi}   | {panel}          | samenet        | scram-sha-256 |
          29 | {bifi}   | {+responsables}  | 192.168.6.118  | reject        |
          32 | {bifi}   | {+responsables}  | 192.168.6.0    | scram-sha-256 |
          35 | {all}    | {all}            | all            | reject        |
```

## Base del BIFI: datos, vistas y panel (ASGBD RA3 · IAW RA6)

| # | Qué | Orden | Salida esperada | Si falla |
|---|---|---|---|---|
| B5 | `bifi` nació poblada | `docker compose exec db psql -U $DB_USUARIO -d bifi -Atc "SELECT (SELECT count(*) FROM proyecto), (SELECT count(*) FROM uso), (SELECT count(*) FROM usuario)"` | `5\|20\|10` (más lo que se haya dado de alta) | `db/init/02-bifi.sql` |
| B6 | Las reglas del modelo las pone la base | `docker compose exec db psql -U $DB_USUARIO -d bifi -c "INSERT INTO participa VALUES ('BIO-0001','marta')"` | `ERROR:  El miembro «marta» no tiene perfil investigador` | disparadores de `02-bifi.sql` |
| B7 | Un responsable solo ve lo suyo (RA3) | desde el equipo del aula: `psql service=bifi -c 'SELECT * FROM mi_factura'` | solo BIO-0001 (34,24) y QUI-0003 (26,88): los de Marta | `cuenta_bd` y vistas de `02-bifi.sql` |
| B8 | …y no las tablas | `psql service=bifi -c 'SELECT * FROM usuario'` | `ERROR:  permission denied for table usuario` | `GRANT` de `02-bifi.sql` |
| B9 | El panel lee de PostgreSQL (RA6) | entrar en el panel como `cesar` / `bifi2026` | los 5 proyectos; un alta nueva aparece en `SELECT * FROM proyecto` | `DB_*` del servicio `panel`; `docker compose logs panel` |
| B10 | El panel no puede más de lo que necesita | `$CLIENTE env PGPASSWORD=$PANEL_CLAVE psql -h db -U panel -d bifi -c 'DELETE FROM uso'` | `ERROR:  permission denied for table uso` | `GRANT` de `02-bifi.sql` |

## Forgejo (optativo · git)

| # | Qué | Orden | Salida esperada | Si falla |
|---|---|---|---|---|
| F1 | Forgejo tras el proxy | `curl -I -H 'Host: git.calatayud.empresa.lan' http://localhost/` | `HTTP/1.1 200` | proxy en `web/conf/site.conf`; logs: `docker compose logs git` |
| F2 | SSH de git en el 2222 | `nc -zv $IP_AULA 2222` | `Connection to … 2222 port succeeded` | mapeo de puertos en `compose.yaml` |
| F3 | Copia de Forgejo | `./forgejo/backup.sh` | `OK forgejo/backups/forgejo-*.tar.gz` | permisos de `forgejo/data/` |
