<?php

namespace App\Filament\App\Pages\Reports;

use App\Mail\CustomerStatementMail;
use App\Models\ThirdParty;
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
