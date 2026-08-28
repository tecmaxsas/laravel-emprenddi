<x-filament-panels::page>
    @php
        $fmt = fn ($n) => '$ '.number_format((float) $n, 0, ',', '.');
        $customer = $this->selectedCustomer;
        $totals = $this->cartTotals;
    @endphp

    <div style="display:grid; grid-template-columns:1fr 380px; gap:14px;">
        {{-- Columna izquierda: cliente + productos --}}
        <div>
            <div style="background:#fff; border:1px solid #e2e8f0; border-radius:10px; padding:14px 16px;">
                <div style="display:grid; grid-template-columns:2fr 1fr 1fr; gap:10px;">
                    <div>
                        <label style="font-size:11px; font-weight:700; color:#334155; text-transform:uppercase;">Cliente *</label>
                        <select wire:model.live="customerId"
                                style="width:100%; padding:9px 10px; border:1px solid #cbd5e1; border-radius:8px; background:#fff; color:#0f172a; font-size:13px;">
                            <option value="">— Selecciona un cliente —</option>
                            @foreach ($this->customers as $c)
                                <option value="{{ $c->id }}">{{ $c->document_number }} · {{ $c->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label style="font-size:11px; font-weight:700; color:#334155; text-transform:uppercase;">Lista de precios *</label>
                        <select wire:model.live="priceListId"
                                style="width:100%; padding:9px 10px; border:1px solid #cbd5e1; border-radius:8px; background:#fff; color:#0f172a; font-size:13px;">
                            <option value="">— Selecciona —</option>
                            @foreach ($this->priceLists as $pl)
                                <option value="{{ $pl->id }}">{{ $pl->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label style="font-size:11px; font-weight:700; color:#334155; text-transform:uppercase;">Sede / Bodega</label>
                        <select wire:model="locationId"
                                style="width:100%; padding:9px 10px; border:1px solid #cbd5e1; border-radius:8px; background:#fff; color:#0f172a; font-size:13px;">
                            <option value="">— Ninguna —</option>
                            @foreach ($this->locations as $l)
                                <option value="{{ $l->id }}">{{ $l->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                @if ($customer)
                    <div style="margin-top:10px; padding:10px 12px; background:#f8fafc; border-radius:8px; font-size:12.5px; color:#334155;">
                        <div><strong>{{ $customer->name }}</strong> · NIT {{ $customer->document_number }}</div>
                        <div>{{ $customer->address ?? '—' }} · {{ $customer->city ?? '' }}</div>
                        <div style="color:#64748b; font-size:11.5px;">
                            {{ $customer->payment_terms ?? 'Sin condiciones' }} · {{ $customer->delivery_horario ?? '' }}
                        </div>
                    </div>
                @endif

                <div style="margin-top:10px; display:grid; grid-template-columns:1fr 1fr; gap:10px;">
                    <div>
                        <label style="font-size:11px; font-weight:700; color:#334155; text-transform:uppercase;">Fecha del pedido</label>
                        <input type="date" wire:model="orderDate" style="width:100%; padding:8px 10px; border:1px solid #cbd5e1; border-radius:8px; background:#fff; color:#0f172a;">
                    </div>
                    <div>
                        <label style="font-size:11px; font-weight:700; color:#334155; text-transform:uppercase;">Entrega esperada (opcional)</label>
                        <input type="date" wire:model="deliveryDateExpected" style="width:100%; padding:8px 10px; border:1px solid #cbd5e1; border-radius:8px; background:#fff; color:#0f172a;">
                    </div>
                </div>
            </div>

            <div style="margin-top:14px; background:#fff; border:1px solid #e2e8f0; border-radius:10px; padding:14px 16px;">
                <label style="font-size:11px; font-weight:700; color:#334155; text-transform:uppercase;">Buscar producto</label>
                <input type="text" wire:model.live.debounce.300ms="productSearch"
                       placeholder="Nombre, código, código de barras, marca, categoría..."
                       style="width:100%; padding:10px 12px; border:1px solid #cbd5e1; border-radius:8px; background:#fff; color:#0f172a; font-size:14px;"
                       {{ !$priceListId ? 'disabled' : '' }}>
                @if (!$priceListId)
                    <div style="margin-top:6px; color:#f59e0b; font-size:12px;">⚠ Primero selecciona una lista de precios.</div>
                @endif

                @if ($this->foundProducts->isNotEmpty())
                    <div style="margin-top:10px; max-height:250px; overflow-y:auto; border:1px solid #e2e8f0; border-radius:8px;">
                        @foreach ($this->foundProducts as $item)
                            <button type="button" wire:click="addProduct({{ $item->id }})"
                                    style="width:100%; text-align:left; padding:9px 12px; border:0; border-bottom:1px solid #f1f5f9; background:#fff; cursor:pointer; display:flex; justify-content:space-between; align-items:center;">
                                <div>
                                    <div style="font-family:ui-monospace,monospace; font-size:11px; color:#64748b;">
                                        {{ $item->product?->code }}
                                        @if ($item->product?->barcode)
                                            <span style="color:#94a3b8;">· {{ $item->product->barcode }}</span>
                                        @endif
                                    </div>
                                    <div style="font-weight:600; color:#0f172a; font-size:13px;">{{ $item->product?->name }}</div>
                                    @if ($item->product?->brand || $item->product?->category?->name)
                                        <div style="font-size:11px; color:#94a3b8;">
                                            {{ collect([$item->product?->brand, $item->product?->category?->name])->filter()->implode(' · ') }}
                                        </div>
                                    @endif
                                </div>
                                <div style="font-weight:700; color:#166534;">{{ $fmt($item->price_at_public) }}</div>
                            </button>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>

        {{-- Columna derecha: carrito --}}
        <div style="background:#fff; border:1px solid #e2e8f0; border-radius:10px; padding:14px 16px; height:fit-content; position:sticky; top:12px;">
            <h3 style="margin:0 0 10px; font-size:15px; font-weight:800; color:#0f172a;">Líneas del pedido</h3>

            @if (empty($cart))
                <div style="text-align:center; padding:30px 10px; color:#94a3b8; font-size:13px;">
                    Selecciona un cliente y busca productos para agregarlos aquí.
                </div>
            @else
                <div style="max-height:400px; overflow-y:auto; margin-bottom:10px;">
                    @foreach ($cart as $i => $line)
                        <div style="padding:8px 0; border-bottom:1px solid #f1f5f9;">
                            <div style="display:flex; justify-content:space-between; align-items:flex-start;">
                                <div style="flex:1;">
                                    <div style="font-family:ui-monospace,monospace; font-size:10px; color:#64748b;">{{ $line['code'] }}</div>
                                    <div style="font-size:12px; color:#0f172a; font-weight:600;">{{ $line['name'] }}</div>
                                </div>
                                <button type="button" wire:click="removeLine({{ $i }})" style="background:none; border:0; color:#dc2626; cursor:pointer; padding:0 4px;">✕</button>
                            </div>
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-top:4px; gap:8px;">
                                <input type="number" min="0" step="1"
                                       value="{{ $line['quantity'] }}"
                                       wire:change="updateQuantity({{ $i }}, $event.target.value)"
                                       style="width:70px; padding:4px 8px; border:1px solid #cbd5e1; border-radius:6px; text-align:center; background:#fff; color:#0f172a; font-weight:700;">
                                <div style="font-size:11px; color:#64748b;">× {{ $fmt($line['price_at_public']) }}</div>
                                <div style="font-weight:700; color:#0f172a;">{{ $fmt($line['quantity'] * $line['price_at_public']) }}</div>
                            </div>
                        </div>
                    @endforeach
                </div>

                <div style="border-top:2px solid #e2e8f0; padding-top:8px; font-size:12.5px;">
                    {{-- El color va explicito: el panel es blanco fijo, asi que
                         sin el estos valores heredan el texto claro del modo
                         oscuro y quedan invisibles. --}}
                    <div style="display:flex; justify-content:space-between; padding:2px 0;">
                        <span style="color:#64748b;">Subtotal</span>
                        <span style="font-weight:600; color:#0f172a;">{{ $fmt($totals['subtotal']) }}</span>
                    </div>
                    <div style="display:flex; justify-content:space-between; padding:2px 0;">
                        <span style="color:#64748b;">IVA</span>
                        <span style="font-weight:600; color:#0f172a;">{{ $fmt($totals['tax']) }}</span>
                    </div>
                    <div style="display:flex; justify-content:space-between; padding:6px 0 4px; border-top:1px solid #e2e8f0; margin-top:4px;">
                        <span style="font-weight:800; font-size:14px;">TOTAL</span>
                        <span style="font-weight:900; font-size:18px; color:#0f172a;">{{ $fmt($totals['total']) }}</span>
                    </div>

                    {{-- Retenciones del cliente. Se aplican solas al elegirlo;
                         aqui el vendedor las revisa, corrige la base o las quita. --}}
                    @if ($retentions)
                        <div style="margin-top:8px; padding-top:8px; border-top:1px dashed #cbd5e1;">
                            <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:6px;">
                                <span style="font-size:11px; font-weight:800; color:#b45309; text-transform:uppercase;">
                                    Retenciones del cliente
                                </span>
                                <span style="font-weight:800; color:#b45309;">− {{ $fmt($this->retentionTotal) }}</span>
                            </div>

                            <div style="display:grid; grid-template-columns:1fr 96px 24px; gap:6px; font-size:10px; font-weight:700; color:#94a3b8; text-transform:uppercase; padding-bottom:2px;">
                                <span>Retención</span>
                                <span style="text-align:right;">Base</span>
                                <span></span>
                            </div>

                            @foreach ($retentions as $i => $ret)
                                <div style="display:grid; grid-template-columns:1fr 96px 24px; gap:6px; align-items:center; padding:3px 0;">
                                    <div style="min-width:0;">
                                        <div style="font-size:11.5px; font-weight:700; color:#0f172a; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                                            {{ $ret['tax_code'] }} · {{ number_format((float) $ret['rate'], 2) }}%
                                        </div>
                                        <div style="font-size:10.5px; color:#b45309; font-weight:700;">− {{ $fmt($ret['amount']) }}</div>
                                    </div>
                                    <input type="number" min="0" step="0.01"
                                           value="{{ $ret['base_amount'] }}"
                                           wire:change="updateRetentionBase({{ $i }}, $event.target.value)"
                                           title="Base gravable"
                                           style="width:100%; padding:4px 6px; border:1px solid #cbd5e1; border-radius:6px; text-align:right; background:#fff; color:#0f172a; font-size:11.5px;">
                                    <button type="button" wire:click="removeRetention({{ $i }})" title="Quitar retención"
                                            style="background:none; border:0; color:#94a3b8; font-size:16px; cursor:pointer; line-height:1;">×</button>
                                </div>
                            @endforeach

                            <div style="display:flex; justify-content:space-between; padding:6px 0 2px; border-top:1px solid #e2e8f0; margin-top:6px;">
                                <span style="font-weight:800; font-size:13px;">NETO A PAGAR</span>
                                <span style="font-weight:900; font-size:16px; color:#15803d;">
                                    {{ $fmt(max(0, $totals['total'] - $this->retentionTotal)) }}
                                </span>
                            </div>
                        </div>
                    @elseif ($customer)
                        @php $configuradas = $customer->retentionTaxes()->where('is_active', true)->count(); @endphp
                        @if ($configuradas > 0)
                            <div style="margin-top:8px; padding-top:8px; border-top:1px dashed #cbd5e1;">
                                <button type="button" wire:click="restoreRetentions"
                                        style="width:100%; padding:6px; background:#fef3c7; color:#92400e; border:1px solid #fcd34d; border-radius:6px; font-weight:700; font-size:11.5px; cursor:pointer;">
                                    ↺ Volver a aplicar las retenciones del cliente ({{ $configuradas }})
                                </button>
                            </div>
                        @endif
                    @endif
                </div>
            @endif

            <textarea wire:model="notes" rows="2" placeholder="Notas del pedido (opcional)"
                      style="width:100%; margin-top:10px; padding:8px 10px; border:1px solid #cbd5e1; border-radius:8px; background:#fff; color:#0f172a; font-size:12.5px;"></textarea>

            <button type="button" wire:click="saveOrder"
                    style="width:100%; margin-top:10px; padding:12px; background:#16a34a; color:#fff; border:0; border-radius:8px; font-weight:800; font-size:14px; cursor:pointer;"
                    {{ empty($cart) || !$customerId ? 'disabled' : '' }}>
                ✓ Guardar pedido
            </button>
        </div>
    </div>
</x-filament-panels::page>
