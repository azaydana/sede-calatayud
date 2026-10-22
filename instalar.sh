#!/bin/bash
#
# instalar.sh · Despliegue de la sede calatayud
#
# Se usa así, en un Ubuntu Server 24.04 recién instalado:
#
#   sudo ./instalar.sh
#
# Es una receta: los pasos que haríais a mano para dejar la sede funcionando, uno detrás de otro.
# Se puede ejecutar muchas veces: antes de hacer algo, se mira si ya está hecho (DS8).


# Si una orden falla, el script para aquí mismo. Así no se queda nada hecho a medias.
set -e

# Nos movemos a la carpeta donde está este script.
# Así se encuentran sede.env, web/, dns/… aunque lo ejecutéis desde otro sitio.
cd "$(dirname "$0")"

# Cargamos los datos de la sede. A partir de aquí existen $SEDE, $DOMINIO, $IP_AULA…
source sede.env

echo "== 0. Comprobaciones antes de tocar nada"

# Este paso no cambia nada: mira que todo está listo y, si falta algo, dice qué y para (exit 1).

if [ "$(id -u)" -ne 0 ]; then
    echo "ERROR: este script es root-only: sudo ./instalar.sh"
    exit 1
fi

# ¿Existe el fichero .env con las claves?
if [ ! -f .env ]; then
    echo "ERROR: falta .env. Haced: cp .env.example .env y cambiad las claves"
    exit 1
fi

# Cargamos las claves. A partir de aquí existen $DB_CLAVE, $INTRANET_CLAVE…
source .env

# ¿Queda alguna clave sin cambiar o vacía? (DS3: nada de valores de fábrica)
for V in DB_NOMBRE DB_USUARIO DB_CLAVE INTRANET_USUARIO INTRANET_CLAVE; do
    VALOR="${!V:-}"
    if [ -z "$VALOR" ] || [ "$VALOR" = "cambiar" ]; then
        echo "ERROR: la variable $V está vacía o sin cambiar en .env"
        exit 1
    fi
done

# ¿Docker y Compose instalados? Sin ellos no hay sede.
if ! command -v docker >/dev/null || ! docker compose version >/dev/null 2>&1; then
    echo "ERROR: falta Docker con el plugin compose (docker compose version)"
    exit 1
fi

# ¿Los ficheros que van a entrar en contenedores están en su sitio?
for F in web/Dockerfile web/conf/site.conf db/init/01-esquema.sql dns/config/named.conf.local \
         dns/config/named.conf.options dns/zones/db.calatayud.empresa.lan dns/zones/db.10.4; do
    if [ ! -f "$F" ]; then
        echo "ERROR: falta $F en el repositorio"
        exit 1
    fi
done

# ¿Las interfaces de sede.env existen? La del aula es imprescindible;
# la interna avisa: sin ella no hay DHCP ni NAT, pero el resto sigue.
if [ ! -d "/sys/class/net/$INTERFAZ_AULA" ]; then
    echo "ERROR: la interfaz del aula no existe: $INTERFAZ_AULA (muerda con ip a)"
    exit 1
fi
if [ ! -d "/sys/class/net/$INTERFAZ_INTERNA" ]; then
    echo "AVISO: la interfaz de la red interna no existe hoy: $INTERFAZ_INTERNA"
    echo "       Haz ip a y corrige INTERFAZ_INTERNA en sede.env; hasta entonces, sin DHCP interno."
fi

# ¿Hay claves públicas para instalar? (sistema/usuarios/*.pub, una por persona)
N_CLAVES=$(find sistema/usuarios -maxdepth 1 -name '*.pub' 2>/dev/null | wc -l)
if [ "$N_CLAVES" -eq 0 ]; then
    echo "AVISO: no hay claves públicas en sistema/usuarios/ — el paso 1 solo instalará sshd base"
fi

echo "== 1. Usuarios y SSH (ASO)"

# Un usuario por cada .pub de sistema/usuarios/ (el nombre del fichero es el del usuario).
for PUB in sistema/usuarios/*.pub; do
    [ -e "$PUB" ] || break
    PERSONA=$(basename "$PUB" .pub)
    if ! id "$PERSONA" >/dev/null 2>&1; then
        useradd -m -s /bin/bash "$PERSONA"
        echo "Usuario creado: $PERSONA"
    fi
    # Authorized_keys: idempotente, la clave solo entra si no estaba (DS8)
    install -d -m 700 -o "$PERSONA" -g "$PERSONA" "/home/$PERSONA/.ssh"
    if ! grep -qxF "$(cat "$PUB")" "/home/$PERSONA/.ssh/authorized_keys" 2>/dev/null; then
        cat "$PUB" >> "/home/$PERSONA/.ssh/authorized_keys"
    fi
    chown "$PERSONA:$PERSONA" "/home/$PERSONA/.ssh/authorized_keys"
    chmod 600 "/home/$PERSONA/.ssh/authorized_keys"
done

# Endurecer SSH, SOLO si existe alguna clave instalada:
# cerrar la contraseña antes de poner claves = quedarse fuera del servidor.
if [ "$N_CLAVES" -gt 0 ]; then
    install -m 644 sistema/ssh/99-sede-hardening.conf /etc/ssh/sshd_config.d/99-sede-hardening.conf
    # Validar antes de recargar: un fichero roto no puede dejarnos fuera
    if sshd -t 2>/dev/null; then
        systemctl reload ssh
        echo "SSHD endurecido (solo acceso por clave)."
    else
        echo "AVISO: sshd -t no valida el fichero, no se aplica (revisar sistema/ssh/)"
    fi
fi


echo "== 2. Red permanente: netplan, sysctl y NAT (SRI · ASO)"

# Cloud-init no debe reescribir la red en cada arranque: la gestionamos nosotros.
CLOUD_CFG=/etc/cloud/cloud.cfg.d/99-disable-network-config.cfg
if [ ! -f "$CLOUD_CFG" ]; then
    printf 'network: {config: disabled}\n' > "$CLOUD_CFG"
fi

# Netplan de la sede: las __MARCADORES__ salen de sede.env (DS4: los datos solo ahí)
mkdir -p /etc/netplan
sed -e "s/__INTERFAZ_AULA__/$INTERFAZ_AULA/" \
    -e "s/__IP_AULA__/$IP_AULA/" \
    -e "s/__PUERTA_AULA__/$PUERTA_AULA/" \
    -e "s/__RESOLUTOR_CENTRO__/$RESOLUTOR_CENTRO/" \
    -e "s/__INTERFAZ_INTERNA__/$INTERFAZ_INTERNA/" \
    -e "s/__IP_INTERNA__/$IP_INTERNA/" \
    red/netplan/99-sede.yaml > /etc/netplan/99-sede.yaml
chmod 600 /etc/netplan/99-sede.yaml
netplan apply

# Reenvío de paquetes: la interna solo sale si el servidor reenvía
install -m 644 red/kernel/90-sede.conf /etc/sysctl.d/90-sede.conf
sysctl --system --quiet

# NAT de la red interna: tabla sede_nat aparte, que no machaca las reglas de Docker
mkdir -p /etc/nftables.d
sed -e "s/__INTERFAZ_AULA__/$INTERFAZ_AULA/" \
    -e "s/__IP_AULA__/$IP_AULA/" \
    red/nftables/99-sede-nat.conf > /etc/nftables.d/sede-nat.conf
nft -f /etc/nftables.d/sede-nat.conf
install -m 644 red/nftables/sede-nat.service /etc/systemd/system/sede-nat.service
systemctl daemon-reload
systemctl enable --now sede-nat.service
echo "NAT de la red interna activo ($RED_INTERNA sale por $INTERFAZ_AULA)."


echo "== 3. DHCP de la red interna: Kea (SRI · ASO)"

# Kea en el host (no en contenedor): la red interna es un segmento físico de GNS3.
# Si el paquete no está en este Ubuntu, se avisa y se sigue: que dé la IP GNS3.
if apt-cache show kea-dhcp4-server >/dev/null 2>&1; then
    if ! dpkg -s kea-dhcp4-server >/dev/null 2>&1; then
        apt-get install -y -qq kea-dhcp4-server
    fi
    sed -e "s/__INTERFAZ_INTERNA__/$INTERFAZ_INTERNA/" \
        -e "s/__IP_INTERNA__/$IP_INTERNA/" \
        -e "s/__DOMINIO__/$DOMINIO/" \
        red/kea/dhcp4.json > /etc/kea/kea-dhcp4.conf
    mkdir -p /kea/sockets /kea/leases /var/log/kea
    systemctl restart kea-dhcp4-server
    systemctl enable kea-dhcp4-server
    echo "Kea instalado: 10.4.0.10-200 con puerta y DNS en $IP_INTERNA."
else
    echo "AVISO: kea-dhcp4-server no está en los repos; DHCP vía GNS3 o instalación manual."
fi


echo "== 4. Secretos de la web (DS3 · IAW)"

# El htpasswd de la intranet se regenera SIEMPRE desde .env: quedan sincronizados.
apt-get install -y -qq apache2-utils
mkdir -p secretos
htpasswd -nbB "$INTRANET_USUARIO" "$INTRANET_CLAVE" > secretos/intranet.htpasswd
chmod 640 secretos/intranet.htpasswd


echo "== 5. Contenedores: dns, web, db y git (IAW · ASGBD · SRI)"

docker compose up -d --build

# Scripts auxiliares ejecutables (copia de seguridad, restauración de prueba)
chmod +x db/backup.sh db/restore-test.sh forgejo/backup.sh


echo
echo "Sede $SEDE instalada. Comprobaciones, una a una: docs/comprobaciones.md"
