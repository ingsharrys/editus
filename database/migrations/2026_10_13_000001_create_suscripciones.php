<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Suscripciones (planes Básico y Full), pagos con Wompi y licencia del plugin SharryStreem.
 * Los usuarios que ya existen son el equipo interno: quedan exentos de los planes.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('users', 'exento_planes')) {
            Schema::table('users', fn(Blueprint $t) => $t->boolean('exento_planes')->default(false)->after('role_id'));
            DB::table('users')->update(['exento_planes' => true]);
        }

        if (!Schema::hasTable('suscripciones')) {
            Schema::create('suscripciones', function (Blueprint $t) {
                $t->id();
                $t->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
                $t->string('plan', 20);                    // basico | full
                $t->string('periodo', 10)->default('mensual'); // mensual | anual
                $t->timestamp('inicia_en')->nullable();
                $t->timestamp('vence_en')->nullable();     // activa mientras vence_en > ahora
                $t->json('paginas')->nullable();           // meta_pages.id elegidas para el plan
                // Licencia del plugin: solo se guarda el hash; el prefijo identifica la llave
                $t->string('licencia_prefijo', 16)->nullable()->unique();
                $t->string('licencia_hash', 64)->nullable();
                $t->json('licencia_sitios')->nullable();   // sitios WordPress vinculados
                $t->timestamp('licencia_creada_en')->nullable();
                $t->text('nota')->nullable();
                $t->timestamps();
            });
        }

        if (!Schema::hasTable('pagos_suscripcion')) {
            Schema::create('pagos_suscripcion', function (Blueprint $t) {
                $t->id();
                $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $t->string('plan', 20);
                $t->string('periodo', 10);
                $t->string('referencia', 60)->unique();
                $t->unsignedBigInteger('monto_centavos');
                $t->string('moneda', 3)->default('COP');
                $t->string('estado', 20)->default('PENDING'); // PENDING | APPROVED | DECLINED | VOIDED | ERROR
                $t->string('wompi_id', 60)->nullable()->index();
                $t->string('metodo', 30)->nullable();
                $t->string('origen', 20)->default('wompi');    // wompi | manual
                $t->timestamp('aplicado_en')->nullable();       // cuándo extendió la suscripción (una sola vez)
                $t->json('respuesta')->nullable();
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pagos_suscripcion');
        Schema::dropIfExists('suscripciones');
        if (Schema::hasColumn('users', 'exento_planes')) Schema::table('users', fn(Blueprint $t) => $t->dropColumn('exento_planes'));
    }
};
