<?php

namespace Tests\Feature;

use App\Filament\App\Pages\Ai\ClaudeChat;
use App\Filament\App\Pages\Ai\ClaudeSettings;
use App\Models\AiConversation;
use App\Models\Company;
use App\Models\User;
use App\Services\Ai\AiSettings;
use App\Support\CurrentCompany;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Las pantallas de Claude AI.
 *
 * Usa la base de desarrollo y borra lo que crea en tearDown.
 */
class ClaudeAiPagesTest extends TestCase
{
    private Company $company;

    private User $user;

    /** @var list<callable> */
    private array $limpiar = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::query()
            ->whereNotNull('company_id')
            ->orderBy('id')
            ->get()
            ->first(fn (User $u) => $u->can('ai.use') && $u->can('ai.manage'))
            ?? $this->markTestSkipped('Ningún usuario de la base tiene los permisos de Claude.');

        $this->company = Company::findOrFail($this->user->company_id);

        $this->actingAs($this->user);
        app(CurrentCompany::class)->set($this->company);
        Filament::setCurrentPanel(Filament::getPanel('app'));

        $originales = $this->company->settings;
        $this->limpiar[] = function () use ($originales) {
            DB::table('companies')->where('id', $this->company->id)
                ->update(['settings' => json_encode($originales)]);
        };

        config(['ai.api_key' => 'sk-ant-de-prueba', 'ai.sales_whatsapp' => '573001112233']);
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->limpiar) as $fn) {
            $fn();
        }
        $this->limpiar = [];

        parent::tearDown();
    }

    public function test_las_dos_pantallas_abren(): void
    {
        Livewire::test(ClaudeChat::class)->assertOk();
        Livewire::test(ClaudeSettings::class)->assertOk();
    }

    /** Apagada, la pantalla lo explica en vez de dejar escribir al vacío. */
    public function test_apagada_la_pantalla_explica_el_motivo(): void
    {
        $this->configurar(['enabled' => false]);

        Livewire::test(ClaudeChat::class)
            ->assertOk()
            ->assertSee('apagada');
    }

    /** El botón de recarga arma un WhatsApp al comercial con la empresa. */
    public function test_el_boton_de_whatsapp_lleva_al_equipo_comercial(): void
    {
        $enlace = Livewire::test(ClaudeChat::class)->instance()->whatsappRecarga;

        $this->assertStringStartsWith('https://wa.me/573001112233', $enlace);
        $this->assertStringContainsString(rawurlencode($this->company->name), $enlace);
    }

    /** Preguntar crea la conversación, la titula y guarda los dos mensajes. */
    public function test_preguntar_crea_la_conversacion_y_la_titula(): void
    {
        $this->configurar(['enabled' => true, 'mode' => AiSettings::MODE_OWN, 'api_key' => 'sk-ant-propia']);

        Http::fake(['*' => Http::response([
            'content' => [['type' => 'text', 'text' => 'Respuesta de prueba.']],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
        ])]);

        $componente = Livewire::test(ClaudeChat::class)
            ->set('pregunta', 'ZZ ¿Cuánto vendí ayer?')
            ->call('preguntar')
            ->assertOk();

        $conversacion = AiConversation::withoutGlobalScopes()
            ->where('user_id', $this->user->id)
            ->where('title', 'like', 'ZZ %')
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($conversacion, 'No se creó la conversación.');
        $this->registrarLimpieza($conversacion->id);

        $this->assertSame('ZZ ¿Cuánto vendí ayer?', $conversacion->title);
        $this->assertSame(2, $conversacion->messages()->count());
        $this->assertSame('', $componente->get('pregunta'), 'El campo debe quedar vacío tras enviar.');
    }

    /** Una conversación anterior se puede volver a abrir con su historial. */
    public function test_una_conversacion_anterior_se_puede_retomar(): void
    {
        $conversacion = AiConversation::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
            'title' => 'ZZ Conversación vieja',
            'last_message_at' => now()->subDay(),
        ]);
        $this->registrarLimpieza($conversacion->id);

        $conversacion->messages()->create([
            'role' => 'user',
            'content' => 'ZZ pregunta de ayer',
            'created_at' => now()->subDay(),
        ]);

        Livewire::test(ClaudeChat::class)
            ->call('abrir', $conversacion->id)
            ->assertOk()
            ->assertSee('ZZ pregunta de ayer');
    }

    /** Nadie puede abrir la conversación de otro usuario por id. */
    public function test_no_se_puede_abrir_la_conversacion_de_otro_usuario(): void
    {
        $otro = User::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('id', '!=', $this->user->id)
            ->value('id');

        $ajena = AiConversation::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'user_id' => $otro,
            'title' => 'ZZ Conversación ajena',
            'last_message_at' => now(),
        ]);
        $this->registrarLimpieza($ajena->id);

        $componente = Livewire::test(ClaudeChat::class)->call('abrir', $ajena->id);

        $this->assertNotSame($ajena->id, $componente->get('conversationId'),
            'El historial es de cada usuario: no se abre por id ajeno.');
    }

    /** Guardar la conexión persiste el modo y cifra la llave. */
    public function test_la_configuracion_guarda_el_modo_y_la_llave(): void
    {
        Livewire::test(ClaudeSettings::class)
            ->set('data.enabled', true)
            ->set('data.mode', AiSettings::MODE_OWN)
            ->set('data.model', 'claude-haiku-4-5-20251001')
            ->set('data.api_key', 'sk-ant-zz-de-prueba')
            ->call('guardar')
            ->assertOk();

        $ajustes = AiSettings::para($this->company->fresh());

        $this->assertTrue($ajustes->habilitado());
        $this->assertTrue($ajustes->usaCuentaPropia());
        $this->assertSame('claude-haiku-4-5-20251001', $ajustes->modelo());
        $this->assertSame('sk-ant-zz-de-prueba', $ajustes->apiKeyPropia());
    }

    /** Sin permiso de gestión, la pantalla de conexión no es accesible. */
    public function test_la_configuracion_exige_su_permiso(): void
    {
        $sinPermiso = User::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'name' => 'ZZ Usuario sin Claude',
            'email' => 'zz-sin-claude-'.random_int(10000, 99999).'@example.test',
            'password' => bcrypt('ZZclaveDePrueba123'),
            'active' => true,
        ]);

        $this->actingAs($sinPermiso);

        try {
            $this->assertFalse(ClaudeSettings::canAccess());
            $this->assertFalse(ClaudeChat::canAccess());
        } finally {
            DB::table('audit_logs')->where('auditable_type', User::class)
                ->where('auditable_id', $sinPermiso->id)->delete();
            DB::table('users')->where('id', $sinPermiso->id)->delete();
        }
    }

    private function configurar(array $datos): void
    {
        AiSettings::para($this->company)->guardar($datos);
        $this->company = $this->company->fresh();
        app(CurrentCompany::class)->set($this->company);
    }

    private function registrarLimpieza(int $conversacionId): void
    {
        $this->limpiar[] = function () use ($conversacionId) {
            DB::table('ai_credit_movements')->where('ai_conversation_id', $conversacionId)->delete();
            DB::table('ai_messages')->where('ai_conversation_id', $conversacionId)->delete();
            DB::table('ai_conversations')->where('id', $conversacionId)->delete();
        };
    }
}
