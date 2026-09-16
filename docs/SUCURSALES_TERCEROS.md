# Clientes con varias sucursales bajo el mismo NIT

## El problema

Hay clientes —cadenas, empresas con varios puntos, distribuidores con CEDIs— que
operan varias sucursales y todas facturan bajo el **mismo NIT**. Hoy el sistema
no deja registrarlas: existe un índice único `(empresa, tipo de documento,
número)` sobre los terceros, así que un NIT solo puede existir una vez.

Esto pesa sobre todo en **toma de pedidos**, donde cada sucursal pide por
separado, recibe en su propia dirección y muchas veces tiene su propia lista de
precios y su propio vendedor.

## Lo que NO se debe hacer

**Duplicar el tercero cambiando el documento** (`900123456`, `900123456-1`,
`900123456-2`). Es la salida que todo el mundo intenta primero y rompe cuatro
cosas a la vez:

- La **factura electrónica se emite al NIT**. Un documento inventado se le envía
  a la DIAN como identificación del adquirente y lo rechaza, o peor: lo acepta
  con un NIT que no existe.
- La **cartera queda partida**. El cliente debe un total y el sistema lo muestra
  en cinco pedazos que no suman en ninguna pantalla.
- El **cupo de crédito deja de servir**. Cinco terceros son cinco cupos que no
  se hablan: el cliente puede estar cinco veces sobre su límite real.
- El **estado de cuenta no cuadra** con lo que el cliente cree deber, que es la
  discusión que uno no quiere tener con un cliente grande.

**Quitar el índice único** es la otra tentación, y es peor: además de todo lo
anterior, abre la puerta a que el mismo cliente se cree dos veces por descuido y
nadie se entere.

## La solución: sucursales del tercero

Un solo tercero por NIT —el que la DIAN conoce— con **N sucursales colgando de
él**. La sucursal no es un cliente: es una dirección de entrega con datos
comerciales propios.

```
ThirdParty  (NIT 900123456 — SUPERMERCADOS XYZ SAS)
   ├── Sucursal  CEDI Norte      · Cra 45 #120-30  · lista MAYORISTA · cupo 20.000.000
   ├── Sucursal  Punto Chapinero · Cll 63 #11-25   · lista RETAIL    · sin cupo propio
   └── Sucursal  Punto Sur       · Cll 8 Sur #40-1 · lista RETAIL    · cupo  5.000.000
```

### Tabla nueva: `third_party_branches`

| Campo | Para qué |
|---|---|
| `company_id`, `third_party_id` | A quién pertenece |
| `code` | Código interno de la sucursal (el que usa el cliente en sus órdenes de compra) |
| `name` | «CEDI Norte», «Punto Chapinero» |
| `address`, `city`, `department`, `dian_municipality_id` | Dónde se entrega |
| `contact_person`, `contact_phone`, `phone`, `email` | A quién se le avisa |
| `delivery_horario` | Franja en que reciben — ya existe en el tercero y en toma de pedidos pesa |
| `default_price_list_id` | Lista propia. Vacío = hereda la del tercero |
| `default_seller_user_id` | Vendedor propio. Vacío = hereda |
| `credit_limit` | Sub-cupo. Vacío = comparte el del NIT |
| `active`, `notes` | |

Único por `(company_id, third_party_id, code)`.

### Qué cambia en cada parte

**Toma de pedidos.** Al elegir el cliente, si tiene sucursales aparece un segundo
selector con ellas. Elegida la sucursal, la lista de precios y el vendedor salen
de ella (o del tercero si no tiene propios), y la dirección de entrega del pedido
es la suya. Si el cliente no tiene sucursales, la pantalla se comporta
exactamente como hoy: **nadie que no las use ve nada nuevo**.

**Factura.** Lleva `third_party_branch_id` para poder agrupar después, pero **se
emite al NIT del tercero**, siempre. Eso no es negociable: es lo que la DIAN
valida. La sucursal viaja como dirección de entrega y como referencia visible en
la representación gráfica.

**Cartera.** Los reportes ganan un agrupador por sucursal. El total del NIT sigue
siendo el total del NIT —es quien responde por la deuda— y debajo se abre por
sucursal para saber de dónde viene.

**Cupo de crédito.** Aquí hay una regla que conviene fijar desde el principio:

> El cupo del NIT es el techo. El de la sucursal es un sub-límite dentro de ese
> techo, nunca por encima.

Es decir, se validan los dos: la venta no puede pasar el cupo de la sucursal ni
el del NIT. Al revés —sumar los cupos de las sucursales— le daría al cliente más
crédito del que se le aprobó, que es exactamente el riesgo que el cupo existe
para evitar.

## Lo que hay que decidir antes de construir

**1. ¿La sucursal va impresa en la factura?**
Puede ir como una línea de «Entregar en:» debajo de los datos del cliente, o solo
quedar en el pedido y la remisión. Fiscalmente da igual; es una decisión de
formato.

**2. ¿Qué pasa con los pagos?**
Si una sucursal paga, ¿el abono baja la deuda de esa sucursal o la del NIT? Lo
natural es que baje la de la factura que se está pagando —y esa factura ya tiene
sucursal—, así que se resuelve solo. Pero conviene confirmarlo.

**3. ¿Las sucursales entran por importación?**
Hoy no. Si el cliente va a cargar cincuenta sucursales, hace falta una columna en
la plantilla de terceros o una importación aparte.

## Por dónde empezar

El orden importa, porque cada paso deja el terreno listo para el siguiente y
ninguno rompe lo que ya funciona:

1. **Tabla, modelo y pantalla de sucursales** dentro del tercero. Con esto ya se
   pueden registrar, aunque todavía no se usen en ningún documento.
2. **Toma de pedidos**: selector de sucursal, herencia de lista de precios y
   vendedor, dirección de entrega.
3. **Factura**: guardar la sucursal al convertir el pedido, y mostrarla.
4. **Cartera y cupo**: agrupación en los reportes y validación del sub-límite.

Los pasos 1 y 2 resuelven el caso que trae el cliente. Los 3 y 4 son los que
evitan que dentro de tres meses haya que preguntarle a alguien de dónde salió una
deuda.
