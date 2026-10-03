<?php

namespace App\Console\Commands;

use App\Models\Campana;
use App\Services\Inteligencia\InformeService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class InteligenciaInforme extends Command
{
    protected $signature = 'inteligencia:informe {campana? : ID de la campaña (todas las activas si se omite)} {--dias=7 : Periodo del informe}';
    protected $description = 'Redacta con la IA el informe periódico de cada campaña activa.';

    public function handle(InformeService $informes): int
    {
        $campanas = $this->argument('campana') ? Campana::whereKey((int) $this->argument('campana'))->get() : Campana::where('activa', true)->get();
        $hasta = Carbon::yesterday();
        $desde = $hasta->copy()->subDays(max(1, (int) $this->option('dias')) - 1);
        foreach ($campanas as $c) {
            try {
                $i = $informes->generar($c, $desde, $hasta);
                $this->line("{$c->nombre}: informe #{$i->id} ({$desde->toDateString()} a {$hasta->toDateString()})");
            } catch (\Throwable $e) {
                $this->error("{$c->nombre}: " . $e->getMessage());
            }
        }
        return self::SUCCESS;
    }
}
