@php
    $panelId = filament()->getId();
    $brand = filament()->getBrandName();

    $themes = [
        'app' => [
            'grad' => 'linear-gradient(155deg,#4f46e5 0%,#7c3aed 52%,#2563eb 100%)',
            'tagline' => 'Tu negocio bajo control',
            'desc' => 'Contabilidad, facturación electrónica DIAN, inventario y POS — todo en una sola plataforma.',
            'features' => ['Facturación electrónica DIAN', 'POS e inventario en tiempo real', 'Contabilidad y nómina automáticas'],
        ],
        'super-admin' => [
            'grad' => 'linear-gradient(155deg,#e11d48 0%,#be123c 52%,#9f1239 100%)',
            'tagline' => 'Panel de administración',
            'desc' => 'Gestión central de la plataforma Emprenddi.',
            'features' => ['Empresas y suscripciones', 'Planes y facturación', 'Catálogos DIAN'],
        ],
        'contador' => [
            'grad' => 'linear-gradient(155deg,#059669 0%,#0d9488 52%,#0f766e 100%)',
            'tagline' => 'Portal del contador',
            'desc' => 'Gestiona la contabilidad de todos tus clientes desde un solo lugar.',
            'features' => ['Multiempresa en un clic', 'Reportes y libros oficiales', 'Información exógena y nómina'],
        ],
    ];
    $t = $themes[$panelId] ?? $themes['app'];
@endphp

<style>
    .fi-simple-layout {
        background:linear-gradient(125deg,#eef2ff 0%,#faf5ff 45%,#eff6ff 100%);
        background-size:180% 180%; animation:authBg 18s ease infinite;
    }
    .dark .fi-simple-layout {
        background:linear-gradient(125deg,#0f172a 0%,#1e1b4b 50%,#0f172a 100%);
        background-size:180% 180%;
    }
    .fi-simple-main {
        padding:0 !important; border-radius:20px !important;
        box-shadow:0 30px 70px -28px rgba(30,27,75,.5) !important;
        animation:authCard .6s cubic-bezier(.22,1,.36,1) both;
    }
    /* El encabezado propio de Filament se oculta: el diseño usa el suyo. */
    .fi-simple-header { display:none !important; }
    .fi-simple-page { padding:0 !important; }

    .auth-shell { display:flex; min-height:560px; }
    .auth-shell * { box-sizing:border-box; }

    /* ---- Branding ---- */
    .auth-brand {
        flex:0 0 350px; position:relative; overflow:hidden; color:#fff;
        padding:46px 40px; display:flex; flex-direction:column; background:{{ $t['grad'] }};
        border-radius:20px 0 0 20px;
    }
    .auth-blob { position:absolute; border-radius:50%; filter:blur(8px); opacity:.5; }
    .auth-blob-1 {
        width:220px; height:220px; top:-70px; right:-60px;
        background:radial-gradient(circle,rgba(255,255,255,.5),transparent 68%);
        animation:authFloat 9s ease-in-out infinite;
    }
    .auth-blob-2 {
        width:260px; height:260px; bottom:-110px; left:-80px;
        background:radial-gradient(circle,rgba(255,255,255,.32),transparent 70%);
        animation:authFloat 11s ease-in-out infinite reverse;
    }
    .auth-brand-inner { position:relative; z-index:1; display:flex; flex-direction:column; height:100%; }
    .auth-logo { font-size:1.5rem; font-weight:800; letter-spacing:-.01em; display:flex; align-items:center; gap:8px; }
    .auth-logo span { font-size:1.25rem; }
    .auth-tagline { font-size:1.7rem; font-weight:800; line-height:1.2; margin-top:38px; letter-spacing:-.02em; }
    .auth-desc { font-size:13.5px; line-height:1.65; color:rgba(255,255,255,.82); margin-top:12px; }
    .auth-features { list-style:none; margin:26px 0 0; padding:0; display:flex; flex-direction:column; gap:12px; }
    .auth-features li {
        display:flex; align-items:center; gap:11px; font-size:13px; font-weight:600;
        color:rgba(255,255,255,.95); animation:authFadeUp .5s ease both;
    }
    .auth-check {
        width:22px; height:22px; border-radius:50%; background:rgba(255,255,255,.22);
        display:flex; align-items:center; justify-content:center; font-size:12px; flex-shrink:0;
    }
    .auth-brand-foot { margin-top:auto; padding-top:28px; font-size:11.5px; color:rgba(255,255,255,.6); }

    /* ---- Form column ---- */
    .auth-form-col {
        flex:1; padding:48px 46px; display:flex; flex-direction:column; justify-content:center;
        animation:authFadeUp .55s ease .1s both;
    }
    .auth-head { margin-bottom:22px; }
    .auth-h1 { font-size:1.6rem; font-weight:800; color:#0f172a; letter-spacing:-.02em; }
    .auth-sub { font-size:13.5px; color:#64748b; margin-top:5px; }
    .dark .auth-h1 { color:#f1f5f9; }
    .dark .auth-sub { color:#94a3b8; }

    .auth-alt { font-size:13px; color:#64748b; margin-top:20px; }
    .dark .auth-alt { color:#94a3b8; }
    .auth-policy {
        font-size:11.5px; color:#94a3b8; margin-top:22px; padding-top:18px;
        border-top:1px solid #eef2f7; line-height:1.6;
    }
    .dark .auth-policy { color:#64748b; border-color:#1e293b; }
    .auth-policy a { color:#6366f1; font-weight:600; text-decoration:none; }
    .auth-policy a:hover { text-decoration:underline; }
    .dark .auth-policy a { color:#a5b4fc; }

    /* ---- Animations ---- */
    @keyframes authBg { 0%,100% { background-position:0% 50%; } 50% { background-position:100% 50%; } }
    @keyframes authCard { from { opacity:0; transform:translateY(22px) scale(.98); } to { opacity:1; transform:translateY(0) scale(1); } }
    @keyframes authFadeUp { from { opacity:0; transform:translateY(14px); } to { opacity:1; transform:translateY(0); } }
    @keyframes authFloat { 0%,100% { transform:translate(0,0); } 50% { transform:translate(22px,-26px); } }

    /* ---- Responsive ---- */
    @media (max-width:860px) {
        .auth-shell { flex-direction:column; min-height:0; }
        .auth-brand { flex:none; padding:30px 32px; border-radius:20px 20px 0 0; }
        .auth-tagline { margin-top:18px; font-size:1.35rem; }
        .auth-desc, .auth-features, .auth-brand-foot { display:none; }
        .auth-form-col { padding:34px 28px; }
    }
</style>

<div class="auth-shell">
    <aside class="auth-brand">
        <div class="auth-blob auth-blob-1"></div>
        <div class="auth-blob auth-blob-2"></div>
        <div class="auth-brand-inner">
            <div class="auth-logo"><span>✦</span> {{ $brand }}</div>
            <div class="auth-tagline">{{ $t['tagline'] }}</div>
            <div class="auth-desc">{{ $t['desc'] }}</div>
            <ul class="auth-features">
                @foreach ($t['features'] as $i => $feature)
                    <li style="animation-delay:{{ 200 + $i * 110 }}ms;">
                        <span class="auth-check">✓</span> {{ $feature }}
                    </li>
                @endforeach
            </ul>
            <div class="auth-brand-foot">© {{ date('Y') }} Emprenddi · Hecho en Colombia</div>
        </div>
    </aside>

    <div class="auth-form-col">
        {{ $slot }}
    </div>
</div>
