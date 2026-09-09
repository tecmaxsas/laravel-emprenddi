<?php

namespace Tests\Feature;

use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\Company;
use App\Models\Product;
use App\Models\User;
use App\Services\Ai\AiAssistant;
use App\Services\Ai\AiCredits;
use App\Services\Ai\AiSettings;
use App\Services\Ai\BusinessTools;
use App\Support\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * La integración con Claude.
 *
 * Dos riesgos gobiernan estas pruebas:
 *
 *  1. **Aislamiento.** Claude consulta la base de la empresa en sesión. Si una
 *     consulta se escapa del filtro por empresa, un cliente ve los datos de
 *     otro. Por eso Claude no escribe SQL: elige entre consultas fijas que ya
 *     llevan el `company_id` cosido, y aquí se comprueba que lo llevan.
 *  2. **Plata.** El saldo se descuenta por token consumido. Un cobro mal
 *     calculado, o que se le cobre a Tecmax lo que debía pagar el cliente con
 *     su propia llave, son errores caros y silenciosos.
 *
 * Las llamadas a Anthropic van simuladas: no se gasta dinero real ni se depende
 * de la red.
 *
 * Usa la base de desarrollo y borra lo que crea en tearDown.
 */
class ClaudeAiTest extends TestCase
{
    private Company $company;

    private User $user;

    /** @var list<callable> */
    private array $limpiar = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::query()->whereNotNull('company_id')->orderBy('id')->firstOrFail();
        $this->company = Company::findOrFail($this->user->company_id);
        $this->actingAs($this->user);
        app(CurrentCompany::class)->set($this->company);

        $ajustesOriginales = $this->company->settings;
        $this->limpiar[] = function () use ($ajustesOriginales) {
            DB::table('companies')->where('id', $this->company->id)
                ->update(['settings' => json_encode($ajustesOriginales)]);
        };

        config(['ai.api_key' => 'sk-ant-de-prueba', 'ai.margin' => 1.0]);
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->limpiar) as $fn) {
            $fn();
        }
        $this->limpiar = [];

        parent::tearDown();
    }

    // ------------------------------------------------------- aislamiento

    /**
     * La prueba que justifica el diseño: ninguna consulta puede devolver datos
     * de otra empresa, aunque el modelo lo pida.
     */
    public function test_las_consultas_no_devuelven_datos_de_otra_empresa(): void
    {
        $otra = Company::query()->where('id', '!=', $this->company->id)->first();

        if (! $otra) {
            $this->markTestSkipped('Solo hay una empresa en la base: no hay con qué cruzar.');
        }

        $ajeno = Product::withoutGlobalScopes()->create([
            'company_id' => $otra->id,
            'code' => 'ZZIA'.random_int(10000, 99999),
            'name' => 'ZZ PRODUCTO SECRETO DE OTRA EMPRESA',
            'type' => 'good',
            'unit_of_measure' => 'und',
            'track_inventory' => false,
            'is_sellable' => true,
            'default_sale_price' => 999000,
            'active' => true,
        ]);
        $this->limpiar[] = fn () => Product::withoutGlobalScopes()->whereKey($ajeno->id)->forceDelete();

        $tools = new BusinessTools($this->company);

        $resultado = $tools->ejecutar('buscar_producto', ['texto' => 'ZZ PRODUCTO SECRETO']);

        $this->assertSame([], $resultado['productos'],
            'La consulta devolvió un producto de otra empresa.');

        $resumen = $tools->ejecutar('resumen_negocio', []);
        $this->assertSame($this->company->name, $resumen['empresa']);
    }

    /**
     * No hay una herramienta de «ejecuta este SQL», y no debe haberla: en una
     * base multiempresa es la forma más fácil de filtrar datos entre clientes.
     */
    public function test_no_existe_una_herramienta_de_sql_libre(): void
    {
        $nombres = collect((new BusinessTools($this->company))->definiciones())->pluck('name');

        foreach (['sql', 'query', 'consulta_libre', 'ejecutar_sql'] as $prohibida) {
            $this->assertNotContains($prohibida, $nombres->all(),
                'Dejar que el modelo arme el SQL rompe el aislamiento entre empresas.');
        }

        $this->assertGreaterThan(5, $nombres->count(), 'Se esperaban varias consultas disponibles.');
    }

    /** Una consulta que no existe se responde con un error, no con una excepción. */
    public function test_una_consulta_desconocida_devuelve_error_legible(): void
    {
        $resultado = (new BusinessTools($this->company))->ejecutar('borrar_todo', []);

        $this->assertArrayHasKey('error', $resultado);
        $this->assertStringContainsString('borrar_todo', $resultado['error']);
    }

    /** Las fechas mal formadas no revientan la conversación. */
    public function test_las_fechas_invalidas_se_explican_en_vez_de_reventar(): void
    {
        $tools = new BusinessTools($this->company);

        $this->assertArrayHasKey('error', $tools->ejecutar('resumen_ventas', ['desde' => '', 'hasta' => '']));
        $this->assertArrayHasKey('error', $tools->ejecutar('resumen_ventas', ['desde' => '2026-01-01']));
    }

    /** Un rango al revés se corrige solo en vez de devolver cero. */
    public function test_un_rango_invertido_se_corrige(): void
    {
        $resultado = (new BusinessTools($this->company))
            ->ejecutar('resumen_ventas', ['desde' => '2026-03-31', 'hasta' => '2026-03-01']);

        $this->assertSame('2026-03-01 a 2026-03-31', $resultado['periodo']);
    }

    // ------------------------------------------------------------- acceso

    /** Sin activar, la integración no responde y lo dice. */
    public function test_apagada_no_deja_conversar(): void
    {
        $this->configurar(['enabled' => false]);

        $this->assertStringContainsString('apagada', AiSettings::para($this->company)->motivoParaNoUsar());
    }

    /** En modo cuenta propia sin llave, no se cae a la de Tecmax. */
    public function test_cuenta_propia_sin_llave_no_usa_la_de_tecmax(): void
    {
        $this->configurar(['enabled' => true, 'mode' => AiSettings::MODE_OWN]);

        $ajustes = AiSettings::para($this->company);

        $this->assertNull($ajustes->apiKey(),
            'Caer a la llave de Tecmax le cobraría a Tecmax lo que el cliente eligió pagar aparte.');
        $this->assertStringContainsString('llave', $ajustes->motivoParaNoUsar());
    }

    /** La llave propia se guarda cifrada, nunca en claro. */
    public function test_la_llave_propia_se_guarda_cifrada(): void
    {
        $llave = 'sk-ant-secreta-'.random_int(100000, 999999);

        $this->configurar(['enabled' => true, 'mode' => AiSettings::MODE_OWN, 'api_key' => $llave]);

        $crudo = DB::table('companies')->where('id', $this->company->id)->value('settings');

        $this->assertStringNotContainsString($llave, $crudo,
            'La llave de Anthropic no puede quedar legible en la base.');

        $this->assertSame($llave, AiSettings::para($this->company->fresh())->apiKeyPropia());
    }

    /** Guardar sin escribir llave no borra la que ya estaba. */
    public function test_guardar_sin_llave_conserva_la_anterior(): void
    {
        $llave = 'sk-ant-original-'.random_int(100000, 999999);

        $this->configurar(['enabled' => true, 'mode' => AiSettings::MODE_OWN, 'api_key' => $llave]);
        $this->configurar(['enabled' => true, 'mode' => AiSettings::MODE_OWN, 'api_key' => '']);

        $this->assertSame($llave, AiSettings::para($this->company->fresh())->apiKeyPropia(),
            'El formulario nunca muestra la llave: un campo vacío significa «no la cambié».');
    }

    /** Sin saldo, la conversación no arranca. */
    public function test_sin_saldo_no_se_puede_conversar(): void
    {
        $this->configurar(['enabled' => true, 'mode' => AiSettings::MODE_TECMAX]);
        $this->dejarSaldoEn(0);

        $this->assertStringContainsString('saldo', AiSettings::para($this->company->fresh())->motivoParaNoUsar());
    }

    // ------------------------------------------------------------- tarifas

    /**
     * La aritmética del cobro, con las tarifas reales y el margen del negocio.
     *
     * Se fija aquí porque es plata y porque `AI_MARGIN` se presta a confusión:
     * es un multiplicador, no un porcentaje. El modelo de Tecmax es que el
     * cliente recargue 50, consuma 37,5 reales y Tecmax se quede con 12,5 —el
     * 25 % de lo facturado—, y eso da 1/(1-0.25) = 1,3333. Poner 1.25 dejaría
     * un 20 % sin que nada fallara.
     */
    public function test_el_cobro_deja_el_veinticinco_por_ciento_de_ganancia(): void
    {
        config(['ai.margin' => 1 / 0.75]);

        $this->configurar(['enabled' => true, 'mode' => AiSettings::MODE_TECMAX, 'model' => 'claude-sonnet-5']);
        $this->dejarSaldoEn(500);

        Http::fake(['*' => Http::response([
            'content' => [['type' => 'text', 'text' => 'Listo.']],
            // 1M de entrada (USD 3) + 1M de salida (USD 15) = USD 18.
            'usage' => ['input_tokens' => 1_000_000, 'output_tokens' => 1_000_000],
        ])]);

        $respuesta = app(AiAssistant::class)
            ->responder($this->nuevaConversacion(), 'Hola', $this->user);

        $costoReal = 18.0;                            // lo que se le paga a Anthropic
        $cobrado = (float) $respuesta->cost_usd;

        $this->assertEqualsWithDelta(24, $cobrado, 0.001, 'USD 18 al 1,3333 son USD 24.');

        $this->assertEqualsWithDelta(0.25, ($cobrado - $costoReal) / $cobrado, 0.001,
            'La ganancia debe ser el 25 % de lo que se le descuenta al cliente.');
    }

    /** La tasa del dólar ya no toca ningún cobro: solo se usa para mostrar. */
    public function test_la_tasa_del_dolar_no_altera_lo_que_se_cobra(): void
    {
        $this->configurar(['enabled' => true, 'mode' => AiSettings::MODE_TECMAX, 'model' => 'claude-sonnet-5']);
        $this->dejarSaldoEn(500);

        Http::fake(['*' => Http::response([
            'content' => [['type' => 'text', 'text' => 'Listo.']],
            'usage' => ['input_tokens' => 1_000_000, 'output_tokens' => 0],
        ])]);

        config(['ai.margin' => 1.0, 'ai.usd_to_cop' => 4200]);
        $conTasaBaja = (float) app(AiAssistant::class)
            ->responder($this->nuevaConversacion(), 'Hola', $this->user)->cost_usd;

        config(['ai.usd_to_cop' => 9999]);
        $conTasaAlta = (float) app(AiAssistant::class)
            ->responder($this->nuevaConversacion(), 'Hola', $this->user)->cost_usd;

        $this->assertEqualsWithDelta($conTasaBaja, $conTasaAlta, 0.000001,
            'Con el monedero en dólares, mover la tasa no puede cambiarle el saldo a nadie.');
        $this->assertEqualsWithDelta(3, $conTasaBaja, 0.001);
    }

    /** El multiplicador por descuento de saldo, visto como lo ve el comercial. */
    public function test_una_recarga_de_cincuenta_alcanza_para_treinta_y_siete_y_medio(): void
    {
        config(['ai.margin' => 1 / 0.75]);

        $this->configurar(['enabled' => true, 'mode' => AiSettings::MODE_TECMAX, 'model' => 'claude-sonnet-5']);

        // El cliente recarga US$ 50 y eso es lo que ve en su monedero.
        $this->dejarSaldoEn(50);

        // Consumo real de USD 37,50: 12,5M de tokens de entrada de Sonnet 5.
        Http::fake(['*' => Http::response([
            'content' => [['type' => 'text', 'text' => 'Listo.']],
            'usage' => ['input_tokens' => 12_500_000, 'output_tokens' => 0],
        ])]);

        app(AiAssistant::class)->responder($this->nuevaConversacion(), 'Hola', $this->user);

        $this->assertEqualsWithDelta(0, app(AiCredits::class)->saldo($this->company->fresh()), 0.05,
            'Con 50 recargados, 37,50 de consumo real deben dejar el monedero en cero.');
    }

    /** A costo, sin recargo, se cobra exactamente lo que cuesta. */
    public function test_sin_recargo_se_cobra_el_costo(): void
    {
        config(['ai.margin' => 1.0]);

        $this->configurar(['enabled' => true, 'mode' => AiSettings::MODE_TECMAX, 'model' => 'claude-haiku-4-5-20251001']);
        $this->dejarSaldoEn(100);

        Http::fake(['*' => Http::response([
            'content' => [['type' => 'text', 'text' => 'Listo.']],
            'usage' => ['input_tokens' => 1_000_000, 'output_tokens' => 0],
        ])]);

        $respuesta = app(AiAssistant::class)
            ->responder($this->nuevaConversacion(), 'Hola', $this->user);

        // Haiku 4.5: USD 1 por millón de tokens de entrada.
        $this->assertEqualsWithDelta(1, (float) $respuesta->cost_usd, 0.001);
    }

    /**
     * Una respuesta corta cuesta centésimas de centavo. Con dos decimales se
     * cobraría cero y el saldo no bajaría nunca.
     */
    public function test_un_consumo_muy_pequeno_no_se_redondea_a_cero(): void
    {
        config(['ai.margin' => 1.0]);

        $this->configurar(['enabled' => true, 'mode' => AiSettings::MODE_TECMAX, 'model' => 'claude-haiku-4-5-20251001']);
        $this->dejarSaldoEn(10);

        Http::fake(['*' => Http::response([
            'content' => [['type' => 'text', 'text' => 'Sí.']],
            'usage' => ['input_tokens' => 800, 'output_tokens' => 120],
        ])]);

        $respuesta = app(AiAssistant::class)
            ->responder($this->nuevaConversacion(), 'Hola', $this->user);

        $costo = (float) $respuesta->cost_usd;

        $this->assertGreaterThan(0, $costo, 'Un consumo real no puede quedar cobrado en cero.');
        $this->assertEqualsWithDelta(0.0014, $costo, 0.0001);
        $this->assertEqualsWithDelta(10 - $costo, app(AiCredits::class)->saldo($this->company->fresh()), 0.000001);
    }

    // -------------------------------------------------------------- saldo

    /** El saldo es el del último movimiento, y cada uno deja rastro. */
    public function test_el_saldo_se_lleva_como_libro_de_movimientos(): void
    {
        $creditos = app(AiCredits::class);
        $inicial = $creditos->saldo($this->company);

        $recarga = $creditos->recargar($this->company, 50, 'Prueba automatizada');
        $this->registrarMovimiento($recarga->id);

        $this->assertEqualsWithDelta($inicial + 50, (float) $recarga->balance_after, 0.000001);
        $this->assertEqualsWithDelta($inicial + 50, $creditos->saldo($this->company), 0.000001);

        $ajuste = $creditos->ajustar($this->company, -20, 'Prueba automatizada');
        $this->registrarMovimiento($ajuste->id);

        $this->assertEqualsWithDelta($inicial + 30, $creditos->saldo($this->company), 0.000001);
    }

    // -------------------------------------------------- conversación completa

    /** El camino feliz: se guarda la pregunta, la respuesta y se cobra. */
    public function test_una_conversacion_guarda_los_mensajes_y_descuenta_saldo(): void
    {
        config(['ai.margin' => 1.0]);

        $this->configurar(['enabled' => true, 'mode' => AiSettings::MODE_TECMAX, 'model' => 'claude-sonnet-5']);
        $this->dejarSaldoEn(100);

        Http::fake([
            '*' => Http::response([
                'content' => [['type' => 'text', 'text' => 'Vendiste **$1.000.000** este mes.']],
                'usage' => ['input_tokens' => 1_000_000, 'output_tokens' => 0],
            ]),
        ]);

        $conversacion = $this->nuevaConversacion();

        $respuesta = app(AiAssistant::class)->responder($conversacion, '¿Cuánto vendí?', $this->user);

        $this->assertSame(AiMessage::ROLE_ASSISTANT, $respuesta->role);
        $this->assertStringContainsString('1.000.000', $respuesta->content);
        $this->assertNull($respuesta->error);

        $this->assertSame(2, $conversacion->messages()->count(),
            'Deben quedar la pregunta y la respuesta.');

        // 1M de tokens de entrada de Sonnet 5 = USD 3, sin recargo en esta prueba.
        $this->assertEqualsWithDelta(3, (float) $respuesta->cost_usd, 0.001);
        $this->assertEqualsWithDelta(97, app(AiCredits::class)->saldo($this->company->fresh()), 0.001);
    }

    /** Con cuenta propia no se toca el saldo: le factura Anthropic al cliente. */
    public function test_con_cuenta_propia_no_se_descuenta_saldo(): void
    {
        $this->configurar([
            'enabled' => true,
            'mode' => AiSettings::MODE_OWN,
            'api_key' => 'sk-ant-del-cliente',
        ]);
        $this->dejarSaldoEn(100);

        Http::fake([
            '*' => Http::response([
                'content' => [['type' => 'text', 'text' => 'Listo.']],
                'usage' => ['input_tokens' => 1_000_000, 'output_tokens' => 1_000_000],
            ]),
        ]);

        $respuesta = app(AiAssistant::class)
            ->responder($this->nuevaConversacion(), 'Hola', $this->user);

        $this->assertEqualsWithDelta(0, (float) $respuesta->cost_usd, 0.000001);
        $this->assertEqualsWithDelta(100, app(AiCredits::class)->saldo($this->company->fresh()), 0.000001);
    }

    /** Claude consulta la base antes de responder, y queda registrado cuál usó. */
    public function test_se_registra_que_consultas_uso_para_responder(): void
    {
        $this->configurar(['enabled' => true, 'mode' => AiSettings::MODE_TECMAX]);
        $this->dejarSaldoEn(100);

        $llamadas = 0;

        Http::fake(function () use (&$llamadas) {
            $llamadas++;

            // Primera vuelta: pide datos. Segunda: responde con ellos.
            return $llamadas === 1
                ? Http::response([
                    'content' => [[
                        'type' => 'tool_use',
                        'id' => 'tu_1',
                        'name' => 'resumen_negocio',
                        'input' => [],
                    ]],
                    'usage' => ['input_tokens' => 100, 'output_tokens' => 50],
                ])
                : Http::response([
                    'content' => [['type' => 'text', 'text' => 'Tu empresa tiene sedes activas.']],
                    'usage' => ['input_tokens' => 200, 'output_tokens' => 80],
                ]);
        });

        $respuesta = app(AiAssistant::class)
            ->responder($this->nuevaConversacion(), '¿Cómo va mi negocio?', $this->user);

        $this->assertSame(2, $llamadas, 'Debió volver a llamar con el resultado de la consulta.');
        $this->assertSame(['resumen_negocio'], $respuesta->consultasUsadas());
        $this->assertSame(300, $respuesta->input_tokens, 'Los tokens de las dos vueltas se suman.');
    }

    /** Un error de Anthropic queda en la conversación, no la rompe. */
    public function test_un_error_de_la_api_queda_registrado_en_la_conversacion(): void
    {
        $this->configurar(['enabled' => true, 'mode' => AiSettings::MODE_TECMAX]);
        $this->dejarSaldoEn(100);

        Http::fake(['*' => Http::response(['error' => ['message' => 'nope']], 401)]);

        $respuesta = app(AiAssistant::class)
            ->responder($this->nuevaConversacion(), 'Hola', $this->user);

        $this->assertNotNull($respuesta->error);
        $this->assertStringContainsString('llave', $respuesta->error,
            'Un 401 debe explicarse como problema de la llave, no como «invalid_request_error».');
        $this->assertEqualsWithDelta(0, (float) $respuesta->cost_usd, 0.000001);
    }

    /** Bloqueada, ni siquiera se llama a la API. */
    public function test_bloqueada_no_llama_a_la_api(): void
    {
        $this->configurar(['enabled' => false]);
        Http::fake();

        $this->expectException(RuntimeException::class);

        try {
            app(AiAssistant::class)->responder($this->nuevaConversacion(), 'Hola', $this->user);
        } finally {
            Http::assertNothingSent();
        }
    }

    /** La conversación se retoma: el historial viaja con la nueva pregunta. */
    public function test_la_conversacion_se_puede_retomar(): void
    {
        $this->configurar(['enabled' => true, 'mode' => AiSettings::MODE_TECMAX]);
        $this->dejarSaldoEn(100);

        Http::fake(['*' => Http::response([
            'content' => [['type' => 'text', 'text' => 'Ok.']],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ])]);

        $conversacion = $this->nuevaConversacion();
        $asistente = app(AiAssistant::class);

        $asistente->responder($conversacion, 'Primera pregunta', $this->user);
        $asistente->responder($conversacion, 'Segunda pregunta', $this->user);

        $this->assertSame(4, $conversacion->messages()->count());

        Http::assertSent(function ($request) {
            $mensajes = $request->data()['messages'] ?? [];

            // En la segunda llamada tiene que ir la primera pregunta: sin eso,
            // «¿y el mes pasado?» no significaría nada.
            return count($mensajes) < 3
                || collect($mensajes)->contains(fn ($m) => ($m['content'] ?? null) === 'Primera pregunta');
        });
    }

    /** El título sale de la primera pregunta: nadie titula sus conversaciones. */
    public function test_el_titulo_sale_de_la_primera_pregunta(): void
    {
        $this->assertSame('¿Cuánto vendí este mes?',
            AiConversation::tituloDesde('  ¿Cuánto vendí   este mes?  '));

        $this->assertSame('Nueva conversación', AiConversation::tituloDesde('   '));
        $this->assertLessThanOrEqual(80, mb_strlen(AiConversation::tituloDesde(str_repeat('a', 300))));
    }

    // --------------------------------------------------------- auxiliares

    private function configurar(array $datos): void
    {
        AiSettings::para($this->company)->guardar($datos);
        $this->company = $this->company->fresh();
        app(CurrentCompany::class)->set($this->company);
    }

    private function nuevaConversacion(): AiConversation
    {
        $conversacion = AiConversation::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
            'title' => 'ZZ Conversación de prueba',
            'last_message_at' => now(),
        ]);

        $this->limpiar[] = function () use ($conversacion) {
            DB::table('ai_credit_movements')->where('ai_conversation_id', $conversacion->id)->delete();
            DB::table('ai_messages')->where('ai_conversation_id', $conversacion->id)->delete();
            DB::table('ai_conversations')->where('id', $conversacion->id)->delete();
        };

        return $conversacion;
    }

    /** Deja el saldo exactamente en un valor, sin depender de lo que hubiera. */
    private function dejarSaldoEn(float $objetivo): void
    {
        $creditos = app(AiCredits::class);
        $actual = $creditos->saldo($this->company);

        if (abs($objetivo - $actual) < 0.01) {
            return;
        }

        $movimiento = $creditos->ajustar($this->company, $objetivo - $actual, 'Preparación de prueba');
        $this->registrarMovimiento($movimiento->id);
    }

    private function registrarMovimiento(int $id): void
    {
        $this->limpiar[] = fn () => DB::table('ai_credit_movements')->where('id', $id)->delete();
    }
}
