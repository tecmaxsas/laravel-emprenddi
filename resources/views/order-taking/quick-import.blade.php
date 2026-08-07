<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Importar catálogo · Emprenddi</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: system-ui, -apple-system, sans-serif; }
        body { background: #f1f5f9; padding: 30px 20px; color: #0f172a; }
        .wrap { max-width: 700px; margin: 0 auto; background: #fff; border-radius: 12px; padding: 26px; box-shadow: 0 4px 20px rgba(0,0,0,.08); }
        h1 { font-size: 22px; margin-bottom: 6px; color: #0f172a; }
        .sub { color: #64748b; font-size: 13px; margin-bottom: 20px; }
        .info { background: #eff6ff; border: 1px solid #93c5fd; padding: 12px 14px; border-radius: 8px; font-size: 13px; color: #1e40af; margin-bottom: 18px; }
        label { display: block; font-weight: 700; font-size: 12px; text-transform: uppercase; color: #334155; margin: 14px 0 6px; letter-spacing: .05em; }
        .file-wrap { position: relative; border: 2px dashed #cbd5e1; border-radius: 8px; padding: 20px; background: #f8fafc; cursor: pointer; transition: all .15s; }
        .file-wrap:hover { border-color: #6366f1; background: #eff6ff; }
        .file-wrap.has-file { border-color: #16a34a; background: #dcfce7; border-style: solid; }
        .file-wrap input[type=file] { position: absolute; inset: 0; opacity: 0; cursor: pointer; }
        .file-hint { font-size: 13px; color: #475569; text-align: center; }
        .file-name { font-weight: 700; color: #166534; text-align: center; word-break: break-all; }
        .actions { display: flex; justify-content: space-between; align-items: center; margin-top: 22px; }
        button[type=submit] {
            background: #16a34a; color: #fff; border: 0; padding: 14px 28px; border-radius: 10px;
            font-weight: 800; font-size: 15px; cursor: pointer; transition: background .15s;
        }
        button[type=submit]:hover { background: #15803d; }
        button[type=submit]:disabled { background: #94a3b8; cursor: not-allowed; }
        .back { color: #6366f1; text-decoration: none; font-size: 13px; font-weight: 600; }
        .flash { padding: 12px 14px; border-radius: 8px; margin-bottom: 16px; font-size: 13.5px; }
        .flash.ok { background: #dcfce7; border: 1px solid #16a34a; color: #166534; }
        .flash.err { background: #fee2e2; border: 1px solid #dc2626; color: #991b1b; }
        .flash strong { display: block; margin-bottom: 4px; }
        .csrf-note { font-size: 11px; color: #94a3b8; text-align: center; margin-top: 14px; }
    </style>
</head>
<body>
    <div class="wrap">
        <a href="/app/order-taking" class="back">← Volver al panel</a>
        <h1 style="margin-top: 10px;">Importar catálogo MAC DULCES</h1>
        <div class="sub">Formulario HTML vanilla — sin Livewire, sin Filament. Máxima compatibilidad.</div>

        @if (session('import_result'))
            @php $r = session('import_result'); @endphp
            <div class="flash ok">
                <strong>✓ Importación completada</strong>
                Productos: {{ $r['products_created'] }} nuevos + {{ $r['products_updated'] }} actualizados ·
                Listas de precios: {{ $r['price_lists'] }} ·
                Items de precio: {{ $r['price_items'] }} ·
                Clientes: {{ $r['customers_created'] }} nuevos + {{ $r['customers_updated'] }} actualizados
            </div>
        @endif

        @if (session('import_error'))
            <div class="flash err">
                <strong>✕ Error al importar</strong>
                {{ session('import_error') }}
            </div>
        @endif

        @if ($errors->any())
            <div class="flash err">
                <strong>✕ Validación falló</strong>
                @foreach ($errors->all() as $e)
                    · {{ $e }}<br>
                @endforeach
            </div>
        @endif

        <div class="info">
            📋 Se importarán:<br>
            · <strong>Productos</strong> (por código único)<br>
            · <strong>4 listas de precios</strong> (Lista 1 a Lista 4) con todos sus precios<br>
            · <strong>Clientes</strong> con su lista de precios asignada según "COD LISTA NEW"
        </div>

        <form method="POST" action="{{ route('order-taking.import.submit') }}"
              enctype="multipart/form-data" id="importForm">
            @csrf

            <label for="precios">LISTAS DE PRECIOS (.xlsx)</label>
            <div class="file-wrap" id="wrapPrecios">
                <input type="file" name="precios" id="precios" accept=".xlsx" required>
                <div class="file-hint" id="hintPrecios">Click para seleccionar<br><small>3. LISTAS DE PRECIOS ENE 2026.xlsx</small></div>
            </div>

            <label for="clientes">CATALOGO DE CLIENTES (.xlsx)</label>
            <div class="file-wrap" id="wrapClientes">
                <input type="file" name="clientes" id="clientes" accept=".xlsx" required>
                <div class="file-hint" id="hintClientes">Click para seleccionar<br><small>2. CATALOGO DE CLIENTES MAC DULCES.xlsx</small></div>
            </div>

            <div class="actions">
                <span id="statusMsg" style="color:#64748b; font-size: 12.5px;"></span>
                <button type="submit" id="btnSubmit">⚡ Ejecutar importación</button>
            </div>
        </form>

        <div class="csrf-note">
            Token CSRF vigente: <code style="font-family: monospace; font-size: 10px;">{{ substr(csrf_token(), 0, 12) }}...</code>
            · Si sale "página expirada", <a href="{{ route('order-taking.import.form') }}" style="color: #6366f1;">recarga esta página</a>
        </div>
    </div>

    <script>
        function bindFile(id, wrapId, hintId) {
            const input = document.getElementById(id);
            const wrap = document.getElementById(wrapId);
            const hint = document.getElementById(hintId);
            input.addEventListener('change', function() {
                if (this.files.length) {
                    const f = this.files[0];
                    const kb = Math.round(f.size / 1024);
                    hint.innerHTML = '<div class="file-name">✓ ' + f.name + '</div><small style="color:#166534;">' + kb + ' KB seleccionados</small>';
                    wrap.classList.add('has-file');
                } else {
                    wrap.classList.remove('has-file');
                }
            });
        }
        bindFile('precios', 'wrapPrecios', 'hintPrecios');
        bindFile('clientes', 'wrapClientes', 'hintClientes');

        document.getElementById('importForm').addEventListener('submit', function() {
            const btn = document.getElementById('btnSubmit');
            btn.disabled = true;
            btn.textContent = 'Importando... (10-30 segundos)';
            document.getElementById('statusMsg').textContent = 'Procesando 340 precios + 85 clientes...';
        });
    </script>
</body>
</html>
