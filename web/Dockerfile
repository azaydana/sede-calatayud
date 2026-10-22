FROM php:8.2-apache

# IAW: Imagen propia con Apache + PHP y soporte PostgreSQL.
# Se documenta cada línea (rúbrica Experto).

# Instalar la extensión pgsql/pdo_pgsql para conectar con PostgreSQL,
# activar los módulos de Apache necesarios (proxy para el vhost de Forgejo)
# y limpiar la caché de APT.
RUN apt-get update && apt-get install -y --no-install-recommends libpq-dev \
    && docker-php-ext-install pgsql pdo_pgsql \
    && a2enmod proxy proxy_http headers rewrite \
    && rm -rf /var/lib/apt/lists/*

# La configuración de los VirtualHosts (www + intranet + git) entra como copia.
COPY conf/site.conf /etc/apache2/sites-available/000-default.conf

# Contenido web:
# - publica: web principal (www.calatayud.empresa.lan)
COPY publica/ /var/www/html/publica/
# - privada: intranet protegida con htpasswd (intranet.calatayud.empresa.lan)
COPY privada/ /var/www/html/privada/
# - www: sitio de pruebas con su página de estado (estado.php)
COPY www/ /var/www/html/www/

# Permisos de lectura para Apache
RUN chmod -R 755 /var/www/html

# El fichero de contraseñas de la intranet NO entra en la imagen:
# se monta en compose.yaml desde secretos/intranet.htpasswd (fuera de Git, DS3),
# generado por instalar.sh a partir de .env.

# Puerto expuesto por Apache
EXPOSE 80
