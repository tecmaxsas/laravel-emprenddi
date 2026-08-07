<?php

use App\Http\Controllers\DeliveryTrackController;
use App\Http\Controllers\PosPrintController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/app');

// Ticket POS, exports XLSX y utilidades detras del middleware web+auth.
// Se agrega SetActiveCompany asi CurrentCompany queda hidratado y el
// CompanyScope global filtra automaticamente por empresa del usuario —
// evitando que reportes / prints puedan leak data cross-tenant.
Route::middleware(['web', 'auth', \App\Http\Middleware\SetActiveCompany::class])->group(function () {
    Route::get('/app/pos/print/{invoice}', [PosPrintController::class, 'show'])
        ->name('pos.print');

    // Comprobante imprimible de garantía (constancia de recepción)
    Route::get('/app/warranties/{warranty}/print', [\App\Http\Controllers\WarrantyPrintController::class, 'show'])
        ->name('warranties.print');

    // Firma de peticiones QZ Tray (impresión local).
    Route::get('/qz/certificate', [\App\Http\Controllers\QzSigningController::class, 'certificate'])
        ->name('qz.certificate');
    Route::post('/qz/sign', [\App\Http\Controllers\QzSigningController::class, 'sign'])
        ->name('qz.sign');

    // Exportacion XLSX de estados financieros.
    Route::get('/app/reports/export/income-statement',
        [\App\Http\Controllers\App\ReportExportController::class, 'incomeStatement'])
        ->name('reports.export.income_statement');
    Route::get('/app/reports/export/balance-sheet',
        [\App\Http\Controllers\App\ReportExportController::class, 'balanceSheet'])
        ->name('reports.export.balance_sheet');
    Route::get('/app/reports/export/financial-indicators',
        [\App\Http\Controllers\App\ReportExportController::class, 'indicators'])
        ->name('reports.export.financial_indicators');

    // Exportacion XLSX de reportes tabulares operativos.
    Route::get('/app/reports/export/journal-book',
        [\App\Http\Controllers\App\ReportExportController::class, 'journalBook'])
        ->name('reports.export.journal_book');
    Route::get('/app/reports/export/general-ledger',
        [\App\Http\Controllers\App\ReportExportController::class, 'generalLedger'])
        ->name('reports.export.general_ledger');
    Route::get('/app/reports/export/trial-balance',
        [\App\Http\Controllers\App\ReportExportController::class, 'trialBalance'])
        ->name('reports.export.trial_balance');
    Route::get('/app/reports/export/kardex',
        [\App\Http\Controllers\App\ReportExportController::class, 'kardex'])
        ->name('reports.export.kardex');
    Route::get('/app/reports/export/accounts-receivable',
        [\App\Http\Controllers\App\ReportExportController::class, 'accountsReceivable'])
        ->name('reports.export.accounts_receivable');
    Route::get('/app/reports/export/accounts-payable',
        [\App\Http\Controllers\App\ReportExportController::class, 'accountsPayable'])
        ->name('reports.export.accounts_payable');
    Route::get('/app/reports/export/sales-by-period',
        [\App\Http\Controllers\App\ReportExportController::class, 'salesByPeriod'])
        ->name('reports.export.sales_by_period');
    Route::get('/app/reports/export/stock-by-location',
        [\App\Http\Controllers\App\ReportExportController::class, 'stockByLocation'])
        ->name('reports.export.stock_by_location');
    Route::get('/app/reports/export/cash-closings',
        [\App\Http\Controllers\App\ReportExportController::class, 'cashClosings'])
        ->name('reports.export.cash_closings');

    // Impresion de etiquetas con codigo de barras.
    // ?products=id:qty,id:qty,...  |  ?preview=1
    Route::get('/app/labels/print',
        [\App\Http\Controllers\App\LabelPrintController::class, 'print'])
        ->name('labels.print');

    // Descarga de la plantilla XLSX para importacion masiva de productos.
    Route::get('/app/products/import/template',
        [\App\Http\Controllers\App\ProductImportController::class, 'template'])
        ->name('products.import.template');

    // Descarga de la plantilla XLSX para importacion masiva de terceros.
    Route::get('/app/third-parties/import/template',
        [\App\Http\Controllers\App\ThirdPartyImportController::class, 'template'])
        ->name('third-parties.import.template');

    // Ticket imprimible de entrada al parqueadero (con QR para salida).
    Route::get('/app/parking/tickets/{session}/print',
        [\App\Http\Controllers\App\ParkingTicketController::class, 'show'])
        ->name('parking.tickets.print');

    // PDF descargable del pedido (modulo Toma pedidos).
    Route::get('/app/order-taking/orders/{order}/pdf',
        [\App\Http\Controllers\App\OrderTakingPdfController::class, 'show'])
        ->name('order-taking.orders.pdf');

    // Fallback HTTP puro para importar catalogo MAC DULCES (cuando Livewire
    // falla con 'This page has expired').
    Route::get('/app/order-taking/quick-import',
        [\App\Http\Controllers\App\OrderTakingImportController::class, 'form'])
        ->name('order-taking.import.form');
    Route::post('/app/order-taking/import/submit',
        [\App\Http\Controllers\App\OrderTakingImportController::class, 'submit'])
        ->name('order-taking.import.submit');

    // TEMPORAL: endpoint de diagnostico para el 500 al abrir un pedido.
    // Eliminar cuando se resuelva.
    Route::get('/app/order-taking/debug/list',
        [\App\Http\Controllers\App\OrderTakingDebugController::class, 'index'])
        ->name('order-taking.debug.list');
    Route::get('/app/order-taking/debug/{order}',
        [\App\Http\Controllers\App\OrderTakingDebugController::class, 'show'])
        ->name('order-taking.debug');

    // Exportacion XLSX de reportes del modulo de parqueadero.
    Route::get('/app/parking/reports/export/sessions',
        [\App\Http\Controllers\App\ParkingReportExportController::class, 'sessions'])
        ->name('parking.reports.export.sessions');
    Route::get('/app/parking/reports/export/revenue',
        [\App\Http\Controllers\App\ParkingReportExportController::class, 'revenue'])
        ->name('parking.reports.export.revenue');
});

// Tracking público de domicilios — sin auth, token random.
Route::get('/track/{token}', [DeliveryTrackController::class, 'show'])
    ->name('delivery.track')
    ->where('token', '[A-Za-z0-9]{32}');

// Carta pública del restaurante — sin auth, slug humano (ej: mi-pizzeria).
Route::get('/menu/{slug}', [\App\Http\Controllers\PublicMenuController::class, 'show'])
    ->name('menu.public')
    ->where('slug', '[a-z0-9-]+');

// Páginas legales — términos y política de privacidad (públicas).
Route::get('/legal/{doc}', fn (string $doc) => view('legal.policy', ['doc' => $doc]))
    ->name('legal.policy')
    ->where('doc', 'terminos|privacidad');

// Documentación pública del módulo Parqueadero.
Route::get('/docs/parqueadero', fn () => view('docs.parking'))
    ->name('docs.parking');
