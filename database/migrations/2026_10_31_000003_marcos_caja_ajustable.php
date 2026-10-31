<?php

use App\Models\PlantillaEditor;
use App\Services\MarcosService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * La caja de color de la categoría se ajusta al largo del texto: en los marcos ya subidos se
 * quita la caja fija del PNG y se activa la caja ajustable (con la alineación y el redondeo
 * del diseño que les corresponde).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('plantillas_editor') || !Schema::hasColumn('plantillas_editor', 'zonas')) return;
        foreach (PlantillaEditor::where('tipo', 'marco')->whereNotNull('marco_path')->get() as $t) {
            try {
                $z = $t->zonas ?: [];
                if (empty($z['etiqueta']) || array_key_exists('ajustar', $z['etiqueta'])) continue;
                $preset = MarcosService::presets()[MarcosService::presetPara($t->medio_slug, (string) $t->nombre)]['zonas']['etiqueta'] ?? [];
                $z['etiqueta']['ajustar'] = true;
                $z['etiqueta']['alinear'] = $preset['alinear'] ?? ($z['etiqueta']['alinear'] ?? 'centro');
                $z['etiqueta']['radio'] = (int) ($preset['radio'] ?? 0);
                $z['etiqueta']['fondo'] = null;
                $t->zonas = MarcosService::normalizarZonas($z);
                $t->prepararCaja();
                $t->save();
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }

    public function down(): void
    {
    }
};
