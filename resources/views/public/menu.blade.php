<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $menu->name }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family={{ str_replace(' ', '+', $theme['font_family']) }}:wght@400;600;700&display=swap" rel="stylesheet">

    <style>
        :root {
            --primary: {{ $theme['primary_color'] }};
            --accent: {{ $theme['accent_color'] }};
            --bg: {{ $theme['bg_color'] }};
            --text: {{ $theme['text_color'] }};
            --muted: {{ $theme['muted_color'] }};
            --font: '{{ $theme['font_family'] }}', system-ui, sans-serif;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: var(--font);
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            line-height: 1.5;
            -webkit-font-smoothing: antialiased;
        }

        .container { max-width: 720px; margin: 0 auto; }

        /* HEADER */
        .header {
            position: relative;
            text-align: center;
            padding: 60px 20px 40px;
            color: white;
            overflow: hidden;
        }
        .header-bg {
            position: absolute;
            inset: 0;
            z-index: 0;
        }

        @if ($theme['header_style'] === 'image' && $menu->header_image_path)
            .header-bg {
                background-image: linear-gradient(rgba(0,0,0,0.5), rgba(0,0,0,0.5)), url('{{ asset('storage/'.$menu->header_image_path) }}');
                background-size: cover;
                background-position: center;
            }
        @elseif ($theme['header_style'] === 'gradient')
            .header-bg {
                background: linear-gradient(135deg, var(--primary) 0%, var(--accent) 100%);
            }
        @else
            .header-bg { background: var(--primary); }
        @endif

        .header-content { position: relative; z-index: 1; }

        .logo {
            width: 110px;
            height: 110px;
            border-radius: 50%;
            background: white;
            padding: 10px;
            margin: 0 auto 16px;
            object-fit: contain;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }

        .menu-title {
            font-size: 38px;
            font-weight: 700;
            margin-bottom: 8px;
            text-shadow: 0 2px 8px rgba(0,0,0,0.3);
        }

        .menu-subtitle {
            font-size: 16px;
            opacity: 0.95;
            font-weight: 400;
        }

        /* SECTIONS */
        .sections { padding: 40px 20px 80px; }

        .section { margin-bottom: 50px; }

        .section-header {
            text-align: center;
            margin-bottom: 24px;
            position: relative;
        }

        .section-name {
            font-size: 28px;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 6px;
            display: inline-block;
            padding: 0 16px;
            background: var(--bg);
            position: relative;
            z-index: 1;
        }

        .section-header::before {
            content: '';
            position: absolute;
            left: 0; right: 0;
            top: 18px;
            height: 1px;
            background: var(--primary);
            opacity: 0.2;
            z-index: 0;
        }

        .section-description {
            font-size: 14px;
            color: var(--muted);
            margin-top: 8px;
            max-width: 480px;
            margin-left: auto; margin-right: auto;
            font-style: italic;
        }

        /* ITEMS — LAYOUT LIST */
        .items.list { display: flex; flex-direction: column; gap: 16px; }

        .item-list {
            display: flex;
            gap: 16px;
            padding: 14px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        }

        .item-list .image-small,
        .item-list .image-medium,
        .item-list .image-large {
            flex-shrink: 0;
            background-size: cover;
            background-position: center;
            border-radius: 8px;
        }

        .item-list .image-small { width: 72px; height: 72px; }
        .item-list .image-medium { width: 100px; height: 100px; }
        .item-list .image-large { width: 130px; height: 130px; }

        .item-list .info { flex: 1; min-width: 0; }

        /* ITEMS — LAYOUT GRID */
        .items.grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
            gap: 16px;
        }

        .item-grid {
            background: white;
            border-radius: 14px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.07);
            display: flex;
            flex-direction: column;
        }

        .item-grid .image-small { height: 100px; }
        .item-grid .image-medium { height: 140px; }
        .item-grid .image-large { height: 180px; }

        .item-grid .image-small,
        .item-grid .image-medium,
        .item-grid .image-large {
            background-size: cover;
            background-position: center;
        }

        .item-grid .info { padding: 14px; }

        /* SHARED ITEM STYLES */
        .item-head {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            gap: 12px;
            margin-bottom: 6px;
        }

        .item-name {
            font-size: 16px;
            font-weight: 700;
            color: var(--text);
            flex: 1;
            min-width: 0;
        }

        .item-price {
            font-size: 16px;
            font-weight: 700;
            color: var(--primary);
            white-space: nowrap;
        }

        .item-desc {
            font-size: 13px;
            color: var(--muted);
            line-height: 1.5;
            margin-bottom: 6px;
        }

        .item-tags {
            display: flex;
            flex-wrap: wrap;
            gap: 4px;
            margin-top: 6px;
        }

        .item-tag {
            font-size: 10px;
            font-weight: 700;
            padding: 2px 8px;
            border-radius: 999px;
            background: var(--accent);
            color: var(--text);
        }

        /* FOOTER */
        .footer {
            text-align: center;
            padding: 30px 20px 50px;
            color: var(--muted);
            font-size: 13px;
        }
        .footer a { color: var(--primary); text-decoration: none; }

        .empty {
            text-align: center;
            padding: 40px 20px;
            color: var(--muted);
            font-style: italic;
        }
    </style>
</head>
<body>
    <div class="container">
        {{-- HEADER --}}
        <div class="header">
            <div class="header-bg"></div>
            <div class="header-content">
                @if ($menu->logo_path)
                    <img src="{{ asset('storage/'.$menu->logo_path) }}" alt="Logo" class="logo">
                @endif
                <div class="menu-title">{{ $menu->name }}</div>
                @if ($menu->subtitle)
                    <div class="menu-subtitle">{{ $menu->subtitle }}</div>
                @endif
            </div>
        </div>

        {{-- SECTIONS --}}
        <div class="sections">
            @forelse ($menu->sections as $section)
                <div class="section">
                    <div class="section-header">
                        <div class="section-name">{{ $section->name }}</div>
                        @if ($section->description)
                            <div class="section-description">{{ $section->description }}</div>
                        @endif
                    </div>

                    @if ($section->items->isEmpty())
                        <div class="empty">— Sin items —</div>
                    @else
                        @php $imgSize = $theme['item_image_size']; @endphp
                        <div class="items {{ $theme['layout'] }}">
                            @foreach ($section->items as $item)
                                @php
                                    $hasImg = $theme['show_images'] && $item->image_path;
                                    $imgStyle = $hasImg
                                        ? 'background-image: url(\''.asset('storage/'.$item->image_path).'\');'
                                        : '';
                                @endphp
                                @if ($theme['layout'] === 'grid')
                                    <div class="item-grid">
                                        @if ($hasImg)
                                            <div class="image-{{ $imgSize }}" style="{{ $imgStyle }}"></div>
                                        @endif
                                        <div class="info">
                                            <div class="item-head">
                                                <div class="item-name">{{ $item->name }}</div>
                                                @if ($menu->show_prices)
                                                    <div class="item-price">${{ number_format((float) $item->price, 0, ',', '.') }}</div>
                                                @endif
                                            </div>
                                            @if ($theme['show_descriptions'] && $item->description)
                                                <div class="item-desc">{{ $item->description }}</div>
                                            @endif
                                            @if (! empty($item->tags))
                                                <div class="item-tags">
                                                    @foreach ($item->tags as $tag)
                                                        <span class="item-tag">{{ \App\Models\Restaurant\MenuItem::COMMON_TAGS[$tag] ?? $tag }}</span>
                                                    @endforeach
                                                </div>
                                            @endif
                                        </div>
                                    </div>
                                @else
                                    <div class="item-list">
                                        @if ($hasImg)
                                            <div class="image-{{ $imgSize }}" style="{{ $imgStyle }}"></div>
                                        @endif
                                        <div class="info">
                                            <div class="item-head">
                                                <div class="item-name">{{ $item->name }}</div>
                                                @if ($menu->show_prices)
                                                    <div class="item-price">${{ number_format((float) $item->price, 0, ',', '.') }}</div>
                                                @endif
                                            </div>
                                            @if ($theme['show_descriptions'] && $item->description)
                                                <div class="item-desc">{{ $item->description }}</div>
                                            @endif
                                            @if (! empty($item->tags))
                                                <div class="item-tags">
                                                    @foreach ($item->tags as $tag)
                                                        <span class="item-tag">{{ \App\Models\Restaurant\MenuItem::COMMON_TAGS[$tag] ?? $tag }}</span>
                                                    @endforeach
                                                </div>
                                            @endif
                                        </div>
                                    </div>
                                @endif
                            @endforeach
                        </div>
                    @endif
                </div>
            @empty
                <div class="empty">La carta está vacía. Agrega secciones e items desde el panel.</div>
            @endforelse
        </div>

        {{-- FOOTER --}}
        <div class="footer">
            <div style="font-weight:700; margin-bottom:4px;">{{ $menu->company->name ?? '' }}</div>
            @if ($menu->company?->phone)
                <a href="tel:{{ $menu->company->phone }}">{{ $menu->company->phone }}</a><br>
            @endif
            @if ($menu->company?->address)
                <div style="font-size:11px; margin-top:4px;">{{ $menu->company->address }}</div>
            @endif
            <div style="font-size:10px; margin-top:14px; opacity:0.6;">Powered by Emprenddi</div>
        </div>
    </div>
</body>
</html>
