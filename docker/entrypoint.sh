#!/bin/bash
echo "Caching config..."
php artisan config:clear
php artisan cache:clear

echo "[ENTRYPOINT] Cacheando config..."
php artisan config:cache

echo "Laravel log config..."
chown -R www-data:www-data /var/www/storage /var/www/bootstrap/cache
chmod -R 775 /var/www/storage /var/www/bootstrap/cache

echo "[ENTRYPOINT] Ajustando permissões de log..."
touch /var/www/storage/logs/laravel.log
chown www-data:www-data /var/www/storage/logs/laravel.log
chmod 664 /var/www/storage/logs/laravel.log

echo "[ENTRYPOINT] Executando migrations..."
php /var/www/artisan migrate --force

exec "$@"