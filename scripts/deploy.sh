#!/usr/bin/env bash
# =============================================================================
# Emprenddi — Deploy a producción (en la VM)
# Pull último código, instala deps, build assets, migra, restart.
# Usado por GitHub Actions y para deploys manuales.
# Idempotente.
# =============================================================================

set -euo pipefail

# Re-exec con sudo si no somos root. El container chown'a archivos a www-data
# (uid del container) que el usuario host no puede manipular después.
if [ "$EUID" -ne 0 ]; then
    exec sudo --preserve-env=PROJECT_DIR bash "$0" "$@"
fi

PROJECT_DIR="${PROJECT_DIR:-/opt/emprenddi}"
COMPOSE="docker compose --env-file .env.production -f docker-compose.prod.yml"

cd "$PROJECT_DIR"

echo "==> Pull últimos cambios desde origin/main..."
SCRIPT_FILE="$(realpath "$0")"
SCRIPT_HASH_BEFORE=$(md5sum "$SCRIPT_FILE" | awk '{print $1}')
git fetch origin main
git reset --hard origin/main
SCRIPT_HASH_AFTER=$(md5sum "$SCRIPT_FILE" | awk '{print $1}')

if [ "$SCRIPT_HASH_BEFORE" != "$SCRIPT_HASH_AFTER" ] && [ "${EMPRENDDI_DEPLOY_REEXEC:-0}" != "1" ]; then
    echo "==> deploy.sh cambió en este pull — re-ejecutando con la versión nueva..."
    export EMPRENDDI_DEPLOY_REEXEC=1
    exec bash "$SCRIPT_FILE" "$@"
fi

echo "==> Construyendo imagen app si hay cambios en Dockerfile/composer..."
$COMPOSE build app worker scheduler

echo "==> Levantando stack (postgres, redis, app, worker, scheduler, nginx)..."
$COMPOSE up -d

echo "==> Esperando a que postgres esté healthy..."
until $COMPOSE exec -T postgres pg_isready -U "${DB_USERNAME:-emprenddi}" >/dev/null 2>&1; do
    sleep 2
done

echo "==> Instalando dependencias PHP (composer install)..."
$COMPOSE exec -T -e COMPOSER_ALLOW_SUPERUSER=1 app composer install --no-dev --optimize-autoloader --no-interaction

echo "==> Permisos en storage/ y bootstrap/cache..."
$COMPOSE exec -T app chmod -R 775 storage bootstrap/cache
$COMPOSE exec -T app chown -R www-data:www-data storage bootstrap/cache || true

echo "==> Publicando assets de Filament a public/css/filament y public/js/filament..."
$COMPOSE exec -T app php artisan filament:assets

echo "==> Instalando dependencias JS y compilando assets Vite..."
$COMPOSE exec -T app npm ci --no-audit --no-fund
$COMPOSE exec -T app npm run build

echo "==> Migraciones (force, sin prompts)..."
$COMPOSE exec -T app php artisan migrate --force

echo "==> Seed de infraestructura (roles + planes + superadmin desde env + catálogos DIAN + métodos de pago)..."
$COMPOSE exec -T app php artisan db:seed --class=RolesSeeder --force
$COMPOSE exec -T app php artisan db:seed --class=PlansSeeder --force
$COMPOSE exec -T app php artisan db:seed --class=SuperAdminUserSeeder --force
$COMPOSE exec -T app php artisan db:seed --class=DianCatalogsSeeder --force
$COMPOSE exec -T app php artisan db:seed --class=DefaultPaymentMethodsSeeder --force

echo "==> Limpiando caches viejos y re-cacheando para producción..."
$COMPOSE exec -T app php artisan optimize:clear
$COMPOSE exec -T app php artisan config:cache
$COMPOSE exec -T app php artisan route:cache
$COMPOSE exec -T app php artisan view:cache
$COMPOSE exec -T app php artisan event:cache
$COMPOSE exec -T app php artisan filament:optimize

echo "==> Restart workers para que tomen el nuevo código..."
$COMPOSE exec -T app php artisan queue:restart
$COMPOSE restart worker scheduler

echo "==> Recreando nginx (re-bindea volume mount de prod.conf si cambió)..."
$COMPOSE up -d --no-deps --force-recreate nginx

echo ""
echo "✅ Deploy completado: $(date -Is)"
echo "   Commit desplegado: $(git rev-parse --short HEAD) — $(git log -1 --pretty=%s)"
echo "   Ver: https://github.com/tecmaxsas/laravel-emprenddi/commit/$(git rev-parse HEAD)"
