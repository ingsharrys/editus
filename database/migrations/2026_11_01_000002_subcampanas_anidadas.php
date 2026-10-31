<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Subcampañas dentro de subcampañas: la campaña del plan (p. ej. «Campaña 1: Poblaciones vulnerables») agrupa
 * sus subcampañas («Discapacidad», «Envejecimiento y vejez»). Las que no tienen hijas llevan sus propias cantidades.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('campaign_subcampanas') && !Schema::hasColumn('campaign_subcampanas', 'parent_id')) {
            Schema::table('campaign_subcampanas', function (Blueprint $t) {
                $t->unsignedBigInteger('parent_id')->nullable()->after('campaign_id')->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('campaign_subcampanas', 'parent_id')) {
            Schema::table('campaign_subcampanas', function (Blueprint $t) {
                $t->dropIndex(['parent_id']);
                $t->dropColumn('parent_id');
            });
        }
    }
};
