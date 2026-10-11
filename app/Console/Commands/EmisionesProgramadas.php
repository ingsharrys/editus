<?php

namespace App\Console\Commands;

use App\Services\EmisionesService;
use App\Services\EmisorClient;
use App\Services\EnVivoProgramadoService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Cada minuto: pregrabados programados (anuncia, arranca a la hora, vigila y limpia) y en vivos
 * programados con cámaras (anuncia, avisa 10 min antes y quita el anuncio si nadie salió al aire).
 */
class EmisionesProgramadas extends Command
{
    protected $signature = 'editus:emisiones';
    protected $description = 'Pregrabados y en vivos programados: anuncio en Facebook/YouTube, aviso, salida al aire, cierre y limpieza';

    public function handle(EmisionesService $emisiones, EmisorClient $emisor, EnVivoProgramadoService $enVivo): int
    {
        $lineas = [];
        if (Schema::hasColumn('transmisiones_en_vivo', 'programada_en')) $lineas = $enVivo->tick();
        if (Schema::hasTable('emisiones_programadas') && $emisor->configurado()) $lineas = array_merge($lineas, $emisiones->tick());
        foreach ($lineas as $linea) {
            $this->line($linea);
            \Log::info('[programados] ' . $linea);
        }
        return self::SUCCESS;
    }
}
