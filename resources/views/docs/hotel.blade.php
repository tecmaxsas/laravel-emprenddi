{{--
    Guía pública del módulo Hoteles.

    Solo el contenido: el cascarón —encabezado, tabla de contenido, tarjetas y
    el CSS— vive en `docs.layout`, compartido con la guía del parqueadero.
--}}
@php
    $co = config('legal.company_name');

    $sections = [
        [
            'icon' => '🏨',
            'title' => '¿Qué es el módulo Hoteles?',
            'lead' => 'Un módulo opcional dentro de '.$co.' para administrar un hotel completo: '
                .'habitaciones, reservas, huéspedes, la cuenta de cada estadía y el cobro al salir. '
                .'Se conecta con la contabilidad, el inventario, las cajas y la facturación '
                .'electrónica DIAN sin configurar nada aparte.',
            'bullets' => [
                'Soporta varios hoteles en una misma empresa.',
                'Mapa de recepción con el estado de cada habitación y el saldo de quien está dentro.',
                'Reservas por tipo de habitación o por habitación concreta, con control de sobrecupo.',
                'Folio por estadía: noches, consumos del minibar, restaurante, lavandería y servicios.',
                'Registro de huéspedes con los datos que pide la ley, y tarjeta de registro imprimible.',
                'Check-out que emite tiquete POS o factura electrónica DIAN y cuadra con el turno de caja.',
                'Ocupación, ADR y RevPAR día por día, con exportación a Excel.',
            ],
        ],
        [
            'icon' => '🔓',
            'title' => 'Activación',
            'lead' => 'El módulo es opcional. Lo activa el SuperAdmin de '.$co.' para una empresa concreta '
                .'cuando se ha contratado.',
            'bullets' => [
                'Con el módulo activo aparece el grupo «Hotel» en el menú de la empresa.',
                'Los permisos hotel.* se reparten a los roles desde Administración → Roles.',
                'Por defecto el administrador recibe todo; el recepcionista aloja, reserva, registra '
                    .'huéspedes y carga consumos, pero NO puede cerrar una cuenta sin facturar, anular '
                    .'una estadía ni rebajar un folio: eso lo autoriza un administrador.',
            ],
        ],
        [
            'icon' => '⚙️',
            'title' => 'Configuración inicial',
            'lead' => 'Cuatro pasos para dejar el hotel operando:',
            'bullets' => [
                '1. Hotel: créalo con su código, dirección, RNT y —obligatorio— la sede de facturación. '
                    .'La sede decide qué resolución DIAN aplica al cobrar; sin ella el check-out no puede '
                    .'emitir factura.',
                '2. Horarios: la hora de entrada y la de salida. La de salida no es decorativa: decide '
                    .'desde cuándo una entrega tardía suma una noche al folio.',
                '3. Tipos de habitación: Sencilla, Doble, Suite… Aquí vive la tarifa por noche. Se siembran '
                    .'seis tipos al activar el módulo, todos en $0: con tarifa en cero el sistema no deja '
                    .'alojar a nadie, en vez de abrir un folio sin precio.',
                '4. Zonas y habitaciones: las zonas son los pisos o torres y agrupan el mapa. Las '
                    .'habitaciones tienen un botón «Crear en lote» para generar un piso completo '
                    .'(101 a 112) de una sola vez.',
            ],
        ],
        [
            'icon' => '📅',
            'title' => 'Reservas y sobrecupo',
            'lead' => 'Una reserva puede nacer sin habitación asignada: el cliente aparta «una doble para el '
                .'viernes» y el número se decide después, a veces el mismo día de la llegada.',
            'bullets' => [
                'Lo que se aparta es el tipo de habitación; la habitación concreta se ancla cuando se '
                    .'quiera, y el sistema solo ofrece las que están libres en esas fechas.',
                'Las noches son el rango: del 8 al 10 son dos noches, y la habitación queda libre la '
                    .'mañana del 10 para quien llegue ese día.',
                'El control de sobrecupo es el mismo por todos los caminos —crear, anclar, hacer check-in '
                    .'de mostrador o extender una estadía—, y cuando choca dice con qué: «la tiene la '
                    .'reserva R-000012 de Pedro Gómez».',
                'Estados: por confirmar, confirmada, alojado, no llegó, cancelada y terminada. «No llegó» '
                    .'se separa de «cancelada» a propósito: una cancelación avisada suele devolver el '
                    .'depósito y un no-show no, y así se puede medir cuántas reservas se pierden.',
                'Origen de la reserva: mostrador, teléfono, WhatsApp, Booking, Airbnb, agencia.',
                'El depósito pactado se anota en la reserva; la plata se recibe en la recepción, donde '
                    .'entra al turno de caja.',
            ],
        ],
        [
            'icon' => '🛏️',
            'title' => 'La recepción (el POS del hotel)',
            'lead' => 'Una sola pantalla con el mapa de habitaciones. El recepcionista trabaja a golpe de '
                .'clic sobre el tile.',
            'bullets' => [
                'Habitación libre → check-in, de una reserva que llega hoy o de mostrador.',
                'Habitación ocupada → el folio: cargar consumos, trasladar, cambiar la fecha de salida, '
                    .'recibir depósito, imprimir la cuenta y cobrar.',
                'Habitación en aseo → marcarla aseada y devolverla al inventario vendible.',
                'Arriba, las llegadas y las salidas del día: la lista de pendientes del turno. Las '
                    .'estadías que se pasaron de la fecha salen marcadas.',
                'El buscador acepta número de habitación, nombre del huésped, código de reserva '
                    .'(R-000012) o número de folio (H-000045).',
                'KPIs en vivo: libres, ocupadas, en aseo y porcentaje de ocupación.',
                'El turno de caja se abre y se cierra desde la misma pantalla: un hotel no tiene por qué '
                    .'pasar por el POS de mostrador para nada.',
            ],
        ],
        [
            'icon' => '🧾',
            'title' => 'El folio: la cuenta del huésped',
            'lead' => 'Una estadía es una cuenta abierta, no una transacción. El huésped entra, se le van '
                .'cargando noches y consumos, y todo se cobra junto al salir.',
            'bullets' => [
                'Las noches se cargan una por una, con su fecha, para que el huésped pueda ver qué se le '
                    .'cobró y qué día. En la factura se agrupan en una sola línea.',
                'Las noches se cargan mientras el huésped está adentro, nunca por adelantado: si se va '
                    .'antes, habría que devolverle plata ya facturada.',
                'Consumos: productos del catálogo (minibar, restaurante) o servicios escritos a mano '
                    .'(lavandería, uso de salón, traslado). Los productos con inventario se descargan del '
                    .'almacén al cerrar la cuenta.',
                'Ajustes y cortesías: entran como rebaja con el IVA del alojamiento, así que la rebaja '
                    .'también baja el impuesto. Sin eso el hotel le pagaría a la DIAN el IVA de una plata '
                    .'que no cobró. Requieren permiso aparte.',
                'Trasladar al huésped de habitación conserva el folio: es la cuenta de la persona, no de '
                    .'la habitación.',
                'La cuenta se imprime en la térmica de 80 mm y lleva, en grande, el aviso de que NO es '
                    .'una factura de venta. Ese aviso no es configurable.',
            ],
        ],
        [
            'icon' => '💳',
            'title' => 'Check-out y facturación',
            'lead' => 'El cobro liquida el folio, emite el documento y cierra la estadía en una sola '
                .'operación.',
            'bullets' => [
                'Al abrir el cobro se liquida el folio: se cargan las noches que faltaban y, si la salida '
                    .'es tardía, la noche extra. La cifra que se le enseña al huésped es la que se le cobra.',
                'Quien se va antes de lo pactado no paga la noche que no durmió: la liquidación la quita '
                    .'del folio.',
                'Quien entrega tarde paga una noche más, con los minutos de gracia que el hotel configure '
                    .'(una hora por defecto). Cobrarle una noche completa a quien entregó cinco minutos '
                    .'tarde es una pelea que el hotel pierde siempre.',
                'Documento: tiquete POS o factura electrónica DIAN, con el consecutivo y la resolución de '
                    .'la sede. Se propone el que tenga configurado el hotel y se puede cambiar en cada '
                    .'check-out.',
                'Las rebajas del folio viajan a la factura como descuento global, que baja la base gravable '
                    .'antes del IVA. Una línea de factura en negativo la rechaza la DIAN.',
                'El vuelto se calcula cuando se paga en efectivo, igual que en los demás POS.',
                'Requiere turno de caja abierto: una venta tiene que quedar en el cierre de alguien.',
                'Al cerrar, la habitación pasa a «aseo» y no a «disponible»: hay que entrar a tenderla '
                    .'antes de dársela a nadie.',
            ],
        ],
        [
            'icon' => '💵',
            'title' => 'Depósitos y anticipos',
            'lead' => 'El depósito se recibe desde el folio y entra como anticipo del cliente, que es la '
                .'figura que ya existe en el sistema.',
            'bullets' => [
                'La plata entra al turno de caja el día que se recibe, no el día del check-out.',
                'Al facturar el check-out el anticipo se cruza solo y el huésped paga la diferencia.',
                'Necesita un cliente con nombre asignado a la estadía: un saldo a favor sin dueño no se '
                    .'puede cruzar con nada ni devolver.',
            ],
        ],
        [
            'icon' => '🪪',
            'title' => 'Huéspedes y registro hotelero',
            'lead' => 'El huésped es quién durmió en el hotel; el cliente es a quién se le factura. No '
                .'siempre son la misma persona —una empresa paga la habitación de su empleado— y por eso '
                .'son dos cosas distintas.',
            'bullets' => [
                'Del huésped se guardan los datos que pide el registro hotelero: documento, nacionalidad, '
                    .'fecha de nacimiento, procedencia, destino y motivo del viaje.',
                'Se registran todos los que duermen en la habitación, no solo el que paga.',
                'El titular se captura en el mismo check-in, sin salir de la recepción. Si ya se alojó '
                    .'antes, se reusa su ficha en vez de duplicarla.',
                'Tarjeta de registro imprimible en tamaño carta, con la declaración de tratamiento de '
                    .'datos (Ley 1581 de 2012) y espacio para la firma.',
                'El reporte de huéspedes alojados tiene filtro de extranjeros, que es la base para el '
                    .'reporte a Migración Colombia.',
            ],
        ],
        [
            'icon' => '🧮',
            'title' => 'IVA y el turista extranjero',
            'lead' => 'El hospedaje en Colombia es gravado al 19%, pero el alojamiento a un turista '
                .'extranjero no residente es exento (artículo 481 del Estatuto Tributario).',
            'bullets' => [
                'La exención se marca en el check-in, con el motivo escrito: hay que poder sustentarla.',
                'Cubre el alojamiento, no los consumos: la cerveza del minibar se sigue gravando. '
                    .'Aplicarla a todo le dejaría de cobrar al huésped un IVA que el hotel sí tiene que '
                    .'declarar.',
                'La exención queda impresa en la cuenta y en la tarjeta de registro.',
            ],
        ],
        [
            'icon' => '📊',
            'title' => 'Reportes',
            'lead' => 'Las tres métricas de la hotelería, que no son el total facturado:',
            'bullets' => [
                'Ocupación: noches vendidas sobre noches disponibles. Cuánto del hotel se usó.',
                'ADR (tarifa media diaria): a cuánto se vendió lo que se vendió.',
                'RevPAR: ingreso por habitación disponible. Es la que de verdad manda, porque mezcla las '
                    .'dos: un hotel con tarifa altísima y 20% de ocupación gana menos que uno con tarifa '
                    .'media y 90%, y mirando solo la tarifa nadie lo nota.',
                'Todo día por día, con los consumos aparte del alojamiento, y exportable a Excel.',
                'Detalle por estadía para responder «¿y ese día de qué fue?».',
                'Reporte de huéspedes con las columnas del registro hotelero.',
            ],
        ],
        [
            'icon' => '❓',
            'title' => 'Preguntas frecuentes',
            'lead' => 'Lo que más se pregunta antes de activar el módulo:',
            'bullets' => [
                '¿Sirve para hostales, fincas o apartamentos? Sí. Lo que cambia es cómo se nombren las '
                    .'zonas y los tipos: una finca puede tener «Cabañas» como zona y «Cabaña familiar» '
                    .'como tipo.',
                '¿Puedo tener tarifas distintas por temporada? La tarifa vive en el tipo de habitación y '
                    .'se cambia ahí cuando entra temporada. Lo pactado con cada huésped queda sellado en '
                    .'su reserva y en su estadía, así que subir la lista no cambia lo prometido.',
                '¿El restaurante del hotel puede cargar al folio? Sí, cargando el producto o el servicio '
                    .'al folio de la habitación desde la recepción.',
                '¿Las facturas del hotel se ven en el cierre de caja? Sí, automáticamente: la factura queda '
                    .'enlazada al turno del cajero.',
                '¿Qué pasa si una habitación está en mantenimiento? Sale del inventario vendible, también '
                    .'para fechas futuras: no se puede reservar ni para dentro de un mes.',
                '¿Puedo cerrar una estadía sin cobrarla? Sí, con permiso de administrador y motivo escrito. '
                    .'No toca la caja, pero sí descarga el inventario de los consumos: la cerveza salió de '
                    .'la nevera aunque no se cobrara.',
            ],
        ],
    ];
@endphp

@include('docs.layout', [
    'titulo' => 'Hoteles',
    'resumen' => 'Habitaciones, reservas, huéspedes y la cuenta de cada estadía — todo conectado con '
        .'la contabilidad, el inventario, las cajas y la facturación electrónica DIAN.',
    'sections' => $sections,
])
