# red/ · SRI · ASO

Red del servidor: una tarjeta con la IP del aula y la de la red interna, reenvío, NAT y DHCP.

- `netplan/99-sede.yaml`: plantilla con `__MARCADORES__`; `instalar.sh` la rellena
  desde `sede.env` (DS4) y la instala como `/etc/netplan/99-sede.yaml` (permisos 600).
  Una sola tarjeta (`enp5s0`) con dos IPs fijas: la del aula y la interna
  (10.4.0.1), que no tiene puerta de enlace propia porque la salida la hace el
  NAT del servidor.
- `kernel/90-sede.conf`: reenvío de paquetes (`net.ipv4.ip_forward=1`), instalado
  en `/etc/sysctl.d/`: sin él la interna no sale.
- `nftables/99-sede-nat.conf`: NAT (masquerade) de 10.4.0.0/24 por la interfaz del
  aula. Solo declara SU tabla `sede_nat`, sin flush: no machaca las reglas que
  Docker crea por su cuenta. `nftables/sede-nat.service` la carga al arrancar
  y al reinstalar (idempotente).
- `resolved/90-sede.conf`: apaga el stub de systemd-resolved (`127.0.0.53:53`)
  para que el contenedor `dns` pueda publicar el puerto 53 del servidor.
- `kea/dhcp4.json`: DHCP de la red interna, otra plantilla con marcadores.
  `instalar.sh` la rellena en `generado/kea/dhcp4.json` y la usa el contenedor
  `kea` de `compose.yaml` (red del anfitrión, imagen `jonasal/kea-dhcp4`):
  reparte 10.4.0.10-200 con puerta y DNS en `10.4.0.1` (las fichas fijas 1 y 2
  son de ns1 y ns2, mirar `dns/zones/`).

Lo instalan los **pasos 2** (netplan, sysctl, NAT, resolved) y **3** (configuración de Kea;
el contenedor arranca en el 5) de `instalar.sh`.
El orden importa: la red va antes que el DNS de la zona, porque las IPs de las
zonas (`srv`, `ns1`) tienen que existir cuando se pregunta por ellas.
