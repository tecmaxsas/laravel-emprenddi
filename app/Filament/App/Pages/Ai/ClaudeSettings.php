<?php

namespace App\Filament\App\Pages\Ai;

use App\Models\AiCreditMovement;
use App\Models\Company;
use App\Services\Ai\AiCredits;
use App\Services\Ai\AiSettings;
use App\Support\CurrentCompany;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;

/**
 * Cómo se conecta la empresa con Claude.
 *
 * Las dos formas son excluyentes a propósito: o consume del saldo que le vende
 * Tecmax, o pone su propia llave y le factura Anthropic. Mezclarlas —caer a la
 * llave de Tecmax cuando la propia falla— haría que un error de configuración
 * del cliente se le cobrara a Tecmax sin que nadie se enterara.
 */
class ClaudeSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-key';

    protected static ?string $navigationLabel = 'Conexión y saldo';

    protected static ?string $navigationGroup = 'Claude AI';

    protected static ?int $navigationSort = 20;

    protected static ?string $title = 'Conexión con Claude';

    protected static ?string $slug = 'claude/conexion';

    protected static string $view = 'filament.app.pages.ai.claude-settings';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can('ai.manage');
    }

    public function mount(): void
    {
        $ajustes = $this->ajustes();

        $this->form->fill([
            'enabled' => $ajustes->habilitado(),
            'mode' => $ajustes->modo(),
            'model' => $ajustes->modelo(),
            'api_key' => null,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->statePath('data')
            ->schema([
                Forms\Components\Section::make('Activación')
                    ->schema([
                        Forms\Components\Toggle::make('enabled')
                            ->label('Activar Claude AI para esta empresa')
                            ->helperText('Apagado, la sección de conversación no responde.'),
                    ]),

                Forms\Components\Section::make('Cómo se paga')
                    ->schema([
                        Forms\Components\Radio::make('mode')
                            ->label('')
                            ->options(AiSettings::MODES)
                            ->descriptions([
                                AiSettings::MODE_TECMAX => 'Tecmax pone la conexión y tú consumes de un saldo '
                                    .'que recargas con el equipo comercial. No necesitas cuenta en Anthropic.',
                                AiSettings::MODE_OWN => 'Conectas tu propia cuenta de Anthropic con tu llave. '
                                    .'Anthropic te factura a ti directamente y no consumes saldo.',
                            ])
                            ->default(AiSettings::MODE_TECMAX)
                            ->live(),

                        Forms\Components\TextInput::make('api_key')
                            ->label('Llave de Anthropic (API key)')
                            ->password()
                            ->revealable()
                            ->autocomplete(false)
                            ->maxLength(200)
                            ->placeholder(fn () => $this->ajustes()->pistaDeLlave()
                                ? 'Ya hay una llave guardada ('.$this->ajustes()->pistaDeLlave().'). '
                                    .'Escribe una nueva solo si quieres reemplazarla.'
                                : 'sk-ant-…')
                            ->helperText('Se obtiene en console.anthropic.com → API Keys. '
                                .'Se guarda cifrada y no se vuelve a mostrar completa.')
                            ->visible(fn (Forms\Get $get) => $get('mode') === AiSettings::MODE_OWN),

                        Forms\Components\Checkbox::make('remove_api_key')
                            ->label('Borrar la llave guardada')
                            ->visible(fn (Forms\Get $get) => $get('mode') === AiSettings::MODE_OWN
                                && $this->ajustes()->pistaDeLlave() !== null),
                    ]),

                Forms\Components\Section::make('Modelo')
                    ->schema([
                        Forms\Components\Select::make('model')
                            ->label('Qué modelo usar')
                            ->options(collect(config('ai.models'))->map(fn ($m) => $m['label'])->all())
                            ->default(config('ai.default_model'))
                            ->native(false)
                            ->helperText('El más capaz entiende mejor las preguntas difíciles; el económico '
                                .'responde más rápido y consume menos saldo.'),
                    ]),
            ]);
    }

    public function guardar(): void
    {
        abort_unless(auth()->user()?->can('ai.manage'), 403);

        $estado = $this->form->getState();

        if (($estado['mode'] ?? null) === AiSettings::MODE_OWN
            && empty($estado['api_key'])
            && ! $this->ajustes()->pistaDeLlave()) {
            Notification::make()
                ->title('Falta la llave')
                ->body('Para conectar tu propia cuenta necesitas cargar la llave de Anthropic.')
                ->warning()
                ->send();

            return;
        }

        $this->ajustes()->guardar($estado);

        // La llave no se deja en el formulario después de guardarla.
        $this->data['api_key'] = null;
        $this->data['remove_api_key'] = false;

        Notification::make()->title('Conexión guardada')->success()->send();
    }

    // ------------------------------------------------------------- consultas

    public function getEmpresaProperty(): ?Company
    {
        return app(CurrentCompany::class)->get()
            ?? Company::find(auth()->user()?->company_id);
    }

    public function getSaldoProperty(): float
    {
        return $this->empresa ? app(AiCredits::class)->saldo($this->empresa) : 0.0;
    }

    public function getUsaCuentaPropiaProperty(): bool
    {
        return ($this->data['mode'] ?? $this->ajustes()->modo()) === AiSettings::MODE_OWN;
    }

    /** @return Collection<int, AiCreditMovement> */
    public function getMovimientosProperty(): Collection
    {
        return AiCreditMovement::query()
            ->orderByDesc('id')
            ->limit(25)
            ->get();
    }

    public function getWhatsappRecargaProperty(): string
    {
        $numero = preg_replace('/\D+/', '', (string) config('ai.sales_whatsapp'));

        $texto = rawurlencode(sprintf(
            'Hola, soy %s de %s. Quiero recargar saldo de Claude AI en Emprenddi.',
            auth()->user()?->name ?? '',
            $this->empresa?->name ?? '',
        ));

        return "https://wa.me/{$numero}?text={$texto}";
    }

    private function ajustes(): AiSettings
    {
        return AiSettings::para($this->empresa);
    }
}
