<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Videos pregrabados que salen EN VIVO a una hora programada (Facebook y YouTube).
 * El archivo vive en el emisor del VPS; aquí queda la programación, los destinos
 * (con sus claves de transmisión cifradas) y el estado.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('emisiones_programadas')) return;
        Schema::create('emisiones_programadas', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('emisor_id', 64)->unique();      // carpeta del video en el emisor
            $t->string('titulo', 200);
            $t->text('descripcion')->nullable();
            $t->dateTime('programada_en');
            // subiendo → procesando → listo → al_aire → terminado | error | cancelado
            $t->string('estado', 20)->default('subiendo')->index();
            $t->unsignedTinyInteger('progreso')->default(0);
            $t->string('archivo_nombre', 255)->nullable();
            $t->unsignedBigInteger('archivo_bytes')->nullable();
            $t->decimal('duracion', 10, 1)->nullable();   // segundos
            $t->json('plantilla')->nullable();             // logo, marco, etiqueta, cuenta regresiva, calidad
            $t->text('destinos')->nullable();              // JSON cifrado (incluye las URL de transmisión)
            $t->text('error')->nullable();
            $t->timestamp('anunciada_en')->nullable();     // se creó el en vivo programado en Facebook/YouTube
            $t->timestamp('inicio_real')->nullable();
            $t->timestamp('fin_real')->nullable();
            $t->timestamp('archivo_borrado_en')->nullable();
            $t->timestamps();
            $t->index(['estado', 'programada_en']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emisiones_programadas');
    }
};
