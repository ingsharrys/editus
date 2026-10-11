<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Miniatura del anuncio de un en vivo programado o pregrabado (la imagen que se ve en Facebook y
 * YouTube antes de que empiece). JPG o PNG de hasta 2 MB (el máximo de YouTube); se recomienda 1280×720.
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
        if (strlen($bytes) > self::MAX_BYTES) throw new \InvalidArgumentException('La miniatura pesa más de 2 MB. Usa una imagen JPG de 1280×720.');
        $info = @getimagesizefromstring($bytes);
        $ext = match ($info['mime'] ?? '') { 'image/jpeg' => 'jpg', 'image/png' => 'png', default => null };
        if (!$ext) throw new \InvalidArgumentException('La miniatura debe ser una imagen JPG o PNG.');
        if (($info[0] ?? 0) < 640 || ($info[1] ?? 0) < 360) throw new \InvalidArgumentException('La miniatura es muy pequeña: usa al menos 1280×720 (mínimo 640×360).');
        $ruta = self::CARPETA . '/' . Str::lower(Str::random(24)) . '.' . $ext;
        Storage::disk('public')->put($ruta, $bytes);
        return $ruta;
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
