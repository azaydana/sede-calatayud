# web/ · IAW

`Dockerfile` de la imagen propia, sitios de Apache y contenido (`www`, `intranet`, página de estado).

FROM php:8.2-apache

# IAW: Imagen propia con Apache + PHP y soporte PostgreSQL.
# Se documenta cada línea.

# Instalar la extensión pgsql/pdo_pgsql para conectar con PostgreSQL,
# activar los módulos de Apache necesarios y limpiar la caché de APT.
RUN apt-get update && apt-get install -y --no-install-recommends libpq-dev \
    && docker-php-ext-install pgsql pdo_pgsql \
    && a2enmod proxy proxy_http headers rewrite \
    && rm -rf /var/lib/apt/lists/*

# Copiar el fichero de contraseñas de la intranet (auth basic)
COPY conf/htpasswd /etc/apache2/conf-custom/htpasswd

# Copiar la configuración de los VirtualHosts (www + intranet)
COPY conf/sitios.conf /etc/apache2/sites-available/000-default.conf

# Copiar el contenido web a la imagen (pueden cambiar ya que son archivos muy basicos):
# - publica: web principal (www.calatayud.empresa.lan)
COPY publica/ /var/www/html/publica/
# - privada: intranet protegida con htpasswd (intranet.calatayud.empresa.lan)
COPY privada/ /var/www/html/privada/
# - www: copia del sitio con su página de estado (estado.php)
COPY www/ /var/www/html/www/

# Permitir acceso a los directorios copiados y dar permisos de lectura
RUN chmod -R 755 /var/www/html

# Puerto expuesto por Apache
EXPOSE 80


El fichero de contraseñas de la intranet no va aquí: va en `secretos/intranet.htpasswd`, fuera de Git, y se monta desde `compose.yaml`.

Plantilla: borrad esta línea y explicad qué hay.
