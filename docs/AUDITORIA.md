# Auditoría

Bitácora de quién hizo qué, cuándo y desde dónde, dentro de cada empresa.

## Dónde se ve

**Gestión de usuarios → Auditoría**, en el panel de la empresa.

Solo la ve quien tenga el permiso `audit.view`, que por defecto es únicamente el
rol **admin**. No se le dio al `manager` a propósito: la bitácora muestra los
movimientos de todos los usuarios, incluido el de quien la consulta.

Para dárselo a otro rol: **Gestión de usuarios → Roles → (rol) → Administración
→ Ver la bitácora de auditoría**.

## Qué registra

| Acción | Cuándo queda |
|---|---|
| Creó | Se crea un registro de los tipos auditados |
| Modificó | Cambia al menos un campo (con el valor anterior y el nuevo) |
| Eliminó | Borrado lógico o definitivo |
| Restauró | Se recupera algo borrado |
| Inició sesión | Entrada exitosa a la plataforma |
| Cerró sesión | Salida |
| Intento de acceso fallido | Credenciales incorrectas, con el correo tecleado |

De cada acción se guarda: fecha y hora, usuario (nombre y correo copiados en el
momento), dirección IP, navegador y la pantalla desde la que se hizo.

## Qué se audita

Los objetos por los que alguien pregunta: documentos de venta y compra, pagos,
anticipos, turnos de caja, asientos contables, productos, categorías, terceros,
impuestos, medios de pago, cuentas contables, promociones, bonos, ajustes y
traslados de inventario, empleados, períodos de nómina, liquidaciones, usuarios,
roles, sedes y períodos fiscales.

La lista completa está en `app/Services/Audit/AuditRegistry.php`. Para agregar un
modelo basta ponerlo ahí con su nombre en español; el observador se engancha
solo.

### Qué NO se audita, y por qué

- **Las líneas de los documentos.** Una factura de veinte ítems generaría
  veintiún registros. Las líneas se ven en el documento.
- **Los movimientos de inventario.** El kardex ya es su propio registro
  histórico y no se puede alterar.
- **Las contraseñas y las claves de la DIAN.** Nunca se copian, ni siquiera el
  hash. La lista está en `AuditRegistry::CAMPOS_OCULTOS`.
- **Los guardados que no cambian nada.** Un `touch` o un guardado idéntico no es
  una acción del usuario.

## Reglas que cumple

**No se puede modificar ni borrar.** El modelo `AuditLog` lanza un error ante
cualquier intento de `update` o `delete`. Una bitácora que el propio sistema
puede reescribir no prueba nada.

**No cruza empresas.** Usa el mismo aislamiento que el resto del sistema
(`CompanyScope`). El administrador de una empresa no ve ni una línea de otra.

**Lo revertido no queda.** Las entradas se guardan cuando la transacción hace
commit. Si una operación falla a medias, no aparece como si hubiera ocurrido.

**Nunca tumba una operación.** Si escribir la bitácora falla, se anota en el log
de la aplicación y la venta sigue. Un cajero no se queda sin facturar porque la
auditoría tuvo un problema. (En el banco de pruebas sí revienta: tragarse el
error ahí convertiría una bitácora rota en una suite verde.)

## Mantenimiento

La bitácora crece. Para depurar lo antiguo:

```bash
# Deja los últimos 365 días (valor por defecto)
docker exec emprenddi_app php artisan audit:purge

# Otro plazo
docker exec emprenddi_app php artisan audit:purge --days=730

# Sin preguntar, para un cron
docker exec emprenddi_app php artisan audit:purge --days=400 --force
```

No acepta menos de 90 días: por debajo de eso la bitácora deja de servir para
revisar un cierre contable o un reclamo del mes pasado.

Es el único camino para borrar entradas. Hacerlo desde la consola deja además el
rastro en el historial del servidor.

## Limitaciones conocidas

- **El reinicio de datos de una empresa** (`CompanyDataReset`) no borra la
  bitácora ni se registra en ella: usa consultas directas, que no pasan por los
  eventos del modelo. Las entradas anteriores quedan apuntando a documentos que
  ya no existen.
- **Las acciones de los seeders y las migraciones** quedan registradas con
  «Sistema» como usuario, porque no hay nadie autenticado. Para evitarlo en una
  migración de datos larga se puede pausar:
  `app(AuditRecorder::class)->pausar()`.
- **No hay exportación a Excel/PDF** todavía. La tabla se filtra por usuario,
  acción, tipo de registro y rango de fechas, pero para llevárselo hay que
  copiarlo de pantalla.
