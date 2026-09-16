<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Agrega 'credit_note', 'debit_note' y 'general' al CHECK de
 * journal_entries.type.
 *
 * Contabilizar una nota crédito fallaba con «Check violation 23514»: el motor
 * escribe type='credit_note' (o 'debit_note') y ninguno de los dos estaba en la
 * lista permitida, así que la nota nunca llegaba a quedar contabilizada — y como
 * el botón de enviar a DIAN solo aparece sobre notas contabilizadas, tampoco
 * había forma de transmitirla.
 *
 * 'general' venía por el mismo camino desde la liquidación de comisiones.
 *
 * Es la segunda vez que pasa: en mayo fue 'cogs'. La lista vive en dos sitios
 * —esta tabla y JournalEntry::TYPES— y se desincronizan en silencio hasta que
 * alguien contabiliza en producción. Por eso este arreglo viene con una prueba
 * (JournalEntryTypesTest) que compara ambas listas y falla en cuanto se separan.
 */
return new class extends Migration
{
    /** Debe coincidir con JournalEntry::TYPES. */
    protected array $types = [
        'manual', 'opening', 'closing', 'adjustment', 'sale', 'purchase',
        'receipt', 'payment', 'payroll', 'depreciation', 'reversal',
        'cogs', 'cogs_reversal',
        'credit_note', 'debit_note', 'general',
    ];

    protected array $anteriores = [
        'manual', 'opening', 'closing', 'adjustment', 'sale', 'purchase',
        'receipt', 'payment', 'payroll', 'depreciation', 'reversal',
        'cogs', 'cogs_reversal',
    ];

    public function up(): void
    {
        $this->reemplazarCheck($this->types);
    }

    public function down(): void
    {
        // Si ya hay asientos con los tipos nuevos, volver atrás dejaría la tabla
        // violando su propio CHECK. Se quedan como están.
        $enUso = DB::table('journal_entries')
            ->whereIn('type', ['credit_note', 'debit_note', 'general'])
            ->exists();

        if ($enUso) {
            return;
        }

        $this->reemplazarCheck($this->anteriores);
    }

    private function reemplazarCheck(array $tipos): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        $lista = "'".implode("','", $tipos)."'";

        DB::statement('ALTER TABLE journal_entries DROP CONSTRAINT IF EXISTS journal_entries_type_check');
        DB::statement("ALTER TABLE journal_entries ADD CONSTRAINT journal_entries_type_check CHECK (type IN ({$lista}))");
    }
};
