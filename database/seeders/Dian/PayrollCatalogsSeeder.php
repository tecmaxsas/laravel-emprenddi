<?php

namespace Database\Seeders\Dian;

use App\Models\Dian\PayrollDocumentType;
use App\Models\Dian\PayrollPeriodCatalog;
use App\Models\Dian\SubTypeWorker;
use App\Models\Dian\TypeContract;
use App\Models\Dian\TypeLawDeduction;
use App\Models\Dian\TypeWorker;
use Illuminate\Database\Seeder;

/**
 * Catalogos de nomina electronica, tal como estan en la base de apidian.
 *
 * Los ids son los de apidian y no se pueden reasignar: son los que viajan en
 * el payload. Por eso se siembra con id explicito y updateOrCreate por id.
 */
class PayrollCatalogsSeeder extends Seeder
{
    public function run(): void
    {
        $this->periodicidades();
        $this->tiposDeDocumento();
        $this->tiposDeContrato();
        $this->tiposDeTrabajador();
        $this->subtiposDeTrabajador();
        $this->deduccionesDeLey();
    }

    private function periodicidades(): void
    {
        $this->sembrar(PayrollPeriodCatalog::class, [
            ['id' => 1, 'code' => '1', 'name' => 'Semanal'],
            ['id' => 2, 'code' => '2', 'name' => 'Decenal'],
            ['id' => 3, 'code' => '3', 'name' => 'Catorcenal'],
            ['id' => 4, 'code' => '4', 'name' => 'Quincenal'],
            ['id' => 5, 'code' => '5', 'name' => 'Mensual'],
        ]);
    }

    /** Ojo: los ids NO son los mismos que en dian_document_types. */
    private function tiposDeDocumento(): void
    {
        $this->sembrar(PayrollDocumentType::class, [
            ['id' => 1, 'code' => '11', 'name' => 'Registro civil'],
            ['id' => 2, 'code' => '12', 'name' => 'Tarjeta de identidad'],
            ['id' => 3, 'code' => '13', 'name' => 'Cédula de ciudadanía'],
            ['id' => 4, 'code' => '21', 'name' => 'Tarjeta de extranjería'],
            ['id' => 5, 'code' => '22', 'name' => 'Cédula de extranjería'],
            ['id' => 6, 'code' => '31', 'name' => 'NIT'],
            ['id' => 7, 'code' => '41', 'name' => 'Pasaporte'],
            ['id' => 8, 'code' => '42', 'name' => 'Documento de identificación extranjero'],
            ['id' => 9, 'code' => '47', 'name' => 'PEP (Permiso Especial de Permanencia)'],
            ['id' => 10, 'code' => '50', 'name' => 'NIT de otro país'],
            ['id' => 11, 'code' => '91', 'name' => 'NUIP'],
            ['id' => 12, 'code' => '48', 'name' => 'PPT (Permiso Protección Temporal)'],
        ]);
    }

    private function tiposDeContrato(): void
    {
        $this->sembrar(TypeContract::class, [
            ['id' => 1, 'code' => '1', 'name' => 'Término Fijo'],
            ['id' => 2, 'code' => '2', 'name' => 'Término Indefinido'],
            ['id' => 3, 'code' => '3', 'name' => 'Obra o Labor'],
            ['id' => 4, 'code' => '4', 'name' => 'Aprendizaje'],
            ['id' => 5, 'code' => '5', 'name' => 'Prácticas'],
        ]);
    }

    private function tiposDeTrabajador(): void
    {
        $this->sembrar(TypeWorker::class, [
            ['id' => 1, 'code' => '01', 'name' => 'Dependiente'],
            ['id' => 2, 'code' => '02', 'name' => 'Servicio doméstico'],
            ['id' => 3, 'code' => '04', 'name' => 'Madre comunitaria'],
            ['id' => 4, 'code' => '12', 'name' => 'Aprendices del SENA en etapa lectiva'],
            ['id' => 5, 'code' => '18', 'name' => 'Funcionarios públicos sin tope máximo de IBC'],
            ['id' => 6, 'code' => '19', 'name' => 'Aprendices del SENA en etapa productiva'],
            ['id' => 7, 'code' => '21', 'name' => 'Estudiantes de postgrado en salud'],
            ['id' => 8, 'code' => '22', 'name' => 'Profesor de establecimiento particular'],
            ['id' => 9, 'code' => '23', 'name' => 'Estudiantes aportes solo riesgos laborales'],
            ['id' => 10, 'code' => '30', 'name' => 'Dependiente entidades o universidades públicas con régimen especial en salud'],
            ['id' => 11, 'code' => '31', 'name' => 'Cooperados o pre cooperativas de trabajo asociado'],
            ['id' => 12, 'code' => '47', 'name' => 'Trabajador dependiente de entidad beneficiaria del SGP - aportes patronales'],
            ['id' => 13, 'code' => '51', 'name' => 'Trabajador de tiempo parcial'],
            ['id' => 14, 'code' => '54', 'name' => 'Pre pensionado de entidad en liquidación'],
            ['id' => 15, 'code' => '56', 'name' => 'Pre pensionado con aporte voluntario a salud'],
            ['id' => 16, 'code' => '58', 'name' => 'Estudiantes de prácticas laborales en el sector público'],
        ]);
    }

    private function subtiposDeTrabajador(): void
    {
        $this->sembrar(SubTypeWorker::class, [
            ['id' => 1, 'code' => '00', 'name' => 'No aplica'],
            ['id' => 2, 'code' => '01', 'name' => 'Dependiente pensionado por vejez activo'],
            ['id' => 3, 'code' => '02', 'name' => 'Independiente pensionado por vejez activo'],
            ['id' => 4, 'code' => '03', 'name' => 'Cotizante no obligado a cotizar a pensión por edad'],
            ['id' => 5, 'code' => '04', 'name' => 'Cotizante con requisitos cumplidos para pensión'],
            ['id' => 6, 'code' => '12', 'name' => 'Cotizante con indemnización sustitutiva o devolución de saldos'],
            ['id' => 7, 'code' => '16', 'name' => 'Cotizante de régimen exceptuado de pensiones'],
            ['id' => 8, 'code' => '18', 'name' => 'Cotizante pensionado con mesada superior a 25 SMLMV'],
            ['id' => 9, 'code' => '19', 'name' => 'Residente en el exterior afiliado voluntario a pensiones'],
            ['id' => 10, 'code' => '20', 'name' => 'Conductores de servicio público individual (taxi) — decreto 1047 de 2014'],
            ['id' => 11, 'code' => '21', 'name' => 'Conductores servicio taxi que no aportan pensión — dec. 1047'],
        ]);
    }

    /**
     * La tarifa importa: el trabajador de alto riesgo aporta a pensión por el
     * concepto 7, no por el 5.
     */
    private function deduccionesDeLey(): void
    {
        $this->sembrar(TypeLawDeduction::class, [
            ['id' => 1, 'code' => '1', 'name' => 'Salud Tarifa (12.5%) Trabajador', 'percentage' => 4],
            ['id' => 2, 'code' => '2', 'name' => 'Salud Tarifa (12.5%) Empleador', 'percentage' => 8.5],
            ['id' => 3, 'code' => '3', 'name' => 'Salud Sal<10SMLV Tarifa (4%) Trabajador', 'percentage' => 4],
            ['id' => 4, 'code' => '4', 'name' => 'Salud Sal<10SMLV Tarifa (4%) Empleador', 'percentage' => 0],
            ['id' => 5, 'code' => '5', 'name' => 'Pensión Tarifa (16%) Trabajador', 'percentage' => 4],
            ['id' => 6, 'code' => '6', 'name' => 'Pensión Tarifa (16%) Empleador', 'percentage' => 12],
            ['id' => 7, 'code' => '7', 'name' => 'Pensión Alto Riesgo Tarifa (26%) Trabajador', 'percentage' => 4],
            ['id' => 8, 'code' => '8', 'name' => 'Pensión Alto Riesgo Tarifa (26%) Empleador', 'percentage' => 22],
            ['id' => 9, 'code' => '9', 'name' => 'Fondo de Solidaridad Empleado', 'percentage' => 1],
        ]);
    }

    /** @param  list<array<string, mixed>>  $filas */
    private function sembrar(string $modelo, array $filas): void
    {
        foreach ($filas as $fila) {
            $modelo::updateOrCreate(['id' => $fila['id']], $fila);
        }
    }
}
