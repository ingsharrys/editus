<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Consultor de IA (preguntas libres sobre los datos de toda la organización o de
 * una campaña, con diagnóstico emocional, recomendaciones y publicaciones
 * sugeridas) y emociones en la lectura de comentarios.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('comentarios_analisis', function (Blueprint $t) {
            if (!Schema::hasColumn('comentarios_analisis', 'emociones')) $t->json('emociones')->nullable()->after('neutro'); // {"alegria": 3, "enojo": 5, ...}
        });
        if (!Schema::hasTable('consultas_ia')) {
            Schema::create('consultas_ia', function (Blueprint $t) {
                $t->id();
                $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $t->foreignId('campana_id')->nullable()->constrained('campanas')->cascadeOnDelete();
                $t->json('ambito')->nullable();      // {tipo: general|campana, medio, paginas: [...], desde, hasta, paginas_nombres: [...]}
                $t->text('pregunta');
                $t->json('respuesta')->nullable();   // JSON estructurado de la IA
                $t->string('modelo', 80)->nullable();
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('consultas_ia');
        Schema::table('comentarios_analisis', fn(Blueprint $t) => $t->dropColumn('emociones'));
    }
};
