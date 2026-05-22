{{--
    Páginas legales públicas (términos y privacidad).
    NOTA PARA LA EMPRESA: este es un contenido base, redactado como punto
    de partida. DEBE ser revisado y ajustado por un asesor jurídico antes
    de su publicación definitiva. Los datos de la empresa se configuran en
    config/legal.php (o variables de entorno LEGAL_*).
--}}
@php
    $co = config('legal.company_name');
    $legal = config('legal.legal_name');
    $nit = config('legal.nit');
    $email = config('legal.contact_email');
    $city = config('legal.jurisdiction_city');
    $identifier = $legal.($nit ? ', NIT '.$nit : '');

    $docs = [
        'terminos' => [
            'title' => 'Términos y Condiciones de Uso',
            'intro' => 'Estos Términos y Condiciones regulan el acceso y uso de la plataforma '.$co.'. Al crear una cuenta o utilizar el servicio, el usuario declara haberlos leído y aceptado.',
            'sections' => [
                ['Aceptación de los términos', 'Al registrarte y utilizar '.$co.' aceptás de forma íntegra estos Términos y Condiciones y la Política de Tratamiento de Datos Personales. Si no estás de acuerdo, debés abstenerte de usar el servicio.'],
                ['Definiciones', '«Plataforma» o «Servicio» se refiere a '.$co.'; «Cliente» o «Usuario» a la persona natural o jurídica que contrata o utiliza el servicio; «Cuenta» al registro de acceso individual.'],
                ['Descripción del servicio', $co.' es una plataforma de gestión empresarial en modalidad de software como servicio (SaaS) que incluye contabilidad, facturación electrónica DIAN, inventario, punto de venta, nómina y módulos complementarios.'],
                ['Registro y cuenta de usuario', 'Para usar el servicio debés crear una cuenta con información veraz, completa y actualizada. Sos responsable de la exactitud de los datos suministrados.'],
                ['Seguridad de las credenciales', 'Sos responsable de mantener la confidencialidad de tus credenciales de acceso y de toda actividad realizada con tu cuenta. Debés notificarnos de inmediato cualquier uso no autorizado.'],
                ['Uso aceptable', 'Te comprometés a utilizar la plataforma conforme a la ley y a no realizar acciones que afecten su seguridad, disponibilidad, integridad o la información de otros usuarios, ni a usarla para fines fraudulentos o ilícitos.'],
                ['Información del cliente', 'La información contable, comercial y operativa que cargás es de tu exclusiva propiedad y responsabilidad. '.$co.' la procesa únicamente para prestar el servicio y no la utiliza para fines distintos.'],
                ['Planes, pagos y renovación', 'El servicio se presta mediante suscripción. Los planes, precios, límites y condiciones de renovación se informan al momento de la contratación y pueden actualizarse con previo aviso razonable.'],
                ['Período de prueba', 'Si se ofrece un período de prueba, al finalizar el mismo el acceso continuo al servicio queda sujeto a la contratación de un plan.'],
                ['Facturación electrónica y DIAN', 'La emisión y transmisión de documentos electrónicos a la DIAN depende de la correcta configuración por parte del cliente y de la disponibilidad de los servicios de la DIAN y del proveedor tecnológico autorizado. '.$co.' no es responsable por interrupciones ajenas a su control.'],
                ['Propiedad intelectual', 'La plataforma, su software, marcas, diseño y contenidos son propiedad de '.$co.' o de sus licenciantes. El uso del servicio no transfiere ningún derecho de propiedad intelectual al cliente.'],
                ['Disponibilidad y soporte', 'Procuramos la mayor disponibilidad posible; sin embargo, el servicio se presta «tal cual» y pueden existir ventanas de mantenimiento o interrupciones. El alcance del soporte depende del plan contratado.'],
                ['Suspensión y terminación', 'Podemos suspender o terminar el acceso ante incumplimientos de estos términos o falta de pago. Cualquiera de las partes puede terminar la relación; conservás el derecho a exportar tu información durante un período razonable.'],
                ['Limitación de responsabilidad', 'En la medida permitida por la ley, '.$co.' no será responsable por daños indirectos, lucro cesante o pérdida de datos derivados del uso o la imposibilidad de uso de la plataforma.'],
                ['Indemnidad', 'El cliente se obliga a mantener indemne a '.$co.' frente a reclamaciones de terceros derivadas del uso indebido del servicio o del incumplimiento de estos términos.'],
                ['Modificaciones', 'Podemos actualizar estos Términos y Condiciones. Los cambios relevantes serán notificados a través de la plataforma o por correo electrónico y regirán desde su publicación.'],
                ['Ley aplicable y controversias', 'Estos términos se rigen por las leyes de la República de Colombia. Las controversias se resolverán ante los jueces competentes de '.$city.', sin perjuicio de los mecanismos de solución amistosa.'],
                ['Contacto', 'Para dudas sobre estos términos podés escribirnos a '.$email.'.'],
            ],
        ],
        'privacidad' => [
            'title' => 'Política de Tratamiento de Datos Personales',
            'intro' => 'En '.$co.' protegemos los datos personales conforme a la normativa colombiana de habeas data. Esta política describe cómo se recolectan, usan, almacenan y protegen.',
            'sections' => [
                ['Responsable del tratamiento', $identifier.', con domicilio en '.config('legal.contact_address').', actúa como responsable del tratamiento de los datos personales recolectados a través de la plataforma.'],
                ['Marco legal', 'El tratamiento de datos se realiza en cumplimiento de la Ley 1581 de 2012, el Decreto 1377 de 2013 y demás normas concordantes sobre protección de datos personales en Colombia.'],
                ['Autorización del titular', 'Al registrarte y usar la plataforma autorizás de manera previa, expresa e informada el tratamiento de tus datos personales para las finalidades descritas en esta política.'],
                ['Datos que recolectamos', 'Recolectamos datos de identificación y contacto, información de la empresa, datos de facturación y la información operativa y contable que cargás voluntariamente para usar el servicio.'],
                ['Finalidades del tratamiento', 'Usamos los datos para prestar y mejorar el servicio, gestionar la facturación y la suscripción, brindar soporte, enviar comunicaciones del servicio y cumplir obligaciones legales y tributarias.'],
                ['Derechos del titular', 'Como titular podés conocer, actualizar, rectificar y suprimir tus datos, solicitar prueba de la autorización, ser informado del uso dado a tus datos, presentar quejas y revocar la autorización cuando proceda.'],
                ['Cómo ejercer tus derechos', 'Podés ejercer tus derechos enviando una solicitud a '.$email.'. La atenderemos en los plazos previstos por la ley.'],
                ['Encargados y transmisión de datos', 'Podemos apoyarnos en proveedores tecnológicos (alojamiento, transmisión a la DIAN, pasarelas de pago, correo) que actúan como encargados del tratamiento bajo acuerdos de confidencialidad y seguridad.'],
                ['Seguridad de la información', 'Aplicamos medidas técnicas, humanas y administrativas razonables para proteger los datos contra acceso no autorizado, pérdida, alteración o divulgación.'],
                ['Conservación de los datos', 'Conservamos los datos durante la vigencia de la relación contractual y los plazos exigidos por la normativa contable, tributaria y de protección de datos aplicable.'],
                ['Datos de menores de edad', 'La plataforma está dirigida a empresas y personas mayores de edad. No recolectamos de forma consciente datos de menores sin la autorización de sus representantes legales.'],
                ['Cookies', 'Utilizamos cookies necesarias para el funcionamiento, la sesión y la seguridad de la plataforma. Podés gestionarlas desde la configuración de tu navegador.'],
                ['Autoridad de control', 'La Superintendencia de Industria y Comercio (SIC) es la autoridad de control en materia de protección de datos personales en Colombia.'],
                ['Vigencia y cambios', 'Esta política rige desde su publicación. Podemos actualizarla; las modificaciones se publicarán en esta misma página.'],
                ['Contacto', 'Para solicitudes relacionadas con tus datos personales, escribinos a '.$email.'.'],
            ],
        ],
    ];
    $current = $docs[$doc] ?? $docs['terminos'];
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $current['title'] }} · {{ $co }}</title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">
    <style>
        * { box-sizing:border-box; margin:0; padding:0; }
        body { font-family:'Inter',system-ui,sans-serif; background:#f1f5f9; color:#1e293b; line-height:1.65; }
        .lg-hero {
            background:linear-gradient(150deg,#4f46e5,#7c3aed 55%,#2563eb);
            color:#fff; padding:54px 24px 64px; text-align:center; position:relative; overflow:hidden;
        }
        .lg-hero::after {
            content:""; position:absolute; width:300px; height:300px; border-radius:50%;
            background:radial-gradient(circle,rgba(255,255,255,.18),transparent 70%);
            top:-120px; right:-60px;
        }
        .lg-brand { font-size:.95rem; font-weight:800; letter-spacing:.02em; opacity:.85; }
        .lg-title { font-size:clamp(1.6rem,4vw,2.3rem); font-weight:800; margin-top:10px; letter-spacing:-.02em; }
        .lg-updated { font-size:.8rem; opacity:.7; margin-top:10px; }
        .lg-wrap { max-width:760px; margin:-34px auto 0; padding:0 20px 70px; position:relative; }
        .lg-card {
            background:#fff; border-radius:16px; padding:36px 38px;
            box-shadow:0 20px 50px -24px rgba(30,27,75,.4); animation:lgUp .5s ease both;
        }
        .lg-intro { font-size:1rem; color:#475569; margin-bottom:26px; padding-bottom:22px; border-bottom:1px solid #eef2f7; }
        .lg-sec { margin-bottom:22px; animation:lgUp .5s ease both; }
        .lg-sec h2 {
            font-size:1.02rem; font-weight:700; color:#0f172a; margin-bottom:6px;
            display:flex; gap:9px; align-items:baseline;
        }
        .lg-num { color:#6366f1; font-weight:800; font-size:.85rem; flex-shrink:0; }
        .lg-sec p { font-size:.92rem; color:#475569; padding-left:26px; }
        .lg-foot {
            margin-top:30px; padding-top:22px; border-top:1px solid #eef2f7;
            font-size:.82rem; color:#94a3b8; display:flex; justify-content:space-between;
            flex-wrap:wrap; gap:10px;
        }
        .lg-back { color:#6366f1; text-decoration:none; font-weight:700; }
        .lg-back:hover { text-decoration:underline; }
        @keyframes lgUp { from { opacity:0; transform:translateY(14px); } to { opacity:1; transform:translateY(0); } }
        @media (max-width:560px) { .lg-card { padding:26px 22px; } }
    </style>
</head>
<body>
    <header class="lg-hero">
        <div class="lg-brand">✦ {{ mb_strtoupper($co) }}</div>
        <h1 class="lg-title">{{ $current['title'] }}</h1>
        <div class="lg-updated">Última actualización: {{ config('legal.updated_at') }}</div>
    </header>

    <div class="lg-wrap">
        <article class="lg-card">
            <p class="lg-intro">{{ $current['intro'] }}</p>

            @foreach ($current['sections'] as $i => $section)
                <section class="lg-sec" style="animation-delay:{{ $i * 35 }}ms;">
                    <h2><span class="lg-num">{{ $i + 1 }}.</span> {{ $section[0] }}</h2>
                    <p>{{ $section[1] }}</p>
                </section>
            @endforeach

            <div class="lg-foot">
                <span>© {{ date('Y') }} {{ $co }} · Colombia</span>
                <a href="javascript:history.back()" class="lg-back">← Volver</a>
            </div>
        </article>
    </div>
</body>
</html>
