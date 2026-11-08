<?php

namespace App\Filament\App\Pages;

use App\Support\DashboardSections;
use App\Support\PosDestination;
use Filament\Pages\Dashboard as BaseDashboard;

/**
 * El escritorio, que a quien no tiene nada que ver ahí lo manda a trabajar.
 *
 * Al separar las cifras del negocio en su propio permiso, el cajero dejó de
 * ver ventas del mes, cartera y cuentas por pagar —que era el objetivo— pero
 * quedó entrando a una pantalla casi vacía: un saludo y poco más. Eso no es
 * una mejora, es un paso de más entre abrir sesión y poder cobrar.
 *
 * Así que si el usuario no tiene NINGUNA sección del escritorio disponible y
 * sí puede usar un POS, se le lleva directo a su POS.
 *
 * La condición se mide por permisos, no por el nombre del rol. Un rol llamado
 * «cajero» al que alguien le habilite los indicadores debe quedarse en el
 * escritorio, y un rol llamado «mostrador» sin nada que ver debe irse al POS
 * igual. Mirar el nombre sería adivinar; mirar lo que puede ver es saberlo.
 *
 * `PosDestination::url()` resuelve a cuál POS: retail, restaurante,
 * parqueadero u hotel, según los módulos activos de la empresa. Esa decisión
 * ya estaba escrita una vez y no se repite aquí.
 */
class Escritorio extends BaseDashboard
{
    protected static ?string $navigationIcon = 'heroicon-o-home';

    protected static ?string $navigationLabel = 'Escritorio';

    protected static ?string $title = 'Escritorio';

    /**
     * La marca de que a este usuario ya se le ofrecio su POS en esta sesion.
     */
    private const YA_LO_MANDE = 'escritorio.ya_mande_al_pos';

    public function mount(): void
    {
        $destino = $this->aDondeMandarlo();

        if (! $destino) {
            return;
        }

        // UNA SOLA VEZ POR SESION.
        //
        // La primera version desviaba en CADA carga del escritorio, y eso
        // dejo a los cajeros encerrados en el POS: no podian volver al menu
        // ni por el boton ni escribiendo la ruta, porque el escritorio los
        // devolvia al instante.
        //
        // Lo que se queria era ahorrarles un clic al entrar, no quitarles una
        // pantalla. Con la marca, el desvio ocurre al llegar despues de
        // iniciar sesion; si el usuario vuelve al escritorio a proposito, se
        // queda.
        if (session()->get(self::YA_LO_MANDE) === auth()->id()) {
            return;
        }

        session()->put(self::YA_LO_MANDE, auth()->id());

        $this->redirect($destino);
    }

    /**
     * El POS al que corresponde mandarlo, o null si se queda en el escritorio.
     */
    protected function aDondeMandarlo(): ?string
    {
        $user = auth()->user();

        if (! $user) {
            return null;
        }

        // Si tiene algo que ver, se queda: el escritorio es suyo.
        if (DashboardSections::availableFor($user) !== []) {
            return null;
        }

        return PosDestination::url();
    }
}
