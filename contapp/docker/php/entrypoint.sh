#!/bin/sh
set -e

if [ ! -d vendor ]; then
    composer install --no-interaction --optimize-autoloader
fi

if [ ! -f .env ]; then
    cp .env.example .env
fi

if ! grep -q "^APP_KEY=base64" .env; then
    php artisan key:generate --ansi
fi

# route:cache reduce notablemente el tiempo de cada request en este entorno
# (bind mount de Docker en Windows: releer y re-parsear TODAS las rutas
# —incluida la serialización de Ziggy vía @routes— en cada request sin
# caché era, medido, el costo dominante). clear primero: si quedó una caché
# vieja de un arranque anterior con rutas ya removidas/renombradas, cachear
# encima sin limpiar la dejaría desactualizada. Trade-off: una ruta agregada
# DESPUÉS de este arranque no aparece hasta el próximo restart (o
# route:clear a mano) — aceptable acá, el contenedor se reinicia seguido.
#
# NUNCA config:cache acá. phpunit.xml fija DB_CONNECTION=sqlite,
# SESSION_DRIVER=array, etc. vía <env> para que los tests corran aislados en
# memoria — pero esas variables solo tienen efecto si Laravel LEE el .env en
# cada arranque. Con config cacheado, Laravel usa el array ya congelado
# (armado con el .env real, mysql) e IGNORA esos overrides por completo: los
# tests dejarían de usar sqlite en memoria y correrían migrate/RefreshDatabase
# contra la base de datos real de desarrollo. Ya pasó una vez (vació
# companies/users/licenses de bdcontapp) — no repetir este error.
php artisan route:clear
php artisan config:clear
php artisan migrate --force
php artisan route:cache

# storage/ y bootstrap/cache/ los escriben también el programador y las colas
# (scheduler, queue, queue-hacienda), que corren como root. Un archivo que
# crea root queda de root, y php-fpm (www-data) ya no lo puede escribir: el
# error real de una página se tapa con «laravel.log: Permission denied». Se
# devuelven a www-data en cada arranque (el route:cache de arriba también lo
# deja como root).
chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true

exec "$@"
