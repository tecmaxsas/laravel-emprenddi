{{--
    Catálogo público de productos.

    Página autónoma: no usa el bundle de Vite ni los estilos de Filament. Va con
    su CSS en línea porque el bundle solo se construye en el despliegue y una
    página que un cliente abre desde un WhatsApp no puede depender de eso.

    Todo el color y la tipografía salen de variables CSS que el tema llena, así
    que personalizar no toca ni una regla.
--}}
@php
    $logo = $catalogo->logoParaMostrar();
    $whatsapp = $catalogo->whatsappNormalizado();
    $empresa = $catalogo->company;
    $moneda = fn ($valor) => '$'.number_format((float) $valor, 0, ',', '.');
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $catalogo->name }} — {{ $empresa?->name }}</title>

    @if (! $catalogo->allow_indexing)
        <meta name="robots" content="noindex, nofollow">
    @endif

    <meta name="description" content="{{ $catalogo->subtitle ?: 'Catálogo de productos de '.$empresa?->name }}">
    <meta property="og:title" content="{{ $catalogo->name }}">
    <meta property="og:description" content="{{ $catalogo->subtitle ?: $empresa?->name }}">
    @if ($logo)
        <meta property="og:image" content="{{ asset('storage/'.$logo) }}">
    @endif

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family={{ str_replace(' ', '+', $tema['font_family']) }}:wght@400;500;600;700&display=swap" rel="stylesheet">

    <style>
        :root {
            --primary: {{ $tema['primary_color'] }};
            --accent: {{ $tema['accent_color'] }};
            --bg: {{ $tema['bg_color'] }};
            --card: {{ $tema['card_color'] }};
            --text: {{ $tema['text_color'] }};
            --muted: {{ $tema['muted_color'] }};
            --font: '{{ $tema['font_family'] }}', system-ui, -apple-system, sans-serif;
            --radius: {{ ['rounded' => '16px', 'soft' => '8px', 'square' => '0'][$tema['card_shape']] ?? '16px' }};
            --cols: {{ (int) $tema['columns'] }};
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            background: var(--bg);
            color: var(--text);
            font-family: var(--font);
            line-height: 1.5;
            -webkit-font-smoothing: antialiased;
        }

        a { color: inherit; }

        .contenedor { max-width: 1180px; margin: 0 auto; padding: 0 16px; }

        /* ------------------------------------------------------- cabecera */
        .cabecera {
            padding: 40px 0 32px;
            color: #fff;
            text-align: center;
            @if ($tema['header_style'] === 'image' && $catalogo->header_image_path)
                background-image: linear-gradient(rgba(0,0,0,.55), rgba(0,0,0,.55)), url('{{ asset('storage/'.$catalogo->header_image_path) }}');
                background-size: cover;
                background-position: center;
            @elseif ($tema['header_style'] === 'gradient')
                background: linear-gradient(135deg, var(--primary) 0%, var(--accent) 100%);
            @else
                background: var(--primary);
            @endif
        }

        .cabecera img.logo {
            max-height: 84px;
            max-width: 240px;
            margin-bottom: 16px;
            border-radius: 10px;
            background: rgba(255, 255, 255, .92);
            padding: 8px 12px;
        }

        .cabecera h1 { margin: 0; font-size: clamp(1.6rem, 4vw, 2.4rem); font-weight: 700; }
        .cabecera p { margin: 8px auto 0; max-width: 620px; opacity: .92; font-size: 1rem; }

        .cabecera .datos {
            margin-top: 18px;
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 8px 20px;
            font-size: .9rem;
            opacity: .92;
        }

        /* --------------------------------------------------------- filtros */
        .barra {
            position: sticky;
            top: 0;
            z-index: 10;
            background: var(--bg);
            padding: 16px 0 12px;
            border-bottom: 1px solid rgba(0, 0, 0, .07);
        }

        .buscador { display: flex; gap: 8px; }

        .buscador input {
            flex: 1;
            padding: 11px 14px;
            border: 1px solid rgba(0, 0, 0, .15);
            border-radius: 999px;
            font: inherit;
            font-size: .95rem;
            background: var(--card);
            color: var(--text);
        }

        .buscador input:focus { outline: 2px solid var(--accent); outline-offset: 1px; }

        .buscador button {
            padding: 11px 22px;
            border: 0;
            border-radius: 999px;
            background: var(--accent);
            color: #fff;
            font: inherit;
            font-weight: 600;
            cursor: pointer;
        }

        .categorias {
            margin-top: 12px;
            display: flex;
            gap: 8px;
            overflow-x: auto;
            padding-bottom: 4px;
            scrollbar-width: thin;
        }

        .categorias a {
            flex: 0 0 auto;
            padding: 7px 15px;
            border-radius: 999px;
            border: 1px solid rgba(0, 0, 0, .13);
            background: var(--card);
            color: var(--muted);
            text-decoration: none;
            font-size: .87rem;
            white-space: nowrap;
        }

        .categorias a.activa {
            background: var(--primary);
            border-color: var(--primary);
            color: #fff;
            font-weight: 600;
        }

        .conteo { margin: 18px 0 4px; color: var(--muted); font-size: .88rem; }

        /* -------------------------------------------------------- productos */
        .grilla {
            display: grid;
            gap: 18px;
            padding: 10px 0 40px;
            grid-template-columns: repeat(var(--cols), minmax(0, 1fr));
        }

        @media (max-width: 900px) { .grilla { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        @media (max-width: 520px) { .grilla { grid-template-columns: 1fr; } }

        .lista { display: flex; flex-direction: column; gap: 12px; padding: 10px 0 40px; }

        .tarjeta {
            background: var(--card);
            border-radius: var(--radius);
            overflow: hidden;
            border: 1px solid rgba(0, 0, 0, .07);
            display: flex;
            flex-direction: column;
        }

        .lista .tarjeta { flex-direction: row; align-items: stretch; }

        .foto {
            aspect-ratio: 1 / 1;
            width: 100%;
            object-fit: cover;
            display: block;
            background: rgba(0, 0, 0, .04);
        }

        .lista .foto { width: 110px; aspect-ratio: 1 / 1; flex: 0 0 110px; }

        .sin-foto {
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--muted);
            font-size: 2.6rem;
            font-weight: 700;
            opacity: .28;
            letter-spacing: .02em;
        }

        .lista .sin-foto { font-size: 1.6rem; }

        .cuerpo { padding: 14px 16px 16px; display: flex; flex-direction: column; gap: 6px; flex: 1; }

        .nombre { font-weight: 600; font-size: 1rem; margin: 0; }
        .marca { color: var(--muted); font-size: .8rem; text-transform: uppercase; letter-spacing: .03em; }
        .descripcion { color: var(--muted); font-size: .88rem; margin: 0; }
        .codigo { color: var(--muted); font-size: .78rem; font-family: ui-monospace, monospace; }
        .variantes { color: var(--muted); font-size: .8rem; }

        .precio { font-size: 1.15rem; font-weight: 700; color: var(--primary); margin-top: auto; padding-top: 6px; }
        .precio small { font-weight: 400; font-size: .75rem; color: var(--muted); }

        .etiqueta {
            display: inline-block;
            padding: 2px 9px;
            border-radius: 999px;
            font-size: .72rem;
            font-weight: 600;
        }

        .disponible { background: rgba(22, 163, 74, .12); color: #15803d; }
        .agotado { background: rgba(220, 38, 38, .1); color: #b91c1c; }

        .pedir {
            margin-top: 10px;
            display: inline-block;
            text-align: center;
            padding: 9px 14px;
            border-radius: 999px;
            background: var(--accent);
            color: #fff;
            text-decoration: none;
            font-size: .87rem;
            font-weight: 600;
        }

        /* ---------------------------------------------------------- varios */
        .vacio { text-align: center; padding: 60px 20px; color: var(--muted); }
        .vacio strong { display: block; font-size: 1.1rem; color: var(--text); margin-bottom: 6px; }

        .paginacion { display: flex; justify-content: center; gap: 8px; padding: 10px 0 40px; flex-wrap: wrap; }

        .paginacion a, .paginacion span {
            padding: 8px 14px;
            border-radius: 8px;
            border: 1px solid rgba(0, 0, 0, .13);
            background: var(--card);
            text-decoration: none;
            font-size: .88rem;
        }

        .paginacion .actual { background: var(--primary); border-color: var(--primary); color: #fff; font-weight: 600; }

        .pie {
            border-top: 1px solid rgba(0, 0, 0, .07);
            padding: 26px 0 40px;
            text-align: center;
            color: var(--muted);
            font-size: .85rem;
        }

        .pie a { color: var(--accent); }

        .flotante {
            position: fixed;
            right: 18px;
            bottom: 18px;
            z-index: 20;
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 13px 20px;
            border-radius: 999px;
            background: #25d366;
            color: #fff;
            text-decoration: none;
            font-weight: 600;
            font-size: .92rem;
            box-shadow: 0 6px 20px rgba(0, 0, 0, .22);
        }
    </style>
</head>
<body>

<header class="cabecera">
    <div class="contenedor">
        @if ($logo)
            <img src="{{ asset('storage/'.$logo) }}" alt="{{ $empresa?->name }}" class="logo">
        @endif

        <h1>{{ $catalogo->name }}</h1>

        @if ($catalogo->subtitle)
            <p>{{ $catalogo->subtitle }}</p>
        @endif

        <div class="datos">
            @if ($telefono = ($catalogo->contact_phone ?: $empresa?->phone))
                <span>{{ $telefono }}</span>
            @endif
            @if ($correo = ($catalogo->contact_email ?: $empresa?->email))
                <span>{{ $correo }}</span>
            @endif
            @if ($empresa?->address)
                <span>{{ $empresa->address }}</span>
            @endif
        </div>
    </div>
</header>

<div class="barra">
    <div class="contenedor">
        <form class="buscador" method="GET" action="{{ route('catalog.public', $catalogo->slug) }}">
            <input type="search"
                   name="q"
                   value="{{ $busqueda }}"
                   placeholder="Buscar por nombre, código o marca…"
                   aria-label="Buscar productos">
            @if ($categoriaActiva)
                <input type="hidden" name="categoria" value="{{ $categoriaActiva }}">
            @endif
            <button type="submit">Buscar</button>
        </form>

        @if ($categorias->isNotEmpty())
            <nav class="categorias" aria-label="Categorías">
                <a href="{{ route('catalog.public', ['slug' => $catalogo->slug, 'q' => $busqueda]) }}"
                   class="{{ $categoriaActiva ? '' : 'activa' }}">Todos</a>

                @foreach ($categorias as $categoria)
                    <a href="{{ route('catalog.public', ['slug' => $catalogo->slug, 'categoria' => $categoria->id, 'q' => $busqueda]) }}"
                       class="{{ $categoriaActiva === $categoria->id ? 'activa' : '' }}">{{ $categoria->name }}</a>
                @endforeach
            </nav>
        @endif
    </div>
</div>

<main class="contenedor">
    @if ($productos->total() > 0)
        <p class="conteo">
            {{ $productos->total() }} {{ $productos->total() === 1 ? 'producto' : 'productos' }}
            @if ($busqueda) para «{{ $busqueda }}» @endif
        </p>
    @endif

    @if ($productos->isEmpty())
        <div class="vacio">
            <strong>No encontramos productos</strong>
            @if ($busqueda || $categoriaActiva)
                Prueba con otra búsqueda o mira todas las categorías.
            @else
                Este catálogo todavía no tiene productos publicados.
            @endif
        </div>
    @else
        <div class="{{ $tema['layout'] === 'list' ? 'lista' : 'grilla' }}">
            @foreach ($productos as $producto)
                @php
                    $precio = (float) $producto->default_sale_price;
                    $preciosVariantes = $producto->variants
                        ->pluck('default_sale_price')
                        ->map(fn ($p) => (float) $p)
                        ->filter()
                        ->values();
                    $saldo = $existencias[$producto->id] ?? null;
                    $mensaje = rawurlencode('Hola, me interesa: '.$producto->name);
                @endphp

                <article class="tarjeta">
                    @if ($tema['show_images'])
                        @if ($producto->image_path)
                            <img class="foto"
                                 src="{{ asset('storage/'.$producto->image_path) }}"
                                 alt="{{ $producto->name }}"
                                 loading="lazy">
                        @else
                            {{-- Sin foto: la inicial del producto se ve
                                 intencional; un recuadro vacío, roto. --}}
                            <div class="foto sin-foto" aria-hidden="true">{{ mb_strtoupper(mb_substr($producto->name, 0, 1)) }}</div>
                        @endif
                    @endif

                    <div class="cuerpo">
                        @if ($producto->brand)
                            <span class="marca">{{ $producto->brand }}</span>
                        @endif

                        <h2 class="nombre">{{ $producto->name }}</h2>

                        @if ($catalogo->show_codes)
                            <span class="codigo">{{ $producto->code }}</span>
                        @endif

                        @if ($tema['show_descriptions'] && $producto->description)
                            <p class="descripcion">{{ \Illuminate\Support\Str::limit($producto->description, 130) }}</p>
                        @endif

                        @if ($producto->variants->isNotEmpty())
                            @php
                                $cuantas = $producto->variants->count();
                                $nombres = $producto->variants->take(4)->pluck('name')->implode(', ')
                                    .($cuantas > 4 ? '…' : '');
                            @endphp
                            <span class="variantes">{{ $cuantas }} {{ $cuantas === 1 ? 'presentación' : 'presentaciones' }}: {{ $nombres }}</span>
                        @endif

                        @if ($catalogo->show_stock && $producto->track_inventory)
                            <span class="etiqueta {{ ($saldo ?? 0) > 0 ? 'disponible' : 'agotado' }}">
                                {{ ($saldo ?? 0) > 0 ? 'Disponible' : 'Agotado' }}
                            </span>
                        @endif

                        @if ($catalogo->show_prices)
                            <div class="precio">
                                @if ($preciosVariantes->count() > 1 && $preciosVariantes->min() != $preciosVariantes->max())
                                    {{ $moneda($preciosVariantes->min()) }} – {{ $moneda($preciosVariantes->max()) }}
                                @elseif ($precio > 0)
                                    {{ $moneda($precio) }}
                                    @if ($producto->sale_price_includes_tax)
                                        <small>IVA incluido</small>
                                    @endif
                                @elseif ($preciosVariantes->isNotEmpty())
                                    {{ $moneda($preciosVariantes->min()) }}
                                @else
                                    <small>Consultar precio</small>
                                @endif
                            </div>
                        @endif

                        @if ($whatsapp)
                            <a class="pedir"
                               href="https://wa.me/{{ $whatsapp }}?text={{ $mensaje }}"
                               target="_blank"
                               rel="noopener">Preguntar por este producto</a>
                        @endif
                    </div>
                </article>
            @endforeach
        </div>

        @if ($productos->hasPages())
            <nav class="paginacion" aria-label="Páginas">
                @if ($productos->onFirstPage())
                    <span>Anterior</span>
                @else
                    <a href="{{ $productos->previousPageUrl() }}" rel="prev">Anterior</a>
                @endif

                <span class="actual">{{ $productos->currentPage() }} de {{ $productos->lastPage() }}</span>

                @if ($productos->hasMorePages())
                    <a href="{{ $productos->nextPageUrl() }}" rel="next">Siguiente</a>
                @else
                    <span>Siguiente</span>
                @endif
            </nav>
        @endif
    @endif
</main>

<footer class="pie">
    <div class="contenedor">
        @if ($catalogo->footer_text)
            <p>{{ $catalogo->footer_text }}</p>
        @endif
        <p>{{ $empresa?->name }} · Precios sujetos a cambio sin previo aviso.</p>
    </div>
</footer>

@if ($whatsapp)
    <a class="flotante"
       href="https://wa.me/{{ $whatsapp }}?text={{ rawurlencode('Hola, vi su catálogo y quiero hacer un pedido.') }}"
       target="_blank"
       rel="noopener">Escríbenos</a>
@endif

</body>
</html>
