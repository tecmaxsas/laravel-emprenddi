<?php

namespace App\Filament\App\Resources\OrderTaking\OrderResource\Pages;

use App\Filament\App\Resources\OrderTaking\OrderResource;
use App\Mail\OrderTaking\OrderPdfMail;
use App\Models\Account;
use App\Models\OrderTaking\EmailLog;
use App\Models\OrderTaking\Order;
use App\Models\OrderTaking\Payment;
use App\Services\OrderTaking\OrderEngine;
use Filament\Actions;
use Filament\Forms;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Mail;

class ViewOrder extends ViewRecord
{
    protected static string $resource = OrderResource::class;

    public function getTitle(): string
    {
        return 'Pedido '.$this->record->fullNumber();
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('print')
                ->label('PDF')->icon('heroicon-o-printer')->color('gray')
                ->url(fn (Order $r) => route('order-taking.orders.pdf', $r->id))
                ->openUrlInNewTab(),

            Actions\Action::make('email')
                ->label('Enviar por correo')
                ->icon('heroicon-o-paper-airplane')
                ->color('info')
                ->visible(fn () => auth()->user()?->can('order_taking.send_email'))
                ->form([
                    Forms\Components\TextInput::make('to')
                        ->label('Destinatario principal')
                        ->email()->required()
                        ->default(fn (Order $r) => $r->customer?->email),
                    Forms\Components\TagsInput::make('cc')
                        ->label('CC (destinatarios adicionales)')
                        ->placeholder('Enter para agregar')
                        ->helperText('Se envía copia a estos correos.'),
                    Forms\Components\TextInput::make('subject')
                        ->label('Asunto')->required()
                        ->default(fn (Order $r) => "Pedido {$r->fullNumber()} — ".auth()->user()?->company?->name),
                    Forms\Components\Textarea::make('body')
                        ->label('Mensaje (opcional)')
                        ->rows(4)
                        ->placeholder('Adjunto el pedido para su revisión...'),
                ])
                ->action(function (Order $r, array $data) {
                    $company = auth()->user()?->company;
                    $to = trim($data['to']);
                    $cc = array_filter(array_map('trim', $data['cc'] ?? []));

                    try {
                        if (! class_exists(\Barryvdh\DomPDF\Facade\Pdf::class)) {
                            Notification::make()
                                ->danger()
                                ->title('Falta instalar el paquete de PDF')
                                ->body('Ejecuta en la VM: composer install en el container app.')
                                ->persistent()->send();
                            return;
                        }
                        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('order-taking.order-pdf', [
                            'order' => $r->load(['items.product', 'customer', 'priceList', 'seller']),
                            'company' => $company,
                        ]);

                        $mailable = new OrderPdfMail(
                            order: $r,
                            company: $company,
                            subject: $data['subject'],
                            body: $data['body'] ?? '',
                            pdfContent: $pdf->output(),
                        );

                        $mail = Mail::to($to);
                        if (! empty($cc)) $mail->cc($cc);
                        $mail->send($mailable);

                        EmailLog::create([
                            'company_id' => $r->company_id,
                            'order_id' => $r->id,
                            'sent_at' => now(),
                            'to_address' => $to,
                            'cc_addresses' => implode(', ', $cc),
                            'subject' => $data['subject'],
                            'sent_by_user_id' => auth()->id(),
                            'status' => 'sent',
                        ]);

                        Notification::make()
                            ->success()
                            ->title('Correo enviado')
                            ->body("Se envió a {$to}".(count($cc) ? ' (+'.count($cc).' CC)' : ''))
                            ->send();
                    } catch (\Throwable $e) {
                        EmailLog::create([
                            'company_id' => $r->company_id,
                            'order_id' => $r->id,
                            'sent_at' => now(),
                            'to_address' => $to,
                            'cc_addresses' => implode(', ', $cc),
                            'subject' => $data['subject'] ?? '',
                            'sent_by_user_id' => auth()->id(),
                            'status' => 'failed',
                            'error_message' => $e->getMessage(),
                        ]);
                        Notification::make()
                            ->danger()->title('No se pudo enviar')->body($e->getMessage())
                            ->persistent()->send();
                    }
                }),

            Actions\Action::make('confirm')
                ->label('Confirmar pedido')
                ->icon('heroicon-o-check-circle')->color('success')
                ->visible(fn (Order $r) => $r->status === Order::STATUS_DRAFT)
                ->requiresConfirmation()
                ->modalDescription('Al confirmar el pedido queda listo para despacho. No podrás editarlo después.')
                ->action(function (Order $r) {
                    $r->update(['status' => Order::STATUS_CONFIRMED]);
                    Notification::make()->success()->title('Pedido confirmado')->send();
                    $this->refreshFormData(['status']);
                }),

            Actions\Action::make('delivery')
                ->label('Registrar despacho')
                ->icon('heroicon-o-truck')->color('warning')
                ->visible(fn (Order $r) => in_array($r->status, [Order::STATUS_CONFIRMED, Order::STATUS_PARTIAL_DELIVERED], true))
                ->form(function (Order $r) {
                    $r->loadMissing('items.product');
                    return [
                        Forms\Components\TextInput::make('delivery_number')
                            ->label('Número de remisión (opcional)')->maxLength(30),
                        Forms\Components\DatePicker::make('delivery_date')
                            ->label('Fecha')->native(false)->default(now())->required(),
                        Forms\Components\Repeater::make('items')
                            ->label('Líneas a despachar')
                            ->schema([
                                Forms\Components\Select::make('order_item_id')
                                    ->label('Producto')->required()->native(false)
                                    ->options(function () use ($r) {
                                        return $r->items
                                            ->filter(fn ($i) => $i->pendingQuantity() > 0)
                                            ->mapWithKeys(fn ($i) => [
                                                $i->id => "{$i->product?->code} — {$i->description} · pendiente: {$i->pendingQuantity()}",
                                            ])->all();
                                    }),
                                Forms\Components\TextInput::make('quantity')
                                    ->label('Cantidad')->numeric()->minValue(0.001)->required(),
                            ])
                            ->defaultItems(1)
                            ->columns(2),
                        Forms\Components\Textarea::make('notes')->label('Observaciones')->rows(2),
                    ];
                })
                ->action(function (Order $r, array $data) {
                    try {
                        app(OrderEngine::class)->registerDelivery(
                            $r, $data['items'] ?? [],
                            $data['delivery_number'] ?? null,
                            $data['notes'] ?? null,
                            $data['delivery_date'] ?? null,
                        );
                        Notification::make()->success()->title('Despacho registrado')->send();
                        $this->refreshFormData(['status', 'delivery_status']);
                    } catch (\Throwable $e) {
                        Notification::make()->danger()->title('Error al despachar')
                            ->body($e->getMessage())->persistent()->send();
                    }
                }),

            Actions\Action::make('payment')
                ->label('Registrar abono')
                ->icon('heroicon-o-banknotes')->color('success')
                ->visible(fn (Order $r) => $r->status !== Order::STATUS_CANCELLED
                    && $r->status !== Order::STATUS_DRAFT
                    && (float) $r->balance > 0)
                ->form(fn (Order $r) => [
                    Forms\Components\TextInput::make('amount')
                        ->label('Monto')->numeric()->required()->prefix('$')
                        ->minValue(0.01)->maxValue((float) $r->balance)
                        ->default((float) $r->balance)
                        ->helperText("Saldo pendiente: $".number_format((float) $r->balance, 2)),
                    Forms\Components\Select::make('payment_method')
                        ->label('Método')->native(false)->required()
                        ->options(Payment::METHODS)->default('cash'),
                    Forms\Components\Select::make('account_id')
                        ->label('Cuenta contable (opcional)')->native(false)->searchable()
                        ->options(fn () => Account::query()
                            ->where('company_id', auth()->user()?->company_id)
                            ->where('accepts_movements', true)
                            ->where(fn ($q) => $q->where('code', 'like', '11%'))
                            ->orderBy('code')
                            ->get()
                            ->mapWithKeys(fn ($a) => [$a->id => "{$a->code} — {$a->name}"])
                            ->all()),
                    Forms\Components\DatePicker::make('payment_date')
                        ->label('Fecha')->native(false)->default(now())->required(),
                    Forms\Components\TextInput::make('reference')->label('Referencia'),
                    Forms\Components\Textarea::make('notes')->label('Notas')->rows(2),
                ])
                ->action(function (Order $r, array $data) {
                    try {
                        app(OrderEngine::class)->registerPayment($r, $data);
                        Notification::make()->success()->title('Abono registrado')->send();
                        $this->refreshFormData(['paid_amount', 'balance', 'payment_status']);
                    } catch (\Throwable $e) {
                        Notification::make()->danger()->title('Error')
                            ->body($e->getMessage())->persistent()->send();
                    }
                }),

            Actions\Action::make('cancel')
                ->label('Anular')
                ->icon('heroicon-o-x-circle')->color('danger')
                ->visible(fn (Order $r) => $r->status !== Order::STATUS_CANCELLED
                    && auth()->user()?->can('order_taking.manage'))
                ->requiresConfirmation()
                ->action(function (Order $r) {
                    $r->update(['status' => Order::STATUS_CANCELLED]);
                    Notification::make()->success()->title('Pedido anulado')->send();
                    $this->refreshFormData(['status']);
                }),
        ];
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Cabecera')
                ->columns(4)
                ->schema([
                    Infolists\Components\TextEntry::make('full_number')
                        ->label('Número')
                        ->state(fn ($record) => $record->fullNumber())
                        ->fontFamily('mono')->weight('bold'),
                    Infolists\Components\TextEntry::make('order_date')->label('Fecha')->date('Y-m-d'),
                    Infolists\Components\TextEntry::make('delivery_date_expected')
                        ->label('Entrega esperada')->date('Y-m-d')->placeholder('—'),
                    Infolists\Components\TextEntry::make('status')
                        ->label('Estado')->badge()
                        ->formatStateUsing(fn ($state) => Order::STATUSES[$state] ?? (string) $state)
                        ->color(fn ($state) => match ($state) {
                            'draft' => 'gray', 'confirmed' => 'info',
                            'partial_delivered' => 'warning', 'fully_delivered' => 'success',
                            'cancelled' => 'danger', default => 'gray',
                        }),
                    Infolists\Components\TextEntry::make('customer.name')->label('Cliente')->columnSpan(2),
                    Infolists\Components\TextEntry::make('customer.document_number')->label('NIT / Documento')->fontFamily('mono'),
                    Infolists\Components\TextEntry::make('priceList.name')->label('Lista de precios')->badge()->color('info')->placeholder('—'),
                    Infolists\Components\TextEntry::make('customer.address')->label('Dirección')->columnSpan(2)->placeholder('—'),
                    Infolists\Components\TextEntry::make('customer.city')->label('Ciudad')->placeholder('—'),
                    Infolists\Components\TextEntry::make('customer.payment_terms')->label('Forma de pago')->placeholder('—'),
                    Infolists\Components\TextEntry::make('customer.delivery_horario')->label('Horario recibo')->columnSpan(2)->placeholder('—'),
                    Infolists\Components\TextEntry::make('seller.name')->label('Vendedor')->placeholder('—'),
                    Infolists\Components\TextEntry::make('notes')->label('Notas')->columnSpanFull()->placeholder('—'),
                ]),

            Infolists\Components\Section::make('Líneas')
                ->schema([
                    Infolists\Components\RepeatableEntry::make('items')
                        ->hiddenLabel()
                        ->schema([
                            Infolists\Components\TextEntry::make('product.code')->label('SKU')->fontFamily('mono')->columnSpan(1)->placeholder('—'),
                            Infolists\Components\TextEntry::make('description')->label('Descripción')->columnSpan(3),
                            Infolists\Components\TextEntry::make('quantity_ordered')->label('Pedido')->columnSpan(1),
                            Infolists\Components\TextEntry::make('quantity_delivered')->label('Entregado')->columnSpan(1)->color('success'),
                            Infolists\Components\TextEntry::make('unit_price_at_public')->label('Precio')->money('COP')->columnSpan(2),
                            Infolists\Components\TextEntry::make('total')->label('Total')->money('COP')->weight('semibold')->columnSpan(2),
                        ])->columns(10),
                ]),

            Infolists\Components\Section::make('Totales')
                ->columns(4)
                ->schema([
                    Infolists\Components\TextEntry::make('subtotal')->label('Subtotal')->money('COP'),
                    Infolists\Components\TextEntry::make('tax_total')->label('IVA')->money('COP'),
                    Infolists\Components\TextEntry::make('total')->label('Total')->money('COP')->weight('bold'),
                    Infolists\Components\TextEntry::make('paid_amount')->label('Pagado')->money('COP')->color('success'),
                    Infolists\Components\TextEntry::make('balance')->label('Saldo')->money('COP')->color('warning')->weight('bold'),
                    Infolists\Components\TextEntry::make('payment_status')->label('Pago')->badge()
                        ->formatStateUsing(fn ($state) => Order::PAYMENT_STATUSES[$state] ?? (string) $state)
                        ->color(fn ($state) => match ($state) {
                            'pendiente' => 'gray', 'parcial' => 'warning', 'pagado' => 'success', default => 'gray',
                        }),
                ]),
        ]);
    }
}
