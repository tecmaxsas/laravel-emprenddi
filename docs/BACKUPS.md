# Respaldos

## Qué se respalda, y qué no

Solo lo que **no se puede volver a crear**:

| | Por qué |
|---|---|
| **Base de datos** | Las ventas, la cartera, el inventario, la nómina |
| **`storage/app/public`** | Logos, fotos de productos, portadas de catálogo |
| **`.env.production`** | Contiene `APP_KEY`. Sin ella, las llaves cifradas (Anthropic) quedan ilegibles aunque se restaure la base entera |

**No** se respalda el código —está en git—, ni `vendor`, ni `node_modules`, ni
los cachés, ni los logs. Recuperar eso es un `git pull` y un deploy.

## Instalación

El bucket y los permisos **no se crean desde la VM**: sus credenciales suelen
venir recortadas y fallan con `Provided scope(s) are not authorized`. Se crean
desde Cloud Shell o desde tu máquina, siguiendo *«Si la VM no puede subir al
bucket»* más abajo.

Una vez existe el bucket y la VM tiene con qué escribir en él:

```bash
cd /opt/emprenddi
git pull origin main

nano .env.production
#   BACKUP_GCS_BUCKET=gs://emprenddi-respaldos
#   BACKUP_GCS_KEY_FILE=/opt/emprenddi/gcs-respaldos.json   (si usas llave)

# Probar UNA vez a mano antes de automatizar
sudo bash scripts/backup.sh

# Comprobar que dice «Fuera del servidor: sí»
docker exec emprenddi_app php artisan backup:status

# Dejar el cron diario (3:15 a. m.)
sudo bash scripts/backup.sh --instalar-cron
```

### Si la VM no puede subir al bucket

Si al correr `backup.sh` aparece:

```
Provided scope(s) are not authorized
```

no es un problema de permisos IAM: es que **la VM se creó con permisos de
acceso (*scopes*) limitados**. Una máquina de Compute Engine pide sus
credenciales al servidor de metadatos, y ese token viene recortado según los
scopes con que se creó la instancia. El habitual por defecto solo deja **leer**
de Storage. Por muchos roles que se le den a la cuenta de servicio, el token no
alcanza.

Hay dos salidas.

#### Opción A — una llave propia (sin apagar nada) ← recomendada

Con una llave de cuenta de servicio, `gcloud` no pasa por el servidor de
metadatos y los scopes de la instancia dejan de aplicar.

Son cuatro pasos. Los tres primeros **no se hacen en la VM**, porque la VM es
justamente la que no tiene permiso.

---

##### Paso 1 · Abrir Cloud Shell

Cloud Shell es una terminal de Google, dentro del navegador, que se abre **ya
autenticada con tu propia cuenta** —la que sí tiene permisos para crear buckets
y cuentas de servicio—.

1. Entra a <https://console.cloud.google.com>.
2. Arriba a la derecha, junto a la campana de notificaciones, hay un icono de
   terminal: **`>_`**. Se llama *Activar Cloud Shell*.
3. Haz clic. Se abre un panel negro en la parte de abajo. La primera vez tarda
   medio minuto y puede pedirte **Autorizar**: acepta.
4. Cuando el prompt diga algo como `tu_usuario@cloudshell:~ (emprenddi-454013)$`
   ya estás dentro.

> **No confundas Cloud Shell con el SSH de la VM.** Son dos terminales
> distintas. El SSH de la VM se abre desde *Compute Engine → Instancias de VM →
> SSH* y es la máquina donde vive Emprenddi. Cloud Shell es una máquina
> temporal de Google, y es la única de las dos que tiene permisos para crear el
> bucket.

##### Paso 2 · Crear el bucket, la cuenta de servicio y la llave

Copia esto y pégalo **completo** en Cloud Shell (clic derecho → Pegar, o
`Ctrl+Shift+V`):

```bash
PROYECTO=emprenddi-454013

# a) La identidad que va a subir los respaldos
gcloud iam service-accounts create emprenddi-respaldos \
  --display-name="Respaldos Emprenddi" --project=$PROYECTO

# b) El bucket donde se guardan
gcloud storage buckets create gs://emprenddi-respaldos \
  --location=us-central1 --uniform-bucket-level-access --project=$PROYECTO

# c) Permiso de esa identidad SOBRE ESE BUCKET, y solo sobre ese
gcloud storage buckets add-iam-policy-binding gs://emprenddi-respaldos \
  --member="serviceAccount:emprenddi-respaldos@$PROYECTO.iam.gserviceaccount.com" \
  --role=roles/storage.objectAdmin

# d) La llave: el archivo que le da esa identidad a la VM
gcloud iam service-accounts keys create clave-respaldos.json \
  --iam-account=emprenddi-respaldos@$PROYECTO.iam.gserviceaccount.com
```

Al terminar debe decir algo como:

```
created key [a1b2c3...] of type [json] as [clave-respaldos.json]
```

Compruébalo:

```bash
ls -la clave-respaldos.json
```

##### Paso 3 · Llevar la llave a la VM

Hay dos formas. **La segunda es más corta y evita descargar la credencial a tu
computador**, que es preferible.

**Forma 1 — descargar y subir**

1. En Cloud Shell, arriba a la derecha del panel negro, el menú de tres puntos
   **⋮** → **Descargar** (*Download*).
2. Te pide una ruta. Escribe exactamente:
   `clave-respaldos.json`
   y dale **Descargar**. El archivo cae en las descargas de tu computador.
3. Abre el **SSH de la VM** (*Compute Engine → Instancias de VM → SSH*).
4. Arriba de esa ventana, botón **SUBIR ARCHIVO**. Elige el
   `clave-respaldos.json` que acabas de descargar.
5. El archivo queda en tu carpeta personal de la VM, en
   `/home/desarrollotecmax/clave-respaldos.json`.

**Forma 2 — copiar y pegar el contenido** (sin que toque tu computador)

1. En **Cloud Shell**:
   ```bash
   cat clave-respaldos.json
   ```
2. Selecciona con el mouse **todo** lo que imprimió —desde la primera `{` hasta
   la última `}`— y cópialo.
3. En el **SSH de la VM**:
   ```bash
   sudo nano /opt/emprenddi/gcs-respaldos.json
   ```
4. Pega (clic derecho → Pegar). Guarda con `Ctrl+O`, `Enter`, y sal con
   `Ctrl+X`.
5. Comprueba que quedó bien:
   ```bash
   sudo python3 -c "import json;json.load(open('/opt/emprenddi/gcs-respaldos.json'));print('la llave es válida')"
   ```

##### Paso 4 · Configurar y probar, en la VM

```bash
sudo su root
cd /opt/emprenddi
git pull origin main

# Solo si usaste la Forma 1 (si usaste la 2, el archivo ya está en su sitio)
mv /home/desarrollotecmax/clave-respaldos.json /opt/emprenddi/gcs-respaldos.json

# Una credencial no se deja legible para todo el mundo
chown root:root /opt/emprenddi/gcs-respaldos.json
chmod 600 /opt/emprenddi/gcs-respaldos.json

nano .env.production
```

Dentro de `nano`, busca las líneas de `BACKUP_` (están al final) y déjalas así:

```
BACKUP_GCS_BUCKET=gs://emprenddi-respaldos
BACKUP_GCS_KEY_FILE=/opt/emprenddi/gcs-respaldos.json
```

Guarda con `Ctrl+O`, `Enter`, sal con `Ctrl+X`. Y prueba:

```bash
bash scripts/backup.sh
```

Si algo falla, **antes de investigar a ciegas**:

```bash
bash scripts/backup.sh --probar-nube
```

Comprueba una por una las seis cosas que pueden estar mal —el bucket
configurado, gcloud, la llave, la autenticación, que el bucket exista, y que se
pueda escribir en él— y nombra la que falla con el comando para arreglarla. Se
escribió porque el error de Google en este punto es literalmente
`GcsApiError('')`, sin mensaje, y no le dice a nadie qué hacer.

Cuando todo esté bien, `bash scripts/backup.sh` debe decir:

```
   • Subiendo a gs://emprenddi-respaldos...
     Listo.
==> Respaldo terminado.
```

Confírmalo desde dos lados:

```bash
docker exec emprenddi_app php artisan backup:status   # «Fuera del servidor: sí»
gcloud storage ls gs://emprenddi-respaldos            # el archivo, desde Cloud Shell
```

##### Después: borra la copia suelta de la llave

Si usaste la Forma 1, el archivo quedó en tres sitios. Deja solo el de la VM:

```bash
# En Cloud Shell
rm clave-respaldos.json
```

Y borra el de las descargas de tu computador.

> **Qué es esa llave, en serio.** Da acceso de escritura y lectura al bucket de
> respaldos, y ese bucket contiene la base de datos completa —clientes, ventas,
> cartera— y el `.env` con las contraseñas. No la mandes por correo, ni por
> WhatsApp, ni la subas a ningún repositorio. Si alguna vez se filtra, se
> revoca así, desde Cloud Shell:
>
> ```bash
> gcloud iam service-accounts keys list \
>   --iam-account=emprenddi-respaldos@emprenddi-454013.iam.gserviceaccount.com
> gcloud iam service-accounts keys delete EL_ID_DE_LA_LLAVE \
>   --iam-account=emprenddi-respaldos@emprenddi-454013.iam.gserviceaccount.com
> ```
>
> Y se genera una nueva repitiendo el paso 2d.

---

#### Opción B — ampliar los permisos de la VM (con apagón)

Más limpio a largo plazo, pero **exige apagar la máquina**: los scopes no se
pueden cambiar en caliente.

```bash
gcloud compute instances stop instance-20260502-170340 --zone=us-central1-c
gcloud compute instances set-service-account instance-20260502-170340 \
  --zone=us-central1-c --scopes=cloud-platform
gcloud compute instances start instance-20260502-170340 --zone=us-central1-c
```

**Antes de hacerlo, comprueba que la IP externa sea estática.** Si es efímera,
al apagar la VM se pierde y `pos.emprenddi.com` deja de resolver hasta que se
actualice el DNS:

```bash
gcloud compute addresses list
# Si 104.154.82.129 no aparece ahí, es efímera: reservarla ANTES de apagar.
```

### Que el bucket no se pueda borrar por accidente

Vale la pena, y son dos comandos:

```bash
# Borra solo lo de más de 90 días
gcloud storage buckets update gs://emprenddi-respaldos \
  --lifecycle-file=<(echo '{"rule":[{"action":{"type":"Delete"},"condition":{"age":90}}]}')

# Nadie puede borrar un respaldo antes de 7 días, ni siquiera con permisos
gcloud storage buckets update gs://emprenddi-respaldos \
  --retention-period=7d
```

Lo segundo es lo que protege contra un ransomware o un borrado en caliente: el
atacante tiene las credenciales de la VM, pero no puede borrar lo que ya está
guardado.

## Comprobar que están funcionando

```bash
docker exec emprenddi_app php artisan backup:status
```

```
Último respaldo:  10/09/2026 03:15 am (hace 9 horas)
Archivo:          emprenddi-20260910-031500.tar.gz
Tamaño:           46 MB
Fuera del servidor: sí

✓ Los respaldos están al día.
```

Devuelve un **código de salida distinto de cero** cuando algo va mal, así que
sirve para un monitor externo. Avisa de tres cosas:

- El último respaldo **falló**.
- El último respaldo salió bien pero es **viejo** (más de 30 horas). Es el caso
  traicionero: el cron muerto y el comando diciendo «ok» porque mira el último
  archivo, no la última hora.
- El respaldo **no salió del servidor**.

Este comando existe por una razón: **un respaldo que falla en silencio es peor
que no tener respaldo**, porque da la tranquilidad sin dar la protección.

## Restaurar

La mitad que casi nadie prueba. Un respaldo que no se sabe restaurar no es un
respaldo: es un archivo grande.

```bash
# Ver qué hay
gcloud storage ls gs://emprenddi-respaldos

# Comprobar que un respaldo sirve, SIN tocar nada
sudo bash scripts/restore.sh gs://emprenddi-respaldos/emprenddi-20260910-031500.tar.gz --a-prueba

# Restaurar de verdad
sudo bash scripts/restore.sh gs://emprenddi-respaldos/emprenddi-20260910-031500.tar.gz
```

El script:

1. Descarga y **valida** el respaldo antes de tocar nada (cuenta las tablas del
   dump; si vienen menos de veinte, se detiene).
2. Pide que escribas `RESTAURAR` en letras.
3. **Toma un respaldo del estado actual** antes de reemplazarlo. Restaurar el
   archivo equivocado es un error frecuente y tiene que ser reversible.
4. Para la aplicación, restaura con `--clean` —para no dejar una mezcla de lo
   viejo y lo nuevo, que es el peor resultado posible—, y la vuelve a levantar.

El `.env.production` **no se sobreescribe**: viene dentro del respaldo como
`env.production` y hay que copiarlo a mano. Es deliberado — restaurar una base
de hace un mes no debería revertir la configuración de hoy.

### Restaurar solo una tabla

Cuando alguien borró unas facturas y el resto del sistema está bien:

```bash
tar -xzf emprenddi-20260910-031500.tar.gz
docker exec -i emprenddi_postgres pg_restore -U emprenddi -d emprenddi \
  --data-only --table=sale_invoices < 20260910-031500/base-de-datos.dump
```

Por eso el dump va en formato `custom` (`-Fc`) y no como SQL plano: permite
sacar una tabla sin volver a montar todo.

## Probar la restauración de verdad

**Cada tres meses**, en una VM aparte o en local:

```bash
gcloud storage cp gs://emprenddi-respaldos/emprenddi-XXXX.tar.gz .
sudo bash scripts/restore.sh emprenddi-XXXX.tar.gz --a-prueba
```

`--a-prueba` valida el archivo sin escribir nada. Es lo mínimo. Lo ideal es
restaurar de verdad en una máquina de pruebas y abrir la aplicación.

## Limitaciones conocidas

- **El respaldo es diario, a las 3:15 a. m.** Si el disco muere a las 6 p. m.,
  se pierde el día de ventas. Para un POS eso puede ser mucho: si se necesita
  menos ventana, el siguiente paso es archivado WAL continuo de PostgreSQL
  (`pg_basebackup` + `archive_command`), que permite recuperar a cualquier
  minuto. Es bastante más trabajo de montar y mantener.
- **No hay aviso automático cuando falla.** El estado queda en `backup:status`
  y en `storage/logs/backup.log`, pero nadie recibe un correo. Lo más simple
  sería un cron que corra `backup:status` y mande correo si devuelve error.
- **El respaldo incluye la configuración con secretos.** Va con permisos 600 y
  el bucket debe ser privado. Si alguna vez se comparte un respaldo con un
  tercero, hay que sacarle el `env.production` antes.
- **La base se respalda en caliente**, sin detener la aplicación. `pg_dump` da
  una foto consistente, así que no hay riesgo de datos a medias, pero una venta
  registrada durante el dump puede quedar fuera.
