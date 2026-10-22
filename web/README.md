# web/ · IAW

`Dockerfile` de la imagen propia, sitios de Apache y contenido (`publica`,
`privada`, `www`, página de estado).

- `Dockerfile`: `php:8.2-apache` con `pdo_pgsql` (conexión con PostgreSQL), los
  módulos de Apache necesarios (`proxy` para el vhost de git), y el contenido
  y la configuración copiados dentro de la imagen.
- `conf/site.conf`: los tres VirtualHosts que entran en la imagen como
  `000-default.conf`:
  - `www.calatayud.empresa.lan` → `publica/`, acceso abierto.
  - `intranet.calatayud.empresa.lan` → `privada/`, auth basic.
  - `git.calatayud.empresa.lan` → proxy hacia `forgejo:3000` (compose).
- `publica/`: web principal + `estado.php` (comprueba la conexión con la BD).
- `privada/`: panel de la intranet, PHP: estado de PostgreSQL, recursos del
  servidor, servicios y tareas. Credenciales SIEMPRE desde entorno
  (`DB_HOST`, `DB_NOMBRE`, `DB_USUARIO`, `DB_CLAVE`, que `compose.yaml` pasa
  desde `.env`).
- `www/`: sitio de pruebas con `estado.php` (versión del gestor y número de
  filas, con consulta preparada).

El fichero de contraseñas de la intranet NO va en Git ni dentro de la imagen:
`instalar.sh` lo genera en `secretos/intranet.htpasswd` a partir de `.env` y
`compose.yaml` lo monta en `/etc/apache2/conf-custom/htpasswd` (DS3).
La imagen se reconstruye con `docker compose up -d --build`
(paso 5 de `instalar.sh`).
