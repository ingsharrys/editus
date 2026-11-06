<?php

use App\Services\ContratosService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Los modelos del PIC 2026 cambiaron la cláusula quinta: el valor se paga en dos cuotas mensuales iguales del 50 %.
 * Los tipos de contrato que se cargaron con el texto anterior y nadie editó quedan con el texto nuevo.
 * Los contratos ya firmados no cambian (cada uno guarda el texto con que se firmó).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('campaign_contrato_tipos') || !Schema::hasColumn('campaign_contrato_tipos', 'clave')) return;
        app(ContratosService::class)->actualizarModelos();
    }

    public function down(): void
    {
        // El texto anterior no se recupera: los modelos quedan con el texto vigente
    }
};
