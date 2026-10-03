<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Transmisiones en vivo (app del editor → LiveKit → Facebook Live). */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('transmisiones_en_vivo')) return;
        Schema::create('transmisiones_en_vivo', function (Blueprint $t) {
            $t->id();
            $t->foreignId('meta_page_id')->constrained('meta_pages')->cascadeOnDelete();
            $t->string('usuario_app', 60)->nullable();      // user_id del backend de esnoticia
            $t->string('titulo', 200);
            $t->text('descripcion')->nullable();
            $t->string('room', 80)->unique();              // sala LiveKit
            $t->string('fb_live_id', 60)->nullable();      // live_video de Facebook
            $t->string('fb_video_id', 60)->nullable();     // video resultante (para métricas y la web)
            $t->string('fb_permalink', 500)->nullable();
            $t->text('stream_url')->nullable();            // RTMPS secreto de Facebook (no se expone a la app)
            $t->string('egress_id', 80)->nullable();
            $t->string('estado', 20)->default('creada');   // creada | en_vivo | terminada | error
            $t->json('plantilla')->nullable();             // logo, colores, cintillo, etiqueta...
            $t->text('error')->nullable();
            $t->timestamp('iniciada_en')->nullable();
            $t->timestamp('terminada_en')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transmisiones_en_vivo');
    }
};
