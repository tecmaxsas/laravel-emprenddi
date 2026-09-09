# Catálogo público de productos

Un enlace que la empresa comparte con sus clientes por WhatsApp o redes, con
sus productos, fotos y precios.

## Dónde se crea

**Productos → Catálogo público**, en el panel de la empresa. Lo ve y lo edita
quien tenga los permisos de productos (`products.view` y `products.manage`): no
se creó un permiso aparte porque quien administra el catálogo de productos es
quien decide qué se le muestra al público.

El enlace queda en `pos.emprenddi.com/catalogo/<lo-que-elijas>`.

## Se actualiza solo

**El catálogo no guarda productos.** Guarda cómo se ven y cuáles entran; los
productos se leen de la base cada vez que alguien abre el enlace. Por eso un
cambio de precio, una foto nueva o un producto desactivado se reflejan de
inmediato: no hay nada que regenerar ni un botón que alguien vaya a olvidar.

## Qué productos aparecen

Automáticamente, todos los que cumplan:

- Están **activos**.
- Están marcados como **vendibles** (o son un producto «variable», el padre de
  unas tallas o colores).
- Pertenecen a alguna de las **categorías publicadas**, si elegiste algunas.
- Tienen **foto**, si activaste esa opción.

Las variantes no se listan sueltas: «Camiseta talla S», «talla M» y «talla L»
se verían como tres productos distintos. Se muestra el producto padre con sus
presentaciones y el rango de precios.

## Qué se puede personalizar

**Enlace** — nombre, dirección del enlace, frase de presentación, publicado o
no, y si quieres que aparezca en Google (apagado por defecto: el enlace se
comparte, no se publica).

**Qué se muestra** — categorías a publicar, precios sí o no, código del
producto, disponibilidad («Disponible» / «Agotado», nunca la cantidad exacta), y
si se muestran solo los productos con foto.

**Diseño** — logo propio (o el de la empresa), imagen de portada, seis colores,
diez tipografías de Google Fonts, estilo de cabecera (degradado, color sólido o
imagen), cuadrícula o lista, productos por fila, forma de las esquinas, y si se
muestran fotos y descripciones.

**Contacto** — WhatsApp para pedidos (pone un botón en cada producto y otro
flotante), teléfono, correo y un texto al pie. Lo que dejes vacío se toma de la
empresa.

Los valores de fábrica son neutros y se ven bien tal cual: no hace falta abrir
la pestaña de diseño para publicar.

## Lo que ve el cliente

Una página sola, sin necesidad de instalar nada ni iniciar sesión: buscador por
nombre, código o marca; filtro por categorías; y las tarjetas con foto, marca,
nombre, descripción, precio y el botón de WhatsApp. Se adapta a celular. Los
productos se paginan de 48 en 48.

## Detalles técnicos

- Ruta pública `GET /catalogo/{slug}`, con límite de 120 peticiones por minuto.
- El `slug` es único en **toda la plataforma**, no por empresa: la URL no lleva
  el identificador de la empresa.
- **El aislamiento entre empresas se hace a mano.** La ruta no tiene
  autenticación, y el filtro automático por empresa (`CompanyScope`) falla
  abierto cuando no hay usuario: no filtra nada. Cada consulta de
  `App\Services\Catalog\CatalogProducts` lleva `withoutGlobalScopes()` y su
  `company_id` explícito tomado del catálogo. Hay pruebas dedicadas a esto.
- La página trae su CSS en línea y no depende del bundle de Vite, que solo se
  construye en el despliegue.
- Los cambios sobre un catálogo quedan en la [bitácora de auditoría](AUDITORIA.md).

## Instalación

```bash
cd /opt/emprenddi
git pull origin main
docker exec emprenddi_app php artisan migrate --force
docker exec emprenddi_app php artisan storage:link
docker exec emprenddi_app php artisan optimize:clear
```

`storage:link` es lo que hace que se vean las fotos. El despliegue normal ya lo
corre, pero conviene confirmarlo: sin ese enlace las imágenes dan 404 y el
catálogo sale con las iniciales de cada producto en vez de las fotos.

## Limitaciones conocidas

- **No es una tienda.** No hay carrito ni pago en línea: el pedido sale por
  WhatsApp. Si se necesita vender desde ahí, es otro desarrollo.
- **Las fotos las carga el usuario producto por producto** (Productos → editar →
  Imagen del producto). No hay carga masiva de imágenes.
- **La disponibilidad suma todas las sedes.** No se puede publicar el stock de
  una sede concreta.
- **Un catálogo con muchísimos productos consulta la base en cada visita.** Con
  el volumen actual de los clientes no es problema; si alguno llega a decenas de
  miles de productos y mucho tráfico, habría que ponerle una caché corta.
