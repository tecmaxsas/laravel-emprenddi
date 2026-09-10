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

En la VM, una sola vez:

```bash
cd /opt/emprenddi
git pull origin main

# 1. Crear el bucket (una vez, desde tu máquina o la VM)
gcloud storage buckets create gs://emprenddi-respaldos \
  --location=us-central1 \
  --uniform-bucket-level-access

# 2. Que la VM pueda escribir en él
gcloud storage buckets add-iam-policy-binding gs://emprenddi-respaldos \
  --member="serviceAccount:$(gcloud compute instances describe instance-20260502-170340 \
      --zone=us-central1-c --format='value(serviceAccounts[0].email)')" \
  --role=roles/storage.objectAdmin

# 3. Apuntar la aplicación al bucket
nano .env.production        # BACKUP_GCS_BUCKET=gs://emprenddi-respaldos

# 4. Probar UNA vez a mano antes de automatizar
sudo bash scripts/backup.sh

# 5. Dejar el cron diario (3:15 a. m.)
sudo bash scripts/backup.sh --instalar-cron
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
