<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notificaciones al celular (servicio gratuito de Expo) y miniatura del anuncio de los en vivos
 * programados y pregrabados. También identifica al usuario de la app que programa un pregrabado.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('dispositivos_push')) {
            Schema::create('dispositivos_push', function (Blueprint $t) {
                $t->id();
                $t->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete(); // usuario de la web de editus
                $t->string('usuario_app', 60)->nullable()->index();                              // usuario de la app (backend de esnoticia)
                $t->string('token', 255)->unique();                                              // ExponentPushToken[...]
                $t->string('plataforma', 10)->nullable();
                $t->timestamp('ultimo_uso')->nullable();
                $t->timestamps();
            });
        }
        if (Schema::hasTable('transmisiones_en_vivo') && !Schema::hasColumn('transmisiones_en_vivo', 'miniatura')) {
            Schema::table('transmisiones_en_vivo', fn(Blueprint $t) => $t->string('miniatura', 300)->nullable());
        }
        if (Schema::hasTable('emisiones_programadas')) {
            Schema::table('emisiones_programadas', function (Blueprint $t) {
                if (!Schema::hasColumn('emisiones_programadas', 'usuario_app')) $t->string('usuario_app', 60)->nullable()->index();
                if (!Schema::hasColumn('emisiones_programadas', 'miniatura')) $t->string('miniatura', 300)->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('dispositivos_push');
        if (Schema::hasColumn('transmisiones_en_vivo', 'miniatura')) Schema::table('transmisiones_en_vivo', fn(Blueprint $t) => $t->dropColumn('miniatura'));
        foreach (['usuario_app', 'miniatura'] as $c) if (Schema::hasColumn('emisiones_programadas', $c)) Schema::table('emisiones_programadas', fn(Blueprint $t) => $t->dropColumn($c));
    }
};
