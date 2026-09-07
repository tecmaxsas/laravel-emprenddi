<?php

/**
 * Contenido del instructivo del demo de perfumeria.
 *
 * Va aparte del constructor para que actualizar el texto no obligue a tocar
 * la maquinaria del .docx.
 */
$doc = new DocxBuilder;

// ---------------------------------------------------------------- portada
$doc->titulo('Demo Perfumería Aroma')
    ->p('Guía para mostrar la cartera de clientes de Emprenddi: venta a crédito, abonos, anticipos y estado de cuenta.')
    ->p('Documento generado el '.date('d/m/Y').'.');

$doc->h1('1. Qué es este demo y para qué sirve');

$doc->p('Es una empresa de prueba con seis clientes, cada uno en una situación distinta de cartera. '
    .'No son datos de relleno: cada cliente existe para mostrar una cosa concreta en pantalla, sin '
    .'tener que explicarla.')
    ->p('Sirve para enseñarle a un prospecto cómo Emprenddi maneja las ventas a crédito y, sobre todo, '
        .'cómo evita el descuadre clásico de un cliente con saldo a favor y deuda al mismo tiempo.');

$doc->h2('Lo que se puede mostrar');
$doc->vineta('La hoja de cuenta de un cliente con su saldo corrido, movimiento por movimiento.')
    ->vineta('Un cliente que abonó de más y quedó con saldo a favor.')
    ->vineta('Un cliente que migró debiendo de otro sistema.')
    ->vineta('Un cliente con el cupo de crédito casi copado, y el bloqueo al intentar venderle más.')
    ->vineta('El PDF del estado de cuenta y su envío por correo.')
    ->vineta('El registro de un anticipo en vivo, con su aplicación automática.');

// ------------------------------------------------------------- instalacion
$doc->h1('2. Instalación');

$doc->p('En el servidor, dentro de la carpeta del proyecto:');
$doc->comando("cd /opt/emprenddi\ngit pull origin main\ndocker exec emprenddi_app php artisan migrate --force\ndocker exec emprenddi_app php artisan db:seed --class=PerfumeryDemoSeeder --force");

$doc->p('Al terminar debe aparecer:');
$doc->comando("✅ Demo de perfumería lista.\n   Empresa:  Perfumería Aroma S.A.S.\n   Admin:    demo@perfumeriaaroma.co\n   Password: Demo2026!");

$doc->p('**Se puede repetir sin miedo.** El seeder detecta lo que ya existe y solo crea lo que falta, '
    .'así que volver a lanzarlo no duplica facturas ni altera los saldos.');

$doc->h2('Si algo sale mal a mitad de camino');
$doc->p('Vuelve a lanzar el mismo comando. Retoma donde quedó.');

// ---------------------------------------------------------------- acceso
$doc->h1('3. Cómo entrar');

$doc->tabla(
    ['Dato', 'Valor'],
    [
        ['Dirección', 'pos.emprenddi.com/app'],
        ['Usuario', 'demo@perfumeriaaroma.co'],
        ['Contraseña', 'Demo2026!'],
        ['Empresa', 'Perfumería Aroma S.A.S. — NIT 901777333'],
    ],
);

$doc->p('La pantalla de la demostración es **Reportes operativos → Estado de cuenta**.');

$doc->saltoDePagina();

// -------------------------------------------------------------- clientes
$doc->h1('4. Los seis clientes');

$doc->p('Busca cada uno por su nombre en el selector de la hoja de cuenta. Los valores son los que '
    .'deja el seeder recién instalado.');

$doc->tabla(
    ['Cliente', 'Documento', 'Saldo', 'Qué muestra'],
    [
        ['Laura Mejía Castro', '1018445720', '$0', 'Al día: compró y pagó completo'],
        ['Carolina Ramírez', '52984110', '$148.800', 'Dos facturas, una abonada al 40%'],
        ['Jorge Andrés Soto', '79554321', '$425.000', 'Migró debiendo del sistema anterior'],
        ['Diana Patiño', '1032889014', '−$150.000', 'Saldo a favor: abonó más de lo que debía'],
        ['Martha Lucía Gómez', '43118902', '$240.000', 'Cupo casi copado: solo $10.000 disponibles'],
        ['Wilson Villegas', '1015998877', '−$20.000', 'El caso Broadway, resuelto'],
    ],
);

$doc->p('Un saldo en negativo significa que la empresa le debe al cliente, no al revés.');

// ------------------------------------------------------------------ guion
$doc->h1('5. Guion de la demostración');

$doc->p('Este recorrido dura unos diez minutos y va de lo simple a lo que de verdad diferencia.');

$doc->h2('Paso 1 — La hoja de cuenta (Carolina Ramírez)');
$doc->vineta('Entra a **Reportes operativos → Estado de cuenta** y busca a Carolina.')
    ->vineta('Arriba salen cuatro cifras: total facturado, total abonado, saldo a favor y saldo adeudado.')
    ->vineta('Abajo, cada movimiento con el saldo acumulado hasta ese punto.')
    ->vineta('Punto a resaltar: el saldo de cada línea sale de las facturas y los pagos reales, no de una '
        .'tabla aparte que pueda desincronizarse.');

$doc->h2('Paso 2 — El que migró debiendo (Jorge Andrés Soto)');
$doc->vineta('Su hoja abre con una línea **Saldo de apertura**: lo que ya debía cuando la empresa '
    .'empezó a usar Emprenddi.')
    ->vineta('Sirve para responder la pregunta que siempre sale en una demostración: '
        .'“¿y los saldos que ya tengo en mi sistema actual?”.')
    ->vineta('La respuesta: se importan con el archivo de terceros, en las columnas '
        .'`opening_balance` y `opening_balance_date`.');

$doc->h2('Paso 3 — El caso Broadway (Wilson Villegas)');
$doc->p('**Este es el que vale.** Wilson reproduce un caso real de otro sistema: un cliente que abona '
    .'cuando ya tiene una factura pendiente.');
$doc->vineta('En el otro sistema quedaba con **$20.000 a favor Y $20.000 en deuda al mismo tiempo**, '
    .'y nadie sabía si el cliente debía o le debían.')
    ->vineta('Aquí el anticipo se aplicó solo contra la factura pendiente: queda **$20.000 a favor y '
        .'deuda en cero**.')
    ->vineta('Esa es la diferencia, y se ve en una pantalla sin explicar nada.');

$doc->h2('Paso 4 — El cupo de crédito (Martha Lucía Gómez)');
$doc->vineta('Su cupo es de $250.000 y debe $240.000: le quedan $10.000.')
    ->vineta('En **Contactos → Terceros**, la columna Cupo muestra debajo cuánto le queda disponible.')
    ->vineta('Intenta venderle a crédito algo de más de $10.000 y contabilizar la factura: el sistema lo '
        .'bloquea diciendo el número exacto.');

$doc->h2('Paso 5 — Registrar un anticipo en vivo');
$doc->vineta('En la hoja de cuenta de **Carolina**, dale a **Registrar anticipo**.')
    ->vineta('Pon un valor mayor a su deuda, por ejemplo $200.000.')
    ->vineta('Se aplica solo contra su factura pendiente y el sobrante queda como saldo a favor.')
    ->vineta('Es la demostración de que el descuadre no se puede formar ni queriendo.');

$doc->h2('Paso 6 — PDF y correo');
$doc->vineta('**Descargar PDF** genera el estado de cuenta con el membrete de la empresa.')
    ->vineta('**Enviar por correo** abre un mensaje con el saldo ya redactado y el PDF adjunto.')
    ->vineta('Los clientes del demo tienen correos de ejemplo (`@example.co`), así que cambia el '
        .'destinatario por uno tuyo si vas a enviarlo de verdad.');

$doc->saltoDePagina();

// -------------------------------------------------------------- catalogo
$doc->h1('6. Qué más trae el demo');

$doc->h2('Productos');
$doc->tabla(
    ['Código', 'Producto', 'Precio de venta'],
    [
        ['PERF-001', 'Eau de Parfum Nocturne 100 ml', '$185.000'],
        ['PERF-002', 'Eau de Toilette Brisa 75 ml', '$128.000'],
        ['PERF-003', 'Perfume Ámbar Oud 50 ml', '$240.000'],
        ['PERF-004', 'Body Splash Cítrico 250 ml', '$45.000'],
        ['PERF-005', 'Set Regalo Elegance', '$210.000'],
        ['PERF-006', 'Crema Corporal Vainilla 200 ml', '$38.000'],
    ],
);

$doc->p('Los precios ya incluyen IVA del 19 %.');

$doc->h2('Facturas');
$doc->p('Siete facturas a crédito, con prefijo `DEMO` y números del 1001 al 1007, todas contabilizadas. '
    .'Cada una tiene 30 días de plazo desde su fecha.');

// ----------------------------------------------------------- limitaciones
$doc->h1('7. Lo que el demo no hace');

$doc->p('Conviene saberlo antes de que lo pregunten en la reunión.');

$doc->vineta('**Los productos no controlan existencias.** El demo es de cartera; exigir una apertura de '
    .'inventario para poder facturar solo agregaría ruido. Si necesitas mostrar inventario, es otro demo.')
    ->vineta('**Las facturas no se envían a la DIAN.** Son facturas POS. La facturación electrónica se '
        .'muestra en otra cuenta con su habilitación hecha.')
    ->vineta('**Los correos de los clientes son de ejemplo.** No le envíes el estado de cuenta a '
        .'`@example.co` esperando que llegue a alguna parte.');

// --------------------------------------------------------------- reinicio
$doc->h1('8. Dejarlo como estaba');

$doc->p('Si durante una demostración quedaron datos que no querías —un anticipo de prueba, una factura '
    .'nueva—, lo más rápido es borrar los movimientos del demo y volver a sembrarlo:');

$doc->comando("docker exec emprenddi_app php artisan tinker --execute='\n"
    ."\$c = \\App\\Models\\Company::where(\"nit\",\"901777333\")->firstOrFail();\n"
    ."\$inv = \\App\\Models\\SaleInvoice::withoutGlobalScopes()->where(\"company_id\",\$c->id)->pluck(\"id\");\n"
    ."\\App\\Models\\Payment::withoutGlobalScopes()->whereIn(\"paymentable_id\",\$inv)->delete();\n"
    ."\\DB::table(\"sale_invoice_lines\")->whereIn(\"sale_invoice_id\",\$inv)->delete();\n"
    ."\\App\\Models\\SaleInvoice::withoutGlobalScopes()->whereIn(\"id\",\$inv)->forceDelete();\n"
    ."\\App\\Models\\CustomerAdvance::withoutGlobalScopes()->where(\"company_id\",\$c->id)->delete();\n"
    ."echo \"demo limpiado\\n\";'");

$doc->p('Y después:');
$doc->comando('docker exec emprenddi_app php artisan db:seed --class=PerfumeryDemoSeeder --force');

$doc->p('**Ojo:** eso borra las facturas de esa empresa. Úsalo solo en la cuenta del demo, nunca en una '
    .'cuenta de cliente real.');

$doc->guardar(__DIR__.'/../docs/DEMO_PERFUMERIA.docx');

echo "Documento generado en docs/DEMO_PERFUMERIA.docx\n";
