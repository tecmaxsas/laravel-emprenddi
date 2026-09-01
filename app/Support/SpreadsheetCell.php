<?php

namespace App\Support;

use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\FormulaCell;

/**
 * Valor util de una celda de Excel, para todos los importadores.
 *
 * Existe por un error que se repitio en dos importadores distintos: en una
 * celda con formula, getValue() de OpenSpout devuelve el TEXTO de la formula
 * ("=E2-F2"), no el resultado. Al convertirlo a numero da 0, asi que un
 * archivo que en pantalla se ve correcto se lee como si esas columnas
 * estuvieran vacias — precios en cero, cantidades en cero.
 *
 * El resultado que Excel deja guardado en el archivo esta en
 * getComputedValue(), y es el que hay que leer.
 */
class SpreadsheetCell
{
    public static function value(mixed $celda): mixed
    {
        if ($celda instanceof FormulaCell) {
            // Si Excel no guardo el resultado, OpenSpout devuelve 0 — que es
            // indistinguible de un 0 legitimo calculado con formula. No se
            // adivina aqui: le toca al importador validar que las cifras
            // cuadren y explicarlo.
            return $celda->getComputedValue() ?? $celda->getValue();
        }

        if ($celda instanceof Cell) {
            return $celda->getValue();
        }

        // Algunos lectores ya entregan el valor pelado.
        return is_object($celda) && method_exists($celda, 'getValue')
            ? $celda->getValue()
            : $celda;
    }
}
