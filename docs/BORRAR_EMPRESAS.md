# Borrar una empresa completa

Acción del SuperAdmin: **Compañías → (fila) → Resetear datos → ELIMINAR la
empresa completa**.

Borra la empresa y todo lo suyo —usuarios, contabilidad, facturas,
productos, inventario, configuración— sin papelera y sin vuelta atrás.

> No confundir con **«Resetear datos»**, que está en el mismo menú. Ese
> limpia los movimientos y deja la empresa viva y configurada, para el
> cliente que quiere empezar de cero. Esto es para la empresa que no
> debería existir: el registro de prueba que nadie terminó de montar, la
> demo vieja, el alta duplicada.

---

## Por qué no bastaba con borrar la fila

Casi todas las llaves foráneas hacia `companies` son `CASCADE`, así que lo
obvio es `DELETE FROM companies` y que la base haga el resto. Con una
empresa vacía funciona. Con una que tenga contabilidad, no:

```
ERROR: update or delete on table "accounts" violates foreign key constraint
       "journal_entry_lines_account_id_foreign" on table "journal_entry_lines"
```

Entre las tablas de la empresa hay `RESTRICT` —los renglones de un asiento
apuntan a la cuenta contable— y Postgres resuelve la cascada en el orden que
quiere. Si le toca `accounts` antes que `journal_entries`, aborta. Depender
de ese orden es depender de suerte.

## Por qué no hay una lista de tablas

La solución obvia es escribir las tablas en el orden correcto. **Ya existe
una así en este proyecto y cuenta la historia sola:** `CompanyDataReset`
enumera 46 tablas de las 112 que tienen `company_id`, y no conoce ninguna de
parqueadero, hotel ni billares. Nadie se acordó de volver a tocarla al
publicar un módulo nuevo.

Por eso `CompanyEraser` **le pregunta al esquema** en cada corrida qué
tablas tienen `company_id`. Cualquier módulo que se publique mañana queda
cubierto sin que nadie tenga que acordarse.

El orden tampoco se calcula: se borra **a tanteo, en pasadas**. En cada
pasada se intenta vaciar cada tabla que queda; la que falla por una llave
foránea se deja para la siguiente, cuando ya se habrá ido lo que la
sujetaba. Es O(n²) en sentencias y da igual: se corre un puñado de veces al
año y cada sentencia es trivial. Medido contra una empresa real de 38.189
filas: **1,4 segundos**.

Si una pasada completa no logra borrar nada y todavía quedan tablas, se
aborta con los nombres a la vista: algo de *fuera* de la empresa apunta
hacia adentro y lo tiene que mirar una persona. Como todo va en una sola
transacción, ahí no se perdió nada.

## Detalles que no son obvios

- **Los usuarios se borran de últimos**, después de vaciar las tablas. Su
  llave foránea hacia `companies` es `SET NULL`, así que si no se borran a
  mano quedan vivos con la empresa en blanco —usuarios fantasma que todavía
  pueden intentar entrar—. Y no se pueden borrar *antes*, porque
  `cash_register_sessions.cashier_user_id` los sujeta con `RESTRICT`.
  Lo encontró una prueba, no el razonamiento.

- **El pivote `model_has_roles` se limpia a mano.** Spatie lo resuelve por
  morph y no tiene llave foránea hacia `users`, así que borrar el usuario
  dejaría filas apuntando a un id que ya no existe.

- **El logo se borra fuera de la transacción.** Un archivo no se deshace con
  un rollback, así que se quita cuando la base ya confirmó. Si falla, queda
  un archivo huérfano y no pasa nada.

- **Queda registro en el log antes de borrar**, con quién lo hizo y qué
  había dentro. El registro de auditoría de la empresa vive *dentro* de la
  empresa y se va con ella, así que si no se anota ahí no queda rastro en
  ninguna parte.

## Salvaguardas

1. Solo super-admin.
2. Hay que escribir el **NIT exacto**. Un «¿está seguro?» se contesta que sí
   sin leerlo; un NIT hay que ir a buscarlo.
3. **La modal dice qué hay adentro**: cuántas facturas, cuántos asientos,
   cuántos usuarios. Es lo que de verdad frena la mano cuando uno se
   equivocó de fila, porque una empresa de prueba se ve vacía y un cliente
   real no.
4. No se puede borrar la empresa a la que uno pertenece.

**No hay acción masiva, a propósito.** Borrar ocho empresas son ocho
confirmaciones, y eso es una característica: cada una obliga a mirar lo que
se está destruyendo.

## Pruebas

`tests/Feature/EliminarEmpresaTest.php` — 9 casos. El escenario monta un
salón de billar y le **cobra una mesa de verdad** antes de borrarlo, así que
hay asientos, renglones de asiento apuntando a cuentas, factura, pago e
inventario: exactamente el caso en que la cascada plana falla.

Lo que se vigila, además de que borre: que no deje usuarios fantasma, que no
deje huérfanos colgando, que **no toque a las demás empresas**, que el NIT
equivocado no borre nada, y que la lista de tablas siga saliendo del esquema
y no de una lista escrita a mano.

---

## Pendiente conocido (no es de esta función)

`CompanyDataReset` —el «Resetear datos» del mismo menú— **no conoce los
módulos verticales**. Su lista enumera 46 tablas de 112 y no incluye
ninguna de `parking_*`, `hotel_*` ni `billiard_*`. Eso significa que hoy
resetear una empresa de parqueadero o de hotel le deja adentro las sesiones,
las estadías y los folios.

No se arregló aquí porque no es el mismo problema: ese servicio distingue
datos «transaccionales» de «configuración», y esa distinción es un criterio
humano que no se puede sacar del esquema. Hay que decidir módulo por módulo
qué se borra y qué se conserva.
