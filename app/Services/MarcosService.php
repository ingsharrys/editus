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
        $etiqueta = ['fuente' => 'montserrat-black', 'tam' => 40, 'color' => '#FFFFFF', 'fondo' => null, 'alinear' => 'centro', 'espaciado' => 4, 'mayusculas' => true];
        return [
            'opa-titulo' => ['nombre' => 'Opa Noticias · con título', 'zonas' => [
                'foto' => ['x' => 0, 'y' => 0, 'w' => 1080, 'h' => 1350],
                'etiqueta' => ['x' => 81, 'y' => 876, 'w' => 416, 'h' => 56] + $etiqueta,
                'titulo' => ['x' => 81, 'y' => 940, 'w' => 914, 'h' => 186, 'fuente' => 'bree', 'tam_max' => 58, 'tam_min' => 40, 'color' => '#FFFFFF', 'interlineado' => 1.12, 'alinear' => 'izquierda', 'max_lineas' => 3, 'sombra' => false],
            ]],
            'opa-etiqueta' => ['nombre' => 'Opa Noticias · solo categoría', 'zonas' => [
                'foto' => ['x' => 0, 'y' => 0, 'w' => 1080, 'h' => 1083],
                'etiqueta' => ['x' => 335, 'y' => 994, 'w' => 394, 'h' => 57] + $etiqueta,
                'titulo' => null,
            ]],
            'neiva24' => ['nombre' => 'Neiva 24', 'zonas' => [
                'foto' => ['x' => 0, 'y' => 0, 'w' => 1080, 'h' => 1350],
                'etiqueta' => ['x' => 68, 'y' => 960, 'w' => 268, 'h' => 62, 'tam' => 34] + $etiqueta,
                'titulo' => ['x' => 110, 'y' => 1036, 'w' => 880, 'h' => 190, 'fuente' => 'montserrat-extrabold', 'tam_max' => 54, 'tam_min' => 36, 'color' => '#FFFFFF', 'interlineado' => 1.12, 'alinear' => 'izquierda', 'max_lineas' => 3, 'sombra' => false],
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
}
