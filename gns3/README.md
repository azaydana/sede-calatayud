# gns3/ · SRI

Proyecto de GNS3 de la red interna y la configuración de sus nodos.

- `gns3/prueba/prueba.gns3` (+ `project-files/`): el proyecto de GNS3, listo para
  abrir con el cliente (File → Open project), o copiando la carpeta a
  `~/GNS3/projects/`, donde el servidor de GNS3 la encuentra.
- `dns/ns2/`: la configuración **del secundario** (10.4.0.2), que vive aquí y no
  en el servidor: copiar esos dos ficheros a `/etc/bind/` del nodo ns2 del
  proyecto. El primario es el contenedor `dns` del servidor (10.4.0.1).

Antes de abrir el proyecto debe estar: el servidor GNS3 en marcha y el enlace de
la red interna conectado a la interfaz del servidor (`INTERFAZ_INTERNA` en
`sede.env`), y los contenedores `kea` (DHCP) y `dns` levantados (paso 5 de
`instalar.sh`).
