<?php

use App\Http\Controllers\DeliveryTrackController;
use App\Http\Controllers\PosPrintController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/app');

// Ticket POS — vista imprimible. Detrás del middleware web (sesión + auth)
// para que el panel App sea quien autentica.
Route::middleware(['web', 'auth'])->group(function () {
    Route::get('/app/pos/print/{invoice}', [PosPrintController::class, 'show'])
        ->name('pos.print');

    // Firma de peticiones QZ Tray (impresión local).
    Route::get('/qz/certificate', [\App\Http\Controllers\QzSigningController::class, 'certificate'])
        ->name('qz.certificate');
    Route::post('/qz/sign', [\App\Http\Controllers\QzSigningController::class, 'sign'])
        ->name('qz.sign');
});

// Tracking público de domicilios — sin auth, token random.
Route::get('/track/{token}', [DeliveryTrackController::class, 'show'])
    ->name('delivery.track')
    ->where('token', '[A-Za-z0-9]{32}');

// Carta pública del restaurante — sin auth, slug humano (ej: mi-pizzeria).
Route::get('/menu/{slug}', [\App\Http\Controllers\PublicMenuController::class, 'show'])
    ->name('menu.public')
    ->where('slug', '[a-z0-9-]+');
