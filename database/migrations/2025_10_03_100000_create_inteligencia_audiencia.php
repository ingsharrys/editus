<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inteligencia de audiencia: campañas, temas, histórico diario por página,
 * publicaciones de redes con su tema y métricas, análisis de comentarios e informes.
 * Todo es agregado por página/segmento: no se guardan datos de personas.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('campanas', function (Blueprint $t) {
            $t->id();
            $t->string('nombre', 120);
            $t->text('descripcion')->nullable();
            $t->string('territorio', 120)->nullable();
            $t->date('desde')->nullable();
            $t->date('hasta')->nullable();
            $t->boolean('activa')->default(true);
            $t->timestamps();
        });

        Schema::create('campana_pagina', function (Blueprint $t) {
            $t->id();
            $t->foreignId('campana_id')->constrained('campanas')->cascadeOnDelete();
            $t->foreignId('meta_page_id')->constrained('meta_pages')->cascadeOnDelete();
            $t->unique(['campana_id', 'meta_page_id']);
        });

        Schema::create('temas', function (Blueprint $t) {
            $t->id();
            $t->foreignId('campana_id')->nullable()->constrained('campanas')->cascadeOnDelete();
            $t->string('nombre', 80);
            $t->text('descripcion')->nullable();
            $t->json('palabras_clave')->nullable();
            $t->string('color', 7)->nullable();
            $t->unsignedSmallInteger('orden')->default(0);
            $t->timestamps();
        });

        Schema::create('audiencia_diaria', function (Blueprint $t) {
            $t->id();
            $t->foreignId('meta_page_id')->constrained('meta_pages')->cascadeOnDelete();
            $t->string('red', 10); // facebook | instagram
            $t->date('fecha');
            $t->unsignedBigInteger('alcance')->nullable();
            $t->unsignedBigInteger('impresiones')->nullable();
            $t->unsignedBigInteger('interacciones')->nullable();
            $t->unsignedBigInteger('seguidores')->nullable();
            $t->integer('nuevos_seguidores')->nullable();
            $t->unsignedBigInteger('visitas')->nullable();
            $t->json('demografia')->nullable(); // {edad_genero:{...}, ciudad:{...}, pais:{...}}
            $t->json('horarios')->nullable();   // {"0":{"0":n,...},...} día de semana → hora → seguidores en línea
            $t->json('extras')->nullable();
            $t->timestamps();
            $t->unique(['meta_page_id', 'red', 'fecha']);
        });

        Schema::create('publicaciones_redes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('meta_page_id')->constrained('meta_pages')->cascadeOnDelete();
            $t->string('red', 10);
            $t->string('post_id', 80);
            $t->string('tipo', 20)->nullable(); // foto | video | reel | enlace | texto | en_vivo | carrusel
            $t->text('texto')->nullable();
            $t->string('permalink', 500)->nullable();
            $t->dateTime('publicado_en');
            $t->foreignId('tema_id')->nullable()->constrained('temas')->nullOnDelete();
            $t->string('tema_fuente', 10)->nullable(); // ia | manual
            $t->unsignedTinyInteger('tema_confianza')->nullable();
            $t->unsignedBigInteger('alcance')->nullable();
            $t->unsignedBigInteger('impresiones')->nullable();
            $t->unsignedBigInteger('interacciones')->nullable();
            $t->unsignedInteger('reacciones')->nullable();
            $t->unsignedInteger('comentarios')->nullable();
            $t->unsignedInteger('compartidos')->nullable();
            $t->unsignedBigInteger('reproducciones')->nullable();
            $t->unsignedInteger('guardados')->nullable();
            $t->dateTime('metricas_en')->nullable();
            $t->timestamps();
            $t->unique(['red', 'post_id']);
            $t->index(['meta_page_id', 'publicado_en']);
            $t->index('tema_id');
        });

        Schema::create('comentarios_analisis', function (Blueprint $t) {
            $t->id();
            $t->foreignId('publicacion_id')->unique()->constrained('publicaciones_redes')->cascadeOnDelete();
            $t->unsignedInteger('total')->default(0);
            $t->unsignedInteger('a_favor')->default(0);
            $t->unsignedInteger('en_contra')->default(0);
            $t->unsignedInteger('neutro')->default(0);
            $t->json('preocupaciones')->nullable(); // ["inseguridad en el centro", ...]
            $t->json('palabras')->nullable();       // ["empleo", "vías", ...]
            $t->text('resumen')->nullable();
            $t->dateTime('analizado_en');
            $t->timestamps();
        });

        Schema::create('informes_campana', function (Blueprint $t) {
            $t->id();
            $t->foreignId('campana_id')->constrained('campanas')->cascadeOnDelete();
            $t->date('desde');
            $t->date('hasta');
            $t->longText('contenido'); // markdown redactado por la IA
            $t->json('datos')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['informes_campana', 'comentarios_analisis', 'publicaciones_redes', 'audiencia_diaria', 'temas', 'campana_pagina', 'campanas'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
