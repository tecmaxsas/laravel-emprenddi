# Borrar facturas de venta

## Qué se puede borrar y qué no

**Solo facturas POS.** Una factura electrónica no se borra nunca, ni siquiera
desde aquí. Esa factura ya existe fuera de Emprenddi: la DIAN la recibió, le dio
un CUFE y la tiene en sus servidores. Borrarla de la base de datos no la borra de
la DIAN — solo hace que los dos sistemas dejen de coincidir, y el que queda mal
parado en una revisión es el contribuyente. Para deshacer una factura electrónica
está la **nota crédito**, que es el mecanismo que la DIAN reconoce.

Además de eso, el sistema se niega a borrar en cuatro casos:

| No se borra si… | Por qué | Qué hacer en su lugar |
|---|---|---|
| Es una factura electrónica | Ya existe ante la DIAN | Nota crédito |
| Se envió o fue aceptada por la DIAN | Igual, aunque sea POS | Nota crédito |
| Tiene notas crédito o débito asociadas | Quedarían apuntando a una factura que ya no existe | Anular primero las notas |
| Pertenece a un **turno de caja ya cerrado** | El cuadre se firmó con esa venta adentro | Devolución o nota crédito |

El caso del turno cerrado es el menos obvio y vale la pena explicarlo. Cuando un
cajero cierra su turno, el sistema guarda cuánto debía haber en la caja. Si
después se le quita una venta a ese turno, el cierre guardado queda diciendo una
cifra que ya no corresponde a ninguna factura, y nadie lo va a notar hasta que
alguien audite ese día. Con el turno **abierto** no hay problema: los totales se
recalculan solos a partir de las facturas vivas.

## Quién puede borrar

Solo el **administrador**. Es el permiso `sales.delete`, y no lo tiene ningún otro
rol —ni el gerente—. Si alguien más debe tenerlo, se le asigna desde
*Configuración → Roles*.

## Qué se devuelve al borrar

Todo. Esa es la idea: el sistema tiene que quedar exactamente como estaba antes de
la venta.

1. **Los pagos.** Cada pago se elimina y su asiento contable se reversa con un
   asiento espejo (lo que era débito pasa a crédito y al revés). La factura vuelve
   a quedar en cero pagado.
2. **Los anticipos.** Si la venta se pagó con un anticipo del cliente, ese dinero
   vuelve a quedar disponible: sigue siendo del cliente.
3. **Los bonos.** Lo que la venta redimió vuelve al saldo del bono. Lo que la
   venta emitió se anula — ese bono se vendió en una factura que ya no existe.
4. **Las promociones.** Los usos se borran, no se marcan, para que un cupón de
   «una vez por cliente» se pueda volver a usar.
5. **El inventario.** Los productos vuelven al stock de la sede, con su movimiento
   en el kardex.
6. **Los seriales.** Vuelven a quedar disponibles para vender.
7. **El asiento de la venta y el del costo.** Ambos se reversan.
8. **Las comisiones pendientes** del vendedor.

Todo esto ocurre dentro de una sola transacción: o se devuelve todo, o no se
borra nada. No existe el estado intermedio de «factura borrada con el inventario
todavía descontado».

## La factura no desaparece del todo

Se marca como eliminada y deja de salir en las listas, los reportes y los
informes, pero la fila se queda en la base de datos. Es deliberado:

- Una resolución POS también tiene un rango de numeración autorizado. Un
  consecutivo que desaparece sin dejar rastro es un hueco que después nadie sabe
  explicar.
- La auditoría necesita poder responder **quién** borró **qué** y **cuándo**.

Por eso la pantalla pide un **motivo obligatorio**. El motivo, la fecha, la hora y
el nombre del usuario quedan escritos en las notas de la factura borrada, y la
acción queda además en la bitácora de auditoría.

## Cómo se hace

1. Abrir la factura (*Ventas → Facturas de venta → ver*).
2. Botón **Borrar factura**, arriba a la derecha.
3. Si la factura no se puede borrar, el botón aparece gris y al pasarle el mouse
   por encima dice exactamente por qué — no hay que adivinarlo a punta de
   intentos.
4. Escribir el motivo y confirmar.

Después de borrar, el sistema devuelve a la lista de facturas.

## Nota para quien mantenga el código

La lógica está en `app/Services/Sales/SaleInvoiceDeleter.php`. Dos cosas que
conviene saber antes de tocarlo:

- **El orden de los pasos importa.** Los pagos se devuelven primero porque
  `SaleInvoiceEngine::cancel()` se niega a anular una factura que tenga pagos
  registrados. Si se cambia el orden, deja de funcionar justo en el caso más
  común, que es la venta POS pagada de contado.
- **El inventario, los seriales y los asientos los devuelve `cancel()`**, no este
  servicio. Duplicar esa lógica aquí significaría tener dos reversas que hay que
  mantener iguales para siempre, y algún día una de las dos se quedaría atrás.

Las pruebas están en `tests/Feature/SaleInvoiceDeleteTest.php` y se corren contra
la base de desarrollo:

```bash
docker compose exec -T \
  -e DB_CONNECTION=pgsql -e DB_HOST=postgres -e DB_PORT=5432 \
  -e DB_DATABASE=emprenddi -e DB_USERNAME=emprenddi -e DB_PASSWORD=secret \
  app php artisan test --filter=SaleInvoiceDeleteTest
```

Un detalle que ya hizo pasar una prueba que no probaba nada: en los modelos con
borrado suave, `withoutGlobalScopes()` **también** quita el filtro de borrados, así
que devuelve las filas eliminadas. Para comprobar que algo desapareció hay que
quitar solo el de empresa: `withoutGlobalScope(CompanyScope::class)`.
