<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Radar web: investigaciones que la IA hace en internet (noticias, sitios públicos) sobre los
 * temas y el territorio de una campaña. Se ejecuta por pasos (un frente por petición) y guarda
 * las fuentes consultadas, los hallazgos y la síntesis final.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('radar_web')) return;
        Schema::create('radar_web', function (Blueprint $t) {
            $t->id();
            $t->foreignId('campana_id')->constrained('campanas')->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('estado', 12)->default('en_curso'); // en_curso | listo | error
            $t->string('enfoque', 300)->nullable();        // pregunta o enfoque opcional del operador
            $t->json('plan');                               // frentes a investigar [{nombre, descripcion, palabras}]
            $t->unsignedSmallInteger('avance')->default(0); // frentes ya investigados
            $t->json('hallazgos')->nullable();
            $t->json('fuentes')->nullable();                // todo lo que trajo la búsqueda (url, título, fecha)
            $t->json('lineas')->nullable();                 // bitácora por frente
            $t->json('sintesis')->nullable();
            $t->unsignedInteger('busquedas')->default(0);
            $t->text('error')->nullable();
            $t->timestamps();
            $t->index(['campana_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('radar_web');
    }
};
