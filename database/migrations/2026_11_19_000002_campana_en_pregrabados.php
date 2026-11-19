<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Pregrabados programados dentro de una campaña con plan de medios (como los en vivos del estudio): suman al informe. */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('emisiones_programadas') || Schema::hasColumn('emisiones_programadas', 'campaign_id')) return;
        Schema::table('emisiones_programadas', function (Blueprint $t) {
            $t->unsignedBigInteger('campaign_id')->nullable()->index();
            $t->unsignedBigInteger('subcampana_id')->nullable();
            $t->string('tipo_pieza', 60)->nullable();
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('emisiones_programadas', 'campaign_id')) return;
        Schema::table('emisiones_programadas', function (Blueprint $t) {
            $t->dropIndex(['campaign_id']);
            $t->dropColumn(['campaign_id', 'subcampana_id', 'tipo_pieza']);
        });
    }
};
