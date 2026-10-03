<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Qué usuarios de la app ven cada página / canal de la organización.
 * app_usuarios: lista JSON de nombres de usuario de la app (backend de esnoticia);
 * vacío o NULL = todos los usuarios de la app.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('meta_pages', function (Blueprint $t) {
            if (!Schema::hasColumn('meta_pages', 'app_usuarios')) $t->text('app_usuarios')->nullable();
        });
        Schema::table('youtube_canales', function (Blueprint $t) {
            if (!Schema::hasColumn('youtube_canales', 'app_usuarios')) $t->text('app_usuarios')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('meta_pages', fn(Blueprint $t) => $t->dropColumn('app_usuarios'));
        Schema::table('youtube_canales', fn(Blueprint $t) => $t->dropColumn('app_usuarios'));
    }
};
