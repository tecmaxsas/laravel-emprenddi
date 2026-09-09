# Descuentos globales (pie de factura)

Un descuento a toda la factura, además de los de cada línea.

## Dónde se habilita

**Configuraciones → Empresa → Descuentos globales en facturas**, con dos
interruptores independientes:

- Permitir en **facturas de venta**
- Permitir en **facturas de compra**

Vienen **apagados**. Un descuento a toda la factura es una decisión comercial
que no todos deben poder tomar; quien lo necesita lo enciende.

El **POS ya lo tenía** desde antes y se configura en su propia pestaña, con
«Permitir descuentos»: ahí el mismo interruptor gobierna el descuento por línea,
el global, y el umbral que exige autorización de un supervisor por PIN.

## Se aplica sobre la base, no sobre el total

Esta es la regla que importa. Primero baja la base gravable y **después** se
calcula el IVA.

Con una factura de $1.000.000, IVA del 19 % y un 10 % de descuento:

| | Sobre la base (correcto) | Sobre el total (incorrecto) |
|---|---|---|
| Subtotal | $1.000.000 | $1.000.000 |
| Descuento | −$100.000 | — |
| Base gravable | $900.000 | $1.000.000 |
| IVA 19 % | $171.000 | $190.000 |
| Descuento | — | −$119.000 |
| **Total** | **$1.071.000** | **$1.071.000** |

El total coincide, y por eso el error pasa desapercibido. Lo que no coincide es
el **IVA declarado**: $19.000 de más en cada factura, que la empresa le paga a la
DIAN sin habérselos cobrado a nadie. Es lo que exige la DIAN para un descuento
no condicionado y hay una prueba dedicada a ello.

## Cómo se reparte

El descuento se prorratea entre las líneas en proporción a lo que pesa cada una,
y se suma al descuento de cada línea. Así, una factura con una línea gravada al
19 % y otra excluida paga el IVA correcto: cada línea aplica su propia tarifa
sobre lo que le quedó.

El redondeo del reparto deja centavos sueltos; se le cargan a la línea más grande
para que lo repartido sume exactamente lo pactado.

### Por qué se suma al descuento de la línea

Podría haberse guardado aparte y restarse después, pero **doce lugares del
sistema calculan la base gravable como `subtotal - discount_amount`**: el asiento
contable, los tres constructores de payload de la DIAN, el costeo de compras y
las comisiones. Metiéndolo ahí, los doce quedan correctos sin tocar ninguno —que
es exactamente lo que uno quiere cuando una base mal calculada significa una
factura rechazada por la DIAN.

El precio de esa decisión es que en una línea con descuento global,
`discount_amount` deja de ser `subtotal × discount_percentage`. La columna
`global_discount_amount` de la línea guarda cuánto de ese descuento vino del
global, y es lo que permite recalcular sin que el descuento se aplique dos veces.

## Qué se guarda

En la factura: el tipo (`percent` / `amount`), el valor que escribió el usuario y
el monto en pesos que efectivamente se descontó. Se guardan los tres porque el
valor pactado —«10 %»— es información distinta del resultado, y al reeditar la
factura hay que poder ver lo que se acordó.

## Instalación

```bash
cd /opt/emprenddi
git pull origin main
docker exec emprenddi_app php artisan migrate --force
docker exec emprenddi_app php artisan optimize:clear
```

Las migraciones solo agregan columnas con valor por defecto cero: **ninguna
factura existente cambia**. Mientras el descuento global sea cero, el cálculo es
byte por byte el de antes.

## El del POS

Existía desde antes y tenía cuatro fallos, corregidos en septiembre de 2026:

1. **Vaciar el campo reventaba la pantalla** con un `TypeError` en plena venta:
   el navegador manda la cadena vacía y el método exigía un `float`.
2. **No se limpiaba entre ventas.** `resetCart()` no lo tocaba, así que el
   siguiente cliente heredaba el descuento del anterior sin que el cajero lo
   viera.
3. **Un monto fijo se desajustaba.** Se convertía a porcentaje una sola vez, así
   que un descuento de $50.000 crecía en cuanto entraba otro producto al
   carrito.
4. **Sumaba los porcentajes.** Un 10 % de línea más un 10 % global daba un 20 %
   de descuento, cuando lo correcto es 19 % —el global va sobre lo que quedó—.
   El POS y la pantalla de facturas daban resultados distintos con los mismos
   datos.
5. **Un porcentaje imposible dejaba la venta en cero, sin avisar.** Con el
   selector en «%» y un valor pensado en pesos —10000— el sistema lo recortaba
   a 100 % y regalaba la mercancía. Recortar parecía lo prudente y era justo lo
   que hacía daño: ahora se rechaza y se explica que probablemente quería el
   modo «$». El 100 % sí se acepta, porque a veces se regala de verdad.
6. **El selector «%» / «$» no recalculaba.** Se escribía el valor, se cambiaba
   de modo y no pasaba nada.

Ahora el POS guarda el valor tal como se escribió y lo reparte en cada
recálculo, con el mismo criterio del motor de facturas.

## Limitaciones conocidas

- **En el POS el código no es compartido.** El criterio sí: desde la corrección
  de septiembre, el POS reparte el descuento igual que las facturas —proporcional
  y sobre la base ya neta de los descuentos de línea— pero con su propia
  implementación, porque el carrito vive en memoria y las facturas en la base.
- **No hay umbral de autorización fuera del POS.** En el POS, un descuento por
  encima del umbral pide el PIN de un supervisor; en las facturas capturadas a
  mano no. Si se necesita, es el siguiente paso natural.
- **Las plantillas de impresión muestran el descuento sumado**, no desglosado
  entre «de línea» y «global». El número es correcto; el desglose no está.
