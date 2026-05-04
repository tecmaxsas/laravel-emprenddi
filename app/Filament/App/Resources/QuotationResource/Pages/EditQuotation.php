<?php

namespace App\Filament\App\Resources\QuotationResource\Pages;

use App\Filament\App\Resources\QuotationResource;
use App\Models\Quotation;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;

class EditQuotation extends EditRecord
{
    protected static string $resource = QuotationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()->visible(fn (Quotation $record) => $record->isDraft()),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (! $this->record->isDraft()) {
            throw ValidationException::withMessages([
                'status' => 'Solo se pueden editar cotizaciones en estado borrador.',
            ]);
        }

        $subtotal = 0;
        $discount = 0;
        $tax = 0;
        $total = 0;
        $lineNum = 1;

        $data['lines'] = collect($data['lines'] ?? [])->map(function ($line) use (&$lineNum, &$subtotal, &$discount, &$tax, &$total) {
            $line['line_number'] = $lineNum++;
            $subtotal += (float) ($line['subtotal'] ?? 0);
            $discount += (float) ($line['discount_amount'] ?? 0);
            $tax += (float) ($line['tax_amount'] ?? 0);
            $total += (float) ($line['total'] ?? 0);
            return $line;
        })->all();

        $data['subtotal'] = $subtotal;
        $data['discount_total'] = $discount;
        $data['tax_total'] = $tax;
        $data['total'] = $total;

        return $data;
    }
}
