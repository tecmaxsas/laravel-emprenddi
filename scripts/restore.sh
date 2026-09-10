#!/usr/bin/env bash
# =============================================================================
# Emprenddi — Restaurar un respaldo
#
# La mitad que casi nadie prueba. Un respaldo que no se sabe restaurar no es un
# respaldo: es un archivo grande.
#
# Uso:
#   sudo bash scripts/restore.sh storage/backups/emprenddi-20260910-031500.tar.gz
#   sudo bash scripts/restore.sh gs://mi-bucket/emprenddi-20260910-031500.tar.gz
#   sudo bash scripts/restore.sh <archivo> --solo-base    # solo la base de datos
#   sudo bash scripts/restore.sh <archivo> --a-prueba     # extrae y valida, no restaura
#
# PISA LOS DATOS ACTUALES. Antes de tocar nada toma un respaldo de seguridad
# del estado presente, para que un error al restaurar no sea el final del
# camino sino una vuelta atrás.
# =============================================================================

set -euo pipefail

PROJECT_DIR="${PROJECT_DIR:-/opt/emprenddi}"

# Se eleva a root solo si de verdad hace falta. En la VM si: .env.production es
# de root y docker tambien. En un entorno de desarrollo donde el usuario ya
# tiene acceso a las dos cosas, pedir sudo solo impediria probar el script —y un
# respaldo que no se puede probar es un respaldo en el que no se puede confiar.
if [ "$EUID" -ne 0 ]; then
    if ! [ -r "$PROJECT_DIR/.env.production" ] || ! docker info >/dev/null 2>&1; then
        exec sudo --preserve-env=PROJECT_DIR,BACKUP_DB_CONTAINER,BACKUP_APP_CONTAINER bash "$0" "$@"
    fi
fi

cd "$PROJECT_DIR"

ORIGEN="${1:-}"
SOLO_BASE=false
A_PRUEBA=false

for arg in "${@:2}"; do
    case "$arg" in
        --solo-base) SOLO_BASE=true ;;
        --a-prueba) A_PRUEBA=true ;;
    esac
done

if [ -z "$ORIGEN" ]; then
    grep -E '^# ' "$0" | sed 's/^# \?//'
    exit 1
fi

leer_env() {
    grep -E "^${1}=" .env.production 2>/dev/null | tail -1 | cut -d= -f2- | tr -d '"' | tr -d "'" || true
}

DB_NAME="$(leer_env DB_DATABASE)"
DB_USER="$(leer_env DB_USERNAME)"
CONTENEDOR_DB="${BACKUP_DB_CONTAINER:-emprenddi_postgres}"
CONTENEDOR_APP="${BACKUP_APP_CONTAINER:-emprenddi_app}"
COMPOSE="docker compose --env-file .env.production -f docker-compose.prod.yml"

TRABAJO="$(mktemp -d)"
trap 'rm -rf "$TRABAJO"' EXIT

# ---- Traer el archivo -------------------------------------------------------
if [[ "$ORIGEN" == gs://* ]]; then
    echo "==> Descargando de $ORIGEN..."

    # Misma llave que usa el respaldo. Ojo con el detalle: no basta con
    # activarla, hay que FORZAR que gcloud la use —en una VM de Compute Engine
    # la cuenta de la maquina sigue siendo la predeterminada—.
    KEY_FILE="$(leer_env BACKUP_GCS_KEY_FILE)"

    if [ -n "$KEY_FILE" ] && [ -r "$KEY_FILE" ]; then
        export CLOUDSDK_CONFIG="$PROJECT_DIR/.gcloud-respaldos"
        mkdir -p "$CLOUDSDK_CONFIG"
        gcloud auth activate-service-account --key-file="$KEY_FILE" --quiet 2>/dev/null || true
        export CLOUDSDK_CORE_ACCOUNT="$(
            python3 -c "import json,sys;print(json.load(open(sys.argv[1])).get('client_email',''))" "$KEY_FILE" 2>/dev/null \
                || grep -o '"client_email"[^,]*' "$KEY_FILE" | cut -d'"' -f4
        )"
    fi

    gcloud storage cp "$ORIGEN" "$TRABAJO/respaldo.tar.gz" --quiet
    ARCHIVO="$TRABAJO/respaldo.tar.gz"
else
    ARCHIVO="$ORIGEN"
    [ -f "$ARCHIVO" ] || { echo "No existe: $ARCHIVO" >&2; exit 1; }
fi

echo "==> Abriendo el respaldo..."
tar -xzf "$ARCHIVO" -C "$TRABAJO"
CONTENIDO="$(find "$TRABAJO" -maxdepth 1 -type d -name '20*' | head -1)"

[ -n "$CONTENIDO" ] || { echo "El archivo no tiene la forma de un respaldo de Emprenddi." >&2; exit 1; }

# ---- Validar antes de tocar nada --------------------------------------------
echo "==> Comprobando el contenido..."
[ -f "$CONTENIDO/base-de-datos.dump" ] || { echo "Falta el dump de la base." >&2; exit 1; }

TABLAS=$(docker exec -i "$CONTENEDOR_DB" pg_restore --list < "$CONTENIDO/base-de-datos.dump" \
    | grep -c "TABLE DATA" || true)

echo "    Base de datos:  $TABLAS tablas con datos"
echo "    Archivos:       $([ -f "$CONTENIDO/archivos.tar.gz" ] && du -h "$CONTENIDO/archivos.tar.gz" | cut -f1 || echo 'no incluidos')"
echo "    Versión:        $(cat "$CONTENIDO/version.txt" 2>/dev/null || echo desconocida)"

if [ "${TABLAS:-0}" -lt 20 ]; then
    echo "El dump está incompleto: no se restaura." >&2
    exit 1
fi

if [ "$A_PRUEBA" = true ]; then
    echo "==> --a-prueba: el respaldo es válido y restaurable. No se tocó nada."
    exit 0
fi

# ---- Confirmar --------------------------------------------------------------
echo
echo "Esto REEMPLAZA la base de datos y los archivos actuales de $PROJECT_DIR."
read -r -p "Escribe RESTAURAR para continuar: " CONFIRMA
[ "$CONFIRMA" = "RESTAURAR" ] || { echo "Cancelado."; exit 0; }

# ---- Red de seguridad -------------------------------------------------------
# Antes de pisar nada, un respaldo de lo que hay. Restaurar el archivo
# equivocado es un error frecuente y tiene que ser reversible.
echo "==> Respaldando el estado actual antes de reemplazarlo..."
bash "$PROJECT_DIR/scripts/backup.sh" --solo-local || {
    echo "No se pudo respaldar el estado actual. Se detiene aquí a propósito." >&2
    exit 1
}

# ---- Restaurar --------------------------------------------------------------
echo "==> Poniendo la aplicación en pausa..."
$COMPOSE stop app worker scheduler nginx || true

echo "==> Restaurando la base de datos..."
# --clean --if-exists borra los objetos antes de recrearlos: sin eso, restaurar
# sobre una base con datos deja una mezcla de lo viejo y lo nuevo, que es el
# peor resultado posible.
docker exec -i "$CONTENEDOR_DB" pg_restore \
    -U "$DB_USER" -d "$DB_NAME" --clean --if-exists --no-owner --no-privileges \
    < "$CONTENIDO/base-de-datos.dump" || true
# pg_restore devuelve error por avisos inofensivos (objetos que no existían).
# Lo que importa se comprueba abajo.

if [ "$SOLO_BASE" = false ] && [ -f "$CONTENIDO/archivos.tar.gz" ]; then
    echo "==> Restaurando los archivos subidos..."
    rm -rf storage/app/public
    tar -xzf "$CONTENIDO/archivos.tar.gz" -C storage/app
    chown -R www-data:www-data storage/app/public 2>/dev/null || true
fi

echo "==> Levantando la aplicación..."
$COMPOSE up -d
sleep 5

docker exec "$CONTENEDOR_APP" php artisan optimize:clear || true
docker exec "$CONTENEDOR_APP" php artisan storage:link 2>/dev/null || true

# ---- Comprobar --------------------------------------------------------------
echo "==> Comprobando..."
docker exec "$CONTENEDOR_APP" php artisan migrate:status --no-interaction | tail -3 || true

echo
echo "==> Restauración terminada."
echo "    El .env.production NO se sobreescribió: si lo necesitas, está en el"
echo "    respaldo como env.production y hay que copiarlo a mano."
echo "    Revisa que la aplicación abra antes de darlo por bueno."
