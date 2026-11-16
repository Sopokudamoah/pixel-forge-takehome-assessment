#!/usr/bin/env bash
set -e

echo "=== Deploying Pixel Forge Application ==="

# 1. Environment configuration
if [ ! -f .env ]; then
    echo "Creating .env from .env.example..."
    cp .env.example .env
fi

# Ensure SQLite database file exists if configured
if [ -f .env ] && grep -q "DB_CONNECTION=sqlite" .env; then
    mkdir -p database
    touch database/database.sqlite
fi

# 2. Install PHP Composer dependencies
echo "Installing Composer dependencies..."
composer install --no-interaction --prefer-dist --optimize-autoloader

# 3. Generate Application Key if not set
if ! grep -q "APP_KEY=base64:" .env; then
    echo "Generating Application Key..."
    php artisan key:generate --force
fi

# 4. Install Node dependencies and build frontend assets
echo "Installing NPM packages and building assets..."
if [ -f package-lock.json ]; then
    npm ci --no-audit --prefer-offline || npm install
else
    npm install
fi

npm run build

# 5. Run Database Migrations
echo "Running database migrations..."
php artisan migrate --force

# 6. Optimize and Cache Laravel Configuration
echo "Caching configuration, routes, and views..."
php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache

# 7. Storage link
echo "Creating storage symlink..."
php artisan storage:link || true

echo "=== Deployment completed successfully! ==="
