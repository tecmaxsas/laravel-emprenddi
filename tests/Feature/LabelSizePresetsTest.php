<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Product;
use App\Models\User;
use App\Support\CurrentCompany;
use App\Support\LabelsSettings;
use Illuminate\Support\Facades\DB;
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
     * El código de barras ocupa una parte real de la etiqueta.
     *
     * El alto se calculaba como `alto_mm * 0.5` tratando los milímetros como
     * si fueran píxeles: en una etiqueta de 50 mm daba 25 px, que sobre una
     * página de 189 px son 7 mm. Salía un código diminuto con media etiqueta
     * en blanco.
     */
    public function test_el_codigo_de_barras_ocupa_la_etiqueta(): void
    {
        $this->configurar(['print_mode' => 'roll', 'width_mm' => 100, 'height_mm' => 50]);

        $html = $this->imprimir();

        preg_match('/const height = (\d+);/', $html, $m);

        $this->assertNotEmpty($m, 'No se encontró el alto del código de barras.');

        // 50 mm son ~189 px; el codigo se lleva el 35%.
        $this->assertGreaterThanOrEqual(50, (int) $m[1],
            'Con 25 px sobre una página de 189 px el código sale minúsculo.');
    }

    /** Y en una etiqueta pequeña no se desborda. */
    public function test_en_la_etiqueta_chica_el_codigo_no_se_desborda(): void
    {
        $this->configurar(['print_mode' => 'roll', 'width_mm' => 50, 'height_mm' => 25]);

        $html = $this->imprimir();

        preg_match('/const height = (\d+);/', $html, $m);

        // 25 mm son ~94 px: el codigo no puede pedir mas que la etiqueta.
        $this->assertLessThan(94, (int) $m[1],
            'Un código más alto que la etiqueta se corta.');
    }

    // ------------------------------------------- rollos de varias columnas

    /**
     * Un rollo de dos etiquetas a lo ancho imprime las dos.
     *
     * El de 50 × 25 se consigue mucho en presentación de dos columnas.
     * Mandando una etiqueta por página salía la de la izquierda impresa y la de
     * la derecha en blanco: se botaba la mitad del rollo y no había manera de
     * configurarlo.
     */
    public function test_el_rollo_de_dos_columnas_usa_las_dos(): void
    {
        $this->configurar([
            'print_mode' => 'roll', 'width_mm' => 50, 'height_mm' => 25,
            'roll_across' => 2, 'roll_gap_mm' => 2,
        ]);

        $html = $this->imprimir();

        // 50 + 2 + 50 = 102 mm de rollo, no 50.
        $this->assertStringContainsString('@page { size: 102mm 25mm; margin: 0; }', $html,
            'La página en rollo mide la FILA, no una etiqueta.');
    }

    /** El salto de página es por fila, no por etiqueta. */
    public function test_el_salto_de_pagina_es_por_fila(): void
    {
        $this->configurar([
            'print_mode' => 'roll', 'width_mm' => 50, 'height_mm' => 25,
            'roll_across' => 2, 'roll_gap_mm' => 0,
        ]);

        $html = $this->imprimir();

        $this->assertMatchesRegularExpression('/\.fila \{[^}]*page-break-after: always/', $html,
            'Si cada etiqueta salta de página, la de la derecha nunca se usa.');
    }

    /** Un rollo normal de una columna sigue igual que siempre. */
    public function test_el_rollo_de_una_columna_no_cambia(): void
    {
        $this->configurar([
            'print_mode' => 'roll', 'width_mm' => 50, 'height_mm' => 25,
            'roll_across' => 1,
        ]);

        $this->assertStringContainsString('@page { size: 50mm 25mm; margin: 0; }', $this->imprimir(),
            'La mayoría de los rollos son de una sola columna y no se pueden romper.');
    }

    /** El ancho de la fila cuenta la separación troquelada. */
    public function test_el_ancho_de_la_fila_cuenta_la_separacion(): void
    {
        $this->assertSame(50, LabelsSettings::anchoDePagina(50, 1, 3),
            'Con una sola columna no hay separación que contar.');
        $this->assertSame(102, LabelsSettings::anchoDePagina(50, 2, 2));
        $this->assertSame(156, LabelsSettings::anchoDePagina(50, 3, 3));
    }

    /** En modo hoja el ajuste del rollo no aplica. */
    public function test_en_modo_hoja_el_ajuste_del_rollo_no_aplica(): void
    {
        $this->configurar([
            'print_mode' => 'sheet', 'width_mm' => 50, 'height_mm' => 25,
            'roll_across' => 2,
        ]);

        $html = $this->imprimir();

        $this->assertStringContainsString('@page { size: A4;', $html);
        $this->assertStringNotContainsString('class="fila"', $html,
            'La grilla de la hoja ya reparte las etiquetas por fila.');
    }

    // ------------------------------------------- lo que falta se dice

    /**
     * Un producto sin precio lo dice, no imprime «$ 0».
     *
     * La columna `default_sale_price` es NOT NULL, así que un producto sin
     * precio cargado no llega en null: llega en cero. Una etiqueta de estante
     * que diga «$ 0» se pega igual y el problema aparece en la caja.
     */
    public function test_un_producto_sin_precio_lo_dice(): void
    {
        $this->configurar([
            'print_mode' => 'roll', 'width_mm' => 100, 'height_mm' => 50,
            'fields' => ['name', 'code', 'barcode', 'price'],
        ]);

        $producto = $this->productoSinPrecio();

        $html = $this->get(route('labels.print', ['products' => $producto->id.':1']))
            ->assertOk()->getContent();

        $this->assertStringContainsString('no tiene precio de venta', $html,
            'Un hueco en blanco no le dice al usuario qué arreglar.');
    }

    /** Y el aviso frena la impresión automática. */
    public function test_con_datos_faltantes_no_se_imprime_solo(): void
    {
        $this->configurar([
            'print_mode' => 'roll', 'width_mm' => 100, 'height_mm' => 50,
            'fields' => ['name', 'code', 'barcode', 'price'],
        ]);

        $producto = $this->productoSinPrecio();

        $html = $this->get(route('labels.print', ['products' => $producto->id.':1']))
            ->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/if \(fallaron \|\| true\) return;/', $html,
            'Imprimir solo etiquetas incompletas gasta el rollo del cliente.');
    }

    /** Con todo completo sí se imprime solo, como siempre. */
    public function test_con_todo_completo_sigue_imprimiendo_solo(): void
    {
        $this->configurar(['print_mode' => 'roll', 'width_mm' => 100, 'height_mm' => 50]);

        $html = $this->get(route('labels.print', ['preview' => 1]))
            ->assertOk()->getContent();

        $this->assertStringContainsString('if (fallaron || false) return;', $html);
        $this->assertStringContainsString('window.print()', $html);
    }

    /**
     * Si JsBarcode no carga, la etiqueta lo dice.
     *
     * Viene de un CDN. Una tienda sin internet imprimía el rollo entero con el
     * hueco en blanco y se enteraba al pasar el lector.
     */
    public function test_si_no_carga_jsbarcode_la_etiqueta_lo_dice(): void
    {
        $this->configurar(['print_mode' => 'roll', 'width_mm' => 100, 'height_mm' => 50]);

        $html = $this->imprimir();

        $this->assertStringContainsString("typeof JsBarcode === 'undefined'", $html);
        $this->assertStringContainsString('No se pudo cargar el generador', $html);
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

    /** Un producto de la empresa sin precio de venta, creado para la prueba. */
    private function productoSinPrecio(): Product
    {
        $producto = Product::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'code' => 'ZZ-SIN-PRECIO',
            'name' => 'ZZ producto sin precio',
            'default_sale_price' => 0,
            'active' => true,
        ]);

        $this->limpiar[] = fn () => DB::table('products')->where('id', $producto->id)->delete();

        return $producto;
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
