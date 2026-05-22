<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Agrega 'cogs' y 'cogs_reversal' al CHECK constraint de
 * journal_entries.type.
 *
 * El asiento de Costo de Ventas (DR 6135 / CR 1435) que SaleInvoiceEngine
 * genera al postear una factura con productos de inventario usa type='cogs';
 * CreditDebitNoteEngine usa 'cogs_reversal'. Ninguno estaba en el enum
 * original → Check violation 23514 al facturar productos con stock.
 */
return new class extends Migration
{
    protected array $types = [
        'manual', 'opening', 'closing', 'adjustment', 'sale', 'purchase',
        'receipt', 'payment', 'payroll', 'depreciation', 'reversal',
        'cogs', 'cogs_reversal',
    ];

    public function up(): void
    {
        $list = "'".implode("','", $this->types)."'";
        DB::statement('ALTER TABLE journal_entries DROP CONSTRAINT IF EXISTS journal_entries_type_check');
        DB::statement("ALTER TABLE journal_entries ADD CONSTRAINT journal_entries_type_check CHECK (type IN ({$list}))");
    }

    public function down(): void
    {
        $original = "'manual','opening','closing','adjustment','sale','purchase','receipt','payment','payroll','depreciation','reversal'";
        DB::statement('ALTER TABLE journal_entries DROP CONSTRAINT IF EXISTS journal_entries_type_check');
        DB::statement("ALTER TABLE journal_entries ADD CONSTRAINT journal_entries_type_check CHECK (type IN ({$original}))");
    }
};
