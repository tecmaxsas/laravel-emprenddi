# Notas crédito: contabilizar, enviar a la DIAN y descargar el PDF

## Qué estaba pasando

Al contabilizar una nota crédito aparecía este error en pantalla:

```
SQLSTATE[23514]: Check violation: 7 ERROR: new row for relation "journal_entries"
violates check constraint "journal_entries_type_check"
```

Y además faltaban dos cosas que parecían funcionalidades no construidas: el botón
de **enviar la nota a la DIAN** y el de **descargar el PDF**.

Los tres síntomas eran en realidad **una sola falla**, más una pieza que
efectivamente no existía todavía.

### La causa

La tabla `journal_entries` tiene una lista cerrada de tipos de asiento permitidos
(una restricción `CHECK` en PostgreSQL). El motor de notas escribe
`type = 'credit_note'` y ese valor **no estaba en la lista**, así que la base
rechazaba el asiento y la nota nunca salía de borrador.

De ahí venía el segundo síntoma: el botón de enviar a la DIAN solo aparece sobre
notas **contabilizadas**. Como ninguna lograba contabilizarse, el botón no
aparecía nunca y parecía que la función no estaba hecha — cuando en realidad el
código de envío (`CreditDebitNoteSender`) llevaba meses ahí, listo.

Lo mismo pasaba con `debit_note` en las notas débito, y con `general` en la
liquidación de comisiones.

### Por qué se repitió

Esta es la **segunda vez**. En mayo pasó igual con el tipo `cogs` al facturar
productos con inventario. El motivo es estructural: la lista de tipos vive en dos
lugares que nada obliga a mantener iguales —la restricción en la base y
`JournalEntry::TYPES` en el código—. Cuando alguien agrega un tipo nuevo en PHP,
no falla al guardar el archivo ni al levantar la aplicación. Falla en producción,
con un error crudo de PostgreSQL, en el segundo exacto en que un usuario aprieta
«Contabilizar».

Por eso el arreglo no fue solo agregar los tipos que faltaban.

## Qué se hizo

**1. Se agregaron los tres tipos faltantes** (`credit_note`, `debit_note`,
`general`) a la restricción de la base y a `JournalEntry::TYPES`.

**2. Se puso un candado para que no haya una tercera vez.** Ahora hay pruebas
automáticas que:

- comparan la lista de la base contra la del código y fallan si se separan;
- recorren todos los servicios buscando asientos que escriban un tipo que la base
  vaya a rechazar, **antes** de que alguien lo contabilice.

Esa segunda prueba es la que de verdad habría atajado este fallo: mira el código,
no la base.

**3. Se implementó la descarga del PDF**, que sí faltaba de verdad.

## El PDF oficial

El PDF con CUFE y código QR **no lo genera Emprenddi**. Lo genera el proveedor
tecnológico en el momento en que la DIAN autoriza el documento. Uno hecho aquí se
parecería mucho, pero no sería el documento válido ante una revisión.

Por eso el botón **Descargar PDF DIAN** lo pide al proveedor en el momento y lo
abre en una pestaña nueva. No se guarda copia en el servidor: es un documento
público que ya vive del lado del proveedor, y una copia local solo serviría para
desactualizarse.

El botón aparece:

- en **Notas Crédito / Débito → Ver**, cuando la nota está aceptada por la DIAN;
- en **Facturas de Venta → Ver** (rotulado «PDF DIAN»), con la misma condición.

En la factura de venta conviven dos botones parecidos y hacen cosas distintas:

| Botón | Qué es |
|---|---|
| **Imprimir** | El tiquete para la impresora del punto de venta |
| **PDF DIAN** | El documento oficial con CUFE y QR, traído del proveedor |

Si el documento todavía no está aceptado por la DIAN, el botón no se muestra —
ese PDF aún no existe en ninguna parte.

### «El proveedor todavía no tiene el archivo»

Es el mensaje más común y casi siempre significa que el documento se autorizó
hace unos segundos y el PDF se está generando. Esperar un momento y reintentar
resuelve. No significa que se haya perdido nada.

## Para quien mantenga el código

- La restricción se reconstruye en
  `database/migrations/2026_09_17_090000_add_note_types_to_journal_entries.php`.
  **Agregar un tipo de asiento nuevo exige una migración**; cambiar solo
  `JournalEntry::TYPES` no basta y la prueba `JournalEntryTypesTest` lo va a
  gritar.
- La descarga está en `app/Services/Dian/DianDocumentDownloader.php`. El nombre
  del archivo es lo que decide qué devuelve el proveedor, porque el endpoint es
  uno solo para PDF, XML y ZIP:

  | Documento | Patrón |
  |---|---|
  | Factura electrónica | `FES-{prefijo}{número}.pdf` |
  | Documento equivalente POS | `POSS-{prefijo}{número}.pdf` |
  | Nota crédito | `NCS-{prefijo}{número}.pdf` |
  | Nota débito | `NDS-{prefijo}{número}.pdf` |

  Cuando la respuesta del envío trae el nombre exacto (`urlinvoicepdf`), se usa
  ese en lugar de reconstruirlo — si el proveedor cambia la convención, ese campo
  sigue siendo correcto.

- **Cuidado con el código HTTP del proveedor.** Cuando no encuentra un archivo
  responde `200 OK` con un JSON `{"success": false}`, no un 404. Un
  `$response->successful()` a secas daría por buena la descarga y el usuario
  abriría un «PDF» que en realidad son dos líneas de JSON. Esa comprobación está
  en `DianApiClient::downloadFile()` y tiene prueba propia.

Las pruebas se corren contra la base de desarrollo:

```bash
docker compose exec -T \
  -e DB_CONNECTION=pgsql -e DB_HOST=postgres -e DB_PORT=5432 \
  -e DB_DATABASE=emprenddi -e DB_USERNAME=emprenddi -e DB_PASSWORD=secret \
  app php artisan test --filter="JournalEntryTypes|CreditNotePost|DianPdfDownload"
```
