<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Prepara editus para la revisión de la app en Meta: crea (o actualiza) el rol «Meta Reviewer»
 * con solo los módulos que el revisor debe probar y su usuario de prueba, en inglés y sin
 * límites de plan. La clave se genera y se muestra UNA vez (va solo en el formulario de Meta).
 *
 * Uso:
 *   php artisan editus:revisor-meta
 *   php artisan editus:revisor-meta --email=meta.review@editus.online
 *   php artisan editus:revisor-meta --cambiar-clave-demo   (también cambia la clave de admin@example.com)
 *   php artisan editus:revisor-meta --desactivar           (al terminar la revisión: desactiva al revisor)
 */
class RevisorMeta extends Command
{
    protected $signature = 'editus:revisor-meta {--email=meta.review@editus.online : correo del usuario revisor}
        {--cambiar-clave-demo : genera una clave nueva para admin@example.com (salió en la solicitud anterior)}
        {--desactivar : desactiva al usuario revisor cuando Meta termine}';
    protected $description = 'Crea el rol y el usuario de prueba para la revisión de la app en Meta';

    /** Lo que el revisor necesita para probar cada permiso solicitado. */
    public const PERMISOS = [
        'paginas', 'paginas.publicar', 'publicaciones', 'mis_publicaciones', // pages_manage_posts, pages_read_engagement
        'en_vivo',                                                          // Live Video API
        'estadisticas', 'informes', 'informe_admin',                        // read_insights
        'inteligencia',                                                     // pages_read_user_content (comentarios)
    ];

    public function handle(): int
    {
        $email = strtolower(trim((string) $this->option('email')));

        if ($this->option('desactivar')) {
            $u = User::where('email', $email)->first();
            if (!$u) { $this->error("No existe el usuario $email"); return self::FAILURE; }
            $u->forceFill(['activo' => false])->save();
            $this->info("Usuario $email desactivado (ya no puede entrar).");
            return self::SUCCESS;
        }

        $rol = Role::firstOrNew(['slug' => 'meta-reviewer']);
        $rol->fill([
            'name' => 'Meta Reviewer',
            'descripcion' => 'Solo para la revisión de la app en Meta: páginas, publicar, en vivo, estadísticas, informes e inteligencia.',
            'permisos' => array_values(array_intersect(Role::claves(), self::PERMISOS)),
        ]);
        if (Schema::hasColumn('roles', 'sistema')) $rol->sistema = false;
        $rol->save();

        $clave = Str::password(20, symbols: false);
        $u = User::firstOrNew(['email' => $email]);
        $nuevo = !$u->exists;
        $u->forceFill(array_filter([
            'name' => $u->name ?: 'Meta Reviewer',
            'password' => Hash::make($clave),
            'role_id' => $rol->id,
            'email_verified_at' => $u->email_verified_at ?: now(),
            'activo' => Schema::hasColumn('users', 'activo') ? true : null,
            'idioma' => Schema::hasColumn('users', 'idioma') ? 'en' : null,
            'cargo' => Schema::hasColumn('users', 'cargo') ? 'Meta App Review' : null,
            // Sin límites de plan: el revisor debe poder publicar y transmitir sin pagar
            'exento_planes' => Schema::hasColumn('users', 'exento_planes') ? true : null,
        ], fn($v) => $v !== null))->save();

        $this->newLine();
        $this->info('✓ Rol «Meta Reviewer» listo con: ' . implode(', ', $rol->listaPermisos()));
        $this->info('✓ Usuario revisor ' . ($nuevo ? 'creado' : 'actualizado (clave nueva)') . ', en inglés y sin límites de plan.');
        $this->newLine();
        $this->line('  Login:    ' . url('/login'));
        $this->line('  Email:    ' . $email);
        $this->line('  Password: ' . $clave);
        $this->newLine();
        $this->warn('Copia estos datos SOLO en el formulario de Meta (Web reviewer instructions). No se vuelven a mostrar.');

        if ($this->option('cambiar-clave-demo')) {
            $demo = User::where('email', 'admin@example.com')->first();
            if ($demo) {
                $claveDemo = Str::password(20, symbols: false);
                $demo->forceFill(['password' => Hash::make($claveDemo)])->save();
                $this->newLine();
                $this->info('✓ Clave de admin@example.com cambiada. Nueva clave (guárdala en un lugar seguro): ' . $claveDemo);
            } else {
                $this->line('No hay usuario admin@example.com: nada que cambiar.');
            }
        }

        $this->newLine();
        $this->line('Cuando Meta apruebe, desactívalo con: php artisan editus:revisor-meta --desactivar');
        return self::SUCCESS;
    }
}
