# Módulo Hoteles

Módulo vertical opcional, al mismo nivel que Restaurante y Parqueadero: se
activa por empresa desde el SuperAdmin y, sin esa activación, es invisible
completo —menú, pantallas, rutas y reportes—.

Fecha de implementación: **29 de octubre de 2026**.

> **Este es el documento técnico.** Hay otros dos del mismo módulo:
> **`MANUAL_CAPACITACION_HOTELES.md`** para el equipo que presenta, vende y
> enseña, y **emprenddi.com/docs/hotel** —la guía pública que se le pasa a un
> prospecto, cuyo contenido vive en `resources/views/docs/hotel.blade.php`—.

---

## 1. Qué resuelve

Un hotel no se parece al POS de mostrador y tampoco al parqueadero. La
diferencia está en una sola cosa, y de ella sale todo el diseño:

> **Una estadía es una cuenta abierta, no una transacción.**

El huésped entra, se le van cargando noches, el restaurante, la lavandería y el
minibar, y todo se cobra junto al salir. Eso es un **folio**, y se parece mucho
más a la orden de una mesa de restaurante que a una sesión de parqueadero, que
entra y sale en el mismo rato.

La segunda diferencia es que un hotel vende **tiempo futuro**: se aparta una
habitación para el viernes, y el error que un hotel no puede permitirse es
vender dos veces la misma noche. Un sobrecupo no se arregla con un parche:
hay dos familias en el lobby y una sola habitación.

---

## 2. Lo que quedó construido

### 2.1 Base de datos — 4 migraciones, 8 tablas

| Tabla | Para qué |
|---|---|
| `hotels` | Cada hotel: código, dirección, **RNT**, sede de facturación, hora de entrada y de salida |
| `hotel_zones` | Pisos, torres, alas. Agrupan el mapa de recepción |
| `hotel_room_types` | Sencilla, Doble, Suite… **aquí vive la tarifa** |
| `hotel_rooms` | La habitación física, con su estado |
| `hotel_guests` | Registro hotelero: quién durmió, con los datos que pide la ley |
| `hotel_reservations` | Reservas, con o sin habitación asignada |
| `hotel_stays` | La estadía y los totales del folio |
| `hotel_stay_charges` | Cada renglón del folio: noches, consumos, servicios, ajustes |
| `hotel_stay_guests` | Quiénes duermen en esa habitación (titular + acompañantes) |

**Decisiones del esquema que conviene conocer:**

- **La tarifa está en el tipo, no en la habitación.** La 101 y la 102 son dos
  dobles y valen lo mismo. Ponerla en cada habitación obligaría a editar
  cincuenta filas cuando entra temporada alta, y bastaría olvidar una para
  vender una noche al precio del año pasado.

- **Los precios son al público (IVA incluido).** Misma convención que los
  extras del parqueadero: el recepcionista cotiza lo que el huésped va a pagar,
  y la base gravable se descompone al guardar cada renglón. Si se guardara la
  base, el precio del renglón no coincidiría con el aviso de la recepción ni
  con la carta del restaurante.

- **Los totales del folio están en columnas**, no se suman al leer. El mapa de
  recepción muestra el saldo de cada habitación ocupada y se repinta todo el
  día; sumar renglones en cada pintada sería una consulta por habitación. El
  costo de esa decisión es que un total guardado puede quedar viejo, así que
  **nada toca un renglón sin pasar por `HotelFolio`**, que recalcula al salir
  en la misma transacción.

- **Una noche se cobra una sola vez**, garantizado con un índice único parcial
  en la base (`stay_id`, `night_date`) para `kind = 'room'` y filas no
  anuladas. El pase nocturno puede correr dos veces; el motor lo verifica
  igual, pero esta regla está en la base porque es la única que no se puede
  saltar llegando por otro camino.

- **Los huéspedes NO son Terceros.** El Tercero es a quién se le factura
  —puede ser la empresa que paga la habitación de su empleado—; el huésped es
  quién durmió, y de él la ley pide nacionalidad, procedencia, destino y motivo
  del viaje, que a la contabilidad no le sirven de nada. Mezclarlos habría
  llenado el maestro de Terceros —el mismo que alimenta la exógena y la
  cartera— de una fila por cada persona que pasó una noche, incluidos los niños
  sin documento. `hotel_guests.third_party_id` los enlaza cuando el huésped
  también es quien paga.

### 2.2 El convenio de fechas

Es la decisión de la que depende todo el control de sobrecupo:

```
[check_in_date, check_out_date)   ← entrada inclusiva, salida EXCLUSIVA
```

Quien sale el 10 deja la habitación libre para quien llega el 10. Lo que se
aparta son **noches**, no días: del 8 al 10 son dos noches (la del 8 y la del
9), y la mañana del 10 la habitación ya se puede entregar.

Con el convenio al revés el hotel pierde una noche vendible por cada reserva,
que en temporada alta es la mitad del inventario.

Dos rangos se solapan si: `desde < otro_hasta` **Y** `hasta > otro_desde`.

### 2.3 Servicios (`app/Services/Hotel/`)

| Clase | Responsabilidad |
|---|---|
| **`HotelAvailability`** | ¿Está libre esa habitación en esas fechas? **El único sitio que lo decide.** |
| `HotelReservationEngine` | Crear, confirmar, anclar a habitación, modificar, cancelar, no-show |
| `HotelStayEngine` | Check-in, traslado, cambio de fecha, liquidación, cierre, anulación, camarería |
| `HotelFolio` | Los renglones y los totales. El único que los escribe |
| `HotelBillingEngine` | Folio → factura + pago + cierre, en una transacción. Depósitos |
| `HotelReports` | Ocupación, ADR, RevPAR, estadías y huéspedes |
| `HotelProductProvisioner` | El producto «Alojamiento» (`HOTEL-ALOJ`), cuenta 4140 del PUC |
| `HotelDefaultsProvisioner` | Siembra los seis tipos de habitación canónicos |
| `HotelStayNumberer` | El consecutivo del folio (`H-000142`) |
| `HotelRoomBulkCreator` | Crear un piso completo de habitaciones de una vez |

**`HotelAvailability` es el corazón y hay una razón para que esté solo.** La
pregunta «¿está libre?» se hace por cuatro caminos distintos:

1. Crear una reserva
2. Anclarla a una habitación concreta
3. Hacer check-in sin reserva (de mostrador)
4. Extender una estadía

Si cada uno la respondiera a su manera, bastaría que uno se quedara atrás para
vender dos veces la misma noche. Las pruebas entran por los cuatro caminos
justamente para comprobar que todos pasan por ahí.

Qué compromete una habitación:

- Una reserva **viva** (por confirmar o confirmada) que se solape.
- Una estadía **abierta**: bloquea desde que entró hasta la salida pactada, o
  hasta mañana si ya se pasó de la fecha —el huésped está adentro esta noche
  aunque en el papel debiera haberse ido ayer—.
- Una estadía **cerrada** cuyo rango real todavía toque el consultado.
- El **estado** de la habitación: mantenimiento o bloqueada no se venden ni
  para dentro de un mes.

Qué **no** compromete: una reserva cancelada, una que no llegó, y una que ya
hizo check-in (esa bloquea a través de su estadía; si contara dos veces, el
huésped se chocaría consigo mismo al pedir quedarse un día más).

Y el **motivo** importa: `exigirLibre()` revienta con «la habitación 101 no
está disponible: Reserva R-000012 de Pedro Gómez (08/03 al 10/03)». Un «no está
disponible» a secas obliga al recepcionista a revisar a mano con el cliente al
frente.

### 2.4 Pantallas

**Operación:**

| Pantalla | Ruta | Permiso |
|---|---|---|
| **Recepción** (el POS del hotel) | `/app/hotel` | `hotel.use` |
| Reservas | `/app/hotel/reservations` | `hotel.reservations.manage` |
| Estadías (histórico) | `/app/hotel/stays` | `hotel.use` |
| Huéspedes | `/app/hotel/guests` | `hotel.guests.manage` |

**Configuración:**

| Pantalla | Permiso |
|---|---|
| Hoteles | `hotel.manage` |
| Zonas | `hotel.manage` |
| Tipos de habitación | `hotel.manage` (la tarifa, `hotel.rates.manage`) |
| Habitaciones | `hotel.manage` |

**Reportes:**

| Pantalla | Permiso |
|---|---|
| Ocupación e ingresos | `hotel.reports` |
| Huéspedes alojados | `hotel.reports` |

### 2.5 La recepción

Una sola pantalla con el mapa de habitaciones. El recepcionista trabaja a golpe
de clic sobre el tile:

- **Libre** → check-in (de una reserva que llega hoy, o de mostrador)
- **Ocupada** → el folio: cargar consumos, trasladar, extender, recibir
  depósito, imprimir la cuenta, cobrar
- **En aseo** → marcarla aseada y devolverla al inventario vendible

Arriba, las **llegadas y salidas del día**: la lista de pendientes del turno,
con las estadías que se pasaron de la fecha marcadas. El buscador acepta número
de habitación, nombre del huésped, código de reserva (`R-000012`) o número de
folio (`H-000045`).

El turno de caja se abre y se cierra desde la misma pantalla. Es la misma
corrección que hubo que hacerle al parqueadero: un hotel no tiene por qué pasar
por el POS de mostrador para nada.

**El estado de la habitación es uno, no dos.** Un PMS grande separa el estado
de recepción del de camarería (ocupada-sucia, libre-limpia…). Aquí el ciclo es
lineal y el estado lo refleja: `ocupada → (check-out) aseo → disponible`. Con
dos columnas habría que mantenerlas sincronizadas y la recepción terminaría
mirando la que no corresponde.

### 2.6 El folio

- Las noches se cargan **una por una, con su fecha**. Podrían ser un solo total
  «5 noches × $180.000», pero entonces el huésped que discute una noche no
  tendría nada que señalar, y el hotel que le hace una cortesía no tendría
  dónde aplicarla. En la **factura** se agrupan en una sola línea: cinco
  renglones iguales en un documento es algo que nadie lee.

- Las noches se cargan **mientras el huésped está adentro**, nunca por
  adelantado. Si se va antes, habría que devolverle plata ya facturada.

- Los **consumos** pueden ser productos del catálogo o servicios escritos a
  mano. Los productos con inventario se descargan del almacén al cerrar, usando
  `DescargaDeVenta` —el mismo servicio de la factura de venta y del «casa
  invita» del restaurante—, para que un producto salga igual del inventario sin
  importar por dónde salió del hotel.

- Los **ajustes** (cortesías, rebajas) entran como renglón negativo y llevan el
  IVA de lo que están rebajando. Sin impuesto, la rebaja bajaría el total pero
  no el IVA, y el hotel terminaría pagándole a la DIAN el IVA de una plata que
  no cobró.

- **Trasladar al huésped conserva el folio**: es la cuenta de la persona, no de
  la habitación. Las noches ya cobradas no se reescriben porque sí se durmieron
  en la otra habitación.

### 2.7 Liquidación y check-out

Al abrir el cobro se **liquida** el folio y después se cobra. Es a propósito:
la cuenta que se le enseña al huésped tiene que ser la misma que se le cobra, y
liquidar al dar «cobrar» dejaría que la cifra cambiara entre que la mira y la
paga.

La liquidación hace dos cosas que son las que descuadran una cuenta sin que
nadie lo note:

1. **Quita las noches que no durmió.** El pase nocturno ya cargó la noche de
   hoy; si el huésped se va esta mañana, esa noche sale del folio. Sin esto se
   le cobra una de más.
2. **Suma la noche extra por salida tardía.** Quien entrega a las cinco de la
   tarde usó otra noche. Con **minutos de gracia configurables** (una hora por
   defecto): cobrarle una noche completa a quien entregó cinco minutos tarde es
   una pelea en el mostrador que el hotel pierde siempre.

El cobro ocurre **dentro de una sola transacción**: factura, pago, cierre de la
estadía, habitación a aseo y reserva terminada. Si se cerrara la estadía antes
y el cobro fallara —sin turno de caja, por ejemplo—, el huésped quedaría con el
folio cerrado en el mostrador y sin forma de pagarlo.

**Las rebajas del folio viajan a la factura como descuento global**, no como
líneas negativas: la DIAN rechaza una línea de factura con valor negativo, y el
descuento global además baja la base gravable antes de calcular el IVA, que es
lo que corresponde a un descuento no condicionado. Se le pasa la **base** de la
rebaja, no el valor al público, para que el total de la factura cuadre con el
del folio.

### 2.8 Depósitos

El depósito entra como **anticipo de cliente**, que es la figura que el sistema
ya tiene. No se inventó nada:

- La plata entra al turno de caja **el día que se recibe**, no el del check-out.
- Al facturar el check-out, `SaleInvoiceEngine::post()` lo aplica solo —el
  anticipo se cruza contra la factura siguiente del cliente— y el huésped paga
  la diferencia.
- Requiere un cliente con nombre asignado a la estadía: un saldo a favor sin
  dueño no se puede cruzar con nada ni devolver.

---

## 3. Lo específico de Colombia

### 3.1 IVA del hospedaje y el turista extranjero

El hospedaje en Colombia es **gravado al 19 %**. Pero el servicio de
alojamiento a un **turista extranjero no residente** es **exento**
(art. 481 del Estatuto Tributario).

Se marca en el check-in, con el **motivo escrito** —la exención hay que poder
sustentarla ante la DIAN— y:

- **Cubre el alojamiento, no los consumos.** La cerveza del minibar se sigue
  gravando. Aplicarla a todo le dejaría de cobrar al huésped un IVA que el
  hotel sí tiene que declarar.
- Queda impresa en la cuenta y en la tarjeta de registro.

### 3.2 Registro Nacional de Turismo (RNT)

Campo en la ficha del hotel. Es obligatorio exhibirlo para un prestador de
servicios turísticos inscrito, y va impreso en la cuenta del huésped
(configurable si se quiere apagar).

### 3.3 Tarjeta de registro hotelero

Imprimible en **tamaño carta** —se firma y se archiva, no va a la térmica— con:

- Datos de la estadía: habitación, tipo, entrada, salida prevista, noches,
  personas, tarifa, responsable del pago.
- **Todos** los huéspedes, no solo el que paga: apellidos y nombres,
  documento, nacionalidad, nacimiento, procedencia, destino, motivo del viaje.
  Siempre con al menos cuatro renglones, porque una habitación familiar llega
  con acompañantes que no están en el sistema y la tarjeta tiene que dejar
  dónde escribirlos.
- Declaración de **tratamiento de datos (Ley 1581 de 2012)** y aceptación de
  las condiciones de la estadía, incluida la hora de salida.
- Espacio de firma del huésped, documento y recepción.

### 3.4 Migración Colombia

El reporte de huéspedes alojados tiene filtro de **solo extranjeros**, con las
columnas del registro (documento, nacionalidad, procedencia, destino, motivo),
exportable a Excel. `Guest::esExtranjero()` lo resuelve por nacionalidad y por
tipo de documento: un pasaporte o una cédula de extranjería ya lo dicen aunque
no se haya digitado el país.

### 3.5 Cuenta contable

El producto `HOTEL-ALOJ` se crea con la cuenta **4140 — Hoteles y restaurantes**
del PUC colombiano. Si la empresa no la tiene en su plan, queda en null y
`SaleInvoiceEngine` cae al fallback 4135 (mercancías); el administrador puede
corregirla en la ficha del producto.

---

## 4. Permisos

Doce permisos nuevos en el grupo **Hotel** del catálogo:

| Permiso | Qué habilita |
|---|---|
| `hotel.use` | Operar la recepción (check-in, check-out, folio) |
| `hotel.manage` | Configurar hoteles, zonas, tipos y habitaciones |
| `hotel.rates.manage` | Editar las tarifas por tipo de habitación |
| `hotel.reservations.manage` | Crear y gestionar reservas |
| `hotel.guests.manage` | Registrar y editar huéspedes |
| `hotel.folio.charge` | Cargar consumos al folio |
| `hotel.folio.adjust` | Aplicar cortesías y ajustes al folio |
| `hotel.room.change` | Trasladar un huésped a otra habitación |
| `hotel.stay.close_without_invoice` | Cerrar un folio sin facturar |
| `hotel.stay.cancel` | Anular una estadía (check-in equivocado) |
| `hotel.housekeeping` | Marcar habitaciones aseadas, en mantenimiento o bloqueadas |
| `hotel.reports` | Ver los reportes del hotel |

**El reparto por defecto aplica la lección del restaurante antes de que haga
falta arreglarla.** El rol `cashier` (el recepcionista) recibe:

```
hotel.use · hotel.reservations.manage · hotel.guests.manage
hotel.folio.charge · hotel.housekeeping
```

Y **no** recibe `hotel.stay.close_without_invoice`, `hotel.stay.cancel`,
`hotel.folio.adjust`, `hotel.manage` ni `hotel.rates.manage`. La razón es una
sola: **cerrar sin facturar, anular una estadía y rebajar un folio son plata
que el hotel deja de recibir**, y eso lo autoriza un administrador. Es
exactamente lo que hubo que corregirle al restaurante con el «cerrar sin
facturar» del cajero.

Cada bloqueo está en **dos sitios**: la pantalla oculta el botón y el método de
Livewire lo verifica otra vez. Dos cerraduras para la misma puerta, porque la
de la pantalla se puede saltar llamando la acción a mano.

---

## 5. Reportes: las tres métricas de la hotelería

No es «cuánto facturé». Un hotel se mide con tres cifras:

| Métrica | Fórmula | Qué dice |
|---|---|---|
| **Ocupación** | noches vendidas / noches disponibles | Cuánto del hotel se usó |
| **ADR** | ingreso de alojamiento / noches vendidas | A cuánto se vendió lo que se vendió |
| **RevPAR** | ingreso de alojamiento / noches disponibles | **La que manda** |

RevPAR es la importante porque mezcla las dos: un hotel con ADR altísimo y 20 %
de ocupación gana menos que uno con ADR medio y 90 %, y mirando solo el ADR
nadie lo nota. Por eso en la pantalla va de primera.

**El ingreso de alojamiento sale de los renglones de noche del folio, no de las
facturas.** Es la única fuente que sabe **de qué noche** era cada peso: una
estadía de cinco noches se factura un solo día, y contarla ahí haría que un
martes apareciera con el ingreso de toda la semana.

El reporte recorre el **calendario completo**, no solo los días con movimiento:
un día con cero ocupación es un dato, y si falta la fila el promedio del mes
sale inflado.

**Limitación conocida y declarada en pantalla:** la columna «Habitaciones» es el
inventario vendible de **hoy**. El sistema no guarda cuántas habitaciones tenía
el hotel en cada fecha, así que un hotel que amplió un piso verá los meses
anteriores calculados contra el inventario actual. Guardar un histórico de
inventario por día es mucha máquina para una cifra que casi nadie mira hacia
atrás.

Los números los calcula `HotelReports`, **el mismo servicio que alimenta el
Excel**. Un exportador que arma su propia consulta es un exportador que algún
día entrega un total distinto al que el usuario acaba de ver, y no hay manera
de saber cuál de los dos está bien.

---

## 6. Hueco que se encontró y se cerró: `roles:sync-catalog`

Construyendo el módulo apareció un problema que **no es del hotel** y que
afecta a todo permiso nuevo que se agregue al sistema.

Los roles son **por empresa**: cada una trabaja sobre su propia copia de la
plantilla, para que editarle un permiso al cajero de una no se lo quite a los
cajeros de las demás. `RolesSeeder` mantiene las plantillas, pero **las copias
de las empresas quedan como estaban**.

El efecto es el que nadie espera: se publica un módulo nuevo, se despliega, y en
las empresas que ya existían **no funciona para nadie —ni para el
administrador—** porque sus roles no tienen los permisos nuevos. Y como el
módulo «no sirve», la primera reacción es buscar el error en el código.

```bash
php artisan roles:sync-catalog --dry-run   # muestra qué haría
php artisan roles:sync-catalog             # lo aplica
```

**La regla que lo hace seguro:** se le da a un rol un permiso que le falta
**solo si ese permiso se creó después que el rol**. Si nació después, la copia
nunca pudo haberlo tenido y nadie pudo habérselo quitado, así que dárselo no
pisa ninguna decisión. Si nació antes y el rol no lo tiene, es porque alguien
se lo quitó a propósito, y ahí no se toca. Repartir «todo lo que le falta»
devolvería justamente eso, y en silencio.

Es idempotente, y tiene `--dry-run` y `--prefijo=hotel.` para acotar.

> **La primera versión no servía.** Usaba otra regla —solo los permisos que esa
> corrida acababa de crear— y falló en el único momento en que hace falta:
> `deploy.sh` corre `RolesSeeder` antes, que ya crea las filas de permiso, así
> que al llegar el comando no había nada «nuevo» y respondía «el catálogo ya
> está completo» dejando a las empresas sin los permisos. Lo fija
> `SyncRolePermissionsTest`.

---

## 7. Lo que se reusó (y no se reimplementó)

Nada de la infraestructura se duplicó. Una segunda forma de facturar en el
sistema sería una segunda forma de equivocarse con la DIAN.

| Se reusó | Para qué |
|---|---|
| `DocumentNumberer` | Consecutivo y resolución (POS o DIAN) |
| `SaleInvoiceEngine` | Posteo contable, impuestos, pagos, descarga de inventario |
| `GlobalDiscount` | Las rebajas del folio sobre la base gravable |
| `PosDianTransmitter` | Factura electrónica, **fuera** de la transacción |
| `CustomerAdvanceService` | Depósitos como anticipo, con su turno de caja |
| `DescargaDeVenta` | Inventario de los consumos (igual que «casa invita») |
| `CashRegisterSession` + `CashSessionCloser` + `CashSessionSummary` | El turno |
| `Vuelto` | El cambio en efectivo |
| `PaymentMethodOptions` / `PaymentAccountResolver` | Formas de pago de la empresa |
| `QuickCustomer` | Crear el cliente sin salir de la recepción |
| `partials/thermal-print` | Que la cuenta no salga clara ni borrosa |
| `TabularReportExporter` | Los Excel |
| `ClockFormat` | El formato de hora de la empresa (am/pm o 24 h) |
| `ModuleGate` / `ChecksPermission` | Activación y permisos |

Además se extrajo `resources/views/docs/layout.blade.php`: la guía del
parqueadero y la del hotel eran la misma página con otro contenido —el mismo
encabezado, la misma tabla de contenido y 200 líneas de CSS idénticas—.
Duplicadas, el día que haya que arreglar el salto de página al imprimir se
arregla en una y la otra sigue saliendo mal, que es exactamente lo que pasó con
los cuatro formatos térmicos.

---

## 8. Configuración (Configuración → Hotel)

| Opción | Por defecto | Qué hace |
|---|---|---|
| Cobrar salida tardía | Sí | Si una entrega después de la hora suma una noche |
| Minutos de gracia | 60 (máx. 360) | La holgura antes de cobrarla |
| Imprimir el RNT en la cuenta | Sí | Toma el RNT de la ficha del hotel |
| Pie de la cuenta | vacío | Texto libre al final del documento |
| Exigir documento al titular | Sí | A los acompañantes nunca se les exige |

El aviso de que la cuenta **NO es una factura de venta** no es configurable.
Sin él el huésped se va creyendo que ya la tiene.

La hora de entrada y de salida son de **cada hotel** (ficha del hotel), no de la
empresa: una empresa puede tener dos hoteles con horarios distintos.

---

## 9. Puesta en marcha

### 9.1 En el servidor, tras el deploy

```bash
# 1. Las tablas
php artisan migrate --force

# 2. Los permisos nuevos a todas las empresas (incluye las que ya existían)
php artisan roles:sync-catalog

# 3. Activar el módulo para la empresa que lo contrató
php artisan companies:enable-hotel 5
# o por NIT:  php artisan companies:enable-hotel --nit=900123456

# 4. (Opcional) La cuenta demo para mostrarle el módulo al equipo y a clientes
HOTEL_DEMO_PASSWORD='la-que-quieras' php artisan db:seed --class=HotelDemoSeeder
```

`companies:enable-hotel` es idempotente y hace cuatro cosas: agrega `hotel` a
`active_modules`, corre el bootstrap contable (PUC, impuestos, sede,
Consumidor Final, plantillas), crea un hotel inicial si no hay ninguno y siembra
los seis tipos de habitación.

También se puede activar desde **SuperAdmin → Compañías → Módulos activos**,
marcando «Hotel».

### 9.2 Lo que hace el administrador de la empresa

1. **Ponerle tarifa a cada tipo de habitación.** Se siembran en **$0** a
   propósito: con tarifa en cero el sistema **no deja alojar a nadie**, en vez
   de abrir un folio sin precio. Una tarifa de ejemplo se queda puesta y algún
   día se le cobra a un huésped.
2. **Crear las zonas** (pisos, torres) y las **habitaciones** — hay un botón
   «Crear en lote» para generar un piso completo (101 a 112) de una vez.
3. **Verificar la sede de facturación del hotel.** Sin ella el check-out no
   puede emitir factura: la resolución y el consecutivo viven en la sede.
4. **Repartir los permisos** del grupo «Hotel» a los roles que correspondan.

---

## 10. Cuenta demo (`HotelDemoSeeder`)

Para mostrarle el módulo al equipo y a los clientes sin tener que montar nada
en el momento.

```bash
php artisan db:seed --class=HotelDemoSeeder

# con otra contraseña (recomendado en producción):
HOTEL_DEMO_PASSWORD='la-que-quieras' php artisan db:seed --class=HotelDemoSeeder
```

**Es idempotente.** Detecta por código lo que ya existe y solo crea lo que
falta; lo que depende de la fecha —la historia, las estadías abiertas, las
llegadas de hoy— se genera relativo a `now()`, así que una corrida nueva lo
deja fresco si todavía no se había generado.

| | |
|---|---|
| Empresa | Hotel Mirador del Parque S.A.S. · NIT **901777666** |
| Admin | `demo@hotelmirador.co` |
| Recepción | `recepcion@hotelmirador.co` |
| Contraseña | `Demo2026!` (o `HOTEL_DEMO_PASSWORD`) |
| Entrada | `/app/hotel` |

**Son dos usuarios a propósito.** La diferencia entre lo que puede un
administrador y lo que puede un recepcionista es de las cosas que más se
preguntan en una demostración, y se ve mejor entrando con cada uno que
explicándola: el recepcionista no tiene «cerrar sin facturar», ni anular, ni
cortesías.

Lo que deja montado:

- 1 hotel con RNT, 4 zonas, 5 tipos con tarifa real y **24 habitaciones**
- **Resolución POS y turno de caja abierto**: el check-out se puede hacer en
  vivo y sale una factura de verdad con su tiquete
- Minibar **con inventario** (para mostrar que un consumo descarga el almacén)
  y servicios: lavandería, desayuno, parqueadero, salón
- 14 huéspedes, 4 de ellos extranjeros
- **~340 estadías cerradas de los últimos 60 días**, con la ocupación subiendo
  los fines de semana: es lo que le da contenido al reporte de ADR y RevPAR.
  Las cifras están calibradas para que el período quede alrededor del **61 % de
  ocupación, ADR ~$225.000 y RevPAR ~$138.000** — un hotel que va bien. Con
  menos, el reporte le dice al cliente que el hotel está vacío; con más, que
  está lleno todos los días y nadie se lo cree
- Las **6 habitaciones del guion no las toca la historia**: se reservan para
  los casos de abajo. Sin eso, la historia las ocupaba hasta hace pocos días y
  el check-in de la 401 lo rechazaba —con razón— por solaparse, dejando un
  resumen que prometía una habitación vacía
- **6 habitaciones ocupadas ahora**, cada una contando algo distinto:
  folio con minibar, estadía corporativa, familiar con acompañante, una que
  llegó hoy, una que **se pasó de la fecha** y tiene una cortesía aplicada, y
  un **extranjero exento de IVA**
- Reservas en todos los estados, con **3 llegadas para hoy**
- 2 habitaciones en aseo y 1 en mantenimiento

### Dos decisiones del seeder que conviene conocer

**La historia se cierra sin factura, a propósito.** Facturarla quemaría
cientos de números de la resolución y, como la factura se emite con la fecha
del día en que se cobra, todas saldrían fechadas hoy: el reporte de ventas
mostraría dos meses de ingresos concentrados en una sola jornada. Lo que esos
datos alimentan es el reporte de ocupación, ADR y RevPAR, que lee los renglones
de noche del folio y no necesita facturas. La factura se muestra en vivo
haciendo un check-out de verdad.

**La suscripción se crea.** `canAccessPanel()` bloquea el panel de una empresa
sin suscripción vigente, y el login responde lo mismo que ante una contraseña
mala. Sin ella la demo queda perfecta por dentro y la contraseña «no
funciona» — una hora buscando el error donde no está. (El `ParkingDemoSeeder`
tiene justamente ese hueco: no crea suscripción.)

### Guion sugerido

1. **Recepción**: el mapa, las llegadas de hoy, los KPIs de ocupación
2. **Habitación 103** → el folio con noches y minibar
3. **Habitación 402** → extranjero exento de IVA (art. 481 ET)
4. **Habitación 401** → se pasó de la fecha, con una cortesía aplicada
5. **Check-out de la 103** → factura POS de verdad, con su tiquete
6. **Reportes → Ocupación e ingresos** → ADR y RevPAR de 60 días
7. **Entrar como recepción** → no tiene «cerrar sin facturar» ni cortesías

---

## 11. Segunda entrega: calendario, temporadas y pronóstico

Lo que faltaba y se notaba. Añadido el **30 de octubre de 2026**.

### 11.1 Calendario de disponibilidad (`Hotel → Calendario`)

La Recepción muestra **hoy**, y eso cubre la mitad del trabajo. La otra mitad
es «¿qué tengo libre del 15 al 20?», y para eso no había pantalla: tocaba
empezar a crear una reserva y mirar qué salía en el desplegable.

Habitaciones en filas, días en columnas, cada reserva una barra. Clic en un
hueco abre la reserva con la habitación y la fecha puestas.

Sale de **`HotelAvailability::calendario()`**, la misma clase que decide si un
check-in cabe. No es casualidad: un calendario que pintara libre una noche que
el check-in rechaza haría que el recepcionista dejara de creerle a la pantalla,
y entonces no sirve de nada. `test_el_calendario_no_contradice_a_la_disponibilidad`
recorre todas las casillas comprobando justo eso.

### 11.2 Tarifas por temporada (`Hotel → Temporadas`)

Un rango de fechas con su precio. Si una noche cae dentro, manda la temporada.

- **Cada noche se cotiza por separado.** El folio ya guardaba un renglón por
  noche, así que encajó sin inventar nada: cada renglón nace con el precio de
  su fecha. Una estadía del 29/12 al 03/01 cuesta lo que debe costar en vez de
  un promedio.
- **Las dos fechas son inclusivas**, al revés que el rango de una estadía. Una
  temporada no son noches, son días del calendario: «del 15 de diciembre al 15
  de enero» incluye los dos, que es como lo dice cualquiera.
- **`priority`** resuelve el empate, y sirve para meter un puente caro de tres
  días dentro de una temporada alta de un mes sin partirla en tres.
- **`rate_is_fixed`** (en reservas y estadías) marca la tarifa pactada: un
  convenio corporativo no se reajusta porque caiga un puente en el medio. Se
  decide **una vez**, al crear, y queda escrito — no se adivina en cada cobro,
  porque adivinar significa cobrar distinto de lo pactado y descubrirlo con el
  huésped en el mostrador.

Las temporadas **empiezan vacías**: sin ninguna configurada todo se cobra a la
tarifa del tipo, exactamente como antes. El despliegue no cambia ningún precio
por su cuenta.

### 11.3 Pronóstico de ocupación (`Hotel → Pronóstico`)

El único reporte que mira hacia adelante. Si el fin de semana viene en 40 %
todavía hay tiempo de hacer algo; si se entera el lunes siguiente, no.

Sale del mismo `calendario()`, así que el pronóstico y la rejilla no pueden
discrepar. El ingreso es lo **comprometido** a la tarifa de cada fecha, no lo
facturado.

### 11.4 El depósito de la reserva, conectado

Era un número en un formulario: se escribía, se mostraba y nadie lo leía. Ahora
son dos cosas distintas, que es lo que siempre fueron:

| Campo | Qué es |
|---|---|
| `deposit_amount` | Lo **pactado**: «para apartar son $200.000» |
| `deposit_advance_id` | Lo **recibido**: el anticipo con su fecha, forma de pago y turno de caja |

Al facturar el check-out se cruza solo —vía `CustomerAdvanceService`, que ya
existía— y el huésped paga la diferencia. La pantalla de cobro muestra «Tiene a
favor» antes del total, para que nadie le pida el total completo a quien ya
abonó la mitad.

Al cancelar o marcar no-show, `avisoDelDeposito()` dice que hay plata a nombre
de esa persona. **El sistema no decide** si se devuelve o se retiene como
penalidad —eso es de cada caso— pero no la deja desaparecer en silencio.

### 11.5 Check-out a crédito

La empresa que manda empleados todo el mes y paga a treinta días es cliente
típico de hotel urbano, y no se podía atender: la pantalla siempre cobraba el
total.

`issueForStay` acepta `a_credito`. La factura queda pendiente con el
`payment_terms_days` del cliente y su `due_date`, así entra en cartera y vence
cuando toca. **A Consumidor Final no se le fía**: la cartera se cobra por
tercero y nadie sabría a quién llamar. El cupo de crédito no se revisa aquí —lo
hace `SaleInvoiceEngine` al contabilizar, que es por donde pasan todas las
ventas.

### 11.6 Un fallo que encontraron las pruebas

El pronóstico valoraba **todo a tarifa base**, ignorando las temporadas.
`calendario()` cargaba el tipo de habitación con un `select` parcial que no
incluía `hotel_id`, y `HotelTarifas` busca las temporadas justamente por ahí:
llegaba en `null`, ninguna coincidía y nadie se enteraba.

Habría salido a producción subestimando los ingresos de diciembre sin dar
ningún error. Los `select` parciales sobre relaciones son exactamente esta
clase de trampa: traen solo lo que se nombra, y lo que falta llega en `null`
sin quejarse.

---

## 12. Pruebas

60 pruebas nuevas, en cuatro archivos:

| Archivo | Qué cubre |
|---|---|
| `HotelDisponibilidadTest` | 15 — el sobrecupo por los cuatro caminos, el convenio de fechas, capacidad, cancelaciones |
| `HotelEstadiaYFolioTest` | 28 — check-in, pase nocturno, salida temprana y tardía, consumos, exención de IVA, traslado, factura, cierre sin facturar, anulación |
| `HotelPermisosTest` | 10 — quién puede dejar de cobrar, y a qué POS lleva el botón |
| `HotelImpresosTest` | 7 — la cuenta, la tarjeta de registro, el aislamiento por empresa, las guías |

Más la cuenta del hotel agregada a `ImpresionTermicaTest`, que ahora cubre
**cinco** formatos térmicos.

Hay un trait reutilizable, `Tests\Concerns\MontaUnHotel`, que monta el escenario
(hotel, zona, tipo con tarifa, habitaciones, turno de caja) sobre la empresa de
la base de desarrollo y lo borra en `tearDown`. Levantar una empresa entera por
prueba sería más limpio en teoría y en la práctica significa sembrar el plan de
cuentas completo cada vez: las pruebas de facturación pasarían de segundos a
minutos.

Las pruebas que más valen son las tres del dinero:

- **`test_el_que_se_va_antes_no_paga_la_noche_que_no_durmio`** — el caso que
  descuadra una cuenta sin que nadie lo note.
- **`test_la_salida_tardia_suma_una_noche`** — la noche que el hotel regalaría.
- **`test_la_rebaja_del_folio_va_como_descuento_global`** — que el total de la
  factura sea el del folio y que ninguna línea quede en negativo.

Comando:

```bash
docker compose exec -T -e DB_CONNECTION=pgsql -e DB_HOST=postgres -e DB_PORT=5432 \
  -e DB_DATABASE=emprenddi -e DB_USERNAME=emprenddi -e DB_PASSWORD=secret \
  app php artisan test --filter=Hotel
```

---

## 13. Lo que quedó fuera, y por qué

| No está | Por qué |
|---|---|
| **Canal de reservas (Booking, Airbnb)** | Solo se guarda el **origen** de la reserva. Una integración real es sincronización bidireccional de disponibilidad con un tercero, y eso es un módulo propio |
| **División de cuenta entre huéspedes** | El folio es uno por habitación. Dos cuentas en una habitación es una decisión de producto que conviene ver pedida antes de construirla |
| **Estado de camarería separado** | Un solo estado cubre el ciclo real; dos columnas habría que mantenerlas sincronizadas |
| **Histórico de inventario de habitaciones** | Declarado como limitación en el reporte de ocupación |
| **Penalidad por salida temprana** | Se carga como servicio al folio, a mano. Automatizarla exige una política que cada hotel tiene distinta |

---

## 14. Archivos

**Nuevos (46):**

```
database/seeders/HotelDemoSeeder.php

database/migrations/
  2026_10_29_100000_create_hotel_tables.php
  2026_10_29_100100_create_hotel_guests_table.php
  2026_10_29_100200_create_hotel_reservations_table.php
  2026_10_29_100300_create_hotel_stays_table.php

app/Models/Hotel/
  Hotel.php  HotelZone.php  RoomType.php  Room.php
  Guest.php  Reservation.php  Stay.php  StayCharge.php

app/Services/Hotel/
  HotelAvailability.php      HotelReservationEngine.php
  HotelStayEngine.php        HotelFolio.php
  HotelBillingEngine.php     HotelReports.php
  HotelProductProvisioner.php  HotelDefaultsProvisioner.php
  HotelStayNumberer.php      HotelRoomBulkCreator.php

app/Support/HotelSettings.php

app/Console/Commands/
  EnableHotelCommand.php     SyncRolePermissions.php

app/Http/Controllers/App/
  HotelDocumentController.php  HotelReportExportController.php

app/Filament/App/Pages/Hotel/
  HotelFrontDesk.php
  Reports/HotelOccupancyReport.php
  Reports/HotelGuestsReport.php

app/Filament/App/Resources/Hotel/
  HotelResource.php  HotelZoneResource.php  RoomTypeResource.php
  RoomResource.php   GuestResource.php      ReservationResource.php
  StayResource.php   (+ sus Pages)

resources/views/
  filament/app/pages/hotel/front-desk.blade.php
  filament/app/pages/hotel/partials/front-desk-styles.blade.php
  filament/app/pages/hotel/reports/occupancy.blade.php
  filament/app/pages/hotel/reports/guests.blade.php
  filament/app/pages/hotel/reports/partials/report-styles.blade.php
  hotel/folio.blade.php
  hotel/registration-card.blade.php
  docs/hotel.blade.php
  docs/layout.blade.php

tests/Concerns/MontaUnHotel.php
tests/Feature/HotelDisponibilidadTest.php
tests/Feature/HotelEstadiaYFolioTest.php
tests/Feature/HotelPermisosTest.php
tests/Feature/HotelImpresosTest.php
```

**Modificados (9):**

```
app/Services/Auth/PermissionsCatalog.php          grupo Hotel + rol cashier
app/Support/PosDestination.php                    destino HOTEL
app/Providers/Filament/AppPanelProvider.php       grupo de menú «Hotel»
app/Filament/SuperAdmin/Resources/CompanyResource.php   módulo activable
app/Filament/App/Pages/Settings.php               pestaña Hotel
routes/web.php                                    impresos, exports, guía
resources/views/docs/parking.blade.php            usa la plantilla compartida
resources/views/filament/app/topbar/docs-button.blade.php  guía por módulo
resources/views/partials/thermal-print.blade.php  comentario (5 formatos)
tests/Feature/ImpresionTermicaTest.php            cubre la cuenta del hotel
```
