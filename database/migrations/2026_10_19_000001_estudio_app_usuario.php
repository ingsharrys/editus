<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Estudio en vivo desde la app del editor: marcas y archivos propios del usuario de la app (backend de esnoticia). */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['marcas_en_vivo', 'activos_en_vivo'] as $tabla) {
            if (Schema::hasTable($tabla) && !Schema::hasColumn($tabla, 'usuario_app')) {
                Schema::table($tabla, fn(Blueprint $t) => $t->string('usuario_app', 60)->nullable()->index());
            }
        }
    }

    public function down(): void
    {
        foreach (['marcas_en_vivo', 'activos_en_vivo'] as $tabla) {
            if (Schema::hasColumn($tabla, 'usuario_app')) Schema::table($tabla, fn(Blueprint $t) => $t->dropColumn('usuario_app'));
        }
    }
};
