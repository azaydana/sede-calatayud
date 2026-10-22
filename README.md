# Sede calatayud · calatayud.empresa.lan

Despliegue completo de la sede según el encargo 5: un Ubuntu Server 24.04 deja
de estar recién instalado y pasa a servir DNS, web, base de datos y git, con
usuarios propios y SSH por clave, una red interna con NAT y DHCP, y todo
reproducible con `sudo ./instalar.sh`.

## Despliegue

En un Ubuntu Server 24.04 recién instalado, con la IP de la sede y OpenSSH:

```bash
git clone <repositorio> && cd sede-calatayud
cp .env.example .env && nano .env
sudo ./instalar.sh
```

Se puede repetir sin romper nada. Después, las comprobaciones una a una:
[`docs/comprobaciones.md`](docs/comprobaciones.md).

## Pasos

`instalar.sh` se detiene si algo falla y todos los pasos son repetibles:
antes de hacer algo comprueba si ya está hecho (DS8). La última columna
justifica el orden (DS9): por qué ese paso va ahí y por qué no deja el
servidor sin acceso.

| Paso | Módulo | Qué hace | Por qué en este lugar |
|---|---|---|---|
| 0 | — | Comprueba root, `.env` (sin claves sin cambiar), docker compose, ficheros del repo e interfaces de `sede.env` | Siempre el primero: nada se toca sin saber que lo demás está completo |
| 1 | ASO | Crea un usuario por cada `sistema/usuarios/*.pub`, instala `authorized_keys` y endurece sshd (solo claves) | La SSH se cierra primero: nadie queda fuera porque las claves ya están puestas |
| 2 | SRI·ASO | Netplan (aula + interna), reenvío (`sysctl`), NAT con `nftables` en su propia tabla | La red antes que los servicios: las IPs que anuncia el DNS y el NAT del que dependen los PCs de la interna tienen que existir ya |
| 3 | SRI·ASO | Kea reparte IPs en la red interna (10.4.0.10-200) | Va tras el netplan: necesita la interfaz interna ya configurada |
| 4 | IAW | Genera `secretos/intranet.htpasswd` desde `.env` | Va antes de levantar los contenedores: la web lo monta al arrancar |
| 5 | Todos | `docker compose up -d --build`: dns, web, db, git | Al final: necesita usuarios, red, DNS publicable y secretos; el orden dentro lo da el `depends_on` de compose |

## Carpetas

| Carpeta | Módulo | Responsable | Contenido |
|---|---|---|---|
| `sistema/` | ASO | Ayoub | Claves públicas por persona (`usuarios/*.pub`) y endurecimiento de sshd |
| `red/` | SRI · ASO | Ayoub | Netplan, `sysctl`, NAT (`nftables` + unit de systemd) y DHCP con Kea |
| `dns/` | SRI | Younes | BIND primario (configs + zonas directa e inversa) y ficheros del secundario (`ns2/`) |
| `web/` | IAW | Nico | `Dockerfile`, VirtualHosts, `publica/`, `privada/` y sitio de pruebas `www/` |
| `db/` | ASGBD | Nico | Esquema inicial con datos (`init/`), copias de seguridad y prueba de restauración |
| `gns3/` | SRI | Younes | Proyecto de GNS3 de la red interna |
| `forgejo/` | Optativo | Ayoub | Copias de Forgejo (`backup.sh`); el servicio vive en `compose.yaml` |
| `docs/` | Todos | — | Cuaderno (`bitacora.md`), comprobaciones, investigaciones y capturas |

## Datos y claves

- Datos de la sede (IP, interfaces, red interna, dominio, resolutor): `sede.env`. Van en Git.
- Claves: `.env`, que no va en Git. Las que hacen falta están en `.env.example`.
- El htpasswd de la intranet tampoco va en Git: lo genera el paso 4 en `secretos/`.

## Última prueba en limpio

Pendiente: se hace con otra sede del equipo (prueba A7): clone del repositorio
en un servidor vacío, `sudo ./instalar.sh` y el resultado completo de
`docs/comprobaciones.md` enlazado desde aquí, con fecha y quien la hizo.
