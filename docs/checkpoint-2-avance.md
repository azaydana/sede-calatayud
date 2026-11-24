# Checkpoint 2 · Avance del despliegue en DMZ con pfSense

**Fecha de revisión:** 24/11/2026  
**Estado:** inventario y almacenamiento preparados. Instalación y validación pendientes.

## Trabajo realizado

| Comprobación | Resultado |
|---|---|
| Acceso al Ubuntu de la sede | Sesión SSH verificada. |
| Virtualización | Host x86_64 con VT-x, KVM y libvirt disponibles. El inventario de libvirt no mostraba máquinas virtuales definidas. |
| Red de partida | El aula y la red interna comparten la interfaz física de Ubuntu. La red interna sigue configurada como un único segmento. |
| Servicios | Docker mostraba contenedores web, BIND, Kea, PostgreSQL y Forgejo en ejecución. |
| Disco de pfSense | Creado en el servidor y verificado con `qemu-img info`. Formato QCOW2, capacidad virtual de 20 GiB y sin corrupción detectada por la consulta de metadatos. |

El disco dedicado está en `/var/lib/libvirt/images/pfsense-calatayud.qcow2`. La máquina virtual y la configuración de interfaces están pendientes. Los servicios de la sede siguen funcionando con la configuración anterior.

## Diseño propuesto

Ejecutar pfSense CE en una VM KVM y reutilizar la VLAN 10 como DMZ dentro del direccionamiento definido en el Checkpoint 1. Antes de la migración hay que validar la ubicación de DNS y LDAP, las IP disponibles, el enlace de las VLAN y el acceso administrativo por consola.

## Próximos pasos

1. Obtener la ISO amd64 del [instalador oficial de Netgate](https://docs.netgate.com/pfsense/en/latest/install/download-installer-image.html) y verificar su SHA-256.
2. Definir la VM, instalar pfSense CE en el disco dedicado y comprobar la consola y las interfaces en una red de prueba.
3. Preparar las copias de configuración y una vía de acceso alternativa. Configurar la DMZ y las reglas de firewall y NAT según los servicios autorizados.
4. Migrar los servicios previstos y registrar evidencias desde el aula, la DMZ y las redes internas. Revisar también [las comprobaciones de la sede](comprobaciones.md).
