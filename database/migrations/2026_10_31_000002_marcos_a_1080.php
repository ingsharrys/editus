<?php

use App\Services\MarcosService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Marcos subidos en un tamaño distinto de 1080 px de ancho (p. ej. 675 × 844 de Canva): se
 * llevan a 1080 px y, como sus zonas quedaron recortadas a ese tamaño, se les ponen de nuevo
 * las medidas del diseño que les corresponde (Opa con título, Opa solo categoría o Neiva 24).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('plantillas_editor') || !Schema::hasColumn('plantillas_editor', 'marco_path')) return;
        $marcos = app(MarcosService::class);
        foreach (DB::table('plantillas_editor')->whereNotNull('marco_path')->get() as $t) {
            try {
                $disco = Storage::disk('public');
                if (!$disco->exists($t->marco_path)) continue;
                $bytes = (string) $disco->get($t->marco_path);
                $info = @getimagesizefromstring($bytes);
                if (!$info || (int) $info[0] === 1080) continue;
                $disco->put($t->marco_path, $marcos->aAncho1080($bytes));
                $preset = MarcosService::presets()[MarcosService::presetPara($t->medio_slug, (string) $t->nombre)]['zonas'];
                DB::table('plantillas_editor')->where('id', $t->id)->update(['zonas' => json_encode(MarcosService::normalizarZonas($preset)), 'updated_at' => now()]);
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }

    public function down(): void
    {
    }
};
