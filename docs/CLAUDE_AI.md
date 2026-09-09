# Claude AI

Conversaciones con Claude sobre los datos del negocio: ventas, inventario,
cartera, gastos y caja, consultados en el momento.

## Dónde está

Grupo **Claude AI** en el panel de la empresa, con dos pantallas:

- **Conversar** — el chat. Permiso `ai.use` (admin y manager por defecto).
- **Conexión y saldo** — cómo se paga y con qué llave. Permiso `ai.manage`
  (solo admin: toca la llave de Anthropic y el saldo, que es plata).

## Las dos formas de conectarse

### 1. Saldo con Tecmax

La llave la pone Tecmax y la empresa consume de un saldo en pesos. Cada
respuesta descuenta según los tokens que gastó. Cuando se acaba, Claude deja de
responder y la pantalla lo dice.

Para recargar, el botón **Solicitar recarga por WhatsApp** abre un chat con el
equipo comercial con el nombre de la empresa ya escrito. Tecmax cobra por fuera
y abona el saldo desde el panel de super admin: **Empresas → (fila) → Saldo
Claude**, que permite recargar o ajustar.

El saldo se lleva como libro de movimientos, igual que el kardex: cada fila trae
el saldo que quedó después, así se puede explicar de dónde salió cada peso
cobrado. La pantalla de conexión muestra los últimos 25 movimientos.

### 2. Cuenta propia de Anthropic

La empresa pega su propia llave (`console.anthropic.com` → API Keys) y Anthropic
le factura directamente. No consume saldo.

La llave **se guarda cifrada** y no se vuelve a mostrar completa — solo los
últimos seis caracteres, para reconocerla. Guardar con el campo vacío no la
borra: significa «no la cambié». Para quitarla hay una casilla explícita.

Los dos modos son excluyentes a propósito. Si la empresa eligió su propia cuenta
y la llave falta o falla, la integración se detiene y lo dice; **no cae a la
llave de Tecmax**. Caer sería cobrarle a Tecmax lo que el cliente eligió pagar
aparte, y nadie se enteraría.

## Las conversaciones se conservan

Cada conversación queda guardada con todos sus mensajes. La lista de la
izquierda es el historial del usuario ordenado por actividad: se abre cualquiera
y se sigue donde iba. Al entrar a la pantalla se abre automáticamente la última.

El título sale de la primera pregunta — nadie titula sus conversaciones, y sin
eso la lista sería «Conversación 1, 2, 3» y no se podría retomar ninguna.

Cada conversación es del usuario que la creó: no se abren las de otros, ni por
id.

## Qué puede ver Claude, y por qué así

**Claude no escribe SQL.** Tiene un juego cerrado de consultas ya escritas y
elige cuál usar según la pregunta:

| Consulta | Para qué |
|---|---|
| `resumen_negocio` | Nombre, sedes, cuántos productos y terceros hay |
| `resumen_ventas` | Total, facturas, ticket promedio y medios de pago |
| `ventas_por_dia` / `ventas_por_mes` | Tendencias |
| `productos_mas_vendidos` | Ranking por unidades e importe |
| `clientes_top` | Quiénes más compran |
| `inventario` | Existencias por producto, o solo los agotados |
| `cartera_clientes` | Quién debe, cuánto y desde hace cuántos días |
| `cuentas_por_pagar` | A qué proveedores se les debe |
| `gastos` | Gastos por concepto |
| `cierres_de_caja` | Cierres con sus diferencias |
| `buscar_producto` / `buscar_tercero` | Ficha de un registro |

La alternativa obvia era darle una herramienta de «ejecuta este SELECT». Se
descartó por tres razones:

1. **Esta base es multiempresa.** Un SELECT generado por un modelo que olvide un
   `where company_id` le muestra a un cliente los datos de otro. Es el error más
   fácil de cometer en este esquema, y ninguna instrucción en el prompt lo
   previene de forma confiable.
2. **Un usuario puede pedirle a Claude, con toda intención, que salte el
   filtro.** Con consultas fijas no hay nada que saltar.
3. **Una consulta mal armada sobre tablas de millones de filas tumba la base de
   todos los clientes.**

Con este diseño el aislamiento no depende de que el modelo se porte bien: cada
consulta lleva su `company_id` cosido, tomado de la empresa en sesión y nunca de
lo que el modelo mande. Ninguna escribe: todas son SELECT.

Agregar una consulta nueva es añadir una entrada en
`app/Services/Ai/BusinessTools.php` con su método.

## Cómo se calcula el cobro

Anthropic devuelve cuántos tokens consumió cada respuesta. Se convierten a
dólares con las tarifas del modelo, a pesos con la tasa configurada, y se
multiplican por el margen de Tecmax. Todo vive en `config/ai.php` y se ajusta
por variables de entorno:

```
ANTHROPIC_API_KEY=sk-ant-...        # llave de Tecmax (modo saldo)
TECMAX_SALES_WHATSAPP=57...         # a quién llega el botón de recarga
ANTHROPIC_MODEL=claude-sonnet-5     # modelo por defecto
AI_USD_TO_COP=4200                  # tasa para cobrar
AI_MARGIN=1.3333                    # 25 % de ganancia sobre lo facturado
```

Cada mensaje guarda sus tokens y su costo, así que un cobro siempre se puede
explicar.

`AI_MARGIN` es un **multiplicador, no un porcentaje**, y sale del modelo de
negocio: el cliente recarga 50 USD, ve 50 USD en su monedero, y por dentro esos
50 le alcanzan para 37,5 USD de consumo real. Tecmax se queda con 12,5 — el
**25 % de lo facturado**. El multiplicador que produce eso es

    50 / 37,5 = 1 / (1 − 0,25) = 1,3333

Ojo con la confusión: `1.25` **no** es una ganancia del 25 %, es del 20 % sobre
la venta. Para una ganancia del X % sobre lo facturado, el multiplicador es
`1/(1−X)`:

| Ganancia sobre la venta | `AI_MARGIN` |
|---|---|
| 20 % | 1.25 |
| **25 %** | **1.3333** |
| 30 % | 1.4286 |
| 40 % | 1.6667 |

## Instalación

```bash
cd /opt/emprenddi
git pull origin main
docker exec emprenddi_app php artisan migrate --force
docker exec emprenddi_app php artisan optimize:clear
```

Después hay que agregar las variables en **`/opt/emprenddi/.env.production`** —no
en `.env`: en producción el contenedor arranca con `env_file: .env.production` y
el despliegue usa `--env-file .env.production`—. Basta con `ANTHROPIC_API_KEY`
para ofrecer el modo con saldo; sin esa variable las empresas en ese modo ven un
aviso y solo funciona el de cuenta propia.

```bash
nano /opt/emprenddi/.env.production
docker compose --env-file .env.production -f docker-compose.prod.yml restart app
docker exec emprenddi_app php artisan optimize:clear
```

La migración `2026_09_13_100300` entrega los permisos a los roles que ya
existen; sin ella la sección queda instalada y nadie puede abrirla.

## Limitaciones conocidas

- **No se ha probado contra la API real de Anthropic.** Todas las pruebas
  simulan la respuesta. La primera conversación en producción es la prueba de
  fuego del formato de la petición.
- **La respuesta es síncrona.** La pantalla se bloquea mientras Claude piensa
  (unos segundos). No hay streaming: montar colas y websockets para eso era
  desproporcionado. Si las respuestas largas se sienten lentas, ese es el
  siguiente paso.
- **Claude no ve todo.** Solo lo que cubren las consultas de la tabla de arriba.
  Si alguien pregunta por algo que no está —márgenes por producto, comisiones,
  nómina— responderá que no tiene esa consulta, no inventará el dato.
- **El saldo puede quedar en negativo por el último cobro.** Se permite a
  propósito: cortar a mitad de una respuesta ya generada dejaría al cliente sin
  lo que ya se le pagó a Anthropic. El bloqueo ocurre al empezar la siguiente
  pregunta.
- **Los cambios de la llave y del modo no quedan en la bitácora de auditoría**,
  porque viven dentro de `companies.settings`, que no se audita.
- **Las conversaciones no se purgan solas.** Si con el tiempo pesan, habrá que
  agregar un comando como el de `audit:purge`.

## Advertencia para el usuario

La pantalla lo dice y conviene repetirlo: Claude **solo consulta** — no crea, no
modifica y no borra nada. Aun así, es un modelo de lenguaje: conviene verificar
en el reporte correspondiente cualquier cifra sobre la que se vaya a tomar una
decisión importante.
