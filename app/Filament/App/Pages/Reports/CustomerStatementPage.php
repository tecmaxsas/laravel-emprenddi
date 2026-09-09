<?php

namespace App\Filament\App\Pages\Reports;

use App\Mail\CustomerStatementMail;
use App\Models\CustomerStatementShare;
use App\Models\Payment;
use App\Models\ThirdParty;
use App\Services\Sales\CustomerAdvanceService;
use App\Services\Sales\CustomerStatement;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Hoja de cuenta de un cliente: sus movimientos y el saldo despues de cada uno.
 *
 * Responde la pregunta que el reporte de cartera no responde: no "cuanto me
 * deben en total" sino "de donde sale lo que este cliente me debe".
 */
class CustomerStatementPage extends Page implements HasActions, HasForms
{
    use InteractsWithActions, InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-user-circle';

    protected static ?string $navigationLabel = 'Estado de cuenta';

    protected static ?string $navigationGroup = 'Reportes operativos';

    protected static ?string $title = 'Estado de cuenta del cliente';

    protected static ?int $navigationSort = 41;

    protected static string $view = 'filament.app.pages.reports.customer-statement';

    /** @var array<string, mixed> */
    public ?array $filters = [];

    /** @var array<string, mixed>|null */
    public ?array $statement = null;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can('reports.accounts_receivable');
    }

    public function mount(): void
    {
        $this->form->fill([
            'third_party_id' => null,
            'from' => null,
            'to' => null,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->statePath('filters')
            ->schema([
                Forms\Components\Grid::make(3)->schema([
                    Forms\Components\Select::make('third_party_id')
                        ->label('Cliente')
                        ->placeholder('Busca por nombre o documento')
                        ->searchable()
                        ->required()
                        ->live()
                        ->afterStateUpdated(fn () => $this->generate())
                        ->getSearchResultsUsing(fn (string $search) => ThirdParty::query()
                            ->where('company_id', auth()->user()?->company_id)
                            ->where('is_customer', true)
                            ->where(function ($q) use ($search) {
                                $q->where('name', 'ilike', "%{$search}%")
                                    ->orWhere('document_number', 'like', "%{$search}%");
                            })
                            ->orderBy('name')
                            ->limit(40)
                            ->get()
                            ->mapWithKeys(fn (ThirdParty $t) => [$t->id => $t->document_number.' — '.$t->name])
                            ->all())
                        ->getOptionLabelUsing(function ($value) {
                            $t = ThirdParty::find($value);

                            return $t ? $t->document_number.' — '.$t->name : null;
                        }),

                    Forms\Components\DatePicker::make('from')
                        ->label('Desde')
                        ->live()
                        ->afterStateUpdated(fn () => $this->generate())
                        ->helperText('Vacío = desde el principio.'),

                    Forms\Components\DatePicker::make('to')
                        ->label('Hasta')
                        ->live()
                        ->afterStateUpdated(fn () => $this->generate()),
                ]),
            ]);
    }

    public function generate(): void
    {
        $customer = $this->customer();

        if (! $customer) {
            $this->statement = null;

            return;
        }

        $this->statement = app(CustomerStatement::class)->build(
            $customer,
            $this->filters['from'] ?? null,
            $this->filters['to'] ?? null,
        );
    }

    /** Modal para mandar la hoja por correo, con el saldo ya en el cuerpo. */
    public function sendEmailAction(): Action
    {
        return Action::make('sendEmail')
            ->label('Enviar por correo')
            ->icon('heroicon-o-envelope')
            ->color('primary')
            ->modalHeading('Enviar el estado de cuenta')
            ->modalSubmitActionLabel('Enviar')
            ->fillForm(fn () => [
                'to' => $this->customer()?->email,
                'subject' => 'Estado de cuenta — '.(auth()->user()?->company?->name ?? ''),
                'body' => $this->defaultEmailBody(),
            ])
            ->form([
                Forms\Components\TextInput::make('to')
                    ->label('Para')
                    ->required()
                    ->helperText('Varias direcciones separadas por coma.'),
                Forms\Components\TextInput::make('subject')
                    ->label('Asunto')
                    ->required(),
                Forms\Components\Textarea::make('body')
                    ->label('Mensaje')
                    ->rows(5)
                    ->required(),
            ])
            ->action(fn (array $data) => $this->sendByEmail($data));
    }

    /**
     * Compartir la hoja por WhatsApp.
     *
     * No manda el PDF: manda un enlace. Dos razones —adjuntar un archivo exige
     * un API de WhatsApp que la empresa no tiene, y un enlace se abre en el
     * celular sin descargar nada y muestra el saldo del día en que se abre, no
     * el del día en que se envió—.
     *
     * El envío lo hace el usuario desde su propio WhatsApp Web: aquí solo se
     * arma el mensaje y se abre la conversación.
     */
    public function shareWhatsappAction(): Action
    {
        return Action::make('shareWhatsapp')
            ->label('Compartir por WhatsApp')
            ->icon('heroicon-o-chat-bubble-left-right')
            ->color('success')
            ->modalHeading('Enviar el estado de cuenta por WhatsApp')
            ->modalDescription('Se abre tu WhatsApp Web con el mensaje listo. El cliente recibe un '
                .'enlace donde ve su cuenta actualizada, sin descargar nada.')
            ->modalSubmitActionLabel('Abrir WhatsApp')
            ->fillForm(fn () => [
                'phone' => $this->defaultWhatsappNumber(),
                'days' => CustomerStatementShare::DIAS_POR_DEFECTO,
            ])
            ->form([
                Forms\Components\TextInput::make('phone')
                    ->label('Número de WhatsApp')
                    ->tel()
                    ->required()
                    ->maxLength(25)
                    ->placeholder('3105551234')
                    ->helperText('Se sugiere el del tercero, pero puedes cambiarlo para mandarlo a otro '
                        .'número. Si escribes 10 dígitos se asume Colombia; para otro país incluye el '
                        .'indicativo.'),

                Forms\Components\Select::make('days')
                    ->label('El enlace caduca en')
                    ->options([
                        7 => '7 días',
                        15 => '15 días',
                        30 => '30 días',
                        90 => '90 días',
                    ])
                    ->default(CustomerStatementShare::DIAS_POR_DEFECTO)
                    ->native(false)
                    ->required()
                    ->helperText('Después de esa fecha el enlace deja de funcionar. Es información '
                        .'financiera del cliente viajando por un chat que se puede reenviar.'),
            ])
            ->action(fn (array $data) => $this->shareByWhatsapp($data));
    }

    /** @param  array<string, mixed>  $data */
    public function shareByWhatsapp(array $data): void
    {
        $customer = $this->customer();

        if (! $customer) {
            Notification::make()->warning()->title('Elige un cliente primero')->send();

            return;
        }

        $numero = $this->normalizarWhatsapp((string) $data['phone']);

        if (! $numero) {
            Notification::make()->danger()
                ->title('Número inválido')
                ->body('Escribe solo dígitos, con indicativo si es de otro país.')
                ->send();

            return;
        }

        $enlace = CustomerStatementShare::paraCliente(
            $customer,
            $this->filters['from'] ?? null,
            $this->filters['to'] ?? null,
            $numero,
            (int) $data['days'],
        );

        $this->generate();

        $mensaje = rawurlencode($this->whatsappMessage($customer, $enlace->publicUrl()));

        // Se abre en otra pestaña porque el usuario ya tiene WhatsApp Web
        // abierto: mandarlo en la misma le haría perder la pantalla.
        $this->js('window.open('.json_encode("https://wa.me/{$numero}?text={$mensaje}").', "_blank")');

        Notification::make()->success()
            ->title('Enlace generado')
            ->body('Se abrió WhatsApp con el mensaje listo. El enlace caduca el '
                .$enlace->expires_at->format('d/m/Y').'.')
            ->send();
    }

    /** El texto que va en el chat, con el saldo ya adentro. */
    public function whatsappMessage(ThirdParty $customer, string $enlace): string
    {
        $empresa = auth()->user()?->company?->name ?? '';
        $saldo = (float) ($this->statement['due'] ?? 0);
        $nombre = Str::of($customer->name)->trim()->explode(' ')->first();

        $situacion = match (true) {
            abs($saldo) <= 0.01 => 'A la fecha no registra saldo pendiente.',
            $saldo < 0 => 'A la fecha tiene un saldo a favor de $'
                .number_format(abs($saldo), 0, ',', '.').'.',
            default => 'A la fecha su saldo pendiente es de $'
                .number_format($saldo, 0, ',', '.').'.',
        };

        return "Hola {$nombre}, le compartimos su estado de cuenta con {$empresa}.\n\n"
            ."{$situacion}\n\n"
            ."Puede consultarlo aquí:\n{$enlace}";
    }

    /** El teléfono del tercero, priorizando el celular. */
    public function defaultWhatsappNumber(): ?string
    {
        $cliente = $this->customer();

        return $cliente?->mobile ?: $cliente?->phone;
    }

    /**
     * El número en el formato que espera wa.me: solo dígitos, con indicativo.
     * Diez dígitos que empiezan por 3 son un celular colombiano sin indicativo.
     */
    protected function normalizarWhatsapp(string $telefono): ?string
    {
        $numero = preg_replace('/\D+/', '', $telefono);

        if (strlen((string) $numero) < 7) {
            return null;
        }

        if (strlen($numero) === 10 && str_starts_with($numero, '3')) {
            return '57'.$numero;
        }

        return $numero;
    }

    /**
     * Registrar plata que el cliente abona por adelantado.
     *
     * Vive aqui porque es donde se mira la cuenta del cliente: se ve el saldo,
     * se recibe el abono y se ve el efecto de una vez.
     */
    public function registerAdvanceAction(): Action
    {
        return Action::make('registerAdvance')
            ->label('Registrar anticipo')
            ->icon('heroicon-o-banknotes')
            ->color('success')
            ->modalHeading('Registrar un anticipo del cliente')
            ->modalDescription('Se aplica automáticamente a las facturas pendientes, de la más antigua a la '
                .'más nueva. Lo que sobre queda como saldo a favor para la próxima venta.')
            ->modalSubmitActionLabel('Registrar')
            ->fillForm(fn () => ['date' => now()->toDateString(), 'payment_method' => 'cash'])
            ->form([
                Forms\Components\TextInput::make('amount')
                    ->label('Valor')
                    ->numeric()
                    ->minValue(1)
                    ->prefix('$')
                    ->required(),
                Forms\Components\DatePicker::make('date')
                    ->label('Fecha')
                    ->required(),
                Forms\Components\Select::make('payment_method')
                    ->label('Forma de pago')
                    ->options(Payment::PAYMENT_METHODS)
                    ->native(false)
                    ->required(),
                Forms\Components\TextInput::make('reference')
                    ->label('Referencia')
                    ->helperText('Número de consignación, recibo, etc.'),
                Forms\Components\Textarea::make('notes')
                    ->label('Observaciones')
                    ->rows(2),
            ])
            ->action(function (array $data) {
                $customer = $this->customer();

                if (! $customer) {
                    Notification::make()->warning()->title('Elige un cliente primero')->send();

                    return;
                }

                try {
                    $servicio = app(CustomerAdvanceService::class);
                    $servicio->register($customer, $data);
                    $this->generate();

                    $aFavor = $servicio->availableBalance($customer);

                    Notification::make()->success()
                        ->title('Anticipo registrado')
                        ->body($aFavor > 0.01
                            ? 'Quedó $'.number_format($aFavor, 0, ',', '.').' de saldo a favor.'
                            : 'Se aplicó completo a las facturas pendientes.')
                        ->send();
                } catch (\Throwable $e) {
                    Notification::make()->danger()
                        ->title('No se pudo registrar')
                        ->body($e->getMessage())
                        ->persistent()
                        ->send();
                }
            });
    }

    public function downloadPdf(): ?StreamedResponse
    {
        $customer = $this->customer();

        if (! $customer) {
            Notification::make()->warning()->title('Elige un cliente primero')->send();

            return null;
        }

        $pdf = $this->buildPdf($customer);
        $nombre = 'estado-cuenta-'.Str::slug($customer->name).'.pdf';

        return response()->streamDownload(fn () => print ($pdf), $nombre);
    }

    /** @param  array<string, mixed>  $data */
    public function sendByEmail(array $data): void
    {
        $customer = $this->customer();

        if (! $customer) {
            Notification::make()->warning()->title('Elige un cliente primero')->send();

            return;
        }

        $destinatarios = collect(explode(',', (string) $data['to']))
            ->map(fn ($c) => trim($c))
            ->filter(fn ($c) => filter_var($c, FILTER_VALIDATE_EMAIL))
            ->values();

        if ($destinatarios->isEmpty()) {
            Notification::make()->danger()
                ->title('Ningún correo válido')
                ->body('Revisa las direcciones: se separan con coma.')
                ->send();

            return;
        }

        try {
            $this->generate();

            Mail::to($destinatarios->all())->send(new CustomerStatementMail(
                $customer,
                auth()->user()?->company,
                $data['subject'],
                $data['body'],
                (float) ($this->statement['due'] ?? 0),
                $this->buildPdf($customer),
            ));

            Notification::make()->success()
                ->title('Estado de cuenta enviado')
                ->body($destinatarios->implode(', '))
                ->send();
        } catch (\Throwable $e) {
            Notification::make()->danger()
                ->title('No se pudo enviar')
                ->body($e->getMessage())
                ->persistent()
                ->send();
        }
    }

    /** Asunto y cuerpo por defecto del correo, ya con el saldo. */
    public function defaultEmailBody(): string
    {
        $saldo = (float) ($this->statement['due'] ?? 0);

        if (abs($saldo) <= 0.01) {
            return "Buen día,\n\nAdjuntamos su estado de cuenta. A la fecha no registra saldo pendiente.\n\nGracias.";
        }

        if ($saldo < 0) {
            return "Buen día,\n\nAdjuntamos su estado de cuenta. A la fecha tiene un saldo a favor de $"
                .number_format(abs($saldo), 0, ',', '.')."\n\nGracias.";
        }

        return "Buen día,\n\nAdjuntamos su estado de cuenta. A la fecha su saldo pendiente es de $"
            .number_format($saldo, 0, ',', '.')."\n\nQuedamos atentos.";
    }

    protected function customer(): ?ThirdParty
    {
        $id = $this->filters['third_party_id'] ?? null;

        return $id
            ? ThirdParty::query()->where('company_id', auth()->user()?->company_id)->find($id)
            : null;
    }

    protected function buildPdf(ThirdParty $customer): string
    {
        $datos = app(CustomerStatement::class)->build(
            $customer,
            $this->filters['from'] ?? null,
            $this->filters['to'] ?? null,
        );

        return Pdf::loadView('sales.customer-statement-pdf', [
            ...$datos,
            'company' => auth()->user()?->company,
        ])->setPaper('letter')->output();
    }
}
