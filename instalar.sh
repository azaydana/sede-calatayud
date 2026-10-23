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

echo "== 0. Comprobaciones y paquetes"

# Primero mira que todo está listo y, si falta algo, dice qué y para (exit 1).
# Solo al final instala los paquetes que falten.

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

# ¿Los ficheros que van a entrar en contenedores están en su sitio?
for F in web/Dockerfile web/conf/site.conf db/init/01-esquema.sql dns/config/named.conf \
         dns/config/named.conf.local dns/config/named.conf.options \
         dns/zones/db.calatayud.empresa.lan dns/zones/db.10.4 red/kea/dhcp4.json; do
    if [ ! -f "$F" ]; then
        echo "ERROR: falta $F en el repositorio"
        exit 1
    fi
done

# ¿La interfaz de sede.env existe? Sin ella el netplan dejaría el servidor sin red.
if [ ! -d "/sys/class/net/$INTERFAZ_AULA" ]; then
    echo "ERROR: la interfaz del aula no existe: $INTERFAZ_AULA (miradla con ip a y corregid sede.env)"
    exit 1
fi
# Las dos IPs van en la misma tarjeta (red/netplan/99-sede.yaml)
if [ "$INTERFAZ_INTERNA" != "$INTERFAZ_AULA" ]; then
    echo "ERROR: INTERFAZ_INTERNA tiene que ser la misma que INTERFAZ_AULA en sede.env"
    exit 1
fi

# ¿Hay claves públicas para instalar? (sistema/usuarios/*.pub, una por persona)
N_CLAVES=$(find sistema/usuarios -maxdepth 1 -name '*.pub' 2>/dev/null | wc -l)
if [ "$N_CLAVES" -eq 0 ]; then
    echo "AVISO: no hay claves públicas en sistema/usuarios/ — el paso 1 solo instalará sshd base"
fi

# Paquetes que la sede necesita. En un Ubuntu recién instalado no hay Docker.
# Solo se instala lo que falta (DS8).
PAQUETES=""
for P in docker.io docker-compose-v2 docker-buildx apache2-utils nftables; do
    dpkg -s "$P" >/dev/null 2>&1 || PAQUETES="$PAQUETES $P"
done
if [ -n "$PAQUETES" ]; then
    echo "Instalando:$PAQUETES"
    apt-get update -qq
    apt-get install -y -qq $PAQUETES
fi
systemctl enable --now docker
if ! docker compose version >/dev/null 2>&1; then
    echo "ERROR: docker compose no funciona tras instalar los paquetes"
    exit 1
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
    # Administradores de la sede: grupo sudo (sudo les pedirá su contraseña)
    usermod -aG sudo "$PERSONA"
    if passwd -S "$PERSONA" | awk '{exit !($2 == "L" || $2 == "NP")}'; then
        echo "AVISO: $PERSONA no tiene contraseña: no podrá usar sudo. Ponédsela con: sudo passwd $PERSONA"
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
# Tampoco si quien ejecuta esto (sudo) no tiene clave: perdería su propio SSH.
ENDURECER=no
if [ "$N_CLAVES" -gt 0 ]; then
    ENDURECER=si
    QUIEN="${SUDO_USER:-root}"
    CASA=$(getent passwd "$QUIEN" | cut -d: -f6)
    if [ "$QUIEN" != "root" ] && [ ! -s "$CASA/.ssh/authorized_keys" ]; then
        ENDURECER=no
        echo "AVISO: $QUIEN no tiene clave SSH: no se cierra la contraseña para no dejarle fuera."
        echo "       Entrad con uno de los usuarios de sistema/usuarios/ y repetid sudo ./instalar.sh"
    fi
fi
if [ "$ENDURECER" = "si" ]; then
    # Antes se llamaba 99-… y cloud-init (50-…) le ganaba: fuera el viejo
    rm -f /etc/ssh/sshd_config.d/99-sede-hardening.conf
    install -m 644 sistema/ssh/00-sede-hardening.conf /etc/ssh/sshd_config.d/00-sede-hardening.conf
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
# Si el servidor aún no tiene IP_AULA, quien entró por SSH a la IP de la
# instalación (DHCP) pierde la sesión al aplicar. (sudo borra SSH_CONNECTION.)
if ! ip -4 -o addr show "$INTERFAZ_AULA" | grep -qw "$IP_AULA"; then
    echo "AVISO: el servidor pasa a $IP_AULA: si estáis por SSH a otra IP, la sesión se cortará."
    echo "       Volved a entrar a $IP_AULA y repetid sudo ./instalar.sh (Ctrl+C en 10 s para parar)."
    sleep 10
fi
netplan apply

# El 53 del servidor es del contenedor dns: el stub de systemd-resolved
# (127.0.0.53:53) se apaga y el servidor resuelve con lo que dé el netplan.
if systemctl is-active --quiet systemd-resolved; then
    mkdir -p /etc/systemd/resolved.conf.d
    install -m 644 red/resolved/90-sede.conf /etc/systemd/resolved.conf.d/90-sede.conf
    systemctl restart systemd-resolved
    ln -sf /run/systemd/resolve/resolv.conf /etc/resolv.conf
fi

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


echo "== 3. DHCP de la red interna: configuración de Kea (SRI · ASO)"

# Kea va en contenedor (servicio kea de compose.yaml, red del anfitrión): no
# depende de que este Ubuntu traiga el paquete. Aquí solo se genera su
# configuración; arranca en el paso 5 con los demás.
mkdir -p generado/kea
sed -e "s/__INTERFAZ_INTERNA__/$INTERFAZ_INTERNA/" \
    -e "s/__IP_INTERNA__/$IP_INTERNA/" \
    -e "s/__DOMINIO__/$DOMINIO/" \
    red/kea/dhcp4.json > generado/kea/dhcp4.json
chmod 644 generado/kea/dhcp4.json
# Un Kea del host (versión anterior de este script) le quitaría el puerto 67
if systemctl is-enabled --quiet kea-dhcp4-server 2>/dev/null; then
    systemctl disable --now kea-dhcp4-server
fi
echo "Kea: 10.4.0.10-200 con puerta y DNS en $IP_INTERNA (generado/kea/dhcp4.json)."


echo "== 4. Secretos de la web (DS3 · IAW)"

# El htpasswd de la intranet se regenera SIEMPRE desde .env: quedan sincronizados.
mkdir -p secretos
htpasswd -nbB "$INTRANET_USUARIO" "$INTRANET_CLAVE" > secretos/intranet.htpasswd
# Lo lee Apache dentro del contenedor como www-data (gid 33 en la imagen php)
chgrp 33 secretos/intranet.htpasswd
chmod 640 secretos/intranet.htpasswd


echo "== 5. Contenedores: dns, web, db, git y kea (IAW · ASGBD · SRI)"

docker compose up -d --build

# Scripts auxiliares ejecutables (copia de seguridad, restauración de prueba)
chmod +x db/backup.sh db/restore-test.sh forgejo/backup.sh


echo
echo "Sede $SEDE instalada. Comprobaciones, una a una: docs/comprobaciones.md"
