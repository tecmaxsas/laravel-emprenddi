<?php

namespace Tests\Concerns;

use App\Models\Account;
use App\Models\Billiards\BilliardHall;
use App\Models\Billiards\BilliardRate;
use App\Models\Billiards\BilliardTable;
use App\Models\Billiards\BilliardTableType;
use App\Models\CashRegisterSession;
use App\Models\Company;
use App\Models\Location;
use App\Models\Product;
use App\Models\SaleInvoice;
use App\Models\User;
use App\Support\CompanyRoles;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

/**
 * El escenario mínimo de un salón de billar: sala, dos mesas, una tarifa
 * por hora, un cajero con turno abierto y una cerveza que vender.
 *
 * Está en un trait y no copiado en cada prueba porque el escenario tiene
 * once piezas que se tienen que crear en orden y borrar en el orden
 * contrario; escrito dos veces, la segunda copia es la que deja basura en
 * la base de desarrollo cuando alguien le agregue una pieza a la primera.
 *
 * Monta una empresa nueva en vez de usar la de desarrollo: el salón necesita
 * su propia resolución DIAN y sus propias mesas, y encenderle y apagarle el
 * módulo a la empresa compartida es como se rompieron antes las pruebas del
 * POS de retail.
 */
trait MontaUnSalonDeBillar
{
    protected Company $empresa;

    protected User $cajero;

    protected Location $sede;

    protected BilliardHall $sala;

    protected BilliardTableType $tipoDeMesa;

    protected BilliardTable $mesa;

    protected BilliardTable $otraMesa;

    protected BilliardRate $tarifa;

    protected Product $cerveza;

    protected int $cuentaCaja = 0;

    /** @param  list<callable>  $limpiar */
    protected function montaUnSalonDeBillar(array &$limpiar): void
    {
        $this->empresa = Company::create([
            'nit' => 'ZZ'.random_int(1000000, 9999999),
            'name' => 'Billares ZZ',
            'document_type' => 'nit',
            'organization_type' => 'juridica',
            'regime_type' => 'comun',
            'currency' => 'COP',
            'timezone' => 'America/Bogota',
            'active_modules' => ['billiards'],
            'active' => true,
        ]);

        $this->sede = Location::create([
            'company_id' => $this->empresa->id,
            'name' => 'Sede ZZ billar',
            'code' => 'ZZB'.random_int(100, 999),
            'is_main' => true,
            'active' => true,
        ]);

        $this->sala = BilliardHall::create([
            'company_id' => $this->empresa->id,
            'code' => 'ZZS'.random_int(100, 999),
            'name' => 'Salón ZZ',
            'default_location_id' => $this->sede->id,
            'default_invoice_kind' => 'pos',
            'active' => true,
        ]);

        $this->tipoDeMesa = BilliardTableType::create([
            'company_id' => $this->empresa->id,
            'code' => 'ZZPOOL'.random_int(100, 999),
            'name' => 'Pool ZZ',
            'sort_order' => 10,
            'active' => true,
        ]);

        $this->mesa = $this->creaUnaMesa('1');
        $this->otraMesa = $this->creaUnaMesa('2');

        $this->tarifa = BilliardRate::create([
            'company_id' => $this->empresa->id,
            'hall_id' => $this->sala->id,
            'table_type_id' => $this->tipoDeMesa->id,
            'name' => 'Tarifa ZZ',
            'kind' => BilliardRate::KIND_REGULAR,
            'config' => ['type' => 'per_hour', 'amount' => 10000],
            'priority' => 0,
            'active' => true,
        ]);

        $this->cajero = User::create([
            'company_id' => $this->empresa->id,
            'name' => 'Cajero ZZ billar',
            'email' => 'cajerozzb'.random_int(10000, 99999).'@zz.test',
            'password' => Hash::make('zz'),
            'active' => true,
        ]);

        CompanyRoles::provision($this->empresa);
        CompanyRoles::assign($this->cajero, 'cashier');

        // Caja, cartera e ingresos: el cobro mueve las tres. Sin la 1305 el
        // motor no sabe contra qué cuenta abonar y la factura no nace.
        $cuentas = collect([
            ['110505', 'Caja general ZZ', 'activo', 'debit'],
            ['1305', 'Clientes ZZ', 'activo', 'debit'],
            ['413500', 'Ingresos ZZ', 'ingreso', 'credit'],
        ])->map(fn (array $c) => Account::create([
            'company_id' => $this->empresa->id,
            'code' => $c[0],
            'name' => $c[1],
            'type' => $c[2],
            'nature' => $c[3],
            'level' => 5,
            'accepts_movements' => true,
            'active' => true,
        ]));

        $this->cuentaCaja = (int) $cuentas->first()->id;

        $this->cerveza = Product::create([
            'company_id' => $this->empresa->id,
            'code' => 'ZZCV'.random_int(10000, 99999),
            'name' => 'Cerveza ZZ',
            'type' => 'good',
            'unit_of_measure' => 'unit',
            'track_inventory' => false,
            'is_sellable' => true,
            'default_sale_price' => 5000,
            'active' => true,
        ]);

        $this->creaLaResolucionPos($limpiar);

        $limpiar[] = function () use ($cuentas) {
            // El orden importa y es el contrario al de creación: las líneas
            // de asiento apuntan a las cuentas, los pagos a las facturas y
            // los cargos a las sesiones. Al revés, las llaves foráneas lo
            // impiden y la prueba deja basura en la base de desarrollo.
            $asientos = DB::table('journal_entries')
                ->where('company_id', $this->empresa->id)->pluck('id');
            DB::table('journal_entry_lines')->whereIn('journal_entry_id', $asientos)->delete();

            $facturas = SaleInvoice::query()->where('company_id', $this->empresa->id)->pluck('id');
            DB::table('payments')->whereIn('paymentable_id', $facturas)
                ->where('paymentable_type', SaleInvoice::class)->delete();
            DB::table('sale_invoice_lines')->whereIn('sale_invoice_id', $facturas)->delete();

            $sesiones = DB::table('billiard_sessions')->where('company_id', $this->empresa->id)->pluck('id');
            DB::table('billiard_session_pauses')->whereIn('session_id', $sesiones)->delete();
            DB::table('billiard_session_charges')->whereIn('session_id', $sesiones)->delete();
            DB::table('billiard_sessions')->whereIn('id', $sesiones)->delete();

            DB::table('sale_invoices')->whereIn('id', $facturas)->delete();
            DB::table('cash_register_sessions')->where('company_id', $this->empresa->id)->delete();

            $roles = Role::query()->where('company_id', $this->empresa->id)->pluck('id');
            DB::table('model_has_roles')->whereIn('role_id', $roles)->delete();
            DB::table('role_has_permissions')->whereIn('role_id', $roles)->delete();
            DB::table('roles')->whereIn('id', $roles)->delete();

            DB::table('journal_entries')->whereIn('id', $asientos)->delete();
            DB::table('billiard_rates')->where('company_id', $this->empresa->id)->delete();
            DB::table('billiard_tables')->where('company_id', $this->empresa->id)->delete();
            DB::table('billiard_table_types')->where('company_id', $this->empresa->id)->delete();
            DB::table('billiard_halls')->where('company_id', $this->empresa->id)->delete();
            DB::table('products')->where('company_id', $this->empresa->id)->delete();
            DB::table('accounts')->whereIn('id', $cuentas->pluck('id'))->delete();
            DB::table('third_parties')->where('company_id', $this->empresa->id)->delete();
            DB::table('locations')->where('id', $this->sede->id)->delete();
            DB::table('users')->where('company_id', $this->empresa->id)->delete();
            DB::table('companies')->where('id', $this->empresa->id)->delete();
        };
    }

    protected function creaUnaMesa(string $codigo): BilliardTable
    {
        return BilliardTable::create([
            'company_id' => $this->empresa->id,
            'hall_id' => $this->sala->id,
            'table_type_id' => $this->tipoDeMesa->id,
            'code' => $codigo,
            'zone' => 'Salón',
            'status' => BilliardTable::STATUS_FREE,
            'active' => true,
        ]);
    }

    /** @param  list<callable>  $limpiar */
    protected function abreUnTurnoDeCaja(array &$limpiar): void
    {
        $turno = CashRegisterSession::create([
            'company_id' => $this->empresa->id,
            'location_id' => $this->sede->id,
            'cashier_user_id' => $this->cajero->id,
            'status' => CashRegisterSession::STATUS_OPEN,
            'opened_at' => now(),
            'opening_amount' => 0,
        ]);

        $limpiar[] = fn () => DB::table('cash_register_sessions')->where('id', $turno->id)->delete();
    }

    /** Sin resolución POS la sede no puede emitir. */
    protected function creaLaResolucionPos(array &$limpiar): void
    {
        $resolucion = DB::table('dian_resolutions')->insertGetId([
            'company_id' => $this->empresa->id,
            'document_type_id' => 1,
            'document_type_name' => 'Factura de venta',
            'kind' => 'pos',
            'prefix' => 'ZZB',
            'resolution_number' => 'ZZ-BILLAR',
            'range_from' => 1,
            'range_to' => 999999,
            'date_from' => now()->subYear()->toDateString(),
            'date_to' => now()->addYear()->toDateString(),
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $enlace = DB::table('dian_location_resolutions')->insertGetId([
            'location_id' => $this->sede->id,
            'dian_resolution_id' => $resolucion,
            'current_consecutive' => 0,
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $limpiar[] = function () use ($enlace, $resolucion) {
            DB::table('dian_location_resolutions')->where('id', $enlace)->delete();
            DB::table('dian_resolutions')->where('id', $resolucion)->delete();
        };
    }
}
