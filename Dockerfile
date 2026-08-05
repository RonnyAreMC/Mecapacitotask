# InnoTech Hub — panel PHP sobre Apache.
# Apache (no nginx) a propósito: así se respetan los .htaccess que bloquean
# el acceso web a admin/data (config con credenciales) y admin/lib.
FROM php:8.2-apache

# SQLite (PDO) + mod_rewrite. pdo_sqlite necesita las cabeceras libsqlite3-dev
# (la imagen base no las trae).
RUN apt-get update \
 && apt-get install -y --no-install-recommends libsqlite3-dev \
 && docker-php-ext-install pdo_sqlite \
 && a2enmod rewrite \
 && rm -rf /var/lib/apt/lists/*

# Seguridad a nivel de servidor, independiente de los .htaccess: aunque un
# volumen de Railway tape el .htaccess de admin/data, la carpeta con las
# credenciales y la base SQLite NUNCA queda accesible desde la web.
RUN printf '%s\n' \
    '<Directory /var/www/html/>' \
    '    AllowOverride All' \
    '    Require all granted' \
    '</Directory>' \
    '<Directory /var/www/html/admin/data/>' \
    '    Require all denied' \
    '</Directory>' \
    '<Directory /var/www/html/admin/lib/>' \
    '    Require all denied' \
    '</Directory>' \
    > /etc/apache2/conf-available/innotech.conf \
 && a2enconf innotech

# Arranque: normaliza el MPM y levanta Apache en el $PORT de Railway.
COPY docker-start.sh /usr/local/bin/docker-start.sh
RUN chmod +x /usr/local/bin/docker-start.sh

# Código de la app.
COPY . /var/www/html/

# Carpetas que la app escribe en runtime. En Railway se monta un Volume en
# /var/www/html/admin/data para que la base SQLite sobreviva a los redeploys.
RUN mkdir -p /var/www/html/admin/data /var/www/html/admin/uploads \
 && chown -R www-data:www-data /var/www/html/admin/data /var/www/html/admin/uploads \
 && rm -f /var/www/html/docker-start.sh /var/www/html/Dockerfile /var/www/html/.dockerignore

CMD ["/usr/local/bin/docker-start.sh"]
