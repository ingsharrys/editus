<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Subida TEMPORAL de videos desde la app del editor, para publicarlos en
 * Facebook / Instagram (Meta necesita una URL pública del archivo).
 *
 * El archivo va a storage/app/public/videos/tmp y se borra apenas se
 * publica (PublicacionesController::video) o, como máximo, a las 24 horas.
 * No queda nada almacenado.
 *
 * La petición la firma el backend de esnoticia (que sí conoce el token de
 * integración): sig = HMAC-SHA256("{u}|{exp}", EDITUS_INGEST_TOKEN).
 */
class SubidasController extends Controller
{
    public const CARPETA = 'videos/tmp';

    public function video(Request $request): JsonResponse
    {
        $u = (string) $request->input('u', '');
        $exp = (int) $request->input('exp', 0);
        $sig = (string) $request->input('sig', '');
        $token = (string) config('services.editus.ingest_token');
        if ($token === '' || $u === '' || $exp <= 0 || $sig === '') {
            return response()->json(['success' => false, 'error' => 'Subida no autorizada'], 401);
        }
        if ($exp < time()) {
            return response()->json(['success' => false, 'error' => 'La autorización de subida venció: vuelve a intentarlo'], 401);
        }
        if (!hash_equals(hash_hmac('sha256', "{$u}|{$exp}", $token), $sig)) {
            return response()->json(['success' => false, 'error' => 'Firma de subida inválida'], 401);
        }

        $datos = $request->validate([
            'video' => ['required', 'file', 'mimetypes:video/mp4,video/quicktime,video/x-m4v,application/octet-stream', 'max:256000'],
        ], [
            'video.max' => 'El video no puede superar 250 MB',
            'video.mimetypes' => 'El video debe ser MP4 o MOV',
        ]);

        self::limpiarViejos();

        $archivo = $datos['video'];
        $ext = strtolower($archivo->getClientOriginalExtension() ?: 'mp4');
        if (!in_array($ext, ['mp4', 'mov', 'm4v'], true)) $ext = 'mp4';
        $id = (string) Str::uuid();
        $ruta = $archivo->storeAs(self::CARPETA, "{$id}.{$ext}", 'public');
        if (!$ruta) {
            return response()->json(['success' => false, 'error' => 'No se pudo guardar el video temporal'], 500);
        }

        return response()->json([
            'success' => true,
            'video_id' => $id,
            'url' => url(Storage::disk('public')->url($ruta)),
            'tamano' => $archivo->getSize(),
            'expira_en' => now()->addDay()->toIso8601String(),
        ]);
    }

    /** Ruta relativa (disco public) del temporal de un video_id, o null si no existe. */
    public static function rutaDe(string $videoId): ?string
    {
        if (!preg_match('/^[0-9a-f-]{36}$/', $videoId)) return null;
        foreach (['mp4', 'mov', 'm4v'] as $ext) {
            $ruta = self::CARPETA . "/{$videoId}.{$ext}";
            if (Storage::disk('public')->exists($ruta)) return $ruta;
        }
        return null;
    }

    public static function borrar(string $videoId): void
    {
        $ruta = self::rutaDe($videoId);
        if ($ruta) Storage::disk('public')->delete($ruta);
    }

    /** Borra temporales con más de 24 h (por si una publicación no terminó). */
    public static function limpiarViejos(): void
    {
        try {
            $disco = Storage::disk('public');
            foreach ($disco->files(self::CARPETA) as $f) {
                if ($disco->lastModified($f) < time() - 86400) $disco->delete($f);
            }
        } catch (\Throwable) {
            // la carpeta puede no existir todavía
        }
    }
}
