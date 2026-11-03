<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Usuario de editus asociado a un periodista de esnoticia: en Redes publica siempre con la firma de ese
 * periodista (no puede elegir otra). Crea también el rol «Periodista (solo Redes)».
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('users') && !Schema::hasColumn('users', 'esnoticia_usuario_id')) {
            Schema::table('users', function (Blueprint $t) {
                $t->unsignedBigInteger('esnoticia_usuario_id')->nullable()->index();
            });
        }
        if (Schema::hasTable('roles') && Schema::hasColumn('roles', 'permisos') && !DB::table('roles')->where('slug', 'periodista-redes')->exists()) {
            DB::table('roles')->insert([
                'name' => 'Periodista (solo Redes)', 'slug' => 'periodista-redes',
                'descripcion' => 'Solo publica en Redes, con la firma del periodista de esnoticia asociado a su usuario',
                'permisos' => json_encode(['redes']), 'sistema' => false, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'esnoticia_usuario_id')) {
            Schema::table('users', function (Blueprint $t) {
                $t->dropIndex(['esnoticia_usuario_id']);
                $t->dropColumn('esnoticia_usuario_id');
            });
        }
    }
};
