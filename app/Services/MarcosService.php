<?php

namespace App\Services;

/**
 * Marcos de las plantillas de imagen (diseños hechos en Canva).
 *
 * - El marco es un PNG con transparencia donde va la foto. Con Canva gratis no se puede
 *   exportar con fondo transparente: se exporta dos veces (fondo blanco y fondo negro) y
 *   aquí se calcula la transparencia comparando las dos (lo blanco que se vuelve negro es
 *   transparente; lo que no cambia es opaco; las sombras quedan semitransparentes).
 * - Las zonas dicen dónde va la foto, la etiqueta (categoría) y el título. El backend de
 *   esnoticia compone la pieza con ellas (PlantillaService::renderizarMarco).
 */
class MarcosService
{
    public const FUENTES = [
        'bree' => 'Bree Serif',
        'montserrat-black' => 'Montserrat Black',
        'montserrat-extrabold' => 'Montserrat ExtraBold',
        'playfair' => 'Playfair Display',
        'inter-black' => 'Inter Black',
        'inter-bold' => 'Inter Bold',
    ];

    /** Zonas listas para los diseños de Canva (lienzo 1080 × 1350). */
    public static function presets(): array
    {
        // ajustar: la caja de color del marco se quita y se dibuja del largo de la categoría
        $etiqueta = ['fuente' => 'montserrat-black', 'tam' => 44, 'color' => '#FFFFFF', 'fondo' => null, 'alinear' => 'centro', 'espaciado' => 4, 'mayusculas' => true, 'ajustar' => true, 'radio' => 0];
        return [
            'opa-titulo' => ['nombre' => 'Opa Noticias · con título', 'zonas' => [
                'foto' => ['x' => 0, 'y' => 0, 'w' => 1080, 'h' => 1350],
                'etiqueta' => ['x' => 81, 'y' => 876, 'w' => 416, 'h' => 56, 'alinear' => 'izquierda'] + $etiqueta,
                'titulo' => ['x' => 81, 'y' => 940, 'w' => 914, 'h' => 186, 'fuente' => 'bree', 'tam_max' => 61, 'tam_min' => 40, 'color' => '#FFFFFF', 'interlineado' => 1.0, 'alinear' => 'izquierda', 'max_lineas' => 3, 'sombra' => false],
            ]],
            'opa-etiqueta' => ['nombre' => 'Opa Noticias · solo categoría', 'zonas' => [
                'foto' => ['x' => 0, 'y' => 0, 'w' => 1080, 'h' => 1083],
                'etiqueta' => ['x' => 335, 'y' => 994, 'w' => 394, 'h' => 57] + $etiqueta,
                'titulo' => null,
            ]],
            'neiva24' => ['nombre' => 'Neiva 24', 'zonas' => [
                'foto' => ['x' => 0, 'y' => 0, 'w' => 1080, 'h' => 1350],
                'etiqueta' => ['x' => 68, 'y' => 960, 'w' => 268, 'h' => 62, 'tam' => 42, 'alinear' => 'izquierda', 'radio' => 31] + $etiqueta,
                'titulo' => ['x' => 110, 'y' => 1036, 'w' => 880, 'h' => 190, 'fuente' => 'montserrat-extrabold', 'tam_max' => 56, 'tam_min' => 36, 'color' => '#FFFFFF', 'interlineado' => 1.08, 'alinear' => 'izquierda', 'max_lineas' => 3, 'sombra' => false],
            ]],
        ];
    }

    /** Limpia las zonas que llegan del formulario (JSON). */
    public static function normalizarZonas($zonas, int $W = 1080, int $H = 1350): array
    {
        $z = is_string($zonas) ? (json_decode($zonas, true) ?: []) : (array) $zonas;
        $caja = function ($c) use ($W, $H) {
            if (!is_array($c)) return null;
            $x = max(0, min($W, (int) round($c['x'] ?? 0)));
            $y = max(0, min($H, (int) round($c['y'] ?? 0)));
            $w = max(0, min($W - $x, (int) round($c['w'] ?? 0)));
            $h = max(0, min($H - $y, (int) round($c['h'] ?? 0)));
            return ($w >= 5 && $h >= 5) ? compact('x', 'y', 'w', 'h') : null;
        };
        $color = fn($v, $def) => preg_match('/^#[0-9A-Fa-f]{6}$/', (string) $v) ? strtoupper($v) : $def;
        $fuente = fn($v, $def) => array_key_exists((string) $v, self::FUENTES) ? (string) $v : $def;
        $alinear = fn($v, $def) => in_array($v, ['centro', 'izquierda'], true) ? $v : $def;

        $salida = ['foto' => $caja($z['foto'] ?? null) ?? ['x' => 0, 'y' => 0, 'w' => $W, 'h' => $H], 'etiqueta' => null, 'titulo' => null];
        if (!empty($z['etiqueta']) && ($c = $caja($z['etiqueta']))) {
            $e = $z['etiqueta'];
            $salida['etiqueta'] = $c + [
                'fuente' => $fuente($e['fuente'] ?? null, 'montserrat-black'),
                'tam' => max(10, min(120, (int) ($e['tam'] ?? 40))),
                'color' => $color($e['color'] ?? null, '#FFFFFF'),
                'fondo' => !empty($e['fondo']) ? $color($e['fondo'], null) : null,
                'alinear' => $alinear($e['alinear'] ?? null, 'centro'),
                'espaciado' => max(0, min(20, (int) ($e['espaciado'] ?? 4))),
                'mayusculas' => ($e['mayusculas'] ?? true) !== false,
                'ajustar' => !empty($e['ajustar']),
                'radio' => max(0, min(60, (int) ($e['radio'] ?? 0))),
            ];
        }
        if (!empty($z['titulo']) && ($c = $caja($z['titulo']))) {
            $t = $z['titulo'];
            $max = max(12, min(160, (int) ($t['tam_max'] ?? 60)));
            $salida['titulo'] = $c + [
                'fuente' => $fuente($t['fuente'] ?? null, 'bree'),
                'tam_max' => $max,
                'tam_min' => max(10, min($max, (int) ($t['tam_min'] ?? 36))),
                'color' => $color($t['color'] ?? null, '#FFFFFF'),
                'interlineado' => round(max(0.8, min(1.6, (float) ($t['interlineado'] ?? 1.05))), 2),
                'alinear' => $alinear($t['alinear'] ?? null, 'izquierda'),
                'max_lineas' => max(1, min(6, (int) ($t['max_lineas'] ?? 3))),
                'sombra' => !empty($t['sombra']),
            ];
        }
        return $salida;
    }

    /**
     * PNG transparente a partir de las dos exportaciones de Canva (fondo blanco y fondo negro).
     * Por píxel: alfa = 1 − (blanco − negro) / 255 y color = negro / alfa.
     */
    public function desdeDosFondos(string $bytesBlanco, string $bytesNegro): string
    {
        $b = @imagecreatefromstring($bytesBlanco);
        $n = @imagecreatefromstring($bytesNegro);
        if (!$b || !$n) throw new \RuntimeException(__('No se pudieron leer las dos imágenes del marco.'));
        $W = imagesx($b); $H = imagesy($b);
        if (imagesx($n) !== $W || imagesy($n) !== $H) {
            throw new \RuntimeException(__('Las dos imágenes del marco deben tener el mismo tamaño (exporta las dos páginas igual en Canva).'));
        }
        $out = imagecreatetruecolor($W, $H);
        imagealphablending($out, false);
        imagesavealpha($out, true);
        for ($y = 0; $y < $H; $y++) {
            for ($x = 0; $x < $W; $x++) {
                $cb = imagecolorat($b, $x, $y); $cn = imagecolorat($n, $x, $y);
                $rb = ($cb >> 16) & 255; $gb = ($cb >> 8) & 255; $bb = $cb & 255;
                $rn = ($cn >> 16) & 255; $gn = ($cn >> 8) & 255; $bn = $cn & 255;
                $a = 1 - (($rb - $rn) + ($gb - $gn) + ($bb - $bn)) / 765;
                $a = max(0.0, min(1.0, $a));
                if ($a < 0.004) {
                    imagesetpixel($out, $x, $y, 0x7F000000);
                    continue;
                }
                $r = min(255, (int) round($rn / $a)); $g = min(255, (int) round($gn / $a)); $bl = min(255, (int) round($bn / $a));
                $alfaGd = 127 - (int) round($a * 127);
                imagesetpixel($out, $x, $y, ($alfaGd << 24) | ($r << 16) | ($g << 8) | $bl);
            }
        }
        imagedestroy($b); imagedestroy($n);
        ob_start();
        imagepng($out, null, 9);
        $png = (string) ob_get_clean();
        imagedestroy($out);
        return $png;
    }

    /** ¿El PNG tiene transparencia? (un marco sin ella taparía la foto). */
    public function tieneTransparencia(string $bytes): bool
    {
        $img = @imagecreatefromstring($bytes);
        if (!$img) return false;
        if (!imageistruecolor($img)) imagepalettetotruecolor($img);
        $W = imagesx($img); $H = imagesy($img);
        $paso = max(1, (int) floor(min($W, $H) / 60));
        for ($y = 0; $y < $H; $y += $paso) {
            for ($x = 0; $x < $W; $x += $paso) {
                if (((imagecolorat($img, $x, $y) >> 24) & 0x7F) > 100) { imagedestroy($img); return true; }
            }
        }
        imagedestroy($img);
        return false;
    }

    /**
     * Para un PNG sin transparencia (blanco donde va la foto): desde el centro de la zona de la
     * foto se recorren los píxeles grises conectados (blanco, el degradado y hasta casi negro) y
     * se vuelven negro con transparencia (alfa = 1 − brillo). Lo de color (logos, franjas) y lo
     * que no se alcanza (textos blancos sobre la banda negra) queda igual.
     */
    public function blancoATransparente(string $bytes, array $foto): string
    {
        $img = @imagecreatefromstring($bytes);
        if (!$img) throw new \RuntimeException(__('No se pudo leer el marco.'));
        if (!imageistruecolor($img)) imagepalettetotruecolor($img);
        $W = imagesx($img); $H = imagesy($img);
        $sx = (int) min($W - 1, max(0, ($foto['x'] ?? 0) + ($foto['w'] ?? $W) / 2));
        $sy = (int) min($H - 1, max(0, ($foto['y'] ?? 0) + ($foto['h'] ?? $H) / 3));
        $out = imagecreatetruecolor($W, $H);
        imagealphablending($out, false); imagesavealpha($out, true);
        imagecopy($out, $img, 0, 0, 0, 0, $W, $H);
        $visto = str_repeat("\0", $W * $H);
        $pila = [$sy * $W + $sx];
        while ($pila) {
            $i = array_pop($pila);
            if ($visto[$i] !== "\0") continue;
            $visto[$i] = "\1";
            $x = $i % $W; $y = intdiv($i, $W);
            $c = imagecolorat($img, $x, $y);
            $r = ($c >> 16) & 255; $g = ($c >> 8) & 255; $b = $c & 255;
            if (max($r, $g, $b) - min($r, $g, $b) > 14) continue;   // color: es parte del diseño
            $v = ($r + $g + $b) / 3;
            $a = 1 - $v / 255;
            imagesetpixel($out, $x, $y, $a < 0.02 ? 0x7F000000 : ((127 - (int) round($a * 127)) << 24));
            if ($v < 8) continue;                                    // negro: no se sigue (protege el pie)
            if ($x > 0) $pila[] = $i - 1;
            if ($x < $W - 1) $pila[] = $i + 1;
            if ($y > 0) $pila[] = $i - $W;
            if ($y < $H - 1) $pila[] = $i + $W;
        }
        imagedestroy($img);
        ob_start(); imagepng($out, null, 9); $png = (string) ob_get_clean();
        imagedestroy($out);
        return $png;
    }

    /** Lleva el marco a 1080 px de ancho (las zonas y los tamaños de letra están en esa escala). */
    public function aAncho1080(string $bytes): string
    {
        $img = @imagecreatefromstring($bytes);
        if (!$img) throw new \RuntimeException(__('No se pudo leer el marco.'));
        $W = imagesx($img); $H = imagesy($img);
        if ($W === 1080) { imagedestroy($img); return $bytes; }
        $nh = (int) round($H * 1080 / $W);
        $out = imagecreatetruecolor(1080, $nh);
        imagealphablending($out, false); imagesavealpha($out, true);
        imagefill($out, 0, 0, 0x7F000000);
        imagecopyresampled($out, $img, 0, 0, 0, 0, 1080, $nh, $W, $H);
        imagedestroy($img);
        ob_start(); imagepng($out, null, 9); $png = (string) ob_get_clean();
        imagedestroy($out);
        return $png;
    }

    /** Medidas listas que mejor corresponden a una plantilla (por su medio y su nombre). */
    public static function presetPara(?string $medio, string $nombre): string
    {
        $n = mb_strtolower($nombre);
        if ($medio === 'neiva24' || str_contains($n, 'neiva')) return 'neiva24';
        if (str_contains($n, 'solo') || str_contains($n, 'categor')) return 'opa-etiqueta';
        return 'opa-titulo';
    }

    /**
     * Quita del marco la caja de color de la categoría (para dibujarla luego del largo del texto):
     * los píxeles del color de la caja dentro de la zona se reemplazan por la mezcla vertical de lo
     * que hay justo arriba y justo abajo (así se conserva la sombra del diseño).
     * Devuelve [png, color #RRGGBB de la caja].
     */
    public function quitarCaja(string $bytes, array $zona): array
    {
        $img = @imagecreatefromstring($bytes);
        if (!$img) throw new \RuntimeException(__('No se pudo leer el marco.'));
        if (!imageistruecolor($img)) imagepalettetotruecolor($img);
        imagealphablending($img, false); imagesavealpha($img, true);
        $W = imagesx($img); $H = imagesy($img);
        $x0 = max(0, (int) $zona['x'] - 3); $x1 = min($W - 1, (int) ($zona['x'] + $zona['w']) + 3);
        $y0 = max(0, (int) $zona['y'] - 3); $y1 = min($H - 1, (int) ($zona['y'] + $zona['h']) + 3);
        // Color de la caja: el más repetido entre los píxeles con color (saturados) de la zona
        $cuenta = [];
        for ($y = $y0; $y <= $y1; $y += 2) {
            for ($x = $x0; $x <= $x1; $x += 2) {
                $c = imagecolorat($img, $x, $y);
                if ((($c >> 24) & 0x7F) > 40) continue;
                $r = ($c >> 16) & 255; $g = ($c >> 8) & 255; $b = $c & 255;
                if (max($r, $g, $b) - min($r, $g, $b) < 60) continue;
                $k = sprintf('#%02X%02X%02X', $r & 0xF8, $g & 0xF8, $b & 0xF8);
                $cuenta[$k] = ($cuenta[$k] ?? 0) + 1;
            }
        }
        if (!$cuenta) { imagedestroy($img); return [$bytes, null]; }
        arsort($cuenta);
        $hex = (string) array_key_first($cuenta);
        [$cr, $cg, $cb] = sscanf($hex, '#%02X%02X%02X');
        $cerca = function (int $c) use ($cr, $cg, $cb): bool {
            $r = ($c >> 16) & 255; $g = ($c >> 8) & 255; $b = $c & 255;
            return abs($r - $cr) + abs($g - $cg) + abs($b - $cb) < 150 && (max($r, $g, $b) - min($r, $g, $b)) > 25;
        };
        // Arriba y abajo de la zona, la primera fila que ya no es caja
        $arriba = max(0, $y0 - 1); $abajo = min($H - 1, $y1 + 1);
        for ($x = $x0; $x <= $x1; $x++) {
            $ta = imagecolorat($img, $x, $arriba); $tb = imagecolorat($img, $x, $abajo);
            // Si el color sigue arriba o abajo de la zona no es la caja (p. ej. la franja lateral de Neiva 24)
            if ($cerca($ta) || $cerca($tb)) continue;
            for ($y = $y0; $y <= $y1; $y++) {
                if (!$cerca(imagecolorat($img, $x, $y))) continue;
                $t = ($y - $arriba) / max(1, $abajo - $arriba);
                $mix = function ($s) use ($ta, $tb, $t) { return (int) round(((($ta >> $s) & ($s === 24 ? 0x7F : 255)) * (1 - $t)) + ((($tb >> $s) & ($s === 24 ? 0x7F : 255)) * $t)); };
                imagesetpixel($img, $x, $y, ($mix(24) << 24) | ($mix(16) << 16) | ($mix(8) << 8) | $mix(0));
            }
        }
        ob_start(); imagepng($img, null, 9); $png = (string) ob_get_clean();
        imagedestroy($img);
        // El color de la caja, más exacto (sin el redondeo de la cuenta)
        return [$png, sprintf('#%02X%02X%02X', min(255, $cr + 3), min(255, $cg + 3), min(255, $cb + 3))];
    }
}
