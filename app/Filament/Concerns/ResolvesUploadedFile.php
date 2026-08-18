<?php

namespace App\Filament\Concerns;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Resuelve la ruta absoluta de un FileUpload de Filament.
 *
 * El estado de FileUpload NO es un string: es un array ['uuid' => valor],
 * y ese valor es un TemporaryUploadedFile mientras el formulario no se haya
 * procesado. Leer $this->data['archivo'] y pasarlo directo a Storage::path()
 * revienta con "Argument #1 ($path) must be of type string, array given".
 */
trait ResolvesUploadedFile
{
    /**
     * Ruta absoluta del archivo subido, o null si no hay archivo utilizable.
     */
    protected function resolveUploadedFile(mixed $state, string $disk = 'local'): ?string
    {
        if (is_array($state)) {
            $state = Arr::first($state);
        }

        // Aun en el directorio temporal de Livewire (form sin procesar).
        if ($state instanceof TemporaryUploadedFile) {
            $absolute = $state->getRealPath();

            return $absolute && file_exists($absolute) ? $absolute : null;
        }

        if (! is_string($state) || trim($state) === '') {
            return null;
        }

        $absolute = Storage::disk($disk)->path($state);

        return file_exists($absolute) ? $absolute : null;
    }
}
