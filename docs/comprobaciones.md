# Comprobaciones de la sede calatayud

DS10 del encargo: las pruebas que cualquiera del equipo hace en el servidor para saber si la sede
está bien, cada una con su orden y la salida esperada. Se hacen después de `sudo ./instalar.sh`.
Todas se lanzan desde la carpeta del repositorio. Donde pone `$VAR`, es una variable de `sede.env`
o de `.env` (`source sede.env` antes de empezar).

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
| B2 | La web tiene usuario propio, no superusuario | `docker compose exec db psql -U "$DB_USUARIO" -c '\du'` | solo el usuario de la BD y postgres | `.env` + `POSTGRES_USER` |
| B3 | Copia de seguridad | `./db/backup.sh` | `OK db/backups/sede-…sql.gz` | permisos de `db/backups/` |
| B4 | La copia devuelve las filas | `./db/restore-test.sh db/backups/sede-*.sql.gz` | `filas_restauradas 25` + `RESTORE OK` | integridad del `.sql.gz` (B3) |

## Forgejo (optativo · git)

| # | Qué | Orden | Salida esperada | Si falla |
|---|---|---|---|---|
| F1 | Forgejo tras el proxy | `curl -I -H 'Host: git.calatayud.empresa.lan' http://localhost/` | `HTTP/1.1 200` | proxy en `web/conf/site.conf`; logs: `docker compose logs git` |
| F2 | SSH de git en el 2222 | `nc -zv $IP_AULA 2222` | `Connection to … 2222 port succeeded` | mapeo de puertos en `compose.yaml` |
| F3 | Copia de Forgejo | `./forgejo/backup.sh` | `OK forgejo/backups/forgejo-*.tar.gz` | permisos de `forgejo/data/` |
