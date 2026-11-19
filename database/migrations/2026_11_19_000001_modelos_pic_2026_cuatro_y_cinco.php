<?php

use App\Models\Campaign;
use App\Services\ContratosService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Modelos 4 y 5 del PIC 2026 (un mes desde el 20 de noviembre, pago en una sola cuota): se agregan a las campañas
 * que ya tenían cargados los modelos de editus. Los tipos que ya existen no cambian.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('campaign_contrato_tipos') || !Schema::hasColumn('campaign_contrato_tipos', 'clave')) return;
        $servicio = app(ContratosService::class);
        Campaign::whereNotNull('contratos')->get()
            ->filter(fn(Campaign $c) => !empty(((array) $c->contratos)['modelos_cargados']))
            ->each(fn(Campaign $c) => $servicio->cargarModelos($c));
    }

    public function down(): void
    {
        // Se dejan: pueden tener medios o contratos firmados
    }
};
