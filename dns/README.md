# dns/ · SRI

BIND en contenedor: configuración, zonas directa e inversa, primario y secundario.

- `config/named.conf`: sustituye al de la imagen `internetsystemsconsortium/bind9`,
  que no incluye nada; carga `named.conf.options` y `named.conf.local`.
- `config/named.conf.options`: recursión, ACLs (red interna + red aula), forwarders
  del centro, rate limit y endurecimiento. Solo la red interna recurre a internet.
- `config/named.conf.local`: zonas de `calatayud.empresa.lan` en modo `primary`,
  con transferencia y notificación limitadas a `ns2` (10.4.0.2).
- `zones/db.calatayud.empresa.lan`: zona directa — `ns1`, `ns2`, `srv`
  (192.168.6.118, alcanzable desde el aula), `www` / `intranet` / `git` como CNAME
  de `srv`.
- `zones/db.10.4`: zona inversa `0.4.10.in-addr.arpa` (10.4.0.0/24), con un PTR
  canónico por IP.
- `ns2/`: los dos ficheros del **secundario**, que es un nodo de GNS3 (10.4.0.2),
  no un contenedor: se copian a `/etc/bind/` de esa máquina.

Llega al contenedor por volumen en `compose.yaml` (sucursal `dns`): configs a
`/etc/bind/` como `:ro` y zonas a `/var/lib/bind/`. El servicio publica el 53/tcp
y 53/udp del servidor (por eso el paso 2 apaga el stub de systemd-resolved). La
ACL `red_interna` permite `172.22.0.0/16`, la subred que `compose.yaml` fija para
la red `frontal`: así el propio servidor puede preguntar a `127.0.0.1`.
