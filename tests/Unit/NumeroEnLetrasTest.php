<?php

namespace Tests\Unit;

use App\Support\NumeroEnLetras;
use PHPUnit\Framework\TestCase;

/** Valor de los contratos en letras («la suma de $ 2.500.000 (DOS MILLONES QUINIENTOS MIL pesos M/cte.)»). */
class NumeroEnLetrasTest extends TestCase
{
    public function test_pesos_en_letras(): void
    {
        foreach ([
            2500000 => 'DOS MILLONES QUINIENTOS MIL', 1000000 => 'UN MILLÓN DE', 2000000 => 'DOS MILLONES DE',
            21000 => 'VEINTIÚN MIL', 21 => 'VEINTIÚN', 101 => 'CIENTO UN', 100 => 'CIEN', 115 => 'CIENTO QUINCE',
            1500000 => 'UN MILLÓN QUINIENTOS MIL', 31000000 => 'TREINTA Y UN MILLONES DE', 1001000 => 'UN MILLÓN MIL',
            1500000000 => 'MIL QUINIENTOS MILLONES DE', 3550750 => 'TRES MILLONES QUINIENTOS CINCUENTA MIL SETECIENTOS CINCUENTA',
            16000 => 'DIECISÉIS MIL', 222222 => 'DOSCIENTOS VEINTIDÓS MIL DOSCIENTOS VEINTIDÓS',
        ] as $n => $letras) {
            $this->assertSame($letras, NumeroEnLetras::pesos($n), (string) $n);
        }
        $this->assertSame('veintiuno', NumeroEnLetras::texto(21));
    }
}
