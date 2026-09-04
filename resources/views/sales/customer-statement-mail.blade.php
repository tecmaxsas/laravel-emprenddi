<!DOCTYPE html>
<html lang="es">
<head><meta charset="utf-8"></head>
<body style="margin:0; padding:24px; background:#f4f4f5; font-family:Arial, Helvetica, sans-serif; color:#111827;">
    <div style="max-width:560px; margin:0 auto; background:#ffffff; border-radius:10px; padding:26px;">

        <h2 style="margin:0 0 4px; font-size:18px;">{{ $company?->name }}</h2>
        <div style="color:#6b7280; font-size:12px; margin-bottom:18px;">Estado de cuenta</div>

        <div style="font-size:14px; line-height:1.6; white-space:pre-line;">{{ $body }}</div>

        <div style="margin:22px 0; padding:14px 16px; background:{{ $due > 0.01 ? '#fef2f2' : '#f0fdf4' }}; border-radius:8px;">
            <div style="font-size:12px; color:#6b7280;">
                {{ $due > 0.01 ? 'Saldo pendiente' : ($due < -0.01 ? 'Saldo a favor' : 'Estado') }}
            </div>
            <div style="font-size:22px; font-weight:bold; color:{{ $due > 0.01 ? '#991b1b' : '#166534' }};">
                @if (abs($due) <= 0.01)
                    Al día
                @else
                    ${{ number_format(abs($due), 0, ',', '.') }}
                @endif
            </div>
        </div>

        <div style="font-size:12px; color:#6b7280;">
            El detalle de los movimientos va adjunto en PDF.
        </div>

        <div style="margin-top:22px; padding-top:14px; border-top:1px solid #e5e7eb; font-size:11px; color:#9ca3af;">
            {{ $company?->name }}
            @if ($company?->phone) · {{ $company->phone }} @endif
            @if ($company?->email) · {{ $company->email }} @endif
        </div>
    </div>
</body>
</html>
