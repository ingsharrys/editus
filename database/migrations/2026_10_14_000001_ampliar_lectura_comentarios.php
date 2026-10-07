<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lectura de comentarios más completa para medios, campañas políticas y comercios:
 * preguntas, quejas, pedidos, menciones (figuras públicas, marcas, productos) y cuántos
 * comentarios muestran intención de compra o de participación. Todo agregado, sin datos personales.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('comentarios_analisis')) return;
        Schema::table('comentarios_analisis', function (Blueprint $t) {
            if (!Schema::hasColumn('comentarios_analisis', 'preguntas')) $t->json('preguntas')->nullable();
            if (!Schema::hasColumn('comentarios_analisis', 'quejas')) $t->json('quejas')->nullable();
            if (!Schema::hasColumn('comentarios_analisis', 'pedidos')) $t->json('pedidos')->nullable();
            if (!Schema::hasColumn('comentarios_analisis', 'menciones')) $t->json('menciones')->nullable();
            if (!Schema::hasColumn('comentarios_analisis', 'intencion')) $t->unsignedInteger('intencion')->default(0); // compra, voto o participación según el enfoque
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('comentarios_analisis')) return;
        Schema::table('comentarios_analisis', function (Blueprint $t) {
            foreach (['preguntas', 'quejas', 'pedidos', 'menciones', 'intencion'] as $c) if (Schema::hasColumn('comentarios_analisis', $c)) $t->dropColumn($c);
        });
    }
};
