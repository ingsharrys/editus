<?php

namespace App\Services;

use App\Http\Controllers\Api\EnVivoController;
use App\Models\ActivoEnVivo;
use App\Models\MarcaEnVivo;
use App\Models\TransmisionEnVivo;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Lo que comparten el estudio web y la app del editor: marcas (identidad reutilizable) y
 * archivos propios. El "dueño" es ['user_id' => id] (web) o ['usuario_app' => id] (app).
 */
class EstudioEnVivoService
{
    /** Límite de tamaño por tipo, en MB */
    public const MAX_MB = ['logo' => 8, 'superposicion' => 8, 'fondo' => 8, 'clip' => 300, 'sonido' => 20, 'musica' => 40];
    public const EXTENSIONES = [
        'logo' => ['png', 'jpg', 'jpeg', 'webp', 'gif', 'svg'], 'superposicion' => ['png', 'webp', 'gif'], 'fondo' => ['png', 'jpg', 'jpeg', 'webp'],
        'clip' => ['mp4', 'webm', 'mov', 'm4v'], 'sonido' => ['mp3', 'wav', 'ogg', 'm4a', 'aac'], 'musica' => ['mp3', 'wav', 'ogg', 'm4a', 'aac'],
    ];

    public static function disponible(): bool
    {
        return Schema::hasTable('marcas_en_vivo') && Schema::hasTable('activos_en_vivo');
    }

    // ------------------------------------------------------------------ marcas

    /** Marcas del dueño (si no tiene, se crea "Marca 1"). */
    public function marcas(array $dueno): array
    {
        $lista = MarcaEnVivo::where($dueno)->orderBy('id')->get();
        if ($lista->isEmpty()) $lista = collect([MarcaEnVivo::create($dueno + ['nombre' => 'Marca 1', 'datos' => MarcaEnVivo::DEFECTO])]);
        return $lista->map(fn($m) => $m->paraApi())->values()->all();
    }

    public function marcaDe(array $dueno, $id): ?MarcaEnVivo
    {
        return $id ? MarcaEnVivo::where($dueno)->find((int) $id) : null;
    }

    public function crearMarca(array $dueno, ?string $nombre, $copiarDe = null): MarcaEnVivo
    {
        $n = MarcaEnVivo::where($dueno)->count();
        if ($n >= 20) throw new \RuntimeException('Puedes tener hasta 20 marcas.');
        $base = $this->marcaDe($dueno, $copiarDe);
        return MarcaEnVivo::create($dueno + ['nombre' => trim((string) $nombre) ?: 'Marca ' . ($n + 1), 'datos' => $base ? $base->datosCompletos() : MarcaEnVivo::DEFECTO]);
    }

    public function guardarMarca(MarcaEnVivo $m, ?string $nombre, ?array $datos): MarcaEnVivo
    {
        if (trim((string) $nombre) !== '') $m->nombre = Str::limit(trim((string) $nombre), 60, '');
        if (is_array($datos)) $m->datos = self::normalizarMarca(array_merge($m->datosCompletos(), $datos));
        $m->save();
        return $m;
    }

    public function borrarMarca(array $dueno, MarcaEnVivo $m): void
    {
        if (MarcaEnVivo::where($dueno)->count() <= 1) throw new \RuntimeException('Debe quedar al menos una marca.');
        $m->delete();
    }

    /**
     * Usa la marca en la transmisión (plantilla + sala). Con $adoptarSiNueva, una marca que nunca se
     * editó toma primero el aspecto actual de la transmisión (logo, colores, pie…) en vez de borrarlo.
     */
    public function aplicar(TransmisionEnVivo $t, MarcaEnVivo $m, ?callable $filtroPlan = null, bool $adoptarSiNueva = false): array
    {
        if ($adoptarSiNueva && self::normalizarMarca(MarcaEnVivo::DEFECTO) == self::normalizarMarca($m->datosCompletos())) {
            $actual = array_intersect_key((array) ($t->plantilla ?? []), array_flip(MarcaEnVivo::A_PLANTILLA));
            if ($actual) $this->guardarMarca($m, null, $actual);
        }
        $plantilla = $m->paraPlantilla();
        if ($filtroPlan) $plantilla = $filtroPlan($plantilla);
        $j = app(EnVivoController::class)->plantilla(new Request(['plantilla' => $plantilla]), $t)->getData(true);
        if (empty($j['success'])) throw new \RuntimeException($j['error'] ?? 'No se pudo aplicar la marca');
        $t->forceFill(['marca_id' => $m->id])->save();
        return $j['plantilla'];
    }

    public static function normalizarMarca(array $m): array
    {
        $p = EnVivoController::normalizarPlantilla($m, '');
        $out = array_intersect_key($p, array_flip(MarcaEnVivo::A_PLANTILLA));
        $banners = [];
        foreach (array_slice((array) ($m['banners'] ?? []), 0, 40) as $b) {
            if (!is_array($b) || trim((string) ($b['texto'] ?? '')) === '') continue;
            $banners[] = ['id' => preg_match('/^[a-z0-9]{4,20}$/', (string) ($b['id'] ?? '')) ? $b['id'] : Str::lower(Str::random(8)),
                'titulo' => Str::limit(trim((string) ($b['titulo'] ?? '')), 60, ''), 'texto' => Str::limit(trim((string) $b['texto']), 300, ''), 'cinta' => (bool) ($b['cinta'] ?? false)];
        }
        $out['banners'] = $banners;
        return $out;
    }

    // ------------------------------------------------------------------ archivos

    public function activos(array $dueno): array
    {
        return ActivoEnVivo::where($dueno)->orderByDesc('id')->limit(300)->get()->map(fn($a) => $a->paraApi())->values()->all();
    }

    /** Guarda un archivo propio. Lanza \RuntimeException con el motivo si no es válido. */
    public function subirActivo(array $dueno, UploadedFile $f, string $tipo, ?string $nombre, ?int $duracion): ActivoEnVivo
    {
        if (!in_array($tipo, ActivoEnVivo::TIPOS, true)) throw new \RuntimeException('Tipo de archivo no válido.');
        if ($f->getSize() > self::MAX_MB[$tipo] * 1024 * 1024) throw new \RuntimeException('El archivo supera ' . self::MAX_MB[$tipo] . ' MB.');
        if (ActivoEnVivo::where($dueno)->count() >= 300) throw new \RuntimeException('Llegaste al máximo de 300 archivos. Borra algunos.');
        $ext = strtolower($f->getClientOriginalExtension() ?: (string) $f->extension());
        if (!in_array($ext, self::EXTENSIONES[$tipo], true)) throw new \RuntimeException('Formato no permitido para ' . $tipo . ': usa ' . implode(', ', self::EXTENSIONES[$tipo]) . '.');
        $mime = (string) $f->getMimeType();
        $esperado = in_array($tipo, ActivoEnVivo::IMAGEN, true) ? 'image/' : ($tipo === 'clip' ? 'video/' : 'audio/');
        // Algunos celulares y navegadores marcan los .mp3 / .m4a como video/mp4 o application/octet-stream
        if (!str_starts_with($mime, $esperado) && !($esperado === 'audio/' && in_array($mime, ['video/mp4', 'application/octet-stream'], true))
            && !($esperado === 'video/' && $mime === 'application/octet-stream')) {
            throw new \RuntimeException('El archivo no parece ' . ($esperado === 'image/' ? 'una imagen' : ($esperado === 'video/' ? 'un video' : 'un audio')) . '.');
        }
        $prefijo = isset($dueno['usuario_app']) ? 'a' . preg_replace('/[^A-Za-z0-9]/', '', (string) $dueno['usuario_app']) : 'u' . (int) ($dueno['user_id'] ?? 0);
        $ruta = $f->storeAs('en-vivo/recursos', $prefijo . '-' . $tipo . '-' . Str::lower(Str::random(16)) . '.' . $ext, 'public');
        if (!$ruta) throw new \RuntimeException('No se pudo guardar el archivo.');
        return ActivoEnVivo::create($dueno + [
            'tipo' => $tipo, 'archivo' => $ruta, 'mime' => $esperado === 'audio/' && !str_starts_with($mime, 'audio/') ? 'audio/' . $ext : $mime,
            'bytes' => (int) $f->getSize(), 'duracion' => $duracion ?: null,
            'nombre' => Str::limit(trim((string) $nombre) ?: pathinfo($f->getClientOriginalName(), PATHINFO_FILENAME), 120, ''),
        ]);
    }

    public function borrarActivo(ActivoEnVivo $a): void
    {
        Storage::disk('public')->delete($a->archivo);
        $a->delete();
    }
}
