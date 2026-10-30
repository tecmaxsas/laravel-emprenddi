@php
    /** @var \App\Models\Hotel\Reservation $reserva */
    /** @var \App\Models\Hotel\Hotel|null $hotel */
    /** @var string $cuerpo */
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>{{ $reserva->code }}</title>
</head>
<body style="margin:0; padding:0; background:#f1f5f9; font-family:Arial,Helvetica,sans-serif; color:#1e293b;">
    <div style="max-width:560px; margin:0 auto; padding:24px 16px;">
        <div style="background:#4f46e5; color:#fff; border-radius:12px 12px 0 0; padding:20px 24px;">
            <div style="font-size:12px; letter-spacing:.12em; text-transform:uppercase; opacity:.85;">
                Confirmación de reserva
            </div>
            <div style="font-size:22px; font-weight:800; margin-top:4px;">{{ $hotel?->name }}</div>
            <div style="font-size:26px; font-weight:900; margin-top:10px; font-family:monospace;">
                {{ $reserva->code }}
            </div>
        </div>

        <div style="background:#fff; border-radius:0 0 12px 12px; padding:22px 24px;">
            {{-- El mismo texto que va por WhatsApp. Se respeta tal cual, con
                 sus saltos de línea, para que las dos versiones digan lo
                 mismo palabra por palabra. --}}
            <div style="font-size:14px; line-height:1.65; white-space:pre-line;">{{ $cuerpo }}</div>

            @if ($reserva->notes)
                <div style="margin-top:18px; padding:12px 14px; background:#f8fafc; border-left:3px solid #4f46e5; font-size:13px;">
                    {{ $reserva->notes }}
                </div>
            @endif
        </div>

        <div style="text-align:center; font-size:11.5px; color:#64748b; padding:16px 8px;">
            Este correo se generó automáticamente al confirmar su reserva.
            @if ($hotel?->rnt)
                <br>RNT {{ $hotel->rnt }}
            @endif
        </div>
    </div>
</body>
</html>
