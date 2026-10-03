<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Recursos en vivo: para qué sirve cada uno (intro, plantilla PNG o publicidad). */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('recursos_en_vivo', function (Blueprint $t) {
            if (!Schema::hasColumn('recursos_en_vivo', 'uso')) $t->string('uso', 15)->default('publicidad')->after('tipo');
        });
        DB::table('recursos_en_vivo')->where('tipo', 'plantilla')->update(['tipo' => 'imagen', 'uso' => 'plantilla']);
    }

    public function down(): void
    {
        Schema::table('recursos_en_vivo', function (Blueprint $t) {
            if (Schema::hasColumn('recursos_en_vivo', 'uso')) $t->dropColumn('uso');
        });
    }
};
