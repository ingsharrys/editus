<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** En vivo: varias páginas a la vez (destinos) y recursos de producción (cortinillas, comerciales, imágenes). */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('transmisiones_en_vivo', function (Blueprint $t) {
            if (!Schema::hasColumn('transmisiones_en_vivo', 'destinos')) $t->json('destinos')->nullable()->after('stream_url');
        });
        if (!Schema::hasTable('recursos_en_vivo')) {
            Schema::create('recursos_en_vivo', function (Blueprint $t) {
                $t->id();
                $t->string('tipo', 10); // imagen | video
                $t->string('nombre', 80);
                $t->string('archivo', 300);
                $t->unsignedInteger('duracion')->nullable(); // segundos (imágenes: cuánto se muestra; vacío = hasta quitarla)
                $t->unsignedSmallInteger('orden')->default(0);
                $t->boolean('activo')->default(true);
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('recursos_en_vivo');
        Schema::table('transmisiones_en_vivo', function (Blueprint $t) {
            if (Schema::hasColumn('transmisiones_en_vivo', 'destinos')) $t->dropColumn('destinos');
        });
    }
};
