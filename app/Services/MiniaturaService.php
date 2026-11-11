<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Miniatura del anuncio de un en vivo programado o pregrabado (la imagen que se ve en Facebook y
 * YouTube antes de que empiece). JPG o PNG; se guarda de hasta 2 MB (el máximo de YouTube) y, si llega más pesada o grande, se reduce. Ideal 1280×720.
 */
class MiniaturaService
{
    public const MAX_BYTES = 2 * 1024 * 1024;
    private const CARPETA = 'en-vivo/miniaturas';

    /** Desde un formulario web. */
    public function guardarArchivo(UploadedFile $f): string
    {
        return $this->guardar((string) file_get_contents($f->getRealPath()));
    }

    /** Desde la app: imagen en base64 (con o sin "data:image/...;base64,"). */
    public function guardarBase64(string $b64): string
    {
        $b64 = preg_replace('#^data:image/[a-z]+;base64,#i', '', trim($b64));
        $bytes = base64_decode((string) $b64, true);
        if ($bytes === false) throw new \InvalidArgumentException('La miniatura no llegó bien. Vuelve a elegir la imagen.');
        return $this->guardar($bytes);
    }

    private function guardar(string $bytes): string
    {
        $info = @getimagesizefromstring($bytes);
        $ext = match ($info['mime'] ?? '') { 'image/jpeg' => 'jpg', 'image/png' => 'png', default => null };
        if (!$ext) throw new \InvalidArgumentException('La miniatura debe ser una imagen JPG o PNG.');
        if (($info[0] ?? 0) < 640 || ($info[1] ?? 0) < 360) throw new \InvalidArgumentException('La miniatura es muy pequeña: usa al menos 1280×720 (mínimo 640×360).');
        // Muy pesada o muy grande (p. ej. una foto del celular): se reduce a JPG en vez de rechazarla
        if (strlen($bytes) > self::MAX_BYTES || $info[0] > self::ANCHO || $info[1] > self::ALTO) {
            $bytes = $this->reducir($bytes, (int) $info[0], (int) $info[1]);
            $ext = 'jpg';
        }
        $ruta = self::CARPETA . '/' . Str::lower(Str::random(24)) . '.' . $ext;
        Storage::disk('public')->put($ruta, $bytes);
        return $ruta;
    }

    /** Lado máximo de la miniatura guardada (sin recortar: se reduce para que quepa). */
    private const ANCHO = 1920;
    private const ALTO = 1080;

    /** JPG que cabe en 1920×1080 y pesa menos de 2 MB (baja la calidad y, si hace falta, el tamaño). */
    private function reducir(string $bytes, int $ancho, int $alto): string
    {
        $original = function_exists('imagecreatefromstring') ? @imagecreatefromstring($bytes) : false;
        if (!$original) {
            if (strlen($bytes) > self::MAX_BYTES) throw new \InvalidArgumentException('La miniatura pesa más de 2 MB y no se pudo reducir. Usa una imagen JPG de 1280×720.');
            return $bytes;
        }
        $escala = min(1, self::ANCHO / $ancho, self::ALTO / $alto);
        try {
            for ($intento = 0; $intento < 6; $intento++) {
                $w = max(1, (int) round($ancho * $escala));
                $h = max(1, (int) round($alto * $escala));
                $lienzo = imagecreatetruecolor($w, $h);
                imagefill($lienzo, 0, 0, imagecolorallocate($lienzo, 255, 255, 255)); // un PNG transparente no queda negro
                imagecopyresampled($lienzo, $original, 0, 0, 0, 0, $w, $h, $ancho, $alto);
                foreach ([90, 82, 74, 66] as $calidad) {
                    ob_start();
                    imagejpeg($lienzo, null, $calidad);
                    $jpg = (string) ob_get_clean();
                    if (strlen($jpg) <= self::MAX_BYTES) { imagedestroy($lienzo); return $jpg; }
                }
                imagedestroy($lienzo);
                $escala *= 0.8;
            }
        } finally {
            imagedestroy($original);
        }
        throw new \InvalidArgumentException('La miniatura pesa más de 2 MB y no se pudo reducir. Usa una imagen JPG de 1280×720.');
    }

    public static function url(string $ruta): string
    {
        return url(Storage::disk('public')->url($ruta));
    }

    /** Ruta en disco (para enviarla a Facebook / YouTube). null si ya no existe. */
    public static function archivo(?string $ruta): ?string
    {
        if (!$ruta || !Storage::disk('public')->exists($ruta)) return null;
        return Storage::disk('public')->path($ruta);
    }
}
