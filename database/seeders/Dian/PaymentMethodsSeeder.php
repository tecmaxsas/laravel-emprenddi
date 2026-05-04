<?php

namespace Database\Seeders\Dian;

use App\Models\Dian\PaymentMethod;
use Illuminate\Database\Seeder;

class PaymentMethodsSeeder extends Seeder
{
    public function run(): void
    {
        $methods = [
            [1, '1', 'Instrumento no definido'],
            [2, '2', 'Crédito ACH'],
            [3, '3', 'Débito ACH'],
            [4, '4', 'Reversión débito de demanda ACH'],
            [5, '5', 'Reversión crédito de demanda ACH'],
            [6, '6', 'Crédito de demanda ACH'],
            [7, '7', 'Débito de demanda ACH'],
            [8, '8', 'Mantener'],
            [9, '9', 'Clearing Nacional o Regional'],
            [10, '10', 'Efectivo'],
            [11, '11', 'Reversión Crédito Ahorro'],
            [12, '12', 'Reversión Débito Ahorro'],
            [13, '13', 'Crédito Ahorro'],
            [14, '14', 'Débito Ahorro'],
            [15, '15', 'Bookentry Crédito'],
            [16, '16', 'Bookentry Débito'],
            [17, '17', 'Concentración de la demanda en efectivo / Desembolso Crédito (CCD)'],
            [18, '18', 'Concentración de la demanda en efectivo / Desembolso (CCD) débito'],
            [19, '19', 'Crédito Pago negocio corporativo (CTP)'],
            [20, '20', 'Cheque'],
            [21, '21', 'Proyecto bancario'],
            [22, '22', 'Proyecto bancario certificado'],
            [23, '23', 'Cheque bancario'],
            [24, '24', 'Nota cambiaria esperando aceptación'],
            [25, '25', 'Cheque certificado'],
            [26, '26', 'Cheque Local'],
            [27, '27', 'Débito Pago Negocio Corporativo (CTP)'],
            [28, '28', 'Crédito Negocio Intercambio Corporativo (CTX)'],
            [29, '29', 'Débito Negocio Intercambio Corporativo (CTX)'],
            [30, '30', 'Transferencia Crédito'],
            [31, '31', 'Transferencia Débito'],
            [32, '32', 'Concentración Efectivo / Desembolso Crédito plus (CCD+)'],
            [33, '33', 'Concentración Efectivo / Desembolso Débito plus (CCD+)'],
            [34, '34', 'Pago y depósito pre acordado (PPD)'],
            [35, '35', 'Concentración efectivo ahorros / Desembolso Crédito (CCD)'],
            [36, '36', 'Concentración efectivo ahorros / Desembolso Débito (CCD)'],
            [37, '37', 'Pago Negocio Corporativo Ahorros Crédito (CTP)'],
            [38, '38', 'Pago Negocio Corporativo Ahorros Débito (CTP)'],
            [39, '39', 'Crédito Negocio Intercambio Corporativo (CTX)'],
            [40, '40', 'Débito Negocio Intercambio Corporativo (CTX)'],
            [41, '41', 'Concentración efectivo / Desembolso Crédito plus (CCD+)'],
            [42, '42', 'Consignación bancaria'],
            [43, '43', 'Concentración efectivo / Desembolso Débito plus (CCD+)'],
            [44, '44', 'Nota cambiaria'],
            [45, '45', 'Transferencia Crédito Bancario'],
            [46, '46', 'Transferencia Débito Interbancario'],
            [47, '47', 'Transferencia Débito Bancaria'],
            [48, '48', 'Tarjeta Crédito'],
            [49, '49', 'Tarjeta Débito'],
            [50, '50', 'Postgiro'],
            [51, '51', 'Telex estándar bancario francés'],
            [52, '52', 'Pago comercial urgente'],
            [53, '53', 'Pago Tesorería Urgente'],
            [54, '60', 'Nota promisoria'],
            [55, '61', 'Nota promisoria firmada por el acreedor'],
            [56, '62', 'Nota promisoria firmada por el acreedor, avalada por el banco'],
            [57, '63', 'Nota promisoria firmada por el acreedor, avalada por un tercero'],
            [58, '64', 'Nota promisoria firmada por el banco'],
            [59, '65', 'Nota promisoria firmada por un banco avalada por otro banco'],
            [60, '66', 'Nota promisoria firmada'],
            [61, '67', 'Nota promisoria firmada por un tercero avalada por un banco'],
            [62, '70', 'Retiro de nota por el acreedor'],
            [63, '74', 'Retiro de nota por el acreedor sobre un banco'],
            [64, '75', 'Retiro de nota por el acreedor, avalada por otro banco'],
            [65, '76', 'Retiro de nota por el acreedor, sobre un banco avalada por un tercero'],
            [66, '77', 'Retiro de una nota por el acreedor sobre un tercero'],
            [67, '78', 'Retiro de una nota por el acreedor sobre un tercero avalada por un banco'],
            [68, '91', 'Nota bancaria transferible'],
            [69, '92', 'Cheque local transferible'],
            [70, '93', 'Giro referenciado'],
            [71, '94', 'Giro urgente'],
            [72, '95', 'Giro formato abierto'],
            [73, '96', 'Método de pago solicitado no usado'],
            [74, '97', 'Clearing entre partners'],
            [75, 'ZZZ', 'Acuerdo mutuo'],
        ];

        foreach ($methods as [$id, $code, $name]) {
            PaymentMethod::updateOrCreate(['id' => $id], ['code' => $code, 'name' => $name]);
        }
    }
}
