<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Estudio en vivo (al estilo StreamYard): marcas guardadas por usuario (logo, colores, tema,
 * fondo, superposición, banners), archivos propios (logos, superposiciones, fondos, clips de
 * video, efectos de sonido y música) y notas privadas de cada transmisión.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('marcas_en_vivo')) {
            Schema::create('marcas_en_vivo', function (Blueprint $t) {
                $t->id();
                $t->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
                $t->string('nombre', 60);
                $t->json('datos')->nullable();
                $t->timestamps();
            });
        }
        if (!Schema::hasTable('activos_en_vivo')) {
            Schema::create('activos_en_vivo', function (Blueprint $t) {
                $t->id();
                $t->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
                $t->string('tipo', 20)->index(); // logo | superposicion | fondo | clip | sonido | musica
                $t->string('nombre', 120);
                $t->string('archivo', 300);
                $t->string('mime', 80)->nullable();
                $t->unsignedBigInteger('bytes')->default(0);
                $t->unsignedInteger('duracion')->nullable();
                $t->timestamps();
            });
        }
        if (Schema::hasTable('transmisiones_en_vivo')) {
            Schema::table('transmisiones_en_vivo', function (Blueprint $t) {
                if (!Schema::hasColumn('transmisiones_en_vivo', 'notas')) $t->text('notas')->nullable();
                if (!Schema::hasColumn('transmisiones_en_vivo', 'marca_id')) $t->unsignedBigInteger('marca_id')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('activos_en_vivo');
        Schema::dropIfExists('marcas_en_vivo');
        foreach (['notas', 'marca_id'] as $c) {
            if (Schema::hasColumn('transmisiones_en_vivo', $c)) Schema::table('transmisiones_en_vivo', fn(Blueprint $t) => $t->dropColumn($c));
        }
    }
};
