# InnoTech Académico — panel PHP sobre Apache.
# Apache (no nginx) a propósito: así se respetan los .htaccess que bloquean
# el acceso web a admin/data (config con credenciales) y admin/lib.
FROM php:8.2-apache

# SQLite (PDO) + mod_rewrite. AllowOverride All para que los .htaccess manden.
# pdo_sqlite necesita las cabeceras libsqlite3-dev (la imagen base no las trae).
# Al instalar por apt, apache2 se actualiza y activa mpm_event encima del
# mpm_prefork que ya trae la imagen; con dos MPM Apache no arranca. Dejamos
# solo mpm_prefork (el que usa mod_php).
RUN apt-get update \
 && apt-get install -y --no-install-recommends libsqlite3-dev \
 && docker-php-ext-install pdo_sqlite \
 && (a2dismod mpm_event mpm_worker 2>/dev/null || true) \
 && a2enmod mpm_prefork rewrite \
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

# Código de la app.
COPY . /var/www/html/

# Carpetas que la app escribe en runtime. En Railway se monta un Volume en
# /var/www/html/admin/data para que la base SQLite sobreviva a los redeploys.
RUN mkdir -p /var/www/html/admin/data /var/www/html/admin/uploads \
 && chown -R www-data:www-data /var/www/html/admin/data /var/www/html/admin/uploads

# Railway inyecta $PORT; Apache tiene que escuchar ahí (por defecto 80 en local).
CMD chown -R www-data:www-data /var/www/html/admin/data /var/www/html/admin/uploads 2>/dev/null; \
    sed -i "s/Listen 80/Listen ${PORT:-80}/" /etc/apache2/ports.conf && \
    sed -i "s/:80>/:${PORT:-80}>/" /etc/apache2/sites-available/000-default.conf && \
    exec apache2-foreground
