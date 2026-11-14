<?php

namespace App\Services;

/**
 * Logos y membretes livianos: máximo 400 px de ancho y 100 KB, en WebP (o JPG/PNG si el PHP del
 * servidor no tiene WebP). El navegador ya los manda así (resources/views/partials/imagen-ligera-js);
 * esto es la segunda capa, por si llegan tal cual (navegador viejo o sin JavaScript).
 */
class ImagenLigera
{
    public const ANCHO = 400;
    public const MAX_BYTES = 100 * 1024;

    /** El PHP del servidor puede leer y escribir WebP (y dompdf mostrarlo en el PDF). */
    public static function webpDisponible(): bool
    {
        return function_exists('imagewebp') && function_exists('imagecreatefromwebp') && (bool) (gd_info()['WebP Support'] ?? false);
    }

    /**
     * Devuelve [bytes, extensión]. Si la imagen ya cumple (WebP, ≤ 400 px y ≤ 100 KB) se deja igual;
     * si no, se reduce y se convierte. Lanza InvalidArgumentException si no es una imagen que se pueda leer.
     */
    public static function reducir(string $bytes, int $ancho = self::ANCHO, int $maxBytes = self::MAX_BYTES): array
    {
        $info = @getimagesizefromstring($bytes);
        $mime = $info['mime'] ?? '';
        $ext = match ($mime) { 'image/webp' => 'webp', 'image/png' => 'png', 'image/jpeg' => 'jpg', default => null };
        if (!$ext || empty($info[0]) || empty($info[1])) throw new \InvalidArgumentException(__('La imagen no se pudo leer. Usa un archivo PNG, JPG o WebP.'));
        [$w, $h] = [(int) $info[0], (int) $info[1]];
        $webp = self::webpDisponible();
        $cumple = $w <= $ancho && strlen($bytes) <= $maxBytes;
        if ($cumple && ($ext === 'webp' ? $webp : !$webp)) return [$bytes, $ext];

        $original = function_exists('imagecreatefromstring') ? @imagecreatefromstring($bytes) : false;
        if (!$original) {
            if ($cumple) return [$bytes, $ext]; // no se puede convertir, pero ya es liviana
            throw new \InvalidArgumentException(__('La imagen no se pudo reducir. Usa un PNG o JPG de máximo :ancho px de ancho.', ['ancho' => $ancho]));
        }
        $escala = min(1, $ancho / $w);
        try {
            for ($intento = 0; $intento < 8; $intento++) {
                $nw = max(1, (int) round($w * $escala));
                $nh = max(1, (int) round($h * $escala));
                $lienzo = imagecreatetruecolor($nw, $nh);
                if ($webp) { // WebP conserva la transparencia del logo
                    imagealphablending($lienzo, false);
                    imagesavealpha($lienzo, true);
                    imagefill($lienzo, 0, 0, imagecolorallocatealpha($lienzo, 255, 255, 255, 127));
                } else {
                    imagefill($lienzo, 0, 0, imagecolorallocate($lienzo, 255, 255, 255)); // JPG: lo transparente queda blanco
                }
                imagecopyresampled($lienzo, $original, 0, 0, 0, 0, $nw, $nh, $w, $h);
                foreach ([90, 80, 70, 60, 50, 40] as $calidad) {
                    ob_start();
                    $webp ? imagewebp($lienzo, null, $calidad) : imagejpeg($lienzo, null, $calidad);
                    $salida = (string) ob_get_clean();
                    if ($salida !== '' && strlen($salida) <= $maxBytes) {
                        imagedestroy($lienzo);
                        return [$salida, $webp ? 'webp' : 'jpg'];
                    }
                }
                imagedestroy($lienzo);
                $escala *= 0.8;
            }
        } finally {
            imagedestroy($original);
        }
        throw new \InvalidArgumentException(__('La imagen no se pudo reducir a :kb KB. Usa una imagen más sencilla.', ['kb' => (int) round($maxBytes / 1024)]));
    }
}
