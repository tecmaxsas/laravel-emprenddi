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
#   sudo bash scripts/backup.sh --probar-nube   # diagnostica la conexion a GCS
#
# Variables (en .env.production):
#   BACKUP_GCS_BUCKET   gs://mi-bucket-de-respaldos   ← el respaldo de verdad
#   BACKUP_GCS_KEY_FILE llave JSON de cuenta de servicio, si la VM no tiene
#                       permiso para escribir en Storage (ver docs/BACKUPS.md)
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
        --probar-nube) PROBAR_NUBE=true ;;
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

# Llave de cuenta de servicio, opcional.
#
# Una VM de Compute Engine pide sus credenciales al metadata server, y esas
# credenciales estan limitadas por los "scopes" con que se CREO la maquina. Si
# se creo con el scope de solo lectura de Storage —el habitual por defecto—, la
# subida falla con «Provided scope(s) are not authorized» por mas permisos IAM
# que tenga la cuenta, y ampliarlos exige APAGAR la VM.
#
# Con una llave propia gcloud no pasa por el metadata server y los scopes de la
# instancia dejan de aplicar. Se usa solo para esta subida: no toca la sesion de
# gcloud de la maquina.
KEY_FILE="$(leer_env BACKUP_GCS_KEY_FILE)"
RETENCION="$(leer_env BACKUP_RETENCION)"
RETENCION="${RETENCION:-30}"

DB_NAME="$(leer_env DB_DATABASE)"
DB_USER="$(leer_env DB_USERNAME)"

# Los nombres de los contenedores se pueden sobreescribir. Sirve para probar el
# script contra un entorno de desarrollo sin tocar produccion, y para que un
# rename del stack no rompa los respaldos en silencio.
CONTENEDOR_DB="${BACKUP_DB_CONTAINER:-emprenddi_postgres}"

# ---- Autenticación con la nube ---------------------------------------------
# `CLOUDSDK_AUTH_CREDENTIAL_FILE_OVERRIDE` parecía lo más limpio pero no es lo
# que gcloud espera para una llave de cuenta de servicio: la petición sale con
# credenciales a medias y Storage responde un error vacío —`GcsApiError('')`—
# que no dice nada. Lo que sí funciona es activar la cuenta, y para no pisar la
# sesión de gcloud de la máquina se hace en una configuración aparte.
autenticar_nube() {
    [ -n "$KEY_FILE" ] || return 0

    if [ ! -r "$KEY_FILE" ]; then
        echo "   ✗ BACKUP_GCS_KEY_FILE apunta a $KEY_FILE y no se puede leer." >&2
        return 1
    fi

    export CLOUDSDK_CONFIG="$PROJECT_DIR/.gcloud-respaldos"
    mkdir -p "$CLOUDSDK_CONFIG"
    chmod 700 "$CLOUDSDK_CONFIG"

    # Se activa una sola vez; despues queda en esa configuracion.
    if ! gcloud auth list --filter=status:ACTIVE --format='value(account)' 2>/dev/null | grep -q .; then
        gcloud auth activate-service-account --key-file="$KEY_FILE" --quiet || {
            echo "   ✗ La llave no sirve para autenticarse. ¿Está completa el JSON?" >&2
            return 1
        }
    fi

    return 0
}

# ---- Diagnóstico de la nube -------------------------------------------------
# Existe porque «GcsApiError('')» no le dice a nadie qué hacer. Comprueba una
# por una las cosas que pueden estar mal y nombra la que falla.
if [ "${PROBAR_NUBE:-false}" = true ]; then
    echo "==> Probando la conexión con Google Cloud Storage"
    FALLOS=0

    echo -n "  1. BACKUP_GCS_BUCKET configurado ....... "
    if [ -z "$BUCKET" ]; then
        echo "NO"
        echo "     Falta en .env.production. Ej: BACKUP_GCS_BUCKET=gs://emprenddi-respaldos"
        exit 1
    fi
    echo "$BUCKET"

    echo -n "  2. gcloud instalado .................... "
    command -v gcloud >/dev/null 2>&1 && echo "sí" || { echo "NO"; exit 1; }

    CUENTA=""
    echo -n "  3. Llave de cuenta de servicio ......... "
    if [ -z "$KEY_FILE" ]; then
        echo "no se usa (se usarán las credenciales de la VM)"
    elif [ ! -r "$KEY_FILE" ]; then
        echo "NO SE PUEDE LEER: $KEY_FILE"
        exit 1
    elif ! python3 -c "import json,sys;json.load(open(sys.argv[1]))" "$KEY_FILE" 2>/dev/null; then
        echo "NO ES UN JSON VÁLIDO"
        echo "     Si la pegaste a mano, seguramente quedó cortada. Vuelve a copiarla completa,"
        echo "     desde la primera llave { hasta la última }."
        exit 1
    else
        CUENTA=$(python3 -c "import json,sys;print(json.load(open(sys.argv[1])).get('client_email',''))" "$KEY_FILE")
        echo "ok — $CUENTA"
    fi

    echo -n "  4. Autenticación ....................... "
    if autenticar_nube; then
        # Cual es la cuenta que gcloud esta usando REALMENTE. Si no es la de la
        # llave, el permiso se le dio a una cuenta y escribe otra.
        ACTIVA=$(gcloud auth list --filter=status:ACTIVE --format='value(account)' 2>/dev/null | head -1)
        echo "ok — usando ${ACTIVA:-(desconocida)}"

        if [ -n "$CUENTA" ] && [ -n "$ACTIVA" ] && [ "$CUENTA" != "$ACTIVA" ]; then
            echo "     ⚠ La llave es de $CUENTA pero gcloud está usando $ACTIVA."
            echo "       El permiso se le dio a una cuenta y escribe otra."
        fi
    else
        echo "FALLÓ"
        exit 1
    fi

    echo -n "  5. El bucket existe y es accesible ..... "
    if SALIDA=$(gcloud storage ls "$BUCKET" 2>&1); then
        echo "sí"
    else
        echo "NO"
        echo "     $SALIDA" | head -3
        echo
        echo "     Causas frecuentes, en orden:"
        echo "       • El bucket no se creó. Créalo desde Cloud Shell:"
        echo "         gcloud storage buckets create $BUCKET --location=us-central1 \\"
        echo "           --uniform-bucket-level-access --project=TU_PROYECTO"
        echo "       • La cuenta de la llave no tiene permiso SOBRE ESE bucket:"
        echo "         gcloud storage buckets add-iam-policy-binding $BUCKET \\"
        echo "           --member=\"serviceAccount:${CUENTA:-LA_CUENTA}\" --role=roles/storage.objectAdmin"
        echo "       • La API de Cloud Storage está apagada en el proyecto:"
        echo "         gcloud services enable storage.googleapis.com --project=TU_PROYECTO"
        exit 1
    fi

    echo -n "  6. Se puede escribir ................... "
    PRUEBA="$(mktemp)"
    echo "prueba de escritura $(date -Iseconds)" > "$PRUEBA"

    # El error de gcloud se MUESTRA. Esconderlo era el problema: «no se puede
    # escribir» sin decir por que deja igual de perdido que el error vacio que
    # este diagnostico venia a resolver.
    if SALIDA=$(gcloud storage cp "$PRUEBA" "$BUCKET/.prueba-de-escritura" 2>&1); then
        gcloud storage rm "$BUCKET/.prueba-de-escritura" --quiet 2>/dev/null || true
        echo "sí"
        rm -f "$PRUEBA"
    else
        echo "NO"
        rm -f "$PRUEBA"
        echo
        echo "     Lo que respondió Google:"
        echo "$SALIDA" | sed 's/^/       /' | head -8
        echo
        echo "     Qué mirar, según lo que diga arriba:"
        echo "       • «does not have storage.objects.create access» → falta el rol."
        echo "         Desde Cloud Shell:"
        echo "         gcloud storage buckets add-iam-policy-binding $BUCKET \\"
        echo "           --member=\"serviceAccount:${CUENTA:-LA_CUENTA}\" --role=roles/storage.objectAdmin"
        echo "       • Si el rol YA aparece en get-iam-policy, suele ser propagación:"
        echo "         espera un minuto y vuelve a correr esta prueba."
        echo "       • «retention policy» o «bucket lock» → el bucket tiene retención"
        echo "         y no admite sobrescribir un objeto con el mismo nombre."
        echo "       • «billing» o «has not enabled» → falta habilitar la API o la"
        echo "         facturación del proyecto."
        FALLOS=1
    fi

    echo
    [ "$FALLOS" -eq 0 ] && echo "==> Todo en orden: los respaldos pueden subir a la nube." \
        || { echo "==> Hay que corregir lo marcado arriba." >&2; exit 1; }
    exit 0
fi

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

    autenticar_nube || exit 1

    if gcloud storage cp "$BACKUP_DIR/$ARCHIVO" "$BUCKET/$ARCHIVO" --quiet; then
        FUERA=true
        echo "     Listo."
    else
        DETALLE="el respaldo se creó pero no se pudo subir a $BUCKET"
        echo "   ✗ $DETALLE" >&2
        echo "     Si el error dice «Provided scope(s) are not authorized», la VM se creó" >&2
        echo "     con permisos limitados. Ver docs/BACKUPS.md → «Si la VM no puede subir»." >&2
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
