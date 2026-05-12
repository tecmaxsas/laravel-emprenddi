<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Token público inopinable para que el cliente vea el estado de su pedido
 * via URL /track/{token} sin necesidad de login. Usamos un char(32)
 * random (no relacionado al id) para evitar enumeración secuencial.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurant_orders', function (Blueprint $table) {
            $table->string('tracking_token', 40)->nullable()->unique()->after('number');
        });

        // Backfill: poblar token para ordenes existentes con is_delivery=true
        DB::table('restaurant_orders')
            ->where('is_delivery', true)
            ->whereNull('tracking_token')
            ->orderBy('id')
            ->each(function ($row) {
                DB::table('restaurant_orders')
                    ->where('id', $row->id)
                    ->update(['tracking_token' => Str::random(32)]);
            });
    }

    public function down(): void
    {
        Schema::table('restaurant_orders', function (Blueprint $table) {
            $table->dropColumn('tracking_token');
        });
    }
};
