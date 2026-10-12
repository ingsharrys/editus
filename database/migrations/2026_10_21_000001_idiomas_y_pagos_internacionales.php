<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sitio en español e inglés, y cobros fuera de Colombia en dólares con Square:
 * cada usuario guarda su idioma y su país; cada pago, su pasarela y el id de la orden.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            if (!Schema::hasColumn('users', 'idioma')) $t->string('idioma', 5)->nullable();
            if (!Schema::hasColumn('users', 'pais')) $t->string('pais', 2)->nullable();
        });
        if (Schema::hasTable('pagos_suscripcion')) {
            Schema::table('pagos_suscripcion', function (Blueprint $t) {
                if (!Schema::hasColumn('pagos_suscripcion', 'pasarela')) $t->string('pasarela', 20)->default('wompi');
                if (!Schema::hasColumn('pagos_suscripcion', 'externo_id')) $t->string('externo_id', 120)->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        foreach (['idioma', 'pais'] as $c) if (Schema::hasColumn('users', $c)) Schema::table('users', fn(Blueprint $t) => $t->dropColumn($c));
        if (Schema::hasTable('pagos_suscripcion')) {
            foreach (['pasarela', 'externo_id'] as $c) if (Schema::hasColumn('pagos_suscripcion', $c)) Schema::table('pagos_suscripcion', fn(Blueprint $t) => $t->dropColumn($c));
        }
    }
};
