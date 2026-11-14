#!/bin/sh
set -e
cd /var/www/html

# Dependencias (solo si no existen, p. ej. en un clon nuevo)
if [ ! -f vendor/autoload.php ]; then
    composer install --no-interaction --prefer-dist
fi

# .env y clave de la aplicación
if [ ! -f .env ]; then
    cp .env.example .env
fi
if ! grep -q '^APP_KEY=base64' .env; then
    php artisan key:generate --force
fi

# Base de datos SQLite, migraciones y usuario auditor inicial
if grep -q '^DB_CONNECTION=sqlite' .env && [ ! -f database/database.sqlite ]; then
    touch database/database.sqlite
fi
php artisan migrate --force
php artisan db:seed --force

mkdir -p storage/app/private/uploads
chown -R www-data:www-data storage bootstrap/cache database 2>/dev/null || true

exec "$@"
