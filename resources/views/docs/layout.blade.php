{{--
    La guía pública de un módulo.

    Existe porque la del parqueadero y la del hotel eran la misma página con
    otro contenido: el mismo encabezado, la misma tabla de contenido, las
    mismas tarjetas y 200 líneas de CSS idénticas. Copiada, el día que haya que
    arreglar el salto de página al imprimir se arregla en una y la otra sigue
    saliendo mal — que es exactamente lo que pasó con los cuatro formatos
    térmicos.

    Lo que cambia por módulo son tres cosas, y entran como variables:

      $titulo     el nombre del módulo
      $resumen    la frase del encabezado
      $sections   [['icon','title','lead','bullets'=>[...]], ...]
--}}
@php
    $co = config('legal.company_name');
    $titulo = $titulo ?? 'Módulo';
    $resumen = $resumen ?? '';
    $sections = $sections ?? [];
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>Módulo {{ $titulo }} · {{ $co }}</title>
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
        <h1 class="pk-title">{{ $titulo }}</h1>
        <p class="pk-sub">{{ $resumen }}</p>
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
