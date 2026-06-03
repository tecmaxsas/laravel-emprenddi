<x-filament-panels::page>
    <form wire:submit.prevent>
        {{ $this->form }}
    </form>

    @php $exportUrl = method_exists($this, 'getExportUrl') ? $this->getExportUrl() : null; @endphp
    @if ($exportUrl)
        <div class="rep-toolbar">
            <a href="{{ $exportUrl }}" class="rep-btn rep-btn-excel" target="_blank" rel="noopener">
                📊 Descargar Excel
            </a>
            <button type="button" class="rep-btn rep-btn-print" onclick="window.print()">
                🖨️ Imprimir / Guardar PDF
            </button>
        </div>
    @endif

    <div class="rep-print-area">
        {{ $this->table }}
    </div>

    <style>
        .rep-toolbar { display:flex; gap:8px; margin-top:14px; margin-bottom:8px; flex-wrap:wrap; }
        .rep-btn { display:inline-flex; align-items:center; gap:6px; padding:8px 14px; border-radius:8px; font-weight:700; font-size:13px; text-decoration:none; cursor:pointer; border:0; }
        .rep-btn-excel { background:#16a34a; color:#fff; }
        .rep-btn-excel:hover { background:#15803d; }
        .rep-btn-print { background:#475569; color:#fff; }
        .rep-btn-print:hover { background:#334155; }

        @media print {
            @page { size: A4 landscape; margin: 10mm; }
            body * { visibility: hidden !important; }
            .rep-print-area, .rep-print-area * { visibility: visible !important; }
            .rep-print-area {
                position: absolute !important; left: 0 !important; top: 0 !important; width: 100% !important;
                background: #fff !important; color: #000 !important;
            }
            .rep-print-area .fi-ta, .rep-print-area .fi-section { background:#fff !important; color:#000 !important; box-shadow:none !important; border:1px solid #ccc !important; }
            .rep-print-area .fi-ta-cell, .rep-print-area .fi-ta-header-cell { background:#fff !important; color:#000 !important; border-color:#e5e7eb !important; }
            .rep-print-area .fi-ta-header { background:#f3f4f6 !important; }
            .rep-print-area tr { page-break-inside: avoid; }
            .rep-print-area .fi-ta-header-toolbar,
            .rep-print-area .fi-pagination,
            .rep-print-area .fi-pagination-records-per-page,
            .rep-print-area .fi-ta-actions,
            .rep-print-area .fi-ta-header-actions { display: none !important; }
        }
    </style>
</x-filament-panels::page>
