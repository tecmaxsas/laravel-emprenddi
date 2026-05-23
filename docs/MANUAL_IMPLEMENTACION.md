# Manual de Implementación — Emprenddi

> Documento interno del equipo Emprenddi. Última revisión: 2026-05-23.
> Audiencia: asesores comerciales, implementadores, soporte, contadores externos del portal.
> Objetivo: dejar a un cliente nuevo **operando correctamente y con contabilidad limpia desde el día 1**.

---

## Índice

1. [Visión general del sistema](#1-visión-general-del-sistema)
2. [Arquitectura y los tres paneles](#2-arquitectura-y-los-tres-paneles)
3. [Registro y onboarding automático](#3-registro-y-onboarding-automático)
4. [Parametrización inicial paso a paso](#4-parametrización-inicial-paso-a-paso)
   - 4.1 Datos generales de la empresa
   - 4.2 Identidad visual (logo)
   - 4.3 Plan Único de Cuentas (PUC)
   - 4.4 Impuestos
   - 4.5 Cuentas bancarias y métodos de pago
   - 4.6 Sedes (Locations)
   - 4.7 Categorías y productos
   - 4.8 Terceros (clientes / proveedores / empleados)
   - 4.9 Resoluciones DIAN
   - 4.10 Configuración POS
   - 4.11 Configuración Restaurante (si aplica)
   - 4.12 Seriales y Garantías (si aplica)
   - 4.13 Plantillas de impresión
   - 4.14 Impresoras (QZ Tray)
   - 4.15 Usuarios, roles y permisos
   - 4.16 Inventario inicial (apertura)
   - 4.17 Saldos contables iniciales
   - 4.18 Periodos fiscales
   - 4.19 Centros de costo
   - 4.20 Activos fijos
   - 4.21 Nómina (catálogo y parámetros)
5. [Operación diaria recomendada](#5-operación-diaria-recomendada)
6. [Módulos por área (referencia detallada)](#6-módulos-por-área)
7. [Información exógena DIAN](#7-información-exógena-dian)
8. [Backups, mantenimiento y monitoreo](#8-backups-mantenimiento-y-monitoreo)
9. [Errores comunes y solución](#9-errores-comunes-y-solución)
10. [Checklist de implementación rápida](#10-checklist-de-implementación-rápida)
11. [Glosario](#11-glosario)

---

## 1. Visión general del sistema

**Emprenddi** es un SaaS contable + punto de venta + facturación electrónica DIAN para PyMEs colombianas. Cubre los siguientes flujos:

| Área | Cubre |
|---|---|
| **Contabilidad** | PUC colombiano, asientos manuales y automáticos, libros oficiales, balance, kardex |
| **Ventas** | Factura tradicional, POS retail, POS restaurante, notas crédito/débito, cotizaciones, remisiones, DIAN electrónica vía apidian |
| **Compras** | Factura de compra, documento soporte (no responsables IVA), devoluciones |
| **Inventario** | Promedio ponderado por sede, ajustes, transferencias, apertura, **gestión de seriales** |
| **Restaurante** | POS de mesas, KDS de cocina, comandas por categoría, modificadores, domicilios, reservaciones, carta pública QR |
| **Empleados/Nómina** | Liquidación periódica, liquidación definitiva con indemnización, parámetros (SMLMV, UVT) |
| **Garantías** | Tickets RMA con tracking de estados, asignación de técnico, comprobante imprimible |
| **DIAN** | Facturación electrónica, resoluciones POS locales, información exógena, retenciones |
| **Administración** | Multi-sede, multimoneda, multi-usuario, roles granulares, plantillas de impresión, impresión local QZ Tray |

**Modelo comercial**: 4 planes (`free-trial` 30 días → `starter` → `pro` → `enterprise`). Multitenant single-DB: todos los clientes comparten la misma base con aislamiento por `company_id`.

---

## 2. Arquitectura y los tres paneles

El sistema expone **3 paneles Filament** independientes con permisos separados:

### 2.1 Panel `App` — `pos.emprenddi.com/app`

El panel principal del cliente. Aquí trabajan dueño, administrativos, cajeros, vendedores, contador interno. Tiene todas las funcionalidades operativas (POS, facturación, compras, inventario, reportes, etc.).

### 2.2 Panel `SuperAdmin` — `pos.emprenddi.com/super-admin`

Solo para el equipo Emprenddi. Gestiona compañías clientes, planes, suscripciones, provisión manual de catálogos (PUC e impuestos), y operaciones destructivas (reset de datos transaccionales).

### 2.3 Panel `Contador` — `pos.emprenddi.com/contador`

Para contadores externos que llevan **varias empresas a la vez**. Se vinculan a las empresas que les dan acceso y desde un selector cambian de contexto. Acceso de lectura amplia + edición contable (postear, anular, ajustes). No pueden tocar configuración de empresa ni crear ventas.

---

## 3. Registro y onboarding automático

### 3.1 Cómo se registra un cliente nuevo

El cliente entra a `https://pos.emprenddi.com/app/register` y completa el wizard:

1. **Datos de la empresa**: nombre comercial, razón social, NIT (con DV calculado automáticamente), régimen (común / no responsable / gran contribuyente / simplificado), tipo de organización (jurídica / natural), método contable (NIIF Pymes / NIIF Full), método de inventario (promedio ponderado / FIFO / LIFO), moneda, dirección, ciudad, departamento, teléfono.
2. **Datos del administrador**: nombre, apellidos, email, contraseña, aceptación de términos, opt-in marketing.
3. **Crear cuenta**.

### 3.2 Qué se crea automáticamente

En **una sola transacción** el sistema ejecuta `CompanyOnboarding::bootstrap()` que crea:

| Catálogo | Cantidad | Provisioner |
|---|---|---|
| Compañía | 1 | inline |
| Usuario admin con rol `admin` | 1 | inline |
| Suscripción (free-trial 30 días) | 1 | inline |
| **PUC colombiano** | ~120 cuentas | `PucProvisioner` |
| **Impuestos**: IVA 19/5/0/EXC, INC 8%, retefuente, reteIVA, reteICA | ~13 | `TaxesProvisioner` |
| **Monedas**: COP base, USD, EUR | 3 | `CurrencyProvisioner` |
| **Métodos de pago**: efectivo, débito, crédito, transferencia, PSE, cheque, NC, otro | 8 | `PaymentMethodProvisioner` |
| **Sede Principal** (con dirección de la empresa) | 1 | `DefaultLocationProvisioner` |
| **Consumidor Final** (NIT 222222222) | 1 | `ConsumerFinalProvisioner` |
| **Plantillas de impresión**: "Ticket POS 80mm" (default) + "Factura Carta" | 2 | `InvoiceTemplateProvisioner` |

**El cliente termina el registro y puede entrar al POS, vender, facturar y contabilizar sin que un asesor toque nada.** El onboarding es 100% automático.

### 3.3 Onboarding manual (caso especial)

Si un cliente fue creado con la versión vieja del sistema y le falta algún catálogo, o si por alguna razón el bootstrap falló parcialmente, ejecutar desde la VM:

```bash
docker exec -it emprenddi-app php artisan companies:bootstrap [--id=X] [--all]
```

- Sin flags: aplica a todas las empresas activas.
- `--id=X`: una empresa puntual.
- `--all`: incluye inactivas.

Es **idempotente** (no duplica lo existente). Imprime una tabla con cuántos registros nuevos creó por empresa.

---

## 4. Parametrización inicial paso a paso

Aunque el onboarding deja al cliente operativo, **algunos parámetros requieren intervención humana** para que la contabilidad refleje correctamente el negocio. Este es el checklist que el asesor debe completar con el cliente o validar que el cliente entendió.

### 4.1 Datos generales de la empresa

**Ruta**: panel App → **Configuraciones** → tab **Empresa**.

Verificar / completar:

| Campo | Cuándo es importante |
|---|---|
| Nombre comercial | Aparece en tickets POS y facturas |
| Razón social | Obligatorio para DIAN. Si difiere de nombre comercial, ambos salen en el ticket |
| NIT + DV | El sistema calcula el DV con `DianDvCalculator`. Validar que coincida con el RUT físico |
| Régimen | Define si el sistema activa retenciones, IVA, etc. |
| Email + teléfono + dirección | Datos públicos en facturas y menú QR |
| Moneda | COP por defecto. **No cambiar después de iniciar operaciones** |
| Zona horaria | `America/Bogota` por defecto |

### 4.2 Identidad visual (logo)

**Ruta**: panel App → **Configuraciones** → tab **Empresa** → sección **Identidad visual**.

Subir un **logo cuadrado o ligeramente horizontal**, máximo 2 MB, PNG con fondo transparente recomendado. El editor incluye crop. Se publica en:

- Ticket POS impreso (HTML)
- Header de la factura imprimible
- Carta pública del menú restaurante
- Comprobante de garantía
- Header de página legal

### 4.3 Plan Único de Cuentas (PUC)

**Ruta**: panel App → **Contabilidad** → **Plan de Cuentas**.

El PUC viene **pre-sembrado** con la estructura del Decreto 2650/1993. **No es necesario crear cuentas a menos que el cliente tenga necesidades específicas** (sub-cuentas para divisiones, departamentos, líneas de negocio).

Cuentas clave que se usan automáticamente (verificar que existen y están activas):

| Código | Cuenta | Uso |
|---|---|---|
| 110505 | Caja general | Pagos en efectivo |
| 1110xx | Bancos | Pagos por transferencia / tarjeta |
| 1305 | Clientes | Cartera (CxC) |
| 1435 | Mercancías | Inventario |
| 2205 | Proveedores nacionales | CxP |
| 240805 | IVA generado | Pasivo por IVA cobrado |
| 240810 | IVA descontable | IVA pagado en compras |
| 2365/2367/2370 | Retenciones | ReteFuente, ReteIVA, ReteICA |
| 4135 | Comercio | Ingresos por ventas |
| 6135 | Costo de ventas | COGS |
| 218505 | Propinas por pagar | Solo si activan propinas en POS Restaurante |

**Acciones disponibles**:
- Crear / editar / desactivar cuenta personalizada
- No se pueden borrar cuentas con movimientos
- Cuentas marcadas como `is_system=true` (las que vienen del PUC) están protegidas de algunos cambios

### 4.4 Impuestos

**Ruta**: panel App → **Contabilidad** → **Impuestos**.

Pre-sembrados:

| Código | Tasa | Aplica a | Cuenta venta | Cuenta compra |
|---|---|---|---|---|
| IVA-19 | 19% | venta + compra | 240805 | 240810 |
| IVA-5 | 5% | venta + compra | 240805 | 240810 |
| IVA-0 | 0% (exento) | venta | 240805 | — |
| IVA-EXC | excluido | venta | — | — |
| INC-8 | 8% | venta (restaurantes) | 240805 | — |
| RTF-* | 2.5/4/6/10/11% | compra | — | 2365 |
| ReteIVA-15 | 15% | compra | — | 2367 |
| ReteICA | 4.14×1000 | compra | — | 2368 |

**Validar con el contador del cliente**: ¿el cliente factura con IVA del 19%? ¿solo del 5%? ¿es no responsable? Activar/desactivar los que apliquen.

**Importante**: si el cliente es **no responsable de IVA** (régimen simplificado), no debería usar impuestos al vender. El sistema lo respeta si los toggles están bien.

### 4.5 Cuentas bancarias y métodos de pago

**Ruta**: panel App → **Configuraciones** → **Métodos de pago**.

Los 8 métodos vienen pre-sembrados con cuentas resueltas automáticamente:

| Método | Cuenta default | Editar para asociar |
|---|---|---|
| Efectivo | 110505 | Caja física |
| Tarjeta débito | 1110xx (primera disponible) | Banco específico donde llegan los abonos |
| Tarjeta crédito | 1110xx | Banco |
| Transferencia | 1110xx | Banco |
| PSE | 1110xx | Banco |
| Cheque | 1110xx | Banco |
| Nota crédito | sin cuenta | — |
| Otro | sin cuenta | — |

**Acción crítica**: si el cliente tiene **más de una cuenta bancaria**, crear las cuentas en el PUC (subcuentas de 1110, p.ej. `111005-Bancolombia`, `111010-Davivienda`) y luego duplicar los métodos de pago apuntando a la cuenta correcta. Si no, todos los pagos por transferencia caen en la misma cuenta y el banco queda mal contabilizado.

### 4.6 Sedes (Locations)

**Ruta**: panel App → **Operación** → **Sedes**.

Viene 1 sede creada: **"Sede Principal"** (`PRINCIPAL`). Tipo `mixed` (tienda + bodega).

Crear más si:
- El cliente tiene **varias tiendas físicas** → una sede por tienda
- Hay **bodega central separada** de los puntos de venta → sede tipo `warehouse`
- Hay tienda virtual / e-commerce → sede tipo `virtual`

Cada sede tiene su propia plantilla de impresión asignada (`invoice_template_id`) y sus propios consecutivos DIAN (`LocationResolution`).

**Tipos**:
- `store`: solo vende
- `warehouse`: solo almacena
- `mixed`: ambos (default)
- `virtual`: e-commerce

### 4.7 Categorías y productos

**Ruta**: panel App → **Catálogo** → **Categorías** y **Productos**.

#### Categorías

**Crearlas primero** según el giro del cliente. Recomendaciones:
- **Retail (electrónica)**: Computadores, Impresoras, Periféricos, Cables, Accesorios, Servicios
- **Ropa**: Camisetas, Pantalones, Jeans, Vestidos, Accesorios, Calzado
- **Restaurante**: Bebidas, Entradas, Fuertes, Pizzas, Postres, Para llevar
- **Mixto**: armar según necesidad

Las categorías permiten **anidamiento** (subcategorías), routing de impresoras (en restaurante: bebidas → impresora de barra, comidas → impresora de cocina), y filtros en POS.

#### Productos

Crear cada producto con:

| Campo | Notas |
|---|---|
| **Código (SKU)** | Único por empresa. Recomendado prefijar por categoría (`HW-001`, `PZ-002`) |
| **Código de barras** | Para escaneo en POS |
| **Nombre** | Lo que ve el cajero |
| **Marca** | Para reportes ABC |
| **Categoría** | Determina routing impresora restaurante |
| **Tipo** | `good` (físico), `service` (sin inventario), `kit` (combo), `consumable` (insumo interno), `variable` (padre con variantes) |
| **Unidad de medida** | unit/kg/g/l/ml/m/box/hour/etc. |
| **Imagen** | Aparece en POS y menú público; max 2 MB, cropped a 1:1 |
| **Controla inventario** | OFF para servicios |
| **Maneja seriales** | ON para equipos con número de serie (computadores, impresoras). Requiere feature global activa. |
| **Días de garantía** | 365 por defecto si el cliente vende equipos. 0 = sin garantía |
| **Se compra / Se vende** | Para excluir del POS o de compras |
| **Precio de compra default** | Para autocompletar en factura de compra |
| **Precio de venta default** | El que aparece en POS |
| **Precio incluye IVA** | Si el cliente piensa en precios "ya con IVA", marcar ON. El sistema desnormaliza al guardar |
| **Impuesto de venta default** | IVA-19, IVA-5, etc. |
| **Cuentas contables** (inventario, venta, costo) | Solo si difieren de las default (1435, 4135, 6135) |

#### Productos variables (talla / color)

1. Crear producto padre tipo `variable` con `is_sellable=false`, `is_purchasable=false`.
2. Desde el padre, crear variantes con sus propios precios, códigos y stock.
3. Las variantes son las que entran al POS, no el padre.

### 4.8 Terceros (clientes / proveedores / empleados)

**Ruta**: panel App → **Contabilidad** → **Terceros**.

Cada tercero tiene flags: `is_customer`, `is_supplier`, `is_employee` (no son excluyentes — un mismo NIT puede ser cliente y proveedor).

**Datos críticos para DIAN**:
- `person_type` (natural / jurídica)
- `document_type` (CC, NIT, CE, pasaporte, RUT)
- `document_number` + `dv` (para NIT)
- `dian_municipality_id` (catálogo DIAN, no escribir ciudad libre para B2B)
- `regime_type` (común / no responsable / gran contribuyente)
- `is_self_withholder`, `is_iva_withholder`, `is_ica_withholder` — para autoretenciones
- `tax_responsibilities` (multi-select del catálogo DIAN)

**Pre-creado**: el **Consumidor Final** con NIT 222222222. **No borrarlo** — el POS lo usa como fallback cuando no se identifica al cliente.

**Recomendación de implementación**: cargar terceros importantes (los 20-50 clientes y proveedores más frecuentes) **antes de arrancar a facturar** para evitar crearlos sobre la marcha en POS.

### 4.9 Resoluciones DIAN

**Ruta**: panel App → **Configuraciones DIAN** o **Ventas → Resoluciones POS**.

Existen **dos tipos** de resoluciones:

#### 4.9.1 Resolución POS local (Documento Equivalente)

- Para ventas POS de bajo monto donde NO se exige factura electrónica.
- El cliente la solicita ante la DIAN (resolución de talonarios o sistema POS).
- En el sistema: kind=`pos`, prefix (ej. `POS`), rango (`range_from` → `range_to`), fecha desde/hasta.
- Se asigna a una sede con `LocationResolution`.
- El POS la usa para emitir tickets POS no electrónicos.

#### 4.9.2 Resolución de facturación electrónica DIAN

- Para B2B y clientes que exigen factura electrónica.
- Requiere previamente:
  - **Certificado digital** vigente
  - **Resolución asignada por la DIAN** con `technical_key`
  - **apidian** configurado en config/dian.php con `token` y `url`
  - **Habilitación en producción** ante DIAN
- En el sistema: kind=`electronic`, document_type_id (1=Factura, 2=NC, 3=ND, 4=Soporte, 5=Nómina, 6=Exportación), prefix, rango.

**Flujo de implementación recomendado**:
1. Cliente envía RUT + resolución DIAN al asesor.
2. Asesor sube el certificado a `storage/app/dian/{nit}/`.
3. Asesor crea la resolución en el sistema con todos los datos.
4. Asesor habilita la resolución en pruebas DIAN (Habien) antes de producción.
5. Cliente factura una primera factura de prueba.
6. Si DIAN responde con CUFE y QR válidos → cambiar a producción.

**No se crean automáticamente**. Requieren acción manual de un asesor con conocimiento DIAN.

### 4.10 Configuración POS

**Ruta**: panel App → **Configuraciones** → tab **POS**.

| Toggle / campo | Recomendación |
|---|---|
| Permitir modificar precio en el carrito | ON para retail; OFF para cadenas con precio fijo |
| Permitir descuento por línea | ON usualmente |
| Permitir modificar/agregar impuesto por línea | ON; útil para casos donde el cliente compra con IVA diferente al default |
| Cliente obligatorio | OFF para POS rápido (consumidor final); ON para B2B |
| Permitir vender sin stock | OFF por seguridad |
| Imprimir ticket automático al cerrar venta | ON |
| Cierre de caja oculto | ON solo si el dueño quiere detectar diferencias sin sesgar al cajero |
| Propina sugerida % | 10% típico para restaurante |
| **Umbral % para aprobación de supervisor** | 10% por defecto. Si el cajero quiere descuento mayor, pide PIN |

### 4.11 Configuración Restaurante (si aplica)

**Ruta**: panel App → **Configuraciones** → tab **Restaurante** (visible si el módulo está activo en `active_modules`).

**Toggles de features** (RestaurantSettings::FEATURES):

| Feature | Activar si |
|---|---|
| Mesas y zonas | Restaurante con servicio en mesa |
| Domicilios | Hace entregas a domicilio |
| Reservaciones | Acepta reservas |
| Comer aquí / Para llevar (IVA diferencial) | INC del 8% solo aplica para comer aquí en algunos casos |
| Modificadores | Personalización de items (sin queso, salsas extra) |
| Cursos | Servir por cursos (entrada → fuerte → postre) |
| Propinas | Capturar propina al cobrar |
| División de cuenta | Dividir el total entre clientes |
| Half-and-half (1/2 + 1/2) | Pizzas o pastas con dos sabores |
| Operaciones de mesa | Transferir / juntar mesas |
| Split por item | Asignar items a cuentas separadas |
| Carta QR pública | Slug público para que el cliente vea la carta |

**Cuenta de propinas por pagar**: si activan propinas, asignar `218505` (subcuenta de pasivos). El sistema genera asiento DR Caja / CR 218505 cuando se cobra propina — queda como pasivo a pagar al staff.

#### Mesas y zonas (config)

**Ruta**: panel App → Restaurante → **Zonas de atención** y **Mesas**.

1. Crear zonas (Salón, Terraza, Barra) — cada una con color.
2. Crear mesas asignándolas a una zona, con código (M1, M2…), capacidad (personas).

#### Modificadores

**Ruta**: panel App → Restaurante → **Modificadores**.

1. Crear **grupos de modificadores** (ej. "Adiciones pizza", "Tipo de masa").
2. Cada grupo: nombre, `required` (obligatorio?), `min_select`, `max_select`.
3. Crear **modificadores** dentro de cada grupo (ej. "Queso extra +$2.000", "Masa delgada +$0").
4. **Asociar** grupos a productos (pivot `product_restaurant_modifier_group`).

#### Carta pública (QR)

**Ruta**: panel App → Restaurante → **Carta QR** → crear un menú con slug (`mi-pizzeria`) → accesible públicamente en `/menu/mi-pizzeria`. Imprimir QR y poner en cada mesa.

### 4.12 Seriales y Garantías (si aplica)

**Activar solo para tiendas de electrónica / equipos que requieren tracking individual y soporte post-venta.**

**Ruta**: panel App → **Configuraciones** → tab **Empresa** → secciones **Inventario por seriales** y **Garantías**.

1. **Activar gestión de seriales** (toggle global).
2. **Activar módulo de garantías** (toggle global).
3. En cada producto que lo amerite, marcar **"Maneja seriales"** y configurar **"Días de garantía"** (ej. 365 para portátiles, 180 para impresoras).

**Flujo**:
- Compras: al recibir, capturar los N seriales que llegan (TagsInput permite pegar de Excel).
- Ventas POS: pistolear el serial del equipo que sale → trae el producto y queda vinculado a la venta.
- Garantía: cliente trae equipo → buscar por SN → crear ticket → tracking de estados → comprobante imprimible → cambio de estado en cada movimiento → entregar al cliente firmado.

### 4.13 Plantillas de impresión

**Ruta**: panel App → **Operación** → **Plantillas de impresión**.

Pre-creadas:
- **Ticket POS 80mm** (default): para impresora térmica.
- **Factura Carta**: para impresión en oficina A4.

Cada plantilla controla qué se imprime: logo, NIT, dirección, datos del cliente, columnas (qty, código, precio, descuento), totales, footer DIAN (QR, CUFE), agradecimiento.

**Si el cliente tiene impresora 58mm**, crear una plantilla nueva con `paper_size=pos_58` y asignarla a la sede.

**Asignar plantilla a sede**: editar la sede → campo `invoice_template_id`. La sede usará esa plantilla cuando imprima desde el POS.

### 4.14 Impresoras (QZ Tray)

**Ruta**: panel App → **Operación** → **Impresoras**.

Para impresión local desde el navegador del cajero, el sistema usa **QZ Tray**.

#### Instalación inicial (cliente debe hacer)
1. Descargar QZ Tray desde `qz.io` (Windows/Mac/Linux).
2. Instalar y dejar corriendo en bandeja del sistema.
3. Conectar la impresora térmica.

#### Configuración en Emprenddi
1. Crear impresora: panel App → Impresoras → Nueva.
2. Llenar:
   - **Sede**: la sede del cajero
   - **Propósito**: `cashier` (caja/tickets), `kitchen` (cocina restaurante), `bar` (barra restaurante)
   - **Tipo de conexión**: `Navegador (QZ Tray local)` para imprimir desde el navegador del cajero
   - **Nombre Windows**: el nombre EXACTO que aparece en "Dispositivos e impresoras" de Windows (atento a mayúsculas, espacios, acentos)
   - **Columnas**: 48 para 80mm, 32 para 58mm
   - **Abrir cajón monedero**: ON si la impresora tiene cajón conectado
3. En la primera impresión QZ pide permiso una vez por sesión (cert auto-firmado actualmente; cliente puede pagar licencia QZ para firma sin diálogo).

**Routing por categoría en restaurante**: en la impresora de propósito `kitchen` o `bar`, asignar el `category_ids` (qué categorías de productos imprimen ahí). Ej: impresora "Barra" recibe categoría "Bebidas".

### 4.15 Usuarios, roles y permisos

**Ruta**: panel App → **Administración** → **Usuarios** y **Roles**.

#### Roles pre-sembrados (RolesSeeder)

| Rol | Para quién | Resumen |
|---|---|---|
| `admin` | Dueño / gerente | TODO. No se le restringe nada |
| `manager` | Subgerente | Casi todo menos `users.manage`, `roles.manage`, `company.settings`, `pos.settings`, `dian.manage`, `accounts.manage`, `taxes.manage`, `payment_methods.manage` |
| `accountant` | Contador interno | Contabilidad + reportes + pagos + nómina + exógena; SIN crear ventas o POS |
| `cashier` | Cajero POS | POS + cerrar caja + ventas + cobros + productos (view) + terceros + inventario (view) + restaurante (use, kitchen, mesas, modificadores, reservaciones, delivery) + seriales (view) + garantías (view, create) |
| `seller` | Vendedor | Productos (view) + crear ventas + cotizaciones + terceros + inventario (view) + seriales (view) |
| `accountant_external` | Contador del Portal Contador | Lectura amplia + edición contable (postear, anular); SIN POS ni settings |

#### Asignar usuario
1. Crear usuario con email + contraseña inicial.
2. Asignarle uno o más roles (un usuario puede tener varios).
3. Si quieres permiso específico fuera de roles, editar el rol o crear uno custom.

#### Permiso especial: aprobar descuentos POS
Si activaste el **umbral de descuento con supervisor**, el usuario que aprueba debe tener el permiso `pos.discount.approve` (admin y manager lo tienen por default).

### 4.16 Inventario inicial (apertura)

**Ruta**: panel App → **Inventario** → **Apertura de inventario**.

**Crítico**: ANTES de empezar a vender o comprar, el cliente debe cargar el **stock físico actual** (lo que tiene en bodega al cierre del día anterior).

Si NO carga apertura → los costos al vender serán 0 → utilidad inflada → contabilidad equivocada.

1. Hacer **conteo físico** (idealmente fuera de horario).
2. Apertura → Nueva → seleccionar **sede**, **fecha** (la del corte, ej. 31 dic).
3. Por cada producto: cantidad y costo unitario (lo que realmente costó comprarlo, NO el precio de venta).
4. Postear → genera asiento DR 1435 / CR 3xxx (ajuste patrimonial inicial) + crea `inventory_movements` tipo `opening`.

**El sistema bloquea ediciones de apertura ya posteada**. Si hay error, anular y volver a hacer.

### 4.17 Saldos contables iniciales

Si la empresa **viene de otro sistema contable** y trae saldos de cuentas (caja, bancos, CxC, CxP, capital), debe registrarlos con **asientos manuales**:

**Ruta**: panel App → **Contabilidad** → **Asientos contables** → Nuevo asiento.

Hacer un asiento de apertura con fecha del corte:

| Cuenta | Débito | Crédito |
|---|---|---|
| 110505 Caja | $X | |
| 111005 Banco | $Y | |
| 130505 Clientes (con tercero) | $Z | |
| 1435 Mercancías (si no se hizo apertura inventario aparte) | $W | |
| 220505 Proveedores (con tercero) | | $A |
| 360505 Resultados ejercicios anteriores | | (cuadre) |

**Las cuentas que requieren tercero** (`requires_third_party=true`) van con `third_party_id` asignado por línea. Ejemplo: 1305 Clientes — cada saldo CxC debe ir con el tercero específico, no agrupado.

### 4.18 Periodos fiscales

**Ruta**: panel App → **Contabilidad** → **Periodos fiscales**.

1. Crear el periodo del año fiscal actual (ej. `2026-01-01` → `2026-12-31`).
2. Crear periodos mensuales o el sistema los infiere para los reportes.
3. **Cerrar un mes** una vez liquidado bloquea ediciones contables en él (`FiscalPeriodGuard`).

**Recomendación**: cerrar mes a más tardar el día 10 del mes siguiente, después de conciliar bancos y validar IVA.

### 4.19 Centros de costo

**Ruta**: panel App → **Contabilidad** → **Centros de costo**.

Opcional. Útil para clientes que quieren reportar utilidad por **división**, **departamento**, **línea de negocio**, **sede operativa**.

Si activan, las cuentas configuradas como `requires_cost_center=true` exigirán cost_center_id en cada movimiento.

### 4.20 Activos fijos

**Ruta**: panel App → **Contabilidad** → **Activos fijos**.

Para registrar maquinaria, vehículos, computadores propios de la empresa (no para vender).

1. Crear activo: nombre, código, fecha adquisición, valor compra, vida útil (años), método (línea recta), cuenta de activo (15xx), cuenta de depreciación acumulada (159xxx), cuenta de gasto depreciación (5160xx).
2. Sistema calcula depreciación mensual y genera asientos automáticos al postear cada mes.

### 4.21 Nómina (catálogo y parámetros)

**Ruta**: panel App → **Nómina** → varios sub-módulos.

1. **Parámetros**: SMLMV vigente, UVT, aux. transporte, % aportes salud/pensión/ARL, % cesantías, % prima, etc.
2. **Mapeo de cuentas**: qué cuenta del PUC se usa para salario causado, salud, pensión, parafiscales, prima, cesantías, indemnización por despido (cuenta nueva agregada en feedback_indemnizacion).
3. **Empleados**: nombre, documento, fecha ingreso, salario, EPS, AFP, ARL.
4. **Contratos**: indefinido / fijo / obra labor / aprendizaje. Tipo de jornada. Fecha inicio y fin.
5. **Periodos**: crear el periodo a liquidar (quincenal o mensual).
6. **Liquidar**: el sistema genera la nómina, calcula deducciones legales, deja todo en borrador.
7. **Postear**: genera asiento contable y queda lista para pago.
8. **Liquidación definitiva** (cuando se va un empleado): el sistema calcula cesantías + intereses + prima + vacaciones + indemnización por despido si aplica.

**Pendiente**: envío a nómina electrónica DIAN (apidian lo soporta, falta cablear endpoint).

---

## 5. Operación diaria recomendada

Flujo típico del cliente día a día:

### Mañana
1. Cajero entra al POS → **abrir caja** con monto inicial (lo que dejó la caja chica del día anterior).
2. Revisar ventas suspendidas pendientes (si las hay).
3. Imprimir reporte de cierre del día anterior si no se hizo.

### Durante el día
1. Vender por POS (productos del grid, scan barcode, scan serial si aplica).
2. Recibir compras: factura proveedor → digitar → postear → genera asiento + entra al inventario.
3. Si llega un cliente con garantía: ir a **Garantías → Nueva**, buscar por SN o factura.
4. Si hay devolución del cliente: nota crédito desde la factura de venta original.
5. Si hay devolución a proveedor: documento "Devolución compra" desde factura compra original.

### Final del día
1. Cajero **cierra caja**: digita lo contado físicamente. Sistema compara con esperado y deja diferencia visible.
2. Hacer cuadre de propinas (si aplica restaurante).
3. Imprimir Z (cierre).

### Semanal
1. Contador (interno o externo via Portal Contador) revisa:
   - Asientos del día / semana.
   - Bancos: conciliar movimientos del sistema con extracto bancario.
   - Cartera: CxC vencidas.
   - CxP por pagar.
2. Programar pagos a proveedores.

### Mensual
1. Liquidar **nómina** del mes.
2. Generar **IVA bimestral** (si aplica).
3. Generar **retenciones del mes**.
4. **Cerrar el mes** en periodos fiscales.
5. Generar reportes: balance, P&G, kardex, ventas por vendedor.

### Anual
1. Generar **Información Exógena** DIAN.
2. Declaración renta.
3. Apertura del nuevo periodo fiscal.

---

## 6. Módulos por área (referencia detallada)

### 6.1 Contabilidad

**Sub-módulos**:
- Plan de cuentas (PUC editable)
- Asientos contables manuales
- Asientos automáticos (generados por ventas, compras, pagos, nómina, ajustes)
- **Reportes oficiales**: Libro Diario, Libro Mayor, Libro Auxiliar (por cuenta + tercero), Balance de Prueba
- **Reportes operativos**: Cartera (CxC), CxP, Cierres de caja, Kardex, Stock por sede, Ventas por periodo
- Centros de costo
- Periodos fiscales con bloqueo
- Activos fijos + depreciación
- Conciliación bancaria
- Socios + movimientos de capital
- Información exógena DIAN

### 6.2 Ventas

- **Factura tradicional** (UI form): para B2B, larga vida útil. Genera asiento al postear.
- **POS tradicional** (PosTerminal): pantalla touch. Para retail rápido. Imprime ticket por QZ Tray o HTML.
- **POS restaurante** (RestaurantPos): mesas, cursos, modificadores, propinas, división.
- **Cotizaciones**: convertibles a factura o remisión.
- **Remisiones (delivery notes)**: salida de inventario sin facturar; se factura después.
- **Notas crédito**: total o parcial. Reversa inventario y contabilidad. Envía a DIAN.
- **Notas débito**: aumentos al cliente. Envía a DIAN.
- **Anular factura**: bloqueada si tiene pagos o ya está en DIAN.

### 6.3 Compras

- **Factura de compra** (proveedor con NIT).
- **Documento soporte electrónico** (proveedor sin NIT o no responsable IVA).
- **Devolución a proveedor**: salida de inventario + nota débito a proveedor.
- **Pagos a proveedores**: parcial / total / con NC aplicada.
- **Anular**: bloqueada si tiene pagos o si los seriales que entraron ya se vendieron.

### 6.4 Inventario

- **Movimientos** (read-only, auto-generados): purchase, sale, opening, adjustment_in/out, transfer_in/out, return_to_supplier, return_from_customer.
- **Ajustes de inventario** (entrada / salida): con razón (daño, pérdida, conteo, expiración, encontrado, otro).
- **Transferencias entre sedes**: 1 ajuste origen + 1 ajuste destino atados.
- **Apertura de inventario**: al iniciar operaciones, una sola vez por producto y sede.
- **Kardex**: histórico de movimientos por producto.
- **Stock por sede**: vista actual.
- **Seriales** (si activado): por unidad, con trazabilidad compra → venta → garantía.

### 6.5 Restaurante

- **POS de mesas**: zonas con colores, mesas con estado (libre, ocupada, reservada), abrir orden, agregar items, enviar a cocina por curso, cobrar.
- **KDS (Cocina)**: pantalla auto-actualizable con tickets pendientes; mesero marca "listo" → mesero entrega → cierra ítem.
- **Comandas ESC/POS**: routing automático por categoría a impresora correcta.
- **Modificadores**: grupos obligatorios/opcionales con min/max.
- **Domicilios**: orden tipo `is_delivery`, con dirección y datos del cliente, asignación de driver, tracking público por token.
- **Reservaciones**: día/hora, mesa sugerida, contacto cliente, llegada/no show.
- **Carta pública QR**: `/menu/{slug}`, sin login, refresca cada visita.

### 6.6 Garantías

- Búsqueda por serial (autocompleta producto + cliente + factura) o crear manual.
- Estados con transiciones validadas: `received → in_review → in_repair → resolved/replaced/rejected → delivered`.
- Asignación de técnico interno.
- RMA opcional (número propio).
- Timeline visual de eventos.
- Comprobante imprimible A5 con condiciones legales (recepción al cliente).

### 6.7 Nómina

- Liquidación periódica (quincenal/mensual).
- Liquidación definitiva (retiro) con cálculo automático de prestaciones + indemnización por despido si aplica.
- Novedades (vacaciones, incapacidades, ausencias, bonificaciones).
- Asiento contable automático al postear.
- Mapeo flexible cuenta-concepto (cada empresa configura qué cuenta usa para salario, salud, etc.).

### 6.8 Suscripciones (Panel App)

El cliente puede ver su suscripción actual en su panel pero **no la puede modificar**. El cambio de plan o renovación lo hace el super-admin (o un equipo comercial).

---

## 7. Información exógena DIAN

**Ruta**: panel App → **Contabilidad** → **Información Exógena**.

Obligación anual de reportar a DIAN movimientos con terceros. El sistema:

1. **Catálogo**: tipos de formatos (1001 retenciones, 1004 IVA descontable, 1005 IVA generado, 1006 ventas, 1007 ingresos, 1008 cuentas por cobrar, 1009 pasivos, 1010 socios).
2. **Mapeo de cuentas**: el contador del cliente define qué cuentas del PUC alimentan cada concepto.
3. **Manual entries**: para datos que no surgen de movimientos contables (ej. aportes de socios).
4. **Generar**: el sistema arma el archivo XML por formato para cargar en MUISCA.

**Plazo legal**: hasta abril del año siguiente típicamente. Validar resolución vigente de la DIAN cada año.

---

## 8. Backups, mantenimiento y monitoreo

### 8.1 Backups

- La VM tiene snapshot diario de Google Cloud (configurado).
- BD se respalda con `pg_dump` automático (configurar en `/etc/cron.d/` de la VM si no está).
- Storage (`storage/app/public/`) incluye logos, imágenes de productos, certificados DIAN — incluir en backup.

### 8.2 Deploy

**Manual**:
```bash
ssh user@vm
cd /opt/emprenddi
git pull
sudo bash scripts/deploy.sh
```

El script aplica composer, npm, vite, filament:assets, migraciones, seeders, storage:link, cache (config/route/view), reinicia workers y scheduler.

### 8.3 Monitoreo

- Logs Laravel: `storage/logs/laravel.log` (filtrar por nivel `error` y `warning`).
- Logs Postgres: `/var/log/postgresql/`.
- Logs apidian (DIAN): respuestas guardadas en `sale_invoices.dian_response` (JSON).
- QZ Tray: errores en consola del navegador del cliente.

### 8.4 Operaciones recurrentes

- Limpiar `suspended_sales` muy antiguas (>30 días).
- Limpiar `cash_register_sessions` cerradas con más de 1 año (para reportes históricos seguir disponibles).
- Validar que el certificado DIAN no esté próximo a vencer (1 año típico).
- Validar resoluciones DIAN: pueden vencer por fecha o por rango (avisar al cliente 30 días antes).

---

## 9. Errores comunes y solución

| Error | Causa probable | Solución |
|---|---|---|
| "La factura no se envió a DIAN" | Credenciales apidian o cert vencido | Revisar `config/dian.php` y certificado en `storage/app/dian/{nit}/` |
| "No se puede anular factura con pagos" | Hay pagos registrados | Reversar primero los pagos, luego anular |
| "No se puede anular: hay seriales vendidos" | El producto entró por esta compra y ya se vendió | Anular primero las ventas que consumieron esos seriales |
| "Cliente Consumidor Final no existe" | Empresa creada antes del provisioner | `docker exec emprenddi-app php artisan companies:bootstrap --id=X` |
| "Stock negativo no permitido" | Setting `pos_allow_negative_stock=false` | Verificar inventario o activar setting (no recomendado) |
| "No se encontró cuenta de inventario del producto" | Producto sin cuenta de inventario y no existe 1435 | Provisionar PUC o asignar manualmente la cuenta al producto |
| "Foreign key violation al borrar productos en reset" | Refs huérfanas en restaurant_order_items | Ya cubierto con `cleanupProductReferences` en `CompanyDataReset` |
| QZ Tray no imprime | QZ no corriendo en bandeja, o nombre Windows no coincide | Verificar bandeja + nombre exacto en config impresora |
| Imagen producto no se ve | `storage:link` no creado | El deploy lo crea; si es manual: `php artisan storage:link` |
| Cierre de caja con diferencia muy alta | Cajero no registró pago correctamente | Revisar logs de pagos del turno |
| "Login no funciona / sesión expira rápido" | Cookie de sesión perdida | Validar `SESSION_DOMAIN` y `APP_URL` en `.env` |

---

## 10. Checklist de implementación rápida

Para un cliente nuevo, el asesor debe completar este checklist (idealmente 1-2 sesiones de capacitación):

### Sesión 1 — Configuración (2-3 horas)

- [ ] Validar datos generales empresa (NIT, dirección, régimen)
- [ ] Subir logo
- [ ] Validar PUC: agregar cuentas custom si las hay (subcuentas por banco, por línea de negocio)
- [ ] Validar impuestos: activar/desactivar según régimen del cliente
- [ ] Crear sucursales si tiene más de una sede
- [ ] Configurar métodos de pago con sus cuentas bancarias correctas
- [ ] Crear categorías de productos
- [ ] Cargar 20-50 productos prioritarios con sus imágenes
- [ ] Cargar 20-50 terceros (clientes y proveedores frecuentes)
- [ ] Cargar 1-3 usuarios adicionales con sus roles
- [ ] Configurar plantilla de impresión (si difiere de 80mm default)
- [ ] Si tiene impresora térmica: instalar QZ Tray + registrar impresora
- [ ] Configurar resolución POS local si va a usar POS
- [ ] Si maneja seriales o garantías: activar toggles y configurar productos
- [ ] Si es restaurante: zonas, mesas, modificadores, carta QR

### Sesión 2 — Saldos iniciales y arranque (2-3 horas)

- [ ] Cargar apertura de inventario (conteo físico)
- [ ] Cargar asiento de saldos iniciales (caja, bancos, CxC, CxP, capital)
- [ ] Crear primer periodo fiscal del año
- [ ] Si tiene activos fijos: cargarlos
- [ ] Si tiene empleados: cargar nómina (empleados + contratos + parámetros + mapeo cuentas)
- [ ] Conciliar bancos del último mes con extracto
- [ ] Validar balance de prueba: ¿cuadra con balance del sistema anterior?
- [ ] Hacer una venta de prueba completa: producto → POS → cobro → impresión → revisión asiento contable
- [ ] Hacer una compra de prueba: factura proveedor → postear → revisión asiento e inventario
- [ ] Si va a usar DIAN electrónica: emitir factura de prueba en ambiente Habien

### Sesión 3 (opcional) — Capacitación al usuario final (1-2 horas por rol)

- [ ] Cajero: usar POS, abrir/cerrar caja, manejar devoluciones
- [ ] Vendedor: hacer cotización → convertir a factura
- [ ] Administrativo: dashboard, reportes básicos, autorizar descuentos
- [ ] Contador interno: postear asientos, generar libros, conciliar bancos

---

## 11. Glosario

| Término | Significado |
|---|---|
| **PUC** | Plan Único de Cuentas. Catálogo estándar de cuentas contables Colombia (Decreto 2650/1993) |
| **DIAN** | Dirección de Impuestos y Aduanas Nacionales (autoridad fiscal Colombia) |
| **DV** | Dígito de Verificación del NIT (calculado por algoritmo DIAN) |
| **CUFE** | Código Único de Factura Electrónica (devuelto por DIAN al aceptar una factura) |
| **CUDE** | Código Único de Documento Electrónico (variante del CUFE para otros documentos) |
| **Documento Equivalente / POS** | Comprobante de venta NO electrónico (talonario físico o sistema POS con resolución DIAN especial) |
| **Documento Soporte** | Comprobante electrónico de compra a NO responsables de IVA (obligación del comprador) |
| **NC / ND** | Nota Crédito / Nota Débito (modificaciones a factura ya emitida) |
| **RUT** | Registro Único Tributario |
| **SMLMV** | Salario Mínimo Legal Mensual Vigente |
| **UVT** | Unidad de Valor Tributario (referencia DIAN, se actualiza cada año) |
| **CxC / CxP** | Cuentas por Cobrar / Cuentas por Pagar |
| **COGS** | Cost of Goods Sold (costo de mercancía vendida — cuenta 6135 típicamente) |
| **WAvg / Promedio Ponderado** | Método de costeo de inventario: costo unitario = (costo total) / (cantidad total) |
| **FIFO** | First In First Out (otro método de costeo, primero en entrar primero en salir) |
| **RMA** | Return Merchandise Authorization (autorización de garantía / devolución) |
| **KDS** | Kitchen Display System (pantalla de cocina) |
| **ESC/POS** | Lenguaje binario estándar de impresoras térmicas (Epson, Star, etc.) |
| **QZ Tray** | Aplicación que permite imprimir desde navegador a impresora local |
| **MUISCA** | Plataforma virtual de la DIAN (Modelo Único de Ingresos, Servicios y Control Automatizado) |
| **Habien** | Ambiente de pruebas DIAN para facturación electrónica antes de pasar a producción |
| **apidian** | Proveedor tecnológico autorizado por DIAN para transmisión electrónica (usado por Emprenddi) |
| **Tercero** | Cualquier persona/empresa con la que se opera (cliente, proveedor, empleado, socio) |
| **Periodo fiscal** | Rango de fechas contables; al cerrarse bloquea ediciones |
| **Conciliación bancaria** | Cruce de movimientos del sistema vs. extracto del banco |
| **Cierre de caja** | Cuadre del cajero al final del turno (esperado vs contado) |

---

## Anexos

### A. Versión del documento

| Versión | Fecha | Cambios |
|---|---|---|
| 1.0 | 2026-05-23 | Versión inicial. Cubre todas las features hasta commit `2f73d05` (POS restaurante con catálogo arriba e imágenes) |

### B. Referencias técnicas

- Stack: ver `composer.json` (PHP 8.4, Laravel 12, Filament 3.3, Spatie Permission, Livewire 3)
- Memorias del proyecto: `~/.claude/projects/.../memory/` (docs internos de diseño)
- Scripts de deploy: `scripts/deploy.sh`
- Configuración: `config/legal.php`, `config/qz.php`, `config/filesystems.php`

### C. Contactos internos

- Desarrollo: `comercial1@triadify.com`
- Soporte: (definir)
- Comercial: (definir)
