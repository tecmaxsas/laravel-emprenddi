{{--
    Documentación pública del módulo Parqueadero.
    Página estática (sin auth) para que compradores y operarios puedan
    revisar capacidades y flujos antes/después de activar el módulo.
--}}
@php
    $co = config('legal.company_name');

    $sections = [
        [
            'icon' => '🚗',
            'title' => '¿Qué es el módulo Parqueadero?',
            'lead' => 'Un módulo opcional dentro de '.$co.' para administrar el ingreso, permanencia, cobro y reportes de un parqueadero (públicos, edificios, eventos, centros comerciales). Se conecta nativamente con la contabilidad, el POS, la facturación electrónica DIAN, las cajas y los turnos.',
            'bullets' => [
                'Soporta múltiples parqueaderos en una misma empresa (multi-sede).',
                'Motor de tarifas flexible: por minuto, por hora, por día, fija o escalonada.',
                'Soporta cortesía (minutos sin cobro) y tope diario.',
                'Mensualidades individuales y convenios corporativos.',
                'Asignación opcional de espacios físicos con mapa de ocupación.',
                'Foto de entrada y salida + registro de incidentes para reclamos.',
                'Facturación POS o electrónica DIAN automática al cobrar.',
            ],
        ],
        [
            'icon' => '🔓',
            'title' => 'Activación',
            'lead' => 'El módulo es opt-in. Lo activa el SuperAdmin de '.$co.' para una empresa específica una vez se ha contratado.',
            'bullets' => [
                'Una vez activo, el grupo "Parqueadero" aparece en el sidebar de la empresa.',
                'Los permisos parking.* se asignan a los roles desde Administración → Roles.',
                'Por defecto: el admin recibe todos los permisos; el cajero opera entrada/salida y puede anular.',
            ],
        ],
        [
            'icon' => '⚙️',
            'title' => 'Configuración inicial',
            'lead' => 'Tres pasos para dejar el módulo operativo:',
            'bullets' => [
                '1. Tipos de vehículo: Carro, Moto, Bicicleta, Camión (se siembran automáticamente al activar el módulo).',
                '2. Parqueadero(s): crea cada uno con su código, dirección, capacidad y sede de facturación. La sede determina qué resolución DIAN aplica al cobrar.',
                '3. Tarifas: al menos una por parqueadero. Pueden ser globales (cualquier vehículo) o específicas (solo motos, solo camiones).',
                '4. (Opcional) Espacios: crea slots individuales agrupados por zona para el mapa de ocupación.',
            ],
        ],
        [
            'icon' => '💰',
            'title' => 'Esquemas de tarifa',
            'lead' => 'El motor de cálculo soporta cinco esquemas combinables con minutos de cortesía y tope diario:',
            'bullets' => [
                'Por minuto (per_minute): el monto se multiplica por minutos cobrables.',
                'Por hora (per_hour): permite redondeo (ceil/floor/round/none). La opción "ceil" es la más usada en Colombia.',
                'Por día (per_day): cada día calendario suma monto fijo.',
                'Fija (flat): monto único por la estancia, sin importar tiempo.',
                'Escalonada (tiered): rangos por minutos con tipo y monto independientes (ej. primeros 15 min gratis, 16–60 min $3.000 flat, después $80/min).',
                'Cortesía: minutos iniciales gratis que aplican al inicio del cálculo (ej. los primeros 10 minutos no cobran).',
                'Tope diario: el cobro no excede X pesos por día, sin importar el tiempo.',
                'Ticket perdido: tarifa fija para clientes que pierden el ticket (configurable por tarifa o por defecto 24h × tarifa).',
            ],
        ],
        [
            'icon' => '🎟️',
            'title' => 'Operación diaria',
            'lead' => 'Dos pantallas dedicadas para el cajero de turno:',
            'bullets' => [
                'Entrada: el operario registra placa + tipo de vehículo (+ espacio opcional + foto opcional). El sistema detecta automáticamente si la placa tiene mensualidad activa y lo avisa con banner verde.',
                'Salida: busca por placa, ve el monto calculado en vivo con desglose por concepto (tiempo, cortesía, tope diario), elige medio de pago + cuenta + tipo de factura, sube foto de salida (opcional) y procesa.',
                'Cobro normal: emite factura POS o electrónica DIAN. La sesión queda enlazada a la factura y al turno de caja del operario.',
                'Ticket perdido: cobra con la tarifa especial y deja la sesión marcada como tal.',
                'Anulación: el operario con permiso parking.cancel puede anular una sesión con motivo auditable (errores de registro).',
            ],
        ],
        [
            'icon' => '🗺️',
            'title' => 'Mapa de ocupación',
            'lead' => 'Dashboard visual para conocer el estado en tiempo real de cada parqueadero:',
            'bullets' => [
                'KPIs: libres / ocupados / reservados / mantenimiento con porcentajes.',
                'Alerta de aforo: amarilla a partir del 75% de ocupación, roja a partir del 90%.',
                'Cuadrícula por zona: cada espacio es un tile coloreado por estado. Tooltip muestra la placa y la hora de entrada cuando está ocupado. Ícono ♿ marca espacios accesibles.',
                'Refresco manual para no saturar el servidor.',
            ],
        ],
        [
            'icon' => '📅',
            'title' => 'Mensualidades y convenios corporativos',
            'lead' => 'Soporta dos formatos en una sola tabla:',
            'bullets' => [
                'Individual: un cliente, una placa, una mensualidad con vigencia y monto.',
                'Corporativo: una empresa cliente con N placas autorizadas (lista mientras dura el convenio).',
                'Detección automática: al registrar entrada de una placa cubierta, el sistema lo avisa y no cobra al salir.',
                'Alertas de vencimiento: badge de "días restantes" en la tabla; filtro rápido "Vencen en ≤ 15 días".',
                'Auto-renovación: bandera opcional para que un cron procese renovaciones al vencer (próximamente).',
            ],
        ],
        [
            'icon' => '🧾',
            'title' => 'Facturación, cajas y turnos',
            'lead' => 'Toda la cadena contable y fiscal está integrada:',
            'bullets' => [
                'El cobro emite una SaleInvoice real con producto "Servicio de Parqueadero" (descripción enriquecida con placa + tiempo + horas).',
                'Selecciona POS o electrónica (DIAN) al momento del cobro. La resolución se toma de la sede de facturación del parqueadero.',
                'Si el producto tiene IVA, el sistema descompone base + impuesto y postea el asiento contable automáticamente.',
                'Requiere turno de caja abierto: si el cajero no tiene caja abierta, el sistema le pide hacerlo desde el POS antes de cobrar.',
                'La factura queda asociada al CashRegisterSession, por lo que el cierre de caja incluye los cobros de parqueadero en total_sales y en el desglose por medio de pago.',
            ],
        ],
        [
            'icon' => '📊',
            'title' => 'Reportes',
            'lead' => 'Dos reportes con exportación XLSX y vista imprimible PDF:',
            'bullets' => [
                'Sesiones por período: lista detallada (ticket, placa, parqueadero, tiempo, monto, estado, factura) con filtros por fechas, parqueadero y estado.',
                'Ingresos por día: agregado diario con KPIs (ingresos, sesiones, ticket promedio, tiempo vendido, ticket perdido, mensualidades).',
                'Excel: descarga inmediata con encabezados, subtítulo del rango y tipos de columna preservados.',
                'PDF: usa el diálogo de impresión del navegador (A4 landscape) sin instalar nada.',
            ],
        ],
        [
            'icon' => '📸',
            'title' => 'Fotos y registro de incidentes',
            'lead' => 'Para respaldar reclamos de daño y operación segura:',
            'bullets' => [
                'Foto opcional al entrar y al salir, con editor incorporado para recortar o ajustar.',
                'Las fotos se almacenan en el disco público de la empresa y quedan ligadas a la sesión.',
                'Módulo de Incidentes: tipo (daño, robo, accidente, conducta irregular, mantenimiento, otro), severidad (bajo/medio/alto/crítico), descripción, múltiples fotos de evidencia.',
                'Asociación opcional a una sesión (a veces los incidentes se descubren después de la salida).',
                'Bucle de resolución: resolved_at + resolved_notes + resolved_by para trazabilidad.',
                'Badge en el sidebar con conteo de incidentes abiertos en rojo.',
            ],
        ],
        [
            'icon' => '🇨🇴',
            'title' => 'Cumplimiento legal Colombia',
            'lead' => 'El módulo se diseñó pensando en las normas que aplican en Colombia:',
            'bullets' => [
                'Espacios accesibles (♿): cada espacio puede marcarse como accesible para cumplir con la cuota mínima de la Ley 361 de 1997 / NTC 6047.',
                'Factura electrónica DIAN: el cobro genera factura electrónica si el parqueadero está configurado en una sede con resolución vigente.',
                'Resolución POS: el cobrador puede emitir POS (no electrónica) según las reglas DIAN aplicables a su volumen y tope.',
                'Información exógena: los terceros con facturas de parqueadero quedan incluidos en los reportes de exógena estándar de '.$co.'.',
                'Auditoría: cada sesión, cobro, anulación y resolución de incidente queda con el usuario, fecha y motivo correspondientes.',
            ],
        ],
        [
            'icon' => '❓',
            'title' => 'Preguntas frecuentes',
            'lead' => 'Las dudas más comunes que recibimos al implementar el módulo:',
            'bullets' => [
                '¿Puedo tener tarifas distintas para motos y carros? Sí. Crea una tarifa por tipo de vehículo. El motor elige la más específica disponible.',
                '¿Qué pasa si el cliente pierde el ticket? Se cobra la tarifa de "ticket perdido" definida por tarifa o por defecto 24h × tarifa.',
                '¿Puedo poner el primer minuto / la primera hora gratis? Sí, con la cortesía configurable en cada tarifa.',
                '¿Funciona sin internet? La operación local sí (entrada/salida); la emisión DIAN requiere conexión. Si cae internet, el cobro POS funciona y la factura electrónica se puede emitir luego con notas internas.',
                '¿Puedo cobrar al cliente con mensualidad un parqueo adicional? Por ahora la mensualidad cubre 100%. Para cobros extra se debe crear una sesión nueva sin mensualidad.',
                '¿Las facturas de parqueadero se ven en el cierre de caja? Sí, automáticamente, porque la factura queda enlazada al turno del cajero.',
            ],
        ],
    ];
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>Módulo Parqueadero · {{ $co }}</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('logos/favicon_emprenddi.svg') }}">
    <link rel="icon" type="image/png" href="{{ asset('logos/favicon_emprenddi.png') }}">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap">
    <style>
        * { box-sizing:border-box; margin:0; padding:0; }
        body { font-family:'Inter',system-ui,sans-serif; background:#f1f5f9; color:#1e293b; line-height:1.65; }
        .pk-hero {
            background:linear-gradient(150deg,#0ea5e9,#6366f1 55%,#7c3aed);
            color:#fff; padding:60px 24px 70px; text-align:center; position:relative; overflow:hidden;
        }
        .pk-hero::after {
            content:""; position:absolute; width:340px; height:340px; border-radius:50%;
            background:radial-gradient(circle,rgba(255,255,255,.20),transparent 70%);
            top:-140px; right:-60px;
        }
        .pk-brand img { height:42px; width:auto; }
        .pk-eyebrow { font-size:.78rem; letter-spacing:.18em; text-transform:uppercase; opacity:.8; margin-top:12px; }
        .pk-title { font-size:clamp(1.9rem,5vw,2.7rem); font-weight:900; margin-top:8px; letter-spacing:-.02em; }
        .pk-sub { margin-top:14px; max-width:580px; margin-left:auto; margin-right:auto; font-size:1rem; opacity:.9; }
        .pk-cta {
            display:inline-block; margin-top:22px; padding:11px 22px; border-radius:10px;
            background:rgba(255,255,255,.18); color:#fff; text-decoration:none; font-weight:700;
            border:1px solid rgba(255,255,255,.28); backdrop-filter:blur(6px);
        }
        .pk-cta:hover { background:rgba(255,255,255,.28); }
        .pk-wrap { max-width:880px; margin:-38px auto 0; padding:0 20px 70px; position:relative; }

        .pk-toc {
            background:#fff; border-radius:14px; padding:22px 26px; margin-bottom:22px;
            box-shadow:0 14px 34px -22px rgba(30,27,75,.4);
        }
        .pk-toc h3 { font-size:.78rem; letter-spacing:.16em; text-transform:uppercase; color:#6366f1; font-weight:700; margin-bottom:10px; }
        .pk-toc ol { list-style:none; counter-reset:tocnum; columns:2; gap:12px; }
        .pk-toc li { counter-increment:tocnum; font-size:.9rem; padding:5px 0; }
        .pk-toc li::before { content:counter(tocnum) ". "; color:#6366f1; font-weight:800; }
        .pk-toc a { color:#1e293b; text-decoration:none; }
        .pk-toc a:hover { color:#6366f1; text-decoration:underline; }

        .pk-card {
            background:#fff; border-radius:16px; padding:30px 32px; margin-bottom:18px;
            box-shadow:0 14px 34px -22px rgba(30,27,75,.30); animation:pkUp .5s ease both;
        }
        .pk-card h2 {
            font-size:1.25rem; font-weight:800; color:#0f172a; margin-bottom:10px;
            display:flex; align-items:center; gap:12px;
        }
        .pk-card .icon { font-size:1.7rem; line-height:1; }
        .pk-lead { color:#475569; margin-bottom:14px; font-size:.97rem; }
        .pk-card ul { list-style:none; padding-left:0; }
        .pk-card ul li {
            position:relative; padding:6px 0 6px 24px; font-size:.92rem; color:#475569;
        }
        .pk-card ul li::before {
            content:"›"; position:absolute; left:6px; color:#6366f1; font-weight:800; font-size:1.1rem; top:5px;
        }

        .pk-foot {
            margin-top:30px; padding:20px; text-align:center;
            font-size:.85rem; color:#64748b;
        }
        .pk-foot a { color:#6366f1; text-decoration:none; font-weight:700; }
        .pk-foot a:hover { text-decoration:underline; }

        @keyframes pkUp { from { opacity:0; transform:translateY(14px); } to { opacity:1; transform:translateY(0); } }
        @media (max-width:640px) {
            .pk-card { padding:22px 20px; }
            .pk-toc ol { columns:1; }
        }
        @media print {
            body { background:#fff; }
            .pk-hero, .pk-cta, .pk-toc { display:none; }
            .pk-wrap { margin-top:0; max-width:100%; padding:0; }
            .pk-card { box-shadow:none; border:1px solid #e5e7eb; page-break-inside:avoid; margin-bottom:14px; }
        }
    </style>
</head>
<body>
    <header class="pk-hero">
        <div class="pk-brand">
            <img src="{{ asset('logos/logo_emprenddi_sin_fondo_blanco.png') }}" alt="{{ $co }}">
        </div>
        <div class="pk-eyebrow">Módulo opcional</div>
        <h1 class="pk-title">Parqueadero</h1>
        <p class="pk-sub">Administra tarifas, espacios, mensualidades, cobros y reportes — todo conectado con la contabilidad, el POS y la facturación electrónica DIAN.</p>
        <a href="javascript:window.print()" class="pk-cta">🖨️ Imprimir / Guardar PDF</a>
    </header>

    <div class="pk-wrap">
        <div class="pk-toc">
            <h3>Contenido</h3>
            <ol>
                @foreach ($sections as $i => $s)
                    <li><a href="#sec-{{ $i + 1 }}">{{ $s['title'] }}</a></li>
                @endforeach
            </ol>
        </div>

        @foreach ($sections as $i => $s)
            <article id="sec-{{ $i + 1 }}" class="pk-card" style="animation-delay:{{ $i * 25 }}ms;">
                <h2><span class="icon">{{ $s['icon'] }}</span> {{ $s['title'] }}</h2>
                <p class="pk-lead">{{ $s['lead'] }}</p>
                <ul>
                    @foreach ($s['bullets'] as $b)
                        <li>{{ $b }}</li>
                    @endforeach
                </ul>
            </article>
        @endforeach

        <div class="pk-foot">
            © {{ date('Y') }} {{ $co }} · Colombia ·
            <a href="javascript:history.back()">← Volver</a>
        </div>
    </div>
</body>
</html>
