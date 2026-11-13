{{-- Datos del cobro (medio de pago, vuelto, cuenta, cliente, tipo de
     factura). Lo comparten la salida y la factura rapida; los dos escriben
     en $exitForm, asi el modal de cliente sirve para ambos.
     Recibe $totalCobro: lo que se le cobra al cliente, para el vuelto. --}}
<div class="pt-payment">
    <div class="pt-payment-head">Datos del cobro</div>
    <div class="pt-payment-grid">
        <div class="pt-field">
            <label>Medio de pago</label>
            <select wire:model.live="exitForm.payment_method">
                @foreach ($this->paymentMethodOptions as $code => $name)
                    <option value="{{ $code }}">{{ $name }}</option>
                @endforeach
            </select>
        </div>
        {{-- CON CUANTO PAGA / VUELTO
             Solo en efectivo: de una transferencia
             nadie devuelve plata. En un parqueadero
             el cobro casi siempre se paga con billete
             grande, asi que es donde mas se usa. --}}
        @if (\App\Support\Vuelto::aplica($this->exitForm['payment_method'] ?? null))
            @php
                $recibido = (float) ($this->exitForm['cash_received'] ?? 0);
                $aPagar = (float) $totalCobro;
                $vuelto = \App\Support\Vuelto::calcular($recibido, $aPagar);
                $falta = \App\Support\Vuelto::faltante($recibido, $aPagar);
            @endphp
            <div class="pt-field">
                <label>Con cuánto paga</label>
                <input type="number" step="1" min="0" inputmode="numeric"
                       wire:model.live.debounce.300ms="exitForm.cash_received"
                       onwheel="this.blur()"
                       placeholder="Opcional" />
            </div>
            <div class="pt-field">
                <label>Cambio</label>
                <div style="padding:8px 0; font-size:18px; font-weight:800;">
                    @if ($recibido <= 0)
                        <span style="color:#9ca3af; font-size:14px; font-weight:500;">—</span>
                    @elseif ($falta > 0)
                        <span style="color:rgb(220,38,38); font-size:15px;">
                            Faltan ${{ number_format($falta, 0, ',', '.') }}
                        </span>
                    @else
                        <span style="color:rgb(5,150,105);">
                            ${{ number_format($vuelto, 0, ',', '.') }}
                        </span>
                    @endif
                </div>
            </div>
        @endif

        {{-- La cuenta la decide el metodo de pago. Solo se
             muestra si el negocio lleva contabilidad y
             quiere sobreescribirla en el cobro. --}}
        @if (\App\Support\ModuleGate::active(\App\Support\ModuleGate::ACCOUNTING))
            <div class="pt-field">
                <label>Cuenta contable</label>
                <select wire:model="exitForm.account_id">
                    @foreach ($this->accountOptions as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </select>
            </div>
        @endif
        <div class="pt-field">
            <label>Cliente</label>
            <button type="button" wire:click="openCustomerModal"
                    style="width:100%; text-align:left; padding:8px 10px; border-radius:6px; cursor:pointer; border:1px solid #d1d5db; background:#ffffff; color:#111827;">
                <div style="font-size:13px; font-weight:700;">
                    {{ $exitForm['third_party_label'] ?? 'Consumidor Final' }}
                </div>
                <div style="font-size:11px; color:#6b7280;">
                    Toca para buscar o crear un cliente
                </div>
            </button>
        </div>
        <div class="pt-field">
            <label>Tipo de factura</label>
            <select wire:model="exitForm.invoice_kind">
                <option value="pos">POS (no electrónica)</option>
                <option value="electronic">Electrónica DIAN</option>
            </select>
        </div>
    </div>
</div>
