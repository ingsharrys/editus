<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cuentas conectadas desde la app del editor: cada usuario de la app vincula
 * sus propias páginas de Facebook y canales de YouTube (sin usuario de editus).
 *
 *  - usuario_app: id del usuario en el backend de esnoticia (NULL = conexión de
 *    la organización, hecha desde la web de editus y compartida con todos).
 *  - user_id pasa a ser opcional en social_accounts y meta_page_user.
 *  - Un mismo usuario de Facebook / canal de YouTube puede estar conectado por
 *    la organización y por varios usuarios de la app a la vez.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('social_accounts', function (Blueprint $t) {
            if (!Schema::hasColumn('social_accounts', 'usuario_app')) {
                $t->string('usuario_app', 60)->nullable()->after('user_id')->index();
            }
            $t->unsignedBigInteger('user_id')->nullable()->change();
        });
        Schema::table('social_accounts', function (Blueprint $t) {
            $t->unique(['provider', 'provider_user_id', 'usuario_app'], 'social_accounts_proveedor_usuario_app_unique');
        });
        Schema::table('social_accounts', function (Blueprint $t) {
            $t->dropUnique('social_accounts_provider_provider_user_id_unique');
        });

        Schema::table('meta_page_user', function (Blueprint $t) {
            if (!Schema::hasColumn('meta_page_user', 'usuario_app')) {
                $t->string('usuario_app', 60)->nullable()->after('user_id')->index();
            }
            $t->unsignedBigInteger('user_id')->nullable()->change();
        });

        Schema::table('youtube_canales', function (Blueprint $t) {
            if (!Schema::hasColumn('youtube_canales', 'usuario_app')) {
                $t->string('usuario_app', 60)->nullable()->after('user_id')->index();
            }
        });
        Schema::table('youtube_canales', function (Blueprint $t) {
            $t->unique(['channel_id', 'usuario_app'], 'youtube_canales_canal_usuario_app_unique');
        });
        Schema::table('youtube_canales', function (Blueprint $t) {
            $t->dropUnique('youtube_canales_channel_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('youtube_canales', function (Blueprint $t) {
            $t->unique('channel_id');
            $t->dropUnique('youtube_canales_canal_usuario_app_unique');
            $t->dropColumn('usuario_app');
        });
        Schema::table('meta_page_user', function (Blueprint $t) {
            $t->dropColumn('usuario_app');
        });
        Schema::table('social_accounts', function (Blueprint $t) {
            $t->unique(['provider', 'provider_user_id']);
            $t->dropUnique('social_accounts_proveedor_usuario_app_unique');
            $t->dropColumn('usuario_app');
        });
    }
};
