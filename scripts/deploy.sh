#!/usr/bin/env bash
# =============================================================================
# Emprenddi — Deploy a producción (en la VM)
#
# Deploy incremental: detecta qué cambió entre el HEAD anterior y el nuevo
# (Dockerfile, composer.lock, package*.json, migraciones, seeders, vistas,
# código PHP) y SOLO ejecuta los pasos costosos cuando hace falta. Esto baja
# un deploy típico de "sólo PHP/Blade" de ~30 min a ~30-60 segundos.
#
# Flags:
#   --full / --force   Ejecuta TODOS los pasos (build, composer, npm, etc.)
#                      Usar después de cambios al entorno (php.ini, .env)
#                      o si sospechas de estado inconsistente.
#   --no-build         Salta el docker build aunque cambiaran composer/Dockerfile.
#
# Idempotente. Usado por GitHub Actions y deploys manuales.
# =============================================================================

set -euo pipefail

# Re-exec con sudo si no somos root. El container chown'a archivos a www-data
# (uid del container) que el usuario host no puede manipular después.
if [ "$EUID" -ne 0 ]; then
    exec sudo --preserve-env=PROJECT_DIR bash "$0" "$@"
fi

PROJECT_DIR="${PROJECT_DIR:-/opt/emprenddi}"
COMPOSE="docker compose --env-file .env.production -f docker-compose.prod.yml"

# ---- Parse flags ------------------------------------------------------------
FORCE_ALL=false
SKIP_BUILD=false
for arg in "$@"; do
    case "$arg" in
        --full|--force) FORCE_ALL=true ;;
        --no-build) SKIP_BUILD=true ;;
        -h|--help)
            grep -E '^# ' "$0" | sed 's/^# \?//'
            exit 0
            ;;
    esac
done

cd "$PROJECT_DIR"

# ---- Pull -------------------------------------------------------------------
echo "==> Pull últimos cambios desde origin/main..."

# Si venimos de un re-exec (el propio deploy.sh cambió en el pull anterior),
# el reset ya se ejecutó: usamos el PRE_HEAD original que el padre nos pasa.
# Si no, lo capturamos del HEAD actual antes de fetch+reset.
if [ -n "${EMPRENDDI_DEPLOY_PRE_HEAD:-}" ]; then
    PRE_HEAD="$EMPRENDDI_DEPLOY_PRE_HEAD"
    echo "   (continuación post re-exec, PRE_HEAD heredado: $(git rev-parse --short "$PRE_HEAD" 2>/dev/null || echo "$PRE_HEAD"))"
else
    PRE_HEAD=$(git rev-parse HEAD 2>/dev/null || echo "")
fi

SCRIPT_FILE="$(realpath "$0")"
SCRIPT_HASH_BEFORE=$(md5sum "$SCRIPT_FILE" | awk '{print $1}')
git fetch origin main
git reset --hard origin/main
POST_HEAD=$(git rev-parse HEAD)
SCRIPT_HASH_AFTER=$(md5sum "$SCRIPT_FILE" | awk '{print $1}')

if [ "$SCRIPT_HASH_BEFORE" != "$SCRIPT_HASH_AFTER" ] && [ "${EMPRENDDI_DEPLOY_REEXEC:-0}" != "1" ]; then
    echo "==> deploy.sh cambió en este pull — re-ejecutando con la versión nueva..."
    export EMPRENDDI_DEPLOY_REEXEC=1
    export EMPRENDDI_DEPLOY_PRE_HEAD="$PRE_HEAD"
    exec bash "$SCRIPT_FILE" "$@"
fi

# ---- Detectar cambios -------------------------------------------------------
if [ -z "$PRE_HEAD" ] || [ "$PRE_HEAD" = "$POST_HEAD" ]; then
    if [ "$FORCE_ALL" = false ]; then
        echo "==> Sin cambios desde el último deploy (HEAD: $(git rev-parse --short HEAD))."
        echo "   Usa --full para forzar reconstrucción completa."
        # Aun así, garantizamos que el stack esté arriba (idempotente).
        $COMPOSE up -d
        exit 0
    fi
fi

if [ "$FORCE_ALL" = true ]; then
    CHANGED="__ALL__"
    echo "==> Modo --full: ejecutando todos los pasos."
else
    CHANGED=$(git diff --name-only "$PRE_HEAD" "$POST_HEAD" || echo "")
    echo "==> $(echo "$CHANGED" | wc -l) archivo(s) cambiado(s)."
fi

changed() {
    [ "$CHANGED" = "__ALL__" ] && return 0
    echo "$CHANGED" | grep -qE "$1"
}

NEEDS_BUILD=false
NEEDS_COMPOSER=false
NEEDS_NPM=false
NEEDS_MIGRATE=false
NEEDS_SEEDERS=false
NEEDS_FILAMENT_ASSETS=false
NEEDS_VIEW_CACHE=false

changed '^(Dockerfile|docker/php/|composer\.(json|lock))'            && NEEDS_BUILD=true
changed '^composer\.(json|lock)$'                                    && NEEDS_COMPOSER=true
changed '^(package(-lock)?\.json|vite\.config\.|tailwind\.config\.|resources/(css|js)/)' && NEEDS_NPM=true
changed '^database/migrations/'                                      && NEEDS_MIGRATE=true
changed '^database/seeders/'                                         && NEEDS_SEEDERS=true
changed '^composer\.lock$|^app/Providers/Filament/'                  && NEEDS_FILAMENT_ASSETS=true
changed '\.blade\.php$|^resources/views/'                            && NEEDS_VIEW_CACHE=true

[ "$SKIP_BUILD" = true ] && NEEDS_BUILD=false

echo "==> Plan de deploy:"
printf "   %-22s %s\n" "docker build:"      "$NEEDS_BUILD"
printf "   %-22s %s\n" "composer install:"  "$NEEDS_COMPOSER"
printf "   %-22s %s\n" "npm + vite build:"  "$NEEDS_NPM"
printf "   %-22s %s\n" "migrate:"           "$NEEDS_MIGRATE"
printf "   %-22s %s\n" "seeders:"           "$NEEDS_SEEDERS"
printf "   %-22s %s\n" "filament:assets:"   "$NEEDS_FILAMENT_ASSETS"
printf "   %-22s %s\n" "view:cache rebuild:" "$NEEDS_VIEW_CACHE"

# ---- Stack arriba -----------------------------------------------------------
echo "==> Asegurando stack arriba..."
if [ "$NEEDS_BUILD" = true ]; then
    echo "==> Construyendo imagen app/worker/scheduler..."
    $COMPOSE build app worker scheduler
fi
$COMPOSE up -d

echo "==> Esperando a que postgres esté healthy..."
until $COMPOSE exec -T postgres pg_isready -U "${DB_USERNAME:-emprenddi}" >/dev/null 2>&1; do
    sleep 1
done

# ---- Dependencias -----------------------------------------------------------
if [ "$NEEDS_COMPOSER" = true ]; then
    echo "==> composer install --no-dev..."
    $COMPOSE exec -T -e COMPOSER_ALLOW_SUPERUSER=1 app composer install --no-dev --optimize-autoloader --no-interaction
fi

if [ "$NEEDS_NPM" = true ]; then
    echo "==> npm ci + vite build..."
    $COMPOSE exec -T app npm ci --no-audit --no-fund
    $COMPOSE exec -T app npm run build
fi

if [ "$NEEDS_FILAMENT_ASSETS" = true ]; then
    echo "==> filament:assets..."
    $COMPOSE exec -T app php artisan filament:assets
fi

# ---- Permisos (siempre, son rápidos) ----------------------------------------
$COMPOSE exec -T app chmod -R 775 storage bootstrap/cache || true
$COMPOSE exec -T app chown -R www-data:www-data storage bootstrap/cache || true

# ---- Base de datos ----------------------------------------------------------
if [ "$NEEDS_MIGRATE" = true ]; then
    echo "==> Migraciones..."
    $COMPOSE exec -T app php artisan migrate --force
fi

if [ "$NEEDS_SEEDERS" = true ]; then
    echo "==> Seeders de infraestructura..."
    $COMPOSE exec -T app php artisan db:seed --class=RolesSeeder --force
    $COMPOSE exec -T app php artisan db:seed --class=PlansSeeder --force
    $COMPOSE exec -T app php artisan db:seed --class=SuperAdminUserSeeder --force
    $COMPOSE exec -T app php artisan db:seed --class=DianCatalogsSeeder --force
    $COMPOSE exec -T app php artisan db:seed --class=DefaultPaymentMethodsSeeder --force
fi

# ---- Caches (siempre, son <10s) ---------------------------------------------
echo "==> Recacheando..."
$COMPOSE exec -T app php artisan optimize:clear
$COMPOSE exec -T app php artisan config:cache
$COMPOSE exec -T app php artisan route:cache
$COMPOSE exec -T app php artisan view:cache
$COMPOSE exec -T app php artisan event:cache
if [ "$NEEDS_FILAMENT_ASSETS" = true ] || [ "$FORCE_ALL" = true ]; then
    $COMPOSE exec -T app php artisan filament:optimize
fi

# ---- Workers + app restart --------------------------------------------------
# queue:restart hace que workers terminen su job actual y reinicien tomando
# el nuevo código. Restart de app es para que PHP-FPM/opcache lo tomen.
echo "==> Reiniciando workers y app para tomar código nuevo..."
$COMPOSE exec -T app php artisan queue:restart
$COMPOSE restart worker scheduler app

# Nginx solo si cambió su config
if changed '^docker/nginx/'; then
    echo "==> Recreando nginx (cambió su configuración)..."
    $COMPOSE up -d --no-deps --force-recreate nginx
fi

echo ""
echo "✅ Deploy completado: $(date -Is)"
echo "   $(git rev-parse --short "$PRE_HEAD" 2>/dev/null || echo "init") → $(git rev-parse --short HEAD) — $(git log -1 --pretty=%s)"
echo "   Ver: https://github.com/tecmaxsas/laravel-emprenddi/commit/$(git rev-parse HEAD)"
