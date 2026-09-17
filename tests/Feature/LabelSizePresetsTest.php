<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Product;
use App\Models\User;
use App\Support\CurrentCompany;
use App\Support\LabelsSettings;
use Tests\TestCase;

/**
 * Imprimir etiquetas en el rollo que cada cliente compró.
 *
 * Hay dos maneras de imprimir y son incompatibles:
 *
 *   - **Hoja** manda una A4 con una grilla de etiquetas. Es lo correcto para
 *     hojas autoadhesivas tipo Avery.
 *   - **Rollo** manda cada etiqueta como una página con las dimensiones exactas
 *     del adhesivo y margen cero, que es lo que entiende una impresora térmica.
 *
 * Con el modo equivocado la térmica recibe una A4, la encoge para que quepa, y
 * sale una etiqueta diminuta en una esquina con el resto del rollo en blanco.
 * No es un fallo de la impresora: es el navegador obedeciendo.
 *
 * Las medidas ya eran configurables, pero había que escribirlas en milímetros.
 * La lista de tamaños existía en el código desde el principio **sin estar
 * conectada a nada**, así que nadie la veía.
 *
 * Usa la base de desarrollo y borra lo que crea en tearDown.
 */
class LabelSizePresetsTest extends TestCase
{
    private Company $company;

    /** @var list<callable> */
    private array $limpiar = [];

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::query()->whereNotNull('company_id')->orderBy('id')->firstOrFail();
        $this->company = Company::findOrFail($user->company_id);
        $this->actingAs($user);
        app(CurrentCompany::class)->set($this->company);
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->limpiar) as $fn) {
            $fn();
        }
        $this->limpiar = [];

        parent::tearDown();
    }

    /** Los dos tamaños que pidió el cliente están en la lista. */
    public function test_estan_los_tamanos_que_se_consiguen(): void
    {
        $this->assertArrayHasKey('50x25', LabelsSettings::SIZE_PRESETS,
            'El de precio de estante es el más común.');

        $this->assertArrayHasKey('100x50', LabelsSettings::SIZE_PRESETS,
            'El de caja y despacho.');
    }

    /** Elegir un tamaño da sus milímetros. */
    public function test_el_preset_entrega_sus_medidas(): void
    {
        $this->assertSame([50, 25], LabelsSettings::medidasDelPreset('50x25'));
        $this->assertSame([100, 50], LabelsSettings::medidasDelPreset('100x50'));
        $this->assertSame([100, 150], LabelsSettings::medidasDelPreset('100x150'));
    }

    /** «Personalizado» no tiene medidas: las escribe el usuario. */
    public function test_personalizado_no_impone_medidas(): void
    {
        $this->assertNull(LabelsSettings::medidasDelPreset('custom'));
        $this->assertNull(LabelsSettings::medidasDelPreset('no-existe'));
    }

    /** Unas medidas guardadas se reconocen como su preset. */
    public function test_las_medidas_guardadas_reconocen_su_preset(): void
    {
        $this->assertSame('50x25', LabelsSettings::presetDeMedidas(50, 25));
        $this->assertSame('100x50', LabelsSettings::presetDeMedidas(100, 50));
    }

    /** Y una medida rara cae en «Personalizado» en vez de mentir. */
    public function test_una_medida_rara_es_personalizada(): void
    {
        $this->assertSame('custom', LabelsSettings::presetDeMedidas(47, 23),
            'Decir «50 × 25» cuando dice 47 es peor que no decir nada.');
    }

    /**
     * El modo rollo manda la página del tamaño de la etiqueta.
     *
     * Es lo único que de verdad arregla la impresión: sin este `@page`, la
     * térmica recibe una A4 y encoge todo a una esquina.
     */
    public function test_en_modo_rollo_la_pagina_mide_lo_que_la_etiqueta(): void
    {
        $this->configurar(['print_mode' => 'roll', 'width_mm' => 50, 'height_mm' => 25]);

        $html = $this->imprimir();

        $this->assertStringContainsString('@page { size: 50mm 25mm; margin: 0; }', $html,
            'Sin esto la térmica recibe una A4 y la encoge.');
    }

    /** Y con el otro rollo, el otro tamaño. */
    public function test_el_rollo_grande_tambien(): void
    {
        $this->configurar(['print_mode' => 'roll', 'width_mm' => 100, 'height_mm' => 50]);

        $this->assertStringContainsString('@page { size: 100mm 50mm; margin: 0; }', $this->imprimir());
    }

    /**
     * En modo hoja sigue siendo A4.
     *
     * Quien imprime en hojas Avery no puede quedarse sin su grilla porque otro
     * cliente use rollo.
     */
    public function test_en_modo_hoja_sigue_siendo_a4(): void
    {
        $this->configurar(['print_mode' => 'sheet', 'width_mm' => 50, 'height_mm' => 30]);

        $html = $this->imprimir();

        $this->assertStringContainsString('@page { size: A4;', $html);
        $this->assertStringNotContainsString('@page { size: 50mm', $html);
    }

    /** Cada empresa tiene su propia configuración. */
    public function test_la_configuracion_es_de_cada_empresa(): void
    {
        $this->configurar(['print_mode' => 'roll', 'width_mm' => 100, 'height_mm' => 50]);

        $otra = Company::query()->where('id', '!=', $this->company->id)->first();

        if (! $otra) {
            $this->markTestSkipped('Solo hay una empresa en la base de desarrollo.');
        }

        $suConfig = LabelsSettings::config($otra);

        $this->assertNotSame(
            [100, 50],
            [$suConfig['width_mm'], $suConfig['height_mm']],
            'Un cliente con rollo de 100 × 50 no puede cambiarle el tamaño a otro.',
        );
    }

    // ------------------------------------------- que se lea lo impreso

    /**
     * Todo en negro puro al imprimir.
     *
     * Una térmica es monocroma: no tiene tinta, quema puntos. Un gris como
     * `#64748b` no lo puede hacer, así que lo aproxima con un patrón disperso y
     * el texto sale desvaído. Era lo que pasaba con el nombre de la empresa y el
     * SKU; el precio, que iba en verde, era peor todavía.
     */
    public function test_al_imprimir_todo_va_en_negro(): void
    {
        $this->configurar(['print_mode' => 'roll', 'width_mm' => 50, 'height_mm' => 25]);

        $html = $this->imprimir();

        $this->assertStringContainsString('color: #000 !important', $html,
            'Cualquier gris sale desvaído en una impresora térmica.');

        // El bloque tiene que cubrir los campos que salian mas claros.
        foreach (['.label .company', '.label .code', '.label .price'] as $campo) {
            $this->assertStringContainsString($campo, $html);
        }
    }

    /**
     * El código de barras, negro y con bordes limpios.
     *
     * Una barra gris o con el borde difuminado es lo que hace que el lector
     * tenga que intentarlo tres veces.
     */
    public function test_el_codigo_de_barras_sale_negro(): void
    {
        $this->configurar(['print_mode' => 'roll', 'width_mm' => 50, 'height_mm' => 25]);

        $html = $this->imprimir();

        $this->assertStringContainsString("lineColor: '#000000'", $html);
        $this->assertStringContainsString('shape-rendering: crispEdges', $html);
    }

    /**
     * El color del código no se fuerza desde el CSS.
     *
     * JsBarcode dibuja un rectángulo de **fondo** además de las barras. Una
     * regla que pinte todos los `rect` de negro tapa el código entero con un
     * bloque sólido — y eso fue exactamente lo que pasó al intentar oscurecer la
     * impresión: el código desapareció y salió un cuadro negro.
     *
     * El color va en las opciones de JsBarcode, que sí sabe cuál rectángulo es
     * cuál.
     */
    public function test_el_css_no_pinta_el_fondo_del_codigo(): void
    {
        $vista = file_get_contents(resource_path('views/labels/print.blade.php'));

        $this->assertDoesNotMatchRegularExpression(
            '/barcode-wrap svg rect\s*\{[^}]*fill/',
            $vista,
            'Pintar todos los `rect` tapa el código de barras con un bloque negro.',
        );
    }

    /**
     * Ningún texto de la etiqueta baja de 7pt.
     *
     * A 203 dpi —lo normal en estas impresoras— 6pt son unos diecisiete
     * píxeles de alto y las letras se comen entre sí.
     */
    public function test_ningun_texto_es_demasiado_pequeno(): void
    {
        $vista = file_get_contents(resource_path('views/labels/print.blade.php'));

        preg_match_all('/\.label \.[a-z-]+ \{[^}]*font-size: ([\d.]+)pt/', $vista, $tamanos);

        $this->assertNotEmpty($tamanos[1], 'No se encontraron los tamaños de fuente.');

        foreach ($tamanos[1] as $pt) {
            $this->assertGreaterThanOrEqual(7, (float) $pt,
                "Hay texto de {$pt}pt: impreso en térmica no se alcanza a leer.");
        }
    }

    // --------------------------------------------------------- auxiliares

    private function imprimir(): string
    {
        $producto = Product::query()
            ->where('company_id', $this->company->id)
            ->where('active', true)
            ->first();

        $ruta = $producto
            ? route('labels.print', ['products' => $producto->id.':1'])
            : route('labels.print', ['preview' => 1]);

        return $this->get($ruta)->assertOk()->getContent();
    }

    /** @param  array<string, mixed>  $labels */
    private function configurar(array $labels): void
    {
        $original = $this->company->settings;

        $settings = $original ?? [];
        $settings['labels'] = array_merge($settings['labels'] ?? [], ['enabled' => true], $labels);

        $this->company->update(['settings' => $settings]);
        app(CurrentCompany::class)->set($this->company->fresh());

        $this->limpiar[] = function () use ($original) {
            $this->company->update(['settings' => $original]);
            app(CurrentCompany::class)->set($this->company->fresh());
        };
    }
}
