<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Transmisiones iniciadas desde la web de editus: quién las creó (las de la app usan usuario_app). */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('transmisiones_en_vivo') || Schema::hasColumn('transmisiones_en_vivo', 'user_id')) return;
        Schema::table('transmisiones_en_vivo', function (Blueprint $t) {
            $t->foreignId('user_id')->nullable()->after('usuario_app')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('transmisiones_en_vivo') && Schema::hasColumn('transmisiones_en_vivo', 'user_id')) {
            Schema::table('transmisiones_en_vivo', fn(Blueprint $t) => $t->dropConstrainedForeignId('user_id'));
        }
    }
};
