#!/bin/bash
#
# instalar.sh · Despliegue de la sede <nombre>
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

# ¿Existe el fichero .env con las claves?
# [ -f fichero ] es verdad si el fichero existe. El ! le da la vuelta: "si NO existe".
if [ ! -f .env ]; then
    echo "ERROR: falta .env. Haced: cp .env.example .env y cambiad las claves"
    exit 1
fi

# Cargamos las claves. A partir de aquí existen $DB_CLAVE, $INTRANET_CLAVE…
source .env

# Plantilla: aquí, el resto de comprobaciones (ejercicio del material de apoyo).


# Plantilla: un bloque por paso, en el orden en que se hacen. Cada uno empieza con su echo:
#
# echo "== 1. Usuarios y SSH (ASO)"
# ...órdenes...
#
# echo "== 2. Docker (ASO)"
# ...órdenes...


echo
echo "Sede $SEDE instalada. Comprobaciones, una a una: docs/comprobaciones.md"
