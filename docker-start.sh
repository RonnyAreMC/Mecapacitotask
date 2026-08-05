#!/bin/sh
# Arranque del contenedor en Railway.
set -e

# 1) MPM a la fuerza: deja SOLO prefork (el que usa mod_php). Pase lo que pase
#    en el build (Railway a veces reactiva mpm_event al actualizar apache2 por
#    apt), esto garantiza un único MPM en cada arranque y evita el crash
#    "AH00534: More than one MPM loaded".
rm -f /etc/apache2/mods-enabled/mpm_event.*  /etc/apache2/mods-enabled/mpm_worker.*
ln -sf /etc/apache2/mods-available/mpm_prefork.load /etc/apache2/mods-enabled/mpm_prefork.load
ln -sf /etc/apache2/mods-available/mpm_prefork.conf /etc/apache2/mods-enabled/mpm_prefork.conf

# 2) Permisos del volumen montado (SQLite y subidas las escribe www-data).
chown -R www-data:www-data /var/www/html/admin/data /var/www/html/admin/uploads 2>/dev/null || true

# 3) Apache escucha en el puerto que inyecta Railway (80 por defecto en local).
: "${PORT:=80}"
sed -i "s/Listen 80/Listen ${PORT}/" /etc/apache2/ports.conf
sed -i "s/:80>/:${PORT}>/" /etc/apache2/sites-available/000-default.conf

exec apache2-foreground
