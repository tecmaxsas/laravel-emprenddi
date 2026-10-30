# Módulo Hoteles
### Manual del equipo de Capacitación y Ventas

> Documento de referencia para presentar, vender y enseñar el módulo de Hoteles.
> Última actualización: 2026-10-29.
>
> Hay otros dos documentos del módulo y sirven para cosas distintas:
> **`MODULO_HOTELES.md`** es el técnico (esquema, decisiones, despliegue) y
> **emprenddi.com/docs/hotel** es la guía pública que se le puede pasar a un
> prospecto. Este es el de ustedes.

---

## 1. Qué resuelve

Un hotel no lleva su operación en un POS de mostrador y tampoco en el de un
parqueadero. La diferencia es una sola y de ella sale todo lo demás:

> **Una estadía es una cuenta abierta, no una venta.**

El huésped entra, se le van cargando noches, el restaurante, la lavandería y el
minibar, y **todo se cobra junto al salir**. Eso se llama **folio**, y se
parece mucho más a la cuenta de una mesa de restaurante que a una venta de
tienda.

La segunda diferencia es que un hotel **vende tiempo futuro**: se aparta una
habitación para el viernes. Y el error que un hotel no se puede permitir es
vender dos veces la misma noche, porque eso no se arregla con un descuento:
hay dos familias en la recepción y una sola habitación.

Ese problema tiene nombre —**sobrecupo**, o *overbooking*— y es el primer
argumento de venta del módulo. Un hotel que lleva reservas en un cuaderno o en
un Excel compartido lo ha vivido.

---

## 2. A quién le sirve

- **Hoteles pequeños y medianos** — de 8 a 120 habitaciones es donde mejor cae
- **Hostales y casas de huéspedes**
- **Apartahoteles y aparta-estudios por noche**
- **Fincas, cabañas y glampings** — las zonas se llaman «Cabañas» en vez de
  «Piso 2» y funciona igual
- **Moteles** — el cobro por noche y la rotación de habitación es el mismo
  flujo

Les sirve especialmente si:

- Llevan las reservas en papel, Excel o WhatsApp
- Ya se les ha cruzado una reserva alguna vez
- Facturan a mano y pelean con la DIAN al final del mes
- Tienen restaurante o bar y hoy no pueden cargarlo a la habitación
- No saben cuál fue su ocupación del mes pasado

---

## 3. Qué NO hace — léelo antes de la primera demo

Esto es lo más importante del manual. **Prometer de más en una demo se paga
caro**, y hay cuatro cosas que conviene decir antes de que las pregunten.

**No se conecta con Booking ni con Airbnb.** El módulo deja marcar de dónde
vino cada reserva —Booking, Airbnb, agencia, teléfono, WhatsApp, mostrador—
pero eso es **una etiqueta para reportes, no una sincronización**. Si el hotel
recibe una reserva por Booking, alguien la digita. Si te preguntan por la
integración: está en estudio, tiene costo adicional y todavía no está
disponible. **No la ofrezcas como «ya viene».**

**No divide la cuenta de una habitación entre dos huéspedes.** El folio es uno
por habitación. Dos personas que quieren pagar mitad y mitad se resuelven
cobrando y dando dos recibos. *(Lo de varias habitaciones en una sola factura
sí existe: son los grupos.)*

**No es un channel manager ni un motor de reservas web.** El huésped no reserva
solo desde la página del hotel.

**No manda la confirmación solo.** El botón de WhatsApp abre el chat con el
mensaje escrito y el de correo lo envía, pero alguien tiene que apretarlos.

---

## 3 bis. Lo que sí hace y conviene enseñar

Tres cosas que llegaron después de la primera versión y que son argumento de
venta por sí solas.

**Calendario de disponibilidad.** Habitaciones en filas, días en columnas. Se
ve el hueco de tres noches entre dos reservas y se reserva con un clic encima.
Es la pantalla que más impresiona en una demo porque es la que el hotelero
reconoce de inmediato.

**Tarifas por temporada.** Diciembre, Semana Santa y los puentes con su propio
precio, y cada noche se cobra a lo que valga su fecha. El hotel no tiene que
acordarse de devolver el precio en enero. Con prioridad, para meter un puente
caro dentro de una temporada larga.

**Grupos.** Un matrimonio o un congreso se aparta de una vez —«cinco dobles del
8 al 10»— y se factura en **un solo documento**, normalmente a crédito contra
la empresa o la agencia. Es lo que abre el cliente corporativo.

Y dos más pequeñas: **planes de alimentación** (con desayuno, media pensión,
todo incluido) y **confirmación al huésped** por WhatsApp o correo con el
código de la reserva.

---

## 4. El vocabulario del cliente

Un hotelero habla distinto. Si usas sus palabras, la demo cambia de tono.

| Palabra | Qué significa |
|---|---|
| **Folio** | La cuenta abierta de una habitación |
| **Check-in / check-out** | Entrada y salida |
| **No-show** | Reservó y no llegó |
| **Overbooking / sobrecupo** | Vender dos veces la misma noche |
| **Walk-in** | Llegó sin reserva |
| **Late check-out** | Entregar la habitación después de la hora |
| **Ocupación** | Qué porcentaje del hotel se usó |
| **ADR** | *Average Daily Rate*: a cuánto se vendió la noche promedio |
| **RevPAR** | Ingreso por habitación disponible |
| **Camarería / housekeeping** | El aseo de las habitaciones |
| **Titular** | El huésped responsable de la habitación |

**Las tres cifras que un hotelero mira** son ocupación, ADR y RevPAR. Explicar
bien RevPAR es la forma más rápida de que te tomen en serio:

> Un hotel con tarifa altísima y 20 % de ocupación gana menos que uno con
> tarifa media y 90 %. El RevPAR es el único número que mezcla las dos, y por
> eso es el que manda. Mirando solo la tarifa, nadie nota que está vacío.

---

## 5. Qué hay que configurar antes de operar

Son cuatro pasos y se hacen una sola vez. En una implementación normal toma
entre media hora y dos horas, según cuántas habitaciones tenga.

**Paso 1 — El hotel.** `Hotel → Hoteles`. Código, nombre, dirección, teléfono,
**RNT** y —obligatorio— la **sede de facturación**. Sin sede no hay resolución
DIAN ni consecutivo, y el check-out no va a poder facturar. También se definen
la hora de entrada y la de salida; la de salida no es decorativa, porque decide
a partir de cuándo una entrega tardía suma otra noche.

**Paso 2 — Los tipos de habitación.** `Hotel → Tipos de habitación`. Sencilla,
Doble, Suite… **Aquí vive la tarifa**, no en la habitación: la 101 y la 102 son
dos dobles y valen lo mismo. Al activar el módulo se siembran seis tipos en
**$0**, y eso es a propósito: con tarifa en cero el sistema **no deja alojar a
nadie**. Es preferible a abrir un folio sin precio, y obliga a configurarlo.

**Paso 3 — Zonas y habitaciones.** `Hotel → Zonas` son los pisos, torres o
alas; agrupan el mapa de la recepción. Después `Hotel → Habitaciones`, que
tiene un botón **«Crear en lote»** para generar un piso completo (101 a 112) de
una sola vez. Un hotel de 40 habitaciones se arma en cinco minutos.

**Paso 4 — Los permisos.** `Administración → Roles`. Repartir el grupo
**Hotel** a quien corresponda. Más sobre esto en la sección 8.

> **Si al hacer un check-in dice «La habitación no tiene tarifa»**, es el paso
> 2 sin terminar. Es el tropiezo más común en una implementación.

---

## 6. Recorrido por el sistema

El menú **Hotel** queda en este orden, que es el del uso diario:

| Pantalla | Para qué |
|---|---|
| **Recepción** | Donde se trabaja todo el día |
| **Reservas** | Crear y gestionar lo apartado |
| **Estadías** | El histórico: buscar una cuenta vieja, reimprimirla |
| **Huéspedes** | El registro de quién se alojó |
| Hoteles · Zonas · Tipos · Habitaciones | Configuración |
| **Ocupación e ingresos** | ADR y RevPAR |
| **Huéspedes alojados** | El registro hotelero, con filtro de extranjeros |

### La Recepción

**Es la pantalla.** Si solo tienes diez minutos de demo, enséñala y nada más.

Un mapa con todas las habitaciones, cada una de un color según su estado, y el
recepcionista trabaja **a golpe de clic sobre la habitación**:

- **Verde (disponible)** → check-in
- **Roja (ocupada)** → el folio: cargar consumos, trasladar, cambiar la fecha
  de salida, recibir depósito, imprimir la cuenta, cobrar
- **Azul (en aseo)** → marcarla aseada y devolverla al inventario
- Ámbar (reservada), gris (mantenimiento), gris oscuro (bloqueada)

Cada habitación ocupada muestra **el nombre del huésped, cuándo sale y cuánto
debe** sin tener que abrirla.

Arriba, la lista de pendientes del turno: **llegadas de hoy** y **salidas de
hoy**, con las que se pasaron de la fecha marcadas. Y cuatro indicadores en
vivo: libres, ocupadas, en aseo y **porcentaje de ocupación**.

El buscador acepta número de habitación, nombre del huésped, código de reserva
(`R-000012`) o número de folio (`H-000045`). Es a donde va el recepcionista
cuando suena el teléfono y le preguntan por «el señor Gómez».

El **turno de caja se abre y se cierra desde ahí mismo**. Un hotel no tiene por
qué pasar por el POS de mostrador para nada.

---

## 7. Un día normal en la recepción

### Al abrir el turno

1. Entrar a **Recepción**. Si sale la franja roja de «sin turno de caja», abrir
   caja con la base. Los cobros van a fallar hasta que lo haga.
2. Mirar **llegadas de hoy**: a quién hay que esperar.
3. Mirar **salidas de hoy**: quién se va, y si alguien ya se pasó de la fecha.
4. Mirar las habitaciones **en aseo**: cuáles hay que apurar porque entran hoy.

### Durante el día

**Llega alguien con reserva** → clic en su nombre en «llegadas de hoy» →
confirmar datos → registrar al huésped titular → **Alojar**. Queda abierto el
folio con la primera noche ya cargada.

**Llega alguien sin reserva (walk-in)** → clic en una habitación verde →
fechas, personas, tarifa → registrar al huésped → **Alojar**.

**Llaman a reservar** → `Reservas → Crear`. Se puede apartar **un tipo** sin
decidir el número, que es lo normal: «una doble para el viernes». La
habitación concreta se asigna después, incluso el mismo día de la llegada.

**El huésped consume algo** → clic en su habitación → buscar el producto y
cargarlo, o escribir el servicio a mano (lavandería, salón, traslado).

**Se quiere quedar una noche más** → clic en la habitación → **Cambiar
salida**. El sistema comprueba que siga libre: si entra otra reserva esa noche,
avisa **ahora** y no el día que lleguen los dos.

**Hay un problema con la habitación** → **Trasladar**. El folio se va con la
persona: es su cuenta, no la de la habitación.

**Se va** → clic en la habitación → **Check-out y cobrar**.

### Al cerrar el turno

Cerrar caja desde la misma pantalla. El sistema compara lo esperado con lo
contado y muestra la diferencia.

---

## 8. Quién puede qué

Esto conviene explicarlo bien, porque **es un argumento de venta** y casi
siempre lo valoran.

El **recepcionista** (rol *cajero*) puede alojar, reservar, registrar huéspedes,
cargar consumos y marcar habitaciones aseadas.

Lo que **no** puede hacer, y es a propósito:

| No puede | Por qué |
|---|---|
| Cerrar un folio sin facturar | Es plata que el hotel deja de recibir |
| Anular una estadía | Borra el rastro de lo que pasó |
| Aplicar cortesías o rebajas | Es plata que el hotel deja de recibir |
| Cambiar tarifas | Le cambia el precio a todo el inventario |

Las cuatro las autoriza un **administrador**. La forma de decirlo en una demo:

> El recepcionista puede cobrar de más por error y eso se corrige. Dejar de
> cobrar no se corrige, porque nadie se entera. Por eso solo el administrador
> puede hacerlo, y queda con motivo escrito y con nombre.

---

## 9. Los casos que van a preguntar

Son los que salen en toda demo. Vale la pena tenerlos practicados.

**«¿Y si se va antes de lo que dijo?»**
No paga la noche que no durmió. El sistema carga las noches mientras está
adentro; al hacer el check-out quita las que no usó. *(Es el caso que más
descuadra una cuenta en un hotel que lleva esto a mano.)*

**«¿Y si entrega la habitación a las cinco de la tarde?»**
Se le cobra una noche más, con los **minutos de gracia** que el hotel configure
—una hora por defecto—. Cobrarle una noche completa a quien entregó cinco
minutos tarde es una pelea en el mostrador que el hotel siempre pierde, y por
eso la holgura es configurable.

**«Tengo un extranjero, ¿le cobro IVA?»**
El alojamiento a un **turista extranjero no residente** es **exento** (art. 481
del Estatuto Tributario). Se marca en el check-in con el motivo escrito, porque
la exención hay que poder sustentarla. **Cubre el alojamiento, no los
consumos**: la cerveza del minibar se sigue gravando.

**«¿Puedo cobrarle un depósito al que reserva?»**
Sí. Entra como anticipo del cliente, la plata **entra a la caja el día que se
recibe**, y al facturar el check-out se cruza sola: el huésped paga solo la
diferencia. Necesita un cliente con nombre, porque un saldo a favor sin dueño
no se puede cruzar ni devolver.

**«El restaurante del hotel, ¿puede cargar a la habitación?»**
Sí, desde la recepción, cargando el producto o el servicio al folio.

**«¿Qué pasa si una habitación está en mantenimiento?»**
Sale del inventario vendible, también para fechas futuras. No se puede reservar
ni para dentro de un mes.

**«¿Puedo regalar una noche o hacer un descuento?»**
Sí, con permiso de administrador y motivo escrito. La rebaja entra con el IVA
del alojamiento, así que también baja el impuesto — si no, el hotel le pagaría
a la DIAN el IVA de una plata que no cobró.

**«Me equivoqué en el check-in.»**
Si no hay consumos cargados, se anula y la habitación vuelve a disponible. Si
ya hay consumos, se cierra sin facturar: así queda el rastro de lo que salió
del almacén aunque no se cobre.

**«¿Sirve para una finca / cabañas / glamping?»**
Sí. Las zonas se llaman «Cabañas» y los tipos «Cabaña familiar». Es el mismo
flujo.

---

## 10. Facturación y DIAN

El **check-out** hace todo en una sola operación: liquida el folio, emite el
documento, cobra y cierra la habitación.

- **Documento**: tiquete POS o factura electrónica DIAN, con el consecutivo y
  la resolución de la sede. Se propone el que el hotel tenga configurado y se
  puede cambiar en cada check-out.
- **El vuelto** se calcula si pagan en efectivo, igual que en los demás POS.
- **Requiere turno de caja abierto.** Una venta tiene que quedar en el cierre
  de alguien.
- Al cerrar, la habitación pasa a **aseo** y no a disponible: hay que entrar a
  tenderla antes de dársela a nadie.

Las noches se agrupan en **una sola línea** en la factura («Alojamiento hab.
203 — 4 noches»), aunque en el folio estén una por una. Cinco renglones
iguales en un documento es algo que nadie lee; en el folio sí van separadas
porque ahí se revisan con el huésped al frente.

---

## 11. Lo colombiano

Son tres cosas que un hotelero sabe que necesita, y que la competencia barata
no tiene.

**RNT.** El Registro Nacional de Turismo se guarda en la ficha del hotel y se
imprime en la cuenta del huésped. Es obligatorio exhibirlo para un prestador
inscrito.

**Tarjeta de registro hotelero.** Imprimible en tamaño carta —se firma y se
archiva—, con todos los que duermen en la habitación y no solo el que paga:
documento, nacionalidad, nacimiento, **procedencia, destino y motivo del
viaje**. Incluye la autorización de tratamiento de datos (Ley 1581 de 2012) y
las condiciones de la estadía. Siempre trae al menos cuatro renglones, porque
una familia llega con acompañantes que no están en el sistema y hay que poder
escribirlos a mano.

**Huéspedes extranjeros.** El reporte `Huéspedes alojados` tiene filtro de
**solo extranjeros**, con las columnas del registro, exportable a Excel. Es la
base para lo que hay que reportarle a Migración Colombia.

> Ojo con cómo se dice: el sistema **da la información lista para reportar**.
> No envía nada a Migración automáticamente.

---

## 12. Los reportes

**Ocupación e ingresos** — día por día: habitaciones disponibles, noches
vendidas, % de ocupación, ingreso de alojamiento, **ADR**, **RevPAR** y
consumos aparte. Con barra visual de ocupación y exportable a Excel. Hay un
segundo Excel con el detalle estadía por estadía, para cuando el dueño
pregunte «¿y ese día de qué fue?».

**Huéspedes alojados** — el registro hotelero del período.

**Un detalle que conviene decir antes de que lo noten:** la columna
«Habitaciones» es el inventario **de hoy**. El sistema no guarda cuántas
habitaciones tenía el hotel en cada fecha, así que un hotel que amplió un piso
verá los meses anteriores calculados contra el inventario actual. Está dicho en
la propia pantalla.

---

## 13. La cuenta demo

Está sembrada y lista. Pídele los datos de acceso al equipo técnico (la
contraseña se fija en el despliegue, no está publicada).

| | |
|---|---|
| Empresa | Hotel Mirador del Parque S.A.S. |
| Administrador | `demo@hotelmirador.co` |
| Recepción | `recepcion@hotelmirador.co` |
| Entrada | `/app/hotel` |

Trae 24 habitaciones, 6 ocupadas, reservas en todos los estados, tres llegadas
para hoy y **dos meses de historia** para que los reportes tengan contenido:
alrededor de 61 % de ocupación, ADR ~$225.000 y RevPAR ~$138.000.

> **Son dos usuarios a propósito.** La diferencia entre lo que puede un
> administrador y lo que puede un recepcionista se ve mejor entrando con cada
> uno que explicándola.

### Guion de 10 minutos

1. **Recepción** — el mapa, las llegadas de hoy, los indicadores de ocupación
2. **Habitación 103** — el folio, con noches y minibar
3. **Habitación 402** — extranjero exento de IVA (art. 481 ET)
4. **Habitación 401** — se pasó de la fecha, y tiene una cortesía aplicada
5. **Check-out de la 103** — factura de verdad, con su tiquete
6. **Reportes → Ocupación e ingresos** — ADR y RevPAR de 60 días
7. **Entrar como recepción** — no aparecen «cerrar sin facturar» ni cortesías

El paso 5 es el que cierra la venta, y el 7 el que convence al dueño.

---

## 14. Tropiezos comunes

| Síntoma | Qué pasó |
|---|---|
| «La habitación no tiene tarifa» | Falta ponerle precio al tipo. Se siembran en $0 a propósito |
| «No tienes un turno de caja abierto» | Abrir caja desde la Recepción antes de cobrar |
| «El hotel no tiene sede asignada para facturar» | Falta la sede de facturación en la ficha del hotel |
| «La habitación no está disponible en esas fechas» | Dice exactamente qué reserva o estadía estorba. No es un error: está evitando un sobrecupo |
| El menú «Hotel» no aparece | El módulo no está activo para esa empresa, o al usuario le falta el permiso `hotel.use` |
| El administrador no ve las opciones de hotel | Su rol no recibió los permisos nuevos. Lo resuelve el equipo técnico con `roles:sync-catalog` |

---

## 15. Cómo cerrar una demo

Tres frases que funcionan, según con quién estés hablando.

**Al dueño:**
> Hoy usted sabe cuánto facturó. Después de esto va a saber cuánto de su hotel
> se usó, a qué precio lo vendió, y cuánto le dejó cada habitación — tenga
> huésped o no.

**Al administrador:**
> Nadie va a poder dejar de cobrar sin que quede su nombre y el motivo.

**Al recepcionista** —que suele ser quien más se resiste—:
> Todo lo del día está en una pantalla. No va a tener que abrir el cuaderno de
> reservas, ni la calculadora, ni llamar a contabilidad para saber cuánto debe
> la 203.
