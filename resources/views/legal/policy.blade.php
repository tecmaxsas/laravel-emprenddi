{{--
    Páginas legales públicas (términos y privacidad).
    NOTA PARA LA EMPRESA: este es un contenido base. Revisalo y ajustalo
    con tu asesor legal antes de publicarlo de forma definitiva.
--}}
@php
    $docs = [
        'terminos' => [
            'title' => 'Términos y Condiciones de Uso',
            'intro' => 'Estos términos regulan el acceso y uso de la plataforma Emprenddi. Al crear una cuenta o utilizar el servicio, aceptás estas condiciones.',
            'sections' => [
                ['Aceptación de los términos', 'Al registrarte y utilizar Emprenddi declarás que leíste, entendiste y aceptás estos Términos y Condiciones, así como la Política de Tratamiento de Datos Personales.'],
                ['Descripción del servicio', 'Emprenddi es una plataforma de gestión empresarial en modalidad SaaS que incluye contabilidad, facturación electrónica DIAN, inventario, punto de venta y nómina, entre otros módulos.'],
                ['Registro y cuenta', 'Sos responsable de la veracidad de los datos suministrados y de mantener la confidencialidad de tus credenciales de acceso. Cualquier actividad realizada con tu cuenta es de tu responsabilidad.'],
                ['Uso aceptable', 'Te comprometés a usar la plataforma conforme a la ley y a no realizar acciones que afecten su seguridad, disponibilidad o la información de otros usuarios.'],
                ['Planes, pagos y suscripción', 'El servicio se presta mediante suscripción. Los planes, precios, límites y el período de prueba se informan al momento de la contratación y pueden actualizarse con previo aviso.'],
                ['Facturación electrónica', 'La emisión y transmisión de documentos electrónicos a la DIAN depende de la correcta configuración por parte del cliente y de la disponibilidad de los servicios de la DIAN y del proveedor tecnológico autorizado.'],
                ['Propiedad de la información', 'La información que cargás en la plataforma es de tu propiedad. Emprenddi la procesa únicamente para prestar el servicio y podés exportarla cuando lo requieras.'],
                ['Disponibilidad del servicio', 'Procuramos la mayor disponibilidad posible; sin embargo, el servicio se presta «tal cual» y pueden existir ventanas de mantenimiento o interrupciones ajenas a nuestro control.'],
                ['Limitación de responsabilidad', 'En la medida permitida por la ley, Emprenddi no será responsable por daños indirectos derivados del uso o la imposibilidad de uso de la plataforma.'],
                ['Terminación', 'Cualquiera de las partes puede dar por terminada la relación. En caso de terminación conservás el derecho a exportar tu información durante un período razonable.'],
                ['Modificaciones', 'Podemos actualizar estos términos. Los cambios relevantes serán notificados a través de la plataforma o por correo electrónico.'],
                ['Ley aplicable', 'Estos Términos y Condiciones se rigen e interpretan conforme a las leyes de la República de Colombia.'],
            ],
        ],
        'privacidad' => [
            'title' => 'Política de Tratamiento de Datos Personales',
            'intro' => 'En Emprenddi protegemos tus datos personales conforme a la normativa colombiana de habeas data. Esta política describe cómo los recolectamos, usamos y protegemos.',
            'sections' => [
                ['Responsable del tratamiento', 'Emprenddi actúa como responsable del tratamiento de los datos personales recolectados a través de la plataforma. Para cualquier solicitud podés escribirnos a nuestro canal de contacto.'],
                ['Marco legal', 'El tratamiento de datos se realiza en cumplimiento de la Ley 1581 de 2012, el Decreto 1377 de 2013 y demás normas concordantes sobre protección de datos personales.'],
                ['Datos que recolectamos', 'Recolectamos datos de identificación y contacto, información de la empresa y la información operativa y contable que cargás voluntariamente para usar el servicio.'],
                ['Finalidad del tratamiento', 'Usamos los datos para prestar y mejorar el servicio, gestionar la facturación, brindar soporte, enviar comunicaciones del servicio y cumplir obligaciones legales.'],
                ['Derechos del titular', 'Como titular podés conocer, actualizar, rectificar y suprimir tus datos, así como revocar la autorización otorgada, en los términos de la ley.'],
                ['Seguridad de la información', 'Aplicamos medidas técnicas, humanas y administrativas razonables para proteger los datos contra acceso no autorizado, pérdida o alteración.'],
                ['Conservación', 'Conservamos los datos durante la vigencia de la relación contractual y los plazos exigidos por la normativa contable y tributaria aplicable.'],
                ['Encargados y terceros', 'Podemos apoyarnos en proveedores tecnológicos (alojamiento, transmisión a la DIAN, pagos) que actúan como encargados bajo acuerdos de confidencialidad.'],
                ['Cookies', 'Utilizamos cookies necesarias para el funcionamiento, la sesión y la seguridad de la plataforma.'],
                ['Cambios a esta política', 'Podemos actualizar esta política; las modificaciones se publicarán en esta misma página.'],
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
    <title>{{ $current['title'] }} · Emprenddi</title>
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
        .lg-num {
            color:#6366f1; font-weight:800; font-size:.85rem; flex-shrink:0;
        }
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
        <div class="lg-brand">✦ EMPRENDDI</div>
        <h1 class="lg-title">{{ $current['title'] }}</h1>
        <div class="lg-updated">Última actualización: mayo de 2026</div>
    </header>

    <div class="lg-wrap">
        <article class="lg-card">
            <p class="lg-intro">{{ $current['intro'] }}</p>

            @foreach ($current['sections'] as $i => $section)
                <section class="lg-sec" style="animation-delay:{{ $i * 45 }}ms;">
                    <h2><span class="lg-num">{{ $i + 1 }}.</span> {{ $section[0] }}</h2>
                    <p>{{ $section[1] }}</p>
                </section>
            @endforeach

            <div class="lg-foot">
                <span>© {{ date('Y') }} Emprenddi · Colombia</span>
                <a href="javascript:history.back()" class="lg-back">← Volver</a>
            </div>
        </article>
    </div>
</body>
</html>
