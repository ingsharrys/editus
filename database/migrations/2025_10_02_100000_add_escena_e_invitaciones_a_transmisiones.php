<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Estudio en vivo: diseño de la escena (quién sale al aire) e invitaciones para cámaras remotas. */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('transmisiones_en_vivo', function (Blueprint $t) {
            if (!Schema::hasColumn('transmisiones_en_vivo', 'escena')) $t->json('escena')->nullable()->after('plantilla');
            if (!Schema::hasColumn('transmisiones_en_vivo', 'invitaciones')) $t->json('invitaciones')->nullable()->after('escena');
        });
    }

    public function down(): void
    {
        Schema::table('transmisiones_en_vivo', function (Blueprint $t) {
            foreach (['escena', 'invitaciones'] as $c) {
                if (Schema::hasColumn('transmisiones_en_vivo', $c)) $t->dropColumn($c);
            }
        });
    }
};
