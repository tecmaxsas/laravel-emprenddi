{{-- El modal de cliente y los dos de caja (apertura y cierre).

     Van en un parcial porque son los mismos tres en todos los terminales y
     el blade principal ya pesa lo suyo. La lógica vive en los traits
     EligeCliente y AbreYCierraCaja; aquí solo está la forma. --}}
@php
    $fmt = $fmt ?? fn ($n) => '$ '.number_format((float) $n, 0, ',', '.');
@endphp

{{-- Cliente --}}
@if ($customerModalOpen)
    @php $coincidencias = $this->customerMatches; @endphp
    <div class="bt-overlay" wire:click.self="closeCustomerModal">
        <div class="bt-modal">
            <div class="bt-modal-head bt-modal-head-blue">
                <h2>👤 Cliente de la factura</h2>
                <button type="button" wire:click="closeCustomerModal" class="bt-modal-close">✕</button>
            </div>
            <div class="bt-modal-body">
                <div class="bt-field">
                    <label>Buscar por nombre o documento</label>
                    <input type="text" wire:model.live.debounce.300ms="customerSearch"
                        placeholder="Mín. 2 caracteres" />
                </div>

                @if ($coincidencias->isNotEmpty())
                    <div class="bt-search-results" style="margin-bottom:14px;">
                        @foreach ($coincidencias as $c)
                            <button type="button" wire:click="selectCustomer({{ $c->id }})" class="bt-search-row">
                                <span><strong>{{ $c->name }}</strong><span class="bt-search-code">{{ $c->document_number }}</span></span>
                            </button>
                        @endforeach
                    </div>
                @endif

                <details>
                    <summary style="cursor:pointer; font-size:12.5px; font-weight:700; color:#4f46e5; margin-bottom:10px;">
                        Crear uno nuevo
                    </summary>
                    <div class="bt-grid-2">
                        <div class="bt-field">
                            <label>Nombre</label>
                            <input type="text" wire:model="newCustomerName" />
                        </div>
                        <div class="bt-field">
                            <label>Tipo de documento</label>
                            <select wire:model="newCustomerDocumentType">
                                <option value="cc">Cédula</option>
                                <option value="nit">NIT</option>
                                <option value="ce">Cédula de extranjería</option>
                                <option value="passport">Pasaporte</option>
                            </select>
                        </div>
                        <div class="bt-field">
                            <label>Documento</label>
                            <input type="text" wire:model="newCustomerDocument" />
                        </div>
                        <div class="bt-field">
                            <label>Teléfono</label>
                            <input type="text" wire:model="newCustomerPhone" />
                        </div>
                        <div class="bt-field">
                            <label>Correo</label>
                            <input type="email" wire:model="newCustomerEmail" />
                        </div>
                        <div class="bt-field">
                            <label>Dirección</label>
                            <input type="text" wire:model="newCustomerAddress" />
                        </div>
                    </div>
                    <button type="button" wire:click="createCustomer" class="bt-btn bt-btn-primary">Crear y seleccionar</button>
                </details>
            </div>
            <div class="bt-modal-foot">
                <button type="button" wire:click="clearCustomer" class="bt-btn bt-btn-ghost">Consumidor Final</button>
                <button type="button" wire:click="closeCustomerModal" class="bt-btn bt-btn-ghost">Cerrar</button>
            </div>
        </div>
    </div>
@endif

{{-- Apertura de caja --}}
@if ($openCashModalOpen)
    <div class="bt-overlay" wire:click.self="closeCashModal">
        <div class="bt-modal">
            <div class="bt-modal-head bt-modal-head-green">
                <h2>🔓 Abrir caja</h2>
                <button type="button" wire:click="closeCashModal" class="bt-modal-close">✕</button>
            </div>
            <div class="bt-modal-body">
                <div class="bt-field">
                    <label>Sede</label>
                    <select wire:model="openingLocationId">
                        @foreach ($this->openingLocations as $id => $nombre)
                            <option value="{{ $id }}">{{ $nombre }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="bt-field">
                    <label>Base en efectivo</label>
                    <input type="number" min="0" step="1" inputmode="numeric"
                        wire:model="openingAmount" onwheel="this.blur()" />
                </div>
                <div class="bt-field">
                    <label>Notas</label>
                    <textarea rows="2" wire:model="openingNotes" placeholder="Opcional"></textarea>
                </div>
            </div>
            <div class="bt-modal-foot">
                <button type="button" wire:click="closeCashModal" class="bt-btn bt-btn-ghost">Cancelar</button>
                <button type="button" wire:click="openCashRegister" class="bt-btn bt-btn-success">Abrir turno</button>
            </div>
        </div>
    </div>
@endif

{{-- Cierre de caja --}}
@if ($showCloseSessionModal)
    <div class="bt-overlay" wire:click.self="closeCloseSessionModal">
        <div class="bt-modal">
            <div class="bt-modal-head bt-modal-head-amber">
                <h2>🔒 Cerrar caja</h2>
                <button type="button" wire:click="closeCloseSessionModal" class="bt-modal-close">✕</button>
            </div>
            <div class="bt-modal-body">
                {{-- A propósito no se muestra el esperado: saberlo antes de
                     contar invita a cuadrar el conteo contra él en vez de
                     contar de verdad. La diferencia sale después del cierre,
                     y solo a quien la empresa deje verla. --}}
                <div class="bt-field">
                    <label>Efectivo contado</label>
                    <input type="number" min="0" step="1" inputmode="numeric"
                        wire:model="closingCounted" onwheel="this.blur()" />
                </div>
                <div class="bt-field">
                    <label>Notas del cierre</label>
                    <textarea rows="2" wire:model="closingNotes" placeholder="Opcional"></textarea>
                </div>
            </div>
            <div class="bt-modal-foot">
                <button type="button" wire:click="closeCloseSessionModal" class="bt-btn bt-btn-ghost">Cancelar</button>
                <button type="button" wire:click="closeCashSession" class="bt-btn bt-btn-warning">Cerrar turno</button>
            </div>
        </div>
    </div>
@endif
