<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Canales de YouTube conectados (OAuth de Google) para transmitir en vivo desde la app. */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('youtube_canales', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('channel_id', 64)->unique();
            $t->string('titulo', 150);
            $t->text('foto')->nullable();
            $t->text('access_token');
            $t->text('refresh_token')->nullable();
            $t->timestamp('expira_en')->nullable();
            $t->string('stream_id', 64)->nullable(); // liveStream reutilizable (clave RTMP fija del canal)
            $t->boolean('visible_en_editor')->default(true);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('youtube_canales');
    }
};
