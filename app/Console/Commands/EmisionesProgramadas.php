<?php

namespace App\Console\Commands;

use App\Services\EmisionesService;
use App\Services\EmisorClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/** Videos pregrabados en vivo: anuncia, arranca a la hora, vigila y limpia (cada minuto). */
class EmisionesProgramadas extends Command
{
    protected $signature = 'editus:emisiones';
    protected $description = 'Mueve los videos pregrabados programados: anuncio en Facebook/YouTube, salida al aire, cierre y limpieza';

    public function handle(EmisionesService $emisiones, EmisorClient $emisor): int
    {
        if (!Schema::hasTable('emisiones_programadas') || !$emisor->configurado()) return self::SUCCESS;
        foreach ($emisiones->tick() as $linea) {
            $this->line($linea);
            \Log::info('[emisiones] ' . $linea);
        }
        return self::SUCCESS;
    }
}
