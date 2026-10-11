<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * En vivo PROGRAMADO con cámaras (como StreamYard): se anuncia días antes en Facebook y YouTube
 * (con "Recordarme"), se avisa al equipo 10 minutos antes y, a la hora, se entra al estudio y se
 * sale al aire en ese mismo en vivo. Estados nuevos: programada y cancelada.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('transmisiones_en_vivo')) return;
        Schema::table('transmisiones_en_vivo', function (Blueprint $t) {
            if (!Schema::hasColumn('transmisiones_en_vivo', 'programada_en')) $t->dateTime('programada_en')->nullable()->index();
            if (!Schema::hasColumn('transmisiones_en_vivo', 'anunciada_en')) $t->timestamp('anunciada_en')->nullable();
            if (!Schema::hasColumn('transmisiones_en_vivo', 'avisada_en')) $t->timestamp('avisada_en')->nullable();
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('transmisiones_en_vivo')) return;
        Schema::table('transmisiones_en_vivo', function (Blueprint $t) {
            foreach (['programada_en', 'anunciada_en', 'avisada_en'] as $c) if (Schema::hasColumn('transmisiones_en_vivo', $c)) $t->dropColumn($c);
        });
    }
};
