#!/usr/bin/env bash
# =============================================================================
# Emprenddi — Respaldo de producción
#
# Respalda LO QUE NO SE PUEDE VOLVER A CREAR:
#
#   1. La base de datos      — las ventas, la cartera, el inventario.
#   2. storage/app/public    — logos, fotos de productos, portadas de catálogo.
#   3. .env.production       — y con él APP_KEY, sin la cual las llaves
#                              cifradas (Anthropic, etc.) quedan ilegibles
#                              aunque se restaure la base entera.
#
# NO respalda el código (está en git), ni vendor, ni node_modules, ni los
# cachés, ni los logs. Restaurar eso es un `git pull` y un deploy.
#
# Corre en el HOST, no dentro del contenedor, y a propósito: un respaldo tiene
# que poder tomarse justo cuando la aplicación está rota, que es cuando hace
# falta.
#
# Uso:
#   sudo bash scripts/backup.sh                 # respaldo normal
#   sudo bash scripts/backup.sh --solo-local    # sin subir a la nube
#   sudo bash scripts/backup.sh --instalar-cron # deja el cron diario puesto
#
# Variables (en .env.production):
#   BACKUP_GCS_BUCKET   gs://mi-bucket-de-respaldos   ← el respaldo de verdad
#   BACKUP_RETENCION    dias que se conservan (por defecto 30)
#   BACKUP_DIR          donde se guardan localmente (por defecto storage/backups)
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

SOLO_LOCAL=false
for arg in "$@"; do
    case "$arg" in
        --solo-local) SOLO_LOCAL=true ;;
        --instalar-cron) INSTALAR_CRON=true ;;
        -h|--help) grep -E '^# ' "$0" | sed 's/^# \?//'; exit 0 ;;
    esac
done

# ---- Configuración ----------------------------------------------------------
# Se lee de .env.production sin exportar todo el archivo: ahí hay contraseñas
# que no tienen por qué acabar en el entorno de este script.
leer_env() {
    grep -E "^${1}=" .env.production 2>/dev/null | tail -1 | cut -d= -f2- | tr -d '"' | tr -d "'" || true
}

BACKUP_DIR="$(leer_env BACKUP_DIR)"
BACKUP_DIR="${BACKUP_DIR:-$PROJECT_DIR/storage/backups}"
BUCKET="$(leer_env BACKUP_GCS_BUCKET)"
RETENCION="$(leer_env BACKUP_RETENCION)"
RETENCION="${RETENCION:-30}"

DB_NAME="$(leer_env DB_DATABASE)"
DB_USER="$(leer_env DB_USERNAME)"

# Los nombres de los contenedores se pueden sobreescribir. Sirve para probar el
# script contra un entorno de desarrollo sin tocar produccion, y para que un
# rename del stack no rompa los respaldos en silencio.
CONTENEDOR_DB="${BACKUP_DB_CONTAINER:-emprenddi_postgres}"

SELLO="$(date +%Y%m%d-%H%M%S)"
DESTINO="$BACKUP_DIR/$SELLO"
ESTADO="$BACKUP_DIR/estado.json"

mkdir -p "$DESTINO"

# ---- El estado se escribe pase lo que pase ----------------------------------
# Un respaldo que falla en silencio es peor que no tener respaldo: da la
# tranquilidad sin dar la protección. `backup:status` lee este archivo y avisa.
RESULTADO="fallo"
DETALLE="interrumpido antes de terminar"

escribir_estado() {
    cat > "$ESTADO" <<JSON
{
  "resultado": "$RESULTADO",
  "detalle": "$DETALLE",
  "fecha": "$(date -Iseconds)",
  "archivo": "${ARCHIVO:-}",
  "tamano_bytes": ${TAMANO:-0},
  "fuera_del_servidor": ${FUERA:-false}
}
JSON
    chmod 600 "$ESTADO" 2>/dev/null || true
}

trap escribir_estado EXIT

echo "==> Respaldo $SELLO"

# ---- 1. Base de datos -------------------------------------------------------
# Formato custom (-Fc): comprimido y restaurable tabla por tabla con
# pg_restore, que es lo que se necesita cuando hay que recuperar UNA cosa y no
# volver a montar todo.
echo "   • Base de datos..."
docker exec "$CONTENEDOR_DB" pg_dump \
    -U "$DB_USER" -d "$DB_NAME" -Fc --no-owner --no-privileges \
    > "$DESTINO/base-de-datos.dump"

# Un dump vacío pesa unos cientos de bytes y se ve igual de bien en un `ls`.
# Se comprueba que pg_restore lo pueda leer y que tenga tablas de verdad.
TABLAS=$(docker exec -i "$CONTENEDOR_DB" pg_restore --list < "$DESTINO/base-de-datos.dump" \
    | grep -c "TABLE DATA" || true)

if [ "${TABLAS:-0}" -lt 20 ]; then
    DETALLE="el dump solo tiene ${TABLAS:-0} tablas con datos: algo salió mal"
    echo "   ✗ $DETALLE" >&2
    exit 1
fi

echo "     $TABLAS tablas con datos"

# ---- 2. Archivos subidos ----------------------------------------------------
# Solo storage/app/public: lo que subieron los usuarios. Los cachés de vistas y
# las sesiones se regeneran solos y ocupan más que todo lo demás junto.
echo "   • Archivos subidos..."
if [ -d "storage/app/public" ]; then
    tar -czf "$DESTINO/archivos.tar.gz" -C storage/app public
else
    echo "     (no hay storage/app/public todavía)"
fi

# ---- 3. Configuración -------------------------------------------------------
# Va con permisos 600: tiene la contraseña de la base y APP_KEY.
echo "   • Configuración..."
cp .env.production "$DESTINO/env.production"
chmod 600 "$DESTINO/env.production"

# La versión desplegada, para saber contra qué código restaurar.
git rev-parse HEAD > "$DESTINO/version.txt" 2>/dev/null || echo "desconocida" > "$DESTINO/version.txt"

# ---- Empaquetar -------------------------------------------------------------
ARCHIVO="emprenddi-$SELLO.tar.gz"
tar -czf "$BACKUP_DIR/$ARCHIVO" -C "$BACKUP_DIR" "$SELLO"
rm -rf "$DESTINO"
chmod 600 "$BACKUP_DIR/$ARCHIVO"

TAMANO=$(stat -c%s "$BACKUP_DIR/$ARCHIVO")
echo "   • Empaquetado: $ARCHIVO ($(numfmt --to=iec "$TAMANO" 2>/dev/null || echo "$TAMANO bytes"))"

# ---- Fuera del servidor -----------------------------------------------------
# Esta es la parte que convierte el ejercicio en un respaldo. Un archivo en el
# mismo disco no protege contra lo que de verdad pasa: que el disco muera, que
# borren la VM, que un ransomware cifre el servidor.
FUERA=false

if [ "$SOLO_LOCAL" = true ]; then
    echo "   • Copia externa omitida (--solo-local)"
elif [ -z "$BUCKET" ]; then
    echo "   ⚠ BACKUP_GCS_BUCKET no está configurado: el respaldo queda SOLO en este servidor." >&2
    echo "     Si el disco falla, se pierde con él." >&2
elif ! command -v gcloud >/dev/null 2>&1; then
    echo "   ⚠ gcloud no está instalado: no se pudo subir la copia externa." >&2
else
    echo "   • Subiendo a $BUCKET..."
    if gcloud storage cp "$BACKUP_DIR/$ARCHIVO" "$BUCKET/$ARCHIVO" --quiet; then
        FUERA=true
        echo "     Listo."
    else
        DETALLE="el respaldo se creó pero no se pudo subir a $BUCKET"
        echo "   ✗ $DETALLE" >&2
        exit 1
    fi
fi

# ---- Limpieza ---------------------------------------------------------------
echo "   • Borrando respaldos locales de más de $RETENCION días..."
find "$BACKUP_DIR" -maxdepth 1 -name 'emprenddi-*.tar.gz' -mtime "+$RETENCION" -delete

RESULTADO="ok"
DETALLE="respaldo completo"

echo "==> Respaldo terminado."
[ "$FUERA" = true ] || echo "    OJO: este respaldo NO salió del servidor."

# ---- Cron -------------------------------------------------------------------
if [ "${INSTALAR_CRON:-false}" = true ]; then
    LINEA="15 3 * * * PROJECT_DIR=$PROJECT_DIR bash $PROJECT_DIR/scripts/backup.sh >> $PROJECT_DIR/storage/logs/backup.log 2>&1"
    if crontab -l 2>/dev/null | grep -q 'scripts/backup.sh'; then
        echo "==> El cron de respaldo ya estaba puesto."
    else
        (crontab -l 2>/dev/null; echo "$LINEA") | crontab -
        echo "==> Cron instalado: todos los días a las 3:15 a. m."
    fi
fi
