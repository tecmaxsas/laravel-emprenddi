# Nómina electrónica — cómo funciona y cómo se envía a la DIAN

Documento de referencia del módulo de nómina electrónica de Emprenddi.
Describe lo que hay implementado hoy, incluidas sus limitaciones.

---

## 1. Qué es y en qué se diferencia de la factura

La nómina electrónica es un documento que el **empleador se emite a sí mismo**
para soportar ante la DIAN el pago de su nómina. No hay cliente, no hay IVA y
no hay factura: hay un desprendible por empleado y por período.

Tres diferencias con la facturación que cambian cómo se opera:

| | Factura electrónica | Nómina electrónica |
|---|---|---|
| Identificador DIAN | CUFE | **CUNE** |
| Numeración | Resolución que otorga la DIAN | **La define el empleador**, sin resolución |
| Habilitación | Su propio set de pruebas | **Otro set, aparte**, con su propio software y TestSetId |

La consecuencia práctica de la tercera fila: una empresa puede estar
**facturando en producción y todavía en pruebas para nómina**. Son dos trámites
independientes y el sistema los trata por separado.

---

## 2. Puesta en marcha (una sola vez por empresa)

Todo esto vive en **Configuración → Facturación Electrónica DIAN → pestaña
"Nómina electrónica"**.

### Paso 1 — Registrar el software de nómina

En el portal de la DIAN, la empresa solicita la habilitación de nómina y recibe
un **ID de software** y un **PIN**. Son distintos a los de facturación.

Se copian aquí y se guardan. Sin este paso la DIAN rechaza todos los documentos.

### Paso 2 — Registrar los dos rangos de numeración

La nómina electrónica **no lleva resolución de la DIAN**: el prefijo y el rango
los define el empleador. Hay que registrar dos:

- **Nómina** — prefijo sugerido `NI`, desde 1 hasta 99999999
- **Nota de ajuste** — prefijo sugerido `NA`, mismo rango

Los dos prefijos quedan guardados en la empresa y son los que usa el sistema
para numerar cada envío.

> Si el prefijo no está registrado, apidian no encuentra la resolución y
> responde `Server Error` sin más detalle.

### Paso 3 — Set de pruebas

El portal de la DIAN entrega un **TestSetId**. Se pega aquí y se guarda.

Luego, el botón **Iniciar habilitación** envía el set completo:
**10 nóminas y 10 notas de ajuste**, de las que la DIAN tiene que **aceptar 4 y 4**.

Cómo se comporta:

- Manda **5 documentos por pasada**. Veinte llamadas seguidas dentro de una
  petición web se pasan del tiempo límite.
- **Se puede volver a darle las veces que haga falta.** Cada pasada envía solo
  lo que falta, reintenta lo que falló y respeta lo que ya pasó.
- Primero las nóminas, después las notas. Una nota de ajuste no puede salir
  hasta que su nómina le conste recibida a la DIAN — si sale antes, responde
  `NIAE191a: Documento a Reemplazar no encuentra recibido en la Base de Datos`.
- El panel muestra el avance y la bitácora de la última tanda, con el motivo
  exacto de cada fallo.

**Enviado no es aceptado.** La DIAN valida de forma asíncrona: la respuesta
inmediata es solo un acuse de recibo con un ZipKey. El veredicto se consulta en
el portal de la DIAN, en *Gráfico set de Pruebas Nómina Electrónica*.

### Paso 4 — Pasar a producción

Cuando la DIAN apruebe el set, se cambia el **Ambiente de nómina** a
*Producción*.

Este ambiente va aparte del de facturación a propósito. La DIAN valida ese dato
en cada documento (regla **NIE023**) y mientras dura la habilitación tiene que
decir *Pruebas*, aunque la empresa ya facture en producción.

---

## 3. Operación mes a mes

### 3.1 Antes de liquidar

Que estén al día:

- **Parámetros de nómina** — salario mínimo, auxilio de transporte, porcentajes
  de seguridad social y parafiscales del año en curso.
- **Empleados** con su contrato vigente: tipo de contrato, salario, frecuencia
  de pago y método de pago.
- **Cuentas de nómina**, si se va a contabilizar.

### 3.2 Liquidar el período

**Liquidación de Nómina → Períodos → Nuevo período**, con su fecha de inicio y
de fin.

Dentro del período, el botón **Liquidar nómina** genera **un desprendible por
cada empleado activo con contrato vigente**. Si el período ya estaba liquidado,
los reemplaza.

Cada desprendible queda con devengados, deducciones, costo del empleador y
provisiones (cesantías, intereses, prima, vacaciones).

### 3.3 Contabilizar (opcional)

**Contabilizar** genera el asiento contable del período con base en el mapeo de
cuentas de nómina. Después de contabilizar **ya no se puede re-liquidar**.

Es independiente del envío a la DIAN: se puede enviar sin contabilizar y al
revés.

### 3.4 Enviar a la DIAN

En la tabla de desprendibles del período, cada fila tiene **Enviar a DIAN**.

Qué pasa al darle:

1. Se comprueba que el **período ya haya cerrado**. Un documento emitido antes
   de que termine el período que liquida certifica un pago que todavía no
   ocurrió, y la DIAN lo rechaza.
2. Se **reserva el prefijo y el consecutivo**. Se hace aquí y no al liquidar:
   un consecutivo quemado por un desprendible que nunca se envió deja un hueco
   en la numeración, y la DIAN los pregunta.
3. Se arma el documento y se envía.
4. Se guarda el resultado en el desprendible.

Si el envío falla por red, **el reintento conserva el mismo número**. No se
gastan consecutivos en intentos fallidos.

---

## 4. Estados del desprendible

La columna **DIAN** de la tabla:

| Estado | Qué significa |
|---|---|
| **Pendiente** | No se ha enviado |
| **Enviado** | Llegó a la DIAN, que todavía está validando |
| **Aceptado** | Tiene CUNE y URL de consulta en el catálogo de la DIAN |
| **Rechazado** | La DIAN lo rechazó; la columna muestra el motivo |

Un desprendible **aceptado no se puede volver a enviar**. Para corregirlo hay
que emitir una nota de ajuste.

El CUNE aceptado queda visible bajo el estado, y el desprendible guarda la URL
de consulta pública:
`https://catalogo-vpfe.dian.gov.co/document/searchqr?documentkey={CUNE}`

---

## 5. Validaciones que frenan el envío antes de llegar a la DIAN

El sistema no manda un documento que sabe que va a ser rechazado. Estos casos
salen con un mensaje en pantalla y **no gastan consecutivo**:

- **El período todavía no ha cerrado.** Dice qué día cierra.
- **Deducciones que no se pueden desglosar.** La DIAN valida que el total de
  deducciones sea igual a la suma de los conceptos detallados. Hoy el documento
  sabe desglosar **salud y pensión**. Si el desprendible trae fondo de
  solidaridad, retención en la fuente, libranzas u otros descuentos, el envío se
  frena y el mensaje nombra los conceptos que sobran.
- **Falta el prefijo de nómina** o el registro DIAN de la empresa está
  incompleto.

---

## 6. Limitaciones actuales

Lo que **no** está implementado, para que no sorprenda:

### 6.1 El envío es uno por uno

No hay envío masivo ni selección múltiple. Una empresa de 50 empleados son 50
clics cada mes.

### 6.2 Deducciones extendidas

Solo se desglosan salud y pensión (incluida la de alto riesgo, que usa su propio
concepto). Un desprendible con FSP o retefuente **no se puede enviar** hasta que
se implemente el payload extendido.

Esto afecta directamente a salarios altos, donde el fondo de solidaridad es
obligatorio.

### 6.3 El formulario de empleado no tiene los campos DIAN

Las columnas existen en la base de datos, pero **no hay dónde editarlas** desde
la interfaz:

| Campo | Valor que se usa hoy |
|---|---|
| Municipio del trabajador | El de la empresa |
| Tipo de trabajador | Dependiente |
| Subtipo de trabajador | No aplica |
| Pensión de alto riesgo | No |

Para la mayoría de empleados los valores por defecto son correctos. Pero un
trabajador de **alto riesgo**, un **aprendiz del SENA** o alguien que vive en
otro municipio se reportarían mal y no hay forma de corregirlo desde la
aplicación.

### 6.4 Notas de ajuste solo en la habilitación

Las notas de ajuste están implementadas **únicamente para pasar el set de
pruebas**. No existe el flujo para corregir una nómina real ya aceptada: ni la
pantalla, ni la numeración en producción, ni la nota de eliminación.

Hoy, si una nómina aceptada quedó mal, no hay cómo corregirla desde el sistema.

---

## 7. Dónde mirar cuando algo falla

**En Emprenddi** — la columna DIAN del desprendible muestra el motivo del
rechazo. En la pestaña de configuración, cada paso deja visible la respuesta
cruda de apidian y el JSON que se envió.

**En apidian** (`apidian.emprenddi.com/company/{NIT}/payrolls`) — *Nóminas
emitidas* lista los documentos con su XML, PDF y estado.

**En la DIAN** — el catálogo público por CUNE muestra si el documento está
validado. Durante la habilitación, *Gráfico set de Pruebas Nómina Electrónica*
muestra recibidos, aceptados y rechazados.

### Errores que ya nos costaron tiempo

| Código | Qué es en realidad |
|---|---|
| `NIE023` | El **ambiente** del documento. En habilitación tiene que ser Pruebas. apidian lo toma de la configuración de la empresa, no del JSON, así que no se ve revisando el payload. |
| `NIAE191a` | La nómina que la nota reemplaza todavía no le consta recibida a la DIAN. Se espera y se reintenta. |
| `Server Error` (HTTP 500) | Casi siempre un campo en `null` que apidian necesita, o un prefijo sin registrar. El 500 no dice cuál. |
| Rechazo sin motivo | El documento salió al set de pruebas: la respuesta es un acuse asíncrono y el veredicto está en el portal de la DIAN, no en la respuesta. |

---

## 8. Resumen del flujo

```
UNA VEZ POR EMPRESA
  Software de nómina  →  Rangos NI y NA  →  Set de pruebas  →  Ambiente: Producción
                                              (10 + 10, aceptar 4 + 4)

CADA MES
  Parámetros al día
        ↓
  Crear período  →  Liquidar  →  [Contabilizar]
        ↓
  Enviar a DIAN (desprendible por desprendible)
        ↓
  Pendiente → Enviado → Aceptado (CUNE)
                     └→ Rechazado (motivo en la columna DIAN)
```
