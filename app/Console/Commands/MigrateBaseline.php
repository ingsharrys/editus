<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Marca migraciones pendientes como ya ejecutadas SIN correrlas.
 * Útil cuando la base de datos de producción ya tiene las tablas
 * pero la tabla `migrations` no lo registró (estructura creada a mano
 * o base restaurada). No modifica ninguna tabla de datos.
 */
class MigrateBaseline extends Command
{
    protected $signature = 'migrate:baseline
        {--before= : Solo marcar migraciones cuyo nombre sea anterior a este prefijo (ej. 2026_07_25)}
        {--dry-run : Mostrar qué se marcaría sin escribir nada}';

    protected $description = 'Registra migraciones pendientes como ejecutadas sin correrlas (baseline de BD existente)';

    public function handle(): int
    {
        $done = DB::table('migrations')->pluck('migration')->all();
        $before = (string) $this->option('before');

        $pending = collect(glob(database_path('migrations/*.php')))
            ->map(fn($f) => basename($f, '.php'))
            ->reject(fn($name) => in_array($name, $done, true))
            ->filter(fn($name) => $before === '' || strcmp($name, $before) < 0)
            ->sort()
            ->values();

        if ($pending->isEmpty()) {
            $this->info('No hay migraciones pendientes que marcar.');
            return self::SUCCESS;
        }

        $this->table(['Se marcarán como ejecutadas'], $pending->map(fn($m) => [$m])->all());

        if ($this->option('dry-run')) {
            $this->comment('Dry-run: no se escribió nada.');
            return self::SUCCESS;
        }

        $batch = (int) DB::table('migrations')->max('batch') + 1;

        foreach ($pending as $name) {
            DB::table('migrations')->insert(['migration' => $name, 'batch' => $batch]);
        }

        $this->info("Listo: {$pending->count()} migración(es) marcadas (batch {$batch}).");
        $this->comment('Ahora ejecuta: php artisan migrate --force');

        return self::SUCCESS;
    }
}
