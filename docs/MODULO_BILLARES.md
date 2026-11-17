# Módulo Billares

Módulo vertical opcional, al mismo nivel que Restaurante, Parqueadero y
Hotel: se activa por empresa desde el SuperAdmin y, sin esa activación, es
invisible completo —menú, pantallas, rutas y reportes—.

Fecha de implementación: **17 de noviembre de 2026**.

---

## 1. Qué resuelve

Un billar no es un mostrador ni un parqueadero. Es las dos cosas a la vez, y
de ahí sale todo el diseño:

> **Una mesa es un reloj corriendo con una cuenta abierta colgando.**

Se cobra el tiempo, como un parqueadero. Pero mientras juegan piden cervezas,
cigarrillos y empanadas, y eso se va acumulando en la cuenta de la mesa, como
el folio de un hotel. Al final pagan una sola vez.

Hasta ahora, un salón que quisiera usar Emprenddi tenía que elegir entre
cobrar el tiempo por fuera del sistema (en una libreta) o montar cada cerveza
como una venta suelta del POS, perdiendo la relación con la mesa. Las dos
salidas rompen el arqueo de caja.

Tres cosas más que un salón colombiano necesita y que ningún módulo existente
hacía:

1. **La tarifa cambia por franja.** La hora de un martes a las tres de la
   tarde no vale lo que la de un viernes a las diez de la noche. Y una cuenta
   que empieza a las 5 y termina a las 8 cruza de franja a mitad de partida.
2. **El reloj se para.** Se van a comer, vuelven. Ese tiempo no se cobra, y
   es el reclamo que más vuelve al mostrador.
3. **Se cambian de mesa.** El paño está roto, llegó más gente y necesitan la
   grande. Si las dos mesas tienen tarifas distintas, cobrar todo a la última
   es cobrarle de más a quien se movió por culpa del salón.

---

## 2. Lo que quedó construido

### 2.1 Base de datos — 2 migraciones, 7 tablas

| Tabla | Para qué |
|---|---|
| `billiard_table_types` | Pool, tres bandas, mini pool, rana, ping pong. **Es lo que separa las tarifas** |
| `billiard_halls` | Cada sala física, con su **sede de facturación** |
| `billiard_tables` | Las mesas. Cada una es un tile del mapa del terminal |
| `billiard_rates` | La tarifa, con su JSON de reglas (ver `BilliardRateEngine`) |
| `billiard_sessions` | La cuenta de una mesa: quién, desde cuándo, cuánto debe |
| `billiard_session_charges` | Cada renglón: el tiempo y cada cerveza |
| `billiard_session_pauses` | Cada pausa, con hora, motivo y quién la hizo |

**Decisiones del esquema que conviene conocer:**

- **La pausa no es un estado.** Es `paused_at`: si tiene hora, el reloj está
  detenido. Tener además un `status = 'paused'` sería tener dos verdades sobre
  lo mismo, y el día que una se actualice sin la otra habría mesas cobrando
  tiempo mientras la pantalla las muestra detenidas.

- **No hay contador de segundos pausados.** Lo tentador era guardarlo en la
  sesión para no leer la tabla de pausas, pero entonces el tiempo cobrado
  tendría dos orígenes. El tiempo se deriva **siempre** de las filas de
  `billiard_session_pauses`; el mapa del salón las trae con un eager load.

- **La tabla de pausas existe por una razón concreta.** Es la que contesta
  «yo paré la mesa para ir a comer y me cobraron esa hora». Un contador de
  segundos no responde eso; hay que poder decir *«se paró a las 8:14 y se
  reanudó a las 8:52, lo hizo Marcela»*. Tres columnas más cuestan nada y
  evitan la discusión que de verdad pasa.

- **El tiempo también es un renglón** (`kind = time`), no una columna. Así una
  cuenta con dos tramos —jugaron en la 3, se pasaron a la 7— muestra cada
  tramo con su tarifa, que es justo lo que el cliente pide ver cuando reclama.

- **Los precios son al público (IVA incluido).** Misma convención que el folio
  del hotel y los extras del parqueadero. En un billar eso no es un detalle
  técnico: el aviso de la pared dice «hora $10.000» y eso es lo que el cliente
  espera pagar. La base gravable se descompone al guardar cada renglón, así
  que la factura copia base e impuesto tal cual y no hay dos cálculos de IVA
  que puedan discrepar en centavos.

- **Los totales en plata están en columnas**, no se suman al leer: el mapa del
  salón muestra el saldo de cada mesa ocupada y se repinta solo. Nada toca un
  renglón sin pasar por `BilliardAccount`, que recalcula al salir en la misma
  transacción.

- **`time_anchor_at`**, además de `started_at`. El primero es desde cuándo
  cuenta el tramo que se está cobrando ahora; el segundo, desde cuándo están
  en el salón. Son distintos después de un traslado.

### 2.2 Servicios

| Clase | Responsabilidad |
|---|---|
| `BilliardRateEngine` | Cuánto cuesta el tiempo. **Función pura**: recibe los tramos jugados y una tarifa, no toca la base |
| `BilliardSessionEngine` | Abrir, pausar, reanudar, trasladar, cerrar, anular, cotizar |
| `BilliardAccount` | La cuenta: consumos, ajustes, el renglón de tiempo, el recálculo y lo que va a la factura |
| `BilliardBillingEngine` | Emite la factura (POS o electrónica DIAN), registra el pago |
| `BilliardProductProvisioner` | El producto `BILLAR-MESA`, idempotente y auto-restaurable |
| `BilliardDefaultsProvisioner` | Los cinco tipos de mesa al activar el módulo |

### 2.3 Pantallas

- **Terminal** (`/app/billar`) — el mapa del salón. Verde libre, rojo ocupada,
  ámbar en pausa. Un clic en una libre la abre; un clic en una ocupada abre su
  cuenta, que es donde se cargan las cervezas, se para el reloj, se traslada
  la mesa y se cobra.
- **Salas**, **Tipos de mesa**, **Mesas**, **Tarifas** — la configuración.
- **Cuentas por período** — el reporte, con el tiempo y los consumos en
  columnas separadas.

---

## 3. El motor de tarifas

El JSON de `billiard_rates.config`:

| Clave | Qué hace |
|---|---|
| `type` | `per_hour`, `per_minute`, `flat`, `per_game`, `tiered` |
| `amount` | Valor base según el tipo |
| `free_minutes` | Cortesía inicial |
| `minimum_minutes` | «Mínimo una hora aunque jueguen veinte minutos» |
| `minimum_amount` | Cobro mínimo en pesos |
| `max_amount` | Tope por cuenta («mesa libre toda la noche») |
| `rounding` | `ceil` (por defecto), `round`, `floor`, `none` |
| `rounding_unit_min` | `60` = hora completa empezada. Es lo más común |
| `per_player` | La tarifa se multiplica por los jugadores |
| `schedules` | Las franjas |
| `tiers` | Los escalones |

### Las franjas

```json
"schedules": [
  { "label": "Happy hour", "days": [1,2,3,4], "from": "14:00", "to": "18:00", "amount": 6000 },
  { "label": "Noche",      "days": [5,6],     "from": "20:00", "to": "02:00", "amount": 14000 }
]
```

- `days` son días ISO (1 = lunes … 7 = domingo). Vacío = todos.
- Si `to` es menor que `from`, la franja **cruza la medianoche**: «la noche
  del viernes» incluye las dos de la mañana del sábado, que es como la piensa
  el dueño del salón.
- Gana la **primera** que coincida, así que el orden de la lista es el orden
  de prioridad. Lo que no cae en ninguna se cobra al `amount` base.

**Una cuenta que cruza de franja se parte en tramos y cada tramo paga la
suya.** Entraron a las 5 en happy hour y salieron a las 7: una hora a $6.000
más una hora a $12.000. Cobrarlas todas a la tarifa de la salida es el
reclamo que vuelve; cobrarlas a la de la entrada es regalar plata.

El motor camina por **cortes** y no minuto a minuto: una cuenta que alguien
dejó abierta un fin de semana serían tres mil iteraciones de Carbon por cada
repintada del mapa.

### Redondeo y mínimos

Los minutos que agregan el mínimo y el redondeo se cobran **a la tarifa del
último tramo jugado**: la que estaba corriendo al soltar el taco. Es lo que se
puede explicar en el mostrador —«se le completó la hora a la tarifa de la
noche, que es la que estaba corriendo»—.

### Los escalones no se parten por franja

Un escalón ya *es* una regla sobre el tiempo total («la primera hora $8.000,
de ahí en adelante $6.000»). Cruzarlo con las franjas daría dos reglas
peleando por el mismo minuto, así que `tiered` ignora `schedules` y se cobra
sobre el total cobrado. Está documentado en el código y en el formulario.

---

## 4. Decisiones de operación

**El traslado congela el tiempo.** Al pasar de la mesa 3 a la 7, el tiempo ya
jugado se escribe como su propio renglón con la tarifa de la mesa vieja, y el
reloj vuelve a arrancar. Si la mesa está en pausa, se reanuda primero: una
cuenta que se muda con el reloj detenido llegaría a la mesa nueva sin que
nadie se acuerde de arrancarlo, y esas son horas que el salón regala.

**Cerrar y facturar son dos pasos, no uno.** Pueden fallar por separado. Si el
cobro falla —no hay turno de caja, falta la resolución DIAN— la mesa ya quedó
libre y la cuenta cerrada con su tiempo liquidado, y se puede facturar después
desde el histórico. Al revés, un error de facturación dejaría la mesa ocupada
con los clientes ya en la puerta.

**El tiempo no se quita a mano de la cuenta.** Lo decide el reloj, no el
cajero. Para perdonarlo está el ajuste, que deja constancia de quién regaló
esa plata y por qué. La cortesía entra como renglón negativo y al facturar se
convierte en el **descuento global** de la factura —que la DIAN sí acepta,
porque baja la base gravable y recalcula el IVA—; una línea de factura con
valor negativo la rechaza.

**Anular ≠ cerrar en cero.** Una cuenta anulada no entró a la caja y no sale
en el reporte de ingresos; una cerrada en cero sí, porque es una cortesía y el
dueño quiere saber cuántas dio. Anular una cuenta **con consumos está
bloqueado**: haría desaparecer productos que ya salieron de la nevera.

**El reloj lo pinta el navegador.** Una sala de doce mesas repintando cada
segundo desde Livewire serían doce consultas por segundo para mover un número.
El servidor manda la hora de arranque; el contador lo lleva Alpine, y el mapa
se refresca entero cada 30 segundos para que los consumos que cargó otro
mesero aparezcan.

---

## 5. Contabilidad

- **Cuenta de ingreso: 4170 — Otras actividades de servicios.** Es donde el
  PUC colombiano deja el esparcimiento: un billar no vende mercancía (4135) ni
  transporte (4145). Si la empresa no tiene esa cuenta abierta, el producto
  queda sin cuenta y `SaleInvoiceEngine` cae al 4135; el contador lo corrige
  desde la ficha del producto.
- El tiempo va al producto **`BILLAR-MESA`** («Alquiler de mesa de billar»).
  Los consumos van a su propio producto, con su propio IVA y su propia cuenta
  —y descargan inventario—.
- **El IVA del consumo es el del producto**, no el del servicio de mesa.
- Toda factura exige **turno de caja abierto**, igual que el resto del
  sistema: si no, el arqueo nunca cuadra contra lo facturado.

---

## 6. Permisos

| Permiso | Para qué |
|---|---|
| `billiards.use` | Operar el salón: abrir mesas, cargar consumos, cobrar |
| `billiards.manage` | Configurar salas, mesas, tipos y tarifas |
| `billiards.adjust` | Aplicar cortesías y ajustes |
| `billiards.cancel` | Anular la cuenta de una mesa |
| `billiards.reports` | Ver los reportes |

El rol **CAJERO** trae solo `billiards.use`. Lo que no tiene es con qué dejar
de cobrar: la cortesía y la anulación son plata que el salón no recibe, y eso
lo autoriza un administrador. Es la misma lección del folio del hotel.

---

## 7. Cómo se activa

```bash
php artisan companies:enable-billiards 5
php artisan companies:enable-billiards --nit=900123456
```

El comando enciende el módulo, corre el bootstrap (PUC, impuestos, sede,
Consumidor Final, plantillas) y siembra los cinco tipos de mesa. Después, a
mano en el panel de la empresa:

1. Crear la sala **con su sede de facturación** (sin ella no hay resolución
   DIAN de dónde sacar el consecutivo, y es el primer error que reporta todo
   salón recién montado).
2. Crear las mesas, por zona.
3. Crear al menos una tarifa por tipo de mesa.
4. Asignar los permisos del grupo **Billares** a los roles.

> En el despliegue, tocar `PermissionsCatalog.php` hace que `deploy.sh` corra
> `RolesSeeder` y `roles:sync-catalog`, que es lo que reparte los permisos
> nuevos a los roles de las empresas que ya existían.

---

## 8. Pruebas

| Archivo | Qué cubre |
|---|---|
| `BilliardRateEngineTest` | 14 casos del motor: franjas, cruce de medianoche, días, cortesía, mínimos, redondeo, tope, partidas, jugadores, escalones, pausas y una franja mal escrita |
| `BilliardSessionTest` | 19 casos del ciclo de vida y la facturación, de punta a punta |
| `BilliardTerminalTest` | 9 casos de la pantalla, incluidos los dos guardas de CSS |

Los guardas de CSS vigilan las dos reglas que ya se rompieron en el terminal
de parqueadero y se pagaron con defectos en producción: que ninguna regla que
toque un campo use la forma corta `background` (resetea la imagen de la flecha
del select, de ahí las tres flechas apiladas), y que el selector cubra **todo**
`input` y no solo `input[type=text]` (si no, en modo oscuro los campos
numéricos quedan con texto blanco sobre fondo blanco).

**Esos guardas leen la hoja de estilos, no la pantalla.** Atrapan la regresión
concreta; no reemplazan abrir el terminal en modo oscuro y mirarlo.

---

## 9. Lo que NO quedó, y por qué

- **Reservas de mesa.** Un billar reserva por teléfono y a veces anota en una
  libreta; el estado `reserved` ya existe en la mesa para bloquearla a mano.
  Montar un calendario de reservas como el del hotel es otro módulo, y antes
  de construirlo conviene saber si los salones lo piden.
- **Dividir la cuenta entre jugadores.** El restaurante ya tiene
  `restaurant.bill.split`; cuando se pida, la pieza que falta es reusar ese
  flujo, no inventar otro.
- **Mensualidades / clientes frecuentes.** El parqueadero las tiene
  (`parking_memberships`). En billar se usan menos; cuando aparezca el caso,
  el molde ya está escrito.
- **Impresión del tiquete de mesa.** La factura se imprime por el camino
  normal del POS. Un «recibo de mesa» intermedio —el que se le muestra al
  cliente antes de pagar— todavía no existe.
- **Crear mesas en lote.** El parqueadero tiene `ParkingSpaceBulkCreator`. Un
  salón típico tiene entre seis y veinte mesas, así que crearlas a mano es
  tolerable; si aparece uno de cincuenta, el servicio del parqueadero se
  adapta en una tarde.

### Deuda conocida

El bloque de cobro (medio de pago, vuelto, cliente, tipo de factura) y el
buscador de productos están escritos otra vez en este terminal, parecidos a
los del parqueadero. **Las reglas no están duplicadas** —el vuelto vive en
`App\Support\Vuelto`, la cuenta del método de pago en `PaymentAccountResolver`
y el alta rápida de clientes en `QuickCustomer`—, así que lo repetido es
marcado, que es el tipo de duplicación que menos daño hace. La caja y el
selector de cliente sí se extrajeron a traits compartidos
(`AbreYCierraCaja`, `EligeCliente`); el terminal de parqueadero todavía lleva
su copia y puede adoptarlos cuando se vuelva a tocar esa pantalla.
