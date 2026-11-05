<?php

namespace App\Support;

/** Números en letras para los contratos: 2500000 → «DOS MILLONES QUINIENTOS MIL». */
class NumeroEnLetras
{
    private const BASICOS = ['', 'uno', 'dos', 'tres', 'cuatro', 'cinco', 'seis', 'siete', 'ocho', 'nueve', 'diez',
        'once', 'doce', 'trece', 'catorce', 'quince', 'dieciséis', 'diecisiete', 'dieciocho', 'diecinueve', 'veinte',
        'veintiuno', 'veintidós', 'veintitrés', 'veinticuatro', 'veinticinco', 'veintiséis', 'veintisiete', 'veintiocho', 'veintinueve'];
    private const DECENAS = [3 => 'treinta', 4 => 'cuarenta', 5 => 'cincuenta', 6 => 'sesenta', 7 => 'setenta', 8 => 'ochenta', 9 => 'noventa'];
    private const CENTENAS = [1 => 'ciento', 2 => 'doscientos', 3 => 'trescientos', 4 => 'cuatrocientos', 5 => 'quinientos',
        6 => 'seiscientos', 7 => 'setecientos', 8 => 'ochocientos', 9 => 'novecientos'];

    /**
     * En minúsculas: 21000 → «veintiún mil», 1500000 → «un millón quinientos mil».
     * Con $antesDeNombre el «uno» final se vuelve «un» («veintiún pesos»).
     */
    public static function texto(int $n, bool $antesDeNombre = false): string
    {
        if ($n === 0) return 'cero';
        if ($n < 0) return 'menos ' . self::texto(-$n, $antesDeNombre);
        $billones = intdiv($n, 1_000_000_000_000);
        $millones = intdiv($n % 1_000_000_000_000, 1_000_000);
        $resto = $n % 1_000_000;
        $partes = [];
        if ($billones) $partes[] = $billones === 1 ? 'un billón' : self::hastaUnMillon($billones, true) . ' billones';
        if ($millones) $partes[] = $millones === 1 ? 'un millón' : self::hastaUnMillon($millones, true) . ' millones';
        if ($resto) $partes[] = self::hastaUnMillon($resto, $antesDeNombre);
        return implode(' ', $partes);
    }

    /** Pesos en mayúsculas para «la suma de $ … (… pesos M/cte.)»: los millones exactos llevan «DE» («DOS MILLONES DE pesos»). */
    public static function pesos(int $n): string
    {
        $texto = mb_strtoupper(self::texto($n, true));
        return $n >= 1_000_000 && $n % 1_000_000 === 0 ? $texto . ' DE' : $texto;
    }

    /** 1 a 999.999; con $apocope el «uno» final se vuelve «un» (va antes de «millones»). */
    private static function hastaUnMillon(int $n, bool $apocope): string
    {
        $miles = intdiv($n, 1000);
        $resto = $n % 1000;
        $partes = [];
        if ($miles) $partes[] = $miles === 1 ? 'mil' : self::hastaMil($miles, true) . ' mil';
        if ($resto) $partes[] = self::hastaMil($resto, $apocope);
        return implode(' ', $partes);
    }

    /** 1 a 999. */
    private static function hastaMil(int $n, bool $apocope): string
    {
        if ($n === 100) return 'cien';
        $partes = [];
        if ($c = intdiv($n, 100)) $partes[] = self::CENTENAS[$c];
        if ($r = $n % 100) {
            $t = $r < 30 ? self::BASICOS[$r] : self::DECENAS[intdiv($r, 10)] . ($r % 10 ? ' y ' . self::BASICOS[$r % 10] : '');
            if ($apocope) $t = preg_replace(['/veintiuno$/u', '/uno$/u'], ['veintiún', 'un'], $t);
            $partes[] = $t;
        }
        return implode(' ', $partes);
    }
}
