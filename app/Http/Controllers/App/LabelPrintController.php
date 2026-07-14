<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Support\LabelsSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Renderiza etiquetas imprimibles con codigo de barras.
 *
 * Input via query string:
 *   ?products=id:qty,id:qty,...    Ej. ?products=12:5,45:1,88:12
 *   ?preview=1                     Modo preview (1 sola etiqueta de muestra)
 *
 * Reusa la config de LabelsSettings (campos, dimensiones, tipo de codigo).
 * Renderiza HTML con grid CSS + JsBarcode del CDN para dibujar los barcodes
 * en cliente. Auto-print al cargar.
 */
class LabelPrintController extends Controller
{
    public function print(Request $request)
    {
        abort_unless(Auth::check(), 403);
        abort_unless(Auth::user()?->can('products.view'), 403);

        $company = Auth::user()->company;
        abort_unless($company, 403);

        $config = LabelsSettings::config($company);
        if (! $config['enabled']) {
            abort(403, 'La impresión de etiquetas no está activada. Actívala en Configuración → Etiquetas.');
        }

        $items = $this->parseProductsParam($request->query('products', ''), (int) $company->id);
        $isPreview = (bool) $request->query('preview');

        if ($isPreview) {
            // Modo demo: 1 etiqueta con datos placeholder
            $items = [[
                'product' => (object) [
                    'id' => 0,
                    'code' => 'PROD-0001',
                    'barcode' => '7702186000123',
                    'name' => 'Producto de ejemplo — vista previa',
                    'default_sale_price' => 25000,
                    'brand' => 'Marca',
                    'category' => (object) ['name' => 'Categoría'],
                ],
                'qty' => 1,
            ]];
        }

        if (empty($items)) {
            abort(422, 'No se seleccionaron productos para imprimir.');
        }

        return view('labels.print', [
            'company' => $company,
            'items' => $items,
            'config' => $config,
        ]);
    }

    /**
     * Parsea "12:5,45:1,88:12" en array de {product, qty}, filtrando por
     * empresa. Silenciosamente ignora ids invalidos o de otras empresas.
     */
    protected function parseProductsParam(string $param, int $companyId): array
    {
        if (! $param) return [];

        $entries = [];
        foreach (explode(',', $param) as $token) {
            $token = trim($token);
            if ($token === '') continue;
            $parts = explode(':', $token, 2);
            $id = (int) $parts[0];
            $qty = max(1, (int) ($parts[1] ?? 1));
            if ($id > 0) $entries[$id] = ($entries[$id] ?? 0) + $qty;
        }
        if (empty($entries)) return [];

        $products = Product::query()
            ->where('company_id', $companyId)
            ->whereIn('id', array_keys($entries))
            ->with('category:id,name')
            ->get()
            ->keyBy('id');

        $out = [];
        foreach ($entries as $id => $qty) {
            if (isset($products[$id])) {
                $out[] = ['product' => $products[$id], 'qty' => $qty];
            }
        }
        return $out;
    }
}
