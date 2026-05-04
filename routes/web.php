<?php

use App\Http\Controllers\PosPrintController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/app');

// Ticket POS — vista imprimible. Detrás del middleware web (sesión + auth)
// para que el panel App sea quien autentica.
Route::middleware(['web', 'auth'])->group(function () {
    Route::get('/app/pos/print/{invoice}', [PosPrintController::class, 'show'])
        ->name('pos.print');
});
