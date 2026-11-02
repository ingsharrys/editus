<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * En vivos de una campaña con plan de medios: la transmisión guarda su campaña, subcampaña y tipo
 * (p. ej. «En vivo»). Al salir al aire queda como pieza del informe con su video en cada página.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('transmisiones_en_vivo') || Schema::hasColumn('transmisiones_en_vivo', 'campaign_id')) return;
        Schema::table('transmisiones_en_vivo', function (Blueprint $t) {
            $t->unsignedBigInteger('campaign_id')->nullable()->index();
            $t->unsignedBigInteger('subcampana_id')->nullable();
            $t->string('tipo_pieza', 60)->nullable();
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('transmisiones_en_vivo', 'campaign_id')) return;
        Schema::table('transmisiones_en_vivo', function (Blueprint $t) {
            $t->dropIndex(['campaign_id']);
            $t->dropColumn(['campaign_id', 'subcampana_id', 'tipo_pieza']);
        });
    }
};
