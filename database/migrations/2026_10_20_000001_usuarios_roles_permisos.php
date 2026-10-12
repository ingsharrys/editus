<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Usuarios, roles y permisos: cada rol guarda sus permisos (config/permisos.php),
 * los usuarios se pueden desactivar y el perfil tiene foto, teléfono y cargo.
 * El rol "user" recibe los permisos que ya tenían los usuarios normales.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $t) {
            if (!Schema::hasColumn('roles', 'descripcion')) $t->string('descripcion', 255)->nullable();
            if (!Schema::hasColumn('roles', 'permisos')) $t->json('permisos')->nullable();
            if (!Schema::hasColumn('roles', 'sistema')) $t->boolean('sistema')->default(false); // admin y user: no se borran
        });
        Schema::table('users', function (Blueprint $t) {
            if (!Schema::hasColumn('users', 'activo')) $t->boolean('activo')->default(true);
            if (!Schema::hasColumn('users', 'telefono')) $t->string('telefono', 40)->nullable();
            if (!Schema::hasColumn('users', 'cargo')) $t->string('cargo', 100)->nullable();
            if (!Schema::hasColumn('users', 'avatar')) $t->string('avatar', 300)->nullable();
            if (!Schema::hasColumn('users', 'ultimo_acceso')) $t->timestamp('ultimo_acceso')->nullable();
        });
        $ahora = now();
        if (!DB::table('roles')->where('slug', 'admin')->exists()) DB::table('roles')->insert(['name' => 'Admin', 'slug' => 'admin', 'created_at' => $ahora, 'updated_at' => $ahora]);
        if (!DB::table('roles')->where('slug', 'user')->exists()) DB::table('roles')->insert(['name' => 'User', 'slug' => 'user', 'created_at' => $ahora, 'updated_at' => $ahora]);
        DB::table('roles')->where('slug', 'admin')->update(['sistema' => true, 'descripcion' => 'Acceso total a editus (todos los permisos).']);
        DB::table('roles')->where('slug', 'user')->whereNull('permisos')->update(['sistema' => true, 'descripcion' => 'Usuario normal: sus páginas, en vivo, estadísticas e informes propios.',
            'permisos' => json_encode(config('permisos.por_defecto', []))]);
        DB::table('roles')->where('slug', 'user')->update(['sistema' => true]);
    }

    public function down(): void
    {
        foreach (['descripcion', 'permisos', 'sistema'] as $c) if (Schema::hasColumn('roles', $c)) Schema::table('roles', fn(Blueprint $t) => $t->dropColumn($c));
        foreach (['activo', 'telefono', 'cargo', 'avatar', 'ultimo_acceso'] as $c) if (Schema::hasColumn('users', $c)) Schema::table('users', fn(Blueprint $t) => $t->dropColumn($c));
    }
};
