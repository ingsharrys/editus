<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modelos de contrato listos para elegir (los del PIC 2026 vienen con editus):
 *  - campaign_contrato_tipos.clave: identifica un modelo que trae editus (p. ej. «pic-2026-1»).
 *  - campaign_contrato_tipos.canales: los canales que cubre ese contrato; al elegirlo, el medio queda con ellos.
 *  - campaign_contrato_tipos.rol_medio / rol_contratante: cómo se llama cada parte en las firmas (EL ARRENDADOR…).
 *  - campaign_medios.valor: valor del contrato de cada medio, en pesos (lo pone el administrador).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('campaign_contrato_tipos')) {
            Schema::table('campaign_contrato_tipos', function (Blueprint $t) {
                if (!Schema::hasColumn('campaign_contrato_tipos', 'clave')) $t->string('clave', 40)->nullable()->after('campaign_id');
                if (!Schema::hasColumn('campaign_contrato_tipos', 'canales')) $t->json('canales')->nullable()->after('clase_medio');
                if (!Schema::hasColumn('campaign_contrato_tipos', 'rol_medio')) $t->string('rol_medio', 60)->nullable()->after('canales');
                if (!Schema::hasColumn('campaign_contrato_tipos', 'rol_contratante')) $t->string('rol_contratante', 60)->nullable()->after('rol_medio');
            });
        }
        if (Schema::hasTable('campaign_medios') && !Schema::hasColumn('campaign_medios', 'valor')) {
            Schema::table('campaign_medios', function (Blueprint $t) {
                $t->unsignedBigInteger('valor')->nullable()->after('tipo_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('campaign_medios') && Schema::hasColumn('campaign_medios', 'valor')) {
            Schema::table('campaign_medios', fn(Blueprint $t) => $t->dropColumn('valor'));
        }
        if (Schema::hasTable('campaign_contrato_tipos')) {
            foreach (['rol_contratante', 'rol_medio', 'canales', 'clave'] as $c) {
                if (Schema::hasColumn('campaign_contrato_tipos', $c)) Schema::table('campaign_contrato_tipos', fn(Blueprint $t) => $t->dropColumn($c));
            }
        }
    }
};
