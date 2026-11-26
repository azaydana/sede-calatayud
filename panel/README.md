# Panel del BIFI · esqueleto

Punto de partida del panel. **Funciona, pero no es seguro**: cualquiera entra como si fuera el
CESAR, ve todos los proyectos y puede dar de alta cualquier cosa. Lo que falta es justo lo que se
evalúa en IAW RA5, y está marcado con `TODO` en el código. El encargo:
[`../encargos/iaw-panel-ra5.md`](../encargos/iaw-panel-ra5.md).

## Qué hay

```text
Dockerfile          PHP 8.3 con Apache y pdo_pgsql. Solo se sirve public/
public/             lo que pide el navegador: una página por pantalla
  index.php         lista de proyectos
  proyecto.php      un proyecto: miembros y consumo
  proyecto-nuevo.php formulario de alta
  solicitudes.php   peticiones de recuperar la contraseña (CESAR)
  recuperar.php     pedir y usar un enlace de recuperación
  login.php         formulario de entrada
  logout.php        salir
  estilo.css
comun/              funciones; el navegador no llega aquí
  arranque.php      lo primero de cada página: carga lo demás y abre la sesión
  funciones.php     e() para escapar, bd(), consulta() y ejecutar() para la base
  sesion.php        login, salir y control por perfil          ← TODO
  proyectos.php     quién ve qué, validación del alta y miembros ← TODO
  recuperacion.php  solicitudes y enlaces de un solo uso
  plantilla.php     cabecera, menú y pie                        ← TODO el menú
datos-ejemplo/      los JSON de antes del RA6 (solo de referencia: el panel ya no los lee)
```

## Datos (RA6)

Desde el RA6 los datos están en PostgreSQL, en la base `bifi`. La crea, con sus tablas y datos de
ejemplo, [`../db/init/02-bifi.sql`](../db/init/02-bifi.sql). El modelo está en
`reto-1/modelo-datos.md`, más dos tablas para recuperar la contraseña (`solicitud` y
`recuperacion`).

El panel entra con el rol `panel` (variables `DB_*` de `compose.yaml`), que solo puede:

| Tabla | Permiso | Para qué |
|---|---|---|
| todas las del modelo | `SELECT` | ver proyectos, miembros y consumo |
| `proyecto` | `INSERT` | alta (CESAR) |
| `participa` | `INSERT`, `DELETE` | meter y sacar investigadores (responsable) |
| `usuario` | `UPDATE (hash)` | cambiar la contraseña con un enlace |
| `solicitud`, `recuperacion` | `INSERT`, `DELETE` | recuperar la contraseña |

No puede borrar proyectos, tocar `uso` ni cambiar perfiles: si alguien colara SQL, la base no le
dejaría. Además, todas las consultas son preparadas (`consulta()` y `ejecutar()` en
`funciones.php`), y las reglas del modelo que no caben en un `CHECK` (el responsable tiene perfil de
responsable, solo se meten investigadores, solo se cobra a un proyecto en el que se participa) las
vigilan disparadores de la base, aunque el panel ya las compruebe antes.

## Usuarios de ejemplo

Todos con la contraseña `bifi2026`. En la tabla `usuario` está su *hash*, no la
contraseña.

| Usuario | Perfil | Proyectos |
|---|---|---|
| `ana` | Investigador | Miembro de BIO-0001 y QUI-0003 |
| `luis` | Investigador | Miembro de FIS-0002 |
| `sofia` | Investigador | Miembro de BIO-0001 y CLI-0004 |
| `pablo` | Investigador | Miembro de FIS-0002 |
| `irene` | Investigador | Miembro de QUI-0003 e IND-0005 |
| `david` | Investigador | Miembro de CLI-0004 |
| `marta` | Responsable | BIO-0001 y QUI-0003: añade y quita investigadores |
| `jorge` | Responsable | FIS-0002 e IND-0005 |
| `elena` | Responsable | CLI-0004 |
| `cesar` | Administración del CESAR | Todos; da de alta proyectos |

## Levantarlo

Copiad la carpeta `panel/` a la raíz del repositorio de vuestra sede y añadid a vuestro
`compose.yaml` el servicio de [`compose.ejemplo.yaml`](compose.ejemplo.yaml). Después:

```bash
docker compose up -d --build panel
```

Para probar antes de tener el nombre `panel.<sede>.empresa.lan` en el DNS y en el Apache de la
sede, publicad un puerto (`ports: ["8081:80"]`) y abrid `http://<IP_AULA>:8081`.

Lo que se da de alta queda en la base, en el volumen de `db`. Para volver a los datos de ejemplo
hay que recrear ese volumen (`docker compose down -v` borra **también** la base de la web: copia
antes con `./db/backup.sh`).

Cada cambio en el código necesita `docker compose up -d --build panel`. Para no reconstruir a cada
paso mientras programáis, montad el código: `- ./panel/public:/var/www/panel/public` y
`- ./panel/comun:/var/www/panel/comun` en `volumes:`.
