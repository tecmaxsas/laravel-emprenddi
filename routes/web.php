<?php

use App\Http\Controllers\App\LabelPrintController;
use App\Http\Controllers\App\OrderTakingCatalogTemplateController;
use App\Http\Controllers\App\OrderTakingDebugController;
use App\Http\Controllers\App\OrderTakingImportController;
use App\Http\Controllers\App\OrderTakingPdfController;
use App\Http\Controllers\App\ParkingReportExportController;
use App\Http\Controllers\App\ParkingTicketController;
use App\Http\Controllers\App\ProductImportController;
use App\Http\Controllers\App\ReportExportController;
use App\Http\Controllers\App\ThirdPartyImportController;
use App\Http\Controllers\DeliveryTrackController;
use App\Http\Controllers\KitchenTicketPrintController;
use App\Http\Controllers\PosPrintController;
use App\Http\Controllers\PublicCatalogController;
use App\Http\Controllers\PublicCustomerStatementController;
use App\Http\Controllers\PublicMenuController;
use App\Http\Controllers\QzSigningController;
use App\Http\Controllers\WarrantyPrintController;
use App\Http\Middleware\SetActiveCompany;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/app');

// Ticket POS, exports XLSX y utilidades detras del middleware web+auth.
// Se agrega SetActiveCompany asi CurrentCompany queda hidratado y el
// CompanyScope global filtra automaticamente por empresa del usuario —
// evitando que reportes / prints puedan leak data cross-tenant.
Route::middleware(['web', 'auth', SetActiveCompany::class])->group(function () {
    Route::get('/app/pos/print/{invoice}', [PosPrintController::class, 'show'])
        ->name('pos.print');

    // Comanda de cocina imprimible por navegador: se usa cuando ninguna
    // impresora activa enruta los productos de la orden.
    Route::get('/app/restaurant/kot/print/{ticket}', [KitchenTicketPrintController::class, 'show'])
        ->name('restaurant.kot.print');

    // Comprobante imprimible de garantía (constancia de recepción)
    Route::get('/app/warranties/{warranty}/print', [WarrantyPrintController::class, 'show'])
        ->name('warranties.print');

    // Firma de peticiones QZ Tray (impresión local).
    Route::get('/qz/certificate', [QzSigningController::class, 'certificate'])
        ->name('qz.certificate');
    Route::post('/qz/sign', [QzSigningController::class, 'sign'])
        ->name('qz.sign');

    // Exportacion XLSX de estados financieros.
    Route::get('/app/reports/export/income-statement',
        [ReportExportController::class, 'incomeStatement'])
        ->name('reports.export.income_statement');
    Route::get('/app/reports/export/balance-sheet',
        [ReportExportController::class, 'balanceSheet'])
        ->name('reports.export.balance_sheet');
    Route::get('/app/reports/export/financial-indicators',
        [ReportExportController::class, 'indicators'])
        ->name('reports.export.financial_indicators');

    // Exportacion XLSX de reportes tabulares operativos.
    Route::get('/app/reports/export/journal-book',
        [ReportExportController::class, 'journalBook'])
        ->name('reports.export.journal_book');
    Route::get('/app/reports/export/general-ledger',
        [ReportExportController::class, 'generalLedger'])
        ->name('reports.export.general_ledger');
    Route::get('/app/reports/export/trial-balance',
        [ReportExportController::class, 'trialBalance'])
        ->name('reports.export.trial_balance');
    Route::get('/app/reports/export/kardex',
        [ReportExportController::class, 'kardex'])
        ->name('reports.export.kardex');
    Route::get('/app/reports/export/accounts-receivable',
        [ReportExportController::class, 'accountsReceivable'])
        ->name('reports.export.accounts_receivable');
    Route::get('/app/reports/export/accounts-payable',
        [ReportExportController::class, 'accountsPayable'])
        ->name('reports.export.accounts_payable');
    Route::get('/app/reports/export/sales-by-period',
        [ReportExportController::class, 'salesByPeriod'])
        ->name('reports.export.sales_by_period');
    Route::get('/app/reports/export/stock-by-location',
        [ReportExportController::class, 'stockByLocation'])
        ->name('reports.export.stock_by_location');
    Route::get('/app/reports/export/cash-closings',
        [ReportExportController::class, 'cashClosings'])
        ->name('reports.export.cash_closings');

    // Impresion de etiquetas con codigo de barras.
    // ?products=id:qty,id:qty,...  |  ?preview=1
    Route::get('/app/labels/print',
        [LabelPrintController::class, 'print'])
        ->name('labels.print');

    // Descarga de la plantilla XLSX para importacion masiva de productos.
    Route::get('/app/products/import/template',
        [ProductImportController::class, 'template'])
        ->name('products.import.template');

    // Descarga de la plantilla XLSX para importacion masiva de terceros.
    Route::get('/app/third-parties/import/template',
        [ThirdPartyImportController::class, 'template'])
        ->name('third-parties.import.template');

    // Ticket imprimible de entrada al parqueadero (con QR para salida).
    Route::get('/app/parking/tickets/{session}/print',
        [ParkingTicketController::class, 'show'])
        ->name('parking.tickets.print');

    // Plantillas XLSX del catalogo (modulo Toma pedidos). Dos archivos
    // separados: el importador lee la primera hoja de cada uno.
    Route::get('/app/order-taking/import/template/{tipo}',
        [OrderTakingCatalogTemplateController::class, 'template'])
        ->whereIn('tipo', ['precios', 'clientes'])
        ->name('order-taking.import.template');

    // PDF descargable del pedido (modulo Toma pedidos).
    Route::get('/app/order-taking/orders/{order}/pdf',
        [OrderTakingPdfController::class, 'show'])
        ->name('order-taking.orders.pdf');

    // Fallback HTTP puro para importar catalogo MAC DULCES (cuando Livewire
    // falla con 'This page has expired').
    Route::get('/app/order-taking/quick-import',
        [OrderTakingImportController::class, 'form'])
        ->name('order-taking.import.form');
    Route::post('/app/order-taking/import/submit',
        [OrderTakingImportController::class, 'submit'])
        ->name('order-taking.import.submit');

    // TEMPORAL: endpoint de diagnostico para el 500 al abrir un pedido.
    // Eliminar cuando se resuelva.
    Route::get('/app/order-taking/debug/list',
        [OrderTakingDebugController::class, 'index'])
        ->name('order-taking.debug.list');
    Route::get('/app/order-taking/debug/{order}',
        [OrderTakingDebugController::class, 'show'])
        ->name('order-taking.debug');

    // Exportacion XLSX de reportes del modulo de parqueadero.
    Route::get('/app/parking/reports/export/sessions',
        [ParkingReportExportController::class, 'sessions'])
        ->name('parking.reports.export.sessions');
    Route::get('/app/parking/reports/export/revenue',
        [ParkingReportExportController::class, 'revenue'])
        ->name('parking.reports.export.revenue');
});

// Tracking público de domicilios — sin auth, token random.
Route::get('/track/{token}', [DeliveryTrackController::class, 'show'])
    ->name('delivery.track')
    ->where('token', '[A-Za-z0-9]{32}');

// Carta pública del restaurante — sin auth, slug humano (ej: mi-pizzeria).
Route::get('/menu/{slug}', [PublicMenuController::class, 'show'])
    ->name('menu.public')
    ->where('slug', '[a-z0-9-]+');

// Catálogo público de productos — sin auth, slug humano (ej: perfumeria-aroma).
// Con límite de peticiones: la página consulta la base en vivo para que los
// cambios de precio se vean al instante, así que no conviene dejarla abierta a
// que la golpeen sin freno.
Route::get('/catalogo/{slug}', [PublicCatalogController::class, 'show'])
    ->middleware('throttle:120,1')
    ->name('catalog.public')
    ->where('slug', '[a-z0-9-]+');

// Estado de cuenta que el cliente abre desde el WhatsApp que le llegó.
// Token aleatorio de 40 caracteres y con caducidad: es informacion financiera
// de un tercero viajando por un chat que se puede reenviar.
Route::get('/estado-cuenta/{token}', [PublicCustomerStatementController::class, 'show'])
    ->middleware('throttle:60,1')
    ->name('customer-statement.public')
    ->where('token', '[A-Za-z0-9]{40}');

// Páginas legales — términos y política de privacidad (públicas).
Route::get('/legal/{doc}', fn (string $doc) => view('legal.policy', ['doc' => $doc]))
    ->name('legal.policy')
    ->where('doc', 'terminos|privacidad');

// Documentación pública del módulo Parqueadero.
Route::get('/docs/parqueadero', fn () => view('docs.parking'))
    ->name('docs.parking');
