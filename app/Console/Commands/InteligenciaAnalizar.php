<?php

namespace App\Console\Commands;

use App\Models\Campana;
use App\Services\Inteligencia\ClasificadorService;
use App\Services\Inteligencia\ClaudeService;
use App\Services\Inteligencia\ComentariosService;
use Illuminate\Console\Command;

class InteligenciaAnalizar extends Command
{
    protected $signature = 'inteligencia:analizar {--campana= : Solo esta campaña} {--limite=200 : Publicaciones a clasificar por campaña} {--comentarios=30 : Publicaciones cuyos comentarios se leen por campaña}';
    protected $description = 'Clasifica por tema las publicaciones nuevas y lee los comentarios con la IA.';

    public function handle(ClasificadorService $clasificador, ComentariosService $comentarios, ClaudeService $ia): int
    {
        if (!$ia->configurado()) {
            $this->warn('Falta ANTHROPIC_API_KEY: no se puede clasificar ni leer comentarios.');
            return self::FAILURE;
        }
        // La campaña de sistema (Esnoticia) cubre todos los medios: se analiza solo cuando el operador lo pide
        $campanas = $this->option('campana') ? Campana::whereKey((int) $this->option('campana'))->get() : Campana::with('campaign')->where('activa', true)->get()->reject(fn($c) => $c->esDeSistema());
        foreach ($campanas as $c) {
            try {
                $n = $clasificador->clasificarCampana($c, (int) $this->option('limite'));
                $m = $comentarios->analizarCampana($c, (int) $this->option('comentarios'));
                $this->line("{$c->nombre}: {$n} publicaciones clasificadas, {$m} lecturas de comentarios");
            } catch (\Throwable $e) {
                $this->error("{$c->nombre}: " . $e->getMessage());
            }
        }
        return self::SUCCESS;
    }
}
