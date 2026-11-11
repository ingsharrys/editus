<?php

use App\Services\ContratosService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Las campañas con los modelos del PIC 2026 que aún no tienen «Firma del contratante» quedan con la de Dime Media.
 * Los contratos que ya estaban firmados se rehacen con ella la próxima vez que se abren o descargan.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('campaigns') || !Schema::hasColumn('campaigns', 'contratos')) return;
        app(ContratosService::class)->ponerFirmaDeLosModelos();
    }

    public function down(): void
    {
        // La firma se queda: se cambia o se quita desde Contratos → Firma del contratante
    }
};
